<?php
namespace VoxforML\Integrations;

use VoxforML\Core\Plugin;

/**
 * Advanced Custom Fields (ACF) Integration
 */
class ACFIntegration {
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
		// Only initialize if ACF is active
		if ( ! class_exists( 'ACF' ) && ! function_exists( 'get_field' ) ) {
			return;
		}

		// Hook into ACF field rendering
		add_filter( 'acf/load_value', array( $this, 'translateFieldValue' ), 10, 3 );

		// Hook into flexible content and repeater fields
		add_filter( 'acf/load_value/type=flexible_content', array( $this, 'translateFlexibleContent' ), 10, 3 );
		add_filter( 'acf/load_value/type=repeater', array( $this, 'translateRepeaterContent' ), 10, 3 );

		// Add ACF meta box for translation status
		add_action( 'add_meta_boxes', array( $this, 'addACFMetaBox' ) );

		// Enqueue admin scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminScripts' ) );

		// AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_translate_acf_fields', array( $this, 'ajaxTranslateACFFields' ) );

		// Hook into ACF field groups to identify translatable fields
		add_action( 'acf/init', array( $this, 'registerTranslatableFieldTypes' ) );
	}

	/**
	 * Register translatable field types
	 */
	public function registerTranslatableFieldTypes() {
		$this->translatable_field_types = array(
			'text',
			'textarea',
			'wysiwyg',
			'email',
			'url',
			'password',
			'select',
			'checkbox',
			'radio',
			'button_group',
			'true_false',
		);
	}

	/**
	 * Translate ACF field value
	 */
	public function translateFieldValue( $value, $post_id, $field ) {
		if ( ! $this->shouldTranslate() || empty( $value ) ) {
			return $value;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $value;
		}

		// Only translate specific field types
		if ( ! $this->isTranslatableFieldType( $field ) ) {
			return $value;
		}

		// Handle different field types
		switch ( $field['type'] ) {
			case 'text':
			case 'email':
			case 'url':
				return $this->translateSimpleField( $value, $current_lang, $field );

			case 'textarea':
				return $this->translateTextareaField( $value, $current_lang, $field );

			case 'wysiwyg':
				return $this->translateWysiwygField( $value, $current_lang, $field );

			case 'select':
			case 'checkbox':
			case 'radio':
			case 'button_group':
				return $this->translateChoiceField( $value, $current_lang, $field );

			default:
				return $value;
		}
	}

	/**
	 * Translate flexible content fields
	 */
	public function translateFlexibleContent( $value, $post_id, $field ) {
		if ( ! $this->shouldTranslate() || ! is_array( $value ) ) {
			return $value;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $value;
		}

		foreach ( $value as $row_index => $row ) {
			if ( ! isset( $row['acf_fc_layout'] ) ) {
				continue;
			}

			$layout = $row['acf_fc_layout'];

			// Get layout fields
			$layout_fields = $this->getLayoutFields( $field, $layout );

			foreach ( $layout_fields as $sub_field ) {
				if ( isset( $row[ $sub_field['name'] ] ) && $this->isTranslatableFieldType( $sub_field ) ) {
					$original_value   = $row[ $sub_field['name'] ];
					$translated_value = $this->translateFieldByType( $original_value, $current_lang, $sub_field );

					if ( $translated_value !== $original_value ) {
						$value[ $row_index ][ $sub_field['name'] ] = $translated_value;
					}
				}
			}
		}

		return $value;
	}

	/**
	 * Translate repeater content
	 */
	public function translateRepeaterContent( $value, $post_id, $field ) {
		if ( ! $this->shouldTranslate() || ! is_array( $value ) ) {
			return $value;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $value;
		}

		foreach ( $value as $row_index => $row ) {
			foreach ( $field['sub_fields'] as $sub_field ) {
				if ( isset( $row[ $sub_field['name'] ] ) && $this->isTranslatableFieldType( $sub_field ) ) {
					$original_value   = $row[ $sub_field['name'] ];
					$translated_value = $this->translateFieldByType( $original_value, $current_lang, $sub_field );

					if ( $translated_value !== $original_value ) {
						$value[ $row_index ][ $sub_field['name'] ] = $translated_value;
					}
				}
			}
		}

		return $value;
	}

	/**
	 * Translate field by type
	 */
	private function translateFieldByType( $value, $language, $field ) {
		switch ( $field['type'] ) {
			case 'text':
			case 'email':
			case 'url':
				return $this->translateSimpleField( $value, $language, $field );

			case 'textarea':
				return $this->translateTextareaField( $value, $language, $field );

			case 'wysiwyg':
				return $this->translateWysiwygField( $value, $language, $field );

			case 'select':
			case 'checkbox':
			case 'radio':
			case 'button_group':
				return $this->translateChoiceField( $value, $language, $field );

			default:
				return $value;
		}
	}

	/**
	 * Translate simple text field
	 */
	private function translateSimpleField( $value, $language, $field ) {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return $value;
		}

		$context    = 'acf_' . $field['type'] . '_' . $field['name'];
		$translated = $this->memory->getTranslation( $value, $language, $context );

		return $translated ?: $value;
	}

	/**
	 * Translate textarea field
	 */
	private function translateTextareaField( $value, $language, $field ) {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return $value;
		}

		$context    = 'acf_textarea_' . $field['name'];
		$translated = $this->memory->getTranslation( $value, $language, $context );

		return $translated ?: $value;
	}

	/**
	 * Translate WYSIWYG field
	 */
	private function translateWysiwygField( $value, $language, $field ) {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return $value;
		}

		$context    = 'acf_wysiwyg_' . $field['name'];
		$translated = $this->memory->getTranslation( $value, $language, $context );

		return $translated ?: $value;
	}

	/**
	 * Translate choice field (select, checkbox, radio)
	 */
	private function translateChoiceField( $value, $language, $field ) {
		if ( empty( $value ) ) {
			return $value;
		}

		// For choice fields, translate the labels if they exist
		if ( isset( $field['choices'] ) && is_array( $field['choices'] ) ) {
			if ( is_array( $value ) ) {
				// Multiple values (checkbox)
				$translated_values = array();
				foreach ( $value as $val ) {
					if ( isset( $field['choices'][ $val ] ) ) {
						$label               = $field['choices'][ $val ];
						$context             = 'acf_choice_' . $field['name'] . '_' . $val;
						$translated_label    = $this->memory->getTranslation( $label, $language, $context );
						$translated_values[] = $translated_label ?: $label;
					} else {
						$translated_values[] = $val;
					}
				}
				return $translated_values;
			} else {
				// Single value
				if ( isset( $field['choices'][ $value ] ) ) {
					$label            = $field['choices'][ $value ];
					$context          = 'acf_choice_' . $field['name'] . '_' . $value;
					$translated_label = $this->memory->getTranslation( $label, $language, $context );
					return $translated_label ?: $label;
				}
			}
		}

		return $value;
	}

	/**
	 * Check if field type is translatable
	 */
	private function isTranslatableFieldType( $field ) {
		if ( ! isset( $field['type'] ) ) {
			return false;
		}

		return in_array( $field['type'], $this->translatable_field_types ?? array() );
	}

	/**
	 * Get layout fields for flexible content
	 */
	private function getLayoutFields( $field, $layout_name ) {
		if ( ! isset( $field['layouts'] ) ) {
			return array();
		}

		foreach ( $field['layouts'] as $layout ) {
			if ( $layout['name'] === $layout_name ) {
				return isset( $layout['sub_fields'] ) ? $layout['sub_fields'] : array();
			}
		}

		return array();
	}

	/**
	 * Add ACF translation meta box
	 */
	public function addACFMetaBox() {
		// Only add to post types that have ACF fields
		$post_types = $this->getPostTypesWithACFFields();

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'voxfor_ml_acf_translations',
				__( 'ACF Field Translations', 'voxfor-multilanguage' ),
				array( $this, 'renderACFMetaBox' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render ACF translation meta box
	 */
	public function renderACFMetaBox( $post ) {
		$acf_fields = $this->getACFFieldsForPost( $post->ID );

		if ( empty( $acf_fields ) ) {
			echo '<p>' . esc_html__( 'No ACF fields found for this post.', 'voxfor-multilanguage' ) . '</p>';
			return;
		}

		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();

		wp_nonce_field( 'voxfor_ml_acf_translations', 'voxfor_ml_acf_nonce' );

		?>
		<div class="voxfor-ml-acf-translations">
			<p><strong><?php esc_html_e( 'ACF Translation Status:', 'voxfor-multilanguage' ); ?></strong></p>
			
			<?php foreach ( $languages as $lang ) : ?>
				<?php
				if ( $lang === $default_lang ) {
					continue;}
				?>
				
				<?php
				$translated_fields = $this->countTranslatedACFFields( $acf_fields, $lang );
				$total_fields      = count( $acf_fields );
				$percentage        = $total_fields > 0 ? round( ( $translated_fields / $total_fields ) * 100 ) : 0;
				?>
				
				<div class="voxfor-ml-acf-lang" style="margin-bottom: 10px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
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
						<small><?php printf( /* translators: %1$d is the number of translated fields, %2$d is the total number of fields */ esc_html__( '%1$d of %2$d fields translated', 'voxfor-multilanguage' ), intval( $translated_fields ), intval( $total_fields ) ); ?></small>
					</div>
					
					<div style="margin-top: 8px;">
						<button type="button" class="button button-small voxfor-ml-translate-acf" 
								data-post-id="<?php echo intval( $post->ID ); ?>" 
								data-language="<?php echo esc_attr( $lang ); ?>">
							<?php echo esc_html( $percentage === 100 ? __( 'Update', 'voxfor-multilanguage' ) : __( 'Translate', 'voxfor-multilanguage' ) ); ?>
						</button>
					</div>
				</div>
			<?php endforeach; ?>
			
			<div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd;">
				<button type="button" class="button button-primary voxfor-ml-translate-all-acf" 
						data-post-id="<?php echo intval( $post->ID ); ?>">
					<?php esc_html_e( 'Translate All ACF Fields', 'voxfor-multilanguage' ); ?>
				</button>
			</div>
		</div>
		
<?php
// ACF Integration JavaScript is now in public/js/admin/acf-integration.js
// Script is properly enqueued via wp_enqueue_script with localization
?>
		<?php
	}

	/**
	 * Enqueue admin scripts for ACF integration
	 */
	public function enqueueAdminScripts( $hook ) {
		// Only enqueue on post edit screens
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
			return;
		}

		// Check if current post type has ACF fields
		global $post;
		if ( ! $post ) {
			return;
		}

		$post_types = $this->getPostTypesWithACFFields();
		if ( ! in_array( $post->post_type, $post_types ) ) {
			return;
		}

		// Enqueue ACF integration script
		wp_enqueue_script(
			'voxfor-ml-acf-integration',
			VOXFOR_ML_PLUGIN_URL . 'public/js/admin/acf-integration.js',
			array( 'jquery' ),
			VOXFOR_ML_VERSION,
			true
		);

		// Localize script
		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();

		wp_localize_script(
			'voxfor-ml-acf-integration',
			'voxforACF',
			array(
				'nonce'     => wp_create_nonce( 'voxfor_ml_acf_translate' ),
				'languages' => array_values( array_diff( $languages, array( $default_lang ) ) ),
				'strings'   => array(
					'translating'        => __( 'Translating...', 'voxfor-multilanguage' ),
					'translate'          => __( 'Translate', 'voxfor-multilanguage' ),
					'error'              => __( 'Error', 'voxfor-multilanguage' ),
					'confirmTranslateAll' => __( 'Translate all ACF fields to all languages?', 'voxfor-multilanguage' ),
				),
			)
		);
	}

	/**
	 * AJAX: Translate ACF fields
	 */
	public function ajaxTranslateACFFields() {
		// Verify nonce
		if ( ! check_ajax_referer( 'voxfor_ml_acf_translate', 'nonce', false ) ) {
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
			$acf_fields = $this->getACFFieldsForPost( $post_id );

			foreach ( $acf_fields as $field_name => $field_value ) {
				if ( ! empty( $field_value ) && is_string( $field_value ) ) {
					$context = 'acf_field_' . $field_name;
					$this->translator->translateText( $field_value, $language, $context );
				}
			}

			wp_send_json_success( array( 'message' => __( 'ACF fields translated successfully', 'voxfor-multilanguage' ) ) );

		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Get ACF fields for post
	 */
	private function getACFFieldsForPost( $post_id ) {
		if ( ! function_exists( 'get_fields' ) ) {
			return array();
		}

		$fields = get_fields( $post_id );
		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Count translated ACF fields
	 */
	private function countTranslatedACFFields( $fields, $language ) {
		$count = 0;

		foreach ( $fields as $field_name => $field_value ) {
			if ( ! empty( $field_value ) && is_string( $field_value ) ) {
				$context    = 'acf_field_' . $field_name;
				$translated = $this->memory->getTranslation( $field_value, $language, $context );
				if ( $translated ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Get post types with ACF fields
	 */
	private function getPostTypesWithACFFields() {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return array();
		}

		$field_groups = acf_get_field_groups();
		$post_types   = array();

		foreach ( $field_groups as $group ) {
			if ( isset( $group['location'] ) ) {
				foreach ( $group['location'] as $location_group ) {
					foreach ( $location_group as $rule ) {
						if ( $rule['param'] === 'post_type' ) {
							$post_types[] = $rule['value'];
						}
					}
				}
			}
		}

		return array_unique( $post_types );
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