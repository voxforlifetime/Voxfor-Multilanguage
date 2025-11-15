<?php
namespace VoxforML\Analytics;

use VoxforML\Core\Plugin;

/**
 * Manages translation statistics and analytics
 */
class StatisticsManager {
	private $plugin;
	private $stats_option_key = 'voxfor_ml_statistics';
	private $daily_stats_key  = 'voxfor_ml_daily_stats';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin = Plugin::getInstance();

		// Track translation events
		add_action( 'voxfor_ml_translation_saved', array( $this, 'trackTranslation' ), 10, 4 );
		add_action( 'voxfor_ml_translation_failed', array( $this, 'trackFailure' ), 10, 3 );

		// Daily cleanup
		add_action( 'voxfor_ml_daily_stats_cleanup', array( $this, 'cleanupOldStats' ) );

		if ( ! wp_next_scheduled( 'voxfor_ml_daily_stats_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'voxfor_ml_daily_stats_cleanup' );
		}
	}

	/**
	 * Track a successful translation
	 */
	public function trackTranslation( $source_text, $translated_text, $language, $context ) {
		$word_count = str_word_count( $source_text );
		$char_count = mb_strlen( $source_text );

		// Update global stats
		$stats = $this->getGlobalStats();
		++$stats['total_translations'];
		$stats['total_words']      += $word_count;
		$stats['total_characters'] += $char_count;

		// Language stats
		if ( ! isset( $stats['by_language'][ $language ] ) ) {
			$stats['by_language'][ $language ] = array(
				'count'      => 0,
				'words'      => 0,
				'characters' => 0,
			);
		}
		++$stats['by_language'][ $language ]['count'];
		$stats['by_language'][ $language ]['words']      += $word_count;
		$stats['by_language'][ $language ]['characters'] += $char_count;

		// Context stats
		if ( ! isset( $stats['by_context'][ $context ] ) ) {
			$stats['by_context'][ $context ] = array(
				'count' => 0,
				'words' => 0,
			);
		}
		++$stats['by_context'][ $context ]['count'];
		$stats['by_context'][ $context ]['words'] += $word_count;

		// Provider stats
		$provider = 'deepl'; // Using DeepL as the primary translator
		if ( ! isset( $stats['by_provider'][ $provider ] ) ) {
			$stats['by_provider'][ $provider ] = array(
				'count' => 0,
				'words' => 0,
			);
		}
		++$stats['by_provider'][ $provider ]['count'];
		$stats['by_provider'][ $provider ]['words'] += $word_count;

		$this->saveGlobalStats( $stats );

		// Update daily stats
		$this->updateDailyStats( $word_count, $language );

		// Estimate token usage
		$this->trackTokenUsage( $source_text, $translated_text );
	}

	/**
	 * Track a failed translation
	 */
	public function trackFailure( $source_text, $language, $error ) {
		$stats = $this->getGlobalStats();
		++$stats['total_failures'];

		if ( ! isset( $stats['failures_by_language'][ $language ] ) ) {
			$stats['failures_by_language'][ $language ] = 0;
		}
		++$stats['failures_by_language'][ $language ];

		// Track error types
		$error_type = $this->categorizeError( $error );
		if ( ! isset( $stats['failures_by_type'][ $error_type ] ) ) {
			$stats['failures_by_type'][ $error_type ] = 0;
		}
		++$stats['failures_by_type'][ $error_type ];

		$this->saveGlobalStats( $stats );
	}

	/**
	 * Get global statistics
	 */
	public function getGlobalStats() {
		$defaults = array(
			'total_translations'   => 0,
			'total_words'          => 0,
			'total_characters'     => 0,
			'total_failures'       => 0,
			'estimated_tokens'     => 0,
			'by_language'          => array(),
			'by_context'           => array(),
			'by_provider'          => array(),
			'failures_by_language' => array(),
			'failures_by_type'     => array(),
			'first_translation'    => null,
			'last_translation'     => null,
		);

		return get_option( $this->stats_option_key, $defaults );
	}

	/**
	 * Save global statistics
	 */
	private function saveGlobalStats( $stats ) {
		$stats['last_translation'] = current_time( 'mysql' );
		if ( ! $stats['first_translation'] ) {
			$stats['first_translation'] = current_time( 'mysql' );
		}
		update_option( $this->stats_option_key, $stats );
	}

	/**
	 * Update daily statistics
	 */
	private function updateDailyStats( $word_count, $language ) {
		$today       = gmdate( 'Y-m-d' );
		$daily_stats = get_option( $this->daily_stats_key, array() );

		if ( ! isset( $daily_stats[ $today ] ) ) {
			$daily_stats[ $today ] = array(
				'translations' => 0,
				'words'        => 0,
				'languages'    => array(),
			);
		}

		++$daily_stats[ $today ]['translations'];
		$daily_stats[ $today ]['words'] += $word_count;

		if ( ! isset( $daily_stats[ $today ]['languages'][ $language ] ) ) {
			$daily_stats[ $today ]['languages'][ $language ] = 0;
		}
		++$daily_stats[ $today ]['languages'][ $language ];

		update_option( $this->daily_stats_key, $daily_stats );
	}

	/**
	 * Get daily statistics for a date range
	 */
	public function getDailyStats( $start_date = null, $end_date = null ) {
		if ( ! $start_date ) {
			$start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		}
		if ( ! $end_date ) {
			$end_date = gmdate( 'Y-m-d' );
		}

		$all_stats      = get_option( $this->daily_stats_key, array() );
		$filtered_stats = array();

		foreach ( $all_stats as $date => $stats ) {
			if ( $date >= $start_date && $date <= $end_date ) {
				$filtered_stats[ $date ] = $stats;
			}
		}

		return $filtered_stats;
	}

	/**
	 * Get chart data for admin dashboard
	 */
	public function getChartData( $period = 'last_30_days' ) {
		$data = array(
			'labels'   => array(),
			'datasets' => array(),
		);

		switch ( $period ) {
			case 'last_7_days':
				$start_date = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
				break;
			case 'last_30_days':
				$start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
				break;
			case 'last_90_days':
				$start_date = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
				break;
			default:
				$start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		}

		$daily_stats = $this->getDailyStats( $start_date, gmdate( 'Y-m-d' ) );

		// Fill in missing dates
		$current = strtotime( $start_date );
		$end     = strtotime( gmdate( 'Y-m-d' ) );

		while ( $current <= $end ) {
			$date             = gmdate( 'Y-m-d', $current );
			$data['labels'][] = gmdate( 'M j', $current );

			if ( isset( $daily_stats[ $date ] ) ) {
				$translations[] = $daily_stats[ $date ]['translations'];
				$words[]        = $daily_stats[ $date ]['words'];
			} else {
				$translations[] = 0;
				$words[]        = 0;
			}

			$current = strtotime( '+1 day', $current );
		}

		$data['datasets'] = array(
			array(
				'label'           => __( 'Translations', 'voxfor-multilanguage' ),
				'data'            => $translations,
				'borderColor'     => '#0073aa',
				'backgroundColor' => 'rgba(0, 115, 170, 0.1)',
			),
			array(
				'label'           => __( 'Words', 'voxfor-multilanguage' ),
				'data'            => $words,
				'borderColor'     => '#46b450',
				'backgroundColor' => 'rgba(70, 180, 80, 0.1)',
				'yAxisID'         => 'y-words',
			),
		);

		return $data;
	}

	/**
	 * Get language distribution data
	 */
	public function getLanguageDistribution() {
		$stats        = $this->getGlobalStats();
		$distribution = array();

		foreach ( $stats['by_language'] as $lang => $data ) {
			$distribution[] = array(
				'language'   => $this->getLanguageName( $lang ),
				'code'       => $lang,
				'count'      => $data['count'],
				'words'      => $data['words'],
				'percentage' => $stats['total_translations'] > 0 ?
					round( ( $data['count'] / $stats['total_translations'] ) * 100, 2 ) : 0,
			);
		}

		// Sort by count descending
		usort(
			$distribution,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		return $distribution;
	}

	/**
	 * Get content type distribution
	 */
	public function getContentTypeDistribution() {
		$stats        = $this->getGlobalStats();
		$distribution = array();

		$context_labels = array(
			'title'            => __( 'Titles', 'voxfor-multilanguage' ),
			'content'          => __( 'Content', 'voxfor-multilanguage' ),
			'excerpt'          => __( 'Excerpts', 'voxfor-multilanguage' ),
			'menu_item'        => __( 'Menu Items', 'voxfor-multilanguage' ),
			'widget_title'     => __( 'Widget Titles', 'voxfor-multilanguage' ),
			'widget_text'      => __( 'Widget Text', 'voxfor-multilanguage' ),
			'image_alt'        => __( 'Image ALT Text', 'voxfor-multilanguage' ),
			'image_title'      => __( 'Image Titles', 'voxfor-multilanguage' ),
			'term_name'        => __( 'Term Names', 'voxfor-multilanguage' ),
			'term_description' => __( 'Term Descriptions', 'voxfor-multilanguage' ),
		);

		foreach ( $stats['by_context'] as $context => $data ) {
			$distribution[] = array(
				'context'    => $context,
				'label'      => isset( $context_labels[ $context ] ) ? $context_labels[ $context ] : ucfirst( $context ),
				'count'      => $data['count'],
				'words'      => $data['words'],
				'percentage' => $stats['total_translations'] > 0 ?
					round( ( $data['count'] / $stats['total_translations'] ) * 100, 2 ) : 0,
			);
		}

		return $distribution;
	}

	/**
	 * Track token usage (estimate)
	 */
	private function trackTokenUsage( $source_text, $translated_text ) {
		// Rough estimation: 1 token ≈ 4 characters
		$source_tokens     = ceil( mb_strlen( $source_text ) / 4 );
		$translated_tokens = ceil( mb_strlen( $translated_text ) / 4 );
		$total_tokens      = $source_tokens + $translated_tokens;

		$stats                      = $this->getGlobalStats();
		$stats['estimated_tokens'] += $total_tokens;
		$this->saveGlobalStats( $stats );

		// Track monthly token usage
		$this->trackMonthlyTokens( $total_tokens );
	}

	/**
	 * Track monthly token usage
	 */
	private function trackMonthlyTokens( $tokens ) {
		$month_key      = gmdate( 'Y-m' );
		$monthly_tokens = get_option( 'voxfor_ml_monthly_tokens', array() );

		if ( ! isset( $monthly_tokens[ $month_key ] ) ) {
			$monthly_tokens[ $month_key ] = 0;
		}

		$monthly_tokens[ $month_key ] += $tokens;

		// Keep only last 12 months
		if ( count( $monthly_tokens ) > 12 ) {
			$monthly_tokens = array_slice( $monthly_tokens, -12, 12, true );
		}

		update_option( 'voxfor_ml_monthly_tokens', $monthly_tokens );
	}

	/**
	 * Get estimated costs with enhanced pricing
	 */
	public function getEstimatedCosts() {
		// Get real-time usage from DeepL API
		$deepl_translator = new \VoxforML\Translator\DeepLTranslator();
		$api_usage        = $deepl_translator->getUsage();

		// Fallback to database if API call fails
		if ( ! $api_usage ) {
			global $wpdb;
			$table = $wpdb->prefix . 'voxfor_ml_translations';

		$total_chars = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT SUM(CHAR_LENGTH(source_text) + CHAR_LENGTH(translated_text)) FROM `%1s` WHERE provider = %s", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table,
				'deepl'
			)
		);

		$month_chars = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT SUM(CHAR_LENGTH(source_text) + CHAR_LENGTH(translated_text)) FROM `%1s` WHERE provider = %s AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table,
				'deepl'
			)
		);

			$api_usage   = array(
				'character_count' => $total_chars ?: 0,
				'character_limit' => 500000, // Default free limit
			);
			$month_chars = $month_chars ?: 0;
		} else {
			// Use real API data
			$total_chars = $api_usage['character_count'] ?? 0;
			$month_chars = $total_chars; // For API usage, this represents current month usage
		}

		// Determine API type
		$deepl_key   = get_option( 'voxfor_ml_deepl_api_key', '' );
		$is_free_api = ! empty( $deepl_key ) && substr( $deepl_key, -3 ) === ':fx';
		$api_limit   = $api_usage['character_limit'] ?? ( $is_free_api ? 500000 : null );

		// DeepL pricing: approximately $25 per 1M characters for Pro
		$cost_per_char = 0.000025;

		return array(
			'total_characters'       => $total_chars,
			'total_cost'             => $is_free_api ? 0 : ( $total_chars * $cost_per_char ),
			'monthly_characters'     => $month_chars,
			'monthly_cost'           => $is_free_api ? 0 : ( $month_chars * $cost_per_char ),
			'provider'               => $is_free_api ? 'DeepL Free API' : 'DeepL Pro API',
			'cost_per_million_chars' => $is_free_api ? 0 : 25.00,
			'estimated_cost'         => $is_free_api ? 0 : ( $total_chars * $cost_per_char ),
			'model'                  => $is_free_api ? 'DeepL Free API' : 'DeepL Pro API',
			'is_free_api'            => $is_free_api,
			'character_limit'        => $api_limit,
			'api_usage_raw'          => $api_usage,
			// Keep these for backward compatibility with templates
			'total_tokens'           => $total_chars,
			'monthly_costs'          => array( $this->getCurrentMonth() => $is_free_api ? 0 : ( $month_chars * $cost_per_char ) ),
			'daily_average'          => round( $this->getDailyAverageCost(), 4 ),
			'projected_monthly'      => $is_free_api ? 0 : ( $month_chars * $cost_per_char ),
		);
	}

	/**
	 * Get daily average cost for DeepL
	 */
	private function getDailyAverageCost() {
		global $wpdb;
		$table = $wpdb->prefix . 'voxfor_ml_translations';

		$daily_chars = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT AVG(daily_chars) as avg_daily FROM (SELECT DATE(created_at) as date, SUM(CHAR_LENGTH(source_text) + CHAR_LENGTH(translated_text)) as daily_chars FROM `%1s` WHERE provider = %s AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(created_at)) as daily_stats", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table,
				'deepl'
			)
		);

		// DeepL pricing: $25 per 1M characters
		$cost_per_char = 0.000025;

		return ( $daily_chars ?: 0 ) * $cost_per_char;
	}

	/**
	 * Get projected monthly cost
	 */
	private function getProjectedMonthlyCost() {
		$daily_avg = $this->getDailyAverageCost();
		return round( $daily_avg * 30, 2 );
	}

	/**
	 * Calculate cost for tokens
	 */
	private function calculateTokenCost( $tokens, $model = null ) {
		if ( ! $model ) {
			$model = 'deepl'; // Default to DeepL since we're not using Anthropic
		}

		$pricing = array(
			'claude-3-5-sonnet-20241022' => array(
				'input'  => 3.00,
				'output' => 15.00,
			),
			'claude-3-5-haiku-20241022'  => array(
				'input'  => 0.25,
				'output' => 1.25,
			),
			'claude-3-opus-20240229'     => array(
				'input'  => 15.00,
				'output' => 75.00,
			),
			'claude-3-sonnet-20240229'   => array(
				'input'  => 3.00,
				'output' => 15.00,
			),
			'claude-3-haiku-20240307'    => array(
				'input'  => 0.25,
				'output' => 1.25,
			),
		);

		$model_pricing = isset( $pricing[ $model ] ) ? $pricing[ $model ] : $pricing['claude-3-5-sonnet-20241022'];

		$input_tokens  = $tokens * 0.7;
		$output_tokens = $tokens * 0.3;

		$input_cost  = ( $input_tokens / 1000000 ) * $model_pricing['input'];
		$output_cost = ( $output_tokens / 1000000 ) * $model_pricing['output'];

		return $input_cost + $output_cost;
	}

	/**
	 * Get monthly estimated costs
	 */
	private function getMonthlyEstimatedCosts( $model_pricing ) {
		$monthly_tokens = get_option( 'voxfor_ml_monthly_tokens', array() );
		$costs          = array();

		foreach ( $monthly_tokens as $month => $tokens ) {
			$input_tokens  = $tokens * 0.7;
			$output_tokens = $tokens * 0.3;

			$input_cost  = ( $input_tokens / 1000000 ) * $model_pricing['input'];
			$output_cost = ( $output_tokens / 1000000 ) * $model_pricing['output'];

			$costs[ $month ] = round( $input_cost + $output_cost, 4 );
		}

		return $costs;
	}

	/**
	 * Categorize error type
	 */
	private function categorizeError( $error ) {
		if ( strpos( $error, 'rate limit' ) !== false ) {
			return 'rate_limit';
		} elseif ( strpos( $error, 'API key' ) !== false ) {
			return 'api_key';
		} elseif ( strpos( $error, 'timeout' ) !== false ) {
			return 'timeout';
		} elseif ( strpos( $error, 'network' ) !== false ) {
			return 'network';
		} else {
			return 'other';
		}
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

	/**
	 * Clean up old daily stats
	 */
	public function cleanupOldStats() {
		$daily_stats = get_option( $this->daily_stats_key, array() );
		$cutoff      = gmdate( 'Y-m-d', strtotime( '-90 days' ) );

		foreach ( $daily_stats as $date => $stats ) {
			if ( $date < $cutoff ) {
				unset( $daily_stats[ $date ] );
			}
		}

		update_option( $this->daily_stats_key, $daily_stats );
	}

	/**
	 * Export statistics
	 */
	public function exportStatistics( $format = 'json' ) {
		$data = array(
			'global_stats'          => $this->getGlobalStats(),
			'daily_stats'           => $this->getDailyStats(),
			'language_distribution' => $this->getLanguageDistribution(),
			'content_distribution'  => $this->getContentTypeDistribution(),
			'estimated_costs'       => $this->getEstimatedCosts(),
			'export_date'           => current_time( 'mysql' ),
		);

		if ( $format === 'csv' ) {
			return $this->convertToCSV( $data );
		}

		return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Convert data to CSV format
	 */
	private function convertToCSV( $data ) {
		$csv  = "Voxfor Multilanguage Statistics Export\n";
		$csv .= 'Export Date: ' . $data['export_date'] . "\n\n";

		// Global stats
		$csv .= "Global Statistics\n";
		$csv .= 'Total Translations,' . $data['global_stats']['total_translations'] . "\n";
		$csv .= 'Total Words,' . $data['global_stats']['total_words'] . "\n";
		$csv .= 'Total Characters,' . $data['global_stats']['total_characters'] . "\n";
		$csv .= 'Estimated Tokens,' . $data['global_stats']['estimated_tokens'] . "\n\n";

		// Language distribution
		$csv .= "Language Distribution\n";
		$csv .= "Language,Translations,Words,Percentage\n";
		foreach ( $data['language_distribution'] as $lang ) {
			$csv .= $lang['language'] . ',' . $lang['count'] . ',' . $lang['words'] . ',' . $lang['percentage'] . "%\n";
		}

		return $csv;
	}



	/**
	 * Get cost breakdown for specified number of days
	 */
	public function getCostBreakdown( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'voxfor_ml_translations';

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT DATE(created_at) as date, COUNT(*) as translations, SUM(CHAR_LENGTH(source_text) + CHAR_LENGTH(translated_text)) as characters, language_code FROM `%1s` WHERE provider = %s AND created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY) GROUP BY DATE(created_at), language_code ORDER BY date DESC", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table,
				'deepl',
				$days
			)
		);

		$breakdown     = array();
		$cost_per_char = 0.000025;

		foreach ( $results as $result ) {
			$date  = $result->date;
			$chars = (int) $result->characters;
			$cost  = $chars * $cost_per_char;

			if ( ! isset( $breakdown[ $date ] ) ) {
				$breakdown[ $date ] = array(
					'date'             => $date,
					'total_characters' => 0,
					'total_cost'       => 0,
					'translations'     => 0,
					'languages'        => array(),
				);
			}

			$breakdown[ $date ]['total_characters']                   += $chars;
			$breakdown[ $date ]['total_cost']                         += $cost;
			$breakdown[ $date ]['translations']                       += (int) $result->translations;
			$breakdown[ $date ]['languages'][ $result->language_code ] = array(
				'characters'   => $chars,
				'cost'         => $cost,
				'translations' => (int) $result->translations,
			);
		}

		return array_values( $breakdown );
	}

	/**
	 * Get monthly projection based on current usage
	 */
	public function getMonthlyProjection() {
		global $wpdb;
		$table = $wpdb->prefix . 'voxfor_ml_translations';

		// Get current month data
		$current_month_chars = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT SUM(CHAR_LENGTH(source_text) + CHAR_LENGTH(translated_text)) FROM `%1s` WHERE provider = %s AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table,
				'deepl'
			)
		);

		// Get days elapsed in current month
		$days_elapsed  = (int) gmdate( 'j' );
		$days_in_month = (int) gmdate( 't' );

		// Calculate projection
		$daily_average   = $days_elapsed > 0 ? ( $current_month_chars ?: 0 ) / $days_elapsed : 0;
		$projected_chars = $daily_average * $days_in_month;

		$cost_per_char = 0.000025;

		return array(
			'current_month_characters' => $current_month_chars ?: 0,
			'current_month_cost'       => ( $current_month_chars ?: 0 ) * $cost_per_char,
			'projected_characters'     => $projected_chars,
			'projected_cost'           => $projected_chars * $cost_per_char,
			'daily_average_characters' => $daily_average,
			'daily_average_cost'       => $daily_average * $cost_per_char,
			'days_elapsed'             => $days_elapsed,
			'days_remaining'           => $days_in_month - $days_elapsed,
		);
	}

	/**
	 * Get current month in Y-m format
	 */
	private function getCurrentMonth() {
		return gmdate( 'Y-m' );
	}
}
