<?php
/**
 * Per-Language SEO Settings Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Security: Verify user has permission to access this page
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'voxfor-multilanguage' ) );
}

$per_lang_seo        = new VoxforML\SEO\PerLanguageSEO();
$languages           = get_option( 'voxfor_ml_languages', array() );
$default_lang        = VoxforML\Core\Plugin::getInstance()->getDefaultLanguage();
$available_languages = array_diff( $languages, array( $default_lang ) );

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

// Get selected language
$selected_lang = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : ( reset( $available_languages ) ?: '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$settings      = $selected_lang ? $per_lang_seo->getLanguageSettings( $selected_lang ) : array();
?>

<div class="wrap voxfor-ml-admin-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	
	<?php settings_errors( 'voxfor_ml_messages' ); ?>
	
	<?php if ( empty( $available_languages ) ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'Please add languages in the main settings before configuring per-language SEO.', 'voxfor-multilanguage' ); ?></p>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=voxfor-ml-settings' ) ); ?>" class="button"><?php esc_html_e( 'Go to Settings', 'voxfor-multilanguage' ); ?></a></p>
		</div>
	<?php else : ?>
	
	<div class="voxfor-ml-language-seo-container">
		<!-- Language Selector -->
		<div class="voxfor-ml-language-selector">
			<label for="language-select"><?php esc_html_e( 'Configure SEO for:', 'voxfor-multilanguage' ); ?></label>
			<select id="language-select" onchange="window.location.href='<?php echo esc_js( admin_url( 'admin.php?page=voxfor-ml-language-seo&lang=' ) ); ?>' + this.value;">
				<?php foreach ( $available_languages as $lang ) : ?>
					<option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $selected_lang, $lang ); ?>>
						<?php echo esc_html( $language_names[ $lang ] ?? $lang ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		
		<?php if ( $selected_lang ) : ?>
		<form method="post" action="">
			<?php wp_nonce_field( 'voxfor_ml_language_seo', 'voxfor_ml_seo_nonce' ); ?>
			<input type="hidden" name="language" value="<?php echo esc_attr( $selected_lang ); ?>" />
			<input type="hidden" name="save_language_seo" value="1" />
			
			<!-- Indexing Settings -->
			<div class="voxfor-ml-seo-section">
				<h2><?php esc_html_e( 'Search Engine Indexing', 'voxfor-multilanguage' ); ?></h2>
				
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Allow Indexing', 'voxfor-multilanguage' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="indexable" value="1" <?php checked( ! empty( $settings['indexable'] ) ); ?> />
								<?php printf( /* translators: %s is the language name */ esc_html__( 'Allow search engines to index %s pages', 'voxfor-multilanguage' ), esc_html( $language_names[ $selected_lang ] ?? $selected_lang ) ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'If unchecked, all pages in this language will have noindex meta tag and be excluded from sitemaps.', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
					
					<tr>
						<th scope="row"><?php esc_html_e( 'Include in Sitemap', 'voxfor-multilanguage' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="in_sitemap" value="1" <?php checked( ! empty( $settings['in_sitemap'] ) ); ?> />
								<?php esc_html_e( 'Include this language in XML sitemaps', 'voxfor-multilanguage' ); ?>
							</label>
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="custom_robots_rules"><?php esc_html_e( 'Custom Robots Rules', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<textarea name="custom_robots_rules" id="custom_robots_rules" rows="5" cols="50" class="large-text code"><?php echo esc_textarea( $settings['custom_robots_rules'] ?? '' ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Additional robots.txt rules specific to this language. One rule per line.', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>
			
			<!-- Meta Tag Patterns -->
			<div class="voxfor-ml-seo-section">
			<h2><?php esc_html_e( 'Meta Tag Patterns', 'voxfor-multilanguage' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Use these patterns to customize how meta tags are generated for this language. Available variables: {title}, {site_name}, {site_description}, {language}, {language_name}, {date}, {year}', 'voxfor-multilanguage' ); ?>
				</p>
				
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="meta_title_pattern"><?php esc_html_e( 'Title Pattern', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<input type="text" 
									name="meta_title_pattern" 
									id="meta_title_pattern" 
									value="<?php echo esc_attr( $settings['meta_title_pattern'] ?? '' ); ?>" 
									class="large-text" />
							<p class="description">
								<?php esc_html_e( 'Example: {title} - {site_name} ({language_name})', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="meta_description_pattern"><?php esc_html_e( 'Description Pattern', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<input type="text" 
									name="meta_description_pattern" 
									id="meta_description_pattern" 
									value="<?php echo esc_attr( $settings['meta_description_pattern'] ?? '' ); ?>" 
									class="large-text" />
						</td>
					</tr>
				</table>
			</div>
			
			<!-- Open Graph Settings -->
			<div class="voxfor-ml-seo-section">
				<h2><?php esc_html_e( 'Open Graph Settings', 'voxfor-multilanguage' ); ?></h2>
				
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="og_title_pattern"><?php esc_html_e( 'OG Title Pattern', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<input type="text" 
									name="og_title_pattern" 
									id="og_title_pattern" 
									value="<?php echo esc_attr( $settings['og_title_pattern'] ?? '' ); ?>" 
									class="large-text" />
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="og_description_pattern"><?php esc_html_e( 'OG Description Pattern', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<input type="text" 
									name="og_description_pattern" 
									id="og_description_pattern" 
									value="<?php echo esc_attr( $settings['og_description_pattern'] ?? '' ); ?>" 
									class="large-text" />
						</td>
					</tr>
				</table>
			</div>
			
			<!-- Twitter Card Settings -->
			<div class="voxfor-ml-seo-section">
				<h2><?php esc_html_e( 'Twitter Card Settings', 'voxfor-multilanguage' ); ?></h2>
				
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="twitter_title_pattern"><?php esc_html_e( 'Twitter Title Pattern', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<input type="text" 
									name="twitter_title_pattern" 
									id="twitter_title_pattern" 
									value="<?php echo esc_attr( $settings['twitter_title_pattern'] ?? '' ); ?>" 
									class="large-text" />
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="twitter_description_pattern"><?php esc_html_e( 'Twitter Description Pattern', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<input type="text" 
									name="twitter_description_pattern" 
									id="twitter_description_pattern" 
									value="<?php echo esc_attr( $settings['twitter_description_pattern'] ?? '' ); ?>" 
									class="large-text" />
						</td>
					</tr>
				</table>
			</div>
			
			<!-- Image Translation Settings -->
			<div class="voxfor-ml-seo-section">
				<h2><?php esc_html_e( 'Image Translation', 'voxfor-multilanguage' ); ?></h2>
				
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Translate Image ALT Text', 'voxfor-multilanguage' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="translate_image_alt" value="1" <?php checked( ! empty( $settings['translate_image_alt'] ) ); ?> />
								<?php esc_html_e( 'Automatically translate image ALT attributes', 'voxfor-multilanguage' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Improves image search visibility in this language.', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
					
					<tr>
						<th scope="row"><?php esc_html_e( 'Translate Image Title', 'voxfor-multilanguage' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="translate_image_title" value="1" <?php checked( ! empty( $settings['translate_image_title'] ) ); ?> />
								<?php esc_html_e( 'Automatically translate image title attributes', 'voxfor-multilanguage' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>
			
			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Save SEO Settings', 'voxfor-multilanguage' ); ?>
				</button>
			</p>
		</form>
		
		<!-- Preview Section -->
		<div class="voxfor-ml-seo-preview">
			<h2><?php esc_html_e( 'Preview', 'voxfor-multilanguage' ); ?></h2>
			
			<div class="voxfor-ml-preview-box">
				<h4><?php esc_html_e( 'Search Engine Result Preview', 'voxfor-multilanguage' ); ?></h4>
				<div class="voxfor-ml-serp-preview">
					<div class="serp-title" id="preview-title">
						<?php
						$preview_title = $settings['meta_title_pattern'] ?? '{title} - {site_name}';
						$preview_title = str_replace(
							array( '{title}', '{site_name}', '{language_name}' ),
							array( __( 'Sample Page Title', 'voxfor-multilanguage' ), get_bloginfo( 'name' ), $language_names[ $selected_lang ] ?? $selected_lang ),
							$preview_title
						);
						echo esc_html( $preview_title );
						?>
					</div>
					<div class="serp-url">
						<?php echo esc_url( home_url( '/' . $selected_lang . '/sample-page/' ) ); ?>
					</div>
					<div class="serp-description" id="preview-description">
						<?php
						$preview_desc = $settings['meta_description_pattern'] ?? '';
						if ( empty( $preview_desc ) ) {
							$preview_desc = __( 'This is a sample description for your page in the selected language.', 'voxfor-multilanguage' );
						}
						echo esc_html( $preview_desc );
						?>
					</div>
				</div>
			</div>
			
			<div class="voxfor-ml-preview-box">
				<h4><?php esc_html_e( 'Robots.txt Preview', 'voxfor-multilanguage' ); ?></h4>
				<pre class="voxfor-ml-code-preview">
				<?php
				if ( ! $settings['indexable'] ) {
					echo "User-agent: *\n";
					echo esc_html( "Disallow: /{$selected_lang}/\n\n" );
				}
				if ( ! empty( $settings['custom_robots_rules'] ) ) {
					echo esc_html( "# Custom rules for {$selected_lang}\n" );
					echo esc_html( $settings['custom_robots_rules'] ) . "\n\n";
				}
				if ( $settings['in_sitemap'] ) {
					echo 'Sitemap: ' . esc_url( home_url( "/{$selected_lang}/sitemap.xml" ) ) . "\n";
				}
				?>
				</pre>
			</div>
		</div>
		<?php endif; ?>
	</div>
	
	<?php endif; ?>
</div>

<?php
// Styles are now in separate CSS file and properly enqueued
// Styles are properly enqueued via wp_enqueue_style
?>

<?php
// JavaScript is now in separate file and properly enqueued
// Script is properly enqueued via wp_enqueue_script with localization
?>
