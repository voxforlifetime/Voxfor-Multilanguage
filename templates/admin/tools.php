<?php
/**
 * Tools Page Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap voxfor-ml-admin-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	
	<?php settings_errors( 'voxfor_ml_messages' ); ?>
	
	<div class="voxfor-ml-tools-container">
		<!-- Cache Management -->
		<div class="voxfor-ml-tool-section">
			<h2><?php esc_html_e( 'Cache Management', 'voxfor-multilanguage' ); ?></h2>
			
			<div class="voxfor-ml-tools-grid">
				<div class="voxfor-ml-tool-box">
				<h3><?php esc_html_e( 'Clear Translation Cache', 'voxfor-multilanguage' ); ?></h3>
				<p><?php esc_html_e( 'Remove all cached translations. This will not delete translations from the database.', 'voxfor-multilanguage' ); ?></p>
					<form method="post" action="">
						<?php wp_nonce_field( 'voxfor_ml_clear_cache', 'voxfor_ml_cache_nonce' ); ?>
						<input type="hidden" name="voxfor_ml_clear_cache" value="1" />
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Clear Cache', 'voxfor-multilanguage' ); ?>
						</button>
					</form>
				</div>
				
				<div class="voxfor-ml-tool-box">
				<h3><?php esc_html_e( 'Optimize Database', 'voxfor-multilanguage' ); ?></h3>
				<p><?php esc_html_e( 'Clean up old translation queue items and optimize database tables.', 'voxfor-multilanguage' ); ?></p>
					<form method="post" action="">
						<?php wp_nonce_field( 'voxfor_ml_optimize_db', 'voxfor_ml_optimize_nonce' ); ?>
						<input type="hidden" name="voxfor_ml_optimize_db" value="1" />
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Optimize Database', 'voxfor-multilanguage' ); ?>
						</button>
					</form>
				</div>
				
			</div>
		</div>
		
		<!-- Translation Queue -->
		<div class="voxfor-ml-tool-section">
			<h2><?php esc_html_e( 'Translation Queue', 'voxfor-multilanguage' ); ?></h2>
			
			<div class="voxfor-ml-tools-grid">
				<div class="voxfor-ml-tool-box">
				<h3><?php esc_html_e( 'Process Queue', 'voxfor-multilanguage' ); ?></h3>
				<p><?php esc_html_e( 'Manually process pending translations in the queue.', 'voxfor-multilanguage' ); ?></p>
					<?php
					$memory = new VoxforML\Database\TranslationMemory();
					$stats  = $memory->getStatistics();
					?>
					<p><strong><?php printf( /* translators: %d is the number of pending items in the queue */ esc_html__( 'Pending: %d items', 'voxfor-multilanguage' ), intval( $stats['queue']['pending'] ) ); ?></strong></p>
					<button type="button" class="button button-primary" id="process-queue-btn">
						<?php esc_html_e( 'Process Queue Now', 'voxfor-multilanguage' ); ?>
					</button>
					<div id="queue-progress" style="display: none; margin-top: 10px;">
						<progress id="queue-progress-bar" max="100" value="0" style="width: 100%;"></progress>
						<p id="queue-status"></p>
					</div>
				</div>
				
				<div class="voxfor-ml-tool-box">
				<h3><?php esc_html_e( 'Clear Failed Items', 'voxfor-multilanguage' ); ?></h3>
				<p><?php esc_html_e( 'Remove failed translation attempts from the queue.', 'voxfor-multilanguage' ); ?></p>
					<p><strong><?php printf( /* translators: %d is the number of failed items in the queue */ esc_html__( 'Failed: %d items', 'voxfor-multilanguage' ), intval( $stats['queue']['failed'] ) ); ?></strong></p>
					<form method="post" action="">
						<?php wp_nonce_field( 'voxfor_ml_clear_failed', 'voxfor_ml_failed_nonce' ); ?>
						<input type="hidden" name="voxfor_ml_clear_failed" value="1" />
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Clear Failed Items', 'voxfor-multilanguage' ); ?>
						</button>
					</form>
				</div>
			</div>
		</div>
		
		<!-- Diagnostics -->
		<div class="voxfor-ml-tool-section">
			<h2><?php esc_html_e( 'Diagnostics', 'voxfor-multilanguage' ); ?></h2>
			
			<div class="voxfor-ml-diagnostic-info">
				<h3><?php esc_html_e( 'System Information', 'voxfor-multilanguage' ); ?></h3>
				
				<table class="widefat striped">
					<tbody>
						<tr>
						<th><?php esc_html_e( 'Plugin Version', 'voxfor-multilanguage' ); ?></th>
						<td><?php echo esc_html( VOXFOR_ML_VERSION ); ?></td>
						</tr>
						<tr>
						<th><?php esc_html_e( 'WordPress Version', 'voxfor-multilanguage' ); ?></th>
						<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
						</tr>
					<tr>
						<th><?php esc_html_e( 'PHP Version', 'voxfor-multilanguage' ); ?></th>
						<td><?php echo esc_html( PHP_VERSION ); ?></td>
					</tr>
						<tr>
						<th><?php esc_html_e( 'Database Version', 'voxfor-multilanguage' ); ?></th>
						<td><?php echo esc_html( get_option( 'voxfor_ml_db_version', 'Unknown' ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'API Key Status', 'voxfor-multilanguage' ); ?></th>
							<td>
								<?php
								// Check DeepL API key using EncryptionHandler
								use VoxforML\Utils\EncryptionHandler;
								$api_key = EncryptionHandler::getApiKey();
								if ( empty( $api_key ) ) {
								echo '<span style="color: #dc3232;">' . esc_html__( 'Not configured', 'voxfor-multilanguage' ) . '</span>';
							} else {
								echo '<span style="color: #46b450;">' . esc_html__( 'Configured', 'voxfor-multilanguage' ) . '</span>';
								}
								?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Active Languages', 'voxfor-multilanguage' ); ?></th>
							<td>
								<?php
								$active_langs = get_option( 'voxfor_ml_languages', array( 'en' ) );
								echo esc_html( count( $active_langs ) . ' (' . implode( ', ', array_map( 'strtoupper', $active_langs ) ) . ')' );
								?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Total Translations', 'voxfor-multilanguage' ); ?></th>
							<td><?php echo number_format( $stats['total_translations'] ); ?></td>
						</tr>
						<tr>
						<th><?php esc_html_e( 'Memory Usage', 'voxfor-multilanguage' ); ?></th>
						<td><?php echo esc_html( size_format( memory_get_usage() ) ); ?> / <?php echo esc_html( ini_get( 'memory_limit' ) ); ?></td>
						</tr>
					</tbody>
				</table>
				
				<h3><?php esc_html_e( 'Database Tables', 'voxfor-multilanguage' ); ?></h3>
				<?php
				global $wpdb;
				$tables = array(
					'translations'      => $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translations',
					'glossary'          => $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'glossary',
					'exclusions'        => $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions',
					'translation_queue' => $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translation_queue',
				);
				?>
				<table class="widefat striped">
					<thead>
						<tr>
						<th><?php esc_html_e( 'Table', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Status', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Rows', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Size', 'voxfor-multilanguage' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tables as $name => $table ) : ?>
							<?php
							$exists       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
							$row_count    = $exists ? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `%1s`', $table ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
							$table_status = $exists ? $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ) ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
							?>
							<tr>
								<td><?php echo esc_html( $name ); ?></td>
								<td>
									<?php if ( $exists ) : ?>
									<span style="color: #46b450;">✓ <?php esc_html_e( 'Exists', 'voxfor-multilanguage' ); ?></span>
								<?php else : ?>
									<span style="color: #dc3232;">✗ <?php esc_html_e( 'Missing', 'voxfor-multilanguage' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo number_format( $row_count ); ?></td>
								<td>
									<?php
									if ( $table_status ) {
										echo esc_html( size_format( $table_status->Data_length + $table_status->Index_length ) );
									} else {
										echo '—';
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<?php
// Tools styles are now in public/css/admin/tools.css
// Styles are properly enqueued via wp_enqueue_style
?>

<?php
// Tools JavaScript is now in public/js/admin/tools.js
// Script is properly enqueued via wp_enqueue_script with localization
?>
