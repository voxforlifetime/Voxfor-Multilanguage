<?php
namespace VoxforML\Core;

use VoxforML\Core\Plugin;
use VoxforML\Database\TranslationMemory;
use VoxforML\Translator\DeepLTranslator;
use VoxforML\Utils\TextProcessor;

/**
 * Comprehensive translation system that ensures complete page translation
 * on first language switch, with priority for header/footer elements
 */
class ComprehensiveTranslator {
	private $plugin;
	private $memory;
	private $translator;
	private $processor;
	private $translated_texts   = array();
	private static $translating = false;

	public function __construct() {
		$this->plugin     = Plugin::getInstance();
		$this->memory     = new TranslationMemory();
		$this->translator = new DeepLTranslator();
		$this->processor  = new TextProcessor();

		$this->initHooks();
	}

	private function initHooks() {
		// High priority hooks to catch all content
		add_action( 'wp_loaded', array( $this, 'initTranslationSystem' ), 1 );

		// Language switch detection
		add_action( 'wp_ajax_voxfor_ml_switch_language', array( $this, 'handleLanguageSwitch' ) );
		add_action( 'wp_ajax_nopriv_voxfor_ml_switch_language', array( $this, 'handleLanguageSwitch' ) );

		// Template redirect - trigger translation on first visit
		add_action( 'template_redirect', array( $this, 'triggerFirstVisitTranslation' ), 1 );

		// Comprehensive gettext filters with higher priority
		add_filter( 'gettext', array( $this, 'translateGettext' ), 5, 3 );
		add_filter( 'ngettext', array( $this, 'translateNgettext' ), 5, 5 );
		add_filter( 'gettext_with_context', array( $this, 'translateGettextWithContext' ), 5, 4 );
		add_filter( 'ngettext_with_context', array( $this, 'translateNgettextWithContext' ), 5, 6 );

		// Output buffer for complete page scanning
		add_action( 'wp_head', array( $this, 'startOutputBuffer' ), 1 );
		add_action( 'wp_footer', array( $this, 'processOutputBuffer' ), 999 );

		// AJAX endpoint for real-time translation
		add_action( 'wp_ajax_voxfor_ml_translate_page_content', array( $this, 'ajaxTranslatePageContent' ) );
		add_action( 'wp_ajax_nopriv_voxfor_ml_translate_page_content', array( $this, 'ajaxTranslatePageContent' ) );
	}

	/**
	 * Initialize translation system
	 */
	public function initTranslationSystem() {
		if ( is_admin() ) {
			return;
		}

		$current_language = $this->plugin->getCurrentLanguage();

		// Skip if English
		if ( $current_language === 'en' ) {
			return;
		}

		// Enqueue frontend translation script
		wp_enqueue_script(
			'voxfor-ml-comprehensive-translator',
			VOXFOR_ML_PLUGIN_URL . 'public/js/comprehensive-translator.js',
			array( 'jquery' ),
			VOXFOR_ML_VERSION,
			true
		);

		wp_localize_script(
			'voxfor-ml-comprehensive-translator',
			'voxforMLTranslator',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'voxfor_ml_translator' ),
				'currentLanguage' => $current_language,
				'isFirstVisit'    => ! isset( $_COOKIE[ 'voxfor_ml_visited_' . $current_language ] ),
			)
		);
	}

	/**
	 * Trigger translation on first visit to a language - SMART VERSION
	 */
	public function triggerFirstVisitTranslation() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$current_language = $this->plugin->getCurrentLanguage();

		// Skip if English
		if ( $current_language === 'en' ) {
			return;
		}

		// SMART FIX: Only trigger comprehensive translation if:
		// 1. It's truly the first visit AND
		// 2. There are no existing translations for this page AND
		// 3. Comprehensive translation is explicitly enabled

		$cookie_name           = 'voxfor_ml_visited_' . $current_language;
		$is_first_visit        = ! isset( $_COOKIE[ $cookie_name ] );
		$comprehensive_enabled = get_option( 'voxfor_ml_comprehensive_translation_enabled', false );

		// Check if page has existing translations
		$has_translations = $this->checkPageHasTranslations( $current_language );

		if ( $is_first_visit && ! $has_translations && $comprehensive_enabled ) {
			// Set cookie to mark as visited
			if ( ! headers_sent() ) {
				setcookie( $cookie_name, '1', time() + ( 30 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN );
			}

			// Trigger comprehensive translation only if needed
			add_action( 'wp_footer', array( $this, 'injectFirstVisitTranslationScript' ), 1 );
		} elseif ( $is_first_visit ) {
			// Just mark as visited without triggering translation
			if ( ! headers_sent() ) {
				setcookie( $cookie_name, '1', time() + ( 30 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN );
			}
		}
	}

	/**
	 * Check if current page has existing translations
	 */
	private function checkPageHasTranslations( $language ) {
		// Quick check for existing translations in memory
		global $post;

		if ( ! $post ) {
			return false;
		}

		// Check for common page elements translations
		$test_texts = array(
			get_the_title( $post->ID ),
			'Home',
			'About',
			'Contact',
			'Menu',
		);

		foreach ( $test_texts as $text ) {
			if ( ! empty( $text ) ) {
				$translation = $this->memory->getTranslation( $text, $language, 'page_content' );
				if ( $translation !== false ) {
					return true; // Found existing translations
				}
			}
		}

		return false; // No translations found
	}

	/**
	 * Inject script for first visit translation
	 */
	public function injectFirstVisitTranslationScript() {
		$current_language = $this->plugin->getCurrentLanguage();
		
		// Enqueue the comprehensive translator loading script
		wp_enqueue_script(
			'voxfor-ml-comprehensive-loading',
			VOXFOR_ML_PLUGIN_URL . 'public/js/frontend/comprehensive-translator-loading.js',
			array(),
			VOXFOR_ML_VERSION,
			true
		);

		// Localize the script with current language data
		wp_localize_script(
			'voxfor-ml-comprehensive-loading',
			'voxforComprehensiveLoading',
			array(
				'currentLanguage' => $current_language,
				// translators: %s is the target language name
				'loadingText'     => __( 'Translating page to %s...', 'voxfor-multilanguage' ),
			)
		);
	}

	/**
	 * Start output buffer to capture page content
	 */
	public function startOutputBuffer() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$current_language = $this->plugin->getCurrentLanguage();
		if ( $current_language !== 'en' ) {
			ob_start( array( $this, 'translatePageContent' ) );
		}
	}

	/**
	 * Process output buffer - not used in this implementation
	 */
	public function processOutputBuffer() {
		// This method is kept for compatibility but we use JavaScript-based translation
	}

	/**
	 * Translate page content in output buffer
	 */
	public function translatePageContent( $content ) {
		if ( self::$translating || is_admin() ) {
			return $content;
		}

		self::$translating = true;

		$current_language = $this->plugin->getCurrentLanguage();

		if ( $current_language === 'en' ) {
			self::$translating = false;
			return $content;
		}

		// Use DOMDocument for better HTML parsing
		$dom = new \DOMDocument();
		@$dom->loadHTML( '<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		$xpath = new \DOMXPath( $dom );

		// Find all text nodes that need translation
		$textNodes = $xpath->query( '//text()[normalize-space(.) != ""]' );

		foreach ( $textNodes as $textNode ) {
			$text = trim( $textNode->nodeValue );

			// Skip if empty, numeric, or already translated
			if ( empty( $text ) || is_numeric( $text ) || isset( $this->translated_texts[ $text ] ) ) {
				continue;
			}

			// Skip script and style content
			$parent = $textNode->parentNode;
			if ( $parent && in_array( strtolower( $parent->nodeName ), array( 'script', 'style', 'noscript' ) ) ) {
				continue;
			}

			// Translate the text
			$translated = $this->translateText( $text, $current_language, 'page_content' );

			if ( $translated && $translated !== $text ) {
				$textNode->nodeValue             = $translated;
				$this->translated_texts[ $text ] = $translated;
			}
		}

		// Return modified HTML
		$result = $dom->saveHTML();

		self::$translating = false;

		return $result;
	}

	/**
	 * Enhanced gettext translation
	 */
	public function translateGettext( $translation, $text, $domain ) {
		if ( is_admin() || self::$translating ) {
			return $translation;
		}

		// 🎯 SMART ACTIVATION: Only activate during translation operations
		if ( ! $this->shouldActivateComprehensiveTranslator() ) {
			return $translation;
		}

		$current_language = $this->plugin->getCurrentLanguage();

		if ( $current_language === 'en' ) {
			return $translation;
		}

		// Skip if already processed
		if ( isset( $this->translated_texts[ $text ] ) ) {
			return $this->translated_texts[ $text ];
		}

		// Translate the text
		$translated = $this->translateText( $text, $current_language, 'gettext' );

		if ( $translated && $translated !== $text ) {
			$this->translated_texts[ $text ] = $translated;
			return $translated;
		}

		return $translation;
	}

	/**
	 * Enhanced ngettext translation
	 */
	public function translateNgettext( $translation, $single, $plural, $number, $domain ) {
		if ( is_admin() || self::$translating ) {
			return $translation;
		}

		// 🎯 SMART ACTIVATION: Only activate during translation operations
		if ( ! $this->shouldActivateComprehensiveTranslator() ) {
			return $translation;
		}

		$current_language = $this->plugin->getCurrentLanguage();

		if ( $current_language === 'en' ) {
			return $translation;
		}

		$text_to_translate = ( $number == 1 ) ? $single : $plural;
		$translated        = $this->translateText( $text_to_translate, $current_language, 'ngettext' );

		return $translated ?: $translation;
	}

	/**
	 * Enhanced gettext with context translation
	 */
	public function translateGettextWithContext( $translation, $text, $context, $domain ) {
		if ( is_admin() || self::$translating ) {
			return $translation;
		}

		// 🎯 SMART ACTIVATION: Only activate during translation operations
		if ( ! $this->shouldActivateComprehensiveTranslator() ) {
			return $translation;
		}

		$current_language = $this->plugin->getCurrentLanguage();

		if ( $current_language === 'en' ) {
			return $translation;
		}

		$translated = $this->translateText( $text, $current_language, 'gettext_context' );

		return $translated ?: $translation;
	}

	/**
	 * Enhanced ngettext with context translation
	 */
	public function translateNgettextWithContext( $translation, $single, $plural, $number, $context, $domain ) {
		if ( is_admin() || self::$translating ) {
			return $translation;
		}

		// 🎯 SMART ACTIVATION: Only activate during translation operations
		if ( ! $this->shouldActivateComprehensiveTranslator() ) {
			return $translation;
		}

		$current_language = $this->plugin->getCurrentLanguage();

		if ( $current_language === 'en' ) {
			return $translation;
		}

		$text_to_translate = ( $number == 1 ) ? $single : $plural;
		$translated        = $this->translateText( $text_to_translate, $current_language, 'ngettext_context' );

		return $translated ?: $translation;
	}

	/**
	 * AJAX: Translate page content in real-time
	 */
	public function ajaxTranslatePageContent() {
		check_ajax_referer( 'voxfor_ml_translator', 'nonce' );

		// Check permissions - allow logged-in users to translate content they can view
		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'voxfor-multilanguage' ) ) );
		}

		$texts    = isset( $_POST['texts'] ) && is_array( $_POST['texts'] ) ? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['texts'] ) ) : array();
		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

		if ( empty( $texts ) || empty( $language ) || $language === 'en' ) {
			wp_send_json_error( array( 'message' => 'Invalid parameters' ) );
		}

		$translations     = array();
		$new_translations = array();

		// Check existing translations first
		foreach ( $texts as $text ) {
			$text = trim( $text );
			if ( empty( $text ) ) {
				continue;
			}

			// Try multiple contexts
			$contexts = array( 'page_content', 'gettext', 'content', 'general', 'text_fragment' );
			$found    = false;

			foreach ( $contexts as $context ) {
				$existing = $this->memory->getTranslation( $text, $language, $context );
				if ( $existing !== false ) {
					$translations[ $text ] = $existing;
					$found                 = true;
					break;
				}
			}

			if ( ! $found ) {
				$new_translations[] = $text;
			}
		}

		// Translate new texts in batches
		if ( ! empty( $new_translations ) ) {
			try {
				$batch_size = 20;
				$batches    = array_chunk( $new_translations, $batch_size );

				foreach ( $batches as $batch ) {
					$batch_translations = $this->translator->batchTranslate( $batch, $language, 'EN' );

					if ( is_array( $batch_translations ) ) {
						foreach ( $batch_translations as $index => $translation ) {
							if ( ! empty( $translation ) && $translation !== $batch[ $index ] ) {
								$original                  = $batch[ $index ];
								$translations[ $original ] = $translation;

								// Save in multiple contexts
								$this->memory->saveTranslation( $original, $translation, $language, 'page_content', null, 'deepl' );
								$this->memory->saveTranslation( $original, $translation, $language, 'gettext', null, 'deepl' );
								$this->memory->saveTranslation( $original, $translation, $language, 'text_fragment', null, 'deepl' );
							}
						}
					}
				}
			} catch ( \Exception $e ) {
			}
		}

		wp_send_json_success(
			array(
				'translations' => $translations,
				'total'        => count( $texts ),
				'found'        => count( $texts ) - count( $new_translations ),
				'new'          => count( $new_translations ),
			)
		);
	}

	/**
	 * Handle language switch - EMERGENCY FIX: Don't force re-translation
	 */
	public function handleLanguageSwitch() {
		check_ajax_referer( 'voxfor_ml_translator', 'nonce' );

		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

		if ( empty( $language ) ) {
			wp_send_json_error( 'Invalid language' );
		}

		// Set language cookie
		if ( ! headers_sent() ) {
			setcookie( 'voxfor_ml_language', $language, time() + ( 365 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN );
		}

		// EMERGENCY FIX: Don't delete visit cookie - preserve existing translations
		// This prevents automatic re-translation on every language switch
		// Only manual "Translate" button should trigger comprehensive translation

		wp_send_json_success( array( 'message' => 'Language switched to ' . $language . ' (using cached translations)' ) );
	}

	/**
	 * Translate text with caching and context
	 */
	private function translateText( $text, $language, $context = 'general' ) {
		if ( empty( $text ) || $language === 'en' ) {
			return $text;
		}

		// Check cache first
		$cached = $this->memory->getTranslation( $text, $language, $context );
		if ( $cached !== false ) {
			return $cached;
		}

		// 🎯 SMART BEHAVIOR: ComprehensiveTranslator is now only active during translation operations
		// So when it reaches this point, it's safe to proceed with translation logic

		// Skip very short or numeric texts
		if ( strlen( trim( $text ) ) < 2 || is_numeric( $text ) ) {
			return $text;
		}

		// Skip HTML tags and special characters only
		if ( preg_match( '/^[\s\W]*$/', $text ) ) {
			return $text;
		}

		// 🚨 ADDITIONAL SAFETY: Check if immediate translation is explicitly disabled
		if ( ! get_option( 'voxfor_ml_immediate_translation', false ) ) {
			// Immediate translation is disabled - don't make API calls
			return $text;
		}

		try {
			$translated = $this->translator->translate( $text, $language, 'EN' );

			if ( $translated && $translated !== $text ) {
				// Save translation
				$this->memory->saveTranslation( $text, $translated, $language, $context, null, 'deepl' );
				return $translated;
			}
		} catch ( \Exception $e ) {
		}

		return $text;
	}

	/**
	 * 🎯 SMART ACTIVATION: Determine when ComprehensiveTranslator should be active
	 */
	private function shouldActivateComprehensiveTranslator() {
		// 1. ALWAYS ACTIVE during admin translation operations
		if ( is_admin() && wp_doing_ajax() ) {
			// Check if this is a translation AJAX request
			$action = sanitize_text_field( wp_unslash( $_POST['action'] ?? $_GET['action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
			if ( in_array(
				$action,
				array(
					'voxfor_ml_translate_individual_content',
					'voxfor_ml_translate_bulk_content',
					'voxfor_ml_translate_complete_site',
				)
			) ) {
				return true;
			}
		}

		// 2. 🚀 CRITICAL: ACTIVE during individual translation simulation
		// This allows ComprehensiveTranslator to capture all text pieces individually
		if ( get_transient( 'voxfor_ml_individual_translation_active' ) ) {
			return true;
		}

		// 3. ACTIVE when immediate translation is enabled AND user is admin (for testing)
		if ( get_option( 'voxfor_ml_immediate_translation', false ) && current_user_can( 'manage_options' ) ) {
			return true;
		}

		// 4. ACTIVE during translation preview mode
		if ( isset( $_GET['voxfor_ml_preview'] ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		// 5. ACTIVE when specifically requested via URL parameter (for testing)
		if ( isset( $_GET['voxfor_ml_comprehensive'] ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		// 6. DEFAULT: INACTIVE for regular visitors browsing translated content
		// This prevents API consumption during language switching
		return false;
	}
}
