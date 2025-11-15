<?php
namespace VoxforML\Compatibility;

use VoxforML\Core\Plugin;

/**
 * Ensures compatibility with all modern WordPress themes
 * Focus on supporting new WordPress themes as requested by user
 */
class ThemeCompatibility {
	private $plugin;
	private $supported_themes;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin = Plugin::getInstance();
		$this->initSupportedThemes();
		$this->init();
	}

	/**
	 * Initialize supported themes list
	 */
	private function initSupportedThemes() {
		$this->supported_themes = array(
			// WordPress Core Themes (2024 and newer)
			'twentytwentyfour'  => 'Twenty Twenty-Four',
			'twentytwentythree' => 'Twenty Twenty-Three',
			'twentytwentytwo'   => 'Twenty Twenty-Two',
			'twentytwentyone'   => 'Twenty Twenty-One',
			'twentytwenty'      => 'Twenty Twenty',

			// Popular Commercial Themes
			'astra'             => 'Astra',
			'generatepress'     => 'GeneratePress',
			'oceanwp'           => 'OceanWP',
			'kadence'           => 'Kadence',
			'neve'              => 'Neve',
			'blocksy'           => 'Blocksy',
			'hello-elementor'   => 'Hello Elementor',
			'storefront'        => 'Storefront',
			'divi'              => 'Divi',
			'avada'             => 'Avada',
			'enfold'            => 'Enfold',
			'the7'              => 'The7',
			'betheme'           => 'BeTheme',
			'x'                 => 'X Theme',
			'pro'               => 'Pro Theme',

			// Block Themes (FSE)
			'tt1-blocks'        => 'Twenty Twenty-One Blocks',
			'frost'             => 'Frost',
			'blockbase'         => 'Blockbase',
			'emptytheme'        => 'Empty Theme',
			'quadrat'           => 'Quadrat',
			'tove'              => 'Tove',
			'livro'             => 'Livro',
			'pendant'           => 'Pendant',
			'archeo'            => 'Archeo',
			'naledi'            => 'Naledi',
		);
	}

	/**
	 * Initialize theme compatibility
	 */
	public function init() {
		add_action( 'after_setup_theme', array( $this, 'setupThemeSupport' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueThemeSpecificStyles' ), 20 );
		add_filter( 'body_class', array( $this, 'addLanguageBodyClasses' ) );
		add_action( 'wp_head', array( $this, 'addThemeSpecificMeta' ), 1 );

		// Block theme support
		add_action( 'init', array( $this, 'registerBlockPatterns' ) );
		add_filter( 'block_categories_all', array( $this, 'addBlockCategories' ) );

		// Theme switching compatibility
		add_action( 'switch_theme', array( $this, 'handleThemeSwitch' ), 10, 3 );
		add_action( 'voxfor_ml_theme_switched', array( $this, 'clearTranslationCache' ) );

		// Theme-specific hooks
		$this->setupThemeSpecificHooks();
	}

	/**
	 * Setup theme support
	 */
	public function setupThemeSupport() {
		$current_theme = get_template();

		// Add theme support for language switcher
		add_theme_support(
			'voxfor-multilanguage',
			array(
				'language-switcher' => true,
				'auto-placement'    => true,
				'widget-areas'      => true,
				'menu-integration'  => true,
				'block-support'     => true,
			)
		);

		// Theme-specific configurations
		switch ( $current_theme ) {
			case 'twentytwentyfour':
			case 'twentytwentythree':
			case 'twentytwentytwo':
				$this->setupBlockThemeSupport();
				break;

			case 'astra':
				$this->setupAstraSupport();
				break;

			case 'generatepress':
				$this->setupGeneratePressSupport();
				break;

			case 'oceanwp':
				$this->setupOceanWPSupport();
				break;

			case 'kadence':
				$this->setupKadenceSupport();
				break;

			case 'neve':
				$this->setupNeveSupport();
				break;

			case 'divi':
				$this->setupDiviSupport();
				break;

			default:
				// Generic theme support - no specific setup needed
				break;
		}
	}

	/**
	 * Setup Block Theme support (Twenty Twenty-Four, etc.)
	 */
	private function setupBlockThemeSupport() {
		// Add language switcher to navigation blocks
		add_filter( 'render_block_core/navigation', array( $this, 'addLanguageSwitcherToNavigation' ), 10, 2 );

		// FSE template part support
		add_filter( 'get_block_template', array( $this, 'translateBlockTemplate' ), 10, 3 );
	}

	/**
	 * Setup theme-specific hooks
	 */
	private function setupThemeSpecificHooks() {
		$current_theme = get_template();

		// Theme-specific CSS classes
		add_filter(
			'voxfor_ml_widget_classes',
			function ( $classes ) use ( $current_theme ) {
				$classes[] = 'voxfor-ml-theme-' . $current_theme;
				return $classes;
			}
		);
	}

	/**
	 * Enqueue theme-specific styles
	 */
	public function enqueueThemeSpecificStyles() {
		$current_theme = get_template();

		// Add inline CSS for theme compatibility
		$inline_css = $this->getThemeSpecificCSS( $current_theme );
		if ( $inline_css ) {
			// Escape late: sanitize CSS to prevent any potential injection
			wp_add_inline_style( 'voxfor-ml-frontend', wp_strip_all_tags( $inline_css ) );
		}
	}

	/**
	 * Get theme-specific CSS
	 */
	private function getThemeSpecificCSS( $theme ) {
		$css_rules = array(
			'twentytwentyfour' => '
                .wp-site-blocks .voxfor-ml-language-switcher {
                    margin: 0;
                    font-family: var(--wp--preset--font-family--system-font, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif);
                }
                .wp-block-navigation .voxfor-ml-language-switcher {
                    display: inline-flex;
                    align-items: center;
                }
            ',
			'astra'            => '
                .ast-header-break-point .voxfor-ml-language-switcher {
                    margin: 10px 0;
                }
                .ast-desktop .voxfor-ml-language-switcher {
                    margin: 0 15px;
                }
            ',
			'generatepress'    => '
                .inside-navigation .voxfor-ml-language-switcher {
                    margin-left: 20px;
                    display: inline-flex;
                    align-items: center;
                }
            ',
			'oceanwp'          => '
                #site-header .voxfor-ml-language-switcher {
                    margin: 0 15px;
                    display: inline-flex;
                    align-items: center;
                }
            ',
			'kadence'          => '
                .site-header .voxfor-ml-language-switcher {
                    margin: 0 var(--global-kb-spacing-sm, 1rem);
                }
            ',
			'neve'             => '
                .header--row .voxfor-ml-language-switcher {
                    margin: 0 15px;
                    display: flex;
                    align-items: center;
                }
            ',
		);

		return $css_rules[ $theme ] ?? '';
	}

	/**
	 * Add language body classes
	 */
	public function addLanguageBodyClasses( $classes ) {
		$current_lang  = $this->plugin->getCurrentLanguage();
		$current_theme = get_template();

		$classes[] = 'voxfor-ml-lang-' . $current_lang;
		$classes[] = 'voxfor-ml-theme-' . $current_theme;

		if ( $current_lang !== 'en' ) {
			$classes[] = 'voxfor-ml-translated';
		}

		return $classes;
	}

	/**
	 * Add theme-specific meta tags
	 */
	public function addThemeSpecificMeta() {
		$current_theme = get_template();
		$current_lang  = $this->plugin->getCurrentLanguage();

		// Add theme compatibility meta
		echo '<meta name="voxfor-ml-theme" content="' . esc_attr( $current_theme ) . '" />' . "\n";

		// Use display language for meta tag (cosmetic)
		$display_lang = ( $current_lang === 'en' ) ? get_option( 'voxfor_ml_display_prefix', 'en' ) : $current_lang;
		echo '<meta name="voxfor-ml-language" content="' . esc_attr( $display_lang ) . '" />' . "\n";

		// Add theme-specific viewport adjustments for RTL languages
		if ( in_array( $current_lang, array( 'ar', 'he', 'fa', 'ur' ) ) ) {
			echo '<meta name="voxfor-ml-direction" content="rtl" />' . "\n";
		}
	}

	/**
	 * Register block patterns for language switcher
	 */
	public function registerBlockPatterns() {
		if ( ! function_exists( 'register_block_pattern_category' ) ) {
			return;
		}

		register_block_pattern_category(
			'voxfor-multilanguage',
			array(
				'label'       => __( 'Voxfor Multilanguage', 'voxfor-multilanguage' ),
				'description' => __( 'Language switcher patterns', 'voxfor-multilanguage' ),
			)
		);
	}

	/**
	 * Add block categories
	 */
	public function addBlockCategories( $categories ) {
		return array_merge(
			$categories,
			array(
				array(
					'slug'  => 'voxfor-multilanguage',
					'title' => __( 'Voxfor Multilanguage', 'voxfor-multilanguage' ),
					'icon'  => 'translation',
				),
			)
		);
	}

	/**
	 * Add language switcher to navigation block
	 */
	public function addLanguageSwitcherToNavigation( $block_content, $block ) {
		if ( ! get_option( 'voxfor_ml_auto_add_to_navigation', false ) ) {
			return $block_content;
		}

		$language_switcher = do_shortcode( '[voxfor_language_switcher style="compact"]' );

		// Add before closing </ul> tag
		$block_content = str_replace( '</ul>', $language_switcher . '</ul>', $block_content );

		return $block_content;
	}

	/**
	 * Translate block template
	 */
	public function translateBlockTemplate( $block_template, $id, $template_type ) {
		if ( ! $block_template || ! $block_template->content ) {
			return $block_template;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		if ( $current_lang === 'en' ) {
			return $block_template;
		}

		// Translate template content
		$translator = $this->plugin->getComponent( 'translator' );
		if ( $translator ) {
			$block_template->content = $translator->translateText(
				$block_template->content,
				$current_lang,
				'block_template'
			);
		}

		return $block_template;
	}

	/**
	 * Setup Astra theme support
	 */
	private function setupAstraSupport() {
		// Astra theme specific configuration
		add_action( 'astra_header', array( $this, 'addAstraLanguageSwitcher' ), 15 );
		add_filter( 'astra_header_elements', array( $this, 'addAstraHeaderElement' ) );

		// Astra customizer integration
		add_action( 'customize_register', array( $this, 'addAstraCustomizerOptions' ) );

		// Astra mobile menu support
		add_action( 'astra_mobile_header', array( $this, 'addAstraMobileLanguageSwitcher' ), 10 );
	}

	/**
	 * Setup GeneratePress theme support
	 */
	private function setupGeneratePressSupport() {
		// GeneratePress theme specific configuration
		add_action( 'generate_header', array( $this, 'addGeneratePressLanguageSwitcher' ), 15 );
		add_filter( 'generate_navigation_class', array( $this, 'addGeneratePressNavClass' ) );

		// GeneratePress Elements compatibility
		add_filter( 'generate_element_display', array( $this, 'filterGenerateElementDisplay' ), 10, 2 );
	}

	/**
	 * Setup OceanWP theme support
	 */
	private function setupOceanWPSupport() {
		// OceanWP theme specific configuration
		add_action( 'ocean_header', array( $this, 'addOceanWPLanguageSwitcher' ), 15 );
		add_filter( 'ocean_menu_social_options', array( $this, 'addOceanWPSocialOption' ) );

		// OceanWP Customizer integration
		add_action( 'customize_register', array( $this, 'addOceanWPCustomizerOptions' ) );
	}

	/**
	 * Setup Kadence theme support
	 */
	private function setupKadenceSupport() {
		// Kadence theme specific configuration
		add_action( 'kadence_header', array( $this, 'addKadenceLanguageSwitcher' ), 15 );
		add_filter( 'kadence_theme_options', array( $this, 'addKadenceThemeOptions' ) );

		// Kadence Blocks compatibility
		add_filter( 'kadence_blocks_default_color_palette', array( $this, 'updateKadenceColorPalette' ) );
	}

	/**
	 * Setup Neve theme support
	 */
	private function setupNeveSupport() {
		// Neve theme specific configuration
		add_action( 'neve_do_header', array( $this, 'addNeveLanguageSwitcher' ), 15 );
		add_filter( 'neve_nav_menu_args', array( $this, 'addNeveNavMenuArgs' ) );

		// Neve Pro compatibility
		if ( defined( 'NEVE_PRO_VERSION' ) ) {
			add_filter( 'neve_pro_header_elements', array( $this, 'addNeveProHeaderElement' ) );
		}
	}

	/**
	 * Setup Divi theme support
	 */
	private function setupDiviSupport() {
		// Divi theme specific configuration
		add_action( 'et_header_top', array( $this, 'addDiviLanguageSwitcher' ), 15 );
		add_filter( 'et_builder_load_actions', array( $this, 'addDiviBuilderActions' ) );

		// Divi Builder module registration
		add_action( 'et_builder_ready', array( $this, 'registerDiviLanguageSwitcherModule' ) );
	}

	/**
	 * Add language switcher to Astra header
	 */
	public function addAstraLanguageSwitcher() {
		if ( get_option( 'voxfor_ml_auto_add_to_astra', false ) ) {
			echo '<div class="ast-voxfor-ml-switcher">';
			echo do_shortcode( '[voxfor_language_switcher style="compact"]' );
			echo '</div>';
		}
	}

	/**
	 * Add Astra header element
	 */
	public function addAstraHeaderElement( $elements ) {
		if ( get_option( 'voxfor_ml_auto_add_to_astra', false ) ) {
			$elements['language-switcher'] = array(
				'name'     => __( 'Language Switcher', 'voxfor-multilanguage' ),
				'callback' => array( $this, 'addAstraLanguageSwitcher' ),
			);
		}
		return $elements;
	}

	/**
	 * Add Astra mobile language switcher
	 */
	public function addAstraMobileLanguageSwitcher() {
		if ( get_option( 'voxfor_ml_auto_add_to_astra_mobile', false ) ) {
			echo '<div class="ast-mobile-voxfor-ml-switcher">';
			echo do_shortcode( '[voxfor_language_switcher style="dropdown"]' );
			echo '</div>';
		}
	}

	/**
	 * Add Astra customizer options
	 */
	public function addAstraCustomizerOptions( $wp_customize ) {
		// Add section for language switcher
		$wp_customize->add_section(
			'voxfor_ml_astra_section',
			array(
				'title'    => __( 'Voxfor Language Switcher', 'voxfor-multilanguage' ),
				'priority' => 200,
			)
		);

		// Auto-add to header option
		$wp_customize->add_setting(
			'voxfor_ml_auto_add_to_astra',
			array(
				'default'   => false,
				'transport' => 'refresh',
			)
		);

		$wp_customize->add_control(
			'voxfor_ml_auto_add_to_astra',
			array(
				'type'        => 'checkbox',
				'section'     => 'voxfor_ml_astra_section',
				'label'       => __( 'Auto-add to header', 'voxfor-multilanguage' ),
				'description' => __( 'Automatically add language switcher to Astra header', 'voxfor-multilanguage' ),
			)
		);
	}

	/**
	 * Add language switcher to GeneratePress header
	 */
	public function addGeneratePressLanguageSwitcher() {
		if ( get_option( 'voxfor_ml_auto_add_to_generatepress', false ) ) {
			echo '<div class="gp-voxfor-ml-switcher">';
			echo do_shortcode( '[voxfor_language_switcher style="inline"]' );
			echo '</div>';
		}
	}

	/**
	 * Add GeneratePress navigation class
	 */
	public function addGeneratePressNavClass( $classes ) {
		if ( get_option( 'voxfor_ml_auto_add_to_generatepress', false ) ) {
			$classes[] = 'has-language-switcher';
		}
		return $classes;
	}

	/**
	 * Filter GeneratePress element display
	 */
	public function filterGenerateElementDisplay( $display, $element_id ) {
		$current_lang = $this->plugin->getCurrentLanguage();

		// Check if element has language restrictions
		$restricted_langs = get_post_meta( $element_id, '_voxfor_ml_element_languages', true );
		if ( ! empty( $restricted_langs ) && ! in_array( $current_lang, $restricted_langs ) ) {
			return false;
		}

		return $display;
	}

	/**
	 * Add language switcher to OceanWP header
	 */
	public function addOceanWPLanguageSwitcher() {
		if ( get_option( 'voxfor_ml_auto_add_to_oceanwp', false ) ) {
			echo '<div class="oceanwp-voxfor-ml-switcher">';
			echo do_shortcode( '[voxfor_language_switcher style="flags"]' );
			echo '</div>';
		}
	}

	/**
	 * Add OceanWP social option
	 */
	public function addOceanWPSocialOption( $options ) {
		$options['language_switcher'] = __( 'Language Switcher', 'voxfor-multilanguage' );
		return $options;
	}

	/**
	 * Add OceanWP customizer options
	 */
	public function addOceanWPCustomizerOptions( $wp_customize ) {
		// Similar to Astra implementation
		$wp_customize->add_section(
			'voxfor_ml_oceanwp_section',
			array(
				'title'    => __( 'Voxfor Language Switcher', 'voxfor-multilanguage' ),
				'priority' => 200,
			)
		);

		$wp_customize->add_setting(
			'voxfor_ml_auto_add_to_oceanwp',
			array(
				'default'   => false,
				'transport' => 'refresh',
			)
		);

		$wp_customize->add_control(
			'voxfor_ml_auto_add_to_oceanwp',
			array(
				'type'    => 'checkbox',
				'section' => 'voxfor_ml_oceanwp_section',
				'label'   => __( 'Auto-add to header', 'voxfor-multilanguage' ),
			)
		);
	}

	/**
	 * Add language switcher to Kadence header
	 */
	public function addKadenceLanguageSwitcher() {
		if ( get_option( 'voxfor_ml_auto_add_to_kadence', false ) ) {
			echo '<div class="kadence-voxfor-ml-switcher">';
			echo do_shortcode( '[voxfor_language_switcher style="compact"]' );
			echo '</div>';
		}
	}

	/**
	 * Add Kadence theme options
	 */
	public function addKadenceThemeOptions( $options ) {
		$options['language_switcher_enabled'] = get_option( 'voxfor_ml_auto_add_to_kadence', false );
		return $options;
	}

	/**
	 * Update Kadence color palette
	 */
	public function updateKadenceColorPalette( $palette ) {
		// Add language switcher specific colors if needed
		$palette[] = array(
			'color' => '#0073aa',
			'name'  => __( 'Language Switcher Blue', 'voxfor-multilanguage' ),
		);
		return $palette;
	}

	/**
	 * Add language switcher to Neve header
	 */
	public function addNeveLanguageSwitcher() {
		if ( get_option( 'voxfor_ml_auto_add_to_neve', false ) ) {
			echo '<div class="neve-voxfor-ml-switcher">';
			echo do_shortcode( '[voxfor_language_switcher style="dropdown"]' );
			echo '</div>';
		}
	}

	/**
	 * Add Neve nav menu args
	 */
	public function addNeveNavMenuArgs( $args ) {
		if ( get_option( 'voxfor_ml_auto_add_to_neve', false ) ) {
			$args['container_class'] = isset( $args['container_class'] ) ? $args['container_class'] . ' has-language-switcher' : 'has-language-switcher';
		}
		return $args;
	}

	/**
	 * Add Neve Pro header element
	 */
	public function addNeveProHeaderElement( $elements ) {
		$elements['language_switcher'] = array(
			'name'     => __( 'Language Switcher', 'voxfor-multilanguage' ),
			'callback' => array( $this, 'addNeveLanguageSwitcher' ),
		);
		return $elements;
	}

	/**
	 * Add language switcher to Divi header
	 */
	public function addDiviLanguageSwitcher() {
		if ( get_option( 'voxfor_ml_auto_add_to_divi', false ) ) {
			echo '<div class="et-voxfor-ml-switcher">';
			echo do_shortcode( '[voxfor_language_switcher style="inline"]' );
			echo '</div>';
		}
	}

	/**
	 * Add Divi builder actions
	 */
	public function addDiviBuilderActions( $actions ) {
		$actions[] = 'voxfor_ml_language_switcher';
		return $actions;
	}

	/**
	 * Register Divi language switcher module
	 */
	public function registerDiviLanguageSwitcherModule() {
		if ( class_exists( 'ET_Builder_Module' ) ) {
			// Register custom Divi module for language switcher
			// This would require a separate module class file
		}
	}

	/**
	 * Check if current theme is supported
	 */
	public function isThemeSupported( $theme = null ) {
		$theme = $theme ?: get_template();
		return isset( $this->supported_themes[ $theme ] );
	}

	/**
	 * Get supported themes list
	 */
	public function getSupportedThemes() {
		return $this->supported_themes;
	}

	/**
	 * Handle theme switching
	 */
	public function handleThemeSwitch( $new_name, $new_theme, $old_theme ) {
		// Clear all translation caches when theme switches
		$this->clearTranslationCache();

		// Clear WordPress object cache
		wp_cache_flush();

		// Clear any page caches
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// Update theme tracking
		update_option( 'voxfor_ml_current_theme', $new_name );
		update_option( 'voxfor_ml_theme_switched', time() );

		// Log theme switch for debugging

		// Trigger plugin cache clear
		do_action( 'voxfor_ml_theme_switched' );
	}

	/**
	 * Clear translation cache
	 */
	public function clearTranslationCache() {
		global $wpdb;

		// Clear translation memory cache
		$cache_table = $wpdb->prefix . 'voxfor_ml_cache';
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $cache_table ) ) === $cache_table ) {
			$wpdb->query( $wpdb->prepare( "TRUNCATE TABLE %s", $cache_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Clear URL cache
		delete_transient( 'voxfor_ml_language_urls' );
		delete_transient( 'voxfor_ml_theme_urls' );

		// Clear object cache
		wp_cache_delete( 'voxfor_ml_translations', 'voxfor_ml' );
		wp_cache_delete( 'voxfor_ml_urls', 'voxfor_ml' );

		// Clear any theme-specific options
		$theme_options = array(
			'voxfor_ml_auto_add_to_astra',
			'voxfor_ml_auto_add_to_generatepress',
			'voxfor_ml_auto_add_to_oceanwp',
			'voxfor_ml_auto_add_to_kadence',
			'voxfor_ml_auto_add_to_neve',
			'voxfor_ml_auto_add_to_divi',
		);

		foreach ( $theme_options as $option ) {
			delete_option( $option );
		}
	}
}
