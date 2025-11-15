<?php
namespace VoxforML\SEO;

use VoxforML\Core\Plugin;

/**
 * Advanced Image SEO Manager with ALT text translation and optimization
 * Focus on SEO improvements including image ALT as requested by user
 */
class ImageSEOManager {
	private $plugin;
	private static $processing_images     = array();
	private static $translation_cache     = array();
	private $max_translations_per_request = 20;
	private $current_translations         = 0;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin = Plugin::getInstance();
		$this->init();
	}

	/**
	 * Initialize image SEO hooks
	 */
	public function init() {
		// Image ALT text translation
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'translateImageAttributes' ), 10, 3 );
		add_filter( 'get_post_metadata', array( $this, 'translateImageMetadata' ), 10, 4 );
		
		// Handle Elementor and other content-based image alt text
		add_filter( 'the_content', array( $this, 'translateImageAltInContent' ), 15 );

		// Image optimization for SEO
		add_filter( 'wp_get_attachment_image_src', array( $this, 'optimizeImageForSEO' ), 10, 4 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'optimizeImageSrcset' ), 10, 5 );

		// Structured data for images
		add_action( 'wp_head', array( $this, 'addImageStructuredData' ) );

		// Image sitemap integration
		add_filter( 'voxfor_ml_sitemap_images', array( $this, 'addTranslatedImageData' ), 10, 2 );

		// Admin hooks for image management
		add_action( 'add_attachment', array( $this, 'processNewAttachment' ) );
		add_action( 'edit_attachment', array( $this, 'processUpdatedAttachment' ) );

		// Bulk image ALT translation
		add_action( 'wp_ajax_voxfor_ml_bulk_translate_images', array( $this, 'bulkTranslateImages' ) );

		// Image lazy loading with SEO considerations
		add_filter( 'wp_img_tag_add_loading_attr', array( $this, 'optimizeImageLoading' ), 10, 3 );
	}

	/**
	 * Translate image attributes including ALT text
	 */
	public function translateImageAttributes( $attr, $attachment, $size ) {

		// Memory protection: prevent recursion and limit translations
		if ( $this->current_translations >= $this->max_translations_per_request ) {
			return $attr;
		}

		if ( ! get_option( 'voxfor_ml_translate_image_alt', true ) ) {
			return $attr;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();


		if ( $current_lang === $default_lang ) {
			return $attr;
		}

		// Get attachment ID (handle both object and ID)
		$attachment_id = is_object( $attachment ) ? $attachment->ID : $attachment;
		
		
		// Prevent processing same image multiple times
		$image_key = $attachment_id . '_' . $current_lang;
		if ( isset( self::$processing_images[ $image_key ] ) ) {
			return $attr;
		}

		self::$processing_images[ $image_key ] = true;

		try {
			// Translate ALT text
			if ( ! empty( $attr['alt'] ) ) {
				$translated_alt = $this->getTranslatedImageAlt( $attachment_id, $attr['alt'], $current_lang );
				if ( $translated_alt ) {
					$attr['alt'] = $translated_alt;
					++$this->current_translations;
				}
			}

			// Translate title attribute (only if we haven't hit limit)
			if ( ! empty( $attr['title'] ) && $this->current_translations < $this->max_translations_per_request ) {
				$translated_title = $this->getTranslatedImageTitle( $attachment_id, $attr['title'], $current_lang );
				if ( $translated_title ) {
					$attr['title'] = $translated_title;
					++$this->current_translations;
				}
			}

			// Add language-specific classes for styling
			$attr['class'] = ( isset( $attr['class'] ) ? $attr['class'] . ' ' : '' ) . 'voxfor-ml-lang-' . $current_lang;

			// Add data attributes for SEO
			$attr['data-lang'] = $current_lang;
			if ( $current_lang !== $default_lang ) {
				$attr['data-translated'] = 'true';
			}
		} finally {
			unset( self::$processing_images[ $image_key ] );
		}


		return $attr;
	}

	/**
	 * Translate image alt text within content (for Elementor and other content-based images)
	 */
	public function translateImageAltInContent( $content ) {
		// Skip if not needed
		if ( empty( $content ) || is_admin() ) {
			return $content;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		// Skip if on default language or translation disabled
		if ( $current_lang === $default_lang || ! get_option( 'voxfor_ml_translate_image_alt', true ) ) {
			return $content;
		}

		// Memory protection
		if ( $this->current_translations >= $this->max_translations_per_request ) {
			return $content;
		}


		// Find and translate alt text in img tags
		$content = preg_replace_callback(
			'/<img([^>]*?)alt=["\']([^"\']*)["\']([^>]*?)>/i',
			array( $this, 'translateImageAltCallback' ),
			$content
		);

		return $content;
	}

	/**
	 * Callback for translating image alt text in content
	 */
	private function translateImageAltCallback( $matches ) {
		$before_alt = $matches[1];
		$alt_text   = $matches[2];
		$after_alt  = $matches[3];

		// Skip empty alt text
		if ( empty( trim( $alt_text ) ) ) {
			return $matches[0];
		}

		// Memory protection
		if ( $this->current_translations >= $this->max_translations_per_request ) {
			return $matches[0];
		}

		$current_lang = $this->plugin->getCurrentLanguage();


		// Try to get attachment ID from the img tag
		$attachment_id = null;
		if ( preg_match( '/wp-image-(\d+)/', $matches[0], $id_matches ) ) {
			$attachment_id = intval( $id_matches[1] );
		}

		// Get translated alt text - try multiple contexts
		$translated_alt = $this->getTranslatedImageAltFromContent( $alt_text, $current_lang, $attachment_id );

		if ( $translated_alt && $translated_alt !== $alt_text ) {
			++$this->current_translations;
			return '<img' . $before_alt . 'alt="' . esc_attr( $translated_alt ) . '"' . $after_alt . '>';
		}


		return $matches[0];
	}

	/**
	 * Get translated image alt text from content with multiple context fallbacks
	 */
	private function getTranslatedImageAltFromContent( $alt_text, $target_lang, $attachment_id = null ) {
		$memory = $this->plugin->getComponent( 'translation_memory' );
		if ( ! $memory ) {
			return null;
		}

		// Try multiple contexts in order of preference
		$contexts = array(
			'image_alt',
			'image_alt_inline',
			'content',
			'text_fragment'
		);

		foreach ( $contexts as $context ) {
			$translated = $memory->getTranslation( $alt_text, $target_lang, $context, $attachment_id );
			if ( $translated && $translated !== $alt_text ) {
				return $translated;
			}
		}

		return null;
	}

	/**
	 * Translate image metadata
	 */
	public function translateImageMetadata( $value, $object_id, $meta_key, $single ) {
		// Memory protection: limit metadata translations
		if ( $this->current_translations >= $this->max_translations_per_request ) {
			return $value;
		}

		if ( $meta_key !== '_wp_attachment_image_alt' ) {
			return $value;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $value;
		}

		// Prevent recursion
		$meta_key_check = $object_id . '_' . $meta_key . '_' . $current_lang;
		if ( isset( self::$processing_images[ $meta_key_check ] ) ) {
			return $value;
		}

		self::$processing_images[ $meta_key_check ] = true;

		try {
			// Get original ALT text
			$original_alt = get_post_meta( $object_id, '_wp_attachment_image_alt', true );
			if ( ! $original_alt ) {
				return $value;
			}

			// Get translated ALT text
			$translated_alt = $this->getTranslatedImageAlt( $object_id, $original_alt, $current_lang );
			if ( $translated_alt ) {
				++$this->current_translations;
				return $single ? $translated_alt : array( $translated_alt );
			}
		} finally {
			unset( self::$processing_images[ $meta_key_check ] );
		}

		return $value;
	}

	/**
	 * Get translated image ALT text
	 */
	private function getTranslatedImageAlt( $attachment_id, $original_alt, $target_lang ) {

		// Memory protection: check static cache first
		$static_cache_key = $attachment_id . '_' . $target_lang;
		if ( isset( self::$translation_cache[ $static_cache_key ] ) ) {
			return self::$translation_cache[ $static_cache_key ];
		}

		// Check cache first
		$cache_key  = "voxfor_ml_alt_{$attachment_id}_{$target_lang}";
		$cached_alt = wp_cache_get( $cache_key, 'voxfor_ml_images' );

		if ( $cached_alt !== false ) {
			self::$translation_cache[ $static_cache_key ] = $cached_alt;
			return $cached_alt;
		}

		// Check database
		$stored_alt = get_post_meta( $attachment_id, '_voxfor_ml_alt_' . $target_lang, true );
		if ( $stored_alt ) {
			wp_cache_set( $cache_key, $stored_alt, 'voxfor_ml_images', 3600 );
			self::$translation_cache[ $static_cache_key ] = $stored_alt;
			return $stored_alt;
		}


		// Memory protection: limit new translations
		if ( $this->current_translations >= $this->max_translations_per_request ) {
			return null;
		}

		// Translate if not found
		$translator = $this->plugin->getComponent( 'translator' );
		if ( ! $translator ) {
			return null;
		}


		$translated_alt = $translator->translateText(
			$original_alt,
			'image_alt',
			$attachment_id
		);


		if ( $translated_alt && $translated_alt !== $original_alt ) {
			// Store translation
			update_post_meta( $attachment_id, '_voxfor_ml_alt_' . $target_lang, $translated_alt );
			wp_cache_set( $cache_key, $translated_alt, 'voxfor_ml_images', 3600 );

			return $translated_alt;
		}

		return null;
	}

	/**
	 * Get translated image title
	 */
	private function getTranslatedImageTitle( $attachment_id, $original_title, $target_lang ) {
		// Check cache first
		$cache_key    = "voxfor_ml_title_{$attachment_id}_{$target_lang}";
		$cached_title = wp_cache_get( $cache_key, 'voxfor_ml_images' );

		if ( $cached_title !== false ) {
			return $cached_title;
		}

		// Check database
		$stored_title = get_post_meta( $attachment_id, '_voxfor_ml_title_' . $target_lang, true );
		if ( $stored_title ) {
			wp_cache_set( $cache_key, $stored_title, 'voxfor_ml_images', 3600 );
			return $stored_title;
		}

		// Translate if not found
		$translator = $this->plugin->getComponent( 'translator' );
		if ( ! $translator ) {
			return null;
		}

		$translated_title = $translator->translateText(
			$original_title,
			'image_title',
			$attachment_id
		);

		if ( $translated_title && $translated_title !== $original_title ) {
			// Store translation
			update_post_meta( $attachment_id, '_voxfor_ml_title_' . $target_lang, $translated_title );
			wp_cache_set( $cache_key, $translated_title, 'voxfor_ml_images', 3600 );

			return $translated_title;
		}

		return null;
	}

	/**
	 * Optimize image for SEO
	 */
	public function optimizeImageForSEO( $image, $attachment_id, $size, $icon ) {
		if ( ! $image ) {
			return $image;
		}

		// Add language-specific image optimization
		$current_lang = $this->plugin->getCurrentLanguage();

		// Log image usage for analytics
		$this->logImageUsage( $attachment_id, $current_lang, $size );

		return $image;
	}

	/**
	 * Optimize image srcset for multilingual SEO
	 */
	public function optimizeImageSrcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		$current_lang = $this->plugin->getCurrentLanguage();

		// Add language parameter to image URLs for CDN optimization
		if ( get_option( 'voxfor_ml_add_lang_to_images', false ) ) {
			foreach ( $sources as &$source ) {
				$source['url'] = add_query_arg( 'lang', $current_lang, $source['url'] );
			}
		}

		return $sources;
	}

	/**
	 * Add structured data for images
	 */
	public function addImageStructuredData() {
		if ( ! is_single() && ! is_page() ) {
			return;
		}

		global $post;
		$current_lang = $this->plugin->getCurrentLanguage();

		// Get all images in post content
		$images = $this->extractImagesFromContent( $post->post_content );

		if ( empty( $images ) ) {
			return;
		}

		$structured_data = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'ImageGallery',
			'mainEntity' => array(),
		);

		foreach ( $images as $image ) {
			$image_data = array(
				'@type'      => 'ImageObject',
				'url'        => $image['src'],
				'contentUrl' => $image['src'],
			);

			if ( ! empty( $image['alt'] ) ) {
				$image_data['name']          = $image['alt'];
				$image_data['alternateName'] = $image['alt'];
			}

			if ( ! empty( $image['caption'] ) ) {
				$image_data['caption'] = $image['caption'];
			}

			// Add language information (use display language for SEO)
			$display_lang             = ( $current_lang === 'en' ) ? get_option( 'voxfor_ml_display_prefix', 'en' ) : $current_lang;
			$image_data['inLanguage'] = $display_lang;

			$structured_data['mainEntity'][] = $image_data;
		}

		if ( ! empty( $structured_data['mainEntity'] ) ) {
			// Use default wp_json_encode escaping for security (search engines handle escaped JSON properly)
			wp_print_inline_script_tag(
				wp_json_encode( $structured_data ),
				array( 'type' => 'application/ld+json' )
			);
		}
	}

	/**
	 * Extract images from content
	 */
	private function extractImagesFromContent( $content ) {
		$images = array();

		// Find all img tags
		preg_match_all( '/<img[^>]+>/i', $content, $matches );

		foreach ( $matches[0] as $img_tag ) {
			$image = array();

			// Extract src
			if ( preg_match( '/src=["\']([^"\']+)["\']/', $img_tag, $src_matches ) ) {
				$image['src'] = $src_matches[1];
			}

			// Extract alt
			if ( preg_match( '/alt=["\']([^"\']*)["\']/', $img_tag, $alt_matches ) ) {
				$image['alt'] = $alt_matches[1];
			}

			// Extract title
			if ( preg_match( '/title=["\']([^"\']*)["\']/', $img_tag, $title_matches ) ) {
				$image['title'] = $title_matches[1];
			}

			if ( ! empty( $image['src'] ) ) {
				$images[] = $image;
			}
		}

		return $images;
	}

	/**
	 * Add translated image data to sitemap
	 */
	public function addTranslatedImageData( $images, $post_id ) {
		$current_lang = $this->plugin->getCurrentLanguage();

		foreach ( $images as &$image ) {
			if ( ! empty( $image['alt'] ) ) {
				$translated_alt = $this->getTranslatedImageAlt( $image['attachment_id'], $image['alt'], $current_lang );
				if ( $translated_alt ) {
					$image['alt'] = $translated_alt;
				}
			}

			if ( ! empty( $image['caption'] ) ) {
				$translator = $this->plugin->getComponent( 'translator' );
				if ( $translator ) {
					$translated_caption = $translator->translateText(
						$image['caption'],
						'image_caption'
					);
					if ( $translated_caption ) {
						$image['caption'] = $translated_caption;
					}
				}
			}
		}

		return $images;
	}

	/**
	 * Process new attachment
	 */
	public function processNewAttachment( $attachment_id ) {
		// Auto-generate ALT text if missing
		$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		if ( empty( $alt_text ) ) {
			$attachment = get_post( $attachment_id );
			if ( $attachment ) {
				// Use filename as fallback ALT text
				$filename = basename( $attachment->guid );
				$auto_alt = $this->generateAltFromFilename( $filename );

				if ( $auto_alt ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', $auto_alt );
				}
			}
		}

		// Schedule translation for enabled languages
		$this->scheduleImageTranslation( $attachment_id );
	}

	/**
	 * Process updated attachment
	 */
	public function processUpdatedAttachment( $attachment_id ) {
		// Clear translation cache
		$enabled_languages = get_option( 'voxfor_ml_languages', array( 'en' ) );
		// Ensure $enabled_languages is always an array
		if ( ! is_array( $enabled_languages ) ) {
			$enabled_languages = array( 'en' );
		}

		foreach ( $enabled_languages as $lang ) {
			wp_cache_delete( "voxfor_ml_alt_{$attachment_id}_{$lang}", 'voxfor_ml_images' );
			wp_cache_delete( "voxfor_ml_title_{$attachment_id}_{$lang}", 'voxfor_ml_images' );
		}

		// Re-schedule translation
		$this->scheduleImageTranslation( $attachment_id );
	}

	/**
	 * Generate ALT text from filename
	 */
	private function generateAltFromFilename( $filename ) {
		// Remove extension
		$name = pathinfo( $filename, PATHINFO_FILENAME );

		// Replace common separators with spaces
		$name = str_replace( array( '-', '_', '.' ), ' ', $name );

		// Remove numbers and common suffixes
		$name = preg_replace( '/\d+/', '', $name );
		$name = preg_replace( '/\b(img|image|photo|pic|picture)\b/i', '', $name );

		// Clean up spaces
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );

		// Capitalize first letter
		$name = ucfirst( $name );

		return ! empty( $name ) ? $name : null;
	}

	/**
	 * Schedule image translation
	 */
	private function scheduleImageTranslation( $attachment_id ) {
		if ( ! get_option( 'voxfor_ml_auto_translate_images', true ) ) {
			return;
		}

		// Use WordPress cron to translate images in background
		wp_schedule_single_event( time() + 60, 'voxfor_ml_translate_image', array( $attachment_id ) );
	}

	/**
	 * Bulk translate images
	 */
	public function bulkTranslateImages() {
		check_ajax_referer( 'voxfor_ml_bulk_translate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'voxfor-multilanguage' ) );
		}

		$page        = intval( $_POST['page'] ?? 1 );
		$per_page    = 20;
		$target_lang = sanitize_text_field( wp_unslash( $_POST['target_lang'] ?? 'fr' ) );

		// Get attachments
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_wp_attachment_image_alt',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		$translated = 0;
		$errors     = array();

		foreach ( $attachments as $attachment ) {
			$alt_text = get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );

			if ( $alt_text ) {
				$translated_alt = $this->getTranslatedImageAlt( $attachment->ID, $alt_text, $target_lang );

				if ( $translated_alt ) {
					++$translated;
				} else {
					$errors[] = $attachment->ID;
				}
			}
		}

		wp_send_json_success(
			array(
				'translated' => $translated,
				'errors'     => count( $errors ),
				'has_more'   => count( $attachments ) === $per_page,
				'next_page'  => $page + 1,
			)
		);
	}

	/**
	 * Optimize image loading for SEO
	 */
	public function optimizeImageLoading( $loading_attr, $tag_name, $attr ) {
		// Don't lazy load above-the-fold images for better SEO
		if ( isset( $attr['class'] ) && strpos( $attr['class'], 'hero-image' ) !== false ) {
			return false;
		}

		if ( isset( $attr['class'] ) && strpos( $attr['class'], 'featured-image' ) !== false ) {
			return false;
		}

		return $loading_attr;
	}

	/**
	 * Log image usage for analytics
	 */
	private function logImageUsage( $attachment_id, $language, $size ) {
		// Simple usage logging for analytics
		$usage_key     = "voxfor_ml_image_usage_{$attachment_id}_{$language}";
		$current_count = get_transient( $usage_key ) ?: 0;
		set_transient( $usage_key, $current_count + 1, DAY_IN_SECONDS );
	}

	/**
	 * Get image translation statistics
	 */
	public function getImageTranslationStats() {
		global $wpdb;

		$stats             = array();
		$enabled_languages = get_option( 'voxfor_ml_languages', array( 'en' ) );
		// Ensure $enabled_languages is always an array
		if ( ! is_array( $enabled_languages ) ) {
			$enabled_languages = array( 'en' );
		}

		foreach ( $enabled_languages as $lang ) {
			if ( $lang === 'en' ) {
				continue;
			}

			$translated_count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"
                SELECT COUNT(*) 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = %s
            ",
					'_voxfor_ml_alt_' . $lang
				)
			);

			$stats[ $lang ] = array(
				'translated'    => intval( $translated_count ),
				'language_name' => $this->plugin->getLanguageName( $lang ),
			);
		}

		return $stats;
	}
}
