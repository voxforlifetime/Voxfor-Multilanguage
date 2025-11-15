<?php
namespace VoxforML\Integrations;

use VoxforML\Core\Plugin;

/**
 * Divi Builder Integration
 */
class DiviIntegration {
	private $plugin;
	private $translator;
	private $memory;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin     = Plugin::getInstance();
		$this->translator = $this->plugin->getComponent( 'translator' );
		$this->memory     = $this->plugin->getComponent( 'translation_memory' );

		$this->initHooks();
	}

	/**
	 * Initialize hooks
	 */
	private function initHooks() {
		// Only initialize if Divi is active
		if ( ! function_exists( 'et_core_is_builder_used_on_current_request' ) &&
			! class_exists( 'ET_Builder_Element' ) ) {
			return;
		}

		// Hook into Divi content rendering
		add_filter( 'the_content', array( $this, 'translateDiviContent' ), 15, 1 );

		// Hook into Divi shortcodes
		add_filter( 'et_builder_render_layout', array( $this, 'translateDiviLayout' ), 10, 2 );

		// Add Divi meta box for translation status
		add_action( 'add_meta_boxes', array( $this, 'addDiviMetaBox' ) );

		// Enqueue admin scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminScripts' ) );

		// AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_translate_divi_page', array( $this, 'ajaxTranslateDiviPage' ) );

		// Register translatable Divi modules
		add_action( 'init', array( $this, 'registerTranslatableModules' ) );
	}

	/**
	 * Register translatable Divi modules
	 */
	public function registerTranslatableModules() {
		$this->translatable_modules = array(
			'et_pb_text'                 => array( 'content' ),
			'et_pb_blurb'                => array( 'title', 'content' ),
			'et_pb_cta'                  => array( 'title', 'button_text', 'content' ),
			'et_pb_button'               => array( 'button_text' ),
			'et_pb_contact_form'         => array( 'title', 'success_message' ),
			'et_pb_contact_field'        => array( 'field_title' ),
			'et_pb_accordion'            => array( 'title' ),
			'et_pb_accordion_item'       => array( 'title', 'content' ),
			'et_pb_tabs'                 => array(),
			'et_pb_tab'                  => array( 'title', 'content' ),
			'et_pb_toggle'               => array( 'title', 'content' ),
			'et_pb_pricing_tables'       => array(),
			'et_pb_pricing_table'        => array( 'title', 'subtitle', 'currency', 'button_text' ),
			'et_pb_testimonial'          => array( 'author', 'job_title', 'company_name', 'content' ),
			'et_pb_slide'                => array( 'heading', 'button_text', 'content' ),
			'et_pb_slider'               => array(),
			'et_pb_fullwidth_slider'     => array(),
			'et_pb_image'                => array( 'alt' ),
			'et_pb_gallery'              => array(),
			'et_pb_countdown_timer'      => array( 'title' ),
			'et_pb_number_counter'       => array( 'title', 'number' ),
			'et_pb_circle_counter'       => array( 'title', 'number' ),
			'et_pb_bar_counter'          => array( 'title' ),
			'et_pb_blog'                 => array(),
			'et_pb_portfolio'            => array(),
			'et_pb_filterable_portfolio' => array(),
			'et_pb_fullwidth_portfolio'  => array(),
			'et_pb_shop'                 => array(),
			'et_pb_wc_breadcrumb'        => array(),
			'et_pb_wc_cart_notice'       => array(),
			'et_pb_signup'               => array( 'title', 'button_text', 'description' ),
			'et_pb_login'                => array( 'title' ),
			'et_pb_search'               => array( 'placeholder' ),
			'et_pb_sidebar'              => array(),
			'et_pb_social_media_follow'  => array(),
			'et_pb_fullwidth_code'       => array( 'content' ),
			'et_pb_code'                 => array( 'content' ),
		);
	}

	/**
	 * Translate Divi content
	 */
	public function translateDiviContent( $content ) {
		if ( ! $this->shouldTranslate() || ! $this->isDiviContent( $content ) ) {
			return $content;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $content;
		}

		return $this->translateDiviShortcodes( $content, $current_lang );
	}

	/**
	 * Translate Divi layout
	 */
	public function translateDiviLayout( $content, $post_id ) {
		if ( ! $this->shouldTranslate() ) {
			return $content;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $content;
		}

		return $this->translateDiviShortcodes( $content, $current_lang );
	}

	/**
	 * Check if content contains Divi shortcodes
	 */
	private function isDiviContent( $content ) {
		return strpos( $content, '[et_pb_' ) !== false;
	}

	/**
	 * Translate Divi shortcodes
	 */
	private function translateDiviShortcodes( $content, $language ) {
		// Parse shortcodes and translate attributes
		$pattern = '/\[et_pb_(\w+)([^\]]*)\]/';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $language ) {
				$module     = $matches[1];
				$attributes = $matches[2];

				if ( ! isset( $this->translatable_modules[ 'et_pb_' . $module ] ) ) {
					return $matches[0]; // Return unchanged if not translatable
				}

				$translatable_attrs = $this->translatable_modules[ 'et_pb_' . $module ];

				// Parse attributes
				$parsed_attrs = $this->parseShortcodeAttributes( $attributes );

				// Translate each translatable attribute
				foreach ( $translatable_attrs as $attr ) {
					if ( isset( $parsed_attrs[ $attr ] ) && ! empty( $parsed_attrs[ $attr ] ) ) {
						$original_value = $parsed_attrs[ $attr ];
						$context        = 'divi_' . $module . '_' . $attr;

						$translated = $this->memory->getTranslation( $original_value, $language, $context );
						if ( $translated ) {
							$parsed_attrs[ $attr ] = $translated;
						}
					}
				}

				// Rebuild shortcode
				$new_attributes = $this->buildShortcodeAttributes( $parsed_attrs );
				return '[et_pb_' . $module . $new_attributes . ']';
			},
			$content
		);
	}

	/**
	 * Parse shortcode attributes
	 */
	private function parseShortcodeAttributes( $attr_string ) {
		$attributes = array();

		// Match attribute="value" patterns
		preg_match_all( '/(\w+)="([^"]*)"/', $attr_string, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$attributes[ $match[1] ] = $match[2];
		}

		return $attributes;
	}

	/**
	 * Build shortcode attributes string
	 */
	private function buildShortcodeAttributes( $attributes ) {
		$attr_string = '';

		foreach ( $attributes as $key => $value ) {
			$attr_string .= ' ' . $key . '="' . esc_attr( $value ) . '"';
		}

		return $attr_string;
	}

	/**
	 * Add Divi translation meta box
	 */
	public function addDiviMetaBox() {
		// Only add to pages that use Divi
		$screen = get_current_screen();
		if ( $screen && $screen->post_type ) {
			add_meta_box(
				'voxfor_ml_divi_translations',
				__( 'Divi Translations', 'voxfor-multilanguage' ),
				array( $this, 'renderDiviMetaBox' ),
				$screen->post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render Divi translation meta box
	 */
	public function renderDiviMetaBox( $post ) {
		// Check if this page uses Divi
		$divi_content = get_post_meta( $post->ID, '_et_pb_use_builder', true );
		if ( $divi_content !== 'on' ) {
			echo '<p>' . esc_html__( 'This page does not use Divi Builder.', 'voxfor-multilanguage' ) . '</p>';
			return;
		}

		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();

		wp_nonce_field( 'voxfor_ml_divi_translations', 'voxfor_ml_divi_nonce' );

		?>
		<div class="voxfor-ml-divi-translations">
			<p><strong><?php esc_html_e( 'Divi Translation Status:', 'voxfor-multilanguage' ); ?></strong></p>
			
			<?php foreach ( $languages as $lang ) : ?>
				<?php
				if ( $lang === $default_lang ) {
					continue;}
				?>
				
				<?php
				// Count translatable modules
				$divi_modules       = $this->countDiviModules( $post->post_content );
				$translated_modules = $this->countTranslatedModules( $post->post_content, $lang );

				$percentage = $divi_modules > 0 ? round( ( $translated_modules / $divi_modules ) * 100 ) : 0;
				?>
				
				<div class="voxfor-ml-divi-lang" style="margin-bottom: 10px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
					<div style="display: flex; align-items: center; justify-content: space-between;">
											<span>
						<?php echo esc_html( $this->getLanguageFlag( $lang ) ); ?>
						<strong><?php echo esc_html( $this->getLanguageName( $lang ) ); ?></strong>
					</span>
					<span style="color: <?php echo esc_attr( $percentage === 100 ? '#46b450' : ( $percentage > 0 ? '#f56e28' : '#dc3232' ) ); ?>">
						<?php echo esc_html( $percentage ); ?>%
					</span>
					</div>
					
					<div style="margin-top: 5px;">
						<div style="background: #f0f0f0; height: 4px; border-radius: 2px;">
							<div style="background: <?php echo esc_attr( $percentage === 100 ? '#46b450' : '#f56e28' ); ?>; height: 100%; width: <?php echo esc_attr( $percentage ); ?>%; border-radius: 2px;"></div>
						</div>
					</div>
					
					<div style="margin-top: 8px;">
						<small><?php printf( /* translators: %1$d is the number of translated modules, %2$d is the total number of modules */ esc_html__( '%1$d of %2$d modules translated', 'voxfor-multilanguage' ), intval( $translated_modules ), intval( $divi_modules ) ); ?></small>
					</div>
					
					<div style="margin-top: 8px;">
						<button type="button" class="button button-small voxfor-ml-translate-divi" 
								data-post-id="<?php echo intval( $post->ID ); ?>" 
								data-language="<?php echo esc_attr( $lang ); ?>">
							<?php echo esc_html( $percentage === 100 ? __( 'Update', 'voxfor-multilanguage' ) : __( 'Translate', 'voxfor-multilanguage' ) ); ?>
						</button>
						
						<?php if ( $percentage > 0 ) : ?>
							<a href="<?php echo esc_url( $this->plugin->getComponent( 'router' )->getLanguageUrl( $lang, get_permalink( intval( $post->ID ) ) ) ); ?>" 
								target="_blank" class="button button-small">
								<?php esc_html_e( 'Preview', 'voxfor-multilanguage' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
			
			<div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd;">
				<button type="button" class="button button-primary voxfor-ml-translate-all-divi" 
						data-post-id="<?php echo intval( $post->ID ); ?>">
					<?php esc_html_e( 'Translate All Divi Modules', 'voxfor-multilanguage' ); ?>
				</button>
			</div>
		</div>
		
<?php
// Divi Integration JavaScript is now in public/js/admin/divi-integration.js
// Script is properly enqueued via wp_enqueue_script with localization
?>
		<?php
	}

	/**
	 * Enqueue admin scripts for Divi integration
	 */
	public function enqueueAdminScripts( $hook ) {
		// Only enqueue on post edit screens
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
			return;
		}

		// Check if current post uses Divi
		global $post;
		if ( ! $post || ! $this->isUsingDivi( $post->ID ) ) {
			return;
		}

		// Enqueue Divi integration script
		wp_enqueue_script(
			'voxfor-ml-divi-integration',
			VOXFOR_ML_PLUGIN_URL . 'public/js/admin/divi-integration.js',
			array( 'jquery' ),
			VOXFOR_ML_VERSION,
			true
		);

		// Localize script
		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();

		wp_localize_script(
			'voxfor-ml-divi-integration',
			'voxforDivi',
			array(
				'nonce'     => wp_create_nonce( 'voxfor_ml_divi_translate' ),
				'languages' => array_values( array_diff( $languages, array( $default_lang ) ) ),
				'strings'   => array(
					'translating'        => __( 'Translating...', 'voxfor-multilanguage' ),
					'translate'          => __( 'Translate', 'voxfor-multilanguage' ),
					'error'              => __( 'Error', 'voxfor-multilanguage' ),
					'confirmTranslateAll' => __( 'Translate all Divi modules to all languages?', 'voxfor-multilanguage' ),
				),
			)
		);
	}

	/**
	 * Count Divi modules
	 */
	private function countDiviModules( $content ) {
		$count = 0;

		foreach ( $this->translatable_modules as $module => $attrs ) {
			if ( ! empty( $attrs ) ) {
				$count += substr_count( $content, '[' . $module );
			}
		}

		return $count;
	}

	/**
	 * Count translated modules
	 */
	private function countTranslatedModules( $content, $language ) {
		$count = 0;

		// Parse all modules and check if their translatable attributes are translated
		foreach ( $this->translatable_modules as $module => $attrs ) {
			if ( empty( $attrs ) ) {
				continue;
			}

			$pattern = '/\[' . preg_quote( $module ) . '([^\]]*)\]/';
			preg_match_all( $pattern, $content, $matches );

			foreach ( $matches[1] as $attr_string ) {
				$parsed_attrs      = $this->parseShortcodeAttributes( $attr_string );
				$module_translated = false;

				foreach ( $attrs as $attr ) {
					if ( isset( $parsed_attrs[ $attr ] ) && ! empty( $parsed_attrs[ $attr ] ) ) {
						$context    = 'divi_' . str_replace( 'et_pb_', '', $module ) . '_' . $attr;
						$translated = $this->memory->getTranslation( $parsed_attrs[ $attr ], $language, $context );
						if ( $translated ) {
							$module_translated = true;
							break;
						}
					}
				}

				if ( $module_translated ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * AJAX: Translate Divi page
	 */
	public function ajaxTranslateDiviPage() {
		// Verify nonce
		if ( ! check_ajax_referer( 'voxfor_ml_divi_translate', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'voxfor-multilanguage' ) ) );
		}

		// Check permissions
		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'voxfor-multilanguage' ) ) );
		}

		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

		if ( empty( $language ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid language', 'voxfor-multilanguage' ) ) );
		}

		try {
			$post = get_post( $post_id );
			if ( ! $post ) {
				wp_send_json_error( array( 'message' => __( 'Post not found', 'voxfor-multilanguage' ) ) );
			}

			$this->translateDiviPageContent( $post->post_content, $language );

			wp_send_json_success( array( 'message' => __( 'Divi page translated successfully', 'voxfor-multilanguage' ) ) );

		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Translate Divi page content
	 */
	private function translateDiviPageContent( $content, $language ) {
		foreach ( $this->translatable_modules as $module => $attrs ) {
			if ( empty( $attrs ) ) {
				continue;
			}

			$pattern = '/\[' . preg_quote( $module ) . '([^\]]*)\]/';
			preg_match_all( $pattern, $content, $matches );

			foreach ( $matches[1] as $attr_string ) {
				$parsed_attrs = $this->parseShortcodeAttributes( $attr_string );

				foreach ( $attrs as $attr ) {
					if ( isset( $parsed_attrs[ $attr ] ) && ! empty( $parsed_attrs[ $attr ] ) ) {
						$context = 'divi_' . str_replace( 'et_pb_', '', $module ) . '_' . $attr;
						$this->translator->translateText( $parsed_attrs[ $attr ], $language, $context );
					}
				}
			}
		}
	}

	/**
	 * Check if we should translate
	 */
	private function shouldTranslate() {
		return ! is_admin();
	}

	/**
	 * Get language flag
	 */
	private function getLanguageFlag( $code ) {
		$flags = array(
			'en' => '🇬🇧',
			'he' => '🇮🇱',
			'fr' => '🇫🇷',
			'de' => '🇩🇪',
			'es' => '🇪🇸',
			'it' => '🇮🇹',
			'pt' => '🇵🇹',
			'ru' => '🇷🇺',
			'ja' => '🇯🇵',
			'zh' => '🇨🇳',
		);
		return isset( $flags[ $code ] ) ? $flags[ $code ] : '🌐';
	}

	/**
	 * Get language name
	 */
	private function getLanguageName( $code ) {
		$languages = array(
			'en' => 'English',
			'he' => 'עברית',
			'fr' => 'Français',
			'de' => 'Deutsch',
			'es' => 'Español',
			'it' => 'Italiano',
			'pt' => 'Português',
			'ru' => 'Русский',
		);
		return isset( $languages[ $code ] ) ? $languages[ $code ] : strtoupper( $code );
	}
}