<?php
namespace VoxforML\Frontend;

use VoxforML\Core\Plugin;

/**
 * Visual Editor for in-context translation editing
 */
class VisualEditor {
	private $plugin;
	private $translator;
	private $memory;
	private $security;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin     = Plugin::getInstance();
		$this->translator = $this->plugin->getComponent( 'translator' );
		$this->memory     = $this->plugin->getComponent( 'translation_memory' );
		$this->security   = $this->plugin->getComponent( 'security' );

		$this->initHooks();
	}

	/**
	 * Initialize hooks
	 */
	private function initHooks() {
		// Check if visual editor is enabled
		if ( ! get_option( 'voxfor_ml_enable_visual_editor', true ) ) {
			return;
		}

		// Frontend hooks
		add_action( 'wp_footer', array( $this, 'renderEditor' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueScripts' ) );

		// AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_get_segments', array( $this, 'ajaxGetSegments' ) );
		add_action( 'wp_ajax_voxfor_ml_save_segment', array( $this, 'ajaxSaveSegment' ) );
		add_action( 'wp_ajax_voxfor_ml_lock_segment', array( $this, 'ajaxLockSegment' ) );

		// Add edit mode indicator
		add_filter( 'body_class', array( $this, 'addBodyClass' ) );
	}

	/**
	 * Check if user can use visual editor
	 */
	private function canUseEditor() {
		return current_user_can( 'edit_posts' ) && isset( $_GET['voxfor_ml_edit'] ) && $_GET['voxfor_ml_edit'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Add body class for edit mode
	 */
	public function addBodyClass( $classes ) {
		if ( $this->canUseEditor() ) {
			$classes[] = 'voxfor-ml-edit-mode';
		}
		return $classes;
	}

	/**
	 * Enqueue scripts and styles
	 */
	public function enqueueScripts() {
		if ( ! $this->canUseEditor() ) {
			return;
		}

		wp_enqueue_script(
			'voxfor-ml-visual-editor',
			VOXFOR_ML_PLUGIN_URL . 'public/js/visual-editor.js',
			array( 'jquery' ),
			VOXFOR_ML_VERSION,
			true
		);

		wp_enqueue_style(
			'voxfor-ml-visual-editor',
			VOXFOR_ML_PLUGIN_URL . 'public/css/frontend/visual-editor.css',
			array(),
			VOXFOR_ML_VERSION
		);

		// Localize script
		wp_localize_script(
			'voxfor-ml-visual-editor',
			'voxforMLEditor',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'voxfor_ml_visual_editor' ),
				'currentLang' => $this->plugin->getComponent( 'router' )->getCurrentLanguage(),
				'defaultLang' => $this->plugin->getDefaultLanguage(),
				'strings'     => array(
					'save'          => __( 'Save', 'voxfor-multilanguage' ),
					'cancel'        => __( 'Cancel', 'voxfor-multilanguage' ),
					'edit'          => __( 'Edit Translation', 'voxfor-multilanguage' ),
					'saving'        => __( 'Saving...', 'voxfor-multilanguage' ),
					'saved'         => __( 'Saved!', 'voxfor-multilanguage' ),
					'error'         => __( 'Error saving translation', 'voxfor-multilanguage' ),
					'locked'        => __( 'This translation is locked', 'voxfor-multilanguage' ),
					'draft'         => __( 'Draft', 'voxfor-multilanguage' ),
					'reviewed'      => __( 'Reviewed', 'voxfor-multilanguage' ),
					'locked_status' => __( 'Locked', 'voxfor-multilanguage' ),
				),
			)
		);
	}

	/**
	 * Render visual editor interface
	 */
	public function renderEditor() {
		if ( ! $this->canUseEditor() ) {
			return;
		}

		include VOXFOR_ML_PLUGIN_DIR . 'templates/frontend/visual-editor.php';
	}

	/**
	 * AJAX: Get translatable segments
	 */
	public function ajaxGetSegments() {
		// Verify nonce - use standard WordPress function
		if ( ! check_ajax_referer( 'voxfor_ml_visual_editor', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'voxfor-multilanguage' ) ) );
		}

		// Check permissions
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'voxfor-multilanguage' ) ) );
		}

		$post_id  = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : get_the_ID();
		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : $this->plugin->getComponent( 'router' )->getCurrentLanguage();

		// Get all translatable segments for the page
		$segments = $this->getPageSegments( $post_id, $language );

		foreach ( $segments as $segment ) {
		}

		wp_send_json_success( $segments );
	}

	/**
	 * AJAX: Save segment translation
	 */
	public function ajaxSaveSegment() {
		// Verify nonce - use standard WordPress function
		if ( ! check_ajax_referer( 'voxfor_ml_visual_editor', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'voxfor-multilanguage' ) ) );
		}

		// Check permissions
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'voxfor-multilanguage' ) ) );
		}

		// Sanitize input properly
		$segment_id    = isset( $_POST['segment_id'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_id'] ) ) : '';
		$original_text = isset( $_POST['original'] ) ? sanitize_textarea_field( wp_unslash( $_POST['original'] ) ) : '';
		$translation   = isset( $_POST['translation'] ) ? sanitize_textarea_field( wp_unslash( $_POST['translation'] ) ) : '';
		$language      = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$context       = isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : 'general';

		if ( empty( $original_text ) || empty( $translation ) || empty( $language ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields', 'voxfor-multilanguage' ) ) );
		}

		// SIMPLE APPROACH: Update existing translation by post_id + context

		// Get post_id from current page
		$post_id = get_the_ID() ?: get_queried_object_id();

		// Step 1: Find existing translation for this post + context
		$existing_translation = $this->findExistingTranslation( $original_text, $language, $context, $post_id );

		if ( $existing_translation ) {
			// Step 2: UPDATE existing translation (user's logical approach)
			$result = $this->updateExistingTranslation( $existing_translation['id'], $translation );

			if ( $result ) {
				// Translation updated successfully
			} else {
				// Failed to update translation
			}
		} else {
			// SAFETY CHECK: Before creating new translation, do one final aggressive search

			// Try searching with LIKE pattern for similar text
			global $wpdb;
			$table_name = $wpdb->prefix . 'voxfor_ml_translations';
			$similar    = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT id, translated_text, provider, context, source_text FROM `{$wpdb->prefix}voxfor_ml_translations` 
                 WHERE language_code = %s AND (
                    source_text LIKE %s OR 
                    source_text LIKE %s OR
                    source_text LIKE %s
                 )
                 ORDER BY 
                    CASE WHEN provider = 'manual' THEN 1 ELSE 2 END,
                    LENGTH(source_text) DESC
                 LIMIT 1",
					$language,
					'%' . $wpdb->esc_like( $original_text ) . '%',
					'%' . $wpdb->esc_like( substr( $original_text, 0, 50 ) ) . '%',
					$original_text
				),
				ARRAY_A
			);

			if ( $similar ) {

				// Update the similar translation instead of creating new one
				$result = $this->updateExistingTranslation( $similar['id'], $translation );
			} else {
				// Only create new translation if absolutely no similar translation exists
				$result = $this->memory->saveTranslation(
					$original_text,
					$translation,
					$language,
					$context,
					null, // post_id - will be set by saveTranslation if needed
					'manual' // provider
				);

				if ( $result ) {
				} else {
				}
			}
		}

		if ( $result ) {
			// Clear all relevant caches to ensure manual edits show immediately
			wp_cache_flush();

			// Clear object cache for this specific translation
			if ( get_option( 'voxfor_ml_enable_object_cache', true ) ) {
				$normalized_text = html_entity_decode( $original_text, ENT_QUOTES, 'UTF-8' );
				$normalized_text = trim( preg_replace( '/\s+/', ' ', $normalized_text ) );

				// Use text-based hash for cache clearing
				$source_hash = hash( 'sha256', $normalized_text );
				$cache_key   = 'voxfor_ml_trans_' . $source_hash . '_' . $language . '_' . $context;
				wp_cache_delete( $cache_key, 'voxfor_ml_translations' );
			}

			// Clear WordPress transients that might cache translations
			delete_transient( 'voxfor_ml_translations_' . $language );
			delete_transient( 'voxfor_ml_page_cache_' . get_the_ID() . '_' . $language );

			wp_send_json_success(
				array(
					'message'    => __( 'Translation saved successfully', 'voxfor-multilanguage' ),
					'segment_id' => $segment_id,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save translation', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * Find existing translation for update (logical approach with context matching)
	 */
	private function findExistingTranslation( $source_text, $language, $context, $post_id = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'voxfor_ml_translations';

		// USER'S FOOLPROOF LOGIC: Find existing translation by source_hash (unique identifier)
		// Normalize source text to match what we save (same as TranslationMemory)
		$normalized_source = html_entity_decode( $source_text, ENT_QUOTES, 'UTF-8' );
		$normalized_source = trim( preg_replace( '/\s+/', ' ', $normalized_source ) );

		// Use text-based hash for finding existing translations
		$source_hash = hash( 'sha256', $normalized_source );


		// STEP 1: Try exact source_hash + language + context match (most precise)
		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, translated_text, provider, context, source_hash, source_text FROM `{$wpdb->prefix}voxfor_ml_translations` 
             WHERE source_hash = %s AND language_code = %s AND context = %s
             ORDER BY 
                CASE WHEN provider = 'manual' THEN 1 ELSE 2 END,
                updated_at DESC 
             LIMIT 1",
				$source_hash,
				$language,
				$context
			),
			ARRAY_A
		);

		if ( $existing ) {
			return $existing;
		}

		// STEP 2: Try source_hash + language + post_id (same post, different context)
		if ( $post_id ) {
			$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT id, translated_text, provider, context, source_hash, source_text FROM `{$wpdb->prefix}voxfor_ml_translations` 
                 WHERE source_hash = %s AND language_code = %s AND post_id = %d
                 ORDER BY 
                    CASE WHEN provider = 'manual' THEN 1 ELSE 2 END,
                    updated_at DESC 
                 LIMIT 1",
					$source_hash,
					$language,
					$post_id
				),
				ARRAY_A
			);

			if ( $existing ) {
				return $existing;
			}
		}

		// STEP 3: Try source_hash + language (any context, but prioritize manual)
		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, translated_text, provider, context, source_hash, source_text FROM `{$wpdb->prefix}voxfor_ml_translations` 
             WHERE source_hash = %s AND language_code = %s
             ORDER BY 
                CASE WHEN provider = 'manual' THEN 1 ELSE 2 END,
                updated_at DESC 
             LIMIT 1",
				$source_hash,
				$language
			),
			ARRAY_A
		);

		if ( $existing ) {
			return $existing;
		}

		// STEP 4: Try exact source_text + context match (fallback for hash mismatches)
		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, translated_text, provider, context, source_hash, source_text FROM `{$wpdb->prefix}voxfor_ml_translations` 
             WHERE source_text = %s AND language_code = %s AND context = %s
             ORDER BY 
                CASE WHEN provider = 'manual' THEN 1 ELSE 2 END,
                updated_at DESC 
             LIMIT 1",
				$source_text,
				$language,
				$context
			),
			ARRAY_A
		);

		if ( $existing ) {
			return $existing;
		}

		// STEP 5: Try exact source_text match (any context)
		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, translated_text, provider, context, source_hash, source_text FROM `{$wpdb->prefix}voxfor_ml_translations` 
             WHERE source_text = %s AND language_code = %s
             ORDER BY 
                CASE WHEN provider = 'manual' THEN 1 ELSE 2 END,
                updated_at DESC 
             LIMIT 1",
				$source_text,
				$language
			),
			ARRAY_A
		);

		if ( $existing ) {
			return $existing;
		}

		return false;
	}

	/**
	 * Get related contexts that should be considered the same for updates
	 */
	private function getRelatedContexts( $context ) {
		$context_map = array(
			// Product contexts
			'product_short_description' => array( 'text_fragment', 'content', 'general' ),
			'product_description'       => array( 'content', 'post_content', 'text_fragment', 'general' ),
			'product_name'              => array( 'title', 'post_title', 'general' ),

			// Post contexts
			'post_content'              => array( 'content', 'text_fragment', 'general' ),
			'post_title'                => array( 'title', 'general' ),

			// Generic contexts
			'content'                   => array( 'post_content', 'text_fragment', 'product_description', 'general' ),
			'text_fragment'             => array( 'content', 'post_content', 'product_short_description', 'general' ),
			'title'                     => array( 'post_title', 'product_name', 'general' ),
			'general'                   => array( 'content', 'text_fragment', 'title' ),
		);

		return $context_map[ $context ] ?? array( 'general' );
	}

	/**
	 * Update existing translation with new text and mark as manual (logical approach)
	 */
	private function updateExistingTranslation( $translation_id, $new_translation ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'voxfor_ml_translations';

		// Check if translation is locked
		$is_locked = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT is_locked FROM `{$wpdb->prefix}voxfor_ml_translations` WHERE id = %d",
				$translation_id
			)
		);

		if ( $is_locked ) {
			// Cannot update locked translation
			return false;
		}

		// Update ONLY the translated_text field
		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table_name,
			array(
				'translated_text' => $new_translation,
			),
			array( 'id' => $translation_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( $result !== false ) {
			// Clear object cache for this translation
			if ( get_option( 'voxfor_ml_enable_object_cache', true ) ) {
				// Get the source info to clear cache properly
				$translation_info = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT source_hash, language_code, context FROM `{$wpdb->prefix}voxfor_ml_translations` WHERE id = %d",
						$translation_id
					),
					ARRAY_A
				);

				if ( $translation_info ) {
					$cache_key = 'voxfor_ml_trans_' . $translation_info['source_hash'] . '_' .
								$translation_info['language_code'] . '_' . $translation_info['context'];
					wp_cache_delete( $cache_key, 'voxfor_ml_translations' );
				}
			}

			return true;
		}

		return false;
	}

	// Legacy methods removed - using clean logical approach above

	/**
	 * AJAX: Lock/unlock segment
	 */
	public function ajaxLockSegment() {
		// Verify nonce - use standard WordPress function
		if ( ! check_ajax_referer( 'voxfor_ml_visual_editor', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'voxfor-multilanguage' ) ) );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only administrators can lock translations', 'voxfor-multilanguage' ) ) );
		}

		$segment_id    = isset( $_POST['segment_id'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_id'] ) ) : '';
		$original_text = isset( $_POST['original'] ) ? sanitize_textarea_field( wp_unslash( $_POST['original'] ) ) : '';
		$language      = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$context       = isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : 'general';
		$locked        = isset( $_POST['locked'] ) ? filter_var( wp_unslash( $_POST['locked'] ), FILTER_VALIDATE_BOOLEAN ) : false;

		// Update lock status
		global $wpdb;
		$table = $wpdb->prefix . 'voxfor_ml_translations';

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'status'     => $locked ? 'locked' : 'reviewed',
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'source_hash'   => md5( $original_text ),
				'language_code' => $language,
				'context'       => $context,
			),
			array( '%s', '%s' ),
			array( '%s', '%s', '%s' )
		);

		if ( $result !== false ) {
			wp_send_json_success(
				array(
					'message' => $locked ? __( 'Translation locked', 'voxfor-multilanguage' ) : __( 'Translation unlocked', 'voxfor-multilanguage' ),
					'locked'  => $locked,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to update lock status', 'voxfor-multilanguage' ) ) );
		}
	}

	/**
	 * Get translatable segments for a page
	 */
	private function getPageSegments( $post_id, $language ) {
		$segments = array();

		// Get post content
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $segments;
		}

		// For WooCommerce products, extract comprehensive product content

		if ( $post->post_type === 'product' && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				// 1. Product title
				if ( $product->get_name() ) {
					$title_segment = array(
						'id'       => 'product_title_' . $post_id,
						'original' => $product->get_name(),
						'context'  => 'product_name',
						'type'     => 'title',
						'label'    => __( 'Product Name', 'voxfor-multilanguage' ),
					);
					$segments[]    = $this->enrichSegment( $title_segment, $language );
				}

				// 2. Product short description
				if ( $product->get_short_description() ) {
					$short_desc_segment = array(
						'id'       => 'product_short_desc_' . $post_id,
						'original' => $product->get_short_description(),
						'context'  => 'product_short_description',
						'type'     => 'short_description',
						'label'    => __( 'Short Description', 'voxfor-multilanguage' ),
					);
					$segments[]         = $this->enrichSegment( $short_desc_segment, $language );
				}

				// 3. Product description (full description)
				if ( $product->get_description() ) {
					$desc_segment = array(
						'id'       => 'product_desc_' . $post_id,
						'original' => $product->get_description(),
						'context'  => 'content',
						'type'     => 'description',
						'label'    => __( 'Product Description', 'voxfor-multilanguage' ),
					);
					$segments[]   = $this->enrichSegment( $desc_segment, $language );
				}

				// 4. Add to Cart Button Text
				$add_to_cart_text = $product->add_to_cart_text();
				if ( $add_to_cart_text ) {
					$cart_segment = array(
						'id'       => 'product_add_to_cart_' . $post_id,
						'original' => $add_to_cart_text,
						'context'  => 'woocommerce_button',
						'type'     => 'button',
						'label'    => __( 'Add to Cart Button', 'voxfor-multilanguage' ),
					);
					$segments[]   = $this->enrichSegment( $cart_segment, $language );
				}

				// 5. Product Categories
				$categories = wp_get_post_terms( $post_id, 'product_cat' );
				foreach ( $categories as $category ) {
					if ( ! empty( $category->name ) ) {
						$cat_segment = array(
							'id'       => 'product_category_' . $category->term_id,
							'original' => $category->name,
							'context'  => 'product_category',
							'type'     => 'category',
							// translators: %s is the category name
							'label'    => sprintf( __( 'Category: %s', 'voxfor-multilanguage' ), $category->name ),
						);
						$segments[]  = $this->enrichSegment( $cat_segment, $language );
					}
				}

				// 6. Product Tags
				$tags = wp_get_post_terms( $post_id, 'product_tag' );
				foreach ( $tags as $tag ) {
					if ( ! empty( $tag->name ) ) {
						$tag_segment = array(
							'id'       => 'product_tag_' . $tag->term_id,
							'original' => $tag->name,
							'context'  => 'product_tag',
							'type'     => 'tag',
							// translators: %s is the tag name
							'label'    => sprintf( __( 'Tag: %s', 'voxfor-multilanguage' ), $tag->name ),
						);
						$segments[]  = $this->enrichSegment( $tag_segment, $language );
					}
				}

				// 7. Product Attributes
				$attributes = $product->get_attributes();
				foreach ( $attributes as $attribute ) {
					if ( $attribute->is_taxonomy() ) {
						$taxonomy = $attribute->get_taxonomy_object();
						if ( $taxonomy && ! empty( $taxonomy->attribute_label ) ) {
							$attr_segment = array(
								'id'       => 'product_attribute_' . $attribute->get_id(),
								'original' => $taxonomy->attribute_label,
								'context'  => 'product_attribute',
								'type'     => 'attribute',
								// translators: %s is the attribute name
								'label'    => sprintf( __( 'Attribute: %s', 'voxfor-multilanguage' ), $taxonomy->attribute_label ),
							);
							$segments[]   = $this->enrichSegment( $attr_segment, $language );
						}
					} else {
						// Custom attribute
						$attr_name = $attribute->get_name();
						if ( ! empty( $attr_name ) ) {
							$attr_segment = array(
								'id'       => 'product_custom_attr_' . md5( $attr_name ),
								'original' => $attr_name,
								'context'  => 'product_attribute',
								'type'     => 'attribute',
								// translators: %s is the custom attribute name
								'label'    => sprintf( __( 'Custom Attribute: %s', 'voxfor-multilanguage' ), $attr_name ),
							);
							$segments[]   = $this->enrichSegment( $attr_segment, $language );
						}
					}
				}

				// 8. Product Tab Titles (Description, Additional Information, Reviews)
				$tabs = apply_filters( 'woocommerce_product_tabs', array() );
				foreach ( $tabs as $key => $tab ) {
					if ( ! empty( $tab['title'] ) ) {
						$tab_segment = array(
							'id'       => 'product_tab_' . $key . '_' . $post_id,
							'original' => $tab['title'],
							'context'  => 'woocommerce_tab_title',
							'type'     => 'tab_title',
							// translators: %s is the tab title
							'label'    => sprintf( __( 'Tab Title: %s', 'voxfor-multilanguage' ), $tab['title'] ),
						);
						$segments[]  = $this->enrichSegment( $tab_segment, $language );
					}
				}

				// 9. Get all existing translations for this product from database
				$existing_translations = $this->getExistingProductTranslations( $post_id, $language );
				foreach ( $existing_translations as $translation ) {
					// Skip if we already have this segment
					$segment_exists = false;
					foreach ( $segments as $existing_segment ) {
						if ( $existing_segment['original'] === $translation->source_text ) {
							$segment_exists = true;
							break;
						}
					}

					if ( ! $segment_exists && ! empty( $translation->source_text ) ) {
						$db_segment = array(
							'id'       => 'product_db_' . $translation->id,
							'original' => $translation->source_text,
							'context'  => $translation->context,
							'type'     => 'database',
							'label'    => sprintf(
								/* translators: %s is the context label for the database entry */
								__( 'Database Entry: %s', 'voxfor-multilanguage' ),
								$this->getContextLabel( $translation->context )
							),
						);
						$segments[] = $this->enrichSegment( $db_segment, $language );
					}
				}

				return $segments;
			}
		}

		// For regular posts/pages
		$content_segments = $this->extractSegments( $post->post_content, 'post_content' );
		$title_segment    = array(
			'id'       => 'title_' . $post_id,
			'original' => $post->post_title,
			'context'  => 'post_title',
			'type'     => 'title',
		);

		// Add title
		$segments[] = $this->enrichSegment( $title_segment, $language );

		// Add content segments
		foreach ( $content_segments as $segment ) {
			$segments[] = $this->enrichSegment( $segment, $language );
		}

		// Get meta fields
		$meta_fields = array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', 'rank_math_title', 'rank_math_description' );
		foreach ( $meta_fields as $field ) {
			$value = get_post_meta( $post_id, $field, true );
			if ( ! empty( $value ) ) {
				$segment    = array(
					'id'       => 'meta_' . $field . '_' . $post_id,
					'original' => $value,
					'context'  => 'meta_' . $field,
					'type'     => 'meta',
				);
				$segments[] = $this->enrichSegment( $segment, $language );
			}
		}

		return $segments;
	}

	/**
	 * Extract segments from content
	 */
	private function extractSegments( $content, $context_base ) {
		$segments = array();

		// Split content into paragraphs and headings
		$blocks = preg_split( '/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

		$current_text  = '';
		$segment_count = 0;

		foreach ( $blocks as $block ) {
			if ( preg_match( '/^<(p|h[1-6]|li|td|th|div|span)[^>]*>$/i', $block ) ) {
				// Start of a block
				$current_text = '';
			} elseif ( preg_match( '/^<\/(p|h[1-6]|li|td|th|div|span)>$/i', $block ) ) {
				// End of a block
				if ( ! empty( trim( $current_text ) ) ) {
					$segments[] = array(
						'id'       => $context_base . '_' . $segment_count,
						'original' => trim( $current_text ),
						'context'  => $context_base,
						'type'     => 'content',
					);
					++$segment_count;
				}
				$current_text = '';
			} else {
				// Text content
				$current_text .= $block;
			}
		}

		return $segments;
	}

	/**
	 * Enrich segment with translation data
	 */
	private function enrichSegment( $segment, $language ) {
		// Get existing translation
		$translation_data = $this->memory->getTranslationWithMeta(
			$segment['original'],
			$language,
			$segment['context']
		);

		if ( $translation_data ) {
			$segment['translation'] = $translation_data['translated_text'];
			$segment['status']      = $translation_data['status'];
			$segment['provider']    = $translation_data['provider'];
			$segment['locked']      = ( $translation_data['status'] === 'locked' );
		} else {
			$segment['translation'] = '';
			$segment['status']      = 'untranslated';
			$segment['provider']    = '';
			$segment['locked']      = false;
		}

		return $segment;
	}

	/**
	 * Get translation with metadata
	 */
	private function getTranslationWithMeta( $source_text, $language, $context ) {
		global $wpdb;
		$table = $wpdb->prefix . 'voxfor_ml_translations';

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}voxfor_ml_translations` 
             WHERE source_hash = %s AND language_code = %s AND context = %s",
				md5( $source_text ),
				$language,
				$context
			),
			ARRAY_A
		);
	}

	/**
	 * Normalize text for consistent hashing (same as TranslationMemory)
	 */
	private function normalizeText( $text ) {
		// Remove extra whitespace, normalize line endings, strip HTML tags
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );
		return strtolower( $text );
	}

	/**
	 * Get existing translations for a product from the database
	 */
	private function getExistingProductTranslations( $post_id, $language ) {
		global $wpdb;

		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translations';

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, source_text, translated_text, context 
             FROM `{$wpdb->prefix}voxfor_ml_translations` 
             WHERE post_id = %d AND language_code = %s 
             ORDER BY context, updated_at DESC",
				$post_id,
				$language
			)
		);

		return $results ?: array();
	}

	/**
	 * Get human-readable label for context
	 */
	private function getContextLabel( $context ) {
		$labels = array(
			'product_name'              => __( 'Product Name', 'voxfor-multilanguage' ),
			'product_short_description' => __( 'Short Description', 'voxfor-multilanguage' ),
			'content'                   => __( 'Product Description', 'voxfor-multilanguage' ),
			'product_category'          => __( 'Category', 'voxfor-multilanguage' ),
			'product_tag'               => __( 'Tag', 'voxfor-multilanguage' ),
			'product_attribute'         => __( 'Attribute', 'voxfor-multilanguage' ),
			'woocommerce_button'        => __( 'Button Text', 'voxfor-multilanguage' ),
			'woocommerce_tab_title'     => __( 'Tab Title', 'voxfor-multilanguage' ),
			'title'                     => __( 'Title', 'voxfor-multilanguage' ),
			'excerpt'                   => __( 'Excerpt', 'voxfor-multilanguage' ),
			'menu_item'                 => __( 'Menu Item', 'voxfor-multilanguage' ),
			'widget_title'              => __( 'Widget Title', 'voxfor-multilanguage' ),
			'widget_text'               => __( 'Widget Text', 'voxfor-multilanguage' ),
			'image_alt'                 => __( 'Image Alt Text', 'voxfor-multilanguage' ),
			'image_title'               => __( 'Image Title', 'voxfor-multilanguage' ),
			'general'                   => __( 'General Content', 'voxfor-multilanguage' ),
		);

		return $labels[ $context ] ?? ucfirst( str_replace( '_', ' ', $context ) );
	}
}
