<?php
namespace VoxforML\SEO;

/**
 * Manages per-language SEO settings including indexing control
 */
class PerLanguageSEO {
	private $settings_key = 'voxfor_ml_language_seo_settings';

	/**
	 * Get SEO settings for a specific language
	 */
	public function getLanguageSettings( $language ) {
		$all_settings = get_option( $this->settings_key, array() );
		return isset( $all_settings[ $language ] ) ? $all_settings[ $language ] : $this->getDefaultSettings();
	}

	/**
	 * Update SEO settings for a language
	 */
	public function updateLanguageSettings( $language, $settings ) {
		$all_settings              = get_option( $this->settings_key, array() );
		$all_settings[ $language ] = wp_parse_args( $settings, $this->getDefaultSettings() );
		update_option( $this->settings_key, $all_settings );

		// Update robots.txt
		$this->updateRobotsTxt();

		// Clear cache
		do_action( 'voxfor_ml_clear_cache' );
	}

	/**
	 * Get default SEO settings
	 */
	private function getDefaultSettings() {
		return array(
			'indexable'                   => true,
			'in_sitemap'                  => true,
			'meta_title_pattern'          => '',
			'meta_description_pattern'    => '',
			'og_title_pattern'            => '',
			'og_description_pattern'      => '',
			'twitter_title_pattern'       => '',
			'twitter_description_pattern' => '',
			'translate_image_alt'         => true,
			'translate_image_title'       => true,
			'custom_robots_rules'         => '',
		);
	}

	/**
	 * Check if language should be indexed
	 */
	public function isLanguageIndexable( $language ) {
		$settings = $this->getLanguageSettings( $language );
		return ! empty( $settings['indexable'] );
	}

	/**
	 * Check if language should be in sitemap
	 */
	public function isLanguageInSitemap( $language ) {
		$settings = $this->getLanguageSettings( $language );
		return ! empty( $settings['in_sitemap'] );
	}

	/**
	 * Get robots meta tag for language
	 */
	public function getRobotsMetaTag( $language ) {
		if ( ! $this->isLanguageIndexable( $language ) ) {
			return 'noindex, nofollow';
		}
		return 'index, follow';
	}

	/**
	 * Update robots.txt with language-specific rules
	 */
	public function updateRobotsTxt() {
		// Hook into robots_txt filter
		add_filter( 'robots_txt', array( $this, 'addLanguageRobotRules' ), 10, 2 );
	}

	/**
	 * Add language-specific rules to robots.txt
	 */
	public function addLanguageRobotRules( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}

		$languages    = get_option( 'voxfor_ml_languages', array() );
		$custom_rules = "\n# Voxfor Multilanguage Rules\n";

		foreach ( $languages as $lang ) {
			$settings = $this->getLanguageSettings( $lang );

			// Add noindex rules for non-indexable languages
			if ( ! $settings['indexable'] ) {
				$custom_rules .= "User-agent: *\n";
				$custom_rules .= "Disallow: /{$lang}/\n\n";
			}

			// Add custom rules if any
			if ( ! empty( $settings['custom_robots_rules'] ) ) {
				$custom_rules .= "# Custom rules for {$lang}\n";
				$custom_rules .= $settings['custom_robots_rules'] . "\n\n";
			}
		}

		// Add sitemap references
		$custom_rules .= $this->getSitemapReferences();

		return $output . $custom_rules;
	}

	/**
	 * Get sitemap references for robots.txt
	 */
	private function getSitemapReferences() {
		$sitemaps  = "\n# Language Sitemaps\n";
		$languages = get_option( 'voxfor_ml_languages', array() );

		foreach ( $languages as $lang ) {
			if ( $this->isLanguageInSitemap( $lang ) ) {
				$sitemap_url = home_url( "/{$lang}/sitemap.xml" );
				$sitemaps   .= "Sitemap: {$sitemap_url}\n";
			}
		}

		return $sitemaps;
	}

	/**
	 * Apply SEO patterns to content
	 */
	public function applyPatterns( $content, $language, $type = 'meta_title' ) {
		$settings    = $this->getLanguageSettings( $language );
		$pattern_key = $type . '_pattern';

		if ( empty( $settings[ $pattern_key ] ) ) {
			return $content;
		}

		$pattern = $settings[ $pattern_key ];

		// Replace variables
		$replacements = array(
			'{title}'            => get_the_title(),
			'{site_name}'        => get_bloginfo( 'name' ),
			'{site_description}' => get_bloginfo( 'description' ),
			'{language}'         => $language,
			'{language_name}'    => $this->getLanguageName( $language ),
			'{date}'             => date_i18n( get_option( 'date_format' ) ),
			'{year}'             => gmdate( 'Y' ),
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $pattern );
	}

	/**
	 * Get language name
	 */
	private function getLanguageName( $code ) {
		$languages = array(
			'en' => 'English',
			'he' => 'Hebrew',
			'fr' => 'French',
			'de' => 'German',
			'es' => 'Spanish',
			'it' => 'Italian',
			'pt' => 'Portuguese',
			'ru' => 'Russian',
			'ja' => 'Japanese',
			'zh' => 'Chinese',
			'ko' => 'Korean',
			'ar' => 'Arabic',
			'sv' => 'Swedish',
			'no' => 'Norwegian',
			'da' => 'Danish',
			'fi' => 'Finnish',
			'nl' => 'Dutch',
			'pl' => 'Polish',
			'tr' => 'Turkish',
			'cs' => 'Czech',
			'hu' => 'Hungarian',
			'ro' => 'Romanian',
			'el' => 'Greek',
			'th' => 'Thai',
			'vi' => 'Vietnamese',
			'id' => 'Indonesian',
			'ms' => 'Malay',
			'hi' => 'Hindi',
			'bn' => 'Bengali',
			'uk' => 'Ukrainian',
		);

		return isset( $languages[ $code ] ) ? $languages[ $code ] : $code;
	}
}
