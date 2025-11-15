<?php
namespace VoxforML\SEO;

use VoxforML\Core\Plugin;

/**
 * Manages translated slugs for posts
 */
class SlugManager {
	private $wpdb;
	private $table_slugs;
	private $plugin;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb        = $wpdb;
		$this->table_slugs = $wpdb->prefix . 'voxfor_ml_slugs';
		$this->plugin      = Plugin::getInstance();

		$this->initHooks();
	}

	/**
	 * Initialize hooks
	 */
	private function initHooks() {
		$slug_translation_enabled = get_option( 'voxfor_ml_translate_slugs', false );

		// Always add admin UI and REST API (needed for management)
		add_action( 'add_meta_boxes', array( $this, 'addMetaBox' ) );
		add_action( 'save_post', array( $this, 'savePostSlugs' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminStyles' ) );
		add_action( 'rest_api_init', array( $this, 'registerRestRoutes' ) );

		// Only add frontend hooks if slug translation is enabled
		if ( $slug_translation_enabled ) {
			// Frontend URL generation
			add_filter( 'post_link', array( $this, 'filterPostLink' ), 10, 2 );
			add_filter( 'page_link', array( $this, 'filterPageLink' ), 10, 2 );
			add_filter( 'post_type_link', array( $this, 'filterPostTypeLink' ), 10, 2 );

			// URL resolution
			add_filter( 'request', array( $this, 'resolveTranslatedSlug' ), 5 );
		}
	}

	/**
	 * Add meta box for slug management
	 */
	public function addMetaBox() {
		// Only show meta box if slug translation is enabled
		if ( ! get_option( 'voxfor_ml_translate_slugs', false ) ) {
			return;
		}

		$post_types = get_post_types( array( 'public' => true ) );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'voxfor_ml_translated_slugs',
				__( 'Translated URLs', 'voxfor-multilanguage' ),
				array( $this, 'renderMetaBox' ),
				$post_type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Render slug management meta box
	 */
	public function renderMetaBox( $post ) {
		wp_nonce_field( 'voxfor_ml_save_slugs', 'voxfor_ml_slugs_nonce' );

		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();
		$slugs        = $this->getPostSlugs( $post->ID );

		echo '<div class="voxfor-ml-slug-manager">';
		echo '<p>' . esc_html__( 'Customize URLs for each language. Leave empty to use the default slug.', 'voxfor-multilanguage' ) . '</p>';

		foreach ( $languages as $lang ) {
			if ( $lang === $default_lang ) {
				continue;
			}

			$current_slug = isset( $slugs[ $lang ] ) ? $slugs[ $lang ] : '';
			$preview_url  = $this->getPreviewUrl( $post, $lang, $current_slug );

			echo '<div class="voxfor-ml-slug-row">';
			echo '<label>';
			echo '<strong>' . esc_html( $this->getLanguageName( $lang ) ) . ':</strong><br>';
			echo '<input type="text" name="voxfor_ml_slugs[' . esc_attr( $lang ) . ']" ';
			echo 'value="' . esc_attr( $current_slug ) . '" class="regular-text" ';
			echo 'placeholder="' . esc_attr( $post->post_name ) . '" />';
			echo '</label>';
			echo '<p class="description">' . esc_html__( 'Preview:', 'voxfor-multilanguage' ) . ' ';
			echo '<code>' . esc_html( $preview_url ) . '</code></p>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Enqueue admin styles for slug manager
	 */
	public function enqueueAdminStyles( $hook ) {
		// Only enqueue on post edit screens
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
			return;
		}

		wp_enqueue_style(
			'voxfor-ml-slug-manager',
			VOXFOR_ML_PLUGIN_URL . 'public/css/admin/slug-manager.css',
			array(),
			VOXFOR_ML_VERSION
		);
	}

	/**
	 * Save post slugs
	 */
	public function savePostSlugs( $post_id, $post ) {
		// Verify nonce
		if ( ! isset( $_POST['voxfor_ml_slugs_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['voxfor_ml_slugs_nonce'] ) ), 'voxfor_ml_save_slugs' ) ) {
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Skip autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Save slugs
		if ( isset( $_POST['voxfor_ml_slugs'] ) && is_array( $_POST['voxfor_ml_slugs'] ) ) {
			$slugs_data = array_map( 'sanitize_text_field', wp_unslash( $_POST['voxfor_ml_slugs'] ) );
			foreach ( $slugs_data as $lang => $slug ) {
				$slug = sanitize_title( $slug );

				if ( empty( $slug ) ) {
					$this->deleteSlug( $post_id, $lang );
				} else {
					$this->saveSlug( $post_id, $lang, $slug );
				}
			}
		}
	}

	/**
	 * Get slugs for a post
	 */
	public function getPostSlugs( $post_id ) {
		$slugs = $this->wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT language_code, slug FROM `{$this->table_slugs}` WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			),
			ARRAY_A
		);

		$result = array();
		foreach ( $slugs as $row ) {
			$result[ $row['language_code'] ] = $row['slug'];
		}

		return $result;
	}

	/**
	 * Get translated URL for a post in a specific language
	 * This method handles both slug lookup and URL construction
	 */
	public function getTranslatedUrl( $post_id, $language, $base_url = null ) {
		// Get the post
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $base_url;
		}

		// For English, return the original URL without language prefix
		if ( $language === 'en' ) {
			return $base_url ? $this->removeLanguagePrefix( $base_url ) : get_permalink( $post_id );
		}

		// Get translated slug from database
		$translated_slug = $this->getSlug( $post_id, $language );
		
		if ( $translated_slug ) {
			// Construct URL with translated slug
			$site_url = trailingslashit( site_url() );
			return $site_url . $language . '/' . $translated_slug . '/';
		}

		// Fallback: use original slug with language prefix
		if ( $base_url ) {
			return $this->addLanguagePrefix( $base_url, $language );
		}

		// Last resort: construct from post slug
		$site_url = trailingslashit( site_url() );
		return $site_url . $language . '/' . $post->post_name . '/';
	}

	/**
	 * Find post ID by translated slug
	 */
	public function findPostBySlug( $slug, $language ) {
		global $wpdb;

		// Try both URL encoded and decoded versions of the slug
		$slugs_to_try = array(
			$slug,                    // Original slug
			urlencode( $slug ),       // URL encoded version
			urldecode( $slug ),       // URL decoded version
		);

		// Remove duplicates
		$slugs_to_try = array_unique( $slugs_to_try );

	foreach ( $slugs_to_try as $search_slug ) {
		// First try to find by translated slug
		$post_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}voxfor_ml_slugs WHERE slug = %s AND language_code = %s",
					$search_slug,
					$language
				)
			);

			if ( $post_id ) {
				return intval( $post_id );
			}
		}

		// Fallback: try to find by original English slug
		$post = get_posts( array(
			'name' => $slug,
			'post_type' => array( 'post', 'page', 'product' ),
			'post_status' => 'publish',
			'posts_per_page' => 1,
		) );

		if ( ! empty( $post ) ) {
			return $post[0]->ID;
		}

		return null;
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
	 * Remove language prefix from URL
	 */
	private function removeLanguagePrefix( $url ) {
		$parsed_url = wp_parse_url( $url );
		$path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/';
		
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
		$languages = array( 'es', 'he', 'fr', 'de', 'it' ); // Add more as needed
		
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
	 * Get single slug for a post and language
	 */
	public function getSlug( $post_id, $lang ) {
		$slug = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT slug FROM `{$this->table_slugs}` WHERE post_id = %d AND language_code = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$lang // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			)
	);

	return $slug;
	}

	/**
	 * Save a slug
	 */
	public function saveSlug( $post_id, $lang, $slug ) {
		// Check if slug already exists for another post
		$existing = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT post_id FROM `%1s` WHERE language_code = %s AND slug = %s AND post_id != %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$this->table_slugs, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$lang, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$slug, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$post_id // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			)
		);

		if ( $existing ) {
			// Make slug unique
			$slug = $this->makeSlugUnique( $slug, $lang, $post_id );
		}

		$this->wpdb->replace(
			$this->table_slugs,
			array(
				'post_id'       => $post_id,
				'language_code' => $lang,
				'slug'          => $slug,
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		// Clear any caches
		wp_cache_delete( 'voxfor_ml_slug_' . $post_id . '_' . $lang, 'voxfor_ml' );
	}

	/**
	 * Delete a slug
	 */
	public function deleteSlug( $post_id, $lang ) {
		$this->wpdb->delete(
			$this->table_slugs,
			array(
				'post_id'       => $post_id,
				'language_code' => $lang,
			),
			array( '%d', '%s' )
		);

		wp_cache_delete( 'voxfor_ml_slug_' . $post_id . '_' . $lang, 'voxfor_ml' );
	}

	/**
	 * Make slug unique
	 */
	private function makeSlugUnique( $slug, $lang, $post_id ) {
		$original_slug = $slug;
		$suffix        = 2;

		while ( $this->slugExists( $slug, $lang, $post_id ) ) {
			$slug = $original_slug . '-' . $suffix;
			++$suffix;
		}

		return $slug;
	}

	/**
	 * Check if slug exists
	 */
	private function slugExists( $slug, $lang, $exclude_post_id = 0 ) {
		$count = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT COUNT(*) FROM `%1s` WHERE language_code = %s AND slug = %s AND post_id != %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$this->table_slugs, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$lang, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$slug, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$exclude_post_id // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			)
		);

		return $count > 0;
	}

	/**
	 * Filter post link to use translated slug
	 */
	public function filterPostLink( $permalink, $post ) {
		return $this->filterPermalink( $permalink, $post );
	}

	/**
	 * Filter page link to use translated slug
	 */
	public function filterPageLink( $permalink, $post_id ) {
		$post = get_post( $post_id );
		return $this->filterPermalink( $permalink, $post );
	}

	/**
	 * Filter post type link to use translated slug
	 */
	public function filterPostTypeLink( $permalink, $post ) {
		return $this->filterPermalink( $permalink, $post );
	}

	/**
	 * Filter permalink to use translated slug
	 */
	private function filterPermalink( $permalink, $post ) {
		if ( ! $post || ! is_object( $post ) ) {
			return $permalink;
		}

		$router       = $this->plugin->getComponent( 'router' );
		$current_lang = $router->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $permalink;
		}

		// Get translated slug
		$translated_slug = $this->getSlug( $post->ID, $current_lang );

	if ( $translated_slug && $translated_slug !== $post->post_name ) {
			$original_permalink = $permalink;
			
			$permalink = str_replace(
				'/' . $post->post_name . '/',
				'/' . $translated_slug . '/',
				$permalink
			);

		// Handle trailing slash
		$permalink = str_replace(
			'/' . $post->post_name,
			'/' . $translated_slug,
			$permalink
		);
	}

	return $permalink;
	}

	/**
	 * Resolve translated slug to post ID
	 */
	public function resolveTranslatedSlug( $query_vars ) {
		// Skip if in admin
		if ( is_admin() ) {
			return $query_vars;
		}

		$router       = $this->plugin->getComponent( 'router' );
		$current_lang = $router->getCurrentLanguage();
		$default_lang = $this->plugin->getDefaultLanguage();

		if ( $current_lang === $default_lang ) {
			return $query_vars;
		}

		// Handle different query var types
		$slug = null;
		$query_var_key = null;

		// Check for regular posts/pages
		if ( isset( $query_vars['name'] ) && ! isset( $query_vars['post_type'] ) ) {
			$slug = $query_vars['name'];
			$query_var_key = 'name';
		}
		// Check for WooCommerce products
		elseif ( isset( $query_vars['product'] ) ) {
			$slug = $query_vars['product'];
			$query_var_key = 'product';
		}
		// Check for other post types
		elseif ( isset( $query_vars['pagename'] ) ) {
			$slug = $query_vars['pagename'];
			$query_var_key = 'pagename';
		}

		// If no slug found, return original query vars
		if ( ! $slug || ! $query_var_key ) {
			return $query_vars;
		}

		// Try to find post by translated slug (with URL encoding support)
		$post_id = $this->findPostBySlug( $slug, $current_lang );

		if ( $post_id ) {
			// Get the original post
			$post = get_post( $post_id );
			if ( $post ) {
				// Replace with original slug
				$query_vars[ $query_var_key ] = $post->post_name;

		// For WooCommerce products, ensure post_type is set
		if ( $query_var_key === 'product' ) {
			$query_vars['post_type'] = 'product';
			
	}

			// Cache the mapping for performance
				wp_cache_set(
					'voxfor_ml_slug_resolve_' . $current_lang . '_' . $slug,
					$post->post_name,
					'voxfor_ml',
					3600
				);
			}
		}

		return $query_vars;
	}

	/**
	 * Auto-generate translated slug from translated title
	 */
public function autoGenerateSlug( $post_id, $language, $translated_title = null ) {
	// Only if slug translation is enabled
	if ( ! get_option( 'voxfor_ml_translate_slugs', false ) ) {
		return false;
		}

	// Skip if slug already exists
	$existing_slug = $this->getSlug( $post_id, $language );
	if ( $existing_slug ) {
		return false;
	}

	// Get translated title if not provided
	if ( ! $translated_title ) {
		$memory = $this->plugin->getComponent( 'translation_memory' );
		if ( ! $memory ) {
			return false;
		}

		$original_title = get_the_title( $post_id );
		$translated_title = $memory->getTranslation( $original_title, $language, 'title', $post_id );
	}

	// Generate slug from translated title
	if ( $translated_title && $translated_title !== get_the_title( $post_id ) ) {
		$slug = sanitize_title( $translated_title );
		
		if ( ! empty( $slug ) ) {
			$this->saveSlug( $post_id, $language, $slug );
			return $slug;
		}
	}

	return false;
	}

	/**
	 * Manually generate slugs for all posts with translated titles
	 */
	public function generateSlugsFromExistingTranslations() {
		if ( ! get_option( 'voxfor_ml_translate_slugs', false ) ) {
			return array( 'error' => 'Slug translation is disabled' );
		}

		$memory = $this->plugin->getComponent( 'translation_memory' );
		if ( ! $memory ) {
			return array( 'error' => 'Translation memory not available' );
		}

		// Get all posts
		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page', 'product' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		) );

		$languages = array( 'he', 'fr', 'de', 'es', 'it' ); // Add your languages here
		$generated = 0;
		$results = array();

		foreach ( $posts as $post ) {
			$original_title = $post->post_title;
			
			foreach ( $languages as $language ) {
				// Check if slug already exists
				if ( $this->getSlug( $post->ID, $language ) ) {
					continue;
				}

				// Look for translated title
				$translated_title = $memory->getTranslation( $original_title, $language, 'title', $post->ID );
				
				if ( $translated_title && $translated_title !== $original_title ) {
					$slug = $this->autoGenerateSlug( $post->ID, $language, $translated_title );
					if ( $slug ) {
						$generated++;
						$results[] = array(
							'post_id' => $post->ID,
							'title' => $original_title,
							'language' => $language,
							'translated_title' => $translated_title,
							'slug' => $slug
						);
					}
				}
			}
		}

		return array(
			'success' => true,
			'generated' => $generated,
			'results' => $results
		);
	}

	/**
	 * Generate slugs for translated content OR localize all website URLs when setting is enabled
	 */
public function generateSlugsForTranslatedContent() {
	try {
		if ( ! get_option( 'voxfor_ml_translate_slugs', false ) ) {
			return array( 'error' => 'Slug translation is disabled' );
		}

		$memory = $this->plugin->getComponent( 'translation_memory' );
		if ( ! $memory ) {
			return array( 'error' => 'Translation memory not available' );
		}

		$translator = $this->plugin->getComponent( 'translator' );
		if ( ! $translator ) {
				return array( 'error' => 'Translator not available' );
			}

		// Get DeepL translator directly for slug translation
		$deepl_translator = new \VoxforML\Translator\DeepLTranslator();

	} catch ( \Exception $e ) {
		return array( 'error' => 'Initialization failed: ' . $e->getMessage() );
	}

		// Get ALL posts, pages, and products
		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page', 'product' ),
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => -1,
		) );

	// Get ALL WooCommerce taxonomies (categories, tags, brands, etc.)
	$taxonomies = $this->getWooCommerceTaxonomies();

	try {
		$languages = $this->plugin->getEnabledLanguages();
		$default_language = $this->plugin->getDefaultLanguage();
	} catch ( \Exception $e ) {
		// Fallback to default languages if methods fail
		$languages = array( 'en', 'he', 'fr', 'de', 'es', 'it' );
		$default_language = 'en';
	}

		if ( empty( $languages ) ) {
			$languages = array( 'en', 'he', 'fr', 'de', 'es', 'it' );
			$default_language = 'en';
		}
	$generated = 0;
	$results = array();
	$errors = array();
	
	// Get DeepL translator directly for author processing
	try {
		$deepl_translator = new \VoxforML\Translator\DeepLTranslator();
	} catch ( \Exception $e ) {
		$deepl_translator = null;
	}
	
	// Ensure enabled_languages is available for author processing
	$enabled_languages = $languages;

	try {
		foreach ( $posts as $post ) {
				$original_title = $post->post_title;
				$post_results = array(
					'title' => $original_title,
					'post_id' => $post->ID,
					'post_type' => $post->post_type,
					'languages' => array(),
				);

			foreach ( $languages as $language ) {
				// Skip default language
				if ( $language === $default_language ) {
					continue;
				}

				// Skip if slug already exists
				if ( $this->getSlug( $post->ID, $language ) ) {
					continue;
				}

					// Try to get existing translation first
					$translated_title = $memory->getTranslation( $original_title, $language, 'title', $post->ID );
					
					// If no translation exists, create one
					if ( ! $translated_title || $translated_title === $original_title ) {
						try {
							$translated_title = $deepl_translator->translate( $original_title, $language, 'EN', 'title' );
							
							// Save the translation for future use
							if ( $translated_title && $translated_title !== $original_title ) {
								$memory->saveTranslation(
									$original_title,
									$translated_title,
									$language,
									'title',
									$post->ID,
									'deepl'
								);
							}
						} catch ( \Exception $e ) {
							$errors[] = sprintf(
								'Failed to translate title for post %d (%s) to %s: %s',
								$post->ID,
								$original_title,
								$language,
								$e->getMessage()
							);
							continue;
						}
					}

					// Generate slug from translated title
					if ( $translated_title && $translated_title !== $original_title ) {
						$slug = sanitize_title( $translated_title );
						
						// Ensure slug is unique
						$slug = $this->ensureUniqueSlug( $slug, $post->ID, $language );
						
						if ( $slug && $slug !== sanitize_title( $original_title ) ) {
							$success = $this->saveSlug( $post->ID, $language, $slug );
					if ( $success ) {
							$generated++;
							$post_results['languages'][] = $language;
						}
					}
				}
			}

			if ( ! empty( $post_results['languages'] ) ) {
				$results[] = $post_results;
			}
		}
	} catch ( \Exception $e ) {
		$errors[] = 'Processing failed: ' . $e->getMessage();
	}

	// Process WooCommerce taxonomies (categories, tags, brands, etc.)
	try {
		foreach ( $taxonomies as $taxonomy_data ) {

				$original_name = $taxonomy_data['name'];
				$term_results = array(
					'name' => $original_name,
					'term_id' => $taxonomy_data['term_id'],
					'taxonomy' => $taxonomy_data['taxonomy'],
					'taxonomy_label' => $taxonomy_data['taxonomy_label'],
					'languages' => array(),
				);

				foreach ( $languages as $language ) {
					// Skip default language
					if ( $language === $default_language ) {
						continue;
					}

			// Skip if taxonomy slug already exists
			if ( $this->getTaxonomySlug( $taxonomy_data['term_id'], $language ) ) {
				continue;
			}

					// Try to get existing translation first
					$translated_name = $memory->getTranslation( $original_name, $language, 'taxonomy', $taxonomy_data['term_id'] );
					
					// If no translation exists, create one
					if ( ! $translated_name || $translated_name === $original_name ) {
						try {
							$translated_name = $deepl_translator->translate( $original_name, $language, 'EN', 'taxonomy' );
							
							// Save the translation for future use
							if ( $translated_name && $translated_name !== $original_name ) {
								$memory->saveTranslation(
									$original_name,
									$translated_name,
									$language,
									'taxonomy',
									$taxonomy_data['term_id'],
									'deepl'
								);
							}
						} catch ( \Exception $e ) {
							$errors[] = sprintf(
								'Failed to translate taxonomy term %d (%s) to %s: %s',
								$taxonomy_data['term_id'],
								$original_name,
								$language,
								$e->getMessage()
							);
							continue;
						}
					}

					if ( $translated_name && $translated_name !== $original_name ) {
						// Generate slug from translated name
						$translated_slug = sanitize_title( $translated_name );
						
						// Ensure uniqueness for taxonomy slugs
						$translated_slug = $this->ensureUniqueTaxonomySlug( $translated_slug, $taxonomy_data['term_id'], $language, $taxonomy_data['taxonomy'] );
						
						// Save the translated slug
						$this->saveTaxonomySlug( $taxonomy_data['term_id'], $translated_slug, $language, $taxonomy_data['taxonomy'] );
						
						$term_results['languages'][ $language ] = array(
							'translated_name' => $translated_name,
							'slug' => $translated_slug,
						);
						
					$generated++;
				}
			}

			if ( ! empty( $term_results['languages'] ) ) {
				$results[] = $term_results;
			}
		}
	} catch ( \Exception $e ) {
		$errors[] = 'Taxonomy processing failed: ' . $e->getMessage();
	}

	// Process Authors (users with published posts)
	try {
		// Check if the extended table exists before processing authors
		global $wpdb;
			$table_name = $wpdb->prefix . 'voxfor_ml_taxonomy_slugs';
			$columns = $wpdb->get_col( "DESCRIBE {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			
		if ( ! in_array( 'user_id', $columns ) || ! in_array( 'slug_type', $columns ) ) {
			// Skip author processing if table hasn't been updated
		} else {
			$authors = get_users( array(
				'capability' => 'edit_posts', // Modern way to get authors
				'fields'     => array( 'ID', 'user_nicename', 'display_name' ),
			) );

			foreach ( $authors as $author ) {

				foreach ( $enabled_languages as $lang ) {
					if ( $lang === 'en' ) {
						continue; // Skip English
					}

				// Check if author slug already exists
				$existing_slug = $this->getAuthorSlug( $author->ID, $lang );
				if ( $existing_slug ) {
					continue;
				}

				// Translate the display name (more meaningful than username)
				$name_to_translate = ! empty( $author->display_name ) ? $author->display_name : $author->user_nicename;
				
				// Check if DeepL translator is available
				if ( ! $deepl_translator ) {
					continue;
				}
					
					$translated_name = $deepl_translator->translate( $name_to_translate, 'en', $lang );

					if ( $translated_name && $translated_name !== $name_to_translate ) {
						// Convert to slug format
						$translated_slug = sanitize_title( $translated_name );
						
						// Ensure uniqueness
						$unique_slug = $this->ensureUniqueAuthorSlug( $translated_slug, $author->ID, $lang );
						
					// Save the translated slug
					$saved = $this->saveAuthorSlug( $author->ID, $unique_slug, $lang );
					
					if ( $saved ) {
						$generated++;
					} else {
						$errors[] = "Failed to save author slug for {$author->user_nicename} in {$lang}";
					}
				}
			}
		}
		} // Close the else block for table check
	} catch ( \Exception $e ) {
		$errors[] = 'Author processing failed: ' . $e->getMessage();
	}

		// Ensure we always return valid data
		$success = $generated > 0 || empty( $errors );
		$message = $success 
			? sprintf( 'Successfully localized %d URL slugs for complete website', $generated )
			: 'URL localization completed with errors';

		return array(
			'success'   => $success,
			'generated' => intval( $generated ),
			'results'   => is_array( $results ) ? $results : array(),
			'errors'    => is_array( $errors ) ? $errors : array(),
			'message'   => $message,
		);
	}

	/**
	 * Ensure slug is unique by appending numbers if needed
	 */
	private function ensureUniqueSlug( $slug, $post_id, $language ) {
		$original_slug = $slug;
		$counter = 1;

		// Check if slug already exists for another post in this language
		while ( $this->slugExistsForAnotherPost( $slug, $post_id, $language ) ) {
			$slug = $original_slug . '-' . $counter;
			$counter++;
		}

		return $slug;
	}

	/**
	 * Check if slug exists for another post in the same language
	 */
	private function slugExistsForAnotherPost( $slug, $current_post_id, $language ) {
		$existing_post_id = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT post_id FROM `{$this->table_slugs}` WHERE slug = %s AND language_code = %s AND post_id != %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$slug, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$language, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$current_post_id // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			)
		);

		return ! empty( $existing_post_id );
	}

	/**
	 * Register REST routes
	 */
	public function registerRestRoutes() {
		// Generate slugs from existing translations
		register_rest_route(
			'voxfor-ml/v1',
			'/slugs/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generateSlugsFromExistingTranslations' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Generate slugs only for translated content
		register_rest_route(
			'voxfor-ml/v1',
			'/slugs/generate-translated',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generateSlugsForTranslatedContent' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'voxfor-ml/v1',
			'/slugs/(?P<post_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getPostSlugsRest' ),
				'permission_callback' => array( $this, 'checkRestPermission' ),
				'args'                => array(
					'post_id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);

		register_rest_route(
			'voxfor-ml/v1',
			'/slugs/(?P<post_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'updatePostSlugsRest' ),
				'permission_callback' => array( $this, 'checkRestPermission' ),
				'args'                => array(
					'post_id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
					'slugs'   => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_array( $param );
						},
					),
				),
			)
		);
	}

	/**
	 * REST: Get post slugs
	 */
	public function getPostSlugsRest( $request ) {
		$post_id = $request['post_id'];
		return new \WP_REST_Response( $this->getPostSlugs( $post_id ) );
	}

	/**
	 * REST: Update post slugs
	 */
	public function updatePostSlugsRest( $request ) {
		$post_id = $request['post_id'];
		$slugs   = $request['slugs'];

		foreach ( $slugs as $lang => $slug ) {
			if ( empty( $slug ) ) {
				$this->deleteSlug( $post_id, $lang );
			} else {
				$this->saveSlug( $post_id, $lang, sanitize_title( $slug ) );
			}
		}

		return new \WP_REST_Response( array( 'success' => true ) );
	}

	/**
	 * Check REST permission
	 */
	public function checkRestPermission( $request ) {
		$post_id = $request['post_id'];
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Get all WooCommerce taxonomy terms that need slug translation
	 */
	private function getWooCommerceTaxonomies() {
		$taxonomy_terms = array();

		// Define WooCommerce taxonomies to translate
		$wc_taxonomies = array(
			'product_cat'   => 'Product Categories',
			'product_tag'   => 'Product Tags',
		);

		// Get all product attribute taxonomies dynamically
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			$attribute_taxonomies = wc_get_attribute_taxonomies();
			foreach ( $attribute_taxonomies as $attribute ) {
				$taxonomy_name = 'pa_' . $attribute->attribute_name;
				$wc_taxonomies[ $taxonomy_name ] = 'Product ' . ucfirst( $attribute->attribute_label );
			}
		}

		foreach ( $wc_taxonomies as $taxonomy => $label ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0, // Get all terms
			) );

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					$taxonomy_terms[] = array(
						'term_id'     => $term->term_id,
						'name'        => $term->name,
						'slug'        => $term->slug,
						'taxonomy'    => $taxonomy,
						'taxonomy_label' => $label,
					);
				}
			}
		}

		return $taxonomy_terms;
	}

	/**
	 * Get translated taxonomy slug from database
	 */
	public function getTaxonomySlug( $term_id, $language ) {
		global $wpdb;

// Check if table exists first
$table_name = $wpdb->prefix . 'voxfor_ml_taxonomy_slugs';
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return null;
}

$slug = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"SELECT slug FROM {$table_name} WHERE term_id = %d AND language_code = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$term_id,
			$language
		)
	);

		return $slug ? urldecode( $slug ) : null;
	}

/**
 * Save translated taxonomy slug to database
 */
public function saveTaxonomySlug( $term_id, $slug, $language, $taxonomy ) {
	global $wpdb;

// Check if table exists first
$table_name = $wpdb->prefix . 'voxfor_ml_taxonomy_slugs';
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return false;
}

	// URL encode the slug to handle special characters
	$encoded_slug = urlencode( $slug );

	$result = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			array(
				'term_id'       => $term_id,
				'slug'          => $encoded_slug,
				'language_code' => $language,
				'taxonomy'      => $taxonomy,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		return $result !== false;
	}

	/**
	 * Ensure taxonomy slug is unique by appending numbers if needed
	 */
	private function ensureUniqueTaxonomySlug( $slug, $term_id, $language, $taxonomy ) {
		$original_slug = $slug;
		$counter = 1;

		// Check if slug already exists for another term in this language and taxonomy
		while ( $this->taxonomySlugExistsForAnotherTerm( $slug, $term_id, $language, $taxonomy ) ) {
			$slug = $original_slug . '-' . $counter;
			$counter++;
		}

		return $slug;
	}

	/**
	 * Check if taxonomy slug exists for another term in the same language and taxonomy
	 */
	private function taxonomySlugExistsForAnotherTerm( $slug, $current_term_id, $language, $taxonomy ) {
		global $wpdb;

	// Check if table exists first
	$table_name = $wpdb->prefix . 'voxfor_ml_taxonomy_slugs';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
		return false;
	}

	$encoded_slug = urlencode( $slug );

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT term_id FROM %i WHERE slug = %s AND language_code = %s AND taxonomy = %s AND term_id != %d",
			$table_name,
			$encoded_slug,
			$language,
			$taxonomy,
			$current_term_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $exists !== null;
	}

	/**
	 * Get translated author slug from database
	 */
public function getAuthorSlug( $user_id, $language ) {
	global $wpdb;

// Check if table exists first
$table_name = $wpdb->prefix . 'voxfor_ml_taxonomy_slugs';
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return null;
}

$slug = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"SELECT slug FROM {$table_name} WHERE user_id = %d AND language_code = %s AND slug_type = 'author'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$language
			)
		);

		return $slug ? urldecode( $slug ) : null;
	}

	/**
	 * Save translated author slug to database
	 */
public function saveAuthorSlug( $user_id, $slug, $language ) {
	global $wpdb;

// Check if table exists first
$table_name = $wpdb->prefix . 'voxfor_ml_taxonomy_slugs';
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return false;
}

// URL encode the slug to handle special characters
$encoded_slug = urlencode( $slug );

$result = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_name,
		array(
			'user_id'       => $user_id,
			'slug'          => $encoded_slug,
			'language_code' => $language,
			'slug_type'     => 'author',
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		return $result !== false;
	}

	/**
	 * Find user by translated author slug
	 */
public function findUserByTranslatedSlug( $slug, $language ) {
	global $wpdb;

// Check if table exists first
$table_name = $wpdb->prefix . 'voxfor_ml_taxonomy_slugs';
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return null;
}

$encoded_slug = urlencode( $slug );

$user_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"SELECT user_id FROM {$table_name} WHERE slug = %s AND language_code = %s AND slug_type = 'author'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$encoded_slug,
				$language
			)
		);
		
	if ( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( $user ) {
			return $user;
		}
	}

		return null;
	}

	/**
	 * Ensure author slug is unique by appending numbers if needed
	 */
	private function ensureUniqueAuthorSlug( $slug, $user_id, $language ) {
		$original_slug = $slug;
		$counter = 1;

		while ( $this->authorSlugExistsForAnotherUser( $slug, $user_id, $language ) ) {
			$counter++;
			$slug = $original_slug . '-' . $counter;
		}

		return $slug;
	}

	/**
	 * Check if author slug exists for another user in the same language
	 */
private function authorSlugExistsForAnotherUser( $slug, $current_user_id, $language ) {
	global $wpdb;

// Check if table exists first
$table_name = $wpdb->prefix . 'voxfor_ml_taxonomy_slugs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
	return false;
}

$encoded_slug = urlencode( $slug );

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$exists = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT user_id FROM %i WHERE slug = %s AND language_code = %s AND slug_type = 'author' AND user_id != %d",
		$table_name,
		$encoded_slug,
		$language,
		$current_user_id
	)
);
// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $exists !== null;
	}

	/**
	 * Find taxonomy term by translated slug
	 */
	public function findTermByTranslatedSlug( $slug, $language, $taxonomy = null ) {
		global $wpdb;

// Check if table exists first
$table_name = $wpdb->prefix . 'voxfor_ml_taxonomy_slugs';
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return null;
}

$encoded_slug = urlencode( $slug );

// Build query with optional taxonomy filter
$query = "SELECT term_id, taxonomy FROM {$table_name} WHERE slug = %s AND language_code = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$params = array( $encoded_slug, $language );

if ( $taxonomy ) {
	$query .= " AND taxonomy = %s";
	$params[] = $taxonomy;
}

$result = $wpdb->get_row( $wpdb->prepare( $query, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		
		if ( $result ) {
			// Get the actual term object
			$term = get_term( $result->term_id, $result->taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term;
		}
	}

	return null;
	}

	/**
	 * Get preview URL
	 */
	private function getPreviewUrl( $post, $lang, $slug = '' ) {
		$router   = $this->plugin->getComponent( 'router' );
		$base_url = home_url( '/' . $lang . '/' );

		if ( empty( $slug ) ) {
			$slug = $post->post_name;
		}

		// Build URL based on post type
		if ( $post->post_type === 'page' ) {
			// Handle page hierarchy
			$ancestors = get_post_ancestors( $post->ID );
			if ( $ancestors ) {
				$path = array();
				foreach ( array_reverse( $ancestors ) as $ancestor_id ) {
					$ancestor      = get_post( $ancestor_id );
					$ancestor_slug = $this->getSlug( $ancestor_id, $lang );
					$path[]        = $ancestor_slug ?: $ancestor->post_name;
				}
				$path[] = $slug;
				return $base_url . implode( '/', $path ) . '/';
			}
		}

		return $base_url . $slug . '/';
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
			'ja' => '日本語',
			'zh' => '中文',
			'ar' => 'العربية',
		);

		return isset( $languages[ $code ] ) ? $languages[ $code ] : strtoupper( $code );
	}
}
