<?php
namespace VoxforML\Admin;

use VoxforML\Core\Plugin;
use VoxforML\Database\TranslationMemory;
use VoxforML\Translator\DeepLTranslator;
use VoxforML\Utils\TextProcessor;

/**
 * Handles header and footer translation
 */
class HeaderFooterTranslator {
	private $plugin;
	private $memory;
	private $translator;
	private $processor;

	public function __construct() {
		$this->plugin     = Plugin::getInstance();
		$this->memory     = new TranslationMemory();
		$this->translator = new DeepLTranslator();
		$this->processor  = new TextProcessor();

		$this->initHooks();
	}

	private function initHooks() {
		// Add admin menu
		add_action( 'admin_menu', array( $this, 'addAdminMenu' ) );

		// AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_translate_header_footer', array( $this, 'ajaxTranslateHeaderFooter' ) );
		add_action( 'wp_ajax_voxfor_ml_collect_header_footer_texts', array( $this, 'ajaxCollectHeaderFooterTexts' ) );

		// Auto-translate on language switch (frontend)
		add_action( 'wp_ajax_nopriv_voxfor_ml_ensure_header_footer_translation', array( $this, 'ajaxEnsureTranslation' ) );
		add_action( 'wp_ajax_voxfor_ml_ensure_header_footer_translation', array( $this, 'ajaxEnsureTranslation' ) );

		// Get translations for frontend
		add_action( 'wp_ajax_nopriv_voxfor_ml_get_header_footer_translations', array( $this, 'ajaxGetTranslations' ) );
		add_action( 'wp_ajax_voxfor_ml_get_header_footer_translations', array( $this, 'ajaxGetTranslations' ) );
	}

	/**
	 * Add admin menu page
	 */
	public function addAdminMenu() {
		add_submenu_page(
			'voxfor-multilanguage',
			__( 'Header & Footer Translation', 'voxfor-multilanguage' ),
			__( 'Header & Footer', 'voxfor-multilanguage' ),
			'manage_options',
			'voxfor-ml-header-footer',
			array( $this, 'renderAdminPage' )
		);
	}

	/**
	 * Render admin page
	 */
	public function renderAdminPage() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Header & Footer Translation', 'voxfor-multilanguage' ); ?></h1>
			
			<div class="notice notice-info">
				<p><?php esc_html_e( 'This tool collects all text from your header and footer and translates them for all enabled languages.', 'voxfor-multilanguage' ); ?></p>
			</div>
			
			<div class="voxfor-ml-header-footer-translator">
				<h2><?php esc_html_e( 'Step 1: Collect Header & Footer Texts', 'voxfor-multilanguage' ); ?></h2>
				<p><?php esc_html_e( 'First, we need to scan your site to collect all header and footer texts.', 'voxfor-multilanguage' ); ?></p>
				<button id="voxfor-ml-collect-texts" class="button button-primary">
					<?php esc_html_e( 'Collect Texts', 'voxfor-multilanguage' ); ?>
				</button>
				
				<div id="collected-texts" style="margin-top: 20px; display: none;">
					<h3><?php esc_html_e( 'Collected Texts:', 'voxfor-multilanguage' ); ?></h3>
					<div id="texts-list"></div>
				</div>
				
				<div id="translation-section" style="margin-top: 30px; display: none;">
					<h2><?php esc_html_e( 'Step 2: Translate to All Languages', 'voxfor-multilanguage' ); ?></h2>
					<p><?php esc_html_e( 'Select languages to translate:', 'voxfor-multilanguage' ); ?></p>
					
					<?php
					$enabled_languages = $this->plugin->getEnabledLanguages();
					foreach ( $enabled_languages as $lang_code => $lang_name ) {
						if ( $lang_code === 'en' ) {
							continue;
						}
						?>
						<label style="display: block; margin: 5px 0;">
							<input type="checkbox" name="target_languages[]" value="<?php echo esc_attr( $lang_code ); ?>" checked>
							<?php echo esc_html( $lang_name ); ?>
						</label>
						<?php
					}
					?>
					
					<button id="voxfor-ml-translate-header-footer" class="button button-primary" style="margin-top: 10px;">
						<?php esc_html_e( 'Translate Header & Footer', 'voxfor-multilanguage' ); ?>
					</button>
					
					<div id="translation-progress" style="margin-top: 20px; display: none;">
						<div class="progress-bar" style="width: 100%; height: 20px; background: #f0f0f0; border-radius: 10px;">
							<div class="progress-fill" style="width: 0%; height: 100%; background: #0073aa; border-radius: 10px; transition: width 0.3s;"></div>
						</div>
						<p class="progress-text"></p>
					</div>
					
					<div id="translation-results" style="margin-top: 20px;"></div>
				</div>
			</div>
		</div>
		
<?php
// Header Footer Translator JavaScript is now in public/js/admin/header-footer-translator.js
// Script is properly enqueued via wp_enqueue_script with localization
?>
		<?php
	}

	/**
	 * AJAX: Collect header and footer texts
	 */
	public function ajaxCollectHeaderFooterTexts() {
		check_ajax_referer( 'voxfor_ml_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		// Get site URL to scan
		$site_url = home_url( '/' );

		// Fetch the homepage HTML
		$response = wp_remote_get( $site_url );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => 'Failed to fetch homepage' ) );
		}

		$html = wp_remote_retrieve_body( $response );

		// Parse HTML to extract header and footer texts
		$texts = $this->extractHeaderFooterTexts( $html );

		// Add common menu items and widget texts
		$texts = array_merge( $texts, $this->getMenuTexts() );
		$texts = array_merge( $texts, $this->getWidgetTexts() );

		// Remove duplicates and empty values
		$texts = array_unique( array_filter( $texts ) );
		$texts = array_values( $texts ); // Re-index

		wp_send_json_success( array( 'texts' => $texts ) );
	}

	/**
	 * Extract texts from header and footer HTML
	 */
	private function extractHeaderFooterTexts( $html ) {
		$texts = array();

		// Use DOMDocument for parsing
		$dom = new \DOMDocument();
		@$dom->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		$xpath = new \DOMXPath( $dom );

		// More comprehensive selectors for header/footer elements
		$selectors = array(
			// Header elements
			'//header//*[not(self::script or self::style)]/text()',
			'//*[@id="header" or @class="header" or contains(@class, "header-")]//*[not(self::script or self::style)]/text()',
			'//*[@id="masthead" or contains(@class, "masthead")]//*[not(self::script or self::style)]/text()',
			'//*[contains(@class, "site-header")]//*[not(self::script or self::style)]/text()',

			// Footer elements
			'//footer//*[not(self::script or self::style)]/text()',
			'//*[@id="footer" or @class="footer" or contains(@class, "footer-")]//*[not(self::script or self::style)]/text()',
			'//*[@id="colophon" or contains(@class, "colophon")]//*[not(self::script or self::style)]/text()',
			'//*[contains(@class, "site-footer")]//*[not(self::script or self::style)]/text()',

			// Navigation elements
			'//nav//*[not(self::script or self::style)]/text()',
			'//*[contains(@class, "menu") or contains(@class, "nav")]//*[not(self::script or self::style)]/text()',
			'//*[contains(@class, "navigation")]//*[not(self::script or self::style)]/text()',

			// Common header/footer areas
			'//*[contains(@class, "top-bar")]//*[not(self::script or self::style)]/text()',
			'//*[contains(@class, "bottom-bar")]//*[not(self::script or self::style)]/text()',
			'//*[contains(@class, "copyright")]//*[not(self::script or self::style)]/text()',
			'//*[contains(@class, "credits")]//*[not(self::script or self::style)]/text()',

			// Links and buttons in header/footer
			'//header//a[not(self::script or self::style)]/text()',
			'//footer//a[not(self::script or self::style)]/text()',
			'//header//button[not(self::script or self::style)]/text()',
			'//footer//button[not(self::script or self::style)]/text()',

			// Widget areas
			'//*[contains(@class, "widget")]//*[not(self::script or self::style)]/text()',
			'//*[contains(@class, "sidebar")]//*[not(self::script or self::style)]/text()',
		);

		foreach ( $selectors as $selector ) {
			$nodes = $xpath->query( $selector );
			foreach ( $nodes as $node ) {
				$text = trim( $node->nodeValue );
				// More lenient check - include short texts too
				if ( strlen( $text ) > 0 && ! is_numeric( $text ) && ! preg_match( '/^[\s\W]+$/', $text ) ) {
					$texts[] = $text;
				}
			}
		}

		// Also extract placeholder text, alt text, and title attributes
		$attribute_selectors = array(
			'//header//*[@placeholder]/@placeholder',
			'//footer//*[@placeholder]/@placeholder',
			'//header//img[@alt]/@alt',
			'//footer//img[@alt]/@alt',
			'//header//*[@title]/@title',
			'//footer//*[@title]/@title',
			'//header//*[@aria-label]/@aria-label',
			'//footer//*[@aria-label]/@aria-label',
		);

		foreach ( $attribute_selectors as $selector ) {
			$attrs = $xpath->query( $selector );
			foreach ( $attrs as $attr ) {
				$text = trim( $attr->nodeValue );
				if ( strlen( $text ) > 0 && ! is_numeric( $text ) ) {
					$texts[] = $text;
				}
			}
		}

		// Add some common header/footer texts that might be missed
		$common_texts = array(
			'Enlaces rápidos',
			'Para ella',
			'Para él',
			'Envíos a todo el mundo',
			'Mejor calidad',
			'Mejores ofertas',
			'Pagos seguros',
			'ESPAÑOL',
			'ITALIANO',
			'ENGLISH',
			'Inicio',
			'Acerca de',
			'Mi cuenta',
			'Cesta',
			'Contacto',
			'Range of Products',
			'Filter by color',
			'Filter by size',
			'Subscribe',
			'Filter by Price',
			'Categories',
			'Our Best Sellers',
		);

		$texts = array_merge( $texts, $common_texts );

		return $texts;
	}

	/**
	 * Get menu texts
	 */
	private function getMenuTexts() {
		$texts = array();

		$menus = wp_get_nav_menus();
		foreach ( $menus as $menu ) {
			$menu_items = wp_get_nav_menu_items( $menu->term_id );
			if ( $menu_items ) {
				foreach ( $menu_items as $item ) {
					$texts[] = $item->title;
					if ( ! empty( $item->description ) ) {
						$texts[] = $item->description;
					}
				}
			}
		}

		return $texts;
	}

	/**
	 * Get widget texts
	 */
	private function getWidgetTexts() {
		$texts = array();

		// Get all widget areas
		$sidebars = wp_get_sidebars_widgets();

		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( $sidebar_id === 'wp_inactive_widgets' ) {
				continue;
			}

			foreach ( $widgets as $widget_id ) {
				// Extract widget type and number
				if ( preg_match( '/^(.+)-(\d+)$/', $widget_id, $matches ) ) {
					$widget_type   = $matches[1];
					$widget_number = $matches[2];

					// Get widget options
					$option_name    = 'widget_' . $widget_type;
					$widget_options = get_option( $option_name );

					if ( isset( $widget_options[ $widget_number ] ) ) {
						$widget_data = $widget_options[ $widget_number ];

						// Extract common text fields
						if ( isset( $widget_data['title'] ) ) {
							$texts[] = $widget_data['title'];
						}
						if ( isset( $widget_data['text'] ) ) {
							$texts[] = wp_strip_all_tags( $widget_data['text'] );
						}
						if ( isset( $widget_data['description'] ) ) {
							$texts[] = $widget_data['description'];
						}
					}
				}
			}
		}

		return $texts;
	}

	/**
	 * AJAX: Translate header and footer texts
	 */
	public function ajaxTranslateHeaderFooter() {
		check_ajax_referer( 'voxfor_ml_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$texts     = isset( $_POST['texts'] ) && is_array( $_POST['texts'] ) ? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['texts'] ) ) : array();
		$languages = isset( $_POST['languages'] ) && is_array( $_POST['languages'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['languages'] ) ) : array();

		if ( empty( $texts ) || empty( $languages ) ) {
			wp_send_json_error( array( 'message' => 'No texts or languages provided' ) );
		}

		$translated = 0;
		$failed     = 0;
		$errors     = array();

		// Define constant to enable immediate translation
		if ( ! defined( 'VOXFOR_ML_BULK_TRANSLATING' ) ) {
			define( 'VOXFOR_ML_BULK_TRANSLATING', true );
		}

		// Group texts for batch translation
		$batch_size   = 20; // DeepL can handle multiple texts at once
		$text_batches = array_chunk( $texts, $batch_size );

		foreach ( $languages as $language ) {
			foreach ( $text_batches as $batch ) {
				// Filter out already translated texts
				$to_translate = array();
				foreach ( $batch as $text ) {
					$text = trim( $text );
					if ( empty( $text ) ) {
						continue;
					}

					// Check if translation exists in any context
					$exists   = false;
					$contexts = array( 'text_fragment', 'content', 'general', 'title', 'menu_item', 'widget_title', 'header', 'footer' );

					foreach ( $contexts as $context ) {
						if ( $this->memory->getTranslation( $text, $language, $context ) !== false ) {
							$exists = true;
							++$translated;
							break;
						}
					}

					if ( ! $exists ) {
						$to_translate[] = $text;
					}
				}

				// Batch translate
				if ( ! empty( $to_translate ) ) {
					try {
						$translations = $this->translator->batchTranslate( $to_translate, $language, 'EN' );

						if ( is_array( $translations ) ) {
							foreach ( $translations as $index => $translation ) {
								if ( ! empty( $translation ) && $translation !== $to_translate[ $index ] ) {
									// Save all common variations
									$this->saveTranslationVariations( $to_translate[ $index ], $translation, $language );
									++$translated;
								} else {
									++$failed;
									$errors[] = 'Failed to translate: ' . $to_translate[ $index ] . ' (Result: ' . ( $translation === false ? 'FALSE' : "'{$translation}'" ) . ')';
								}
							}
						} else {
							$failed  += count( $to_translate );
							$errors[] = 'Batch translation failed for ' . count( $to_translate ) . ' texts';
						}
					} catch ( \Exception $e ) {
						$failed  += count( $to_translate );
						$errors[] = 'API Error: ' . $e->getMessage();
					}

					// Small delay between batches to avoid rate limiting
					usleep( 100000 ); // 0.1 second
				}
			}
		}

		$response = array(
			'translated' => $translated,
			'failed'     => $failed,
			'languages'  => $languages,
		);

		if ( ! empty( $errors ) ) {
			$response['errors'] = array_slice( $errors, 0, 10 ); // Show first 10 errors
		}

		wp_send_json_success( $response );
	}

	/**
	 * Save translation in multiple contexts and variations
	 */
	private function saveTranslationVariations( $original, $translation, $language ) {
		// Save in multiple contexts for better matching
		$contexts = array( 'text_fragment', 'content', 'general', 'header', 'footer', 'menu_item', 'widget_title' );

		foreach ( $contexts as $context ) {
			$this->memory->saveTranslation( $original, $translation, $language, $context, null, 'deepl' );
		}

		// Also save case variations
		$variations = array(
			strtolower( $original )            => strtolower( $translation ),
			strtoupper( $original )            => strtoupper( $translation ),
			ucfirst( strtolower( $original ) ) => ucfirst( strtolower( $translation ) ),
			ucwords( strtolower( $original ) ) => ucwords( strtolower( $translation ) ),
		);

		foreach ( $variations as $orig_var => $trans_var ) {
			if ( $orig_var !== $original ) {
				$this->memory->saveTranslation( $orig_var, $trans_var, $language, 'text_fragment', null, 'deepl' );
			}
		}
	}

	/**
	 * AJAX: Ensure header/footer translation when language is switched
	 */
	public function ajaxEnsureTranslation() {
		// This can be called from frontend, so no permission check needed
		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( empty( $language ) || $language === 'en' ) {
			wp_send_json_success( array( 'message' => 'No translation needed for English' ) );
		}

		// Check if header/footer translations exist for this language
		$common_texts = array(
			'EVERYTHING',
			'WOMEN',
			'MEN',
			'ACCESSORIES',
			'CONTACT US',
			'Home',
			'About',
			'Contact',
			'Quick Links',
			'For Her',
			'For Him',
		);

		$missing = array();

		foreach ( $common_texts as $text ) {
			$translation = $this->memory->getTranslation( $text, $language, 'text_fragment' );
			if ( $translation === false ) {
				$missing[] = $text;
			}
		}

		if ( ! empty( $missing ) ) {
			// Queue these for translation
			foreach ( $missing as $text ) {
				$this->memory->queueForTranslation( $text, $language, 'text_fragment' );
			}

			wp_send_json_success(
				array(
					'message' => 'Queued ' . count( $missing ) . ' texts for translation',
					'missing' => $missing,
				)
			);
		} else {
			wp_send_json_success( array( 'message' => 'All header/footer texts are already translated' ) );
		}
	}

	/**
	 * AJAX: Get header/footer translations for a language
	 */
	public function ajaxGetTranslations() {
		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( empty( $language ) || $language === 'en' ) {
			wp_send_json_error( array( 'message' => 'Invalid language' ) );
		}

		// Get all possible header/footer texts
		$texts = $this->getAllHeaderFooterTexts();

		// Build translation map
		$translations = array();

		foreach ( $texts as $text ) {
			$translation = $this->memory->getTranslation( $text, $language, 'text_fragment' );

			// Try other contexts if not found
			if ( $translation === false ) {
				$contexts = array( 'content', 'general', 'title', 'menu_item', 'widget_title', 'navigation', 'header', 'footer' );
				foreach ( $contexts as $context ) {
					$translation = $this->memory->getTranslation( $text, $language, $context );
					if ( $translation !== false ) {
						break;
					}
				}
			}

			if ( $translation !== false ) {
				$translations[ $text ] = $translation;
			}
		}

		wp_send_json_success( array( 'translations' => $translations ) );
	}

	/**
	 * Get all possible header/footer texts
	 */
	private function getAllHeaderFooterTexts() {
		$texts = array();

		// Get from scanning
		$site_url = home_url( '/' );
		$response = wp_remote_get( $site_url );

		if ( ! is_wp_error( $response ) ) {
			$html  = wp_remote_retrieve_body( $response );
			$texts = array_merge( $texts, $this->extractHeaderFooterTexts( $html ) );
		}

		// Add from menus and widgets
		$texts = array_merge( $texts, $this->getMenuTexts() );
		$texts = array_merge( $texts, $this->getWidgetTexts() );

		// Add common texts
		$common_texts = array(
			'EVERYTHING',
			'WOMEN',
			'MEN',
			'ACCESSORIES',
			'CONTACT US',
			'Home',
			'About',
			'Contact',
			'Quick Links',
			'For Her',
			'For Him',
			'Enlaces rápidos',
			'Para ella',
			'Para él',
			'Envíos a todo el mundo',
			'Mejor calidad',
			'Mejores ofertas',
			'Pagos seguros',
			'Inicio',
			'Acerca de',
			'Mi cuenta',
			'Cesta',
			'Contacto',
		);

		$texts = array_merge( $texts, $common_texts );

		// Remove duplicates and empty values
		$texts = array_unique( array_filter( $texts ) );

		return array_values( $texts );
	}
}
