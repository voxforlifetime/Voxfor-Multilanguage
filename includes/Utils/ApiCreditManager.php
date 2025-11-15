<?php
namespace VoxforML\Utils;

/**
 * Manages DeepL API credit usage, limits, and controls
 */
class ApiCreditManager {
	private $wpdb;
	private $table_usage;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb        = $wpdb;
		$this->table_usage = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'api_usage';

		// Create usage tracking table if needed
		$this->createUsageTable();

		// Register hooks
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'wp_ajax_voxfor_ml_emergency_stop', array( $this, 'ajaxEmergencyStop' ) );
		add_action( 'wp_ajax_voxfor_ml_toggle_api', array( $this, 'ajaxToggleApi' ) );
		add_action( 'wp_ajax_voxfor_ml_get_usage_stats', array( $this, 'ajaxGetUsageStats' ) );
	}

	/**
	 * Initialize API credit manager
	 */
	public function init() {
		// Add admin bar controls for quick access
		if ( is_admin() && current_user_can( 'manage_options' ) ) {
			add_action( 'admin_bar_menu', array( $this, 'addAdminBarControls' ), 100 );
		}

		// Clean old usage records daily
		if ( ! wp_next_scheduled( 'voxfor_ml_cleanup_usage' ) ) {
			wp_schedule_event( time(), 'daily', 'voxfor_ml_cleanup_usage' );
		}
		add_action( 'voxfor_ml_cleanup_usage', array( $this, 'cleanupOldUsage' ) );
	}

	/**
	 * Check if API calls are allowed
	 */
	public function isApiEnabled() {
		// Master switch
		$api_enabled = get_option( 'voxfor_ml_api_enabled', true );
		if ( ! $api_enabled ) {
			return false;
		}

		// Emergency stop
		$emergency_stop = get_option( 'voxfor_ml_api_emergency_stop', false );
		if ( $emergency_stop ) {
			return false;
		}

		// Check daily limit
		if ( $this->isDailyLimitReached() ) {
			$daily_limit = get_option( 'voxfor_ml_daily_credit_limit', 0 );
			$daily_used  = $this->getDailyUsage();
			return false;
		}

		// Check monthly limit
		if ( $this->isMonthlyLimitReached() ) {
			$monthly_limit = get_option( 'voxfor_ml_monthly_credit_limit', 0 );
			$monthly_used  = $this->getMonthlyUsage();
			return false;
		}

		return true;
	}

	/**
	 * Check if daily limit is reached
	 */
	public function isDailyLimitReached() {
		$daily_limit = get_option( 'voxfor_ml_daily_credit_limit', 0 );
		if ( $daily_limit <= 0 ) {
			return false; // No limit set
		}

		$today_usage = $this->getDailyUsage();
		return $today_usage >= $daily_limit;
	}

	/**
	 * Check if monthly limit is reached
	 */
	public function isMonthlyLimitReached() {
		$monthly_limit = get_option( 'voxfor_ml_monthly_credit_limit', 0 );
		if ( $monthly_limit <= 0 ) {
			return false; // No limit set
		}

		$month_usage = $this->getMonthlyUsage();
		return $month_usage >= $monthly_limit;
	}

	/**
	 * Record API usage
	 */
	public function recordUsage( $text_length, $source_lang, $target_lang, $context = 'general', $provider = 'deepl' ) {
		if ( ! $this->shouldTrackUsage() ) {
			return;
		}

		// Ensure source_lang is not null to prevent database errors
		if ( empty( $source_lang ) || $source_lang === null ) {
			$source_lang = 'EN'; // Default to English if source language is unknown
		}

		// Ensure target_lang is not null
		if ( empty( $target_lang ) || $target_lang === null ) {
			return;
		}

		$result = $this->wpdb->insert(
			$this->table_usage,
			array(
				'provider'        => $provider,
				'source_language' => $source_lang,
				'target_language' => $target_lang,
				'character_count' => $text_length,
				'context'         => $context,
				'usage_date'      => current_time( 'mysql' ),
				'cost_estimate'   => $this->estimateCost( $text_length, $provider ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%f' )
		);

		if ( $result ) {
			// Check if we're approaching limits and send alerts
			$this->checkUsageAlerts();
		}
	}

	/**
	 * Get daily usage
	 */
	public function getDailyUsage( $date = null ) {
		// Check if table exists
		$table_exists = $this->wpdb->get_var( $this->wpdb->prepare( "SHOW TABLES LIKE %s", $this->table_usage ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $table_exists ) {
			return 0; // Table doesn't exist yet
		}

		if ( ! $date ) {
			$date = current_time( 'Y-m-d' );
		}

		$usage = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT SUM(character_count) FROM `%1s` WHERE DATE(usage_date) = %s", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$this->table_usage, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$date // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return intval( $usage );
	}

	/**
	 * Get monthly usage
	 */
	public function getMonthlyUsage( $month = null, $year = null ) {
		// Check if table exists
		$table_exists = $this->wpdb->get_var( $this->wpdb->prepare( "SHOW TABLES LIKE %s", $this->table_usage ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $table_exists ) {
			return 0; // Table doesn't exist yet
		}

		if ( ! $month ) {
			$month = current_time( 'm' );
		}
		if ( ! $year ) {
			$year = current_time( 'Y' );
		}

		$usage = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT SUM(character_count) FROM `%1s` WHERE MONTH(usage_date) = %d AND YEAR(usage_date) = %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$this->table_usage, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$month, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$year // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return intval( $usage );
	}

	/**
	 * Get usage statistics
	 */
	public function getUsageStats( $days = 30 ) {
		$stats = array();

		// Daily usage for the last X days
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date                    = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$stats['daily'][ $date ] = $this->getDailyUsage( $date );
		}

		// Current month usage
		$stats['monthly']['current'] = $this->getMonthlyUsage();

		// Previous month usage
		$prev_month                   = gmdate( 'm', strtotime( '-1 month' ) );
		$prev_year                    = gmdate( 'Y', strtotime( '-1 month' ) );
		$stats['monthly']['previous'] = $this->getMonthlyUsage( $prev_month, $prev_year );

		// Total usage by language
		$lang_usage = $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT target_language, SUM(character_count) as total_chars FROM `%1s` WHERE usage_date >= DATE_SUB(NOW(), INTERVAL %d DAY) GROUP BY target_language ORDER BY total_chars DESC", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$this->table_usage, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$days // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$stats['by_language'] = array();
		foreach ( $lang_usage as $row ) {
			$stats['by_language'][ $row->target_language ] = intval( $row->total_chars );
		}

		// Usage by context
		$context_usage = $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT context, SUM(character_count) as total_chars FROM `%1s` WHERE usage_date >= DATE_SUB(NOW(), INTERVAL %d DAY) GROUP BY context ORDER BY total_chars DESC", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$this->table_usage, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$days // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$stats['by_context'] = array();
		foreach ( $context_usage as $row ) {
			$stats['by_context'][ $row->context ] = intval( $row->total_chars );
		}

		// Limits and current status
		$stats['limits'] = array(
			'daily_limit'    => get_option( 'voxfor_ml_daily_credit_limit', 0 ),
			'monthly_limit'  => get_option( 'voxfor_ml_monthly_credit_limit', 0 ),
			'daily_used'     => $this->getDailyUsage(),
			'monthly_used'   => $this->getMonthlyUsage(),
			'api_enabled'    => $this->isApiEnabled(),
			'emergency_stop' => get_option( 'voxfor_ml_api_emergency_stop', false ),
		);

		return $stats;
	}

	/**
	 * Estimate cost for character count
	 */
	private function estimateCost( $char_count, $provider = 'deepl' ) {
		$rates = array(
			'deepl' => 0.00002, // $20 per 1M characters
		);

		$rate = isset( $rates[ $provider ] ) ? $rates[ $provider ] : $rates['deepl'];
		return $char_count * $rate;
	}

	/**
	 * Check usage alerts and send notifications
	 */
	private function checkUsageAlerts() {
		$alert_settings = array(
			'daily_80'   => get_option( 'voxfor_ml_alert_daily_80', true ),
			'daily_90'   => get_option( 'voxfor_ml_alert_daily_90', true ),
			'monthly_80' => get_option( 'voxfor_ml_alert_monthly_80', true ),
			'monthly_90' => get_option( 'voxfor_ml_alert_monthly_90', true ),
		);

		$daily_limit   = get_option( 'voxfor_ml_daily_credit_limit', 0 );
		$monthly_limit = get_option( 'voxfor_ml_monthly_credit_limit', 0 );

		if ( $daily_limit > 0 ) {
			$daily_usage   = $this->getDailyUsage();
			$daily_percent = ( $daily_usage / $daily_limit ) * 100;

			if ( $daily_percent >= 90 && $alert_settings['daily_90'] ) {
				$this->sendUsageAlert( 'daily', 90, $daily_usage, $daily_limit );
			} elseif ( $daily_percent >= 80 && $alert_settings['daily_80'] ) {
				$this->sendUsageAlert( 'daily', 80, $daily_usage, $daily_limit );
			}
		}

		if ( $monthly_limit > 0 ) {
			$monthly_usage   = $this->getMonthlyUsage();
			$monthly_percent = ( $monthly_usage / $monthly_limit ) * 100;

			if ( $monthly_percent >= 90 && $alert_settings['monthly_90'] ) {
				$this->sendUsageAlert( 'monthly', 90, $monthly_usage, $monthly_limit );
			} elseif ( $monthly_percent >= 80 && $alert_settings['monthly_80'] ) {
				$this->sendUsageAlert( 'monthly', 80, $monthly_usage, $monthly_limit );
			}
		}
	}

	/**
	 * Send usage alert email
	 */
	private function sendUsageAlert( $period, $percentage, $used, $limit ) {
		$alert_key = "voxfor_ml_alert_{$period}_{$percentage}_sent_" . gmdate( 'Y-m-d' );

		// Prevent duplicate alerts for the same day
		if ( get_transient( $alert_key ) ) {
			return;
		}

		$admin_email = get_option( 'voxfor_ml_cost_alert_email', get_option( 'admin_email' ) );
		$site_name   = get_bloginfo( 'name' );

		$subject = sprintf(
			/* translators: %1$s is the site name, %2$d is the usage percentage, %3$s is the time period (daily/monthly) */
			__( '[%1$s] DeepL API Usage Alert - %2$d%% of %3$s limit reached', 'voxfor-multilanguage' ),
			$site_name,
			$percentage,
			$period
		);

		$message = sprintf(
			__( "Hello,\n\nYour website %1\$s has reached %2\$d%% of the %3\$s DeepL API usage limit.\n\nUsage: %4\$s characters\nLimit: %5\$s characters\n\nTo avoid service interruption, consider:\n- Increasing the limit\n- Pausing translation temporarily\n- Reviewing translation settings\n\nManage settings: %6\$s\n\nBest regards,\nVoxfor Multilanguage Plugin", 'voxfor-multilanguage' ),
			$site_name,
			$percentage,
			$period,
			number_format( $used ),
			number_format( $limit ),
			admin_url( 'admin.php?page=voxfor-multilanguage-settings' )
		);

		wp_mail( $admin_email, $subject, $message );

		// Set transient to prevent duplicate alerts
		set_transient( $alert_key, true, DAY_IN_SECONDS );
	}

	/**
	 * Add admin bar controls
	 */
	public function addAdminBarControls( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$api_enabled    = $this->isApiEnabled();
		$emergency_stop = get_option( 'voxfor_ml_api_emergency_stop', false );

		// Main menu item
		$wp_admin_bar->add_menu(
			array(
				'id'    => 'voxfor-ml-api-control',
				'title' => sprintf(
					'<span class="ab-icon dashicons dashicons-translation" style="color: %s;"></span> DeepL API',
					$api_enabled ? '#46b450' : '#dc3232'
				),
				'href'  => admin_url( 'admin.php?page=voxfor-multilanguage-api-management' ),
			)
		);

		// Toggle API
		$wp_admin_bar->add_menu(
			array(
				'parent' => 'voxfor-ml-api-control',
				'id'     => 'voxfor-ml-toggle-api',
				'title'  => $api_enabled ? __( 'Pause API', 'voxfor-multilanguage' ) : __( 'Resume API', 'voxfor-multilanguage' ),
				'href'   => '#',
				'meta'   => array(
					'onclick' => 'voxforMLToggleAPI(); return false;',
				),
			)
		);

		// Emergency stop
		if ( ! $emergency_stop ) {
			$wp_admin_bar->add_menu(
				array(
					'parent' => 'voxfor-ml-api-control',
					'id'     => 'voxfor-ml-emergency-stop',
					'title'  => '<span style="color: #dc3232;">' . __( 'Emergency Stop', 'voxfor-multilanguage' ) . '</span>',
					'href'   => '#',
					'meta'   => array(
						'onclick' => 'voxforMLEmergencyStop(); return false;',
					),
				)
			);
		}

		// Usage stats
		$daily_usage = $this->getDailyUsage();
		$daily_limit = get_option( 'voxfor_ml_daily_credit_limit', 0 );

		if ( $daily_limit > 0 ) {
			$percentage = min( 100, ( $daily_usage / $daily_limit ) * 100 );
			$wp_admin_bar->add_menu(
				array(
					'parent' => 'voxfor-ml-api-control',
					'id'     => 'voxfor-ml-usage-today',
					'title'  => sprintf(
						/* translators: %1$s is characters used today, %2$s is daily limit, %3$.1f is usage percentage */
						__( 'Today: %1$s/%2$s chars (%3$.1f%%)', 'voxfor-multilanguage' ),
						number_format( $daily_usage ),
						number_format( $daily_limit ),
						$percentage
					),
				)
			);
		} else {
			$wp_admin_bar->add_menu(
				array(
					'parent' => 'voxfor-ml-api-control',
					'id'     => 'voxfor-ml-usage-today',
					'title'  => sprintf(
						/* translators: %s is the number of characters used today */
						__( 'Today: %s chars', 'voxfor-multilanguage' ),
						number_format( $daily_usage )
					),
				)
			);
		}
	}

	/**
	 * AJAX: Emergency stop
	 */
	public function ajaxEmergencyStop() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'voxfor-multilanguage' ) ) );
		}

		if ( ! check_ajax_referer( 'voxfor_ml_emergency_stop', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'voxfor-multilanguage' ) ) );
		}

		update_option( 'voxfor_ml_api_emergency_stop', true );
		update_option( 'voxfor_ml_api_enabled', false );

		wp_send_json_success(
			array(
				'message' => __( 'Emergency stop activated. All API calls have been suspended.', 'voxfor-multilanguage' ),
			)
		);
	}

	/**
	 * AJAX: Toggle API
	 */
	public function ajaxToggleApi() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'voxfor-multilanguage' ) ) );
		}

		if ( ! check_ajax_referer( 'voxfor_ml_toggle_api', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'voxfor-multilanguage' ) ) );
		}

		$current_status = get_option( 'voxfor_ml_api_enabled', true );
		$new_status     = ! $current_status;

		update_option( 'voxfor_ml_api_enabled', $new_status );

		// Reset emergency stop if enabling
		if ( $new_status ) {
			update_option( 'voxfor_ml_api_emergency_stop', false );
		}

		wp_send_json_success(
			array(
				'status'  => $new_status,
				'message' => $new_status ?
					__( 'API enabled successfully', 'voxfor-multilanguage' ) :
					__( 'API paused successfully', 'voxfor-multilanguage' ),
			)
		);
	}

	/**
	 * AJAX: Get usage stats
	 */
	public function ajaxGetUsageStats() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'voxfor-multilanguage' ) ) );
		}

		$stats = $this->getUsageStats();
		wp_send_json_success( $stats );
	}

	/**
	 * Create usage tracking table
	 */
	private function createUsageTable() {
		// Check if table already exists to prevent multiple creation attempts
		$table_exists = $this->wpdb->get_var( $this->wpdb->prepare( "SHOW TABLES LIKE %s", $this->table_usage ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $table_exists ) {
			return; // Table already exists
		}

		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_usage} (
            id int(11) NOT NULL AUTO_INCREMENT,
            provider varchar(20) NOT NULL DEFAULT 'deepl',
            source_language varchar(10) NOT NULL,
            target_language varchar(10) NOT NULL,
            character_count int(11) NOT NULL,
            context varchar(50) NOT NULL DEFAULT 'general',
            usage_date datetime NOT NULL,
            cost_estimate decimal(10,6) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_usage_date (usage_date),
            KEY idx_provider_lang (provider, target_language)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Should track usage
	 */
	private function shouldTrackUsage() {
		return get_option( 'voxfor_ml_track_usage', true );
	}

	/**
	 * Clean up old usage records
	 */
	public function cleanupOldUsage() {
		// Check if table exists
		$table_exists = $this->wpdb->get_var( $this->wpdb->prepare( "SHOW TABLES LIKE %s", $this->table_usage ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $table_exists ) {
			return; // Table doesn't exist yet
		}

		$retention_days = get_option( 'voxfor_ml_usage_retention_days', 365 );

		$this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"DELETE FROM `%1s` WHERE usage_date < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$this->table_usage, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$retention_days // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Get queue size estimate
	 */
	public function getQueueCreditEstimate() { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		global $wpdb;
		$queue_table = $wpdb->prefix . VOXFOR_ML_TABLE_PREFIX . 'translation_queue';

		// Check if queue table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $queue_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $table_exists ) {
			return array(
				'character_count' => 0,
				'estimated_cost'  => 0,
			);
		}

		$total_chars = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT SUM(CHAR_LENGTH(source_text)) /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */ FROM {$queue_table} /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
             WHERE status = %s", 'pending' )
		);

		return array(
			'character_count' => intval( $total_chars ),
			'estimated_cost'  => $this->estimateCost( intval( $total_chars ) ),
		);
	}
}
