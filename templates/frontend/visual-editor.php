<?php
/**
 * Visual Editor Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only show for users with edit permissions
if ( ! current_user_can( 'edit_posts' ) ) {
	return;
}
?>

<div id="voxfor-ml-visual-editor-toolbar" class="voxfor-ml-visual-toolbar">
	<div class="voxfor-ml-toolbar-inner">
		<div class="voxfor-ml-toolbar-brand">
			<span class="dashicons dashicons-translation"></span>
			<?php esc_html_e( 'Visual Translation Editor', 'voxfor-multilanguage' ); ?>
		</div>
		
		<div class="voxfor-ml-toolbar-info">
			<span class="voxfor-ml-current-language">
				<?php
				$current_lang = VoxforML\Core\Plugin::getInstance()->getCurrentLanguage();
				$languages    = array(
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
				// translators: %s is the language name being edited
				printf( esc_html__( 'Editing: %s', 'voxfor-multilanguage' ), esc_html( $languages[ $current_lang ] ?? $current_lang ) );
				?>
			</span>
		</div>
		
		<div class="voxfor-ml-toolbar-actions">
			<button class="voxfor-ml-toolbar-button voxfor-ml-toggle-highlights" title="<?php esc_attr_e( 'Toggle Highlights', 'voxfor-multilanguage' ); ?>">
				<span class="dashicons dashicons-visibility"></span>
			</button>
			<button class="voxfor-ml-toolbar-button voxfor-ml-save-all" title="<?php esc_attr_e( 'Save All Changes', 'voxfor-multilanguage' ); ?>">
				<span class="dashicons dashicons-saved"></span>
			</button>
			<a href="<?php echo esc_url( remove_query_arg( 'voxfor_ml_edit' ) ); ?>" class="voxfor-ml-toolbar-button voxfor-ml-exit-editor" title="<?php esc_attr_e( 'Exit Editor', 'voxfor-multilanguage' ); ?>">
				<span class="dashicons dashicons-no"></span>
			</a>
		</div>
	</div>
</div>

<?php
// Styles are now in separate CSS file and properly enqueued
// Styles are properly enqueued via wp_enqueue_style
?>
