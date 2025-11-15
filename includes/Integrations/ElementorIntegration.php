<?php
namespace VoxforML\Integrations;

use VoxforML\Core\Plugin;

/**
 * Elementor Integration
 */
class ElementorIntegration {
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
		// Only initialize if Elementor is active and properly loaded
		if ( ! class_exists( '\Elementor\Plugin' ) || ! did_action( 'elementor/loaded' ) ) {
			// If Elementor isn't loaded yet, wait for it
			add_action( 'elementor/loaded', array( $this, 'initElementorHooks' ) );
			return;
		}

		$this->initElementorHooks();
	}

	/**
	 * Initialize Elementor-specific hooks - SIMPLIFIED TEXT-ONLY APPROACH
	 */
	public function initElementorHooks() {
		// Double-check Elementor is available
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		// SIMPLIFIED APPROACH: Only admin translation creation and meta box
		// NO frontend hooks that interfere with Elementor rendering

		// Add translation controls to Elementor editor
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueueEditorScripts' ), 20 );

		// AJAX handlers for admin translation creation
		add_action( 'wp_ajax_voxfor_ml_translate_elementor_page', array( $this, 'ajaxTranslateElementorPage' ) );

		// Add meta box to Elementor pages
		add_action( 'add_meta_boxes', array( $this, 'addElementorMetaBox' ) );

		// Enqueue admin scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminScripts' ) );

		// Let the main TranslationManager handle all frontend text translation
		// via the standard the_content filter - no Elementor-specific interference
	}

	// REMOVED: All complex frontend translation methods
	// The main TranslationManager will handle text translation via the_content filter
	// This keeps Elementor structure intact and only translates the text content

	// REMOVED: Complex JSON and content processing methods
	// These were interfering with Elementor structure
	// Text translation is now handled by the main TranslationManager

	/**
	 * Enqueue Elementor editor scripts
	 */
	public function enqueueEditorScripts() {
		// Skip if Elementor safe mode is active
		if ( isset( $_GET['elementor-preview'] ) && isset( $_GET['safe_mode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Only load on Elementor editor pages
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		// Check if Elementor is properly initialized
		if ( ! \Elementor\Plugin::$instance || ! \Elementor\Plugin::$instance->editor ) {
			return;
		}

		// Check if we're in edit mode
		if ( ! \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return;
		}

		// Additional safety check - don't load if there are JS errors
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// In debug mode with Elementor preview, be extra careful
			if ( ! wp_script_is( 'elementor-editor', 'registered' ) ) {
				return;
			}
		}

		// Enqueue our translation scripts for Elementor editor
		wp_enqueue_script(
			'voxfor-ml-elementor-editor',
			VOXFOR_ML_PLUGIN_URL . 'public/js/elementor-editor.js',
			array( 'elementor-editor' ),
			VOXFOR_ML_VERSION,
			true
		);

		// Localize script with translation data
		wp_localize_script(
			'voxfor-ml-elementor-editor',
			'voxforMLElementor',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'voxfor_ml_elementor_editor' ),
				'languages'       => $this->plugin->getEnabledLanguages(),
				'currentLanguage' => $this->plugin->getCurrentLanguage(),
				'strings'         => array(
					'translate'   => __( 'Translate', 'voxfor-multilanguage' ),
					'translating' => __( 'Translating...', 'voxfor-multilanguage' ),
					'translated'  => __( 'Translated', 'voxfor-multilanguage' ),
					'error'       => __( 'Translation error', 'voxfor-multilanguage' ),
				),
			)
		);

		// Add editor CSS
		wp_enqueue_style(
			'voxfor-ml-elementor-editor',
			VOXFOR_ML_PLUGIN_URL . 'public/css/elementor-editor.css',
			array( 'elementor-editor' ),
			VOXFOR_ML_VERSION
		);
	}

	/**
	 * Add Elementor meta box
	 */
	public function addElementorMetaBox() {
		// Only add to pages that use Elementor
		$screen = get_current_screen();
		if ( $screen && $screen->post_type ) {
			add_meta_box(
				'voxfor_ml_elementor_translations',
				__( 'Elementor Translations', 'voxfor-multilanguage' ),
				array( $this, 'renderElementorMetaBox' ),
				$screen->post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render Elementor meta box
	 */
	public function renderElementorMetaBox( $post ) {
		// Check if this page uses Elementor
		$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			echo '<p>' . esc_html__( 'This page does not use Elementor.', 'voxfor-multilanguage' ) . '</p>';
			return;
		}

		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();

		wp_nonce_field( 'voxfor_ml_elementor_translations', 'voxfor_ml_elementor_nonce' );

		?>
		<div class="voxfor-ml-elementor-translations">
			<p><strong><?php esc_html_e( 'Elementor Translation Status:', 'voxfor-multilanguage' ); ?></strong></p>
			
			<?php foreach ( $languages as $lang ) : ?>
				<?php
				if ( $lang === $default_lang ) {
					continue;}
				?>
				
				<?php
				// Count translatable elements
				$elementor_elements  = $this->countElementorElements( $elementor_data );
				$translated_elements = $this->countTranslatedElements( $elementor_data, $lang );

				$percentage = $elementor_elements > 0 ? round( ( $translated_elements / $elementor_elements ) * 100 ) : 0;
				?>
				
				<div class="voxfor-ml-elementor-lang" style="margin-bottom: 10px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
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
						<small><?php printf( /* translators: %1$d is the number of translated elements, %2$d is the total number of elements */ esc_html__( '%1$d of %2$d elements translated', 'voxfor-multilanguage' ), intval( $translated_elements ), intval( $elementor_elements ) ); ?></small>
					</div>
					
					<div style="margin-top: 8px;">
						<button type="button" class="button button-small voxfor-ml-translate-elementor" 
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
				<button type="button" class="button button-primary voxfor-ml-translate-all-elementor" 
						data-post-id="<?php echo intval( $post->ID ); ?>">
					<?php esc_html_e( 'Translate All Elements', 'voxfor-multilanguage' ); ?>
				</button>
			</div>
		</div>
		
<?php
// Elementor Integration JavaScript is now in public/js/admin/elementor-integration.js
// Script is properly enqueued via wp_enqueue_script with localization
// All debug console.log statements have been removed for production
?>
		<?php
	}

	/**
	 * Enqueue admin scripts for Elementor integration
	 */
	public function enqueueAdminScripts( $hook ) {
		// Only enqueue on post edit screens
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
			return;
		}

		// Check if current post uses Elementor
		global $post;
		if ( ! $post || ! $this->isUsingElementor( $post->ID ) ) {
			return;
		}

		// Enqueue Elementor integration script
		wp_enqueue_script(
			'voxfor-ml-elementor-integration',
			VOXFOR_ML_PLUGIN_URL . 'public/js/admin/elementor-integration.js',
			array( 'jquery' ),
			VOXFOR_ML_VERSION,
			true
		);

		// Localize script
		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();

		wp_localize_script(
			'voxfor-ml-elementor-integration',
			'voxforElementor',
			array(
				'nonce'     => wp_create_nonce( 'voxfor_ml_elementor_translate' ),
				'languages' => array_values( array_diff( $languages, array( $default_lang ) ) ),
				'strings'   => array(
					'translating'        => __( 'Translating...', 'voxfor-multilanguage' ),
					'translate'          => __( 'Translate', 'voxfor-multilanguage' ),
					'error'              => __( 'Error', 'voxfor-multilanguage' ),
					'ajaxError'          => __( 'AJAX Error', 'voxfor-multilanguage' ),
					'confirmTranslateAll' => __( 'Translate all Elementor elements to all languages?', 'voxfor-multilanguage' ),
				),
			)
		);
	}

	/**
	 * Count Elementor elements that can be translated
	 */
	private function countElementorElements( $elementor_data ) {
		if ( empty( $elementor_data ) ) {
			return 0;
		}

		$data = json_decode( $elementor_data, true );
		if ( ! $data ) {
			return 0;
		}

		$count = 0;
		$this->countElementsRecursive( $data, $count );

		return $count;
	}

	/**
	 * Count translated elements
	 */
	private function countTranslatedElements( $elementor_data, $language ) {
		if ( empty( $elementor_data ) ) {
			return 0;
		}

		$data = json_decode( $elementor_data, true );
		if ( ! $data ) {
			return 0;
		}

		$count = 0;
		$this->countTranslatedElementsRecursive( $data, $language, $count );

		return $count;
	}

	/**
	 * Recursively count elements
	 */
	private function countElementsRecursive( $elements, &$count ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( isset( $element['widgetType'] ) ) {
				// Check if this widget has translatable content
				if ( $this->hasTranslatableContent( $element ) ) {
					++$count;
				}
			}

			// Recurse into child elements
			if ( isset( $element['elements'] ) ) {
				$this->countElementsRecursive( $element['elements'], $count );
			}
		}
	}

	/**
	 * Recursively count translated elements
	 */
	private function countTranslatedElementsRecursive( $elements, $language, &$count ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( isset( $element['widgetType'] ) ) {
				// Check if this widget is translated
				if ( $this->isElementTranslated( $element, $language ) ) {
					++$count;
				}
			}

			// Recurse into child elements
			if ( isset( $element['elements'] ) ) {
				$this->countTranslatedElementsRecursive( $element['elements'], $language, $count );
			}
		}
	}

	/**
	 * Check if element has translatable content - SIMPLIFIED
	 */
	private function hasTranslatableContent( $element ) {
		$translatable_widgets = array( 'heading', 'text-editor', 'button', 'image', 'hfe-infocard', 'image-box' );
		return in_array( $element['widgetType'] ?? '', $translatable_widgets );
	}

	/**
	 * Check if element is translated - SIMPLIFIED to use 'content' context
	 */
	private function isElementTranslated( $element, $language ) {
		if ( ! $this->hasTranslatableContent( $element ) ) {
			return false;
		}

		$settings = $element['settings'] ?? array();

		// Simplified: Just check if any text content is translated using 'content' context
		switch ( $element['widgetType'] ) {
			case 'heading':
				return ! empty( $settings['title'] ) &&
						$this->memory->getTranslation( $settings['title'], $language, 'content' );
			case 'text-editor':
				return ! empty( $settings['editor'] ) &&
						$this->memory->getTranslation( $settings['editor'], $language, 'content' );
			case 'button':
				return ! empty( $settings['text'] ) &&
						$this->memory->getTranslation( $settings['text'], $language, 'content' );
			case 'image':
				return ! empty( $settings['image']['alt'] ) &&
						$this->memory->getTranslation( $settings['image']['alt'], $language, 'image_alt_inline' );
			case 'hfe-infocard':
				return ( ! empty( $settings['infocard_title'] ) &&
						$this->memory->getTranslation( $settings['infocard_title'], $language, 'content' ) ) ||
						( ! empty( $settings['infocard_description'] ) &&
						$this->memory->getTranslation( $settings['infocard_description'], $language, 'content' ) );
			case 'image-box':
				return ( ! empty( $settings['title_text'] ) &&
						$this->memory->getTranslation( $settings['title_text'], $language, 'content' ) ) ||
						( ! empty( $settings['description_text'] ) &&
						$this->memory->getTranslation( $settings['description_text'], $language, 'content' ) );
		}

		return false;
	}

	/**
	 * AJAX: Translate Elementor page
	 */
	public function ajaxTranslateElementorPage() {
		// Verify nonce
		if ( ! check_ajax_referer( 'voxfor_ml_elementor_translate', 'nonce', false ) ) {
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

		// Get Elementor data
		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );

		if ( empty( $elementor_data ) ) {
			wp_send_json_error( array( 'message' => __( 'No Elementor data found', 'voxfor-multilanguage' ) ) );
		}

		try {
			$this->translateElementorPageData( $elementor_data, $language );
			wp_send_json_success( array( 'message' => __( 'Elementor page translated successfully', 'voxfor-multilanguage' ) ) );

		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Translate Elementor page data
	 */
	private function translateElementorPageData( $elementor_data, $language ) {
		$data = json_decode( $elementor_data, true );
		if ( ! $data ) {
			return;
		}

		$this->translateElementsRecursive( $data, $language );
	}

	/**
	 * Recursively translate elements
	 */
	private function translateElementsRecursive( $elements, $language ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( isset( $element['widgetType'] ) ) {
				$this->translateElementContent( $element, $language );
			}

			// Recurse into child elements
			if ( isset( $element['elements'] ) ) {
				$this->translateElementsRecursive( $element['elements'], $language );
			}
		}
	}

	/**
	 * Extract and translate text from Elementor widget - SIMPLIFIED
	 */
	private function translateElementContent( $element, $language ) {
		$settings    = $element['settings'] ?? array();
		$widget_type = $element['widgetType'] ?? 'unknown';

		// Simple text extraction based on widget type
		$texts_to_translate = array();

		switch ( $widget_type ) {
			case 'heading':
				if ( ! empty( $settings['title'] ) ) {
					$texts_to_translate[] = array(
						'text'    => $settings['title'],
						'context' => 'content',
					);
				}
				break;

			case 'text-editor':
				if ( ! empty( $settings['editor'] ) ) {
					// Strip HTML for translation, but use 'content' context
					$clean_text = wp_strip_all_tags( $settings['editor'] );
					if ( ! empty( trim( $clean_text ) ) ) {
						$texts_to_translate[] = array(
							'text'    => $settings['editor'],
							'context' => 'content',
						);
					}
				}
				break;

			case 'button':
				if ( ! empty( $settings['text'] ) ) {
					$texts_to_translate[] = array(
						'text'    => $settings['text'],
						'context' => 'content',
					);
				}
				break;

			case 'image':
				if ( ! empty( $settings['image']['alt'] ) ) {
					$texts_to_translate[] = array(
						'text'    => $settings['image']['alt'],
						'context' => 'image_alt_inline',
					);
				}
				break;

			case 'hfe-infocard':
				if ( ! empty( $settings['infocard_title'] ) ) {
					$texts_to_translate[] = array(
						'text'    => $settings['infocard_title'],
						'context' => 'content',
					);
				}
				if ( ! empty( $settings['infocard_description'] ) ) {
					$texts_to_translate[] = array(
						'text'    => $settings['infocard_description'],
						'context' => 'content',
					);
				}
				break;

			case 'image-box':
				if ( ! empty( $settings['title_text'] ) ) {
					$texts_to_translate[] = array(
						'text'    => $settings['title_text'],
						'context' => 'content',
					);
				}
				if ( ! empty( $settings['description_text'] ) ) {
					$texts_to_translate[] = array(
						'text'    => $settings['description_text'],
						'context' => 'content',
					);
				}
				if ( ! empty( $settings['image']['alt'] ) ) {
					$texts_to_translate[] = array(
						'text'    => $settings['image']['alt'],
						'context' => 'image_alt_inline',
					);
				}
				break;
		}

		// Batch translate for better performance
		if ( ! empty( $texts_to_translate ) ) {
			$this->batchTranslateTexts( $texts_to_translate, $language );
		}
	}

	/**
	 * Batch translate texts for better performance
	 */
	private function batchTranslateTexts( $texts_to_translate, $language ) {
		if ( empty( $texts_to_translate ) ) {
			return;
		}

		// FIXED: Process each text individually to ensure unique UUID entries
		// Don't use batch checking as it deduplicates identical text
		$deepl_translator = new \VoxforML\Translator\DeepLTranslator();

		foreach ( $texts_to_translate as $item ) {
			// ENHANCED: Clean up duplicate text before processing
			$clean_text = $this->cleanDuplicateText( $item['text'] );

			// Check if this specific text already has a translation
			$existing = $this->memory->getTranslation( $clean_text, $language, $item['context'] );

			// Always translate and save with unique UUID, even if text is identical
			if ( $existing === false ) {
				$translated = $deepl_translator->translate( $clean_text, $language, 'auto', $item['context'] );
				if ( $translated ) {
					// This will create a unique UUID entry for each text instance
					$this->memory->saveTranslation( $clean_text, $translated, $language, $item['context'] );
				}
			}
		}
	}

	/**
	 * Clean duplicate text patterns (handles Elementor widget duplications)
	 */
	private function cleanDuplicateText( $text ) {
		// Normalize whitespace first
		$normalized = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$normalized = trim( preg_replace( '/\s+/', ' ', $normalized ) );

		// Split into words for pattern detection
		$words      = explode( ' ', $normalized );
		$word_count = count( $words );

		// Skip very short text
		if ( $word_count < 2 ) {
			return $normalized;
		}

		$original_text = $normalized;

		// Try different division factors to find repeating patterns (2x, 3x, 4x, 5x, 6x, etc.)
		for ( $divisor = 2; $divisor <= min( 10, $word_count ); $divisor++ ) {
			if ( $word_count % $divisor == 0 ) {
				$segment_length = $word_count / $divisor;
				$first_segment  = array_slice( $words, 0, $segment_length );

				// Check if all segments are identical to the first segment
				$is_exact_repetition = true;
				for ( $i = 1; $i < $divisor; $i++ ) {
					$current_segment = array_slice( $words, $i * $segment_length, $segment_length );
					if ( $current_segment !== $first_segment ) {
						$is_exact_repetition = false;
						break;
					}
				}

				// If we found exact repetition, use only the first segment
				if ( $is_exact_repetition ) {
					$cleaned_text = implode( ' ', $first_segment );
					return $cleaned_text;
				}
			}
		}

		// No duplication found, return normalized text
		return $normalized;
	}

	/**
	 * Translate text and save to database (legacy method for backward compatibility)
	 */
	private function translateAndSaveText( $text, $language, $context ) {
		// Check if already translated
		$existing = $this->memory->getTranslation( $text, $language, $context );
		if ( $existing !== false ) {
			return $existing;
		}

		// Get the DeepL translator directly
		$deepl_translator = new \VoxforML\Translator\DeepLTranslator();

		// Translate via DeepL (source language is auto-detected by DeepL)
		$translated = $deepl_translator->translate( $text, $language, 'auto', $context );

		if ( $translated ) {
			// Save translation to database
			$this->memory->saveTranslation( $text, $translated, $language, $context );
			return $translated;
		} else {
			return false;
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

	/**
	 * Check if a post uses Elementor
	 */
	private function isUsingElementor( $post_id ) {
		if ( ! $post_id ) {
			return false;
		}
		
		// Check if Elementor data exists for this post
		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
		return ! empty( $elementor_data );
	}
}