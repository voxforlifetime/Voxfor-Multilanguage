<?php
namespace VoxforML\Admin;

use VoxforML\Core\Plugin;
use VoxforML\Admin\TranslationStatusMetaBox;
use VoxforML\Utils\EncryptionHandler;

/**
 * Manages admin functionality
 */
class AdminManager {
	private $plugin;

	/**
	 * Constructor
	 */
	public function __construct() {

		$this->plugin = Plugin::getInstance();

		// Initialize translation status meta box
		new TranslationStatusMetaBox();

		// Add admin menu - check if we can add it immediately
		if ( did_action( 'admin_menu' ) ) {

			$this->addAdminMenu();
		} else {

			add_action( 'admin_menu', array( $this, 'addAdminMenu' ) );
		}

		// Enqueue admin assets
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminAssets' ) );

		// Register all AJAX handlers
		$this->registerAjaxHandlers();

		// Register AJAX handlers for API management
		add_action( 'wp_ajax_voxfor_ml_toggle_api', array( $this, 'ajaxToggleApi' ) );
		add_action( 'wp_ajax_voxfor_ml_emergency_stop', array( $this, 'ajaxEmergencyStop' ) );
		add_action( 'wp_ajax_voxfor_ml_get_usage_stats', array( $this, 'ajaxGetUsageStats' ) );

		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) {
			},
			1
		);

		// Add settings link to plugins page
		add_filter( 'plugin_action_links_' . VOXFOR_ML_PLUGIN_BASENAME, array( $this, 'addSettingsLink' ) );

		// Add meta boxes
		add_action( 'add_meta_boxes', array( $this, 'addMetaBoxes' ) );
		add_action( 'save_post', array( $this, 'savePostMeta' ) );

		// Add admin notices
		add_action( 'admin_notices', array( $this, 'adminNotices' ) );

		// Handle admin actions
		add_action( 'admin_init', array( $this, 'handleAdminActions' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		
		// Debug: Track option updates (can be removed in production)
		// if (defined('WP_DEBUG') && WP_DEBUG) {
		//	add_action( 'update_option', array( $this, 'debugOptionUpdate' ), 10, 3 );
		// }

		// Add language column to posts list
		add_filter( 'manage_posts_columns', array( $this, 'addLanguageColumn' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'displayLanguageColumn' ), 10, 2 );

		// Add bulk actions
		add_filter( 'bulk_actions-edit-post', array( $this, 'addBulkActions' ) );
		add_filter( 'handle_bulk_actions-edit-post', array( $this, 'handleBulkActions' ), 10, 3 );

		// Register AJAX handlers
		$this->registerAjaxHandlers();
	}

	/**
	 * Register AJAX handlers
	 */
	private function registerAjaxHandlers() {
		// Bulk translation AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_start_bulk_translation', array( $this, 'ajaxStartBulkTranslation' ) );
		add_action( 'wp_ajax_voxfor_ml_pause_job', array( $this, 'ajaxPauseJob' ) );
		add_action( 'wp_ajax_voxfor_ml_resume_job', array( $this, 'ajaxResumeJob' ) );
		add_action( 'wp_ajax_voxfor_ml_cancel_job', array( $this, 'ajaxCancelJob' ) );
		add_action( 'wp_ajax_voxfor_ml_get_job_status', array( $this, 'ajaxGetJobStatus' ) );
		add_action( 'wp_ajax_voxfor_ml_get_job_log', array( $this, 'ajaxGetJobLog' ) );
		add_action( 'wp_ajax_voxfor_ml_estimate_bulk', array( $this, 'ajaxEstimateBulk' ) );

		// Translation queue handler
		add_action( 'wp_ajax_voxfor_ml_process_queue', array( $this, 'ajaxProcessQueue' ) );

		// Other AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_test_api_key', array( $this, 'ajaxTestApiKey' ) );
		add_action( 'wp_ajax_voxfor_ml_toggle_exclusion', array( $this, 'ajaxToggleExclusion' ) );
		add_action( 'wp_ajax_voxfor_ml_seed_exclusions', array( $this, 'ajaxSeedExclusions' ) );
		add_action( 'wp_ajax_voxfor_ml_toggle_translation_lock', array( $this, 'ajaxToggleTranslationLock' ) );
		add_action( 'wp_ajax_voxfor_ml_update_translation', array( $this, 'ajaxUpdateTranslation' ) );
		add_action( 'wp_ajax_voxfor_ml_delete_translation', array( $this, 'ajaxDeleteTranslation' ) );
		add_action( 'wp_ajax_voxfor_ml_toggle_needs_review', array( $this, 'ajaxToggleNeedsReview' ) );
		add_action( 'wp_ajax_voxfor_ml_save_language_seo', array( $this, 'ajaxSaveLanguageSEO' ) );
		add_action( 'wp_ajax_voxfor_ml_get_content_items', array( $this, 'ajaxGetContentItems' ) );
		add_action( 'wp_ajax_voxfor_ml_get_context_items', array( $this, 'ajaxGetContextItems' ) );

		// Diagnostics AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_fix_diagnostic_issue', array( $this, 'ajaxFixDiagnosticIssue' ) );

		// Visual translation editor AJAX handlers are handled in VisualEditor class
		// Removed duplicate handlers to prevent 500 errors

		// WooCommerce product translation AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_translate_product', array( $this, 'ajaxTranslateProduct' ) );
		add_action( 'wp_ajax_voxfor_ml_translate_all_product', array( $this, 'ajaxTranslateAllProduct' ) );

		// Elementor translation AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_translate_elementor_page', array( $this, 'ajaxTranslateElementorPage' ) );
		add_action( 'wp_ajax_voxfor_ml_translate_all_elementor', array( $this, 'ajaxTranslateAllElementor' ) );

		// General translation AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_translate_all_languages', array( $this, 'ajaxTranslateAllLanguages' ) );
		add_action( 'wp_ajax_voxfor_ml_translate_all', array( $this, 'ajaxTranslateAll' ) );

		// Translation progress tracking AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_get_translation_progress', array( $this, 'ajaxGetTranslationProgress' ) );
		add_action( 'wp_ajax_voxfor_ml_reset_translation_progress', array( $this, 'ajaxResetTranslationProgress' ) );

		// Complete website translation
		add_action( 'wp_ajax_voxfor_ml_translate_complete_website', array( $this, 'ajaxTranslateCompleteWebsite' ) );

		// Single language translation for a post
		add_action( 'wp_ajax_voxfor_ml_translate_single_language', array( $this, 'ajaxTranslateSingleLanguage' ) );

		// Visual editor helper AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_find_original_text', array( $this, 'ajaxFindOriginalText' ) );
		add_action( 'wp_ajax_voxfor_ml_batch_find_original_texts', array( $this, 'ajaxBatchFindOriginalTexts' ) );

		// Database migration AJAX handler


		// Comprehensive translation AJAX handler
		add_action( 'wp_ajax_voxfor_ml_comprehensive_translate', array( $this, 'ajaxComprehensiveTranslate' ) );

		// Cancel comprehensive translation AJAX handler
		add_action( 'wp_ajax_voxfor_ml_cancel_comprehensive_translate', array( $this, 'ajaxCancelComprehensiveTranslate' ) );

		// Individual content translation AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_get_content_types', array( $this, 'ajaxGetContentTypes' ) );
		add_action( 'wp_ajax_voxfor_ml_get_content_list', array( $this, 'ajaxGetContentList' ) );
		add_action( 'wp_ajax_voxfor_ml_translate_individual_content', array( $this, 'ajaxTranslateIndividualContent' ) );
		add_action( 'wp_ajax_voxfor_ml_get_filter_options', array( $this, 'ajaxGetFilterOptions' ) );
		add_action( 'wp_ajax_voxfor_ml_cancel_individual_translation', array( $this, 'ajaxCancelIndividualTranslation' ) );
		add_action( 'wp_ajax_voxfor_ml_estimate_translation_cost', array( $this, 'ajaxEstimateTranslationCost' ) );
		add_action( 'wp_ajax_voxfor_ml_get_individual_translation_progress', array( $this, 'ajaxGetIndividualTranslationProgress' ) );

		// Glossary AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_edit_glossary', array( $this, 'ajaxEditGlossary' ) );
		add_action( 'wp_ajax_voxfor_ml_delete_glossary', array( $this, 'ajaxDeleteGlossary' ) );

		// Admin post handlers for non-AJAX actions
		add_action( 'admin_post_voxfor_ml_delete_glossary', array( $this, 'handleDeleteGlossary' ) );
		add_action( 'admin_post_voxfor_ml_export_glossary', array( $this, 'handleExportGlossary' ) );
		add_action( 'admin_post_voxfor_ml_delete_exclusion', array( $this, 'handleDeleteExclusion' ) );
		add_action( 'admin_post_voxfor_ml_clear_cache', array( $this, 'handleClearCache' ) );
		add_action( 'admin_post_voxfor_ml_clean_excluded_urls', array( $this, 'handleCleanExcludedUrls' ) );

	}

	/**
	 * Add admin menu
	 */
	public function addAdminMenu() {

		// Main menu
		add_menu_page(
			__( 'Voxfor Multilanguage', 'voxfor-multilanguage' ),
			__( 'Multilanguage', 'voxfor-multilanguage' ),
			'manage_options',
			'voxfor-multilanguage',
			array( $this, 'renderDashboard' ),
			'dashicons-translation',
			30
		);

		// Dashboard
		add_submenu_page(
			'voxfor-multilanguage',
			__( 'Dashboard', 'voxfor-multilanguage' ),
			__( 'Dashboard', 'voxfor-multilanguage' ),
			'manage_options',
			'voxfor-multilanguage',
			array( $this, 'renderDashboard' )
		);

		// Settings
		add_submenu_page(
			'voxfor-multilanguage',
			__( 'Settings', 'voxfor-multilanguage' ),
			__( 'Settings', 'voxfor-multilanguage' ),
			'manage_options',
			'voxfor-ml-settings',
			array( $this, 'renderSettings' )
		);

		// Translation Memory
		add_submenu_page(
			'voxfor-multilanguage',
			__( 'Translation Memory', 'voxfor-multilanguage' ),
			__( 'Translation Memory', 'voxfor-multilanguage' ),
			'manage_options',
			'voxfor-ml-memory',
			array( $this, 'renderTranslationMemory' )
		);

		// Glossary
		add_submenu_page(
			'voxfor-multilanguage',
			__( 'Glossary', 'voxfor-multilanguage' ),
			__( 'Glossary', 'voxfor-multilanguage' ),
			'manage_options',
			'voxfor-ml-glossary',
			array( $this, 'renderGlossary' )
		);

		// Exclusions
		add_submenu_page(
			'voxfor-multilanguage',
			__( 'Exclusions', 'voxfor-multilanguage' ),
			__( 'Exclusions', 'voxfor-multilanguage' ),
			'manage_options',
			'voxfor-ml-exclusions',
			array( $this, 'renderExclusions' )
		);

		// AJAX for post type filtering is already registered above

		// Tools
		add_submenu_page(
			'voxfor-multilanguage',
			__( 'Tools', 'voxfor-multilanguage' ),
			__( 'Tools', 'voxfor-multilanguage' ),
			'manage_options',
			'voxfor-ml-tools',
			array( $this, 'renderTools' )
		);

		// Translate
		add_submenu_page(
			'voxfor-multilanguage',
			__( 'Translate', 'voxfor-multilanguage' ),
			__( 'Translate', 'voxfor-multilanguage' ),
			'manage_options',
			'voxfor-ml-translate',
			array( $this, 'renderTranslatePage' )
		);

		// Cost Analytics
		add_submenu_page(
			'voxfor-multilanguage',
			__( 'Cost Analytics', 'voxfor-multilanguage' ),
			__( 'Cost Analytics', 'voxfor-multilanguage' ),
			'manage_options',
			'voxfor-ml-cost-analytics',
			array( $this, 'renderCostAnalytics' )
		);

		// Diagnostics
		add_submenu_page(
			'voxfor-multilanguage',
			__( 'Diagnostics', 'voxfor-multilanguage' ),
			__( 'Diagnostics', 'voxfor-multilanguage' ),
			'manage_options',
			'voxfor-ml-diagnostics',
			array( $this, 'renderDiagnostics' )
		);
	}

	/**
	 * Enqueue admin assets
	 */
	public function enqueueAdminAssets( $hook ) {
		// Only load on our admin pages
		if ( strpos( $hook, 'voxfor-ml' ) === false && strpos( $hook, 'voxfor-multilanguage' ) === false ) {
			return;
		}

		// Admin CSS
		wp_enqueue_style(
			'voxfor-ml-admin',
			VOXFOR_ML_PLUGIN_URL . 'public/css/admin.css',
			array(),
			VOXFOR_ML_VERSION
		);

		// Admin JS
		wp_enqueue_script(
			'voxfor-ml-admin',
			VOXFOR_ML_PLUGIN_URL . 'public/js/admin.js',
			array( 'jquery', 'wp-api', 'wp-i18n' ),
			VOXFOR_ML_VERSION,
			true
		);

		// Dashboard-specific assets
		if ( $hook === 'toplevel_page_voxfor-multilanguage' ) {
			// Dashboard CSS
			wp_enqueue_style(
				'voxfor-ml-dashboard',
				VOXFOR_ML_PLUGIN_URL . 'public/css/admin/dashboard.css',
				array( 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION
			);

		// Chart.js (local copy)
		wp_enqueue_script(
			'chartjs',
			VOXFOR_ML_PLUGIN_URL . 'public/js/vendor/chart.min.js',
			array(),
			'4.5.1',
			true
		);

			// Dashboard JS (requires Chart.js)
			wp_enqueue_script(
				'voxfor-ml-dashboard',
				VOXFOR_ML_PLUGIN_URL . 'public/js/admin/dashboard.js',
				array( 'jquery', 'voxfor-ml-admin', 'chartjs' ),
				VOXFOR_ML_VERSION,
				true
			);
		}

		// Settings-specific assets - Check for multiple possible hook names
		if ( $hook === 'voxfor-multilanguage_page_voxfor-ml-settings' || 
		     $hook === 'multilanguage_page_voxfor-ml-settings' ||
		     strpos( $hook, 'voxfor-ml-settings' ) !== false ) {
			// Settings CSS
			wp_enqueue_style(
				'voxfor-ml-settings',
				VOXFOR_ML_PLUGIN_URL . 'public/css/admin/settings.css',
				array( 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION
			);

			// Settings JS
			wp_enqueue_script(
				'voxfor-ml-settings',
				VOXFOR_ML_PLUGIN_URL . 'public/js/admin/settings.js',
				array( 'jquery', 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION,
				true
			);

			// Localize settings script
			wp_localize_script(
				'voxfor-ml-settings',
				'voxforSettings',
				array(
					'nonce'   => wp_create_nonce( 'voxfor_ml_admin' ),
					'strings' => array(
						'testing'      => __( 'Testing...', 'voxfor-multilanguage' ),
						'testApiKey'   => __( 'Test API Key', 'voxfor-multilanguage' ),
						'ajaxError'    => __( 'AJAX request failed', 'voxfor-multilanguage' ),
						'showAdvanced' => __( 'Show Advanced Options', 'voxfor-multilanguage' ),
						'hideAdvanced' => __( 'Hide Advanced Options', 'voxfor-multilanguage' ),
					),
				)
			);
		}

		// Comprehensive translator assets
		if ( $hook === 'voxfor-multilanguage_page_voxfor-ml-translate' ||
		     strpos( $hook, 'voxfor-ml-translate' ) !== false ) {
			// Comprehensive translator CSS
			wp_enqueue_style(
				'voxfor-ml-comprehensive-translator',
				VOXFOR_ML_PLUGIN_URL . 'public/css/admin/comprehensive-translator.css',
				array( 'voxfor-ml-admin' ),
				time() // Force reload
			);

			// Comprehensive translator JS
			wp_enqueue_script(
				'voxfor-ml-comprehensive-translator',
				VOXFOR_ML_PLUGIN_URL . 'public/js/admin/comprehensive-translator.js',
				array( 'jquery', 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION,
				true
			);

			// Localize comprehensive translator script
			wp_localize_script(
				'voxfor-ml-comprehensive-translator',
				'voxforComprehensive',
				array(
					'nonce'   => wp_create_nonce( 'voxfor_ml_ajax' ),
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'strings' => array(
						'selectLanguage'          => __( 'Please select at least one language.', 'voxfor-multilanguage' ),
						'confirmTranslation'      => __( 'This will scan and translate all theme texts, which may consume API credits. Continue?', 'voxfor-multilanguage' ),
						'translating'             => __( 'Translating...', 'voxfor-multilanguage' ),
						'starting'                => __( 'Starting comprehensive translation...', 'voxfor-multilanguage' ),
						'confirmCancel'           => __( 'Are you sure you want to cancel the translation? This will stop all API requests immediately.', 'voxfor-multilanguage' ),
						'cancelled'               => __( 'Translation cancelled by user', 'voxfor-multilanguage' ),
						'cancelledMessage'        => __( 'Translation was cancelled. You can restart it anytime.', 'voxfor-multilanguage' ),
						'completed'               => __( 'Comprehensive translation completed successfully!', 'voxfor-multilanguage' ),
						'completedMessage'        => __( 'All selected languages have been processed. Your website is now fully translated.', 'voxfor-multilanguage' ),
						'scanningHomepage'        => __( 'Scanning homepage', 'voxfor-multilanguage' ),
						'processingWooCommerce'   => __( 'Processing WooCommerce texts', 'voxfor-multilanguage' ),
						'processingElementor'     => __( 'Processing Elementor content', 'voxfor-multilanguage' ),
						'processingTheme'         => __( 'Processing theme texts', 'voxfor-multilanguage' ),
						'processingWidgets'       => __( 'Processing widgets and menus', 'voxfor-multilanguage' ),
						'finalizingTranslations'  => __( 'Finalizing translations', 'voxfor-multilanguage' ),
						'languageCompleted'       => __( 'Language completed', 'voxfor-multilanguage' ),
						'translationFailed'       => __( 'Translation failed:', 'voxfor-multilanguage' ),
						'unknownError'            => __( 'Unknown error', 'voxfor-multilanguage' ),
						'networkError'            => __( 'Network error occurred', 'voxfor-multilanguage' ),
						'startTranslation'        => __( 'Start Comprehensive Translation', 'voxfor-multilanguage' ),
						'selectContentType'       => __( 'Select content type...', 'voxfor-multilanguage' ),
						'loading'                 => __( 'Loading...', 'voxfor-multilanguage' ),
						'errorLoadingContent'     => __( 'Error loading content', 'voxfor-multilanguage' ),
						'noContentFound'          => __( 'No content found', 'voxfor-multilanguage' ),
					'selected'                => __( 'selected', 'voxfor-multilanguage' ),
				),
			)
		);

		// Slug Manager JS (for URL localization feature)
		wp_enqueue_script(
			'voxfor-ml-slug-manager',
			VOXFOR_ML_PLUGIN_URL . 'public/js/admin/slug-manager.js',
			array( 'jquery', 'voxfor-ml-admin' ),
			VOXFOR_ML_VERSION,
			true
		);

		// Localize slug manager script
		wp_localize_script(
			'voxfor-ml-slug-manager',
			'voxforMLSlugManager',
			array(
				'restUrl'          => rest_url( 'voxfor-ml/v1/slugs/generate-translated' ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'localizingText'   => __( 'Localizing...', 'voxfor-multilanguage' ),
				'startingText'     => __( 'Starting website URL localization...', 'voxfor-multilanguage' ),
				'localizedForText' => __( 'Localized URLs for:', 'voxfor-multilanguage' ),
				'postsText'        => __( 'posts/pages/products', 'voxfor-multilanguage' ),
				'errorText'        => __( 'An error occurred during slug generation.', 'voxfor-multilanguage' ),
				'networkErrorText' => __( 'Network error occurred. Please try again.', 'voxfor-multilanguage' ),
				'buttonText'       => __( 'Localize All Website URLs', 'voxfor-multilanguage' ),
			)
		);
	}

	// Bulk translation assets
	if ( $hook === 'voxfor-multilanguage_page_voxfor-ml-bulk-translation' ) {
			// Bulk translation CSS
			wp_enqueue_style(
				'voxfor-ml-bulk-translation',
				VOXFOR_ML_PLUGIN_URL . 'public/css/admin/bulk-translation.css',
				array( 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION
			);

			// Bulk translation JS
			wp_enqueue_script(
				'voxfor-ml-bulk-translation',
				VOXFOR_ML_PLUGIN_URL . 'public/js/admin/bulk-translation.js',
				array( 'jquery', 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION,
				true
			);

			// Localize bulk translation script
			wp_localize_script(
				'voxfor-ml-bulk-translation',
				'voxforBulkTranslation',
				array(
					'nonce'   => wp_create_nonce( 'voxfor_ml_ajax' ),
					'strings' => array(
						'selectLanguage'       => __( 'Please select at least one language', 'voxfor-multilanguage' ),
						'minutes'              => __( 'minutes', 'voxfor-multilanguage' ),
						'hours'                => __( 'hours', 'voxfor-multilanguage' ),
						// translators: %1$d is the number of posts, %2$d is the number of languages, %3$d is the total translations, %4$s is the estimated time
						'estimateMessage'      => __( 'Estimated: %1$d posts × %2$d languages = %3$d translations. Time needed: approximately %4$s', 'voxfor-multilanguage' ),
						'confirmStart'         => __( 'Are you sure you want to start bulk translation? This process will run in the background.', 'voxfor-multilanguage' ),
						'starting'             => __( 'Starting...', 'voxfor-multilanguage' ),
						'errorStarting'        => __( 'Error starting bulk translation', 'voxfor-multilanguage' ),
						'startBulkTranslation' => __( 'Start Bulk Translation', 'voxfor-multilanguage' ),
						'errorPausing'         => __( 'Error pausing job', 'voxfor-multilanguage' ),
						'errorResuming'        => __( 'Error resuming job', 'voxfor-multilanguage' ),
						'confirmCancel'        => __( 'Are you sure you want to cancel this job?', 'voxfor-multilanguage' ),
						'errorCancelling'      => __( 'Error cancelling job', 'voxfor-multilanguage' ),
					),
				)
			);
		}

		// Tools page assets
		if ( $hook === 'voxfor-multilanguage_page_voxfor-ml-tools' ) {
			// Tools CSS
			wp_enqueue_style(
				'voxfor-ml-tools',
				VOXFOR_ML_PLUGIN_URL . 'public/css/admin/tools.css',
				array( 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION
			);

			// Tools JS
			wp_enqueue_script(
				'voxfor-ml-tools',
				VOXFOR_ML_PLUGIN_URL . 'public/js/admin/tools.js',
				array( 'jquery', 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION,
				true
			);

		// Localize tools script
		wp_localize_script(
			'voxfor-ml-tools',
			'voxforTools',
			array(
				'nonce'   => wp_create_nonce( 'voxfor_ml_ajax' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'strings' => array(
					'startingQueue'         => __( 'Starting queue processing...', 'voxfor-multilanguage' ),
					'queueComplete'         => __( 'Queue processing complete!', 'voxfor-multilanguage' ),
					'processing'            => __( 'Processing...', 'voxfor-multilanguage' ),
				),
			)
		);

		// Slug Manager JS
		wp_enqueue_script(
			'voxfor-ml-slug-manager',
			VOXFOR_ML_PLUGIN_URL . 'public/js/admin/slug-manager.js',
			array( 'jquery', 'voxfor-ml-admin' ),
			VOXFOR_ML_VERSION,
			true
		);

		// Localize slug manager script
		wp_localize_script(
			'voxfor-ml-slug-manager',
			'voxforMLSlugManager',
			array(
				'restUrl'          => rest_url( 'voxfor-ml/v1/slugs/generate-translated' ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'localizingText'   => __( 'Localizing...', 'voxfor-multilanguage' ),
				'startingText'     => __( 'Starting website URL localization...', 'voxfor-multilanguage' ),
				'localizedForText' => __( 'Localized URLs for:', 'voxfor-multilanguage' ),
				'postsText'        => __( 'posts/pages/products', 'voxfor-multilanguage' ),
				'errorText'        => __( 'An error occurred during slug generation.', 'voxfor-multilanguage' ),
				'networkErrorText' => __( 'Network error occurred. Please try again.', 'voxfor-multilanguage' ),
				'buttonText'       => __( 'Localize All Website URLs', 'voxfor-multilanguage' ),
			)
		);
	}

		// Exclusions page assets - check multiple possible hook names
		if ( $hook === 'voxfor-multilanguage_page_voxfor-ml-exclusions' || 
		     strpos( $hook, 'voxfor-ml-exclusions' ) !== false ) {
			// Exclusions CSS
			wp_enqueue_style(
				'voxfor-ml-exclusions',
				VOXFOR_ML_PLUGIN_URL . 'public/css/admin/exclusions.css',
				array( 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION
			);

			// Exclusions JS
			wp_enqueue_script(
				'voxfor-ml-exclusions',
				VOXFOR_ML_PLUGIN_URL . 'public/js/admin/exclusions.js',
				array( 'jquery', 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION,
				true
			);

			// Localize exclusions script
			wp_localize_script(
				'voxfor-ml-exclusions',
				'voxforExclusions',
				array(
					'nonce'   => wp_create_nonce( 'voxfor_ml_ajax' ),
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'strings' => array(
						'error'             => __( 'Error', 'voxfor-multilanguage' ),
						'editRule'          => __( 'Edit Exclusion Rule', 'voxfor-multilanguage' ),
						'updateRule'        => __( 'Update Rule', 'voxfor-multilanguage' ),
						'addNewRule'        => __( 'Add New Exclusion Rule', 'voxfor-multilanguage' ),
						'addRule'           => __( 'Add Exclusion Rule', 'voxfor-multilanguage' ),
						'confirmSeed'       => __( 'This will add recommended exclusion rules for WooCommerce, page builders, and technical elements. Continue?', 'voxfor-multilanguage' ),
						'addingExclusions'  => __( 'Adding exclusions...', 'voxfor-multilanguage' ),
						'failedToAdd'       => __( 'Failed to add exclusions', 'voxfor-multilanguage' ),
						'failedToConnect'   => __( 'Failed to connect to server', 'voxfor-multilanguage' ),
					),
				)
			);
		}

		// Header Footer Translator page assets
		if ( $hook === 'voxfor-multilanguage_page_voxfor-ml-header-footer' ) {
			// Header Footer Translator JS
			wp_enqueue_script(
				'voxfor-ml-header-footer-translator',
				VOXFOR_ML_PLUGIN_URL . 'public/js/admin/header-footer-translator.js',
				array( 'jquery', 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION,
				true
			);

			// Localize header footer translator script
			wp_localize_script(
				'voxfor-ml-header-footer-translator',
				'voxforHeaderFooter',
				array(
					'nonce'   => wp_create_nonce( 'voxfor_ml_admin' ),
					'strings' => array(
						'collecting'            => __( 'Collecting...', 'voxfor-multilanguage' ),
						'collectTexts'          => __( 'Collect Texts', 'voxfor-multilanguage' ),
						'error'                 => __( 'Error', 'voxfor-multilanguage' ),
						'noTextsFound'          => __( 'No texts found in header/footer.', 'voxfor-multilanguage' ),
						'selectLanguage'        => __( 'Please select at least one language.', 'voxfor-multilanguage' ),
						'collectFirst'          => __( 'Please collect texts first.', 'voxfor-multilanguage' ),
						'translating'           => __( 'Translating...', 'voxfor-multilanguage' ),
						'translateHeaderFooter' => __( 'Translate Header & Footer', 'voxfor-multilanguage' ),
						'translationResults'    => __( 'Translation Results', 'voxfor-multilanguage' ),
						// translators: %1$d is the number of successfully translated texts, %2$d is the number of languages
						'successfullyTranslated' => __( 'Successfully translated %1$d texts to %2$d languages.', 'voxfor-multilanguage' ),
						// translators: %d is the number of failed translations
						'failed'                => __( 'Failed: %d translations.', 'voxfor-multilanguage' ),
						'errorDetails'          => __( 'Error Details', 'voxfor-multilanguage' ),
					),
				)
			);
		}

		// Cost Analytics page assets
		if ( $hook === 'voxfor-multilanguage_page_voxfor-ml-cost-analytics' ||
		     strpos( $hook, 'voxfor-ml-cost-analytics' ) !== false ) {
			// Cost Analytics CSS
			wp_enqueue_style(
				'voxfor-ml-cost-analytics',
				VOXFOR_ML_PLUGIN_URL . 'public/css/admin/cost-analytics.css',
				array( 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION
			);

			// Cost Analytics JS
			wp_enqueue_script(
				'voxfor-ml-cost-analytics',
				VOXFOR_ML_PLUGIN_URL . 'public/js/admin/cost-analytics.js',
				array( 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION,
				true
			);

			// Localize cost analytics script
			$stats_manager = new \VoxforML\Analytics\StatisticsManager();
			$cost_breakdown = $stats_manager->getCostBreakdown( 30 );
			
			wp_localize_script(
				'voxfor-ml-cost-analytics',
				'voxforCostAnalytics',
				array(
					'breakdown' => array_slice( $cost_breakdown, 0, 14 ),
					'strings'   => array(
						'charactersTranslated' => __( 'Characters Translated', 'voxfor-multilanguage' ),
						'estimatedCost'        => __( 'Estimated Cost ($)', 'voxfor-multilanguage' ),
						'date'                 => __( 'Date', 'voxfor-multilanguage' ),
						'characters'           => __( 'Characters', 'voxfor-multilanguage' ),
						'cost'                 => __( 'Cost ($)', 'voxfor-multilanguage' ),
					),
				)
			);
		}

		// Diagnostics page assets
		if ( $hook === 'voxfor-multilanguage_page_voxfor-ml-diagnostics' ) {
			// Diagnostics CSS
			wp_enqueue_style(
				'voxfor-ml-diagnostics',
				VOXFOR_ML_PLUGIN_URL . 'public/css/admin/diagnostics.css',
				array( 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION
			);

			// Diagnostics JS
			wp_enqueue_script(
				'voxfor-ml-diagnostics',
				VOXFOR_ML_PLUGIN_URL . 'public/js/admin/diagnostics.js',
				array( 'jquery', 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION,
				true
			);

			wp_localize_script(
				'voxfor-ml-diagnostics',
				'voxforDiagnostics',
				array(
					'nonce'   => wp_create_nonce( 'voxfor_ml_diagnostics' ),
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'strings' => array(
						'fixing'     => __( 'Fixing...', 'voxfor-multilanguage' ),
						'fixIssue'   => __( 'Fix Issue', 'voxfor-multilanguage' ),
						'testing'    => __( 'Testing...', 'voxfor-multilanguage' ),
						'testStorage' => __( 'Test Storage', 'voxfor-multilanguage' ),
						'running'    => __( 'Running...', 'voxfor-multilanguage' ),
						'runCheck'   => __( 'Run Check', 'voxfor-multilanguage' ),
						'error'      => __( 'Error', 'voxfor-multilanguage' ),
					),
				)
			);
		}

		// Glossary page assets
		if ( $hook === 'voxfor-multilanguage_page_voxfor-ml-glossary' ) {
			// Glossary CSS
			wp_enqueue_style(
				'voxfor-ml-glossary',
				VOXFOR_ML_PLUGIN_URL . 'public/css/admin/glossary.css',
				array( 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION
			);

			// Glossary JS
			wp_enqueue_script(
				'voxfor-ml-glossary',
				VOXFOR_ML_PLUGIN_URL . 'public/js/admin/glossary.js',
				array( 'jquery', 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION,
				true
			);

			wp_localize_script(
				'voxfor-ml-glossary',
				'voxforGlossary',
				array(
					'nonce'   => wp_create_nonce( 'voxfor_ml_glossary' ),
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'strings' => array(
						'adding'    => __( 'Adding...', 'voxfor-multilanguage' ),
						'addTerm'   => __( 'Add Term', 'voxfor-multilanguage' ),
						'deleting'  => __( 'Deleting...', 'voxfor-multilanguage' ),
						'delete'    => __( 'Delete', 'voxfor-multilanguage' ),
						'confirmDelete' => __( 'Are you sure you want to delete this term?', 'voxfor-multilanguage' ),
						'error'     => __( 'Error', 'voxfor-multilanguage' ),
					),
				)
			);
		}

		// Language SEO page assets
		if ( $hook === 'voxfor-multilanguage_page_voxfor-ml-language-seo' ) {
			// Language SEO CSS
			wp_enqueue_style(
				'voxfor-ml-language-seo',
				VOXFOR_ML_PLUGIN_URL . 'public/css/admin/language-seo.css',
				array( 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION
			);

			// Language SEO JS
			wp_enqueue_script(
				'voxfor-ml-language-seo',
				VOXFOR_ML_PLUGIN_URL . 'public/js/admin/language-seo.js',
				array( 'jquery', 'voxfor-ml-admin' ),
				VOXFOR_ML_VERSION,
				true
			);

			wp_localize_script(
				'voxfor-ml-language-seo',
				'voxforLanguageSEO',
				array(
					'nonce'   => wp_create_nonce( 'voxfor_ml_seo' ),
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'strings' => array(
						'saving'    => __( 'Saving...', 'voxfor-multilanguage' ),
						'save'      => __( 'Save Settings', 'voxfor-multilanguage' ),
						'generating' => __( 'Generating...', 'voxfor-multilanguage' ),
						'generate'  => __( 'Generate Sitemap', 'voxfor-multilanguage' ),
						'error'     => __( 'Error', 'voxfor-multilanguage' ),
					),
				)
			);
		}

		// Translation Memory page assets - check multiple possible hook names
		if ( $hook === 'voxfor-multilanguage_page_voxfor-ml-memory' ||
		     strpos( $hook, 'voxfor-ml-memory' ) !== false ) {
			// Translation Memory CSS
		wp_enqueue_style(
			'voxfor-ml-translation-memory',
			VOXFOR_ML_PLUGIN_URL . 'public/css/admin/translation-memory.css',
			array( 'voxfor-ml-admin' ),
			time() // Force reload
		);

		// Translation Memory JS
		wp_enqueue_script(
			'voxfor-ml-translation-memory',
			VOXFOR_ML_PLUGIN_URL . 'public/js/admin/translation-memory.js',
			array( 'jquery', 'voxfor-ml-admin' ),
			VOXFOR_ML_VERSION,
			true
		);

			wp_localize_script(
				'voxfor-ml-translation-memory',
				'voxforTranslationMemory',
				array(
					'nonce'   => wp_create_nonce( 'voxfor_ml_ajax' ),
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'strings' => array(
						'searching'  => __( 'Searching...', 'voxfor-multilanguage' ),
						'search'     => __( 'Search', 'voxfor-multilanguage' ),
						'clearing'   => __( 'Clearing...', 'voxfor-multilanguage' ),
						'clear'      => __( 'Clear Memory', 'voxfor-multilanguage' ),
						'confirmClear' => __( 'Are you sure you want to clear the translation memory?', 'voxfor-multilanguage' ),
						'error'      => __( 'Error', 'voxfor-multilanguage' ),
					),
				)
			);
	}

		// React for settings page - settings.js removed as it doesn't exist
		// if ( $hook === 'voxfor-multilanguage_page_voxfor-ml-settings' ) {
		//     wp_enqueue_script(
		//         'voxfor-ml-settings',
		//         VOXFOR_ML_PLUGIN_URL . 'public/js/settings.js',
		//         array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
		//         VOXFOR_ML_VERSION,
		//         true
		//     );
		// }

		// Localize script
		wp_localize_script(
			'voxfor-ml-admin',
			'voxforMLAdmin',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'restUrl'          => rest_url( 'voxfor-ml/v1/' ),
				'nonce'            => wp_create_nonce( 'voxfor_ml_ajax' ),
				'restNonce'        => wp_create_nonce( 'wp_rest' ),
				'translationNonce' => wp_create_nonce( 'voxfor_ml_translation_status' ),
				'strings'          => array(
					'confirmDelete'       => __( 'Are you sure you want to delete this?', 'voxfor-multilanguage' ),
					'saving'              => __( 'Saving...', 'voxfor-multilanguage' ),
					'saved'               => __( 'Saved!', 'voxfor-multilanguage' ),
					'error'               => __( 'An error occurred', 'voxfor-multilanguage' ),
					'translating'         => __( 'Translating...', 'voxfor-multilanguage' ),
					'confirmTranslateAll' => __( 'Translate this content to all languages?', 'voxfor-multilanguage' ),
					'testApiKey'          => __( 'Test API Key', 'voxfor-multilanguage' ),
					'testing'             => __( 'Testing...', 'voxfor-multilanguage' ),
				),
			)
		);

		// Enqueue API management script globally for admin bar
		wp_enqueue_script(
			'voxfor-ml-api-management',
			VOXFOR_ML_PLUGIN_URL . 'public/js/api-management.js',
			array( 'jquery' ),
			VOXFOR_ML_VERSION,
			true
		);

		// Localize API management script
		wp_localize_script(
			'voxfor-ml-api-management',
			'voxforMLApiMgmt',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'toggle_api'     => wp_create_nonce( 'voxfor_ml_toggle_api' ),
					'emergency_stop' => wp_create_nonce( 'voxfor_ml_emergency_stop' ),
				),
				'strings' => array(
					'confirmToggle'        => __( 'Are you sure you want to toggle the API status?', 'voxfor-multilanguage' ),
					'confirmEmergencyStop' => __( 'EMERGENCY STOP: This will immediately disable all API calls and pause translations. Continue?', 'voxfor-multilanguage' ),
					'confirmNoLimits'      => __( 'Warning: You are enabling API calls with no usage limits. This could result in unexpected charges. Continue?', 'voxfor-multilanguage' ),
					'pauseApi'             => __( 'Pause API', 'voxfor-multilanguage' ),
					'resumeApi'            => __( 'Resume API', 'voxfor-multilanguage' ),
					'error'                => __( 'An error occurred', 'voxfor-multilanguage' ),
					'requestFailed'        => __( 'Request failed: ', 'voxfor-multilanguage' ),
					// translators: %used% is characters used, %limit% is character limit, %percentage% is usage percentage
					'todayUsage'           => __( 'Today: %used%/%limit% chars (%percentage%%)', 'voxfor-multilanguage' ),
					// translators: %used% is the number of characters used today
					'todayUsageNoLimit'    => __( 'Today: %used% chars', 'voxfor-multilanguage' ),
				),
			)
		);
	}

	/**
	 * Add settings link to plugins page
	 */
	public function addSettingsLink( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=voxfor-ml-settings' ),
			__( 'Settings', 'voxfor-multilanguage' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add meta boxes (old method - now replaced by TranslationStatusMetaBox class)
	 */
	public function addMetaBoxes() {
		// Meta boxes are now handled by TranslationStatusMetaBox class
		// This method is kept for compatibility but disabled
		return;
	}

	/**
	 * Render translation status meta box
	 */
	public function renderTranslationStatusMetaBox( $post ) {
		$exclude    = get_post_meta( $post->ID, '_voxfor_ml_exclude', true );
		$languages  = $this->plugin->getAvailableLanguages();
		$translator = $this->plugin->getComponent( 'translator' );

		wp_nonce_field( 'voxfor_ml_meta_box', 'voxfor_ml_meta_box_nonce' );

		include VOXFOR_ML_PLUGIN_DIR . 'templates/admin/meta-box-translation-status.php';
	}

	/**
	 * Save post meta
	 */
	public function savePostMeta( $post_id ) {
		// Check nonce
		if ( ! isset( $_POST['voxfor_ml_meta_box_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['voxfor_ml_meta_box_nonce'] ) ), 'voxfor_ml_meta_box' ) ) {
			return;
		}

		// Check autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save exclude status
		$exclude = isset( $_POST['voxfor_ml_exclude'] ) ? 1 : 0;
		update_post_meta( $post_id, '_voxfor_ml_exclude', $exclude );
	}

	/**
	 * Admin notices
	 */
	public function adminNotices() {
		// Check if API key is set
		$api_key = \VoxforML\Utils\EncryptionHandler::getApiKey();

		if ( empty( $api_key ) ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %1$s and %2$s are HTML link tags around the word "settings" */
						esc_html__( 'Voxfor Multilanguage: Please configure your DeepL API key in the %1$ssettings%2$s.', 'voxfor-multilanguage' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=voxfor-ml-settings' ) ) . '">',
						'</a>'
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Sanitize API key to prevent empty overwrites
	 */
	public function sanitizeApiKey( $value ) {
		
		// Check if user actually intended to change the API key
		$api_key_changed = isset( $_POST['voxfor_ml_api_key_changed'] ) ? sanitize_text_field( wp_unslash( $_POST['voxfor_ml_api_key_changed'] ) ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$original_masked = isset( $_POST['voxfor_ml_api_key_original'] ) ? sanitize_text_field( wp_unslash( $_POST['voxfor_ml_api_key_original'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		
		// Trim the value to remove any whitespace
		$value = trim( $value );
		
		// If user didn't change the field or submitted the masked value, don't process
		if ( $api_key_changed !== '1' || $value === $original_masked ) {
			// Always return empty string - the real key is stored encrypted separately
			return '';
		}
		
		// If the value is empty after user indicated they changed it, show error
		if ( empty( $value ) ) {
			add_settings_error(
				'voxfor_ml_settings',
				'api_key_empty',
				__( 'Please enter a valid API key.', 'voxfor-multilanguage' ),
				'error'
			);
			return '';
		}
		
		// Check for placeholder or masked text
		if ( $value === 'Enter your DeepL API key' || 
			 preg_match( '/^•+$/', $value ) ||
			 preg_match( '/^\*+$/', $value ) ) {
			add_settings_error(
				'voxfor_ml_settings',
				'api_key_invalid',
				__( 'Please enter a real API key, not placeholder text.', 'voxfor-multilanguage' ),
				'error'
			);
			return '';
		}

		// Validate API key format (DeepL keys are typically 36-40 characters)
		if ( strlen( $value ) < 20 || strlen( $value ) > 100 ) {
			add_settings_error(
				'voxfor_ml_settings',
				'api_key_format',
				__( 'API key format appears invalid. DeepL API keys should be 20-100 characters long.', 'voxfor-multilanguage' ),
				'error'
			);
			return '';
		}

		// If a new key is provided, encrypt and store it
		$store_result = \VoxforML\Utils\EncryptionHandler::storeApiKey( $value );
		
		if ( $store_result ) {
			add_settings_error(
				'voxfor_ml_settings',
				'api_key_saved',
				__( 'API key saved and encrypted successfully.', 'voxfor-multilanguage' ),
				'success'
			);

			// Always return empty string - the real key is stored encrypted separately
			return '';
		}

		// If encryption failed, show error
		add_settings_error(
			'voxfor_ml_settings',
			'api_key_error',
			__( 'Failed to save API key. Please try again.', 'voxfor-multilanguage' ),
			'error'
		);
		// Always return empty string - the real key is stored encrypted separately
		return '';
	}

	/**
	 * Register settings
	 */
	public function registerSettings() {
		// Main settings group
		register_setting(
			'voxfor_ml_settings',
			'voxfor_ml_deepl_api_key',
			array(
				'sanitize_callback' => array( $this, 'sanitizeApiKey' ),
			)
		);
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_api_key_changed', array( 'sanitize_callback' => array( $this, 'sanitizeTextSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_api_key_original', array( 'sanitize_callback' => array( $this, 'sanitizeTextSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_languages', array( 'sanitize_callback' => array( $this, 'sanitizeArraySetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_default_language', array( 'sanitize_callback' => array( $this, 'sanitizeTextSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_display_label', array( 'sanitize_callback' => array( $this, 'sanitizeTextSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_display_flag', array( 'sanitize_callback' => array( $this, 'sanitizeTextSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_display_prefix', array( 'sanitize_callback' => array( $this, 'sanitizeTextSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_auto_redirect', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_cache_ttl', array( 'sanitize_callback' => array( $this, 'sanitizeIntegerSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_rate_limit', array( 'sanitize_callback' => array( $this, 'sanitizeIntegerSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_enable_hreflang', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_translate_slugs', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_immediate_translation', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_noindex_preparing', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_translate_image_alt', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_include_post_tags_sitemap', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_include_product_tags_sitemap', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_widget_style', array( 'sanitize_callback' => array( $this, 'sanitizeTextSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_show_flags', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_show_names', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_show_native_names', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_floating_switcher', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_floating_position', array( 'sanitize_callback' => array( $this, 'sanitizeTextSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_enable_object_cache', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_enable_lazy_loading', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );

		// WooCommerce settings
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_wc_translate_products', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_wc_translate_categories', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_wc_translate_attributes', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_wc_translate_ui', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_wc_translate_shop_pages', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_wc_preserve_currency', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_wc_preserve_cart', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );

		// API Management settings
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_api_enabled', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_daily_credit_limit', array( 'sanitize_callback' => array( $this, 'sanitizeIntegerSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_monthly_credit_limit', array( 'sanitize_callback' => array( $this, 'sanitizeIntegerSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_alert_daily_80', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_settings', 'voxfor_ml_alert_monthly_80', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );

		// Cost alert settings
		register_setting( 'voxfor_ml_cost_alerts', 'voxfor_ml_daily_cost_alert', array( 'sanitize_callback' => array( $this, 'sanitizeIntegerSetting' ) ) );
		register_setting( 'voxfor_ml_cost_alerts', 'voxfor_ml_monthly_cost_alert', array( 'sanitize_callback' => array( $this, 'sanitizeIntegerSetting' ) ) );
		register_setting( 'voxfor_ml_cost_alerts', 'voxfor_ml_cost_alert_email', array( 'sanitize_callback' => array( $this, 'sanitizeEmailSetting' ) ) );

		// API Credit Management settings
		register_setting( 'voxfor_ml_api_management', 'voxfor_ml_api_enabled', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_api_management', 'voxfor_ml_api_emergency_stop', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_api_management', 'voxfor_ml_daily_credit_limit', array( 'sanitize_callback' => array( $this, 'sanitizeIntegerSetting' ) ) );
		register_setting( 'voxfor_ml_api_management', 'voxfor_ml_monthly_credit_limit', array( 'sanitize_callback' => array( $this, 'sanitizeIntegerSetting' ) ) );
		register_setting( 'voxfor_ml_api_management', 'voxfor_ml_track_usage', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_api_management', 'voxfor_ml_usage_retention_days', array( 'sanitize_callback' => array( $this, 'sanitizeIntegerSetting' ) ) );
		register_setting( 'voxfor_ml_api_management', 'voxfor_ml_alert_daily_80', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_api_management', 'voxfor_ml_alert_daily_90', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_api_management', 'voxfor_ml_alert_monthly_80', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
		register_setting( 'voxfor_ml_api_management', 'voxfor_ml_alert_monthly_90', array( 'sanitize_callback' => array( $this, 'sanitizeBooleanSetting' ) ) );
	}

	/**
	 * Sanitize text field setting
	 */
	public function sanitizeTextSetting( $value ) {
		return sanitize_text_field( $value );
	}

	/**
	 * Sanitize boolean setting
	 */
	public function sanitizeBooleanSetting( $value ) {
		return $value ? 1 : 0;
	}

	/**
	 * Sanitize integer setting
	 */
	public function sanitizeIntegerSetting( $value ) {
		return intval( $value );
	}

	/**
	 * Sanitize array setting
	 */
	public function sanitizeArraySetting( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_map( 'sanitize_text_field', $value );
	}

	/**
	 * Sanitize email setting
	 */
	public function sanitizeEmailSetting( $value ) {
		return sanitize_email( $value );
	}

	/**
	 * Debug option updates (disabled in production)
	 */
	public function debugOptionUpdate( $option_name, $old_value, $new_value ) {
		// Debug logging disabled in production
	}

	/**
	 * Handle admin actions
	 */
	public function handleAdminActions() {
		// Note: Settings submission is now handled by WordPress Settings API
		// The custom handleSettingsSubmission method was causing conflicts

		// Handle cache clear
		if ( isset( $_POST['voxfor_ml_clear_cache'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->clearCache();
		}
	}

	/**
	 * Add language column to posts list
	 */
	public function addLanguageColumn( $columns ) {
		$columns['voxfor_ml_translations'] = __( 'Translations', 'voxfor-multilanguage' );
		return $columns;
	}

	/**
	 * Display language column
	 */
	public function displayLanguageColumn( $column, $post_id ) {
		if ( $column === 'voxfor_ml_translations' ) {
			$languages = array_diff( $this->plugin->getAvailableLanguages(), array( 'en' ) );
			$memory    = new \VoxforML\Database\TranslationMemory();

			echo '<div class="voxfor-ml-language-flags">';

			foreach ( $languages as $lang ) {
				$translations    = $memory->getPostTranslations( $post_id, $lang );
				$has_translation = ! empty( $translations );

				$class = $has_translation ? 'has-translation' : 'no-translation';
				$title = $has_translation ?
					sprintf( /* translators: %s is the language code */ __( 'Translated to %s', 'voxfor-multilanguage' ), $lang ) :
					sprintf( /* translators: %s is the language code */ __( 'Not translated to %s', 'voxfor-multilanguage' ), $lang );

				printf(
					'<span class="voxfor-ml-lang-flag %s" title="%s">%s</span>',
					esc_attr( $class ),
					esc_attr( $title ),
					esc_html( strtoupper( $lang ) )
				);
			}

			echo '</div>';
		}
	}

	/**
	 * Add bulk actions
	 */
	public function addBulkActions( $bulk_actions ) {
		$bulk_actions['voxfor_ml_translate'] = __( 'Translate', 'voxfor-multilanguage' );
		$bulk_actions['voxfor_ml_exclude']   = __( 'Exclude from translation', 'voxfor-multilanguage' );
		$bulk_actions['voxfor_ml_include']   = __( 'Include in translation', 'voxfor-multilanguage' );

		return $bulk_actions;
	}

	/**
	 * Handle bulk actions
	 */
	public function handleBulkActions( $redirect_to, $doaction, $post_ids ) {
		if ( $doaction === 'voxfor_ml_translate' ) {
			// Queue posts for translation
			$memory    = new \VoxforML\Database\TranslationMemory();
			$languages = array_diff( $this->plugin->getAvailableLanguages(), array( 'en' ) );

			foreach ( $post_ids as $post_id ) {
				$post = get_post( $post_id );
				if ( $post ) {
					foreach ( $languages as $lang ) {
						$memory->queueForTranslation( $post->post_title, $lang, 'title', $post_id );
						$memory->queueForTranslation( $post->post_content, $lang, 'content', $post_id );

						if ( $post->post_excerpt ) {
							$memory->queueForTranslation( $post->post_excerpt, $lang, 'excerpt', $post_id );
						}
					}
				}
			}

			$redirect_to = add_query_arg( 'voxfor_ml_queued', count( $post_ids ), $redirect_to );
		} elseif ( $doaction === 'voxfor_ml_exclude' ) {
			foreach ( $post_ids as $post_id ) {
				update_post_meta( $post_id, '_voxfor_ml_exclude', 1 );
			}

			$redirect_to = add_query_arg( 'voxfor_ml_excluded', count( $post_ids ), $redirect_to );
		} elseif ( $doaction === 'voxfor_ml_include' ) {
			foreach ( $post_ids as $post_id ) {
				delete_post_meta( $post_id, '_voxfor_ml_exclude' );
			}

			$redirect_to = add_query_arg( 'voxfor_ml_included', count( $post_ids ), $redirect_to );
		}

		return $redirect_to;
	}

	/**
	 * Render dashboard page
	 */
	public function renderDashboard() {
		$memory = new \VoxforML\Database\TranslationMemory();
		$stats  = $memory->getStatistics();

		// Get additional data for charts and analytics
		$activity_data = $this->getTranslationActivity();

		global $wpdb;
		$debug_langs = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT language_code, COUNT(*) as count, 
                    MIN(created_at) as first_translation,
                    MAX(created_at) as last_translation
             FROM {$wpdb->prefix}voxfor_ml_translations 
             GROUP BY language_code 
             ORDER BY count DESC"
		);

		// Localize dashboard script with data
		wp_localize_script(
			'voxfor-ml-dashboard',
			'voxforDashboard',
			array(
				'activityData' => $activity_data,
			)
		);

		// This will help us debug what's happening
		// Language distribution data processed successfully

		include VOXFOR_ML_PLUGIN_DIR . 'templates/admin/dashboard.php';
	}

	/**
	 * Render settings page
	 */
	public function renderSettings() {
		include VOXFOR_ML_PLUGIN_DIR . 'templates/admin/settings.php';
	}

	/**
	 * Render translation memory page
	 */
	public function renderTranslationMemory() {
		$memory = new \VoxforML\Database\TranslationMemory();

		// Handle search and pagination parameters
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$language      = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$context       = isset( $_GET['context'] ) ? sanitize_text_field( wp_unslash( $_GET['context'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$specific_item = isset( $_GET['specific_item'] ) ? sanitize_text_field( wp_unslash( $_GET['specific_item'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab           = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page  = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page      = 20; // More reasonable page size

		// Get total count for pagination
		$total_count = $memory->getTranslationCount( $search, $language, $context, $specific_item, $tab );

		// Get translations with offset
		$offset       = ( $current_page - 1 ) * $per_page;
		$translations = $memory->searchTranslations( $search, $language, $context, $per_page, $offset, $specific_item, $tab );

		// Calculate pagination data
		$total_pages = ceil( $total_count / $per_page );

		include VOXFOR_ML_PLUGIN_DIR . 'templates/admin/translation-memory.php';
	}



	/**
	 * AJAX: Toggle API
	 */
	public function ajaxToggleApi() {
		$credit_manager = $this->plugin->getComponent( 'api_credit_manager' );
		if ( $credit_manager ) {
			$credit_manager->ajaxToggleApi();
		} else {
			wp_send_json_error( array( 'message' => __( 'API Credit Manager not available', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * AJAX: Emergency Stop
	 */
	public function ajaxEmergencyStop() {
		$credit_manager = $this->plugin->getComponent( 'api_credit_manager' );
		if ( $credit_manager ) {
			$credit_manager->ajaxEmergencyStop();
		} else {
			wp_send_json_error( array( 'message' => __( 'API Credit Manager not available', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * AJAX: Get Usage Stats
	 */
	public function ajaxGetUsageStats() {
		$credit_manager = $this->plugin->getComponent( 'api_credit_manager' );
		if ( $credit_manager ) {
			$credit_manager->ajaxGetUsageStats();
		} else {
			wp_send_json_error( array( 'message' => __( 'API Credit Manager not available', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * Render glossary page
	 */
	public function renderGlossary() {
		// Security: Verify user has permission
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'voxfor-multilanguage' ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'glossary';

		// Handle form submission
		if ( isset( $_POST['add_glossary_term'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// Verify nonce and capability before processing
			if ( ! isset( $_POST['voxfor_ml_glossary_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['voxfor_ml_glossary_nonce'] ) ), 'voxfor_ml_glossary_action' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'voxfor-multilanguage' ) );
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'voxfor-multilanguage' ) );
			}

			$this->addGlossaryTerm();
		}

		// Handle import
		if ( isset( $_POST['import_glossary'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// Verify nonce and capability before processing
			if ( ! isset( $_POST['voxfor_ml_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['voxfor_ml_import_nonce'] ) ), 'voxfor_ml_import_glossary' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'voxfor-multilanguage' ) );
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'voxfor-multilanguage' ) );
			}

			$this->handleImportGlossary();
		}

		// Handle redirect messages (require nonce to prevent forged URLs)
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['message'], $_GET['type'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'voxfor_ml_glossary_notice' ) ) {
			add_settings_error( 'voxfor_ml_messages', 'redirect_message', urldecode( sanitize_text_field( wp_unslash( $_GET['message'] ) ) ), sanitize_text_field( wp_unslash( $_GET['type'] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Get glossary terms
		$terms = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `%1s` ORDER BY language_code, term", $table_name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder

		include VOXFOR_ML_PLUGIN_DIR . 'templates/admin/glossary.php';
	}

	/**
	 * Render exclusions page
	 */
	public function renderExclusions() {
		// Security: Verify user has permission
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'voxfor-multilanguage' ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions';

		// Handle redirect messages (require nonce to prevent forged URLs)
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['message'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'voxfor_ml_exclusions_notice' ) ) {
			$message = urldecode( sanitize_text_field( wp_unslash( $_GET['message'] ) ) );
			$type    = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : 'info';
			add_settings_error( 'voxfor_ml_messages', 'redirect_message', $message, $type );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Handle form submission
 		if ( isset( $_POST['add_exclusion_rule'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// Verify nonce and capability before processing
			if ( ! isset( $_POST['voxfor_ml_exclusion_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['voxfor_ml_exclusion_nonce'] ) ), 'voxfor_ml_exclusion_action' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'voxfor-multilanguage' ) );
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'voxfor-multilanguage' ) );
			}

			$this->addExclusionRule();
		}

		// Get exclusion rules
		$rules = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `%1s` ORDER BY rule_type, id", $table_name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder

		include VOXFOR_ML_PLUGIN_DIR . 'templates/admin/exclusions.php';
	}





	/**
	 * Render tools page
	 */
	public function renderTools() {
		include VOXFOR_ML_PLUGIN_DIR . 'templates/admin/tools.php';
	}

	/**
	 * Render Cost Analytics page
	 */
	public function renderCostAnalytics() {
		include VOXFOR_ML_PLUGIN_DIR . 'templates/admin/cost-analytics.php';
	}

	/**
	 * Render Diagnostics page
	 */
	public function renderDiagnostics() {
		include VOXFOR_ML_PLUGIN_DIR . 'templates/admin/diagnostics.php';
	}




	/**
	 * Clear cache
	 */
	private function clearCache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_voxfor_ml_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_voxfor_ml_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		add_settings_error( 'voxfor_ml_messages', 'cache_cleared', __( 'Cache cleared successfully', 'voxfor-multilanguage' ), 'success' );
	}

	/**
	 * Clear translation cache (for glossary updates)
	 */
	private function clearTranslationCache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Clear WordPress object cache for translations
		wp_cache_flush_group( 'voxfor_ml_translations' );

		// Clear any transients related to translations
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_voxfor_ml_trans_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_voxfor_ml_trans_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Force TextProcessor to reload glossary on next request
		delete_option( 'voxfor_ml_glossary_loaded' );
	}

	/**
	 * Clean translations for excluded URLs
	 */
	public function cleanExcludedUrlTranslations() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return 0;
		}

		global $wpdb;

		// Get all URL exclusion patterns
		$exclusions_table   = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions';
		$translations_table = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translations';

		$url_patterns = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT rule_value FROM `%1s` WHERE rule_type = 'url' AND is_active = 1", $exclusions_table ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			ARRAY_A
		);

		if ( empty( $url_patterns ) ) {
			return 0; // No URL exclusions found
		}

		$deleted_count = 0;

		foreach ( $url_patterns as $pattern ) {
			$url_pattern = $pattern['rule_value'];

			// For exact URL matches, we can directly delete
			if ( strpos( $url_pattern, '*' ) === false ) {
				// Extract the post slug from the URL pattern
				// Example: /he/product/blue-hoodie/ -> blue-hoodie
				$slug = basename( rtrim( $url_pattern, '/' ) );

				if ( ! empty( $slug ) ) {
					// Find posts with this slug
					$posts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->prepare(
							"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s",
							$slug
						)
					);

					foreach ( $posts as $post ) {
						// Delete all translations for this post
						$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
							$translations_table,
							array( 'post_id' => $post->ID ),
							array( '%d' )
						);

						if ( $deleted !== false ) {
							$deleted_count += $deleted;
						}
					}
				}
			}
		}

		// Clear caches after cleanup
		$this->clearTranslationCache();

		return $deleted_count;
	}

	/**
	 * Add or update glossary term
	 */
	private function addGlossaryTerm() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Verify nonce
		if ( ! isset( $_POST['voxfor_ml_glossary_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['voxfor_ml_glossary_nonce'] ) ), 'voxfor_ml_glossary_action' ) ) {
			add_settings_error( 'voxfor_ml_messages', 'nonce_error', __( 'Security check failed', 'voxfor-multilanguage' ), 'error' );
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'glossary';

		$term           = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		$translation    = isset( $_POST['translation'] ) ? sanitize_text_field( wp_unslash( $_POST['translation'] ) ) : '';
		$language       = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$case_sensitive = isset( $_POST['case_sensitive'] ) ? 1 : 0;
		$match_type     = isset( $_POST['match_type'] ) ? sanitize_text_field( wp_unslash( $_POST['match_type'] ) ) : 'exact';
		$priority       = isset( $_POST['priority'] ) ? intval( $_POST['priority'] ) : 0;
		$term_id        = isset( $_POST['term_id'] ) ? intval( $_POST['term_id'] ) : 0;

		// Validate required fields
		if ( empty( $term ) || empty( $translation ) || empty( $language ) ) {
			add_settings_error( 'voxfor_ml_messages', 'missing_fields', __( 'Please fill in all required fields', 'voxfor-multilanguage' ), 'error' );
			return;
		}

		$data = array(
			'term'           => $term,
			'translation'    => $translation,
			'language_code'  => $language,
			'case_sensitive' => $case_sensitive,
			'match_type'     => $match_type,
			'priority'       => $priority,
		);

		if ( $term_id > 0 ) {
			// Update existing term
			$result  = $wpdb->update( $table_name, $data, array( 'id' => $term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$message = $result !== false ? __( 'Glossary term updated successfully', 'voxfor-multilanguage' ) : __( 'Failed to update glossary term', 'voxfor-multilanguage' );
			$type    = $result !== false ? 'success' : 'error';
		} else {
			// Check for duplicate
			$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT id FROM `%1s` WHERE term = %s AND language_code = %s", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
					$table_name,
					$term,
					$language
				)
			);

			if ( $existing ) {
				add_settings_error( 'voxfor_ml_messages', 'duplicate_term', __( 'This term already exists for this language', 'voxfor-multilanguage' ), 'error' );
				return;
			}

			// Insert new term
			$result  = $wpdb->insert( $table_name, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$message = $result ? __( 'Glossary term added successfully', 'voxfor-multilanguage' ) : __( 'Failed to add glossary term', 'voxfor-multilanguage' );
			$type    = $result ? 'success' : 'error';
		}

		add_settings_error( 'voxfor_ml_messages', 'glossary_result', $message, $type );

		// Clear translation cache when glossary is updated
		if ( $result !== false ) {
			$this->clearTranslationCache();
		}
	}

	/**
	 * Handle delete glossary term (non-AJAX)
	 */
	public function handleDeleteGlossary() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'voxfor-multilanguage' ) );
		}

		// Verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'voxfor_ml_delete_glossary' ) ) {
			wp_die( esc_html__( 'Security check failed', 'voxfor-multilanguage' ) );
		}

		$term_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
		if ( $term_id <= 0 ) {
			wp_die( esc_html__( 'Invalid term ID', 'voxfor-multilanguage' ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'glossary';

		$result = $wpdb->delete( $table_name, array( 'id' => $term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $result ) {
			$message = __( 'Glossary term deleted successfully', 'voxfor-multilanguage' );
			$type    = 'success';

			// Clear translation cache when glossary is updated
			$this->clearTranslationCache();
		} else {
			$message = __( 'Failed to delete glossary term', 'voxfor-multilanguage' );
			$type    = 'error';
		}

		// Redirect back with message (append nonce for display verification)
		$redirect_url = add_query_arg(
			array(
				'page'    => 'voxfor-ml-glossary',
				'message' => urlencode( $message ),
				'type'    => $type,
			),
			admin_url( 'admin.php' )
		);

		$redirect_url = wp_nonce_url( $redirect_url, 'voxfor_ml_glossary_notice' );

		wp_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle export glossary
	 */
	public function handleExportGlossary() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'voxfor-multilanguage' ) );
		}

		// Verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'voxfor_ml_export_glossary' ) ) {
			wp_die( esc_html__( 'Security check failed', 'voxfor-multilanguage' ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'glossary';

		$terms = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `%1s` ORDER BY language_code, term", $table_name ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder

		// Generate CSV
		$csv_content = "term,translation,language_code,match_type,case_sensitive,priority\n";
		foreach ( $terms as $term ) {
			$csv_content .= sprintf(
				'"%s","%s","%s","%s","%s","%s"' . "\n",
				str_replace( '"', '""', $term['term'] ),
				str_replace( '"', '""', $term['translation'] ),
				$term['language_code'],
				$term['match_type'],
				$term['case_sensitive'] ? '1' : '0',
				$term['priority']
			);
		}

		// Send file
		$filename = 'voxfor-glossary-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $csv_content ) );

		echo wp_kses_post( $csv_content );
		exit;
	}

	/**
	 * AJAX: Edit glossary term
	 */
	public function ajaxEditGlossary() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'voxfor-multilanguage' ) );
		}

		// This would be used for getting term data for editing
		// The actual edit form is handled by the frontend JavaScript
		$term_id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'glossary';

		$term = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM `%1s` WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table_name,
				$term_id
			)
		);

		if ( $term ) {
			wp_send_json_success( $term );
		} else {
			wp_send_json_error( __( 'Term not found', 'voxfor-multilanguage' ) );
		}
	}

	/**
	 * AJAX: Delete glossary term
	 */
	public function ajaxDeleteGlossary() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'voxfor-multilanguage' ) );
		}

		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'voxfor_ml_delete_glossary' ) ) {
			wp_send_json_error( __( 'Security check failed', 'voxfor-multilanguage' ) );
		}

		$term_id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'glossary';

		$result = $wpdb->delete( $table_name, array( 'id' => $term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $result ) {
			wp_send_json_success( __( 'Glossary term deleted successfully', 'voxfor-multilanguage' ) );
		} else {
			wp_send_json_error( __( 'Failed to delete glossary term', 'voxfor-multilanguage' ) );
		}
	}

	/**
	 * Handle import glossary
	 */
	public function handleImportGlossary() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Verify nonce
		if ( ! isset( $_POST['voxfor_ml_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['voxfor_ml_import_nonce'] ) ), 'voxfor_ml_import_glossary' ) ) {
			add_settings_error( 'voxfor_ml_messages', 'nonce_error', __( 'Security check failed', 'voxfor-multilanguage' ), 'error' );
			return;
		}

		if ( ! isset( $_FILES['glossary_file'] ) || ! isset( $_FILES['glossary_file']['error'] ) || $_FILES['glossary_file']['error'] !== UPLOAD_ERR_OK ) {
			add_settings_error( 'voxfor_ml_messages', 'file_error', __( 'File upload failed', 'voxfor-multilanguage' ), 'error' );
			return;
		}

		$tmp_name = isset( $_FILES['glossary_file']['tmp_name'] ) ? sanitize_text_field( $_FILES['glossary_file']['tmp_name'] ) : '';
		if ( empty( $tmp_name ) ) {
			add_settings_error( 'voxfor_ml_messages', 'file_error', __( 'Invalid file upload', 'voxfor-multilanguage' ), 'error' );
			return;
		}

		$file_content = file_get_contents( $tmp_name );
		$lines        = str_getcsv( $file_content, "\n" );

		if ( empty( $lines ) ) {
			add_settings_error( 'voxfor_ml_messages', 'empty_file', __( 'File is empty', 'voxfor-multilanguage' ), 'error' );
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'glossary';

		$imported       = 0;
		$skipped        = 0;
		$header_skipped = false;

		foreach ( $lines as $line ) {
			$data = str_getcsv( $line );

			// Skip header row
			if ( ! $header_skipped ) {
				$header_skipped = true;
				continue;
			}

			// Skip empty lines
			if ( count( $data ) < 3 ) {
				continue;
			}

			$term           = trim( $data[0] );
			$translation    = trim( $data[1] );
			$language       = trim( $data[2] );
			$match_type     = isset( $data[3] ) ? trim( $data[3] ) : 'exact';
			$case_sensitive = isset( $data[4] ) ? ( trim( $data[4] ) == '1' ? 1 : 0 ) : 0;
			$priority       = isset( $data[5] ) ? intval( trim( $data[5] ) ) : 0;

			// Validate data
			if ( empty( $term ) || empty( $translation ) || empty( $language ) ) {
				++$skipped;
				continue;
			}

			// Check if term already exists
			$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT id FROM `%1s` WHERE term = %s AND language_code = %s", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
					$table_name,
					$term,
					$language
				)
			);

			if ( $existing ) {
				++$skipped;
				continue;
			}

			// Insert term
			$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table_name,
				array(
					'term'           => $term,
					'translation'    => $translation,
					'language_code'  => $language,
					'match_type'     => in_array( $match_type, array( 'exact', 'partial' ) ) ? $match_type : 'exact',
					'case_sensitive' => $case_sensitive,
					'priority'       => $priority,
				)
			);

			if ( $result ) {
				++$imported;
			} else {
				++$skipped;
			}
		}

		$message = sprintf( /* translators: %1$d is imported terms count, %2$d is skipped terms count */ __( 'Import completed: %1$d terms imported, %2$d skipped', 'voxfor-multilanguage' ), $imported, $skipped );
		add_settings_error( 'voxfor_ml_messages', 'import_result', $message, 'success' );
	}

	/**
	 * Add or update exclusion rule
	 */
	private function addExclusionRule() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Verify nonce
		if ( ! isset( $_POST['voxfor_ml_exclusion_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['voxfor_ml_exclusion_nonce'] ) ), 'voxfor_ml_exclusion_action' ) ) {
			add_settings_error( 'voxfor_ml_messages', 'exclusion_nonce_failed', __( 'Security check failed', 'voxfor-multilanguage' ), 'error' );
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions';

		$rule_id     = ! empty( $_POST['rule_id'] ) ? intval( $_POST['rule_id'] ) : 0;
		$rule_type   = isset( $_POST['rule_type'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_type'] ) ) : '';
		$rule_value  = isset( $_POST['rule_value'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_value'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';

		// Validate rule type
		if ( ! in_array( $rule_type, array( 'css', 'namespace' ) ) ) {
			add_settings_error( 'voxfor_ml_messages', 'exclusion_invalid_type', __( 'Invalid rule type', 'voxfor-multilanguage' ), 'error' );
			return;
		}

		// Validate rule value
		if ( empty( $rule_value ) ) {
			add_settings_error( 'voxfor_ml_messages', 'exclusion_empty_value', __( 'Rule value cannot be empty', 'voxfor-multilanguage' ), 'error' );
			return;
		}

		$data = array(
			'rule_type'   => $rule_type,
			'rule_value'  => $rule_value,
			'description' => $description,
			'is_active'   => 1,
		);

		if ( $rule_id > 0 ) {
			// Update existing rule
			$result = $wpdb->update( $table_name, $data, array( 'id' => $rule_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $result !== false ) {
				add_settings_error( 'voxfor_ml_messages', 'exclusion_updated', __( 'Exclusion rule updated successfully', 'voxfor-multilanguage' ), 'success' );
			} else {
				add_settings_error( 'voxfor_ml_messages', 'exclusion_update_failed', __( 'Failed to update exclusion rule', 'voxfor-multilanguage' ), 'error' );
			}
		} else {
			// Check for duplicates
			$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `%1s` WHERE rule_type = %s AND rule_value = %s", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
					$table_name,
					$rule_type,
					$rule_value
				)
			);

			if ( $existing > 0 ) {
				add_settings_error( 'voxfor_ml_messages', 'exclusion_duplicate', __( 'This exclusion rule already exists', 'voxfor-multilanguage' ), 'error' );
				return;
			}

			// Add new rule
			$result = $wpdb->insert( $table_name, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			if ( $result ) {
				add_settings_error( 'voxfor_ml_messages', 'exclusion_added', __( 'Exclusion rule added successfully', 'voxfor-multilanguage' ), 'success' );
			} else {
				add_settings_error( 'voxfor_ml_messages', 'exclusion_add_failed', __( 'Failed to add exclusion rule', 'voxfor-multilanguage' ), 'error' );
			}
		}
	}

	/**
	 * Handle exclusion deletion
	 */
	public function handleDeleteExclusion() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		// Verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'voxfor_ml_delete_exclusion' ) ) {
			wp_die( esc_html__( 'Security check failed', 'voxfor-multilanguage' ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions';

		$rule_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;

		if ( $rule_id > 0 ) {
			$result = $wpdb->delete( $table_name, array( 'id' => $rule_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $result ) {
				$message = urlencode( __( 'Exclusion rule deleted successfully', 'voxfor-multilanguage' ) );
				$type    = 'success';
			} else {
				$message = urlencode( __( 'Failed to delete exclusion rule', 'voxfor-multilanguage' ) );
				$type    = 'error';
			}
		} else {
			$message = urlencode( __( 'Invalid rule ID', 'voxfor-multilanguage' ) );
			$type    = 'error';
		}

		// Redirect back to exclusions page with message (append nonce for display verification)
		$redirect_url = add_query_arg(
			array(
				'page'    => 'voxfor-ml-exclusions',
				'message' => $message,
				'type'    => $type,
			),
			admin_url( 'admin.php' )
		);

		$redirect_url = wp_nonce_url( $redirect_url, 'voxfor_ml_exclusions_notice' );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * AJAX: Start bulk translation
	 */
	public function ajaxStartBulkTranslation() {
		check_ajax_referer( 'voxfor_ml_bulk_translation', 'voxfor_ml_bulk_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$languages          = isset( $_POST['languages'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['languages'] ) ) : array();
		$post_types         = isset( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['post_types'] ) ) : array( 'post', 'page' );
		$post_status        = isset( $_POST['post_status'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['post_status'] ) ) : array( 'publish' );
		$translate_existing = isset( $_POST['translate_existing'] ) ? (bool) $_POST['translate_existing'] : false;
		$items_per_batch    = isset( $_POST['items_per_batch'] ) ? intval( $_POST['items_per_batch'] ) : 1;

		if ( empty( $languages ) ) {
			wp_send_json_error( array( 'message' => __( 'Please select at least one language', 'voxfor-multilanguage' ) ) );
		}

		$bulk_manager = new \VoxforML\Translator\BulkTranslationManager();
		$result       = $bulk_manager->startBulkTranslation(
			array(
				'languages'          => $languages,
				'post_types'         => $post_types,
				'post_status'        => $post_status,
				'translate_existing' => $translate_existing,
				'items_per_batch'    => $items_per_batch,
			)
		);

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: Pause job
	 */
	public function ajaxPauseJob() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$job_id       = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$bulk_manager = new \VoxforML\Translator\BulkTranslationManager();

		if ( $bulk_manager->pauseJob( $job_id ) ) {
			wp_send_json_success( array( 'message' => __( 'Job paused', 'voxfor-multilanguage' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to pause job', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * AJAX: Resume job
	 */
	public function ajaxResumeJob() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$job_id       = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$bulk_manager = new \VoxforML\Translator\BulkTranslationManager();

		if ( $bulk_manager->resumeJob( $job_id ) ) {
			wp_send_json_success( array( 'message' => __( 'Job resumed', 'voxfor-multilanguage' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to resume job', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * AJAX: Cancel job
	 */
	public function ajaxCancelJob() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$job_id       = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$bulk_manager = new \VoxforML\Translator\BulkTranslationManager();

		if ( $bulk_manager->cancelJob( $job_id ) ) {
			wp_send_json_success( array( 'message' => __( 'Job cancelled', 'voxfor-multilanguage' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to cancel job', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * AJAX: Get job status
	 */
	public function ajaxGetJobStatus() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		$job_id       = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$bulk_manager = new \VoxforML\Translator\BulkTranslationManager();

		$status = $bulk_manager->getJobStatus( $job_id );

		if ( $status ) {
			wp_send_json_success( $status );
		} else {
			wp_send_json_error( array( 'message' => __( 'Job not found', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * AJAX: Get job log
	 */
	public function ajaxGetJobLog() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$job_id       = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$bulk_manager = new \VoxforML\Translator\BulkTranslationManager();

		$jobs = $bulk_manager->getAllJobs();
		if ( isset( $jobs[ $job_id ] ) ) {
			$log_text = '';
			foreach ( $jobs[ $job_id ]['log'] as $entry ) {
				$log_text .= sprintf(
					"[%s] %s: %s\n",
					$entry['timestamp'],
					$entry['event'],
					json_encode( $entry['data'] )
				);
			}
			wp_send_json_success( array( 'log' => $log_text ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Job not found', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * AJAX: Estimate bulk translation
	 */
	public function ajaxEstimateBulk() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		$post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['post_types'] ) ) : array( 'post', 'page' );

		$query = new \WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_voxfor_ml_exclude',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		wp_send_json_success( array( 'count' => $query->found_posts ) );
	}

	/**
	 * AJAX: Process translation queue
	 */
	public function ajaxProcessQueue() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$translator = $this->plugin->getComponent( 'translator' );
		$processed  = $translator->processTranslationQueue();

		wp_send_json_success(
			array(
				'processed' => $processed,
				'message'   => sprintf( /* translators: %d is the number of processed translations */ esc_html__( 'Processed %d translations', 'voxfor-multilanguage' ), $processed ),
			)
		);
	}

	/**
	 * AJAX: Toggle exclusion rule
	 */
	public function ajaxToggleExclusion() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions';

		$rule_id   = isset( $_POST['rule_id'] ) ? intval( $_POST['rule_id'] ) : 0;
		$is_active = isset( $_POST['is_active'] ) ? intval( $_POST['is_active'] ) : 0;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			array( 'is_active' => $is_active ),
			array( 'id' => $rule_id )
		);

		if ( $result !== false ) {
			wp_send_json_success( array( 'message' => __( 'Rule updated', 'voxfor-multilanguage' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to update rule', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * AJAX: Translate Elementor page
	 */
	public function ajaxTranslateElementorPage() {
		check_ajax_referer( 'voxfor_ml_elementor_translate', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'voxfor-multilanguage' ) ) );
		}

		$post_id  = intval( $_POST['post_id'] ?? 0 );
		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

		if ( ! $post_id || ! $language ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'voxfor-multilanguage' ) ) );
		}

		// Get Elementor integration
		$elementor_integration = $this->plugin->getComponent( 'elementor_integration' );
		if ( ! $elementor_integration ) {
			wp_send_json_error( array( 'message' => __( 'Elementor integration not available', 'voxfor-multilanguage' ) ) );
		}

		// Translate Elementor page
		$result = $elementor_integration->translateElementorPage( $post_id, $language );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Elementor page translated successfully', 'voxfor-multilanguage' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to translate Elementor page', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * AJAX: Translate all Elementor elements (SIMPLIFIED - works for ANY page)
	 */
	public function ajaxTranslateAllElementor() {
		check_ajax_referer( 'voxfor_ml_elementor_translate', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'voxfor-multilanguage' ) ) );
		}

		$post_id = intval( $_POST['post_id'] ?? 0 );

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID', 'voxfor-multilanguage' ) ) );
		}

		// Get the post
		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found', 'voxfor-multilanguage' ) ) );
		}

		// Check if this is single language translation (from individual "Translate" button)
		$single_language = isset( $_POST['single_language'] ) ? sanitize_text_field( wp_unslash( $_POST['single_language'] ) ) : '';

		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $single_language ) {
			// Single language translation
			$target_languages = array( $single_language );
			$progress_message = sprintf( /* translators: %s is the target language code */ __( 'Translating page to %s...', 'voxfor-multilanguage' ), strtoupper( $single_language ) );
		} else {
			// All languages translation
			$target_languages = array_diff( $languages, array( $default_lang ) );
			$progress_message = __( 'Starting page translation to all languages...', 'voxfor-multilanguage' );
		}

		$total_languages = count( $target_languages );

		// Create progress key
		$progress_key = 'page_' . $post_id . '_' . time();
		$this->updateTranslationProgress( $progress_key, 0, $total_languages, $progress_message );

		// Get DeepL translator
		$deepl_translator = $this->plugin->getComponent( 'translator' );
		$memory           = $this->plugin->getComponent( 'translation_memory' );

		if ( ! $deepl_translator || ! $memory ) {
			wp_send_json_error( array( 'message' => __( 'Translation components not available', 'voxfor-multilanguage' ) ) );
		}

		$results = array();
		$current = 0;

		foreach ( $target_languages as $language ) {
			++$current;
			$this->updateTranslationProgress( $progress_key, $current, $total_languages, sprintf( /* translators: %s is the target language code */ __( 'Translating to %s...', 'voxfor-multilanguage' ), strtoupper( $language ) ) );

			// SIMPLE APPROACH: Just translate the post content like we do for products
			$success              = $this->translatePageContent( $post, $language, $deepl_translator, $memory );
			$results[ $language ] = $success;
		}

		$success_count      = count( array_filter( $results ) );
		$completion_message = $single_language
			? sprintf( /* translators: %s is the target language code */ __( 'Completed: Page translated to %s', 'voxfor-multilanguage' ), strtoupper( $single_language ) )
			: sprintf( /* translators: %1$d is successful translations count, %2$d is total languages count */ __( 'Completed: %1$d of %2$d languages translated', 'voxfor-multilanguage' ), $success_count, $total_languages );

		$this->updateTranslationProgress( $progress_key, $total_languages, $total_languages, $completion_message );

		wp_send_json_success(
			array(
				'message'         => $single_language
					? sprintf( /* translators: %s is the target language code */ __( 'Page translated to %s', 'voxfor-multilanguage' ), strtoupper( $single_language ) )
					: sprintf( /* translators: %1$d is successful translations count, %2$d is total languages count */ __( 'Translated %1$d of %2$d languages', 'voxfor-multilanguage' ), $success_count, $total_languages ),
				'results'         => $results,
				'progress_key'    => $progress_key,
				'single_language' => $single_language,
			)
		);
	}

	/**
	 * Simple method to translate page content (works for ANY page builder)
	 */
	private function translatePageContent( $post, $language, $translation_manager, $memory, $force_retranslate = false ) {
		try {
			// Get the internal DeepL translator directly
			$translator = $this->getDeepLTranslator();
			if ( ! $translator ) {

				return false;
			}

			// Source language is always English for now
			$source_lang = 'EN';

			// 1. Translate post title (check existing only if not force_retranslate)
			if ( ! empty( $post->post_title ) ) {
				$existing_title = ! $force_retranslate ? $memory->getTranslation( $post->post_title, $language, 'title' ) : false;
				if ( $existing_title === false || $force_retranslate ) {
					$translated_title = $translator->translate( $post->post_title, $language, $source_lang, 'title' );
					if ( $translated_title && $translated_title !== $post->post_title ) {
						$memory->saveTranslation( $post->post_title, $translated_title, $language, 'title', $post->ID, 'deepl' );
					}
				}
			}

			// 2. Translate post content (this includes Elementor content as raw HTML)
			if ( ! empty( $post->post_content ) ) {
				// For Elementor pages, get the Elementor data
				$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
				if ( $elementor_data ) {
					// Decode Elementor data
					$data = json_decode( $elementor_data, true );
					if ( $data ) {
						// Extract all text from Elementor elements
						$this->translateElementorData( $data, $language, $translator, $memory, $post->ID, $force_retranslate );
						
						// Also translate images in Elementor content
						if ( get_option( 'voxfor_ml_translate_image_alt', true ) ) {
							$this->translateElementorImages( $data, $language, $translator, $memory, $post->ID, $force_retranslate );
						}
					}
				} else {
					// For non-Elementor pages, translate the content directly
					$existing_content = ! $force_retranslate ? $memory->getTranslation( $post->post_content, $language, 'content' ) : false;
					if ( $existing_content === false || $force_retranslate ) {
						$translated_content = $translator->translate( $post->post_content, $language, $source_lang, 'content' );
						if ( $translated_content && $translated_content !== $post->post_content ) {
							$memory->saveTranslation( $post->post_content, $translated_content, $language, 'content', $post->ID, 'deepl' );
						}
					}
				}
			}

			// 3. Translate post excerpt if exists
			if ( ! empty( $post->post_excerpt ) ) {
				$existing_excerpt = ! $force_retranslate ? $memory->getTranslation( $post->post_excerpt, $language, 'excerpt' ) : false;
				if ( $existing_excerpt === false || $force_retranslate ) {
					$translated_excerpt = $translator->translate( $post->post_excerpt, $language, $source_lang, 'excerpt' );
					if ( $translated_excerpt && $translated_excerpt !== $post->post_excerpt ) {
						$memory->saveTranslation( $post->post_excerpt, $translated_excerpt, $language, 'excerpt', $post->ID, 'deepl' );
					}
				}
			}

			// 4. Translate image ALT text if the setting is enabled
			if ( get_option( 'voxfor_ml_translate_image_alt', true ) ) {
				$this->translateImageAltTextForPost( $post, $language, $translator, $memory, $force_retranslate );
			}

			return true;
		} catch ( Exception $e ) {

			return false;
		}
	}

	/**
	 * Get DeepL translator directly
	 */
	private function getDeepLTranslator() {
		// Create a new instance directly
		return new \VoxforML\Translator\DeepLTranslator();
	}

	/**
	 * Translate image ALT text for a specific post
	 */
	private function translateImageAltTextForPost( $post, $language, $translator, $memory, $force_retranslate = false ) {
		if ( empty( $post->post_content ) ) {
			return;
		}

		// Create a DOM document to parse HTML content
		$dom = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $post->post_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		// Find all images in the content
		$images = $dom->getElementsByTagName( 'img' );
		$source_lang = 'EN';

		foreach ( $images as $img ) {
			// Translate ALT text
			$alt = $img->getAttribute( 'alt' );
			if ( ! empty( $alt ) && strlen( $alt ) >= 3 ) {
				$existing_alt = ! $force_retranslate ? $memory->getTranslation( $alt, $language, 'image_alt', $post->ID ) : false;
				if ( $existing_alt === false || $force_retranslate ) {
					$translated_alt = $translator->translate( $alt, $language, $source_lang, 'image_alt' );
					if ( $translated_alt && $translated_alt !== $alt ) {
						$memory->saveTranslation( $alt, $translated_alt, $language, 'image_alt', $post->ID, 'deepl' );
					}
				}
			}

			// Translate title attribute
			$title = $img->getAttribute( 'title' );
			if ( ! empty( $title ) && strlen( $title ) >= 3 ) {
				$existing_title = ! $force_retranslate ? $memory->getTranslation( $title, $language, 'image_title', $post->ID ) : false;
				if ( $existing_title === false || $force_retranslate ) {
					$translated_title = $translator->translate( $title, $language, $source_lang, 'image_title' );
					if ( $translated_title && $translated_title !== $title ) {
						$memory->saveTranslation( $title, $translated_title, $language, 'image_title', $post->ID, 'deepl' );
					}
				}
			}
		}
	}

	/**
	 * Translate images in Elementor data
	 */
	private function translateElementorImages( $elements, $language, $translator, $memory, $post_id, $force_retranslate = false ) {
		if ( empty( $elements ) || ! is_array( $elements ) ) {
			return;
		}

		$source_lang = 'EN';

		foreach ( $elements as $element ) {
			// Check for image settings in Elementor widgets
			if ( isset( $element['settings'] ) ) {
				$settings = $element['settings'];

				// Common image ALT fields in Elementor
				$image_alt_fields = array(
					'image_alt',
					'alt_text',
					'image' => 'alt', // For image widget where ALT is nested
				);

				foreach ( $image_alt_fields as $field_key => $alt_key ) {
					if ( is_string( $field_key ) ) {
						// Nested ALT text (like image widget)
						if ( isset( $settings[ $field_key ][ $alt_key ] ) ) {
							$alt_text = $settings[ $field_key ][ $alt_key ];
							if ( ! empty( $alt_text ) && strlen( $alt_text ) >= 3 ) {
								$existing_alt = ! $force_retranslate ? $memory->getTranslation( $alt_text, $language, 'image_alt', $post_id ) : false;
								if ( $existing_alt === false || $force_retranslate ) {
									$translated_alt = $translator->translate( $alt_text, $language, $source_lang, 'image_alt' );
									if ( $translated_alt && $translated_alt !== $alt_text ) {
										$memory->saveTranslation( $alt_text, $translated_alt, $language, 'image_alt', $post_id, 'deepl' );
									}
								}
							}
						}
					} else {
						// Direct ALT text field
						if ( isset( $settings[ $alt_key ] ) ) {
							$alt_text = $settings[ $alt_key ];
							if ( ! empty( $alt_text ) && strlen( $alt_text ) >= 3 ) {
								$existing_alt = ! $force_retranslate ? $memory->getTranslation( $alt_text, $language, 'image_alt', $post_id ) : false;
								if ( $existing_alt === false || $force_retranslate ) {
									$translated_alt = $translator->translate( $alt_text, $language, $source_lang, 'image_alt' );
									if ( $translated_alt && $translated_alt !== $alt_text ) {
										$memory->saveTranslation( $alt_text, $translated_alt, $language, 'image_alt', $post_id, 'deepl' );
									}
								}
							}
						}
					}
				}
			}

			// Recursively process child elements
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->translateElementorImages( $element['elements'], $language, $translator, $memory, $post_id, $force_retranslate );
			}
		}
	}

	/**
	 * Recursively translate Elementor data
	 */
	private function translateElementorData( &$elements, $language, $translator, $memory, $post_id, $force_retranslate = false ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		// Source language is always English for now
		$source_lang = 'EN';

		foreach ( $elements as &$element ) {
			// Check for text in widget settings
			if ( isset( $element['settings'] ) ) {
				$settings = &$element['settings'];

				// Common text fields in Elementor widgets - expanded list
				$text_fields = array(
					'title',
					'title_text',
					'heading_title',
					'description',
					'description_text',
					'editor',
					'text',
					'content',
					'html',
					'button_text',
					'link_text',
					'label',
					'before_text',
					'after_text',
					'highlighted_text',
					'prefix',
					'suffix',
					'caption',
					'inner_text',
					'alert_title',
					'alert_description',
					'testimonial_content',
					'testimonial_name',
					'testimonial_job',
					'tab_title',
					'tab_content',
					'accordion_content',
					'item_text',
					'item_description',
				);

				foreach ( $text_fields as $field ) {
					if ( ! empty( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
						$original_text = $settings[ $field ];

						// Skip if it's just HTML tags without content
						$stripped = wp_strip_all_tags( $original_text );
						if ( empty( trim( $stripped ) ) ) {
							continue;
						}

						// Check if already translated (unless force_retranslate is true)
						$existing = ! $force_retranslate ? $memory->getTranslation( $original_text, $language, 'text_fragment' ) : false;

						if ( $existing === false || $force_retranslate ) {
							// Translate the text
							$translated = $translator->translate( $original_text, $language, $source_lang, 'text_fragment' );
							if ( $translated && $translated !== $original_text ) {
								$memory->saveTranslation( $original_text, $translated, $language, 'text_fragment', $post_id, 'deepl' );
							}
						}
					}
				}

				// Handle repeater fields (like lists, accordions, tabs)
				$repeater_fields = array( 'tabs', 'slides', 'testimonials', 'features', 'items', 'accordion', 'icon_list' );
				foreach ( $repeater_fields as $repeater ) {
					if ( isset( $settings[ $repeater ] ) && is_array( $settings[ $repeater ] ) ) {
						foreach ( $settings[ $repeater ] as &$item ) {
							foreach ( $text_fields as $field ) {
								if ( ! empty( $item[ $field ] ) && is_string( $item[ $field ] ) ) {
									$original_text = $item[ $field ];

									// Check if already translated (unless force_retranslate is true)
									$existing = ! $force_retranslate ? $memory->getTranslation( $original_text, $language, 'text_fragment' ) : false;

									if ( $existing === false || $force_retranslate ) {
										$translated = $translator->translate( $original_text, $language, $source_lang, 'text_fragment' );
										if ( $translated && $translated !== $original_text ) {
											$memory->saveTranslation( $original_text, $translated, $language, 'text_fragment', $post_id, 'deepl' );
										}
									}
								}
							}
						}
					}
				}
			}

			// Recursively process nested elements
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->translateElementorData( $element['elements'], $language, $translator, $memory, $post_id, $force_retranslate );
			}
		}
	}

	/**
	 * AJAX: Translate all languages
	 */
	public function ajaxTranslateAllLanguages() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'voxfor-multilanguage' ) ) );
		}

		$post_id = intval( $_POST['post_id'] ?? 0 );

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID', 'voxfor-multilanguage' ) ) );
		}

		$languages        = $this->plugin->getEnabledLanguages();
		$default_lang     = $this->plugin->getDefaultLanguage();
		$target_languages = array_diff( $languages, array( $default_lang ) );
		$total_languages  = count( $target_languages );

		$translator = $this->plugin->getComponent( 'translator' );
		$post       = get_post( $post_id );

		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found', 'voxfor-multilanguage' ) ) );
		}

		// Create progress key
		$progress_key = 'post_' . $post_id . '_' . time();
		$this->updateTranslationProgress( $progress_key, 0, $total_languages, __( 'Starting translation...', 'voxfor-multilanguage' ) );

		$results = array();
		$current = 0;

		foreach ( $target_languages as $language ) {
			++$current;
			$this->updateTranslationProgress( $progress_key, $current, $total_languages, sprintf( /* translators: %s is the target language code */ __( 'Translating to %s...', 'voxfor-multilanguage' ), strtoupper( $language ) ) );

			// Translate post title
			$title_translated = $translator->translateText( $post->post_title, 'title', $post_id );

			// Translate post content
			$content_translated = $translator->translateContent( $post->post_content, 'content' );

			$results[ $language ] = array(
				'title'   => $title_translated,
				'content' => $content_translated,
			);
		}

		$this->updateTranslationProgress( $progress_key, $total_languages, $total_languages, sprintf( /* translators: %d is the number of languages */ __( 'Translation completed for %d languages', 'voxfor-multilanguage' ), $total_languages ) );

		wp_send_json_success(
			array(
				'message'      => sprintf( /* translators: %d is the number of languages */ __( 'Translated to %d languages', 'voxfor-multilanguage' ), count( $target_languages ) ),
				'results'      => $results,
				'progress_key' => $progress_key,
			)
		);
	}

	/**
	 * AJAX: Translate all (alias for translate all languages)
	 */
	public function ajaxTranslateAll() {
		$this->ajaxTranslateAllLanguages();
	}

	/**
	 * AJAX: Get translation progress
	 */
	public function ajaxGetTranslationProgress() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'voxfor-multilanguage' ) ) );
		}

		$progress_key = isset( $_POST['progress_key'] ) ? sanitize_text_field( wp_unslash( $_POST['progress_key'] ) ) : '';
		if ( ! $progress_key ) {
			wp_send_json_error( array( 'message' => __( 'Invalid progress key', 'voxfor-multilanguage' ) ) );
		}

		$progress_data = get_transient( 'voxfor_ml_progress_' . $progress_key );
		if ( $progress_data === false ) {
			wp_send_json_success(
				array(
					'status'   => 'completed',
					'progress' => 100,
					'current'  => 0,
					'total'    => 0,
					'message'  => __( 'Translation completed', 'voxfor-multilanguage' ),
				)
			);
		}

		wp_send_json_success( $progress_data );
	}

	/**
	 * AJAX: Reset translation progress
	 */
	public function ajaxResetTranslationProgress() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'voxfor-multilanguage' ) ) );
		}

		$progress_key = isset( $_POST['progress_key'] ) ? sanitize_text_field( wp_unslash( $_POST['progress_key'] ) ) : '';
		if ( $progress_key ) {
			delete_transient( 'voxfor_ml_progress_' . $progress_key );
		}

		wp_send_json_success( array( 'message' => __( 'Progress reset', 'voxfor-multilanguage' ) ) );
	}

	/**
	 * Handle AJAX request to translate single language for a post
	 */
	public function ajaxTranslateSingleLanguage() {
		// Check permissions
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		// Verify nonce
		if ( ! check_ajax_referer( 'voxfor_ml_ajax', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
		}

		$post_id           = intval( $_POST['post_id'] ?? 0 );
		$language          = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$force_retranslate = isset( $_POST['force_retranslate'] ) && $_POST['force_retranslate'] === 'true';

		if ( ! $post_id || ! $language ) {
			wp_send_json_error( array( 'message' => 'Missing required parameters' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'Post not found' ) );
		}

		// Generate unique progress key
		$progress_key = 'single_' . $post_id . '_' . $language . '_' . time();

		// Initialize progress
		$this->updateTranslationProgress( $progress_key, 0, 1, sprintf( 'Translating to %s...', strtoupper( $language ) ) );

		// Get DeepL translator and memory
		$deepl_translator = $this->plugin->getComponent( 'translator' );
		$memory           = $this->plugin->getComponent( 'translation_memory' );

		if ( ! $deepl_translator || ! $memory ) {
			wp_send_json_error( array( 'message' => 'Translation components not available' ) );
		}

		// Always use translatePageContent which now handles force_retranslate properly
		$success = $this->translatePageContent( $post, $language, $deepl_translator, $memory, $force_retranslate );

		// Mark as complete
		$this->updateTranslationProgress( $progress_key, 1, 1, 'Translation completed!' );

		if ( $success ) {
			wp_send_json_success(
				array(
					'message'      => sprintf( 'Page "%s" translated to %s', $post->post_title, strtoupper( $language ) ),
					'progress_key' => $progress_key,
					'status'       => array(
						'translated'  => true,
						'in_progress' => false,
					),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => 'Translation failed. Please check the error logs.' ) );
		}
	}

	/**
	 * Handle AJAX request to translate complete website
	 */
	public function ajaxTranslateCompleteWebsite() {
		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		// Verify nonce
		if ( ! check_ajax_referer( 'voxfor_ml_translate_website', 'nonce', false ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}

		// Get all enabled languages
		$languages = $this->plugin->getEnabledLanguages();
		$languages = array_diff( $languages, array( 'en' ) ); // Remove English

		if ( empty( $languages ) ) {
			wp_send_json_error( 'No languages enabled for translation' );
		}

		// Get all public post types
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$post_types = array_diff( $post_types, array( 'attachment' ) ); // Exclude attachments

		// Get all posts/pages
		$posts = get_posts(
			array(
				'post_type'   => $post_types,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			wp_send_json_error( 'No content found to translate' );
		}

		// Generate unique progress key
		$progress_key = 'website_' . time();

		// Calculate total items (posts × languages)
		$total_items = count( $posts ) * count( $languages );
		$processed   = 0;

		// Initialize progress
		$this->updateTranslationProgress( $progress_key, 0, $total_items );

		// Get translator component
		$translator = $this->plugin->getComponent( 'translator' );
		if ( ! $translator ) {
			wp_send_json_error( 'Translation component not available' );
		}

		// Process each post for each language
		foreach ( $posts as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			foreach ( $languages as $language ) {
				// Set current language for translation
				$this->plugin->setCurrentLanguage( $language );

				// Translate title
				if ( ! empty( $post->post_title ) ) {
					$translator->translateText( $post->post_title, 'title', $post_id );
				}

				// Translate content
				if ( ! empty( $post->post_content ) ) {
					// For Elementor pages, use special handling
					if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder' ) {
						$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
						if ( $elementor_data ) {
							// Translate Elementor data
							$translator->translateContent( $elementor_data, 'elementor_data' );
						}
					} else {
						// Regular content
						$translator->translateContent( $post->post_content, 'content' );
					}
				}

				// Translate excerpt
				if ( ! empty( $post->post_excerpt ) ) {
					$translator->translateText( $post->post_excerpt, 'excerpt', $post_id );
				}

				// Update progress
				++$processed;
				$this->updateTranslationProgress( $progress_key, $processed, $total_items );
			}
		}

		// Mark as complete
		$this->updateTranslationProgress( $progress_key, $total_items, $total_items );

		wp_send_json_success(
			array(
				'message'         => sprintf( 'Started translating %d posts to %d languages', count( $posts ), count( $languages ) ),
				'progress_key'    => $progress_key,
				'total_posts'     => count( $posts ),
				'total_languages' => count( $languages ),
				'total_items'     => $total_items,
			)
		);
	}

	/**
	 * Update translation progress
	 */
	private function updateTranslationProgress( $progress_key, $current, $total, $message = '' ) {
		$progress_data = array(
			'status'    => $current >= $total ? 'completed' : 'translating',
			'progress'  => $total > 0 ? round( ( $current / $total ) * 100 ) : 0,
			'current'   => $current,
			'total'     => $total,
			'message'   => $message ?: sprintf( /* translators: %1$d is current progress, %2$d is total items */ __( 'Translating %1$d of %2$d...', 'voxfor-multilanguage' ), $current, $total ),
			'timestamp' => time(),
		);

		set_transient( 'voxfor_ml_progress_' . $progress_key, $progress_data, 300 ); // 5 minutes
	}

	/**
	 * Seed common exclusion rules for WooCommerce and page builders
	 */
	public function seedCommonExclusions() {
		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions';

		$common_exclusions = array(
			// WooCommerce Exclusions
			array( 'css', '.woocommerce-checkout', 'WooCommerce checkout form fields' ),
			array( 'css', '.woocommerce-cart', 'WooCommerce cart form fields' ),
			array( 'css', '.woocommerce-form', 'WooCommerce form elements' ),
			array( 'css', '[data-product_id]', 'WooCommerce product data attributes' ),
			array( 'css', '.wc-proceed-to-checkout', 'WooCommerce checkout buttons' ),
			array( 'namespace', 'woocommerce_checkout', 'WooCommerce checkout context' ),
			array( 'namespace', 'woocommerce_cart', 'WooCommerce cart context' ),

			// Page Builder Exclusions
			array( 'css', '[data-elementor-type]', 'Elementor data attributes' ),
			array( 'css', '.elementor-editor-active', 'Elementor editor mode' ),
			array( 'css', '[data-widget_type]', 'Elementor widget data' ),
			array( 'css', '.vc_row', 'Visual Composer rows' ),
			array( 'css', '.wpb_wrapper', 'WPBakery wrapper elements' ),
			array( 'css', '[data-vc-*]', 'Visual Composer data attributes' ),
			array( 'css', '.fl-builder', 'Beaver Builder elements' ),
			array( 'css', '.et_pb_module', 'Divi Builder modules' ),
			array( 'css', '.fusion-builder-live', 'Fusion Builder live mode' ),

			// Technical Exclusions
			array( 'css', 'script, style, noscript', 'Technical elements' ),
			array( 'css', '.no-translate, .notranslate', 'No-translate classes' ),
			array( 'css', '[translate="no"]', 'HTML translate attribute' ),
			array( 'css', 'code, pre', 'Code blocks' ),

			// Media and Assets
			array( 'css', 'img[src*="data:"]', 'Base64 encoded images' ),
			array( 'css', '[class*="icon-"]', 'Icon classes' ),
			array( 'css', '[class*="fa-"]', 'FontAwesome icons' ),
		);

		$added = 0;
		foreach ( $common_exclusions as $exclusion ) {
			// Check if rule already exists
			$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `%1s` WHERE rule_type = %s AND rule_value = %s", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table_name,
					$exclusion[0],
					$exclusion[1]
				)
			);

			if ( $existing == 0 ) {
				$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$table_name,
					array(
						'rule_type'   => $exclusion[0],
						'rule_value'  => $exclusion[1],
						'description' => $exclusion[2],
						'is_active'   => 1,
					)
				);

				if ( $result ) {
					++$added;
				}
			}
		}

		return $added;
	}

	/**
	 * AJAX: Seed common exclusions
	 */
	public function ajaxSeedExclusions() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$added = $this->seedCommonExclusions();

		wp_send_json_success(
			array(
				'message' => sprintf( /* translators: %d is the number of added exclusion rules */ esc_html__( 'Added %d new exclusion rules', 'voxfor-multilanguage' ), $added ),
				'added'   => $added,
			)
		);
	}

	/**
	 * AJAX: Toggle translation lock
	 */
	public function ajaxToggleTranslationLock() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$translation_id = isset( $_POST['translation_id'] ) ? intval( $_POST['translation_id'] ) : 0;
		$memory         = new \VoxforML\Database\TranslationMemory();

		$result = $memory->toggleTranslationLock( $translation_id );

		if ( $result !== false ) {
			wp_send_json_success(
				array(
					'message'   => __( 'Translation lock toggled successfully', 'voxfor-multilanguage' ),
					'is_locked' => $result,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to toggle translation lock', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * AJAX: Toggle needs review status
	 */
	public function ajaxToggleNeedsReview() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$translation_id = isset( $_POST['translation_id'] ) ? intval( $_POST['translation_id'] ) : 0;
		$needs_review   = isset( $_POST['needs_review'] ) ? intval( $_POST['needs_review'] ) : 1;
		$memory         = new \VoxforML\Database\TranslationMemory();

		$result = $memory->toggleNeedsReview( $translation_id, $needs_review );

		if ( $result ) {
			wp_send_json_success(
				array(
					'message'      => __( 'Review status updated successfully', 'voxfor-multilanguage' ),
					'needs_review' => $needs_review,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to update review status', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * AJAX: Update translation
	 */
	public function ajaxUpdateTranslation() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$translation_id = isset( $_POST['translation_id'] ) ? intval( $_POST['translation_id'] ) : 0;
		$new_text       = isset( $_POST['translated_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['translated_text'] ) ) : '';

		// Normalize whitespace to prevent excessive spaces
		$new_text = trim( preg_replace( '/\s+/', ' ', $new_text ) );

		// Direct database update instead of using the class to avoid 500 errors
		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translations';
		
		// Check if translation is locked first
		$is_locked = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT is_locked FROM `%1s` WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			$table_name,
				$translation_id
			)
		);
		
		if ( $is_locked ) {
			wp_send_json_error( array( 'message' => __( 'Translation is locked and cannot be edited', 'voxfor-multilanguage' ) ) );
			return;
		}
		
		// Update the translation directly
		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table_name,
			array(
				'translated_text' => $new_text,
				'updated_at' => current_time( 'mysql' )
			),
			array( 'id' => $translation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Clear any caches that might be interfering
		wp_cache_flush();
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'translation_' . $translation_id, 'voxfor_ml' );
		}
		
		// Clear WordPress transients
		delete_transient( 'voxfor_ml_translations' );
		delete_transient( 'voxfor_ml_translation_' . $translation_id );
		
		// Clear any object cache
		if ( function_exists( 'wp_cache_delete_group' ) ) {
			wp_cache_delete_group( 'voxfor_ml' );
		}
		
		// Verify the update by reading the translation back from database
		$current_text = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT translated_text FROM `%1s` WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			$table_name,
				$translation_id
			)
		);

		if ( $result ) {
			wp_send_json_success(
				array(
					'message'         => __( 'Translation updated successfully', 'voxfor-multilanguage' ),
					'translated_text' => $new_text,
					'debug' => array(
						'translation_id' => $translation_id,
						'update_result' => $result,
						'wpdb_error' => $wpdb->last_error,
						'wpdb_query' => $wpdb->last_query,
						'sent_text' => $new_text,
						'current_db_text' => $current_text,
						'texts_match' => ($new_text === $current_text),
						'table_name' => $table_name,
						'update_query' => "UPDATE {$table_name} SET translated_text = '{$new_text}', updated_at = '" . current_time('mysql') . "' WHERE id = {$translation_id}",
					)
				)
			);
		} else {
			wp_send_json_error( 
				array( 
					'message' => __( 'Failed to update translation', 'voxfor-multilanguage' ),
					'debug' => array(
						'translation_id' => $translation_id,
						'update_result' => $result,
						'wpdb_error' => $wpdb->last_error,
						'wpdb_query' => $wpdb->last_query,
					)
				) 
			);
		}
	}

	/**
	 * AJAX: Delete translation
	 */
	public function ajaxDeleteTranslation() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$translation_id = isset( $_POST['translation_id'] ) ? intval( $_POST['translation_id'] ) : 0;

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translations';

		$result = $wpdb->delete( $table_name, array( 'id' => $translation_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Translation deleted successfully', 'voxfor-multilanguage' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to delete translation', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * AJAX: Save language SEO settings
	 */
	public function ajaxSaveLanguageSEO() {
		check_ajax_referer( 'voxfor_ml_language_seo', 'voxfor_ml_seo_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$settings = array(
			'indexable'                   => isset( $_POST['indexable'] ),
			'in_sitemap'                  => isset( $_POST['in_sitemap'] ),
			'meta_title_pattern'          => isset( $_POST['meta_title_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_title_pattern'] ) ) : '',
			'meta_description_pattern'    => isset( $_POST['meta_description_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_description_pattern'] ) ) : '',
			'og_title_pattern'            => isset( $_POST['og_title_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['og_title_pattern'] ) ) : '',
			'og_description_pattern'      => isset( $_POST['og_description_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['og_description_pattern'] ) ) : '',
			'twitter_title_pattern'       => isset( $_POST['twitter_title_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['twitter_title_pattern'] ) ) : '',
			'twitter_description_pattern' => isset( $_POST['twitter_description_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['twitter_description_pattern'] ) ) : '',
			'translate_image_alt'         => isset( $_POST['translate_image_alt'] ),
			'translate_image_title'       => isset( $_POST['translate_image_title'] ),
			'custom_robots_rules'         => isset( $_POST['custom_robots_rules'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_robots_rules'] ) ) : '',
		);

		$per_lang_seo = new \VoxforML\SEO\PerLanguageSEO();
		$per_lang_seo->updateLanguageSettings( $language, $settings );

		wp_send_json_success( array( 'message' => __( 'SEO settings saved', 'voxfor-multilanguage' ) ) );
	}

	/**
	 * AJAX: Test API key
	 */
	public function ajaxTestApiKey() {
		// Verify nonce
		if ( ! check_ajax_referer( 'voxfor_ml_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'voxfor-multilanguage' ) ) );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'voxfor-multilanguage' ) ) );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( empty( $api_key ) ) {
			// Test current stored key
			$encryption_handler = new \VoxforML\Utils\EncryptionHandler();
			$result             = $encryption_handler->testApiKey();
		} else {
			// Test provided key
			$encryption_handler = new \VoxforML\Utils\EncryptionHandler();
			$result             = $encryption_handler->testApiKey( $api_key );
		}

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * Handle settings form submission with encrypted API key
	 */
	public function handleSettingsSubmission() {
		if ( ! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'voxfor_ml_settings-options' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle other settings normally
		$settings = array(
			'voxfor_ml_deepl_api_key',  // ADD THIS - the API key field!
			'voxfor_ml_languages',  // Fixed: was voxfor_ml_enabled_languages
			'voxfor_ml_default_language',
			'voxfor_ml_auto_redirect',
			'voxfor_ml_cache_ttl',
			'voxfor_ml_rate_limit',
			'voxfor_ml_enable_hreflang',
			'voxfor_ml_translate_slugs',
			'voxfor_ml_immediate_translation',  // Added missing immediate translation setting
			'voxfor_ml_noindex_preparing',
			'voxfor_ml_translate_image_alt',
			'voxfor_ml_widget_style',
			'voxfor_ml_show_flags',
			'voxfor_ml_show_native_names',
			'voxfor_ml_floating_switcher',
			'voxfor_ml_enable_object_cache',
			'voxfor_ml_enable_lazy_loading',
			'voxfor_ml_wc_translate_products',
			'voxfor_ml_wc_translate_categories',
			'voxfor_ml_wc_translate_attributes',
			'voxfor_ml_wc_translate_ui',
			'voxfor_ml_wc_translate_shop_pages',
			'voxfor_ml_wc_preserve_currency',
			'voxfor_ml_wc_preserve_cart',
			'voxfor_ml_api_enabled',
			'voxfor_ml_daily_credit_limit',
			'voxfor_ml_monthly_credit_limit',
		);

		foreach ( $settings as $setting ) {
			if ( isset( $_POST[ $setting ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $setting ] ) );
				
				// Special handling for API key
				if ( $setting === 'voxfor_ml_deepl_api_key' ) {
					$value = $this->sanitizeApiKey( $value );
				} elseif ( is_array( $value ) ) {
					$value = array_map( 'sanitize_text_field', $value );
				} else {
					$value = sanitize_text_field( wp_unslash( $value ) );
				}
				update_option( $setting, $value );
			}
		}

		// Handle checkbox settings that might not be posted
		$checkbox_settings = array(
			'voxfor_ml_auto_redirect',
			'voxfor_ml_enable_hreflang',
			'voxfor_ml_translate_slugs',
			'voxfor_ml_immediate_translation',
			'voxfor_ml_noindex_preparing',
			'voxfor_ml_translate_image_alt',
			'voxfor_ml_include_post_tags_sitemap',
			'voxfor_ml_include_product_tags_sitemap',
			'voxfor_ml_show_flags',
			'voxfor_ml_show_native_names',
			'voxfor_ml_floating_switcher',
			'voxfor_ml_enable_object_cache',
			'voxfor_ml_enable_lazy_loading',
			'voxfor_ml_wc_translate_products',
			'voxfor_ml_wc_translate_categories',
			'voxfor_ml_wc_translate_attributes',
			'voxfor_ml_wc_translate_ui',
			'voxfor_ml_wc_translate_shop_pages',
			'voxfor_ml_wc_preserve_currency',
			'voxfor_ml_wc_preserve_cart',
			'voxfor_ml_api_enabled',
			'voxfor_ml_alert_daily_80',
			'voxfor_ml_alert_monthly_80',
		);

		foreach ( $checkbox_settings as $checkbox ) {
			if ( ! isset( $_POST[ $checkbox ] ) ) {
				update_option( $checkbox, false );
			}
		}

		add_settings_error(
			'voxfor_ml_settings',
			'settings_saved',
			__( 'Settings saved successfully.', 'voxfor-multilanguage' ),
			'success'
		);

		// Flush rewrite rules after language settings change
		flush_rewrite_rules();

		// Redirect to prevent resubmission
		$redirect_url = add_query_arg( array( 'settings-updated' => 'true' ), admin_url( 'admin.php?page=voxfor-ml-settings' ) );
		wp_redirect( $redirect_url );
		exit;
	}



	/**
	 * AJAX: Fix diagnostic issue
	 */
	public function ajaxFixDiagnosticIssue() {
		// Verify nonce
		if ( ! check_ajax_referer( 'voxfor_ml_diagnostics', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'voxfor-multilanguage' ) ) );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'voxfor-multilanguage' ) ) );
		}

		$check = isset( $_POST['check'] ) ? sanitize_text_field( wp_unslash( $_POST['check'] ) ) : '';

		if ( empty( $check ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid check parameter', 'voxfor-multilanguage' ) ) );
		}

		// Handle different diagnostic fixes
		switch ( $check ) {
			case 'db_charset':
			case 'db_collation':
				wp_send_json_success( array( 'message' => __( 'Database charset/collation fixes require manual server configuration', 'voxfor-multilanguage' ) ) );
				break;

			case 'wp_charset':
				wp_send_json_success( array( 'message' => __( 'WordPress charset requires wp-config.php modification', 'voxfor-multilanguage' ) ) );
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Unknown diagnostic check', 'voxfor-multilanguage' ) ) );
		}
	}



	/**
	 * Clear translation memory (for debugging)
	 */
	public function clearTranslationMemory() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'voxfor_ml_translations';
		$wpdb->query( $wpdb->prepare( "TRUNCATE TABLE `%1s`", $table_name ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder

		return true;
	}

	// ajaxGetSegments method removed - handled by VisualEditor class

	/**
	 * AJAX: Save segment translation
	 * NOTE: This method is now handled by VisualEditor class to avoid conflicts
	 * Keeping this as a fallback/reference
	 */
	private function ajaxSaveSegmentLegacy() {
		// This method is no longer used - VisualEditor handles save_segment AJAX calls
		wp_send_json_error( array( 'message' => __( 'Method moved to VisualEditor class', 'voxfor-multilanguage' ) ) );
	}

	// ajaxLockSegment method removed - handled by VisualEditor class

	/**
	 * AJAX: Translate WooCommerce product
	 */
	public function ajaxTranslateProduct() {
		// SECURITY FIRST: Verify nonce and permissions before any processing
		if ( ! check_ajax_referer( 'voxfor_ml_product_translate', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'voxfor-multilanguage' ) ) );
		}

		// Check permissions
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'voxfor-multilanguage' ) ) );
		}

		// Sanitize and validate input
		$product_id = intval( $_POST['product_id'] ?? 0 );
		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

		if ( ! $product_id || ! $language ) {
			wp_send_json_error( array( 'message' => 'Invalid parameters' ) );
		}

		// Prevent duplicate simultaneous calls with a transient lock
		$lock_key  = 'voxfor_ml_translate_lock_' . $product_id;
		$lock_time = get_transient( $lock_key );
		if ( $lock_time ) {
			// If lock is older than 2 minutes, clear it (stuck lock)
			if ( ( time() - $lock_time ) > 120 ) {
				delete_transient( $lock_key );
			} else {
				wp_send_json_error( array( 'message' => 'Translation already in progress' ) );
			}
		}

		// Set lock with timestamp
		set_transient( $lock_key, time(), 120 );

		// Disable automatic translation queuing to prevent interference
		add_filter( 'voxfor_ml_disable_auto_queue', '__return_true', 1 );

		// Set a global flag as backup
		if ( ! defined( 'VOXFOR_ML_AJAX_TRANSLATING' ) ) {
			define( 'VOXFOR_ML_AJAX_TRANSLATING', true );
		}

		// Disable WordPress database error display for AJAX
		global $wpdb;
		$wpdb->suppress_errors( true );
		$wpdb->hide_errors();

		// Start output buffering to catch any errors that might interfere with JSON response
		ob_start();

		// Set proper content type header early
		header( 'Content-Type: application/json' );

		// Validate product exists and user can edit it
		$product = wc_get_product( $product_id );
		if ( ! $product || ! current_user_can( 'edit_post', $product_id ) ) {
			ob_end_clean();
			wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'voxfor-multilanguage' ) ) );
		}

		// Get product
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			ob_end_clean();
			wp_send_json_error( array( 'message' => __( 'Product not found', 'voxfor-multilanguage' ) ) );
		}

		$translator = $this->plugin->getComponent( 'translator' );
		$memory     = $this->plugin->getComponent( 'translation_memory' );

		$results       = array();
		$success_count = 0;
		$total_count   = 0;

		// Get the DeepL translator directly for API calls
		$deepl_translator = new \VoxforML\Translator\DeepLTranslator();
		$default_lang     = $this->plugin->getDefaultLanguage();

		// Check if API key is configured using the proper encryption handler
		try {
			$api_key = \VoxforML\Utils\EncryptionHandler::getApiKey();
		} catch ( Exception $e ) {
			$api_key = '';
		}

		if ( empty( $api_key ) ) {

			ob_end_clean();
			wp_send_json_error( array( 'message' => __( 'DeepL API key not configured', 'voxfor-multilanguage' ) ) );
		}

		// Translate product name
		if ( $product->get_name() ) {
			++$total_count;
			try {
				$translated = $deepl_translator->translate( $product->get_name(), $language, $default_lang, 'product_name' );

				if ( $translated ) {
					// Product names are usually plain text, but let's normalize anyway
					$pure_name_text = wp_strip_all_tags( $product->get_name() );
					$pure_name_text = html_entity_decode( $pure_name_text, ENT_QUOTES, 'UTF-8' );
					$pure_name_text = trim( $pure_name_text );

					$pure_translated_name = wp_strip_all_tags( $translated );
					$pure_translated_name = html_entity_decode( $pure_translated_name, ENT_QUOTES, 'UTF-8' );
					$pure_translated_name = trim( $pure_translated_name );

					// Direct database insertion for pure text - save in multiple contexts for compatibility
					global $wpdb;
					$table_name = $wpdb->prefix . 'voxfor_ml_translations';

					// Save in multiple contexts for different lookup scenarios
					$contexts_to_save = array( 'product_name', 'title', 'post_title' );

					foreach ( $contexts_to_save as $context ) {
						$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
							$table_name,
							array(
								'source_hash'     => hash( 'sha256', $pure_name_text ),
								'source_text'     => $pure_name_text,
								'translated_text' => $pure_translated_name,
								'language_code'   => $language,
								'context'         => $context,
								'post_id'         => $product_id,
								'provider'        => 'deepl',
							)
						);
					}

					if ( $wpdb->last_error ) {

					} else {
						++$success_count;
						$results['name'] = $translated;

					}
				}
			} catch ( Exception $e ) {

			}
		}

		// Translate product description
		if ( $product->get_description() ) {
			++$total_count;
			try {
				$translated = $deepl_translator->translate( $product->get_description(), $language, $default_lang, 'product_description' );

				if ( $translated ) {
					// Extract pure text for HTML-agnostic storage
					$pure_desc_text = wp_strip_all_tags( $product->get_description() );
					$pure_desc_text = html_entity_decode( $pure_desc_text, ENT_QUOTES, 'UTF-8' );
					$pure_desc_text = trim( $pure_desc_text );

					$pure_translated_desc = wp_strip_all_tags( $translated );
					$pure_translated_desc = html_entity_decode( $pure_translated_desc, ENT_QUOTES, 'UTF-8' );
					$pure_translated_desc = trim( $pure_translated_desc );

					// Direct database insertion for pure text - save in multiple contexts for compatibility
					global $wpdb;
					$table_name = $wpdb->prefix . 'voxfor_ml_translations';

					// Save in multiple contexts for different lookup scenarios
					$contexts_to_save = array( 'product_description', 'content', 'post_content' );

					foreach ( $contexts_to_save as $context ) {
						$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
							$table_name,
							array(
								'source_hash'     => hash( 'sha256', $pure_desc_text ),
								'source_text'     => $pure_desc_text,
								'translated_text' => $pure_translated_desc,
								'language_code'   => $language,
								'context'         => $context,
								'post_id'         => $product_id,
								'provider'        => 'deepl',
							)
						);
					}

					if ( $wpdb->last_error ) {

					} else {
						++$success_count;
						$results['description'] = $translated;

					}
				}
			} catch ( Exception $e ) {

			}
		}

		// Translate short description
		$short_desc = $product->get_short_description();

		// Also get the formatted versions that might be requested by the frontend
		$formatted_short_desc   = '<h2>Product short description</h2>' . "\n" . $short_desc;
		$paragraph_wrapped_desc = '<p>' . $short_desc . '</p>' . "\n";

		if ( $short_desc ) {
			++$total_count;
			try {

				// Translate the complete short description as one piece (SIMPLE APPROACH)
				$translated = $deepl_translator->translate( $short_desc, $language, $default_lang, 'product_short_description' );

				if ( $translated && $translated !== $short_desc ) {
					// Direct database insertion
					global $wpdb;
					$table_name = $wpdb->prefix . 'voxfor_ml_translations';

					// Save in multiple contexts for compatibility
					$contexts_to_save = array( 'product_short_description', 'content', 'text_fragment' );

					foreach ( $contexts_to_save as $context ) {
						$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
							$table_name,
							array(
								'source_hash'     => hash( 'sha256', $short_desc ),
								'source_text'     => $short_desc,
								'translated_text' => $translated,
								'language_code'   => $language,
								'context'         => $context,
								'post_id'         => $product_id,
								'provider'        => 'deepl',
							)
						);
					}

					if ( $wpdb->last_error ) {

					} else {
						++$success_count;
						$results['short_description'] = $translated;

					}
				} else {

				}
			} catch ( Exception $e ) {

			}
		} else {

		}

		// Clean any output that might interfere with JSON response
		$buffer_contents = ob_get_clean();
		if ( ! empty( $buffer_contents ) ) {

		}

		// Force a clean response - clear ALL output buffers
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// Clear any potential output from WordPress
		if ( function_exists( 'wp_ob_end_flush_all' ) ) {
			wp_ob_end_flush_all();
		}

		// Start fresh output buffering for clean JSON
		ob_start();

		// Clear the fresh buffer before sending JSON
		ob_end_clean();

		try {
			if ( $success_count > 0 ) {
				$percentage = round( ( $success_count / $total_count ) * 100 );
				$response   = array(
					'success' => true,
					'data'    => array(
						'message'       => sprintf( /* translators: %1$d is translated fields count, %2$d is total fields count, %3$d is percentage */ __( 'Translated %1$d of %2$d fields (%3$d%%)', 'voxfor-multilanguage' ), $success_count, $total_count, $percentage ),
						'results'       => $results,
						'percentage'    => $percentage,
						'success_count' => $success_count,
						'total_count'   => $total_count,
					),
				);

				// Clean up lock
				$lock_key = 'voxfor_ml_translate_lock_' . get_current_user_id();
				delete_transient( $lock_key );

				// Send clean JSON response using WordPress function
				wp_send_json_success( $response['data'] );
			} else {
				$response = array(
					'success' => false,
					'data'    => array(
						'message' => __( 'No translations were created. Check if product has content and DeepL API is working.', 'voxfor-multilanguage' ),
					),
				);

				// Clean up lock
				$lock_key = 'voxfor_ml_translate_lock_' . get_current_user_id();
				delete_transient( $lock_key );

				// Send clean JSON response using WordPress function
				wp_send_json_error( $response['data'] );
			}
		} catch ( Exception $e ) {

			// Send clean error response
			header( 'Content-Type: application/json' );
			echo json_encode(
				array(
					'success' => false,
					'data'    => array( 'message' => 'Translation failed due to an error' ),
				)
			);

			// Clean up lock
			$lock_key = 'voxfor_ml_translate_lock_' . get_current_user_id();
			delete_transient( $lock_key );

			wp_die();
		}

		// Also translate common WooCommerce UI strings for this language
		$this->translateWooCommerceUIStrings( $language );

		// Clean up lock on function end
		$lock_key = 'voxfor_ml_translate_lock_' . get_current_user_id();
		delete_transient( $lock_key );
	}

	/**
	 * Translate common WooCommerce UI strings
	 */
	private function translateWooCommerceUIStrings( $language ) {
		$memory           = $this->plugin->getComponent( 'translation_memory' );
		$deepl_translator = new \VoxforML\Translator\DeepLTranslator();

		// Common WooCommerce UI strings that appear on product pages
		$ui_strings = array(
			'Description'            => 'wc_tab_description',
			'Additional information' => 'wc_tab_additional_info',
			'Reviews'                => 'wc_tab_reviews',
			'Related products'       => 'wc_related_products',
			'Add to cart'            => 'wc_add_to_cart',
			'SKU:'                   => 'wc_sku_label',
			'Category:'              => 'wc_category_label',
			'Free Shipping'          => 'wc_free_shipping',
			'Home'                   => 'breadcrumb_home',
			'Accessories'            => 'category_accessories',
			'Product'                => 'wc_product_label',
			'Price'                  => 'wc_price_label',
			'In stock'               => 'wc_in_stock',
			'Out of stock'           => 'wc_out_of_stock',
		);

		foreach ( $ui_strings as $text => $context ) {
			// Check if already translated
			$existing = $memory->getTranslation( $text, $language, $context );
			if ( $existing === false ) {
				try {
					// Translate using DeepL
					$translated = $deepl_translator->translate( $text, $language, 'EN', $context );
					if ( $translated && $translated !== $text ) {
						$memory->saveTranslation( $text, $translated, $language, $context, null, 'deepl' );
					}
				} catch ( Exception $e ) {
					// Continue with other strings if one fails
					continue;
				}
			}
		}
	}

	/**
	 * AJAX: Translate WooCommerce product to all languages
	 */
	public function ajaxTranslateAllProduct() {
		// Verify nonce
		if ( ! check_ajax_referer( 'voxfor_ml_product_translate_all', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'voxfor-multilanguage' ) ) );
		}

		// Check permissions
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'voxfor-multilanguage' ) ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID', 'voxfor-multilanguage' ) ) );
		}

		// Get product
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Product not found', 'voxfor-multilanguage' ) ) );
		}

		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();
		$memory       = $this->plugin->getComponent( 'translation_memory' );

		// Get the DeepL translator directly for API calls
		$deepl_translator = new \VoxforML\Translator\DeepLTranslator();

		$total_languages   = 0;
		$success_languages = 0;
		$results           = array();

		foreach ( $languages as $language ) {
			if ( $language === $default_lang ) {
				continue;
			}

			++$total_languages;
			$lang_success_count = 0;
			$lang_total_count   = 0;

			// Translate product name
			if ( $product->get_name() ) {
				++$lang_total_count;
				$translated = $deepl_translator->translate( $product->get_name(), $language, $default_lang, 'product_name' );
				if ( $translated && $translated !== $product->get_name() ) {
					$memory->saveTranslation( $product->get_name(), $translated, $language, 'product_name', $product_id );
					++$lang_success_count;
				}
			}

			// Translate product description
			if ( $product->get_description() ) {
				++$lang_total_count;
				$translated = $deepl_translator->translate( $product->get_description(), $language, $default_lang, 'product_description' );
				if ( $translated && $translated !== $product->get_description() ) {
					$memory->saveTranslation( $product->get_description(), $translated, $language, 'product_description', $product_id );
					++$lang_success_count;
				}
			}

			// Translate short description
			if ( $product->get_short_description() ) {
				++$lang_total_count;
				$translated = $deepl_translator->translate( $product->get_short_description(), $language, $default_lang, 'product_short_description' );
				if ( $translated && $translated !== $product->get_short_description() ) {
					$memory->saveTranslation( $product->get_short_description(), $translated, $language, 'product_short_description', $product_id );
					++$lang_success_count;
				}
			}

			if ( $lang_success_count > 0 ) {
				++$success_languages;
				$results[ $language ] = array(
					'success_count' => $lang_success_count,
					'total_count'   => $lang_total_count,
					'percentage'    => round( ( $lang_success_count / $lang_total_count ) * 100 ),
				);
			}
		}

		if ( $success_languages > 0 ) {
			wp_send_json_success(
				array(
					'message'           => sprintf( /* translators: %1$d is successful languages count, %2$d is total languages count */ __( 'Product translated to %1$d of %2$d languages', 'voxfor-multilanguage' ), $success_languages, $total_languages ),
					'results'           => $results,
					'success_languages' => $success_languages,
					'total_languages'   => $total_languages,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'No translations were created', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * Extract translatable text pieces from HTML
	 */
	private function extractTranslatableTextFromHTML( $html ) {
		if ( empty( $html ) ) {

			return array();
		}

		$text_pieces = array();

		// Method 1: Simple regex extraction (most reliable)
		preg_match_all( '/>([^<]+)</u', $html, $matches );

		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $index => $match ) {

				$cleaned = trim( html_entity_decode( $match, ENT_QUOTES, 'UTF-8' ) );

				if ( ! empty( $cleaned ) && strlen( $cleaned ) > 1 && preg_match( '/[a-zA-Z]/', $cleaned ) ) {

					$text_pieces[] = $cleaned;
				} else {

				}
			}
		} else {

		}

		// If regex didn't find anything, try strip_tags approach
		if ( empty( $text_pieces ) ) {

			$plain_text = wp_strip_all_tags( $html );
			$plain_text = html_entity_decode( $plain_text, ENT_QUOTES, 'UTF-8' );

			// Split by newlines and clean up
			$lines = preg_split( '/[\n\r]+/', $plain_text );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( ! empty( $line ) && strlen( $line ) > 1 && preg_match( '/[a-zA-Z]/', $line ) ) {

					$text_pieces[] = $line;
				}
			}
		}

		$result = array_unique( $text_pieces );

		return $result;
	}

	/**
	 * Fallback text extraction using regex
	 */
	private function extractTextWithRegex( $html ) {

		// Extract text between HTML tags using regex
		$text_matches = array();
		preg_match_all( '/>([^<]+)</u', $html, $text_matches );

		$text_pieces = array();
		if ( ! empty( $text_matches[1] ) ) {
			foreach ( $text_matches[1] as $match ) {
				$cleaned = trim( html_entity_decode( $match, ENT_QUOTES, 'UTF-8' ) );
				if ( ! empty( $cleaned ) ) {
					$text_pieces[] = $cleaned;
				}
			}
		}

		// Also try extracting all text content and splitting intelligently
		$all_text = wp_strip_all_tags( $html );
		$all_text = html_entity_decode( $all_text, ENT_QUOTES, 'UTF-8' );
		$all_text = preg_replace( '/\s+/', ' ', $all_text );

		// Split by common separators while preserving meaningful phrases
		$additional_pieces = preg_split( '/[•\n\r\t]+/', $all_text );

		foreach ( $additional_pieces as $piece ) {
			$piece = trim( $piece );
			if ( ! empty( $piece ) ) {
				$text_pieces[] = $piece;
			}
		}

		$cleaned_pieces = array();
		foreach ( $text_pieces as $piece ) {
			$piece = trim( $piece );
			// Only include meaningful text (more than 1 character, contains letters)
			if ( strlen( $piece ) > 1 && preg_match( '/[a-zA-Z]/', $piece ) ) {
				$cleaned_pieces[] = $piece;
			}
		}

		return array_unique( $cleaned_pieces );
	}

	/**
	 * Recursively extract text from DOM nodes
	 */
	private function extractTextFromNode( $node, &$text_pieces ) {
		if ( ! $node ) {
			return;
		}

		// Add debugging
		if ( $node->nodeType === XML_TEXT_NODE ) {
			$text = trim( $node->nodeValue );
			if ( ! empty( $text ) ) {

				$text_pieces[] = $text;
			}
		} elseif ( $node->nodeType === XML_ELEMENT_NODE ) {

			if ( $node->hasChildNodes() ) {
				foreach ( $node->childNodes as $child ) {
					$this->extractTextFromNode( $child, $text_pieces );
				}
			}
		}
	}

	/**
	 * Replace text pieces in HTML with their translations
	 */
	private function replaceTextPiecesInHTML( $html, $translated_pieces ) {
		$result = $html;

		// Sort by length (longest first) to avoid partial replacements
		uksort(
			$translated_pieces,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		foreach ( $translated_pieces as $original => $translation ) {
			// Use preg_replace with word boundaries for more precise replacement
			$pattern = '/\b' . preg_quote( $original, '/' ) . '\b/u';
			$result  = preg_replace( $pattern, $translation, $result );
		}

		return $result;
	}

	/**
	 * AJAX: Find original text for a translation (reverse lookup)
	 */
	public function ajaxFindOriginalText() {
		// Verify nonce
		check_ajax_referer( 'voxfor_ml_ajax', 'nonce' );

		// Check permissions
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'voxfor-multilanguage' ) ) );
		}

		$translated_text = isset( $_POST['translated_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['translated_text'] ) ) : '';
		$language        = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$post_id         = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

		if ( ! $translated_text || ! $language ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'voxfor-multilanguage' ) ) );
		}

		$memory = $this->plugin->getComponent( 'translation_memory' );

		// Look for a translation where the translated_text matches our input
		global $wpdb;
		$table = $wpdb->prefix . 'voxfor_ml_translations';

		$query  = "SELECT source_text FROM {$table} WHERE translated_text = %s AND language_code = %s";
		$params = array( $translated_text, $language );

		if ( $post_id ) {
			$query   .= ' AND post_id = %d';
			$params[] = $post_id;
		}

		$query .= ' ORDER BY updated_at DESC LIMIT 1';

		$original_text = $wpdb->get_var( $wpdb->prepare( $query, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $original_text ) {
			wp_send_json_success( array( 'original_text' => $original_text ) );
		}

		// Try fuzzy/partial database lookup before giving up
		$fuzzy_query  = "SELECT source_text FROM {$table} WHERE translated_text LIKE %s AND language_code = %s";
		$fuzzy_params = array( '%' . $wpdb->esc_like( $translated_text ) . '%', $language );

		if ( $post_id ) {
			$fuzzy_query   .= ' AND post_id = %d';
			$fuzzy_params[] = $post_id;
		}

		$fuzzy_query   .= ' ORDER BY CHAR_LENGTH(translated_text) ASC LIMIT 1';
		$fuzzy_original = $wpdb->get_var( $wpdb->prepare( $fuzzy_query, ...$fuzzy_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $fuzzy_original ) {
			wp_send_json_success( array( 'original_text' => $fuzzy_original ) );
		}

		// REMOVED: Slow DeepL reverse translation that causes 2-3 minute delays
		// Instead, immediately return a useful fallback

		// Fast fallback: For non-English content, return a helpful placeholder
		$default_lang = $this->plugin->getDefaultLanguage();
		if ( $language !== $default_lang ) {
			wp_send_json_success( array( 'original_text' => '[Original: ' . $translated_text . ']' ) );
		}

		// Final fallback: return the provided text as the original
		wp_send_json_success( array( 'original_text' => $translated_text ) );
	}

	/**
	 * AJAX: Batch find original texts for multiple translations
	 */
	public function ajaxBatchFindOriginalTexts() {
		// Verify nonce
		check_ajax_referer( 'voxfor_ml_ajax', 'nonce' );

		// Check permissions
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'voxfor-multilanguage' ) ) );
		}

		$texts    = isset( $_POST['texts'] ) && is_array( $_POST['texts'] ) ? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['texts'] ) ) : array();
		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$post_id  = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

		if ( ! is_array( $texts ) || empty( $texts ) || ! $language ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters', 'voxfor-multilanguage' ) ) );
		}

		$results = array();
		$memory  = $this->plugin->getComponent( 'translation_memory' );

		global $wpdb;
		$table = $wpdb->prefix . 'voxfor_ml_translations';

		// Process texts in batches to avoid memory issues
		foreach ( $texts as $translated_text ) {
			$translated_text = sanitize_textarea_field( $translated_text );
			if ( empty( $translated_text ) ) {
				continue;
			}

			// Check cache first
			$cache_key = 'voxfor_ml_reverse_' . md5( $translated_text . '_' . $language . '_' . $post_id );
			$cached    = get_transient( $cache_key );
			if ( $cached !== false ) {
				$results[ $translated_text ] = $cached;
				continue;
			}

			// Look for exact match
			$query  = "SELECT source_text FROM {$table} WHERE translated_text = %s AND language_code = %s";
			$params = array( $translated_text, $language );

			if ( $post_id ) {
				$query   .= ' AND post_id = %d';
				$params[] = $post_id;
			}

			$query        .= ' ORDER BY updated_at DESC LIMIT 1';
			$original_text = $wpdb->get_var( $wpdb->prepare( $query, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $original_text ) {
				$results[ $translated_text ] = $original_text;
				set_transient( $cache_key, $original_text, HOUR_IN_SECONDS );
				continue;
			}

			// Try fuzzy match
			$fuzzy_query  = "SELECT source_text FROM {$table} WHERE translated_text LIKE %s AND language_code = %s";
			$fuzzy_params = array( '%' . $wpdb->esc_like( $translated_text ) . '%', $language );

			if ( $post_id ) {
				$fuzzy_query   .= ' AND post_id = %d';
				$fuzzy_params[] = $post_id;
			}

			$fuzzy_query   .= ' ORDER BY CHAR_LENGTH(translated_text) ASC LIMIT 1';
			$fuzzy_original = $wpdb->get_var( $wpdb->prepare( $fuzzy_query, ...$fuzzy_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $fuzzy_original ) {
				$results[ $translated_text ] = $fuzzy_original;
				set_transient( $cache_key, $fuzzy_original, HOUR_IN_SECONDS );
			} else {
				// Use fallback
				$default_lang = $this->plugin->getDefaultLanguage();
				if ( $language !== $default_lang ) {
					$results[ $translated_text ] = '[Original: ' . $translated_text . ']';
				} else {
					$results[ $translated_text ] = $translated_text;
				}
			}
		}

		wp_send_json_success( array( 'translations' => $results ) );
	}


	/**
	 * AJAX handler to cancel pending translations
	 */
	public function ajaxCancelPendingTranslations() {
		// Verify nonce
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'voxfor_ml_cancel_pending' ) ) {
			wp_send_json_error( 'Invalid nonce' );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
			return;
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', wp_unslash( $_POST['ids'] ) ) : array();
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			wp_send_json_error( 'No translation IDs provided' );
			return;
		}

		// Sanitize IDs
		$ids = array_map( 'intval', $ids );
		$ids = array_filter(
			$ids,
			function ( $id ) {
				return $id > 0;
			}
		);

		if ( empty( $ids ) ) {
			wp_send_json_error( 'Invalid translation IDs' );
			return;
		}

		// Cancel translations
		$memory = $this->plugin->getComponent( 'translation_memory' );
		$result = $memory->cancelMultiplePendingTranslations( $ids );

		if ( $result ) {
			wp_send_json_success( array( 'message' => sprintf( /* translators: %d is the number of cancelled translations */ __( '%d translations cancelled successfully', 'voxfor-multilanguage' ), count( $ids ) ) ) );
		} else {
			wp_send_json_error( 'Failed to cancel translations' );
		}
	}

	/**
	 * AJAX handler to process pending translations
	 */
	public function ajaxProcessPendingTranslations() {
		// Verify nonce
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'voxfor_ml_process_pending' ) ) {
			wp_send_json_error( 'Invalid nonce' );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
			return;
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', wp_unslash( $_POST['ids'] ) ) : array();
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			wp_send_json_error( 'No translation IDs provided' );
			return;
		}

		// Sanitize IDs
		$ids = array_map( 'intval', $ids );
		$ids = array_filter(
			$ids,
			function ( $id ) {
				return $id > 0;
			}
		);

		if ( empty( $ids ) ) {
			wp_send_json_error( 'Invalid translation IDs' );
			return;
		}

		// Process translations (this will use API credits)
		$translator_manager = $this->plugin->getComponent( 'translator' );
		$processed          = 0;
		$failed             = 0;

		foreach ( $ids as $id ) {
			try {
				// Get queue item
				global $wpdb;
				$table_queue = $wpdb->prefix . 'voxfor_ml_translation_queue';
				$item        = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT * FROM `%1s` WHERE id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			$table_queue,
						$id
					)
				);

				if ( ! $item ) {
					++$failed;
					continue;
				}

				// Translate text
				$translated = $translator_manager->translator->translate(
					$item->source_text,
					$item->language_code,
					$item->context
				);

				if ( $translated ) {
					// Save to main translations table
					$memory = $this->plugin->getComponent( 'translation_memory' );
					$memory->saveTranslation(
						$item->source_text,
						$translated,
						$item->language_code,
						$item->context,
						$item->post_id,
						'deepl'
					);

					// Mark queue item as completed
					$memory->markQueueItemProcessed( $id, 'completed' );
					++$processed;
				} else {
					// Mark as failed
					$memory = $this->plugin->getComponent( 'translation_memory' );
					$memory->markQueueItemProcessed( $id, 'failed', 'Translation API call failed' );
					++$failed;
				}
			} catch ( Exception $e ) {
				++$failed;
				// Translation processing error handled
			}
		}

		$message = sprintf(
			/* translators: %1$d is successful translations count, %2$d is failed translations count */
			__( '%1$d translations processed successfully, %2$d failed', 'voxfor-multilanguage' ),
			$processed,
			$failed
		);

		wp_send_json_success( array( 'message' => $message ) );
	}

	/**
	 * Get translation activity for last 30 days
	 */
	private function getTranslationActivity() {
		global $wpdb;
		$table = $wpdb->prefix . 'voxfor_ml_translations';

		// Get data for last 30 days
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT DATE(created_at) as date, COUNT(*) as translations, SUM(CHAR_LENGTH(source_text)) as characters FROM `%1s` WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY date ASC", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table
			)
		);

		// Fill in missing days with zero values
		$activity   = array();
		$start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

		for ( $i = 0; $i < 30; $i++ ) {
			$date  = gmdate( 'Y-m-d', strtotime( $start_date . ' +' . $i . ' days' ) );
			$found = false;

			foreach ( $results as $result ) {
				if ( $result->date === $date ) {
					$activity[] = array(
						'date'         => $date,
						'translations' => (int) $result->translations,
						'words'        => $this->estimateWordsFromChars( (int) $result->characters ),
					);
					$found      = true;
					break;
				}
			}

			if ( ! $found ) {
				$activity[] = array(
					'date'         => $date,
					'translations' => 0,
					'words'        => 0,
				);
			}
		}

		return $activity;
	}



	/**
	 * Estimate words from translation count
	 */
	private function estimateWords( $translation_count ) {
		// Rough estimate: average 5 words per translation
		return $translation_count * 5;
	}

	/**
	 * Estimate words from character count
	 */
	private function estimateWordsFromChars( $char_count ) {
		// Rough estimate: average 5 characters per word
		return round( $char_count / 5 );
	}


	/**
	 * AJAX: Process all pending translations with progress tracking
	 */
	public function ajaxProcessAllPending() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'voxfor-multilanguage' ) ) );
		}

		// Generate unique progress key
		$progress_key = 'pending_bulk_' . time() . '_' . wp_generate_uuid4();

		// Get all pending translations
		$memory        = $this->plugin->getComponent( 'translation_memory' );
		$all_pending   = $memory->getAllPendingTranslations();
		$total_pending = count( $all_pending );

		if ( $total_pending === 0 ) {
			wp_send_json_success(
				array(
					'message'      => __( 'No pending translations found', 'voxfor-multilanguage' ),
					'progress_key' => $progress_key,
					'total'        => 0,
				)
			);
		}

		// Initialize progress tracking
		set_transient(
			'voxfor_ml_pending_progress_' . $progress_key,
			array(
				'status'     => 'processing',
				'progress'   => 0,
				'current'    => 0,
				'total'      => $total_pending,
				'processed'  => 0,
				'failed'     => 0,
				'message'    => sprintf( /* translators: %d is the number of pending translations */ __( 'Starting to process %d pending translations...', 'voxfor-multilanguage' ), $total_pending ),
				'start_time' => time(),
			),
			300
		); // 5 minutes expiry

		// Start background processing
		wp_send_json_success(
			array(
				'message'      => sprintf( /* translators: %d is the number of pending translations */ __( 'Started processing %d pending translations', 'voxfor-multilanguage' ), $total_pending ),
				'progress_key' => $progress_key,
				'total'        => $total_pending,
			)
		);

		// Process in background after response is sent
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		$this->processPendingInBackground( $progress_key, $all_pending );
	}

	/**
	 * AJAX: Get pending translation progress
	 */
	public function ajaxGetPendingProgress() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'voxfor-multilanguage' ) ) );
		}

		$progress_key = isset( $_POST['progress_key'] ) ? sanitize_text_field( wp_unslash( $_POST['progress_key'] ) ) : '';
		if ( ! $progress_key ) {
			wp_send_json_error( array( 'message' => __( 'Invalid progress key', 'voxfor-multilanguage' ) ) );
		}

		$progress_data = get_transient( 'voxfor_ml_pending_progress_' . $progress_key );
		if ( $progress_data === false ) {
			wp_send_json_success(
				array(
					'status'   => 'completed',
					'progress' => 100,
					'current'  => 0,
					'total'    => 0,
					'message'  => __( 'Processing completed', 'voxfor-multilanguage' ),
				)
			);
		}

		wp_send_json_success( $progress_data );
	}

	/**
	 * Process pending translations in background
	 */
	private function processPendingInBackground( $progress_key, $pending_items ) {
		$translator = $this->plugin->getComponent( 'translator' );
		$memory     = $this->plugin->getComponent( 'translation_memory' );

		if ( ! $translator || ! $memory ) {
			$this->updatePendingProgress( $progress_key, 0, count( $pending_items ), 'Error: Translation components not available', 'error' );
			return;
		}

		$total      = count( $pending_items );
		$processed  = 0;
		$failed     = 0;
		$batch_size = get_option( 'voxfor_ml_batch_size', 50 );

		// Group by language for efficient batch processing
		$grouped = array();
		foreach ( $pending_items as $item ) {
			$key = $item->language_code;
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array();
			}
			$grouped[ $key ][] = $item;
		}

		// Process each language group
		foreach ( $grouped as $language => $items ) {
			$this->updatePendingProgress(
				$progress_key,
				$processed,
				$total,
				sprintf( /* translators: %s is the language code */ __( 'Processing %s translations...', 'voxfor-multilanguage' ), strtoupper( $language ) )
			);

			// Process in batches
			$batches = array_chunk( $items, $batch_size );

			foreach ( $batches as $batch ) {
				$texts    = array();
				$item_map = array();

				foreach ( $batch as $item ) {
					$texts[ $item->id ]    = $item->source_text;
					$item_map[ $item->id ] = $item;
				}

				// Batch translate
				try {
					$translations = $translator->batchTranslate( $texts, $language, $batch[0]->context ?? 'general' );

					foreach ( $translations as $id => $translated ) {
						$item = $item_map[ $id ];

						if ( $translated && ! empty( $translated ) ) {
							// Save translation
							$memory->saveTranslation(
								$item->source_text,
								$translated,
								$language,
								$item->context ?? 'general',
								$item->post_id
							);
							$memory->markQueueItemProcessed( $id, 'completed' );
							++$processed;
						} else {
							$memory->markQueueItemProcessed( $id, 'failed', 'Translation returned empty' );
							++$failed;
						}
					}
				} catch ( Exception $e ) {
					// Mark all items in this batch as failed
					foreach ( $batch as $item ) {
						$memory->markQueueItemProcessed( $item->id, 'failed', $e->getMessage() );
						++$failed;
					}
				}

				// Update progress
				$current_total = $processed + $failed;
				$progress      = ( $current_total / $total ) * 100;
				$this->updatePendingProgress(
					$progress_key,
					$current_total,
					$total,
					sprintf(
						/* translators: %1$d is processed count, %2$d is total count, %3$d is failed count */
						__( 'Processed %1$d/%2$d translations (%3$d failed)', 'voxfor-multilanguage' ),
						$processed,
						$total,
						$failed
					),
					'processing',
					$progress
				);

				// Small delay to prevent API rate limiting
				usleep( 100000 ); // 0.1 second
			}
		}

		// Final update
		$this->updatePendingProgress(
			$progress_key,
			$total,
			$total,
			sprintf(
				/* translators: %1$d is processed count, %2$d is failed count */
				__( 'Completed! Processed %1$d translations (%2$d failed)', 'voxfor-multilanguage' ),
				$processed,
				$failed
			),
			'completed',
			100
		);
	}

	/**
	 * Update pending translation progress
	 */
	private function updatePendingProgress( $progress_key, $current, $total, $message, $status = 'processing', $progress = null ) {
		if ( $progress === null ) {
			$progress = $total > 0 ? ( $current / $total ) * 100 : 0;
		}

		set_transient(
			'voxfor_ml_pending_progress_' . $progress_key,
			array(
				'status'     => $status,
				'progress'   => round( $progress, 1 ),
				'current'    => $current,
				'total'      => $total,
				'message'    => $message,
				'updated_at' => time(),
			),
			300
		);
	}



	/**
	 * AJAX: Comprehensive site translation
	 */
	public function ajaxComprehensiveTranslate() {
		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'voxfor-multilanguage' ) );
		}

		// Verify nonce
		check_ajax_referer( 'voxfor_ml_ajax', 'nonce' );

		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$step     = isset( $_POST['step'] ) ? intval( $_POST['step'] ) : 0;

		if ( empty( $language ) || $language === 'en' ) {
			wp_send_json_error( __( 'Invalid language', 'voxfor-multilanguage' ) );
		}

		try {
			// Check if translation was cancelled
			if ( get_transient( 'voxfor_ml_cancel_translation' ) ) {
				delete_transient( 'voxfor_ml_cancel_translation' );
				wp_send_json_error( __( 'Translation was cancelled', 'voxfor-multilanguage' ) );
			}

			// Include the theme text scanner
			require_once VOXFOR_ML_PLUGIN_DIR . 'includes/Utils/ThemeTextScanner.php';
			$scanner = new \VoxforML\Utils\ThemeTextScanner();

			switch ( $step ) {
				case 0: // Scan homepage
					$texts   = $scanner->scanSiteTexts( $language );
					$message = sprintf( /* translators: %d is the number of scanned texts */ __( 'Scanned %d texts from homepage', 'voxfor-multilanguage' ), count( $texts ) );
					break;

				case 1: // WooCommerce texts
					if ( class_exists( 'WooCommerce' ) ) {
						$texts   = $scanner->scanSiteTexts( $language );
						$message = __( 'WooCommerce texts processed', 'voxfor-multilanguage' );
					} else {
						$message = __( 'WooCommerce not detected, skipping', 'voxfor-multilanguage' );
					}
					break;

				case 2: // Elementor content
					if ( defined( 'ELEMENTOR_VERSION' ) ) {
						$texts   = $scanner->scanSiteTexts( $language );
						$message = __( 'Elementor content processed', 'voxfor-multilanguage' );
					} else {
						$message = __( 'Elementor not detected, skipping', 'voxfor-multilanguage' );
					}
					break;

				case 3: // Theme texts
					$texts   = $scanner->scanSiteTexts( $language );
					$message = __( 'Theme texts processed', 'voxfor-multilanguage' );
					break;

				case 4: // Widgets and menus
					$texts   = $scanner->scanSiteTexts( $language );
					$message = __( 'Widgets and menus processed', 'voxfor-multilanguage' );
					break;

				case 5: // Final translation
					$texts   = $scanner->scanSiteTexts( $language );
					$message = sprintf( /* translators: %s is the language code */ __( 'Translation completed for %s', 'voxfor-multilanguage' ), $language );
					break;

				default:
					wp_send_json_error( __( 'Invalid step', 'voxfor-multilanguage' ) );
			}

			wp_send_json_success(
				array(
					'message'  => $message,
					'step'     => $step,
					'language' => $language,
				)
			);

		} catch ( Exception $e ) {
			wp_send_json_error( __( 'Translation step failed: ', 'voxfor-multilanguage' ) . $e->getMessage() );
		}
	}

	/**
	 * AJAX: Cancel comprehensive translation
	 */
	public function ajaxCancelComprehensiveTranslate() {
		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'voxfor-multilanguage' ) );
		}

		// Verify nonce
		check_ajax_referer( 'voxfor_ml_ajax', 'nonce' );

		// Set a transient to signal cancellation
		set_transient( 'voxfor_ml_cancel_translation', true, 300 ); // 5 minutes

		wp_send_json_success(
			array(
				'message' => __( 'Translation cancelled successfully', 'voxfor-multilanguage' ),
			)
		);
	}

	/**
	 * Render Translate page
	 */
	public function renderTranslatePage() {
		?>
		<div class="wrap voxfor-ml-admin-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<?php settings_errors( 'voxfor_ml_messages' ); ?>
			
			<div class="voxfor-ml-translate-container">
				<!-- Comprehensive Site Translation -->
				<div class="voxfor-ml-translate-section">
					<div class="voxfor-ml-translate-header">
						<h2><?php esc_html_e( 'Comprehensive Site Translation', 'voxfor-multilanguage' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Scan and translate all theme texts, WooCommerce elements, and Elementor content for complete page translation. This ensures every text on your website is translated when users switch languages.', 'voxfor-multilanguage' ); ?></p>
					</div>
					
					<div class="voxfor-ml-translate-content">
						<div class="voxfor-ml-language-selection">
							<h3><?php esc_html_e( 'Select Languages to Translate', 'voxfor-multilanguage' ); ?></h3>
							<div class="voxfor-ml-language-grid">
								<?php
								$enabled_languages = get_option( 'voxfor_ml_languages', array( 'en' ) );
								// Ensure $enabled_languages is always an array
								if ( ! is_array( $enabled_languages ) ) {
									$enabled_languages = array( 'en' );
								}
								$language_names    = array(
									'en' => array( 'English', 'English' ),
									'fr' => array( 'French', 'Français' ),
									'de' => array( 'German', 'Deutsch' ),
									'es' => array( 'Spanish', 'Español' ),
									'it' => array( 'Italian', 'Italiano' ),
									'pt' => array( 'Portuguese', 'Português' ),
									'ru' => array( 'Russian', 'Русский' ),
									'ja' => array( 'Japanese', '日本語' ),
									'ko' => array( 'Korean', '한국어' ),
									'zh' => array( 'Chinese', '中文' ),
									'he' => array( 'Hebrew', 'עברית' ),
								);

								foreach ( $enabled_languages as $lang_code ) {
									if ( $lang_code === 'en' ) {
										continue;
									}
									$lang_name   = isset( $language_names[ $lang_code ] ) ? $language_names[ $lang_code ][0] : $lang_code;
									$native_name = isset( $language_names[ $lang_code ] ) ? $language_names[ $lang_code ][1] : $lang_code;
									?>
									<label class="voxfor-ml-language-option">
										<input type="checkbox" name="comprehensive_languages[]" value="<?php echo esc_attr( $lang_code ); ?>" checked>
										<span class="voxfor-ml-language-info">
											<strong><?php echo esc_html( $lang_name ); ?></strong>
											<small><?php echo esc_html( $native_name ); ?></small>
										</span>
									</label>
									<?php
								}
								?>
							</div>
						</div>
						
						<div class="voxfor-ml-translation-controls">
							<button type="button" class="button button-primary button-hero" id="start-comprehensive-translate-btn">
								<span class="dashicons dashicons-translation"></span>
								<?php esc_html_e( 'Start Comprehensive Translation', 'voxfor-multilanguage' ); ?>
							</button>
							
							<button type="button" class="button button-secondary" id="cancel-comprehensive-translate-btn" style="display: none;">
								<span class="dashicons dashicons-no"></span>
								<?php esc_html_e( 'Cancel Translation', 'voxfor-multilanguage' ); ?>
							</button>
						</div>
						
						<div id="comprehensive-translation-progress" class="voxfor-ml-progress-container" style="display: none;">
							<div class="voxfor-ml-progress-header">
								<h3><?php esc_html_e( 'Translation Progress', 'voxfor-multilanguage' ); ?></h3>
								<div class="voxfor-ml-progress-stats">
									<span id="progress-current-step"></span>
								</div>
							</div>
							
							<div class="voxfor-ml-progress-bar-container">
								<div id="comprehensive-progress-bar" class="voxfor-ml-progress-bar" style="width: 0%;"></div>
								<div class="voxfor-ml-progress-percentage">
									<span id="progress-percentage">0%</span>
								</div>
							</div>
							
							<div class="voxfor-ml-progress-status">
								<p id="comprehensive-status"><?php esc_html_e( 'Initializing...', 'voxfor-multilanguage' ); ?></p>
							</div>
							
							<div class="voxfor-ml-progress-details">
								<details>
									<summary><?php esc_html_e( 'View Details', 'voxfor-multilanguage' ); ?></summary>
									<div id="progress-details-content">
										<ul id="progress-log"></ul>
									</div>
								</details>
							</div>
						</div>
						
						<div id="comprehensive-result" class="voxfor-ml-result-container"></div>
					</div>
				</div>
				
				<!-- Individual Content Translation -->
				<div class="voxfor-ml-translate-section">
					<div class="voxfor-ml-translate-header">
						<h2><?php esc_html_e( 'Individual Content Translation', 'voxfor-multilanguage' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Select specific pages, posts, products, or templates to translate. Perfect for targeted translations and saving API credits.', 'voxfor-multilanguage' ); ?></p>
					</div>
					
					<div class="voxfor-ml-translate-content">
						<!-- Content Type Selection -->
						<div class="voxfor-ml-content-type-selection">
							<h3><?php esc_html_e( 'Select Content Type', 'voxfor-multilanguage' ); ?></h3>
							<div id="content-types-grid" class="voxfor-ml-content-types-grid">
								<!-- Content types will be loaded here -->
							</div>
						</div>
						
						<!-- Content Browser -->
						<div id="content-browser" class="voxfor-ml-content-browser" style="display: none;">
							<div class="voxfor-ml-browser-header">
								<h3 id="browser-title"><?php esc_html_e( 'Select Content to Translate', 'voxfor-multilanguage' ); ?></h3>
								
								<div class="voxfor-ml-browser-controls">
									<div class="voxfor-ml-search-box">
										<input type="text" id="content-search" placeholder="<?php esc_attr_e( 'Search content...', 'voxfor-multilanguage' ); ?>">
										<button type="button" id="search-btn" class="button">
											<span class="dashicons dashicons-search"></span>
										</button>
									</div>
									
									<div id="individual-filters" class="voxfor-ml-filters">
										<!-- Filters will be loaded here -->
									</div>
								</div>
							</div>
							
							<div class="voxfor-ml-content-list-container">
								<div class="voxfor-ml-bulk-actions">
									<label>
										<input type="checkbox" id="select-all-content">
										<?php esc_html_e( 'Select All', 'voxfor-multilanguage' ); ?>
									</label>
									<span id="selected-count">0 <?php esc_html_e( 'selected', 'voxfor-multilanguage' ); ?></span>
								</div>
								
								<div id="individual-content-list" class="voxfor-ml-content-list">
									<!-- Content list will be loaded here -->
								</div>
								
								<div id="content-pagination" class="voxfor-ml-pagination">
									<!-- Pagination will be loaded here -->
								</div>
							</div>
						</div>
						
						<!-- Translation Settings -->
						<div id="individual-translation-settings" class="voxfor-ml-translation-settings" style="display: none;">
							<h3><?php esc_html_e( 'Translation Settings', 'voxfor-multilanguage' ); ?></h3>
							
							<div class="voxfor-ml-settings-grid">
								<div class="voxfor-ml-language-selection">
									<h4><?php esc_html_e( 'Target Languages', 'voxfor-multilanguage' ); ?></h4>
									<div class="voxfor-ml-language-grid">
										<?php
										$enabled_languages = get_option( 'voxfor_ml_languages', array( 'en' ) );
										// Ensure $enabled_languages is always an array
										if ( ! is_array( $enabled_languages ) ) {
											$enabled_languages = array( 'en' );
										}
										$language_names    = array(
											'en' => array( 'English', 'English' ),
											'fr' => array( 'French', 'Français' ),
											'de' => array( 'German', 'Deutsch' ),
											'es' => array( 'Spanish', 'Español' ),
											'it' => array( 'Italian', 'Italiano' ),
											'pt' => array( 'Portuguese', 'Português' ),
											'ru' => array( 'Russian', 'Русский' ),
											'ja' => array( 'Japanese', '日本語' ),
											'ko' => array( 'Korean', '한국어' ),
											'zh' => array( 'Chinese', '中文' ),
											'he' => array( 'Hebrew', 'עברית' ),
										);

										foreach ( $enabled_languages as $lang_code ) {
											if ( $lang_code === 'en' ) {
												continue;
											}
											$lang_name   = isset( $language_names[ $lang_code ] ) ? $language_names[ $lang_code ][0] : $lang_code;
											$native_name = isset( $language_names[ $lang_code ] ) ? $language_names[ $lang_code ][1] : $lang_code;
											?>
											<label class="voxfor-ml-language-option">
												<input type="checkbox" name="individual_languages[]" class="individual-language-checkbox" value="<?php echo esc_attr( $lang_code ); ?>" checked>
												<span class="voxfor-ml-language-info">
													<strong><?php echo esc_html( $lang_name ); ?></strong>
													<small><?php echo esc_html( $native_name ); ?></small>
												</span>
											</label>
											<?php
										}
										?>
									</div>
								</div>
								
								<div class="voxfor-ml-scope-selection">
									<h4><?php esc_html_e( 'Translation Scope', 'voxfor-multilanguage' ); ?></h4>
									<div class="voxfor-ml-scope-options">
										<label>
											<input type="radio" name="individual_translation_scope" value="full" checked>
											<strong><?php esc_html_e( 'Full Content', 'voxfor-multilanguage' ); ?></strong>
											<small><?php esc_html_e( 'Translate title, content, excerpt, and essential meta data for this specific item only', 'voxfor-multilanguage' ); ?></small>
										</label>
									</div>
								</div>
							</div>
							
							<div class="voxfor-ml-cost-estimate">
								<h4><?php esc_html_e( 'Cost Estimate', 'voxfor-multilanguage' ); ?></h4>
								<div id="cost-estimate-display">
									<button type="button" id="calculate-cost-btn" class="button button-secondary">
										<?php esc_html_e( 'Calculate Cost', 'voxfor-multilanguage' ); ?>
									</button>
								</div>
							</div>
						</div>
						
						<!-- Action Buttons -->
						<div id="individual-translation-actions" class="voxfor-ml-translation-controls" style="display: none;">
							<button type="button" class="button button-primary button-hero" id="start-individual-translate-btn">
								<span class="dashicons dashicons-translation"></span>
								<?php esc_html_e( 'Translate Selected Content', 'voxfor-multilanguage' ); ?>
							</button>
							
							<button type="button" class="button button-secondary" id="cancel-individual-translate-btn" style="display: none;">
								<span class="dashicons dashicons-no"></span>
								<?php esc_html_e( 'Cancel Translation', 'voxfor-multilanguage' ); ?>
							</button>
						</div>
						
						<!-- Progress Section -->
						<div id="individual-translation-progress" class="voxfor-ml-progress-container" style="display: none;">
							<div class="voxfor-ml-progress-header">
								<h3><?php esc_html_e( 'Translation Progress', 'voxfor-multilanguage' ); ?></h3>
								<div class="voxfor-ml-progress-stats">
									<span id="individual-progress-current-item"></span>
								</div>
							</div>
							
							<div class="voxfor-ml-progress-bar-container">
								<div id="individual-progress-bar" class="voxfor-ml-progress-bar" style="width: 0%;"></div>
								<div class="voxfor-ml-progress-percentage">
									<span id="individual-progress-percentage">0%</span>
								</div>
							</div>
							
							<div class="voxfor-ml-progress-status">
								<p id="individual-progress-text"><?php esc_html_e( 'Initializing...', 'voxfor-multilanguage' ); ?></p>
							</div>
							
						</div>
						
						<div id="individual-result" class="voxfor-ml-result-container"></div>
					</div>
				</div>
				
				<!-- URL Slug Generation -->
				<?php if ( get_option( 'voxfor_ml_translate_slugs', false ) ) : ?>
				<div class="voxfor-ml-translate-section">
					<div class="voxfor-ml-translate-header">
						<h2><?php esc_html_e( 'Website URL Localization', 'voxfor-multilanguage' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Localize ALL website URLs by creating translated slugs for every page, post, and product. This creates SEO-friendly localized URLs in each language.', 'voxfor-multilanguage' ); ?></p>
					</div>
					
					<div class="voxfor-ml-translate-content">
						<div class="voxfor-ml-slug-generation">
							<p><?php esc_html_e( 'This will scan ALL posts, pages, and products on your website and automatically generate localized URL slugs for each language. If translations don\'t exist, they will be created automatically.', 'voxfor-multilanguage' ); ?></p>
							
							<div class="voxfor-ml-action-buttons">
								<button type="button" id="voxfor-ml-generate-slugs" class="button button-primary">
									<span class="dashicons dashicons-admin-links"></span>
									<?php esc_html_e( 'Localize All Website URLs', 'voxfor-multilanguage' ); ?>
								</button>
								<span id="voxfor-ml-slug-status" style="margin-left: 15px;"></span>
							</div>
							
							<div id="voxfor-ml-slug-progress" style="display: none; margin-top: 15px;">
								<div class="voxfor-ml-progress-bar-container">
									<div id="voxfor-ml-slug-progress-bar" class="voxfor-ml-progress-bar" style="width: 0%;"></div>
									<div class="voxfor-ml-progress-percentage">
										<span id="voxfor-ml-slug-progress-text">0%</span>
									</div>
								</div>
								<p id="voxfor-ml-slug-details" class="description"></p>
							</div>
						</div>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>
		
		<?php
		// Slug Manager JavaScript is now in public/js/admin/slug-manager.js
		// Properly enqueued via wp_enqueue_script with localization
		// Comprehensive translator styles are now in public/css/admin/comprehensive-translator.css
		// Comprehensive translator JavaScript is now in public/js/admin/comprehensive-translator.js
		// Both are properly enqueued via wp_enqueue_style/wp_enqueue_script with localization
	}

	/**
	 * AJAX: Get available content types
	 */
	public function ajaxGetContentTypes() {
		check_ajax_referer( 'voxfor_ml_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'voxfor-multilanguage' ) );
		}

		try {
			require_once VOXFOR_ML_PLUGIN_DIR . 'includes/Utils/IndividualContentScanner.php';
			$scanner = new \VoxforML\Utils\IndividualContentScanner();

			$content_types = $scanner->getAvailableContentTypes();

			wp_send_json_success( $content_types );

		} catch ( Exception $e ) {
			wp_send_json_error( __( 'Failed to get content types: ', 'voxfor-multilanguage' ) . $e->getMessage() );
		}
	}

	/**
	 * AJAX: Get content list
	 */
	public function ajaxGetContentList() {
		check_ajax_referer( 'voxfor_ml_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'voxfor-multilanguage' ) );
		}

		$content_type = isset( $_POST['content_type'] ) ? sanitize_text_field( wp_unslash( $_POST['content_type'] ) ) : '';
		$page         = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
		$per_page     = isset( $_POST['per_page'] ) ? intval( $_POST['per_page'] ) : 20;
		$search       = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$filters      = isset( $_POST['filters'] ) && is_array( $_POST['filters'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['filters'] ) ) : array();

		if ( empty( $content_type ) ) {
			wp_send_json_error( __( 'Content type is required', 'voxfor-multilanguage' ) );
		}

		try {
			require_once VOXFOR_ML_PLUGIN_DIR . 'includes/Utils/IndividualContentScanner.php';
			$scanner = new \VoxforML\Utils\IndividualContentScanner();

			$content_list = $scanner->getContentList( $content_type, $page, $per_page, $search, $filters );

			wp_send_json_success( $content_list );

		} catch ( Exception $e ) {
			wp_send_json_error( __( 'Failed to get content list: ', 'voxfor-multilanguage' ) . $e->getMessage() );
		}
	}

	/**
	 * AJAX: Get filter options
	 */
	public function ajaxGetFilterOptions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'voxfor-multilanguage' ) );
		}

		check_ajax_referer( 'voxfor_ml_ajax', 'nonce' );

		$content_type = isset( $_POST['content_type'] ) ? sanitize_text_field( wp_unslash( $_POST['content_type'] ) ) : '';

		if ( empty( $content_type ) ) {
			wp_send_json_error( __( 'Content type is required', 'voxfor-multilanguage' ) );
		}

		try {
			require_once VOXFOR_ML_PLUGIN_DIR . 'includes/Utils/IndividualContentScanner.php';
			$scanner = new \VoxforML\Utils\IndividualContentScanner();

			$filter_options = $scanner->getFilterOptions( $content_type );

			wp_send_json_success( array( 'filters' => $filter_options ) );

		} catch ( Exception $e ) {
			wp_send_json_error( __( 'Failed to get filter options: ', 'voxfor-multilanguage' ) . $e->getMessage() );
		}
	}

	/**
	 * AJAX: Translate individual content
	 */
	public function ajaxTranslateIndividualContent() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'voxfor-multilanguage' ) );
		}

		check_ajax_referer( 'voxfor_ml_ajax', 'nonce' );

		$content_ids       = isset( $_POST['content_ids'] ) ? array_map( 'intval', $_POST['content_ids'] ) : array();
		$languages         = isset( $_POST['languages'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['languages'] ) ) : array();
		$translation_scope = isset( $_POST['translation_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['translation_scope'] ) ) : 'full';
		$batch_index       = isset( $_POST['batch_index'] ) ? intval( $_POST['batch_index'] ) : 0;
		$batch_size        = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 5;

		if ( empty( $content_ids ) || empty( $languages ) ) {
			wp_send_json_error( __( 'Content IDs and languages are required', 'voxfor-multilanguage' ) );
		}

		try {
			require_once VOXFOR_ML_PLUGIN_DIR . 'includes/Admin/IndividualTranslationHandler.php';
			$handler = new \VoxforML\Admin\IndividualTranslationHandler();

			// Validate selection
			$validation = $handler->validateContentSelection( $content_ids, $languages, $translation_scope );
			if ( ! $validation['valid'] ) {
				wp_send_json_error(
					array(
						'message' => __( 'Validation failed', 'voxfor-multilanguage' ),
						'errors'  => $validation['errors'],
					)
				);
			}

			// Process batch
			$batch_start       = $batch_index * $batch_size;
			$batch_content_ids = array_slice( $content_ids, $batch_start, $batch_size );

			if ( empty( $batch_content_ids ) ) {
				wp_send_json_success(
					array(
						'completed'        => true,
						'message'          => __( 'All content translated successfully', 'voxfor-multilanguage' ),
						'refresh_status'   => true, // Signal to refresh translation status
						'translated_posts' => $content_ids, // All translated post IDs
					)
				);
			}

			// 🚀 Use new comprehensive translation method
			$results = $this->translateContentUsingComprehensiveMethod( $handler, $batch_content_ids, $languages );

			wp_send_json_success(
				array(
					'completed'        => false,
					'batch_index'      => $batch_index,
					'batch_results'    => $results,
					'next_batch_index' => $batch_index + 1,
					'total_batches'    => ceil( count( $content_ids ) / $batch_size ),
					'translated_posts' => $batch_content_ids, // Include the translated post IDs
				)
			);

		} catch ( Exception $e ) {
			wp_send_json_error( __( 'Translation failed: ', 'voxfor-multilanguage' ) . $e->getMessage() );
		}
	}

	/**
	 * AJAX: Cancel individual translation
	 */
	public function ajaxCancelIndividualTranslation() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'voxfor-multilanguage' ) );
		}

		check_ajax_referer( 'voxfor_ml_ajax', 'nonce' );

		// Set cancellation flag
		set_transient( 'voxfor_ml_cancel_individual_translation', true, 300 ); // 5 minutes

		wp_send_json_success(
			array(
				'message' => __( 'Individual translation cancelled successfully', 'voxfor-multilanguage' ),
			)
		);
	}

	/**
	 * AJAX: Estimate translation cost
	 */
	public function ajaxEstimateTranslationCost() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'voxfor-multilanguage' ) );
		}

		check_ajax_referer( 'voxfor_ml_ajax', 'nonce' );

		$content_ids       = isset( $_POST['content_ids'] ) ? array_map( 'intval', $_POST['content_ids'] ) : array();
		$languages         = isset( $_POST['languages'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['languages'] ) ) : array();
		$translation_scope = isset( $_POST['translation_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['translation_scope'] ) ) : 'full';

		if ( empty( $content_ids ) || empty( $languages ) ) {
			wp_send_json_error( __( 'Content IDs and languages are required', 'voxfor-multilanguage' ) );
		}

		try {
			require_once VOXFOR_ML_PLUGIN_DIR . 'includes/Admin/IndividualTranslationHandler.php';
			$handler = new \VoxforML\Admin\IndividualTranslationHandler();

			$cost_estimate = $handler->estimateTranslationCost( $content_ids, $languages, $translation_scope );

			wp_send_json_success( $cost_estimate );

		} catch ( Exception $e ) {
			wp_send_json_error( __( 'Failed to estimate cost: ', 'voxfor-multilanguage' ) . $e->getMessage() );
		}
	}

	/**
	 * AJAX: Get individual content translation progress
	 */
	public function ajaxGetIndividualTranslationProgress() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'voxfor-multilanguage' ) );
		}

		check_ajax_referer( 'voxfor_ml_ajax', 'nonce' );

		$content_ids = isset( $_POST['content_ids'] ) ? array_map( 'intval', $_POST['content_ids'] ) : array();
		$languages   = isset( $_POST['languages'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['languages'] ) ) : array();

		if ( empty( $content_ids ) || empty( $languages ) ) {
			wp_send_json_error( __( 'Content IDs and languages are required', 'voxfor-multilanguage' ) );
		}

		try {
			require_once VOXFOR_ML_PLUGIN_DIR . 'includes/Admin/IndividualTranslationHandler.php';
			$handler = new \VoxforML\Admin\IndividualTranslationHandler();

			$progress = $handler->getTranslationProgress( $content_ids, $languages );

			wp_send_json_success( $progress );

		} catch ( Exception $e ) {
			wp_send_json_error( __( 'Failed to get progress: ', 'voxfor-multilanguage' ) . $e->getMessage() );
		}
	}

	/**
	 * 🚀 NEW METHOD: Translate content using ComprehensiveTranslator approach
	 */
	private function translateContentUsingComprehensiveMethod( $handler, $content_ids, $languages ) {
		$results = array(
			'total_items'     => count( $content_ids ),
			'completed'       => 0,
			'failed'          => 0,
			'errors'          => array(),
			'processed_items' => array(),
		);

		foreach ( $content_ids as $content_id ) {
			$post = get_post( $content_id );

			if ( ! $post ) {
				++$results['failed'];
				$results['errors'][] = sprintf( /* translators: %d is the post ID */ __( 'Post %d not found', 'voxfor-multilanguage' ), $content_id );
				continue;
			}

			$item_result = array(
				'success'      => true,
				'content_id'   => $content_id,
				'title'        => $post->post_title,
				'post_type'    => $post->post_type,
				'errors'       => array(),
				'translations' => array(),
			);

			// Translate to each language using comprehensive method
			foreach ( $languages as $language ) {
				if ( $language === 'en' ) {
					continue;
				}

				$translation_result                       = $handler->translateUsingComprehensiveMethod( $content_id, $language );
				$item_result['translations'][ $language ] = $translation_result;

				if ( ! $translation_result['success'] ) {
					$item_result['success'] = false;
					$item_result['errors']  = array_merge( $item_result['errors'], $translation_result['errors'] );
				}
			}

			if ( $item_result['success'] ) {
				++$results['completed'];
			} else {
				++$results['failed'];
				$results['errors'] = array_merge( $results['errors'], $item_result['errors'] );
			}

			$results['processed_items'][] = $item_result;
			
			// Clear translation status cache for this post to force refresh
			$this->clearTranslationStatusCache( $content_id );
		}

		return $results;
	}

	/**
	 * Clear translation status cache for a specific post
	 */
	private function clearTranslationStatusCache( $post_id ) {
		// Clear WordPress object cache for this specific post
		wp_cache_delete( "voxfor_ml_translation_status_{$post_id}", 'voxfor_ml' );
		
		// Clear any transients related to this post's translation status
		delete_transient( "voxfor_ml_post_status_{$post_id}" );
		
		// Clear translation memory cache for this post
		$memory = $this->plugin->getComponent( 'translation_memory' );
		if ( $memory && method_exists( $memory, 'clearPostCache' ) ) {
			$memory->clearPostCache( $post_id );
		}
	}

	/**
	 * Handle cache clearing
	 */
	public function handleClearCache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'voxfor-multilanguage' ) );
		}

		// Verify nonce
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'voxfor_ml_clear_cache' ) ) {
			wp_die( esc_html__( 'Security check failed', 'voxfor-multilanguage' ) );
		}

		// Clear WordPress object cache
		wp_cache_flush();

		// Clear translation-specific object cache groups
		wp_cache_flush_group( 'voxfor_ml_translations' );

		// Clear transients
		global $wpdb;
		$deleted_transients = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_voxfor_ml_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted_timeouts   = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_voxfor_ml_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		// Clear translation memory cache if it exists
		$cache_table = $wpdb->prefix . 'voxfor_ml_cache';
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $cache_table ) ) === $cache_table ) {
			$wpdb->query( $wpdb->prepare( "TRUNCATE TABLE `%1s`", $cache_table ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
		}

		// Clear URL cache
		delete_transient( 'voxfor_ml_language_urls' );
		delete_transient( 'voxfor_ml_theme_urls' );

		// Clear specific object cache keys
		wp_cache_delete( 'voxfor_ml_translations', 'voxfor_ml' );
		wp_cache_delete( 'voxfor_ml_urls', 'voxfor_ml' );

		// Clear any cached translation lookups
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_voxfor_ml_trans_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_voxfor_ml_trans_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		// Redirect back with success message
		$redirect_url = add_query_arg(
			array(
				'page'          => 'voxfor-multilanguage',
				'cache_cleared' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle clean excluded URLs action
	 */
	public function handleCleanExcludedUrls() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'voxfor-multilanguage' ) );
		}

		// Verify nonce
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'voxfor_ml_clean_excluded_urls' ) ) {
			wp_die( esc_html__( 'Security check failed', 'voxfor-multilanguage' ) );
		}

		// Clean translations for excluded URLs
		$deleted_count = $this->cleanExcludedUrlTranslations();

		// Redirect back with success message
		$redirect_url = add_query_arg(
			array(
				'page'                 => 'voxfor-ml-exclusions',
				'cleaned_translations' => $deleted_count,
			),
			admin_url( 'admin.php' )
		);

		wp_redirect( $redirect_url );
		exit;
	}

	/**
	 * AJAX: Get content items for selected context type
	 */
	public function ajaxGetContentItems() {
		check_ajax_referer( 'voxfor_ml_ajax', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$context_type = isset( $_POST['context_type'] ) ? sanitize_text_field( wp_unslash( $_POST['context_type'] ) ) : '';
		$items        = array();

		switch ( $context_type ) {
			case 'page':
				$pages   = get_pages( array( 'post_status' => 'publish' ) );
				$items[] = array(
					'id'    => 'all',
					'title' => __( 'All Pages', 'voxfor-multilanguage' ),
				);
				foreach ( $pages as $page ) {
					$items[] = array(
						'id'    => $page->ID,
						'title' => $page->post_title,
					);
				}
				break;

			case 'post':
				$posts   = get_posts(
					array(
						'post_status' => 'publish',
						'numberposts' => -1,
					)
				);
				$items[] = array(
					'id'    => 'all',
					'title' => __( 'All Posts', 'voxfor-multilanguage' ),
				);
				foreach ( $posts as $post ) {
					$items[] = array(
						'id'    => $post->ID,
						'title' => $post->post_title,
					);
				}
				break;

			case 'product':
				if ( class_exists( 'WooCommerce' ) ) {
					$products = get_posts(
						array(
							'post_type'   => 'product',
							'post_status' => 'publish',
							'numberposts' => -1,
						)
					);
					$items[]  = array(
						'id'    => 'all',
						'title' => __( 'All Products', 'voxfor-multilanguage' ),
					);
					foreach ( $products as $product ) {
						$items[] = array(
							'id'    => $product->ID,
							'title' => $product->post_title,
						);
					}
				} else {
					$items[] = array(
						'id'    => 'none',
						'title' => __( 'WooCommerce not active', 'voxfor-multilanguage' ),
					);
				}
				break;

			case 'header':
				$items[] = array(
					'id'    => 'all',
					'title' => __( 'All Headers', 'voxfor-multilanguage' ),
				);

				// Get actual post_ids that contain header content
				$memory     = new \VoxforML\Database\TranslationMemory();
				$header_ids = $memory->getHeaderFooterPostIds( 'header' );

				foreach ( $header_ids as $post_id ) {
					$post    = get_post( $post_id );
					$title   = $post ? $post->post_title : sprintf( /* translators: %d is the post ID */ __( 'Post ID %d', 'voxfor-multilanguage' ), $post_id );
					$items[] = array(
						'id'    => $post_id,
						'title' => sprintf( /* translators: %s is the post title */ __( 'Header from: %s', 'voxfor-multilanguage' ), $title ),
					);
				}

				if ( empty( $header_ids ) ) {
					$items[] = array(
						'id'    => 'none',
						'title' => __( 'No header content found', 'voxfor-multilanguage' ),
					);
				}
				break;

			case 'footer':
				$items[] = array(
					'id'    => 'all',
					'title' => __( 'All Footers', 'voxfor-multilanguage' ),
				);

				// Get actual post_ids that contain footer content
				$memory     = new \VoxforML\Database\TranslationMemory();
				$footer_ids = $memory->getHeaderFooterPostIds( 'footer' );

				foreach ( $footer_ids as $post_id ) {
					$post    = get_post( $post_id );
					$title   = $post ? $post->post_title : sprintf( /* translators: %d is the post ID */ __( 'Post ID %d', 'voxfor-multilanguage' ), $post_id );
					$items[] = array(
						'id'    => $post_id,
						'title' => sprintf( /* translators: %s is the post title */ __( 'Footer from: %s', 'voxfor-multilanguage' ), $title ),
					);
				}

				if ( empty( $footer_ids ) ) {
					$items[] = array(
						'id'    => 'none',
						'title' => __( 'No footer content found', 'voxfor-multilanguage' ),
					);
				}
				break;

			case 'elementor':
				if ( class_exists( 'Elementor\Plugin' ) ) {
					$templates = get_posts(
						array(
							'post_type'   => 'elementor_library',
							'post_status' => 'publish',
							'numberposts' => -1,
						)
					);
					$items[]   = array(
						'id'    => 'all',
						'title' => __( 'All Elementor Templates', 'voxfor-multilanguage' ),
					);
					foreach ( $templates as $template ) {
						$items[] = array(
							'id'    => $template->ID,
							'title' => $template->post_title,
						);
					}
				} else {
					$items[] = array(
						'id'    => 'none',
						'title' => __( 'Elementor not active', 'voxfor-multilanguage' ),
					);
				}
				break;

			default:
				$items[] = array(
					'id'    => 'all',
					'title' => __( 'All Items', 'voxfor-multilanguage' ),
				);
				break;
				}

		wp_send_json_success( array( 'items' => $items ) );
	}

/**
 * AJAX handler to get context items for translation memory filters
 */
	public function ajaxGetContextItems() {
		check_ajax_referer( 'voxfor_ml_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'voxfor-multilanguage' ) );
		}

		$context = isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : '';
		$items   = array();

	switch ( $context ) {
		case 'page':
			$pages = get_pages( array( 'post_status' => 'publish' ) );
			foreach ( $pages as $page ) {
				$items[] = array(
					'id'    => $page->ID,
					'title' => $page->post_title,
				);
			}
			break;

		case 'post':
			$posts = get_posts(
				array(
					'post_status' => 'publish',
					'numberposts' => 100,
				)
			);
			foreach ( $posts as $post ) {
				$items[] = array(
					'id'    => $post->ID,
					'title' => $post->post_title,
				);
			}
			break;

		case 'product':
			if ( class_exists( 'WooCommerce' ) ) {
				$products = get_posts(
					array(
						'post_type'   => 'product',
						'post_status' => 'publish',
						'numberposts' => 100,
					)
				);
				foreach ( $products as $product ) {
					$items[] = array(
						'id'    => $product->ID,
						'title' => $product->post_title,
					);
				}
			}
			break;

		case 'header':
		case 'footer':
			// Get posts that have header/footer translations
			global $wpdb;
			$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translations';
			$post_ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM `%1s` WHERE context = %s AND post_id IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table_name,
					$context
				)
			);
			foreach ( $post_ids as $post_id ) {
				$post = get_post( $post_id );
				if ( $post ) {
					$items[] = array(
						'id'    => $post_id,
						'title' => $post->post_title,
					);
				}
			}
			break;

		case 'elementor':
			if ( class_exists( 'Elementor\Plugin' ) ) {
				$templates = get_posts(
					array(
						'post_type'   => 'elementor_library',
						'post_status' => 'publish',
						'numberposts' => 100,
					)
				);
				foreach ( $templates as $template ) {
					$items[] = array(
						'id'    => $template->ID,
						'title' => $template->post_title,
					);
				}
			}
			break;
	}

	wp_send_json_success( array( 'items' => $items ) );
}

/**
 * Get available languages for admin dropdown
 *
 * @return array
 */
public function getAvailableLanguagesForAdmin() {
		return array(
			'en' => array(
				'name'   => 'English',
				'native' => 'English',
				'flag'   => '🇺🇸',
				'rtl'    => false,
			),
			'es' => array(
				'name'   => 'Spanish',
				'native' => 'Español',
				'flag'   => '🇪🇸',
				'rtl'    => false,
			),
			'fr' => array(
				'name'   => 'French',
				'native' => 'Français',
				'flag'   => '🇫🇷',
				'rtl'    => false,
			),
			'de' => array(
				'name'   => 'German',
				'native' => 'Deutsch',
				'flag'   => '🇩🇪',
				'rtl'    => false,
			),
			'it' => array(
				'name'   => 'Italian',
				'native' => 'Italiano',
				'flag'   => '🇮🇹',
				'rtl'    => false,
			),
			'pt' => array(
				'name'   => 'Portuguese',
				'native' => 'Português',
				'flag'   => '🇵🇹',
				'rtl'    => false,
			),
			'ru' => array(
				'name'   => 'Russian',
				'native' => 'Русский',
				'flag'   => '🇷🇺',
				'rtl'    => false,
			),
			'ja' => array(
				'name'   => 'Japanese',
				'native' => '日本語',
				'flag'   => '🇯🇵',
				'rtl'    => false,
			),
			'zh' => array(
				'name'   => 'Chinese',
				'native' => '中文',
				'flag'   => '🇨🇳',
				'rtl'    => false,
			),
			'ko' => array(
				'name'   => 'Korean',
				'native' => '한국어',
				'flag'   => '🇰🇷',
				'rtl'    => false,
			),
			'ar' => array(
				'name'   => 'Arabic',
				'native' => 'العربية',
				'flag'   => '🇸🇦',
				'rtl'    => true,
			),
			'he' => array(
				'name'   => 'Hebrew',
				'native' => 'עברית',
				'flag'   => '🇮🇱',
				'rtl'    => true,
			),
			'sv' => array(
				'name'   => 'Swedish',
				'native' => 'Svenska',
				'flag'   => '🇸🇪',
				'rtl'    => false,
			),
			'no' => array(
				'name'   => 'Norwegian',
				'native' => 'Norsk',
				'flag'   => '🇳🇴',
				'rtl'    => false,
			),
			'da' => array(
				'name'   => 'Danish',
				'native' => 'Dansk',
				'flag'   => '🇩🇰',
				'rtl'    => false,
			),
			'fi' => array(
				'name'   => 'Finnish',
				'native' => 'Suomi',
				'flag'   => '🇫🇮',
				'rtl'    => false,
			),
			'nl' => array(
				'name'   => 'Dutch',
				'native' => 'Nederlands',
				'flag'   => '🇳🇱',
				'rtl'    => false,
			),
			'pl' => array(
				'name'   => 'Polish',
				'native' => 'Polski',
				'flag'   => '🇵🇱',
				'rtl'    => false,
			),
			'tr' => array(
				'name'   => 'Turkish',
				'native' => 'Türkçe',
				'flag'   => '🇹🇷',
				'rtl'    => false,
			),
		'cs' => array(
			'name'   => 'Czech',
			'native' => 'Čeština',
			'flag'   => '🇨🇿',
			'rtl'    => false,
		),
		'sk' => array(
			'name'   => 'Slovak',
			'native' => 'Slovenčina',
			'flag'   => '🇸🇰',
			'rtl'    => false,
		),
		'sl' => array(
			'name'   => 'Slovenian',
			'native' => 'Slovenščina',
			'flag'   => '🇸🇮',
			'rtl'    => false,
		),
		'hu' => array(
			'name'   => 'Hungarian',
			'native' => 'Magyar',
			'flag'   => '🇭🇺',
			'rtl'    => false,
		),
		'ro' => array(
			'name'   => 'Romanian',
			'native' => 'Română',
			'flag'   => '🇷🇴',
			'rtl'    => false,
		),
		'bg' => array(
			'name'   => 'Bulgarian',
			'native' => 'Български',
			'flag'   => '🇧🇬',
			'rtl'    => false,
		),
		'el' => array(
			'name'   => 'Greek',
			'native' => 'Ελληνικά',
			'flag'   => '🇬🇷',
			'rtl'    => false,
		),
		'et' => array(
			'name'   => 'Estonian',
			'native' => 'Eesti',
			'flag'   => '🇪🇪',
			'rtl'    => false,
		),
		'lv' => array(
			'name'   => 'Latvian',
			'native' => 'Latviešu',
			'flag'   => '🇱🇻',
			'rtl'    => false,
		),
		'lt' => array(
			'name'   => 'Lithuanian',
			'native' => 'Lietuvių',
			'flag'   => '🇱🇹',
			'rtl'    => false,
		),
			'th' => array(
				'name'   => 'Thai',
				'native' => 'ไทย',
				'flag'   => '🇹🇭',
				'rtl'    => false,
			),
			'vi' => array(
				'name'   => 'Vietnamese',
				'native' => 'Tiếng Việt',
				'flag'   => '🇻🇳',
				'rtl'    => false,
			),
		'id' => array(
			'name'   => 'Indonesian',
			'native' => 'Bahasa Indonesia',
			'flag'   => '🇮🇩',
			'rtl'    => false,
		),
		'uk' => array(
			'name'   => 'Ukrainian',
			'native' => 'Українська',
			'flag'   => '🇺🇦',
			'rtl'    => false,
		),
		);
	}
}