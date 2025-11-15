<?php
/**
 * Language Switcher - Compact Style
 *
 * @var array $languages
 * @var string $current_lang
 * @var array $language_urls
 * @var array $args
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="voxfor-ml-switcher voxfor-ml-switcher-compact <?php echo esc_attr( $args['class'] ); ?>">
	<button class="voxfor-ml-compact-toggle" 
			aria-label="<?php esc_attr_e( 'Select Language', 'voxfor-multilanguage' ); ?>" 
			aria-expanded="false">
		<?php if ( isset( $languages[ $current_lang ] ) ) : ?>
			<span class="voxfor-ml-current-lang">
				<span class="voxfor-ml-flag"><?php echo esc_html( $languages[ $current_lang ]['flag'] ); ?></span>
				<span class="voxfor-ml-lang-name"><?php echo esc_html( $languages[ $current_lang ]['native'] ); ?></span>
			</span>
		<?php else : ?>
			<span class="voxfor-ml-globe-icon" aria-hidden="true">🌐</span>
			<span class="voxfor-ml-current-lang"><?php echo esc_html( strtoupper( $current_lang ) ); ?></span>
		<?php endif; ?>
		<span class="voxfor-ml-arrow" aria-hidden="true">▼</span>
	</button>
	
	<div class="voxfor-ml-compact-menu" role="menu" aria-label="<?php esc_attr_e( 'Available Languages', 'voxfor-multilanguage' ); ?>">
		<div class="voxfor-ml-compact-grid">
			<?php foreach ( $language_urls as $lang => $url ) : ?>
				<?php
				if ( ! isset( $languages[ $lang ] ) ) {
					continue;}
				?>
				<?php
				// Use display language for HTML attributes (cosmetic)
				$display_lang = ( $lang === 'en' ) ? get_option( 'voxfor_ml_display_prefix', 'en' ) : $lang;
				?>
				<a href="<?php echo esc_url( $url ); ?>" 
					class="voxfor-ml-compact-option <?php echo $lang === $current_lang ? 'current' : ''; ?>"
					lang="<?php echo esc_attr( $display_lang ); ?>"
					hreflang="<?php echo esc_attr( $display_lang ); ?>"
					role="menuitem"
					title="<?php echo esc_attr( $languages[ $lang ]['native'] ); ?>"
					<?php
					if ( $lang === $current_lang ) :
						?>
						aria-current="true"<?php endif; ?>>
					<span class="voxfor-ml-flag"><?php echo esc_html( $languages[ $lang ]['flag'] ); ?></span>
					<span class="voxfor-ml-lang-name"><?php echo esc_html( $languages[ $lang ]['native'] ); ?></span>
					<?php if ( $lang === $current_lang ) : ?>
						<span class="voxfor-ml-checkmark" aria-hidden="true">✓</span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</div>