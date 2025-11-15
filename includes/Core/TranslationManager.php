<?php
namespace VoxforML\Core;

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
		// Re-enable translation hooks with memory protection

		add_filter( 'the_title', array( $this, 'filterTitle' ), 10 );
		add_filter( 'the_content', array( $this, 'filterContent' ), 10 );
		add_filter( 'wp_nav_menu_items', array( $this, 'filterMenuItems' ), 10, 2 );
		add_filter( 'document_title_parts', array( $this, 'filterDocumentTitle' ), 10 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filterImageAttributes' ), 10, 3 );

		// Additional filters for comprehensive translation
		add_filter( 'bloginfo', array( $this, 'filterBlogInfo' ), 10, 2 );
		add_filter( 'option_blogname', array( $this, 'filterSiteTitle' ), 10 );
		add_filter( 'option_blogdescription', array( $this, 'filterSiteTagline' ), 10 );
		add_filter( 'widget_text', array( $this, 'filterWidgetText' ), 10 );
		add_filter( 'widget_title', array( $this, 'filterWidgetTitle' ), 10 );

		// 🚀 ENHANCED FOOTER TRANSLATION: Hook into all possible footer content sources
		add_filter( 'widget_custom_html_content', array( $this, 'translateContent' ), 10 );
		add_filter( 'widget_text_content', array( $this, 'translateContent' ), 10 );
		add_filter( 'elementor/frontend/the_content', array( $this, 'translateContent' ), 10 );
		add_filter( 'elementor/widget/render_content', array( $this, 'translateContent' ), 10 );

		// 🎯 AGGRESSIVE FOOTER TRANSLATION: Hook into output buffer for footer content
		add_action( 'wp_footer', array( $this, 'startFooterTranslation' ), 1 );
		add_action( 'wp_footer', array( $this, 'endFooterTranslation' ), 999 );

		// Comprehensive text translation filters
		add_filter( 'gettext', array( $this, 'translateGettext' ), 10, 3 );
		add_filter( 'ngettext', array( $this, 'translateNgettext' ), 10, 5 );
		add_filter( 'gettext_with_context', array( $this, 'translateGettextWithContext' ), 10, 4 );
		add_filter( 'ngettext_with_context', array( $this, 'translateNgettextWithContext' ), 10, 6 );

		// Navigation and menu translation
		add_filter( 'wp_nav_menu_objects', array( $this, 'translateNavMenuObjects' ), 10, 2 );
		add_filter( 'wp_get_nav_menu_items', array( $this, 'translateNavMenuItems' ), 10, 3 );

		// Fix URLs to include language prefix
		add_filter( 'home_url', array( $this, 'filterHomeUrl' ), 10, 4 );
		add_filter( 'site_url', array( $this, 'filterSiteUrl' ), 10, 4 );
		add_filter( 'post_link', array( $this, 'filterPostLink' ), 10, 3 );
		add_filter( 'page_link', array( $this, 'filterPageLink' ), 10, 2 );
		add_filter( 'term_link', array( $this, 'filterTermLink' ), 10, 3 );
		add_filter( 'category_link', array( $this, 'filterCategoryLink' ), 10, 2 );
		add_filter( 'tag_link', array( $this, 'filterTagLink' ), 10, 2 );
		add_filter( 'author_link', array( $this, 'filterAuthorLink' ), 10, 2 );
		add_filter( 'get_permalink', array( $this, 'filterGetPermalink' ), 10, 2 );

		// Filter all internal links in content
		add_filter( 'the_content', array( $this, 'filterInternalLinks' ), 25 ); // After translation

		// 🎯 ENHANCED LINK FILTERING: Fix all internal links including logos
		add_action( 'wp_head', array( $this, 'fixLogoLinksInHead' ), 999 );
		add_action( 'wp_footer', array( $this, 'fixLogoLinksInFooter' ), 1 );

		// 🎯 CONTENT FILTERING: Fix logo links in all content output
		add_filter( 'wp_nav_menu', array( $this, 'fixLogoLinksInContent' ), 999 );
		add_filter( 'widget_text', array( $this, 'fixLogoLinksInContent' ), 999 );
		add_filter( 'widget_custom_html', array( $this, 'fixLogoLinksInContent' ), 999 );

		// 🚀 POST-SPECIFIC TRANSLATION: Handle comments and post elements
		add_filter( 'comment_text', array( $this, 'translateCommentText' ), 10, 2 );
		add_filter( 'get_comment_author', array( $this, 'translateCommentAuthor' ), 10, 2 );
		add_filter( 'get_the_author', array( $this, 'translateAuthorName' ), 10 );
		add_filter( 'get_the_author_description', array( $this, 'translateAuthorBio' ), 10 );
		add_filter( 'wp_list_categories', array( $this, 'translateCategoryList' ), 10 );
		add_filter( 'wp_tag_cloud', array( $this, 'translateTagCloud' ), 10 );

		// 🎯 LOGO URL FIX: Add filters for logo and custom link URLs
		add_filter( 'wp_get_attachment_url', array( $this, 'filterAttachmentUrl' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_url', array( $this, 'filterAttachmentImageUrl' ), 10, 3 );
		add_filter( 'get_custom_logo', array( $this, 'filterCustomLogo' ), 10, 2 );
		add_filter( 'theme_mod_custom_logo', array( $this, 'filterCustomLogoUrl' ), 10 );
	}

	/**
	 * Filter content for translation based on current language
	 */
	public function filterContent( $content ) {
		// NEVER translate in admin area
		if ( is_admin() ) {
			return $content;
		}

		if ( empty( $content ) ) {
			return $content;
		}

		// Get current language directly from router
		$router           = $this->plugin->getComponent( 'router' );
		$current_language = $router ? $router->getCurrentLanguage() : 'en';

		// Always return original content for English
		if ( $current_language === 'en' ) {

			return $content;
		}

		// For non-English languages, always attempt translation

		return $this->translateContent( $content, 'content' );
	}

	/**
	 * Translate content
	 */
	public function translateContent( $content, $context = 'content' ) {
		// NEVER translate in admin area - this is the master check
		if ( is_admin() ) {
			return $content;
		}

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

			// Translate processed content
			$translated = $this->translateText( $processed['text'], $context, $post_id );

			// Restore HTML structure
			$final_content = $this->processor->restoreContent( $translated, $processed['placeholders'] );

			// Translate image ALT texts within content
			$final_content = $this->translateImagesInContent( $final_content );

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
		// NEVER translate in admin area
		if ( is_admin() ) {
			return $items;
		}

		// Recursion protection
		if ( self::$translating_menu || self::$translation_depth > 3 ) {
			return $items;
		}

		// Ensure $items is iterable
		if ( ! is_array( $items ) && ! is_object( $items ) ) {
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
				if ( is_object( $item ) ) {
					// Translate menu item title
					if ( ! empty( $item->title ) ) {
						$item->title = $this->translateText( $item->title, 'menu_item', $item->ID ?? null );
					}

					// Translate menu item description if exists
					if ( ! empty( $item->description ) ) {
						$item->description = $this->translateText( $item->description, 'menu_description', $item->ID ?? null );
					}
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
	public function testTranslation( $text, $language, $context = 'test' ) {
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
			// Apply glossary rules first
			$processed_text = $this->processor->applyGlossary( $text, $language );

			// Try direct translation
			$translated = $this->translator->translate( $processed_text, $language, $context );

			if ( $translated ) {

				return $translated;
			} else {

				return $text;
			}
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
		// NEVER translate in admin area
		if ( is_admin() ) {
			return $text;
		}

		if ( empty( $text ) ) {
			return $text;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		// Don't translate English content
		if ( $this->current_language === 'en' ) {
			return $text;
		}

			// Check translation memory FIRST for performance
		$cached = $this->memory->getTranslation( $text, $this->current_language, $context, $post_id );
		if ( $cached !== false ) {

			// Apply glossary rules to cached translation
			return $this->processor->applyGlossary( $cached, $this->current_language );
		}

		// 🎯 ENHANCED FALLBACK: Try text_fragment context first (from individual translation)
		if ( $context !== 'text_fragment' ) {
			$fragment_cached = $this->memory->getTranslation( $text, $this->current_language, 'text_fragment', $post_id );
			if ( $fragment_cached !== false ) {

				return $this->processor->applyGlossary( $fragment_cached, $this->current_language );
			}
		}

		// If no translation found in memory, try different contexts
		$fallback_contexts = array( 'content', 'general', 'title', 'text' );
		foreach ( $fallback_contexts as $fallback_context ) {
			if ( $fallback_context !== $context ) {
				$fallback_cached = $this->memory->getTranslation( $text, $this->current_language, $fallback_context, $post_id );
				if ( $fallback_cached !== false ) {

					return $this->processor->applyGlossary( $fallback_cached, $this->current_language );
				}
			}
		}

		// Queue for translation if not in memory (only in admin)
		if ( ! apply_filters( 'voxfor_ml_disable_auto_queue', false ) && is_admin() ) {
			$this->memory->queueForTranslation( $text, $this->current_language, $context, $post_id );
		}

		// For immediate translation (synchronous mode)
		if ( $this->shouldTranslateImmediately() ) {
			$translated = $this->translator->translate( $text, $this->current_language, 'EN', $context );

			if ( $translated ) {
				$this->memory->saveTranslation( $text, $translated, $this->current_language, $context, $post_id );
				return $this->processor->applyGlossary( $translated, $this->current_language );
			}
		}

		// Return original text if no translation found
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
				$this->memory->saveTranslation( $to_translate[ $key ], $translated, $language, $context );
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
	 * Check if should translate immediately
	 */
	private function shouldTranslateImmediately() {
		// CRITICAL FIX: Visual editor mode should NOT trigger immediate translation
		// Visual editor handles translations via AJAX only, not during page load
		if ( isset( $_GET['voxfor_ml_edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false; // Never translate immediately in visual editor mode
		}

		// SAFETY CHECK: Never translate immediately on frontend for regular users
		// This prevents API consumption when users switch languages
		if ( ! is_admin() && ! wp_doing_ajax() ) {
			// Only allow immediate translation if explicitly requested via admin action
			if ( ! current_user_can( 'manage_options' ) ) {
				return false; // Regular users should never trigger immediate translation
			}
		}

		// In admin or during AJAX requests, translate immediately
		if ( is_admin() || wp_doing_ajax() ) {
			return true;
		}

		// For testing: translate immediately on frontend too (ONLY for admins)
		$immediate_setting = get_option( 'voxfor_ml_immediate_translation', false );

		if ( $immediate_setting && current_user_can( 'manage_options' ) ) {
			return true;
		}

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
						$item->post_id
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
			"
            UPDATE `{$wpdb->prefix}voxfor_ml_queue` 
            SET status = 'failed', 
                error_message = 'Timeout - processing took too long',
                processed_at = NOW()
            WHERE status = 'processing' 
            AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        "
		);

		// Reset pending items that are older than 10 minutes (probably stuck)
		$reset_result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"
            UPDATE `{$wpdb->prefix}voxfor_ml_queue` 
            SET attempts = 0,
                error_message = NULL
            WHERE status = 'pending' 
            AND attempts > 0
            AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        "
		);

		if ( $result > 0 || $reset_result > 0 ) {

		}

		return $result + $reset_result;
	}


	/**
	 * Filter title for translation
	 */
	public function filterTitle( $title ) {
		// NEVER translate in admin area
		if ( is_admin() ) {
			return $title;
		}

		if ( empty( $title ) ) {
			return $title;
		}

		// Get current language from router
		$router           = $this->plugin->getComponent( 'router' );
		$current_language = $router ? $router->getCurrentLanguage() : 'en';

		// Return original for English
		if ( $current_language === 'en' ) {

			return $title;
		}

		// For non-English languages, always attempt translation

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
		// NEVER translate in admin area
		if ( is_admin() ) {
			return $items;
		}

		// Check if $items is a string (HTML) or array/object
		if ( is_string( $items ) ) {
			// If it's HTML string, use regex to translate text within menu items
			return $this->translateMenuHtml( $items );
		}

		// If it's an array or object, iterate through items
		if ( is_array( $items ) || is_object( $items ) ) {
			foreach ( $items as $item ) {
				if ( is_object( $item ) && isset( $item->title ) ) {
					$item->title = $this->translateContent( $item->title, 'menu_item' );
				}
			}
		}

		return $items;
	}

	/**
	 * Translate menu HTML string
	 */
	private function translateMenuHtml( $html ) {
		// Use regex to find and translate menu item text
		$html = preg_replace_callback(
			'/<a[^>]*>([^<]+)<\/a>/u',
			function ( $matches ) {
				$text = trim( $matches[1] );
				if ( ! empty( $text ) ) {
					$translated = $this->translateContent( $text, 'menu_item' );
					return str_replace( $matches[1], $translated, $matches[0] );
				}
				return $matches[0];
			},
			$html
		);

		return $html;
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

	/**
	 * Filter blog info for translation
	 */
	public function filterBlogInfo( $output, $show ) {
		if ( in_array( $show, array( 'name', 'description' ) ) && $this->current_language !== 'en' ) {
			return $this->translateText( $output );
		}
		return $output;
	}

	/**
	 * Filter site title - DON'T translate site name per user request
	 */
	public function filterSiteTitle( $title ) {
		// User requested NOT to translate site title
		return $title;
	}

	/**
	 * Filter site tagline
	 */
	public function filterSiteTagline( $tagline ) {
		if ( $this->current_language !== 'en' ) {
			return $this->translateText( $tagline );
		}
		return $tagline;
	}





	/**
	 * Start footer translation output buffering
	 */
	public function startFooterTranslation() {
		if ( is_admin() ) {
			return;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		if ( $this->current_language !== 'en' ) {
			ob_start();
		}
	}

	/**
	 * End footer translation output buffering and translate content
	 */
	public function endFooterTranslation() {
		if ( is_admin() ) {
			return;
		}

		if ( $this->current_language !== 'en' && ob_get_level() > 0 ) {
			$footer_content = ob_get_clean();
			if ( ! empty( $footer_content ) ) {
				$translated_footer = $this->translateContent( $footer_content, 'footer' );
				echo wp_kses_post( $translated_footer );
			}
		}
	}

	/**
	 * Translate footer content
	 */
	public function translateFooterContent() {
		if ( $this->current_language !== 'en' ) {
			// Enqueue footer translator script
			wp_enqueue_script(
				'voxfor-ml-footer-translator',
				VOXFOR_ML_PLUGIN_URL . 'public/js/frontend/footer-translator.js',
				array(),
				VOXFOR_ML_VERSION,
				true
			);

			// Localize the script with current language data
			wp_localize_script(
				'voxfor-ml-footer-translator',
				'voxforFooterTranslator',
				array(
					'currentLanguage' => $this->current_language,
				)
			);
		}
	}

	/**
	 * Filter home URL to include language prefix
	 */
	public function filterHomeUrl( $url, $path, $orig_scheme, $blog_id ) {
		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		// Don't modify for English or admin area
		if ( $this->current_language === 'en' || is_admin() ) {
			return $url;
		}

		// 🎯 FIX: Handle logo URLs and home page links properly
		$parsed_url = wp_parse_url( $url );
		$base_url   = $parsed_url['scheme'] . '://' . $parsed_url['host'];
		if ( isset( $parsed_url['port'] ) ) {
			$base_url .= ':' . $parsed_url['port'];
		}

		// If no path or just root path, return language home URL
		if ( empty( $path ) || $path === '/' || trim( $path, '/' ) === '' ) {
			return $base_url . '/' . $this->current_language . '/';
		}

		// For other paths, check if they need language prefix
		if ( ! empty( $path ) && strpos( $path, $this->current_language . '/' ) !== 0 ) {
			return $base_url . '/' . $this->current_language . '/' . ltrim( $path, '/' );
		}

		return $url;
	}

	/**
	 * Filter site URL to include language prefix
	 */
	public function filterSiteUrl( $url, $path, $orig_scheme, $blog_id ) {
		// Only modify if we're not on English and no path is specified
		if ( $this->current_language !== 'en' && empty( $path ) ) {
			$parsed_url = wp_parse_url( $url );
			$base_url   = $parsed_url['scheme'] . '://' . $parsed_url['host'];
			if ( isset( $parsed_url['port'] ) ) {
				$base_url .= ':' . $parsed_url['port'];
			}
			return $base_url . '/' . $this->current_language . '/';
		}
		return $url;
	}

	/**
	 * 🎯 LOGO URL FILTERS: Fix logo links to include language prefix
	 */

	/**
	 * Filter custom logo to include proper home URL
	 */
	public function filterCustomLogo( $html, $blog_id ) {
		if ( is_admin() || $this->current_language === 'en' ) {
			return $html;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		if ( ! empty( $html ) && $this->current_language !== 'en' ) {
			// Replace home URL in logo HTML
			$home_url            = home_url( '/' );
			$translated_home_url = $this->getLanguageHomeUrl();

			// Replace href attributes pointing to home
			$html = str_replace( 'href="' . $home_url . '"', 'href="' . $translated_home_url . '"', $html );
			$html = str_replace( "href='" . $home_url . "'", "href='" . $translated_home_url . "'", $html );

			// Also handle relative URLs
			$html = str_replace( 'href="/"', 'href="' . $translated_home_url . '"', $html );
			$html = str_replace( "href='/'", "href='" . $translated_home_url . "'", $html );
		}

		return $html;
	}

	/**
	 * Filter attachment URLs (in case logo is an attachment)
	 */
	public function filterAttachmentUrl( $url, $attachment_id ) {
		// Don't modify attachment file URLs, only links TO attachments
		return $url;
	}

	/**
	 * Filter attachment image URLs
	 */
	public function filterAttachmentImageUrl( $url, $attachment_id, $size ) {
		// Don't modify image file URLs, only links TO images
		return $url;
	}

	/**
	 * Filter custom logo URL from theme customizer
	 */
	public function filterCustomLogoUrl( $logo_id ) {
		// This just returns the logo ID, not the URL
		return $logo_id;
	}

	/**
	 * Get language-specific home URL
	 */
	private function getLanguageHomeUrl() {
		$home_url = home_url( '/' );

		if ( $this->current_language !== 'en' ) {
			$parsed_url = wp_parse_url( $home_url );
			$base_url   = $parsed_url['scheme'] . '://' . $parsed_url['host'];
			if ( isset( $parsed_url['port'] ) ) {
				$base_url .= ':' . $parsed_url['port'];
			}
			return $base_url . '/' . $this->current_language . '/';
		}

		return $home_url;
	}

	/**
	 * Fix logo links in head (JavaScript approach)
	 */
	public function fixLogoLinksInHead() {
		if ( is_admin() || $this->current_language === 'en' ) {
			return;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		$translated_home_url = $this->getLanguageHomeUrl();
		$original_home_url   = home_url( '/' );

		// Enqueue logo link fixer script
		wp_enqueue_script(
			'voxfor-ml-logo-fixer',
			VOXFOR_ML_PLUGIN_URL . 'public/js/frontend/logo-link-fixer.js',
			array(),
			VOXFOR_ML_VERSION,
			true
		);

		// Localize the script with URL data
		wp_localize_script(
			'voxfor-ml-logo-fixer',
			'voxforLogoFixer',
			array(
				'homeUrl'          => $original_home_url,
				'translatedHomeUrl' => $translated_home_url,
				'debug'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
			)
		);
	}

	/**
	 * Fix logo links in footer (backup approach)
	 */
	public function fixLogoLinksInFooter() {
		if ( is_admin() || $this->current_language === 'en' ) {
			return;
		}

		// This is a backup - the head script should handle most cases
		// But we can add additional fixes here if needed
	}

	/**
	 * Fix logo links in any content output
	 */
	public function fixLogoLinksInContent( $content ) {
		if ( is_admin() || $this->current_language === 'en' || empty( $content ) ) {
			return $content;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		$original_home_url   = home_url( '/' );
		$translated_home_url = $this->getLanguageHomeUrl();

		// Replace home URLs in content
		$content = str_replace( 'href="' . $original_home_url . '"', 'href="' . $translated_home_url . '"', $content );
		$content = str_replace( "href='" . $original_home_url . "'", "href='" . $translated_home_url . "'", $content );

		// Replace relative URLs
		$content = str_replace( 'href="/"', 'href="' . $translated_home_url . '"', $content );
		$content = str_replace( "href='/'", "href='" . $translated_home_url . "'", $content );

		return $content;
	}

	/**
	 * 🚀 POST-SPECIFIC TRANSLATION METHODS
	 */

	/**
	 * Translate comment text
	 */
	public function translateCommentText( $comment_text, $comment ) {
		if ( is_admin() || $this->current_language === 'en' ) {
			return $comment_text;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		if ( ! empty( $comment_text ) ) {
			return $this->translateText( $comment_text, 'comment_content', $comment->comment_post_ID );
		}

		return $comment_text;
	}

	/**
	 * Translate comment author name
	 */
	public function translateCommentAuthor( $author, $comment_id ) {
		if ( is_admin() || $this->current_language === 'en' ) {
			return $author;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		// Only translate if it looks like a real name (not username)
		if ( ! empty( $author ) && strlen( $author ) >= 3 && ! preg_match( '/^[a-z0-9_]+$/i', $author ) ) {
			return $this->translateText( $author, 'comment_author' );
		}

		return $author;
	}

	/**
	 * Translate author name
	 */
	public function translateAuthorName( $author_name ) {
		if ( is_admin() || $this->current_language === 'en' ) {
			return $author_name;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		// Only translate if it looks like a real name (not username)
		if ( ! empty( $author_name ) && strlen( $author_name ) >= 3 && ! preg_match( '/^[a-z0-9_]+$/i', $author_name ) ) {
			return $this->translateText( $author_name, 'author_name' );
		}

		return $author_name;
	}

	/**
	 * Translate author bio/description
	 */
	public function translateAuthorBio( $author_bio ) {
		if ( is_admin() || $this->current_language === 'en' ) {
			return $author_bio;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		if ( ! empty( $author_bio ) ) {
			return $this->translateText( $author_bio, 'author_bio' );
		}

		return $author_bio;
	}

	/**
	 * Translate category list
	 */
	public function translateCategoryList( $output ) {
		if ( is_admin() || $this->current_language === 'en' || empty( $output ) ) {
			return $output;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		// Use regex to find and translate category names in the HTML
		$output = preg_replace_callback(
			'/<a[^>]*>([^<]+)<\/a>/',
			function ( $matches ) {
				$category_name = trim( $matches[1] );
				if ( ! empty( $category_name ) && strlen( $category_name ) >= 2 ) {
					$translated = $this->translateText( $category_name, 'category_name' );
					return str_replace( $matches[1], $translated, $matches[0] );
				}
				return $matches[0];
			},
			$output
		);

		return $output;
	}

	/**
	 * Translate tag cloud
	 */
	public function translateTagCloud( $output ) {
		if ( is_admin() || $this->current_language === 'en' || empty( $output ) ) {
			return $output;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		// Use regex to find and translate tag names in the HTML
		$output = preg_replace_callback(
			'/<a[^>]*>([^<]+)<\/a>/',
			function ( $matches ) {
				$tag_name = trim( $matches[1] );
				if ( ! empty( $tag_name ) && strlen( $tag_name ) >= 2 ) {
					$translated = $this->translateText( $tag_name, 'tag_name' );
					return str_replace( $matches[1], $translated, $matches[0] );
				}
				return $matches[0];
			},
			$output
		);

		return $output;
	}

	/**
	 * Translate gettext strings - THIS IS THE MAIN FUNCTION FOR ALL TEXT TRANSLATION
	 */
	public function translateGettext( $translation, $text, $domain ) {
		// NEVER translate in admin area
		if ( is_admin() ) {
			return $translation;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		if ( $this->current_language === 'en' || empty( $text ) || strlen( $text ) < 2 ) {
			return $translation;
		}

		// Skip if already translated or is a technical string
		if ( $translation !== $text || $this->isSkippableString( $text ) ) {
			return $translation;
		}

		// Use DeepL to translate the text
		return $this->translateText( $text );
	}

	/**
	 * Translate ngettext strings (plural forms)
	 */
	public function translateNgettext( $translation, $single, $plural, $number, $domain ) {
		// NEVER translate in admin area
		if ( is_admin() ) {
			return $translation;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		if ( $this->current_language === 'en' ) {
			return $translation;
		}

		$text_to_translate = ( $number == 1 ) ? $single : $plural;
		if ( empty( $text_to_translate ) || $this->isSkippableString( $text_to_translate ) ) {
			return $translation;
		}

		return $this->translateText( $text_to_translate );
	}

	/**
	 * Translate gettext with context
	 */
	public function translateGettextWithContext( $translation, $text, $context, $domain ) {
		// NEVER translate in admin area
		if ( is_admin() ) {
			return $translation;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		if ( $this->current_language === 'en' || empty( $text ) || $this->isSkippableString( $text ) ) {
			return $translation;
		}

		return $this->translateText( $text );
	}

	/**
	 * Translate ngettext with context
	 */
	public function translateNgettextWithContext( $translation, $single, $plural, $number, $context, $domain ) {
		// NEVER translate in admin area
		if ( is_admin() ) {
			return $translation;
		}

		// Ensure current language is set
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		if ( $this->current_language === 'en' ) {
			return $translation;
		}

		$text_to_translate = ( $number == 1 ) ? $single : $plural;
		if ( empty( $text_to_translate ) || $this->isSkippableString( $text_to_translate ) ) {
			return $translation;
		}

		return $this->translateText( $text_to_translate );
	}

	/**
	 * Translate navigation menu objects
	 */
	public function translateNavMenuObjects( $items, $args ) {
		// NEVER translate in admin area
		if ( is_admin() ) {
			return $items;
		}

		if ( $this->current_language === 'en' ) {
			return $items;
		}

		// Ensure $items is iterable
		if ( ! is_array( $items ) && ! is_object( $items ) ) {
			return $items;
		}

		foreach ( $items as $item ) {
			if ( is_object( $item ) ) {
				if ( ! empty( $item->title ) ) {
					$item->title = $this->translateText( $item->title );
				}
				if ( ! empty( $item->attr_title ) ) {
					$item->attr_title = $this->translateText( $item->attr_title );
				}
				if ( ! empty( $item->description ) ) {
					$item->description = $this->translateText( $item->description );
				}
			}
		}

		return $items;
	}

	/**
	 * Translate navigation menu items
	 */
	public function translateNavMenuItems( $items, $menu, $args ) {
		// NEVER translate in admin area
		if ( is_admin() ) {
			return $items;
		}

		if ( $this->current_language === 'en' ) {
			return $items;
		}

		// Ensure $items is iterable
		if ( ! is_array( $items ) && ! is_object( $items ) ) {
			return $items;
		}

		foreach ( $items as $item ) {
			if ( is_object( $item ) ) {
				if ( ! empty( $item->title ) ) {
					$item->title = $this->translateText( $item->title );
				}
				if ( ! empty( $item->attr_title ) ) {
					$item->attr_title = $this->translateText( $item->attr_title );
				}
				if ( ! empty( $item->description ) ) {
					$item->description = $this->translateText( $item->description );
				}
			}
		}

		return $items;
	}

	/**
	 * Check if string should be skipped from translation
	 */
	private function isSkippableString( $text ) {
		// Skip technical strings, URLs, numbers, etc.
		$skip_patterns = array(
			'/^https?:\/\//',           // URLs
			'/^\d+$/',                  // Pure numbers
			'/^[A-Z_]+$/',             // Constants
			'/^wp-/',                   // WordPress technical prefixes
			'/^admin-/',                // Admin technical prefixes
			'/^\s*$/',                  // Empty or whitespace only
			'/^[^\w\s]+$/',            // Only special characters
			'/^[a-z0-9_-]+\.(php|js|css|png|jpg|gif)$/i', // File names
		);

		foreach ( $skip_patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		// Skip very short strings (less than 3 characters)
		if ( strlen( trim( $text ) ) < 3 ) {
			return true;
		}

		return false;
	}

	/**
	 * Filter post links to include language prefix
	 */
	public function filterPostLink( $permalink, $post, $leavename ) {
		return $this->addLanguagePrefix( $permalink );
	}

	/**
	 * Filter page links to include language prefix
	 */
	public function filterPageLink( $permalink, $post_id ) {
		return $this->addLanguagePrefix( $permalink );
	}

	/**
	 * Filter term links to include language prefix
	 */
	public function filterTermLink( $termlink, $term, $taxonomy ) {
		return $this->addLanguagePrefix( $termlink );
	}

	/**
	 * Filter category links to include language prefix
	 */
	public function filterCategoryLink( $catlink, $category_id ) {
		return $this->addLanguagePrefix( $catlink );
	}

	/**
	 * Filter tag links to include language prefix
	 */
	public function filterTagLink( $taglink, $tag_id ) {
		return $this->addLanguagePrefix( $taglink );
	}

	/**
	 * Filter author links to include language prefix
	 */
	public function filterAuthorLink( $link, $author_id ) {
		return $this->addLanguagePrefix( $link );
	}

	/**
	 * Filter get_permalink to include language prefix
	 */
	public function filterGetPermalink( $permalink, $post ) {
		return $this->addLanguagePrefix( $permalink );
	}

	/**
	 * Filter internal links in content
	 */
	public function filterInternalLinks( $content ) {
		if ( $this->current_language === 'en' ) {
			return $content;
		}

		// Get site URL
		$site_url    = get_site_url();
		$parsed_site = wp_parse_url( $site_url );
		$site_domain = $parsed_site['host'];

		// Pattern to match internal links
		$pattern = '/href=["\']([^"\']*' . preg_quote( $site_domain, '/' ) . '[^"\']*)["\']/i';

		$content = preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$url = $matches[1];

				// Skip if already has language prefix
				if ( preg_match( '/\/' . $this->current_language . '\//', $url ) ) {
					return $matches[0];
				}

				// Add language prefix
				$new_url = $this->addLanguagePrefix( $url );
				return str_replace( $matches[1], $new_url, $matches[0] );
			},
			$content
		);

		return $content;
	}

	/**
	 * Add language prefix to URL
	 */
	private function addLanguagePrefix( $url ) {
		if ( $this->current_language === 'en' || empty( $url ) ) {
			return $url;
		}

		// Skip external URLs
		$site_url = get_site_url();
		if ( ! empty( $url ) && strpos( $url, $site_url ) !== 0 && ! preg_match( '/^\//', $url ) ) {
			return $url;
		}

		// Skip if already has language prefix
		if ( preg_match( '/\/' . $this->current_language . '\//', $url ) ) {
			return $url;
		}

		$parsed_url = wp_parse_url( $url );
		if ( ! $parsed_url ) {
			return $url;
		}

		$base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
		if ( isset( $parsed_url['port'] ) ) {
			$base_url .= ':' . $parsed_url['port'];
		}

		$path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/';

		// Add language prefix to path
		if ( $path === '/' || $path === '' ) {
			$new_path = '/' . $this->current_language . '/';
		} else {
			$new_path = '/' . $this->current_language . $path;
		}

		$new_url = $base_url . $new_path;

		// Add query string and fragment if they exist
		if ( isset( $parsed_url['query'] ) ) {
			$new_url .= '?' . $parsed_url['query'];
		}
		if ( isset( $parsed_url['fragment'] ) ) {
			$new_url .= '#' . $parsed_url['fragment'];
		}

		return $new_url;
	}
}