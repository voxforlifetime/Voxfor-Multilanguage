<?php
namespace VoxforML\Frontend;

use VoxforML\Core\Plugin;

/**
 * Manages frontend functionality including language switcher
 */
class FrontendManager {
	private $plugin;
	private $processed_urls = array(); // Cache for processed URLs

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin = Plugin::getInstance();

		// Register widgets
		add_action( 'widgets_init', array( $this, 'registerWidgets' ) );

		// Register shortcodes
		add_shortcode( 'voxfor_language_switcher', array( $this, 'languageSwitcherShortcode' ) );

		// Register Gutenberg blocks
		add_action( 'init', array( $this, 'registerBlocks' ) );

		// Enqueue frontend assets
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueFrontendAssets' ) );

		// Add body class
		add_filter( 'body_class', array( $this, 'addBodyClass' ) );

		// Add language attributes for RTL support
		add_filter( 'language_attributes', array( $this, 'addLanguageAttributes' ) );

		// URL filters temporarily disabled to prevent infinite loop
		// add_filter('home_url', [$this, 'addLanguageToUrl'], 10, 2);
		// add_filter('site_url', [$this, 'addLanguageToUrl'], 10, 2);
		// add_filter('bloginfo_url', [$this, 'addLanguageToUrl'], 10, 2);

		// URL filters temporarily disabled to prevent infinite loops

		// Translate more content
		add_filter( 'wp_nav_menu_items', array( $this, 'translateMenuItems' ), 10, 2 );
		add_filter( 'widget_text', array( $this, 'translateWidgetText' ), 10, 2 );
		add_filter( 'widget_title', array( $this, 'translateWidgetTitle' ), 10, 3 );
		add_filter( 'get_comment_text', array( $this, 'translateCommentText' ), 10, 2 );
		add_filter( 'comment_form_defaults', array( $this, 'translateCommentForm' ) );
		add_filter( 'get_bloginfo', array( $this, 'translateBlogInfo' ), 10, 2 );
		
		// Remove conflicting document_title_parts filters first
		add_action( 'init', function() {
			// Remove other translation system filters that might conflict
			remove_filter( 'document_title_parts', array( $this->plugin->getComponent( 'translation_memory' ), 'filterDocumentTitle' ), 10 );
			remove_filter( 'document_title_parts', array( $this->plugin->getComponent( 'seo' ), 'translateDocumentTitle' ), 20 );
		}, 999 );
		
		// Translate document title for browser tabs (priority 999 to run AFTER all other translation systems)
		add_filter( 'document_title_parts', array( $this, 'translateDocumentTitle' ), 999 );
		
		// Try multiple hooks to catch title generation
		add_filter( 'wp_title', array( $this, 'translateWpTitle' ), 5 );
		add_filter( 'pre_get_document_title', array( $this, 'translatePreDocumentTitle' ), 5 );
		
		// Add JavaScript backup to translate title directly
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueDocumentTitleTranslation' ), 1 );
	}

	/**
	 * Initialize frontend
	 */
	public function init() {
		// Add floating language switcher if enabled
		if ( get_option( 'voxfor_ml_floating_switcher', false ) ) {
			add_action( 'wp_footer', array( $this, 'renderFloatingSwitcher' ) );
		}

		// Add visual editor if user has permission
		if ( get_option( 'voxfor_ml_enable_visual_editor', true ) &&
			isset( $_GET['voxfor_ml_edit'] ) && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			current_user_can( 'edit_posts' ) ) {
			add_action( 'wp_footer', array( $this, 'renderVisualEditor' ) );
		}
	}

	/**
	 * Register widgets
	 */
	public function registerWidgets() {
		register_widget( 'VoxforML\Frontend\LanguageSwitcherWidget' );
	}

	/**
	 * Register Gutenberg blocks
	 */
	public function registerBlocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Register language switcher block
		register_block_type(
			'voxfor-ml/language-switcher',
			array(
				'render_callback' => array( $this, 'renderLanguageSwitcherBlock' ),
				'attributes'      => array(
					'style'           => array(
						'type'    => 'string',
						'default' => 'dropdown',
					),
					'showFlags'       => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showNativeNames' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// Enqueue block editor assets
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueueBlockEditorAssets' ) );
	}

	/**
	 * Enqueue frontend assets
	 */
	public function enqueueFrontendAssets() {
		// Main frontend CSS
		wp_enqueue_style(
			'voxfor-ml-frontend',
			VOXFOR_ML_PLUGIN_URL . 'public/css/frontend.css',
			array(),
			VOXFOR_ML_VERSION
		);

		// Main frontend JS
		wp_enqueue_script(
			'voxfor-ml-frontend',
			VOXFOR_ML_PLUGIN_URL . 'public/js/frontend.js',
			array( 'jquery' ),
			VOXFOR_ML_VERSION,
			true
		);

		// Header/Footer auto-translate JS
		wp_enqueue_script(
			'voxfor-ml-header-footer',
			VOXFOR_ML_PLUGIN_URL . 'public/js/header-footer-auto-translate.js',
			array( 'jquery' ),
			VOXFOR_ML_VERSION,
			true
		);

		// Prepare available languages with cosmetic display language
		$available_langs = $this->plugin->getAvailableLanguages();
		if ( ( $key = array_search( 'en', $available_langs ) ) !== false ) {
			$display_prefix          = get_option( 'voxfor_ml_display_prefix', 'en' );
			$available_langs[ $key ] = $display_prefix;
		}

		// Localize script for both scripts
		$localization_data = array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'ajax_url'       => admin_url( 'admin-ajax.php' ), // Alternative name for compatibility
			'restUrl'        => rest_url( 'voxfor-ml/v1/' ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'currentLang'    => ( $this->plugin->getCurrentLanguage() === 'en' ) ? get_option( 'voxfor_ml_display_prefix', 'en' ) : $this->plugin->getCurrentLanguage(),
			'availableLangs' => $available_langs,
			'cookieName'     => VOXFOR_ML_COOKIE_NAME,
			'isRTL'          => in_array( $this->plugin->getCurrentLanguage(), array( 'ar', 'he' ) ),
			'strings'        => array(
				'switchLanguage' => __( 'Switch Language', 'voxfor-multilanguage' ),
				'loading'        => __( 'Loading...', 'voxfor-multilanguage' ),
				'error'          => __( 'Error loading translation', 'voxfor-multilanguage' ),
			),
		);

		wp_localize_script( 'voxfor-ml-frontend', 'voxforML', $localization_data );
		wp_localize_script( 'voxfor-ml-header-footer', 'voxfor_ml_ajax', $localization_data );
	}

	/**
	 * Enqueue block editor assets
	 */
	public function enqueueBlockEditorAssets() {
		wp_enqueue_script(
			'voxfor-ml-blocks',
			VOXFOR_ML_PLUGIN_URL . 'public/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n' ),
			VOXFOR_ML_VERSION,
			true
		);

		// blocks.css removed as it doesn't exist
		// wp_enqueue_style(
		//     'voxfor-ml-blocks',
		//     VOXFOR_ML_PLUGIN_URL . 'public/css/blocks.css',
		//     array( 'wp-edit-blocks' ),
		//     VOXFOR_ML_VERSION
		// );
	}

	/**
	 * Enqueue document title translation script
	 * Uses wp_add_inline_script() to properly inject JavaScript for title translation
	 */
	public function enqueueDocumentTitleTranslation() {
		$router = $this->plugin->getComponent( 'router' );
		$memory = $this->plugin->getComponent( 'translation_memory' );
		
		if ( ! $router || ! $memory ) {
			return;
		}
		
		$current_language = $router->getCurrentLanguage();
		
		if ( $current_language === 'en' ) {
			return;
		}
		
		// Get ORIGINAL English title from database (not the translated one)
		$post_id       = get_queried_object_id();
		$current_title = '';
		
		if ( $post_id ) {
			// Get the original post title directly from database
			global $wpdb;
			$current_title = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching 
				"SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", 
				$post_id 
			) );
		}
		
		// Fallback methods if needed
		if ( ! $current_title ) {
			$current_title = get_post_field( 'post_title', $post_id, 'raw' );
		}
		
		if ( ! $current_title ) {
			return;
		}
		
		$translated_title = $memory->getTranslation( $current_title, $current_language, 'title' );
		
		if ( ! $translated_title ) {
			$translated_title = $memory->getTranslation( $current_title, $current_language, 'post_title' );
		}
		if ( ! $translated_title ) {
			$translated_title = $memory->getTranslation( $current_title, $current_language, 'content' );
		}
		
		if ( ! $translated_title ) {
			return;
		}
		
		// Prepare the inline script
		$inline_script = sprintf(
			"document.addEventListener('DOMContentLoaded', function() {
				var currentTitle = document.title;
				var translatedText = %s;
				var tempDiv = document.createElement('div');
				tempDiv.innerHTML = translatedText;
				var decodedText = tempDiv.textContent || tempDiv.innerText || '';
				var newTitle = currentTitle.replace(%s, decodedText);
				document.title = newTitle;
			});",
			wp_json_encode( $translated_title ),
			wp_json_encode( $current_title )
		);
		
		// Add inline script to the frontend script handle
		wp_add_inline_script( 'voxfor-ml-frontend', $inline_script );
	}

	/**
	 * Add body class
	 */
	public function addBodyClass( $classes ) {
		$current_lang = $this->plugin->getCurrentLanguage();

		$classes[] = 'voxfor-ml-lang-' . $current_lang;

		if ( in_array( $current_lang, array( 'ar', 'he' ) ) ) {
			$classes[] = 'voxfor-ml-rtl';
		}

		if ( isset( $_GET['voxfor_ml_edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$classes[] = 'voxfor-ml-editing';
		}

		return $classes;
	}

	/**
	 * Add language attributes for RTL support
	 */
	public function addLanguageAttributes( $output ) {
		$current_lang = $this->plugin->getCurrentLanguage();

		// Use display language for HTML lang attribute (cosmetic)
		$display_lang = ( $current_lang === 'en' ) ? get_option( 'voxfor_ml_display_prefix', 'en' ) : $current_lang;

		// Add language code
		$output = str_replace( 'lang="en-US"', 'lang="' . $display_lang . '"', $output );

		// Add RTL direction for Hebrew and Arabic
		if ( in_array( $current_lang, array( 'ar', 'he' ) ) ) {
			$output .= ' dir="rtl"';
		} else {
			$output .= ' dir="ltr"';
		}

		return $output;
	}

	/**
	 * Language switcher shortcode
	 */
	public function languageSwitcherShortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'style'             => get_option( 'voxfor_ml_widget_style', 'dropdown' ),
				'show_flags'        => get_option( 'voxfor_ml_show_flags', true ),
				'show_native_names' => get_option( 'voxfor_ml_show_native_names', true ),
				'class'             => '',
			),
			$atts
		);

		return $this->renderLanguageSwitcher( $atts );
	}

	/**
	 * Render language switcher block
	 */
	public function renderLanguageSwitcherBlock( $attributes ) {
		return $this->renderLanguageSwitcher(
			array(
				'style'             => $attributes['style'],
				'show_flags'        => $attributes['showFlags'],
				'show_native_names' => $attributes['showNativeNames'],
				'class'             => 'wp-block-voxfor-ml-language-switcher',
			)
		);
	}

	/**
	 * Render language switcher
	 */
	public function renderLanguageSwitcher( $args = array() ) {
		$defaults = array(
			'style'             => 'dropdown',
			'show_flags'        => true,
			'show_native_names' => true,
			'class'             => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$router        = $this->plugin->getComponent( 'router' );
		$current_lang  = $router->getCurrentLanguage();
		$language_urls = $router->getLanguageUrls();
		$languages     = $this->getLanguageData();

		ob_start();

		$template_file = VOXFOR_ML_PLUGIN_DIR . 'templates/frontend/language-switcher-' . $args['style'] . '.php';

		if ( ! file_exists( $template_file ) ) {
			$template_file = VOXFOR_ML_PLUGIN_DIR . 'templates/frontend/language-switcher-dropdown.php';
		}

		include $template_file;

		return ob_get_clean();
	}

	/**
	 * Render floating switcher
	 */
	public function renderFloatingSwitcher() {
		$position = get_option( 'voxfor_ml_floating_position', 'bottom-right' );

		echo '<div class="voxfor-ml-floating-switcher voxfor-ml-position-' . esc_attr( $position ) . '">';
		echo wp_kses_post( $this->renderLanguageSwitcher(
			array(
				'style' => 'compact',
				'class' => 'voxfor-ml-floating',
			)
		) );
		echo '</div>';
	}

	/**
	 * Render visual editor
	 */
	public function renderVisualEditor() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		include VOXFOR_ML_PLUGIN_DIR . 'templates/frontend/visual-editor.php';
	}

	/**
	 * Get language data
	 */
	private function getLanguageData() {
		// Get custom display values with proper fallbacks
		$custom_label   = get_option( 'voxfor_ml_display_label', 'English' );
		$custom_flag    = get_option( 'voxfor_ml_display_flag', '🇺🇸' );
		$actual_default = get_option( 'voxfor_ml_default_language', 'en' );

		// Ensure we have a valid default language
		if ( empty( $actual_default ) ) {
			$actual_default = 'en'; // Force fallback to 'en'
		}

		$display_prefix = get_option( 'voxfor_ml_display_prefix', 'en' );

		$languages = array(
			'en' => array(
				'name'   => 'English',
				'native' => 'English',
				'flag'   => '🇬🇧',
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
			'es' => array(
				'name'   => 'Spanish',
				'native' => 'Español',
				'flag'   => '🇪🇸',
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

		// Apply custom values to the actual default language
		if ( isset( $languages[ $actual_default ] ) ) {
			$languages[ $actual_default ]['name']   = $custom_label;
			$languages[ $actual_default ]['native'] = $custom_label;
			$languages[ $actual_default ]['flag']   = $custom_flag;
		}

		return $languages;
	}

	/**
	 * Add current language to URLs
	 */
	public function addLanguageToUrl( $url, $path = '' ) {
		// Check cache first
		$cache_key = md5( $url . '|' . $path );
		if ( isset( $this->processed_urls[ $cache_key ] ) ) {
			return $this->processed_urls[ $cache_key ];
		}

		// Skip AJAX requests only (not admin - we need URL filters to work for logged-in users)
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			$this->processed_urls[ $cache_key ] = $url;
			return $url;
		}

		// Skip admin-related URLs
		if ( strpos( $url, '/wp-admin/' ) !== false || strpos( $url, '/wp-includes/' ) !== false || strpos( $url, '/wp-json' ) !== false ) {
			$this->processed_urls[ $cache_key ] = $url;
			return $url;
		}

		// Get current language directly from URL or cookie (fallback when plugin not ready)
		$current_language = $this->getCurrentLanguageDirect();

		// Skip if no language detected or default language
		if ( ! $current_language || $current_language === 'en' ) {
			$this->processed_urls[ $cache_key ] = $url;
			return $url;
		}

		// Parse URL
		$parsed_url = wp_parse_url( $url );
		$site_url   = wp_parse_url( site_url() );

		// Only modify URLs from the same site
		if ( isset( $parsed_url['host'] ) && $parsed_url['host'] !== $site_url['host'] ) {
			$this->processed_urls[ $cache_key ] = $url;
			return $url;
		}

		// Add language prefix if not already present
		$path_with_lang = '/' . $current_language . '/';
		$current_path   = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/';

		// Skip if path already has language prefix
		if ( strpos( $current_path, $path_with_lang ) !== false ) {
			$this->processed_urls[ $cache_key ] = $url;
			return $url;
		}

		// Add language prefix to path
		if ( $current_path === '/' || $current_path === '' ) {
			$parsed_url['path'] = $path_with_lang;
		} else {
			$parsed_url['path'] = $path_with_lang . ltrim( $current_path, '/' );
		}

		// Rebuild URL without triggering filters again
		$new_url = $this->buildUrlSafe( $parsed_url );

		// Cache the result
		$this->processed_urls[ $cache_key ] = $new_url;

		// Limit cache size to prevent memory issues
		if ( count( $this->processed_urls ) > 1000 ) {
			// Remove oldest entries
			$this->processed_urls = array_slice( $this->processed_urls, -500, null, true );
		}

		return $new_url;
	}

	/**
	 * Get current language directly from URL or cookie (fallback when plugin not ready)
	 */
	private function getCurrentLanguageDirect() {
		// First try to get from URL path
		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( preg_match( '#^/([a-z]{2})/#', $request_uri, $matches ) ) {
			$lang = $matches[1];
			// Check if it's a valid language code
			if ( in_array( $lang, array( 'ko', 'ar', 'he', 'de', 'fr', 'es', 'it', 'pt', 'ru', 'ja', 'zh' ) ) ) {
				return $lang;
			}
		}

		// Fallback to cookie
		if ( isset( $_COOKIE['voxfor_ml_lang'] ) ) {
			$lang = sanitize_text_field( wp_unslash( $_COOKIE['voxfor_ml_lang'] ) );
			if ( in_array( $lang, array( 'ko', 'ar', 'he', 'de', 'fr', 'es', 'it', 'pt', 'ru', 'ja', 'zh' ) ) ) {
				return $lang;
			}
		}

		return null;
	}

	/**
	 * Build URL safely without triggering filters
	 */
	private function buildUrlSafe( $parsed_url ) {
		$url = '';

		if ( isset( $parsed_url['scheme'] ) ) {
			$url .= $parsed_url['scheme'] . '://';
		}

		if ( isset( $parsed_url['host'] ) ) {
			$url .= $parsed_url['host'];
		}

		if ( isset( $parsed_url['port'] ) ) {
			$url .= ':' . $parsed_url['port'];
		}

		if ( isset( $parsed_url['path'] ) ) {
			$url .= $parsed_url['path'];
		}

		if ( isset( $parsed_url['query'] ) ) {
			$url .= '?' . $parsed_url['query'];
		}

		if ( isset( $parsed_url['fragment'] ) ) {
			$url .= '#' . $parsed_url['fragment'];
		}

		return $url;
	}

	/**
	 * Translate menu items
	 */
	public function translateMenuItems( $items, $args ) {
		if ( is_admin() || ! $this->plugin ) {
			return $items;
		}

		$translator = $this->plugin->getComponent( 'translator' );
		$router     = $this->plugin->getComponent( 'router' );

		if ( ! $translator || ! $router ) {
			return $items;
		}

		$current_language = $this->plugin->getCurrentLanguage();
		$default_language = $this->plugin->getDefaultLanguage();

		if ( ! $current_language || $current_language === $default_language ) {
			return $items;
		}

		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( ! empty( $item->title ) ) {
					$translated = $translator->translateText( $item->title, $current_language, 'menu_item' );
					if ( $translated && $translated !== $item->title ) {
						$item->title = $translated;
					}
				}
			}
		}

		return $items;
	}

	/**
	 * Translate widget text
	 */
	public function translateWidgetText( $text, $instance ) {
		if ( is_admin() || empty( $text ) || ! $this->plugin ) {
			return $text;
		}

		$translator = $this->plugin->getComponent( 'translator' );
		$router     = $this->plugin->getComponent( 'router' );

		if ( ! $translator || ! $router ) {
			return $text;
		}

		// Translator component is already ready to use
		$current_language = $router->getCurrentLanguage();

		if ( ! $current_language || ! $translator ) {
			return $text;
		}

		$translated = $translator->translateText( $text, $current_language );
		return $translated ?: $text;
	}

	/**
	 * Translate widget title
	 */
	public function translateWidgetTitle( $title, $instance, $id_base ) {
		if ( is_admin() || empty( $title ) || ! $this->plugin ) {
			return $title;
		}

		$translator = $this->plugin->getComponent( 'translator' );
		$router     = $this->plugin->getComponent( 'router' );

		if ( ! $translator || ! $router ) {
			return $title;
		}

		// Translator component is already ready to use
		$current_language = $router->getCurrentLanguage();

		if ( ! $current_language || ! $translator ) {
			return $title;
		}

		$translated = $translator->translateText( $title, $current_language );
		return $translated ?: $title;
	}

	/**
	 * Translate comment text
	 */
	public function translateCommentText( $comment_text, $comment ) {
		if ( is_admin() || empty( $comment_text ) || ! $this->plugin ) {
			return $comment_text;
		}

		$translator = $this->plugin->getComponent( 'translator' );
		$router     = $this->plugin->getComponent( 'router' );

		if ( ! $translator || ! $router ) {
			return $comment_text;
		}

		// Translator component is already ready to use
		$current_language = $router->getCurrentLanguage();

		if ( ! $current_language || ! $translator ) {
			return $comment_text;
		}

		$translated = $translator->translateText( $comment_text, $current_language );
		return $translated ?: $comment_text;
	}

	/**
	 * Translate comment form
	 */
	public function translateCommentForm( $defaults ) {
		if ( is_admin() || ! $this->plugin ) {
			return $defaults;
		}

		// Safety check for plugin methods
		if ( ! $this->plugin->getComponent( 'translator' ) || ! $this->plugin->getComponent( 'router' ) ) {
			return $defaults;
		}

		$translator = $this->plugin->getComponent( 'translator' );
		$router     = $this->plugin->getComponent( 'router' );

		if ( ! $translator || ! $router ) {
			return $defaults;
		}

		// Translator component is already ready to use
		$current_language = $router->getCurrentLanguage();

		if ( ! $current_language || ! $translator ) {
			return $defaults;
		}

		// Translate common comment form fields
		$fields_to_translate = array(
			'title_reply'          => 'Leave a Reply',
			'title_reply_to'       => 'Leave a Reply to %s',
			'cancel_reply_link'    => 'Cancel reply',
			'label_submit'         => 'Post Comment',
			'comment_field'        => 'Comment',
			'comment_notes_before' => 'Your email address will not be published.',
			'comment_notes_after'  => '',
		);

		foreach ( $fields_to_translate as $field => $default_text ) {
			if ( isset( $defaults[ $field ] ) && ! empty( $defaults[ $field ] ) ) {
				$translated = $translator->translateText( $defaults[ $field ], $current_language );
				if ( $translated && $translated !== $defaults[ $field ] ) {
					$defaults[ $field ] = $translated;
				}
			}
		}

		return $defaults;
	}

	/**
	 * Translate blog info
	 */
	public function translateBlogInfo( $output, $show ) {

		if ( is_admin() || ! $this->plugin || empty( $output ) ) {

			return $output;
		}

		$translator = $this->plugin->getComponent( 'translator' );
		$router     = $this->plugin->getComponent( 'router' );

		if ( ! $translator || ! $router ) {
			return $output;
		}

		// Translator component is already ready to use
		$current_language = $router->getCurrentLanguage();

		if ( ! $current_language || ! $translator ) {
			return $output;
		}

		// Translate specific blog info fields
		$translatable_fields = array( 'name', 'description', 'wpurl', 'url' );

		if ( in_array( $show, $translatable_fields ) ) {
			$translated = $translator->translateText( $output, $current_language );
			return $translated ?: $output;
		}

		return $output;
	}

	/**
	 * Translate document title parts (for HTML <title> tag)
	 */
	public function translateDocumentTitle( $title_parts ) {
		if ( is_admin() || ! $this->plugin ) {
			return $title_parts;
		}

		$router = $this->plugin->getComponent( 'router' );
		$memory = $this->plugin->getComponent( 'translation_memory' );

		if ( ! $router || ! $memory ) {
			return $title_parts;
		}

		$current_language = $router->getCurrentLanguage();
		// Only translate for non-English languages
		if ( $current_language === 'en' ) {
			return $title_parts;
		}

		// Always get the original English title from database to ensure we have the source text
		$post_id = get_queried_object_id();
		$original_title = '';
		
		if ( $post_id ) {
			global $wpdb;
			$original_title = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching 
				"SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", 
				$post_id 
			) );
		}
		
		// If title_parts is empty or missing title, initialize it
		if ( empty( $title_parts ) || ! isset( $title_parts['title'] ) || empty( $title_parts['title'] ) ) {
			// Initialize title_parts if empty
			if ( empty( $title_parts ) ) {
				$title_parts = array();
			}
			
			// Set the title
			if ( $original_title ) {
				$title_parts['title'] = $original_title;
			}
			
			// Also set site name if missing
			if ( ! isset( $title_parts['site'] ) ) {
				$title_parts['site'] = get_bloginfo( 'name' );
			}
		} else {
			// Title parts exist, but check if the title has already been translated by another system
			$current_title = trim( $title_parts['title'] );
			// If the current title is already translated (contains Hebrew entities), use the original English title
			if ( strpos( $current_title, '&#' ) !== false && $original_title ) {
				$title_parts['title'] = $original_title;
			}
		}

		// Translate the main title (post/page title)
		if ( isset( $title_parts['title'] ) && ! empty( $title_parts['title'] ) ) {
			$original_title = trim( $title_parts['title'] );
			// Only translate if it's plain text (no HTML)
			if ( strpos( $original_title, '<' ) === false && strpos( $original_title, '>' ) === false && strlen( $original_title ) >= 3 ) {
				// Try multiple contexts to find translation
				$translated = $memory->getTranslation( $original_title, $current_language, 'title' );
				if ( ! $translated ) {
					$translated = $memory->getTranslation( $original_title, $current_language, 'post_title' );
				}
				if ( ! $translated ) {
					$translated = $memory->getTranslation( $original_title, $current_language, 'content' );
				}
				
				if ( $translated ) {
					// Decode HTML entities for proper display in HTML source
					$decoded_title = html_entity_decode( $translated, ENT_QUOTES, 'UTF-8' );
					$title_parts['title'] = $decoded_title;
				}
			}
		}

		// Translate the tagline/site description if present
		if ( isset( $title_parts['tagline'] ) && ! empty( $title_parts['tagline'] ) ) {
			$original_tagline = trim( $title_parts['tagline'] );
			if ( strpos( $original_tagline, '<' ) === false && strpos( $original_tagline, '>' ) === false && strlen( $original_tagline ) >= 3 ) {
				$translated_tagline = $memory->getTranslation( $original_tagline, $current_language, 'tagline' );
				if ( ! $translated_tagline ) {
					$translated_tagline = $memory->getTranslation( $original_tagline, $current_language, 'content' );
				}
				
				if ( $translated_tagline ) {
					$title_parts['tagline'] = $translated_tagline;
				}
			}
		}

		return $title_parts;
	}

	/**
	 * Translate wp_title (legacy hook)
	 */
	public function translateWpTitle( $title ) {
		if ( is_admin() || ! $this->plugin ) {
			return $title;
		}

		$router = $this->plugin->getComponent( 'router' );
		$memory = $this->plugin->getComponent( 'translation_memory' );

		if ( ! $router || ! $memory ) {
			return $title;
		}

		$current_language = $router->getCurrentLanguage();
		if ( $current_language === 'en' ) {
			return $title;
		}

		// Try to extract just the page title from the full title
		$title_parts = explode( ' - ', $title );
		if ( ! empty( $title_parts[0] ) ) {
			$page_title = trim( $title_parts[0] );
			$translated = $memory->getTranslation( $page_title, $current_language, 'title' );
			
			if ( $translated ) {
				$title_parts[0] = $translated;
				return implode( ' - ', $title_parts );
			}
		}

		return $title;
	}

	/**
	 * Translate pre document title
	 */
	public function translatePreDocumentTitle( $title ) {
		return $title; // Let it continue to document_title_parts
	}

	/**
	 * Build URL from parsed components
	 */
	private function buildUrl( $parsed_url ) {
		$url = '';

		if ( isset( $parsed_url['scheme'] ) ) {
			$url .= $parsed_url['scheme'] . '://';
		}

		if ( isset( $parsed_url['host'] ) ) {
			$url .= $parsed_url['host'];
		}

		if ( isset( $parsed_url['port'] ) ) {
			$url .= ':' . $parsed_url['port'];
		}

		if ( isset( $parsed_url['path'] ) ) {
			$url .= $parsed_url['path'];
		}

		if ( isset( $parsed_url['query'] ) ) {
			$url .= '?' . $parsed_url['query'];
		}

		if ( isset( $parsed_url['fragment'] ) ) {
			$url .= '#' . $parsed_url['fragment'];
		}

		return $url;
	}
}

/**
 * Language Switcher Widget
 */
class LanguageSwitcherWidget extends \WP_Widget {
	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct(
			'voxfor_ml_language_switcher',
			__( 'Voxfor Language Switcher', 'voxfor-multilanguage' ),
			array(
				'description'                 => __( 'Display a language switcher for your multilingual site', 'voxfor-multilanguage' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	/**
	 * Widget output
	 */
	public function widget( $args, $instance ) {
		$title             = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$style             = ! empty( $instance['style'] ) ? $instance['style'] : 'dropdown';
		$show_flags        = ! empty( $instance['show_flags'] ) ? $instance['show_flags'] : true;
		$show_native_names = ! empty( $instance['show_native_names'] ) ? $instance['show_native_names'] : true;

		echo wp_kses_post( $args['before_widget'] );

		if ( $title ) {
			echo wp_kses_post( $args['before_title'] ) . esc_html( apply_filters( 'widget_title', $title ) ) . wp_kses_post( $args['after_title'] );
		}

		$frontend = new FrontendManager();
		echo wp_kses_post( $frontend->renderLanguageSwitcher(
			array(
				'style'             => esc_attr( $style ),
				'show_flags'        => (bool) $show_flags,
				'show_native_names' => (bool) $show_native_names,
				'class'             => 'widget-language-switcher',
			)
		) );

		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Widget form
	 */
	public function form( $instance ) {
		$title             = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$style             = ! empty( $instance['style'] ) ? $instance['style'] : 'dropdown';
		$show_flags        = ! empty( $instance['show_flags'] ) ? $instance['show_flags'] : true;
		$show_native_names = ! empty( $instance['show_native_names'] ) ? $instance['show_native_names'] : true;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'voxfor-multilanguage' ); ?>
			</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" 
					name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" 
					type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>">
				<?php esc_html_e( 'Style:', 'voxfor-multilanguage' ); ?>
			</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>" 
					name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
				<option value="dropdown" <?php selected( $style, 'dropdown' ); ?>>
					<?php esc_html_e( 'Dropdown', 'voxfor-multilanguage' ); ?>
				</option>
				<option value="inline" <?php selected( $style, 'inline' ); ?>>
					<?php esc_html_e( 'Inline List', 'voxfor-multilanguage' ); ?>
				</option>
				<option value="flags" <?php selected( $style, 'flags' ); ?>>
					<?php esc_html_e( 'Flags Only', 'voxfor-multilanguage' ); ?>
				</option>
				<option value="compact" <?php selected( $style, 'compact' ); ?>>
					<?php esc_html_e( 'Compact', 'voxfor-multilanguage' ); ?>
				</option>
			</select>
		</p>
		<p>
			<input class="checkbox" type="checkbox" 
					id="<?php echo esc_attr( $this->get_field_id( 'show_flags' ) ); ?>" 
					name="<?php echo esc_attr( $this->get_field_name( 'show_flags' ) ); ?>" 
					<?php checked( $show_flags ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_flags' ) ); ?>">
				<?php esc_html_e( 'Show flags', 'voxfor-multilanguage' ); ?>
			</label>
		</p>
		<p>
			<input class="checkbox" type="checkbox" 
					id="<?php echo esc_attr( $this->get_field_id( 'show_native_names' ) ); ?>" 
					name="<?php echo esc_attr( $this->get_field_name( 'show_native_names' ) ); ?>" 
					<?php checked( $show_native_names ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_native_names' ) ); ?>">
				<?php esc_html_e( 'Show native language names', 'voxfor-multilanguage' ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Update widget
	 */
	public function update( $new_instance, $old_instance ) {
		$instance                      = array();
		$instance['title']             = ! empty( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';
		$instance['style']             = ! empty( $new_instance['style'] ) ? sanitize_text_field( $new_instance['style'] ) : 'dropdown';
		$instance['show_flags']        = ! empty( $new_instance['show_flags'] );
		$instance['show_native_names'] = ! empty( $new_instance['show_native_names'] );

		return $instance;
	}
}