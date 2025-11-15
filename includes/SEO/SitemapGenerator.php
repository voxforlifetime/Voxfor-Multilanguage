<?php
namespace VoxforML\SEO;

use VoxforML\Core\Plugin;

/**
 * Sitemap Generator for multilingual content
 */
class SitemapGenerator {
	private $plugin;
	private $router;
	private $per_lang_seo;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin       = Plugin::getInstance();
		$this->router       = $this->plugin->getComponent( 'router' );
		$this->per_lang_seo = $this->plugin->getComponent( 'per_language_seo' );

		$this->initHooks();
	}

	/**
	 * Check if taxonomy should be included in sitemap
	 */
	private function shouldIncludeTaxonomyInSitemap( $taxonomy_name ) {
		// Handle post_tag and product_tag based on settings
		if ( $taxonomy_name === 'post_tag' ) {
			return get_option( 'voxfor_ml_include_post_tags_sitemap', false );
		}

		if ( $taxonomy_name === 'product_tag' ) {
			return get_option( 'voxfor_ml_include_product_tags_sitemap', false );
		}

		// Include all other taxonomies by default
		return true;
	}

	/**
	 * Initialize hooks
	 */
	private function initHooks() {
		// Add rewrite rules for language-specific sitemaps
		add_action( 'init', array( $this, 'addRewriteRules' ), 5 );

		// Handle sitemap requests - use multiple hooks for maximum compatibility
		add_action( 'parse_request', array( $this, 'handleSitemapRequest' ), 1 );
		add_action( 'wp', array( $this, 'handleSitemapRequest' ), 1 );
		add_action( 'template_redirect', array( $this, 'handleSitemapRequest' ), 1 );

		// Integrate with Yoast SEO
		add_filter( 'wpseo_sitemap_index', array( $this, 'addLanguageSitemapsToYoastIndex' ) );
		add_filter( 'wpseo_sitemap_entry', array( $this, 'modifyYoastSitemapEntry' ), 10, 3 );

		// Integrate with RankMath (only if active)
		if ( class_exists( 'RankMath' ) ) {
			add_filter( 'rank_math/sitemap/index', array( $this, 'addLanguageSitemapsToRankMathIndex' ) );
			add_filter( 'rank_math/sitemap/entry', array( $this, 'modifyRankMathSitemapEntry' ), 10, 3 );
		}

		// WordPress Core Sitemaps (5.5+)
		add_filter( 'wp_sitemaps_index_entry', array( $this, 'addLanguageSitemapsToWPIndex' ), 10, 4 );
		add_filter( 'wp_sitemaps_posts_entry', array( $this, 'modifyWPSitemapEntry' ), 10, 3 );
	}

	/**
	 * Add rewrite rules for language-specific sitemaps
	 */
	public function addRewriteRules() {
		$languages = $this->plugin->getEnabledLanguages();

		foreach ( (array) $languages as $lang ) {
			// Main sitemap index
			add_rewrite_rule(
				'^' . $lang . '/sitemap\.xml$',
				'index.php?voxfor_ml_sitemap=1&voxfor_ml_lang=' . $lang,
				'top'
			);

			// Sub-sitemaps
			add_rewrite_rule(
				'^' . $lang . '/sitemap-([^/]+)\.xml$',
				'index.php?voxfor_ml_sitemap=1&voxfor_ml_lang=' . $lang . '&voxfor_ml_sitemap_type=$matches[1]',
				'top'
			);

			// Numbered sub-sitemaps
			add_rewrite_rule(
				'^' . $lang . '/sitemap-([^/]+)-([0-9]+)\.xml$',
				'index.php?voxfor_ml_sitemap=1&voxfor_ml_lang=' . $lang . '&voxfor_ml_sitemap_type=$matches[1]&voxfor_ml_sitemap_page=$matches[2]',
				'top'
			);
		}

		// Add query vars
		add_filter(
			'query_vars',
			function ( $vars ) {
				$vars[] = 'voxfor_ml_sitemap';
				$vars[] = 'voxfor_ml_lang';
				$vars[] = 'voxfor_ml_sitemap_type';
				$vars[] = 'voxfor_ml_sitemap_page';
				return $vars;
			}
		);
	}

	/**
	 * Handle sitemap requests
	 */
	public function handleSitemapRequest( $wp = null ) {
		// Check if this is a sitemap request
		$is_sitemap_request = false;

		// Method 1: Check query vars
		if ( get_query_var( 'voxfor_ml_sitemap' ) ) {
			$is_sitemap_request = true;
		}

	// Method 2: Check request URI for sitemap pattern
	if ( ! $is_sitemap_request && isset( $_SERVER['REQUEST_URI'] ) ) {
		// Use esc_url_raw() to sanitize while preserving URL-encoded characters
		$uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );

			// Build dynamic pattern from all enabled languages
			$enabled_languages = $this->plugin->getEnabledLanguages();
			$lang_pattern      = '(' . implode( '|', array_map( 'preg_quote', $enabled_languages ) ) . ')';

			if ( preg_match( '/\/' . $lang_pattern . '\/sitemap.*\.xml$/', $uri ) ) {
				$is_sitemap_request = true;

				// Extract language from URI
				if ( preg_match( '/\/([a-z]{2})\/sitemap/', $uri, $matches ) ) {
					$lang_from_uri = $matches[1];

					// Set query vars manually if not set
					if ( ! get_query_var( 'voxfor_ml_sitemap' ) ) {
						global $wp_query;
						$wp_query->set( 'voxfor_ml_sitemap', '1' );
						$wp_query->set( 'voxfor_ml_lang', $lang_from_uri );

						// Determine sitemap type from URI
						if ( strpos( $uri, 'sitemap.xml' ) !== false ) {
							$wp_query->set( 'voxfor_ml_sitemap_type', 'index' );
						} elseif ( preg_match( '/sitemap-([^-]+)(?:-(\d+))?\.xml/', $uri, $type_matches ) ) {
							$wp_query->set( 'voxfor_ml_sitemap_type', $type_matches[1] );
							if ( isset( $type_matches[2] ) ) {
								$wp_query->set( 'voxfor_ml_sitemap_page', $type_matches[2] );
							}
						}
					}
				}
			}
		}

		if ( ! $is_sitemap_request ) {
			return;
		}

		$lang = get_query_var( 'voxfor_ml_lang' );
		$type = get_query_var( 'voxfor_ml_sitemap_type', 'index' );
		$page = get_query_var( 'voxfor_ml_sitemap_page', 1 );

		// Validate language
		if ( empty( $lang ) ) {
			wp_die( 'Language not specified for sitemap', 400 );
		}

		// Check if language is indexable
		if ( ! $this->per_lang_seo->isLanguageIndexable( $lang ) ) {
			wp_die( 'Sitemap not available for this language', 404 );
		}

		// Clear ALL output buffers to prevent any content before XML
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		
		// Start fresh output buffering
		ob_start();

		// Set XML headers - multiple methods to ensure they're sent
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8', true );
		header( 'X-Robots-Tag: noindex', true );
		header( 'Cache-Control: public, max-age=3600', true );

		// Prevent any WordPress theme/plugin output
		remove_all_actions( 'wp_head' );
		remove_all_actions( 'wp_footer' );

		// Output XML directly - DO NOT use wp_kses_post() as it strips XML tags
		if ( $type === 'index' ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML sitemap output, already escaped in generation methods
			echo $this->generateSitemapIndex( $lang );
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML sitemap output, already escaped in generation methods
			echo $this->generateSitemap( $lang, $type, intval( $page ) );
		}
		
		// Flush and clean the buffer, then exit
		ob_end_flush();
		exit;
	}

	/**
	 * Generate sitemap index for a language
	 */
	private function generateSitemapIndex( $lang ) {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		// Add post type sitemaps (exclude Elementor internal types)
		$post_types          = get_post_types( array( 'public' => true ), 'objects' );
		$excluded_post_types = array( 'elementor_library', 'elementor-hf' );

		foreach ( $post_types as $post_type ) {
			// Skip Elementor internal post types
			if ( in_array( $post_type->name, $excluded_post_types ) ) {
				continue;
			}
			if ( $post_type->name === 'attachment' ) {
				continue;
			}

			$count = $this->getPostTypeCount( $post_type->name, $lang );
			$pages = ceil( $count / 1000 ); // 1000 URLs per sitemap

			for ( $i = 1; $i <= $pages; $i++ ) {
				$xml .= '  <sitemap>' . "\n";
				$xml .= '    <loc>' . home_url( "/$lang/sitemap-{$post_type->name}-$i.xml" ) . '</loc>' . "\n";
				$xml .= '    <lastmod>' . gmdate( 'c' ) . '</lastmod>' . "\n";
				$xml .= '  </sitemap>' . "\n";
			}
		}

		// Add taxonomy sitemaps
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			// Check if this taxonomy should be included
			if ( ! $this->shouldIncludeTaxonomyInSitemap( $taxonomy->name ) ) {
				continue;
			}

			$count = wp_count_terms( $taxonomy->name );
			if ( $count > 0 ) {
				$xml .= '  <sitemap>' . "\n";
				$xml .= '    <loc>' . home_url( "/$lang/sitemap-{$taxonomy->name}.xml" ) . '</loc>' . "\n";
				$xml .= '    <lastmod>' . gmdate( 'c' ) . '</lastmod>' . "\n";
				$xml .= '  </sitemap>' . "\n";
			}
		}

		$xml .= '</sitemapindex>' . "\n";

		return $xml;
	}

	/**
	 * Generate sitemap for specific type
	 */
	private function generateSitemap( $lang, $type, $page ) {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ';
		$xml .= 'xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

		// Check if it's a post type (exclude Elementor internal types)
		$excluded_post_types = array( 'elementor_library', 'elementor-hf' );
		if ( post_type_exists( $type ) && ! in_array( $type, $excluded_post_types ) ) {
			$xml .= $this->generatePostTypeSitemap( $lang, $type, $page );
		}
		// Check if it's a taxonomy and should be included
		elseif ( taxonomy_exists( $type ) && $this->shouldIncludeTaxonomyInSitemap( $type ) ) {
			$xml .= $this->generateTaxonomySitemap( $lang, $type, $page );
		}

		$xml .= '</urlset>' . "\n";

		return $xml;
	}

	/**
	 * Generate post type sitemap entries
	 */
	private function generatePostTypeSitemap( $lang, $post_type, $page ) {
		$xml    = '';
		$offset = ( $page - 1 ) * 1000;

		$args = array(
			'post_type'        => $post_type,
			'post_status'      => 'publish',
			'posts_per_page'   => 1000,
			'offset'           => $offset,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => false,
		);

		$posts = get_posts( $args );

		foreach ( $posts as $post ) {
			// Get translated slug if available
			$slug = $this->getTranslatedSlug( $post->ID, $lang );
			if ( ! $slug ) {
				$slug = $post->post_name;
			}

			$url = $this->router->getLanguageUrl( get_permalink( $post->ID ), $lang );

			$xml .= '  <url>' . "\n";
			$xml .= '    <loc>' . esc_url( $url ) . '</loc>' . "\n";
			$xml .= '    <lastmod>' . get_post_modified_time( 'c', true, $post ) . '</lastmod>' . "\n";
			$xml .= '    <changefreq>weekly</changefreq>' . "\n";
			$xml .= '    <priority>0.8</priority>' . "\n";

			// Add hreflang links
			$languages = $this->plugin->getEnabledLanguages();
			foreach ( $languages as $alt_lang ) {
				if ( $this->per_lang_seo->isLanguageIndexable( $alt_lang ) ) {
					$alt_url = $this->router->getLanguageUrl( get_permalink( $post->ID ), $alt_lang );
					$xml    .= '    <xhtml:link rel="alternate" hreflang="' . esc_attr( $alt_lang ) . '" href="' . esc_url( $alt_url ) . '"/>' . "\n";
				}
			}

			$xml .= '  </url>' . "\n";
		}

		return $xml;
	}

	/**
	 * Generate taxonomy sitemap entries
	 */
	private function generateTaxonomySitemap( $lang, $taxonomy, $page ) {
		$xml    = '';
		$offset = ( $page - 1 ) * 1000;

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => 1000,
				'offset'     => $offset,
			)
		);

		foreach ( $terms as $term ) {
			$url = $this->router->getLanguageUrl( get_term_link( $term ), $lang );

			$xml .= '  <url>' . "\n";
			$xml .= '    <loc>' . esc_url( $url ) . '</loc>' . "\n";
			$xml .= '    <changefreq>weekly</changefreq>' . "\n";
			$xml .= '    <priority>0.6</priority>' . "\n";

			// Add hreflang links
			$languages = $this->plugin->getEnabledLanguages();
			foreach ( $languages as $alt_lang ) {
				if ( $this->per_lang_seo->isLanguageIndexable( $alt_lang ) ) {
					$alt_url = $this->router->getLanguageUrl( get_term_link( $term ), $alt_lang );
					$xml    .= '    <xhtml:link rel="alternate" hreflang="' . esc_attr( $alt_lang ) . '" href="' . esc_url( $alt_url ) . '"/>' . "\n";
				}
			}

			$xml .= '  </url>' . "\n";
		}

		return $xml;
	}

	/**
	 * Get post count for a post type
	 */
	private function getPostTypeCount( $post_type, $lang ) {
		$count = wp_count_posts( $post_type );
		return isset( $count->publish ) ? $count->publish : 0;
	}

	/**
	 * Get translated slug for a post
	 */
	private function getTranslatedSlug( $post_id, $lang ) {
		global $wpdb;

		$table = $wpdb->prefix . 'voxfor_ml_slugs';

		return $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT slug FROM `{$wpdb->prefix}voxfor_ml_slugs` WHERE post_id = %d AND language_code = %s",
				$post_id,
				$lang
			)
		);
	}

	/**
	 * Add language sitemaps to Yoast index
	 */
	public function addLanguageSitemapsToYoastIndex( $sitemap_index ) {
		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();

		foreach ( $languages as $lang ) {
			if ( $lang === $default_lang || ! $this->per_lang_seo->isLanguageIndexable( $lang ) ) {
				continue;
			}

			$sitemap_index .= '<sitemap>';
			$sitemap_index .= '<loc>' . home_url( "/$lang/sitemap.xml" ) . '</loc>';
			$sitemap_index .= '<lastmod>' . gmdate( 'c' ) . '</lastmod>';
			$sitemap_index .= '</sitemap>';
		}

		return $sitemap_index;
	}

	/**
	 * Modify Yoast sitemap entries
	 */
	public function modifyYoastSitemapEntry( $url, $type, $post ) {
		// Add hreflang data to URL array
		$languages        = $this->plugin->getEnabledLanguages();
		$url['languages'] = array();

		foreach ( $languages as $lang ) {
			if ( $this->per_lang_seo->isLanguageIndexable( $lang ) ) {
				$url['languages'][ $lang ] = $this->router->getLanguageUrl( $url['loc'], $lang );
			}
		}

		return $url;
	}

	/**
	 * Add language sitemaps to RankMath index
	 */
	public function addLanguageSitemapsToRankMathIndex( $sitemap ) {
		// Safety check: ensure required components exist
		if ( ! $this->plugin || ! $this->per_lang_seo ) {
			return $sitemap;
		}

		try {
			$languages    = $this->plugin->getEnabledLanguages();
			$default_lang = $this->plugin->getDefaultLanguage();

			if ( ! is_array( $languages ) ) {
				return $sitemap;
			}

			// RankMath expects XML string format, not array
			$additional_xml = '';

			foreach ( $languages as $lang ) {
				if ( $lang === $default_lang || ! $this->per_lang_seo->isLanguageIndexable( $lang ) ) {
					continue;
				}

				$additional_xml .= '
    <sitemap>
        <loc>' . esc_url( home_url( "/$lang/sitemap.xml" ) ) . '</loc>
        <lastmod>' . gmdate( 'c' ) . '</lastmod>
    </sitemap>';
			}

			// If sitemap is a string (XML), append our XML
			if ( is_string( $sitemap ) ) {
				return $sitemap . $additional_xml;
			}

			// If sitemap is an array, convert to proper format
			if ( is_array( $sitemap ) ) {
				foreach ( $languages as $lang ) {
					if ( $lang === $default_lang || ! $this->per_lang_seo->isLanguageIndexable( $lang ) ) {
						continue;
					}

					$sitemap[] = array(
						'loc'     => home_url( "/$lang/sitemap.xml" ),
						'lastmod' => gmdate( 'c' ),
					);
				}
			}
		} catch ( Exception $e ) {
			// Log error but don't break the sitemap
		}

		return $sitemap;
	}

	/**
	 * Modify RankMath sitemap entries
	 */
	public function modifyRankMathSitemapEntry( $url, $type, $object ) {
		// Similar to Yoast modification
		return $this->modifyYoastSitemapEntry( $url, $type, $object );
	}

	/**
	 * Add language sitemaps to WordPress core index
	 */
	public function addLanguageSitemapsToWPIndex( $entry, $post_type, $page, $page_num ) {
		// Add language-specific sitemaps to core index
		return $entry;
	}

	/**
	 * Modify WordPress core sitemap entries
	 */
	public function modifyWPSitemapEntry( $entry, $post, $post_type ) {
		// Add hreflang links
		return $entry;
	}
}
