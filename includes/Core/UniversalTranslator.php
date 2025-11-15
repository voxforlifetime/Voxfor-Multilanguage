<?php
namespace VoxforML\Core;

use VoxforML\Database\TranslationMemory;
use VoxforML\Translator\DeepLTranslator;
use VoxforML\Utils\TextProcessor;

/**
 * Universal Translator - Translates ALL content regardless of source
 */
class UniversalTranslator {
	private $memory;
	private $translator;
	private $processor;
	private $plugin;

	public function __construct() {
		$this->plugin     = Plugin::getInstance();
		$this->memory     = new TranslationMemory();
		$this->translator = new DeepLTranslator();
		$this->processor  = new TextProcessor();

		$this->initHooks();
	}

	private function initHooks() {
		// NEVER run in admin area
		if ( is_admin() ) {
			return;
		}

		// High priority output buffer to catch ALL content (frontend only)
		add_action( 'template_redirect', array( $this, 'startOutputBuffer' ), 1 );

		// Translate dynamic content
		add_filter( 'dynamic_sidebar_params', array( $this, 'translateWidgetParams' ), 999 );
		add_filter( 'render_block', array( $this, 'translateBlockContent' ), 999, 2 );

		// Elementor specific hooks
		add_filter( 'elementor/widget/render_content', array( $this, 'translateElementorWidget' ), 999, 2 );
		add_filter( 'elementor/frontend/the_content', array( $this, 'translateElementorContent' ), 999 );

		// Archive and template hooks
		add_filter( 'get_the_archive_title', array( $this, 'translateText' ), 999 );
		add_filter( 'get_the_archive_description', array( $this, 'translateText' ), 999 );
		add_filter( 'single_post_title', array( $this, 'translateText' ), 999 );
		add_filter( 'single_cat_title', array( $this, 'translateText' ), 999 );
		add_filter( 'single_tag_title', array( $this, 'translateText' ), 999 );
		add_filter( 'single_term_title', array( $this, 'translateText' ), 999 );

		// WooCommerce specific
		add_filter( 'woocommerce_page_title', array( $this, 'translateText' ), 999 );
		add_filter( 'woocommerce_product_title', array( $this, 'translateText' ), 999 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'translateText' ), 999 );

		// Menu items
		add_filter( 'wp_nav_menu_items', array( $this, 'translateMenuItems' ), 999 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'translateMenuObjects' ), 999 );

		// Widget titles and content
		add_filter( 'widget_title', array( $this, 'translateText' ), 999 );
		add_filter( 'widget_text', array( $this, 'translateText' ), 999 );

		// Options and theme mods
		add_filter( 'option_blogname', array( $this, 'translateText' ), 999 );
		add_filter( 'option_blogdescription', array( $this, 'translateText' ), 999 );
		add_filter( 'theme_mod_custom_logo_text', array( $this, 'translateText' ), 999 );
	}

	/**
	 * Start output buffering to catch ALL content
	 */
	public function startOutputBuffer() {
		$current_language = $this->plugin->getCurrentLanguage();

		// Only buffer if not English
		if ( $current_language && $current_language !== 'en' ) {
			ob_start( array( $this, 'translateFullPage' ) );
		}
	}

	/**
	 * Translate the entire page output
	 */
	public function translateFullPage( $html ) {
		$current_language = $this->plugin->getCurrentLanguage();

		if ( ! $current_language || $current_language === 'en' ) {
			return $html;
		}

		// Check if HTML is empty or invalid
		if ( empty( $html ) || strlen( $html ) < 10 ) {
			return $html;
		}

		// Use DOMDocument for robust parsing
		$dom = new \DOMDocument();
		@$dom->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		// Get all text nodes
		$xpath     = new \DOMXPath( $dom );
		$textNodes = $xpath->query( '//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript)]' );

		foreach ( $textNodes as $node ) {
			$text = trim( $node->nodeValue );

			// Skip empty or numeric only text
			if ( empty( $text ) || is_numeric( $text ) || strlen( $text ) < 1 ) {
				continue;
			}

			// Get translation
			$translation = $this->getTranslation( $text, $current_language );

			if ( $translation && $translation !== $text ) {
				$node->nodeValue = $translation;
			}
		}

		// Translate attributes
		$this->translateAttributes( $dom, $xpath, $current_language );

		// Return the translated HTML with UTF-8 characters preserved
		$html_output = $dom->saveHTML();
		
		// Convert HTML entities back to UTF-8 for proper display
		return $this->convertEntitiesToUTF8( $html_output );
	}

	/**
	 * Translate attributes in DOM
	 */
	private function translateAttributes( $dom, $xpath, $language ) {
		// Translate placeholder attributes
		$placeholders = $xpath->query( '//*[@placeholder]' );
		foreach ( $placeholders as $element ) {
			$text        = $element->getAttribute( 'placeholder' );
			$translation = $this->getTranslation( $text, $language );
			if ( $translation && $translation !== $text ) {
				$element->setAttribute( 'placeholder', $translation );
			}
		}

		// Translate alt attributes
		$alts = $xpath->query( '//img[@alt]' );
		foreach ( $alts as $element ) {
			$text        = $element->getAttribute( 'alt' );
			$translation = $this->getTranslation( $text, $language );
			if ( $translation && $translation !== $text ) {
				$element->setAttribute( 'alt', $translation );
			}
		}

		// Translate title attributes
		$titles = $xpath->query( '//*[@title]' );
		foreach ( $titles as $element ) {
			$text        = $element->getAttribute( 'title' );
			$translation = $this->getTranslation( $text, $language );
			if ( $translation && $translation !== $text ) {
				$element->setAttribute( 'title', $translation );
			}
		}

		// Translate aria-label attributes
		$ariaLabels = $xpath->query( '//*[@aria-label]' );
		foreach ( $ariaLabels as $element ) {
			$text        = $element->getAttribute( 'aria-label' );
			$translation = $this->getTranslation( $text, $language );
			if ( $translation && $translation !== $text ) {
				$element->setAttribute( 'aria-label', $translation );
			}
		}
	}

	/**
	 * Convert HTML entities back to UTF-8 characters for proper display
	 */
	private function convertEntitiesToUTF8( $html ) {
		// Convert numeric HTML entities to UTF-8 characters
		return preg_replace_callback( '/&#(\d+);/', function( $matches ) {
			$codepoint = intval( $matches[1] );
			
			// Use mb_chr if available (PHP 7.2+)
			if ( function_exists( 'mb_chr' ) ) {
				$char = mb_chr( $codepoint, 'UTF-8' );
				if ( $char !== false ) {
					return $char;
				}
			}
			
			// Fallback for older PHP versions
			if ( $codepoint < 128 ) {
				return chr( $codepoint );
			}
			
			// For Unicode characters, use html_entity_decode
			return html_entity_decode( $matches[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}, $html );
	}

	/**
	 * Get translation with fallback to multiple contexts
	 */
	private function getTranslation( $text, $language ) {
		if ( empty( $text ) || $language === 'en' ) {
			return $text;
		}

		// Try multiple contexts
		$contexts = array(
			'text_fragment',
			'content',
			'general',
			'title',
			'menu_item',
			'widget_title',
			'navigation',
			'header',
			'footer',
			'elementor',
			'archive',
			'template',
		);

		foreach ( $contexts as $context ) {
			$translation = $this->memory->getTranslation( $text, $language, $context );
			if ( $translation !== false ) {
				return $translation;
			}
		}

		// Try case-insensitive search
		foreach ( $contexts as $context ) {
			// Try lowercase
			$translation = $this->memory->getTranslation( strtolower( $text ), $language, $context );
			if ( $translation !== false ) {
				return ucfirst( $translation );
			}

			// Try uppercase
			$translation = $this->memory->getTranslation( strtoupper( $text ), $language, $context );
			if ( $translation !== false ) {
				return $translation;
			}
		}

		// Queue for translation if not found
		$this->memory->queueForTranslation( $text, $language, 'text_fragment' );

		return $text;
	}

	/**
	 * Translate text - generic filter callback
	 */
	public function translateText( $text ) {
		if ( empty( $text ) ) {
			return $text;
		}

		$current_language = $this->plugin->getCurrentLanguage();
		if ( ! $current_language || $current_language === 'en' ) {
			return $text;
		}

		return $this->getTranslation( $text, $current_language );
	}

	/**
	 * Translate menu items HTML
	 */
	public function translateMenuItems( $items ) {
		$current_language = $this->plugin->getCurrentLanguage();
		if ( ! $current_language || $current_language === 'en' ) {
			return $items;
		}

		// Use regex to find and translate menu text
		$items = preg_replace_callback(
			'/>([^<]+)</u',
			function ( $matches ) use ( $current_language ) {
				$text = trim( $matches[1] );
				if ( empty( $text ) ) {
					return $matches[0];
				}

				$translation = $this->getTranslation( $text, $current_language );
				return '>' . $translation . '<';
			},
			$items
		);

		return $items;
	}

	/**
	 * Translate menu objects
	 */
	public function translateMenuObjects( $items ) {
		$current_language = $this->plugin->getCurrentLanguage();
		if ( ! $current_language || $current_language === 'en' ) {
			return $items;
		}

		foreach ( $items as &$item ) {
			if ( ! empty( $item->title ) ) {
				$item->title = $this->getTranslation( $item->title, $current_language );
			}
			if ( ! empty( $item->attr_title ) ) {
				$item->attr_title = $this->getTranslation( $item->attr_title, $current_language );
			}
		}

		return $items;
	}

	/**
	 * Translate widget parameters
	 */
	public function translateWidgetParams( $params ) {
		$current_language = $this->plugin->getCurrentLanguage();
		if ( ! $current_language || $current_language === 'en' ) {
			return $params;
		}

		if ( isset( $params[0]['before_title'], $params[0]['after_title'] ) ) {
			$params[0]['before_title'] = $this->translateHtml( $params[0]['before_title'], $current_language );
			$params[0]['after_title']  = $this->translateHtml( $params[0]['after_title'], $current_language );
		}

		return $params;
	}

	/**
	 * Translate HTML content
	 */
	private function translateHtml( $html, $language ) {
		if ( empty( $html ) || $language === 'en' ) {
			return $html;
		}

		// Extract text from HTML
		$html = preg_replace_callback(
			'/>([^<]+)</u',
			function ( $matches ) use ( $language ) {
				$text = trim( $matches[1] );
				if ( empty( $text ) ) {
					return $matches[0];
				}

				$translation = $this->getTranslation( $text, $language );
				return '>' . $translation . '<';
			},
			$html
		);

		return $html;
	}

	/**
	 * Translate block content
	 */
	public function translateBlockContent( $content, $block ) {
		$current_language = $this->plugin->getCurrentLanguage();
		if ( ! $current_language || $current_language === 'en' ) {
			return $content;
		}

		return $this->translateHtml( $content, $current_language );
	}

	/**
	 * Translate Elementor widget content
	 */
	public function translateElementorWidget( $content, $widget ) {
		$current_language = $this->plugin->getCurrentLanguage();
		if ( ! $current_language || $current_language === 'en' ) {
			return $content;
		}

		return $this->translateHtml( $content, $current_language );
	}

	/**
	 * Translate Elementor page content
	 */
	public function translateElementorContent( $content ) {
		$current_language = $this->plugin->getCurrentLanguage();
		if ( ! $current_language || $current_language === 'en' ) {
			return $content;
		}

		return $this->translateHtml( $content, $current_language );
	}

	/**
	 * Queue missing translations for a language
	 */
	public function queueMissingTranslations( $texts, $language ) {
		if ( empty( $texts ) || $language === 'en' ) {
			return;
		}

		foreach ( $texts as $text ) {
			$text = trim( $text );
			if ( empty( $text ) ) {
				continue;
			}

			// Check if translation exists
			$exists   = false;
			$contexts = array( 'text_fragment', 'content', 'general', 'title' );

			foreach ( $contexts as $context ) {
				if ( $this->memory->getTranslation( $text, $language, $context ) !== false ) {
					$exists = true;
					break;
				}
			}

			if ( ! $exists ) {
				$this->memory->queueForTranslation( $text, $language, 'text_fragment' );
			}
		}
	}

	/**
	 * Translate queued items immediately
	 */
	public function translateQueuedItems( $language ) {
		if ( $language === 'en' ) {
			return array(
				'translated' => 0,
				'failed'     => 0,
			);
		}

		$translated = 0;
		$failed     = 0;

		// Get all pending translations for this language
		$pending = $this->memory->getAllPendingTranslations();

		// Define constant to enable immediate translation
		if ( ! defined( 'VOXFOR_ML_BULK_TRANSLATING' ) ) {
			define( 'VOXFOR_ML_BULK_TRANSLATING', true );
		}

		foreach ( $pending as $item ) {
			if ( $item->target_language !== $language ) {
				continue;
			}

			// Translate using DeepL
			$translation = $this->translator->translate(
				$item->original_text,
				$language,
				'EN',
				$item->context
			);

			if ( $translation && $translation !== $item->original_text ) {
				// Save translation
				$this->memory->saveTranslation(
					$item->original_text,
					$translation,
					$language,
					$item->context,
					null,
					'deepl'
				);

				// Remove from queue
				$this->memory->cancelPendingTranslation( $item->id );

				++$translated;
			} else {
				++$failed;
			}
		}

		return array(
			'translated' => $translated,
			'failed'     => $failed,
		);
	}
}
