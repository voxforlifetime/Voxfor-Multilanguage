<?php
namespace VoxforML\SEO;

use VoxforML\Core\Plugin;

/**
 * Handles translation of SEO meta tags for various SEO plugins
 */
class MetaTranslator {
	private $plugin;
	private $translator;
	private $memory;
	private $router;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin     = Plugin::getInstance();
		$this->translator = $this->plugin->getComponent( 'translator' );
		$this->memory     = $this->plugin->getComponent( 'translation_memory' );
		$this->router     = $this->plugin->getComponent( 'router' );

		$this->initHooks();
	}

	/**
	 * Initialize hooks
	 */
	private function initHooks() {
		// Yoast SEO hooks
		add_filter( 'wpseo_title', array( $this, 'translateYoastTitle' ), 20 );
		add_filter( 'wpseo_metadesc', array( $this, 'translateYoastDescription' ), 20 );
		add_filter( 'wpseo_opengraph_title', array( $this, 'translateYoastOGTitle' ), 20 );
		add_filter( 'wpseo_opengraph_desc', array( $this, 'translateYoastOGDescription' ), 20 );
		add_filter( 'wpseo_twitter_title', array( $this, 'translateYoastTwitterTitle' ), 20 );
		add_filter( 'wpseo_twitter_description', array( $this, 'translateYoastTwitterDescription' ), 20 );

		// RankMath hooks
		add_filter( 'rank_math/frontend/title', array( $this, 'translateRankMathTitle' ), 20 );
		add_filter( 'rank_math/frontend/description', array( $this, 'translateRankMathDescription' ), 20 );
		add_filter( 'rank_math/opengraph/facebook/title', array( $this, 'translateRankMathOGTitle' ), 20 );
		add_filter( 'rank_math/opengraph/facebook/description', array( $this, 'translateRankMathOGDescription' ), 20 );
		add_filter( 'rank_math/opengraph/twitter/title', array( $this, 'translateRankMathTwitterTitle' ), 20 );
		add_filter( 'rank_math/opengraph/twitter/description', array( $this, 'translateRankMathTwitterDescription' ), 20 );

		// All in One SEO hooks
		add_filter( 'aioseo_title', array( $this, 'translateAIOSEOTitle' ), 20 );
		add_filter( 'aioseo_description', array( $this, 'translateAIOSEODescription' ), 20 );

		// SEOPress hooks
		add_filter( 'seopress_titles_title', array( $this, 'translateSEOPressTitle' ), 20 );
		add_filter( 'seopress_titles_desc', array( $this, 'translateSEOPressDescription' ), 20 );

		// Schema markup translation
		add_filter( 'wpseo_schema_graph', array( $this, 'translateYoastSchema' ), 20 );
		add_filter( 'rank_math/json_ld', array( $this, 'translateRankMathSchema' ), 20 );
		
		// WordPress core meta hooks (when no SEO plugin is active)
		add_filter( 'wp_title', array( $this, 'translateWPTitle' ), 20 );
		add_filter( 'document_title_parts', array( $this, 'translateDocumentTitle' ), 20 );
		add_action( 'wp_head', array( $this, 'outputTranslatedMetaDescription' ), 1 );
	}



	/**
	 * Translate combined title (e.g., "About - My Blog" from Yoast)
	 */
	private function translateCombinedTitle( $title, $context = 'seo_meta' ) {
		if ( empty( $title ) ) {
			return $title;
		}

		$current_lang = $this->router->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		// Don't translate if we're in the default language
		if ( $current_lang === $default_lang ) {
			return $title;
		}

		// Try to translate the full title first
		$full_translation = $this->translateIfNeeded( $title, $context );
		if ( $full_translation !== $title ) {
			return $full_translation;
		}

		// If no full translation, try to split and translate parts
		// Common separators: " - ", " | ", " – ", " — "
		$separators = array( ' - ', ' | ', ' – ', ' — ' );
		
		foreach ( $separators as $separator ) {
			if ( strpos( $title, $separator ) !== false ) {
				$parts = explode( $separator, $title, 2 );
				if ( count( $parts ) === 2 ) {
					$page_title = trim( $parts[0] );
					$site_name = trim( $parts[1] );
					
					// Translate the page title part
					$translated_page_title = $this->translateIfNeeded( $page_title, 'title' );
					
					if ( $translated_page_title !== $page_title ) {
						$result = $translated_page_title . $separator . $site_name;
						return $result;
					}
				}
				break;
			}
		}

		return $title;
	}

	/**
	 * Translate text if not in default language
	 */
	private function translateIfNeeded( $text, $context = 'seo_meta' ) {
		if ( empty( $text ) ) {
			return $text;
		}

		$current_lang = $this->router->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		// Don't translate if we're in the default language
		if ( $current_lang === $default_lang ) {
			return $text;
		}

		// First, check for pre-translated meta stored by our individual translation system
		$post_id = get_the_ID();
		if ( $post_id ) {
			$translated_meta = $this->getStoredTranslatedMeta( $post_id, $context, $current_lang );
			if ( $translated_meta ) {
				return $translated_meta;
			}
		}

		// Check if we have a template for this language
		$template = $this->getMetaTemplate( $context, $current_lang );
		if ( $template ) {
			return $this->processTemplate( $template, $text );
		}

		// Otherwise, check translation memory first (like our working solution)
		if ( $this->memory ) {
			// Try multiple contexts to find translation (same as our working solution)
			$translated = $this->memory->getTranslation( $text, $current_lang, 'title' );
			
			if ( ! $translated ) {
				$translated = $this->memory->getTranslation( $text, $current_lang, 'post_title' );
			}
			if ( ! $translated ) {
				$translated = $this->memory->getTranslation( $text, $current_lang, 'content' );
			}
			if ( ! $translated ) {
				$translated = $this->memory->getTranslation( $text, $current_lang, $context );
			}
			
					if ( $translated ) {
			// Decode HTML entities to proper UTF-8 characters for HTML source - DIRECT METHOD
			// Normalize encoded ampersands like &amp;# or &#038;#
			$normalized = preg_replace( '/&(?:amp|#0*38);#/i', '&#', $translated );
			$decoded = preg_replace_callback( '/&#(x?[0-9A-Fa-f]+);?/i', function( $matches ) {
				$code = intval( $matches[1] );
				if ( $code < 0x80 ) {
					return chr( $code );
				} elseif ( $code < 0x800 ) {
					return chr( 0xC0 | ( $code >> 6 ) ) . chr( 0x80 | ( $code & 0x3F ) );
				} elseif ( $code < 0x10000 ) {
					return chr( 0xE0 | ( $code >> 12 ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
				} else {
					return chr( 0xF0 | ( $code >> 18 ) ) . chr( 0x80 | ( ( $code >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
				}
			}, $translated );
			return $decoded;
		}
		}
		
		// Fallback to API translation if no memory translation found
		return $this->translator->translateText( $text, $current_lang, $context );
	}

	/**
	 * Get stored translated meta from our individual translation system
	 */
	private function getStoredTranslatedMeta( $post_id, $context, $language ) {
		// Map context to our stored meta field names
		$context_map = array(
			'yoast_title'       => '_yoast_wpseo_title_' . $language,
			'yoast_description' => '_yoast_wpseo_metadesc_' . $language,
			'rankmath_title'    => 'rank_math_title_' . $language,
			'rankmath_description' => 'rank_math_description_' . $language,
			'aioseo_title'      => '_aioseo_title_' . $language,
			'aioseo_description' => '_aioseo_description_' . $language,
		);
		
		// Check for SEO plugin specific translations
		if ( isset( $context_map[ $context ] ) ) {
			$translated_meta = get_post_meta( $post_id, $context_map[ $context ], true );
			if ( ! empty( $translated_meta ) ) {
				return $translated_meta;
			}
		}
		
		// Check for WordPress core meta translations
		if ( strpos( $context, 'title' ) !== false ) {
			$wp_title = get_post_meta( $post_id, 'wp_meta_title_' . $language, true );
			if ( ! empty( $wp_title ) ) {
				return $wp_title;
			}
		}
		
		if ( strpos( $context, 'description' ) !== false ) {
			$wp_description = get_post_meta( $post_id, 'wp_meta_description_' . $language, true );
			if ( ! empty( $wp_description ) ) {
				return $wp_description;
			}
		}
		
		return false;
	}

	/**
	 * Get meta template for a specific context and language
	 */
	private function getMetaTemplate( $context, $language ) {
		global $wpdb;

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return false;
		}

		// Check for post-specific template
		$template = get_post_meta( $post_id, '_voxfor_ml_' . $context . '_' . $language, true );

		if ( empty( $template ) ) {
			// Check for global template
			$template = get_option( 'voxfor_ml_template_' . $context . '_' . $language );
		}

		return $template;
	}

	/**
	 * Process template with variables
	 */
	private function processTemplate( $template, $original_text ) {
		// Replace common variables
		$replacements = array(
			'{{site_name}}'        => get_bloginfo( 'name' ),
			'{{site_description}}' => get_bloginfo( 'description' ),
			'{{current_year}}'     => gmdate( 'Y' ),
			'{{post_title}}'       => get_the_title(),
			'{{original}}'         => $original_text,
		);

		// Allow filtering of replacements
		$replacements = apply_filters( 'voxfor_ml_meta_template_vars', $replacements, $template );

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	// Yoast SEO translations
	public function translateYoastTitle( $title ) {
		// Handle Yoast's combined title format (e.g., "About - My Blog")
		$result = $this->translateCombinedTitle( $title, 'yoast_title' );
		
		// Note: HTML entities are now properly decoded in PHP, no JavaScript fallback needed
		
		return $result;
	}

	public function translateYoastDescription( $description ) {
		return $this->translateIfNeeded( $description, 'yoast_description' );
	}

	public function translateYoastOGTitle( $title ) {
		return $this->translateIfNeeded( $title, 'yoast_og_title' );
	}

	public function translateYoastOGDescription( $description ) {
		return $this->translateIfNeeded( $description, 'yoast_og_description' );
	}

	public function translateYoastTwitterTitle( $title ) {
		return $this->translateIfNeeded( $title, 'yoast_twitter_title' );
	}

	public function translateYoastTwitterDescription( $description ) {
		return $this->translateIfNeeded( $description, 'yoast_twitter_description' );
	}

	// RankMath translations
	public function translateRankMathTitle( $title ) {
		$result = $this->translateIfNeeded( $title, 'rankmath_title' );
		return $result;
	}

	public function translateRankMathDescription( $description ) {
		return $this->translateIfNeeded( $description, 'rankmath_description' );
	}

	public function translateRankMathOGTitle( $title ) {
		return $this->translateIfNeeded( $title, 'rankmath_og_title' );
	}

	public function translateRankMathOGDescription( $description ) {
		return $this->translateIfNeeded( $description, 'rankmath_og_description' );
	}

	public function translateRankMathTwitterTitle( $title ) {
		return $this->translateIfNeeded( $title, 'rankmath_twitter_title' );
	}

	public function translateRankMathTwitterDescription( $description ) {
		return $this->translateIfNeeded( $description, 'rankmath_twitter_description' );
	}

	// All in One SEO translations
	public function translateAIOSEOTitle( $title ) {
		return $this->translateIfNeeded( $title, 'aioseo_title' );
	}

	public function translateAIOSEODescription( $description ) {
		return $this->translateIfNeeded( $description, 'aioseo_description' );
	}

	// SEOPress translations
	public function translateSEOPressTitle( $title ) {
		return $this->translateIfNeeded( $title, 'seopress_title' );
	}

	public function translateSEOPressDescription( $description ) {
		return $this->translateIfNeeded( $description, 'seopress_description' );
	}

	/**
	 * Translate Yoast schema markup
	 */
	public function translateYoastSchema( $schema ) {
		$current_lang = $this->router->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $schema;
		}

		// Translate relevant schema properties
		foreach ( $schema as &$piece ) {
			if ( isset( $piece['name'] ) ) {
				$piece['name'] = $this->translateIfNeeded( $piece['name'], 'schema_name' );
			}
			if ( isset( $piece['description'] ) ) {
				$piece['description'] = $this->translateIfNeeded( $piece['description'], 'schema_description' );
			}
			if ( isset( $piece['headline'] ) ) {
				$piece['headline'] = $this->translateIfNeeded( $piece['headline'], 'schema_headline' );
			}

			// Add language info (use display language for SEO)
			$display_lang        = ( $current_lang === 'en' ) ? get_option( 'voxfor_ml_display_prefix', 'en' ) : $current_lang;
			$piece['inLanguage'] = $display_lang;
		}

		return $schema;
	}

	/**
	 * Translate RankMath schema markup
	 */
	public function translateRankMathSchema( $schema ) {
		return $this->translateYoastSchema( $schema );
	}

	/**
	 * Add meta template management UI
	 */
	public function addMetaBox() {
		add_meta_box(
			'voxfor_ml_seo_templates',
			__( 'SEO Translation Templates', 'voxfor-multilanguage' ),
			array( $this, 'renderMetaBox' ),
			array( 'post', 'page' ),
			'normal',
			'default'
		);
	}

	/**
	 * Render meta template box
	 */
	public function renderMetaBox( $post ) {
		wp_nonce_field( 'voxfor_ml_save_seo_templates', 'voxfor_ml_seo_nonce' );

		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();

		echo '<div class="voxfor-ml-seo-templates">';
		echo '<p>' . esc_html__( 'Define custom SEO templates for each language. Leave empty to use automatic translation.', 'voxfor-multilanguage' ) . '</p>';

		$contexts = array(
			'title'          => __( 'Page Title', 'voxfor-multilanguage' ),
			'description'    => __( 'Meta Description', 'voxfor-multilanguage' ),
			'og_title'       => __( 'Open Graph Title', 'voxfor-multilanguage' ),
			'og_description' => __( 'Open Graph Description', 'voxfor-multilanguage' ),
		);

		foreach ( $languages as $lang ) {
			if ( $lang === $default_lang ) {
				continue;
			}

			echo '<h4>' . esc_html( $this->getLanguageName( $lang ) ) . '</h4>';

			foreach ( $contexts as $context => $label ) {
				$value = get_post_meta( $post->ID, '_voxfor_ml_seo_' . $context . '_' . $lang, true );

				echo '<p>';
				echo '<label for="voxfor_ml_seo_' . esc_attr( $context ) . '_' . esc_attr( $lang ) . '">';
				echo esc_html( $label ) . ':<br>';
				echo '<input type="text" id="voxfor_ml_seo_' . esc_attr( $context ) . '_' . esc_attr( $lang ) . '" ';
				echo 'name="voxfor_ml_seo[' . esc_attr( $lang ) . '][' . esc_attr( $context ) . ']" ';
				echo 'value="' . esc_attr( $value ) . '" class="large-text" />';
				echo '</label>';
				echo '</p>';
			}
		}

		echo '<p class="description">';
		echo esc_html__( 'Available variables: {{site_name}}, {{site_description}}, {{current_year}}, {{post_title}}, {{original}}', 'voxfor-multilanguage' );
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Save meta templates
	 */
	public function saveMetaTemplates( $post_id ) {
		if ( ! isset( $_POST['voxfor_ml_seo_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['voxfor_ml_seo_nonce'] ) ), 'voxfor_ml_save_seo_templates' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['voxfor_ml_seo'] ) && is_array( $_POST['voxfor_ml_seo'] ) ) {
			$seo_data = array_map( 'sanitize_text_field', wp_unslash( $_POST['voxfor_ml_seo'] ) );
			foreach ( $seo_data as $lang => $contexts ) {
				foreach ( $contexts as $context => $template ) {
					$meta_key = '_voxfor_ml_seo_' . $context . '_' . $lang;

					if ( empty( $template ) ) {
						delete_post_meta( $post_id, $meta_key );
					} else {
						update_post_meta( $post_id, $meta_key, sanitize_text_field( $template ) );
					}
				}
			}
		}
	}

	/**
	 * Get language name
	 */
	private function getLanguageName( $code ) {
		$languages = array(
			'en' => 'English',
			'he' => 'עברית',
			'fr' => 'Français',
			'de' => 'Deutsch',
			'es' => 'Español',
			'it' => 'Italiano',
			'pt' => 'Português',
			'ru' => 'Русский',
			'ja' => '日本語',
			'zh' => '中文',
			'ar' => 'العربية',
		);

		return isset( $languages[ $code ] ) ? $languages[ $code ] : strtoupper( $code );
	}
	
	/**
	 * Translate WordPress core title
	 */
	public function translateWPTitle( $title ) {
		return $this->translateIfNeeded( $title, 'wp_title' );
	}
	
	/**
	 * Translate document title parts
	 */
	public function translateDocumentTitle( $title_parts ) {
		if ( isset( $title_parts['title'] ) ) {
			$title_parts['title'] = $this->translateIfNeeded( $title_parts['title'], 'wp_title' );
		}
		return $title_parts;
	}
	
	/**
	 * Output translated meta description for WordPress core
	 */
	public function outputTranslatedMetaDescription() {
		// Only output if no SEO plugin is handling it
		if ( $this->hasSEOPlugin() ) {
			return;
		}
		
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}
		
		$current_lang = $this->router->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();
		
		if ( $current_lang === $default_lang ) {
			return;
		}
		
		// Check for stored translated description
		$description = get_post_meta( $post_id, 'wp_meta_description_' . $current_lang, true );
		
		// Fallback to post excerpt if no stored description
		if ( empty( $description ) ) {
			$post = get_post( $post_id );
			if ( $post && ! empty( $post->post_excerpt ) ) {
				$description = $this->translateIfNeeded( $post->post_excerpt, 'wp_description' );
			}
		}
		
		if ( ! empty( $description ) ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
	}
	
	/**
	 * Check if an SEO plugin is active
	 */
	private function hasSEOPlugin() {
		return (
			class_exists( 'WPSEO_Options' ) || // Yoast SEO
			class_exists( 'RankMath' ) ||      // RankMath
			class_exists( 'AIOSEO\\Plugin\\AIOSEO' ) || // All in One SEO
			function_exists( 'seopress_activation' )    // SEOPress
		);
	}

	/**
	 * Translate WordPress core title
	 */
}
