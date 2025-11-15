<?php
namespace VoxforML\Core;

/**
 * Plugin activation handler
 */
class Activator {
	/**
	 * Activate the plugin
	 */
	public static function activate() {
		// Create database tables
		self::createDatabaseTables();

		// Set default options
		self::setDefaultOptions();

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Create database tables for translation memory
	 */
	private static function createDatabaseTables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_prefix    = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX;

		// Translation memory table
		$sql_translations = "CREATE TABLE IF NOT EXISTS {$table_prefix}translations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_hash varchar(64) NOT NULL,
            source_text longtext NOT NULL,
            translated_text longtext NOT NULL,
            language_code varchar(10) NOT NULL,
            context varchar(100) DEFAULT 'general',
            post_id bigint(20) unsigned DEFAULT NULL,
            is_locked tinyint(1) DEFAULT 0,
            needs_review tinyint(1) DEFAULT 0,
                            provider varchar(50) DEFAULT 'deepl',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_translation (source_hash, language_code, context),
            KEY idx_language (language_code),
            KEY idx_post_id (post_id),
            KEY idx_context (context),
            KEY idx_created (created_at),
            KEY idx_needs_review (needs_review)
        ) $charset_collate;";

		// Glossary table
		$sql_glossary = "CREATE TABLE IF NOT EXISTS {$table_prefix}glossary (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term varchar(255) NOT NULL,
            translation varchar(255) NOT NULL,
            language_code varchar(10) NOT NULL,
            case_sensitive tinyint(1) DEFAULT 0,
            match_type enum('exact','partial') DEFAULT 'exact',
            priority int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_term (term, language_code),
            KEY idx_language (language_code),
            KEY idx_priority (priority)
        ) $charset_collate;";

		// Exclusion rules table
		$sql_exclusions = "CREATE TABLE IF NOT EXISTS {$table_prefix}exclusions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_type enum('url','css','regex','namespace') NOT NULL,
            rule_value text NOT NULL,
            description text,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_type (rule_type),
            KEY idx_active (is_active)
        ) $charset_collate;";

		// Translation queue table for batch processing
		$sql_queue = "CREATE TABLE IF NOT EXISTS {$table_prefix}translation_queue (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_hash varchar(64) NOT NULL,
            source_text longtext NOT NULL,
            language_code varchar(10) NOT NULL,
            context varchar(100) DEFAULT 'general',
            post_id bigint(20) unsigned DEFAULT NULL,
            status enum('pending','processing','completed','failed') DEFAULT 'pending',
            attempts int(11) DEFAULT 0,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_queue_item (source_hash, language_code, context),
            KEY idx_status (status),
            KEY idx_language (language_code),
            KEY idx_created (created_at)
        ) $charset_collate;";

		// Task log table for bulk translation tracking
		$sql_task_log = "CREATE TABLE IF NOT EXISTS {$table_prefix}task_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id varchar(50) NOT NULL,
            event varchar(50) NOT NULL,
            data longtext,
            user_id bigint(20) unsigned,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_job_id (job_id),
            KEY idx_event (event),
            KEY idx_timestamp (timestamp)
        ) $charset_collate;";

		// Update log table for content change tracking
		$sql_update_log = "CREATE TABLE IF NOT EXISTS {$table_prefix}update_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            changes longtext NOT NULL,
            user_id bigint(20) unsigned,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_post_id (post_id),
            KEY idx_timestamp (timestamp)
        ) $charset_collate;";

		// Security log table
		$sql_security_log = "CREATE TABLE IF NOT EXISTS {$table_prefix}security_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            ip_address varchar(45),
            user_id bigint(20) unsigned,
            details longtext,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event_type (event_type),
            KEY idx_timestamp (timestamp)
        ) $charset_collate;";

		// Statistics table for analytics
		$sql_statistics = "CREATE TABLE IF NOT EXISTS {$table_prefix}statistics (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            stat_type varchar(50) NOT NULL,
            stat_key varchar(100) NOT NULL,
            stat_value bigint(20) DEFAULT 0,
            date date NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_stat (stat_type, stat_key, date),
            KEY idx_date (date),
            KEY idx_type (stat_type)
        ) $charset_collate;";

		// Translated slugs table
		$sql_slugs = "CREATE TABLE IF NOT EXISTS {$table_prefix}slugs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            language_code varchar(10) NOT NULL,
            slug varchar(200) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_lang (post_id, language_code),
            UNIQUE KEY lang_slug (language_code, slug),
            KEY idx_post_id (post_id),
            KEY idx_language_code (language_code)
        ) $charset_collate;";

		// Translated slugs table (taxonomies and authors)
		$sql_taxonomy_slugs = "CREATE TABLE IF NOT EXISTS {$table_prefix}taxonomy_slugs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id bigint(20) unsigned NULL,
            user_id bigint(20) unsigned NULL,
            taxonomy varchar(50) NULL,
            language_code varchar(10) NOT NULL,
            slug varchar(200) NOT NULL,
            slug_type enum('taxonomy', 'author') DEFAULT 'taxonomy',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY term_lang (term_id, language_code, taxonomy),
            UNIQUE KEY user_lang (user_id, language_code),
            UNIQUE KEY taxonomy_lang_slug (taxonomy, language_code, slug),
            KEY idx_term_id (term_id),
            KEY idx_user_id (user_id),
            KEY idx_taxonomy (taxonomy),
            KEY idx_language_code (language_code),
            KEY idx_slug_type (slug_type)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_translations );
		dbDelta( $sql_glossary );
		dbDelta( $sql_exclusions );
		dbDelta( $sql_queue );
		dbDelta( $sql_task_log );
		dbDelta( $sql_update_log );
		dbDelta( $sql_security_log );
		dbDelta( $sql_statistics );
		dbDelta( $sql_slugs );
		dbDelta( $sql_taxonomy_slugs );

		// Run database migrations
		self::runDatabaseMigrations();

		// Add version option
		update_option( 'voxfor_ml_db_version', VOXFOR_ML_VERSION );
	}

	/**
	 * Run database migrations
	 */
	private static function runDatabaseMigrations() {
		global $wpdb;
		$table_prefix = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX;

		// Migration: Update all 'anthropic' provider entries to 'deepl'
		$translations_table = $table_prefix . 'translations';
		$queue_table        = $table_prefix . 'translation_queue';

		// Check if migrations have already been run
		$migration_version = get_option( 'voxfor_ml_migration_version', '0' );

		// Migration 1.0: Convert anthropic to deepl
		if ( version_compare( $migration_version, '1.0', '<' ) ) {
			// Update translations table
			$updated_translations = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"UPDATE {$translations_table} SET provider = %s WHERE provider = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'deepl',
					'anthropic'
				)
			);

			// Update translation queue table (if it has a provider column)
			$queue_columns = $wpdb->get_col( "DESCRIBE {$queue_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( in_array( 'provider', $queue_columns ) ) {
				$updated_queue = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"UPDATE {$queue_table} SET provider = %s WHERE provider = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						'deepl',
						'anthropic'
					)
				);
			}

			// Log the migration
			if ( $updated_translations > 0 ) {
			}

			// Mark migration as complete
			update_option( 'voxfor_ml_migration_version', '1.1' );
		}

		// Migration 1.1: Create taxonomy slugs table
		if ( version_compare( $migration_version, '1.1', '<' ) ) {
			$charset_collate = $wpdb->get_charset_collate();
			
			$sql_taxonomy_slugs = "CREATE TABLE IF NOT EXISTS {$table_prefix}taxonomy_slugs (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				term_id bigint(20) unsigned NOT NULL,
				taxonomy varchar(50) NOT NULL,
				language_code varchar(10) NOT NULL,
				slug varchar(200) NOT NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY term_lang (term_id, language_code),
				UNIQUE KEY taxonomy_lang_slug (taxonomy, language_code, slug),
				KEY idx_term_id (term_id),
				KEY idx_taxonomy (taxonomy),
				KEY idx_language_code (language_code)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_taxonomy_slugs );
			
			// Mark migration as complete
			update_option( 'voxfor_ml_migration_version', '1.1' );
		}

		// Migration 1.2: Add author support to taxonomy slugs table
		if ( version_compare( $migration_version, '1.2', '<' ) ) {
			$table_name = $table_prefix . 'taxonomy_slugs';
			
			// Check if table exists first
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name ) {
				// Check if columns already exist
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$columns = $wpdb->get_col( $wpdb->prepare( "DESCRIBE %i", $table_name ) );
				
				if ( ! in_array( 'user_id', $columns ) ) {
					// Add new columns for author support (migration during plugin update)
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
					$wpdb->query(
						$wpdb->prepare(
							"ALTER TABLE %i 
							ADD COLUMN user_id bigint(20) unsigned NULL AFTER term_id,
							ADD COLUMN slug_type enum('taxonomy', 'author') DEFAULT 'taxonomy' AFTER slug,
							MODIFY COLUMN term_id bigint(20) unsigned NULL,
							MODIFY COLUMN taxonomy varchar(50) NULL",
							$table_name
						)
					);
					// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
					
					// Add new indexes (migration during plugin update)
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
					$wpdb->query(
						$wpdb->prepare(
							"ALTER TABLE %i 
							ADD UNIQUE KEY user_lang (user_id, language_code),
							ADD KEY idx_user_id (user_id),
							ADD KEY idx_slug_type (slug_type)",
							$table_name
						)
					);
					// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
					
					// Update existing records to be taxonomy type
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE %i SET slug_type = 'taxonomy' WHERE slug_type IS NULL OR slug_type = ''",
							$table_name
						)
					);
					// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				}
			}
			
			// Mark migration as complete
			update_option( 'voxfor_ml_migration_version', '1.2' );
		}
	}

	/**
	 * Set default plugin options
	 */
	private static function setDefaultOptions() {
		// General settings
		add_option( 'voxfor_ml_default_language', 'en' );
		add_option( 'voxfor_ml_display_label', 'English' ); // Custom label for default language
		add_option( 'voxfor_ml_display_flag', '🇺🇸' ); // Custom flag for default language
		add_option( 'voxfor_ml_display_prefix', 'en' ); // Custom SEO prefix for default language
		add_option( 'voxfor_ml_languages', array( 'en', 'fr', 'de', 'es', 'it', 'pt', 'ru', 'ja', 'ko', 'zh' ) );
		add_option( 'voxfor_ml_auto_redirect', false );
		add_option( 'voxfor_ml_first_visit_only', true );

		// API settings
		add_option( 'voxfor_ml_batch_size', 50 ); // Increased from 10 to 50 for faster processing
		add_option( 'voxfor_ml_rate_limit', 50 ); // requests per minute

		// SEO settings
		add_option( 'voxfor_ml_enable_hreflang', true );
		add_option( 'voxfor_ml_translate_slugs', false );
		add_option( 'voxfor_ml_translate_image_alt', true ); // SEO: Enable image ALT translation by default

		// Sitemap settings
		add_option( 'voxfor_ml_include_post_tags_sitemap', false ); // Disabled by default
		add_option( 'voxfor_ml_include_product_tags_sitemap', false ); // Disabled by default

		// Translation memory settings
		add_option( 'voxfor_ml_cache_ttl', 2592000 ); // 30 days in seconds
		add_option( 'voxfor_ml_enable_visual_editor', true );

		// Comprehensive translation settings - DISABLED by default for API safety
		add_option( 'voxfor_ml_comprehensive_translation_enabled', false );

		// Widget settings
		add_option( 'voxfor_ml_widget_style', 'dropdown' );
		add_option( 'voxfor_ml_show_flags', true );
		add_option( 'voxfor_ml_show_native_names', true );
		add_option( 'voxfor_ml_floating_switcher', true ); // Enable floating switcher by default
		add_option( 'voxfor_ml_floating_position', 'bottom-right' ); // Default position

		// Compatibility settings
		add_option( 'voxfor_ml_woocommerce_compat', true );
		add_option( 'voxfor_ml_cache_vary_cookie', true );

		// WooCommerce settings
		add_option( 'voxfor_ml_wc_translate_products', true );
		add_option( 'voxfor_ml_wc_translate_descriptions', true );
		add_option( 'voxfor_ml_wc_translate_categories', true );
		add_option( 'voxfor_ml_wc_translate_attributes', true );
		add_option( 'voxfor_ml_wc_translate_ui', true );

		// Default exclusion rules
		$default_exclusions = array(
			array(
				'type'        => 'css',
				'value'       => '.no-translate',
				'description' => 'Elements with no-translate class',
			),
			array(
				'type'        => 'css',
				'value'       => '.notranslate',
				'description' => 'Google Translate compatible class',
			),
			array(
				'type'        => 'url',
				'value'       => '/checkout/*',
				'description' => 'WooCommerce checkout pages',
			),
			array(
				'type'        => 'url',
				'value'       => '/cart/*',
				'description' => 'WooCommerce cart pages',
			),
			array(
				'type'        => 'namespace',
				'value'       => 'woocommerce_checkout',
				'description' => 'WooCommerce checkout fields',
			),
		);

		global $wpdb;
		$table_name = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'exclusions';

		foreach ( $default_exclusions as $exclusion ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table_name,
				array(
					'rule_type'   => $exclusion['type'],
					'rule_value'  => $exclusion['value'],
					'description' => $exclusion['description'],
					'is_active'   => 1,
				)
			);
		}
	}
}
