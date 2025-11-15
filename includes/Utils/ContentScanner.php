<?php
namespace VoxforML\Utils;

use VoxforML\Core\Plugin;
use VoxforML\Database\TranslationMemory;

/**
 * Comprehensive Content Scanner
 * Scans entire pages/posts/products to extract ALL translatable content
 */
class ContentScanner {
	private $plugin;
	private $memory;
	private $processor;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin    = Plugin::getInstance();
		$this->memory    = new TranslationMemory();
		$this->processor = new TextProcessor();
	}

	/**
	 * Scan complete page/post/product content
	 */
	public function scanCompleteContent( $post_id, $language ) {
		// Get the complete rendered HTML of the page
		$complete_html = $this->getCompletePageHTML( $post_id );

		if ( empty( $complete_html ) ) {
			return array( 'error' => 'Could not retrieve page content' );
		}

		// Extract all translatable text from the complete HTML
		$extracted_content = $this->extractAllTranslatableContent( $complete_html );

		// Organize content by type and context
		$organized_content = $this->organizeContentByContext( $extracted_content, $post_id );

		return array(
			'success'     => true,
			'total_items' => count( $organized_content ),
			'content'     => $organized_content,
			'html_length' => strlen( $complete_html ),
		);
	}

	/**
	 * Get complete rendered HTML of a page/post/product
	 */
	private function getCompletePageHTML( $post_id ) {
		// Get the post
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		// Get the permalink
		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return false;
		}

		// Use WordPress's built-in functions to get the complete page
		// We'll simulate a frontend request to get the complete rendered HTML

		// Method 1: Try to get via HTTP request (most comprehensive)
		$html = $this->getPageViaHTTP( $permalink );

		if ( empty( $html ) ) {
			// Method 2: Fallback to building content manually
			$html = $this->buildPageContentManually( $post_id );
		}

		return $html;
	}

	/**
	 * Get page content via HTTP request (most comprehensive)
	 */
	private function getPageViaHTTP( $url ) {
		// Add query parameter to prevent caching and get fresh content
		$url = add_query_arg( 'voxfor_scan', '1', $url );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'user-agent' => 'VoxforML Content Scanner',
				'headers'    => array(
					'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body        = wp_remote_retrieve_body( $response );
		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code !== 200 ) {
			return false;
		}

		return $body;
	}

	/**
	 * Build page content manually (fallback method)
	 */
	private function buildPageContentManually( $post_id ) {
		global $wp_query, $post;

		// Save current state
		$original_post  = $post;
		$original_query = $wp_query;

		// Set up the post
		$post = get_post( $post_id );
		setup_postdata( $post );

		// Start output buffering
		ob_start();

		// Get header
		get_header();

		// Get main content
		if ( is_product( $post_id ) ) {
			// WooCommerce product
			wc_get_template_part( 'content', 'single-product' );
		} else {
			// Regular post/page
			?>
			<div class="content-area">
				<main class="site-main">
					<article id="post-<?php echo intval( $post_id ); ?>" <?php post_class(); ?>>
						<header class="entry-header">
							<h1 class="entry-title"><?php echo esc_html( get_the_title() ); ?></h1>
						</header>
						<div class="entry-content">
							<?php echo wp_kses_post( apply_filters( 'the_content', get_the_content() ) ); ?>
						</div>
					</article>
				</main>
			</div>
			<?php
		}

		// Get sidebar if exists
		if ( is_active_sidebar( 'sidebar-1' ) ) {
			get_sidebar();
		}

		// Get footer
		get_footer();

		$html = ob_get_clean();

		// Restore original state
		$post     = $original_post;
		$wp_query = $original_query;
		wp_reset_postdata();

		return $html;
	}

	/**
	 * Extract ALL translatable content from HTML
	 */
	private function extractAllTranslatableContent( $html ) {
		$extracted_content = array();

		// Create DOM document
		$dom                     = new \DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput       = false;

		// Load HTML with error suppression
		libxml_use_internal_errors( true );
		$success = $dom->loadHTML(
			'<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		if ( ! $success ) {
			// Fallback to regex-based extraction
			return $this->extractContentWithRegex( $html );
		}

		$xpath = new \DOMXPath( $dom );

		// Extract different types of content

		// 1. Text nodes (visible text)
		$text_nodes = $xpath->query( '//text()[normalize-space() != ""]' );
		foreach ( $text_nodes as $node ) {
			$text = trim( $node->nodeValue );
			if ( $this->isTranslatableText( $text ) ) {
				$extracted_content[] = array(
					'type'       => 'text_node',
					'content'    => $text,
					'context'    => $this->getNodeContext( $node ),
					'parent_tag' => $node->parentNode->nodeName ?? 'unknown',
				);
			}
		}

		// 2. Attributes that should be translated
		$translatable_attrs = array( 'alt', 'title', 'placeholder', 'aria-label', 'data-title' );
		foreach ( $translatable_attrs as $attr ) {
			$nodes = $xpath->query( '//*[@' . $attr . ']' );
			foreach ( $nodes as $node ) {
				$value = trim( $node->getAttribute( $attr ) );
				if ( $this->isTranslatableText( $value ) ) {
					$extracted_content[] = array(
						'type'       => 'attribute',
						'content'    => $value,
						'context'    => 'attribute_' . $attr,
						'parent_tag' => $node->nodeName,
						'attribute'  => $attr,
					);
				}
			}
		}

		// 3. Meta tags
		$meta_nodes = $xpath->query( '//meta[@name="description" or @name="keywords" or @property="og:title" or @property="og:description"]' );
		foreach ( $meta_nodes as $node ) {
			$content = trim( $node->getAttribute( 'content' ) );
			if ( $this->isTranslatableText( $content ) ) {
				$extracted_content[] = array(
					'type'       => 'meta',
					'content'    => $content,
					'context'    => 'meta_' . ( $node->getAttribute( 'name' ) ?: $node->getAttribute( 'property' ) ),
					'parent_tag' => 'meta',
				);
			}
		}

		// 4. Title tag
		$title_nodes = $xpath->query( '//title' );
		foreach ( $title_nodes as $node ) {
			$title = trim( $node->textContent );
			if ( $this->isTranslatableText( $title ) ) {
				$extracted_content[] = array(
					'type'       => 'title',
					'content'    => $title,
					'context'    => 'page_title',
					'parent_tag' => 'title',
				);
			}
		}

		// 5. Structured data (JSON-LD)
		$script_nodes = $xpath->query( '//script[@type="application/ld+json"]' );
		foreach ( $script_nodes as $node ) {
			$json_content    = trim( $node->textContent );
			$structured_data = json_decode( $json_content, true );
			if ( $structured_data ) {
				$this->extractFromStructuredData( $structured_data, $extracted_content );
			}
		}

		return $extracted_content;
	}

	/**
	 * Fallback regex-based content extraction
	 */
	private function extractContentWithRegex( $html ) {
		$extracted_content = array();

		// Extract text between HTML tags
		preg_match_all( '/>([^<]+)</u', $html, $matches );
		foreach ( $matches[1] as $text ) {
			$text = trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
			if ( $this->isTranslatableText( $text ) ) {
				$extracted_content[] = array(
					'type'       => 'text_node',
					'content'    => $text,
					'context'    => 'general',
					'parent_tag' => 'unknown',
				);
			}
		}

		// Extract attributes
		$attrs = array( 'alt', 'title', 'placeholder', 'aria-label' );
		foreach ( $attrs as $attr ) {
			preg_match_all( '/' . $attr . '=["\']([^"\']+)["\']/', $html, $matches );
			foreach ( $matches[1] as $value ) {
				$value = trim( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) );
				if ( $this->isTranslatableText( $value ) ) {
					$extracted_content[] = array(
						'type'       => 'attribute',
						'content'    => $value,
						'context'    => 'attribute_' . $attr,
						'parent_tag' => 'unknown',
						'attribute'  => $attr,
					);
				}
			}
		}

		return $extracted_content;
	}

	/**
	 * Check if text is translatable
	 */
	private function isTranslatableText( $text ) {
		// Skip empty or very short text
		if ( empty( $text ) || strlen( $text ) < 2 ) {
			return false;
		}

		// Skip pure numbers
		if ( is_numeric( $text ) ) {
			return false;
		}

		// Skip URLs
		if ( filter_var( $text, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		// Skip email addresses
		if ( filter_var( $text, FILTER_VALIDATE_EMAIL ) ) {
			return false;
		}

		// Skip CSS/JS code patterns
		if ( preg_match( '/^[{}();,.\s\-_#]+$/', $text ) ) {
			return false;
		}

		// Skip HTML entities only
		if ( preg_match( '/^&[a-zA-Z0-9]+;$/', $text ) ) {
			return false;
		}

		// Must contain at least some letters
		if ( ! preg_match( '/[a-zA-Z]/', $text ) ) {
			return false;
		}

		// Skip very technical strings
		$technical_patterns = array(
			'/^wp-/',
			'/^admin-/',
			'/^[A-Z_]+$/',
			'/^\d{4}-\d{2}-\d{2}/',
			'/^[a-f0-9]{32}$/',
			'/^[a-zA-Z0-9_-]+\.(js|css|png|jpg|gif|svg)$/',
		);

		foreach ( $technical_patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get context for a DOM node
	 */
	private function getNodeContext( $node ) {
		$parent = $node->parentNode;
		if ( ! $parent ) {
			return 'general';
		}

		$tag   = strtolower( $parent->nodeName );
		$class = $parent->getAttribute( 'class' );
		$id    = $parent->getAttribute( 'id' );

		// Determine context based on parent element
		$context_map = array(
			'h1'       => 'heading_1',
			'h2'       => 'heading_2',
			'h3'       => 'heading_3',
			'h4'       => 'heading_4',
			'h5'       => 'heading_5',
			'h6'       => 'heading_6',
			'p'        => 'paragraph',
			'a'        => 'link',
			'button'   => 'button',
			'label'    => 'form_label',
			'input'    => 'form_input',
			'textarea' => 'form_textarea',
			'select'   => 'form_select',
			'option'   => 'form_option',
			'li'       => 'list_item',
			'td'       => 'table_cell',
			'th'       => 'table_header',
			'span'     => 'inline_text',
			'div'      => 'block_text',
		);

		$base_context = $context_map[ $tag ] ?? 'general';

		// Add specific contexts based on class or ID
		if ( strpos( $class, 'menu' ) !== false ) {
			return 'menu_item';
		}
		if ( strpos( $class, 'widget' ) !== false ) {
			return 'widget_text';
		}
		if ( strpos( $class, 'footer' ) !== false ) {
			return 'footer_text';
		}
		if ( strpos( $class, 'header' ) !== false ) {
			return 'header_text';
		}
		if ( strpos( $class, 'product' ) !== false ) {
			return 'product_text';
		}
		if ( strpos( $class, 'price' ) !== false ) {
			return 'price_text';
		}
		if ( strpos( $class, 'button' ) !== false || strpos( $class, 'btn' ) !== false ) {
			return 'button';
		}

		return $base_context;
	}

	/**
	 * Extract translatable content from structured data
	 */
	private function extractFromStructuredData( $data, &$extracted_content ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( is_string( $value ) && $this->isTranslatableText( $value ) ) {
					$extracted_content[] = array(
						'type'       => 'structured_data',
						'content'    => $value,
						'context'    => 'structured_data_' . $key,
						'parent_tag' => 'script',
					);
				} elseif ( is_array( $value ) ) {
					$this->extractFromStructuredData( $value, $extracted_content );
				}
			}
		}
	}

	/**
	 * Organize content by context and priority
	 */
	private function organizeContentByContext( $extracted_content, $post_id ) {
		$organized    = array();
		$seen_content = array(); // Prevent duplicates

		foreach ( $extracted_content as $item ) {
			$content = trim( $item['content'] );

			// Skip duplicates
			$content_hash = md5( $content . $item['context'] );
			if ( isset( $seen_content[ $content_hash ] ) ) {
				continue;
			}
			$seen_content[ $content_hash ] = true;

			// Determine priority (higher number = higher priority)
			$priority = $this->getContentPriority( $item );

			$organized[] = array(
				'original_text' => $content,
				'context'       => $item['context'],
				'type'          => $item['type'],
				'parent_tag'    => $item['parent_tag'],
				'priority'      => $priority,
				'post_id'       => $post_id,
				'attribute'     => $item['attribute'] ?? null,
			);
		}

		// Sort by priority (highest first)
		usort(
			$organized,
			function ( $a, $b ) {
				return $b['priority'] - $a['priority'];
			}
		);

		return $organized;
	}

	/**
	 * Get content priority for translation order
	 */
	private function getContentPriority( $item ) {
		$priority_map = array(
			'page_title'      => 100,
			'heading_1'       => 90,
			'heading_2'       => 85,
			'heading_3'       => 80,
			'product_text'    => 75,
			'button'          => 70,
			'menu_item'       => 65,
			'link'            => 60,
			'paragraph'       => 55,
			'form_label'      => 50,
			'widget_text'     => 45,
			'footer_text'     => 40,
			'header_text'     => 40,
			'attribute_alt'   => 35,
			'attribute_title' => 30,
			'meta'            => 25,
			'general'         => 20,
		);

		return $priority_map[ $item['context'] ] ?? 10;
	}

	/**
	 * Translate all extracted content
	 */
	public function translateAllContent( $content_items, $target_language ) {
		$results    = array();
		$translator = $this->plugin->getComponent( 'translator' );

		if ( ! $translator ) {
			return array( 'error' => 'Translator not available' );
		}

		$batch_size = 10; // Process in batches to avoid timeouts
		$batches    = array_chunk( $content_items, $batch_size );

		foreach ( $batches as $batch_index => $batch ) {
			foreach ( $batch as $item ) {
				$original_text = $item['original_text'];
				$context       = $item['context'];

				// Check if already translated
				$existing_translation = $this->memory->getTranslation( $original_text, $target_language, $context );

				if ( $existing_translation ) {
					$results[] = array(
						'original'   => $original_text,
						'translated' => $existing_translation,
						'context'    => $context,
						'status'     => 'existing',
					);
				} else {
					// Translate new content
					$translated = $translator->translate( $original_text, $target_language, 'EN', $context );

					if ( $translated ) {
						// Save translation
						$this->memory->saveTranslation(
							$original_text,
							$translated,
							$target_language,
							$context,
							$item['post_id']
						);

						$results[] = array(
							'original'   => $original_text,
							'translated' => $translated,
							'context'    => $context,
							'status'     => 'new',
						);
					} else {
						$results[] = array(
							'original'   => $original_text,
							'translated' => null,
							'context'    => $context,
							'status'     => 'failed',
						);
					}
				}
			}

			// Add small delay between batches to prevent overwhelming the API
			if ( $batch_index < count( $batches ) - 1 ) {
				usleep( 500000 ); // 0.5 second delay
			}
		}

		return array(
			'success'         => true,
			'total_processed' => count( $results ),
			'results'         => $results,
		);
	}
}
