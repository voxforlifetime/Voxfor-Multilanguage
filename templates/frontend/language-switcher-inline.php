<?php
/**
 * Language Switcher - Inline Style
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

<nav class="voxfor-ml-switcher voxfor-ml-switcher-inline <?php echo esc_attr( $args['class'] ); ?>" aria-label="<?php esc_attr_e( 'Language Switcher', 'voxfor-multilanguage' ); ?>">
	<ul class="voxfor-ml-lang-list">
		<?php foreach ( $language_urls as $lang => $url ) : ?>
			<?php
			if ( ! isset( $languages[ $lang ] ) ) {
				continue;}
			?>
			<?php
			// Use display language for HTML attributes (cosmetic)
			$display_lang = ( $lang === 'en' ) ? get_option( 'voxfor_ml_display_prefix', 'en' ) : $lang;
			?>
			<li class="voxfor-ml-lang-item">
				<a href="<?php echo esc_url( $url ); ?>" 
					class="voxfor-ml-lang-link <?php echo $lang === $current_lang ? 'current' : ''; ?>"
					lang="<?php echo esc_attr( $display_lang ); ?>"
					hreflang="<?php echo esc_attr( $display_lang ); ?>"
					<?php
					if ( $lang === $current_lang ) :
						?>
						aria-current="page"<?php endif; ?>>
					<?php if ( $args['show_flags'] ) : ?>
						<span class="voxfor-ml-flag"><?php echo esc_html( $languages[ $lang ]['flag'] ); ?></span>
					<?php endif; ?>
					<span class="voxfor-ml-lang-name">
						<?php echo esc_html( $args['show_native_names'] ? $languages[ $lang ]['native'] : $languages[ $lang ]['name'] ); ?>
					</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>