<?php
/**
 * Diagnostics & UTF-8 Health Check Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Run diagnostics
$diagnostics = new \VoxforML\Utils\DiagnosticsManager();
$results     = $diagnostics->runFullDiagnostics();
?>

<div class="wrap">
	<h1><?php esc_html_e( 'System Diagnostics & UTF-8 Health Check', 'voxfor-multilanguage' ); ?></h1>
	
	<div class="notice notice-info">
		<p>
		<strong><?php esc_html_e( 'System Health Check', 'voxfor-multilanguage' ); ?></strong><br>
		<?php esc_html_e( 'This tool checks your WordPress installation for optimal multilingual support, including UTF-8 compatibility, database collation, and system requirements.', 'voxfor-multilanguage' ); ?>
		</p>
	</div>
	
	<!-- Overall Status -->
	<div class="voxfor-ml-diagnostics-overview">
		<div class="voxfor-ml-status-cards">
			<div class="status-card <?php echo esc_attr( $results['overall_status'] ); ?>">
				<div class="status-icon">
					<?php if ( $results['overall_status'] === 'success' ) : ?>
						<span class="dashicons dashicons-yes-alt"></span>
					<?php elseif ( $results['overall_status'] === 'warning' ) : ?>
						<span class="dashicons dashicons-warning"></span>
					<?php else : ?>
						<span class="dashicons dashicons-dismiss"></span>
					<?php endif; ?>
				</div>
				<div class="status-content">
				<h3><?php esc_html_e( 'Overall System Health', 'voxfor-multilanguage' ); ?></h3>
				<p><?php echo esc_html( $results['overall_message'] ); ?></p>
				</div>
			</div>
		</div>
	</div>
	
	<!-- Database Diagnostics -->
	<div class="voxfor-ml-diagnostic-section">
		<h2><?php esc_html_e( 'Database Configuration', 'voxfor-multilanguage' ); ?></h2>
		
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
				<th><?php esc_html_e( 'Check', 'voxfor-multilanguage' ); ?></th>
				<th><?php esc_html_e( 'Status', 'voxfor-multilanguage' ); ?></th>
				<th><?php esc_html_e( 'Current', 'voxfor-multilanguage' ); ?></th>
				<th><?php esc_html_e( 'Recommended', 'voxfor-multilanguage' ); ?></th>
				<th><?php esc_html_e( 'Action', 'voxfor-multilanguage' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results['database_checks'] as $check ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $check['name'] ); ?></strong></td>
						<td>
							<span class="status-badge status-<?php echo esc_attr( $check['status'] ); ?>">
								<?php if ( $check['status'] === 'pass' ) : ?>
									<span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Pass', 'voxfor-multilanguage' ); ?>
								<?php elseif ( $check['status'] === 'warning' ) : ?>
									<span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Warning', 'voxfor-multilanguage' ); ?>
								<?php else : ?>
									<span class="dashicons dashicons-dismiss"></span> <?php esc_html_e( 'Fail', 'voxfor-multilanguage' ); ?>
								<?php endif; ?>
							</span>
						</td>
						<td><code><?php echo esc_html( $check['current'] ); ?></code></td>
						<td><code><?php echo esc_html( $check['recommended'] ); ?></code></td>
						<td>
							<?php if ( ! empty( $check['action'] ) ) : ?>
								<button type="button" class="button button-small voxfor-ml-fix-issue" 
										data-check="<?php echo esc_attr( $check['key'] ); ?>">
									<?php echo esc_html( $check['action'] ); ?>
								</button>
							<?php else : ?>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	
	<!-- Table Status -->
	<div class="voxfor-ml-diagnostic-section">
		<h2><?php esc_html_e( 'Database Tables', 'voxfor-multilanguage' ); ?></h2>
		
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
				<th><?php esc_html_e( 'Table', 'voxfor-multilanguage' ); ?></th>
				<th><?php esc_html_e( 'Status', 'voxfor-multilanguage' ); ?></th>
				<th><?php esc_html_e( 'Collation', 'voxfor-multilanguage' ); ?></th>
				<th><?php esc_html_e( 'Rows', 'voxfor-multilanguage' ); ?></th>
				<th><?php esc_html_e( 'Size', 'voxfor-multilanguage' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results['table_checks'] as $check ) : ?>
					<tr>
						<td><code><?php echo esc_html( $check['name'] ); ?></code></td>
						<td>
							<span class="status-badge status-<?php echo esc_attr( $check['status'] ); ?>">
								<?php if ( $check['status'] === 'pass' ) : ?>
									<span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'OK', 'voxfor-multilanguage' ); ?>
								<?php elseif ( $check['status'] === 'warning' ) : ?>
									<span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Warning', 'voxfor-multilanguage' ); ?>
								<?php else : ?>
									<span class="dashicons dashicons-dismiss"></span> <?php esc_html_e( 'Missing', 'voxfor-multilanguage' ); ?>
								<?php endif; ?>
							</span>
						</td>
						<td><?php echo esc_html( $check['collation'] ); ?></td>
						<td><?php echo esc_html( number_format( $check['rows'] ) ); ?></td>
						<td><?php echo esc_html( $check['size'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	
	<!-- System Requirements -->
	<div class="voxfor-ml-diagnostic-section">
		<h2><?php esc_html_e( 'System Requirements', 'voxfor-multilanguage' ); ?></h2>
		
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
				<th><?php esc_html_e( 'Requirement', 'voxfor-multilanguage' ); ?></th>
				<th><?php esc_html_e( 'Status', 'voxfor-multilanguage' ); ?></th>
				<th><?php esc_html_e( 'Current', 'voxfor-multilanguage' ); ?></th>
				<th><?php esc_html_e( 'Required', 'voxfor-multilanguage' ); ?></th>
				<th><?php esc_html_e( 'Notes', 'voxfor-multilanguage' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results['system_checks'] as $check ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $check['name'] ); ?></strong></td>
						<td>
							<span class="status-badge status-<?php echo esc_attr( $check['status'] ); ?>">
								<?php if ( $check['status'] === 'pass' ) : ?>
									<span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Pass', 'voxfor-multilanguage' ); ?>
								<?php elseif ( $check['status'] === 'warning' ) : ?>
									<span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Warning', 'voxfor-multilanguage' ); ?>
								<?php else : ?>
									<span class="dashicons dashicons-dismiss"></span> <?php esc_html_e( 'Fail', 'voxfor-multilanguage' ); ?>
								<?php endif; ?>
							</span>
						</td>
						<td><code><?php echo esc_html( $check['current'] ); ?></code></td>
						<td><code><?php echo esc_html( $check['required'] ); ?></code></td>
						<td><?php echo esc_html( $check['notes'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	
	<!-- Recommendations -->
	<?php if ( ! empty( $results['recommendations'] ) ) : ?>
		<div class="voxfor-ml-diagnostic-section">
			<h2><?php esc_html_e( 'Recommendations', 'voxfor-multilanguage' ); ?></h2>
			
			<div class="voxfor-ml-recommendations">
				<?php foreach ( $results['recommendations'] as $rec ) : ?>
				<div class="notice notice-<?php echo esc_attr( $rec['type'] ); ?> inline">
					<p><strong><?php echo esc_html( $rec['title'] ); ?>:</strong> <?php echo esc_html( $rec['message'] ); ?></p>
						<?php if ( ! empty( $rec['action'] ) ) : ?>
							<p>
								<button type="button" class="button button-primary voxfor-ml-apply-recommendation" 
										data-action="<?php echo esc_attr( $rec['action'] ); ?>">
									<?php echo esc_html( $rec['action_label'] ); ?>
								</button>
							</p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>
</div>

<?php
// JavaScript is now in separate file and properly enqueued
// Script is properly enqueued via wp_enqueue_script with localization
?>

<?php
// Styles are now in separate CSS file and properly enqueued
// Styles are properly enqueued via wp_enqueue_style
?>
