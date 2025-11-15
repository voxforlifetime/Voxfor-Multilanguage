<?php
/**
 * Language Switcher Widget
 *
 * @package VoxforML
 * @subpackage Widgets
 */

namespace VoxforML\Widgets;

use WP_Widget;
use VoxforML\Core\Plugin;

/**
 * Language Switcher Widget Class
 */
class LanguageSwitcherWidget extends WP_Widget {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct(
			'voxfor_ml_language_switcher',
			__( 'Voxfor Language Switcher', 'voxfor-multilanguage' ),
			array(
				'description' => __( 'Display a language switcher for your multilingual site', 'voxfor-multilanguage' ),
				'classname'   => 'voxfor-ml-language-switcher-widget',
			)
		);
	}

	/**
	 * Widget output
	 */
	public function widget( $args, $instance ) {
		$plugin = Plugin::getInstance();
		$router = $plugin->getComponent( 'router' );

		if ( ! $router ) {
			return;
		}

		$current_language  = $router->getCurrentLanguage();
		$enabled_languages = $plugin->getEnabledLanguages();

		// Ensure enabled_languages is an array
		if ( ! is_array( $enabled_languages ) ) {
			$enabled_languages = explode( ',', $enabled_languages );
		}

		// Clean up the array - remove empty values and trim
		$enabled_languages = array_filter( array_map( 'trim', $enabled_languages ) );

		// Don't show if only one language is enabled
		if ( count( $enabled_languages ) <= 1 ) {
			return;
		}

		$style             = isset( $instance['style'] ) ? $instance['style'] : 'dropdown';
		$show_flags        = isset( $instance['show_flags'] ) ? (bool) $instance['show_flags'] : true;
		$show_native_names = isset( $instance['show_native_names'] ) ? (bool) $instance['show_native_names'] : true;
		$title             = isset( $instance['title'] ) ? $instance['title'] : '';

		echo wp_kses_post( $args['before_widget'] );

		if ( ! empty( $title ) ) {
			echo wp_kses_post( $args['before_title'] ) . esc_html( apply_filters( 'widget_title', $title ) ) . wp_kses_post( $args['after_title'] );
		}

		$this->renderLanguageSwitcher( $current_language, $enabled_languages, $style, $show_flags, $show_native_names );

		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Widget form
	 */
	public function form( $instance ) {
		$title             = isset( $instance['title'] ) ? $instance['title'] : '';
		$style             = isset( $instance['style'] ) ? $instance['style'] : 'dropdown';
		$show_flags        = isset( $instance['show_flags'] ) ? (bool) $instance['show_flags'] : true;
		$show_native_names = isset( $instance['show_native_names'] ) ? (bool) $instance['show_native_names'] : true;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'voxfor-multilanguage' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>"><?php esc_html_e( 'Style:', 'voxfor-multilanguage' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
				<option value="dropdown" <?php selected( $style, 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'voxfor-multilanguage' ); ?></option>
				<option value="inline" <?php selected( $style, 'inline' ); ?>><?php esc_html_e( 'Inline List', 'voxfor-multilanguage' ); ?></option>
				<option value="flags" <?php selected( $style, 'flags' ); ?>><?php esc_html_e( 'Flags Only', 'voxfor-multilanguage' ); ?></option>
				<option value="compact" <?php selected( $style, 'compact' ); ?>><?php esc_html_e( 'Compact', 'voxfor-multilanguage' ); ?></option>
			</select>
		</p>
		
		<p>
			<input class="checkbox" type="checkbox" <?php checked( $show_flags ); ?> id="<?php echo esc_attr( $this->get_field_id( 'show_flags' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_flags' ) ); ?>">
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_flags' ) ); ?>"><?php esc_html_e( 'Show flag icons', 'voxfor-multilanguage' ); ?></label>
		</p>
		
		<p>
			<input class="checkbox" type="checkbox" <?php checked( $show_native_names ); ?> id="<?php echo esc_attr( $this->get_field_id( 'show_native_names' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_native_names' ) ); ?>">
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_native_names' ) ); ?>"><?php esc_html_e( 'Show native language names', 'voxfor-multilanguage' ); ?></label>
		</p>
		<?php
	}

	/**
	 * Update widget settings
	 */
	public function update( $new_instance, $old_instance ) {
		$instance                      = array();
		$instance['title']             = ( ! empty( $new_instance['title'] ) ) ? sanitize_text_field( $new_instance['title'] ) : '';
		$instance['style']             = ( ! empty( $new_instance['style'] ) ) ? sanitize_text_field( $new_instance['style'] ) : 'dropdown';
		$instance['show_flags']        = isset( $new_instance['show_flags'] ) ? (bool) $new_instance['show_flags'] : false;
		$instance['show_native_names'] = isset( $new_instance['show_native_names'] ) ? (bool) $new_instance['show_native_names'] : false;

		return $instance;
	}

	/**
	 * Render language switcher
	 */
	private function renderLanguageSwitcher( $current_language, $enabled_languages, $style, $show_flags, $show_native_names ) {
		$plugin = Plugin::getInstance();
		$router = $plugin->getComponent( 'router' );

		// Get custom display settings
		$custom_label   = get_option( 'voxfor_ml_display_label', 'English' );
		$custom_flag    = get_option( 'voxfor_ml_display_flag', '🇺🇸' );
		$actual_default = get_option( 'voxfor_ml_default_language', 'en' );

		$language_names = array(
			'en' => array( 'English', 'English' ),
			'fr' => array( 'French', 'Français' ),
			'de' => array( 'German', 'Deutsch' ),
			'es' => array( 'Spanish', 'Español' ),
			'it' => array( 'Italian', 'Italiano' ),
			'pt' => array( 'Portuguese', 'Português' ),
			'ru' => array( 'Russian', 'Русский' ),
			'ja' => array( 'Japanese', '日本語' ),
			'zh' => array( 'Chinese', '中文' ),
			'ko' => array( 'Korean', '한국어' ),
			'ar' => array( 'Arabic', 'العربية' ),
			'he' => array( 'Hebrew', 'עברית' ),
			'sv' => array( 'Swedish', 'Svenska' ),
			'no' => array( 'Norwegian', 'Norsk' ),
			'da' => array( 'Danish', 'Dansk' ),
			'fi' => array( 'Finnish', 'Suomi' ),
			'nl' => array( 'Dutch', 'Nederlands' ),
			'pl' => array( 'Polish', 'Polski' ),
			'tr' => array( 'Turkish', 'Türkçe' ),
			'cs' => array( 'Czech', 'Čeština' ),
			'hu' => array( 'Hungarian', 'Magyar' ),
			'ro' => array( 'Romanian', 'Română' ),
			'el' => array( 'Greek', 'Ελληνικά' ),
			'th' => array( 'Thai', 'ไทย' ),
			'vi' => array( 'Vietnamese', 'Tiếng Việt' ),
			'id' => array( 'Indonesian', 'Bahasa Indonesia' ),
			'ms' => array( 'Malay', 'Bahasa Melayu' ),
			'hi' => array( 'Hindi', 'हिन्दी' ),
			'bn' => array( 'Bengali', 'বাংলা' ),
			'uk' => array( 'Ukrainian', 'Українська' ),
		);

		// Use custom label for the actual default language
		$language_names[ $actual_default ] = array( $custom_label, $custom_label );

		$http_host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $http_host . $request_uri;

		echo '<div class="voxfor-ml-language-switcher voxfor-ml-style-' . esc_attr( $style ) . '">';

		switch ( $style ) {
			case 'dropdown':
				$this->renderDropdown( $current_language, $enabled_languages, $language_names, $current_url, $router, $show_flags, $show_native_names );
				break;
			case 'inline':
				$this->renderInline( $current_language, $enabled_languages, $language_names, $current_url, $router, $show_flags, $show_native_names );
				break;
			case 'flags':
				$this->renderFlags( $current_language, $enabled_languages, $language_names, $current_url, $router );
				break;
			case 'compact':
				$this->renderCompact( $current_language, $enabled_languages, $language_names, $current_url, $router, $show_flags, $show_native_names );
				break;
		}

		echo '</div>';
	}

	/**
	 * Render dropdown style
	 */
	private function renderDropdown( $current_language, $enabled_languages, $language_names, $current_url, $router, $show_flags, $show_native_names ) {
		$current_name = $show_native_names ? $language_names[ $current_language ][1] : $language_names[ $current_language ][0];
		$widget_id    = 'voxfor-ml-select-' . wp_rand( 1000, 9999 );

		// Add screen reader label for accessibility
		echo '<label for="' . esc_attr( $widget_id ) . '" class="voxfor-ml-sr-only">' . esc_html__( 'Select Language', 'voxfor-multilanguage' ) . '</label>';

		echo '<select id="' . esc_attr( $widget_id ) . '" class="voxfor-ml-language-select" onchange="window.location.href=this.value" aria-label="' . esc_attr__( 'Language Switcher', 'voxfor-multilanguage' ) . '">';

		foreach ( $enabled_languages as $lang_code ) {
			// Skip if language name is not defined
			if ( ! isset( $language_names[ $lang_code ] ) ) {
				continue;
			}

			$lang_url  = $router->getLanguageUrl( $current_url, $lang_code );
			$lang_name = $show_native_names ? $language_names[ $lang_code ][1] : $language_names[ $lang_code ][0];
			$selected  = ( $lang_code === $current_language ) ? 'selected' : '';

			$flag_emoji   = $this->getFlagEmoji( $lang_code );
			$display_name = $show_flags ? $flag_emoji . ' ' . $lang_name : $lang_name;

			// Add hreflang attribute for SEO
			$custom_prefix  = get_option( 'voxfor_ml_display_prefix', 'en' );
			$actual_default = get_option( 'voxfor_ml_default_language', 'en' );
			$hreflang       = ( $lang_code === $actual_default ) ? $custom_prefix : $lang_code;

			echo '<option value="' . esc_url( $lang_url ) . '" ' . esc_attr( $selected ) . ' data-lang="' . esc_attr( $lang_code ) . '" data-hreflang="' . esc_attr( $hreflang ) . '">' . esc_html( $display_name ) . '</option>';
		}

		echo '</select>';
	}

	/**
	 * Render inline style
	 */
	private function renderInline( $current_language, $enabled_languages, $language_names, $current_url, $router, $show_flags, $show_native_names ) {
		echo '<ul class="voxfor-ml-language-list">';

		foreach ( $enabled_languages as $lang_code ) {
			// Skip if language name is not defined
			if ( ! isset( $language_names[ $lang_code ] ) ) {
				continue;
			}

			$lang_url   = $router->getLanguageUrl( $current_url, $lang_code );
			$lang_name  = $show_native_names ? $language_names[ $lang_code ][1] : $language_names[ $lang_code ][0];
			$is_current = ( $lang_code === $current_language );

			$flag_emoji   = $this->getFlagEmoji( $lang_code );
			$display_name = $show_flags ? $flag_emoji . ' ' . $lang_name : $lang_name;

			echo '<li class="voxfor-ml-language-item' . ( $is_current ? ' current' : '' ) . '">';
			if ( $is_current ) {
				echo '<span class="voxfor-ml-current-language">' . esc_html( $display_name ) . '</span>';
			} else {
				echo '<a href="' . esc_url( $lang_url ) . '" class="voxfor-ml-language-link" hreflang="' . esc_attr( $lang_code ) . '" data-voxfor-lang="' . esc_attr( $lang_code ) . '">' . esc_html( $display_name ) . '</a>';
			}
			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Render flags only style
	 */
	private function renderFlags( $current_language, $enabled_languages, $language_names, $current_url, $router ) {
		echo '<div class="voxfor-ml-flags-container">';

		foreach ( $enabled_languages as $lang_code ) {
			// Skip if language name is not defined
			if ( ! isset( $language_names[ $lang_code ] ) ) {
				continue;
			}

			$lang_url   = $router->getLanguageUrl( $current_url, $lang_code );
			$lang_name  = $language_names[ $lang_code ][1];
			$is_current = ( $lang_code === $current_language );

			$flag_emoji = $this->getFlagEmoji( $lang_code );

			if ( $is_current ) {
				echo '<span class="voxfor-ml-flag current" title="' . esc_attr( $lang_name ) . '">' . esc_html( $flag_emoji ) . '</span>';
			} else {
				echo '<a href="' . esc_url( $lang_url ) . '" class="voxfor-ml-flag" title="' . esc_attr( $lang_name ) . '" hreflang="' . esc_attr( $lang_code ) . '" data-voxfor-lang="' . esc_attr( $lang_code ) . '">' . esc_html( $flag_emoji ) . '</a>';
			}
		}

		echo '</div>';
	}

	/**
	 * Render compact style
	 */
	private function renderCompact( $current_language, $enabled_languages, $language_names, $current_url, $router, $show_flags, $show_native_names ) {
		$current_name = $show_native_names ? $language_names[ $current_language ][1] : $language_names[ $current_language ][0];
		$flag_emoji   = $this->getFlagEmoji( $current_language );
		$display_name = $show_flags ? $flag_emoji . ' ' . $current_name : $current_name;

		echo '<div class="voxfor-ml-compact-switcher">';
		echo '<button class="voxfor-ml-current-lang" onclick="this.nextElementSibling.classList.toggle(\'show\')">' . esc_html( $display_name ) . ' ▼</button>';
		echo '<div class="voxfor-ml-lang-dropdown">';

		foreach ( $enabled_languages as $lang_code ) {
			if ( $lang_code === $current_language ) {
				continue;
			}

			// Skip if language name is not defined
			if ( ! isset( $language_names[ $lang_code ] ) ) {
				continue;
			}

			$lang_url  = $router->getLanguageUrl( $current_url, $lang_code );
			$lang_name = $show_native_names ? $language_names[ $lang_code ][1] : $language_names[ $lang_code ][0];

			$flag_emoji   = $this->getFlagEmoji( $lang_code );
			$display_name = $show_flags ? $flag_emoji . ' ' . $lang_name : $lang_name;

			echo '<a href="' . esc_url( $lang_url ) . '" class="voxfor-ml-lang-option" hreflang="' . esc_attr( $lang_code ) . '" data-voxfor-lang="' . esc_attr( $lang_code ) . '">' . esc_html( $display_name ) . '</a>';
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Get flag emoji for language
	 */
	private function getFlagEmoji( $lang_code ) {
		$flags = array(
			'en' => '🇺🇸',
			'fr' => '🇫🇷',
			'de' => '🇩🇪',
			'es' => '🇪🇸',
			'it' => '🇮🇹',
			'pt' => '🇵🇹',
			'ru' => '🇷🇺',
			'ja' => '🇯🇵',
			'zh' => '🇨🇳',
			'ko' => '🇰🇷',
			'ar' => '🇸🇦',
			'he' => '🇮🇱',
			'sv' => '🇸🇪',
			'no' => '🇳🇴',
			'da' => '🇩🇰',
			'fi' => '🇫🇮',
			'nl' => '🇳🇱',
			'pl' => '🇵🇱',
			'tr' => '🇹🇷',
			'cs' => '🇨🇿',
			'hu' => '🇭🇺',
			'ro' => '🇷🇴',
			'el' => '🇬🇷',
			'th' => '🇹🇭',
			'vi' => '🇻🇳',
			'id' => '🇮🇩',
			'ms' => '🇲🇾',
			'hi' => '🇮🇳',
			'bn' => '🇧🇩',
			'uk' => '🇺🇦',
		);

		// Use custom flag for the actual default language
		$custom_flag    = get_option( 'voxfor_ml_display_flag', '🇺🇸' );
		$actual_default = get_option( 'voxfor_ml_default_language', 'en' );

		if ( $lang_code === $actual_default ) {
			return $custom_flag;
		}

		return isset( $flags[ $lang_code ] ) ? $flags[ $lang_code ] : '🌐';
	}
}