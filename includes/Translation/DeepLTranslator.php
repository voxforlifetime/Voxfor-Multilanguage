<?php
namespace VoxforML\Translator;

/**
 * DeepL API Translator
 * Handles translation using DeepL API
 */
class DeepLTranslator {
	private $api_key;
	private $api_url;
	private $max_retries = 3;
	private $timeout     = 30;

	public function __construct() {
		$this->api_key = get_option( 'voxfor_ml_deepl_api_key', '' );

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
				return array(
					'success' => false,
					'message' => 'API test failed: HTTP ' . $status_code,
				);
			}
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => 'Test failed: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Translate text using DeepL API
	 */
	public function translate( $text, $target_language, $source_language = 'EN', $context = '' ) {
		if ( empty( $this->api_key ) ) {
			return $text;
		}

		if ( empty( $text ) || strlen( trim( $text ) ) === 0 ) {
			return $text;
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

	// Extract and replace HTML tags with placeholders if the text contains HTML
	$contains_html = ( $text !== wp_strip_all_tags( $text ) );
	$placeholders = array();
	$text_to_translate = $text;
	
	if ( $contains_html ) {
		// Replace HTML tags with numbered placeholders
		$placeholder_index = 0;
			$text_to_translate = preg_replace_callback(
				'/<[^>]+>/',
				function( $matches ) use ( &$placeholders, &$placeholder_index ) {
					$tag = $matches[0];
					$placeholder = "[TAG_{$placeholder_index}]";
					$placeholders[$placeholder] = $tag;
					$placeholder_index++;
					return $placeholder;
				},
				$text
			);
		}

		$retry_count = 0;

		while ( $retry_count < $this->max_retries ) {
			try {
				$body = array(
					'text'                => array( $text_to_translate ),
					'target_lang'         => $target_lang,
					'source_lang'         => $source_lang,
					'preserve_formatting' => true,
					'tag_handling'        => 'xml', // Use XML mode to preserve placeholders
				);

				// Add context if provided
				if ( ! empty( $context ) ) {
					$body['context'] = $context;
				}

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
					throw new Exception( 'HTTP request failed: ' . $response->get_error_message() );
				}

				$status_code   = wp_remote_retrieve_response_code( $response );
				$response_body = wp_remote_retrieve_body( $response );


				if ( $status_code === 200 ) {
					$data = json_decode( $response_body, true );

					if ( isset( $data['translations'][0]['text'] ) ) {
						$translated_text = $data['translations'][0]['text'];
						
						// Restore HTML tags from placeholders
						if ( $contains_html && ! empty( $placeholders ) ) {
							foreach ( $placeholders as $placeholder => $tag ) {
								$translated_text = str_replace( $placeholder, $tag, $translated_text );
							}
						}
						
						return $translated_text;
					} else {
						throw new Exception( 'Invalid response format: ' . $response_body );
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
					throw new Exception( "API error: HTTP {$status_code} - {$response_body}" );
				}
			} catch ( Exception $e ) {
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
		// Complete DeepL supported languages mapping (as of 2024)
		// Source: https://developers.deepl.com/docs/resources/supported-languages
		$lang_map = array(
			// Core supported languages
			'en' => 'EN',           // English
			'de' => 'DE',           // German
			'fr' => 'FR',           // French
			'es' => 'ES',           // Spanish
			'it' => 'IT',           // Italian
			'pt' => 'PT',           // Portuguese
			'ru' => 'RU',           // Russian
			'ja' => 'JA',           // Japanese
			'ko' => 'KO',           // Korean
			'zh' => 'ZH',           // Chinese (simplified)
			'nl' => 'NL',           // Dutch
			'pl' => 'PL',           // Polish
			'sv' => 'SV',           // Swedish
			'da' => 'DA',           // Danish
			'fi' => 'FI',           // Finnish
			'no' => 'NB',           // Norwegian (Bokmål)
			'nb' => 'NB',           // Norwegian (Bokmål) - alternative code
			'cs' => 'CS',           // Czech
			'sk' => 'SK',           // Slovak
			'sl' => 'SL',           // Slovenian
			'et' => 'ET',           // Estonian
			'lv' => 'LV',           // Latvian
			'lt' => 'LT',           // Lithuanian
			'hu' => 'HU',           // Hungarian
			'ro' => 'RO',           // Romanian
			'bg' => 'BG',           // Bulgarian
			'el' => 'EL',           // Greek
			'tr' => 'TR',           // Turkish
			'uk' => 'UK',           // Ukrainian
			'ar' => 'AR',           // Arabic
			'id' => 'ID',           // Indonesian
			'th' => 'TH',           // Thai
			'vi' => 'VI',           // Vietnamese

			// Next-generation model languages (text translation only)
			'he' => 'HE',           // Hebrew (text translation via next-gen models only)

		// Note: The following languages are NOT supported by DeepL:
		// 'ms' => 'MS',        // Malay - NOT SUPPORTED (removed)
		// 'hi' => 'HI',        // Hindi - NOT SUPPORTED (removed)
		// 'bn' => 'BN',        // Bengali - NOT SUPPORTED (removed)
		);

		$code   = strtolower( $lang_code );
		$mapped = isset( $lang_map[ $code ] ) ? $lang_map[ $code ] : null;

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
			// Core supported languages
			'en' => 'English',
			'de' => 'German',
			'fr' => 'French',
			'es' => 'Spanish',
			'it' => 'Italian',
			'pt' => 'Portuguese',
			'ru' => 'Russian',
			'ja' => 'Japanese',
			'ko' => 'Korean',
			'zh' => 'Chinese (Simplified)',
			'nl' => 'Dutch',
			'pl' => 'Polish',
			'sv' => 'Swedish',
			'da' => 'Danish',
			'fi' => 'Finnish',
			'no' => 'Norwegian (Bokmål)',
			'nb' => 'Norwegian (Bokmål)',
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

		// Next-generation model languages (text translation only)
		'he' => 'Hebrew (Next-Gen)',

		// Note: Malay, Hindi, and Bengali are NOT supported by DeepL (removed)
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
		} catch ( Exception $e ) {
		}

		return null;
	}

	/**
	 * Batch translate multiple texts using DeepL API
	 */
	public function batchTranslate( $texts, $target_language, $source_language = 'EN', $context = '' ) {
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

		// Translate each text individually with placeholder support
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

			// Use the translate method which now handles placeholders
			$translation = $this->translate( $text, $target_language, $source_language, $context );
			$translations[ $index ] = $translation ?: $text;
		}

		return $translations;
	}
}
