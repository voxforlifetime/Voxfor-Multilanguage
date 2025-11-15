<?php
/**
 * Language Switcher - Dropdown Style
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

<div class="voxfor-ml-switcher voxfor-ml-switcher-dropdown <?php echo esc_attr( $args['class'] ); ?>">
	<button class="voxfor-ml-switcher-toggle" aria-label="<?php esc_attr_e( 'Select Language', 'voxfor-multilanguage' ); ?>" aria-expanded="false">
		<?php if ( $args['show_flags'] ) : ?>
			<span class="voxfor-ml-flag"><?php echo esc_html( $languages[ $current_lang ]['flag'] ); ?></span>
		<?php endif; ?>
		<span class="voxfor-ml-lang-name">
			<?php echo esc_html( $args['show_native_names'] ? $languages[ $current_lang ]['native'] : $languages[ $current_lang ]['name'] ); ?>
		</span>
		<span class="voxfor-ml-arrow" aria-hidden="true">▼</span>
	</button>
	
	<ul class="voxfor-ml-switcher-dropdown-menu" role="menu" aria-label="<?php esc_attr_e( 'Available Languages', 'voxfor-multilanguage' ); ?>">
		<?php foreach ( $language_urls as $lang => $url ) : ?>
			<?php
			if ( ! isset( $languages[ $lang ] ) ) {
				continue;}
			?>
			<?php
			// Use display language for HTML attributes (cosmetic)
			$display_lang = ( $lang === 'en' ) ? get_option( 'voxfor_ml_display_prefix', 'en' ) : $lang;
			?>
			<li role="none">
				<a href="<?php echo esc_url( $url ); ?>" 
					class="voxfor-ml-lang-option <?php echo $lang === $current_lang ? 'current' : ''; ?>"
					lang="<?php echo esc_attr( $display_lang ); ?>"
					hreflang="<?php echo esc_attr( $display_lang ); ?>"
					role="menuitem"
					<?php
					if ( $lang === $current_lang ) :
						?>
						aria-current="true"<?php endif; ?>>
					<?php if ( $args['show_flags'] ) : ?>
						<span class="voxfor-ml-flag"><?php echo esc_html( $languages[ $lang ]['flag'] ); ?></span>
					<?php endif; ?>
					<span class="voxfor-ml-lang-name">
						<?php echo esc_html( $args['show_native_names'] ? $languages[ $lang ]['native'] : $languages[ $lang ]['name'] ); ?>
					</span>
					<?php if ( $lang === $current_lang ) : ?>
						<span class="voxfor-ml-checkmark" aria-hidden="true">✓</span>
					<?php endif; ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</div>