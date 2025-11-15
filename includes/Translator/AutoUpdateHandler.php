<?php
namespace VoxforML\Translator;

use VoxforML\Core\Plugin;
use VoxforML\Database\TranslationMemory;

/**
 * Handles automatic retranslation when content is updated
 */
class AutoUpdateHandler {
	private $plugin;
	private $memory;
	private $processing_update = false;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin = Plugin::getInstance();
		$this->memory = new TranslationMemory();

		// Hook into post updates
		add_action( 'save_post', array( $this, 'handlePostUpdate' ), 10, 3 );
		add_action( 'edit_post', array( $this, 'handlePostUpdate' ), 10, 2 );

		// Hook into term updates
		add_action( 'edited_term', array( $this, 'handleTermUpdate' ), 10, 3 );

		// Hook into menu updates
		add_action( 'wp_update_nav_menu_item', array( $this, 'handleMenuUpdate' ), 10, 3 );

		// Process retranslation queue
		add_action( 'voxfor_ml_process_retranslation', array( $this, 'processRetranslationQueue' ) );
	}

	/**
	 * Handle post update
	 */
	public function handlePostUpdate( $post_id, $post = null, $update = false ) {
		// Avoid infinite loops
		if ( $this->processing_update ) {
			return;
		}

		// Skip autosaves and revisions
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Skip if not enabled
		if ( ! get_option( 'voxfor_ml_auto_retranslate', true ) ) {
			return;
		}

		// Get post if not provided
		if ( ! $post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post || $post->post_status !== 'publish' ) {
			return;
		}

		// Check if post is excluded from translation
		if ( get_post_meta( $post_id, '_voxfor_ml_exclude', true ) ) {
			return;
		}

		// Get previous version from translation memory
		$this->detectAndQueueChanges( $post );
	}

	/**
	 * Detect changes and queue for retranslation
	 */
	private function detectAndQueueChanges( $post ) {
		$languages    = $this->plugin->getAvailableLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();
		$languages    = array_diff( $languages, array( $default_lang ) );

		if ( empty( $languages ) ) {
			return;
		}

		$changes = array();

		// Check title changes
		$title_hash = $this->memory->hashText( $post->post_title );
		foreach ( $languages as $lang ) {
			$existing = $this->memory->getTranslation( $post->post_title, $lang, 'title' );
			if ( $existing !== false ) {
				// Check if source text has changed
				$stored_source = $this->getStoredSourceText( $post->ID, 'title' );
				if ( $stored_source && $stored_source !== $post->post_title ) {
					$changes['title'][ $lang ] = true;
				}
			}
		}

		// Check content changes
		$content_hash = $this->memory->hashText( $post->post_content );
		foreach ( $languages as $lang ) {
			$existing = $this->memory->getTranslation( $post->post_content, $lang, 'content' );
			if ( $existing !== false ) {
				// Check if source text has changed
				$stored_source = $this->getStoredSourceText( $post->ID, 'content' );
				if ( $stored_source && $stored_source !== $post->post_content ) {
					$changes['content'][ $lang ] = true;
				}
			}
		}

		// Check excerpt changes
		if ( $post->post_excerpt ) {
			foreach ( $languages as $lang ) {
				$existing = $this->memory->getTranslation( $post->post_excerpt, $lang, 'excerpt' );
				if ( $existing !== false ) {
					$stored_source = $this->getStoredSourceText( $post->ID, 'excerpt' );
					if ( $stored_source && $stored_source !== $post->post_excerpt ) {
						$changes['excerpt'][ $lang ] = true;
					}
				}
			}
		}

		// Queue retranslations
		if ( ! empty( $changes ) ) {
			$this->queueRetranslations( $post, $changes );

			// Store new source texts
			$this->storeSourceTexts( $post );

			// Log the update
			$this->logContentUpdate( $post->ID, $changes );
		}
	}

	/**
	 * Queue retranslations for changed content
	 */
	private function queueRetranslations( $post, $changes ) {
		foreach ( $changes as $type => $languages ) {
			foreach ( $languages as $lang => $needed ) {
				if ( ! $needed ) {
					continue;
				}

				$text    = '';
				$context = $type;

				switch ( $type ) {
					case 'title':
						$text = $post->post_title;
						break;
					case 'content':
						$text = $post->post_content;
						break;
					case 'excerpt':
						$text = $post->post_excerpt;
						break;
				}

				if ( $text ) {
					// Check if translation is locked
					if ( ! $this->isTranslationLocked( $text, $lang, $context ) ) {
						$this->memory->queueForTranslation( $text, $lang, $context, $post->ID );
					}
				}
			}
		}

		// Schedule processing
		if ( ! wp_next_scheduled( 'voxfor_ml_process_retranslation' ) ) {
			wp_schedule_single_event( time() + 30, 'voxfor_ml_process_retranslation' );
		}
	}

	/**
	 * Check if translation is locked
	 */
	private function isTranslationLocked( $text, $language, $context ) {
		global $wpdb;
		$table_name  = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translations';
		$source_hash = $this->memory->hashText( $text );

		$is_locked = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT is_locked FROM `%1s` WHERE source_hash = %s AND language_code = %s AND context = %s", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table_name,
				$source_hash,
				$language,
				$context
			)
		);

		return (bool) $is_locked;
	}

	/**
	 * Store source texts for change detection
	 */
	private function storeSourceTexts( $post ) {
		update_post_meta( $post->ID, '_voxfor_ml_source_title', $post->post_title );
		update_post_meta( $post->ID, '_voxfor_ml_source_content', $post->post_content );

		if ( $post->post_excerpt ) {
			update_post_meta( $post->ID, '_voxfor_ml_source_excerpt', $post->post_excerpt );
		}

		update_post_meta( $post->ID, '_voxfor_ml_last_update', current_time( 'mysql' ) );
	}

	/**
	 * Get stored source text
	 */
	private function getStoredSourceText( $post_id, $type ) {
		return get_post_meta( $post_id, '_voxfor_ml_source_' . $type, true );
	}

	/**
	 * Handle term update
	 */
	public function handleTermUpdate( $term_id, $tt_id, $taxonomy ) {
		if ( ! get_option( 'voxfor_ml_auto_retranslate', true ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$languages    = $this->plugin->getAvailableLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();
		$languages    = array_diff( $languages, array( $default_lang ) );

		foreach ( $languages as $lang ) {
			// Queue term name
			$this->memory->queueForTranslation( $term->name, $lang, 'term_name', $term_id );

			// Queue term description if exists
			if ( $term->description ) {
				$this->memory->queueForTranslation( $term->description, $lang, 'term_description', $term_id );
			}
		}
	}

	/**
	 * Handle menu update
	 */
	public function handleMenuUpdate( $menu_id, $menu_item_db_id, $args ) {
		if ( ! get_option( 'voxfor_ml_auto_retranslate', true ) ) {
			return;
		}

		$menu_item = get_post( $menu_item_db_id );
		if ( ! $menu_item ) {
			return;
		}

		$languages    = $this->plugin->getAvailableLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();
		$languages    = array_diff( $languages, array( $default_lang ) );

		foreach ( $languages as $lang ) {
			// Queue menu item title
			$this->memory->queueForTranslation( $menu_item->post_title, $lang, 'menu_item', $menu_item_db_id );

			// Queue menu item description if exists
			$description = get_post_meta( $menu_item_db_id, '_menu_item_description', true );
			if ( $description ) {
				$this->memory->queueForTranslation( $description, $lang, 'menu_description', $menu_item_db_id );
			}
		}
	}

	/**
	 * Process retranslation queue
	 */
	public function processRetranslationQueue() {
		$translator = $this->plugin->getComponent( 'translator' );
		$translator->processTranslationQueue();
	}

	/**
	 * Log content update
	 */
	private function logContentUpdate( $post_id, $changes ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'voxfor_ml_update_log';

		// Create table if not exists
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            changes longtext NOT NULL,
            user_id bigint(20) unsigned,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_post_id (post_id),
            KEY idx_timestamp (timestamp)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Insert log entry
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table_name,
			array(
				'post_id' => $post_id,
				'changes' => json_encode( $changes ),
				'user_id' => get_current_user_id(),
			)
		);
	}

	/**
	 * Get update history for a post
	 */
	public function getUpdateHistory( $post_id, $limit = 10 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'voxfor_ml_update_log';

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM `%1s` WHERE post_id = %d ORDER BY timestamp DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table_name,
				$post_id,
				$limit
			)
		);
	}
}
