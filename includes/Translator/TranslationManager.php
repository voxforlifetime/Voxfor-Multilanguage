<?php
namespace VoxforML\Translator;

use VoxforML\Core\Plugin;
use VoxforML\Database\TranslationMemory;
use VoxforML\Translator\DeepLTranslator;
use VoxforML\Utils\TextProcessor;

/**
 * Manages content translation with emphasis on SEO elements including image ALT text
 */
class TranslationManager {
	private $plugin;
	private $memory;
	private $translator;
	private $processor;
	private $current_language;
	private $filters_added = false;

	// Advanced recursion protection
	private static $translating_title   = false;
	private static $translating_content = false;
	private static $translating_excerpt = false;
	private static $translating_menu    = false;
	private static $translating_widget  = false;
	private static $translating_image   = false;
	private static $translation_depth   = 0;

	/**
	 * Constructor
	 */
	public function __construct() {

		$this->plugin     = Plugin::getInstance();
		$this->memory     = new TranslationMemory();
		$this->translator = new DeepLTranslator();
		$this->processor  = new TextProcessor();

		// Hook into WordPress content filters
		$this->initHooks();
	}

	/**
	 * Initialize hooks for content translation
	 */
	private function initHooks() {
		// Prevent duplicate filter registration
		if ( $this->filters_added ) {

			return;
		}

		// Re-enable translation hooks with memory protection
		//
		add_filter( 'the_title', array( $this, 'filterTitle' ), 10 );
		add_filter( 'the_content', array( $this, 'filterContent' ), 10 );
		add_filter( 'wp_nav_menu_items', array( $this, 'filterMenuItems' ), 10, 2 );
		add_filter( 'document_title_parts', array( $this, 'filterDocumentTitle' ), 10 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filterImageAttributes' ), 10, 3 );

		$this->filters_added = true;
	}

	/**
	 * Filter content for translation
	 */
	public function filterContent( $content ) {

		if ( empty( $content ) ) {

			return $content;
		}

		$current_language = $this->plugin->getCurrentLanguage();

		if ( $current_language === 'en' ) {

			return $content;
		}

		return $this->translateContent( $content, 'content' );
	}

	/**
	 * Translate content
	 */
	public function translateContent( $content, $context = 'content' ) {
		// Advanced recursion protection
		if ( self::$translating_content || self::$translation_depth > 3 ) {
			return $content;
		}

		self::$translating_content = true;
		++self::$translation_depth;

		try {
			$this->current_language = $this->plugin->getCurrentLanguage();
			// Don't translate English or if translation is disabled
			if ( $this->current_language === 'en' || ! $this->shouldTranslate() ) {

				return $content;
			}

			// Check if content should be excluded

			if ( $this->processor->shouldExclude( $content, $context ) ) {

				return $content;
			}

			// Process content for translation

			$processed = $this->processor->prepareContent( $content );

			// Get current post ID for proper translation memory
			$post_id = get_the_ID() ?: get_queried_object_id();

			// Translate all text placeholders individually
			$translated_content = $this->translateContentWithPlaceholders( $processed['text'], $processed['placeholders'], $context, $post_id );

			// Restore HTML structure
			$final_content = $this->processor->restoreContent( $translated_content, $processed['placeholders'] );

			// Translate image ALT texts within content
			$final_content = $this->translateImagesInContent( $final_content );

			// Localize internal URLs in content
			$final_content = $this->localizeInternalUrls( $final_content, $this->current_language );

			return $final_content;
		} finally {
			self::$translating_content = false;
			--self::$translation_depth;
		}
	}

	/**
	 * Translate title
	 */
	public function translateTitle( $title, $post_id = null ) {
		// Recursion protection
		if ( self::$translating_title || self::$translation_depth > 3 ) {
			return $title;
		}

		self::$translating_title = true;
		++self::$translation_depth;

		try {
			$this->current_language = $this->plugin->getCurrentLanguage();

			if ( $this->current_language === 'en' || ! $this->shouldTranslate( $post_id ) ) {

				return $title;
			}

			$result = $this->translateText( $title, 'title', $post_id );
			return $result;
		} finally {
			self::$translating_title = false;
			--self::$translation_depth;
		}
	}

	/**
	 * Translate excerpt
	 */
	public function translateExcerpt( $excerpt ) {
		// Recursion protection
		if ( self::$translating_excerpt || self::$translation_depth > 3 ) {
			return $excerpt;
		}

		self::$translating_excerpt = true;
		++self::$translation_depth;

		try {
			$this->current_language = $this->plugin->getCurrentLanguage();

			if ( $this->current_language === 'en' || ! $this->shouldTranslate() ) {
				return $excerpt;
			}

			$result = $this->translateText( $excerpt, 'excerpt' );
			return $result;
		} finally {
			self::$translating_excerpt = false;
			--self::$translation_depth;
		}
	}

	/**
	 * Translate image attributes including ALT text for SEO
	 */
	public function translateImageAttributes( $attributes, $attachment, $size ) {
		// Recursion protection
		if ( self::$translating_image || self::$translation_depth > 3 ) {
			return $attributes;
		}

		self::$translating_image = true;
		++self::$translation_depth;

		try {
			$this->current_language = $this->plugin->getCurrentLanguage();

			// Don't translate if disabled or English
			if ( $this->current_language === 'en' || ! get_option( 'voxfor_ml_translate_image_alt', true ) ) {
				return $attributes;
			}

			$attachment_id = is_object( $attachment ) ? $attachment->ID : $attachment;

			// Translate ALT text
			if ( ! empty( $attributes['alt'] ) ) {
				// Check for cached translation first
				$cached_alt = get_post_meta( $attachment_id, '_voxfor_ml_' . $this->current_language . '_alt', true );

				if ( $cached_alt ) {
					$attributes['alt'] = $cached_alt;
				} else {
					// Translate and cache
					$translated_alt = $this->translateText( $attributes['alt'], 'image_alt', $attachment_id );
					update_post_meta( $attachment_id, '_voxfor_ml_' . $this->current_language . '_alt', $translated_alt );
					$attributes['alt'] = $translated_alt;
				}
			}

			// Translate title attribute if present
			if ( ! empty( $attributes['title'] ) ) {
				$cached_title = get_post_meta( $attachment_id, '_voxfor_ml_' . $this->current_language . '_title', true );

				if ( $cached_title ) {
					$attributes['title'] = $cached_title;
				} else {
					$translated_title = $this->translateText( $attributes['title'], 'image_title', $attachment_id );
					update_post_meta( $attachment_id, '_voxfor_ml_' . $this->current_language . '_title', $translated_title );
					$attributes['title'] = $translated_title;
				}
			}

			// Add language attribute for accessibility
			$attributes['lang'] = $this->current_language;

			return $attributes;
		} finally {
			self::$translating_image = false;
			--self::$translation_depth;
		}
	}

	/**
	 * Translate images within content
	 */
	private function translateImagesInContent( $content ) {
		// Find all images in content
		preg_match_all( '/<img[^>]+>/i', $content, $matches );

		if ( empty( $matches[0] ) ) {
			return $content;
		}

		foreach ( $matches[0] as $img_tag ) {
			$new_img_tag = $img_tag;

			// Extract and translate ALT text
			if ( preg_match( '/alt=["\']([^"\']*)["\']/', $img_tag, $alt_match ) ) {
				$original_alt = $alt_match[1];
				if ( ! empty( $original_alt ) ) {
					$translated_alt = $this->translateText( $original_alt, 'image_alt_inline' );
					$new_img_tag    = str_replace(
						'alt="' . $original_alt . '"',
						'alt="' . esc_attr( $translated_alt ) . '"',
						$new_img_tag
					);
				}
			}

			// Extract and translate title attribute
			if ( preg_match( '/title=["\']([^"\']*)["\']/', $img_tag, $title_match ) ) {
				$original_title = $title_match[1];
				if ( ! empty( $original_title ) ) {
					$translated_title = $this->translateText( $original_title, 'image_title_inline' );
					$new_img_tag      = str_replace(
						'title="' . $original_title . '"',
						'title="' . esc_attr( $translated_title ) . '"',
						$new_img_tag
					);
				}
			}

			// Add language attribute if not present
			if ( ! preg_match( '/lang=["\']/', $new_img_tag ) ) {
				$new_img_tag = str_replace( '<img', '<img lang="' . $this->current_language . '"', $new_img_tag );
			}

			$content = str_replace( $img_tag, $new_img_tag, $content );
		}

		return $content;
	}

	/**
	 * Translate menu items
	 */
	public function translateMenuItems( $items ) {
		// Recursion protection
		if ( self::$translating_menu || self::$translation_depth > 3 ) {
			return $items;
		}

		self::$translating_menu = true;
		++self::$translation_depth;

		try {
			$this->current_language = $this->plugin->getCurrentLanguage();

			if ( $this->current_language === 'en' ) {

				return $items;
			}

			foreach ( $items as &$item ) {
				// Translate menu item title
				$item->title = $this->translateText( $item->title, 'menu_item', $item->ID );

				// Translate menu item description if exists
				if ( ! empty( $item->description ) ) {
					$item->description = $this->translateText( $item->description, 'menu_description', $item->ID );
				}

				// Update URL to include language prefix
				$router    = $this->plugin->getComponent( 'router' );
				$item->url = $router->getLanguageUrl( $item->url, $this->current_language );
			}

			return $items;
		} finally {
			self::$translating_menu = false;
			--self::$translation_depth;
		}
	}

	/**
	 * Translate widget text
	 */
	public function translateWidgetText( $text ) {
		// Recursion protection
		if ( self::$translating_widget || self::$translation_depth > 3 ) {
			return $text;
		}

		self::$translating_widget = true;
		++self::$translation_depth;

		try {
			$this->current_language = $this->plugin->getCurrentLanguage();

			if ( $this->current_language === 'en' ) {
				return $text;
			}

			$result = $this->translateContent( $text, 'widget_text' );
			return $result;
		} finally {
			self::$translating_widget = false;
			--self::$translation_depth;
		}
	}

	/**
	 * Translate widget title
	 */
	public function translateWidgetTitle( $title ) {
		// Recursion protection
		if ( self::$translating_widget || self::$translation_depth > 3 ) {
			return $title;
		}

		self::$translating_widget = true;
		++self::$translation_depth;

		try {
			$this->current_language = $this->plugin->getCurrentLanguage();

			if ( $this->current_language === 'en' ) {

				return $title;
			}

			$result = $this->translateText( $title, 'widget_title' );
			return $result;
		} finally {
			self::$translating_widget = false;
			--self::$translation_depth;
		}
	}

	/**
	 * Test translation with specific language
	 */
	public function testTranslation( $text, $language, $context = 'test', $post_id = null ) {
		if ( empty( $text ) || $language === 'en' ) {
			return $text;
		}

		// Force immediate translation
		$original_immediate = get_option( 'voxfor_ml_immediate_translation', false );
		update_option( 'voxfor_ml_immediate_translation', true );

		// Temporarily set language
		$original_lang          = $this->current_language;
		$this->current_language = $language;

		try {
			// Correct parameter order for DeepL: text, target, source, context
			$translated = $this->translator->translate( $text, $language, 'EN', $context );

			if ( $translated && $translated !== $text ) {
				// Save raw translation (without glossary) to memory for lookup with post_id
				$this->memory->saveTranslation( $text, $translated, $language, $context, $post_id, 'deepl' );

				// Apply glossary rules to display value
				$final_translated = $this->processor->applyGlossary( $translated, $language );
				return $final_translated;
			}

			return $text;
		} finally {
			// Restore settings
			$this->current_language = $original_lang;
			update_option( 'voxfor_ml_immediate_translation', $original_immediate );
		}
	}

		/**
		 * Core translation method
		 */
	public function translateText( $text, $context = 'general', $post_id = null ) {
		if ( empty( $text ) ) {
			return $text;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		// CRITICAL FIX: Check URL exclusion BEFORE retrieving cached translations
		// This ensures excluded URLs show original content even if translations exist in database
		if ( ! $this->shouldTranslate( $post_id ) ) {
			return $text; // Return original text for excluded URLs
		}

		// Check translation memory first
		$cached = $this->memory->getTranslation( $text, $this->current_language, $context );
		if ( $cached !== false ) {
			// Apply glossary rules to cached translation
			return $this->processor->applyGlossary( $cached, $this->current_language );
		}

		// Check if API is enabled before attempting translation
		$credit_manager = $this->plugin->getComponent( 'api_credit_manager' );
		$api_enabled    = $credit_manager ? $credit_manager->isApiEnabled() : true;

		// For immediate translation (synchronous mode)
		if ( $this->shouldTranslateImmediately() && $api_enabled ) {
			$translated = $this->translator->translate( $text, $this->current_language, 'EN', $context );

			if ( $translated ) {
				// Apply glossary rules to the translated text
				$final_translated = $this->processor->applyGlossary( $translated, $this->current_language );

				// Save the original translation (without glossary) to memory
				$this->memory->saveTranslation( $text, $translated, $this->current_language, $context, $post_id, 'deepl' );

				// Return the glossary-processed version
				return $final_translated;
			}
		}

		// Only queue for translation if immediate translation failed or is disabled
		// IMPORTANT: Never auto-queue on frontend - only in admin
		if ( ! apply_filters( 'voxfor_ml_disable_auto_queue', false ) && is_admin() ) {
			$this->memory->queueForTranslation( $text, $this->current_language, $context, $post_id );
		}

		// Return original text if translation fails
		return $text;
	}

	/**
	 * Batch translate multiple texts
	 */
	public function batchTranslate( $texts, $language, $context = 'general' ) {
		$translations = array();
		$to_translate = array();

		// Check memory for each text
		foreach ( $texts as $key => $text ) {
			$cached = $this->memory->getTranslation( $text, $language, $context );
			if ( $cached !== false ) {
				$translations[ $key ] = $cached;
			} else {
				$to_translate[ $key ] = $text;
			}
		}

		// Batch translate missing texts
		if ( ! empty( $to_translate ) ) {
			$batch_translations = $this->translator->batchTranslate( $to_translate, $language, $context );

			foreach ( $batch_translations as $key => $translated ) {
				$translations[ $key ] = $translated;
				$this->memory->saveTranslation( $to_translate[ $key ], $translated, $language, $context, null, 'deepl' );
			}
		}

		return $translations;
	}

	/**
	 * Check if content should be translated
	 */
	private function shouldTranslate( $post_id = null ) {
		// Check if post is excluded
		if ( $post_id && get_post_meta( $post_id, '_voxfor_ml_exclude', true ) ) {
			return false;
		}

		// Check if current URL is excluded
		$current_url = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );

		if ( $this->processor->isUrlExcluded( $current_url ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Translate content with individual placeholder translation
	 */
	private function translateContentWithPlaceholders( $content, &$placeholders, $context, $post_id = null ) {
		// Translate each text/attribute placeholder individually
		foreach ( $placeholders as $placeholder => $original_text ) {
			// Only translate text and attribute placeholders, skip protected elements
			if ( strpos( $placeholder, '[[VOXFOR_TEXT_' ) === 0 || strpos( $placeholder, '[[VOXFOR_ATTR_' ) === 0 ) {
				$translated_text = $this->translateText( $original_text, $context, $post_id );

				// Update the placeholder value with translated text
				$placeholders[ $placeholder ] = $translated_text;
			}
		}

		// Replace placeholders in content with translated text
		foreach ( $placeholders as $placeholder => $translated_text ) {
			if ( strpos( $placeholder, '[[VOXFOR_TEXT_' ) === 0 || strpos( $placeholder, '[[VOXFOR_ATTR_' ) === 0 ) {
				$content = str_replace( $placeholder, $translated_text, $content );
			}
		}

		return $content;
	}

	/**
	 * Localize internal URLs in content
	 * Converts internal links to use translated slugs for the current language
	 */
	private function localizeInternalUrls( $content, $target_language ) {
		if ( empty( $content ) || $target_language === 'en' ) {
			return $content;
		}

		// Get site URL for comparison
		$site_url = trailingslashit( site_url() );
		$site_host = wp_parse_url( $site_url, PHP_URL_HOST );

		// Pattern to match <a> tags with href attributes
		$pattern = '/<a\s+([^>]*?)href=(["\'])([^"\']*?)\2([^>]*?)>/i';

		return preg_replace_callback( $pattern, function( $matches ) use ( $target_language, $site_url, $site_host ) {
			$before_href = $matches[1];
			$quote_char = $matches[2];
			$url = $matches[3];
			$after_href = $matches[4];

			// Parse the URL
			$parsed_url = wp_parse_url( $url );
			
			// Skip if not an internal URL
			if ( isset( $parsed_url['host'] ) && $parsed_url['host'] !== $site_host ) {
				return $matches[0]; // Return original tag
			}

			// Skip if no path or root path
			if ( empty( $parsed_url['path'] ) || $parsed_url['path'] === '/' ) {
				return $matches[0]; // Return original tag
			}

			// Get the localized URL
			$localized_url = $this->getLocalizedUrl( $url, $target_language, $site_url );

			if ( $localized_url && $localized_url !== $url ) {
				// Preserve query string and fragment
				if ( isset( $parsed_url['query'] ) ) {
					$localized_url .= '?' . $parsed_url['query'];
				}
				if ( isset( $parsed_url['fragment'] ) ) {
					$localized_url .= '#' . $parsed_url['fragment'];
				}

				// Replace href attribute
				$new_tag = '<a ' . $before_href . 'href=' . $quote_char . $localized_url . $quote_char . $after_href . '>';

				// Also update data-id attribute if it matches the original URL
				if ( strpos( $after_href, 'data-id=' ) !== false ) {
					$new_tag = preg_replace(
						'/data-id=(["\'])' . preg_quote( $url, '/' ) . '\1/i',
						'data-id=$1' . $localized_url . '$1',
						$new_tag
					);
				}

				return $new_tag;
			}

			return $matches[0]; // Return original tag if no localization needed
		}, $content );
	}

	/**
	 * Get localized URL for internal links
	 */
	private function getLocalizedUrl( $url, $target_language, $site_url ) {
		// Parse the URL to extract path
		$parsed_url = wp_parse_url( $url );
		$path = isset( $parsed_url['path'] ) ? trim( $parsed_url['path'], '/' ) : '';

		if ( empty( $path ) ) {
			return $url; // Keep original for root URLs
		}

		// Remove language prefix if present to get clean path
		$clean_path = $this->removeLanguagePrefixFromPath( $path );
		
		// Detect URL type and handle accordingly
		$url_type = $this->detectUrlType( $clean_path );
		
		// Handle different URL types
		switch ( $url_type ) {
			case 'woocommerce_category':
				return $this->localizeWooCommerceUrl( $clean_path, $target_language, $site_url, 'product_cat', 'product-category' );
			
			case 'woocommerce_tag':
				return $this->localizeWooCommerceUrl( $clean_path, $target_language, $site_url, 'product_tag', 'product-tag' );
			
			case 'woocommerce_product':
				return $this->localizePostUrl( $clean_path, $target_language, $site_url, 'product' );
			
			case 'post':
			case 'page':
				return $this->localizePostUrl( $clean_path, $target_language, $site_url );
			
			default:
				// Fallback: add language prefix to original URL
				return $this->addLanguagePrefixToUrl( $url, $target_language, $site_url );
		}
	}

	/**
	 * Remove language prefix from path
	 */
	private function removeLanguagePrefixFromPath( $path ) {
		$languages = $this->plugin->getEnabledLanguages();
		foreach ( $languages as $lang ) {
			if ( $lang !== 'en' && strpos( $path, $lang . '/' ) === 0 ) {
				return substr( $path, strlen( $lang ) + 1 );
			}
		}
		return $path;
	}

	/**
	 * Detect URL type from path
	 */
	private function detectUrlType( $path ) {
		if ( strpos( $path, 'product-category/' ) === 0 ) {
			return 'woocommerce_category';
		}
		if ( strpos( $path, 'product-tag/' ) === 0 ) {
			return 'woocommerce_tag';
		}
		if ( strpos( $path, 'product/' ) === 0 ) {
			return 'woocommerce_product';
		}
		// Default to post/page
		return 'post';
	}

	/**
	 * Localize WooCommerce taxonomy URLs
	 */
	private function localizeWooCommerceUrl( $path, $target_language, $site_url, $taxonomy, $url_base ) {
		// Extract slug from path (e.g., 'product-category/women' -> 'women')
		$slug = $this->extractSlugFromPath( $path );
		if ( ! $slug ) {
			return $this->addLanguagePrefixToUrl( $site_url . $path, $target_language, $site_url );
		}

		// Get SlugManager
		$slug_manager = $this->plugin->getComponent( 'slug_manager' );
		if ( ! $slug_manager ) {
			return $this->addLanguagePrefixToUrl( $site_url . $path, $target_language, $site_url );
		}

		// Find the term by slug
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term ) {
			return $this->addLanguagePrefixToUrl( $site_url . $path, $target_language, $site_url );
		}

		// Get translated slug
		$translated_slug = $slug_manager->getTaxonomySlug( $term->term_id, $target_language );
		if ( ! $translated_slug ) {
			$translated_slug = $slug; // Use original slug if no translation
		}

		// Build localized URL
		if ( $target_language === 'en' ) {
			return $site_url . $url_base . '/' . $translated_slug . '/';
		} else {
			return $site_url . $target_language . '/' . $url_base . '/' . $translated_slug . '/';
		}
	}

	/**
	 * Localize post/page URLs
	 */
	private function localizePostUrl( $path, $target_language, $site_url, $post_type = null ) {
		// Extract slug from path
		$slug = $this->extractSlugFromPath( $path );
		if ( ! $slug ) {
			return $this->addLanguagePrefixToUrl( $site_url . $path, $target_language, $site_url );
		}

		// Get SlugManager
		$slug_manager = $this->plugin->getComponent( 'slug_manager' );
		if ( ! $slug_manager ) {
			return $this->addLanguagePrefixToUrl( $site_url . $path, $target_language, $site_url );
		}

		// Find post by slug
		$post_id = $slug_manager->findPostBySlug( $slug, 'en' );
		if ( ! $post_id ) {
			// Try current language in case we're dealing with a translated slug
			$post_id = $slug_manager->findPostBySlug( $slug, $target_language );
		}

		if ( $post_id ) {
			// Get the localized URL using SlugManager
			$original_url = $site_url . $path . '/';
			$localized_url = $slug_manager->getTranslatedUrl( $post_id, $target_language, $original_url );
			if ( $localized_url ) {
				return $localized_url;
			}
		}

		// Fallback: add language prefix to original URL
		return $this->addLanguagePrefixToUrl( $site_url . $path . '/', $target_language, $site_url );
	}

	/**
	 * Add language prefix to URL
	 */
	private function addLanguagePrefixToUrl( $url, $target_language, $site_url ) {
		if ( $target_language === 'en' ) {
			return $url;
		}

		// Check if URL already has language prefix
		$parsed_url = wp_parse_url( $url );
		$path = isset( $parsed_url['path'] ) ? trim( $parsed_url['path'], '/' ) : '';
		
		// Remove existing language prefix if present
		$clean_path = $this->removeLanguagePrefixFromPath( $path );
		
		// Build new URL with target language prefix
		return $site_url . $target_language . '/' . $clean_path . '/';
	}

	/**
	 * Extract slug from URL path
	 */
	private function extractSlugFromPath( $path ) {
		if ( empty( $path ) ) {
			return null;
		}

		// Handle special WordPress URL structures
		$path_parts = explode( '/', trim( $path, '/' ) );
		
		// Handle /product/slug, /author/slug, /category/slug patterns
		if ( count( $path_parts ) >= 2 ) {
			$first_part = $path_parts[0];
			
			// Known WordPress path prefixes that should be stripped
			$prefixes_to_strip = array( 'product', 'author', 'category', 'tag', 'product-category', 'product-tag' );
			
			if ( in_array( $first_part, $prefixes_to_strip ) ) {
				return $path_parts[1]; // Return the actual slug
			}
		}

		// For simple paths like /about/, return the first part
		return $path_parts[0];
	}

	/**
	 * Check if should translate immediately
	 */
	private function shouldTranslateImmediately() {
		// CRITICAL FIX: Visual editor mode should NOT trigger immediate translation
		// Visual editor handles translations via AJAX only, not during page load
		if ( isset( $_GET['voxfor_ml_edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false; // Never translate immediately in visual editor mode
		}

		// In admin or during AJAX requests, translate immediately
		if ( is_admin() || wp_doing_ajax() ) {
			return true;
		}

		// Check if this is an explicit translation request (like clicking translate button)
		if ( isset( $_POST['action'] ) && sanitize_text_field( wp_unslash( $_POST['action'] ) ) === 'voxfor_ml_translate_content' ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return true;
		}

		// Check if this is a manual translation trigger
		if ( isset( $_GET['voxfor_ml_force_translate'] ) && current_user_can( 'edit_posts' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		// Frontend visitors should only see pre-translated content from database
		return false;
	}

	/**
	 * Process translation queue
	 */
	public function processTranslationQueue() {
		$queue_items = $this->memory->getQueuedTranslations( get_option( 'voxfor_ml_batch_size', 10 ) );

		if ( empty( $queue_items ) ) {
			return;
		}

		// Group by language and context for efficient batch processing
		$grouped = array();
		foreach ( $queue_items as $item ) {
			$key = $item->language_code . '_' . $item->context;
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array();
			}
			$grouped[ $key ][] = $item;
		}

		// Process each group
		foreach ( $grouped as $group ) {
			$texts    = array_column( $group, 'source_text', 'id' );
			$language = $group[0]->language_code;
			$context  = $group[0]->context;

			$translations = $this->translator->batchTranslate( $texts, $language, $context );

			foreach ( $translations as $id => $translated ) {
				$item = array_filter(
					$group,
					function ( $i ) use ( $id ) {
						return $i->id == $id;
					}
				);
				$item = reset( $item );

				if ( $translated ) {
					$this->memory->saveTranslation(
						$item->source_text,
						$translated,
						$language,
						$context,
						$item->post_id,
						'deepl'
					);
					$this->memory->markQueueItemProcessed( $id, 'completed' );
				} else {
					$this->memory->markQueueItemProcessed( $id, 'failed', 'Translation failed' );
				}
			}
		}
	}

	/**
	 * Get translation statistics
	 */
	public function getTranslationStats() {
		return $this->memory->getStatistics();
	}

	/**
	 * Clean stuck queue items (older than 5 minutes)
	 */
	public function cleanStuckQueueItems() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'voxfor_ml_translation_queue';

		// Mark items as failed if they've been processing for more than 5 minutes
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE `%1s` SET status = 'failed', error_message = 'Timeout - processing took too long', processed_at = NOW() WHERE status = 'processing' AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table_name
			)
		);

		// Reset pending items that are older than 10 minutes (probably stuck)
		$reset_result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE `%1s` SET attempts = 0, error_message = NULL WHERE status = 'pending' AND attempts > 0 AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table_name
			)
		);

		if ( $result > 0 || $reset_result > 0 ) {

		}

		return $result + $reset_result;
	}


	/**
	 * Filter title
	 */
	public function filterTitle( $title ) {
		return $this->translateContent( $title, 'title' );
	}

	/**
	 * Filter excerpt
	 */
	public function filterExcerpt( $excerpt ) {
		return $this->translateContent( $excerpt, 'excerpt' );
	}

	/**
	 * Filter wp_title
	 */
	public function filterWpTitle( $title ) {
		return $this->translateContent( $title, 'title' );
	}

	/**
	 * Filter document title parts
	 */
	public function filterDocumentTitle( $title_parts ) {
		if ( isset( $title_parts['title'] ) ) {
			$title_parts['title'] = $this->translateContent( $title_parts['title'], 'title' );
		}
		if ( isset( $title_parts['tagline'] ) ) {
			$title_parts['tagline'] = $this->translateContent( $title_parts['tagline'], 'tagline' );
		}
		return $title_parts;
	}

	/**
	 * Filter widget title
	 */
	public function filterWidgetTitle( $title ) {
		return $this->translateContent( $title, 'widget_title' );
	}

	/**
	 * Filter widget text
	 */
	public function filterWidgetText( $text ) {
		return $this->translateContent( $text, 'widget_text' );
	}

	/**
	 * Filter menu items
	 */
	public function filterMenuItems( $items, $args ) {
		// Ensure $items is an array before iterating
		if ( ! is_array( $items ) ) {
			return $items;
		}

		foreach ( $items as $item ) {
			if ( isset( $item->title ) ) {
				$item->title = $this->translateContent( $item->title, 'menu_item' );
			}
		}
		return $items;
	}

	/**
	 * Filter image attributes for ALT text translation
	 */
	public function filterImageAttributes( $attr, $attachment, $size ) {
		if ( isset( $attr['alt'] ) && ! empty( $attr['alt'] ) ) {
			$attr['alt'] = $this->translateContent( $attr['alt'], 'image_alt' );
		}
		if ( isset( $attr['title'] ) && ! empty( $attr['title'] ) ) {
			$attr['title'] = $this->translateContent( $attr['title'], 'image_title' );
		}
		return $attr;
	}

	/**
	 * Filter post meta for SEO fields
	 */
	public function filterPostMeta( $value, $object_id, $meta_key, $single ) {
		// Only translate specific SEO meta keys
		$seo_keys = array(
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_title',
			'rank_math_description',
			'rank_math_title',
			'_aioseop_description',
			'_aioseop_title',
		);

		// Return null to let WordPress handle the meta retrieval normally
		// We'll handle SEO meta translation through other hooks
		return null;
	}
}
