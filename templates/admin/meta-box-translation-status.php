<?php
/**
 * Translation Status Meta Box Template
 *
 * @var WP_Post $post
 * @var bool $exclude
 * @var array $languages
 * @var object $translator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$memory              = new VoxforML\Database\TranslationMemory();
$available_languages = array_diff( $languages, array( 'en' ) );
?>

<div class="voxfor-ml-meta-box-content">
	<p>
		<label>
			<input type="checkbox" 
					name="voxfor_ml_exclude" 
					value="1" 
					<?php checked( $exclude ); ?> />
			<?php esc_html_e( 'Exclude this post from translation', 'voxfor-multilanguage' ); ?>
		</label>
	</p>
	
	<?php if ( ! $exclude && ! empty( $available_languages ) ) : ?>
		<div class="voxfor-ml-translation-status-list">
			<h4><?php esc_html_e( 'Translation Status:', 'voxfor-multilanguage' ); ?></h4>
			
			<?php foreach ( $available_languages as $lang ) : ?>
				<?php
				$translations      = $memory->getPostTranslations( $post->ID, $lang );
				$has_translation   = ! empty( $translations );
				$translation_count = count( $translations );

				$language_names = array(
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

				$language_name = $language_names[ $lang ] ?? $lang;
				?>
				
				<div class="voxfor-ml-translation-status-item">
					<span class="voxfor-ml-lang-flag <?php echo $has_translation ? 'has-translation' : 'no-translation'; ?>">
						<?php echo esc_html( strtoupper( $lang ) ); ?>
					</span>
					<span class="voxfor-ml-lang-name"><?php echo esc_html( $language_name ); ?></span>
					<span class="voxfor-ml-translation-info">
						<?php if ( $has_translation ) : ?>
							<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
							<?php printf( /* translators: %d is the number of translations */ esc_html( _n( '%d translation', '%d translations', $translation_count, 'voxfor-multilanguage' ) ), intval( $translation_count ) ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-warning" style="color: #ffb900;"></span>
							<?php esc_html_e( 'Not translated', 'voxfor-multilanguage' ); ?>
						<?php endif; ?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
		
		<div class="voxfor-ml-meta-box-actions" style="margin-top: 15px;">
			<a href="
			<?php
			echo esc_url( add_query_arg(
				array(
					'voxfor_ml_translate' => $post->ID,
					'_wpnonce'            => wp_create_nonce( 'voxfor_ml_translate' ),
				)
			) );
			?>
						" 
				class="button button-secondary">
				<?php esc_html_e( 'Queue for Translation', 'voxfor-multilanguage' ); ?>
			</a>
			
			<?php
			$preview_url = get_permalink( $post->ID );
			if ( $preview_url && ! empty( $available_languages ) ) :
				$router = VoxforML\Core\Plugin::getInstance()->getComponent( 'router' );
				?>
				<div style="margin-top: 10px;">
					<label><?php esc_html_e( 'Preview in:', 'voxfor-multilanguage' ); ?></label>
					<select onchange="if(this.value) window.open(this.value, '_blank');" style="margin-left: 5px;">
						<option value=""><?php esc_html_e( 'Select language', 'voxfor-multilanguage' ); ?></option>
						<?php foreach ( $available_languages as $lang ) : ?>
							<option value="<?php echo esc_url( $router->getLanguageUrl( $preview_url, $lang ) ); ?>">
								<?php echo esc_html( $language_names[ $lang ] ?? $lang ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	
	<?php if ( get_option( 'voxfor_ml_enable_visual_editor', true ) ) : ?>
		<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
			<p class="description">
				<?php esc_html_e( '💡 Tip: Add ?voxfor_ml_edit=1 to any page URL to use the visual translation editor.', 'voxfor-multilanguage' ); ?>
			</p>
		</div>
	<?php endif; ?>
</div>

<?php
// Styles are now in separate CSS file and properly enqueued
// Styles are properly enqueued via wp_enqueue_style
?>
