<?php
namespace VoxforML\Utils;

/**
 * Handles security measures including CSRF protection
 */
class SecurityHandler {
	private $nonce_action         = 'voxfor_ml_ajax';
	private $rate_limit_transient = 'voxfor_ml_rate_limit_';

	/**
	 * Constructor
	 */
	public function __construct() {
		// Add security headers
		add_action( 'send_headers', array( $this, 'addSecurityHeaders' ) );

		// Validate AJAX requests
		add_action( 'wp_ajax_nopriv_voxfor_ml_check_translation', array( $this, 'validatePublicAjax' ), 1 );
		add_action( 'wp_ajax_nopriv_voxfor_ml_trigger_translation', array( $this, 'validatePublicAjax' ), 1 );

		// Rate limiting
		add_filter( 'voxfor_ml_can_make_request', array( $this, 'checkRateLimit' ), 10, 2 );

		// Sanitize all inputs
		add_filter( 'voxfor_ml_sanitize_input', array( $this, 'sanitizeInput' ), 10, 2 );

		// Encrypt sensitive data
		add_filter( 'voxfor_ml_encrypt_data', array( $this, 'encryptData' ) );
		add_filter( 'voxfor_ml_decrypt_data', array( $this, 'decryptData' ) );
	}

	/**
	 * Add security headers
	 */
	public function addSecurityHeaders() {
		// Content Security Policy for admin pages
		if ( is_admin() ) {
			header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:" );
		}

		// X-Content-Type-Options
		header( 'X-Content-Type-Options: nosniff' );

		// X-Frame-Options
		header( 'X-Frame-Options: SAMEORIGIN' );

		// X-XSS-Protection
		header( 'X-XSS-Protection: 1; mode=block' );

		// Referrer Policy
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	}

	/**
	 * Validate public AJAX requests
	 */
	public function validatePublicAjax() {
		// Check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'voxfor_ml_translation_check' ) ) {
			wp_die( esc_html__( 'Security check failed', 'voxfor-multilanguage' ), 403 );
		}

		// Check rate limit
		$ip = $this->getClientIp();
		if ( ! $this->checkRateLimit( $ip, 'ajax' ) ) {
			wp_die( esc_html__( 'Too many requests. Please try again later.', 'voxfor-multilanguage' ), 429 );
		}

		// Validate referer
		if ( ! $this->validateReferer() ) {
			wp_die( esc_html__( 'Invalid request origin', 'voxfor-multilanguage' ), 403 );
		}
	}

	/**
	 * Check rate limit
	 */
	public function checkRateLimit( $identifier, $action = 'default' ) {
		$limits = array(
			'default'     => array(
				'requests' => 60,
				'window'   => 60,
			), // 60 requests per minute
			'ajax'        => array(
				'requests' => 30,
				'window'   => 60,
			), // 30 AJAX requests per minute
			'api'         => array(
				'requests' => 100,
				'window'   => 60,
			), // 100 API requests per minute
			'translation' => array(
				'requests' => 10,
				'window'   => 60,
			), // 10 translation requests per minute
		);

		$limit_config  = isset( $limits[ $action ] ) ? $limits[ $action ] : $limits['default'];
		$transient_key = $this->rate_limit_transient . md5( $identifier . '_' . $action );

		$current = get_transient( $transient_key );

		if ( $current === false ) {
			set_transient( $transient_key, 1, $limit_config['window'] );
			return true;
		}

		if ( $current >= $limit_config['requests'] ) {
			return false;
		}

		set_transient( $transient_key, $current + 1, $limit_config['window'] );
		return true;
	}

	/**
	 * Get client IP address
	 */
	private function getClientIp() {
		$ip_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( array_key_exists( $key, $_SERVER ) === true ) {
				foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) as $ip ) {
					$ip = trim( $ip );

					if ( filter_var(
						$ip,
						FILTER_VALIDATE_IP,
						FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
					) !== false ) {
						return $ip;
					}
				}
			}
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	}

	/**
	 * Validate referer
	 */
	private function validateReferer() {
		if ( ! isset( $_SERVER['HTTP_REFERER'] ) ) {
			return false;
		}

		$referer  = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
		$site_url = wp_parse_url( home_url() );

		return $referer['host'] === $site_url['host'];
	}

	/**
	 * Sanitize input based on type
	 */
	public function sanitizeInput( $value, $type = 'text' ) {
		switch ( $type ) {
			case 'text':
				return sanitize_text_field( $value );

			case 'textarea':
				return sanitize_textarea_field( $value );

			case 'html':
				return wp_kses_post( $value );

			case 'url':
				return esc_url_raw( $value );

			case 'email':
				return sanitize_email( $value );

			case 'key':
				return preg_replace( '/[^a-zA-Z0-9_-]/', '', $value );

			case 'language':
				return preg_replace( '/[^a-z]/', '', strtolower( $value ) );

			case 'int':
				return intval( $value );

			case 'float':
				return floatval( $value );

			case 'bool':
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN );

			case 'array':
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_map( 'sanitize_text_field', $value );

			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Encrypt sensitive data
	 */
	public function encryptData( $data ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return base64_encode( $data );
		}

		$key = $this->getEncryptionKey();
		$iv  = openssl_random_pseudo_bytes( 16 );

		$encrypted = openssl_encrypt( $data, 'AES-256-CBC', $key, 0, $iv );

		return base64_encode( $encrypted . '::' . $iv );
	}

	/**
	 * Decrypt sensitive data
	 */
	public function decryptData( $data ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return base64_decode( $data );
		}

		$key = $this->getEncryptionKey();

		list($encrypted_data, $iv) = explode( '::', base64_decode( $data ), 2 );

		return openssl_decrypt( $encrypted_data, 'AES-256-CBC', $key, 0, $iv );
	}

	/**
	 * Get encryption key
	 */
	private function getEncryptionKey() {
		$key = get_option( 'voxfor_ml_encryption_key' );

		if ( ! $key ) {
			$key = wp_generate_password( 32, true, true );
			update_option( 'voxfor_ml_encryption_key', $key );
		}

		return $key;
	}

	/**
	 * Validate API key format
	 */
	public function validateApiKey( $key ) {
		// DeepL API keys have specific format
		if ( empty( $key ) || strlen( $key ) < 10 ) {
			return false;
		}

		// Check length and format
		if ( strlen( $key ) < 40 || strlen( $key ) > 200 ) {
			return false;
		}

		// Check for valid characters
		if ( ! preg_match( '/^sk-ant-[a-zA-Z0-9-_]+$/', $key ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Sanitize file upload
	 */
	public function sanitizeFileUpload( $file, $allowed_types = array( 'csv', 'json' ) ) {
		// Check if file was uploaded
		if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'invalid_upload', __( 'Invalid file upload', 'voxfor-multilanguage' ) );
		}

		// Check file size (max 10MB)
		if ( $file['size'] > 10 * 1024 * 1024 ) {
			return new \WP_Error( 'file_too_large', __( 'File size exceeds 10MB limit', 'voxfor-multilanguage' ) );
		}

		// Check file extension
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed_types ) ) {
			return new \WP_Error( 'invalid_type', __( 'Invalid file type', 'voxfor-multilanguage' ) );
		}

		// Check MIME type
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = finfo_file( $finfo, $file['tmp_name'] );
		finfo_close( $finfo );

		$allowed_mimes = array(
			'csv'  => array( 'text/csv', 'text/plain' ),
			'json' => array( 'application/json', 'text/plain' ),
		);

		if ( ! isset( $allowed_mimes[ $ext ] ) || ! in_array( $mime, $allowed_mimes[ $ext ] ) ) {
			return new \WP_Error( 'invalid_mime', __( 'Invalid file MIME type', 'voxfor-multilanguage' ) );
		}

		return true;
	}

	/**
	 * Generate secure token
	 */
	public function generateToken( $length = 32 ) {
		if ( function_exists( 'random_bytes' ) ) {
			return bin2hex( random_bytes( $length / 2 ) );
		}

		return wp_generate_password( $length, false, false );
	}

	/**
	 * Validate CSRF token
	 */
	public function validateCsrfToken( $token, $action = 'default' ) {
		$session_token = $this->getSessionToken( $action );

		if ( ! $session_token || ! hash_equals( $session_token, $token ) ) {
			return false;
		}

		// Token is valid, regenerate for next request
		$this->regenerateSessionToken( $action );

		return true;
	}

	/**
	 * Get session token
	 */
	private function getSessionToken( $action ) {
		if ( ! session_id() ) {
			session_start();
		}

		if ( isset( $_SESSION[ 'voxfor_ml_csrf_' . $action ] ) ) {
			return sanitize_text_field( wp_unslash( $_SESSION[ 'voxfor_ml_csrf_' . $action ] ) );
		}
		return null;
	}

	/**
	 * Regenerate session token
	 */
	private function regenerateSessionToken( $action ) {
		if ( ! session_id() ) {
			session_start();
		}

		$_SESSION[ 'voxfor_ml_csrf_' . $action ] = $this->generateToken();

		if ( isset( $_SESSION[ 'voxfor_ml_csrf_' . $action ] ) ) {
			return sanitize_text_field( wp_unslash( $_SESSION[ 'voxfor_ml_csrf_' . $action ] ) );
		}
		return null;
	}

	/**
	 * Verify nonce for specific action
	 * 
	 * Security Note: This method intentionally reads $_POST['nonce'] and $_GET['nonce']
	 * as it IS the security validation handler. The nonce is then validated via wp_verify_nonce().
	 * This is not a security vulnerability - it's the security implementation itself.
	 */
	public function verifyNonce( $action, $nonce = null ) {
		// Check if nonce is provided as parameter, otherwise read from request
		if ( $nonce === null ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
			// Reason: This IS the nonce verification function - we need to read the nonce to verify it
			if ( isset( $_POST['nonce'] ) ) {
				$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
			} elseif ( isset( $_GET['nonce'] ) ) {
				$nonce = sanitize_text_field( wp_unslash( $_GET['nonce'] ) );
			} else {
				$nonce = '';
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		}

		if ( empty( $nonce ) ) {
			$this->logSecurityEvent(
				'nonce_missing',
				array(
					'action'     => $action,
					'ip'         => $this->getClientIP(),
					'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				)
			);
			return false;
		}

		// Verify nonce with action-specific prefix
		$nonce_action = strpos( $action, 'voxfor_ml_' ) === 0 ? $action : 'voxfor_ml_' . $action;

		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$this->logSecurityEvent(
				'nonce_invalid',
				array(
					'action'       => $action,
					'nonce_action' => $nonce_action,
					'ip'           => $this->getClientIP(),
					'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Validate public AJAX request with parameters (uses verifyNonce)
	 */
	public function validatePublicAjaxWithParams( $action, $nonce = null ) {
		return $this->verifyNonce( $action, $nonce );
	}

	/**
	 * Log security event
	 */
	public function logSecurityEvent( $event_type, $details = array() ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'voxfor_ml_security_log';

		// Create table if not exists
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            ip_address varchar(45),
            user_id bigint(20) unsigned,
            details longtext,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event_type (event_type),
            KEY idx_timestamp (timestamp)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Insert log entry
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table_name,
			array(
				'event_type' => $event_type,
				'ip_address' => $this->getClientIp(),
				'user_id'    => get_current_user_id(),
				'details'    => json_encode( $details ),
			)
		);
	}
}
