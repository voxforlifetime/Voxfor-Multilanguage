<?php
namespace VoxforML\SEO;

use VoxforML\Core\Plugin;

/**
 * Manages SEO features including hreflang tags, canonical URLs, and image ALT text
 */
class SEOManager {
	private $plugin;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin = Plugin::getInstance();
	}

	/**
	 * Output hreflang tags in the head
	 */
	public function outputHreflangTags() {
		if ( ! get_option( 'voxfor_ml_enable_hreflang', true ) ) {
			return;
		}

		$router        = $this->plugin->getComponent( 'router' );
		$per_lang_seo  = $this->plugin->getComponent( 'per_language_seo' );
		$language_urls = $router->getLanguageUrls();
		$current_lang  = $router->getCurrentLanguage();
		$default_lang  = $this->plugin->getDefaultLanguage();
		$custom_prefix = get_option( 'voxfor_ml_display_prefix', 'en' );

		// Check if current language should be indexed
		if ( ! $per_lang_seo->isLanguageIndexable( $current_lang ) ) {
			echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
		}

		// Output hreflang tags only for indexable languages
		foreach ( $language_urls as $lang => $url ) {
			if ( $per_lang_seo->isLanguageIndexable( $lang ) ) {
				// Use custom prefix for the actual default language
				$hreflang_code = ( $lang === $default_lang ) ? $custom_prefix : $lang;
				printf(
					'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
					esc_attr( $hreflang_code ),
					esc_url( $url )
				);
			}
		}

		// Add x-default pointing to display default language version
		// Use actual default URL but with display default hreflang
		if ( isset( $language_urls[ $default_lang ] ) ) {
			printf(
				'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
				esc_url( $language_urls[ $default_lang ] )
			);
		}

		// Add Open Graph locale meta tag (use display language for SEO)
		$display_lang = $this->getDisplayLanguageForSEO( $current_lang );
		$og_locale    = $this->getOGLocale( $display_lang );
		printf(
			'<meta property="og:locale" content="%s" />' . "\n",
			esc_attr( $og_locale )
		);

		// Add alternate locales for Open Graph (only indexable languages)
		foreach ( $language_urls as $lang => $url ) {
			if ( $lang !== $current_lang && $per_lang_seo->isLanguageIndexable( $lang ) ) {
				// Use display language for SEO output
				$display_alt_lang = $this->getDisplayLanguageForSEO( $lang );
				$alt_locale       = $this->getOGLocale( $display_alt_lang );
				printf(
					'<meta property="og:locale:alternate" content="%s" />' . "\n",
					esc_attr( $alt_locale )
				);
			}
		}
	}

	/**
	 * Filter canonical URL to include language prefix
	 */
	public function filterCanonicalUrl( $canonical_url ) {
		$router       = $this->plugin->getComponent( 'router' );
		$current_lang = $router->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		// Use internal default language check (keep functionality intact)
		if ( $current_lang !== $default_lang ) {
			$canonical_url = $router->getLanguageUrl( $canonical_url, $current_lang );
		}

		return $canonical_url;
	}

	/**
	 * Translate image ALT text for SEO
	 */
	public function translateImageAlt( $alt_text, $attachment_id = null ) {
		if ( ! get_option( 'voxfor_ml_translate_image_alt', true ) ) {
			return $alt_text;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		// Don't translate if we're on the default language
		if ( $current_lang === $default_lang || empty( $alt_text ) ) {
			return $alt_text;
		}

		// Check for cached translation if attachment ID is provided
		if ( $attachment_id ) {
			$cached_alt = get_post_meta( $attachment_id, '_voxfor_ml_alt_' . $current_lang, true );
			if ( $cached_alt ) {
				return $cached_alt;
			}
		}

		// Get translator component
		$translator = $this->plugin->getComponent( 'translator' );

		// Use the TranslationManager's translateText method
		$translated_alt = $this->translateTextDirect( $alt_text, $current_lang, 'image_alt', $attachment_id );

		// Cache the translation if attachment ID is provided
		if ( $attachment_id && $translated_alt !== $alt_text ) {
			update_post_meta( $attachment_id, '_voxfor_ml_alt_' . $current_lang, $translated_alt );
		}

		return $translated_alt;
	}

	/**
	 * Direct translation method for SEO content
	 */
	private function translateTextDirect( $text, $language, $context = 'seo', $post_id = null ) {
		$memory = new \VoxforML\Database\TranslationMemory();

		// Check translation memory first
		$cached = $memory->getTranslation( $text, $language, $context );
		if ( $cached !== false ) {
			return $cached;
		}

		// Queue for translation
		$memory->queueForTranslation( $text, $language, $context, $post_id );

		// For SEO content, we need immediate translation
		$translator = new \VoxforML\Translator\DeepLTranslator();
		$translated = $translator->translate( $text, $language, 'auto', $context );

		if ( $translated ) {
			$memory->saveTranslation( $text, $translated, $language, $context, $post_id );
			return $translated;
		}

		return $text;
	}

	/**
	 * Get display language for SEO output (cosmetic override)
	 * This converts internal 'en' to user's chosen display language for SEO meta tags
	 */
	private function getDisplayLanguageForSEO( $internal_lang ) {
		// Only apply cosmetic override to the internal default language 'en'
		if ( $internal_lang === 'en' ) {
			return get_option( 'voxfor_ml_display_prefix', 'en' );
		}

		// All other languages remain unchanged
		return $internal_lang;
	}

	/**
	 * Get Open Graph locale from language code
	 */
	private function getOGLocale( $lang_code ) {
		$locales = array(
			'en' => 'en_US',
			'fr' => 'fr_FR',
			'de' => 'de_DE',
			'es' => 'es_ES',
			'it' => 'it_IT',
			'pt' => 'pt_PT',
			'ru' => 'ru_RU',
			'ja' => 'ja_JP',
			'zh' => 'zh_CN',
			'ko' => 'ko_KR',
			'ar' => 'ar_AR',
			'he' => 'he_IL',
			'sv' => 'sv_SE',
			'no' => 'no_NO',
			'da' => 'da_DK',
			'fi' => 'fi_FI',
			'nl' => 'nl_NL',
			'pl' => 'pl_PL',
			'tr' => 'tr_TR',
			'cs' => 'cs_CZ',
			'hu' => 'hu_HU',
			'ro' => 'ro_RO',
			'el' => 'el_GR',
			'th' => 'th_TH',
			'vi' => 'vi_VN',
			'id' => 'id_ID',
			'ms' => 'ms_MY',
			'hi' => 'hi_IN',
			'bn' => 'bn_BD',
			'uk' => 'uk_UA',
		);

		// Use custom prefix for fallback locale
		$custom_prefix  = get_option( 'voxfor_ml_display_prefix', 'en' );
		$default_locale = isset( $locales[ $custom_prefix ] ) ? $locales[ $custom_prefix ] : 'en_US';
		return isset( $locales[ $lang_code ] ) ? $locales[ $lang_code ] : $default_locale;
	}

	/**
	 * Generate structured data for language versions
	 */
	public function generateStructuredData() {
		$router        = $this->plugin->getComponent( 'router' );
		$language_urls = $router->getLanguageUrls();
		$current_lang  = $router->getCurrentLanguage();
		$default_lang  = $this->plugin->getDefaultLanguage();

		// Use display language for SEO structured data
		$display_current_lang = $this->getDisplayLanguageForSEO( $current_lang );

		$structured_data = array(
			'@context'          => 'https://schema.org',
			'@type'             => 'WebPage',
			'inLanguage'        => $display_current_lang,
			'isTranslationOf'   => $language_urls[ $default_lang ],
			'translationOfWork' => array(),
		);

		foreach ( $language_urls as $lang => $url ) {
			// Use display language for each language in structured data
			$display_lang                           = $this->getDisplayLanguageForSEO( $lang );
			$structured_data['translationOfWork'][] = array(
				'@type'      => 'WebPage',
				'inLanguage' => $display_lang,
				'url'        => $url,
			);
		}

		return $structured_data;
	}

	/**
	 * Add language to sitemap URLs (for compatibility with SEO plugins)
	 */
	public function filterSitemapUrls( $urls ) {
		$router    = $this->plugin->getComponent( 'router' );
		$languages = $this->plugin->getAvailableLanguages();
		$new_urls  = array();

		foreach ( $urls as $url ) {
			// Add URL for each language
			foreach ( $languages as $lang ) {
				$lang_url = $url;

				if ( $lang !== 'en' ) {
					$lang_url['loc'] = $router->getLanguageUrl( $url['loc'], $lang );
				}

				// Add language info to sitemap
				$lang_url['language'] = $lang;

				// Add alternate links
				$lang_url['alternates'] = array();
				foreach ( $languages as $alt_lang ) {
					$lang_url['alternates'][] = array(
						'language' => $alt_lang,
						'url'      => $router->getLanguageUrl( $url['loc'], $alt_lang ),
					);
				}

				$new_urls[] = $lang_url;
			}
		}

		return $new_urls;
	}

	/**
	 * Get SEO metadata for translation
	 */
	public function getSEOMetadata( $post_id ) {
		$metadata = array();

		// Get Yoast SEO data
		if ( defined( 'WPSEO_VERSION' ) ) {
			$metadata['yoast_title']           = get_post_meta( $post_id, '_yoast_wpseo_title', true );
			$metadata['yoast_desc']            = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
			$metadata['yoast_focus_kw']        = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
			$metadata['yoast_opengraph_title'] = get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true );
			$metadata['yoast_opengraph_desc']  = get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true );
			$metadata['yoast_twitter_title']   = get_post_meta( $post_id, '_yoast_wpseo_twitter-title', true );
			$metadata['yoast_twitter_desc']    = get_post_meta( $post_id, '_yoast_wpseo_twitter-description', true );
		}

		// Get RankMath data
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$metadata['rankmath_title']          = get_post_meta( $post_id, 'rank_math_title', true );
			$metadata['rankmath_desc']           = get_post_meta( $post_id, 'rank_math_description', true );
			$metadata['rankmath_focus_kw']       = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
			$metadata['rankmath_facebook_title'] = get_post_meta( $post_id, 'rank_math_facebook_title', true );
			$metadata['rankmath_facebook_desc']  = get_post_meta( $post_id, 'rank_math_facebook_description', true );
			$metadata['rankmath_twitter_title']  = get_post_meta( $post_id, 'rank_math_twitter_title', true );
			$metadata['rankmath_twitter_desc']   = get_post_meta( $post_id, 'rank_math_twitter_description', true );
		}

		// Get All in One SEO data
		if ( defined( 'AIOSEO_VERSION' ) ) {
			$aioseo_data = get_post_meta( $post_id, '_aioseo_title', true );
			if ( $aioseo_data ) {
				$metadata['aioseo_title']         = $aioseo_data;
				$metadata['aioseo_desc']          = get_post_meta( $post_id, '_aioseo_description', true );
				$metadata['aioseo_keywords']      = get_post_meta( $post_id, '_aioseo_keywords', true );
				$metadata['aioseo_og_title']      = get_post_meta( $post_id, '_aioseo_og_title', true );
				$metadata['aioseo_og_desc']       = get_post_meta( $post_id, '_aioseo_og_description', true );
				$metadata['aioseo_twitter_title'] = get_post_meta( $post_id, '_aioseo_twitter_title', true );
				$metadata['aioseo_twitter_desc']  = get_post_meta( $post_id, '_aioseo_twitter_description', true );
			}
		}

		// Get SEOPress data
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$metadata['seopress_title']         = get_post_meta( $post_id, '_seopress_titles_title', true );
			$metadata['seopress_desc']          = get_post_meta( $post_id, '_seopress_titles_desc', true );
			$metadata['seopress_keywords']      = get_post_meta( $post_id, '_seopress_analysis_target_kw', true );
			$metadata['seopress_og_title']      = get_post_meta( $post_id, '_seopress_social_fb_title', true );
			$metadata['seopress_og_desc']       = get_post_meta( $post_id, '_seopress_social_fb_desc', true );
			$metadata['seopress_twitter_title'] = get_post_meta( $post_id, '_seopress_social_twitter_title', true );
			$metadata['seopress_twitter_desc']  = get_post_meta( $post_id, '_seopress_social_twitter_desc', true );
		}

		// Get image ALT texts for SEO
		$metadata['image_alts'] = $this->getImageAltTexts( $post_id );

		return array_filter( $metadata ); // Remove empty values
	}

	/**
	 * Get all image ALT texts from post content
	 */
	private function getImageAltTexts( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$alt_texts = array();

		// Extract image IDs from content
		preg_match_all( '/wp-image-(\d+)/', $post->post_content, $matches );

		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $image_id ) {
				$alt_text = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
				if ( $alt_text ) {
					$alt_texts[ $image_id ] = $alt_text;
				}
			}
		}

		// Also check for gallery images
		$gallery_ids = get_post_meta( $post_id, '_gallery', true );
		if ( $gallery_ids ) {
			$ids = explode( ',', $gallery_ids );
			foreach ( $ids as $image_id ) {
				$alt_text = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
				if ( $alt_text ) {
					$alt_texts[ $image_id ] = $alt_text;
				}
			}
		}

		// Check featured image
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			$alt_text = get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
			if ( $alt_text ) {
				$alt_texts[ $thumbnail_id ] = $alt_text;
			}
		}

		return $alt_texts;
	}

	/**
	 * Update translated SEO metadata
	 */
	public function updateTranslatedSEOMetadata( $post_id, $language, $translated_metadata ) {
		// Store translated SEO data in custom meta fields
		foreach ( $translated_metadata as $key => $value ) {
			update_post_meta( $post_id, '_voxfor_ml_' . $language . '_' . $key, $value );
		}

		// Update translated image ALT texts
		if ( isset( $translated_metadata['image_alts'] ) ) {
			foreach ( $translated_metadata['image_alts'] as $image_id => $alt_text ) {
				update_post_meta( $image_id, '_voxfor_ml_' . $language . '_alt', $alt_text );
			}
		}
	}

	/**
	 * Get robots meta tag for language versions
	 */
	public function getRobotsMetaTag() {
		$router       = $this->plugin->getComponent( 'router' );
		$current_lang = $router->getCurrentLanguage();

		// Allow indexing of all language versions
		return 'index, follow';
	}

	/**
	 * Add JSON-LD structured data for multilingual content
	 */
	public function outputStructuredData() {
		$structured_data = $this->generateStructuredData();

		// Use default wp_json_encode escaping for security (search engines handle escaped JSON properly)
		wp_print_inline_script_tag(
			wp_json_encode( $structured_data ),
			array( 'type' => 'application/ld+json' )
		);
	}

	/**
	 * Enable lazy loading for images
	 */
	public function enableLazyLoading( $enabled, $tag_name ) {
		// Check if lazy loading is enabled in settings
		if ( ! get_option( 'voxfor_ml_enable_lazy_loading', true ) ) {
			return $enabled;
		}

		// Enable lazy loading for img tags
		if ( $tag_name === 'img' ) {
			return true;
		}
		return $enabled;
	}

	/**
	 * Add lazy loading attribute to images
	 */
	public function addLazyLoadingAttr( $value, $image, $context ) {
		// Check if lazy loading is enabled in settings
		if ( ! get_option( 'voxfor_ml_enable_lazy_loading', true ) ) {
			return $value;
		}

		// Add loading="lazy" to all images except those in critical areas
		if ( $context !== 'the_content' || strpos( $image, 'no-lazy' ) === false ) {
			return 'lazy';
		}
		return $value;
	}
}
