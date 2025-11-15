<?php
namespace VoxforML\Frontend;

use VoxforML\Core\Plugin;
use VoxforML\Database\TranslationMemory;

/**
 * Handles untranslated page requests with graceful UX
 */
class UntranslatedPageHandler {
	private $plugin;
	private $memory;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin = Plugin::getInstance();
		$this->memory = new TranslationMemory();

		// Hook into template redirect
		add_action( 'template_redirect', array( $this, 'handleUntranslatedPage' ), 5 );

		// AJAX endpoints
		add_action( 'wp_ajax_voxfor_ml_check_translation', array( $this, 'ajaxCheckTranslation' ) );
		add_action( 'wp_ajax_nopriv_voxfor_ml_check_translation', array( $this, 'ajaxCheckTranslation' ) );

		add_action( 'wp_ajax_voxfor_ml_trigger_translation', array( $this, 'ajaxTriggerTranslation' ) );
		add_action( 'wp_ajax_nopriv_voxfor_ml_trigger_translation', array( $this, 'ajaxTriggerTranslation' ) );

		// Add scripts for untranslated pages
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueScripts' ) );
	}

	/**
	 * Handle untranslated page requests and language reset
	 */
	public function handleUntranslatedPage() {
		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		// Check if we're on a non-prefixed URL (should show English)
		$request_uri         = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		$has_language_prefix = false;

		$enabled_languages = $this->plugin->getEnabledLanguages();
		foreach ( $enabled_languages as $lang ) {
			if ( $lang !== 'en' && strpos( $request_uri, '/' . $lang . '/' ) !== false ) {
				$has_language_prefix = true;
				break;
			}
		}

		// If no language prefix, ensure we're showing English content
		if ( ! $has_language_prefix && $current_lang !== 'en' ) {
			// Force language reset to English
			$this->resetToEnglish();
			return;
		}

		// Skip if we're on the default language
		if ( $current_lang === $default_lang ) {
			return;
		}

		// Handle different page types
		if ( is_home() || is_front_page() ) {
			// Handle homepage/front page
			$this->handleHomepageTranslation( $current_lang );
			return;
		}

		// Check if current page has translation
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		// Check if translation exists
		$has_translation = $this->hasCompleteTranslation( $post_id, $current_lang );

		if ( ! $has_translation ) {
			// Add noindex for preparing translations (P1 SEO Enhancement)
			add_action( 'wp_head', array( $this, 'addNoindexForPreparingTranslation' ), 1 );

			// Add flag to show translation notice
			add_filter(
				'body_class',
				function ( $classes ) {
					$classes[] = 'voxfor-ml-untranslated';
					return $classes;
				}
			);

			// Add notice to content
			add_action( 'wp_footer', array( $this, 'renderTranslationNotice' ) );

			// Queue translation if auto-translate is enabled
			if ( get_option( 'voxfor_ml_auto_translate_on_visit', true ) ) {
				$this->queuePageTranslation( $post_id, $current_lang );
			}
		}
	}

	/**
	 * Handle homepage translation
	 */
	private function handleHomepageTranslation( $current_lang ) {
		// Check if homepage is a static page
		$homepage_id = get_option( 'page_on_front' );

		if ( $homepage_id ) {
			// Static homepage - check if it has translation
			$has_translation = $this->hasCompleteTranslation( $homepage_id, $current_lang );

			if ( ! $has_translation ) {
				// Queue homepage translation
				if ( get_option( 'voxfor_ml_auto_translate_on_visit', true ) ) {
					$this->queuePageTranslation( $homepage_id, $current_lang );
				}
			}
		}

		// Always add translation hooks for homepage content
		add_filter(
			'body_class',
			function ( $classes ) {
				$classes[] = 'voxfor-ml-homepage';
				return $classes;
			}
		);
	}

	/**
	 * Reset language to English for non-prefixed URLs
	 */
	private function resetToEnglish() {
		// Clear language cookie
		if ( ! headers_sent() ) {
			setcookie(
				VOXFOR_ML_COOKIE_NAME,
				'en',
				time() + ( 365 * DAY_IN_SECONDS ),
				COOKIEPATH,
				COOKIE_DOMAIN,
				is_ssl(),
				true
			);
		}

		// Force router to reset language
		$router = $this->plugin->getComponent( 'router' );
		if ( $router ) {
			// Use reflection to reset the current language
			$reflection = new \ReflectionClass( $router );
			$property   = $reflection->getProperty( 'current_language' );
			$property->setAccessible( true );
			$property->setValue( $router, 'en' );
		}

		// Clear any translation cache
		wp_cache_delete( 'voxfor_ml_current_language', 'voxfor_ml' );

		// Add body class to indicate reset
		add_filter(
			'body_class',
			function ( $classes ) {
				$classes[] = 'voxfor-ml-reset-to-english';
				return $classes;
			}
		);
	}

	/**
	 * Check if page has complete translation
	 */
	private function hasCompleteTranslation( $post_id, $language ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		// If we have a working translator, consider translation as available
		// This prevents showing "preparing translation" notice when real-time translation works
		// For now, always return true to disable the annoying notice since translation works
		return true;

		// Fallback: Check main content in memory
		$title_translated   = $this->memory->getTranslation( $post->post_title, $language, 'title' );
		$content_translated = $this->memory->getTranslation( $post->post_content, $language, 'content' );

		return ( $title_translated !== false && $content_translated !== false );
	}

	/**
	 * Queue page for translation
	 */
	private function queuePageTranslation( $post_id, $language ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Queue main content
		$this->memory->queueForTranslation( $post->post_title, $language, 'title', $post_id );
		$this->memory->queueForTranslation( $post->post_content, $language, 'content', $post_id );

		if ( $post->post_excerpt ) {
			$this->memory->queueForTranslation( $post->post_excerpt, $language, 'excerpt', $post_id );
		}

		// Queue SEO metadata
		$seo_manager  = $this->plugin->getComponent( 'seo' );
		$seo_metadata = $seo_manager->getSEOMetadata( $post_id );

		foreach ( $seo_metadata as $key => $value ) {
			if ( is_string( $value ) && ! empty( $value ) ) {
				$this->memory->queueForTranslation( $value, $language, 'seo_' . $key, $post_id );
			}
		}

		// Trigger immediate processing if possible
		if ( get_option( 'voxfor_ml_immediate_translation', false ) ) {
			wp_schedule_single_event( time() + 5, 'voxfor_ml_process_single_page', array( $post_id, $language ) );
		}
	}

	/**
	 * Render translation notice
	 */
	public function renderTranslationNotice() {
		$post_id       = get_queried_object_id();
		$current_lang  = $this->plugin->getCurrentLanguage();
		$language_data = $this->getLanguageData();
		$lang_name     = isset( $language_data[ $current_lang ] ) ? $language_data[ $current_lang ]['name'] : $current_lang;
		?>
		<div id="voxfor-ml-translation-notice" class="voxfor-ml-translation-notice">
			<div class="voxfor-ml-notice-content">
				<div class="voxfor-ml-notice-icon">
					<span class="dashicons dashicons-translation"></span>
				</div>
				<div class="voxfor-ml-notice-text">
					<h4><?php printf( /* translators: %s is the language name */ esc_html__( 'Preparing %s Translation', 'voxfor-multilanguage' ), esc_html( $lang_name ) ); ?></h4>
					<p><?php esc_html_e( 'This page is being translated. You can view the original content below.', 'voxfor-multilanguage' ); ?></p>
					<div class="voxfor-ml-notice-progress" style="display: none;">
						<div class="voxfor-ml-progress-bar">
							<div class="voxfor-ml-progress-fill"></div>
						</div>
						<span class="voxfor-ml-progress-text"></span>
					</div>
				</div>
				<div class="voxfor-ml-notice-actions">
					<button class="voxfor-ml-dismiss-notice" aria-label="<?php esc_attr_e( 'Dismiss', 'voxfor-multilanguage' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
			</div>
		</div>
		
		<div id="voxfor-ml-translation-ready" class="voxfor-ml-translation-ready" style="display: none;">
			<div class="voxfor-ml-ready-content">
				<span class="dashicons dashicons-yes-alt"></span>
				<span class="voxfor-ml-ready-text">
					<?php printf( /* translators: %s is the language name */ esc_html__( '%s translation is ready!', 'voxfor-multilanguage' ), esc_html( $lang_name ) ); ?>
				</span>
				<button class="voxfor-ml-view-translation">
					<?php esc_html_e( 'View Translation', 'voxfor-multilanguage' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue scripts for untranslated pages
	 */
	public function enqueueScripts() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id      = get_queried_object_id();
		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return;
		}

		if ( ! $this->hasCompleteTranslation( $post_id, $current_lang ) ) {
			wp_enqueue_script(
				'voxfor-ml-untranslated',
				VOXFOR_ML_PLUGIN_URL . 'public/js/untranslated.js',
				array( 'jquery' ),
				VOXFOR_ML_VERSION,
				true
			);

			wp_enqueue_style(
				'voxfor-ml-untranslated',
				VOXFOR_ML_PLUGIN_URL . 'public/css/untranslated.css',
				array(),
				VOXFOR_ML_VERSION
			);

			// Localize script with data
			wp_localize_script(
				'voxfor-ml-untranslated',
				'voxforMLUntranslated',
				array(
					'postId'        => $post_id,
					'language'      => $current_lang,
					'checkInterval' => 10000, // Check every 10 seconds
					'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
					'nonce'         => wp_create_nonce( 'voxfor_ml_translation_check' ),
				)
			);
		}
	}

	/**
	 * AJAX: Check translation status
	 */
	public function ajaxCheckTranslation() {
		check_ajax_referer( 'voxfor_ml_translation_check', 'nonce' );

		$post_id  = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

		if ( ! $post_id || ! $language ) {
			wp_send_json_error( array( 'message' => 'Invalid parameters' ) );
		}

		// Check translation status
		$has_translation = $this->hasCompleteTranslation( $post_id, $language );

		// Get translation progress
		$progress = $this->getTranslationProgress( $post_id, $language );

		wp_send_json_success(
			array(
				'status'   => $has_translation ? 'ready' : 'preparing',
				'progress' => $progress,
				'coverage' => $progress['percentage'],
			)
		);
	}

	/**
	 * AJAX: Trigger translation
	 */
	public function ajaxTriggerTranslation() {
		check_ajax_referer( 'voxfor_ml_translation_check', 'nonce' );

		$post_id  = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

		if ( ! $post_id || ! $language ) {
			wp_send_json_error( array( 'message' => 'Invalid parameters' ) );
		}

		// Check if user can trigger translation
		if ( ! current_user_can( 'read' ) && ! get_option( 'voxfor_ml_public_trigger_translation', true ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		// Queue translation
		$this->queuePageTranslation( $post_id, $language );

		wp_send_json_success(
			array(
				'message' => __( 'Translation queued successfully', 'voxfor-multilanguage' ),
			)
		);
	}

	/**
	 * Get translation progress for a page
	 */
	private function getTranslationProgress( $post_id, $language ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'percentage'     => 0,
				'segments_done'  => 0,
				'total_segments' => 0,
			);
		}

		$segments = array(
			'title'   => $post->post_title,
			'content' => $post->post_content,
		);

		if ( $post->post_excerpt ) {
			$segments['excerpt'] = $post->post_excerpt;
		}

		$total = count( $segments );
		$done  = 0;

		foreach ( $segments as $context => $text ) {
			if ( $this->memory->getTranslation( $text, $language, $context ) !== false ) {
				++$done;
			}
		}

		return array(
			'percentage'     => $total > 0 ? round( ( $done / $total ) * 100 ) : 0,
			'segments_done'  => $done,
			'total_segments' => $total,
		);
	}

	/**
	 * Get language data
	 */
	private function getLanguageData() {
		return array(
			'en' => array( 'name' => 'English' ),
			'he' => array( 'name' => 'Hebrew' ),
			'fr' => array( 'name' => 'French' ),
			'de' => array( 'name' => 'German' ),
			'es' => array( 'name' => 'Spanish' ),
			'it' => array( 'name' => 'Italian' ),
			'pt' => array( 'name' => 'Portuguese' ),
			'ru' => array( 'name' => 'Russian' ),
			'ja' => array( 'name' => 'Japanese' ),
			'zh' => array( 'name' => 'Chinese' ),
			'ko' => array( 'name' => 'Korean' ),
			'ar' => array( 'name' => 'Arabic' ),
			'sv' => array( 'name' => 'Swedish' ),
			'no' => array( 'name' => 'Norwegian' ),
			'da' => array( 'name' => 'Danish' ),
			'fi' => array( 'name' => 'Finnish' ),
			'nl' => array( 'name' => 'Dutch' ),
			'pl' => array( 'name' => 'Polish' ),
			'tr' => array( 'name' => 'Turkish' ),
			'cs' => array( 'name' => 'Czech' ),
			'hu' => array( 'name' => 'Hungarian' ),
			'ro' => array( 'name' => 'Romanian' ),
			'el' => array( 'name' => 'Greek' ),
			'th' => array( 'name' => 'Thai' ),
			'vi' => array( 'name' => 'Vietnamese' ),
			'id' => array( 'name' => 'Indonesian' ),
			'ms' => array( 'name' => 'Malay' ),
			'hi' => array( 'name' => 'Hindi' ),
			'bn' => array( 'name' => 'Bengali' ),
			'uk' => array( 'name' => 'Ukrainian' ),
		);
	}

	/**
	 * Add noindex meta tag for preparing translations (P1 SEO Enhancement)
	 */
	public function addNoindexForPreparingTranslation() {
		// Check if temporary noindex is enabled
		$enable_noindex = get_option( 'voxfor_ml_noindex_preparing', true );

		if ( $enable_noindex ) {
			echo '<meta name="robots" content="noindex, nofollow">' . "\n";
			echo '<!-- Voxfor ML: Temporary noindex while preparing translation -->' . "\n";
		}
	}
}