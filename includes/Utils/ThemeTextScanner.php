<?php
namespace VoxforML\Utils;

use VoxforML\Core\Plugin;
use VoxforML\Database\TranslationMemory;
use VoxforML\Translator\DeepLTranslator;

/**
 * Advanced theme text scanner for comprehensive translation
 * Handles WooCommerce, Elementor, and theme-specific texts
 */
class ThemeTextScanner {
	private $plugin;
	private $memory;
	private $translator;
	private $scanned_texts = array();

	public function __construct() {
		$this->plugin     = Plugin::getInstance();
		$this->memory     = new TranslationMemory();
		$this->translator = new DeepLTranslator();
	}

	/**
	 * Scan entire site for translatable texts
	 */
	public function scanSiteTexts( $language = null ) {
		$texts = array();

		// 1. Scan homepage
		$texts = array_merge( $texts, $this->scanHomepage() );

		// 2. Scan WooCommerce texts
		if ( class_exists( 'WooCommerce' ) ) {
			$texts = array_merge( $texts, $this->scanWooCommerceTexts() );
		}

		// 3. Scan Elementor texts
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$texts = array_merge( $texts, $this->scanElementorTexts() );
		}

		// 4. Scan theme texts
		$texts = array_merge( $texts, $this->scanThemeTexts() );

		// 5. Scan widget texts
		$texts = array_merge( $texts, $this->scanWidgetTexts() );

		// 6. Scan menu texts
		$texts = array_merge( $texts, $this->scanMenuTexts() );

		// 7. Scan common WordPress texts
		$texts = array_merge( $texts, $this->getCommonWordPressTexts() );

		// Remove duplicates and clean
		$texts = $this->cleanTexts( $texts );

		// If language specified, translate immediately
		if ( $language && $language !== 'en' ) {
			$this->batchTranslateTexts( $texts, $language );
		}

		return $texts;
	}

	/**
	 * Scan homepage for texts
	 */
	private function scanHomepage() {
		$texts    = array();
		$home_url = home_url( '/' );

		$response = wp_remote_get(
			$home_url,
			array(
				'timeout'    => 30,
				'user-agent' => 'VoxforML Scanner',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $texts;
		}

		$html  = wp_remote_retrieve_body( $response );
		$texts = $this->extractTextsFromHTML( $html );

		return $texts;
	}

	/**
	 * Scan WooCommerce specific texts
	 */
	private function scanWooCommerceTexts() {
		$texts = array();

		// Common WooCommerce texts that appear on every page
		$wc_texts = array(
			// Cart and checkout
			'Add to cart',
			'View cart',
			'Checkout',
			'My account',
			'Cart',
			'Wishlist',
			'Compare',
			'Quick view',

			// Product page
			'Description',
			'Additional information',
			'Reviews',
			'Related products',
			'You may also like',
			'Recently viewed',
			'In stock',
			'Out of stock',
			'Sale!',
			'New!',

			// Shop page
			'Shop',
			'Products',
			'Categories',
			'Brands',
			'Filter by price',
			'Filter by',
			'Sort by',
			'Showing all results',
			'Showing',
			'results',
			'Default sorting',
			'Sort by popularity',
			'Sort by latest',
			'Sort by price: low to high',
			'Sort by price: high to low',

			// Account
			'Login',
			'Register',
			'Lost password',
			'Remember me',
			'Dashboard',
			'Orders',
			'Downloads',
			'Addresses',
			'Account details',
			'Logout',

			// Messages
			'Product added to cart',
			'View Cart',
			'Continue Shopping',
			'No products in the cart',
			'Return to shop',
			'Coupon code',
			'Apply coupon',
			'Update cart',

			// Footer/Header
			'Search products',
			'Search',
			'Menu',
			'Home',
			'About',
			'Contact',
			'Privacy Policy',
			'Terms',

			// Currency and pricing
			'Free shipping',
			'Shipping',
			'Tax',
			'Total',
			'Subtotal',
			'Grand total',
			'Price',
			'Regular price',
			'Sale price',
			'From',
			'Select options',
		);

		// Get actual WooCommerce page texts
		$wc_pages = array( 'shop', 'cart', 'checkout', 'my-account' );

		foreach ( $wc_pages as $page_slug ) {
			$page = get_page_by_path( $page_slug );
			if ( $page ) {
				$page_url = get_permalink( $page->ID );
				$response = wp_remote_get( $page_url );

				if ( ! is_wp_error( $response ) ) {
					$html       = wp_remote_retrieve_body( $response );
					$page_texts = $this->extractTextsFromHTML( $html );
					$texts      = array_merge( $texts, $page_texts );
				}
			}
		}

		return array_merge( $texts, $wc_texts );
	}

	/**
	 * Scan Elementor specific texts
	 */
	private function scanElementorTexts() {
		$texts = array();

		// Get all Elementor pages
		$elementor_pages = get_posts(
			array(
				'post_type'      => 'any',
				'meta_key'       => '_elementor_edit_mode', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'builder', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		foreach ( $elementor_pages as $page ) {
			$elementor_data = get_post_meta( $page->ID, '_elementor_data', true );

			if ( $elementor_data ) {
				$elementor_texts = $this->extractElementorTexts( $elementor_data );
				$texts           = array_merge( $texts, $elementor_texts );
			}
		}

		// Common Elementor widget texts
		$elementor_common = array(
			'Read More',
			'Learn More',
			'Click Here',
			'Get Started',
			'Contact Us',
			'Subscribe',
			'Submit',
			'Send Message',
			'View All',
			'See More',
			'Discover',
			'Explore',
			'Our Services',
			'Our Team',
			'Testimonials',
			'Portfolio',
			'Latest News',
			'Blog',
			'Gallery',
			'FAQ',
		);

		return array_merge( $texts, $elementor_common );
	}

	/**
	 * Extract texts from Elementor data
	 */
	private function extractElementorTexts( $elementor_data ) {
		$texts = array();

		if ( is_string( $elementor_data ) ) {
			$elementor_data = json_decode( $elementor_data, true );
		}

		if ( ! is_array( $elementor_data ) ) {
			return $texts;
		}

		// Recursively extract texts from Elementor structure
		$this->extractElementorTextsRecursive( $elementor_data, $texts );

		return $texts;
	}

	/**
	 * Recursively extract texts from Elementor data
	 */
	private function extractElementorTextsRecursive( $data, &$texts ) {
		if ( ! is_array( $data ) ) {
			return;
		}

		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) && strlen( trim( $value ) ) > 1 ) {
				// Check if it's likely a text field
				$text_fields = array(
					'title',
					'text',
					'content',
					'description',
					'button_text',
					'link_text',
					'heading',
					'subtitle',
					'caption',
					'label',
					'placeholder',
					'before_text',
					'after_text',
					'prefix',
					'suffix',
					'testimonial_content',
					'name',
					'job_title',
				);

				if ( in_array( $key, $text_fields ) ||
					strpos( $key, 'text' ) !== false ||
					strpos( $key, 'title' ) !== false ||
					strpos( $key, 'content' ) !== false ) {

					// Clean HTML tags
					$clean_text = wp_strip_all_tags( $value );
					$clean_text = trim( $clean_text );

					if ( strlen( $clean_text ) > 1 && ! is_numeric( $clean_text ) ) {
						$texts[] = $clean_text;
					}
				}
			} elseif ( is_array( $value ) ) {
				$this->extractElementorTextsRecursive( $value, $texts );
			}
		}
	}

	/**
	 * Scan theme-specific texts
	 */
	private function scanThemeTexts() {
		$texts = array();

		// Get current theme
		$theme      = wp_get_theme();
		$theme_name = $theme->get( 'Name' );

		// Common theme texts based on popular themes
		$theme_texts = array(
			// Header/Navigation
			'Home',
			'About',
			'Services',
			'Portfolio',
			'Blog',
			'Contact',
			'Menu',
			'Search',
			'Search...',
			'Search for:',
			'Search products',

			// Footer
			'Copyright',
			'All rights reserved',
			'Privacy Policy',
			'Terms of Service',
			'Follow us',
			'Social Media',
			'Newsletter',
			'Subscribe',

			// Blog
			'Read more',
			'Continue reading',
			'Posted on',
			'By',
			'In',
			'Tagged',
			'Categories',
			'Archives',
			'Recent Posts',
			'Comments',
			'Leave a comment',
			'Reply',
			'Edit',

			// Sidebar/Widgets
			'Recent Posts',
			'Recent Comments',
			'Archives',
			'Categories',
			'Tag Cloud',
			'Meta',
			'Calendar',
			'Search',

			// Pagination
			'Previous',
			'Next',
			'Page',
			'of',
			'Older posts',
			'Newer posts',

			// 404 and errors
			'Page not found',
			'Sorry',
			'Go back',
			'Search our site',
			'Nothing found',
			'Try again',
			'Error 404',
		);

		// Add theme-specific texts based on theme name
		if ( stripos( $theme_name, 'storefront' ) !== false ||
			stripos( $theme_name, 'shop' ) !== false ||
			stripos( $theme_name, 'woo' ) !== false ) {

			$theme_texts = array_merge(
				$theme_texts,
				array(
					'Shop now',
					'New arrivals',
					'Featured products',
					'Best sellers',
					'On sale',
					'Special offers',
					'Free shipping',
					'Returns',
				)
			);
		}

		return array_merge( $texts, $theme_texts );
	}

	/**
	 * Scan widget texts
	 */
	private function scanWidgetTexts() {
		$texts = array();

		$sidebars = wp_get_sidebars_widgets();

		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( $sidebar_id === 'wp_inactive_widgets' ) {
				continue;
			}

			foreach ( $widgets as $widget_id ) {
				if ( preg_match( '/^(.+)-(\d+)$/', $widget_id, $matches ) ) {
					$widget_type   = $matches[1];
					$widget_number = $matches[2];

					$option_name    = 'widget_' . $widget_type;
					$widget_options = get_option( $option_name );

					if ( isset( $widget_options[ $widget_number ] ) ) {
						$widget_data = $widget_options[ $widget_number ];

						foreach ( array( 'title', 'text', 'content', 'description' ) as $field ) {
							if ( isset( $widget_data[ $field ] ) && ! empty( $widget_data[ $field ] ) ) {
								$text = wp_strip_all_tags( $widget_data[ $field ] );
								$text = trim( $text );
								if ( strlen( $text ) > 1 ) {
									$texts[] = $text;
								}
							}
						}
					}
				}
			}
		}

		return $texts;
	}

	/**
	 * Scan menu texts
	 */
	private function scanMenuTexts() {
		$texts = array();

		$menus = wp_get_nav_menus();

		foreach ( $menus as $menu ) {
			$menu_items = wp_get_nav_menu_items( $menu->term_id );

			if ( $menu_items ) {
				foreach ( $menu_items as $item ) {
					if ( ! empty( $item->title ) ) {
						$texts[] = trim( $item->title );
					}
					if ( ! empty( $item->description ) ) {
						$texts[] = trim( $item->description );
					}
				}
			}
		}

		return $texts;
	}

	/**
	 * Get common WordPress texts
	 */
	private function getCommonWordPressTexts() {
		return array(
			// Common UI elements
			'Submit',
			'Cancel',
			'Save',
			'Delete',
			'Edit',
			'Update',
			'Yes',
			'No',
			'OK',
			'Close',
			'Back',
			'Next',
			'Previous',
			'Loading...',
			'Please wait...',
			'Error',
			'Success',

			// Forms
			'Name',
			'Email',
			'Message',
			'Subject',
			'Phone',
			'Address',
			'Required',
			'Optional',
			'Send',
			'Submit',
			'Reset',

			// Time/Date
			'Today',
			'Yesterday',
			'Tomorrow',
			'Week',
			'Month',
			'Year',
			'January',
			'February',
			'March',
			'April',
			'May',
			'June',
			'July',
			'August',
			'September',
			'October',
			'November',
			'December',
			'Monday',
			'Tuesday',
			'Wednesday',
			'Thursday',
			'Friday',
			'Saturday',
			'Sunday',

			// Common actions
			'Click here',
			'Learn more',
			'Read more',
			'View all',
			'See more',
			'Get started',
			'Sign up',
			'Log in',
			'Register',
			'Download',
			'Share',
			'Print',
			'Email',
			'Call',
			'Visit',
			'Book now',
		);
	}

	/**
	 * Extract texts from HTML content
	 */
	private function extractTextsFromHTML( $html ) {
		$texts = array();

		if ( empty( $html ) ) {
			return $texts;
		}

		// Use DOMDocument for better parsing
		$dom = new \DOMDocument();
		@$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		$xpath = new \DOMXPath( $dom );

		// Extract text nodes
		$textNodes = $xpath->query( '//text()[normalize-space(.) != ""]' );

		foreach ( $textNodes as $textNode ) {
			$parent = $textNode->parentNode;

			// Skip script, style, and other non-content elements
			if ( $parent && in_array( strtolower( $parent->nodeName ), array( 'script', 'style', 'noscript', 'head' ) ) ) {
				continue;
			}

			$text = trim( $textNode->nodeValue );

			if ( strlen( $text ) > 1 && ! is_numeric( $text ) && ! preg_match( '/^[\s\W]*$/', $text ) ) {
				$texts[] = $text;
			}
		}

		// Extract attributes
		$attributes = array( 'alt', 'title', 'placeholder', 'aria-label', 'data-title' );

		foreach ( $attributes as $attr ) {
			$attrNodes = $xpath->query( '//*[@' . $attr . ']/@' . $attr );

			foreach ( $attrNodes as $attrNode ) {
				$text = trim( $attrNode->nodeValue );

				if ( strlen( $text ) > 1 && ! is_numeric( $text ) ) {
					$texts[] = $text;
				}
			}
		}

		return $texts;
	}

	/**
	 * Clean and filter texts
	 */
	private function cleanTexts( $texts ) {
		$cleaned = array();

		foreach ( $texts as $text ) {
			$text = trim( $text );

			// Skip empty, numeric, or very short texts
			if ( empty( $text ) || strlen( $text ) < 2 || is_numeric( $text ) ) {
				continue;
			}

			// Skip texts that are only special characters
			if ( preg_match( '/^[\s\W]*$/', $text ) ) {
				continue;
			}

			// Skip URLs and email addresses
			if ( filter_var( $text, FILTER_VALIDATE_URL ) || filter_var( $text, FILTER_VALIDATE_EMAIL ) ) {
				continue;
			}

			// Skip dates and times
			if ( preg_match( '/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}$/', $text ) ||
				preg_match( '/^\d{1,2}:\d{2}/', $text ) ) {
				continue;
			}

			$cleaned[] = $text;
		}

		// Remove duplicates and return
		return array_unique( $cleaned );
	}

	/**
	 * Batch translate texts
	 */
	private function batchTranslateTexts( $texts, $language ) {
		if ( empty( $texts ) || $language === 'en' ) {
			return;
		}

		$batch_size = 20;
		$batches    = array_chunk( $texts, $batch_size );

		foreach ( $batches as $batch ) {
			try {
				$translations = $this->translator->batchTranslate( $batch, $language, 'EN' );

				if ( is_array( $translations ) ) {
					foreach ( $translations as $index => $translation ) {
						if ( ! empty( $translation ) && $translation !== $batch[ $index ] ) {
							// Save in multiple contexts for better matching
							$contexts = array( 'theme_text', 'page_content', 'general', 'text_fragment' );

							foreach ( $contexts as $context ) {
								$this->memory->saveTranslation(
									$batch[ $index ],
									$translation,
									$language,
									$context,
									null,
									'deepl'
								);
							}
						}
					}
				}
			} catch ( \Exception $e ) {
			}

			// Small delay to avoid rate limiting
			usleep( 100000 ); // 0.1 second
		}
	}
}
