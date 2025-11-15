<?php
namespace VoxforML\Utils;

/**
 * Handles encryption/decryption of sensitive data
 */
class EncryptionHandler {

	/**
	 * Get encryption key from WordPress constants
	 */
	private static function getEncryptionKey() {
		// Use WordPress auth keys to create encryption key
		$key_material = AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY . NONCE_KEY;
		return hash( 'sha256', $key_material, true );
	}

	/**
	 * Encrypt sensitive data
	 */
	public static function encrypt( $data ) {
		if ( empty( $data ) ) {
			return '';
		}

		$key = self::getEncryptionKey();
		$iv  = openssl_random_pseudo_bytes( 16 );

		$encrypted = openssl_encrypt( $data, 'AES-256-CBC', $key, 0, $iv );

		if ( $encrypted === false ) {
			return $data; // Fallback to unencrypted if encryption fails
		}

		// Prepend IV to encrypted data
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt sensitive data
	 */
	public static function decrypt( $encrypted_data ) {
		if ( empty( $encrypted_data ) ) {
			return '';
		}

		$data = base64_decode( $encrypted_data );

		if ( $data === false || strlen( $data ) < 16 ) {
			return $encrypted_data; // Assume it's unencrypted legacy data
		}

		$key       = self::getEncryptionKey();
		$iv        = substr( $data, 0, 16 );
		$encrypted = substr( $data, 16 );

		$decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );

		if ( $decrypted === false ) {
			return $encrypted_data; // Fallback to original if decryption fails
		}

		return $decrypted;
	}

	/**
	 * Mask API key for display (show only first 8 and last 4 characters)
	 */
	public static function maskApiKey( $api_key ) {
		if ( empty( $api_key ) || strlen( $api_key ) < 12 ) {
			return $api_key;
		}

		$start  = substr( $api_key, 0, 8 );
		$end    = substr( $api_key, -4 );
		$middle = str_repeat( '*', strlen( $api_key ) - 12 );

		return $start . $middle . $end;
	}

	/**
	 * Validate API key format
	 */
	public static function validateApiKey( $api_key ) {
		// DeepL API keys end with ':fx' for free or are UUID-like for pro
		// Free keys: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx
		// Pro keys: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
		$is_free_key  = strpos( $api_key, ':fx' ) !== false;
		$is_uuid_like = preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}(:fx)?$/i', $api_key );
		$min_length   = strlen( $api_key ) >= 30; // Minimum reasonable length

		// Validate DeepL API key format
		$is_valid = ( $is_uuid_like || $is_free_key ) && $min_length;

		return $is_valid;
	}

	/**
	 * Securely store API key
	 */
	public static function storeApiKey( $api_key ) {

		if ( empty( $api_key ) ) {
			delete_option( 'voxfor_ml_deepl_api_key_encrypted' );

			return true;
		}

		$is_valid = self::validateApiKey( $api_key );

		if ( ! $is_valid ) {
			return false;
		}

		$encrypted = self::encrypt( $api_key );

		$result = update_option( 'voxfor_ml_deepl_api_key_encrypted', $encrypted );

		return $result;
	}

	/**
	 * Retrieve and decrypt API key
	 */
	public static function getApiKey() {
		// First check for encrypted key
		$encrypted = get_option( 'voxfor_ml_deepl_api_key_encrypted', '' );

		if ( ! empty( $encrypted ) ) {
			$decrypted = self::decrypt( $encrypted );

			return $decrypted;
		}

		// Check for unencrypted key (current storage method)
		$unencrypted_key = get_option( 'voxfor_ml_deepl_api_key', '' );

		if ( ! empty( $unencrypted_key ) ) {
			return $unencrypted_key;
		}

		return '';
	}

	/**
	 * Test API key by making a simple request
	 */
	public function testApiKey( $api_key = null ) {

		if ( $api_key === null ) {
			$api_key = self::getApiKey();

		} else {

		}

		if ( empty( $api_key ) ) {

			return array(
				'success' => false,
				'message' => __( 'API key is empty', 'voxfor-multilanguage' ),
			);
		}

		// Determine API URL based on key type
		$api_url = 'https://api-free.deepl.com/v2/usage'; // Default to free
		if ( strpos( $api_key, ':fx' ) === false ) {
			// Pro API key (doesn't contain :fx)
			$api_url = 'https://api.deepl.com/v2/usage';
		}


		// Test DeepL API with usage endpoint
		$response = wp_remote_get(
			$api_url,
			array(
				'headers' => array(
					'Authorization' => 'DeepL-Auth-Key ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {

			return array(
				'success' => false,
				'message' => __( 'Connection failed: ', 'voxfor-multilanguage' ) . $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code === 200 ) {
			$data  = json_decode( $body, true );
			$usage = isset( $data['character_count'] ) ? number_format( $data['character_count'] ) : '0';
			$limit = isset( $data['character_limit'] ) ? number_format( $data['character_limit'] ) : 'Unknown';
			return array(
				'success' => true,
				'message' => __( '✅ DeepL API key is valid! Usage: ', 'voxfor-multilanguage' ) . $usage . '/' . $limit . ' characters',
			);
		} elseif ( $status_code === 403 ) {
			return array(
				'success' => false,
				'message' => __( '❌ Invalid DeepL API key - please check your key', 'voxfor-multilanguage' ),
			);
		} elseif ( $status_code === 429 ) {
			return array(
				'success' => true,
				'message' => __( '⚠️ Rate limit exceeded (but key is valid)', 'voxfor-multilanguage' ),
			);
		} else {
			$data    = json_decode( $body, true );
			$message = isset( $data['message'] ) ? $data['message'] : __( 'Unknown error', 'voxfor-multilanguage' );
			return array(
				'success' => false,
				'message' => __( 'DeepL API Error: ', 'voxfor-multilanguage' ) . $message,
			);
		}
	}

	/**
	 * Static wrapper for backward compatibility
	 */
	public static function testApiKeyStatic( $api_key = null ) {
		$instance = new self();
		return $instance->testApiKey( $api_key );
	}
}
