<?php
namespace VoxforML\Core;

/**
 * Plugin deactivation handler
 */
class Deactivator {
	/**
	 * Deactivate the plugin
	 */
	public static function deactivate() {
		// Clear scheduled events
		wp_clear_scheduled_hook( 'voxfor_ml_process_translation_queue' );
		wp_clear_scheduled_hook( 'voxfor_ml_cleanup_old_translations' );

		// Flush rewrite rules
		flush_rewrite_rules();

		// Clear transients
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_voxfor_ml_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_voxfor_ml_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
