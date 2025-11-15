<?php
namespace VoxforML\Database;

/**
 * Manages database operations
 */
class DatabaseManager {
	private $wpdb;
	private $table_prefix;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb         = $wpdb;
		$this->table_prefix = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX;
	}

	/**
	 * Get table name
	 */
	public function getTableName( $table ) {
		return $this->table_prefix . $table;
	}

	/**
	 * Check if tables exist
	 */
	public function tablesExist() {
		$tables = array(
			'translations',
			'glossary',
			'exclusions',
			'translation_queue',
		);

		foreach ( $tables as $table ) {
			$table_name = $this->getTableName( $table );
			$query = $this->wpdb->prepare( "SHOW TABLES LIKE %s", $table_name );
			if ( $this->wpdb->get_var( $query ) !== $table_name ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return false;
			}
		}

		return true;
	}

	/**
	 * Get database version
	 */
	public function getVersion() {
		return get_option( 'voxfor_ml_db_version', '0' );
	}

	/**
	 * Update database if needed
	 */
	public function maybeUpdate() {
		$current_version = $this->getVersion();

		if ( version_compare( $current_version, VOXFOR_ML_VERSION, '<' ) ) {
			// Run updates
			require_once VOXFOR_ML_PLUGIN_DIR . 'includes/Core/Activator.php';
			\VoxforML\Core\Activator::activate();
		}
	}
}
