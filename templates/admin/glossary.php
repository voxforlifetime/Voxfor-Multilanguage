<?php
/**
 * Glossary Management Template
 *
 * @var array $terms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define language names array - used throughout the template
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
?>

<div class="wrap voxfor-ml-admin-wrap">
	<h1>
		<?php echo esc_html( get_admin_page_title() ); ?>
		<a href="#add-new-term" class="page-title-action"><?php esc_html_e( 'Add New Term', 'voxfor-multilanguage' ); ?></a>
	</h1>
	
	<?php settings_errors( 'voxfor_ml_messages' ); ?>
	
	<div class="voxfor-ml-glossary-container">
		<!-- Existing Terms -->
		<div class="voxfor-ml-glossary-list">
			<h2><?php esc_html_e( 'Glossary Terms', 'voxfor-multilanguage' ); ?></h2>
			
			<?php if ( ! empty( $terms ) ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 25%;"><?php esc_html_e( 'Term', 'voxfor-multilanguage' ); ?></th>
							<th style="width: 25%;"><?php esc_html_e( 'Translation', 'voxfor-multilanguage' ); ?></th>
							<th style="width: 10%;"><?php esc_html_e( 'Language', 'voxfor-multilanguage' ); ?></th>
							<th style="width: 15%;"><?php esc_html_e( 'Match Type', 'voxfor-multilanguage' ); ?></th>
							<th style="width: 15%;"><?php esc_html_e( 'Options', 'voxfor-multilanguage' ); ?></th>
							<th style="width: 10%;"><?php esc_html_e( 'Actions', 'voxfor-multilanguage' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $terms as $term ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $term->term ); ?></strong>
								</td>
								<td><?php echo esc_html( $term->translation ); ?></td>
								<td><?php echo esc_html( $language_names[ $term->language_code ] ?? $term->language_code ); ?></td>
								<td>
									<span class="voxfor-ml-match-type <?php echo esc_attr( $term->match_type ); ?>">
										<?php echo $term->match_type === 'exact' ? esc_html__( 'Exact Match', 'voxfor-multilanguage' ) : esc_html__( 'Partial Match', 'voxfor-multilanguage' ); ?>
									</span>
								</td>
								<td>
									<?php if ( $term->case_sensitive ) : ?>
										<span class="voxfor-ml-option-badge"><?php esc_html_e( 'Case Sensitive', 'voxfor-multilanguage' ); ?></span>
									<?php endif; ?>
									<?php if ( $term->priority > 0 ) : ?>
										<span class="voxfor-ml-option-badge"><?php printf( /* translators: %d is the priority number */ esc_html__( 'Priority: %d', 'voxfor-multilanguage' ), intval( $term->priority ) ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<button class="button button-small voxfor-ml-edit-term" 
											data-id="<?php echo esc_attr( $term->id ); ?>"
											data-term="<?php echo esc_attr( $term->term ); ?>"
											data-translation="<?php echo esc_attr( $term->translation ); ?>"
											data-language="<?php echo esc_attr( $term->language_code ); ?>"
											data-match-type="<?php echo esc_attr( $term->match_type ); ?>"
											data-case-sensitive="<?php echo esc_attr( $term->case_sensitive ); ?>"
											data-priority="<?php echo esc_attr( $term->priority ); ?>">
										<?php esc_html_e( 'Edit', 'voxfor-multilanguage' ); ?>
									</button>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=voxfor_ml_delete_glossary&id=' . $term->id ), 'voxfor_ml_delete_glossary' ) ); ?>" 
										class="button button-small button-link-delete voxfor-ml-delete"
										onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this term?', 'voxfor-multilanguage' ); ?>');">
										<?php esc_html_e( 'Delete', 'voxfor-multilanguage' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No glossary terms found. Add your first term below.', 'voxfor-multilanguage' ); ?></p>
			<?php endif; ?>
		</div>
		
		<!-- Add/Edit Form -->
		<div class="voxfor-ml-glossary-form" id="add-new-term">
			<h2><?php esc_html_e( 'Add New Glossary Term', 'voxfor-multilanguage' ); ?></h2>
			
			<form method="post" action="">
				<?php wp_nonce_field( 'voxfor_ml_glossary_action', 'voxfor_ml_glossary_nonce' ); ?>
				<input type="hidden" name="add_glossary_term" value="1" />
				<input type="hidden" name="term_id" id="edit-term-id" value="" />
				
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="term"><?php esc_html_e( 'Term', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<input type="text" 
									id="term" 
									name="term" 
									class="regular-text" 
									required />
							<p class="description">
								<?php esc_html_e( 'The original term or phrase to be translated consistently', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="translation"><?php esc_html_e( 'Translation', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<input type="text" 
									id="translation" 
									name="translation" 
									class="regular-text" 
									required />
							<p class="description">
								<?php esc_html_e( 'How this term should always be translated', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="language"><?php esc_html_e( 'Language', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<select id="language" name="language" required>
								<option value=""><?php esc_html_e( 'Select Language', 'voxfor-multilanguage' ); ?></option>
								<?php
								// Get enabled languages - try multiple sources
								$enabled_languages = array();
								$default_language  = 'en';

								// Method 1: Try plugin instance
								try {
									$plugin            = VoxforML\Core\Plugin::getInstance();
									$enabled_languages = $plugin->getEnabledLanguages();
									$default_language  = $plugin->getDefaultLanguage();
								} catch ( Exception $e ) {
									// Continue to fallbacks
								}

								// Method 2: Direct WordPress options
								if ( empty( $enabled_languages ) || ! is_array( $enabled_languages ) ) {
									$enabled_languages = get_option( 'voxfor_ml_languages', array() );
									$default_language  = get_option( 'voxfor_ml_default_language', 'en' );
								}

								// Method 3: Fallback to sensible defaults
								if ( empty( $enabled_languages ) || ! is_array( $enabled_languages ) ) {
									$enabled_languages = array( 'en', 'fr', 'de', 'es', 'it', 'pt', 'ru', 'ja', 'zh', 'ko' );
									$default_language  = 'en';
								}

								// Ensure we have an array
								if ( is_string( $enabled_languages ) ) {
									$enabled_languages = explode( ',', $enabled_languages );
									$enabled_languages = array_map( 'trim', $enabled_languages );
								}

								// Calculate target languages (exclude default)
								$available_target_languages = array_diff( $enabled_languages, array( $default_language ) );

								// Debug output
					echo '<!-- DEBUG: Enabled languages: ' . esc_html( implode( ', ', $enabled_languages ) ) . ' -->';
					echo '<!-- DEBUG: Default language: ' . esc_html( $default_language ) . ' -->';
					echo '<!-- DEBUG: Target languages: ' . esc_html( implode( ', ', $available_target_languages ) ) . ' -->';
					echo '<!-- DEBUG: Language names keys: ' . esc_html( implode( ', ', array_keys( $language_names ) ) ) . ' -->';

								// Generate options
								$options_generated = 0;
								foreach ( $available_target_languages as $code ) :
									if ( isset( $language_names[ $code ] ) ) :
										++$options_generated;
										?>
									<option value="<?php echo esc_attr( $code ); ?>">
										<?php echo esc_html( $language_names[ $code ] ); ?>
									</option>
										<?php
								endif;
endforeach;

								echo '<!-- DEBUG: Options generated: ' . intval( $options_generated ) . ' -->';

								// If no options were generated, show a fallback message
								if ( $options_generated === 0 ) :
									?>
									<option value="" disabled>No target languages configured</option>
									<option value="fr">French (Français) - Fallback</option>
									<option value="de">German (Deutsch) - Fallback</option>
									<option value="es">Spanish (Español) - Fallback</option>
								<?php endif; ?>
							</select>
				<p class="description">
					<?php printf( /* translators: %s is a comma-separated list of available language codes */ esc_html__( 'Available languages: %s', 'voxfor-multilanguage' ), esc_html( implode( ', ', array_diff( $enabled_languages, array( $default_language ) ) ) ) ); ?>
				</p>
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="match_type"><?php esc_html_e( 'Match Type', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<select id="match_type" name="match_type">
								<option value="exact"><?php esc_html_e( 'Exact Match (whole word)', 'voxfor-multilanguage' ); ?></option>
								<option value="partial"><?php esc_html_e( 'Partial Match (anywhere)', 'voxfor-multilanguage' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Exact match only replaces whole words, partial match replaces anywhere', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
					
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'voxfor-multilanguage' ); ?></th>
						<td>
							<label>
								<input type="checkbox" 
										id="case_sensitive" 
										name="case_sensitive" 
										value="1" />
								<?php esc_html_e( 'Case sensitive', 'voxfor-multilanguage' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Match only if the case is exactly the same', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="priority"><?php esc_html_e( 'Priority', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<input type="number" 
									id="priority" 
									name="priority" 
									value="0" 
									min="0" 
									max="100" 
									class="small-text" />
							<p class="description">
								<?php esc_html_e( 'Higher priority terms are applied first (0-100)', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
				</table>
				
				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Add Glossary Term', 'voxfor-multilanguage' ); ?>
					</button>
					<button type="button" class="button" id="cancel-edit" style="display: none;">
						<?php esc_html_e( 'Cancel', 'voxfor-multilanguage' ); ?>
					</button>
				</p>
			</form>
		</div>
		
		<!-- Import/Export -->
		<div class="voxfor-ml-glossary-tools">
			<h2><?php esc_html_e( 'Import/Export Glossary', 'voxfor-multilanguage' ); ?></h2>
			
			<div class="voxfor-ml-tools-grid">
				<div class="voxfor-ml-tool-box">
					<h3><?php esc_html_e( 'Export Glossary', 'voxfor-multilanguage' ); ?></h3>
					<p><?php esc_html_e( 'Download your glossary terms as a CSV file.', 'voxfor-multilanguage' ); ?></p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=voxfor_ml_export_glossary' ), 'voxfor_ml_export_glossary' ) ); ?>" 
						class="button">
						<?php esc_html_e( 'Export as CSV', 'voxfor-multilanguage' ); ?>
					</a>
				</div>
				
				<div class="voxfor-ml-tool-box">
					<h3><?php esc_html_e( 'Import Glossary', 'voxfor-multilanguage' ); ?></h3>
					<p><?php esc_html_e( 'Upload a CSV file with glossary terms.', 'voxfor-multilanguage' ); ?></p>
					<form method="post" action="" enctype="multipart/form-data">
						<?php wp_nonce_field( 'voxfor_ml_import_glossary', 'voxfor_ml_import_nonce' ); ?>
						<input type="hidden" name="import_glossary" value="1" />
						<input type="file" name="glossary_file" accept=".csv" required />
						<button type="submit" class="button"><?php esc_html_e( 'Import CSV', 'voxfor-multilanguage' ); ?></button>
					</form>
				</div>
			</div>
		</div>
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
