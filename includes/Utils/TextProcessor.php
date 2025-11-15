<?php
namespace VoxforML\Utils;

/**
 * Processes text for translation, handling exclusions and glossary
 */
class TextProcessor {
	private $glossary;
	private $exclusions;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->loadGlossary();
		$this->loadExclusions();
	}

	/**
	 * Prepare content for translation
	 */
	public function prepareContent( $content ) {
		// Early return for non-HTML content
		if ( ! $this->containsHtml( $content ) ) {
			return array(
				'text'         => $content,
				'placeholders' => array(),
			);
		}

		// Extract and replace HTML elements that shouldn't be translated
		$placeholders      = array();
		$placeholder_index = 0;

		// Protect code blocks
		$content = preg_replace_callback(
			'/<(code|pre|script|style)[^>]*>.*?<\/\1>/is',
			function ( $matches ) use ( &$placeholders, &$placeholder_index ) {
				$placeholder                  = "[[VOXFOR_PLACEHOLDER_{$placeholder_index}]]";
				$placeholders[ $placeholder ] = $matches[0];
				$placeholder_index++;
				return $placeholder;
			},
			$content
		);

		// Protect shortcodes
		$content = preg_replace_callback(
			'/\[[^\]]+\]/',
			function ( $matches ) use ( &$placeholders, &$placeholder_index ) {
				$placeholder                  = "[[VOXFOR_PLACEHOLDER_{$placeholder_index}]]";
				$placeholders[ $placeholder ] = $matches[0];
				$placeholder_index++;
				return $placeholder;
			},
			$content
		);

		// Protect elements with no-translate class
		$content = preg_replace_callback(
			'/<[^>]+class=["\'][^"\']*(?:no-translate|notranslate)[^"\']*["\'][^>]*>.*?<\/[^>]+>/is',
			function ( $matches ) use ( &$placeholders, &$placeholder_index ) {
				$placeholder                  = "[[VOXFOR_PLACEHOLDER_{$placeholder_index}]]";
				$placeholders[ $placeholder ] = $matches[0];
				$placeholder_index++;
				return $placeholder;
			},
			$content
		);

		// Protect Elementor data attributes (to prevent breaking layout)
		$content = preg_replace_callback(
			'/data-elementor-[^=]*=["\'][^"\']*["\']/',
			function ( $matches ) use ( &$placeholders, &$placeholder_index ) {
				$placeholder                  = "[[VOXFOR_PLACEHOLDER_{$placeholder_index}]]";
				$placeholders[ $placeholder ] = $matches[0];
				$placeholder_index++;
				return $placeholder;
			},
			$content
		);

		// Apply exclusion rules
		$content = $this->applyExclusions( $content, $placeholders, $placeholder_index );

		// NEW: Extract and process text content properly
		$processed_content = $this->extractAndProcessText( $content, $placeholders, $placeholder_index );

		return array(
			'text'         => $processed_content,
			'placeholders' => $placeholders,
		);
	}

	/**
	 * Restore content after translation
	 */
	public function restoreContent( $translated_text, $placeholders ) {
		foreach ( $placeholders as $placeholder => $original ) {
			$translated_text = str_replace( $placeholder, $original, $translated_text );
		}

		return $translated_text;
	}

	/**
	 * Apply glossary rules
	 */
	public function applyGlossary( $text, $language ) {
		if ( ! isset( $this->glossary[ $language ] ) ) {
			return $text;
		}

		$original_text = $text;

		foreach ( $this->glossary[ $language ] as $term ) {
			if ( $term['match_type'] === 'exact' ) {
				// For Hebrew and other non-Latin scripts, word boundaries may not work properly
				// Use a more flexible approach for exact matching
				if ( preg_match( '/[\p{Hebrew}\p{Arabic}\p{Cyrillic}\p{Han}\p{Hiragana}\p{Katakana}]/u', $term['term'] ) ) {
					// For non-Latin scripts, use simple string replacement with word-like boundaries
					$pattern = '/(?<!\p{L})' . preg_quote( $term['term'], '/' ) . '(?!\p{L})/u';
				} else {
					// For Latin scripts, use traditional word boundaries
					$pattern = '/\b' . preg_quote( $term['term'], '/' ) . '\b/';
				}

				if ( ! $term['case_sensitive'] ) {
					$pattern .= 'i';
				}
			} else {
				// Partial match
				$pattern = '/' . preg_quote( $term['term'], '/' ) . '/';
				if ( ! $term['case_sensitive'] ) {
					$pattern .= 'i';
				}
			}

			$text = preg_replace( $pattern, $term['translation'], $text );
		}

		return $text;
	}

	/**
	 * Check if content should be excluded
	 */
	public function shouldExclude( $content, $context ) {
		// Check namespace exclusions
		foreach ( $this->exclusions['namespace'] as $namespace ) {
			if ( $context === $namespace['value'] ) {
				return true;
			}
		}

		// Check regex exclusions
		foreach ( $this->exclusions['regex'] as $regex ) {
			if ( @preg_match( $regex['value'], $content ) ) {
				return true;
			}
		}

		// Check CSS selector exclusions
		foreach ( $this->exclusions['css'] as $css_rule ) {
			$pattern = $this->cssToRegex( $css_rule['value'] );
			if ( $pattern !== '/(?!.*)/' && @preg_match( $pattern, $content ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if URL is excluded
	 */
	public function isUrlExcluded( $url ) {
		foreach ( $this->exclusions['url'] as $pattern ) {
			// Use fnmatch for shell-style pattern matching (supports wildcards like *)
			if ( fnmatch( $pattern['value'], $url ) ) {
				return true;
			}

			// Also support exact string matching
			if ( $pattern['value'] === $url ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Apply exclusion rules to content
	 */
	private function applyExclusions( $content, &$placeholders, &$placeholder_index ) {
		// Apply CSS selector exclusions
		foreach ( $this->exclusions['css'] as $selector ) {
			// Convert CSS selector to regex pattern
			$pattern = $this->cssToRegex( $selector['value'] );

			$content = preg_replace_callback(
				$pattern,
				function ( $matches ) use ( &$placeholders, &$placeholder_index ) {
					$placeholder                  = "[[VOXFOR_PLACEHOLDER_{$placeholder_index}]]";
					$placeholders[ $placeholder ] = $matches[0];
					$placeholder_index++;
					return $placeholder;
				},
				$content
			);
		}

		return $content;
	}

	/**
	 * Convert CSS selector to regex pattern
	 */
	private function cssToRegex( $selector ) {
		$selector = trim( $selector );

		// Handle multiple selectors separated by commas
		if ( strpos( $selector, ',' ) !== false ) {
			$selectors = array_map( 'trim', explode( ',', $selector ) );
			$patterns  = array();
			foreach ( $selectors as $single_selector ) {
				$pattern = $this->cssToRegex( $single_selector );
				if ( $pattern !== '/(?!.*)/' && ! in_array( $pattern, $patterns ) ) {
					$patterns[] = $pattern;
				}
			}
			if ( empty( $patterns ) ) {
				return '/(?!.*)/';
			}
			return '/' . implode(
				'|',
				array_map(
					function ( $p ) {
						return substr( $p, 1, -2 );
					},
					$patterns
				)
			) . '/is';
		}

		// Class selectors (e.g., .no-translate, .elementor-widget)
		if ( strpos( $selector, '.' ) === 0 ) {
			$class = substr( $selector, 1 );
			// FIXED: Simplified and more reliable class selector matching
			return '/<[^>]+class=["\'][^"\']*\b' . preg_quote( $class, '/' ) . '\b[^"\']*["\'][^>]*>.*?<\/[^>]+>/s';
		}

		// ID selectors (e.g., #header)
		if ( strpos( $selector, '#' ) === 0 ) {
			$id = substr( $selector, 1 );
			// FIXED: Simplified ID selector matching
			return '/<[^>]+id=["\']' . preg_quote( $id, '/' ) . '["\'][^>]*>.*?<\/[^>]+>/s';
		}

		// Element selectors (e.g., script, style, code, pre)
		if ( preg_match( '/^[a-zA-Z][a-zA-Z0-9]*$/', $selector ) ) {
			$element = preg_quote( $selector, '/' );
			// FIXED: Simplified element matching
			return '/<' . $element . '(?:\s[^>]*)?>.*?<\/' . $element . '>/s';
		}

		// Attribute selectors (e.g., [data-no-translate], [class*="icon"])
		if ( preg_match( '/^\[([^=\]]+)([*^$]?=)?["\']?([^"\'\]]*)["\']?\]$/', $selector, $matches ) ) {
			$attr     = preg_quote( $matches[1], '/' );
			$operator = $matches[2] ?? '';
			$value    = isset( $matches[3] ) ? preg_quote( $matches[3], '/' ) : '';

			if ( $operator === '*=' && $value ) {
				// Contains operator [class*="icon"]
				return '/<[^>]+' . $attr . '=["\'][^"\']*' . $value . '[^"\']*["\'][^>]*>.*?<\/[^>]+>/s';
			} elseif ( $operator === '^=' && $value ) {
				// Starts with operator [class^="icon"]
				return '/<[^>]+' . $attr . '=["\']' . $value . '[^"\']*["\'][^>]*>.*?<\/[^>]+>/s';
			} elseif ( $operator === '$=' && $value ) {
				// Ends with operator [class$="icon"]
				return '/<[^>]+' . $attr . '=["\'][^"\']*' . $value . '["\'][^>]*>.*?<\/[^>]+>/s';
			} elseif ( $operator === '=' && $value ) {
				// Exact match [class="icon"]
				return '/<[^>]+' . $attr . '=["\']' . $value . '["\'][^>]*>.*?<\/[^>]+>/s';
			} else {
				// Attribute exists [data-something]
				return '/<[^>]+' . $attr . '[^>]*>.*?<\/[^>]+>/s';
			}
		}

		// For complex selectors not supported, return a pattern that won't match anything
		return '/(?!.*)/';
	}

	/**
	 * Load glossary from database
	 */
	private function loadGlossary() {
		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'glossary';

		$this->glossary = array();

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) != $table_name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return;
		}

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT * FROM {$table_name} ORDER BY priority DESC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( $wpdb->last_error ) {
			return;
		}

		foreach ( $results as $row ) {
			if ( ! isset( $this->glossary[ $row['language_code'] ] ) ) {
				$this->glossary[ $row['language_code'] ] = array();
			}

			$this->glossary[ $row['language_code'] ][] = array(
				'term'           => $row['term'],
				'translation'    => $row['translation'],
				'case_sensitive' => (bool) $row['case_sensitive'],
				'match_type'     => $row['match_type'],
			);
		}
	}

	/**
	 * Load exclusions from database
	 */
	private function loadExclusions() {
		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions';

		$this->exclusions = array(
			'url'       => array(),
			'css'       => array(),
			'regex'     => array(),
			'namespace' => array(),
		);

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) != $table_name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			return;
		}

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT * FROM {$table_name} WHERE is_active = 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( $wpdb->last_error ) {

			return;
		}

		foreach ( $results as $row ) {
			$this->exclusions[ $row['rule_type'] ][] = array(
				'value'       => $row['rule_value'],
				'description' => $row['description'],
			);
		}
	}

	/**
	 * Extract translatable strings from HTML
	 */
	public function extractTranslatableStrings( $html ) {
		$strings = array();

		// Remove script and style tags
		$html = preg_replace( '/<(script|style)[^>]*>.*?<\/\1>/is', '', $html );

		// Extract text nodes
		$dom = new \DOMDocument();
		@$dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		$xpath      = new \DOMXPath( $dom );
		$text_nodes = $xpath->query( '//text()[normalize-space() != ""]' );

		foreach ( $text_nodes as $node ) {
			$text = trim( $node->nodeValue );
			if ( ! empty( $text ) && strlen( $text ) > 1 ) {
				$strings[] = $text;
			}
		}

		// Extract attributes that should be translated
		$translatable_attrs = array( 'alt', 'title', 'placeholder', 'aria-label' );

		foreach ( $translatable_attrs as $attr ) {
			$nodes = $xpath->query( '//*[@' . $attr . ']' );
			foreach ( $nodes as $node ) {
				$value = $node->getAttribute( $attr );
				if ( ! empty( $value ) ) {
					$strings[] = $value;
				}
			}
		}

		return array_unique( $strings );
	}

	/**
	 * Split text into sentences for better translation
	 */
	public function splitIntoSentences( $text ) {
		// Simple sentence splitting
		$sentences = preg_split( '/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );

		// Merge short sentences that might have been incorrectly split
		$merged  = array();
		$current = '';

		foreach ( $sentences as $sentence ) {
			if ( strlen( $current ) + strlen( $sentence ) < 100 && ! empty( $current ) ) {
				$current .= ' ' . $sentence;
			} else {
				if ( ! empty( $current ) ) {
					$merged[] = $current;
				}
				$current = $sentence;
			}
		}

		if ( ! empty( $current ) ) {
			$merged[] = $current;
		}

		return $merged;
	}

	/**
	 * Clean text for translation
	 */
	public function cleanText( $text ) {
		// Remove extra whitespace
		$text = preg_replace( '/\s+/', ' ', $text );

		// Trim
		$text = trim( $text );

		// Remove zero-width characters
		$text = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text );

		return $text;
	}

	/**
	 * Check if content contains HTML
	 */
	private function containsHtml( $content ) {
		return $content !== wp_strip_all_tags( $content );
	}

	/**
	 * Extract and process text content from HTML while preserving structure
	 */
	private function extractAndProcessText( $content, &$placeholders, &$placeholder_index ) {
		// If content doesn't contain meaningful HTML, return as-is
		if ( ! $this->containsHtml( $content ) ) {
			return $content;
		}

		try {
			// Create DOM document
			$dom                     = new \DOMDocument();
			$dom->preserveWhiteSpace = false;
			$dom->formatOutput       = false;

			// Load HTML content with error suppression
			libxml_use_internal_errors( true );
			$success = $dom->loadHTML(
				'<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $content . '</body></html>',
				LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
			);
			libxml_clear_errors();

			if ( ! $success ) {
				// Fallback: return original content if DOM parsing fails
				return $content;
			}

			$xpath = new \DOMXPath( $dom );

			// Process text nodes
			$this->processTextNodes( $dom, $xpath, $placeholders, $placeholder_index );

			// Process translatable attributes (alt, title, placeholder, aria-label)
			$this->processTranslatableAttributes( $dom, $xpath, $placeholders, $placeholder_index );

			// Extract body content
			$body = $dom->getElementsByTagName( 'body' )->item( 0 );
			if ( $body ) {
				$processed = '';
				foreach ( $body->childNodes as $node ) {
					$processed .= $dom->saveHTML( $node );
				}
				return $processed;
			}

			return $content;

		} catch ( \Exception $e ) {
			// Fallback to original content if anything goes wrong
			return $content;
		}
	}

	/**
	 * Process text nodes in DOM
	 */
	private function processTextNodes( $dom, $xpath, &$placeholders, &$placeholder_index ) {
		// Find all text nodes that contain meaningful content
		$text_nodes = $xpath->query( '//text()[normalize-space() != ""]' );

		foreach ( $text_nodes as $node ) {
			$text = trim( $node->nodeValue );

			// ENHANCED: Normalize whitespace and remove extra spaces/newlines
			$text = preg_replace( '/\s+/', ' ', $text );
			$text = trim( $text );

			// Skip very short text or whitespace-only content
			if ( strlen( $text ) < 2 ) {
				continue;
			}

			// Skip existing placeholders to prevent double-processing
			if ( preg_match( '/\[\[VOXFOR_(?:PLACEHOLDER|TEXT|ATTR)_\d+\]\]/', $text ) ) {
				continue;
			}

			// ENHANCED: Detect and clean up text duplications (including HTML-level duplications)
			$words      = explode( ' ', $text );
			$word_count = count( $words );

			// Skip very short text
			if ( $word_count < 2 ) {
				continue;
			}

			// Check for exact duplications (handles 2x, 3x, 4x, 5x, etc. duplications)
			$original_text = $text;

			// Try different division factors to find repeating patterns
			for ( $divisor = 2; $divisor <= min( 10, $word_count ); $divisor++ ) {
				if ( $word_count % $divisor == 0 ) {
					$segment_length = $word_count / $divisor;
					$first_segment  = array_slice( $words, 0, $segment_length );

					// Check if all segments are identical to the first segment
					$is_exact_repetition = true;
					for ( $i = 1; $i < $divisor; $i++ ) {
						$current_segment = array_slice( $words, $i * $segment_length, $segment_length );
						if ( $current_segment !== $first_segment ) {
							$is_exact_repetition = false;
							break;
						}
					}

					// If we found exact repetition, use only the first segment
					if ( $is_exact_repetition ) {
						$text = implode( ' ', $first_segment );
						break; // Stop after finding the first valid pattern
					}
				}
			}

			// Also check for partial duplications with different ratios
			if ( $word_count > 3 ) {
				$unique_words     = array_unique( $words );
				$uniqueness_ratio = count( $unique_words ) / $word_count;

				// If more than 70% of words are duplicates, it's likely a formatting issue
				if ( $uniqueness_ratio < 0.3 ) {
					// Try to find the repeating pattern
					for ( $pattern_length = 1; $pattern_length <= $word_count / 2; $pattern_length++ ) {
						if ( $word_count % $pattern_length == 0 ) {
							$pattern     = array_slice( $words, 0, $pattern_length );
							$repetitions = $word_count / $pattern_length;

							// Check if this pattern repeats exactly
							$is_pattern = true;
							for ( $i = 1; $i < $repetitions; $i++ ) {
								$current_segment = array_slice( $words, $i * $pattern_length, $pattern_length );
								if ( $current_segment !== $pattern ) {
									$is_pattern = false;
									break;
								}
							}

							if ( $is_pattern ) {
								$text = implode( ' ', $pattern );
								break;
							}
						}
					}
				}
			}

			// Final check after normalization
			if ( strlen( $text ) < 2 ) {
				continue;
			}

				// Create placeholder for this text
				$placeholder                  = "[[VOXFOR_TEXT_{$placeholder_index}]]";
				$placeholders[ $placeholder ] = $text;
				++$placeholder_index;

			// Replace text node content with placeholder
			$node->nodeValue = $placeholder;
		}
	}

	/**
	 * Process translatable attributes in DOM
	 */
	private function processTranslatableAttributes( $dom, $xpath, &$placeholders, &$placeholder_index ) {
		$translatable_attrs = array( 'alt', 'title', 'placeholder', 'aria-label' );

		foreach ( $translatable_attrs as $attr ) {
			$nodes = $xpath->query( '//*[@' . $attr . ']' );

			foreach ( $nodes as $node ) {
				$value = trim( $node->getAttribute( $attr ) );

				if ( strlen( $value ) > 1 ) {
					// Skip existing placeholders to prevent double-processing
					if ( preg_match( '/\[\[VOXFOR_(?:PLACEHOLDER|TEXT|ATTR)_\d+\]\]/', $value ) ) {
						continue;
					}

					$placeholder                  = "[[VOXFOR_ATTR_{$placeholder_index}]]";
					$placeholders[ $placeholder ] = $value;
					++$placeholder_index;

					// Replace attribute value with placeholder
					$node->setAttribute( $attr, $placeholder );
				}
			}
		}
	}

	/**
	 * Decode HTML entities to proper UTF-8 characters
	 * Centralized method for consistent entity decoding across the plugin
	 */
	public function decodeHTMLEntities( $text ) {
		if ( empty( $text ) || strpos( $text, '&#' ) === false ) {
			return $text;
		}

		// Normalize encoded ampersands like &amp;#1489; or &#038;#1489;
		$normalized = preg_replace( '/&(?:amp|#0*38);#/i', '&#', $text );
		
		// MANUAL DECODER: Convert &#1234; or &#x05D1; patterns (semicolon optional) directly to UTF-8
		return preg_replace_callback( '/&#(x?[0-9A-Fa-f]+);?/i', function( $matches ) {
			$value = $matches[1];
			$code  = ( $value[0] === 'x' || $value[0] === 'X' ) ? hexdec( substr( $value, 1 ) ) : intval( $value, 10 );
			
			// Convert Unicode code point to UTF-8 bytes manually
			if ( $code < 0x80 ) {
				return chr( $code );
			} elseif ( $code < 0x800 ) {
				return chr( 0xC0 | ( $code >> 6 ) ) . chr( 0x80 | ( $code & 0x3F ) );
			} elseif ( $code < 0x10000 ) {
				return chr( 0xE0 | ( $code >> 12 ) ) .
				       chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) .
				       chr( 0x80 | ( $code & 0x3F ) );
			} else {
				return chr( 0xF0 | ( $code >> 18 ) ) .
				       chr( 0x80 | ( ( $code >> 12 ) & 0x3F ) ) .
				       chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) .
				       chr( 0x80 | ( $code & 0x3F ) );
			}
		}, $normalized );
	}
}
