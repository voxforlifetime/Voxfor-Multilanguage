<?php
/**
 * Bulk Translation Page Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bulk_manager        = new VoxforML\Translator\BulkTranslationManager();
$all_jobs            = $bulk_manager->getAllJobs();
$languages           = get_option( 'voxfor_ml_languages', array() );
$default_lang        = VoxforML\Core\Plugin::getInstance()->getDefaultLanguage();
$available_languages = array_diff( $languages, array( $default_lang ) );
?>

<div class="wrap voxfor-ml-admin-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	
	<?php settings_errors( 'voxfor_ml_messages' ); ?>
	
	<div class="voxfor-ml-bulk-translation-container">
		<!-- Start New Bulk Translation -->
		<div class="voxfor-ml-bulk-section">
			<h2><?php esc_html_e( 'Start New Bulk Translation', 'voxfor-multilanguage' ); ?></h2>
			
			<form id="voxfor-ml-bulk-translation-form" method="post" action="">
				<?php wp_nonce_field( 'voxfor_ml_bulk_translation', 'voxfor_ml_bulk_nonce' ); ?>
				
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="target_languages"><?php esc_html_e( 'Target Languages', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<fieldset>
								<?php
								$language_names = array(
									'en' => 'English',
									'he' => 'Hebrew',
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

								foreach ( $available_languages as $lang ) :
									$lang_name = isset( $language_names[ $lang ] ) ? $language_names[ $lang ] : $lang;
									?>
									<label>
										<input type="checkbox" name="languages[]" value="<?php echo esc_attr( $lang ); ?>" checked />
										<?php echo esc_html( $lang_name ); ?>
									</label><br>
								<?php endforeach; ?>
							</fieldset>
							<p class="description">
								<?php esc_html_e( 'Select which languages to translate content into.', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="post_types"><?php esc_html_e( 'Content Types', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<fieldset>
								<?php
								$post_types = get_post_types( array( 'public' => true ), 'objects' );
								foreach ( $post_types as $post_type ) :
									if ( $post_type->name === 'attachment' ) {
										continue;
									}
									?>
									<label>
										<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $post_type->name ); ?>" 
												<?php checked( in_array( $post_type->name, array( 'post', 'page' ) ) ); ?> />
										<?php echo esc_html( $post_type->label ); ?>
									</label><br>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="post_status"><?php esc_html_e( 'Post Status', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<select name="post_status[]" id="post_status" multiple>
						<option value="publish" selected><?php esc_html_e( 'Published', 'voxfor-multilanguage' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Draft', 'voxfor-multilanguage' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Pending', 'voxfor-multilanguage' ); ?></option>
						<option value="private"><?php esc_html_e( 'Private', 'voxfor-multilanguage' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Hold Ctrl/Cmd to select multiple statuses.', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Options', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" name="translate_existing" value="1" />
								<?php esc_html_e( 'Re-translate existing translations', 'voxfor-multilanguage' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'If checked, will overwrite existing translations (except locked ones).', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="items_per_batch"><?php esc_html_e( 'Translation Speed', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<select name="items_per_batch" id="items_per_batch">
						<option value="1" selected><?php esc_html_e( '1 page per minute (Recommended)', 'voxfor-multilanguage' ); ?></option>
						<option value="2"><?php esc_html_e( '2 pages per minute', 'voxfor-multilanguage' ); ?></option>
						<option value="5"><?php esc_html_e( '5 pages per minute (Fast)', 'voxfor-multilanguage' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Slower speeds are more reliable and less likely to hit rate limits.', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
				</table>
				
				<div class="voxfor-ml-bulk-actions">
					<button type="button" id="voxfor-ml-estimate-bulk" class="button button-secondary">
						<?php esc_html_e( 'Estimate Time & Cost', 'voxfor-multilanguage' ); ?>
					</button>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Start Bulk Translation', 'voxfor-multilanguage' ); ?>
					</button>
				</div>
				
				<div id="voxfor-ml-bulk-estimate" style="display: none; margin-top: 20px;">
					<div class="notice notice-info">
						<p id="voxfor-ml-estimate-text"></p>
					</div>
				</div>
			</form>
		</div>
		
		<!-- Active Jobs -->
		<?php
		$active_jobs = array_filter(
			$all_jobs,
			function ( $job ) {
				return in_array( $job['status'], array( 'running', 'paused' ) );
			}
		);

		if ( ! empty( $active_jobs ) ) :
			?>
		<div class="voxfor-ml-bulk-section">
			<h2><?php esc_html_e( 'Active Translation Jobs', 'voxfor-multilanguage' ); ?></h2>
			
			<div class="voxfor-ml-active-jobs">
				<?php foreach ( $active_jobs as $job ) : ?>
					<?php $job_status = $bulk_manager->getJobStatus( $job['id'] ); ?>
					<div class="voxfor-ml-job-card" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">
						<div class="voxfor-ml-job-header">
							<h3><?php echo esc_html( $job['id'] ); ?></h3>
							<span class="voxfor-ml-status <?php echo esc_attr( $job['status'] ); ?>">
								<?php echo esc_html( ucfirst( $job['status'] ) ); ?>
							</span>
						</div>
						
						<div class="voxfor-ml-job-progress">
							<div class="voxfor-ml-progress-bar">
								<div class="voxfor-ml-progress-fill" style="width: <?php echo esc_attr( $job_status['progress'] ); ?>%"></div>
							</div>
							<div class="voxfor-ml-progress-info">
								<span><?php echo esc_html( $job_status['progress'] ); ?>%</span>
								<span><?php printf( /* translators: %1$d is the number of processed items, %2$d is the total number of items */ esc_html__( '%1$d / %2$d items', 'voxfor-multilanguage' ), intval( $job_status['processed_items'] ), intval( $job_status['total_items'] ) ); ?></span>
							</div>
						</div>
						
						<div class="voxfor-ml-job-details">
							<p>
								<strong><?php esc_html_e( 'Languages:', 'voxfor-multilanguage' ); ?></strong>
								<?php echo esc_html( implode( ', ', array_map( 'strtoupper', $job['languages'] ) ) ); ?>
							</p>
							<p>
								<strong><?php esc_html_e( 'Started:', 'voxfor-multilanguage' ); ?></strong>
								<?php echo esc_html( human_time_diff( strtotime( $job['started_at'] ), current_time( 'timestamp' ) ) . ' ago' ); ?>
							</p>
							<?php if ( $job_status['eta'] ) : ?>
								<p>
									<strong><?php esc_html_e( 'ETA:', 'voxfor-multilanguage' ); ?></strong>
									<?php echo esc_html( $job_status['eta'] ); ?>
								</p>
							<?php endif; ?>
						</div>
						
						<div class="voxfor-ml-job-actions">
							<?php if ( $job['status'] === 'running' ) : ?>
								<button class="button voxfor-ml-pause-job" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">
									<?php esc_html_e( 'Pause', 'voxfor-multilanguage' ); ?>
								</button>
							<?php else : ?>
								<button class="button voxfor-ml-resume-job" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">
									<?php esc_html_e( 'Resume', 'voxfor-multilanguage' ); ?>
								</button>
							<?php endif; ?>
							<button class="button button-link-delete voxfor-ml-cancel-job" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">
								<?php esc_html_e( 'Cancel', 'voxfor-multilanguage' ); ?>
							</button>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>
		
		<!-- Job History -->
		<?php
		$completed_jobs = array_filter(
			$all_jobs,
			function ( $job ) {
				return in_array( $job['status'], array( 'completed', 'cancelled' ) );
			}
		);

		if ( ! empty( $completed_jobs ) ) :
			?>
		<div class="voxfor-ml-bulk-section">
			<h2><?php esc_html_e( 'Translation Job History', 'voxfor-multilanguage' ); ?></h2>
			
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Job ID', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Status', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Items', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Languages', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Started', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Completed', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'voxfor-multilanguage' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $completed_jobs, 0, 20 ) as $job ) : ?>
						<tr>
							<td><?php echo esc_html( $job['id'] ); ?></td>
							<td>
								<span class="voxfor-ml-status <?php echo esc_attr( $job['status'] ); ?>">
									<?php echo esc_html( ucfirst( $job['status'] ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $job['total_items'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', array_map( 'strtoupper', $job['languages'] ) ) ); ?></td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $job['started_at'] ) ) ); ?></td>
							<td>
								<?php
								if ( $job['completed_at'] ) {
									echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $job['completed_at'] ) ) );
								} else {
									echo '—';
								}
								?>
							</td>
							<td>
								<button class="button button-small voxfor-ml-view-job-log" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">
									<?php esc_html_e( 'View Log', 'voxfor-multilanguage' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
	</div>
</div>

<!-- Job Log Modal -->
<div id="voxfor-ml-job-log-modal" class="voxfor-ml-modal" style="display: none;">
	<div class="voxfor-ml-modal-content">
		<div class="voxfor-ml-modal-header">
			<h3><?php esc_html_e( 'Job Log', 'voxfor-multilanguage' ); ?></h3>
			<button class="voxfor-ml-modal-close">&times;</button>
		</div>
		<div class="voxfor-ml-modal-body">
			<div id="voxfor-ml-job-log-content"></div>
		</div>
	</div>
</div>

<?php
// Bulk translation styles are now in public/css/admin/bulk-translation.css
// Bulk translation JavaScript is now in public/js/admin/bulk-translation.js
// Both are properly enqueued via wp_enqueue_style/wp_enqueue_script with localization
?>
