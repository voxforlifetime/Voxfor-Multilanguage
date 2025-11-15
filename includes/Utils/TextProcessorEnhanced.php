<?php
namespace VoxforML\Utils;

/**
 * Enhanced Text Processor that preserves HTML structure during translation
 * Prevents layout breaking by using DOMDocument for proper HTML parsing
 */
class TextProcessorEnhanced {
	private $glossary;
	private $exclusions;
	private $dom;
	private $xpath;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->loadGlossary();
		$this->loadExclusions();
	}

	/**
	 * Prepare content for translation while preserving HTML structure
	 */
	public function prepareContent( $content ) {
		// If content has no HTML, return as-is
		if ( $content === wp_strip_all_tags( $content ) ) {
			return array(
				'text'         => $content,
				'placeholders' => array(),
			);
		}

		$placeholders      = array();
		$placeholder_index = 0;

		// First, protect critical elements that should never be translated
		$content = $this->protectCriticalElements( $content, $placeholders, $placeholder_index );

		// Use DOMDocument for proper HTML parsing
		$processed_content = $this->processWithDOM( $content, $placeholders, $placeholder_index );

		return array(
			'text'         => $processed_content,
			'placeholders' => $placeholders,
		);
	}

	/**
	 * Protect critical elements before DOM processing
	 */
	private function protectCriticalElements( $content, &$placeholders, &$placeholder_index ) {
		// Protect scripts and styles completely
		$content = preg_replace_callback(
			'/<(script|style)(\s[^>]*)?>.*?<\/\1>/is',
			function ( $matches ) use ( &$placeholders, &$placeholder_index ) {
				$placeholder                  = "[[VOXFOR_PROTECTED_{$placeholder_index}]]";
				$placeholders[ $placeholder ] = $matches[0];
				$placeholder_index++;
				return $placeholder;
			},
			$content
		);

		// Protect code and pre blocks
		$content = preg_replace_callback(
			'/<(code|pre)(\s[^>]*)?>.*?<\/\1>/is',
			function ( $matches ) use ( &$placeholders, &$placeholder_index ) {
				$placeholder                  = "[[VOXFOR_PROTECTED_{$placeholder_index}]]";
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
				$placeholder                  = "[[VOXFOR_PROTECTED_{$placeholder_index}]]";
				$placeholders[ $placeholder ] = $matches[0];
				$placeholder_index++;
				return $placeholder;
			},
			$content
		);

		// Protect Elementor data attributes and elements
		$content = preg_replace_callback(
			'/data-elementor-[^=]*="[^"]*"/',
			function ( $matches ) use ( &$placeholders, &$placeholder_index ) {
				$placeholder                  = "[[VOXFOR_PROTECTED_{$placeholder_index}]]";
				$placeholders[ $placeholder ] = $matches[0];
				$placeholder_index++;
				return $placeholder;
			},
			$content
		);

		// Protect WordPress block comments (Gutenberg)
		$content = preg_replace_callback(
			'/<!--\s*wp:[^-]+.*?\/wp:[^-]+\s*-->/s',
			function ( $matches ) use ( &$placeholders, &$placeholder_index ) {
				$placeholder                  = "[[VOXFOR_PROTECTED_{$placeholder_index}]]";
				$placeholders[ $placeholder ] = $matches[0];
				$placeholder_index++;
				return $placeholder;
			},
			$content
		);

		return $content;
	}

	/**
	 * Process content with DOMDocument for proper HTML handling
	 */
	private function processWithDOM( $content, &$placeholders, &$placeholder_index ) {
		// Initialize DOM
		$this->dom                     = new \DOMDocument( '1.0', 'UTF-8' );
		$this->dom->preserveWhiteSpace = true;
		$this->dom->formatOutput       = false;

		// Suppress errors from malformed HTML
		libxml_use_internal_errors( true );

		// Wrap content to ensure proper parsing
		$wrapped_content = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $content . '</body></html>';

		// Load HTML
		$loaded = $this->dom->loadHTML( $wrapped_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		if ( ! $loaded ) {
			libxml_clear_errors();
			// If DOM parsing fails, return original content
			return $content;
		}

		libxml_clear_errors();

		// Initialize XPath
		$this->xpath = new \DOMXPath( $this->dom );

		// Process text nodes
		$this->processTextNodes( $placeholders, $placeholder_index );

		// Process translatable attributes
		$this->processTranslatableAttributes( $placeholders, $placeholder_index );

		// Extract body content
		$body = $this->dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return $content;
		}

		// Get inner HTML of body
		$processed = '';
		foreach ( $body->childNodes as $child ) {
			$processed .= $this->dom->saveHTML( $child );
		}

		return $processed;
	}

	/**
	 * Process text nodes for translation
	 */
	private function processTextNodes( &$placeholders, &$placeholder_index ) {
		// Get all text nodes that are not inside excluded elements
		$query = '//text()[normalize-space() != "" and not(ancestor::script) and not(ancestor::style) and not(ancestor::code) and not(ancestor::pre)]';

		// Add exclusion for elements with no-translate class
		$query .= '[not(ancestor::*[contains(@class, "no-translate")]) and not(ancestor::*[contains(@class, "notranslate")])]';

		$textNodes = $this->xpath->query( $query );

		foreach ( $textNodes as $node ) {
			$text = $node->nodeValue;

			// Skip if text is too short or only numbers/special chars
			if ( strlen( trim( $text ) ) < 2 || preg_match( '/^[\d\s\W]+$/', $text ) ) {
				continue;
			}

			// Skip if already a placeholder
			if ( preg_match( '/\[\[VOXFOR_[A-Z_]+_\d+\]\]/', $text ) ) {
				continue;
			}

			// Check exclusions
			if ( $this->shouldExcludeText( $text ) ) {
				continue;
			}

			// Create placeholder for translatable text
			$placeholder                  = "[[VOXFOR_TEXT_{$placeholder_index}]]";
			$placeholders[ $placeholder ] = trim( $text );
			++$placeholder_index;

			// Replace node value with placeholder
			$node->nodeValue = $placeholder;
		}
	}

	/**
	 * Process translatable attributes
	 */
	private function processTranslatableAttributes( &$placeholders, &$placeholder_index ) {
		$translatableAttrs = array( 'alt', 'title', 'placeholder', 'aria-label', 'aria-description' );

		foreach ( $translatableAttrs as $attr ) {
			$nodes = $this->xpath->query( "//*[@{$attr}]" );

			foreach ( $nodes as $node ) {
				$value = $node->getAttribute( $attr );

				// Skip empty or very short attributes
				if ( strlen( trim( $value ) ) < 2 ) {
					continue;
				}

				// Skip if already a placeholder
				if ( preg_match( '/\[\[VOXFOR_[A-Z_]+_\d+\]\]/', $value ) ) {
					continue;
				}

				// Create placeholder
				$placeholder                  = "[[VOXFOR_ATTR_{$placeholder_index}]]";
				$placeholders[ $placeholder ] = trim( $value );
				++$placeholder_index;

				// Set placeholder as attribute value
				$node->setAttribute( $attr, $placeholder );
			}
		}
	}

	/**
	 * Check if text should be excluded
	 */
	private function shouldExcludeText( $text ) {
		// Check regex exclusions
		foreach ( $this->exclusions['regex'] as $regex ) {
			if ( @preg_match( $regex['value'], $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Restore content after translation
	 */
	public function restoreContent( $translated_text, $placeholders ) {
		// Restore placeholders in reverse order to handle nested placeholders
		$placeholder_keys = array_keys( $placeholders );
		rsort( $placeholder_keys );

		foreach ( $placeholder_keys as $placeholder ) {
			$translated_text = str_replace( $placeholder, $placeholders[ $placeholder ], $translated_text );
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

		foreach ( $this->glossary[ $language ] as $term ) {
			if ( $term['match_type'] === 'exact' ) {
				// Exact word match
				$pattern = '/\b' . preg_quote( $term['term'], '/' ) . '\b/';
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

		return false;
	}

	/**
	 * Check if URL is excluded
	 */
	public function isUrlExcluded( $url ) {
		foreach ( $this->exclusions['url'] as $pattern ) {
			if ( fnmatch( $pattern['value'], $url ) ) {
				return true;
			}
		}

		return false;
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

		$results = $wpdb->get_results( "SELECT * FROM {$table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $results as $row ) {
			$this->exclusions[ $row['exclusion_type'] ][] = array(
				'value'       => $row['exclusion_value'],
				'description' => $row['description'],
			);
		}
	}
}
