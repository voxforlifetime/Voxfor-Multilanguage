<?php
namespace VoxforML\Admin;

use VoxforML\Core\Plugin;
use VoxforML\Database\TranslationMemory;
use VoxforML\Translator\DeepLTranslator;
use VoxforML\Utils\IndividualContentScanner;

/**
 * Handles individual content translation operations
 */
class IndividualTranslationHandler {
	private $plugin;
	private $memory;
	private $translator;
	private $scanner;
	private $css_exclusions;

	public function __construct() {
		$this->plugin     = Plugin::getInstance();
		$this->memory     = new TranslationMemory();
		$this->translator = new DeepLTranslator();
		$this->scanner    = new IndividualContentScanner();
		$this->loadCssExclusions();
	}

	/**
	 * Translate individual content items
	 */
	public function translateContent( $content_ids, $languages, $translation_scope = 'full', $progress_callback = null ) {
		$results = array(
			'total_items'     => count( $content_ids ),
			'completed'       => 0,
			'failed'          => 0,
			'errors'          => array(),
			'processed_items' => array(),
		);

		foreach ( $content_ids as $index => $content_id ) {
			// Check for cancellation
			if ( get_transient( 'voxfor_ml_cancel_individual_translation' ) ) {
				delete_transient( 'voxfor_ml_cancel_individual_translation' );
				$results['errors'][] = __( 'Translation cancelled by user', 'voxfor-multilanguage' );
				break;
			}

			$item_result = $this->translateSingleItem( $content_id, $languages, $translation_scope );

			if ( $item_result['success'] ) {
				++$results['completed'];
			} else {
				++$results['failed'];
				$results['errors'] = array_merge( $results['errors'], $item_result['errors'] );
			}

			$results['processed_items'][] = $item_result;

			// Call progress callback if provided
			if ( $progress_callback && is_callable( $progress_callback ) ) {
				$progress_callback( $index + 1, $results['total_items'], $item_result );
			}

			// Small delay to prevent overwhelming the server
			usleep( 100000 ); // 0.1 second
		}

		return $results;
	}

	/**
	 * Translate a single content item
	 */
	private function translateSingleItem( $content_id, $languages, $translation_scope ) {
		$post = get_post( $content_id );

		if ( ! $post ) {
			return array(
				'success'      => false,
				'content_id'   => $content_id,
				'title'        => 'Unknown',
				'errors'       => array( __( 'Post not found', 'voxfor-multilanguage' ) ),
				'translations' => array(),
			);
		}

		$result = array(
			'success'      => true,
			'content_id'   => $content_id,
			'title'        => $post->post_title,
			'post_type'    => $post->post_type,
			'errors'       => array(),
			'translations' => array(),
		);

		try {
			// Extract translatable content
			$content_data = $this->scanner->extractTranslatableContent( $content_id, $translation_scope );

			if ( ! $content_data || empty( $content_data['texts'] ) ) {
				$result['errors'][] = __( 'No translatable content found', 'voxfor-multilanguage' );
				$result['success']  = false;
				return $result;
			}

			// Translate for each language
			foreach ( $languages as $language ) {
				if ( $language === 'en' ) {
					continue;
				}

				$language_result                     = $this->translateContentForLanguage( $content_data, $language );
				$result['translations'][ $language ] = $language_result;

				if ( ! $language_result['success'] ) {
					$result['success'] = false;
					$result['errors']  = array_merge( $result['errors'], $language_result['errors'] );
				}
			}
		} catch ( \Exception $e ) {
			$result['success']  = false;
			// translators: %s is the error message
			$result['errors'][] = sprintf( __( 'Translation error: %s', 'voxfor-multilanguage' ), $e->getMessage() );
		}

		return $result;
	}

	/**
	 * Translate content for a specific language
	 */
	private function translateContentForLanguage( $content_data, $language ) {
		$result = array(
			'success'          => true,
			'language'         => $language,
			'translated_count' => 0,
			'skipped_count'    => 0,
			'errors'           => array(),
		);

		$texts_to_translate = array();
		$text_mapping       = array();

		// Prepare texts for batch translation
		foreach ( $content_data['texts'] as $index => $text_item ) {
			$text = trim( $text_item['text'] );

			if ( empty( $text ) || strlen( $text ) < 2 ) {
				++$result['skipped_count'];
				continue;
			}

			// Check if translation already exists
			$existing_translation = $this->memory->getTranslation(
				$text,
				$language,
				$text_item['context'],
				$content_data['post_id']
			);

			if ( $existing_translation !== false ) {
				++$result['skipped_count'];
				continue;
			}

			$texts_to_translate[]                             = $text;
			$text_mapping[ count( $texts_to_translate ) - 1 ] = $text_item;
		}

		if ( empty( $texts_to_translate ) ) {
			return $result;
		}

		try {
			// Batch translate texts
			$batch_size = 20;
			$batches    = array_chunk( $texts_to_translate, $batch_size, true );

			foreach ( $batches as $batch_indices => $batch_texts ) {
				// Check for cancellation
				if ( get_transient( 'voxfor_ml_cancel_individual_translation' ) ) {
					$result['errors'][] = __( 'Translation cancelled', 'voxfor-multilanguage' );
					$result['success']  = false;
					break;
				}

				$translations = $this->translator->batchTranslate( array_values( $batch_texts ), $language, 'EN' );

				if ( ! is_array( $translations ) ) {
					$result['errors'][] = __( 'Batch translation failed', 'voxfor-multilanguage' );
					$result['success']  = false;
					continue;
				}

				// Save translations
				foreach ( $batch_texts as $batch_index => $original_text ) {
					$translation_index = array_search( $batch_index, array_keys( $batch_texts ) );
					$translated_text   = $translations[ $translation_index ] ?? null;

					if ( ! empty( $translated_text ) && $translated_text !== $original_text ) {
						$text_item = $text_mapping[ $batch_index ];

						// Save translation to memory
						$saved = $this->memory->saveTranslation(
							$original_text,
							$translated_text,
							$language,
							$text_item['context'],
							$content_data['post_id'],
							'deepl'
						);

						if ( $saved ) {
							++$result['translated_count'];

							// Update post meta or content if needed
							$this->updatePostContent( $content_data['post_id'], $text_item, $translated_text, $language );
						} else {
							$result['errors'][] = sprintf(
								/* translators: %s is a truncated version of the original text that failed to translate */
								__( 'Failed to save translation for: %s', 'voxfor-multilanguage' ),
								substr( $original_text, 0, 50 ) . '...'
							);
						}
					} else {
						++$result['skipped_count'];
					}
				}

				// Small delay between batches
				usleep( 200000 ); // 0.2 seconds
			}
		} catch ( \Exception $e ) {
			$result['success']  = false;
			// translators: %s is the API error message
			$result['errors'][] = sprintf( __( 'Translation API error: %s', 'voxfor-multilanguage' ), $e->getMessage() );
		}

		return $result;
	}

	/**
	 * Update post content with translation (for future use)
	 */
	private function updatePostContent( $post_id, $text_item, $translated_text, $language ) {
		// This method can be extended to directly update post content
		// For now, we're just storing in translation memory

		// Handle SEO plugin meta fields
		if ( strpos( $text_item['field'], '_yoast_wpseo_' ) === 0 ||
			strpos( $text_item['field'], 'rank_math_' ) === 0 ||
			strpos( $text_item['field'], '_aioseo_' ) === 0 ) {
			// Save translated SEO meta with language suffix
			$meta_key = $text_item['field'] . '_' . $language;
			update_post_meta( $post_id, $meta_key, $translated_text );
		}
		
		// Handle WordPress core meta fields
		elseif ( in_array( $text_item['field'], array( 'wp_core_title', 'wp_core_excerpt' ) ) ) {
			// Save WordPress core meta translations with language suffix
			if ( $text_item['field'] === 'wp_core_title' ) {
				update_post_meta( $post_id, 'wp_meta_title_' . $language, $translated_text );
			} elseif ( $text_item['field'] === 'wp_core_excerpt' ) {
				update_post_meta( $post_id, 'wp_meta_description_' . $language, $translated_text );
			}
		}
		
		// Future enhancement: Create language-specific post versions
		// or update post content directly for other field types
	}

	/**
	 * Get translation progress for content items
	 */
	public function getTranslationProgress( $content_ids, $languages ) {
		$progress = array(
			'total_items' => count( $content_ids ),
			'items'       => array(),
		);

		foreach ( $content_ids as $content_id ) {
			$post = get_post( $content_id );

			if ( ! $post ) {
				continue;
			}

			$item_progress = array(
				'id'        => $content_id,
				'title'     => $post->post_title,
				'post_type' => $post->post_type,
				'languages' => array(),
			);

			foreach ( $languages as $language ) {
				if ( $language === 'en' ) {
					continue;
				}

				$content_data     = $this->scanner->extractTranslatableContent( $content_id, 'full' );
				$total_texts      = count( $content_data['texts'] ?? array() );
				$translated_texts = 0;

				foreach ( $content_data['texts'] ?? array() as $text_item ) {
					$existing_translation = $this->memory->getTranslation(
						$text_item['text'],
						$language,
						$text_item['context'],
						$content_id
					);

					if ( $existing_translation !== false ) {
						++$translated_texts;
					}
				}

				$percentage = $total_texts > 0 ? round( ( $translated_texts / $total_texts ) * 100 ) : 0;

				$item_progress['languages'][ $language ] = array(
					'total_texts'      => $total_texts,
					'translated_texts' => $translated_texts,
					'percentage'       => $percentage,
					'status'           => $percentage === 100 ? 'complete' : ( $percentage > 0 ? 'partial' : 'not_started' ),
				);
			}

			$progress['items'][] = $item_progress;
		}

		return $progress;
	}

	/**
	 * Estimate API cost for translation
	 */
	public function estimateTranslationCost( $content_ids, $languages, $translation_scope = 'full' ) {
		$total_characters = 0;
		$total_texts      = 0;

		foreach ( $content_ids as $content_id ) {
			$content_data = $this->scanner->extractTranslatableContent( $content_id, $translation_scope );

			foreach ( $content_data['texts'] ?? array() as $text_item ) {
				$text = trim( $text_item['text'] );

				if ( strlen( $text ) >= 2 ) {
					$total_characters += strlen( $text );
					++$total_texts;
				}
			}
		}

		// Multiply by number of target languages
		$target_languages  = array_filter(
			$languages,
			function ( $lang ) {
				return $lang !== 'en';
			}
		);
		$total_characters *= count( $target_languages );
		$total_texts      *= count( $target_languages );

		// DeepL pricing (approximate)
		$cost_per_character = 0.00002; // $0.02 per 1000 characters
		$estimated_cost     = $total_characters * $cost_per_character;

		return array(
			'total_characters' => $total_characters,
			'total_texts'      => $total_texts,
			'target_languages' => count( $target_languages ),
			'estimated_cost'   => round( $estimated_cost, 2 ),
			'currency'         => 'USD',
		);
	}

	/**
	 * Validate content selection
	 */
	public function validateContentSelection( $content_ids, $languages, $translation_scope ) {
		$validation = array(
			'valid'    => true,
			'errors'   => array(),
			'warnings' => array(),
		);

		// Check if content IDs are valid
		if ( empty( $content_ids ) ) {
			$validation['valid']    = false;
			$validation['errors'][] = __( 'No content selected for translation', 'voxfor-multilanguage' );
		}

		// Check if languages are valid
		if ( empty( $languages ) || ( count( $languages ) === 1 && $languages[0] === 'en' ) ) {
			$validation['valid']    = false;
			$validation['errors'][] = __( 'No target languages selected', 'voxfor-multilanguage' );
		}

		// Check if posts exist
		$invalid_posts = array();
		foreach ( $content_ids as $content_id ) {
			if ( ! get_post( $content_id ) ) {
				$invalid_posts[] = $content_id;
			}
		}

		if ( ! empty( $invalid_posts ) ) {
			$validation['valid']    = false;
			$validation['errors'][] = sprintf(
				/* translators: %s is a comma-separated list of invalid post IDs */
				__( 'Invalid post IDs: %s', 'voxfor-multilanguage' ),
				implode( ', ', $invalid_posts )
			);
		}

		// Check translation scope
		$valid_scopes = array( 'full', 'title_only', 'content_only', 'meta_only' );
		if ( ! in_array( $translation_scope, $valid_scopes ) ) {
			$validation['valid']    = false;
			$validation['errors'][] = __( 'Invalid translation scope', 'voxfor-multilanguage' );
		}

		// Warnings for large selections
		if ( count( $content_ids ) > 50 ) {
			$validation['warnings'][] = __( 'Large content selection may take significant time and API credits', 'voxfor-multilanguage' );
		}

		return $validation;
	}

	/**
	 * 🚀 CORRECT METHOD: Extract and translate individual text fragments
	 * This properly extracts individual text pieces and saves them with context 'text_fragment'
	 */
	public function translateUsingComprehensiveMethod( $content_id, $language ) {
		try {
			$post = get_post( $content_id );
			if ( ! $post ) {
				throw new \Exception( 'Post not found' );
			}

			// 1. Extract individual text fragments from the content
			$text_fragments = $this->extractTextFragments( $post, $content_id );

			if ( empty( $text_fragments ) ) {
				return array(
					'success'  => false,
					'language' => $language,
					'errors'   => array( 'No text fragments found to translate' ),
				);
			}

			// 2. Translate each text fragment individually
			$translated_count = 0;
			$errors           = array();

			foreach ( $text_fragments as $fragment ) {
				try {
					$translated = $this->translator->translate( $fragment['text'], $language, 'EN' );

					if ( $translated && $translated !== $fragment['text'] ) {
						// Save with context 'text_fragment' and post_id
						$this->memory->saveTranslation(
							$fragment['text'],
							$translated,
							$language,
							'text_fragment', // 🎯 KEY: Use text_fragment context
							$content_id,     // 🎯 KEY: Include post_id
							'deepl'
						);
						++$translated_count;
					}
				} catch ( \Exception $e ) {
					$errors[] = sprintf(
						'Failed to translate "%s": %s',
						substr( $fragment['text'], 0, 50 ) . '...',
						$e->getMessage()
					);
				}
			}

			return array(
				'success'          => $translated_count > 0,
				'language'         => $language,
				'method'           => 'text_fragments',
				'translated_count' => $translated_count,
				'total_fragments'  => count( $text_fragments ),
				'errors'           => $errors,
				'message'          => sprintf(
					/* translators: %1$d is the number of translated fragments, %2$d is the total number of fragments, %3$s is the target language */
					__( 'Translated %1$d of %2$d text fragments to %3$s', 'voxfor-multilanguage' ),
					$translated_count,
					count( $text_fragments ),
					$language
				),
			);

		} catch ( \Exception $e ) {
			return array(
				'success'  => false,
				'language' => $language,
				'errors'   => array( $e->getMessage() ),
			);
		}
	}

	/**
	 * 🎯 EXTRACT INDIVIDUAL TEXT FRAGMENTS
	 * This extracts individual translatable text pieces from post content
	 */
	private function extractTextFragments( $post, $content_id ) {
		$fragments = array();

		// 1. Extract title
		if ( ! empty( $post->post_title ) ) {
			$fragments[] = array(
				'text'    => trim( $post->post_title ),
				'type'    => 'title',
				'context' => 'text_fragment',
			);
		}

		// 2. Extract excerpt if exists
		if ( ! empty( $post->post_excerpt ) ) {
			$fragments[] = array(
				'text'    => trim( $post->post_excerpt ),
				'type'    => 'excerpt',
				'context' => 'text_fragment',
			);
		}

		// 3. Extract text from post content (enhanced approach)
		$content_fragments = $this->extractTextFromContent( $post->post_content );
		$fragments         = array_merge( $fragments, $content_fragments );

		// 3b. Extract sentences separately to avoid merging issues
		$sentence_fragments = $this->extractSentencesFromContent( $post->post_content );
		$fragments          = array_merge( $fragments, $sentence_fragments );

		// 4. Extract Elementor text if it's an Elementor page
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$elementor_data = get_post_meta( $content_id, '_elementor_data', true );
			if ( $elementor_data ) {
				$elementor_fragments = $this->extractElementorText( $elementor_data );
				$fragments           = array_merge( $fragments, $elementor_fragments );
			}
		}

		// 5. Extract WooCommerce product-specific text
		if ( get_post_type( $content_id ) === 'product' ) {
			$wc_fragments = $this->extractWooCommerceText( $content_id );
			$fragments    = array_merge( $fragments, $wc_fragments );
		}

		// 6. Extract WooCommerce UI elements (buttons, labels, etc.)
		if ( get_post_type( $content_id ) === 'product' ) {
			$ui_fragments = $this->extractWooCommerceUIElements( $content_id );
			$fragments    = array_merge( $fragments, $ui_fragments );
		}

		// 7. Extract tab content (WooCommerce tabs, custom tabs, etc.)
		$tab_fragments = $this->extractTabContent( $content_id );
		$fragments     = array_merge( $fragments, $tab_fragments );

		// 8. Extract WordPress post-specific elements (comments, author info, etc.)
		if ( get_post_type( $content_id ) === 'post' ) {
			$post_fragments = $this->extractWordPressPostElements( $content_id );
			$fragments      = array_merge( $fragments, $post_fragments );
		}

		// 9. Extract common section headings and labels
		$common_fragments = $this->extractCommonUIElements( $content_id );
		$fragments        = array_merge( $fragments, $common_fragments );

		// 9. Extract meta descriptions and SEO text
		$meta_fragments = $this->extractMetaText( $content_id );
		$fragments      = array_merge( $fragments, $meta_fragments );

		// Remove duplicates and empty fragments
		$unique_fragments = array();
		$seen_texts       = array();

		foreach ( $fragments as $fragment ) {
			$text = trim( $fragment['text'] );

			// Skip empty, very short, or numeric text
			if ( empty( $text ) || strlen( $text ) < 3 || is_numeric( $text ) ) {
				continue;
			}

			// Skip HTML tags only
			if ( preg_match( '/^<[^>]+>$/', $text ) ) {
				continue;
			}

			// Skip if already seen
			$text_hash = md5( $text );
			if ( isset( $seen_texts[ $text_hash ] ) ) {
				continue;
			}

			$seen_texts[ $text_hash ] = true;
			$unique_fragments[]       = $fragment;
		}

		return $unique_fragments;
	}

	/**
	 * Extract text fragments from HTML content
	 * 🎯 ENHANCED: Split text around inline tags (a, strong, etc.) into separate fragments
	 */
	private function extractTextFromContent( $content ) {
		$fragments = array();

		if ( empty( $content ) ) {
			return $fragments;
		}

		// Remove shortcodes first
		$content = strip_shortcodes( $content );

		// Create DOM document
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );

		// Load HTML content
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		// Define inline tags that should trigger text splitting
		$inline_tags = array( 'a', 'strong', 'b', 'em', 'i', 'span', 'code', 'mark', 'u', 'sub', 'sup', 'small' );

		// Block-level elements that contain text
		$block_elements = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'td', 'th', 'blockquote', 'figcaption', 'div' );

		foreach ( $block_elements as $tag ) {
			$elements = $dom->getElementsByTagName( $tag );
			foreach ( $elements as $element ) {
				// Check if this element contains inline tags
				if ( $this->containsInlineTags( $element, $inline_tags ) ) {
					// 🎯 NEW: Split text around inline tags
					$split_fragments = $this->extractTextAroundInlineTags( $element );
					$fragments       = array_merge( $fragments, $split_fragments );
				} elseif ( $this->hasOnlyTextContent( $element ) ) {
					// No inline tags, extract as single text fragment
					$text = trim( $element->textContent );
					if ( ! empty( $text ) && strlen( $text ) >= 3 ) {
						$fragments[] = array(
							'text'    => $text,
							'type'    => 'content_text',
							'context' => 'text_fragment',
							'element' => $tag,
						);
					}
				} else {
					// For elements with children (but no inline tags), extract direct text
					$direct_text = $this->getDirectTextContent( $element );
					if ( ! empty( $direct_text ) && strlen( $direct_text ) >= 3 ) {
						$fragments[] = array(
							'text'    => $direct_text,
							'type'    => 'content_text',
							'context' => 'text_fragment',
							'element' => $tag,
						);
					}
				}
			}
		}

		// Extract alt text from images
		$images = $dom->getElementsByTagName( 'img' );
		foreach ( $images as $img ) {
			$alt = $img->getAttribute( 'alt' );
			if ( ! empty( $alt ) && strlen( $alt ) >= 3 ) {
				$fragments[] = array(
					'text'    => trim( $alt ),
					'type'    => 'image_alt',
					'context' => 'text_fragment',
				);
			}

			// Also check title attribute
			$title = $img->getAttribute( 'title' );
			if ( ! empty( $title ) && strlen( $title ) >= 3 ) {
				$fragments[] = array(
					'text'    => trim( $title ),
					'type'    => 'image_title',
					'context' => 'text_fragment',
				);
			}
		}

		// Extract button text and input values (enhanced)
		$buttons = $dom->getElementsByTagName( 'button' );
		foreach ( $buttons as $button ) {
			// Get only direct text content from button (not nested elements)
			$direct_text = $this->getDirectTextContent( $button );
			if ( ! empty( $direct_text ) && strlen( $direct_text ) >= 2 ) {
				$fragments[] = array(
					'text'    => $direct_text,
					'type'    => 'button_text',
					'context' => 'text_fragment',
				);
			}

			// Also check if button has only text content (no child elements)
			if ( $this->hasOnlyTextContent( $button ) ) {
				$text = trim( $button->textContent );
				if ( ! empty( $text ) && strlen( $text ) >= 2 && $text !== $direct_text ) {
					$fragments[] = array(
						'text'    => $text,
						'type'    => 'button_text',
						'context' => 'text_fragment',
					);
				}
			}
		}

		// Extract input button values
		$inputs = $dom->getElementsByTagName( 'input' );
		foreach ( $inputs as $input ) {
			$type = $input->getAttribute( 'type' );
			if ( in_array( $type, array( 'submit', 'button', 'reset' ) ) ) {
				$value = $input->getAttribute( 'value' );
				if ( ! empty( $value ) && strlen( $value ) >= 2 ) {
					$fragments[] = array(
						'text'    => trim( $value ),
						'type'    => 'input_value',
						'context' => 'text_fragment',
					);
				}
			}

			// Extract placeholder text
			$placeholder = $input->getAttribute( 'placeholder' );
			if ( ! empty( $placeholder ) && strlen( $placeholder ) >= 3 ) {
				$fragments[] = array(
					'text'    => trim( $placeholder ),
					'type'    => 'input_placeholder',
					'context' => 'text_fragment',
				);
			}
		}

		// Extract option text from select elements
		$selects = $dom->getElementsByTagName( 'select' );
		foreach ( $selects as $select ) {
			$options = $select->getElementsByTagName( 'option' );
			foreach ( $options as $option ) {
				$text = trim( $option->textContent );
				if ( ! empty( $text ) && strlen( $text ) >= 2 && $text !== '---' ) {
					$fragments[] = array(
						'text'    => $text,
						'type'    => 'option_text',
						'context' => 'text_fragment',
					);
				}
			}
		}

		return $fragments;
	}

	/**
	 * Check if element has only text content (no child elements)
	 */
	private function hasOnlyTextContent( $element ) {
		// Check if element has any child elements
		foreach ( $element->childNodes as $child ) {
			if ( $child->nodeType === XML_ELEMENT_NODE ) {
				return false; // Has child elements
			}
		}
		return true; // Only text content
	}

	/**
	 * Get direct text content from element (excluding child elements)
	 */
	private function getDirectTextContent( $element ) {
		$text = '';

		foreach ( $element->childNodes as $child ) {
			if ( $child->nodeType === XML_TEXT_NODE ) {
				$text .= $child->textContent;
			}
		}

		return trim( $text );
	}

	/**
	 * Check if element contains inline tags (a, strong, em, etc.)
	 */
	private function containsInlineTags( $element, $inline_tags ) {
		foreach ( $element->childNodes as $child ) {
			if ( $child->nodeType === XML_ELEMENT_NODE && in_array( $child->nodeName, $inline_tags ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 🎯 KEY METHOD: Extract text around inline tags as separate fragments
	 * Example: "Text before <a>link</a> text after" becomes 3 fragments:
	 * - "Text before"
	 * - "link"
	 * - "text after"
	 */
	private function extractTextAroundInlineTags( $element ) {
		$fragments = array();

		foreach ( $element->childNodes as $child ) {
			if ( $child->nodeType === XML_TEXT_NODE ) {
				// Text node - extract as fragment
				$text = trim( $child->textContent );
				if ( ! empty( $text ) && strlen( $text ) >= 3 ) {
					$fragments[] = array(
						'text'    => $text,
						'type'    => 'text_fragment_split',
						'context' => 'text_fragment',
					);
				}
			} elseif ( $child->nodeType === XML_ELEMENT_NODE ) {
				// ✅ CHECK: Skip if element has CSS exclusion class
				if ( $this->hasExclusionClass( $child ) ) {
					continue; // Skip this element - don't translate it
				}

				// Element node - extract its text content (including nested inline tags)
				$text = trim( $child->textContent );
				if ( ! empty( $text ) && strlen( $text ) >= 2 ) {
					$fragments[] = array(
						'text'    => $text,
						'type'    => 'inline_element_text',
						'context' => 'text_fragment',
						'element' => $child->nodeName,
					);
				}

				// Also recursively extract from nested elements
				if ( $child->hasChildNodes() ) {
					$nested_fragments = $this->extractTextAroundInlineTags( $child );
					foreach ( $nested_fragments as $nested ) {
						// Avoid duplicates - only add if not already in fragments
						$is_duplicate = false;
						foreach ( $fragments as $existing ) {
							if ( $existing['text'] === $nested['text'] ) {
								$is_duplicate = true;
								break;
							}
						}
						if ( ! $is_duplicate ) {
							$fragments[] = $nested;
						}
					}
				}
			}
		}

		return $fragments;
	}

	/**
	 * Extract individual sentences from content to prevent merging
	 */
	private function extractSentencesFromContent( $content ) {
		$fragments = array();

		if ( empty( $content ) ) {
			return $fragments;
		}

		// Remove shortcodes and HTML tags to get clean text
		$clean_content = strip_shortcodes( $content );
		$clean_content = wp_strip_all_tags( $clean_content );
		$clean_content = html_entity_decode( $clean_content, ENT_QUOTES, 'UTF-8' );

		// Split into sentences (basic approach)
		$sentences = preg_split( '/[.!?]+/', $clean_content );

		foreach ( $sentences as $sentence ) {
			$sentence = trim( $sentence );

			// Skip empty, very short, or sentences that are just numbers/symbols
			if ( empty( $sentence ) || strlen( $sentence ) < 10 ) {
				continue;
			}

			// Skip if it looks like a URL, email, or technical string
			if ( preg_match( '/^(http|www|@|#|\$|%)/', $sentence ) ) {
				continue;
			}

			// Skip if it's mostly numbers or special characters
			if ( preg_match( '/^[\d\s\W]{0,3}[\d\s\W]*$/', $sentence ) ) {
				continue;
			}

			$fragments[] = array(
				'text'    => $sentence,
				'type'    => 'sentence',
				'context' => 'text_fragment',
			);
		}

		return $fragments;
	}

	/**
	 * Extract text from Elementor data
	 */
	private function extractElementorText( $elementor_data ) {
		$fragments = array();

		if ( empty( $elementor_data ) ) {
			return $fragments;
		}

		$data = json_decode( $elementor_data, true );
		if ( ! $data ) {
			return $fragments;
		}

		$this->extractElementorTextRecursive( $data, $fragments );

		return $fragments;
	}

	/**
	 * Recursively extract text from Elementor elements
	 */
	private function extractElementorTextRecursive( $elements, &$fragments ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			// Extract text from settings
			if ( isset( $element['settings'] ) ) {
				$settings = $element['settings'];

				// Common text fields in Elementor
				$text_fields = array( 'title', 'description', 'content', 'text', 'button_text', 'link_text', 'heading_title' );

				foreach ( $text_fields as $field ) {
					if ( isset( $settings[ $field ] ) && ! empty( $settings[ $field ] ) ) {
						$text = wp_strip_all_tags( $settings[ $field ] );
						$text = trim( $text );

						if ( strlen( $text ) >= 3 ) {
							$fragments[] = array(
								'text'    => $text,
								'type'    => 'elementor_text',
								'context' => 'text_fragment',
								'field'   => $field,
							);
						}
					}
				}
			}

			// Recursively process nested elements
			if ( isset( $element['elements'] ) ) {
				$this->extractElementorTextRecursive( $element['elements'], $fragments );
			}
		}
	}

	/**
	 * Extract meta text (SEO descriptions, etc.)
	 */
	private function extractMetaText( $post_id ) {
		$fragments = array();

		// Common SEO meta fields
		$meta_fields = array(
			'_yoast_wpseo_title'    => 'seo_title',
			'_yoast_wpseo_metadesc' => 'seo_description',
			'rank_math_title'       => 'seo_title',
			'rank_math_description' => 'seo_description',
		);

		foreach ( $meta_fields as $meta_key => $type ) {
			$meta_value = get_post_meta( $post_id, $meta_key, true );
			if ( ! empty( $meta_value ) ) {
				$text = trim( wp_strip_all_tags( $meta_value ) );
				if ( strlen( $text ) >= 3 ) {
					$fragments[] = array(
						'text'    => $text,
						'type'    => $type,
						'context' => 'text_fragment',
					);
				}
			}
		}

		return $fragments;
	}

	/**
	 * Extract WooCommerce-specific text fragments
	 */
	private function extractWooCommerceText( $product_id ) {
		$fragments = array();

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $fragments;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $fragments;
		}

		// Product short description (often missed)
		$short_desc = $product->get_short_description();
		if ( ! empty( $short_desc ) ) {
			$text = trim( wp_strip_all_tags( $short_desc ) );
			if ( strlen( $text ) >= 3 ) {
				$fragments[] = array(
					'text'    => $text,
					'type'    => 'wc_short_description',
					'context' => 'text_fragment',
				);
			}
		}

		// Product attributes (Color, Size, etc.)
		$attributes = $product->get_attributes();
		foreach ( $attributes as $attribute ) {
			// Attribute name
			$attr_name = wc_attribute_label( $attribute->get_name() );
			if ( ! empty( $attr_name ) && strlen( $attr_name ) >= 2 ) {
				$fragments[] = array(
					'text'    => trim( $attr_name ),
					'type'    => 'wc_attribute_name',
					'context' => 'text_fragment',
				);
			}

			// Attribute values
			if ( $attribute->is_taxonomy() ) {
				$terms = wp_get_post_terms( $product_id, $attribute->get_name() );
				foreach ( $terms as $term ) {
					if ( ! empty( $term->name ) && strlen( $term->name ) >= 2 ) {
						$fragments[] = array(
							'text'    => trim( $term->name ),
							'type'    => 'wc_attribute_value',
							'context' => 'text_fragment',
						);
					}
				}
			} else {
				// Non-taxonomy attributes
				$values = $attribute->get_options();
				foreach ( $values as $value ) {
					$value = trim( $value );
					if ( ! empty( $value ) && strlen( $value ) >= 2 ) {
						$fragments[] = array(
							'text'    => $value,
							'type'    => 'wc_attribute_value',
							'context' => 'text_fragment',
						);
					}
				}
			}
		}

		// Product categories and tags
		$categories = wp_get_post_terms( $product_id, 'product_cat' );
		foreach ( $categories as $category ) {
			if ( ! empty( $category->name ) && strlen( $category->name ) >= 2 ) {
				$fragments[] = array(
					'text'    => trim( $category->name ),
					'type'    => 'wc_category',
					'context' => 'text_fragment',
				);
			}
		}

		$tags = wp_get_post_terms( $product_id, 'product_tag' );
		foreach ( $tags as $tag ) {
			if ( ! empty( $tag->name ) && strlen( $tag->name ) >= 2 ) {
				$fragments[] = array(
					'text'    => trim( $tag->name ),
					'type'    => 'wc_tag',
					'context' => 'text_fragment',
				);
			}
		}

		// Custom fields that might contain text
		$custom_fields = array(
			'_product_subtitle',
			'_product_tagline',
			'_custom_description',
			'_additional_info',
			'_shipping_info',
			'_warranty_info',
		);

		foreach ( $custom_fields as $field ) {
			$value = get_post_meta( $product_id, $field, true );
			if ( ! empty( $value ) ) {
				$text = trim( wp_strip_all_tags( $value ) );
				if ( strlen( $text ) >= 3 ) {
					$fragments[] = array(
						'text'    => $text,
						'type'    => 'wc_custom_field',
						'context' => 'text_fragment',
					);
				}
			}
		}

		return $fragments;
	}

	/**
	 * Extract WooCommerce UI elements (buttons, labels, headings)
	 */
	private function extractWooCommerceUIElements( $product_id ) {
		$fragments = array();

		// Common WooCommerce text strings that appear on product pages
		$wc_ui_strings = array(
			// Buttons
			'Add to cart',
			'Add to basket',
			'Buy now',
			'Select options',
			'Choose an option',
			'Clear',
			'Reset variations',
			'Shop Now',
			'Shop now',
			'SHOP NOW',
			'Browse',
			'View all',
			'See more',
			'Learn more',
			'Read more',

			// Tabs
			'Description',
			'Additional information',
			'Reviews',
			'Shipping & Returns',
			'Size Guide',

			// Labels
			'SKU',
			'Category',
			'Categories',
			'Tag',
			'Tags',
			'Related products',
			'You may also like',
			'Recently viewed',
			'Upsells',
			'Cross-sells',

			// Product info
			'In stock',
			'Out of stock',
			'Free shipping',
			'Guaranteed Safe Checkout',
			'Quantity',
			'Price',
			'Sale!',
			'Featured',

			// Reviews
			'customer review',
			'customer reviews',
			'Be the first to review',
			'Add a review',
			'Your rating',
			'Your review',

			// Variations
			'Choose an option',
			'Clear selection',
			'Size',
			'Color',
			'Material',
		);

		foreach ( $wc_ui_strings as $string ) {
			$fragments[] = array(
				'text'    => $string,
				'type'    => 'wc_ui_element',
				'context' => 'text_fragment',
			);
		}

		// Extract dynamic tab titles from actual WooCommerce tabs
		if ( class_exists( 'WooCommerce' ) ) {
			global $post;
			$original_post = $post;
			$post          = get_post( $product_id );

			$product = wc_get_product( $product_id );
			if ( $product ) {
				setup_postdata( $post );

				// Get actual WooCommerce tabs for this product
				$tabs = apply_filters( 'woocommerce_product_tabs', array() );

				foreach ( $tabs as $key => $tab ) {
					if ( ! empty( $tab['title'] ) ) {
						$title = trim( wp_strip_all_tags( $tab['title'] ) );
						if ( strlen( $title ) >= 2 ) {
							$fragments[] = array(
								'text'    => $title,
								'type'    => 'wc_dynamic_tab',
								'context' => 'text_fragment',
							);
						}
					}
				}

				wp_reset_postdata();
			}

			$post = $original_post;
		}

		return $fragments;
	}

	/**
	 * Extract tab content (WooCommerce product tabs, custom tabs, etc.)
	 */
	private function extractTabContent( $post_id ) {
		$fragments = array();

		// For WooCommerce products, extract product tabs
		if ( get_post_type( $post_id ) === 'product' && class_exists( 'WooCommerce' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				// Get WooCommerce product tabs
				$tabs = apply_filters( 'woocommerce_product_tabs', array() );

				foreach ( $tabs as $key => $tab ) {
					// Tab title
					if ( ! empty( $tab['title'] ) ) {
						$title = trim( wp_strip_all_tags( $tab['title'] ) );
						if ( strlen( $title ) >= 2 ) {
							$fragments[] = array(
								'text'    => $title,
								'type'    => 'wc_tab_title',
								'context' => 'text_fragment',
							);
						}
					}

					// Tab content (if it's a simple callback)
					if ( $key === 'description' && ! empty( $product->get_description() ) ) {
						// Already extracted in main content
						continue;
					}

					if ( $key === 'additional_information' ) {
						// Extract additional information content
						$additional_info = $this->getAdditionalInformationContent( $product );
						if ( ! empty( $additional_info ) ) {
							$fragments[] = array(
								'text'    => $additional_info,
								'type'    => 'wc_additional_info',
								'context' => 'text_fragment',
							);
						}
					}

					// Extract reviews tab content
					if ( $key === 'reviews' ) {
						$review_fragments = $this->extractReviewsTabContent( $product_id );
						$fragments        = array_merge( $fragments, $review_fragments );
					}
				}
			}
		}

		// Extract custom tabs (from plugins like "Custom Product Tabs")
		$custom_tabs = get_post_meta( $post_id, '_custom_tabs', true );
		if ( is_array( $custom_tabs ) ) {
			foreach ( $custom_tabs as $tab ) {
				// Tab title
				if ( ! empty( $tab['title'] ) ) {
					$title = trim( wp_strip_all_tags( $tab['title'] ) );
					if ( strlen( $title ) >= 2 ) {
						$fragments[] = array(
							'text'    => $title,
							'type'    => 'custom_tab_title',
							'context' => 'text_fragment',
						);
					}
				}

				// Tab content
				if ( ! empty( $tab['content'] ) ) {
					$content = trim( wp_strip_all_tags( $tab['content'] ) );
					if ( strlen( $content ) >= 3 ) {
						$fragments[] = array(
							'text'    => $content,
							'type'    => 'custom_tab_content',
							'context' => 'text_fragment',
						);
					}
				}
			}
		}

		// Extract from common tab plugins
		$this->extractPluginTabContent( $post_id, $fragments );

		return $fragments;
	}

	/**
	 * Get additional information content for WooCommerce products
	 */
	private function getAdditionalInformationContent( $product ) {
		$content = '';

		// Get product attributes for additional information tab
		$attributes = $product->get_attributes();
		foreach ( $attributes as $attribute ) {
			if ( $attribute->get_visible() ) {
				$name = wc_attribute_label( $attribute->get_name() );
				if ( ! empty( $name ) ) {
					$content .= $name . ' ';
				}
			}
		}

		return trim( $content );
	}

	/**
	 * Extract reviews tab content
	 */
	private function extractReviewsTabContent( $product_id ) {
		$fragments = array();

		// Common review-related strings
		$review_strings = array(
			'customer review',
			'customer reviews',
			'Be the first to review',
			'Add a review',
			'Submit review',
			'Your rating',
			'Your review',
			'Name',
			'Email',
			'Save my name, email, and website in this browser for the next time I comment',
			'There are no reviews yet',
			'Only logged in customers who have purchased this product may leave a review',
		);

		foreach ( $review_strings as $string ) {
			$fragments[] = array(
				'text'    => $string,
				'type'    => 'review_text',
				'context' => 'text_fragment',
			);
		}

		// Extract existing review content if any
		$comments = get_comments(
			array(
				'post_id' => $product_id,
				'type'    => 'review',
				'status'  => 'approve',
				'number'  => 10, // Limit to avoid too many
			)
		);

		foreach ( $comments as $comment ) {
			if ( ! empty( $comment->comment_content ) ) {
				$content = trim( wp_strip_all_tags( $comment->comment_content ) );
				if ( strlen( $content ) >= 10 ) { // Only meaningful review content
					$fragments[] = array(
						'text'    => $content,
						'type'    => 'review_content',
						'context' => 'text_fragment',
					);
				}
			}
		}

		return $fragments;
	}

	/**
	 * Extract tab content from popular tab plugins
	 */
	private function extractPluginTabContent( $post_id, &$fragments ) {
		// WooCommerce Custom Product Tabs Lite
		$custom_tabs = get_post_meta( $post_id, 'frs_woo_product_tabs', true );
		if ( is_array( $custom_tabs ) ) {
			foreach ( $custom_tabs as $tab ) {
				if ( ! empty( $tab['title'] ) ) {
					$fragments[] = array(
						'text'    => trim( wp_strip_all_tags( $tab['title'] ) ),
						'type'    => 'plugin_tab_title',
						'context' => 'text_fragment',
					);
				}
				if ( ! empty( $tab['content'] ) ) {
					$content = trim( wp_strip_all_tags( $tab['content'] ) );
					if ( strlen( $content ) >= 3 ) {
						$fragments[] = array(
							'text'    => $content,
							'type'    => 'plugin_tab_content',
							'context' => 'text_fragment',
						);
					}
				}
			}
		}

		// YITH WooCommerce Tab Manager
		$yith_tabs = get_post_meta( $post_id, '_ywtm_tab_priority', true );
		if ( is_array( $yith_tabs ) ) {
			foreach ( $yith_tabs as $tab_id ) {
				$tab_title   = get_post_meta( $post_id, '_ywtm_tab_title_' . $tab_id, true );
				$tab_content = get_post_meta( $post_id, '_ywtm_tab_content_' . $tab_id, true );

				if ( ! empty( $tab_title ) ) {
					$fragments[] = array(
						'text'    => trim( wp_strip_all_tags( $tab_title ) ),
						'type'    => 'yith_tab_title',
						'context' => 'text_fragment',
					);
				}

				if ( ! empty( $tab_content ) ) {
					$content = trim( wp_strip_all_tags( $tab_content ) );
					if ( strlen( $content ) >= 3 ) {
						$fragments[] = array(
							'text'    => $content,
							'type'    => 'yith_tab_content',
							'context' => 'text_fragment',
						);
					}
				}
			}
		}

		// WooCommerce Product Add-ons (for form labels)
		$addons = get_post_meta( $post_id, '_product_addons', true );
		if ( is_array( $addons ) ) {
			foreach ( $addons as $addon ) {
				if ( ! empty( $addon['name'] ) ) {
					$fragments[] = array(
						'text'    => trim( wp_strip_all_tags( $addon['name'] ) ),
						'type'    => 'addon_label',
						'context' => 'text_fragment',
					);
				}

				if ( ! empty( $addon['description'] ) ) {
					$desc = trim( wp_strip_all_tags( $addon['description'] ) );
					if ( strlen( $desc ) >= 3 ) {
						$fragments[] = array(
							'text'    => $desc,
							'type'    => 'addon_description',
							'context' => 'text_fragment',
						);
					}
				}

				// Addon options
				if ( isset( $addon['options'] ) && is_array( $addon['options'] ) ) {
					foreach ( $addon['options'] as $option ) {
						if ( ! empty( $option['label'] ) ) {
							$label = trim( wp_strip_all_tags( $option['label'] ) );
							if ( strlen( $label ) >= 2 ) {
								$fragments[] = array(
									'text'    => $label,
									'type'    => 'addon_option',
									'context' => 'text_fragment',
								);
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Extract common UI elements that appear on any page/product
	 */
	private function extractCommonUIElements( $post_id ) {
		$fragments = array();

		// Common UI strings that appear across WordPress/WooCommerce sites
		$common_strings = array(
			// General headings
			'Related products',
			'You may also like',
			'Recently viewed',
			'Featured products',
			'Best sellers',
			'On sale',
			'New arrivals',

			// Common phrases (from your example)
			'visit us in person or browse our online store',
			'Browse our online store or visit us in person to experience the beauty of nature',
			'experience the beauty of nature',

			// Navigation/buttons
			'Read more',
			'Learn more',
			'View details',
			'Shop now',
			'Browse',
			'Search',
			'Filter',
			'Sort by',
			'Show all',
			'Load more',

			// Form elements
			'Submit',
			'Send',
			'Subscribe',
			'Sign up',
			'Login',
			'Register',
			'Forgot password',
			'Remember me',

			// Common labels
			'Price',
			'Quantity',
			'Total',
			'Subtotal',
			'Shipping',
			'Tax',
			'Discount',
			'Coupon',
			'Apply coupon',

			// Status messages
			'Success',
			'Error',
			'Warning',
			'Info',
			'Loading',
			'Please wait',

			// Pagination
			'Previous',
			'Next',
			'First',
			'Last',
			'Page',
			'of',

			// Time/date
			'Today',
			'Yesterday',
			'This week',
			'This month',
			'This year',
		);

		foreach ( $common_strings as $string ) {
			$fragments[] = array(
				'text'    => $string,
				'type'    => 'common_ui',
				'context' => 'text_fragment',
			);
		}

		return $fragments;
	}

	/**
	 * Extract WordPress post-specific elements (comments, author info, etc.)
	 */
	private function extractWordPressPostElements( $post_id ) {
		$fragments = array();

		// 1. Extract comment-related text
		$comment_fragments = $this->extractCommentElements( $post_id );
		$fragments         = array_merge( $fragments, $comment_fragments );

		// 2. Extract post meta information
		$post_meta_fragments = $this->extractPostMetaElements( $post_id );
		$fragments           = array_merge( $fragments, $post_meta_fragments );

		// 3. Extract author information
		$author_fragments = $this->extractAuthorElements( $post_id );
		$fragments        = array_merge( $fragments, $author_fragments );

		// 4. Extract post navigation and related posts
		$navigation_fragments = $this->extractPostNavigationElements( $post_id );
		$fragments            = array_merge( $fragments, $navigation_fragments );

		// 5. Extract category and tag information
		$taxonomy_fragments = $this->extractTaxonomyElements( $post_id );
		$fragments          = array_merge( $fragments, $taxonomy_fragments );

		return $fragments;
	}

	/**
	 * Extract comment section elements
	 */
	private function extractCommentElements( $post_id ) {
		$fragments = array();

		// Common comment-related strings
		$comment_strings = array(
			'Leave a Comment',
			'Leave a Reply',
			'Post Comment',
			'Submit Comment',
			'Reply',
			'Edit',
			'Delete',
			'Approve',
			'Your comment is awaiting moderation',
			'Comment',
			'Comments',
			'No comments',
			'No comments yet',
			'Be the first to comment',
			'Comments are closed',
			'Name',
			'Email',
			'Website',
			'Message',
			'Your email address will not be published',
			'Required fields are marked',
			'Save my name, email, and website in this browser for the next time I comment',
			'Notify me of follow-up comments by email',
			'Notify me of new posts by email',
			'This site uses Akismet to reduce spam',
			'Learn how your comment data is processed',
		);

		foreach ( $comment_strings as $string ) {
			$fragments[] = array(
				'text'    => $string,
				'type'    => 'comment_ui',
				'context' => 'text_fragment',
			);
		}

		// Extract existing comment content
		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => 'approve',
				'number'  => 20, // Limit to avoid too many
				'order'   => 'DESC',
			)
		);

		foreach ( $comments as $comment ) {
			// Comment content
			if ( ! empty( $comment->comment_content ) ) {
				$content = trim( wp_strip_all_tags( $comment->comment_content ) );
				if ( strlen( $content ) >= 10 ) {
					$fragments[] = array(
						'text'    => $content,
						'type'    => 'comment_content',
						'context' => 'text_fragment',
					);
				}
			}

			// Comment author name (if not just a username)
			if ( ! empty( $comment->comment_author ) && strlen( $comment->comment_author ) >= 3 ) {
				$author = trim( $comment->comment_author );
				if ( ! preg_match( '/^[a-z0-9_]+$/i', $author ) ) { // Not just a username
					$fragments[] = array(
						'text'    => $author,
						'type'    => 'comment_author',
						'context' => 'text_fragment',
					);
				}
			}
		}

		return $fragments;
	}

	/**
	 * Extract post meta elements
	 */
	private function extractPostMetaElements( $post_id ) {
		$fragments = array();

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $fragments;
		}

		// Post date strings
		$date_strings = array(
			'Published on',
			'Posted on',
			'Date',
			'Published',
			'Last updated',
			'Modified',
			'By',
			'Author',
			'Written by',
			'Posted by',
			'in',
			'under',
			'Tagged',
			'Filed under',
			'Categories',
			'Tags',
			'Read time',
			'minute read',
			'minutes read',
			'Reading time',
		);

		foreach ( $date_strings as $string ) {
			$fragments[] = array(
				'text'    => $string,
				'type'    => 'post_meta_label',
				'context' => 'text_fragment',
			);
		}

		return $fragments;
	}

	/**
	 * Extract author elements
	 */
	private function extractAuthorElements( $post_id ) {
		$fragments = array();

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $fragments;
		}

		$author_id = $post->post_author;
		$author    = get_userdata( $author_id );

		if ( $author ) {
			// Author display name (if it's not just a username)
			$display_name = $author->display_name;
			if ( ! empty( $display_name ) && strlen( $display_name ) >= 3 ) {
				if ( ! preg_match( '/^[a-z0-9_]+$/i', $display_name ) ) {
					$fragments[] = array(
						'text'    => $display_name,
						'type'    => 'author_name',
						'context' => 'text_fragment',
					);
				}
			}

			// Author bio
			$author_bio = get_user_meta( $author_id, 'description', true );
			if ( ! empty( $author_bio ) ) {
				$bio = trim( wp_strip_all_tags( $author_bio ) );
				if ( strlen( $bio ) >= 10 ) {
					$fragments[] = array(
						'text'    => $bio,
						'type'    => 'author_bio',
						'context' => 'text_fragment',
					);
				}
			}
		}

		return $fragments;
	}

	/**
	 * Extract post navigation elements
	 */
	private function extractPostNavigationElements( $post_id ) {
		$fragments = array();

		// Post navigation strings
		$navigation_strings = array(
			'Previous post',
			'Next post',
			'Previous',
			'Next',
			'Older posts',
			'Newer posts',
			'Continue reading',
			'Read more',
			'Related posts',
			'You might also like',
			'Similar posts',
			'More from this author',
			'More in this category',
			'Share this post',
			'Share',
			'Print',
			'Email this post',
		);

		foreach ( $navigation_strings as $string ) {
			$fragments[] = array(
				'text'    => $string,
				'type'    => 'post_navigation',
				'context' => 'text_fragment',
			);
		}

		return $fragments;
	}

	/**
	 * Extract taxonomy elements (categories, tags)
	 */
	private function extractTaxonomyElements( $post_id ) {
		$fragments = array();

		// Extract categories
		$categories = wp_get_post_categories( $post_id, array( 'fields' => 'all' ) );
		foreach ( $categories as $category ) {
			if ( ! empty( $category->name ) && strlen( $category->name ) >= 2 ) {
				$fragments[] = array(
					'text'    => trim( $category->name ),
					'type'    => 'post_category',
					'context' => 'text_fragment',
				);
			}

			// Category description
			if ( ! empty( $category->description ) ) {
				$desc = trim( wp_strip_all_tags( $category->description ) );
				if ( strlen( $desc ) >= 10 ) {
					$fragments[] = array(
						'text'    => $desc,
						'type'    => 'category_description',
						'context' => 'text_fragment',
					);
				}
			}
		}

		// Extract tags
		$tags = wp_get_post_tags( $post_id );
		foreach ( $tags as $tag ) {
			if ( ! empty( $tag->name ) && strlen( $tag->name ) >= 2 ) {
				$fragments[] = array(
					'text'    => trim( $tag->name ),
					'type'    => 'post_tag',
					'context' => 'text_fragment',
				);
			}

			// Tag description
			if ( ! empty( $tag->description ) ) {
				$desc = trim( wp_strip_all_tags( $tag->description ) );
				if ( strlen( $desc ) >= 10 ) {
					$fragments[] = array(
						'text'    => $desc,
						'type'    => 'tag_description',
						'context' => 'text_fragment',
					);
				}
			}
		}

		return $fragments;
	}

	/**
	 * Load CSS exclusion rules from database
	 */
	private function loadCssExclusions() {
		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions';

		$this->css_exclusions = array();

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) != $table_name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return;
		}

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT rule_value FROM `{$table_name}` WHERE rule_type = %s AND is_active = 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'css'
			),
			ARRAY_A
		);

		foreach ( $results as $row ) {
			$this->css_exclusions[] = $row['rule_value'];
		}
	}

	/**
	 * Check if an HTML element has any CSS exclusion class
	 */
	private function hasExclusionClass( $element ) {
		if ( ! $element->hasAttribute( 'class' ) ) {
			return false;
		}

		$element_classes = $element->getAttribute( 'class' );

		// Check against each CSS exclusion rule
		foreach ( $this->css_exclusions as $css_selector ) {
			// Extract class name from CSS selector (e.g., ".no-translate" -> "no-translate")
			$class_name = ltrim( $css_selector, '.' );

			// Check if element has this class
			if ( strpos( $element_classes, $class_name ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
