<?php
namespace VoxforML\Utils;

use VoxforML\Core\Plugin;
use VoxforML\Database\TranslationMemory;

/**
 * Scanner for individual content items (pages, posts, products, templates)
 */
class IndividualContentScanner {
	private $plugin;
	private $memory;

	public function __construct() {
		$this->plugin = Plugin::getInstance();
		$this->memory = new TranslationMemory();
	}

	/**
	 * Get available content types
	 */
	public function getAvailableContentTypes() {
		$content_types = array();
		
		// Pages
		$pages_count = wp_count_posts( 'page' )->publish ?? 0;
		if ( $pages_count > 0 ) {
			$content_types[] = array(
				'value' => 'pages',
				'label' => __( 'Pages', 'voxfor-multilanguage' ),
				'icon'  => 'dashicons-admin-page',
				'count' => $pages_count,
			);
		}
		
		// Posts  
		$posts_count = wp_count_posts( 'post' )->publish ?? 0;
		if ( $posts_count > 0 ) {
			$content_types[] = array(
				'value' => 'posts',
				'label' => __( 'Posts', 'voxfor-multilanguage' ),
				'icon'  => 'dashicons-admin-post',
				'count' => $posts_count,
			);
		}

		// Add WooCommerce products
		if ( class_exists( 'WooCommerce' ) ) {
			$products_count = wp_count_posts( 'product' )->publish ?? 0;
			if ( $products_count > 0 ) {
				$content_types[] = array(
					'value' => 'products',
					'label' => __( 'Products', 'voxfor-multilanguage' ),
					'icon'  => 'dashicons-products',
					'count' => $products_count,
				);
			}
		}

		// Add Elementor templates
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$elementor_count = wp_count_posts( 'elementor_library' )->publish ?? 0;
			if ( $elementor_count > 0 ) {
				$content_types[] = array(
					'value' => 'elementor_templates',
					'label' => __( 'Elementor Templates', 'voxfor-multilanguage' ),
					'icon'  => 'dashicons-layout',
					'count' => $elementor_count,
				);
			}
		}
		
		// Add Elementor Header & Footer Builder if available
		if ( class_exists( 'ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			global $wpdb;
			$hf_count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching 
				"SELECT COUNT(*) FROM {$wpdb->posts} p 
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
				 WHERE p.post_type = 'elementor_library' 
				 AND p.post_status = 'publish' 
				 AND pm.meta_key = '_elementor_template_type' 
				 AND pm.meta_value IN ('header', 'footer')"
			);
			
			if ( $hf_count > 0 ) {
				$content_types[] = array(
					'value' => 'elementor_hf',
					'label' => __( 'Elementor Header & Footer Builder', 'voxfor-multilanguage' ),
					'icon'  => 'dashicons-editor-kitchensink',
					'count' => $hf_count,
				);
			}
		}

		// Add custom post types
		$custom_post_types = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		);

		foreach ( $custom_post_types as $post_type => $post_type_obj ) {
			// Skip WooCommerce and Elementor types (already handled)
			if ( in_array( $post_type, array( 'product', 'elementor_library' ) ) ) {
				continue;
			}

			$count = wp_count_posts( $post_type )->publish ?? 0;
			if ( $count > 0 ) {
				$content_types[] = array(
					'value' => $post_type,
					'label' => $post_type_obj->labels->name,
					'icon'  => $post_type_obj->menu_icon ?: 'dashicons-admin-post',
					'count' => $count,
				);
			}
		}

		return $content_types;
	}

	/**
	 * Get content list with pagination and filtering
	 */
	public function getContentList( $content_type, $page = 1, $per_page = 20, $search = '', $filters = array() ) {
		$offset = ( $page - 1 ) * $per_page;

		$args = array(
			'post_type'      => $this->getPostTypeFromContentType( $content_type ),
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		
		// Special handling for Elementor Header & Footer
		if ( $content_type === 'elementor_hf' ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_elementor_template_type',
					'value'   => array( 'header', 'footer' ),
					'compare' => 'IN',
				),
			);
		}

		// Add search
		if ( ! empty( $search ) ) {
			$args['s'] = sanitize_text_field( $search );
		}

		// Add filters
		if ( ! empty( $filters['category'] ) && $filters['category'] !== 'all' ) {
			if ( $content_type === 'posts' ) {
				$args['cat'] = intval( $filters['category'] );
			} elseif ( $content_type === 'products' ) {
				$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => intval( $filters['category'] ),
					),
				);
			}
		}

		if ( ! empty( $filters['status'] ) && $filters['status'] !== 'all' ) {
			$args['post_status'] = sanitize_text_field( $filters['status'] );
		}

		// Get posts
		$query = new \WP_Query( $args );
		$posts = $query->posts;

		// Get total count for pagination
		$total_args                   = $args;
		$total_args['posts_per_page'] = -1;
		$total_args['fields']         = 'ids';
		unset( $total_args['offset'] );
		$total_query = new \WP_Query( $total_args );
		$total_count = $total_query->found_posts;

		// Format posts for frontend
		$formatted_posts = array();
		foreach ( $posts as $post ) {
			$formatted_posts[] = $this->formatPostForList( $post, $content_type );
		}

		return array(
			'items'      => $formatted_posts,
			'total_pages' => ceil( $total_count / $per_page ),
			'current_page' => $page,
			'per_page'     => $per_page,
			'total_count'  => $total_count,
		);
	}

	/**
	 * Get filter options for content type
	 */
	public function getFilterOptions( $content_type ) {
		$filters = array(
			'status' => array(
				'label'   => __( 'Status', 'voxfor-multilanguage' ),
				'options' => array(
					'all'     => __( 'All Statuses', 'voxfor-multilanguage' ),
					'publish' => __( 'Published', 'voxfor-multilanguage' ),
					'draft'   => __( 'Draft', 'voxfor-multilanguage' ),
					'private' => __( 'Private', 'voxfor-multilanguage' ),
				),
			),
		);

		// Add category filter for posts
		if ( $content_type === 'posts' ) {
			$categories       = get_categories( array( 'hide_empty' => false ) );
			$category_options = array( 'all' => __( 'All Categories', 'voxfor-multilanguage' ) );

			foreach ( $categories as $category ) {
				$category_options[ $category->term_id ] = $category->name;
			}

			$filters['category'] = array(
				'label'   => __( 'Category', 'voxfor-multilanguage' ),
				'options' => $category_options,
			);
		}

		// Add category filter for products
		if ( $content_type === 'products' && class_exists( 'WooCommerce' ) ) {
			$categories       = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				)
			);
			$category_options = array( 'all' => __( 'All Categories', 'voxfor-multilanguage' ) );

			if ( ! is_wp_error( $categories ) ) {
				foreach ( $categories as $category ) {
					$category_options[ $category->term_id ] = $category->name;
				}
			}

			$filters['category'] = array(
				'label'   => __( 'Category', 'voxfor-multilanguage' ),
				'options' => $category_options,
			);
		}

		return $filters;
	}

	/**
	 * Extract translatable content from a post
	 */
	public function extractTranslatableContent( $post_id, $translation_scope = 'full' ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$content = array(
			'post_id'   => $post_id,
			'post_type' => $post->post_type,
			'texts'     => array(),
		);

		// Extract based on scope
		switch ( $translation_scope ) {
			case 'title_only':
				$content['texts'][] = array(
					'text'    => $post->post_title,
					'context' => 'title',
					'field'   => 'post_title',
				);
				break;

			case 'content_only':
				if ( ! empty( $post->post_content ) ) {
					$content['texts'][] = array(
						'text'    => $post->post_content,
						'context' => 'content',
						'field'   => 'post_content',
					);
				}
				break;

			case 'meta_only':
				$content['texts'] = array_merge( $content['texts'], $this->extractMetaContent( $post_id ) );
				break;

			case 'full':
			default:
				// Title
				$content['texts'][] = array(
					'text'    => $post->post_title,
					'context' => 'title',
					'field'   => 'post_title',
				);

				// Content
				if ( ! empty( $post->post_content ) ) {
					$content['texts'][] = array(
						'text'    => $post->post_content,
						'context' => 'content',
						'field'   => 'post_content',
					);
				}

				// Excerpt
				if ( ! empty( $post->post_excerpt ) ) {
					$content['texts'][] = array(
						'text'    => $post->post_excerpt,
						'context' => 'excerpt',
						'field'   => 'post_excerpt',
					);
				}

				// Meta content
				$content['texts'] = array_merge( $content['texts'], $this->extractMetaContent( $post_id ) );

				// Post type specific content
				$content['texts'] = array_merge( $content['texts'], $this->extractPostTypeSpecificContent( $post_id, $post->post_type ) );
				break;
		}

		return $content;
	}

	/**
	 * Extract meta content (SEO, custom fields) - RESTRICTED to post-specific content only
	 */
	private function extractMetaContent( $post_id ) {
		$meta_texts = array();
		
		// Get post info for header/footer detection
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $meta_texts;
		}
		
		// Skip meta translation for header/footer templates
		if ( $this->isHeaderFooterTemplate( $post ) ) {
			return $meta_texts;
		}

		// Only extract SEO meta that is specifically set for this post
		// Skip if using default/template values that might be shared

		// Yoast SEO - only if specifically set for this post
		$yoast_title = get_post_meta( $post_id, '_yoast_wpseo_title', true );
		if ( ! empty( $yoast_title ) && ! $this->isTemplateValue( $yoast_title ) ) {
			$meta_texts[] = array(
				'text'    => $yoast_title,
				'context' => 'seo_title',
				'field'   => '_yoast_wpseo_title',
			);
		}

		$yoast_desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		if ( ! empty( $yoast_desc ) && ! $this->isTemplateValue( $yoast_desc ) ) {
			$meta_texts[] = array(
				'text'    => $yoast_desc,
				'context' => 'seo_description',
				'field'   => '_yoast_wpseo_metadesc',
			);
		}

		// RankMath SEO - only if specifically set for this post
		$rankmath_title = get_post_meta( $post_id, 'rank_math_title', true );
		if ( ! empty( $rankmath_title ) && ! $this->isTemplateValue( $rankmath_title ) ) {
			$meta_texts[] = array(
				'text'    => $rankmath_title,
				'context' => 'seo_title',
				'field'   => 'rank_math_title',
			);
		}

		$rankmath_desc = get_post_meta( $post_id, 'rank_math_description', true );
		if ( ! empty( $rankmath_desc ) && ! $this->isTemplateValue( $rankmath_desc ) ) {
			$meta_texts[] = array(
				'text'    => $rankmath_desc,
				'context' => 'seo_description',
				'field'   => 'rank_math_description',
			);
		}
		
		// WordPress Core Meta (fallback if no SEO plugin meta found)
		$has_seo_meta = false;
		foreach ( $meta_texts as $meta_item ) {
			if ( in_array( $meta_item['context'], array( 'seo_title', 'seo_description' ) ) ) {
				$has_seo_meta = true;
				break;
			}
		}
		
		// If no SEO plugin meta found, use WordPress core meta
		if ( ! $has_seo_meta ) {
			// Use post title as meta title (if not already added as title context)
			$title_exists = false;
			foreach ( $meta_texts as $meta_item ) {
				if ( $meta_item['context'] === 'seo_title' ) {
					$title_exists = true;
					break;
				}
			}
			
			if ( ! $title_exists && ! empty( $post->post_title ) ) {
				$meta_texts[] = array(
					'text'    => $post->post_title,
					'context' => 'seo_title',
					'field'   => 'wp_core_title',
				);
			}
			
			// Use post excerpt as meta description (if available)
			if ( ! empty( $post->post_excerpt ) ) {
				$meta_texts[] = array(
					'text'    => $post->post_excerpt,
					'context' => 'seo_description', 
					'field'   => 'wp_core_excerpt',
				);
			}
		}
		
		// All in One SEO Pack
		$aioseo_title = get_post_meta( $post_id, '_aioseo_title', true );
		if ( ! empty( $aioseo_title ) && ! $this->isTemplateValue( $aioseo_title ) ) {
			$meta_texts[] = array(
				'text'    => $aioseo_title,
				'context' => 'seo_title',
				'field'   => '_aioseo_title',
			);
		}

		$aioseo_desc = get_post_meta( $post_id, '_aioseo_description', true );
		if ( ! empty( $aioseo_desc ) && ! $this->isTemplateValue( $aioseo_desc ) ) {
			$meta_texts[] = array(
				'text'    => $aioseo_desc,
				'context' => 'seo_description',
				'field'   => '_aioseo_description',
			);
		}

		return $meta_texts;
	}

	/**
	 * Check if a post is a header/footer template
	 */
	private function isHeaderFooterTemplate( $post ) {
		// Check post type for Elementor Header & Footer Builder
		if ( $post->post_type === 'elementor_hf' ) {
			return true;
		}
		
		// Check for other header/footer template indicators
		$template_types = array(
			'elementor_library', // Elementor templates
			'header_footer',     // Other header/footer builders
			'hf_template',       // Header Footer template
		);
		
		if ( in_array( $post->post_type, $template_types ) ) {
			// Check meta to confirm it's header/footer
			$template_type = get_post_meta( $post->ID, '_elementor_template_type', true );
			if ( in_array( $template_type, array( 'header', 'footer' ) ) ) {
				return true;
			}
		}
		
		// Check post title/slug for header/footer indicators
		$title_slug = strtolower( $post->post_title . ' ' . $post->post_name );
		$header_footer_keywords = array( 'header', 'footer', 'navigation', 'nav', 'menu' );
		
		foreach ( $header_footer_keywords as $keyword ) {
			if ( strpos( $title_slug, $keyword ) !== false ) {
				// Additional check: only consider it header/footer if it's a template post type
				if ( in_array( $post->post_type, array( 'elementor_library', 'elementor_hf' ) ) ) {
					return true;
				}
			}
		}
		
		return false;
	}

	/**
	 * Check if a value appears to be a template/placeholder that might be shared
	 */
	private function isTemplateValue( $value ) {
		// Skip values that contain template variables or are very generic
		$template_patterns = array(
			'%%title%%',
			'%%sitename%%',
			'%%sep%%',
			'%%page%%',
			'%%currentdate%%',
			'%%currentyear%%',
			'%title%',
			'%sitename%',
			'%sep%',
		);

		$lower_value = strtolower( $value );

		foreach ( $template_patterns as $pattern ) {
			if ( strpos( $lower_value, strtolower( $pattern ) ) !== false ) {
				return true;
			}
		}

		// Skip very short or generic values
		if ( strlen( trim( $value ) ) < 10 ) {
			return true;
		}

		return false;
	}

	/**
	 * Extract post type specific content
	 */
	private function extractPostTypeSpecificContent( $post_id, $post_type ) {
		$specific_texts = array();

		switch ( $post_type ) {
			case 'product':
				$specific_texts = $this->extractProductContent( $post_id );
				break;

			case 'elementor_library':
				$specific_texts = $this->extractElementorContent( $post_id );
				break;
		}

		return $specific_texts;
	}

	/**
	 * Extract WooCommerce product specific content (RESTRICTED - only product-specific content)
	 */
	private function extractProductContent( $post_id ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}

		$product_texts = array();
		$product       = wc_get_product( $post_id );

		if ( ! $product ) {
			return array();
		}

		// Short description (product-specific)
		$short_desc = $product->get_short_description();
		if ( ! empty( $short_desc ) ) {
			$product_texts[] = array(
				'text'    => $short_desc,
				'context' => 'product_short_description',
				'field'   => '_product_short_description',
			);
		}

		// REMOVED: Product attributes and taxonomy terms
		// These are often shared across products and cause unwanted cross-product translations
		// Only translate content that is unique to this specific product

		return $product_texts;
	}

	/**
	 * Extract Elementor template content
	 */
	private function extractElementorContent( $post_id ) {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return array();
		}

		$elementor_texts = array();
		$elementor_data  = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! empty( $elementor_data ) ) {
			if ( is_string( $elementor_data ) ) {
				$elementor_data = json_decode( $elementor_data, true );
			}

			if ( is_array( $elementor_data ) ) {
				$this->extractElementorTextsRecursive( $elementor_data, $elementor_texts );
			}
		}

		return $elementor_texts;
	}

	/**
	 * Recursively extract texts from Elementor data
	 */
	private function extractElementorTextsRecursive( $data, &$texts ) {
		if ( ! is_array( $data ) ) {
			return;
		}

		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) && strlen( trim( $value ) ) > 1 ) {
				$text_fields = array(
					'title',
					'text',
					'content',
					'description',
					'button_text',
					'link_text',
					'heading',
					'subtitle',
					'caption',
					'label',
				);

				if ( in_array( $key, $text_fields ) ||
					strpos( $key, 'text' ) !== false ||
					strpos( $key, 'title' ) !== false ||
					strpos( $key, 'content' ) !== false ) {

					$clean_text = wp_strip_all_tags( $value );
					$clean_text = trim( $clean_text );

					if ( strlen( $clean_text ) > 1 && ! is_numeric( $clean_text ) ) {
						$texts[] = array(
							'text'    => $clean_text,
							'context' => 'elementor_content',
							'field'   => 'elementor_' . $key,
						);
					}
				}
			} elseif ( is_array( $value ) ) {
				$this->extractElementorTextsRecursive( $value, $texts );
			}
		}
	}

	/**
	 * Get post type from content type
	 */
	private function getPostTypeFromContentType( $content_type ) {
		$mapping = array(
			'pages'               => 'page',
			'posts'               => 'post',
			'products'            => 'product',
			'elementor_templates' => 'elementor_library',
			'elementor_hf'        => 'elementor_library',
		);

		return $mapping[ $content_type ] ?? $content_type;
	}

	/**
	 * Format post for list display
	 */
	private function formatPostForList( $post, $content_type ) {
		$formatted = array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
			'date'      => get_the_date( 'Y-m-d H:i:s', $post->ID ),
			'edit_link' => get_edit_post_link( $post->ID ),
			'view_link' => get_permalink( $post->ID ),
			'type'      => $content_type,
		);

		// Add type-specific information
		switch ( $content_type ) {
			case 'posts':
				$categories            = get_the_category( $post->ID );
				$formatted['category'] = ! empty( $categories ) ? $categories[0]->name : '';
				break;

			case 'products':
				if ( class_exists( 'WooCommerce' ) ) {
					$product            = wc_get_product( $post->ID );
					$formatted['price'] = $product ? $product->get_price_html() : '';
					$formatted['sku']   = $product ? $product->get_sku() : '';
				}
				break;

			case 'elementor_templates':
				$template_type              = get_post_meta( $post->ID, '_elementor_template_type', true );
				$formatted['template_type'] = $template_type ?: 'page';
				break;
		}

		// Check translation status
		$formatted['translation_status'] = $this->getTranslationStatus( $post->ID );

		return $formatted;
	}

	/**
	 * Get translation status for a post
	 */
	private function getTranslationStatus( $post_id ) {
		$enabled_languages = get_option( 'voxfor_ml_languages', array( 'en' ) );
		// Ensure $enabled_languages is always an array
		if ( ! is_array( $enabled_languages ) ) {
			$enabled_languages = array( 'en' );
		}
		$status = array();
		$post   = get_post( $post_id );
		
		if ( ! $post ) {
			return $status;
		}

		foreach ( $enabled_languages as $lang_code ) {
			if ( $lang_code === 'en' ) {
				continue;
			}

			// Check multiple aspects of the post for translations
			$title_translated   = false;
			$content_translated = false;
			$excerpt_translated = false;
			
			// Check title translation
			if ( ! empty( $post->post_title ) ) {
				$title_translated = $this->memory->getTranslation(
					$post->post_title,
					$lang_code,
					'title',
					$post_id
				) !== false;
				
				// Also check post_title context for compatibility
				if ( ! $title_translated ) {
					$title_translated = $this->memory->getTranslation(
						$post->post_title,
						$lang_code,
						'post_title',
						$post_id
					) !== false;
				}
		}
		
		// Check content translation (only for posts with substantial content)
		if ( ! empty( $post->post_content ) && strlen( trim( wp_strip_all_tags( $post->post_content ) ) ) > 10 ) {
			// For content, we check if any significant portion is translated
			// We'll check the first 200 characters as a sample
			$content_sample = substr( trim( wp_strip_all_tags( $post->post_content ) ), 0, 200 );
			if ( ! empty( $content_sample ) ) {
				$content_translated = $this->memory->getTranslation(
					$content_sample,
					$lang_code,
					'content',
					$post_id
				) !== false;
					
					// Also check for full content translation
					if ( ! $content_translated ) {
						$content_translated = $this->memory->getTranslation(
							$post->post_content,
							$lang_code,
							'content',
							$post_id
						) !== false;
					}
				}
			}
			
			// Check excerpt translation if it exists
			if ( ! empty( $post->post_excerpt ) ) {
				$excerpt_translated = $this->memory->getTranslation(
					$post->post_excerpt,
					$lang_code,
					'excerpt',
					$post_id
				) !== false;
		}
		
		// Determine overall translation status
		// For pages/posts with content: need both title and content translated
		// For pages/posts without substantial content: only need title translated
		$has_substantial_content = ! empty( $post->post_content ) && strlen( trim( wp_strip_all_tags( $post->post_content ) ) ) > 10;
		
		if ( $has_substantial_content ) {
			// Need both title and content translated for "translated" status
			$is_translated = $title_translated && $content_translated;
			} else {
				// Only need title translated for pages without substantial content
				$is_translated = $title_translated;
			}
			
			// If excerpt exists, it should also be translated
			if ( ! empty( $post->post_excerpt ) && $is_translated ) {
				$is_translated = $is_translated && $excerpt_translated;
			}

			$status[ $lang_code ] = $is_translated ? 'translated' : 'not_translated';
		}

		return $status;
	}
}
