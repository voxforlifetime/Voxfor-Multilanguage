<?php
namespace VoxforML\Utils;

use VoxforML\Core\Plugin;

/**
 * Handles cache and CDN compatibility
 */
class CacheCompatibility {
	private $plugin;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin = Plugin::getInstance();

		// Add cache headers
		add_action( 'send_headers', array( $this, 'addCacheHeaders' ) );

		// Clear cache on translation updates
		add_action( 'voxfor_ml_translation_saved', array( $this, 'clearPageCache' ), 10, 4 );
		add_action( 'voxfor_ml_clear_cache', array( $this, 'clearAllCaches' ) );

		// Add compatibility for popular cache plugins
		$this->addCachePluginSupport();
	}

	/**
	 * Add cache headers
	 */
	public function addCacheHeaders() {

		if ( is_admin() || is_user_logged_in() ) {
			return;
		}

		$current_lang = $this->plugin->getCurrentLanguage();

		// Vary cache by language cookie and Accept-Language
		header( 'Vary: Cookie, Accept-Language' );

		// Add language to cache key
		header( 'X-Voxfor-ML-Language: ' . $current_lang );
	}

	/**
	 * Clear page cache for specific content
	 */
	public function clearPageCache( $source_text, $translated_text, $language, $context ) {
		// Get post ID if available
		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translations';

		$post_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT post_id FROM `%1s` WHERE source_hash = %s AND language_code = %s AND context = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table_name,
				hash( 'sha256', $source_text ),
				$language,
				$context
			)
		);

		if ( $post_id ) {
			$this->clearPostCache( $post_id, $language );
		}
	}

	/**
	 * Clear cache for a specific post
	 */
	public function clearPostCache( $post_id, $language = null ) {
		$permalink = get_permalink( $post_id );

		if ( ! $permalink ) {
			return;
		}

		// Clear for specific language or all languages
		if ( $language ) {
			$router = $this->plugin->getComponent( 'router' );
			$url    = $router->getLanguageUrl( $permalink, $language );
			$this->purgeUrl( $url );
		} else {
			// Clear all language versions
			$languages = $this->plugin->getAvailableLanguages();
			$router    = $this->plugin->getComponent( 'router' );

			foreach ( $languages as $lang ) {
				$url = $router->getLanguageUrl( $permalink, $lang );
				$this->purgeUrl( $url );
			}
		}

		// Trigger cache plugin specific clearing
		$this->clearCachePlugins( $post_id );
	}

	/**
	 * Clear all caches
	 */
	public function clearAllCaches() {
		// WordPress cache
		wp_cache_flush();

		// Object cache
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}

		// Clear transients
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_voxfor_ml_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_voxfor_ml_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Clear cache plugins
		$this->clearAllCachePlugins();
	}

	/**
	 * Purge a specific URL
	 */
	private function purgeUrl( $url ) {

		// Varnish
		if ( $this->isVarnish() ) {
			$this->purgeVarnishUrl( $url );
		}

		// Nginx FastCGI Cache
		if ( $this->isNginxCache() ) {
			$this->purgeNginxUrl( $url );
		}
	}

	/**
	 * Add support for popular cache plugins
	 */
	private function addCachePluginSupport() {
		// WP Rocket
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			add_filter( 'rocket_cache_reject_cookies', array( $this, 'addLanguageCookieToReject' ) );
			add_filter( 'rocket_cache_query_strings', array( $this, 'addLanguageQueryString' ) );
			add_filter( 'rocket_htaccess_mod_rewrite', array( $this, 'modifyWPRocketRules' ) );
		}

		// W3 Total Cache
		if ( defined( 'W3TC' ) ) {
			add_filter( 'w3tc_pagecache_cache_key', array( $this, 'modifyW3TCCacheKey' ) );
		}

		// WP Super Cache
		if ( defined( 'WPCACHEHOME' ) ) {
			add_filter( 'wpsupercache_cookie_list', array( $this, 'addLanguageCookieToSuperCache' ) );
		}

		// LiteSpeed Cache
		if ( defined( 'LSCWP_V' ) ) {
			add_filter( 'litespeed_vary_cookies', array( $this, 'addLanguageCookieToLiteSpeed' ) );
		}

		// WP Fastest Cache
		if ( class_exists( 'WpFastestCache' ) ) {
			add_filter( 'wpfc_exclude_cookies', array( $this, 'addLanguageCookieToFastestCache' ) );
		}
	}

	/**
	 * Check if Varnish is active
	 */
	private function isVarnish() {
		return isset( $_SERVER['HTTP_X_VARNISH'] ) ||
				( isset( $_SERVER['HTTP_X_CACHE'] ) && strpos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CACHE'] ) ), 'HIT' ) !== false );
	}

	/**
	 * Check if Nginx cache is active
	 */
	private function isNginxCache() {
		return isset( $_SERVER['SERVER_SOFTWARE'] ) &&
				strpos( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ), 'nginx' ) !== false &&
				get_option( 'voxfor_ml_nginx_cache_enabled', false );
	}

	/**
	 * Purge Varnish URL
	 */
	private function purgeVarnishUrl( $url ) {
		$parsed = wp_parse_url( $url );

		wp_remote_request(
			$url,
			array(
				'method'  => 'PURGE',
				'headers' => array(
					'Host'           => $parsed['host'],
					'X-Purge-Method' => 'regex',
				),
			)
		);
	}

	/**
	 * Purge Nginx URL
	 */
	private function purgeNginxUrl( $url ) {
		// Nginx cache purging typically requires server-side configuration
		// This is a placeholder for custom implementations
		do_action( 'voxfor_ml_purge_nginx_url', $url );
	}

	/**
	 * Clear cache plugins for a post
	 */
	private function clearCachePlugins( $post_id ) {
		// WP Rocket
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}

		// W3 Total Cache
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id );
		}

		// WP Super Cache
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $post_id );
		}

		// LiteSpeed Cache
		if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
			\LiteSpeed_Cache_API::purge_post( $post_id );
		}

		// WP Fastest Cache
		if ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'singleDeleteCache' ) ) {
			$GLOBALS['wp_fastest_cache']->singleDeleteCache( false, $post_id );
		}
	}

	/**
	 * Clear all cache plugins
	 */
	private function clearAllCachePlugins() {
		// WP Rocket
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// W3 Total Cache
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// WP Super Cache
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// LiteSpeed Cache
		if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
			\LiteSpeed_Cache_API::purge_all();
		}

		// WP Fastest Cache
		if ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
			$GLOBALS['wp_fastest_cache']->deleteCache();
		}
	}

	/**
	 * Add language cookie to cache rejection list
	 */
	public function addLanguageCookieToReject( $cookies ) {
		$cookies[] = VOXFOR_ML_COOKIE_NAME;
		return $cookies;
	}

	/**
	 * Add language query string
	 */
	public function addLanguageQueryString( $query_strings ) {
		$query_strings[] = 'voxfor_ml_lang';
		$query_strings[] = 'voxfor_ml_switch_lang';
		return $query_strings;
	}

	/**
	 * Modify WP Rocket htaccess rules
	 */
	public function modifyWPRocketRules( $rules ) {
		// Add language-specific cache rules
		$language_rules  = "# Voxfor Multilanguage Cache Rules\n";
		$language_rules .= 'RewriteCond %{HTTP_COOKIE} ' . VOXFOR_ML_COOKIE_NAME . "=([a-z]{2}) [NC]\n";
		$language_rules .= "RewriteRule .* - [E=VOXFOR_ML_LANG:%1]\n\n";

		return $language_rules . $rules;
	}

	/**
	 * Modify W3TC cache key
	 */
	public function modifyW3TCCacheKey( $key ) {
		$current_lang = $this->plugin->getCurrentLanguage();
		return $key . '_' . $current_lang;
	}

	/**
	 * Add language cookie to WP Super Cache
	 */
	public function addLanguageCookieToSuperCache( $cookies ) {
		$cookies[] = VOXFOR_ML_COOKIE_NAME;
		return $cookies;
	}

	/**
	 * Add language cookie to LiteSpeed Cache
	 */
	public function addLanguageCookieToLiteSpeed( $cookies ) {
		$cookies[] = VOXFOR_ML_COOKIE_NAME;
		return $cookies;
	}

	/**
	 * Add language cookie to WP Fastest Cache
	 */
	public function addLanguageCookieToFastestCache( $cookies ) {
		return $cookies . '|' . VOXFOR_ML_COOKIE_NAME;
	}

	/**
	 * Get cache configuration for current setup
	 */
	public function getCacheConfiguration() {
		$config = array(
			'cache_plugins' => array(),
			'server'        => array(),
		);

		// Detect cache plugins
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			$config['cache_plugins'][] = 'WP Rocket';
		}
		if ( defined( 'W3TC' ) ) {
			$config['cache_plugins'][] = 'W3 Total Cache';
		}
		if ( defined( 'WPCACHEHOME' ) ) {
			$config['cache_plugins'][] = 'WP Super Cache';
		}
		if ( defined( 'LSCWP_V' ) ) {
			$config['cache_plugins'][] = 'LiteSpeed Cache';
		}
		if ( class_exists( 'WpFastestCache' ) ) {
			$config['cache_plugins'][] = 'WP Fastest Cache';
		}

		// Detect server cache
		if ( $this->isVarnish() ) {
			$config['server'][] = 'Varnish';
		}
		if ( $this->isNginxCache() ) {
			$config['server'][] = 'Nginx FastCGI Cache';
		}

		return $config;
	}
}
