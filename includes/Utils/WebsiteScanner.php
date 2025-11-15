<?php
namespace VoxforML\Utils;

use VoxforML\Core\Plugin;

/**
 * Website Scanner - Scans entire website for translatable content
 */
class WebsiteScanner {
	private $plugin;
	private $content_scanner;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin          = Plugin::getInstance();
		$this->content_scanner = new ContentScanner();
	}

	/**
	 * Scan entire website and return all translatable content
	 */
	public function scanEntireWebsite( $target_languages = null ) {
		if ( ! $target_languages || ! is_array( $target_languages ) || empty( $target_languages ) ) {
			$target_languages = $this->plugin->getEnabledLanguages();
			// Remove default language
			$target_languages = array_diff( $target_languages, array( $this->plugin->getDefaultLanguage() ) );
		}

		// Ensure target_languages is always an array with numeric keys
		$target_languages = array_values( array_filter( $target_languages ) );


		// Get all content items
		$all_content = $this->getAllWebsiteContent();

		$scan_results = array(
			'total_items'            => count( $all_content ),
			'target_languages'       => $target_languages,
			'content_types'          => array(),
			'items'                  => $all_content,
			'estimated_translations' => 0,
		);

		// Count content types
		foreach ( $all_content as $item ) {
			$type = $item['type'];
			if ( ! isset( $scan_results['content_types'][ $type ] ) ) {
				$scan_results['content_types'][ $type ] = 0;
			}
			++$scan_results['content_types'][ $type ];
		}

		// Estimate total translations needed
		$scan_results['estimated_translations'] = count( $all_content ) * count( $target_languages );

		return $scan_results;
	}

	/**
	 * Get all website content (posts, pages, products, etc.)
	 */
	private function getAllWebsiteContent() {
		$all_content = array();


		// Get all public post types
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		foreach ( $post_types as $post_type ) {
			$posts = $this->getPostsByType( $post_type );

			foreach ( $posts as $post ) {
				// Only include published content for translation
				if ( $post->post_status === 'publish' ) {
					$all_content[] = array(
						'id'         => $post->ID,
						'title'      => $post->post_title,
						'type'       => $post_type,
						'status'     => $post->post_status,
						'url'        => get_permalink( $post->ID ),
						'edit_url'   => get_edit_post_link( $post->ID ),
						'modified'   => $post->post_modified,
						'word_count' => $this->estimateWordCount( $post ),
					);
				}
			}
		}


		// Add homepage if it's not already included
		$homepage = $this->getHomepageInfo();
		if ( $homepage && ! $this->isPostInList( $homepage['id'], $all_content ) ) {
			$all_content[] = $homepage;
		}

		// Add custom taxonomy terms if needed
		$taxonomy_terms = $this->getTaxonomyTerms();
		$all_content    = array_merge( $all_content, $taxonomy_terms );

		// Add menu items
		$menu_items  = $this->getMenuItems();
		$all_content = array_merge( $all_content, $menu_items );

		// Add widget content
		$widget_content = $this->getWidgetContent();
		$all_content    = array_merge( $all_content, $widget_content );

		// Add theme options and site settings
		$site_options = $this->getSiteOptions();
		$all_content  = array_merge( $all_content, $site_options );

		// Sort by priority (pages first, then posts, then products, etc.)
		usort( $all_content, array( $this, 'sortContentByPriority' ) );


		for ( $i = 0; $i < min( 5, count( $all_content ) ); $i++ ) {
			$item = $all_content[ $i ];
		}

		return $all_content;
	}

	/**
	 * Get posts by post type
	 */
	private function getPostsByType( $post_type, $limit = 1000 ) {
		return get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'numberposts'      => $limit,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Get homepage information
	 */
	private function getHomepageInfo() {
		$homepage_id = get_option( 'page_on_front' );

		if ( $homepage_id && $homepage_id != 0 ) {
			$homepage = get_post( $homepage_id );
			if ( $homepage ) {
				return array(
					'id'         => $homepage->ID,
					'title'      => $homepage->post_title ?: 'Homepage',
					'type'       => 'homepage',
					'status'     => $homepage->post_status,
					'url'        => home_url( '/' ),
					'edit_url'   => get_edit_post_link( $homepage->ID ),
					'modified'   => $homepage->post_modified,
					'word_count' => $this->estimateWordCount( $homepage ),
				);
			}
		}

		// If no static homepage, return blog homepage info
		return array(
			'id'         => 0,
			'title'      => 'Blog Homepage',
			'type'       => 'homepage',
			'status'     => 'publish',
			'url'        => home_url( '/' ),
			'edit_url'   => admin_url( 'options-reading.php' ),
			'modified'   => current_time( 'mysql' ),
			'word_count' => 100, // Estimate for blog homepage
		);
	}

	/**
	 * Get important taxonomy terms
	 */
	private function getTaxonomyTerms() {
		$terms = array();

		// Get WooCommerce product categories and tags if WooCommerce is active
		if ( class_exists( 'WooCommerce' ) ) {
			$product_cats = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'number'     => 100,
				)
			);

			foreach ( $product_cats as $term ) {
				$terms[] = array(
					'id'         => 'term_' . $term->term_id,
					'title'      => $term->name,
					'type'       => 'product_category',
					'status'     => 'publish',
					'url'        => get_term_link( $term ),
					'edit_url'   => get_edit_term_link( $term->term_id, 'product_cat' ),
					'modified'   => current_time( 'mysql' ),
					'word_count' => str_word_count( $term->description ?: $term->name ),
				);
			}
		}

		// Get regular categories
		$categories = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 50,
			)
		);

		foreach ( $categories as $term ) {
			$terms[] = array(
				'id'         => 'term_' . $term->term_id,
				'title'      => $term->name,
				'type'       => 'category',
				'status'     => 'publish',
				'url'        => get_term_link( $term ),
				'edit_url'   => get_edit_term_link( $term->term_id, 'category' ),
				'modified'   => current_time( 'mysql' ),
				'word_count' => str_word_count( $term->description ?: $term->name ),
			);
		}

		return $terms;
	}

	/**
	 * Get all menu items
	 */
	private function getMenuItems() {
		$items = array();

		// Get all menus
		$menus = wp_get_nav_menus();

		foreach ( $menus as $menu ) {
			$menu_items = wp_get_nav_menu_items( $menu->term_id );

			foreach ( $menu_items as $item ) {
				// Only add items with custom labels
				if ( $item->title && $item->title !== $item->post_title ) {
					$items[] = array(
						'id'          => 'menu_item_' . $item->ID,
						'title'       => $item->title,
						'type'        => 'menu_item',
						'status'      => 'publish',
						'url'         => $item->url,
						'edit_url'    => admin_url( 'nav-menus.php?action=edit&menu=' . $menu->term_id ),
						'modified'    => current_time( 'mysql' ),
						'word_count'  => str_word_count( $item->title ),
						'menu_name'   => $menu->name,
						'description' => $item->description,
					);
				}
			}
		}

		return $items;
	}

	/**
	 * Get widget content
	 */
	private function getWidgetContent() {
		$items = array();

		// Get all widget areas
		$widget_areas = wp_get_sidebars_widgets();

		foreach ( $widget_areas as $area => $widgets ) {
			if ( $area === 'wp_inactive_widgets' || empty( $widgets ) ) {
				continue;
			}

			foreach ( $widgets as $widget_id ) {
				// Parse widget ID
				$id_parts      = explode( '-', $widget_id );
				$widget_number = array_pop( $id_parts );
				$widget_base   = implode( '-', $id_parts );

				// Get widget settings
				$widget_settings = get_option( 'widget_' . $widget_base );

				if ( isset( $widget_settings[ $widget_number ] ) ) {
					$widget_data = $widget_settings[ $widget_number ];

					// Extract translatable content
					$translatable_fields = array( 'title', 'text', 'content', 'description', 'caption' );

					foreach ( $translatable_fields as $field ) {
						if ( isset( $widget_data[ $field ] ) && ! empty( $widget_data[ $field ] ) ) {
							$items[] = array(
								'id'          => 'widget_' . $widget_id . '_' . $field,
								'title'       => 'Widget: ' . ucfirst( $field ) . ' (' . $widget_base . ')',
								'type'        => 'widget',
								'status'      => 'publish',
								'url'         => home_url(),
								'edit_url'    => admin_url( 'widgets.php' ),
								'modified'    => current_time( 'mysql' ),
								'word_count'  => str_word_count( $widget_data[ $field ] ),
								'content'     => $widget_data[ $field ],
								'widget_type' => $widget_base,
								'widget_area' => $area,
							);
						}
					}
				}
			}
		}

		return $items;
	}

	/**
	 * Get site options and theme settings
	 */
	private function getSiteOptions() {
		$items = array();

		// Core site options
		$core_options = array(
			'blogname'        => 'Site Title',
			'blogdescription' => 'Site Tagline',
			'date_format'     => 'Date Format',
			'time_format'     => 'Time Format',
		);

		foreach ( $core_options as $option_name => $label ) {
			$value = get_option( $option_name );
			if ( $value && is_string( $value ) && ! empty( $value ) ) {
				$items[] = array(
					'id'         => 'option_' . $option_name,
					'title'      => $label,
					'type'       => 'site_option',
					'status'     => 'publish',
					'url'        => home_url(),
					'edit_url'   => admin_url( 'options-general.php' ),
					'modified'   => current_time( 'mysql' ),
					'word_count' => str_word_count( $value ),
					'content'    => $value,
				);
			}
		}

		// Theme customizer settings
		$theme_mods = get_theme_mods();
		if ( $theme_mods ) {
			foreach ( $theme_mods as $mod_name => $mod_value ) {
				// Skip non-string values and technical settings
				if ( ! is_string( $mod_value ) || empty( $mod_value ) || strpos( $mod_name, 'nav_menu' ) !== false ) {
					continue;
				}

				// Skip URLs and technical values
				if ( filter_var( $mod_value, FILTER_VALIDATE_URL ) || preg_match( '/^#[0-9a-fA-F]{3,6}$/', $mod_value ) ) {
					continue;
				}

				$items[] = array(
					'id'         => 'theme_mod_' . $mod_name,
					'title'      => 'Theme Setting: ' . ucwords( str_replace( '_', ' ', $mod_name ) ),
					'type'       => 'theme_mod',
					'status'     => 'publish',
					'url'        => home_url(),
					'edit_url'   => admin_url( 'customize.php' ),
					'modified'   => current_time( 'mysql' ),
					'word_count' => str_word_count( $mod_value ),
					'content'    => $mod_value,
				);
			}
		}

		return $items;
	}

	/**
	 * Check if post is already in the list
	 */
	private function isPostInList( $post_id, $content_list ) {
		foreach ( $content_list as $item ) {
			if ( $item['id'] == $post_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Estimate word count for a post
	 */
	private function estimateWordCount( $post ) {
		$content = $post->post_content . ' ' . $post->post_title . ' ' . $post->post_excerpt;
		$content = wp_strip_all_tags( $content );
		return str_word_count( $content );
	}

	/**
	 * Sort content by priority for translation
	 */
	private function sortContentByPriority( $a, $b ) {
		$priority_order = array(
			'site_option'      => 1,      // Site title and tagline first
			'homepage'         => 2,         // Homepage content
			'menu_item'        => 3,        // Navigation items
			'page'             => 4,             // Regular pages
			'product'          => 5,          // WooCommerce products
			'post'             => 6,             // Blog posts
			'product_category' => 7, // Product categories
			'category'         => 8,         // Blog categories
			'widget'           => 9,           // Widget content
			'theme_mod'        => 10,        // Theme customizations
		);

		$a_priority = $priority_order[ $a['type'] ] ?? 999;
		$b_priority = $priority_order[ $b['type'] ] ?? 999;

		if ( $a_priority === $b_priority ) {
			// If same priority, sort by modification date (newest first)
			return strtotime( $b['modified'] ) - strtotime( $a['modified'] );
		}

		return $a_priority - $b_priority;
	}

	/**
	 * Process complete website translation with progress tracking
	 */
	public function processCompleteWebsiteTranslation( $progress_key, $target_languages = null ) {

		// Define constant to enable immediate translation during bulk operations
		if ( ! defined( 'VOXFOR_ML_BULK_TRANSLATING' ) ) {
			define( 'VOXFOR_ML_BULK_TRANSLATING', true );
		}

		// Update progress
		$this->updateProgress( $progress_key, 0, 'Scanning website content...' );

		// Get all content
		$scan_results     = $this->scanEntireWebsite( $target_languages );
		$all_content      = $scan_results['items'];
		$target_languages = $scan_results['target_languages'];

		$total_items = count( $all_content );

		if ( $total_items === 0 ) {
			$this->updateProgress( $progress_key, 100, 'No content found to translate' );
			return array(
				'success'                 => false,
				'error'                   => 'No content found to translate',
				'total_items'             => 0,
				'processed_items'         => 0,
				'successful_translations' => 0,
				'failed_translations'     => 0,
				'target_languages'        => $target_languages,
			);
		}

		$processed_items         = 0;
		$successful_translations = 0;
		$failed_translations     = 0;

		$this->updateProgress( $progress_key, 5, "Found {$total_items} items to translate to " . count( $target_languages ) . ' languages' );

		// Process each content item
		foreach ( $all_content as $content_item ) {
			$item_id    = $content_item['id'];
			$item_title = $content_item['title'];
			$item_type  = $content_item['type'];


			// Update progress
			$progress_percent = 5 + ( ( $processed_items / $total_items ) * 90 );
			$this->updateProgress( $progress_key, $progress_percent, "Translating: {$item_type} - {$item_title}" );

			// Check for cancellation
			if ( get_transient( 'voxfor_ml_cancel_' . $progress_key ) ) {
				$this->updateProgress( $progress_key, $progress_percent, 'Translation cancelled by user' );
				break;
			}

			try {
				// Handle different content types
				if ( strpos( $item_id, 'term_' ) === 0 ) {
					// Handle taxonomy terms
					$term_id = str_replace( 'term_', '', $item_id );
					$this->translateTaxonomyTerm( $term_id, $target_languages, $progress_key );
					$successful_translations += count( $target_languages );
				} elseif ( strpos( $item_id, 'menu_item_' ) === 0 ) {
					// Handle menu items
					foreach ( $target_languages as $language ) {
						$translation_success = $this->translateMenuItem( $content_item, $language );
						if ( $translation_success ) {
							++$successful_translations;
						} else {
							++$failed_translations;
						}
					}
				} elseif ( strpos( $item_id, 'widget_' ) === 0 ) {
					// Handle widgets
					foreach ( $target_languages as $language ) {
						$translation_success = $this->translateWidget( $content_item, $language );
						if ( $translation_success ) {
							++$successful_translations;
						} else {
							++$failed_translations;
						}
					}
				} elseif ( strpos( $item_id, 'option_' ) === 0 || strpos( $item_id, 'theme_mod_' ) === 0 ) {
					// Handle site options and theme mods
					foreach ( $target_languages as $language ) {
						$translation_success = $this->translateSiteOption( $content_item, $language );
						if ( $translation_success ) {
							++$successful_translations;
						} else {
							++$failed_translations;
						}
					}
				} else {
					// Handle posts, pages, products
					foreach ( $target_languages as $language ) {
						$lang_progress_percent = $progress_percent + ( 1 / count( $target_languages ) );
						$this->updateProgress( $progress_key, $lang_progress_percent, "Translating: {$item_type} - {$item_title} ({$language})" );

						try {
							// Use a simpler approach: translate the core content directly
							$translation_success = $this->translatePostContent( $item_id, $language );

							if ( $translation_success ) {
								++$successful_translations;
							} else {
								++$failed_translations;
							}
						} catch ( Exception $e ) {
							++$failed_translations;
						}

						// Small delay to prevent overwhelming the API
						usleep( 200000 ); // 0.2 second delay
					}
				}
			} catch ( Exception $e ) {
				$failed_translations += count( $target_languages );
			}

			++$processed_items;

			// Update progress every 5 items or at the end
			if ( $processed_items % 5 === 0 || $processed_items === $total_items ) {
				$progress_percent = 5 + ( ( $processed_items / $total_items ) * 90 );
				$stats            = array(
					'processed'  => $processed_items,
					'total'      => $total_items,
					'successful' => $successful_translations,
					'failed'     => $failed_translations,
				);
				$this->updateProgress(
					$progress_key,
					$progress_percent,
					"Progress: {$processed_items}/{$total_items} items processed. " .
					"Successful: {$successful_translations}, Failed: {$failed_translations}",
					$stats
				);
			}
		}

		// Final progress update
		$final_stats = array(
			'processed'  => $processed_items,
			'total'      => $total_items,
			'successful' => $successful_translations,
			'failed'     => $failed_translations,
		);
		$this->updateProgress(
			$progress_key,
			100,
			"Complete! Processed {$processed_items} items. " .
			"Successful translations: {$successful_translations}, Failed: {$failed_translations}",
			$final_stats
		);


		return array(
			'success'                 => true,
			'total_items'             => $total_items,
			'processed_items'         => $processed_items,
			'successful_translations' => $successful_translations,
			'failed_translations'     => $failed_translations,
			'target_languages'        => $target_languages,
		);
	}

	/**
	 * Update progress in database or cache
	 */
	public function updateProgress( $progress_key, $percent, $message, $stats = null ) {
		$progress_data = array(
			'percent'    => min( 100, max( 0, $percent ) ),
			'message'    => $message,
			'timestamp'  => current_time( 'mysql' ),
			'updated_at' => time(),
			'stats'      => $stats,
		);

		// Store in transients (expires in 1 hour)
		set_transient( 'voxfor_ml_progress_' . $progress_key, $progress_data, HOUR_IN_SECONDS );

		// Also log to error log for debugging

		if ( $stats ) {
		}
	}

	/**
	 * Get progress data
	 */
	public function getProgress( $progress_key ) {
		return get_transient( 'voxfor_ml_progress_' . $progress_key );
	}

	/**
	 * Clear progress data
	 */
	public function clearProgress( $progress_key ) {
		delete_transient( 'voxfor_ml_progress_' . $progress_key );
	}

	/**
	 * Generate unique progress key
	 */
	public function generateProgressKey() {
		return 'website_' . wp_generate_uuid4();
	}

	/**
	 * Get website translation statistics
	 */
	public function getWebsiteStats() {
		$scan_results = $this->scanEntireWebsite();

		return array(
			'total_content_items'    => $scan_results['total_items'],
			'content_types'          => $scan_results['content_types'],
			'target_languages'       => $scan_results['target_languages'],
			'estimated_translations' => $scan_results['estimated_translations'],
			'estimated_api_calls'    => $scan_results['estimated_translations'],
		);
	}

	/**
	 * Translate post content directly (simpler approach)
	 */
	private function translatePostContent( $post_id, $language ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		// Use direct instantiation to avoid component issues
		$memory     = new \VoxforML\Database\TranslationMemory();
		$translator = new \VoxforML\Translator\DeepLTranslator();

		if ( ! $memory || ! $translator ) {
			return false;
		}


		$credit_manager = new \VoxforML\Utils\ApiCreditManager();
		$api_enabled    = $credit_manager->isApiEnabled();

		if ( ! $api_enabled ) {
			return false;
		}

		$translations_made = 0;

		// Get ALL content from the post - not just title and excerpt
		$content_to_translate = $this->extractAllPostContent( $post );

		// Translate all content fields
		foreach ( $content_to_translate as $field_key => $field_value ) {
			if ( empty( $field_value ) || ! is_string( $field_value ) ) {
				continue;
			}

			// Skip very short content
			if ( strlen( trim( $field_value ) ) < 3 ) {
				continue;
			}


			// Check if translation already exists
			$existing_translation = $memory->getTranslation( $field_value, $language, $field_key );
			if ( ! $existing_translation ) {

				// For long content, we might need to split it
				if ( strlen( $field_value ) > 5000 && $field_key === 'content' ) {
					// Split content into paragraphs for better translation
					$paragraphs            = preg_split( '/\n\s*\n/', $field_value );
					$translated_paragraphs = array();

					foreach ( $paragraphs as $paragraph ) {
						$paragraph = trim( $paragraph );
						if ( strlen( $paragraph ) > 10 ) {
							$translated_paragraph = $translator->translate( $paragraph, $language, 'EN', $field_key );
							if ( $translated_paragraph ) {
								$translated_paragraphs[] = $translated_paragraph;
								// Save paragraph translation
								$memory->saveTranslation( $paragraph, $translated_paragraph, $language, $field_key . '_paragraph', $post_id );
							} else {
								$translated_paragraphs[] = $paragraph; // Keep original if translation fails
							}
						} else {
							$translated_paragraphs[] = $paragraph;
						}
					}

					// Join translated paragraphs
					$translated_content = implode( "\n\n", $translated_paragraphs );
					if ( $translated_content && $translated_content !== $field_value ) {
						$memory->saveTranslation( $field_value, $translated_content, $language, $field_key, $post_id );
						++$translations_made;
					}
				} else {
					// Regular translation for shorter content
					$translated_value = $translator->translate( $field_value, $language, 'EN', $field_key );

					if ( $translated_value && $translated_value !== $field_value ) {
						$memory->saveTranslation( $field_value, $translated_value, $language, $field_key, $post_id );
						++$translations_made;
					} else {
					}
				}
			} else {
				++$translations_made; // Count existing translations too
			}
		}

		return $translations_made > 0;
	}

	/**
	 * Extract all translatable content from a post
	 */
	private function extractAllPostContent( $post ) {
		$content = array();

		// Basic post fields
		$content['title']   = $post->post_title;
		$content['content'] = $post->post_content;
		$content['excerpt'] = $post->post_excerpt;

		// Get all post meta
		$meta_data = get_post_meta( $post->ID );
		foreach ( $meta_data as $key => $values ) {
			// Skip internal WordPress meta and serialized data
			if ( strpos( $key, '_' ) === 0 || strpos( $key, 'voxfor_ml' ) !== false ) {
				continue;
			}

			foreach ( $values as $value ) {
				if ( is_string( $value ) && ! empty( $value ) && ! is_serialized( $value ) ) {
					$content[ 'meta_' . $key ] = $value;
				}
			}
		}

		// Get custom fields (ACF, etc.)
		if ( function_exists( 'get_fields' ) ) {
			$fields = get_fields( $post->ID );
			if ( $fields ) {
				foreach ( $fields as $field_key => $field_value ) {
					if ( is_string( $field_value ) && ! empty( $field_value ) ) {
						$content[ 'acf_' . $field_key ] = $field_value;
					}
				}
			}
		}

		// For WooCommerce products, get additional data
		if ( $post->post_type === 'product' && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				// Product-specific content
				$content['product_short_description'] = $product->get_short_description();

				// Product attributes
				$attributes = $product->get_attributes();
				foreach ( $attributes as $attribute ) {
					if ( is_object( $attribute ) ) {
						$options = $attribute->get_options();
						foreach ( $options as $option ) {
							if ( is_string( $option ) ) {
								$content[ 'attribute_' . $attribute->get_name() . '_' . $option ] = $option;
							}
						}
					}
				}

				// Product tabs
				if ( $product->get_meta( '_product_tabs' ) ) {
					$tabs = $product->get_meta( '_product_tabs' );
					foreach ( $tabs as $tab_key => $tab ) {
						if ( isset( $tab['title'] ) ) {
							$content[ 'tab_title_' . $tab_key ] = $tab['title'];
						}
						if ( isset( $tab['content'] ) ) {
							$content[ 'tab_content_' . $tab_key ] = $tab['content'];
						}
					}
				}
			}
		}

		return $content;
	}

	/**
	 * Translate taxonomy term
	 */
	private function translateTaxonomyTerm( $term_id, $target_languages, $progress_key ) {
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		// Use direct instantiation to avoid component issues
		$memory     = new \VoxforML\Database\TranslationMemory();
		$translator = new \VoxforML\Translator\DeepLTranslator();

		if ( ! $memory || ! $translator ) {
			return false;
		}

		foreach ( $target_languages as $language ) {
			// Translate term name
			if ( ! empty( $term->name ) ) {
				$existing_translation = $memory->getTranslation( $term->name, $language, 'term_name' );
				if ( ! $existing_translation ) {
					$translated_name = $translator->translate( $term->name, $language, 'EN', 'term_name' );
					if ( $translated_name ) {
						$memory->saveTranslation( $term->name, $translated_name, $language, 'term_name', $term_id );
					}
				}
			}

			// Translate term description
			if ( ! empty( $term->description ) ) {
				$existing_translation = $memory->getTranslation( $term->description, $language, 'term_description' );
				if ( ! $existing_translation ) {
					$translated_desc = $translator->translate( $term->description, $language, 'EN', 'term_description' );
					if ( $translated_desc ) {
						$memory->saveTranslation( $term->description, $translated_desc, $language, 'term_description', $term_id );
					}
				}
			}
		}

		return true;
	}

	/**
	 * Translate menu item
	 */
	private function translateMenuItem( $item_data, $language ) {
		// Use direct instantiation
		$memory     = new \VoxforML\Database\TranslationMemory();
		$translator = new \VoxforML\Translator\DeepLTranslator();

		$translations_made = 0;

		// Translate menu title
		if ( ! empty( $item_data['title'] ) ) {
			$existing = $memory->getTranslation( $item_data['title'], $language, 'menu_title' );
			if ( ! $existing ) {
				$translated = $translator->translate( $item_data['title'], $language, 'EN', 'menu_title' );
				if ( $translated ) {
					$memory->saveTranslation( $item_data['title'], $translated, $language, 'menu_title', $item_data['id'] );
					++$translations_made;
				}
			}
		}

		// Translate menu description if exists
		if ( ! empty( $item_data['description'] ) ) {
			$existing = $memory->getTranslation( $item_data['description'], $language, 'menu_description' );
			if ( ! $existing ) {
				$translated = $translator->translate( $item_data['description'], $language, 'EN', 'menu_description' );
				if ( $translated ) {
					$memory->saveTranslation( $item_data['description'], $translated, $language, 'menu_description', $item_data['id'] );
					++$translations_made;
				}
			}
		}

		return $translations_made > 0;
	}

	/**
	 * Translate widget content
	 */
	private function translateWidget( $widget_data, $language ) {
		// Use direct instantiation
		$memory     = new \VoxforML\Database\TranslationMemory();
		$translator = new \VoxforML\Translator\DeepLTranslator();

		if ( ! empty( $widget_data['content'] ) ) {
			$existing = $memory->getTranslation( $widget_data['content'], $language, 'widget_content' );
			if ( ! $existing ) {
				$translated = $translator->translate( $widget_data['content'], $language, 'EN', 'widget_content' );
				if ( $translated ) {
					$memory->saveTranslation( $widget_data['content'], $translated, $language, 'widget_content', $widget_data['id'] );
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Translate site option or theme mod
	 */
	private function translateSiteOption( $option_data, $language ) {
		// Use direct instantiation
		$memory     = new \VoxforML\Database\TranslationMemory();
		$translator = new \VoxforML\Translator\DeepLTranslator();

		if ( ! empty( $option_data['content'] ) ) {
			$context  = $option_data['type'] === 'site_option' ? 'site_option' : 'theme_mod';
			$existing = $memory->getTranslation( $option_data['content'], $language, $context );
			if ( ! $existing ) {
				$translated = $translator->translate( $option_data['content'], $language, 'EN', $context );
				if ( $translated ) {
					$memory->saveTranslation( $option_data['content'], $translated, $language, $context, $option_data['id'] );
					return true;
				}
			}
		}

		return false;
	}
}
