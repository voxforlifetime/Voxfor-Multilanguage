<?php
/**
 * Translation Memory Management Template
 *
 * @var object $memory
 * @var array $translations
 * @var string $search
 * @var string $language
 * @var string $context
 * @var string $specific_item
 * @var int $current_page
 * @var int $total_pages
 * @var int $total_count
 * @var int $per_page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>



<div class="wrap voxfor-ml-admin-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	

	<!-- Stats Overview -->
	<div class="voxfor-ml-memory-stats">
		<div class="voxfor-ml-stat-card">
			<h3><?php echo number_format( $total_count ); ?></h3>
			<p><?php esc_html_e( 'Total Translations', 'voxfor-multilanguage' ); ?></p>
		</div>
		<div class="voxfor-ml-stat-card">
			<h3><?php echo number_format( count( $translations ) ); ?></h3>
			<p><?php esc_html_e( 'Showing on Page', 'voxfor-multilanguage' ); ?></p>
		</div>
		<?php
		// Get locked count
		global $wpdb;
		$table_name   = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translations';
		$locked_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `%1s` WHERE is_locked = 1", $table_name ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		?>
		<div class="voxfor-ml-stat-card">
			<h3><?php echo number_format( $locked_count ); ?></h3>
			<p><?php esc_html_e( 'Locked Translations', 'voxfor-multilanguage' ); ?></p>
		</div>
	</div>
	

	<!-- Search and Filters -->
	<div class="voxfor-ml-memory-filters">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="voxfor-ml-search-form">
			<input type="hidden" name="page" value="voxfor-ml-memory" />
			
			<div class="voxfor-ml-filter-grid">
				<div class="voxfor-ml-filter-item voxfor-ml-search-input">
					<label for="s"><?php esc_html_e( 'Search:', 'voxfor-multilanguage' ); ?></label>
					<input type="search" 
							id="s" 
							name="s" 
							value="<?php echo esc_attr( $search ); ?>" 
							placeholder="<?php esc_attr_e( 'Search original or translated text...', 'voxfor-multilanguage' ); ?>" />
				</div>
				
				<div class="voxfor-ml-filter-item">
					<label for="filter-language"><?php esc_html_e( 'Language:', 'voxfor-multilanguage' ); ?></label>
					<select name="lang" id="filter-language">
						<option value=""><?php esc_html_e( 'All Languages', 'voxfor-multilanguage' ); ?></option>
						<?php
						$languages      = get_option( 'voxfor_ml_languages', array( 'en' ) );
						$language_names = array(
							'en' => 'English',
							'fr' => 'French (Français)',
							'de' => 'German (Deutsch)',
							'es' => 'Spanish (Español)',
							'it' => 'Italian (Italiano)',
							'pt' => 'Portuguese (Português)',
							'ru' => 'Russian (Русский)',
							'ja' => 'Japanese (日本語)',
							'zh' => 'Chinese (中文)',
							'ko' => 'Korean (한국어)',
							'ar' => 'Arabic (العربية)',
							'he' => 'Hebrew (עברית)',
							'sv' => 'Swedish (Svenska)',
							'no' => 'Norwegian (Norsk)',
							'da' => 'Danish (Dansk)',
							'fi' => 'Finnish (Suomi)',
							'nl' => 'Dutch (Nederlands)',
							'pl' => 'Polish (Polski)',
							'tr' => 'Turkish (Türkçe)',
							'cs' => 'Czech (Čeština)',
							'hu' => 'Hungarian (Magyar)',
							'ro' => 'Romanian (Română)',
							'el' => 'Greek (Ελληνικά)',
							'th' => 'Thai (ไทย)',
							'vi' => 'Vietnamese (Tiếng Việt)',
							'id' => 'Indonesian (Bahasa Indonesia)',
							'ms' => 'Malay (Bahasa Melayu)',
							'hi' => 'Hindi (हिन्दी)',
							'bn' => 'Bengali (বাংলা)',
							'uk' => 'Ukrainian (Українська)',
						);
						foreach ( $languages as $lang ) :
							if ( $lang === 'en' ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $language, $lang ); ?>>
								<?php echo esc_html( $language_names[ $lang ] ?? $lang ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				
				<div class="voxfor-ml-filter-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'voxfor-multilanguage' ); ?></button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=voxfor-ml-memory' ) ); ?>" class="button"><?php esc_html_e( 'Clear All', 'voxfor-multilanguage' ); ?></a>
				</div>
			</div>
		</form>
	</div>
	
	<!-- Translation Memory Table -->
	<div class="voxfor-ml-memory-table-container">
		<?php if ( ! empty( $translations ) ) : ?>
			<!-- Results Info -->
			<div class="voxfor-ml-results-info">
				<p>
					<?php
					$start = ( $current_page - 1 ) * $per_page + 1;
					$end   = min( $current_page * $per_page, $total_count );
					printf(
						/* translators: %1$d is the start number, %2$d is the end number, %3$d is the total count of translations */
						esc_html__( 'Showing %1$d-%2$d of %3$d translations', 'voxfor-multilanguage' ),
						intval( $start ),
						intval( $end ),
						intval( $total_count )
					);
					?>
					<?php if ( $search || $language ) : ?>
						<span class="voxfor-ml-filtered">
							(<?php esc_html_e( 'filtered', 'voxfor-multilanguage' ); ?>)
						</span>
					<?php endif; ?>
				</p>
			</div>
			
			<div class="voxfor-ml-table-wrapper">
			<table class="wp-list-table widefat fixed striped voxfor-ml-translation-table">
				<thead>
					<tr>
							<th style="width: 40%;"><?php esc_html_e( 'Original Text', 'voxfor-multilanguage' ); ?></th>
							<th style="width: 40%;"><?php esc_html_e( 'Translated Text', 'voxfor-multilanguage' ); ?></th>
						<th style="width: 10%;"><?php esc_html_e( 'Language', 'voxfor-multilanguage' ); ?></th>
						<th style="width: 10%;"><?php esc_html_e( 'Context', 'voxfor-multilanguage' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $translations as $translation ) : ?>
							<tr data-id="<?php echo esc_attr( $translation->id ); ?>" class="<?php echo $translation->is_locked ? 'locked-row' : ''; ?>">
							<td class="source-text">
									<div class="voxfor-ml-text-content" title="<?php echo esc_attr( $translation->source_text ); ?>">
										<?php echo esc_html( $translation->source_text ); ?>
								</div>
								<?php if ( $translation->post_id ) : ?>
									<div class="row-actions">
										<span class="view">
												<a href="<?php echo esc_url( get_edit_post_link( $translation->post_id ) ); ?>" target="_blank">
													<?php esc_html_e( 'Edit Post', 'voxfor-multilanguage' ); ?>
												</a> |
											<a href="<?php echo esc_url( get_permalink( $translation->post_id ) ); ?>" target="_blank">
													<?php esc_html_e( 'View', 'voxfor-multilanguage' ); ?>
											</a>
										</span>
									</div>
								<?php endif; ?>
							</td>
							<td class="translated-text">
									<div class="voxfor-ml-editable-wrapper">
										<div class="voxfor-ml-text-content voxfor-ml-editable" 
									data-id="<?php echo esc_attr( $translation->id ); ?>"
											data-locked="<?php echo $translation->is_locked ? '1' : '0'; ?>"
											title="<?php echo $translation->is_locked ? esc_attr__( 'Locked - Click lock icon to edit', 'voxfor-multilanguage' ) : esc_attr__( 'Click to edit', 'voxfor-multilanguage' ); ?>">
											<?php echo esc_html( $translation->translated_text ); ?>
										</div>
										<?php if ( $translation->is_locked ) : ?>
											<span class="voxfor-ml-lock-indicator" title="<?php esc_attr_e( 'This translation is locked', 'voxfor-multilanguage' ); ?>">🔒</span>
										<?php endif; ?>
								</div>
							</td>
							<td>
								<span class="voxfor-ml-lang-badge">
									<?php echo esc_html( strtoupper( $translation->language_code ) ); ?>
								</span>
							</td>
						<td>
							<span class="voxfor-ml-context-badge <?php echo esc_attr( $translation->context ); ?>">
								<?php
								$context_labels = array(
									'title'        => __( 'Title', 'voxfor-multilanguage' ),
									'content'      => __( 'Content', 'voxfor-multilanguage' ),
									'excerpt'      => __( 'Excerpt', 'voxfor-multilanguage' ),
									'menu_item'    => __( 'Menu', 'voxfor-multilanguage' ),
									'widget_title' => __( 'Widget Title', 'voxfor-multilanguage' ),
									'widget_text'  => __( 'Widget Text', 'voxfor-multilanguage' ),
									'image_alt'    => __( 'Image ALT', 'voxfor-multilanguage' ),
									'image_title'  => __( 'Image Title', 'voxfor-multilanguage' ),
								);
									echo esc_html( $context_labels[ $translation->context ] ?? ucfirst( $translation->context ) );
								?>
							</span>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			
			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="voxfor-ml-pagination">
					<?php
					// Build base URL with all current filter parameters
					$base_args = array( 'page' => 'voxfor-ml-memory' );
					if ( $search ) {
						$base_args['s'] = $search;
					}
					if ( $language ) {
						$base_args['lang'] = $language;
					}
					if ( $context ) {
						$base_args['context'] = $context;
					}
					if ( $specific_item ) {
						$base_args['specific_item'] = $specific_item;
					}

					$base_url = add_query_arg( $base_args, admin_url( 'admin.php' ) );

					$pagination_args = array(
						'base'      => add_query_arg( 'paged', '%#%', $base_url ),
						'format'    => '',
						'current'   => $current_page,
						'total'     => $total_pages,
						'prev_text' => '← ' . __( 'Previous', 'voxfor-multilanguage' ),
						'next_text' => __( 'Next', 'voxfor-multilanguage' ) . ' →',
						'show_all'  => false,
						'mid_size'  => 2,
						'end_size'  => 1,
					);

					echo wp_kses_post( paginate_links( $pagination_args ) );
					?>
				</div>
			<?php endif; ?>
			
		<?php else : ?>
			<div class="voxfor-ml-no-results">
				<div class="voxfor-ml-empty-state">
					<h3><?php esc_html_e( 'No translations found', 'voxfor-multilanguage' ); ?></h3>
					<?php if ( $search || $language ) : ?>
						<p><?php esc_html_e( 'Try adjusting your filters or search terms.', 'voxfor-multilanguage' ); ?></p>
						<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=voxfor-ml-memory' ) ); ?>" class="button"><?php esc_html_e( 'Clear all filters', 'voxfor-multilanguage' ); ?></a></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Translation memory is empty. Translations will appear here as content gets translated.', 'voxfor-multilanguage' ); ?></p>
				<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>


<?php
// Styles are now in separate CSS file and properly enqueued
// Styles are properly enqueued via wp_enqueue_style
?>

<?php
// JavaScript is now in separate file and properly enqueued
// Script is properly enqueued via wp_enqueue_script with localization
?>
