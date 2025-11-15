<?php
namespace VoxforML\Router;

/**
 * Handles language routing and URL structure
 */
class LanguageRouter {
	private $current_language = null;
	private $initialized      = false;

	public function __set( $name, $value ) {
		$this->$name = $value;
	}
	private $supported_languages = array();
	private static $detecting    = false; // Prevent recursion

	/**
	 * Constructor
	 */
	public function __construct() {
		// Initialize supported languages from enabled languages
		$this->supported_languages = get_option( 'voxfor_ml_languages', array( 'en' ) );

		// If it's a string, convert to array
		if ( is_string( $this->supported_languages ) ) {
			$this->supported_languages = explode( ',', $this->supported_languages );
			$this->supported_languages = array_map( 'trim', $this->supported_languages );
		}

		// Ensure we have at least English
		if ( empty( $this->supported_languages ) || ! is_array( $this->supported_languages ) ) {
			$this->supported_languages = array( 'en' );
		}

		// Clean up the array - remove empty values
		$this->supported_languages = array_filter( $this->supported_languages );

		// Ensure English is always included
		if ( ! in_array( 'en', $this->supported_languages ) ) {
			array_unshift( $this->supported_languages, 'en' );
		}

		// Remove duplicates
		$this->supported_languages = array_unique( $this->supported_languages );

		// Initialize language router

		// Register query var early in constructor
		add_filter( 'query_vars', array( $this, 'addQueryVars' ) );

		// Handle auto-redirect if enabled
		if ( get_option( 'voxfor_ml_auto_redirect', false ) && ! is_admin() ) {
			add_action( 'template_redirect', array( $this, 'handleAutoRedirect' ), 1 );
		}

		// Handle translated slug redirects
		add_action( 'template_redirect', array( $this, 'handleTranslatedSlugRedirect' ), 5 );

		// Add language parameter to post/page links
		add_filter( 'post_link', array( $this, 'filterPermalink' ), 10, 2 );
		add_filter( 'page_link', array( $this, 'filterPermalink' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'filterPermalink' ), 10, 2 );
		add_filter( 'term_link', array( $this, 'filterTermLink' ), 10, 3 );

		// Handle language switching
		add_action( 'init', array( $this, 'handleLanguageSwitch' ) );

		// Add parse_request hook to handle language routing
		add_action( 'parse_request', array( $this, 'parseRequest' ), 1 );

		// Also add request filter to catch the query vars
		add_filter( 'request', array( $this, 'filterRequest' ), 1 );

		// Handle translated taxonomy slug resolution
		add_action( 'parse_request', array( $this, 'resolveTranslatedTaxonomySlugs' ), 5 );

		// Add template_redirect hook as backup
		add_action( 'template_redirect', array( $this, 'handleTemplateRedirect' ), 1 );

		// Prevent WordPress canonical redirects from stripping language prefix
		add_filter(
			'redirect_canonical',
				function ( $redirect_url, $requested_url ) {
					// If request already has a supported language prefix, do not canonicalize
					$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '/' ) : '';
				foreach ( $this->supported_languages as $lang ) {
					if ( $lang !== 'en' && ( strpos( $request_uri, $lang . '/' ) === 0 || $request_uri === $lang ) ) {
						return false; // disable canonical redirect
					}
				}
				return $redirect_url;
			},
			10,
			2
		);
	}

	/**
	 * Initialize router
	 */
	public function init() {
		// Prevent multiple initialization
		if ( $this->initialized ) {
			return;
		}
		$this->initialized = true;

		// Register query var again in init to be sure
		add_filter( 'query_vars', array( $this, 'addQueryVars' ) );

		// Also add directly to global $wp
		global $wp;
		if ( $wp && ! in_array( 'voxfor_ml_lang', $wp->public_query_vars ) ) {
			$wp->add_query_var( 'voxfor_ml_lang' );
		}

		// Add rewrite rules
		$this->addRewriteRules();

		// Force flush rewrite rules to ensure language URLs work
		$current_languages = get_option( 'voxfor_ml_languages', array( 'en' ) );
		$cached_languages  = get_option( 'voxfor_ml_cached_languages', array() );

		// Force flush to apply our recent changes - temporary for debugging
		flush_rewrite_rules( true );
		update_option( 'voxfor_ml_rewrite_rules_version', VOXFOR_ML_VERSION . '_debug' );
		update_option( 'voxfor_ml_cached_languages', $current_languages );

		// Handle custom query var resolution
		add_action( 'parse_request', array( $this, 'handleCustomQueryVar' ), 5 );

		// Hooks are now registered in constructor
	}

	/**
	 * Add rewrite rules for language prefixes
	 */
	private function addRewriteRules() {
		$languages = array_diff( $this->supported_languages, array( 'en' ) );

		foreach ( $languages as $lang ) {
			// Add language prefix to all WordPress rewrite rules - force homepage
			add_rewrite_rule(
				'^' . $lang . '/?$',
				'index.php?voxfor_ml_lang=' . $lang . '&voxfor_ml_is_home=1',
				'top'
			);

			// Handle posts
			add_rewrite_rule(
				'^' . $lang . '/([0-9]{4})/([0-9]{1,2})/([0-9]{1,2})/([^/]+)/?$',
				'index.php?voxfor_ml_lang=' . $lang . '&year=$matches[1]&monthnum=$matches[2]&day=$matches[3]&name=$matches[4]',
				'top'
			);

			// Handle categories
			add_rewrite_rule(
				'^' . $lang . '/category/(.+?)/?$',
				'index.php?voxfor_ml_lang=' . $lang . '&category_name=$matches[1]',
				'top'
			);

			// Handle tags
			add_rewrite_rule(
				'^' . $lang . '/tag/(.+?)/?$',
				'index.php?voxfor_ml_lang=' . $lang . '&tag=$matches[1]',
				'top'
			);

			// Handle author pages
			add_rewrite_rule(
				'^' . $lang . '/author/([^/]+)/?$',
				'index.php?voxfor_ml_lang=' . $lang . '&author_name=$matches[1]',
				'top'
			);

			// Handle language-prefixed URLs for posts and pages
			// Use a more specific pattern to avoid conflicts with WordPress core rules
			add_rewrite_rule(
				'^' . $lang . '/([^/]+)/?$',
				'index.php?voxfor_ml_lang=' . $lang . '&post_name_or_page=$matches[1]',
				'top'
			);

			// Handle WooCommerce pages specifically
			if ( class_exists( 'WooCommerce' ) ) {
				// Shop page
				add_rewrite_rule(
					'^' . $lang . '/shop/?$',
					'index.php?voxfor_ml_lang=' . $lang . '&post_type=product',
					'top'
				);

				add_rewrite_rule(
					'^' . $lang . '/shop/page/([0-9]{1,})/?$',
					'index.php?voxfor_ml_lang=' . $lang . '&post_type=product&paged=$matches[1]',
					'top'
				);

				// WooCommerce core pages (cart, checkout, my-account)
				$wc_pages = array( 'cart', 'checkout', 'my-account' );
				foreach ( $wc_pages as $wc_page ) {
					add_rewrite_rule(
						'^' . $lang . '/' . $wc_page . '/?$',
						'index.php?voxfor_ml_lang=' . $lang . '&pagename=' . $wc_page,
						'top'
					);
				}

				// WooCommerce product pages
				add_rewrite_rule(
					'^' . $lang . '/product/([^/]+)/?$',
					'index.php?voxfor_ml_lang=' . $lang . '&product=$matches[1]',
					'top'
				);

				// WooCommerce product categories
				add_rewrite_rule(
					'^' . $lang . '/product-category/([^/]+)/?$',
					'index.php?voxfor_ml_lang=' . $lang . '&product_cat=$matches[1]',
					'top'
				);

				// WooCommerce product tags
				add_rewrite_rule(
					'^' . $lang . '/product-tag/([^/]+)/?$',
					'index.php?voxfor_ml_lang=' . $lang . '&product_tag=$matches[1]',
					'top'
				);
			}

			// Handle custom post types
			$post_types = get_post_types(
				array(
					'public'   => true,
					'_builtin' => false,
				),
				'objects'
			);
			foreach ( $post_types as $post_type ) {
				// Skip product post type as we handle it specifically above
				if ( $post_type->name === 'product' ) {
					continue;
				}

				$rewrite_slug = is_array( $post_type->rewrite ) ? $post_type->rewrite['slug'] : $post_type->name;
				add_rewrite_rule(
					'^' . $lang . '/' . $rewrite_slug . '/(.+?)/?$',
					'index.php?voxfor_ml_lang=' . $lang . '&' . $post_type->name . '=$matches[1]',
					'top'
				);
			}

			// Generic catch-all rule (must be last)
			add_rewrite_rule(
				'^' . $lang . '/(.+?)/?$',
				'index.php?voxfor_ml_lang=' . $lang . '&pagename=$matches[1]',
				'bottom'
			);
		}

		// Query var will be registered in init() method
	}

	/**
	 * Add query vars
	 */
	public function addQueryVars( $vars ) {
		$vars[] = 'voxfor_ml_lang';
		$vars[] = 'post_name_or_page';
		$vars[] = 'voxfor_ml_is_home';
		return $vars;
	}

	/**
	 * Handle template redirect - backup method for language detection
	 */
	public function handleTemplateRedirect() {

		// Check if we're on a language URL
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Parse the URL to extract language
		if ( preg_match( '#^/([a-z]{2})/(.*)#', $request_uri, $matches ) ) {
			$lang = $matches[1];
			$path = $matches[2];


			// Check if this is a supported language
			if ( in_array( $lang, $this->supported_languages ) ) {
				// Set the current language
				$this->current_language = $lang;

				// Set cookie
				if ( ! headers_sent() ) {
					setcookie(
						VOXFOR_ML_COOKIE_NAME,
						$this->current_language,
						time() + ( 365 * DAY_IN_SECONDS ),
						COOKIEPATH,
						COOKIE_DOMAIN,
						is_ssl(),
						true
					);
				}

			}
		} else {
			// No language in URL, detect from cookie or default to English
			$this->detectLanguage();
		}
	}

	/**
	 * Filter request to handle language routing
	 */
	public function filterRequest( $query_vars ) {

		// Check if we have a language query var
		if ( isset( $query_vars['voxfor_ml_lang'] ) ) {
			$lang = $query_vars['voxfor_ml_lang'];

			// Set the current language
			$this->current_language = $lang;

			// Set cookie
			if ( ! headers_sent() ) {
				setcookie(
					VOXFOR_ML_COOKIE_NAME,
					$this->current_language,
					time() + ( 365 * DAY_IN_SECONDS ),
					COOKIEPATH,
					COOKIE_DOMAIN,
					is_ssl(),
					true
				);
			}

		} else {
			// No query var, detect from cookie or default to English
			$this->detectLanguage();
		}

		return $query_vars;
	}

	/**
	 * Parse request to handle language routing
	 */
	public function parseRequest( $wp ) {

		// Check if we have a language query var
		if ( isset( $wp->query_vars['voxfor_ml_lang'] ) ) {
			$lang = $wp->query_vars['voxfor_ml_lang'];

			// Set the current language
			$this->current_language = $lang;

			// Set cookie
			if ( ! headers_sent() ) {
				setcookie(
					VOXFOR_ML_COOKIE_NAME,
					$this->current_language,
					time() + ( 365 * DAY_IN_SECONDS ),
					COOKIEPATH,
					COOKIE_DOMAIN,
					is_ssl(),
					true
				);
			}

		} else {
			// No query var, detect from cookie or default to English
			$this->detectLanguage();
		}
	}

	/**
	 * Detect current language from URL first, then fallback to cookie
	 */
	private function detectLanguage() {
		// Prevent recursion
		if ( self::$detecting ) {
			return 'en';
		}

		self::$detecting = true;

		// PRIORITY 1: Check URL query var (from rewrite rules)
		$lang = get_query_var( 'voxfor_ml_lang', '' );

		// PRIORITY 2: Parse URL path directly (optimized)
		if ( empty( $lang ) ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '/' ) : '';
			if ( ! empty( $request_uri ) ) {
				$first_segment = strtok( $request_uri, '/' );
				if ( $first_segment && in_array( $first_segment, $this->supported_languages ) ) {
					$lang = $first_segment;
				}
			}
		}

		// PRIORITY 3: For URLs without language prefix, always default to English
		if ( empty( $lang ) ) {
			$lang = 'en';

			// Clear any existing language cookie for non-prefixed URLs
			if ( ! headers_sent() ) {
				setcookie(
					VOXFOR_ML_COOKIE_NAME,
					'en',
					time() + ( 365 * DAY_IN_SECONDS ),
					COOKIEPATH,
					COOKIE_DOMAIN,
					is_ssl(),
					true
				);
			}
		}

		// Validate language is supported
		if ( ! in_array( $lang, $this->supported_languages ) ) {
			$lang = 'en';
		}

		$this->current_language = $lang;

		// Only set cookie for non-English languages
		if ( $lang !== 'en' && ! headers_sent() ) {
			setcookie(
				VOXFOR_ML_COOKIE_NAME,
				$this->current_language,
				time() + ( 365 * DAY_IN_SECONDS ),
				COOKIEPATH,
				COOKIE_DOMAIN,
				is_ssl(),
				true
			);
		}

		self::$detecting = false;
	}

	/**
	 * Handle auto-redirect based on browser language
	 */
	public function handleAutoRedirect() {
		// Only redirect on first visit
		if ( get_option( 'voxfor_ml_first_visit_only', true ) && isset( $_COOKIE['voxfor_ml_visited'] ) ) {
			return;
		}

		// Don't redirect if already on a language-specific URL
		if ( $this->current_language !== 'en' ) {
			return;
		}

		// Don't redirect AJAX, REST API, or admin requests
		if ( wp_doing_ajax() || wp_doing_cron() || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Get browser language
		$browser_lang = $this->getBrowserLanguage();

		if ( $browser_lang && $browser_lang !== 'en' && in_array( $browser_lang, $this->supported_languages ) ) {
			// Set visited cookie
			setcookie( 'voxfor_ml_visited', '1', time() + ( 365 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN );

			// Redirect to language-specific URL
			$redirect_url = $this->getLanguageUrl( get_permalink(), $browser_lang );
			wp_redirect( $redirect_url, 302 );
			exit;
		}
	}

	/**
	 * Get browser language
	 */
	private function getBrowserLanguage() {

		if ( ! isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			return null;
		}

		$languages = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) ) : array();

		foreach ( $languages as $lang ) {
			$original_lang = $lang;
			$lang          = strtolower( trim( explode( ';', $lang )[0] ) );
			$lang          = explode( '-', $lang )[0]; // Get primary language code


			if ( in_array( $lang, $this->supported_languages ) ) {
				return $lang;
			}
		}

		return null;
	}

    /**
     * Handle language switch via URL parameter
     * 
     * Security Note:
     * - This is a public action available to all users (including anonymous visitors)
     * - Language switcher links must remain cacheable; nonce is OPTIONAL here
     * - If a nonce is present, it is verified; otherwise, whitelist and referer rules apply
     */
	public function handleLanguageSwitch() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['voxfor_ml_switch_lang'] ) ) {
            $new_lang = sanitize_text_field( wp_unslash( $_GET['voxfor_ml_switch_lang'] ) );
            // Optional nonce: verify if provided; if invalid, ignore the request
            $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
            if ( $nonce && ! wp_verify_nonce( $nonce, 'voxfor_ml_switch_lang' ) ) {
                return;
            }
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

			// Security: Validate language is in supported list (whitelist validation)
			if ( ! in_array( $new_lang, $this->supported_languages, true ) ) {
				return; // Invalid language, silently ignore
			}

            // Security: Strengthened referer policy
            $referer = wp_get_referer();
            if ( $referer ) {
                $referer_host = wp_parse_url( $referer, PHP_URL_HOST );
                $site_host    = wp_parse_url( home_url(), PHP_URL_HOST );
                // Only allow if referer is our site
                if ( $referer_host && $referer_host !== $site_host ) {
                    return; // External referer, ignore
                }
            } else {
                // No referer: allow only for standard web navigation (not AJAX/REST/admin)
                if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin() ) {
                    return;
                }
            }

            // Set cookie with secure attributes
            $cookie_expires = time() + ( 365 * DAY_IN_SECONDS );
            // Use options array for SameSite attribute where supported
            if ( PHP_VERSION_ID >= 70300 ) {
                setcookie(
                    VOXFOR_ML_COOKIE_NAME,
                    $new_lang,
                    array(
                        'expires'  => $cookie_expires,
                        'path'     => COOKIEPATH,
                        'domain'   => COOKIE_DOMAIN,
                        'secure'   => is_ssl(),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    )
                );
            } else {
                // Fallback for older PHP versions (SameSite not available)
                setcookie(
                    VOXFOR_ML_COOKIE_NAME,
                    $new_lang,
                    $cookie_expires,
                    COOKIEPATH,
                    COOKIE_DOMAIN,
                    is_ssl(),
                    true
                );
            }

            // Redirect to language-specific URL (remove the query args including nonce)
            $redirect_url = $this->getLanguageUrl( remove_query_arg( array( 'voxfor_ml_switch_lang', '_wpnonce' ) ), $new_lang );
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Filter permalink to add language prefix and use translated slugs
	 */
	public function filterPermalink( $permalink, $post ) {
		if ( $this->current_language !== 'en' ) {
			// Check if slug translation is enabled
			if ( get_option( 'voxfor_ml_translate_slugs', false ) ) {
				$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
				if ( $slug_manager && is_object( $post ) ) {
					$translated_url = $slug_manager->getTranslatedUrl( $post->ID, $this->current_language, $permalink );
					if ( $translated_url ) {
						return $translated_url;
					}
				}
			}
			
			// Fallback to original behavior
			$permalink = $this->getLanguageUrl( $permalink, $this->current_language );
		}

		return $permalink;
	}

	/**
	 * Filter term link to add language prefix
	 */
	public function filterTermLink( $termlink, $term, $taxonomy ) {
		if ( $this->current_language !== 'en' ) {
			// Check if this is a WooCommerce taxonomy that we translate
			$wc_taxonomies = array( 'product_cat', 'product_tag' );
			
			// Add product attributes
			if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
				$attribute_taxonomies = wc_get_attribute_taxonomies();
				foreach ( $attribute_taxonomies as $attribute ) {
					$wc_taxonomies[] = 'pa_' . $attribute->attribute_name;
				}
			}
			
			if ( in_array( $taxonomy, $wc_taxonomies ) ) {
				// Get translated slug if available
				$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
				if ( $slug_manager ) {
					$translated_slug = $slug_manager->getTaxonomySlug( $term->term_id, $this->current_language );
					if ( $translated_slug ) {
						// Build the translated URL
						$site_url = trailingslashit( site_url() );
						$archive_type = ( $taxonomy === 'product_cat' ) ? 'product-category' : 
						               ( ( $taxonomy === 'product_tag' ) ? 'product-tag' : $taxonomy );
						
						$termlink = $site_url . $this->current_language . '/' . $archive_type . '/' . $translated_slug . '/';
					
					return $termlink;
					}
				}
			}
			
			// Fallback to language prefix only
			$termlink = $this->getLanguageUrl( $termlink, $this->current_language );
		}

		return $termlink;
	}

	/**
	 * Get URL for specific language
	 */
	public function getLanguageUrl( $url, $language ) {
		// Parse the current URL
		$parsed_url = wp_parse_url( $url );
		$path = isset( $parsed_url['path'] ) ? trim( $parsed_url['path'], '/' ) : '';
		$site_url = trailingslashit( site_url() );

		// Step 1: Detect if this is the homepage
		$is_homepage = $this->isHomepage( $url, $path );
		
		if ( $is_homepage ) {
			// For homepage, return simple language URLs
		if ( $language === 'en' ) {
				return $site_url; // Just the domain for English
			} else {
				return $site_url . $language . '/'; // Domain + language prefix
			}
		}

	// Step 2: Check for special archive pages and product pages
	// Normalize path to have leading slash for regex
	$normalized_path = '/' . ltrim( $path, '/' );
	
	// URL decode the normalized path to handle encoded characters (Hebrew, etc.)
	$decoded_normalized_path = urldecode( $normalized_path );
	
	// Author page: /author/username/ or /es/author/administrador/
	if ( preg_match( '#(?:/[a-z]{2})?/(author)/([^/]+)/?$#', $decoded_normalized_path, $matches ) ) {
		$current_slug = $matches[2];
		return $this->generateAuthorUrlWithTranslation( $current_slug, $language, $site_url );
	}
	
	// WooCommerce pages
	if ( class_exists( 'WooCommerce' ) ) {
		// Individual product: /product/slug/ or /es/product/slug/
		if ( preg_match( '#(?:/[a-z]{2})?/product/([^/]+)/?$#', $decoded_normalized_path, $matches ) ) {
			$product_slug = $matches[1];
			return $this->generateProductUrlWithTranslation( $product_slug, $language, $site_url );
		}
		
		// Product category: /product-category/men/ or /es/product-category/hombres/
		if ( preg_match( '#(?:/[a-z]{2})?/(product-category)/([^/]+)/?$#', $decoded_normalized_path, $matches ) ) {
			$category_slug = $matches[2];
			return $this->generateWooCommerceArchiveUrlWithTranslation( 'product-category', 'product_cat', $category_slug, $language, $site_url );
		}

		// Product tag: /product-tag/sale/ or /es/product-tag/venta/
		if ( preg_match( '#(?:/[a-z]{2})?/(product-tag)/([^/]+)/?$#', $decoded_normalized_path, $matches ) ) {
			$tag_slug = $matches[2];
			return $this->generateWooCommerceArchiveUrlWithTranslation( 'product-tag', 'product_tag', $tag_slug, $language, $site_url );
		}

		// Shop page: /shop/ or /es/shop/
		if ( preg_match( '#(?:/[a-z]{2})?/shop/?$#', $decoded_normalized_path ) ) {
			if ( $language === 'en' ) {
				return $site_url . 'shop/';
			} else {
				return $site_url . $language . '/shop/';
			}
		}
	}

		// Step 3: Extract current language and slug from URL
		$current_language = $this->getCurrentLanguage();
		$slug = $this->extractSlugFromPath( $path, $current_language );

		// Add debug for individual URL generation
		$this->addIndividualUrlGenerationDebugScript( $url, $path, $current_language, $slug, $language );

		// Safety check: if slug is empty after extraction, treat as homepage
		if ( empty( $slug ) ) {
			if ( $language === 'en' ) {
				return $site_url;
			} else {
				return $site_url . $language . '/';
			}
		}

		// Step 4: Find the post ID for this content
		$post_id = $this->findPostIdBySlug( $slug, $current_language );
		
		if ( ! $post_id ) {
			return $this->generateFallbackUrl( $url, $language );
		}

		// Step 5: Generate URL for target language
		$generated_url = $this->generateUrlForLanguage( $post_id, $language, $site_url );
		
		return $generated_url;
	}

	/**
	 * Check if the current URL is the homepage
	 */
	private function isHomepage( $url, $path ) {
		// Empty path means homepage
		if ( empty( $path ) ) {
			return true;
		}

		// Check if path is just a language code (e.g., 'es', 'he')
		if ( in_array( $path, $this->supported_languages ) ) {
			return true;
		}

		// Extract and decode the slug properly
		$current_language = $this->getCurrentLanguage();
		$slug = $this->extractSlugFromPath( $path, $current_language );

		// If no slug after extraction, it might be homepage
		if ( empty( $slug ) ) {
			return true;
		}

		// Check if this is a static front page
		$page_on_front = get_option( 'page_on_front' );
		if ( $page_on_front ) {
			$front_page = get_post( $page_on_front );
			if ( $front_page ) {
				// Check if this slug belongs to the front page
				if ( $slug === $front_page->post_name ) {
					return true;
				}
				
				// Check if this is a translated slug of the front page
				$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
				if ( $slug_manager ) {
					$post_id = $slug_manager->findPostBySlug( $slug, $current_language );
					if ( $post_id && $post_id == $page_on_front ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Extract slug from URL path with proper URL decoding
	 */
	private function extractSlugFromPath( $path, $current_language ) {
		if ( empty( $path ) ) {
			return '';
		}

		// URL decode the path first to handle encoded characters like %d7%90%d7%95%d7%93%d7%95%d7%aa
		$decoded_path = urldecode( $path );

		// Normalize path - ensure it starts with /
		if ( strpos( $decoded_path, '/' ) !== 0 ) {
			$decoded_path = '/' . $decoded_path;
		}

		// If current language is not English, remove language prefix
		if ( $current_language !== 'en' ) {
			// Check for language prefix after the leading slash: /es/acerca-de/
			$language_prefix = '/' . $current_language . '/';
			if ( strpos( $decoded_path, $language_prefix ) === 0 ) {
				// Remove the language prefix: /es/acerca-de/ -> acerca-de/
				$decoded_path = substr( $decoded_path, strlen( $language_prefix ) );
			}
		} else {
			// For English, just remove the leading slash
			$decoded_path = ltrim( $decoded_path, '/' );
		}

		// Handle WooCommerce URLs
		if ( strpos( $decoded_path, 'product/' ) === 0 ) {
			// NUCLEAR FIX: Don't process product URLs in language switcher
		// Return the product slug as-is to prevent shop page fallback
		$product_slug = substr( $decoded_path, 8 ); // Remove 'product/'
		$decoded_path = $product_slug;
		} elseif ( strpos( $decoded_path, 'product-category/' ) === 0 ) {
			// Handle WooCommerce category URLs: product-category/slug -> slug
			$decoded_path = substr( $decoded_path, 17 ); // Remove 'product-category/'
		} elseif ( strpos( $decoded_path, 'product-tag/' ) === 0 ) {
			// Handle WooCommerce tag URLs: product-tag/slug -> slug
			$decoded_path = substr( $decoded_path, 12 ); // Remove 'product-tag/'
		} elseif ( strpos( $decoded_path, 'author/' ) === 0 ) {
			// Handle author URLs: author/username -> author/username (keep as-is for special handling)
			// Don't remove the 'author/' prefix as we need it for detection
		}

		// Clean up the slug - remove trailing slashes and extra spaces
		$slug = trim( $decoded_path, '/' );
		$slug = trim( $slug );

		return $slug;
	}

	/**
	 * Detect URL type from path
	 */
	private function detectUrlType( $path ) {
		$decoded_path = urldecode( $path );
		
		// Remove language prefix if present
		foreach ( $this->supported_languages as $lang ) {
			if ( $lang !== 'en' && strpos( $decoded_path, '/' . $lang . '/' ) === 0 ) {
				$decoded_path = substr( $decoded_path, strlen( '/' . $lang . '/' ) );
				break;
			}
		}
		
		$decoded_path = ltrim( $decoded_path, '/' );
		
		if ( strpos( $decoded_path, 'product-category/' ) === 0 ) {
			return array( 'type' => 'product-category', 'slug' => substr( $decoded_path, 17 ) );
		} elseif ( strpos( $decoded_path, 'product-tag/' ) === 0 ) {
			return array( 'type' => 'product-tag', 'slug' => substr( $decoded_path, 12 ) );
		} elseif ( strpos( $decoded_path, 'product/' ) === 0 ) {
			return array( 'type' => 'product', 'slug' => substr( $decoded_path, 8 ) );
		} elseif ( strpos( $decoded_path, 'author/' ) === 0 ) {
			return array( 'type' => 'author', 'slug' => substr( $decoded_path, 7 ) );
		}
		
		return array( 'type' => 'post', 'slug' => trim( $decoded_path, '/' ) );
	}

	/**
	 * Find post ID by slug (handles both English and translated slugs)
	 */
	private function findPostIdBySlug( $slug, $current_language ) {
		if ( empty( $slug ) ) {
			return null;
		}

		$found_post_id = null;
		$search_method = 'none';
		
		// NUCLEAR FIX: Check if this is a product slug first
		// Don't let it fallback to shop page
		$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
		if ( $slug_manager ) {
		$product_post_id = $slug_manager->findPostBySlug( $slug, $current_language );
		if ( $product_post_id ) {
			$product_post = get_post( $product_post_id );
			if ( $product_post && $product_post->post_type === 'product' ) {
				return $product_post_id;
			}
		}
		}

		// Special handling for WooCommerce shop page
		if ( class_exists( 'WooCommerce' ) ) {
			$shop_page_id = wc_get_page_id( 'shop' );
			if ( $shop_page_id ) {
				$shop_page = get_post( $shop_page_id );
				if ( $shop_page ) {
					// Check if this is the English shop page slug (store, shop)
					if ( $slug === $shop_page->post_name || $slug === 'store' || $slug === 'shop' ) {
						$found_post_id = $shop_page_id;
						$search_method = 'wc_shop_english';
					} else {
						// Check if this is a translated shop page slug (tienda, חנות)
						$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
						if ( $slug_manager ) {
							$shop_translated_post_id = $slug_manager->findPostBySlug( $slug, $current_language );
							if ( $shop_translated_post_id && $shop_translated_post_id == $shop_page_id ) {
								$found_post_id = $shop_page_id;
								$search_method = 'wc_shop_translated';
							}
						}
					}
				}
			}
		}

		// If we're on a non-English page and not found yet, try translated slug first
		if ( ! $found_post_id && $current_language !== 'en' ) {
			$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
			if ( $slug_manager ) {
				$post_id = $slug_manager->findPostBySlug( $slug, $current_language );
				if ( $post_id ) {
					$found_post_id = $post_id;
					$search_method = 'translated_slug';
				}
			}
		}

		// Try to find by English slug if not found
		if ( ! $found_post_id ) {
			$post = get_posts( array(
				'name' => $slug,
				'post_type' => array( 'post', 'page', 'product' ),
				'post_status' => 'publish',
				'posts_per_page' => 1,
			) );

			if ( ! empty( $post ) ) {
				$found_post_id = $post[0]->ID;
				$search_method = 'english_slug';
			}
		}

		// Add debug logging
		$this->addPostIdSearchDebugScript( $slug, $current_language, $found_post_id, $search_method );

		return $found_post_id;
	}

	/**
	 * Generate URL for specific language and post ID
	 */
	private function generateUrlForLanguage( $post_id, $language, $site_url ) {
		// Add debug logging for URL generation
		$this->addUrlGenerationDebugScript( $post_id, $language, $site_url );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $site_url . ( $language !== 'en' ? $language . '/' : '' );
		}

		// Special handling for WooCommerce shop page
		if ( class_exists( 'WooCommerce' ) ) {
			$shop_page_id = wc_get_page_id( 'shop' );
			if ( $shop_page_id && $post_id == $shop_page_id ) {
				// This is the shop page
				if ( $language === 'en' ) {
					// For English, use the original shop page slug (usually 'store')
					return $site_url . $post->post_name . '/';
				} else {
					// For other languages, try to get translated shop page slug
					$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
					if ( $slug_manager ) {
						$translated_slug = $slug_manager->getSlug( $post_id, $language );
						if ( $translated_slug ) {
							return $site_url . $language . '/' . $translated_slug . '/';
						}
					}
					
					// Fallback: use original shop page slug with language prefix
					return $site_url . $language . '/' . $post->post_name . '/';
				}
			}
		}

		// Check if this is a WooCommerce product
		$is_product = ( $post->post_type === 'product' );
		$product_prefix = $is_product ? 'product/' : '';

		if ( $language === 'en' ) {
			// For English, use the original post slug
			return $site_url . $product_prefix . $post->post_name . '/';
		} else {
			// For other languages, try to get translated slug
			$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
			if ( $slug_manager ) {
				$translated_slug = $slug_manager->getSlug( $post_id, $language );
				if ( $translated_slug ) {
					return $site_url . $language . '/' . $product_prefix . $translated_slug . '/';
				}
			}
			
			// Fallback: use original slug with language prefix
			return $site_url . $language . '/' . $product_prefix . $post->post_name . '/';
		}
	}

	/**
	 * Generate URL for author pages with proper translation handling
	 */
	private function generateAuthorUrlWithTranslation( $current_slug, $language, $site_url ) {
		$current_language = $this->getCurrentLanguage();
		$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
		
		if ( ! $slug_manager ) {
			// Fallback to simple method
			if ( $language === 'en' ) {
				return $site_url . 'author/' . $current_slug . '/';
			} else {
				return $site_url . $language . '/author/' . $current_slug . '/';
			}
		}
		
		// Step 1: Find the original user
		$original_user = null;
		
		if ( $current_language === 'en' ) {
			// Current slug is already the original English username
			$original_user = get_user_by( 'slug', $current_slug );
		} else {
			// Current slug might be translated, find the original user
			$original_user = $slug_manager->findUserByTranslatedSlug( $current_slug, $current_language );
			
			// If not found as translated, try as original username
			if ( ! $original_user ) {
				$original_user = get_user_by( 'slug', $current_slug );
			}
		}
		
		if ( ! $original_user ) {
			// Fallback: use the current slug as-is
			if ( $language === 'en' ) {
				return $site_url . 'author/' . $current_slug . '/';
			} else {
				return $site_url . $language . '/author/' . $current_slug . '/';
			}
		}
		
		// Step 2: Get the appropriate slug for target language
		$target_slug = $original_user->user_nicename; // Default to original username
		
		if ( $language !== 'en' ) {
			// Get translated slug for target language
			$translated_slug = $slug_manager->getAuthorSlug( $original_user->ID, $language );
			if ( $translated_slug ) {
				$target_slug = $translated_slug;
			}
		}
		
		// Step 3: Build the URL
		if ( $language === 'en' ) {
			$url = $site_url . 'author/' . $target_slug . '/';
	} else {
		$url = $site_url . $language . '/author/' . $target_slug . '/';
	}
	
	return $url;
	}

	/**
	 * Generate URL for WooCommerce archive pages with proper translation handling
	 */
	private function generateWooCommerceArchiveUrlWithTranslation( $archive_type, $taxonomy, $current_slug, $language, $site_url ) {
		$current_language = $this->getCurrentLanguage();
		$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
		
		if ( ! $slug_manager ) {
			// Fallback to simple method
			return $this->generateWooCommerceArchiveUrl( $archive_type, $current_slug, $language, $site_url );
		}
		
		// Step 1: Find the original term
		$original_term = null;
		
		if ( $current_language === 'en' ) {
			// Current slug is already the original English slug
			$original_term = get_term_by( 'slug', $current_slug, $taxonomy );
		} else {
			// Current slug might be translated, find the original term
			$original_term = $slug_manager->findTermByTranslatedSlug( $current_slug, $current_language, $taxonomy );
			
			// If not found as translated, try as original slug
			if ( ! $original_term ) {
				$original_term = get_term_by( 'slug', $current_slug, $taxonomy );
			}
		}
		
		if ( ! $original_term ) {
			// Fallback: use the current slug as-is
			return $this->generateWooCommerceArchiveUrl( $archive_type, $current_slug, $language, $site_url );
		}
		
		// Step 2: Get the appropriate slug for target language
		$target_slug = $original_term->slug; // Default to original slug
		
		if ( $language !== 'en' ) {
			// Get translated slug for target language
			$translated_slug = $slug_manager->getTaxonomySlug( $original_term->term_id, $language );
			if ( $translated_slug ) {
				$target_slug = $translated_slug;
			}
		}
		
		// Step 3: Build the URL
		if ( $language === 'en' ) {
			$url = $site_url . $archive_type . '/' . $target_slug . '/';
		} else {
		$url = $site_url . $language . '/' . $archive_type . '/' . $target_slug . '/';
	}
	
	return $url;
	}


	/**
	 * Generate URL for WooCommerce product pages with translation
	 */
	private function generateProductUrlWithTranslation( $product_slug, $language, $site_url ) {
		$current_language = $this->getCurrentLanguage();
		$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
		
		if ( ! $slug_manager ) {
			// Fallback without slug manager
			if ( $language === 'en' ) {
				return $site_url . 'product/' . $product_slug . '/';
			} else {
				return $site_url . $language . '/product/' . $product_slug . '/';
			}
		}
		
		// Step 1: Find the product post ID from the current slug
		$product_post_id = null;
		
		if ( $current_language === 'en' ) {
			// Current slug is the English slug
			$product = get_page_by_path( $product_slug, OBJECT, 'product' );
			if ( $product ) {
				$product_post_id = $product->ID;
			}
		} else {
			// Current slug might be translated, find by translated slug
			$product_post_id = $slug_manager->findPostBySlug( $product_slug, $current_language );
			
			// Verify it's a product
			if ( $product_post_id && get_post_type( $product_post_id ) !== 'product' ) {
				$product_post_id = null;
			}
		}
		
		if ( ! $product_post_id ) {
			// Fallback: use the slug as-is
			if ( $language === 'en' ) {
				return $site_url . 'product/' . $product_slug . '/';
			} else {
				return $site_url . $language . '/product/' . $product_slug . '/';
			}
		}
		
		// Step 2: Get the appropriate slug for the target language
		$target_slug = get_post_field( 'post_name', $product_post_id ); // Default to English slug
		
		if ( $language !== 'en' ) {
			// Get translated slug for target language
			$translated_slug = $slug_manager->getSlug( $product_post_id, $language );
			if ( $translated_slug ) {
				$target_slug = $translated_slug;
			}
		}
		
		// Step 3: Build the URL
		if ( $language === 'en' ) {
			return $site_url . 'product/' . $target_slug . '/';
		} else {
			return $site_url . $language . '/product/' . $target_slug . '/';
		}
	}

	/**
	 * Generate URL for WooCommerce archive pages (categories, tags, shop)
	 */
	private function generateWooCommerceArchiveUrl( $archive_type, $term_slug, $language, $site_url ) {
		if ( $language === 'en' ) {
			// For English, use original structure
			return $site_url . $archive_type . '/' . $term_slug . '/';
		} else {
			// For other languages, check if we have a translated term name
			$translated_slug = $this->getTranslatedTermSlug( $term_slug, $archive_type, $language );
			if ( $translated_slug ) {
				return $site_url . $language . '/' . $archive_type . '/' . $translated_slug . '/';
			}
			
			// Fallback: use original slug with language prefix
			return $site_url . $language . '/' . $archive_type . '/' . $term_slug . '/';
		}
	}

	/**
	 * Get translated term slug from database
	 */
	private function getTranslatedTermSlug( $original_slug, $archive_type, $language ) {
		// Get the taxonomy name from archive type
		$taxonomy = '';
		switch ( $archive_type ) {
			case 'product-category':
				$taxonomy = 'product_cat';
				break;
			case 'product-tag':
				$taxonomy = 'product_tag';
				break;
			default:
				// Handle product attributes (pa_color, pa_size, etc.)
				if ( strpos( $archive_type, 'pa_' ) === 0 ) {
					$taxonomy = $archive_type;
				}
				break;
		}

		if ( empty( $taxonomy ) ) {
			return null;
		}

		// Find the term by original slug
		$term = get_term_by( 'slug', $original_slug, $taxonomy );
		if ( ! $term ) {
			return null;
		}

		// Get translated slug from SlugManager
		$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
		if ( ! $slug_manager ) {
			return null;
		}

	$translated_slug = $slug_manager->getTaxonomySlug( $term->term_id, $language );

	return $translated_slug;
	}

	/**
	 * Generate fallback URL when post ID cannot be determined
	 */
	private function generateFallbackUrl( $url, $language ) {
		if ( $language === 'en' ) {
			return $this->removeLanguagePrefix( $url );
		}

		// Add language prefix to existing URL
		$parsed_url = wp_parse_url( $url );
		$path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/';

		// Remove existing language prefix if any
		$path = $this->removeLanguagePrefixFromPath( $path );

		// Add new language prefix
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
	 * Get post ID from URL (legacy method - kept for compatibility)
	 */
	private function getPostIdFromUrl( $url ) {
		$parsed_url = wp_parse_url( $url );
		$path = isset( $parsed_url['path'] ) ? trim( $parsed_url['path'], '/' ) : '';
		
		if ( $this->isHomepage( $url, $path ) ) {
			$page_on_front = get_option( 'page_on_front' );
			return $page_on_front ? intval( $page_on_front ) : null;
		}

		$current_language = $this->getCurrentLanguage();
		$slug = $this->extractSlugFromPath( $path, $current_language );
		
		return $this->findPostIdBySlug( $slug, $current_language );
	}

	/**
	 * Remove language prefix from URL
	 */
	private function removeLanguagePrefix( $url ) {
		$parsed_url = wp_parse_url( $url );
		$path       = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/';

		$path = $this->removeLanguagePrefixFromPath( $path );

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
	 * Remove language prefix from path
	 */
	private function removeLanguagePrefixFromPath( $path ) {
		$languages = array_diff( $this->supported_languages, array( 'en' ) );

		foreach ( $languages as $lang ) {
			if ( strpos( $path, '/' . $lang . '/' ) === 0 ) {
				$path = substr( $path, strlen( $lang ) + 1 );
				break;
			} elseif ( $path === '/' . $lang ) {
				$path = '/';
				break;
			}
		}

		return $path;
	}

	/**
	 * Get current language
	 */
	public function getCurrentLanguage() {
		// If current language is not set, detect it
		if ( $this->current_language === null ) {
			$this->detectLanguage();
		}

		return $this->current_language;
	}


	/**
	 * Handle custom query var to resolve posts/pages
	 */
	public function handleCustomQueryVar( $wp ) {
		// Handle homepage flag first
		if ( isset( $wp->query_vars['voxfor_ml_is_home'] ) && $wp->query_vars['voxfor_ml_is_home'] ) {
			// Check if a static page is set as front page
			$page_on_front = get_option( 'page_on_front' );
			$show_on_front = get_option( 'show_on_front' );


			if ( $show_on_front === 'page' && $page_on_front ) {
				// Static page is set as homepage - load that specific page
				$wp->query_vars['page_id']  = $page_on_front;
				$wp->query_vars['pagename'] = get_post_field( 'post_name', $page_on_front );
				unset( $wp->query_vars['voxfor_ml_is_home'] );
			} else {
				// Blog homepage - show latest posts
				$wp->query_vars['is_home'] = true;
				unset( $wp->query_vars['voxfor_ml_is_home'] );
			}
			return;
		}

		if ( ! isset( $wp->query_vars['post_name_or_page'] ) || empty( $wp->query_vars['post_name_or_page'] ) ) {
			return;
		}

		$slug = $wp->query_vars['post_name_or_page'];
		$lang = isset( $wp->query_vars['voxfor_ml_lang'] ) ? $wp->query_vars['voxfor_ml_lang'] : 'en';


		// Special handling for WooCommerce shop page
		if ( class_exists( 'WooCommerce' ) ) {
			$shop_page_id = wc_get_page_id( 'shop' );
			if ( $shop_page_id ) {
				$shop_page = get_post( $shop_page_id );
				if ( $shop_page && $shop_page->post_name === $slug ) {
					// This should be handled as a WooCommerce shop page
					$wp->query_vars['post_type'] = 'product';
					unset( $wp->query_vars['post_name_or_page'] );
					unset( $wp->query_vars['pagename'] );
					unset( $wp->query_vars['page_id'] );
					return;
				}
			}
		}

		// First try to find by translated slug if we have a non-English language
		if ( $lang !== 'en' ) {
			$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
			if ( $slug_manager ) {
			$post_id = $slug_manager->findPostBySlug( $slug, $lang );
			if ( $post_id ) {
				$post = get_post( $post_id );
				if ( $post ) {
					// Set the correct query vars based on post type
						if ( $post->post_type === 'page' ) {
							$wp->query_vars['pagename'] = $post->post_name;
							$wp->query_vars['page_id']  = $post->ID;
						} else {
							$wp->query_vars['name']      = $post->post_name;
							$wp->query_vars['post_type'] = $post->post_type;
						}
						unset( $wp->query_vars['post_name_or_page'] );
						return;
					}
				}
			}
		}

		// Fallback: try to find a post with this slug (original behavior)
		$post = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);

		if ( ! empty( $post ) ) {
			// Found a post, set the correct query vars
			$wp->query_vars['name']      = $slug;
			$wp->query_vars['post_type'] = $post[0]->post_type;
			unset( $wp->query_vars['post_name_or_page'] );
			return;
		}

		// Try to find a page with this slug
		$page = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);

		if ( ! empty( $page ) ) {
			// Found a page, set the correct query vars
			$wp->query_vars['pagename'] = $slug;
			$wp->query_vars['page_id']  = $page[0]->ID;
			unset( $wp->query_vars['post_name_or_page'] );
			return;
		}

	}

	/**
	 * Get language URLs for current page
	 */
	public function getLanguageUrls() {
		// Use a single, reliable method to get the current URL
		$current_url = $this->getRealCurrentUrl();

		// Validate theme compatibility for current URL
		$current_url = $this->validateThemeCompatibility( $current_url );

		$urls = array();

		foreach ( $this->supported_languages as $lang ) {
			$urls[ $lang ] = $this->getLanguageUrl( $current_url, $lang );
		}

		// Add debug to see what URL was passed to getLanguageUrl
		$this->addGetLanguageUrlsDebugScript( $current_url );

		// Add console logging for all language switcher URLs
		$this->addLanguageSwitcherUrlsDebugScript( $current_url, $urls );

		return $urls;
	}

	/**
	 * Get the real current URL using multiple methods for maximum reliability
	 */
	private function getRealCurrentUrl() {
		$current_language = $this->getCurrentLanguage();
		
		// Method 1: Check server URL first to detect translated shop pages
		$server_url = $this->getUrlFromServerRequest();
		$parsed_url = wp_parse_url( $server_url );
		$path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/';
		
		// NUCLEAR FIX: Check for PRODUCT URLs FIRST before shop page detection
		// URL decode the path to handle Hebrew and other encoded characters
		$decoded_path = urldecode( $path );
		
		if ( class_exists( 'WooCommerce' ) ) {
			// PRIORITY 1: Check if this is a product URL
			if ( $current_language !== 'en' ) {
				$language_prefix = '/' . $current_language . '/';
				if ( strpos( $decoded_path, $language_prefix . 'product/' ) === 0 ) {
				// This is definitely a product URL: /es/product/sudadera-azul/ or /he/product/סווטשירט-כחול/
				$product_slug = substr( $decoded_path, strlen( $language_prefix . 'product/' ) );
				$product_slug = trim( $product_slug, '/' );
				
				// Find the product by translated slug
				$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
				if ( $slug_manager ) {
					$product_post_id = $slug_manager->findPostBySlug( $product_slug, $current_language );
					if ( $product_post_id ) {
						$product_post = get_post( $product_post_id );
						if ( $product_post && $product_post->post_type === 'product' ) {
							return $this->constructUrlFromPostId( $product_post_id, $current_language );
							}
						}
					}
				}
		} elseif ( $current_language === 'en' && strpos( $decoded_path, '/product/' ) === 0 ) {
			// English product URL: /product/red-hoodie/
			$product_slug = substr( $decoded_path, 9 ); // Remove '/product/'
			$product_slug = trim( $product_slug, '/' );
			
			// Find the product by English slug
			$product_post = get_page_by_path( $product_slug, OBJECT, 'product' );
			if ( $product_post ) {
				return $this->constructUrlFromPostId( $product_post->ID, $current_language );
				}
			}
			
			// PRIORITY 2: Check for shop page patterns (only if NOT a product)
			$shop_page_id = wc_get_page_id( 'shop' );
			if ( $shop_page_id ) {
				// Check for translated shop page patterns: /es/tienda/, /he/חנות/
				if ( $current_language !== 'en' ) {
					$language_prefix = '/' . $current_language . '/';
					// Use decoded path for shop page detection too
					if ( strpos( $decoded_path, $language_prefix ) === 0 && strpos( $decoded_path, $language_prefix . 'product/' ) !== 0 ) {
						$slug_after_lang = substr( $decoded_path, strlen( $language_prefix ) );
						$slug_after_lang = trim( $slug_after_lang, '/' );
						
						// Check if this slug belongs to the shop page
						$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
						if ( $slug_manager ) {
							$found_post_id = $slug_manager->findPostBySlug( $slug_after_lang, $current_language );
							if ( $found_post_id && $found_post_id == $shop_page_id ) {
								// This is a translated shop page
								return $this->constructUrlFromPostId( $shop_page_id, $current_language );
							}
						}
					}
				}
				
				// Check for English shop page
				if ( $current_language === 'en' ) {
					$slug = trim( $decoded_path, '/' );
					$shop_page = get_post( $shop_page_id );
					if ( $shop_page && ( $slug === $shop_page->post_name || $slug === 'store' || $slug === 'shop' ) ) {
						return $this->constructUrlFromPostId( $shop_page_id, $current_language );
					}
				}
			}
		}
		
		// Method 2: Check for WordPress archive pages using WordPress functions
		
		// Author page
		if ( is_author() ) {
			$author = get_queried_object();
			if ( $author && isset( $author->user_nicename ) ) {
				$site_url = trailingslashit( site_url() );
				
				// Get translated slug if available
				$slug_to_use = $author->user_nicename;
				if ( $current_language !== 'en' ) {
					$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
					if ( $slug_manager ) {
						$translated_slug = $slug_manager->getAuthorSlug( $author->ID, $current_language );
						if ( $translated_slug ) {
							$slug_to_use = $translated_slug;
						}
					}
				}
				
				if ( $current_language === 'en' ) {
					return $site_url . 'author/' . $slug_to_use . '/';
				} else {
					return $site_url . $current_language . '/author/' . $slug_to_use . '/';
				}
			}
		}
		
		// WooCommerce archive pages
		if ( class_exists( 'WooCommerce' ) ) {
			// Shop page
			if ( is_shop() ) {
				$shop_page_id = wc_get_page_id( 'shop' );
				if ( $shop_page_id ) {
					return $this->constructUrlFromPostId( $shop_page_id, $current_language );
				}
			}
			
			// Product category
			if ( is_product_category() ) {
				$category = get_queried_object();
				if ( $category && isset( $category->slug ) ) {
					$site_url = trailingslashit( site_url() );
					
					// Get translated slug if available
					$slug_to_use = $category->slug;
					if ( $current_language !== 'en' ) {
						$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
						if ( $slug_manager ) {
							$translated_slug = $slug_manager->getTaxonomySlug( $category->term_id, $current_language );
							if ( $translated_slug ) {
								$slug_to_use = $translated_slug;
							}
						}
					}
					
					if ( $current_language === 'en' ) {
						return $site_url . 'product-category/' . $slug_to_use . '/';
					} else {
						return $site_url . $current_language . '/product-category/' . $slug_to_use . '/';
					}
				}
			}
			
			// Product tag
			if ( is_product_tag() ) {
				$tag = get_queried_object();
				if ( $tag && isset( $tag->slug ) ) {
					$site_url = trailingslashit( site_url() );
					
					// Get translated slug if available
					$slug_to_use = $tag->slug;
					if ( $current_language !== 'en' ) {
						$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
						if ( $slug_manager ) {
							$translated_slug = $slug_manager->getTaxonomySlug( $tag->term_id, $current_language );
							if ( $translated_slug ) {
								$slug_to_use = $translated_slug;
							}
						}
					}
					
					if ( $current_language === 'en' ) {
						return $site_url . 'product-tag/' . $slug_to_use . '/';
					} else {
						return $site_url . $current_language . '/product-tag/' . $slug_to_use . '/';
					}
				}
			}
		}
		
		// Method 3: Try to get from WordPress globals for singular content
		global $post, $wp_query;
		
		if ( is_singular() ) {
			if ( $post && isset( $post->ID ) ) {
				return $this->constructUrlFromPostId( $post->ID, $current_language );
			} elseif ( $wp_query && $wp_query->post ) {
				return $this->constructUrlFromPostId( $wp_query->post->ID, $current_language );
			} elseif ( get_queried_object_id() ) {
				return $this->constructUrlFromPostId( get_queried_object_id(), $current_language );
			}
		}

		// Method 4: Parse from server URL as final fallback
		return $server_url;
	}

	/**
	 * Construct URL from post ID and language
	 */
	private function constructUrlFromPostId( $post_id, $language ) {
		if ( $language === 'en' ) {
			// For English, use the original permalink
			return get_permalink( $post_id );
		} else {
			// For other languages, construct URL with translated slug
			$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
			if ( $slug_manager ) {
				$translated_slug = $slug_manager->getSlug( $post_id, $language );
				if ( $translated_slug ) {
					$site_url = trailingslashit( site_url() );
					
				// NUCLEAR FIX: Check if this is a WooCommerce product
				$post = get_post( $post_id );
				if ( $post && $post->post_type === 'product' && class_exists( 'WooCommerce' ) ) {
					// For WooCommerce products, add /product/ prefix
					return $site_url . $language . '/product/' . $translated_slug . '/';
					} else {
						// For regular posts/pages, use direct slug
						return $site_url . $language . '/' . $translated_slug . '/';
					}
				}
			}
			
			// Fallback: add language prefix to original URL
			$original_url = get_permalink( $post_id );
			return $this->addLanguagePrefix( $original_url, $language );
		}
	}

	/**
	 * Get URL from server request (fallback method)
	 */
	private function getUrlFromServerRequest() {
		$http_host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		// Use esc_url_raw() to sanitize while preserving URL encoding for international characters (Hebrew, Arabic, etc.)
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Clean up request URI - remove double slashes and normalize
		$request_uri = preg_replace( '#/+#', '/', $request_uri ); // Replace multiple slashes with single slash
		$request_uri = rtrim( $request_uri, '/' ) . '/'; // Ensure trailing slash

		return ( is_ssl() ? 'https://' : 'http://' ) . $http_host . $request_uri;
	}

	/**
	 * Get current page URL more reliably (legacy method, keeping for compatibility)
	 */
	private function getCurrentPageUrl() {
		// Get server-based URL first (most reliable for current request)
		$http_host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		
		// Clean up request URI - remove double slashes and normalize
		$request_uri = preg_replace( '#/+#', '/', $request_uri ); // Replace multiple slashes with single slash
		$request_uri = rtrim( $request_uri, '/' ) . '/'; // Ensure trailing slash
		
		$server_url = ( is_ssl() ? 'https://' : 'http://' ) . $http_host . $request_uri;

		// Add debug logging for URL detection process
		$this->addCurrentUrlDetectionDebugScript( $server_url );

		// If we can't get post info from WordPress, analyze the server URL directly
		$current_language = $this->getCurrentLanguage();
		$parsed_url = wp_parse_url( $server_url );
		$path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/';
		
		// Extract slug from the current URL path
		$slug = $this->extractSlugFromPath( $path, $current_language );

		// Try to find the post ID from the slug
		$current_post_id = null;
		if ( $slug && $slug !== 'store' ) { // Avoid the store issue
			$current_post_id = $this->findPostIdBySlug( $slug, $current_language );
		}

		// Try WordPress methods as backup
		if ( ! $current_post_id ) {
			global $post, $wp_query;
			
			// Multiple ways to get the current post
			if ( $post && isset( $post->ID ) ) {
				$current_post_id = $post->ID;
			} elseif ( is_singular() && $wp_query && $wp_query->post ) {
				$current_post_id = $wp_query->post->ID;
			} elseif ( get_queried_object_id() ) {
				$current_post_id = get_queried_object_id();
			}
		}

		// If we have a post ID, construct the proper URL
		if ( $current_post_id ) {
			if ( $current_language === 'en' ) {
				// For English, use the original permalink
				return get_permalink( $current_post_id );
			} else {
				// For other languages, construct URL with translated slug
				$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
				if ( $slug_manager ) {
					$translated_slug = $slug_manager->getSlug( $current_post_id, $current_language );
					if ( $translated_slug ) {
						$site_url = trailingslashit( site_url() );
						return $site_url . $current_language . '/' . $translated_slug . '/';
					}
				}
				
				// Fallback: add language prefix to original URL
				$original_url = get_permalink( $current_post_id );
				return $this->addLanguagePrefix( $original_url, $current_language );
			}
		}

		// Final fallback: return server URL as-is (but cleaned)
		return $server_url;
	}

	/**
	 * Add language prefix to URL (helper method)
	 */
	private function addLanguagePrefix( $url, $language ) {
		if ( $language === 'en' ) {
			return $url;
		}

		$parsed_url = wp_parse_url( $url );
		$path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/';
		
		// Remove existing language prefix if any
		$path = $this->removeLanguagePrefixFromPath( $path );
		
		// Add new language prefix
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
	 * Handle redirects from old English-based URLs to new translated URLs
	 */
	public function handleTranslatedSlugRedirect() {
		// Only run on frontend
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Get current language
		$current_language = $this->getCurrentLanguage();
		
		// Only redirect for non-English languages
		if ( $current_language === 'en' ) {
			return;
		}

		// Check if slug translation is enabled
		if ( ! get_option( 'voxfor_ml_translate_slugs', false ) ) {
			return;
		}

		// Get current URL
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$current_url = home_url( $request_uri );
		
		// Parse URL to extract language and slug
		if ( ! preg_match( '#^/(' . implode( '|', $this->supported_languages ) . ')/(.+?)/?$#', $request_uri, $matches ) ) {
			return;
		}

		$lang = $matches[1];
		$slug = rtrim( $matches[2], '/' );

		// Skip if this is already a translated slug
		$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
		if ( ! $slug_manager ) {
			return;
		}

		// Check if this slug exists as a translated slug
		$post_id_by_translated = $slug_manager->findPostBySlug( $slug, $lang );
		if ( $post_id_by_translated ) {
			// This is already a translated slug, no redirect needed
			return;
		}

		// Try to find the post by English slug
		$post = get_posts( array(
			'name' => $slug,
			'post_type' => array( 'post', 'page', 'product' ),
			'post_status' => 'publish',
			'posts_per_page' => 1,
		) );

		if ( empty( $post ) ) {
			return;
		}

		$post_id = $post[0]->ID;

		// Get the translated slug for this post
		$translated_slug = $slug_manager->getSlug( $post_id, $lang );
		if ( ! $translated_slug || $translated_slug === $slug ) {
			// No translated slug available or it's the same as current
			return;
		}

	// Construct the new URL with translated slug
	$new_url = home_url( "/{$lang}/{$translated_slug}/" );

	// Perform 301 redirect
		wp_redirect( $new_url, 301 );
		exit;
	}

	/**
	 * Add debug for post ID search process
	 */
	private function addPostIdSearchDebugScript( $slug, $current_language, $found_post_id, $search_method ) {
		// Debug functionality disabled for production
		return;
	}

	/**
	 * Add debug for URL generation process
	 */
	private function addUrlGenerationDebugScript( $post_id, $language, $site_url ) {
		// Debug functionality disabled for production
		return;
	}

	/**
	 * Add debug to see what URL is passed to getLanguageUrl
	 */
	private function addGetLanguageUrlsDebugScript( $passed_url ) {
		// Debug functionality disabled for production
		return;
	}

	/**
	 * Add debug for individual URL generation in getLanguageUrl
	 */
	private function addIndividualUrlGenerationDebugScript( $input_url, $path, $current_language, $extracted_slug, $target_language ) {
		// Debug functionality disabled for production
		return;
	}

	/**
	 * Add console logging for current URL detection process
	 */
	private function addCurrentUrlDetectionDebugScript( $server_url ) {
		// Debug functionality disabled for production
		return;
	}

	/**
	 * Add console logging for all language switcher URLs
	 */
	private function addLanguageSwitcherUrlsDebugScript( $detected_current_url, $all_urls ) {
		// Debug functionality disabled for production
		return;
	}

	/**
	 * Add console.log debug script for language switcher
	 */
	private function addLanguageSwitcherDebugScript( $original_url, $target_language, $current_language, $slug, $post_id, $generated_url ) {
		// Debug functionality disabled for production
		return;
	}

	/**
	 * Validate theme compatibility and redirect to home if needed
	 */
	private function validateThemeCompatibility( $url ) {
		global $wp_query;

		// Check if current page returns 404 or is invalid
		if ( is_404() || ( isset( $wp_query ) && $wp_query->is_404() ) ) {
			// Return home URL with current language
			$home_url     = home_url( '/' );
			$current_lang = $this->getCurrentLanguage();

			if ( $current_lang !== 'en' ) {
				$home_url = $this->getLanguageUrl( $home_url, $current_lang );
			}

			return $home_url;
		}

		// Check if this URL belongs to previous theme structure
		if ( $this->isOldThemeUrl( $url ) ) {
			// Return current theme equivalent or home
			return $this->getThemeCompatibleUrl( $url );
		}

		return $url;
	}

	/**
	 * Check if URL belongs to old theme structure
	 */
	private function isOldThemeUrl( $url ) {
		// Store current theme in option
		$current_theme = get_template();
		$stored_theme  = get_option( 'voxfor_ml_current_theme', '' );

		// If theme changed, mark all cached URLs as potentially invalid
		if ( $stored_theme !== $current_theme ) {
			update_option( 'voxfor_ml_current_theme', $current_theme );
			update_option( 'voxfor_ml_theme_switched', time() );

			// Clear theme-specific caches
			$this->clearThemeCache();
		}

		// Check if URL was cached before theme switch
		$theme_switch_time = get_option( 'voxfor_ml_theme_switched', 0 );
		if ( $theme_switch_time > 0 ) {
			// Check if URL leads to 404 or invalid content
			$response = wp_remote_head( $url, array( 'timeout' => 5 ) );
			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get theme-compatible URL
	 */
	private function getThemeCompatibleUrl( $url ) {
		$current_lang = $this->getCurrentLanguage();
		$home_url     = home_url( '/' );

		// Try to find equivalent page in current theme
		$parsed_url = wp_parse_url( $url );
		$path       = isset( $parsed_url['path'] ) ? trim( $parsed_url['path'], '/' ) : '';

		// Remove language prefix from path for analysis
		$clean_path = $this->removeLanguagePrefixFromPath( '/' . $path );
		$clean_path = trim( $clean_path, '/' );

		// Check if page exists in current theme
		if ( ! empty( $clean_path ) ) {
			$page = get_page_by_path( $clean_path );
			if ( $page ) {
				$new_url = get_permalink( $page );
				if ( $current_lang !== 'en' ) {
					$new_url = $this->getLanguageUrl( $new_url, $current_lang );
				}
				return $new_url;
			}
		}

		// Fallback to home URL
		if ( $current_lang !== 'en' ) {
			$home_url = $this->getLanguageUrl( $home_url, $current_lang );
		}

		return $home_url;
	}

	/**
	 * Clear theme-specific caches
	 */
	private function clearThemeCache() {
		// Clear WordPress object cache
		wp_cache_flush();

		// Clear plugin-specific cache
		delete_transient( 'voxfor_ml_theme_urls' );
		delete_transient( 'voxfor_ml_language_urls' );

		// Trigger cache clearing action for other plugins
		do_action( 'voxfor_ml_theme_switched' );
	}

	/**
	 * Resolve translated slugs (taxonomies and authors) to original terms
	 */
	public function resolveTranslatedTaxonomySlugs( $wp ) {
		$current_language = $this->getCurrentLanguage();
		
		// Only process non-English languages
		if ( $current_language === 'en' ) {
			return;
		}

		$slug_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'slug_manager' );
		if ( ! $slug_manager ) {
			return;
		}

		// Check for WooCommerce taxonomy queries
		$taxonomies_to_check = array(
			'product_cat' => 'product_cat',
			'product_tag' => 'product_tag',
		);

		// Add product attributes
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			$attribute_taxonomies = wc_get_attribute_taxonomies();
			foreach ( $attribute_taxonomies as $attribute ) {
				$taxonomy_name = 'pa_' . $attribute->attribute_name;
				$taxonomies_to_check[ $taxonomy_name ] = $taxonomy_name;
			}
		}

		foreach ( $taxonomies_to_check as $query_var => $taxonomy ) {
			if ( isset( $wp->query_vars[ $query_var ] ) ) {
				$translated_slug = $wp->query_vars[ $query_var ];
				
			// Try to find the original term by translated slug
			$term = $slug_manager->findTermByTranslatedSlug( $translated_slug, $current_language, $taxonomy );
			
			if ( $term ) {
				// Replace the translated slug with the original slug
				$wp->query_vars[ $query_var ] = $term->slug;
			}
		}
	}

		// NUCLEAR FIX: Check for WooCommerce product queries
		if ( isset( $wp->query_vars['product'] ) && class_exists( 'WooCommerce' ) ) {
			$translated_product_slug = $wp->query_vars['product'];
			
			// Try to find the original product by translated slug
			$product_post_id = $slug_manager->findPostBySlug( $translated_product_slug, $current_language );
			
			if ( $product_post_id ) {
				$product_post = get_post( $product_post_id );
				if ( $product_post && $product_post->post_type === 'product' ) {
					// Replace the translated slug with the original slug
					$wp->query_vars['product'] = $product_post->post_name;
					$wp->query_vars['post_type'] = 'product';
					
				// Remove conflicting query vars that can force shop/archive logic
				unset( $wp->query_vars['name'] );
				unset( $wp->query_vars['pagename'] );
				unset( $wp->query_vars['post_name_or_page'] );
				unset( $wp->query_vars['page_id'] );
			}
		}
	}

	// Check for author queries
	if ( isset( $wp->query_vars['author_name'] ) ) {
		$translated_author_slug = $wp->query_vars['author_name'];
		
		// Try to find the original user by translated slug
		$user = $slug_manager->findUserByTranslatedSlug( $translated_author_slug, $current_language );
		
		if ( $user ) {
			// Replace the translated slug with the original username
			$wp->query_vars['author_name'] = $user->user_nicename;
		}
	}
	}
}
