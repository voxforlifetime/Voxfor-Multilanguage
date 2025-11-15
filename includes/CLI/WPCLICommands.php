<?php
namespace VoxforML\CLI;

use VoxforML\Core\Plugin;
use WP_CLI;

/**
 * WP-CLI Commands for Voxfor Multilanguage
 */
class WPCLICommands {
	private $plugin;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin = Plugin::getInstance();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'voxfor-ml', $this );
		}
	}

	/**
	 * Manage bulk translations
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: start, pause, resume, cancel, status
	 *
	 * [--language=<language>]
	 * : Target language code
	 *
	 * [--post-type=<post_type>]
	 * : Post type to translate (default: post,page)
	 *
	 * [--limit=<limit>]
	 * : Number of posts to process (default: 100)
	 *
	 * ## EXAMPLES
	 *
	 *     wp voxfor-ml bulk start --language=fr --post-type=post --limit=50
	 *     wp voxfor-ml bulk status
	 *     wp voxfor-ml bulk pause
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function bulk( $args, $assoc_args ) {
		$action = $args[0] ?? 'status';

		$bulk_manager = $this->plugin->getComponent( 'bulk_translation_manager' );

		switch ( $action ) {
			case 'start':
				$this->startBulkTranslation( $assoc_args, $bulk_manager );
				break;

			case 'pause':
				$this->pauseBulkTranslation( $bulk_manager );
				break;

			case 'resume':
				$this->resumeBulkTranslation( $bulk_manager );
				break;

			case 'cancel':
				$this->cancelBulkTranslation( $bulk_manager );
				break;

			case 'status':
			default:
				$this->showBulkStatus( $bulk_manager );
				break;
		}
	}

	/**
	 * Export translation memory
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Export format: json, csv, xml (default: json)
	 *
	 * [--language=<language>]
	 * : Filter by language code
	 *
	 * [--output=<file>]
	 * : Output file path
	 *
	 * ## EXAMPLES
	 *
	 *     wp voxfor-ml export-tm --format=csv --language=fr --output=translations.csv
	 *     wp voxfor-ml export-tm --format=json
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function export_tm( $args, $assoc_args ) {
		$format   = $assoc_args['format'] ?? 'json';
		$language = $assoc_args['language'] ?? null;
		$output   = $assoc_args['output'] ?? null;

		$memory = $this->plugin->getComponent( 'translation_memory' );

		WP_CLI::log( 'Exporting translation memory...' );

		$translations = $memory->exportTranslations( $language );

		if ( empty( $translations ) ) {
			WP_CLI::warning( 'No translations found to export.' );
			return;
		}

		$exported_data = $this->formatExportData( $translations, $format );

		if ( $output ) {
			file_put_contents( $output, $exported_data );
			WP_CLI::success( sprintf( 'Translation memory exported to %s (%d translations)', $output, count( $translations ) ) );
		} else {
			WP_CLI::log( $exported_data );
		}
	}

	/**
	 * Show translation statistics
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : Time period: 7d, 30d, 90d (default: 30d)
	 *
	 * [--language=<language>]
	 * : Filter by language code
	 *
	 * ## EXAMPLES
	 *
	 *     wp voxfor-ml stats --period=7d
	 *     wp voxfor-ml stats --language=fr --period=90d
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function stats( $args, $assoc_args ) {
		$period   = $assoc_args['period'] ?? '30d';
		$language = $assoc_args['language'] ?? null;

		$stats_manager = $this->plugin->getComponent( 'statistics_manager' );

		WP_CLI::log( 'Translation Statistics' );
		WP_CLI::log( '====================' );

		// Global stats
		$global_stats = $stats_manager->getGlobalStats();

		WP_CLI::log( sprintf( 'Total Translations: %s', number_format( $global_stats['total_translations'] ) ) );
		WP_CLI::log( sprintf( 'Total Words: %s', number_format( $global_stats['total_words'] ) ) );
		WP_CLI::log( sprintf( 'Total Characters: %s', number_format( $global_stats['total_characters'] ) ) );

		// Cost information
		$cost_data = $stats_manager->getEstimatedCosts();
		WP_CLI::log( sprintf( 'Estimated Cost: $%s', number_format( $cost_data['estimated_cost'], 4 ) ) );
		WP_CLI::log( sprintf( 'Total Tokens: %s', number_format( $cost_data['total_tokens'] ) ) );

		WP_CLI::log( '' );

		// Language breakdown
		if ( $language ) {
			$this->showLanguageStats( $language, $stats_manager );
		} else {
			$this->showAllLanguageStats( $stats_manager );
		}
	}

	/**
	 * Test and validate configuration
	 *
	 * ## EXAMPLES
	 *
	 *     wp voxfor-ml test
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function test( $args, $assoc_args ) {
		WP_CLI::log( 'Testing Voxfor Multilanguage Configuration' );
		WP_CLI::log( '==========================================' );

		// Test API key
		$this->testApiKey();

		// Test database tables
		$this->testDatabaseTables();

		// Test enabled languages
		$this->testEnabledLanguages();

		// Test translation memory
		$this->testTranslationMemory();

		WP_CLI::success( 'Configuration test completed.' );
	}

	/**
	 * Start bulk translation
	 */
	private function startBulkTranslation( $assoc_args, $bulk_manager ) {
		$language  = $assoc_args['language'] ?? null;
		$post_type = $assoc_args['post-type'] ?? 'post,page';
		$limit     = intval( $assoc_args['limit'] ?? 100 );

		if ( ! $language ) {
			WP_CLI::error( 'Language is required. Use --language=<code>' );
		}

		$post_types = array_map( 'trim', explode( ',', $post_type ) );

		WP_CLI::log( sprintf( 'Starting bulk translation to %s...', $language ) );
		WP_CLI::log( sprintf( 'Post types: %s', implode( ', ', $post_types ) ) );
		WP_CLI::log( sprintf( 'Limit: %d posts', $limit ) );

		$job_id = $bulk_manager->startBulkTranslation(
			array(
				'language'   => $language,
				'post_types' => $post_types,
				'limit'      => $limit,
			)
		);

		if ( $job_id ) {
			WP_CLI::success( sprintf( 'Bulk translation job started with ID: %s', $job_id ) );
		} else {
			WP_CLI::error( 'Failed to start bulk translation job.' );
		}
	}

	/**
	 * Show bulk translation status
	 */
	private function showBulkStatus( $bulk_manager ) {
		$jobs = $bulk_manager->getActiveJobs();

		if ( empty( $jobs ) ) {
			WP_CLI::log( 'No active bulk translation jobs.' );
			return;
		}

		WP_CLI::log( 'Active Bulk Translation Jobs' );
		WP_CLI::log( '============================' );

		foreach ( $jobs as $job ) {
			WP_CLI::log( sprintf( 'Job ID: %s', $job->id ) );
			WP_CLI::log( sprintf( 'Language: %s', $job->language ) );
			WP_CLI::log( sprintf( 'Status: %s', $job->status ) );
			WP_CLI::log(
				sprintf(
					'Progress: %d/%d (%.1f%%)',
					$job->processed_items,
					$job->total_items,
					$job->total_items > 0 ? ( $job->processed_items / $job->total_items ) * 100 : 0
				)
			);
			WP_CLI::log( sprintf( 'Created: %s', $job->created_at ) );
			WP_CLI::log( '---' );
		}
	}

	/**
	 * Format export data
	 */
	private function formatExportData( $translations, $format ) {
		switch ( $format ) {
			case 'csv':
				return $this->formatCSV( $translations );
			case 'xml':
				return $this->formatXML( $translations );
			case 'json':
			default:
				return json_encode( $translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		}
	}

	/**
	 * Format CSV export
	 */
	private function formatCSV( $translations ) {
		$csv = "ID,Source Text,Translated Text,Language,Context,Created At\n";

		foreach ( $translations as $translation ) {
			$csv .= sprintf(
				'"%s","%s","%s","%s","%s","%s"' . "\n",
				$translation->id,
				str_replace( '"', '""', $translation->source_text ),
				str_replace( '"', '""', $translation->translated_text ),
				$translation->language_code,
				$translation->context,
				$translation->created_at
			);
		}

		return $csv;
	}

	/**
	 * Test API key
	 */
	private function testApiKey() {
		WP_CLI::log( 'Testing API key...' );

		$encryption_handler = new \VoxforML\Utils\EncryptionHandler();
		$result             = $encryption_handler->testApiKey();

		if ( $result['success'] ) {
			WP_CLI::success( 'API key is valid and working.' );
		} else {
			WP_CLI::warning( 'API key test failed: ' . $result['message'] );
		}
	}

	/**
	 * Test database tables
	 */
	private function testDatabaseTables() {
		WP_CLI::log( 'Testing database tables...' );

		global $wpdb;
		$tables = array(
			'voxfor_ml_translations',
			'voxfor_ml_translation_queue',
			'voxfor_ml_task_log',
			'voxfor_ml_update_log',
			'voxfor_ml_security_log',
			'voxfor_ml_statistics',
			'voxfor_ml_glossary',
			'voxfor_ml_exclusions',
			'voxfor_ml_slugs',
		);

		$missing_tables = array();

		foreach ( $tables as $table ) {
			$full_table_name = $wpdb->prefix . $table;
			$exists          = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $full_table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! $exists ) {
				$missing_tables[] = $table;
			}
		}

		if ( empty( $missing_tables ) ) {
			WP_CLI::success( 'All database tables exist.' );
		} else {
			WP_CLI::warning( 'Missing tables: ' . implode( ', ', $missing_tables ) );
		}
	}

	/**
	 * Test enabled languages
	 */
	private function testEnabledLanguages() {
		WP_CLI::log( 'Testing enabled languages...' );

		$enabled_languages = $this->plugin->getEnabledLanguages();
		$default_language  = $this->plugin->getDefaultLanguage();

		WP_CLI::log( sprintf( 'Default language: %s', $default_language ) );
		WP_CLI::log( sprintf( 'Enabled languages: %s', implode( ', ', $enabled_languages ) ) );

		if ( count( $enabled_languages ) > 1 ) {
			WP_CLI::success( 'Multiple languages configured.' );
		} else {
			WP_CLI::warning( 'Only one language enabled. Add more languages to use translation features.' );
		}
	}

	/**
	 * Test translation memory
	 */
	private function testTranslationMemory() {
		WP_CLI::log( 'Testing translation memory...' );

		$memory = $this->plugin->getComponent( 'translation_memory' );
		$stats  = $memory->getStats();

		WP_CLI::log( sprintf( 'Total translations in memory: %d', $stats['total_translations'] ) );
		WP_CLI::log( sprintf( 'Languages with translations: %d', count( $stats['languages'] ) ) );

		if ( $stats['total_translations'] > 0 ) {
			WP_CLI::success( 'Translation memory is working and contains data.' );
		} else {
			WP_CLI::log( 'Translation memory is empty (this is normal for new installations).' );
		}
	}

	/**
	 * Show all language statistics
	 */
	private function showAllLanguageStats( $stats_manager ) {
		$distribution = $stats_manager->getLanguageDistribution();

		WP_CLI::log( 'Language Distribution:' );
		WP_CLI::log( '---------------------' );

		foreach ( $distribution as $lang_data ) {
			WP_CLI::log(
				sprintf(
					'%s (%s): %d translations (%.1f%%)',
					$lang_data['language'],
					$lang_data['code'],
					$lang_data['count'],
					$lang_data['percentage']
				)
			);
		}
	}
}
