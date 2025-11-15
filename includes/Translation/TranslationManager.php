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

			$translated = $this->translator->translate( $processed_text, $language, 'en', $context );

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
	if ( empty( $text ) ) {
		return $text;
	}

	// Store original text for glossary application
	$original_text = $text;

	// Check translation memory first (without glossary applied to source)
	$cached = $this->memory->getTranslation( $original_text, $this->current_language, $context );
	if ( $cached !== false ) {
		// Apply glossary rules to cached translation
		return $this->processor->applyGlossary( $cached, $this->current_language );
	}

		// Queue for translation if not in memory (unless disabled)
		// IMPORTANT: Never auto-queue on frontend - only in admin
		if ( ! apply_filters( 'voxfor_ml_disable_auto_queue', false ) && is_admin() ) {
			$this->memory->queueForTranslation( $original_text, $this->current_language, $context, $post_id );
		}

		// CRITICAL SAFETY CHECK: Never make API calls from language switcher
		// Language switcher should only display pre-translated content
		if ( isset( $_SERVER['HTTP_REFERER'] ) && ! is_admin() && ! wp_doing_ajax() ) {
			// Apply glossary to original text if no translation available
			return $this->processor->applyGlossary( $original_text, $this->current_language );
		}

		// For immediate translation (synchronous mode)
		if ( $this->shouldTranslateImmediately() ) {
			$translated = $this->translator->translate( $original_text, $this->current_language, 'EN', $context );

			if ( $translated ) {
				// Save original translation to memory (without glossary)
				$this->memory->saveTranslation( $original_text, $translated, $this->current_language, $context, $post_id );

				// Apply glossary rules to the translated text before returning
				return $this->processor->applyGlossary( $translated, $this->current_language );
			} else {

			}
		} else {

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
				$this->memory->saveTranslation( $to_translate[ $key ], $translated, $language, $context );
			}
		}

		return $translations;
	}

	/**
	 * Check if content should be translated
	 */
	private function shouldTranslate( $post_id = null ) {
		// Performance optimization: Skip if current language is English
		if ( $this->current_language === 'en' ) {
			return false;
		}

		// Check if post is excluded
		if ( $post_id && get_post_meta( $post_id, '_voxfor_ml_exclude', true ) ) {
			return false;
		}

		// Check if current URL is excluded
		$current_url = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
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
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
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
