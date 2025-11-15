<?php
namespace VoxforML\Translator;

use VoxforML\Utils\ApiCreditManager;

/**
 * DeepL API Translator
 * Handles translation using DeepL API with credit management
 */
class DeepLTranslator {
	private $api_key;
	private $api_url;
	private $max_retries = 3;
	private $timeout     = 30;
	private $credit_manager;

	public function __construct() {
		// Initialize credit manager
		$this->credit_manager = new ApiCreditManager();

		// Try encrypted version first (current storage method)
		try {
			$this->api_key = \VoxforML\Utils\EncryptionHandler::getApiKey();
		} catch ( Exception $e ) {
			// Fallback to direct WordPress options
			$this->api_key = get_option( 'voxfor_ml_deepl_api_key', '' );
		}

		// Determine API URL based on key type
		if ( strpos( $this->api_key, ':fx' ) !== false ) {
			// Free API
			$this->api_url = 'https://api-free.deepl.com/v2/translate';
		} else {
			// Pro API
			$this->api_url = 'https://api.deepl.com/v2/translate';
		}
	}

	/**
	 * Test API connection
	 */
	public function testConnection() {
		if ( empty( $this->api_key ) ) {
			return array(
				'success' => false,
				'message' => 'DeepL API key is not configured',
			);
		}

		try {
			// Test with usage endpoint
			$usage_url = str_replace( '/translate', '/usage', $this->api_url );


			$response = wp_remote_get(
				$usage_url,
				array(
					'headers' => array(
						'Authorization' => 'DeepL-Auth-Key ' . $this->api_key,
						'Content-Type'  => 'application/json',
					),
					'timeout' => $this->timeout,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'message' => 'Connection failed: ' . $response->get_error_message(),
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( $status_code === 200 ) {
				$usage_data = json_decode( $body, true );
				return array(
					'success' => true,
					'message' => 'DeepL API connection successful',
					'usage'   => $usage_data,
				);
			} else {

				// Check if it's an authentication error
				if ( $status_code === 403 ) {
					return array(
						'success' => false,
						'message' => 'Invalid API key or insufficient permissions',
					);
				} elseif ( $status_code === 401 ) {
					return array(
						'success' => false,
						'message' => 'Authentication failed - check API key',
					);
				} else {
					return array(
						'success' => false,
						'message' => 'API test failed: HTTP ' . $status_code . ' - ' . substr( $body, 0, 100 ),
					);
				}
			}
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => 'Test failed: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Translate text using DeepL API
	 */
	public function translate( $text, $target_language, $source_language = 'AUTO', $context = '' ) {

		// Check if API calls are allowed
		if ( ! $this->credit_manager->isApiEnabled() ) {
			return false; // Return false to indicate API is disabled
		}

		if ( empty( $this->api_key ) ) {
			return false;
		}

		if ( empty( $text ) || strlen( trim( $text ) ) === 0 ) {
			return $text;
		}

		// UNIVERSAL CLEANUP: Clean duplicate text BEFORE sending to DeepL
		$text = $this->cleanDuplicateText( $text );

		// Use custom prefix as source if AUTO is specified
		if ( $source_language === 'AUTO' ) {
			$custom_prefix   = get_option( 'voxfor_ml_display_prefix', 'en' );
			$source_language = strtoupper( $custom_prefix );
		}

		// Skip if target is same as source
		if ( strtoupper( $target_language ) === strtoupper( $source_language ) ) {
			return $text;
		}

		// Map language codes to DeepL format
		$target_lang = $this->mapLanguageCode( $target_language );
		$source_lang = $this->mapLanguageCode( $source_language );

		if ( ! $target_lang ) {
			return $text;
		}

		// Ensure UTF-8 encoding for Hebrew and Arabic text
		$text = mb_convert_encoding( $text, 'UTF-8', 'auto' );

		// Translation parameters validated

		$retry_count = 0;

		while ( $retry_count < $this->max_retries ) {
			try {
				// DeepL API expects form data, not JSON
				$body = array(
					'text'                => $text, // Single text string, not array
					'target_lang'         => $target_lang,
					'preserve_formatting' => '1',
					'tag_handling'        => 'html',
				);

				// Only add source_lang if it's not empty
				if ( ! empty( $source_lang ) ) {
					$body['source_lang'] = $source_lang;
				}

				// Add context if provided (DeepL Pro feature)
				if ( ! empty( $context ) ) {
					$body['context'] = $context;
				}

				// API request prepared

				$response = wp_remote_post(
					$this->api_url,
					array(
						'headers' => array(
							'Authorization' => 'DeepL-Auth-Key ' . $this->api_key,
							'Content-Type'  => 'application/x-www-form-urlencoded',
						),
						'body'    => http_build_query( $body ),
						'timeout' => $this->timeout,
					)
				);

				if ( is_wp_error( $response ) ) {
					throw new \Exception( 'HTTP request failed: ' . $response->get_error_message() );
				}

				$status_code   = wp_remote_retrieve_response_code( $response );
				$response_body = wp_remote_retrieve_body( $response );

				// API response received

				if ( $status_code === 200 ) {
					$data = json_decode( $response_body, true );

					// API response received

					if ( isset( $data['translations'][0]['text'] ) ) {
						$translated_text = $data['translations'][0]['text'];

						// Ensure UTF-8 encoding for the translated text
						$translated_text = mb_convert_encoding( $translated_text, 'UTF-8', 'auto' );

						// Check if translation actually happened (DeepL sometimes returns same text)
						if ( trim( $translated_text ) === trim( $text ) ) {
							// Still record usage but mark as potentially failed translation
						}

						// Record API usage for credit tracking
						$this->credit_manager->recordUsage(
							strlen( $text ),
							$source_lang,
							$target_lang,
							$context,
							'deepl'
						);

						return $translated_text;
					} else {
						throw new \Exception( 'Invalid response format: ' . $response_body );
					}
				} elseif ( $status_code === 429 ) {
					// Rate limit - wait and retry
					sleep( 2 ** $retry_count ); // Exponential backoff
					++$retry_count;
					continue;

				} elseif ( $status_code === 456 ) {
					// Quota exceeded
					return $text;

				} else {
					throw new \Exception( "API error: HTTP {$status_code} - {$response_body}" );
				}
			} catch ( \Exception $e ) {
				++$retry_count;

				if ( $retry_count >= $this->max_retries ) {
					return $text;
				}

				// Wait before retry
				sleep( 1 );
			}
		}

		return $text;
	}

	/**
	 * Map language codes to DeepL format
	 */
	private function mapLanguageCode( $lang_code ) {
		$lang_map = array(
			// DeepL supported languages only
			'en' => 'EN',
			'de' => 'DE',
			'fr' => 'FR',
			'es' => 'ES',
			'it' => 'IT',
			'pt' => 'PT',
			'ru' => 'RU',
			'ja' => 'JA',
			'ko' => 'KO',
			'zh' => 'ZH',
			'nl' => 'NL',
			'pl' => 'PL',
			'sv' => 'SV',
			'da' => 'DA',
			'fi' => 'FI',
			'no' => 'NB',  // Norwegian Bokmål
			'cs' => 'CS',
			'sk' => 'SK',
			'sl' => 'SL',
			'et' => 'ET',
			'lv' => 'LV',
			'lt' => 'LT',
			'hu' => 'HU',
			'ro' => 'RO',
			'bg' => 'BG',
			'el' => 'EL',
			'tr' => 'TR',
			'uk' => 'UK',
			'ar' => 'AR',
		'he' => 'HE',  // Hebrew (next-gen models only)
		'id' => 'ID',
		'th' => 'TH',  // Thai (next-gen models only)
		'vi' => 'VI',   // Vietnamese (next-gen models only)
		// Removed: 'ms' (Malay), 'hi' (Hindi), and 'bn' (Bengali) - not supported by DeepL
		);

		$code   = strtolower( $lang_code );
		$mapped = isset( $lang_map[ $code ] ) ? $lang_map[ $code ] : null;

		// Debug logging for language mapping
		if ( $mapped ) {
		} else {
		}

		return $mapped;
	}

	/**
	 * Get supported languages
	 */
	public function getSupportedLanguages() {
		return array(
			'en' => 'English',
			'de' => 'German',
			'fr' => 'French',
			'es' => 'Spanish',
			'it' => 'Italian',
			'pt' => 'Portuguese',
			'ru' => 'Russian',
			'ja' => 'Japanese',
			'ko' => 'Korean',
			'zh' => 'Chinese',
			'nl' => 'Dutch',
			'pl' => 'Polish',
			'sv' => 'Swedish',
			'da' => 'Danish',
			'fi' => 'Finnish',
			'no' => 'Norwegian',
			'cs' => 'Czech',
			'sk' => 'Slovak',
			'sl' => 'Slovenian',
			'et' => 'Estonian',
			'lv' => 'Latvian',
			'lt' => 'Lithuanian',
			'hu' => 'Hungarian',
			'ro' => 'Romanian',
			'bg' => 'Bulgarian',
			'el' => 'Greek',
			'tr' => 'Turkish',
		'uk' => 'Ukrainian',
		'ar' => 'Arabic',
		'id' => 'Indonesian',
		'th' => 'Thai',
		'vi' => 'Vietnamese',
		);
	}

	/**
	 * Get usage statistics
	 */
	public function getUsage() {
		if ( empty( $this->api_key ) ) {
			return null;
		}

		try {
			$usage_url = str_replace( '/translate', '/usage', $this->api_url );

			$response = wp_remote_get(
				$usage_url,
				array(
					'headers' => array(
						'Authorization' => 'DeepL-Auth-Key ' . $this->api_key,
						'Content-Type'  => 'application/json',
					),
					'timeout' => $this->timeout,
				)
			);

			if ( is_wp_error( $response ) ) {
				return null;
			}

			$status_code = wp_remote_retrieve_response_code( $response );

			if ( $status_code === 200 ) {
				$body = wp_remote_retrieve_body( $response );
				return json_decode( $body, true );
			}
		} catch ( \Exception $e ) {
		}

		return null;
	}

	/**
	 * Batch translate multiple texts using DeepL's batch API
	 */
	public function batchTranslate( $texts, $target_language, $source_language = 'EN', $context = '' ) {

		// Check if API calls are allowed
		if ( ! $this->credit_manager->isApiEnabled() ) {
			return array();
		}

		// Refresh API key in case it was updated
		$this->api_key = \VoxforML\Utils\EncryptionHandler::getApiKey();


		if ( empty( $this->api_key ) ) {
			return array();
		}

		if ( empty( $texts ) || ! is_array( $texts ) ) {
			return array();
		}

		// Map language codes
		$target_lang = $this->mapLanguageCode( $target_language );
		$source_lang = $this->mapLanguageCode( $source_language );

		if ( ! $target_lang ) {
			return array();
		}

		$translations = array();

		// Filter out empty texts and prepare batch
		$batch_texts = array();
		$text_map    = array();

		foreach ( $texts as $index => $text ) {
			if ( empty( $text ) || strlen( trim( $text ) ) === 0 ) {
				$translations[ $index ] = $text;
				continue;
			}

			// Skip if target is same as source
			if ( strtoupper( $target_language ) === strtoupper( $source_language ) ) {
				$translations[ $index ] = $text;
				continue;
			}

			// Ensure UTF-8 encoding
			$text                                  = mb_convert_encoding( $text, 'UTF-8', 'auto' );
			$batch_texts[]                         = $text;
			$text_map[ count( $batch_texts ) - 1 ] = $index;
		}

		if ( empty( $batch_texts ) ) {
			return $translations;
		}

		// DeepL supports up to 50 texts per request, but we'll use smaller batches for reliability
		$max_batch_size = 25;
		$batches        = array_chunk( $batch_texts, $max_batch_size, true );
		$batch_maps     = array_chunk( $text_map, $max_batch_size, true );

		foreach ( $batches as $batch_index => $batch ) {
			$current_map = $batch_maps[ $batch_index ];

			try {
				$batch_translations = $this->sendBatchRequest( $batch, $target_lang, $source_lang );

				if ( $batch_translations && is_array( $batch_translations ) ) {
					foreach ( $batch_translations as $batch_pos => $translation ) {
						if ( isset( $current_map[ $batch_pos ] ) ) {
							$original_index                  = $current_map[ $batch_pos ];
							$translations[ $original_index ] = $translation;
						}
					}
				} else {
					// Fallback to individual translation for this batch
					foreach ( $batch as $batch_pos => $text ) {
						if ( isset( $current_map[ $batch_pos ] ) ) {
							$original_index                  = $current_map[ $batch_pos ];
							$translation                     = $this->translate( $text, $target_language, $source_language, $context );
							$translations[ $original_index ] = $translation ?: $text;
						}
					}
				}

				// Small delay between batches to respect rate limits
				if ( $batch_index < count( $batches ) - 1 ) {
					usleep( 200000 ); // 0.2 second between batches
				}
			} catch ( Exception $e ) {

				// Fallback to individual translation for this batch
				foreach ( $batch as $batch_pos => $text ) {
					if ( isset( $current_map[ $batch_pos ] ) ) {
						$original_index                  = $current_map[ $batch_pos ];
						$translation                     = $this->translate( $text, $target_language, $source_language, $context );
						$translations[ $original_index ] = $translation ?: $text;
					}
				}
			}
		}

		return $translations;
	}

	/**
	 * Send batch request to DeepL API
	 */
	private function sendBatchRequest( $texts, $target_lang, $source_lang ) {
		$data = array(
			'text'                => $texts,
			'target_lang'         => $target_lang,
			'source_lang'         => $source_lang,
			'preserve_formatting' => '1',
			'tag_handling'        => 'html',
		);

		$response = wp_remote_post(
			$this->api_url,
			array(
				'timeout' => 60, // Longer timeout for batch requests
				'headers' => array(
					'Authorization' => 'DeepL-Auth-Key ' . $this->api_key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $data ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code !== 200 ) {
			return false;
		}

		$result = json_decode( $body, true );

		if ( ! $result || ! isset( $result['translations'] ) ) {
			return false;
		}

		// Extract translated texts in order
		$translations = array();
		foreach ( $result['translations'] as $translation ) {
			$translations[] = $translation['text'];
		}

		return $translations;
	}

	/**
	 * Clean duplicate text patterns before sending to DeepL
	 */
	private function cleanDuplicateText( $text ) {
		// Normalize whitespace first
		$normalized = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$normalized = trim( preg_replace( '/\s+/', ' ', $normalized ) );

		// Split into words for pattern detection
		$words      = explode( ' ', $normalized );
		$word_count = count( $words );

		// Skip very short text
		if ( $word_count < 4 ) {
			return $normalized;
		}

		$original_text = $normalized;

		// Try different division factors to find repeating patterns (2x, 3x, 4x, 5x, 6x, etc.)
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

		// No duplication found, return normalized text
		return $normalized;
	}
}
