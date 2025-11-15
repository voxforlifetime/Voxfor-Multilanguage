<?php
namespace VoxforML\Frontend;

/**
 * Display pre-translated content on frontend without calling translation APIs
 * Only shows content that was already translated via admin translate buttons
 */
class TranslatedContentDisplay {
	private $plugin;
	private $memory;
	private $current_language;

	public function __construct() {
		$this->plugin = \VoxforML\Core\Plugin::getInstance();
		$this->memory = $this->plugin->getComponent( 'translation_memory' );
		$this->initHooks();
	}

	private function initHooks() {
		// Only run on frontend, not in admin
		if ( is_admin() ) {
			return;
		}

		// Safe translation hooks that don't break Elementor
		add_filter( 'the_title', array( $this, 'displayTranslatedTitle' ), 20, 2 );
		add_filter( 'document_title_parts', array( $this, 'displayTranslatedDocumentTitle' ), 20 );
		add_filter( 'wp_title', array( $this, 'displayTranslatedWpTitle' ), 20 );
		add_filter( 'option_blogdescription', array( $this, 'displayTranslatedSiteTagline' ), 20 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'displayTranslatedMenuItems' ), 20 );
		add_filter( 'widget_title', array( $this, 'displayTranslatedWidgetTitle' ), 20 );

		// Use output buffering ONLY for non-Elementor pages or after Elementor is done
		add_action( 'wp_head', array( $this, 'startSafeTranslation' ), 1 );
		add_action( 'wp_footer', array( $this, 'endSafeTranslation' ), 999 );
	}

	/**
	 * Display translated content (only from database, no API calls)
	 * CONSERVATIVE: Only translate text content, preserve ALL HTML structure
	 */
	public function displayTranslatedContent( $content ) {
		$current_language = $this->getCurrentLanguage();

		// Only for non-English languages
		if ( $current_language === 'en' || empty( $content ) ) {
			return $content;
		}

		// For Elementor content, use ultra-conservative text-only translation
		if ( strpos( $content, 'elementor' ) !== false || strpos( $content, 'data-' ) !== false ) {
			return $this->translateTextOnlyInHtml( $content, $current_language );
		}

		// For regular content, try direct translation first
		// Try both 'content' and 'post_content' contexts (visual editor uses 'post_content')
		$translated = $this->getTranslationFromDatabase( $content, $current_language, 'content' );
		if ( ! $translated ) {
			$translated = $this->getTranslationFromDatabase( $content, $current_language, 'post_content' );
		}

		if ( $translated ) {
			return $translated;
		}

		// Fallback to conservative text-only translation
		return $this->translateTextOnlyInHtml( $content, $current_language );
	}

	/**
	 * Display translated title
	 */
	public function displayTranslatedTitle( $title, $post_id = null ) {
		$current_language = $this->getCurrentLanguage();

		if ( $current_language === 'en' || empty( $title ) || strlen( $title ) < 3 ) {
			return $title;
		}

		// Only translate if it's plain text (no HTML)
		if ( strpos( $title, '<' ) !== false || strpos( $title, '>' ) !== false ) {
			return $title;
		}


		// Try both 'title' and 'post_title' contexts (visual editor uses 'post_title')
		$translated = $this->getTranslationFromDatabase( trim( $title ), $current_language, 'title' );
		if ( ! $translated ) {
			$translated = $this->getTranslationFromDatabase( trim( $title ), $current_language, 'post_title' );
		}

		// Try 'content' context as well (visual editor might save as content)
		if ( ! $translated ) {
			$translated = $this->getTranslationFromDatabase( trim( $title ), $current_language, 'content' );
			if ( $translated ) {
			}
		}

		if ( $translated ) {
		} else {
		}

		return $translated ?: $title;
	}

	/**
	 * Display translated document title parts (for HTML <title> tag)
	 */
	public function displayTranslatedDocumentTitle( $title_parts ) {
		$current_language = $this->getCurrentLanguage();

		if ( $current_language === 'en' || empty( $title_parts ) ) {
			return $title_parts;
		}

		// Translate the main title (post/page title)
		if ( isset( $title_parts['title'] ) && ! empty( $title_parts['title'] ) ) {
			$original_title = trim( $title_parts['title'] );
			
			// Only translate if it's plain text (no HTML)
			if ( strpos( $original_title, '<' ) === false && strpos( $original_title, '>' ) === false && strlen( $original_title ) >= 3 ) {
				// Try both 'title' and 'post_title' contexts
				$translated = $this->getTranslationFromDatabase( $original_title, $current_language, 'title' );
				if ( ! $translated ) {
					$translated = $this->getTranslationFromDatabase( $original_title, $current_language, 'post_title' );
				}
				
				// Try 'content' context as well (visual editor might save as content)
				if ( ! $translated ) {
					$translated = $this->getTranslationFromDatabase( $original_title, $current_language, 'content' );
				}
				
				if ( $translated ) {
					$title_parts['title'] = $translated;
				}
			}
		}

		// Translate the tagline/site description if present
		if ( isset( $title_parts['tagline'] ) && ! empty( $title_parts['tagline'] ) ) {
			$original_tagline = trim( $title_parts['tagline'] );
			
			if ( strpos( $original_tagline, '<' ) === false && strpos( $original_tagline, '>' ) === false && strlen( $original_tagline ) >= 3 ) {
				$translated_tagline = $this->getTranslationFromDatabase( $original_tagline, $current_language, 'tagline' );
				if ( ! $translated_tagline ) {
					$translated_tagline = $this->getTranslationFromDatabase( $original_tagline, $current_language, 'content' );
				}
				
				if ( $translated_tagline ) {
					$title_parts['tagline'] = $translated_tagline;
				}
			}
		}

		return $title_parts;
	}

	/**
	 * Display translated site tagline (for HTML <title> tag)
	 */
	public function displayTranslatedSiteTagline( $tagline ) {
		$current_language = $this->getCurrentLanguage();

		if ( $current_language === 'en' || empty( $tagline ) || strlen( $tagline ) < 3 ) {
			return $tagline;
		}

		// Only translate if it's plain text (no HTML)
		if ( strpos( $tagline, '<' ) !== false || strpos( $tagline, '>' ) !== false ) {
			return $tagline;
		}

		$original_tagline = trim( $tagline );
		
		// Try 'tagline' context first, then 'content'
		$translated = $this->getTranslationFromDatabase( $original_tagline, $current_language, 'tagline' );
		if ( ! $translated ) {
			$translated = $this->getTranslationFromDatabase( $original_tagline, $current_language, 'content' );
		}

		return $translated ?: $tagline;
	}

	/**
	 * Display translated wp_title (legacy support for older themes)
	 */
	public function displayTranslatedWpTitle( $title ) {
		$current_language = $this->getCurrentLanguage();

		if ( $current_language === 'en' || empty( $title ) || strlen( $title ) < 3 ) {
			return $title;
		}

		// Only translate if it's plain text (no HTML)
		if ( strpos( $title, '<' ) !== false || strpos( $title, '>' ) !== false ) {
			return $title;
		}

		$original_title = trim( $title );
		
		// Try 'title' context first, then 'post_title', then 'content'
		$translated = $this->getTranslationFromDatabase( $original_title, $current_language, 'title' );
		if ( ! $translated ) {
			$translated = $this->getTranslationFromDatabase( $original_title, $current_language, 'post_title' );
		}
		if ( ! $translated ) {
			$translated = $this->getTranslationFromDatabase( $original_title, $current_language, 'content' );
		}

		return $translated ?: $title;
	}

	/**
	 * Display translated excerpt
	 */
	public function displayTranslatedExcerpt( $excerpt ) {
		$current_language = $this->getCurrentLanguage();

		if ( $current_language === 'en' || empty( $excerpt ) || strlen( $excerpt ) < 5 ) {
			return $excerpt;
		}

		// For excerpts with HTML, use conservative text-only translation
		if ( strpos( $excerpt, '<' ) !== false ) {
			return $this->translateTextOnlyInHtml( $excerpt, $current_language );
		}

		$translated = $this->getTranslationFromDatabase( trim( $excerpt ), $current_language, 'excerpt' );

		return $translated ?: $excerpt;
	}

	/**
	 * Display translated menu items
	 */
	public function displayTranslatedMenuItems( $items ) {
		$current_language = $this->getCurrentLanguage();

		if ( $current_language === 'en' || empty( $items ) ) {
			return $items;
		}

		foreach ( $items as $item ) {
			if ( ! empty( $item->title ) && strlen( $item->title ) > 2 ) {
				// Only translate plain text menu items
				if ( strpos( $item->title, '<' ) === false && strpos( $item->title, '>' ) === false ) {
					$translated = $this->getTranslationFromDatabase( trim( $item->title ), $current_language, 'menu_item' );
					
					if ( $translated && $translated !== $item->title ) {
						$item->title = $translated;
					}
				}
			}
		}

		return $items;
	}

	/**
	 * Display translated widget text
	 */
	public function displayTranslatedWidgetText( $text ) {
		$current_language = $this->getCurrentLanguage();

		if ( $current_language === 'en' || empty( $text ) || strlen( $text ) < 3 ) {
			return $text;
		}

		// For widget text with HTML, use conservative translation
		if ( strpos( $text, '<' ) !== false ) {
			return $this->translateTextOnlyInHtml( $text, $current_language );
		}

		$translated = $this->getTranslationFromDatabase( trim( $text ), $current_language, 'widget_text' );

		return $translated ?: $text;
	}

	/**
	 * Display translated widget title
	 */
	public function displayTranslatedWidgetTitle( $title ) {
		$current_language = $this->getCurrentLanguage();

		if ( $current_language === 'en' || empty( $title ) || strlen( $title ) < 3 ) {
			return $title;
		}

		// Only translate plain text widget titles
		if ( strpos( $title, '<' ) !== false || strpos( $title, '>' ) !== false ) {
			return $title;
		}

		$translated = $this->getTranslationFromDatabase( trim( $title ), $current_language, 'widget_title' );

		return $translated ?: $title;
	}

	/**
	 * Get translation from database only (no API calls)
	 */
	private function getTranslationFromDatabase( $text, $language, $context ) {
		if ( ! $this->memory ) {
			return false;
		}

		// Only get existing translations, never trigger new ones
		$cached_translation = $this->memory->getTranslation( $text, $language, $context );

		if ( $cached_translation !== false ) {
			// CRITICAL: Decode HTML entities BEFORE applying glossary
			$processor = new \VoxforML\Utils\TextProcessor();
			$decoded_translation = $processor->decodeHTMLEntities( $cached_translation );
			
			// Apply glossary rules to decoded translation before returning
			$final_result = $processor->applyGlossary( $decoded_translation, $language );
			
			return $final_result;
		}

		return false;
	}

	/**
	 * Ultra-conservative text-only translation for Elementor content
	 * Only translates visible text, preserves ALL HTML structure and attributes
	 */
	private function translateTextOnlyInHtml( $content, $language ) {
		// First, try to translate complete sentences/paragraphs that may have been saved as fragments
		$content = $this->translateCompleteBlocks( $content, $language );
		
		// 🎯 NEW APPROACH: Translate text nodes individually
		// This works with the new backend that splits text around inline tags
		$content = preg_replace_callback(
			'/>([^<]+?)</u',
			function ( $matches ) use ( $language ) {
				$text = $matches[1];

				// Skip anything that could be structural or functional
				if ( strlen( $text ) < 4 ||
					strpos( $text, '&' ) !== false || // HTML entities
					strpos( $text, 'data-' ) !== false || // Data attributes
					strpos( $text, 'class=' ) !== false || // Class attributes
					strpos( $text, 'style=' ) !== false || // Style attributes
					strpos( $text, 'id=' ) !== false || // ID attributes
					strpos( $text, '="' ) !== false || // Any attribute
					strpos( $text, "='" ) !== false || // Any attribute
					strpos( $text, '{' ) !== false || // JSON/JS objects
					strpos( $text, '}' ) !== false || // JSON/JS objects
					strpos( $text, '[' ) !== false || // Arrays
					strpos( $text, ']' ) !== false || // Arrays
					strpos( $text, 'elementor' ) !== false || // Elementor keywords
					strpos( $text, 'widget' ) !== false || // Widget keywords
					is_numeric( trim( $text ) ) || // Pure numbers
					preg_match( '/^\s*[\d\s\-\+\%\$]+\s*$/', $text ) ) { // Numbers with symbols
					return $matches[0];
				}

				// Clean text and final validation
				$clean_text = trim( $text );
				if ( empty( $clean_text ) ||
					strlen( $clean_text ) < 4 ||
					! preg_match( '/[a-zA-Z]{2,}/', $clean_text ) || // Must have at least 2 consecutive letters
					preg_match( '/^[^a-zA-Z]*$/', $clean_text ) ) {   // Must contain some letters
					return $matches[0];
				}

				// 🎯 Try exact match first (for individually stored fragments)
				$translated = $this->getTranslationFromDatabase( $clean_text, $language, 'text_fragment' );

				// If no exact match, try normalized matching
				if ( ! $translated ) {
					$translated = $this->findTranslationByNormalizedText( $clean_text, $language );
				}

				if ( $translated && $translated !== $clean_text && strlen( $translated ) > 0 ) {
					return '>' . $translated . '<';
				}

				return $matches[0];
			},
			$content
		);

		return $content;
	}

	/**
	 * Translate complete blocks by reconstructing fragmented sentences
	 */
	private function translateCompleteBlocks( $content, $language ) {
		// Match block-level elements that commonly contain fragmented sentences
		$content = preg_replace_callback(
			'/<(p|li|td|th|h[1-6]|blockquote|figcaption|div)([^>]*)>(.*?)<\/\1>/is',
			function ( $matches ) use ( $language ) {
				$tag = $matches[1];
				$attributes = $matches[2];
				$inner_content = $matches[3];
				
				// Skip blocks with inline HTML tags - let individual text translation handle those
				if ( preg_match( '/<(a|strong|b|em|i|span|code|mark|u|sub|sup|small)/i', $inner_content ) ) {
					return $matches[0]; // Return unchanged, will be handled by text-only translation
				}
				
				// First, try to find if the complete inner content exists as a translation
				$complete_translation = $this->findCompleteTranslation( $inner_content, $language );
				if ( $complete_translation ) {
					return '<' . $tag . $attributes . '>' . $complete_translation . '</' . $tag . '>';
				}
				
				// Extract all text nodes from the inner content
				$text_parts = array();
				$temp_content = $inner_content;
				
				// Replace inline tags with placeholders to extract text
				$inline_tags = array();
				$placeholder_index = 0;
				
				// Add spaces around inline tags to match database format
				$temp_content = preg_replace_callback(
					'/<(a|strong|b|em|i|span|code|mark|u|sub|sup|small)([^>]*)>(.*?)<\/\1>/is',
					function ( $tag_matches ) use ( &$inline_tags, &$placeholder_index ) {
						$placeholder = " ###INLINE_{$placeholder_index}### ";
						$inline_tags[$placeholder] = $tag_matches[0];
						$placeholder_index++;
						return $placeholder;
					},
					$temp_content
				);
				
				// Also handle self-closing tags
				$temp_content = preg_replace_callback(
					'/<(img|br|hr|input)([^>]*)\/?>/i',
					function ( $tag_matches ) use ( &$inline_tags, &$placeholder_index ) {
						$placeholder = "###INLINE_{$placeholder_index}###";
						$inline_tags[$placeholder] = $tag_matches[0];
						$placeholder_index++;
						return $placeholder;
					},
					$temp_content
				);
				
				// Now extract text parts between placeholders
				$parts = preg_split('/ ?###INLINE_\d+### ?/', $temp_content);
				$placeholders = array();
				preg_match_all('/ ?###INLINE_\d+### ?/', $temp_content, $placeholders);
				
				// Try to find translations for each text part
				$translated_parts = array();
				$has_translation = false;
				
				// FIRST: Try to find translation for the COMPLETE reconstructed sentence
				// This matches how the text was originally stored in the database
				$reconstructed_sentence = implode( ' ', $parts );
				$reconstructed_sentence = trim( preg_replace( '/\s+/', ' ', $reconstructed_sentence ) );
				
				$complete_sentence_translation = $this->findTranslationByNormalizedText( $reconstructed_sentence, $language );
				
				if ( $complete_sentence_translation ) {
					// We have a translation for the complete sentence
					// Now reconstruct it with the inline tags from the original
					$reconstructed = $this->reconstructWithInlineTags( $complete_sentence_translation, $inner_content, $inline_tags );
					return '<' . $tag . $attributes . '>' . $reconstructed . '</' . $tag . '>';
				}
				
				// FALLBACK: Try to find translations for each part individually
				foreach ( $parts as $index => $part ) {
					$clean_part = trim( $part );
					if ( ! empty( $clean_part ) && strlen( $clean_part ) >= 2 ) {
						// Try to find translation using normalized text matching (ignores spacing)
						$translated = $this->findTranslationByNormalizedText( $clean_part, $language );
						
						if ( $translated ) {
							$translated_parts[] = $translated;
							$has_translation = true;
						} else {
							$translated_parts[] = $clean_part;
						}
					} else {
						$translated_parts[] = $part;
					}
				}
				
				// If we found translations, reconstruct the content
				if ( $has_translation ) {
					$new_content = '';
					for ( $i = 0; $i < count( $translated_parts ); $i++ ) {
						$new_content .= $translated_parts[$i];
						if ( isset( $placeholders[0][$i] ) ) {
							$placeholder = $placeholders[0][$i];
							if ( isset( $inline_tags[$placeholder] ) ) {
								// Translate content within inline tags recursively
								$translated_tag = $this->translateCompleteBlocks( $inline_tags[$placeholder], $language );
								$new_content .= $translated_tag;
							}
						}
					}
					
					return '<' . $tag . $attributes . '>' . $new_content . '</' . $tag . '>';
				}
				
				return $matches[0];
			},
			$content
		);
		
		return $content;
	}

	/**
	 * Find complete translation for content that may contain HTML
	 */
private function findCompleteTranslation( $content, $language ) {
	// Extract just the text content (ignore all HTML and normalize spaces)
	$text_only = wp_strip_all_tags( $content );
	$text_only = trim( preg_replace( '/\s+/', ' ', $text_only ) );
	
	if ( empty( $text_only ) || strlen( $text_only ) < 5 ) {
		return false;
	}
	
	// Try to find translation by comparing normalized text (ignoring spacing differences)
	$translation = $this->findTranslationByNormalizedText( $text_only, $language );
	if ( $translation ) {
		return $translation;
	}
	
	// Search for translations that contain this text
	global $wpdb;
	$table = $wpdb->prefix . 'voxfor_ml_translations';
	
	// Search for source text that contains our text (case-insensitive) - use %i for table name
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$sql = $wpdb->prepare(
		"SELECT source_text, translated_text FROM %i 
		WHERE language_code = %s 
		AND context IN ('text_fragment', 'content', 'post_content')
		AND LOWER(source_text) LIKE %s
		ORDER BY LENGTH(source_text) ASC
		LIMIT 10",
		$table,
		$language,
		'%' . $wpdb->esc_like( strtolower( $text_only ) ) . '%'
	);
	
	$results = $wpdb->get_results( $sql );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	
	if ( $results ) {
		foreach ( $results as $row ) {
			// Check if this source text matches our content structure
			$source_text_only = wp_strip_all_tags( $row->source_text );
				$source_text_only = trim( preg_replace( '/\s+/', ' ', $source_text_only ) );
				
				// If the text content matches exactly, use this translation
				if ( strcasecmp( $source_text_only, $text_only ) === 0 ) {
					// If the source had HTML, try to apply the same structure to the translation
					if ( $row->source_text !== $source_text_only ) {
						// The source had HTML, so we need to reconstruct it
						return $this->applyHtmlStructure( $content, $row->source_text, $row->translated_text );
					} else {
						// No HTML in source, just return the translation
						return $row->translated_text;
					}
				}
			}
		}
		
		return false;
	}

	/**
	 * Find translation by normalized text (ignoring spacing differences)
	 */
private function findTranslationByNormalizedText( $normalized_text, $language ) {
	global $wpdb;
	$table = $wpdb->prefix . 'voxfor_ml_translations';
	
	// Use first few words to filter results, then do exact comparison in PHP
	$search_pattern = '%' . $wpdb->esc_like( substr( $normalized_text, 0, 30 ) ) . '%';
	
	// First try: exact normalized match - use %i for table name
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$sql = $wpdb->prepare(
		"SELECT source_text, translated_text FROM %i 
		WHERE language_code = %s 
		AND context IN ('text_fragment', 'content', 'post_content')
		AND source_text LIKE %s
		ORDER BY LENGTH(source_text) ASC
		LIMIT 100",
		$table,
		$language,
		$search_pattern
	);
	
	$results = $wpdb->get_results( $sql );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	
	if ( $results ) {
		foreach ( $results as $row ) {
			// Normalize the database source text (strip tags, normalize spaces)
			$db_text_normalized = wp_strip_all_tags( $row->source_text );
				$db_text_normalized = trim( preg_replace( '/\s+/', ' ', $db_text_normalized ) );
				
				// Compare normalized versions (case-insensitive)
				// Accept exact match OR if the search text is a fragment at the start/end of DB text
				if ( strcasecmp( $db_text_normalized, $normalized_text ) === 0 ) {
					return $row->translated_text;
				}
				
				// Check if our text is at the start of the DB text (fragment before inline tag)
				// e.g., "To truly master WordPress in 2025, focus on" matches "To truly master WordPress in 2025, focus on as it gives..."
				$search_lower = strtolower( trim( $normalized_text ) );
				$db_lower = strtolower( $db_text_normalized );
				if ( strpos( $db_lower, $search_lower ) === 0 && strlen( $search_lower ) > 20 ) {
					// Calculate what percentage of the source this fragment represents
					// and extract approximately the same portion from the translation
					$fragment_ratio = strlen( $search_lower ) / strlen( $db_lower );
					$translated_words = explode( ' ', $row->translated_text );
					$fragment_word_count = (int) ( count( $translated_words ) * $fragment_ratio );
					$translated_fragment = implode( ' ', array_slice( $translated_words, 0, $fragment_word_count ) );
					
					return $translated_fragment;
				}
				
				// Check if our text is at the end of the DB text (fragment after inline tag)
				// e.g., "as it gives full control..." matches "...focus on as it gives full control..."
				$search_len = strlen( $search_lower );
				$db_len = strlen( $db_lower );
				if ( $search_len > 20 && $db_len > $search_len && substr( $db_lower, -$search_len ) === $search_lower ) {
					// Calculate what percentage this fragment represents and extract from end
					$fragment_ratio = $search_len / $db_len;
					$translated_words = explode( ' ', $row->translated_text );
					$fragment_word_count = (int) ( count( $translated_words ) * $fragment_ratio );
					$translated_fragment = implode( ' ', array_slice( $translated_words, -$fragment_word_count ) );
					
					return $translated_fragment;
				}
			}
		}

		return false;
	}

	/**
	 * Apply HTML structure from source to translated text
	 */
private function applyHtmlStructure( $original_content, $source_with_html, $translated_text ) {
	// If translated text already has HTML, return as is
	if ( $translated_text !== wp_strip_all_tags( $translated_text ) ) {
		return $translated_text;
	}
	
	// Extract the HTML structure from the original content
	// and apply it to the translated text
	$pattern = '/<[^>]+>/';
	$tags = array();
	preg_match_all( $pattern, $original_content, $tags );
	
	if ( ! empty( $tags[0] ) ) {
		// Split translated text into words
		$translated_words = preg_split( '/\s+/', $translated_text );
		$original_words = preg_split( '/\s+/', wp_strip_all_tags( $original_content ) );
			
			// Try to reconstruct with tags
			// This is a simple approach - may need refinement
			$result = $original_content;
			foreach ( $original_words as $i => $word ) {
				if ( isset( $translated_words[$i] ) ) {
					$result = str_replace( $word, $translated_words[$i], $result );
				}
			}
			
			return $result;
		}
		
		return $translated_text;
	}

	/**
	 * Start safe translation (output buffering)
	 */
	public function startSafeTranslation() {
		$current_language = $this->getCurrentLanguage();

		// Only for non-English languages
		if ( $current_language === 'en' ) {
			return;
		}

		// Start output buffering to capture final HTML
		ob_start();
	}

	/**
	 * End safe translation and apply text-only translation
	 */
	public function endSafeTranslation() {
		$current_language = $this->getCurrentLanguage();

		// Only for non-English languages
		if ( $current_language === 'en' ) {
			return;
		}

		$content = ob_get_clean();

		if ( empty( $content ) ) {
			return;
		}

		// Apply ultra-safe text-only translation
		$translated_content = $this->applySafeTextTranslation( $content, $current_language );

		// Output safely - content is already processed HTML from translation
		echo wp_kses_post( $translated_content );
	}

	/**
	 * Apply safe text-only translation without breaking structure
	 */
	private function applySafeTextTranslation( $content, $language ) {
		// Use the comprehensive translation method that handles complex HTML
		return $this->translateTextOnlyInHtml( $content, $language );
	}
	
	/**
	 * OLD METHOD - kept for reference
	 * Apply safe text-only translation without breaking structure
	 */
	private function applySafeTextTranslation_OLD( $content, $language ) {
		// Only translate specific text patterns that we know are safe
		// Look for common patterns like headings, buttons, etc.

		// Translate heading text
		$content = preg_replace_callback(
			'/<h[1-6][^>]*>([^<]+)<\/h[1-6]>/i',
			function ( $matches ) use ( $language ) {
				$text = trim( $matches[1] );
				if ( strlen( $text ) > 3 ) {
					$translated = $this->getTranslationFromDatabase( $text, $language, 'content' );
					if ( ! $translated ) {
						$translated = $this->getTranslationFromDatabase( $text, $language, 'post_content' );
					}
					if ( $translated ) {
						return str_replace( $matches[1], $translated, $matches[0] );
					}
				}
				return $matches[0];
			},
			$content
		);

		// Translate button text
		$content = preg_replace_callback(
			'/<a[^>]*class="[^"]*elementor-button[^"]*"[^>]*>([^<]+)<\/a>/i',
			function ( $matches ) use ( $language ) {
				$text = trim( $matches[1] );
				if ( strlen( $text ) > 2 ) {
					$translated = $this->getTranslationFromDatabase( $text, $language, 'content' );
					if ( $translated ) {
						return str_replace( $matches[1], $translated, $matches[0] );
					}
				}
				return $matches[0];
			},
			$content
		);

		// Translate paragraph text (very conservative)
		$content = preg_replace_callback(
			'/<p[^>]*>([^<]+)<\/p>/i',
			function ( $matches ) use ( $language ) {
				$text = trim( $matches[1] );
				if ( strlen( $text ) > 5 && ! is_numeric( $text ) ) {
					$translated = $this->getTranslationFromDatabase( $text, $language, 'content' );
					if ( $translated ) {
						return str_replace( $matches[1], $translated, $matches[0] );
					}
				}
				return $matches[0];
			},
			$content
		);

		return $content;
	}

	/**
	 * Reconstruct translated text with inline HTML tags from original content
	 */
	private function reconstructWithInlineTags( $translated_text, $original_html, $inline_tags_map = array() ) {
		// If we have the inline tags map from translateCompleteBlocks, use it
		if ( ! empty( $inline_tags_map ) ) {
			// The inline tags are already extracted, just insert them back
			// For now, return the original HTML with translated text would require
			// more complex logic to determine tag positions
			// As a simpler approach: just return the original HTML since translation
			// without inline tags doesn't make semantic sense
			return $original_html;
		}
		
		// Extract all inline tags and their text content from original
		$inline_elements = array();
		preg_match_all( '/<(a|strong|b|em|i|span|code|mark|u|sub|sup|small)([^>]*)>(.*?)<\/\1>/is', $original_html, $matches, PREG_SET_ORDER );
		
		// Store inline elements with their text content
	foreach ( $matches as $match ) {
		$full_tag = $match[0];
		$tag_name = $match[1];
		$attributes = $match[2];
		$inner_text = wp_strip_all_tags( $match[3] ); // Get text inside the tag
		
		$inline_elements[] = array(
			'full_tag' => $full_tag,
			'tag_name' => $tag_name,
			'attributes' => $attributes,
				'inner_text' => trim( $inner_text ),
			);
		}
		
		if ( empty( $inline_elements ) ) {
			return $translated_text;
		}
		
		// Start with the translated text
		$result = $translated_text;
		
		// For each inline element, try to find where it should be placed
		// by looking for translated version of its inner text
		foreach ( $inline_elements as $element ) {
			$original_inner = $element['inner_text'];
			
			// Try to find translation of the inner text
			$translated_inner = $this->getTranslationFromDatabase( $original_inner, $this->getCurrentLanguage(), 'text_fragment' );
			
			if ( ! $translated_inner ) {
				// If no translation, keep original text
				$translated_inner = $original_inner;
			}
			
			// Look for the translated inner text in the result and wrap it with the tag
			if ( ! empty( $translated_inner ) && strpos( $result, $translated_inner ) !== false ) {
				// Reconstruct the tag with translated content
				$new_tag = '<' . $element['tag_name'] . $element['attributes'] . '>' . $translated_inner . '</' . $element['tag_name'] . '>';
				$result = str_replace( $translated_inner, $new_tag, $result );
			}
		}
		
		return $result;
	}

	/**
	 * Get current language
	 */
	private function getCurrentLanguage() {
		if ( ! $this->current_language ) {
			$this->current_language = $this->plugin->getCurrentLanguage();
		}

		return $this->current_language;
	}
}
