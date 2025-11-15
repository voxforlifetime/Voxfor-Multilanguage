<?php
namespace VoxforML\Core;

use VoxforML\Router\LanguageRouter;
use VoxforML\Translator\TranslationManager;
use VoxforML\Translator\BulkTranslationManager;
use VoxforML\Translator\AutoUpdateHandler;
use VoxforML\SEO\SEOManager;
use VoxforML\SEO\PerLanguageSEO;
use VoxforML\SEO\SitemapGenerator;
use VoxforML\SEO\SlugManager;
use VoxforML\SEO\MetaTranslator;
use VoxforML\Admin\AdminManager;
use VoxforML\Frontend\FrontendManager;
use VoxforML\Frontend\UntranslatedPageHandler;
use VoxforML\Frontend\VisualEditor;
use VoxforML\API\RestController;
use VoxforML\Database\DatabaseManager;
use VoxforML\Analytics\StatisticsManager;
use VoxforML\Utils\CacheCompatibility;
use VoxforML\Utils\SecurityHandler;
use VoxforML\Widgets\LanguageSwitcherWidget;

/**
 * Main plugin class - singleton pattern
 */
class Plugin {
	private static $instance = null;
	private $components      = array();
	private $initialized     = false;

	/**
	 * Get singleton instance
	 */
	public static function getInstance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor
	 */
	private function __construct() {
		// Initialize plugin constants
		$this->defineConstants();
	}

	/**
	 * Define plugin constants
	 */
	private function defineConstants() {
		// Plugin version
		if ( ! defined( 'VOXFOR_ML_VERSION' ) ) {
			define( 'VOXFOR_ML_VERSION', '1.0.0' );
		}

		// Plugin paths
		if ( ! defined( 'VOXFOR_ML_PLUGIN_DIR' ) ) {
			define( 'VOXFOR_ML_PLUGIN_DIR', plugin_dir_path( dirname( __DIR__ ) ) );
		}

		if ( ! defined( 'VOXFOR_ML_PLUGIN_URL' ) ) {
			define( 'VOXFOR_ML_PLUGIN_URL', plugin_dir_url( dirname( __DIR__ ) ) );
		}

		if ( ! defined( 'VOXFOR_ML_PLUGIN_BASENAME' ) ) {
			define( 'VOXFOR_ML_PLUGIN_BASENAME', plugin_basename( dirname( dirname( __DIR__ ) ) . '/voxfor-multilanguage.php' ) );
		}

		// Database table prefix
		if ( ! defined( 'VOXFOR_ML_TABLE_PREFIX' ) ) {
			define( 'VOXFOR_ML_TABLE_PREFIX', 'voxfor_ml_' );
		}

		// Cookie name
		if ( ! defined( 'VOXFOR_ML_COOKIE_NAME' ) ) {
			define( 'VOXFOR_ML_COOKIE_NAME', 'voxfor_ml_lang' );
		}
	}

	/**
	 * Initialize plugin components
	 */
	public function init() {
		// Prevent multiple initializations
		if ( $this->initialized ) {
			return;
		}

		// Initialize database manager first
		$this->components['database'] = new DatabaseManager();

		// Ensure database tables exist
		$this->ensureDatabaseTables();

		// Run any pending database migrations
		$this->runDatabaseMigrations();

		// Initialize core components
		file_put_contents( '/tmp/voxfor_debug.log', "Voxfor ML Plugin: Creating LanguageRouter...\n", FILE_APPEND );
		$this->components['router'] = new LanguageRouter();
		file_put_contents( '/tmp/voxfor_debug.log', "Voxfor ML Plugin: LanguageRouter created successfully\n", FILE_APPEND );

		// Initialize router on WordPress init hook (after rewrite system is ready)
		add_action( 'init', array( $this->components['router'], 'init' ), 5 );
		$this->components['translator']         = new TranslationManager();
		$this->components['translation_memory'] = new \VoxforML\Database\TranslationMemory();
		$this->components['bulk_translator']    = new BulkTranslationManager();
		$this->components['auto_update']        = new AutoUpdateHandler();
		// 🎯 SMART ComprehensiveTranslator: Only activate during translation operations, not language switching
		$this->components['comprehensive_translator'] = new \VoxforML\Core\ComprehensiveTranslator();
		$this->components['seo']                      = new SEOManager();
		$this->components['per_language_seo']         = new PerLanguageSEO();
		$this->components['frontend']                 = new FrontendManager();
		$this->components['untranslated_handler']     = new UntranslatedPageHandler();
		$this->components['statistics']               = new StatisticsManager();
		$this->components['cache']                    = new CacheCompatibility();
		$this->components['security']                 = new SecurityHandler();

		// SEO components - ImageSEOManager now memory-safe
		$this->components['sitemap_generator'] = new SitemapGenerator();
		$this->components['slug_manager']      = new SlugManager();
		$this->components['meta_translator']   = new MetaTranslator();
		$this->components['image_seo']         = new \VoxforML\SEO\ImageSEOManager(); // FIXED: Memory-safe with recursion protection

		// Initialize visual editor
		if ( get_option( 'voxfor_ml_enable_visual_editor', true ) ) {
			$this->components['visual_editor'] = new VisualEditor();
		}

		// Initialize integrations - testing WooCommerce separately
		$this->components['woocommerce_integration'] = new \VoxforML\Integrations\WooCommerceIntegration();
		$this->components['elementor_integration']   = new \VoxforML\Integrations\ElementorIntegration();
		$this->components['acf_integration']         = new \VoxforML\Integrations\ACFIntegration();
		$this->components['divi_integration']        = new \VoxforML\Integrations\DiviIntegration();

		// Initialize theme compatibility
		$this->components['theme_compatibility'] = new \VoxforML\Compatibility\ThemeCompatibility();

		// Initialize API credit manager
		$this->components['api_credit_manager'] = new \VoxforML\Utils\ApiCreditManager();

		// Initialize CLI commands
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->components['wp_cli'] = new \VoxforML\CLI\WPCLICommands();
		}

		// Initialize admin - FORCE admin context detection
		$is_admin_request = false;
		if ( isset( $_SERVER['REQUEST_URI'] ) && strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '/wp-admin/' ) !== false ) {
			$is_admin_request = true;
		}


		if ( ( is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() ) || $is_admin_request ) {
			file_put_contents( '/tmp/voxfor_debug.log', 'Voxfor ML Plugin: Creating AdminManager immediately - admin context detected' . PHP_EOL, FILE_APPEND );
			try {
				$this->components['admin'] = new AdminManager();
				file_put_contents( '/tmp/voxfor_debug.log', 'Voxfor ML Plugin: AdminManager created successfully' . PHP_EOL, FILE_APPEND );
			} catch ( Exception $e ) {
				file_put_contents( '/tmp/voxfor_debug.log', 'Voxfor ML Plugin: AdminManager creation FAILED: ' . $e->getMessage() . PHP_EOL, FILE_APPEND );
			}
		} else {
			// Fallback to admin_init hook for edge cases
			add_action( 'admin_init', array( $this, 'initAdmin' ) );
			file_put_contents( '/tmp/voxfor_debug.log', 'Voxfor ML Plugin: Admin init hook registered - context: is_admin=' . ( is_admin() ? 'YES' : 'NO' ) . ', ajax=' . ( wp_doing_ajax() ? 'YES' : 'NO' ) . ', cron=' . ( wp_doing_cron() ? 'YES' : 'NO' ) . PHP_EOL, FILE_APPEND );
		}

		// Initialize REST API
		$this->components['api'] = new RestController();

		// Hook into WordPress
		$this->setupHooks();

		// Mark as initialized
		$this->initialized = true;
	}

	/**
	 * Get components for debugging
	 */
	public function getComponents() {
		return $this->components;
	}

	/**
	 * Initialize admin components
	 */
	public function initAdmin() {
		if ( ! isset( $this->components['admin'] ) ) {
			$this->components['admin'] = new AdminManager();
		}
	}

	/**
	 * Setup WordPress hooks
	 */
	private function setupHooks() {
		// Initialize components
		add_action( 'init', array( $this, 'onInit' ), 5 );

		// Handle language detection and routing
		add_action( 'init', array( $this->components['router'], 'init' ), 1 );

		// Frontend hooks
		add_action( 'wp', array( $this->components['frontend'], 'init' ) );

		// SEO hooks
		add_action( 'wp_head', array( $this->components['seo'], 'outputHreflangTags' ), 1 );
		add_filter( 'wpseo_canonical', array( $this->components['seo'], 'filterCanonicalUrl' ) );
		add_filter( 'rank_math/frontend/canonical', array( $this->components['seo'], 'filterCanonicalUrl' ) );

		// Lazy loading for images
		add_filter( 'wp_lazy_loading_enabled', array( $this->components['seo'], 'enableLazyLoading' ), 10, 2 );
		add_filter( 'wp_img_tag_add_loading_attr', array( $this->components['seo'], 'addLazyLoadingAttr' ), 10, 3 );

		// Translation hooks with recursion protection
		// Skip translation hooks if Elementor editor is active to prevent conflicts
		if ( ! $this->isElementorEditorActive() ) {
			add_filter( 'the_content', array( $this->components['translator'], 'translateContent' ), 20 );
			add_filter( 'the_title', array( $this->components['translator'], 'translateTitle' ), 20, 2 );
			add_filter( 'the_excerpt', array( $this->components['translator'], 'translateExcerpt' ), 20 );
			add_filter( 'wp_get_attachment_image_attributes', array( $this->components['translator'], 'translateImageAttributes' ), 20, 3 );
			add_filter( 'wp_nav_menu_objects', array( $this->components['translator'], 'translateMenuItems' ), 20 );
			add_filter( 'widget_text', array( $this->components['translator'], 'translateWidgetText' ), 20 );
			add_filter( 'widget_title', array( $this->components['translator'], 'translateWidgetTitle' ), 20 );
		}

		// REST API
		add_action( 'rest_api_init', array( $this->components['api'], 'registerRoutes' ) );

		// Register widgets
		add_action( 'widgets_init', array( $this, 'registerWidgets' ) );

		// WordPress fully loaded
		add_action( 'wp_loaded', array( $this, 'onWpLoaded' ) );

		// Translation queue processing
		add_action( 'voxfor_ml_process_queue', array( $this, 'processTranslationQueue' ) );
	}

	/**
	 * WordPress init action callback
	 */
	public function onInit() {
		// Register post meta for translation status
		register_post_meta(
			'',
			'_voxfor_ml_translated',
			array(
				'type'         => 'boolean',
				'single'       => true,
				'show_in_rest' => true,
				'auth_callback' => array( $this, 'canEditTranslationMeta' ),
			)
		);

		// Register post meta for excluded from translation
		register_post_meta(
			'',
			'_voxfor_ml_exclude',
			array(
				'type'         => 'boolean',
				'single'       => true,
				'show_in_rest' => true,
				'auth_callback' => array( $this, 'canEditTranslationMeta' ),
			)
		);
	}

	/**
	 * Check if user can edit translation meta fields
	 *
	 * @param bool $allowed Whether the user is allowed to edit the meta field
	 * @param string $meta_key The meta key being edited
	 * @param int $object_id The object ID
	 * @param int $user_id The user ID
	 * @param string $cap The capability required
	 * @param array $caps The capabilities the user has
	 * @return bool Whether the user can edit the meta field
	 */
	public function canEditTranslationMeta( $allowed, $meta_key, $object_id, $user_id, $cap, $caps ) {
		// Allow users who can edit the post to edit translation meta
		if ( current_user_can( 'edit_post', $object_id ) ) {
			return true;
		}
		
		// Allow administrators to edit translation meta
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		
		// Deny by default
		return false;
	}

	/**
	 * Get component instance
	 */
	public function getComponent( $name ) {
		return isset( $this->components[ $name ] ) ? $this->components[ $name ] : null;
	}

	/**
	 * Get current language
	 */
	public function getCurrentLanguage() {
		return $this->components['router']->getCurrentLanguage();
	}

	/**
	 * Get available languages (alias for getEnabledLanguages)
	 */
	public function getAvailableLanguages() {
		return $this->getEnabledLanguages();
	}

	/**
	 * Get default language
	 */
	public function getDefaultLanguage() {
		$default = get_option( 'voxfor_ml_default_language', '' );
		if ( empty( $default ) ) {
			// Use site's language as default
			$site_language = get_locale();
			$default       = substr( $site_language, 0, 2 );
		}
		return $default;
	}

	/**
	 * Get enabled languages
	 */
	public function getEnabledLanguages() {
		$languages = get_option( 'voxfor_ml_languages', array( 'en' ) );

		// If it's a string, convert to array
		if ( is_string( $languages ) ) {
			$languages = explode( ',', $languages );
			$languages = array_map( 'trim', $languages );
		}

		// Ensure it's an array
		if ( ! is_array( $languages ) ) {
			$languages = array( 'en' );
		}

		// Ensure English is always included
		if ( ! in_array( 'en', $languages ) ) {
			array_unshift( $languages, 'en' );
		}

		return array_unique( $languages );
	}

	/**
	 * Check if auto-redirect is enabled
	 */
	public function isAutoRedirectEnabled() {
		return get_option( 'voxfor_ml_auto_redirect', false );
	}

	/**
	 * Register widgets
	 */
	public function registerWidgets() {
		register_widget( 'VoxforML\\Widgets\\LanguageSwitcherWidget' );
	}

	/**
	 * WordPress loaded callback
	 */
	public function onWpLoaded() {
		// Process any queued translations
		if ( ! is_admin() && ! wp_doing_ajax() ) {
			// Schedule translation queue processing if needed
			if ( ! wp_next_scheduled( 'voxfor_ml_process_queue' ) ) {
				wp_schedule_event( time(), 'voxfor_ml_minute', 'voxfor_ml_process_queue' );
			}
		}

		// Clear expired cache entries
		if ( get_transient( 'voxfor_ml_cache_cleaned' ) === false ) {
			if ( isset( $this->components['memory'] ) ) {
				$this->components['memory']->cleanOldTranslations();
			}
			set_transient( 'voxfor_ml_cache_cleaned', true, DAY_IN_SECONDS );
		}
	}

	/**
	 * Ensure database tables exist
	 */
	private function ensureDatabaseTables() {
		if ( ! $this->components['database']->tablesExist() ) {

			require_once VOXFOR_ML_PLUGIN_DIR . 'includes/Core/Activator.php';
			\VoxforML\Core\Activator::activate();
		}
	}

	/**
	 * Run database migrations
	 */
	private function runDatabaseMigrations() {
		global $wpdb;
		$table_prefix = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX;

		// Check if migrations have already been run
		$migration_version = get_option( 'voxfor_ml_migration_version', '0' );

		// Migration 1.0: Convert anthropic to deepl
		if ( version_compare( $migration_version, '1.0', '<' ) ) {
			$translations_table = $table_prefix . 'translations';
			$queue_table        = $table_prefix . 'translation_queue';

			// Update translations table
			$updated_translations = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"UPDATE {$translations_table} SET provider = %s WHERE provider = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'deepl',
					'anthropic'
				)
			);

			// Update translation queue table (if it has a provider column)
			$queue_columns = $wpdb->get_col( "DESCRIBE {$queue_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( in_array( 'provider', $queue_columns ) ) {
				$updated_queue = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"UPDATE {$queue_table} SET provider = %s WHERE provider = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						'deepl',
						'anthropic'
					)
				);
			}

			// Log the migration if any updates were made
			if ( $updated_translations > 0 ) {
			}

			// Mark migration as complete
			update_option( 'voxfor_ml_migration_version', '1.0' );
		}
	}

	/**
	 * Process translation queue (cron job)
	 */
	public function processTranslationQueue() {
		try {
			// Check if API is enabled before processing queue
			$credit_manager = $this->getComponent( 'api_credit_manager' );
			if ( $credit_manager && ! $credit_manager->isApiEnabled() ) {
				return;
			}

			if ( isset( $this->components['translator'] ) ) {
				$this->components['translator']->cleanStuckQueueItems();
				$this->components['translator']->processTranslationQueue();
			}
		} catch ( Exception $e ) {
		}
	}

	/**
	 * Check if Elementor editor is active
	 */
	private function isElementorEditorActive() {
		// Check URL parameters first
		if ( isset( $_GET['elementor-preview'] ) || // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			isset( $_GET['elementor_library'] ) || // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			( isset( $_GET['action'] ) && sanitize_text_field( wp_unslash( $_GET['action'] ) ) === 'elementor' ) || // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			( isset( $_GET['elementor_library'] ) && sanitize_text_field( wp_unslash( $_GET['elementor_library'] ) ) !== '' ) || // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), '/elementor/' ) !== false ||
			strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), 'elementor-preview' ) !== false ) {
			return true;
		}

		// Check Elementor class methods if available
		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				$elementor = \Elementor\Plugin::$instance;
				if ( $elementor &&
					isset( $elementor->editor ) &&
					method_exists( $elementor->editor, 'is_edit_mode' ) &&
					$elementor->editor->is_edit_mode() ) {
					return true;
				}

				// Check if we're in preview mode
				if ( $elementor &&
					isset( $elementor->preview ) &&
					method_exists( $elementor->preview, 'is_preview_mode' ) &&
					$elementor->preview->is_preview_mode() ) {
					return true;
				}
			} catch ( Exception $e ) {
				// Ignore Elementor detection errors
			}
		}

		return false;
	}
}
