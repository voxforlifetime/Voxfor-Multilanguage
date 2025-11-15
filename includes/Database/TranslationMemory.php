<?php
namespace VoxforML\Database;

/**
 * Handles translation memory storage and retrieval
 */
class TranslationMemory {
	private $wpdb;
	private $table_translations;
	private $table_queue;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb               = $wpdb;
		$this->table_translations = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translations';
		$this->table_queue        = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translation_queue';
	}

	/**
	 * Get translation from memory with enhanced context and post_id support
	 */
	public function getTranslation( $source_text, $language, $context = 'general', $post_id = null ) {
		// Skip empty or very short text
		if ( empty( $source_text ) || strlen( trim( $source_text ) ) < 2 ) {
			return false;
		}

		// Normalize source text to match what we save
		$normalized_source = html_entity_decode( $source_text, ENT_QUOTES, 'UTF-8' );
		$normalized_source = trim( preg_replace( '/\s+/', ' ', $normalized_source ) );

		$source_hash = $this->hashText( $normalized_source, $context, $post_id, false );

		// UUID-based translation lookup

		// Skip cache for now to ensure UUID-based translations work correctly
		// The cache will be rebuilt with proper UUID-based keys
		// TODO: Implement proper cache lookup with UUID awareness

		// 🔍 ENHANCED MATCHING: Try multiple approaches to find translation

		// 1. Try exact hash match first (for legacy text-based hashes)
		// Try with context
		if ( $context && $context !== 'general' ) {
			$sql_with_context = "SELECT translated_text FROM {$this->table_translations} 
                                WHERE source_hash = %s AND language_code = %s AND context = %s ORDER BY updated_at DESC LIMIT 1";
			$translation      = $this->wpdb->get_var( $this->wpdb->prepare( $sql_with_context, $source_hash, $language, $context ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $translation !== null ) {
				// Cache and return
				if ( get_option( 'voxfor_ml_enable_object_cache', true ) ) {
					$cache_key = 'voxfor_ml_trans_' . $source_hash . '_' . $language . '_' . $context . '_' . ( $post_id ?: 'global' );
					wp_cache_set( $cache_key, $translation, 'voxfor_ml_translations', 3600 );
				}
				return $translation;
			}
		}

		// 2. Try without context (fallback)
		$sql_fallback = "SELECT translated_text FROM {$this->table_translations} 
                        WHERE source_hash = %s AND language_code = %s ORDER BY updated_at DESC LIMIT 1";
		$translation = $this->wpdb->get_var( $this->wpdb->prepare( $sql_fallback, $source_hash, $language ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $translation !== null ) {
			// Cache and return
			if ( get_option( 'voxfor_ml_enable_object_cache', true ) ) {
				$cache_key = 'voxfor_ml_trans_' . $source_hash . '_' . $language . '_' . $context . '_' . ( $post_id ?: 'global' );
				wp_cache_set( $cache_key, $translation, 'voxfor_ml_translations', 3600 );
			}
			return $translation;
		}

		// 3. 🚀 NEW: Search by source_text for UUID-based translations
		$text_sql = "SELECT translated_text FROM {$this->table_translations} 
                     WHERE source_text = %s AND language_code = %s";

		// Try with context and post_id first (most specific)
		if ( $context && $context !== 'general' && $post_id ) {
			$query = "SELECT source_hash, translated_text FROM {$this->table_translations} 
                 WHERE source_text = %s AND language_code = %s AND context = %s AND post_id = %d 
                 ORDER BY updated_at DESC LIMIT 1";
			$result = $this->wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$normalized_source, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$language, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$context, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$post_id // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				)
			);
			if ( $result && $result->translated_text ) {
				if ( get_option( 'voxfor_ml_enable_object_cache', true ) ) {
					// Use the actual UUID hash from database for unique cache key
					$unique_cache_key = 'voxfor_ml_trans_' . $result->source_hash . '_' . $language . '_' . $context . '_' . $post_id;
					wp_cache_set( $unique_cache_key, $result->translated_text, 'voxfor_ml_translations', 3600 );
				}
				return $result->translated_text;
			}
		}

		// Try with context only
		if ( $context && $context !== 'general' ) {
			$query = "SELECT source_hash, translated_text FROM {$this->table_translations} 
                 WHERE source_text = %s AND language_code = %s AND context = %s 
                 ORDER BY updated_at DESC LIMIT 1";
			$result = $this->wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$normalized_source, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$language, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$context // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				)
			);
					if ( $result && $result->translated_text ) {
							// Decode HTML entities if they exist as literal text - DIRECT METHOD
		$normalized_text = preg_replace( '/&(?:amp|#0*38);#/i', '&#', $result->translated_text );
		$decoded_translation = preg_replace_callback( '/&#(x?[0-9A-Fa-f]+);?/i', function( $matches ) {
			$val = $matches[1];
			$code = ( $val[0] === 'x' || $val[0] === 'X' ) ? hexdec( substr( $val, 1 ) ) : intval( $val, 10 );
			if ( $code < 0x80 ) {
				return chr( $code );
			} elseif ( $code < 0x800 ) {
				return chr( 0xC0 | ( $code >> 6 ) ) . chr( 0x80 | ( $code & 0x3F ) );
			} elseif ( $code < 0x10000 ) {
				return chr( 0xE0 | ( $code >> 12 ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
			}
			return chr( 0xF0 | ( $code >> 18 ) ) . chr( 0x80 | ( ( $code >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
		}, $normalized_text );
		
		if ( get_option( 'voxfor_ml_enable_object_cache', true ) ) {
			// Cache the decoded version
			$unique_cache_key = 'voxfor_ml_trans_' . $result->source_hash . '_' . $language . '_' . $context . '_' . ( $post_id ?: 'global' );
			wp_cache_set( $unique_cache_key, $decoded_translation, 'voxfor_ml_translations', 3600 );
		}
		
		return $decoded_translation;
		}
		}

		// Try with post_id only
		if ( $post_id ) {
			$query = "SELECT source_hash, translated_text FROM {$this->table_translations} 
                 WHERE source_text = %s AND language_code = %s AND post_id = %d 
                 ORDER BY updated_at DESC LIMIT 1";
			$prepared_query = $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$normalized_source,
				$language,
				$post_id
			);
			$result = $this->wpdb->get_row( $prepared_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $result && $result->translated_text ) {
				if ( get_option( 'voxfor_ml_enable_object_cache', true ) ) {
					// Use the actual UUID hash from database for unique cache key
					$unique_cache_key = 'voxfor_ml_trans_' . $result->source_hash . '_' . $language . '_' . $context . '_' . $post_id;
					wp_cache_set( $unique_cache_key, $result->translated_text, 'voxfor_ml_translations', 3600 );
				}
				return $result->translated_text;
			}
		}

		// Final fallback: just source_text and language - use round-robin for multiple UUIDs
		// Get all available translations for this text
		$fallback_query = "SELECT source_hash, translated_text FROM {$this->table_translations} 
             WHERE source_text = %s AND language_code = %s 
             ORDER BY created_at ASC";
		$prepared_fallback = $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$fallback_query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$normalized_source,
			$language
		);
		$all_results = $this->wpdb->get_results( $prepared_fallback ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $all_results && count( $all_results ) > 0 ) {
			// Use a simple round-robin based on current time microseconds
			// This ensures different UUID translations are returned for identical text
			$index  = (int) ( microtime( true ) * 1000000 ) % count( $all_results );
			$result = $all_results[ $index ];

							// Decode HTML entities if they exist as literal text - DIRECT METHOD
		$normalized_text = preg_replace( '/&(?:amp|#0*38);#/i', '&#', $result->translated_text );
		$decoded_translation = preg_replace_callback( '/&#(x?[0-9A-Fa-f]+);?/i', function( $matches ) {
			$val = $matches[1];
			$code = ( $val[0] === 'x' || $val[0] === 'X' ) ? hexdec( substr( $val, 1 ) ) : intval( $val, 10 );
			if ( $code < 0x80 ) {
				return chr( $code );
			} elseif ( $code < 0x800 ) {
				return chr( 0xC0 | ( $code >> 6 ) ) . chr( 0x80 | ( $code & 0x3F ) );
			} elseif ( $code < 0x10000 ) {
				return chr( 0xE0 | ( $code >> 12 ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
			}
			return chr( 0xF0 | ( $code >> 18 ) ) . chr( 0x80 | ( ( $code >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
		}, $normalized_text );
		
		
		if ( get_option( 'voxfor_ml_enable_object_cache', true ) ) {
			// Cache the decoded version
			$unique_cache_key = 'voxfor_ml_trans_' . $result->source_hash . '_' . $language . '_' . $context . '_' . ( $post_id ?: 'global' );
			wp_cache_set( $unique_cache_key, $decoded_translation, 'voxfor_ml_translations', 3600 );
		}
		
		return $decoded_translation;
		}

		// 3. 🚀 FUZZY MATCHING: Try to find similar content (for footer content)
		if ( strlen( $source_text ) > 100 ) { // Only for larger content blocks like footer
			$fuzzy_sql = "SELECT translated_text, source_text FROM {$this->table_translations} 
                         WHERE language_code = %s 
                         AND LENGTH(source_text) > 100 
                         AND (source_text LIKE %s OR %s LIKE CONCAT('%', SUBSTRING(source_text, 1, 50), '%'))
                         ORDER BY LENGTH(source_text) DESC LIMIT 1";

			$like_pattern = '%' . $this->wpdb->esc_like( substr( $normalized_source, 0, 50 ) ) . '%';
			$fuzzy_params = array( $language, $like_pattern, $normalized_source );
			$prepared_fuzzy = $this->wpdb->prepare( $fuzzy_sql, ...$fuzzy_params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$fuzzy_result = $this->wpdb->get_row( $prepared_fuzzy ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( $fuzzy_result && $fuzzy_result->translated_text ) {
				// Cache fuzzy match
				if ( get_option( 'voxfor_ml_enable_object_cache', true ) ) {
					$cache_key = 'voxfor_ml_trans_' . $source_hash . '_' . $language . '_' . $context . '_' . ( $post_id ?: 'global' );
					wp_cache_set( $cache_key, $fuzzy_result->translated_text, 'voxfor_ml_translations', 3600 );
				}
				return $fuzzy_result->translated_text;
			}
		}

		// Enhanced database query with post_id prioritization (original logic)
		$sql    = "SELECT translated_text FROM {$this->table_translations} 
                WHERE source_hash = %s AND language_code = %s";
		$params = array( $source_hash, $language );

		// If we have a post_id, prioritize translations for this specific post
		if ( $post_id ) {
			$sql     .= ' AND (post_id = %d OR post_id IS NULL)';
			$params[] = $post_id;
			$sql     .= " ORDER BY 
                        CASE WHEN post_id = %d THEN 1 ELSE 2 END,
                        CASE WHEN context = %s THEN 1 ELSE 2 END,
                        CASE WHEN provider = 'manual' THEN 1 ELSE 2 END,
                        updated_at DESC";
			$params[] = $post_id;
			$params[] = $context;
		} else {
			$sql     .= ' AND context = %s';
			$params[] = $context;
			$sql     .= " ORDER BY 
                        CASE WHEN provider = 'manual' THEN 1 ELSE 2 END,
                        updated_at DESC";
		}

		$sql .= ' LIMIT 1';

		$prepared_sql = $this->wpdb->prepare( $sql, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$translation = $this->wpdb->get_var( $prepared_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $translation !== null ) {
			// Decode entities to UTF-8 before caching/returning
			$normalized_text = preg_replace( '/&(?:amp|#0*38);#/i', '&#', $translation );
			$decoded_translation = preg_replace_callback( '/&#(x?[0-9A-Fa-f]+);?/i', function( $matches ) {
				$val = $matches[1];
				$code = ( $val[0] === 'x' || $val[0] === 'X' ) ? hexdec( substr( $val, 1 ) ) : intval( $val, 10 );
				if ( $code < 0x80 ) {
					return chr( $code );
				} elseif ( $code < 0x800 ) {
					return chr( 0xC0 | ( $code >> 6 ) ) . chr( 0x80 | ( $code & 0x3F ) );
				} elseif ( $code < 0x10000 ) {
					return chr( 0xE0 | ( $code >> 12 ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
				}
				return chr( 0xF0 | ( $code >> 18 ) ) . chr( 0x80 | ( ( $code >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
			}, $normalized_text );
			
			// Store in object cache if enabled
			if ( get_option( 'voxfor_ml_enable_object_cache', true ) ) {
				$cache_ttl = get_option( 'voxfor_ml_cache_ttl', 3600 );
				$cache_key = 'voxfor_ml_trans_' . $source_hash . '_' . $language . '_' . $context . '_' . ( $post_id ?: 'global' );
				wp_cache_set( $cache_key, $decoded_translation, 'voxfor_ml_translations', $cache_ttl );
			}
			return $decoded_translation;
		}

		// Cache negative results to avoid repeated database queries
		if ( get_option( 'voxfor_ml_enable_object_cache', true ) ) {
			$cache_ttl = get_option( 'voxfor_ml_cache_ttl', 3600 );
			$cache_key = 'voxfor_ml_trans_' . $source_hash . '_' . $language . '_' . $context . '_' . ( $post_id ?: 'global' );
			wp_cache_set( $cache_key, false, 'voxfor_ml_translations', $cache_ttl );
		}

		// Skip fuzzy matching for performance - only use exact matches
		return false;
	}

	/**
	 * Get multiple translations in batch for better performance
	 */
	public function getBatchTranslations( $texts, $language, $context = 'general', $post_id = null ) {
		if ( empty( $texts ) || ! is_array( $texts ) ) {
			return array();
		}

		$results        = array();
		$cache_keys     = array();
		$missing_hashes = array();
		$hash_to_text   = array();

		// Check cache first for all texts
		if ( get_option( 'voxfor_ml_enable_object_cache', true ) ) {
			foreach ( $texts as $index => $text ) {
				if ( empty( $text ) || strlen( trim( $text ) ) < 2 ) {
					$results[ $index ] = false;
					continue;
				}

				$normalized = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
				$normalized = trim( preg_replace( '/\s+/', ' ', $normalized ) );
				$hash       = $this->hashText( $normalized, $context, $post_id, false ); // Use text-based hash for lookup

				$cache_key = 'voxfor_ml_trans_' . $hash . '_' . $language . '_' . $context . '_' . ( $post_id ?: 'global' );
				$cached    = wp_cache_get( $cache_key, 'voxfor_ml_translations' );

				if ( $cached !== false ) {
					$results[ $index ] = $cached;
				} else {
					$missing_hashes[]      = $hash;
					$hash_to_text[ $hash ] = array(
						'index' => $index,
						'text'  => $normalized,
					);
					$cache_keys[ $hash ]   = $cache_key;
				}
			}
		} else {
			// No cache, prepare all for database query
			foreach ( $texts as $index => $text ) {
				if ( empty( $text ) || strlen( trim( $text ) ) < 2 ) {
					$results[ $index ] = false;
					continue;
				}

				$normalized = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
				$normalized = trim( preg_replace( '/\s+/', ' ', $normalized ) );
				$hash       = $this->hashText( $normalized, $context, $post_id, false ); // Use text-based hash for lookup

				$missing_hashes[]      = $hash;
				$hash_to_text[ $hash ] = array(
					'index' => $index,
					'text'  => $normalized,
				);
			}
		}

		// Query database for missing translations
		if ( ! empty( $missing_hashes ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $missing_hashes ), '%s' ) );
			$sql          = "SELECT source_hash, translated_text FROM {$this->table_translations} 
                    WHERE source_hash IN ($placeholders) AND language_code = %s";
			$params       = array_merge( $missing_hashes, array( $language ) );

			if ( $post_id ) {
				$sql     .= ' AND (post_id = %d OR post_id IS NULL)';
				$params[] = $post_id;
				$sql     .= ' ORDER BY CASE WHEN post_id = %d THEN 1 ELSE 2 END';
				$params[] = $post_id;
			}

			$batch_params = $params;
			$prepared_batch_sql = $this->wpdb->prepare( $sql, ...$batch_params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$translations = $this->wpdb->get_results( $prepared_batch_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			// Process results
			$found_hashes = array();
			foreach ( $translations as $row ) {
				$hash = $row['source_hash'];
				if ( isset( $hash_to_text[ $hash ] ) ) {
					$index             = $hash_to_text[ $hash ]['index'];
					$results[ $index ] = $row['translated_text'];
					$found_hashes[]    = $hash;

					// Cache the result
					if ( get_option( 'voxfor_ml_enable_object_cache', true ) && isset( $cache_keys[ $hash ] ) ) {
						$cache_ttl = get_option( 'voxfor_ml_cache_ttl', 3600 );
						wp_cache_set( $cache_keys[ $hash ], $row['translated_text'], 'voxfor_ml_translations', $cache_ttl );
					}
				}
			}

			// Set false for missing translations and cache negative results
			foreach ( $missing_hashes as $hash ) {
				if ( ! in_array( $hash, $found_hashes ) && isset( $hash_to_text[ $hash ] ) ) {
					$index             = $hash_to_text[ $hash ]['index'];
					$results[ $index ] = false;

					// Cache negative result
					if ( get_option( 'voxfor_ml_enable_object_cache', true ) && isset( $cache_keys[ $hash ] ) ) {
						$cache_ttl = get_option( 'voxfor_ml_cache_ttl', 3600 );
						wp_cache_set( $cache_keys[ $hash ], false, 'voxfor_ml_translations', $cache_ttl );
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Try fuzzy matching for similar text with context and post_id awareness
	 */
	private function tryFuzzyMatch( $source_text, $language, $context, $post_id = null ) {
		// Normalize the text for comparison
		$normalized_source = $this->normalizeText( $source_text );

		// Get original text length for comparison
		$source_length = strlen( $source_text );

		// Build query with context and post_id awareness
		$sql    = "SELECT source_text, translated_text FROM {$this->table_translations} 
                WHERE language_code = %s 
                AND LENGTH(source_text) BETWEEN %d AND %d";
		$params = array( $language, $source_length - 20, $source_length + 20 );

		// If we have a post_id, prioritize matches from the same post
		if ( $post_id ) {
			$sql     .= ' ORDER BY 
                        CASE WHEN post_id = %d THEN 1 ELSE 2 END,
                        CASE WHEN context = %s THEN 1 ELSE 2 END,
                        ABS(LENGTH(source_text) - %d) ASC';
			$params[] = $post_id;
			$params[] = $context;
			$params[] = $source_length;
		} else {
			$sql     .= ' AND context = %s ORDER BY ABS(LENGTH(source_text) - %d) ASC';
			$params[] = $context;
			$params[] = $source_length;
		}

		$sql .= ' LIMIT 5';

		$fuzzy_params = $params;
		$prepared_fuzzy_sql = $this->wpdb->prepare( $sql, ...$fuzzy_params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$similar_translations = $this->wpdb->get_results( $prepared_fuzzy_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $similar_translations ) {
			foreach ( $similar_translations as $row ) {
				$normalized_stored = $this->normalizeText( $row->source_text );
				$stored_length     = strlen( $row->source_text );

				// Calculate text similarity
				$text_similarity = $this->textSimilarity( $normalized_source, $normalized_stored );

				// Calculate length similarity (must be very close)
				$length_diff       = abs( $source_length - $stored_length ) / max( $source_length, $stored_length );
				$length_similarity = 1 - $length_diff;

				// Much stricter requirements: 95% text similarity AND 90% length similarity
				if ( $text_similarity > 0.95 && $length_similarity > 0.90 ) {

					return $row->translated_text;
				} else {

				}
			}
		}

		return false;
	}

	/**
	 * Normalize text for comparison
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
	 * Calculate text similarity (simple)
	 */
	private function textSimilarity( $text1, $text2 ) {
		$shorter = strlen( $text1 ) < strlen( $text2 ) ? $text1 : $text2;
		$longer  = strlen( $text1 ) >= strlen( $text2 ) ? $text1 : $text2;

		if ( strlen( $longer ) == 0 ) {
			return 1.0;
		}

		return ( strlen( $longer ) - levenshtein( $longer, $shorter ) ) / strlen( $longer );
	}

	/**
	 * Save translation to memory - UUID approach (always creates new entries)
	 */
	public function saveTranslation( $source_text, $translated_text, $language, $context = 'general', $post_id = null, $provider = 'deepl' ) {
		// DEBUG: Log what we're saving

		// Normalize source text to handle HTML entities and whitespace
		$normalized_source = html_entity_decode( $source_text, ENT_QUOTES, 'UTF-8' );
		$normalized_source = trim( preg_replace( '/\s+/', ' ', $normalized_source ) );

		// UNIVERSAL DUPLICATE CLEANUP: Clean any duplicate patterns before saving (recursive)
		$normalized_source = $this->cleanAllDuplicatesRecursive( $normalized_source );


		// Always generate unique hash for new translations to prevent conflicts
		$source_hash = $this->hashText( $normalized_source, $context, $post_id, true );


		// Decode HTML entities in translated text before storing - DIRECT METHOD
		$decoded_translation = preg_replace( '/&(?:amp|#0*38);#/i', '&#', $translated_text );
		$decoded_translation = preg_replace_callback( '/&#(x?[0-9A-Fa-f]+);?/i', function( $matches ) {
			$val = $matches[1];
			$code = ( $val[0] === 'x' || $val[0] === 'X' ) ? hexdec( substr( $val, 1 ) ) : intval( $val, 10 );
			if ( $code < 0x80 ) {
				return chr( $code );
			} elseif ( $code < 0x800 ) {
				return chr( 0xC0 | ( $code >> 6 ) ) . chr( 0x80 | ( $code & 0x3F ) );
			} elseif ( $code < 0x10000 ) {
				return chr( 0xE0 | ( $code >> 12 ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
			} else {
				return chr( 0xF0 | ( $code >> 18 ) ) . chr( 0x80 | ( ( $code >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
			}
		}, $decoded_translation );
		
		// Always insert new record - no duplicate checking with UUID approach
		$data = array(
			'source_hash'     => $source_hash,
			'source_text'     => $normalized_source,
			'translated_text' => $decoded_translation, // Store decoded version
			'language_code'   => $language,
			'context'         => $context,
			'provider'        => $provider,
			'post_id'         => $post_id ?: null,
		);

		$result = $this->wpdb->insert( $this->table_translations, $data );

		if ( $result !== false ) {
			// Clear cache
			$this->clearCache( $source_hash, $language, $context );
			
			// Auto-generate slug if this is a title translation
			if ( $context === 'title' && $post_id ) {
				$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
				if ( $slug_manager ) {
					$slug_manager->autoGenerateSlug( $post_id, $language, $decoded_translation );
				}
			}
			
			return true;
		}

		return false;
	}


	/**
	 * Queue text for translation with enhanced context support
	 */
	public function queueForTranslation( $source_text, $language, $context = 'general', $post_id = null ) {
		// Check if queuing is disabled (e.g., during AJAX operations)
		if ( apply_filters( 'voxfor_ml_disable_auto_queue', false ) || defined( 'VOXFOR_ML_AJAX_TRANSLATING' ) ) {

			return true;
		}

		// Validate parameters to prevent database errors
		if ( empty( $language ) || ! is_string( $language ) ) {

			return false;
		}

		if ( empty( $source_text ) || ! is_string( $source_text ) ) {

			return false;
		}

		// Normalize and hash consistently to avoid duplicate-hash mismatches
		$normalized_source = html_entity_decode( $source_text, ENT_QUOTES, 'UTF-8' );
		$normalized_source = trim( preg_replace( '/\s+/', ' ', $normalized_source ) );
		$source_hash       = $this->hashText( $normalized_source, $context, $post_id, false );

		// Idempotent insert: avoid race conditions by using ON DUPLICATE KEY UPDATE
		// unique_queue_item is assumed over (source_hash, language_code, context)
		$queue_query = "INSERT INTO {$this->table_queue} (source_hash, source_text, language_code, context, status, post_id)
             VALUES (%s, %s, %s, %s, 'pending', %s)
             ON DUPLICATE KEY UPDATE status = status";
		$queue_params = array( $source_hash, $source_text, $language, $context, $post_id );
		$prepared_queue_sql = $this->wpdb->prepare( $queue_query, ...$queue_params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$result = $this->wpdb->query( $prepared_queue_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $result === false ) {

			return false;
		}
		return true;
	}

	/**
	 * Get queued translations
	 */
	public function getQueuedTranslations( $limit = 10 ) {
		$queue_select_query = "SELECT * FROM {$this->table_queue} 
             WHERE status = 'pending' 
             ORDER BY created_at ASC 
             LIMIT %d";
		$prepared_queue_select = $this->wpdb->prepare( $queue_select_query, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $prepared_queue_select ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get ALL pending translations for admin review
	 */
	public function getAllPendingTranslations() {
		return $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT id, source_text, language_code, context, post_id, created_at, attempts FROM `%1s` WHERE status = 'pending' ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$this->table_queue // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Cancel pending translation (prevent API usage)
	 */
	public function cancelPendingTranslation( $queue_id ) {
		return $this->wpdb->update(
			$this->table_queue,
			array(
				'status'        => 'cancelled',
				'processed_at'  => current_time( 'mysql' ),
				'error_message' => 'Cancelled by admin',
			),
			array( 'id' => $queue_id )
		) !== false;
	}

	/**
	 * Cancel multiple pending translations
	 */
	public function cancelMultiplePendingTranslations( $queue_ids ) {
		if ( empty( $queue_ids ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $queue_ids ), '%d' ) );

		$cancel_query = "UPDATE {$this->table_queue} 
             SET status = 'cancelled', 
                 processed_at = %s, 
                 error_message = 'Cancelled by admin'
             WHERE id IN ($placeholders) 
             AND status = 'pending'";
		$cancel_params = array_merge( array( current_time( 'mysql' ) ), $queue_ids );
		$prepared_cancel_sql = $this->wpdb->prepare( $cancel_query, ...$cancel_params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $this->wpdb->query( $prepared_cancel_sql ) !== false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Migrate completed queue items to main translations table
	 * This fixes the issue where translations stay in queue instead of moving to memory
	 */
	public function migrateQueueToMemory() {
		// Get all pending queue items that might actually be completed
		$queue_items = $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT * FROM `%1s` WHERE status = 'pending' ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$this->table_queue // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$migrated = 0;

		foreach ( $queue_items as $item ) {
			// Check if this text was already translated and just stuck in queue
			// We'll assume if it's in queue for more than 5 minutes, it needs migration
			$created_time = strtotime( $item->created_at );
			$current_time = time();

			if ( ( $current_time - $created_time ) > 300 ) { // 5 minutes
				// Try to get the translation again or mark as failed
				$existing_translation = $this->getTranslation( $item->source_text, $item->language_code, $item->context );

				if ( $existing_translation ) {
					// Translation exists in memory, mark queue item as completed
					$this->markQueueItemProcessed( $item->id, 'completed' );
					++$migrated;
				} else {
					// No translation found, this is truly pending
					continue;
				}
			}
		}

		return $migrated;
	}

	/**
	 * Mark queue item as processed
	 */
	public function markQueueItemProcessed( $id, $status = 'completed', $error_message = null ) {
		$data = array(
			'status'       => $status,
			'processed_at' => current_time( 'mysql' ),
		);

		if ( $error_message ) {
			$data['error_message'] = $error_message;
		}

		return $this->wpdb->update(
			$this->table_queue,
			$data,
			array( 'id' => $id )
		) !== false;
	}

	/**
	 * Clean up translations with invalid language codes
	 */
	public function cleanupInvalidLanguageCodes() {
		// Get count of invalid language codes
		$invalid_count = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT COUNT(*) FROM `%1s` WHERE language_code IS NULL OR language_code = '' OR language_code = ' '", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$this->table_translations // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( $invalid_count > 0 ) {
			// Delete translations with invalid language codes
			$deleted = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					"DELETE FROM `%1s` WHERE language_code IS NULL OR language_code = '' OR language_code = ' '", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
					$this->table_translations // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);

			return array(
				'found'   => $invalid_count,
				'deleted' => $deleted,
			);
		}

		return array(
			'found'   => 0,
			'deleted' => 0,
		);
	}

	/**
	 * Lock translation (prevent automatic updates)
	 */
	public function lockTranslation( $source_text, $language, $context = 'general', $post_id = null ) {
		$source_hash = $this->hashText( $source_text, $context, $post_id );

		return $this->wpdb->update(
			$this->table_translations,
			array( 'is_locked' => 1 ),
			array(
				'source_hash'   => $source_hash,
				'language_code' => $language,
				'context'       => $context,
			)
		) !== false;
	}

	/**
	 * Get translations for a post
	 */
	public function getPostTranslations( $post_id, $language = null ) {
		// Use %i placeholder for table name (WP 6.2+ best practice)
		$query  = "SELECT * FROM %i WHERE post_id = %d";
		$params = array( $this->table_translations, $post_id );

		if ( $language ) {
			$query   .= ' AND language_code = %s';
			$params[] = $language;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $this->wpdb->prepare( $query, ...$params ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Check if a post has translations for a specific language
	 */
	public function hasPostTranslations( $post_id, $language ) {
		// Check what type of post this is
		$post_type = get_post_type( $post_id );

		if ( $post_type === 'product' ) {
			// For WooCommerce products, check product-specific contexts (use %i for table name)
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$title_translation = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE post_id = %d AND language_code = %s AND context IN ('product_name', 'title', 'post_title')",
					$this->table_translations,
					$post_id,
					$language
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$content_translation = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE post_id = %d AND language_code = %s AND context IN ('product_description', 'product_short_description', 'content', 'post_content')",
					$this->table_translations,
					$post_id,
					$language
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		} else {
			// For regular posts/pages, use standard contexts (use %i for table name)
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$title_translation = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE post_id = %d AND language_code = %s AND context IN ('post_title', 'title')",
					$this->table_translations,
					$post_id,
					$language
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$content_translation = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE post_id = %d AND language_code = %s AND context IN ('post_content', 'content')",
					$this->table_translations,
					$post_id,
					$language
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		}

		return array(
			'title'    => $title_translation > 0,
			'content'  => $content_translation > 0,
			'complete' => $title_translation > 0 && $content_translation > 0,
		);
	}

	/**
	 * Delete translations for a post
	 */
	public function deletePostTranslations( $post_id ) {
		return $this->wpdb->delete(
			$this->table_translations,
			array( 'post_id' => $post_id )
		);
	}

	/**
	 * Get translation statistics
	 */
	public function getStatistics() {
		$stats = array();

		// Total translations
		$stats['total_translations'] = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_translations}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// Translations by language (exclude empty language codes)
		$stats['by_language'] = $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT language_code, COUNT(*) as count FROM `%1s` WHERE language_code IS NOT NULL AND language_code != '' AND language_code != ' ' GROUP BY language_code ORDER BY count DESC", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$this->table_translations // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		// Translations by context
		$stats['by_context'] = $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT context, COUNT(*) as count FROM `%1s` GROUP BY context", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$this->table_translations // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		// Queue statistics
		$stats['queue'] = array(
			'pending'    => $this->wpdb->get_var(
				"SELECT COUNT(*) FROM {$this->table_queue} WHERE status = 'pending'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			),
			'processing' => $this->wpdb->get_var(
				"SELECT COUNT(*) FROM {$this->table_queue} WHERE status = 'processing'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			),
			'completed'  => $this->wpdb->get_var(
				"SELECT COUNT(*) FROM {$this->table_queue} WHERE status = 'completed'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			),
			'failed'     => $this->wpdb->get_var(
				"SELECT COUNT(*) FROM {$this->table_queue} WHERE status = 'failed'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			),
		);

		// Locked translations
		$stats['locked_translations'] = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_translations} WHERE is_locked = 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $stats;
	}

	/**
	 * Search translations
	 */
	public function searchTranslations( $search_term, $language = null, $context = null, $limit = 50, $offset = 0, $specific_item = null, $tab = 'all' ) {
		// Use %i placeholder for table name (WP 6.2+ best practice)
		$query  = "SELECT * FROM %i WHERE 1=1";
		$params = array( $this->table_translations ); // Table name as first parameter

		// Handle tab filtering
		if ( $tab === 'needs-review' ) {
			$query .= ' AND needs_review = 1';
		}

		if ( $search_term ) {
			$query      .= ' AND (source_text LIKE %s OR translated_text LIKE %s)';
			$search_like = '%' . $this->wpdb->esc_like( $search_term ) . '%';
			$params[]    = $search_like;
			$params[]    = $search_like;
		}

		if ( $language ) {
			$query   .= ' AND language_code = %s';
			$params[] = $language;
		}

		// Handle content type filtering
		if ( $context ) {
			$query .= $this->buildContextFilter( $context, $specific_item, $params );
		}

		$query   .= ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $this->wpdb->prepare( $query, ...$params ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Build context filter for content type filtering
	 */
	private function buildContextFilter( $context, $specific_item, &$params ) {
		$filter = '';

		switch ( $context ) {
			case 'page':
				if ( $specific_item && $specific_item !== 'all' ) {
					$filter   = ' AND post_id = %d';
					$params[] = intval( $specific_item );
				} else {
					// Filter for pages - get all page post IDs
					$page_ids = get_posts(
						array(
							'post_type'   => 'page',
							'post_status' => 'publish',
							'numberposts' => -1,
							'fields'      => 'ids',
						)
					);
					if ( ! empty( $page_ids ) ) {
						$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
						$filter       = " AND post_id IN ($placeholders)";
						$params       = array_merge( $params, $page_ids );
					}
				}
				break;

			case 'post':
				if ( $specific_item && $specific_item !== 'all' ) {
					$filter   = ' AND post_id = %d';
					$params[] = intval( $specific_item );
				} else {
					// Filter for posts - get all post post IDs
					$post_ids = get_posts(
						array(
							'post_type'   => 'post',
							'post_status' => 'publish',
							'numberposts' => -1,
							'fields'      => 'ids',
						)
					);
					if ( ! empty( $post_ids ) ) {
						$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
						$filter       = " AND post_id IN ($placeholders)";
						$params       = array_merge( $params, $post_ids );
					}
				}
				break;

			case 'product':
				if ( $specific_item && $specific_item !== 'all' ) {
					$filter   = ' AND post_id = %d';
					$params[] = intval( $specific_item );
				} else {
					// Filter for products - get all product post IDs
					$product_ids = get_posts(
						array(
							'post_type'   => 'product',
							'post_status' => 'publish',
							'numberposts' => -1,
							'fields'      => 'ids',
						)
					);
					if ( ! empty( $product_ids ) ) {
						$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
						$filter       = " AND post_id IN ($placeholders)";
						$params       = array_merge( $params, $product_ids );
					}
				}
				break;

			case 'elementor':
				if ( $specific_item && $specific_item !== 'all' ) {
					$filter   = ' AND post_id = %d';
					$params[] = intval( $specific_item );
				} else {
					// Filter for Elementor templates
					$template_ids = get_posts(
						array(
							'post_type'   => 'elementor_library',
							'post_status' => 'publish',
							'numberposts' => -1,
							'fields'      => 'ids',
						)
					);
					if ( ! empty( $template_ids ) ) {
						$placeholders = implode( ',', array_fill( 0, count( $template_ids ), '%d' ) );
						$filter       = " AND post_id IN ($placeholders)";
						$params       = array_merge( $params, $template_ids );
					}
				}
				break;

			case 'header':
			case 'footer':
				if ( $specific_item && $specific_item !== 'all' ) {
					// Filter for specific header/footer item by post_id
					$filter   = ' AND post_id = %d';
					$params[] = intval( $specific_item );
				} else {
					// Find all post_ids that contain header/footer content
					// Look for content with header/footer contexts OR common contexts that appear on header/footer
					$header_footer_ids = $this->wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
							"SELECT DISTINCT post_id FROM `%1s` WHERE (context LIKE %s OR context IN ('text_fragment', 'general', 'menu_item', 'widget_title', 'navigation')) AND post_id IS NOT NULL ORDER BY post_id", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
							$this->table_translations, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
							'%' . $context . '%' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					);

					if ( ! empty( $header_footer_ids ) ) {
						// Filter by the found post_ids that contain header/footer content
						$placeholders = implode( ',', array_fill( 0, count( $header_footer_ids ), '%d' ) );
						$filter       = " AND post_id IN ($placeholders)";
						$params       = array_merge( $params, $header_footer_ids );
					} else {
						// Fallback: if no post_ids found, use context-only filtering
						$filter   = " AND (context LIKE %s OR context IN ('text_fragment', 'general', 'menu_item', 'widget_title'))";
						$params[] = '%' . $context . '%';
					}
				}
				break;

			default:
				// Fallback to old context filtering
				$filter   = ' AND context = %s';
				$params[] = $context;
				break;
		}

		return $filter;
	}

	/**
	 * Get total count of translations matching search criteria
	 */
	public function getTranslationCount( $search_term = null, $language = null, $context = null, $specific_item = null, $tab = 'all' ) {
		// Use %i placeholder for table name (WP 6.2+ best practice)
		$query  = "SELECT COUNT(*) FROM %i WHERE 1=1";
		$params = array( $this->table_translations ); // Table name as first parameter

		// Handle tab filtering
		if ( $tab === 'needs-review' ) {
			$query .= ' AND needs_review = 1';
		}

		if ( $search_term ) {
			$query      .= ' AND (source_text LIKE %s OR translated_text LIKE %s)';
			$search_like = '%' . $this->wpdb->esc_like( $search_term ) . '%';
			$params[]    = $search_like;
			$params[]    = $search_like;
		}

		if ( $language ) {
			$query   .= ' AND language_code = %s';
			$params[] = $language;
		}

		// Handle content type filtering
		if ( $context ) {
			$query .= $this->buildContextFilter( $context, $specific_item, $params );
		}

		// Always use prepare() now that table name is parameterized
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_var( $this->wpdb->prepare( $query, ...$params ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Toggle needs_review status
	 */
	public function toggleNeedsReview( $translation_id, $needs_review = 1 ) {
		$result = $this->wpdb->update(
			$this->table_translations,
			array( 'needs_review' => $needs_review ? 1 : 0 ),
			array( 'id' => $translation_id ),
			array( '%d' ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Toggle translation lock status
	 */
	public function toggleTranslationLock( $translation_id ) {
		// Get current lock status
		$current_status = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT is_locked FROM {$this->table_translations} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$translation_id // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			)
		);

		if ( $current_status === null ) {
			return false;
		}

		$new_status = $current_status ? 0 : 1;

		$result = $this->wpdb->update(
			$this->table_translations,
			array( 'is_locked' => $new_status ),
			array( 'id' => $translation_id ),
			array( '%d' ),
			array( '%d' )
		);

		return $result !== false ? $new_status : false;
	}

	/**
	 * Update translation text (legacy method - kept for compatibility)
	 */
	public function updateTranslation( $translation_id, $new_text ) {
		return $this->updateTranslationComplete(
			$translation_id,
			array(
				'translated_text' => $new_text,
			)
		);
	}

	/**
	 * Update ONLY the translated_text field (simple approach)
	 */
	public function updateTranslationText( $translation_id, $new_text ) {
		// Check if translation is locked
		$lock_check_query = "SELECT is_locked FROM {$this->table_translations} WHERE id = %d";
		$prepared_lock_check = $this->wpdb->prepare( $lock_check_query, $translation_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$is_locked = $this->wpdb->get_var( $prepared_lock_check ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $is_locked ) {
			return false; // Cannot update locked translations
		}

		$result = $this->wpdb->update(
			$this->table_translations,
			array( 'translated_text' => $new_text ),
			array( 'id' => $translation_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Clear object cache if enabled
		if ( $result !== false && get_option( 'voxfor_ml_enable_object_cache', true ) ) {
			// Get translation info to clear cache properly
			$text_cache_info_query = "SELECT source_hash, language_code, context FROM {$this->table_translations} WHERE id = %d";
			$prepared_text_cache_info = $this->wpdb->prepare( $text_cache_info_query, $translation_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$translation_info = $this->wpdb->get_row( $prepared_text_cache_info, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( $translation_info ) {
				$cache_key = 'voxfor_ml_trans_' . $translation_info['source_hash'] . '_' .
							$translation_info['language_code'] . '_' . $translation_info['context'];
				wp_cache_delete( $cache_key, 'voxfor_ml_translations' );
			}
		}

		return $result !== false;
	}

	/**
	 * Update translation with multiple fields (logical approach)
	 */
	public function updateTranslationComplete( $translation_id, $update_data ) {
		// Check if translation is locked
		$complete_lock_check_query = "SELECT is_locked FROM {$this->table_translations} WHERE id = %d";
		$prepared_complete_lock_check = $this->wpdb->prepare( $complete_lock_check_query, $translation_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$is_locked = $this->wpdb->get_var( $prepared_complete_lock_check ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $is_locked ) {
			return false; // Cannot update locked translations
		}

		// Always update the timestamp
		$update_data['updated_at'] = current_time( 'mysql' );

		// Prepare format strings based on data types
		$formats = array();
		foreach ( $update_data as $key => $value ) {
			if ( in_array( $key, array( 'is_locked', 'post_id' ) ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		$result = $this->wpdb->update(
			$this->table_translations,
			$update_data,
			array( 'id' => $translation_id ),
			$formats,
			array( '%d' )
		);

		// Clear object cache if enabled
		if ( $result !== false && get_option( 'voxfor_ml_enable_object_cache', true ) ) {
			// Get translation info to clear cache properly
			$complete_cache_info_query = "SELECT source_hash, language_code, context FROM {$this->table_translations} WHERE id = %d";
			$prepared_complete_cache_info = $this->wpdb->prepare( $complete_cache_info_query, $translation_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$translation_info = $this->wpdb->get_row( $prepared_complete_cache_info, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( $translation_info ) {
				$cache_key = 'voxfor_ml_trans_' . $translation_info['source_hash'] . '_' .
							$translation_info['language_code'] . '_' . $translation_info['context'];
				wp_cache_delete( $cache_key, 'voxfor_ml_translations' );
			}
		}

		return $result !== false;
	}

	/**
	 * Clean old translations
	 */
	public function cleanOldTranslations( $days = 30 ) {
		$date_threshold = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Delete old completed queue items
		$queue_cleanup_query = "DELETE FROM {$this->table_queue} 
             WHERE status IN ('completed', 'failed') AND processed_at < %s";
		$prepared_queue_cleanup = $this->wpdb->prepare( $queue_cleanup_query, $date_threshold ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->query( $prepared_queue_cleanup ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Optionally delete old unused translations (not locked)
		if ( apply_filters( 'voxfor_ml_clean_unused_translations', false ) ) {
			$translations_cleanup_query = "DELETE FROM {$this->table_translations} 
                 WHERE is_locked = 0 AND updated_at < %s";
			$prepared_translations_cleanup = $this->wpdb->prepare( $translations_cleanup_query, $date_threshold ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->wpdb->query( $prepared_translations_cleanup ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Generate hash for text storage
	 * For new translations: generates UUID to ensure uniqueness
	 * For lookups: uses text-based hash for finding existing translations
	 */
	public function hashText( $text, $context = 'general', $post_id = null, $force_unique = false ) {
		if ( $force_unique ) {
			// Generate unique UUID for new translations to prevent conflicts
			return wp_generate_uuid4();
		}

		// Use text-based hash for lookups to find existing translations
		return hash( 'sha256', $text );
	}

	/**
	 * Clear cache for translation
	 */
	private function clearCache( $source_hash, $language, $context ) {
		$cache_key = 'voxfor_ml_trans_' . substr( $source_hash, 0, 8 ) . '_' . $language . '_' . $context;
		delete_transient( $cache_key );
	}

	/**
	 * Clear all translation caches (useful after UUID implementation)
	 */
	public function clearAllTranslationCaches() {
		// Clear WordPress object cache group
		wp_cache_flush_group( 'voxfor_ml_translations' );

		// Clear transients
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_voxfor_ml_trans_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_voxfor_ml_trans_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return true;
	}

	/**
	 * Clear translation cache for a specific post
	 */
	public function clearPostCache( $post_id ) {
		// Get the post to clear cache for its title and content
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$languages = array( 'he', 'fr', 'de', 'es', 'it' ); // Add your enabled languages
		$contexts = array( 'title', 'post_title', 'content', 'post_content', 'text_fragment', 'general' );

		// Clear cache for title and content in all languages and contexts
		foreach ( $languages as $language ) {
			foreach ( $contexts as $context ) {
				// Clear title cache
				if ( ! empty( $post->post_title ) ) {
					$title_hash = md5( $post->post_title );
					$this->clearCache( $title_hash, $language, $context );
				}
				
				// Clear content cache
				if ( ! empty( $post->post_content ) ) {
					$content_hash = md5( $post->post_content );
					$this->clearCache( $content_hash, $language, $context );
				}
			}
		}

		// Clear WordPress object cache for this post
		wp_cache_delete( "voxfor_ml_post_translations_{$post_id}", 'voxfor_ml' );
		
		return true;
	}

	/**
	 * Export translations
	 * Used by CLI commands and Translation Memory page
	 */
	public function exportTranslations( $language = null, $format = 'json' ) {
		$query  = "SELECT source_text, translated_text, language_code, context 
                  FROM {$this->table_translations} WHERE 1=1";
		$params = array();

		if ( $language ) {
			$query   .= ' AND language_code = %s';
			$params[] = $language;
		}

		if ( empty( $params ) ) {
			$translations = $this->wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$prepared_export_query = $this->wpdb->prepare( $query, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$translations = $this->wpdb->get_results( $prepared_export_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( $format === 'csv' ) {
			return $this->exportAsCSV( $translations );
		}

		return json_encode( $translations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}

	/**
	 * Export as CSV
	 */
	private function exportAsCSV( $data ) {
		if ( empty( $data ) ) {
			return '';
		}

		$output = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		// Headers
		fputcsv( $output, array_keys( $data[0] ) );

		// Data
		foreach ( $data as $row ) {
			fputcsv( $output, $row );
		}

		rewind( $output );
		$csv = stream_get_contents( $output );
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $csv;
	}

	/**
	 * Import translations
	 * Used by CLI commands and potentially other import features
	 */
	public function importTranslations( $data, $format = 'json' ) {
		if ( $format === 'csv' ) {
			$translations = $this->parseCSV( $data );
		} else {
			$translations = json_decode( $data, true );
		}

		if ( ! is_array( $translations ) ) {
			return false;
		}

		$imported = 0;

		foreach ( $translations as $translation ) {
			if ( isset( $translation['source_text'] ) &&
				isset( $translation['translated_text'] ) &&
				isset( $translation['language_code'] ) ) {

				$result = $this->saveTranslation(
					$translation['source_text'],
					$translation['translated_text'],
					$translation['language_code'],
					isset( $translation['context'] ) ? $translation['context'] : 'general',
					null,
					'import'
				);

				if ( $result ) {
					++$imported;
				}
			}
		}

		return $imported;
	}

	/**
	 * Parse CSV data
	 */
	private function parseCSV( $csv_data ) {
		$lines   = explode( "\n", $csv_data );
		$headers = str_getcsv( array_shift( $lines ) );
		$data    = array();

		foreach ( $lines as $line ) {
			if ( trim( $line ) === '' ) {
				continue;
			}

			$row = str_getcsv( $line );
			if ( count( $row ) === count( $headers ) ) {
				$data[] = array_combine( $headers, $row );
			}
		}

		return $data;
	}

	/**
	 * Get translations for editing with enhanced context information
	 */
	public function getTranslationsForEditing( $post_id = null, $language = null, $context = null ) {
		$sql    = "SELECT 
                    id,
                    source_text,
                    translated_text,
                    language_code,
                    context,
                    post_id,
                    provider,
                    created_at,
                    updated_at
                FROM {$this->table_translations} 
                WHERE 1=1";
		$params = array();

		if ( $post_id ) {
			$sql     .= ' AND post_id = %d';
			$params[] = $post_id;
		}

		if ( $language ) {
			$sql     .= ' AND language_code = %s';
			$params[] = $language;
		}

		if ( $context ) {
			$sql     .= ' AND context LIKE %s';
			$params[] = '%' . $context . '%';
		}

		$sql .= ' ORDER BY post_id, context, updated_at DESC';

		if ( empty( $params ) ) {
			return $this->wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$prepared_editing_query = $this->wpdb->prepare( $sql, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return $this->wpdb->get_results( $prepared_editing_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Update translation by ID (for editing interface)
	 */
	public function updateTranslationById( $translation_id, $new_translated_text ) {
		$result = $this->wpdb->update(
			$this->table_translations,
			array(
				'translated_text' => $new_translated_text,
				'provider'        => 'manual',
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $translation_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		// Clear related caches
		if ( $result !== false ) {
			$translation_info_query = "SELECT source_hash, language_code, context, post_id FROM {$this->table_translations} WHERE id = %d";
			$prepared_translation_info = $this->wpdb->prepare( $translation_info_query, $translation_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$translation = $this->wpdb->get_row( $prepared_translation_info ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( $translation ) {
				$this->clearCache(
					$translation->source_hash,
					$translation->language_code,
					$translation->context
				);
			}
		}

		return $result !== false;
	}

	/**
	 * Determine enhanced context based on content type and HTML structure
	 */
	public function determineEnhancedContext( $original_context, $html_tag = null, $post_id = null ) {
		// If we have a post_id, determine the post type
		$post_type = null;
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$post_type = $post->post_type;
			}
		}

		// Create enhanced context based on post type and HTML tag
		$context_parts = array();

		// Add post type if available
		if ( $post_type ) {
			$context_parts[] = $post_type;
		}

		// Add HTML tag if available
		if ( $html_tag ) {
			$context_parts[] = $html_tag;
		}

		// Add original context if it's not generic
		if ( $original_context && $original_context !== 'general' && $original_context !== 'text_fragment' ) {
			$context_parts[] = $original_context;
		}

		// If we have specific parts, join them
		if ( ! empty( $context_parts ) ) {
			return implode( '_', $context_parts );
		}

		// Fallback to original context
		return $original_context ?: 'general';
	}

	/**
	 * Universal duplicate text cleanup - handles ANY repetition pattern
	 */
	private function cleanAllDuplicates( $text ) {
		if ( empty( $text ) || strlen( $text ) < 10 ) {
			return $text;
		}

		$original_text = $text;
		$words         = explode( ' ', $text );
		$word_count    = count( $words );

		// Skip very short text
		if ( $word_count < 4 ) {
			return $text;
		}

		// Try all possible division factors to find ANY repeating pattern
		for ( $divisor = 2; $divisor <= min( 20, $word_count ); $divisor++ ) {
			if ( $word_count % $divisor == 0 ) {
				$segment_length = $word_count / $divisor;

				// Skip if segment would be too small
				if ( $segment_length < 2 ) {
					continue;
				}

				$first_segment = array_slice( $words, 0, $segment_length );

				// Check if ALL segments are identical to the first segment
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
					$cleaned_text = implode( ' ', $first_segment );
					return $cleaned_text;
				}
			}
		}

		// Also check for partial word duplications (like "text text text" where words repeat)
		$unique_words     = array_unique( $words );
		$uniqueness_ratio = count( $unique_words ) / $word_count;

		// If more than 80% of words are duplicates, try to find the core pattern
		if ( $uniqueness_ratio < 0.2 && $word_count > 6 ) {
			// Find the shortest repeating pattern
			for ( $pattern_length = 1; $pattern_length <= $word_count / 3; $pattern_length++ ) {
				$pattern      = array_slice( $words, 0, $pattern_length );
				$pattern_text = implode( ' ', $pattern );

				// Check if this pattern repeats throughout the text
				$repetitions = 0;
				for ( $i = 0; $i < $word_count; $i += $pattern_length ) {
					$current_segment = array_slice( $words, $i, $pattern_length );
					if ( $current_segment === $pattern ) {
						++$repetitions;
					} else {
						break;
					}
				}

				// If pattern repeats at least 3 times and covers most of the text
				if ( $repetitions >= 3 && ( $repetitions * $pattern_length ) >= ( $word_count * 0.8 ) ) {
					return $pattern_text;
				}
			}
		}

		// No duplication found, return original text
		return $text;
	}

	/**
	 * Recursive duplicate cleanup - keeps cleaning until no more duplications found
	 */
	private function cleanAllDuplicatesRecursive( $text ) {
		$max_iterations = 10; // Prevent infinite loops
		$iteration      = 0;

		do {
			$original_text = $text;
			$text          = $this->cleanAllDuplicates( $text );
			++$iteration;

			// If text changed, log the recursive cleanup
			if ( $text !== $original_text ) {
			}
		} while ( $text !== $original_text && $iteration < $max_iterations );

		if ( $iteration >= $max_iterations ) {
		}

		return $text;
	}

	/**
	 * Get post_ids that contain header or footer content
	 */
	public function getHeaderFooterPostIds( $type ) {
		$header_footer_query = "SELECT DISTINCT post_id FROM {$this->table_translations} 
             WHERE (context LIKE %s OR context IN ('text_fragment', 'general', 'menu_item', 'widget_title', 'navigation')) 
             AND post_id IS NOT NULL
             ORDER BY post_id";
		$prepared_header_footer = $this->wpdb->prepare( $header_footer_query, '%' . $type . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$post_ids = $this->wpdb->get_col( $prepared_header_footer ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( 'intval', $post_ids );
	}
}
