<?php
namespace VoxforML\Utils;

/**
 * System Diagnostics Manager
 */
class DiagnosticsManager {

	/**
	 * Run full system diagnostics
	 */
	public function runFullDiagnostics() {
		$results = array(
			'database_checks' => $this->runDatabaseChecks(),
			'table_checks'    => $this->runTableChecks(),
			'system_checks'   => $this->runSystemChecks(),
			'recommendations' => array(),
		);

		// Determine overall status
		$results['overall_status']  = $this->determineOverallStatus( $results );
		$results['overall_message'] = $this->getOverallMessage( $results['overall_status'] );

		// Generate recommendations
		$results['recommendations'] = $this->generateRecommendations( $results );

		return $results;
	}

	/**
	 * Run database checks
	 */
	private function runDatabaseChecks() {
		global $wpdb;

		$checks = array();

		// Database charset
		$db_charset = $wpdb->get_var( 'SELECT @@character_set_database' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$checks[]   = array(
			'key'         => 'db_charset',
			'name'        => __( 'Database Character Set', 'voxfor-multilanguage' ),
			'status'      => $this->isUtf8Charset( $db_charset ) ? 'pass' : 'warning',
			'current'     => $db_charset,
			'recommended' => 'utf8mb4',
			'action'      => $this->isUtf8Charset( $db_charset ) ? '' : __( 'Convert to UTF8MB4', 'voxfor-multilanguage' ),
		);

		// Database collation
		$db_collation = $wpdb->get_var( 'SELECT @@collation_database' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$checks[]     = array(
			'key'         => 'db_collation',
			'name'        => __( 'Database Collation', 'voxfor-multilanguage' ),
			'status'      => $this->isUtf8Collation( $db_collation ) ? 'pass' : 'warning',
			'current'     => $db_collation,
			'recommended' => 'utf8mb4_unicode_ci',
			'action'      => $this->isUtf8Collation( $db_collation ) ? '' : __( 'Update Collation', 'voxfor-multilanguage' ),
		);

		// WordPress charset
		$wp_charset = defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8';
		$checks[]   = array(
			'key'         => 'wp_charset',
			'name'        => __( 'WordPress Character Set', 'voxfor-multilanguage' ),
			'status'      => $this->isUtf8Charset( $wp_charset ) ? 'pass' : 'warning',
			'current'     => $wp_charset,
			'recommended' => 'utf8mb4',
			'action'      => $this->isUtf8Charset( $wp_charset ) ? '' : __( 'Update wp-config.php', 'voxfor-multilanguage' ),
		);

		// WordPress collation
		$wp_collation = defined( 'DB_COLLATE' ) ? DB_COLLATE : '';
		if ( empty( $wp_collation ) ) {
			$wp_collation = 'Default';
		}
		$checks[] = array(
			'key'         => 'wp_collation',
			'name'        => __( 'WordPress Collation', 'voxfor-multilanguage' ),
			'status'      => ( empty( DB_COLLATE ) || $this->isUtf8Collation( $wp_collation ) ) ? 'pass' : 'warning',
			'current'     => $wp_collation,
			'recommended' => 'utf8mb4_unicode_ci',
			'action'      => ( empty( DB_COLLATE ) || $this->isUtf8Collation( $wp_collation ) ) ? '' : __( 'Update wp-config.php', 'voxfor-multilanguage' ),
		);

		return $checks;
	}

	/**
	 * Run table checks
	 */
	private function runTableChecks() {
		global $wpdb;

		$tables = array(
			'voxfor_ml_translations',
			'voxfor_ml_translation_queue',
			'voxfor_ml_task_log',
			'voxfor_ml_update_log',
			'voxfor_ml_security_log',
			'voxfor_ml_statistics',
			'voxfor_ml_glossary',
			'voxfor_ml_exclusions',
			'voxfor_ml_slugs',
			'voxfor_ml_taxonomy_slugs',
		);

		$checks = array();

		foreach ( $tables as $table ) {
			$full_table_name = $wpdb->prefix . $table;

			// Check if table exists
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! $exists ) {
				$checks[] = array(
					'key'       => $table,
					'name'      => $table,
					'status'    => 'fail',
					'collation' => 'N/A',
					'rows'      => 0,
					'size'      => 'N/A',
				);
				continue;
			}

			// Get table info
			$table_info = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"
                SELECT 
                    TABLE_COLLATION,
                    TABLE_ROWS,
                    ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = %s
            ",
					$full_table_name
				)
			);

			$collation_ok = $this->isUtf8Collation( $table_info->TABLE_COLLATION ?? '' );

			$checks[] = array(
				'key'       => $table,
				'name'      => $table,
				'status'    => $collation_ok ? 'pass' : 'warning',
				'collation' => $table_info->TABLE_COLLATION ?? 'Unknown',
				'rows'      => intval( $table_info->TABLE_ROWS ?? 0 ),
				'size'      => ( $table_info->size_mb ?? 0 ) . ' MB',
			);
		}

		return $checks;
	}

	/**
	 * Run system checks
	 */
	private function runSystemChecks() {
		$checks = array();

		// PHP version
		$php_version = PHP_VERSION;
		$php_ok      = version_compare( $php_version, '8.1', '>=' );
		$checks[]    = array(
			'name'     => __( 'PHP Version', 'voxfor-multilanguage' ),
			'status'   => $php_ok ? 'pass' : 'warning',
			'current'  => $php_version,
			'required' => '8.1+',
			'notes'    => $php_ok ? __( 'Good', 'voxfor-multilanguage' ) : __( 'Consider upgrading', 'voxfor-multilanguage' ),
		);

		// WordPress version
		$wp_version = get_bloginfo( 'version' );
		$wp_ok      = version_compare( $wp_version, '6.5', '>=' );
		$checks[]   = array(
			'name'     => __( 'WordPress Version', 'voxfor-multilanguage' ),
			'status'   => $wp_ok ? 'pass' : 'warning',
			'current'  => $wp_version,
			'required' => '6.5+',
			'notes'    => $wp_ok ? __( 'Good', 'voxfor-multilanguage' ) : __( 'Consider upgrading', 'voxfor-multilanguage' ),
		);

		// Memory limit
		$memory_limit = ini_get( 'memory_limit' );
		$memory_bytes = $this->parseMemoryLimit( $memory_limit );
		$memory_ok    = $memory_bytes >= 256 * 1024 * 1024; // 256MB
		$checks[]     = array(
			'name'     => __( 'PHP Memory Limit', 'voxfor-multilanguage' ),
			'status'   => $memory_ok ? 'pass' : 'warning',
			'current'  => $memory_limit,
			'required' => '256M+',
			'notes'    => $memory_ok ? __( 'Sufficient', 'voxfor-multilanguage' ) : __( 'May need increase for large sites', 'voxfor-multilanguage' ),
		);

		// cURL support
		$curl_ok  = function_exists( 'curl_init' );
		$checks[] = array(
			'name'     => __( 'cURL Support', 'voxfor-multilanguage' ),
			'status'   => $curl_ok ? 'pass' : 'fail',
			'current'  => $curl_ok ? __( 'Enabled', 'voxfor-multilanguage' ) : __( 'Disabled', 'voxfor-multilanguage' ),
			'required' => __( 'Enabled', 'voxfor-multilanguage' ),
			'notes'    => $curl_ok ? __( 'Required for API calls', 'voxfor-multilanguage' ) : __( 'Required for API calls', 'voxfor-multilanguage' ),
		);

		// Multibyte string support
		$mbstring_ok = extension_loaded( 'mbstring' );
		$checks[]    = array(
			'name'     => __( 'Multibyte String Support', 'voxfor-multilanguage' ),
			'status'   => $mbstring_ok ? 'pass' : 'warning',
			'current'  => $mbstring_ok ? __( 'Enabled', 'voxfor-multilanguage' ) : __( 'Disabled', 'voxfor-multilanguage' ),
			'required' => __( 'Enabled', 'voxfor-multilanguage' ),
			'notes'    => $mbstring_ok ? __( 'Good for multilingual content', 'voxfor-multilanguage' ) : __( 'Recommended for multilingual content', 'voxfor-multilanguage' ),
		);

		// Intl extension
		$intl_ok  = extension_loaded( 'intl' );
		$checks[] = array(
			'name'     => __( 'Internationalization Support', 'voxfor-multilanguage' ),
			'status'   => $intl_ok ? 'pass' : 'warning',
			'current'  => $intl_ok ? __( 'Enabled', 'voxfor-multilanguage' ) : __( 'Disabled', 'voxfor-multilanguage' ),
			'required' => __( 'Recommended', 'voxfor-multilanguage' ),
			'notes'    => $intl_ok ? __( 'Excellent for i18n', 'voxfor-multilanguage' ) : __( 'Helpful for advanced i18n features', 'voxfor-multilanguage' ),
		);

		return $checks;
	}

	/**
	 * Determine overall status
	 */
	private function determineOverallStatus( $results ) {
		$has_fail    = false;
		$has_warning = false;

		foreach ( array( 'database_checks', 'table_checks', 'system_checks' ) as $check_type ) {
			if ( ! isset( $results[ $check_type ] ) ) {
				continue;
			}

			foreach ( $results[ $check_type ] as $check ) {
				$status = $check['status'] ?? 'pass';
				if ( $status === 'fail' ) {
					$has_fail = true;
				} elseif ( $status === 'warning' ) {
					$has_warning = true;
				}
			}
		}

		if ( $has_fail ) {
			return 'error';
		} elseif ( $has_warning ) {
			return 'warning';
		} else {
			return 'success';
		}
	}

	/**
	 * Get overall message
	 */
	private function getOverallMessage( $status ) {
		switch ( $status ) {
			case 'success':
				return __( 'Your system is optimally configured for multilingual content.', 'voxfor-multilanguage' );
			case 'warning':
				return __( 'Your system works but has some recommendations for optimal multilingual support.', 'voxfor-multilanguage' );
			case 'error':
				return __( 'Your system has critical issues that may affect multilingual functionality.', 'voxfor-multilanguage' );
			default:
				return __( 'System status unknown.', 'voxfor-multilanguage' );
		}
	}

	/**
	 * Generate recommendations
	 */
	private function generateRecommendations( $results ) {
		$recommendations = array();

		// Check for UTF8MB4 upgrade
		$needs_utf8mb4 = false;
		foreach ( $results['database_checks'] as $check ) {
			if ( in_array( $check['key'], array( 'db_charset', 'db_collation', 'wp_charset' ) ) && $check['status'] !== 'pass' ) {
				$needs_utf8mb4 = true;
				break;
			}
		}

		if ( $needs_utf8mb4 ) {
			$recommendations[] = array(
				'type'         => 'warning',
				'title'        => __( 'UTF8MB4 Upgrade Recommended', 'voxfor-multilanguage' ),
				'message'      => __( 'Your database should be upgraded to UTF8MB4 for full emoji and multilingual character support.', 'voxfor-multilanguage' ),
				'action'       => 'upgrade_utf8mb4',
				'action_label' => __( 'Upgrade to UTF8MB4', 'voxfor-multilanguage' ),
			);
		}

		// Check for missing tables
		$missing_tables = array();
		foreach ( $results['table_checks'] as $check ) {
			if ( $check['status'] === 'fail' ) {
				$missing_tables[] = $check['name'];
			}
		}

		if ( ! empty( $missing_tables ) ) {
			$recommendations[] = array(
				'type'         => 'error',
				'title'        => __( 'Missing Database Tables', 'voxfor-multilanguage' ),
				'message'      => sprintf(
					/* translators: %s: list of missing tables */
					__( 'The following tables are missing: %s. Try deactivating and reactivating the plugin.', 'voxfor-multilanguage' ),
					implode( ', ', $missing_tables )
				),
				'action'       => 'recreate_tables',
				'action_label' => __( 'Recreate Tables', 'voxfor-multilanguage' ),
			);
		}

		return $recommendations;
	}

	/**
	 * Check if charset is UTF-8 compatible
	 */
	private function isUtf8Charset( $charset ) {
		return in_array( strtolower( $charset ), array( 'utf8', 'utf8mb4' ) );
	}

	/**
	 * Check if collation is UTF-8 compatible
	 */
	private function isUtf8Collation( $collation ) {
		return strpos( strtolower( $collation ), 'utf8' ) === 0;
	}

	/**
	 * Parse memory limit string to bytes
	 */
	private function parseMemoryLimit( $limit ) {
		$limit = trim( $limit );
		$last  = strtolower( $limit[ strlen( $limit ) - 1 ] );
		$limit = (int) $limit;

		switch ( $last ) {
			case 'g':
				$limit *= 1024;
				// Fall through.
			case 'm':
				$limit *= 1024;
				// Fall through.
			case 'k':
				$limit *= 1024;
		}

		return $limit;
	}
}
