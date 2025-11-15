<?php
namespace VoxforML\API;

use VoxforML\Core\Plugin;
use VoxforML\Database\TranslationMemory;

/**
 * REST API Controller
 */
class RestController {
	private $plugin;
	private $namespace = 'voxfor-ml/v1';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin = Plugin::getInstance();
	}

	/**
	 * Register REST routes
	 */
	public function registerRoutes() {
		// Settings endpoints
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'getSettings' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'updateSettings' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
				),
			)
		);

		// Translation endpoints
		register_rest_route(
			$this->namespace,
			'/translate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'translateText' ),
				'permission_callback' => array( $this, 'checkEditPermission' ),
				'args'                => array(
					'text'     => array(
						'required' => true,
						'type'     => 'string',
					),
					'language' => array(
						'required' => true,
						'type'     => 'string',
					),
					'context'  => array(
						'type'    => 'string',
						'default' => 'general',
					),
				),
			)
		);

		// Translation memory endpoints
		register_rest_route(
			$this->namespace,
			'/translations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getTranslations' ),
				'permission_callback' => array( $this, 'checkEditPermission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/translations/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'updateTranslation' ),
					'permission_callback' => array( $this, 'checkEditPermission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'deleteTranslation' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
				),
			)
		);

		// Glossary endpoints
		register_rest_route(
			$this->namespace,
			'/glossary',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'getGlossaryTerms' ),
					'permission_callback' => array( $this, 'checkEditPermission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'addGlossaryTerm' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/glossary/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'updateGlossaryTerm' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'deleteGlossaryTerm' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
				),
			)
		);

		// Exclusion endpoints
		register_rest_route(
			$this->namespace,
			'/exclusions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'getExclusions' ),
					'permission_callback' => array( $this, 'checkEditPermission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'addExclusion' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/exclusions/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'updateExclusion' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'deleteExclusion' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
				),
			)
		);

		// Statistics endpoint
		register_rest_route(
			$this->namespace,
			'/statistics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getStatistics' ),
				'permission_callback' => array( $this, 'checkEditPermission' ),
			)
		);

		// Language detection endpoint
		register_rest_route(
			$this->namespace,
			'/detect-language',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'detectLanguage' ),
				'permission_callback' => '__return_true',
			)
		);

		// Visual editor endpoints
		register_rest_route(
			$this->namespace,
			'/visual-editor/content',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'getEditableContent' ),
				'permission_callback' => array( $this, 'checkEditPermission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/visual-editor/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'saveEditedTranslation' ),
				'permission_callback' => array( $this, 'checkEditPermission' ),
			)
		);
	}

	/**
	 * Check admin permission
	 */
	public function checkAdminPermission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Check edit permission
	 */
	public function checkEditPermission() {

		return current_user_can( 'edit_posts' );
	}

	/**
	 * Get settings
	 */
	public function getSettings() {
		$settings = array(
			'general'  => array(
				'default_language' => get_option( 'voxfor_ml_default_language', 'en' ),
				'languages'        => get_option( 'voxfor_ml_languages', array( 'en' ) ),
				'auto_redirect'    => get_option( 'voxfor_ml_auto_redirect', false ),
				'first_visit_only' => get_option( 'voxfor_ml_first_visit_only', true ),
			),
			'api'      => array(
				'batch_size' => get_option( 'voxfor_ml_batch_size', 10 ),
				'rate_limit' => get_option( 'voxfor_ml_rate_limit', 50 ),
			),
			'seo'      => array(
				'enable_hreflang'     => get_option( 'voxfor_ml_enable_hreflang', true ),
				'translate_slugs'     => get_option( 'voxfor_ml_translate_slugs', false ),
				'translate_image_alt' => get_option( 'voxfor_ml_translate_image_alt', true ),
			),
			'widget'   => array(
				'widget_style'      => get_option( 'voxfor_ml_widget_style', 'dropdown' ),
				'show_flags'        => get_option( 'voxfor_ml_show_flags', true ),
				'show_native_names' => get_option( 'voxfor_ml_show_native_names', true ),
				'floating_switcher' => get_option( 'voxfor_ml_floating_switcher', false ),
				'floating_position' => get_option( 'voxfor_ml_floating_position', 'bottom-right' ),
			),
			'advanced' => array(
				'cache_ttl'            => get_option( 'voxfor_ml_cache_ttl', 2592000 ),
				'enable_visual_editor' => get_option( 'voxfor_ml_enable_visual_editor', true ),
				'woocommerce_compat'   => get_option( 'voxfor_ml_woocommerce_compat', true ),
				'cache_vary_cookie'    => get_option( 'voxfor_ml_cache_vary_cookie', true ),
			),
		);

		return new \WP_REST_Response( $settings, 200 );
	}

	/**
	 * Update settings
	 */
	public function updateSettings( $request ) {
		$settings = $request->get_json_params();

		foreach ( $settings as $group => $options ) {
			foreach ( $options as $key => $value ) {
				update_option( 'voxfor_ml_' . $key, $value );
			}
		}

		// Clear cache after settings update
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_voxfor_ml_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Translate text
	 */
	public function translateText( $request ) {
		$text     = $request->get_param( 'text' );
		$language = $request->get_param( 'language' );
		$context  = $request->get_param( 'context' );

		$translator = $this->plugin->getComponent( 'translator' );
		$translated = $translator->translateText( $text, $language, $context );

		if ( $translated === false ) {
			return new \WP_Error( 'translation_failed', __( 'Translation failed', 'voxfor-multilanguage' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response(
			array(
				'original'   => $text,
				'translated' => $translated,
				'language'   => $language,
				'context'    => $context,
			),
			200
		);
	}

	/**
	 * Get translations
	 */
	public function getTranslations( $request ) {
		$search   = $request->get_param( 'search' );
		$language = $request->get_param( 'language' );
		$context  = $request->get_param( 'context' );
		$page     = $request->get_param( 'page' ) ?: 1;
		$per_page = $request->get_param( 'per_page' ) ?: 50;

		$memory       = new TranslationMemory();
		$translations = $memory->searchTranslations( $search, $language, $context, $per_page );

		return new \WP_REST_Response( $translations, 200 );
	}

	/**
	 * Update translation
	 */
	public function updateTranslation( $request ) {
		$id              = $request->get_param( 'id' );
		$translated_text = $request->get_param( 'translated_text' );
		$is_locked       = $request->get_param( 'is_locked' );

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translations';

		$data = array( 'translated_text' => $translated_text );
		if ( $is_locked !== null ) {
			$data['is_locked'] = $is_locked ? 1 : 0;
		}

		$result = $wpdb->update( $table_name, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $result === false ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update translation', 'voxfor-multilanguage' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Delete translation
	 */
	public function deleteTranslation( $request ) {
		$id = $request->get_param( 'id' );

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translations';

		$result = $wpdb->delete( $table_name, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $result === false ) {
			return new \WP_Error( 'delete_failed', __( 'Failed to delete translation', 'voxfor-multilanguage' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Get glossary terms
	 */
	public function getGlossaryTerms( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'glossary';

		$language = $request->get_param( 'language' );

		if ( $language ) {
			$terms = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table_name} WHERE language_code = %s ORDER BY language_code, term", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$language
				)
			);
		} else {
			$terms = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT * FROM {$table_name} ORDER BY language_code, term" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		return new \WP_REST_Response( $terms, 200 );
	}

	/**
	 * Add glossary term
	 */
	public function addGlossaryTerm( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'glossary';

		$data = array(
			'term'           => $request->get_param( 'term' ),
			'translation'    => $request->get_param( 'translation' ),
			'language_code'  => $request->get_param( 'language_code' ),
			'case_sensitive' => $request->get_param( 'case_sensitive' ) ? 1 : 0,
			'match_type'     => $request->get_param( 'match_type' ) ?: 'exact',
			'priority'       => $request->get_param( 'priority' ) ?: 0,
		);

		$result = $wpdb->insert( $table_name, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( $result === false ) {
			return new \WP_Error( 'insert_failed', __( 'Failed to add glossary term', 'voxfor-multilanguage' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'id' => $wpdb->insert_id ), 201 );
	}

	/**
	 * Update glossary term
	 */
	public function updateGlossaryTerm( $request ) {
		$id = $request->get_param( 'id' );

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'glossary';

		$data = array();

		if ( $request->has_param( 'term' ) ) {
			$data['term'] = $request->get_param( 'term' );
		}
		if ( $request->has_param( 'translation' ) ) {
			$data['translation'] = $request->get_param( 'translation' );
		}
		if ( $request->has_param( 'case_sensitive' ) ) {
			$data['case_sensitive'] = $request->get_param( 'case_sensitive' ) ? 1 : 0;
		}
		if ( $request->has_param( 'match_type' ) ) {
			$data['match_type'] = $request->get_param( 'match_type' );
		}
		if ( $request->has_param( 'priority' ) ) {
			$data['priority'] = $request->get_param( 'priority' );
		}

		$result = $wpdb->update( $table_name, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $result === false ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update glossary term', 'voxfor-multilanguage' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Delete glossary term
	 */
	public function deleteGlossaryTerm( $request ) {
		$id = $request->get_param( 'id' );

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'glossary';

		$result = $wpdb->delete( $table_name, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $result === false ) {
			return new \WP_Error( 'delete_failed', __( 'Failed to delete glossary term', 'voxfor-multilanguage' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Get exclusions
	 */
	public function getExclusions( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions';

		$type = $request->get_param( 'type' );

		if ( $type ) {
			$exclusions = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table_name} WHERE rule_type = %s ORDER BY rule_type, id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$type
				)
			);
		} else {
			$exclusions = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT * FROM {$table_name} ORDER BY rule_type, id" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		return new \WP_REST_Response( $exclusions, 200 );
	}

	/**
	 * Add exclusion
	 */
	public function addExclusion( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions';

		$data = array(
			'rule_type'   => $request->get_param( 'rule_type' ),
			'rule_value'  => $request->get_param( 'rule_value' ),
			'description' => $request->get_param( 'description' ),
			'is_active'   => $request->get_param( 'is_active' ) !== false ? 1 : 0,
		);

		$result = $wpdb->insert( $table_name, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( $result === false ) {
			return new \WP_Error( 'insert_failed', __( 'Failed to add exclusion rule', 'voxfor-multilanguage' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'id' => $wpdb->insert_id ), 201 );
	}

	/**
	 * Update exclusion
	 */
	public function updateExclusion( $request ) {
		$id = $request->get_param( 'id' );

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions';

		$data = array();

		if ( $request->has_param( 'rule_value' ) ) {
			$data['rule_value'] = $request->get_param( 'rule_value' );
		}
		if ( $request->has_param( 'description' ) ) {
			$data['description'] = $request->get_param( 'description' );
		}
		if ( $request->has_param( 'is_active' ) ) {
			$data['is_active'] = $request->get_param( 'is_active' ) ? 1 : 0;
		}

		$result = $wpdb->update( $table_name, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $result === false ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update exclusion rule', 'voxfor-multilanguage' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Delete exclusion
	 */
	public function deleteExclusion( $request ) {
		$id = $request->get_param( 'id' );

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions';

		$result = $wpdb->delete( $table_name, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $result === false ) {
			return new \WP_Error( 'delete_failed', __( 'Failed to delete exclusion rule', 'voxfor-multilanguage' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Get statistics
	 */
	public function getStatistics() {
		$memory = new TranslationMemory();
		$stats  = $memory->getStatistics();

		// Add language names
		$language_data = array(
			'en' => 'English',
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
			'he' => 'Hebrew',
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

		foreach ( $stats['by_language'] as &$lang_stat ) {
			$lang_stat['language_name'] = isset( $language_data[ $lang_stat['language_code'] ] ) ?
				$language_data[ $lang_stat['language_code'] ] : $lang_stat['language_code'];
		}

		return new \WP_REST_Response( $stats, 200 );
	}

	/**
	 * Detect language
	 */
	public function detectLanguage( $request ) {
		$text = $request->get_param( 'text' );

		// Simple language detection based on character sets
		// In production, you might want to use a proper language detection library

		$language = 'en'; // Default

		// Check for specific character sets
		if ( preg_match( '/[\x{4E00}-\x{9FFF}]/u', $text ) ) {
			$language = 'zh'; // Chinese
		} elseif ( preg_match( '/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $text ) ) {
			$language = 'ja'; // Japanese
		} elseif ( preg_match( '/[\x{AC00}-\x{D7AF}]/u', $text ) ) {
			$language = 'ko'; // Korean
		} elseif ( preg_match( '/[\x{0600}-\x{06FF}]/u', $text ) ) {
			$language = 'ar'; // Arabic
		} elseif ( preg_match( '/[\x{0590}-\x{05FF}]/u', $text ) ) {
			$language = 'he'; // Hebrew
		} elseif ( preg_match( '/[\x{0400}-\x{04FF}]/u', $text ) ) {
			$language = 'ru'; // Russian/Cyrillic
		}

		return new \WP_REST_Response( array( 'language' => $language ), 200 );
	}

	/**
	 * Get editable content for visual editor
	 */
	public function getEditableContent( $request ) {
		$url      = $request->get_param( 'url' );
		$language = $request->get_param( 'language' );

		// Parse URL to get post ID
		$post_id = url_to_postid( $url );

		if ( ! $post_id ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found', 'voxfor-multilanguage' ), array( 'status' => 404 ) );
		}

		$post   = get_post( $post_id );
		$memory = new TranslationMemory();

		// Get all translatable content
		$content = array(
			'title'   => array(
				'original'   => $post->post_title,
				'translated' => $memory->getTranslation( $post->post_title, $language, 'title' ),
				'context'    => 'title',
			),
			'content' => array(
				'original'   => $post->post_content,
				'translated' => $memory->getTranslation( $post->post_content, $language, 'content' ),
				'context'    => 'content',
			),
		);

		if ( $post->post_excerpt ) {
			$content['excerpt'] = array(
				'original'   => $post->post_excerpt,
				'translated' => $memory->getTranslation( $post->post_excerpt, $language, 'excerpt' ),
				'context'    => 'excerpt',
			);
		}

		// Get SEO metadata
		$seo          = $this->plugin->getComponent( 'seo' );
		$seo_metadata = $seo->getSEOMetadata( $post_id );

		foreach ( $seo_metadata as $key => $value ) {
			if ( ! empty( $value ) && is_string( $value ) ) {
				$content[ 'seo_' . $key ] = array(
					'original'   => $value,
					'translated' => $memory->getTranslation( $value, $language, 'seo_' . $key ),
					'context'    => 'seo_' . $key,
				);
			}
		}

		return new \WP_REST_Response( $content, 200 );
	}

	/**
	 * Save edited translation (using logical update approach)
	 */
	public function saveEditedTranslation( $request ) {

		$original   = $request->get_param( 'original' );
		$translated = $request->get_param( 'translated' );
		$language   = $request->get_param( 'language' );
		$context    = $request->get_param( 'context' );
		$post_id    = $request->get_param( 'post_id' );

		// Clean up [Original: text] wrapper from fallback placeholder
		if ( preg_match( '/^\[Original: (.*)\]$/', $original, $matches ) ) {
			$original = $matches[1];
		}

		if ( empty( $original ) || empty( $translated ) || empty( $language ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Missing required fields',
				),
				400
			);
		}

		// SIMPLE APPROACH: Find and update existing translation by post_id + context
		$existing_translation = $this->findExistingTranslation( $original, $language, $context, $post_id );

		if ( $existing_translation ) {
			// UPDATE existing translation (user's logical approach)
			$result = $this->updateExistingTranslation( $existing_translation['id'], $translated );

			if ( $result ) {

				// Clear caches
				wp_cache_flush();

				return new \WP_REST_Response(
					array(
						'success' => true,
						'message' => 'Translation updated successfully',
					),
					200
				);
			} else {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Failed to update translation',
					),
					500
				);
			}
		} else {
			// SAFETY CHECK: Before creating new translation, do one final aggressive search

			// Try searching with LIKE pattern for similar text
			global $wpdb;
			$table_name = $wpdb->prefix . 'voxfor_ml_translations';
		$similar    = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT id, translated_text, provider, context, source_text FROM `%1s` WHERE language_code = %s AND (source_text LIKE %s OR source_text LIKE %s OR source_text LIKE %s) ORDER BY CASE WHEN provider = 'manual' THEN 1 ELSE 2 END, LENGTH(source_text) DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			$table_name,
			$language,
			'%' . $wpdb->esc_like( $original ) . '%',
			'%' . $wpdb->esc_like( substr( $original, 0, 50 ) ) . '%',
			'%' . $wpdb->esc_like( $original ) . '%'
		),
		ARRAY_A
	);

			if ( $similar ) {

				// Update the similar translation instead of creating new one
				$result = $this->updateExistingTranslation( $similar['id'], $translated );

				if ( $result ) {
					wp_cache_flush();
					return new \WP_REST_Response(
						array(
							'success' => true,
							'message' => 'Translation updated successfully',
						),
						200
					);
				}
			}

			// Only create new translation if absolutely no similar translation exists
			$memory = new TranslationMemory();
			$result = $memory->saveTranslation( $original, $translated, $language, $context, $post_id, 'manual' );

			if ( $result ) {

				// Clear caches
				wp_cache_flush();

				return new \WP_REST_Response(
					array(
						'success' => true,
						'message' => 'Translation created successfully',
					),
					200
				);
			} else {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Failed to create translation',
					),
					500
				);
			}
		}

		// Return success response
		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Translation saved successfully',
			),
			200
		);
	}

	/**
	 * Find existing translation for update (logical approach with context matching)
	 */
	private function findExistingTranslation( $source_text, $language, $context, $post_id = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'voxfor_ml_translations';

		// USER'S FOOLPROOF LOGIC: Find existing translation by source_hash (unique identifier)
		// Normalize source text to match what we save (same as TranslationMemory)
		$normalized_source = html_entity_decode( $source_text, ENT_QUOTES, 'UTF-8' );
		$normalized_source = trim( preg_replace( '/\s+/', ' ', $normalized_source ) );

		// Use text-based hash for finding existing translations
		$source_hash = hash( 'sha256', $normalized_source );


		// STEP 1: Try exact source_hash + language + context match (most precise)
	$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT id, translated_text, provider, context, source_hash, source_text FROM `%1s` WHERE source_hash = %s AND language_code = %s AND context = %s ORDER BY CASE WHEN provider = 'manual' THEN 1 ELSE 2 END, updated_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			$table_name,
			$source_hash,
			$language,
			$context
		),
		ARRAY_A
	);

		if ( $existing ) {
			return $existing;
		}

		// STEP 2: Try source_hash + language + post_id (same post, different context)
		if ( $post_id ) {
		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, translated_text, provider, context, source_hash, source_text FROM `%1s` WHERE source_hash = %s AND language_code = %s AND post_id = %d ORDER BY CASE WHEN provider = 'manual' THEN 1 ELSE 2 END, updated_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table_name,
				$source_hash,
				$language,
				$post_id
			),
			ARRAY_A
		);

			if ( $existing ) {
				return $existing;
			}
		}

		// STEP 3: Try source_hash + language (any context, but prioritize manual)
	$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT id, translated_text, provider, context, source_hash, source_text FROM `%1s` WHERE source_hash = %s AND language_code = %s ORDER BY CASE WHEN provider = 'manual' THEN 1 ELSE 2 END, updated_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			$table_name,
			$source_hash,
			$language
		),
		ARRAY_A
	);

		if ( $existing ) {
			return $existing;
		}

		// STEP 4: Try exact source_text + context match (fallback for hash mismatches)
	$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT id, translated_text, provider, context, source_hash, source_text FROM `%1s` WHERE source_text = %s AND language_code = %s AND context = %s ORDER BY CASE WHEN provider = 'manual' THEN 1 ELSE 2 END, updated_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			$table_name,
			$source_text,
			$language,
			$context
		),
		ARRAY_A
	);

		if ( $existing ) {
			return $existing;
		}

		// STEP 5: Try exact source_text match (any context)
	$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT id, translated_text, provider, context, source_hash, source_text FROM `%1s` WHERE source_text = %s AND language_code = %s ORDER BY CASE WHEN provider = 'manual' THEN 1 ELSE 2 END, updated_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			$table_name,
			$source_text,
			$language
		),
		ARRAY_A
	);

		if ( $existing ) {
			return $existing;
		}

		return false;
	}

	/**
	 * Get related contexts that should be considered the same for updates
	 */
	private function getRelatedContexts( $context ) {
		$context_map = array(
			// Product contexts
			'product_short_description' => array( 'text_fragment', 'content', 'general' ),
			'product_description'       => array( 'content', 'post_content', 'text_fragment', 'general' ),
			'product_name'              => array( 'title', 'post_title', 'general' ),

			// Post contexts
			'post_content'              => array( 'content', 'text_fragment', 'general' ),
			'post_title'                => array( 'title', 'general' ),

			// Generic contexts
			'content'                   => array( 'post_content', 'text_fragment', 'product_description', 'general' ),
			'text_fragment'             => array( 'content', 'post_content', 'product_short_description', 'general' ),
			'title'                     => array( 'post_title', 'product_name', 'general' ),
			'general'                   => array( 'content', 'text_fragment', 'title' ),
		);

		return $context_map[ $context ] ?? array( 'general' );
	}

	/**
	 * Update existing translation with new text and mark as manual (logical approach)
	 */
	private function updateExistingTranslation( $translation_id, $new_translation ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'voxfor_ml_translations';

		// Check if translation is locked
		$is_locked = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT is_locked FROM {$table_name} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$translation_id
			)
		);

		if ( $is_locked ) {
			return false;
		}

		// Update ONLY the translated_text field
		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table_name,
			array(
				'translated_text' => $new_translation,
			),
			array( 'id' => $translation_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( $result !== false ) {
			// Clear object cache for this translation
			if ( get_option( 'voxfor_ml_enable_object_cache', true ) ) {
				// Get the source info to clear cache properly
			$translation_info = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT source_hash, language_code, context FROM {$table_name} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$translation_id
					),
					ARRAY_A
				);

				if ( $translation_info ) {
					$cache_key = 'voxfor_ml_trans_' . $translation_info['source_hash'] . '_' .
								$translation_info['language_code'] . '_' . $translation_info['context'];
					wp_cache_delete( $cache_key, 'voxfor_ml_translations' );
				}
			}

			return true;
		}

		return false;
	}
}
