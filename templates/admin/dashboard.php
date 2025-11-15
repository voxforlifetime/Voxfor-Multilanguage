<?php
/**
 * Admin Dashboard Template
 *
 * @var array $stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get additional statistics
$statistics_manager   = new VoxforML\Analytics\StatisticsManager();
$global_stats         = $statistics_manager->getGlobalStats();
$chart_data           = $statistics_manager->getChartData( 'last_30_days' );
$content_distribution = $statistics_manager->getContentTypeDistribution();
$estimated_costs      = $statistics_manager->getEstimatedCosts();

// Get bulk translation jobs
$bulk_manager = new VoxforML\Translator\BulkTranslationManager();
$recent_jobs  = array_slice( $bulk_manager->getAllJobs(), 0, 5 );

// Get cache configuration
$cache_manager = new VoxforML\Utils\CacheCompatibility();
$cache_config  = $cache_manager->getCacheConfiguration();
?>

<div class="wrap voxfor-ml-admin-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	
	<?php if ( isset( $_GET['cache_cleared'] ) && $_GET['cache_cleared'] == '1' ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><strong><?php esc_html_e( 'Translation cache cleared successfully!', 'voxfor-multilanguage' ); ?></strong> <?php esc_html_e( 'All cached translations have been cleared. Refresh your pages to see updated translations.', 'voxfor-multilanguage' ); ?></p>
		</div>
	<?php endif; ?>
	
	<div class="voxfor-ml-dashboard">
		<!-- Quick Stats -->
		<div class="voxfor-ml-stats-grid">
			<div class="voxfor-ml-stat-box">
				<h3><?php esc_html_e( 'Total Translations', 'voxfor-multilanguage' ); ?></h3>
				<div class="voxfor-ml-stat-number"><?php echo number_format( $stats['total_translations'] ); ?></div>
			</div>
			
			<div class="voxfor-ml-stat-box">
				<h3><?php esc_html_e( 'Active Languages', 'voxfor-multilanguage' ); ?></h3>
				<div class="voxfor-ml-stat-number"><?php echo count( $stats['by_language'] ); ?></div>
			</div>
			
			<div class="voxfor-ml-stat-box">
				<h3><?php esc_html_e( 'Pending Translations', 'voxfor-multilanguage' ); ?></h3>
				<div class="voxfor-ml-stat-number"><?php echo number_format( $stats['queue']['pending'] ); ?></div>
			</div>
			
			<div class="voxfor-ml-stat-box">
				<h3><?php esc_html_e( 'Locked Translations', 'voxfor-multilanguage' ); ?></h3>
				<div class="voxfor-ml-stat-number"><?php echo number_format( $stats['locked_translations'] ); ?></div>
			</div>
		</div>
		
		<!-- Cache Management -->
		<div class="voxfor-ml-dashboard-section" style="margin-bottom: 20px;">
			<h2><?php esc_html_e( 'Cache Management', 'voxfor-multilanguage' ); ?></h2>
			<div class="voxfor-ml-admin-notice">
				<p>
					<strong><?php esc_html_e( 'Clear Translation Cache:', 'voxfor-multilanguage' ); ?></strong>
					<?php esc_html_e( 'If translations are not appearing correctly after updates, clear the cache to force fresh lookups.', 'voxfor-multilanguage' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
					<input type="hidden" name="action" value="voxfor_ml_clear_cache">
					<?php wp_nonce_field( 'voxfor_ml_clear_cache', '_wpnonce' ); ?>
					<button type="submit" class="button button-secondary" onclick="return confirm('<?php esc_js( esc_html__( 'Are you sure you want to clear all translation caches?', 'voxfor-multilanguage' ) ); ?>')">
						<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>
						<?php esc_html_e( 'Clear Translation Cache', 'voxfor-multilanguage' ); ?>
					</button>
				</form>
			</div>
		</div>
		
		<!-- Translation Status by Language -->
		<div class="voxfor-ml-dashboard-section">
		<h2><?php esc_html_e( 'Translations by Language', 'voxfor-multilanguage' ); ?></h2>
		
		<div class="voxfor-ml-language-stats">
				<?php if ( ! empty( $stats['by_language'] ) ) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
							<th><?php esc_html_e( 'Language', 'voxfor-multilanguage' ); ?></th>
							<th><?php esc_html_e( 'Translations', 'voxfor-multilanguage' ); ?></th>
							<th><?php esc_html_e( 'Percentage', 'voxfor-multilanguage' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $stats['by_language'] as $lang_stat ) : ?>
								<tr>
									<td>
										<?php
										$language_names = array(
											'en' => 'English',
											'fr' => 'French',
											'de' => 'German',
											'es' => 'Spanish',
											'it' => 'Italian',
											'pt' => 'Portuguese',
											'ru' => 'Russian',
											'ja' => 'Japanese',
											'zh' => 'Chinese',
											'ko' => 'Korean',
											'ar' => 'Arabic',
											'he' => 'Hebrew',
											'sv' => 'Swedish',
											'no' => 'Norwegian',
											'da' => 'Danish',
											'fi' => 'Finnish',
											'nl' => 'Dutch',
											'pl' => 'Polish',
											'tr' => 'Turkish',
											'cs' => 'Czech',
											'hu' => 'Hungarian',
											'ro' => 'Romanian',
											'el' => 'Greek',
											'th' => 'Thai',
											'vi' => 'Vietnamese',
											'id' => 'Indonesian',
											'ms' => 'Malay',
											'hi' => 'Hindi',
											'bn' => 'Bengali',
											'uk' => 'Ukrainian',
										);
										echo esc_html( $language_names[ $lang_stat['language_code'] ] ?? $lang_stat['language_code'] );
										?>
									</td>
									<td><?php echo number_format( $lang_stat['count'] ); ?></td>
									<td>
										<?php
										$percentage = $stats['total_translations'] > 0 ?
											round( ( $lang_stat['count'] / $stats['total_translations'] ) * 100, 1 ) : 0;
										?>
										<div class="voxfor-ml-progress-bar">
								<div class="voxfor-ml-progress-fill" style="width: <?php echo esc_attr( $percentage ); ?>%"></div>
								<span><?php echo esc_html( $percentage ); ?>%</span>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><?php esc_html_e( 'No translations yet.', 'voxfor-multilanguage' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		
		<!-- Queue Status -->
		<div class="voxfor-ml-dashboard-section">
			<h2><?php esc_html_e( 'Translation Queue Status', 'voxfor-multilanguage' ); ?></h2>
			<div class="voxfor-ml-queue-stats">
				<div class="voxfor-ml-queue-item">
					<span class="voxfor-ml-queue-label"><?php esc_html_e( 'Pending', 'voxfor-multilanguage' ); ?>:</span>
					<span class="voxfor-ml-queue-count"><?php echo number_format( $stats['queue']['pending'] ); ?></span>
				</div>
				<div class="voxfor-ml-queue-item">
					<span class="voxfor-ml-queue-label"><?php esc_html_e( 'Processing', 'voxfor-multilanguage' ); ?>:</span>
					<span class="voxfor-ml-queue-count"><?php echo number_format( $stats['queue']['processing'] ); ?></span>
				</div>
				<div class="voxfor-ml-queue-item">
					<span class="voxfor-ml-queue-label"><?php esc_html_e( 'Completed', 'voxfor-multilanguage' ); ?>:</span>
					<span class="voxfor-ml-queue-count"><?php echo number_format( $stats['queue']['completed'] ); ?></span>
				</div>
				<div class="voxfor-ml-queue-item">
					<span class="voxfor-ml-queue-label"><?php esc_html_e( 'Failed', 'voxfor-multilanguage' ); ?>:</span>
					<span class="voxfor-ml-queue-count"><?php echo number_format( $stats['queue']['failed'] ); ?></span>
				</div>
			</div>
		</div>
		
		<!-- Quick Actions -->
		<div class="voxfor-ml-dashboard-section">
			<h2><?php esc_html_e( 'Quick Actions', 'voxfor-multilanguage' ); ?></h2>
			<div class="voxfor-ml-quick-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=voxfor-ml-settings' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Configure Settings', 'voxfor-multilanguage' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=voxfor-ml-memory' ) ); ?>" class="button">
					<?php esc_html_e( 'View Translation Memory', 'voxfor-multilanguage' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=voxfor-ml-glossary' ) ); ?>" class="button">
					<?php esc_html_e( 'Manage Glossary', 'voxfor-multilanguage' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=voxfor-ml-tools' ) ); ?>" class="button">
					<?php esc_html_e( 'Tools', 'voxfor-multilanguage' ); ?>
				</a>
			</div>
		</div>
		
		<!-- SEO Status -->
		<div class="voxfor-ml-dashboard-section">
			<h2><?php esc_html_e( 'SEO Features Status', 'voxfor-multilanguage' ); ?></h2>
			<div class="voxfor-ml-seo-status">
				<ul class="voxfor-ml-feature-list">
					<li class="<?php echo get_option( 'voxfor_ml_enable_hreflang', true ) ? 'enabled' : 'disabled'; ?>">
						<span class="dashicons <?php echo get_option( 'voxfor_ml_enable_hreflang', true ) ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
						<?php esc_html_e( 'Hreflang Tags', 'voxfor-multilanguage' ); ?>
					</li>
					<li class="<?php echo get_option( 'voxfor_ml_translate_image_alt', true ) ? 'enabled' : 'disabled'; ?>">
						<span class="dashicons <?php echo get_option( 'voxfor_ml_translate_image_alt', true ) ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
						<?php esc_html_e( 'Image ALT Text Translation', 'voxfor-multilanguage' ); ?>
					</li>
					<li class="<?php echo get_option( 'voxfor_ml_translate_slugs', false ) ? 'enabled' : 'disabled'; ?>">
						<span class="dashicons <?php echo get_option( 'voxfor_ml_translate_slugs', false ) ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
						<?php esc_html_e( 'Translated URL Slugs', 'voxfor-multilanguage' ); ?>
					</li>
					<li class="enabled">
						<span class="dashicons dashicons-yes"></span>
						<?php esc_html_e( 'Language-specific Canonical URLs', 'voxfor-multilanguage' ); ?>
					</li>
					<li class="enabled">
						<span class="dashicons dashicons-yes"></span>
						<?php esc_html_e( 'Structured Data Support', 'voxfor-multilanguage' ); ?>
					</li>
				</ul>
			</div>
		</div>
		
		<!-- Translation Activity Chart -->
		<div class="voxfor-ml-dashboard-section">
			<h2><?php esc_html_e( 'Translation Activity (Last 30 Days)', 'voxfor-multilanguage' ); ?></h2>
			<div class="voxfor-ml-chart-container">
				<canvas id="voxfor-ml-activity-chart" width="400" height="200"></canvas>
			</div>
		</div>
		
		

		
		<!-- Recent Bulk Jobs -->
		<?php if ( isset( $recent_jobs ) && ! empty( $recent_jobs ) ) : ?>
		<div class="voxfor-ml-dashboard-section">
			<h2><?php esc_html_e( 'Recent Bulk Translation Jobs', 'voxfor-multilanguage' ); ?></h2>
			<table class="wp-list-table widefat">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Job ID', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Status', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Progress', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Languages', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Started', 'voxfor-multilanguage' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent_jobs as $job ) : ?>
						<?php $job_status = $bulk_manager->getJobStatus( $job['id'] ); ?>
						<tr>
							<td><?php echo esc_html( $job['id'] ); ?></td>
							<td>
								<span class="voxfor-ml-status <?php echo esc_attr( $job['status'] ); ?>">
									<?php echo esc_html( ucfirst( $job['status'] ) ); ?>
								</span>
							</td>
							<td>
								<div class="voxfor-ml-mini-progress">
									<div class="voxfor-ml-mini-progress-fill" style="width: <?php echo esc_attr( $job_status['progress'] ); ?>%"></div>
									<span><?php echo esc_html( $job_status['progress'] ); ?>%</span>
								</div>
							</td>
							<td><?php echo esc_html( implode( ', ', array_map( 'strtoupper', $job['languages'] ) ) ); ?></td>
							<td><?php echo esc_html( human_time_diff( strtotime( $job['started_at'] ), current_time( 'timestamp' ) ) . ' ago' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
		
	</div>
</div>

<?php
// Chart.js is now enqueued via wp_enqueue_script in the dashboard assets
// Dashboard JavaScript is now in public/js/admin/dashboard.js
// Data is passed via wp_localize_script as voxforDashboard object
?>

<?php
// Dashboard CSS is now in public/css/admin/dashboard.css
// Styles are properly enqueued via wp_enqueue_style
?>

<?php
// Cleanup functionality is now in public/js/admin/dashboard.js
// Strings and nonce are passed via wp_localize_script as voxforDashboard object
?>