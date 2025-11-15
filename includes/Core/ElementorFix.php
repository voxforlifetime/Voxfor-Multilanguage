<?php
namespace VoxforML\Core;

/**
 * Fix for Elementor pages that bypass normal WordPress translation filters
 */
class ElementorFix {
	private $plugin;

	public function __construct() {
		$this->plugin = Plugin::getInstance();
		$this->init();
	}

	public function init() {
		// Only run on frontend for non-English languages
		if ( is_admin() ) {
			return;
		}

		// Use early hook to ensure this runs before other translation attempts
		add_action( 'template_redirect', array( $this, 'handleTranslation' ), 1 );

		// Also hook into wp_loaded as backup
		add_action( 'wp_loaded', array( $this, 'handleTranslation' ), 999 );
	}

	public function handleTranslation() {
		// Prevent multiple initializations
		static $initialized = false;
		if ( $initialized ) {
			return;
		}

		$current_language = $this->plugin->getCurrentLanguage();

		// Only for non-English languages
		if ( $current_language === 'en' ) {
			return;
		}

		$initialized = true;

		// Start output buffering to capture the entire page
		ob_start( array( $this, 'translatePageContent' ) );
	}

	public function translatePageContent( $content ) {
		$current_language = $this->plugin->getCurrentLanguage();

		if ( $current_language === 'en' || empty( $content ) || strlen( $content ) < 100 ) {
			return $content;
		}

		// Performance check: Skip if content is too large (>1MB)
		if ( strlen( $content ) > 1048576 ) {
			return $content;
		}

		try {
			// Get translation memory to check for existing translations
			$memory = new \VoxforML\Database\TranslationMemory();

			// Only translate using existing memory, no API calls
			$content = $this->translateHtmlContentFromMemoryOnly( $content, $memory, $current_language );

			return $content;
		} catch ( Exception $e ) {
			// If anything goes wrong, return original content
			return $content;
		}
	}

	/**
	 * Translate HTML content using ONLY existing translations from memory
	 * This method NEVER triggers API calls - frontend display only
	 */
	private function translateHtmlContentFromMemoryOnly( $content, $memory, $language ) {
		// Protect CSS and JavaScript blocks from translation
		$protected_blocks = array();
		$block_counter    = 0;

		// Protect <style> blocks
		$content = preg_replace_callback(
			'/<style[^>]*>.*?<\/style>/is',
			function ( $matches ) use ( &$protected_blocks, &$block_counter ) {
				$placeholder                      = "<!--PROTECTED_STYLE_BLOCK_{$block_counter}-->";
				$protected_blocks[ $placeholder ] = $matches[0];
				$block_counter++;
				return $placeholder;
			},
			$content
		);

		// Protect <script> blocks
		$content = preg_replace_callback(
			'/<script[^>]*>.*?<\/script>/is',
			function ( $matches ) use ( &$protected_blocks, &$block_counter ) {
				$placeholder                      = "<!--PROTECTED_SCRIPT_BLOCK_{$block_counter}-->";
				$protected_blocks[ $placeholder ] = $matches[0];
				$block_counter++;
				return $placeholder;
			},
			$content
		);

		// Only translate text in specific HTML elements
		$content = preg_replace_callback(
			'/<(h[1-6]|p|span|div|a|li|td|th|label|title)[^>]*>([^<]+)<\/\1>/u',
			function ( $matches ) use ( $memory, $language ) {
				$element = $matches[1];
				$text    = trim( $matches[2] );

				// Skip if text is too short or contains unwanted patterns
				if ( strlen( $text ) < 3 ||
					! preg_match( '/[a-zA-Z]/', $text ) ||
					$this->containsCodePatterns( $text ) ) {
					return $matches[0];
				}

				// Check for existing translation
				$translated = $this->findTranslationInMemory( $text, $memory, $language );

				// Return the original match with translated text
				return str_replace( $text, $translated, $matches[0] );
			},
			$content
		);

		// Restore protected blocks
		foreach ( $protected_blocks as $placeholder => $original ) {
			$content = str_replace( $placeholder, $original, $content );
		}

		return $content;
	}

	/**
	 * Check if text contains code patterns that should not be translated
	 */
	private function containsCodePatterns( $text ) {
		$patterns = array(
			'{',
			'}',
			'var ',
			'function',
			'px',
			'margin',
			'padding',
			'color:',
			'font-',
			'width:',
			'height:',
			'display:',
			'position:',
			'jQuery',
			'$',
			'window.',
			'document.',
			'console.',
			'return ',
			'if (',
			'for (',
			'while (',
			'&&',
			'||',
			'===',
			'!==',
			'http://',
			'https://',
			'mailto:',
			'tel:',
			'ftp://',
		);

		foreach ( $patterns as $pattern ) {
			if ( strpos( $text, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find translation in memory across multiple contexts
	 */
	private function findTranslationInMemory( $text, $memory, $language ) {
		// Try multiple contexts where translations might be stored
		// Order by most likely contexts first
		$contexts = array(
			'text_fragment',    // Most common from bulk translation
			'content',          // Standard content
			'menu_item',        // Navigation items
			'widget_title',     // Widget titles
			'general',          // General translations
			'title',            // Page/post titles
			'navigation',       // Navigation specific
			'elementor',        // Elementor specific
			'footer',           // Footer specific
			'header',            // Header specific
		);

		foreach ( $contexts as $context ) {
			$existing = $memory->getTranslation( $text, $language, $context );
			if ( $existing !== false ) {
				// Translation found
				// Apply glossary to existing translation
				$processor = new \VoxforML\Utils\TextProcessor();
				return $processor->applyGlossary( $existing, $language );
			}
		}

		// Try case-insensitive search as fallback
		$text_lower = strtolower( $text );
		$text_upper = strtoupper( $text );
		$text_title = ucwords( strtolower( $text ) );

		$variations = array( $text_lower, $text_upper, $text_title );

		foreach ( $variations as $variation ) {
			if ( $variation !== $text ) {
				foreach ( $contexts as $context ) {
					$existing = $memory->getTranslation( $variation, $language, $context );
					if ( $existing !== false ) {
						// Translation found via variation
						$processor = new \VoxforML\Utils\TextProcessor();
						return $processor->applyGlossary( $existing, $language );
					}
				}
			}
		}

		// If no translation found, return original text
		return $text;
	}
}
