<?php
namespace VoxforML\Integrations;

use VoxforML\Core\Plugin;

/**
 * WooCommerce Integration
 */
class WooCommerceIntegration {
	private $plugin;
	private $translator;
	private $memory;
	private static $translating = false; // Prevent recursion

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin     = Plugin::getInstance();
		$this->translator = $this->plugin->getComponent( 'translator' );
		$this->memory     = new \VoxforML\Database\TranslationMemory();

		$this->initHooks();
	}

	/**
	 * Initialize hooks
	 */
	private function initHooks() {
		// Only initialize if WooCommerce is active
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Re-enable WooCommerce product translation hooks
		if ( get_option( 'voxfor_ml_wc_translate_products', true ) ) {
			add_filter( 'woocommerce_product_get_name', array( $this, 'translateProductName' ), 10, 2 );
		}

		if ( get_option( 'voxfor_ml_wc_translate_descriptions', true ) ) {
			add_filter( 'woocommerce_product_get_short_description', array( $this, 'translateProductShortDescription' ), 999, 2 );
			add_filter( 'woocommerce_product_get_description', array( $this, 'translateProductDescription' ), 999, 2 );

			// Additional hooks to catch short description in different contexts
			add_filter( 'woocommerce_short_description', array( $this, 'translateShortDescriptionText' ), 999, 1 );
			add_filter( 'the_excerpt', array( $this, 'translateShortDescriptionText' ), 999, 1 );
		}

		if ( get_option( 'voxfor_ml_wc_translate_categories', true ) ) {
			add_filter( 'get_term', array( $this, 'translateTerm' ), 10, 2 );
		}

		if ( get_option( 'voxfor_ml_wc_translate_attributes', true ) ) {
			add_filter( 'woocommerce_attribute_label', array( $this, 'translateAttributeLabel' ), 10, 3 );
		}

		// Cart/Checkout protection
		add_action( 'woocommerce_before_cart', array( $this, 'protectCartCheckout' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'protectCartCheckout' ) );

		// NUCLEAR APPROACH - Intercept at MULTIPLE levels to prevent redirects
		add_action( 'init', array( $this, 'disableShopRedirects' ), 1 );
		add_filter( 'request', array( $this, 'forceMultiLanguageProductDetection' ), 1 );
		add_action( 'parse_request', array( $this, 'forceProductQueryAgain' ), 1 );
		add_filter( 'redirect_canonical', array( $this, 'preventProductRedirects' ), 1, 2 );

		// Additional hooks for maximum compatibility
		add_action( 'wp', array( $this, 'ensureProductPageOnWp' ), 1 );

		// Apply WooCommerce shop override EARLY to prevent shop detection
		add_action( 'init', array( $this, 'applyWooCommerceShopOverride' ), 2 );
		
		// Debug query variables (run later to see processed query)
		add_action( 'wp', array( $this, 'debugQueryVariables' ), 999 );

		// Admin product meta boxes
		add_action( 'add_meta_boxes', array( $this, 'addProductMetaBoxes' ) );
		add_action( 'save_post', array( $this, 'saveProductTranslations' ) );

		// Enqueue admin scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminScripts' ) );

		// AJAX handlers for product translation
		// AJAX handler moved to AdminManager.php to avoid conflicts

		// Exclude checkout/cart from auto-translation
		add_filter( 'voxfor_ml_should_translate_page', array( $this, 'excludeCheckoutPages' ), 10, 2 );

		// ONLY fix product pages - NO SHOP PAGE LOGIC
		add_action( 'wp', array( $this, 'fixWooCommerceFlags' ), 5 );

		// Force correct WooCommerce template for product pages
		add_filter( 'template_include', array( $this, 'forceProductTemplate' ), 10 );

		// Add RTL support for WooCommerce
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueWooCommerceRTLStyles' ), 20 );

		// Add template debugging
		add_action( 'template_include', array( $this, 'debugTemplateInclude' ), 999 );

		// Force correct template for translated shop pages
		add_filter( 'template_include', array( $this, 'forceShopTemplate' ), 10 );


		// Product URL translation
		if ( get_option( 'voxfor_ml_translate_slugs', false ) ) {
			add_filter( 'post_type_link', array( $this, 'filterProductLink' ), 10, 2 );
			add_filter( 'woocommerce_product_get_permalink', array( $this, 'translateProductLink' ), 10, 2 );
		}
	}

	/**
	 * Translate product name
	 */
	public function translateProductName( $name, $product ) {
		if ( self::$translating || ! $this->shouldTranslate() ) {
			return $name;
		}

		self::$translating = true;

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			self::$translating = false;
			return $name;
		}

		// Try specific context first, then fallback to general contexts
		$translated = $this->memory->getTranslation( $name, $current_lang, 'product_name' );
		if ( ! $translated ) {
			$translated = $this->memory->getTranslation( $name, $current_lang, 'title' );
		}
		if ( ! $translated ) {
			$translated = $this->memory->getTranslation( $name, $current_lang, 'general' );
		}

		self::$translating = false;
		return $translated ?: $name;
	}

	/**
	 * Translate product short description - Smart text fragment approach
	 */
	public function translateProductShortDescription( $description, $product ) {
		if ( ! $this->shouldTranslate() || empty( $description ) ) {
			return $description;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $description;
		}

		// Use smart text fragment translation
		return $this->smartTranslateHTML( $description, $current_lang );
	}

	/**
	 * Smart translate HTML by finding and replacing individual text fragments
	 */
	private function smartTranslateHTML( $html, $target_lang ) {
		if ( empty( $html ) ) {
			return $html;
		}

		// Extract all text pieces from the HTML
		$text_pieces = $this->extractTranslatableTextFromHTML( $html );

		if ( empty( $text_pieces ) ) {
			return $html;
		}

		// Use batch translation for better performance
		$contexts_to_try = array(
			'product_description',        // manual edits in description tab
			'product_short_description',  // manual edits in short description
			'content',                    // generic content
			'general',                    // catch-all
			'text_fragment',               // automatic per-fragment fallback
		);

		$translated_pieces = array();
		$translation_found = false;

		// Try batch translation with the most common context first
		$batch_translations = $this->memory->getBatchTranslations( $text_pieces, $target_lang, 'content' );

		foreach ( $text_pieces as $index => $text_piece ) {
			$piece = trim( $text_piece );
			if ( $piece !== '' && strlen( $piece ) > 1 ) {
				$translated_piece = $batch_translations[ $index ] ?? false;

				// If batch translation didn't find it, try other contexts
				if ( ! $translated_piece ) {
					foreach ( $contexts_to_try as $ctx ) {
						$maybe = $this->memory->getTranslation( $piece, $target_lang, $ctx );
						if ( $maybe ) {
							$translated_piece = $maybe;
							break;
						}
					}
				}

				if ( $translated_piece ) {
					$translated_pieces[ $text_piece ] = $translated_piece;
					$translation_found                = true;
				}
			}
		}

		if ( $translation_found && ! empty( $translated_pieces ) ) {
			// Replace all text pieces in the original HTML
			return $this->replaceTextPiecesInHTML( $html, $translated_pieces );
		} else {
			return $html;
		}
	}

	/**
	 * Extract translatable text pieces from HTML (frontend version)
	 */
	private function extractTranslatableTextFromHTML( $html ) {
		if ( empty( $html ) ) {
			return array();
		}

		// Prefer a robust regex-based extraction to avoid DOMDocument edge cases
		$pieces = array();

		// Method 1: Text between tags
		preg_match_all( '/>([^<]+)</u', $html, $matches );
		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $idx => $match ) {
				$cleaned = trim( html_entity_decode( $match, ENT_QUOTES, 'UTF-8' ) );
				if ( ! empty( $cleaned ) && strlen( $cleaned ) > 1 && preg_match( '/[a-zA-Z]/', $cleaned ) ) {
					$pieces[] = $cleaned;
				}
			}
		}

		// Method 2: Fallback to strip tags and split lines
		if ( empty( $pieces ) ) {
			$plain = wp_strip_all_tags( $html );
			$plain = html_entity_decode( $plain, ENT_QUOTES, 'UTF-8' );
			foreach ( preg_split( '/[\n\r]+/', $plain ) as $line ) {
				$line = trim( $line );
				if ( ! empty( $line ) && strlen( $line ) > 1 && preg_match( '/[a-zA-Z]/', $line ) ) {
					$pieces[] = $line;
				}
			}
		}

		return array_unique( $pieces );
	}

	/**
	 * Recursively extract text from DOM nodes (frontend version)
	 */
	private function extractTextFromNode( $node, &$text_pieces ) {
		if ( $node->nodeType === XML_TEXT_NODE ) {
			$text = trim( $node->nodeValue );
			if ( ! empty( $text ) ) {
				$text_pieces[] = $text;
			}
		} elseif ( $node->nodeType === XML_ELEMENT_NODE ) {
			foreach ( $node->childNodes as $child ) {
				$this->extractTextFromNode( $child, $text_pieces );
			}
		}
	}

	/**
	 * Replace text pieces in HTML with their translations (frontend version)
	 */
	private function replaceTextPiecesInHTML( $html, $translated_pieces ) {
		$result = $html;

		// Sort by length (longest first) to avoid partial replacements
		uksort(
			$translated_pieces,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		foreach ( $translated_pieces as $original => $translation ) {
			// Use preg_replace with word boundaries for precise replacement
			$pattern = '/\b' . preg_quote( $original, '/' ) . '\b/u';
			$result  = preg_replace( $pattern, $translation, $result );
		}

		return $result;
	}

	/**
	 * Translate short description text (alternative hooks) - Smart fragment approach
	 */
	public function translateShortDescriptionText( $text ) {

		if ( ! $this->shouldTranslate() || empty( $text ) ) {
			return $text;
		}

		// Only translate on product pages
		if ( ! is_product() ) {
			return $text;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $text;
		}

		// Use smart text fragment translation
		$translated_text = $this->smartTranslateHTML( $text, $current_lang );


		return $translated_text;
	}

	/**
	 * Translate product description
	 */
	public function translateProductDescription( $description, $product ) {

		if ( ! $this->shouldTranslate() || empty( $description ) ) {
			return $description;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $description;
		}

		// Use smart text fragment translation
		$translated_description = $this->smartTranslateHTML( $description, $current_lang );


		return $translated_description;
	}

	/**
	 * Translate terms (categories, tags, attributes)
	 */
	public function translateTerm( $term, $taxonomy ) {
		if ( ! is_object( $term ) ) {
			return $term;
		}

		// Only translate WooCommerce taxonomies
		$wc_taxonomies = array( 'product_cat', 'product_tag', 'pa_color', 'pa_size' );
		if ( ! in_array( $taxonomy, $wc_taxonomies ) && ! taxonomy_is_product_attribute( $taxonomy ) ) {
			return $term;
		}

		// Check specific settings for categories vs attributes
		if ( in_array( $taxonomy, array( 'product_cat', 'product_tag' ) ) ) {
			if ( ! $this->shouldTranslateCategories() ) {
				return $term;
			}
		} else {
			// For attributes, check the attributes setting
			if ( ! get_option( 'voxfor_ml_wc_translate_attributes', false ) ) {
				return $term;
			}
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $term;
		}

		// Translate term name
		$translated_name = $this->memory->getTranslation( $term->name, $current_lang, 'term_name' );
		if ( $translated_name ) {
			$term->name = $translated_name;
		}

		// Translate term description
		if ( ! empty( $term->description ) ) {
			$translated_desc = $this->memory->getTranslation( $term->description, $current_lang, 'term_description' );
			if ( $translated_desc ) {
				$term->description = $translated_desc;
			}
		}

		return $term;
	}

	/**
	 * Translate attribute labels
	 */
	public function translateAttributeLabel( $label, $name, $product ) {
		if ( ! $this->shouldTranslate() || empty( $label ) ) {
			return $label;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $label;
		}

		$translated = $this->memory->getTranslation( $label, $current_lang, 'attribute_label' );
		return $translated ?: $label;
	}

	/**
	 * Protect cart/checkout from language switching
	 */
	public function protectCartCheckout() {
		// Prevent language switching on cart/checkout pages
		remove_action( 'wp_head', array( $this->plugin->getComponent( 'router' ), 'addHreflangTags' ) );

		// Add notice about language switching
		if ( $this->plugin->getCurrentLanguage() !== $this->plugin->getDefaultLanguage() ) {
			wc_add_notice(
				__( 'Language switching is disabled during checkout for security reasons.', 'voxfor-multilanguage' ),
				'notice'
			);
		}
	}

	/**
	 * Add product translation meta boxes
	 */
	public function addProductMetaBoxes() {
		add_meta_box(
			'voxfor_ml_product_translations',
			__( 'Product Translations', 'voxfor-multilanguage' ),
			array( $this, 'renderProductTranslationMetaBox' ),
			'product',
			'side',
			'high'
		);
	}

	/**
	 * Render product translation meta box
	 */
	public function renderProductTranslationMetaBox( $post ) {
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}

		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();

		wp_nonce_field( 'voxfor_ml_product_translations', 'voxfor_ml_product_nonce' );

		?>
		<div class="voxfor-ml-product-translations">
			<p><strong><?php esc_html_e( 'Translation Status:', 'voxfor-multilanguage' ); ?></strong></p>
			
			<?php foreach ( $languages as $lang ) : ?>
				<?php
				if ( $lang === $default_lang ) {
					continue;}
				?>
				
				<?php
				$name_translated       = $this->memory->getTranslation( $product->get_name(), $lang, 'product_name' );
				$desc_translated       = $this->memory->getTranslation( $product->get_description(), $lang, 'product_description' );
				$short_desc_translated = $this->memory->getTranslation( $product->get_short_description(), $lang, 'product_short_description' );

				$completion   = 0;
				$total_fields = 3;
				if ( $name_translated ) {
					++$completion;
				}
				if ( $desc_translated ) {
					++$completion;
				}
				if ( $short_desc_translated ) {
					++$completion;
				}

				$percentage = round( ( $completion / $total_fields ) * 100 );
				?>
				
				<div class="voxfor-ml-product-lang" style="margin-bottom: 10px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
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
						<button type="button" class="button button-small voxfor-ml-translate-product" 
								data-product-id="<?php echo intval( $post->ID ); ?>" 
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
				<button type="button" class="button button-primary voxfor-ml-translate-all-product" 
						data-product-id="<?php echo intval( $post->ID ); ?>">
					<?php esc_html_e( 'Translate to All Languages', 'voxfor-multilanguage' ); ?>
				</button>
			</div>
		</div>
		
<?php
// WooCommerce Integration JavaScript is now in public/js/admin/woocommerce-integration.js
// Script is properly enqueued via wp_enqueue_script with localization
?>
		<?php
	}

	/**
	 * Enqueue admin scripts for WooCommerce integration
	 */
	public function enqueueAdminScripts( $hook ) {
		// Only enqueue on product edit screens
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
			return;
		}

		// Check if current post is a product
		global $post;
		if ( ! $post || $post->post_type !== 'product' ) {
			return;
		}

		// Enqueue WooCommerce integration script
		wp_enqueue_script(
			'voxfor-ml-woocommerce-integration',
			VOXFOR_ML_PLUGIN_URL . 'public/js/admin/woocommerce-integration.js',
			array( 'jquery' ),
			VOXFOR_ML_VERSION,
			true
		);

		// Localize script
		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();

		wp_localize_script(
			'voxfor-ml-woocommerce-integration',
			'voxforWooCommerce',
			array(
				'nonce'     => wp_create_nonce( 'voxfor_ml_product_translate' ),
				'languages' => array_values( array_diff( $languages, array( $default_lang ) ) ),
				'strings'   => array(
					'translating'        => __( 'Translating...', 'voxfor-multilanguage' ),
					'translate'          => __( 'Translate', 'voxfor-multilanguage' ),
					'error'              => __( 'Error', 'voxfor-multilanguage' ),
					'confirmTranslateAll' => __( 'Translate product to all languages?', 'voxfor-multilanguage' ),
				),
			)
		);
	}

	/**
	 * AJAX: Translate product
	 */
		// ajaxTranslateProduct method moved to AdminManager.php to avoid conflicts

	/**
	 * Exclude checkout pages from auto-translation (Enhanced P1 Fix)
	 */
	public function excludeCheckoutPages( $should_translate, $url ) {
		// Comprehensive checkout/cart exclusions
		if ( is_cart() || is_checkout() || is_account_page() ) {
			return false;
		}

		// Check for WooCommerce endpoints
		if ( function_exists( 'is_wc_endpoint_url' ) ) {
			if ( is_wc_endpoint_url( 'order-pay' ) ||
				is_wc_endpoint_url( 'order-received' ) ||
				is_wc_endpoint_url( 'add-payment-method' ) ||
				is_wc_endpoint_url( 'delete-payment-method' ) ||
				is_wc_endpoint_url( 'set-default-payment-method' ) ) {
				return false;
			}
		}

		// Check URL patterns for checkout/cart pages
		$excluded_patterns = array(
			'/checkout/',
			'/cart/',
			'/my-account/',
			'/order-pay/',
			'/order-received/',
			'/add-payment-method/',
			'/wc-api/',
		);

		foreach ( $excluded_patterns as $pattern ) {
			if ( strpos( $url, $pattern ) !== false ) {
				return false;
			}
		}

		// Check for AJAX requests during checkout
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			$action          = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$wc_ajax_actions = array(
				'woocommerce_checkout',
				'woocommerce_update_order_review',
				'woocommerce_apply_coupon',
				'woocommerce_remove_coupon',
				'woocommerce_update_shipping_method',
				'woocommerce_get_refreshed_fragments',
			);

			if ( in_array( $action, $wc_ajax_actions ) ) {
				return false;
			}
		}

		return $should_translate;
	}

	/**
	 * Check if we should translate
	 */
	private function shouldTranslate() {
		// Check if WooCommerce product translation is enabled
		if ( ! get_option( 'voxfor_ml_wc_translate_products', true ) ) {
			return false;
		}

		return ! is_admin() && ! is_cart() && ! is_checkout() && ! is_account_page();
	}

	/**
	 * Check if we should translate categories
	 */
	private function shouldTranslateCategories() {
		return get_option( 'voxfor_ml_wc_translate_categories', true ) && $this->shouldTranslate();
	}

	/**
	 * Check if we should translate shop pages
	 */
	private function shouldTranslateShopPages() {
		return get_option( 'voxfor_ml_wc_translate_shop_pages', true );
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
	 * Enqueue WooCommerce RTL styles for Hebrew and Arabic
	 */
	public function enqueueWooCommerceRTLStyles() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		
		// Check if current language is RTL
		if ( in_array( $current_lang, array( 'he', 'ar' ) ) ) {
			// Add RTL class to body
			add_filter( 'body_class', array( $this, 'addWooCommerceRTLBodyClass' ) );
			
			// Add inline RTL CSS for WooCommerce
			$rtl_css = $this->getWooCommerceRTLCSS();
			if ( $rtl_css ) {
				// Escape late: sanitize CSS to prevent any potential injection
				wp_add_inline_style( 'woocommerce-general', wp_strip_all_tags( $rtl_css ) );
			}
		}
	}

	/**
	 * Add RTL body class for WooCommerce pages
	 */
	public function addWooCommerceRTLBodyClass( $classes ) {
		if ( is_woocommerce() || is_shop() || is_product_category() || is_product_tag() || is_product() ) {
			$classes[] = 'woocommerce-rtl';
		}
		return $classes;
	}

	/**
	 * Get WooCommerce RTL CSS
	 */
	private function getWooCommerceRTLCSS() {
		return '
		/* WooCommerce RTL Fixes */
		.woocommerce-rtl .woocommerce ul.products li.product .price {
			text-align: right;
		}
		
		.woocommerce-rtl .woocommerce .woocommerce-ordering select {
			text-align: right;
			padding-right: 2.618em;
			padding-left: 1em;
		}
		
		.woocommerce-rtl .woocommerce nav.woocommerce-pagination ul li {
			float: right;
		}
		
		.woocommerce-rtl .woocommerce nav.woocommerce-pagination ul li a,
		.woocommerce-rtl .woocommerce nav.woocommerce-pagination ul li span {
			text-align: center;
		}
		
		.woocommerce-rtl .woocommerce .woocommerce-result-count {
			float: right;
		}
		
		.woocommerce-rtl .woocommerce .woocommerce-ordering {
			float: left;
		}
		
		.woocommerce-rtl .woocommerce ul.products li.product .button {
			text-align: center;
		}
		
		.woocommerce-rtl .woocommerce .widget_price_filter .price_slider_wrapper .ui-widget-content {
			direction: ltr; /* Keep slider LTR for functionality */
		}
		
		/* Fix for Hebrew text alignment in product titles */
		.woocommerce-rtl .woocommerce ul.products li.product h2,
		.woocommerce-rtl .woocommerce ul.products li.product .woocommerce-loop-product__title {
			text-align: right;
			direction: rtl;
		}
		
		/* Fix breadcrumbs for RTL */
		.woocommerce-rtl .woocommerce-breadcrumb {
			text-align: right;
			direction: rtl;
		}
		
		/* Fix category layout */
		.woocommerce-rtl .woocommerce .woocommerce-products-header {
			text-align: right;
		}
		';
	}

	/**
	 * Save product translations
	 */
	public function saveProductTranslations( $post_id ) {
		// Check if this is a product
		if ( get_post_type( $post_id ) !== 'product' ) {
			return;
		}

		// Check nonce
		if ( ! isset( $_POST['voxfor_ml_product_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['voxfor_ml_product_nonce'] ) ), 'voxfor_ml_product_translations' ) ) {
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Skip auto-saves and revisions
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Get product
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		// Queue translations for enabled languages
		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();

		foreach ( $languages as $lang ) {
			if ( $lang === $default_lang ) {
				continue;
			}

			// Queue product name translation
			if ( $product->get_name() ) {
				$this->memory->queueForTranslation(
					$product->get_name(),
					$lang,
					'product_name',
					$post_id
				);
			}

			// Queue product description translation
			if ( $product->get_description() ) {
				$this->memory->queueForTranslation(
					$product->get_description(),
					$lang,
					'product_description',
					$post_id
				);
			}

			// Queue short description translation
			if ( $product->get_short_description() ) {
				$this->memory->queueForTranslation(
					$product->get_short_description(),
					$lang,
					'product_short_description',
					$post_id
				);
			}
		}
	}

	/**
	 * Translate product title in loop
	 */
	public function translateProductTitleInLoop( $title, $post_id = null ) {
		// Only translate product titles
		if ( ! $post_id || get_post_type( $post_id ) !== 'product' ) {
			return $title;
		}

		if ( ! $this->shouldTranslate() ) {
			return $title;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $title;
		}

		$translated = $this->memory->getTranslation( $title, $current_lang, 'product_name' );
		return $translated ?: $title;
	}

	/**
	 * Filter product link for post_type_link hook
	 */
	public function filterProductLink( $link, $post ) {
		// Only handle product post type
		if ( ! $post || $post->post_type !== 'product' ) {
			return $link;
		}

		return $this->translateProductLink( $link, $post->ID );
	}

	/**
	 * Translate product link
	 */
	public function translateProductLink( $link, $product ) {
		// Only translate if slug translation is enabled
		if ( ! get_option( 'voxfor_ml_translate_slugs', false ) ) {
		return $link;
		}

		$current_lang = $this->plugin->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		// No translation needed for default language
		if ( $current_lang === $default_lang ) {
			return $link;
		}

		// Get product ID
		$product_id = is_object( $product ) ? $product->get_id() : $product;
		if ( ! $product_id ) {
			return $link;
		}

		// Get slug manager
		$slug_manager = $this->plugin->getComponent( 'slug_manager' );
		if ( ! $slug_manager ) {
			return $link;
		}

		// Get translated slug
		$translated_slug = $slug_manager->getSlug( $product_id, $current_lang );
		if ( ! $translated_slug ) {
			// Fallback: add language prefix to original URL
			return $this->addLanguagePrefix( $link, $current_lang );
		}

		// Build translated URL
		$site_url = trailingslashit( site_url() );
		return $site_url . $current_lang . '/product/' . $translated_slug . '/';
	}

	/**
	 * Add language prefix to URL
	 */
	private function addLanguagePrefix( $url, $language ) {
		if ( $language === 'en' ) {
			return $url;
		}

		$parsed_url = wp_parse_url( $url );
		$path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/';
		
		// Add language prefix
		$path = '/' . $language . $path;
		
		// Rebuild URL
		$new_url  = ( isset( $parsed_url['scheme'] ) ? $parsed_url['scheme'] . '://' : '' );
		$new_url .= isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';
		$new_url .= isset( $parsed_url['port'] ) ? ':' . $parsed_url['port'] : '';
		$new_url .= $path;
		$new_url .= isset( $parsed_url['query'] ) ? '?' . $parsed_url['query'] : '';
		$new_url .= isset( $parsed_url['fragment'] ) ? '#' . $parsed_url['fragment'] : '';

		return $new_url;
	}

	/**
	 * Fix WooCommerce shop page query for multilingual URLs
	 */
	public function fixShopPageQuery( $query ) {
		// Prevent recursion
		if ( self::$translating ) {
			return;
		}

		// Only modify main query on frontend
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// Check if we have a language parameter and post_type=product
		$lang      = get_query_var( 'voxfor_ml_lang' );
		$post_type = get_query_var( 'post_type' );

		// Also check if this is a translated shop page
		$is_translated_shop = $this->isTranslatedShopPage();

		// Add debug logging
		$this->addShopQueryDebugScript( 'fixShopPageQuery', $lang, $post_type, $is_translated_shop, is_shop() );

		if ( ( $lang && ( $post_type === 'product' || is_shop() ) ) || $is_translated_shop ) {
			// This is a multilingual shop page
			$query->set( 'post_type', 'product' );
			$query->set( 'posts_per_page', wc_get_default_products_per_row() * wc_get_default_product_rows_per_page() );

			// Set WooCommerce flags
			$query->is_shop = true;
			$query->is_home = false;
			$query->is_page = false;
			$query->is_singular = false;
			$query->is_single = false;

			// Set shop page as queried object
			$shop_page_id = wc_get_page_id( 'shop' );
			if ( $shop_page_id ) {
				$query->queried_object_id = $shop_page_id;
				$query->queried_object = get_post( $shop_page_id );
			}

			// Remove any conflicting query vars
			$query->set( 'page_id', '' );
			$query->set( 'pagename', '' );
			
			// Log that we applied the fix
			$this->addShopQueryDebugScript( 'fixShopPageQuery_APPLIED', $lang, $post_type, $is_translated_shop, is_shop(), $shop_page_id );
		}
	}

	/**
	 * Ensure product page detection on wp hook - final safety net
	 */
	public function ensureProductPageOnWp() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Check if this is a product URL pattern
		if ( preg_match( '#^/([a-z]{2})/product/([^/]+)/?$#', $request_uri, $matches ) ) {
			$language = $matches[1];
			$product_slug = $matches[2];

			// Find the original product slug
			$original_slug = $this->findOriginalProductSlugByTranslated( $product_slug, $language );

			if ( $original_slug ) {
				$product = get_page_by_path( $original_slug, OBJECT, 'product' );

			if ( $product ) {
				// Force global post and query setup
					global $post, $wp_query;
					$post = $product;
					$wp_query->post = $product;
					$wp_query->posts = array( $product );
					$wp_query->post_count = 1;
					$wp_query->found_posts = 1;
					$wp_query->queried_object = $product;
					$wp_query->queried_object_id = $product->ID;

					// Set query vars
					$wp_query->query_vars['product'] = $original_slug;
					$wp_query->query_vars['post_type'] = 'product';
					$wp_query->query_vars['voxfor_ml_lang'] = $language;

					// Force WordPress flags
					$wp_query->is_single = true;
					$wp_query->is_singular = true;
					$wp_query->is_product = true;
					$wp_query->is_shop = false;
					$wp_query->is_archive = false;
					$wp_query->is_home = false;
					$wp_query->is_page = false;

					// Force WooCommerce functions
					add_filter( 'woocommerce_is_product', '__return_true' );
					add_filter( 'woocommerce_is_shop', '__return_false' );
				}
			}
		}
	}

	/**
	 * Fix WooCommerce conditional flags
	 */
	public function fixWooCommerceFlags() {
		global $wp_query;

		// CLEAN SIMPLE APPROACH - ONLY HANDLE PRODUCT PAGES
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		
		// Check if this is a translated product URL - support ALL languages
		if ( preg_match( '#^/([a-z]{2})/product/([^/]+)/?$#', $request_uri, $matches ) ) {
			$language = $matches[1];
			$translated_slug = $matches[2];
			
			// Get the product slug from query vars (should be set by fixTranslatedProductQuery)
			$product_slug = get_query_var( 'product' );
			
			if ( $product_slug ) {
				// Find the product
				$product = get_page_by_path( $product_slug, OBJECT, 'product' );
				
				if ( $product ) {
					// FORCE CLEAN PRODUCT PAGE FLAGS
					$wp_query->is_single = true;
					$wp_query->is_singular = true;
					$wp_query->is_product = true;
					$wp_query->is_shop = false;
			$wp_query->is_home = false;
			$wp_query->is_page = false;
					$wp_query->is_archive = false;
					
					// CLEAN ALL CONFLICTING QUERY VARS
					$wp_query->set( 'name', '' );
					$wp_query->set( 'pagename', '' );
					$wp_query->set( 'post_name_or_page', '' );
					$wp_query->set( 'page_id', '' );
					$wp_query->set( 'p', '' );
					$wp_query->set( 'attachment', '' );
					$wp_query->set( 'attachment_id', '' );
					$wp_query->set( 'static', '' );
					$wp_query->set( 'page', '' );
					$wp_query->set( 'paged', '' );
					
					// Set product object
					$wp_query->queried_object = $product;
					$wp_query->queried_object_id = $product->ID;
					$wp_query->post = $product;
					$wp_query->posts = array( $product );
					$wp_query->post_count = 1;
					$wp_query->found_posts = 1;

					// Set global $post for WooCommerce functions
					global $post;
					$post = $product;
					setup_postdata( $post );

					// Force WooCommerce functions
					add_filter( 'woocommerce_is_product', '__return_true' );
					add_filter( 'woocommerce_is_shop', '__return_false' );
				}
			}
		}
	}

	/**
	 * NUCLEAR APPROACH - Force multi-language product detection at request level
	 * Works for ALL languages: es, he, de, fr, etc.
	 */
	public function forceMultiLanguageProductDetection( $query_vars ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Match ANY language + product pattern: /[lang]/product/[slug]/
		if ( preg_match( '#^/([a-z]{2})/product/([^/]+)/?$#', $request_uri, $matches ) ) {
			$language = $matches[1];        // es, he, de, fr, etc.
			$translated_slug = $matches[2]; // any translated slug
			
			// Find original product slug from database
			$original_slug = $this->findOriginalProductSlugByTranslated( $translated_slug, $language );
			
			if ( $original_slug ) {
				// FORCE WordPress to recognize this as a product page
				$query_vars = array(
					'product' => $original_slug,
					'post_type' => 'product',
					'voxfor_ml_lang' => $language
				);
			}
		}
		
		return $query_vars;
	}

	/**
	 * Find original product slug by translated slug for any language
	 */
	private function findOriginalProductSlugByTranslated( $translated_slug, $language ) {
		// Get slug manager
		$slug_manager = $this->plugin->getComponent( 'slug_manager' );
		if ( ! $slug_manager ) {
			return null;
		}
		
		// Find post ID by translated slug
		$post_id = $slug_manager->findPostBySlug( $translated_slug, $language );
		
		if ( $post_id ) {
			$product = get_post( $post_id );
			if ( $product && $product->post_type === 'product' ) {
				return $product->post_name; // Return original slug
			}
		}
		
		return null;
	}

	/**
	 * Apply WooCommerce shop override EARLY to prevent incorrect shop detection
	 */
	public function applyWooCommerceShopOverride() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Check if this is a translated product URL pattern
		if ( preg_match( '#^/([a-z]{2})/product/([^/]+)/?$#', $request_uri ) ) {
			// For product URLs, force WooCommerce to NOT think this is a shop page
			add_filter( 'woocommerce_is_shop', '__return_false' );
		}
	}

	/**
	 * NUCLEAR - Disable all shop redirects that interfere with product pages
	 */
	public function disableShopRedirects() {
		// Remove WooCommerce canonical redirects that cause issues
		remove_action( 'template_redirect', 'redirect_canonical' );
		
		// Disable WooCommerce shop page redirects
		if ( class_exists( 'WooCommerce' ) ) {
			remove_action( 'template_redirect', array( WC(), 'template_redirect' ) );
		}
	}

	/**
	 * NUCLEAR - Force product query again at parse_request level
	 */
	public function forceProductQueryAgain( $wp ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		
		// Force detect product URLs again
		if ( preg_match( '#^/([a-z]{2})/product/([^/]+)/?$#', $request_uri, $matches ) ) {
			$language = $matches[1];
			$translated_slug = $matches[2];
			
			// Find original slug
			$original_slug = $this->findOriginalProductSlugByTranslated( $translated_slug, $language );
			
			if ( $original_slug ) {
				// Force set query vars
				$wp->query_vars['product'] = $original_slug;
				$wp->query_vars['post_type'] = 'product';
				$wp->query_vars['voxfor_ml_lang'] = $language;
				
				// Remove ALL shop-related and conflicting vars
				unset( $wp->query_vars['pagename'] );
				unset( $wp->query_vars['name'] );
				unset( $wp->query_vars['post_name_or_page'] );
				unset( $wp->query_vars['page_id'] );
				unset( $wp->query_vars['p'] );
				unset( $wp->query_vars['attachment'] );
				unset( $wp->query_vars['attachment_id'] );
				unset( $wp->query_vars['static'] );
				unset( $wp->query_vars['page'] );
				unset( $wp->query_vars['paged'] );
			}
		}
	}

	/**
	 * NUCLEAR - Prevent canonical redirects for product pages
	 */
	public function preventProductRedirects( $redirect_url, $requested_url ) {
		// If this is a product URL, don't redirect
		if ( preg_match( '#^https?://[^/]+/([a-z]{2})/product/([^/]+)/?$#', $requested_url ) ) {
			return false; // Prevent redirect
		}
		
		return $redirect_url;
	}

	/**
	 * Debug URL routing to catch issues early
	 */
	public function debugUrlRouting() {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		
		// Only debug Spanish product URLs
	}

	/**
	 * Check if current page is a translated shop page
	 */
	private function isTranslatedShopPage() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		// Get current language and shop page ID
		$current_language = $this->plugin->getCurrentLanguage();
		$shop_page_id = wc_get_page_id( 'shop' );
		
		if ( ! $shop_page_id || $current_language === 'en' ) {
			return false;
		}

		// Get current URL path
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		
		if ( ! $path ) {
			return false;
		}

		// Check for translated shop page patterns: /es/tienda/, /he/חנות/
		$language_prefix = '/' . $current_language . '/';
		if ( strpos( $path, $language_prefix ) === 0 ) {
			$slug_after_lang = substr( $path, strlen( $language_prefix ) );
			$slug_after_lang = trim( $slug_after_lang, '/' );
			
			// Skip product URLs - they should not be detected as shop pages
			if ( strpos( $slug_after_lang, 'product/' ) === 0 ) {
				return false;
			}
			
			// Check if this slug belongs to the shop page
			$slug_manager = $this->plugin->getComponent( 'slug_manager' );
			if ( $slug_manager ) {
				$found_post_id = $slug_manager->findPostBySlug( $slug_after_lang, $current_language );
				$is_shop = ( $found_post_id && $found_post_id == $shop_page_id );
				
				// Add debug logging
				$this->addWooCommerceShopDebugScript( $request_uri, $path, $current_language, $slug_after_lang, $found_post_id, $shop_page_id, $is_shop );
				
				return $is_shop;
			}
		}

		return false;
	}

	/**
	 * Fix translated product query to ensure WooCommerce recognizes product pages
	 */
	public function fixTranslatedProductQuery( $wp ) {
		// Skip if in admin or not a WooCommerce request
		if ( is_admin() || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Get current request URI
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$current_language = $this->plugin->getCurrentLanguage();
		
		
		// Skip if English
		if ( $current_language === 'en' ) {
			return;
		}

		// Check if this looks like a translated product URL
		if ( preg_match( '#^/' . preg_quote( $current_language ) . '/product/([^/]+)/?$#', $request_uri, $matches ) ) {
			$translated_slug = $matches[1];
			
			// Get slug manager
			$slug_manager = $this->plugin->getComponent( 'slug_manager' );
			if ( ! $slug_manager ) {
				// If no slug manager, use the translated slug as-is
				$wp->query_vars['product'] = $translated_slug;
				$wp->query_vars['post_type'] = 'product';
				$wp->query_vars['voxfor_ml_lang'] = $current_language;
				return;
			}

			// Try to find the product by translated slug
			$product_id = $slug_manager->findPostBySlug( $translated_slug, $current_language );
			
			if ( $product_id ) {
				$product = get_post( $product_id );
				if ( $product && $product->post_type === 'product' ) {
					// Set the correct query vars - use ORIGINAL slug for WooCommerce
					$wp->query_vars['product'] = $product->post_name;
					$wp->query_vars['post_type'] = 'product';
					$wp->query_vars['voxfor_ml_lang'] = $current_language;
					
					// Remove any conflicting query vars that might confuse WordPress
					unset( $wp->query_vars['pagename'] );
					unset( $wp->query_vars['name'] );
					unset( $wp->query_vars['post_name_or_page'] );
					
					return;
				}
			} else {
				// Product not found by translated slug, try as original slug
				$product = get_page_by_path( $translated_slug, OBJECT, 'product' );
				if ( $product ) {
					$wp->query_vars['product'] = $product->post_name;
					$wp->query_vars['post_type'] = 'product';
					$wp->query_vars['voxfor_ml_lang'] = $current_language;
					return;
				}
			}
		}
	}

	/**
	 * Clean up conflicting query variables for product pages
	 */
	public function cleanupProductQueryVars( $wp ) {
		// Only process if we have a product query var
		if ( isset( $wp->query_vars['product'] ) && isset( $wp->query_vars['voxfor_ml_lang'] ) ) {
			$current_language = $wp->query_vars['voxfor_ml_lang'];
			
			// Skip if English
			if ( $current_language === 'en' ) {
				return;
			}
			
			// Remove conflicting query vars that might make WordPress think this is a regular post
			unset( $wp->query_vars['name'] );
			unset( $wp->query_vars['pagename'] );
			unset( $wp->query_vars['post_name_or_page'] );
			
			// Ensure post_type is set to product
			$wp->query_vars['post_type'] = 'product';
			}
	}

	/**
	 * Final cleanup of product query variables right before WordPress processes them
	 */
	public function finalProductQueryCleanup() {
		global $wp_query, $post;
		
		if ( is_admin() ) {
			return;
		}

		$current_language = $this->plugin->getCurrentLanguage();
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		
		// Only process translated product URLs
		if ( $current_language !== 'en' && preg_match( '#^/' . preg_quote( $current_language ) . '/product/([^/]+)/?$#', $request_uri ) ) {
			// Check if we have a product query var
			$product_slug = get_query_var( 'product' );
			
			if ( $product_slug ) {
				// Remove conflicting query vars from global query
				$wp_query->set( 'name', '' );
				$wp_query->set( 'pagename', '' );
				$wp_query->set( 'post_name_or_page', '' );
				
				// Ensure product and post_type are set correctly
				$wp_query->set( 'product', $product_slug );
				$wp_query->set( 'post_type', 'product' );
				
				// Force WordPress to reprocess the query with clean variables
				$wp_query->parse_query();
				
				// Directly set the correct WordPress query flags
				$wp_query->is_single = true;
				$wp_query->is_singular = true;
				$wp_query->is_product = true;
				$wp_query->is_shop = false;
				$wp_query->is_archive = false;
				$wp_query->is_home = false;
				$wp_query->is_page = false;
				
				// Find and set the product object
				$product_obj = get_page_by_path( $product_slug, OBJECT, 'product' );
				if ( $product_obj ) {
					$wp_query->queried_object = $product_obj;
					$wp_query->queried_object_id = $product_obj->ID;
					$wp_query->post = $product_obj;
					$wp_query->posts = array( $product_obj );
					$wp_query->post_count = 1;
					$wp_query->found_posts = 1;
					
					// CRITICAL: Set global $post for WooCommerce functions
					$post = $product_obj;
					setup_postdata( $post );
				}
				}
		}
	}

	/**
	 * Debug query variables to understand what's being set
	 */
	public function debugQueryVariables() {
		if ( is_admin() ) {
			return;
		}

		$current_language = $this->plugin->getCurrentLanguage();
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		
		// Only debug for translated product URLs
		if ( $current_language !== 'en' && preg_match( '#^/' . preg_quote( $current_language ) . '/product/([^/]+)/?$#', $request_uri, $matches ) ) {
			global $wp_query;
			
			$debug_info = array(
				'request_uri' => $request_uri,
				'current_language' => $current_language,
				'product_slug_from_url' => $matches[1],
				'query_vars' => array(
					'product' => get_query_var( 'product' ),
					'post_type' => get_query_var( 'post_type' ),
					'voxfor_ml_lang' => get_query_var( 'voxfor_ml_lang' ),
					'name' => get_query_var( 'name' ),
					'pagename' => get_query_var( 'pagename' ),
				),
				'wp_query_flags' => array(
					'is_single' => $wp_query->is_single,
					'is_product' => isset( $wp_query->is_product ) ? $wp_query->is_product : 'not_set',
					'is_shop' => isset( $wp_query->is_shop ) ? $wp_query->is_shop : 'not_set',
					'is_archive' => $wp_query->is_archive,
				),
				'woocommerce_functions' => array(
					'is_product()' => function_exists( 'is_product' ) ? is_product() : 'not_available',
					'is_shop()' => function_exists( 'is_shop' ) ? is_shop() : 'not_available',
				),
			);
		}
	}

	/**
	 * Ensure WooCommerce recognizes the product page after query fix
	 */
	public function ensureProductPageRecognition() {
		global $wp_query;
		
		// Skip if in admin or not a WooCommerce request
		if ( is_admin() || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$current_language = $this->plugin->getCurrentLanguage();
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		
		// Check if this looks like a translated product URL
		if ( $current_language !== 'en' && preg_match( '#^/' . preg_quote( $current_language ) . '/product/([^/]+)/?$#', $request_uri, $matches ) ) {
			$product_slug_from_url = $matches[1];
			$product_query_var = get_query_var( 'product' );
			
			// Use the query var if available, otherwise use the URL slug
			$product_slug = $product_query_var ?: $product_slug_from_url;
			
			// Try to find the product
			$product = get_page_by_path( $product_slug, OBJECT, 'product' );
			
			if ( $product ) {
				// Force WordPress to recognize this as a product page
				$wp_query->is_single = true;
				$wp_query->is_singular = true;
				$wp_query->is_product = true;
				$wp_query->is_wc_endpoint_url = false;
				$wp_query->is_page = false;
				$wp_query->is_home = false;
				$wp_query->is_archive = false;
				$wp_query->is_shop = false; // Explicitly set shop to false
				
				// Set the queried object
				$wp_query->queried_object = $product;
				$wp_query->queried_object_id = $product->ID;
				
				// Set post data
				$wp_query->post = $product;
				$wp_query->posts = array( $product );
				$wp_query->post_count = 1;
				$wp_query->found_posts = 1;
				
				// Set up global post data
				setup_postdata( $product );
				}
		}
		// Fallback: Check if we have a product query var but WooCommerce doesn't recognize it as a product page
		elseif ( get_query_var( 'product' ) && ! is_product() ) {
			$product_slug = get_query_var( 'product' );
			
			// Try to find the product
			$product = get_page_by_path( $product_slug, OBJECT, 'product' );
			
			if ( $product ) {
				// Force WooCommerce to recognize this as a product page
				$wp_query->is_single = true;
				$wp_query->is_singular = true;
				$wp_query->is_product = true;
				$wp_query->is_wc_endpoint_url = false;
				$wp_query->is_page = false;
				$wp_query->is_home = false;
				$wp_query->is_archive = false;
				$wp_query->is_shop = false; // Explicitly set shop to false
				
				// Set the queried object
				$wp_query->queried_object = $product;
				$wp_query->queried_object_id = $product->ID;
				
				// Set post data
				$wp_query->post = $product;
				$wp_query->posts = array( $product );
				$wp_query->post_count = 1;
				$wp_query->found_posts = 1;
				}
		}
	}

	/**
	 * Override WooCommerce's is_shop detection for translated product pages
	 */
	public function overrideIsShopForProducts( $is_shop ) {
		// If WooCommerce thinks this is a shop page, but we have a product query var, override it
		if ( $is_shop && get_query_var( 'product' ) ) {
			$current_language = $this->plugin->getCurrentLanguage();
			
			// Only override for non-English languages
			if ( $current_language !== 'en' ) {
				// Check if the current URL looks like a product URL
				$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				if ( preg_match( '#^/' . preg_quote( $current_language ) . '/product/([^/]+)/?$#', $request_uri ) ) {
					return false; // This is NOT a shop page, it's a product page
				}
			}
		}
		
		return $is_shop;
	}

	/**
	 * Force correct template for translated product pages
	 */
	public function forceProductTemplate( $template ) {
		$current_language = $this->plugin->getCurrentLanguage();
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		
		// Check if this looks like a translated product URL
		if ( $current_language !== 'en' && preg_match( '#^/' . preg_quote( $current_language ) . '/product/([^/]+)/?$#', $request_uri ) ) {
			// Check if we have a product query var or can find the product
			$product_slug = get_query_var( 'product' );
			
			if ( $product_slug ) {
				$product = get_page_by_path( $product_slug, OBJECT, 'product' );
				
				if ( $product ) {
					// Look for single product template
					$single_product_template = locate_template( array(
						'woocommerce/single-product.php',
						'single-product.php'
					) );
					
					if ( $single_product_template ) {
						return $single_product_template;
					}
					
					// Fallback to WooCommerce's default single product template
					if ( class_exists( 'WooCommerce' ) ) {
						$wc_template = WC()->plugin_path() . '/templates/single-product.php';
						if ( file_exists( $wc_template ) ) {
							return $wc_template;
						}
					}
				}
			}
		}
		
		return $template;
	}

	/**
	 * Add debug script for WooCommerce shop page detection
	 */
	private function addWooCommerceShopDebugScript( $request_uri, $path, $current_language, $slug, $found_post_id, $shop_page_id, $is_shop ) {
		// Debug functionality disabled for production
		return;
	}

	/**
	 * Add debug script for WooCommerce query handling
	 */
	private function addShopQueryDebugScript( $method, $lang, $post_type, $is_translated_shop, $is_shop_func, $shop_page_id = null ) {
		// Debug functionality disabled for production
		return;
	}

	/**
	 * Debug template include
	 */
	public function debugTemplateInclude( $template ) {
		// Debug functionality disabled for production
		return $template;
	}


	/**
	 * Force correct WooCommerce shop template for translated shop pages
	 */
	public function forceShopTemplate( $template ) {
		if ( is_admin() ) {
			return $template;
		}

		// Only apply to translated shop pages
		if ( ! $this->isTranslatedShopPage() ) {
			return $template;
		}

		// Force WooCommerce to recognize this as shop page for template selection
				add_filter( 'woocommerce_is_shop', '__return_true' );
		add_filter( 'is_shop', '__return_true' );
		
		// Set global query flags to ensure WooCommerce functions work
		global $wp_query;
		$wp_query->is_shop = true;
		$wp_query->is_archive = true;
		$wp_query->is_home = false;
		$wp_query->is_page = false;
		$wp_query->is_singular = false;
		$wp_query->is_single = false;
		
		// Set shop page as queried object
		$shop_page_id = wc_get_page_id( 'shop' );
		if ( $shop_page_id ) {
			$wp_query->queried_object_id = $shop_page_id;
			$wp_query->queried_object = get_post( $shop_page_id );
		}
		
		// Get WooCommerce shop template
		$shop_template = $this->getWooCommerceShopTemplate();
		
		if ( $shop_template && file_exists( $shop_template ) ) {
			// Add debug logging
			$this->addTemplateForceDebugScript( $template, $shop_template );
			return $shop_template;
		}

		return $template;
	}

	/**
	 * Get WooCommerce shop template path
	 */
	private function getWooCommerceShopTemplate() {
		// First, try to use WooCommerce's own template loader to get the correct template
		// This will respect theme customizations and template hierarchy
		
		// Temporarily set the shop page query to get correct template
		global $wp_query;
		$original_is_shop = isset( $wp_query->is_shop ) ? $wp_query->is_shop : false;
		$original_post_type = $wp_query->get( 'post_type' );
		
		// Force shop page context
		$wp_query->is_shop = true;
		$wp_query->set( 'post_type', 'product' );
		
		// Use WooCommerce template hierarchy
		$template = null;
		
		// Try theme-specific WooCommerce templates first
		$theme_templates = array(
			'woocommerce/archive-product.php',
			'archive-product.php',
			'woocommerce.php'
		);
		
		foreach ( $theme_templates as $theme_template ) {
			$located = locate_template( $theme_template );
			if ( $located ) {
				$template = $located;
				break;
			}
		}
		
		// If no theme template found, use WooCommerce plugin template
		if ( ! $template ) {
			$plugin_templates = array(
				WC()->plugin_path() . '/templates/archive-product.php'
			);
			
			foreach ( $plugin_templates as $plugin_template ) {
				if ( file_exists( $plugin_template ) ) {
					$template = $plugin_template;
					break;
				}
			}
		}
		
		// Restore original query state
		$wp_query->is_shop = $original_is_shop;
		$wp_query->set( 'post_type', $original_post_type );
		
		return $template;
	}

	/**
	 * Add debug script for template forcing
	 */
	private function addTemplateForceDebugScript( $original_template, $forced_template ) {
		// Debug functionality disabled for production
		return;
	}


}