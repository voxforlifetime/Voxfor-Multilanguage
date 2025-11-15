<?php
namespace VoxforML\Translator;

use VoxforML\Core\Plugin;
use VoxforML\Database\TranslationMemory;

/**
 * Manages bulk translation operations with progress tracking
 */
class BulkTranslationManager {
	private $job_option_key  = 'voxfor_ml_bulk_jobs';
	private $current_job_key = 'voxfor_ml_current_bulk_job';
	private $items_per_batch = 1; // 1 page per minute as requested
	private $memory;
	private $translator;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->memory     = new TranslationMemory();
		$this->translator = Plugin::getInstance()->getComponent( 'translator' );

		// Schedule cron event for processing
		add_action( 'voxfor_ml_process_bulk_translation', array( $this, 'processBatch' ) );

		if ( ! wp_next_scheduled( 'voxfor_ml_process_bulk_translation' ) ) {
			wp_schedule_event( time(), 'voxfor_ml_minute', 'voxfor_ml_process_bulk_translation' );
		}

		// Add custom cron schedule
		add_filter( 'cron_schedules', array( $this, 'addCronSchedule' ) );
	}

	/**
	 * Add custom cron schedule for every minute
	 */
	public function addCronSchedule( $schedules ) {
		$schedules['voxfor_ml_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every Minute', 'voxfor-multilanguage' ),
		);
		return $schedules;
	}

	/**
	 * Start a new bulk translation job
	 */
	public function startBulkTranslation( $args = array() ) {
		$defaults = array(
			'languages'          => array(),
			'post_types'         => array( 'post', 'page' ),
			'post_status'        => array( 'publish' ),
			'include_ids'        => array(),
			'exclude_ids'        => array(),
			'items_per_batch'    => 1,
			'translate_existing' => false,
		);

		$args = wp_parse_args( $args, $defaults );

		// Generate unique job ID
		$job_id = 'bulk_' . time() . '_' . wp_generate_password( 8, false );

		// Get all items to translate
		$items = $this->getItemsToTranslate( $args );

		if ( empty( $items ) ) {
			return array(
				'success' => false,
				'message' => __( 'No items found to translate', 'voxfor-multilanguage' ),
			);
		}

		// Calculate total operations
		$total_operations = count( $items ) * count( $args['languages'] );

		// Create job data
		$job = array(
			'id'                   => $job_id,
			'status'               => 'running',
			'args'                 => $args,
			'items'                => $items,
			'languages'            => $args['languages'],
			'total_items'          => count( $items ),
			'total_operations'     => $total_operations,
			'processed_items'      => 0,
			'processed_operations' => 0,
			'failed_items'         => array(),
			'current_item'         => 0,
			'current_language'     => 0,
			'started_at'           => current_time( 'mysql' ),
			'updated_at'           => current_time( 'mysql' ),
			'completed_at'         => null,
			'log'                  => array(),
		);

		// Save job
		$this->saveJob( $job );
		update_option( $this->current_job_key, $job_id );

		// Log start
		$this->logJobEvent(
			$job_id,
			'started',
			array(
				'total_items' => count( $items ),
				'languages'   => $args['languages'],
			)
		);

		return array(
			'success'          => true,
			'job_id'           => $job_id,
			'total_items'      => count( $items ),
			'total_operations' => $total_operations,
			'message'          => sprintf(
				/* translators: %1$d is the number of items, %2$d is the number of languages */
				__( 'Bulk translation started. %1$d items will be translated to %2$d languages.', 'voxfor-multilanguage' ),
				count( $items ),
				count( $args['languages'] )
			),
		);
	}

	/**
	 * Get items to translate based on criteria
	 */
	private function getItemsToTranslate( $args ) {
		$query_args = array(
			'post_type'      => $args['post_types'],
			'post_status'    => $args['post_status'],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);

		// Include specific IDs
		if ( ! empty( $args['include_ids'] ) ) {
			$query_args['post__in'] = $args['include_ids'];
		}

		// Exclude specific IDs
		if ( ! empty( $args['exclude_ids'] ) ) {
			$query_args['post__not_in'] = $args['exclude_ids']; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
		}

		// Exclude already translated if not retranslating
		if ( ! $args['translate_existing'] ) {
			$query_args['meta_query'][] = array(
				'key'     => '_voxfor_ml_translated',
				'compare' => 'NOT EXISTS',
			);
		}

		// Exclude items marked as excluded from translation
		$query_args['meta_query'][] = array(
			'relation' => 'OR',
			array(
				'key'     => '_voxfor_ml_exclude',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_voxfor_ml_exclude',
				'value'   => '1',
				'compare' => '!=',
			),
		);

		$query = new \WP_Query( $query_args );

		return $query->posts;
	}

	/**
	 * Process a batch of translations
	 */
	public function processBatch() {
		$job_id = get_option( $this->current_job_key );

		if ( ! $job_id ) {
			return;
		}

		$job = $this->getJob( $job_id );

		if ( ! $job || $job['status'] !== 'running' ) {
			return;
		}

		// Process one item
		$item_index = $job['current_item'];
		$lang_index = $job['current_language'];

		if ( $item_index >= count( $job['items'] ) ) {
			// Job completed
			$this->completeJob( $job_id );
			return;
		}

		$post_id  = $job['items'][ $item_index ];
		$language = $job['languages'][ $lang_index ];

		// Translate the item
		$result = $this->translateItem( $post_id, $language );

		// Update progress
		++$job['processed_operations'];

		if ( ! $result['success'] ) {
			$job['failed_items'][] = array(
				'post_id'  => $post_id,
				'language' => $language,
				'error'    => $result['error'],
			);
		}

		// Log translation
		$this->logJobEvent(
			$job_id,
			'item_translated',
			array(
				'post_id'  => $post_id,
				'language' => $language,
				'success'  => $result['success'],
				'title'    => get_the_title( $post_id ),
			)
		);

		// Move to next language or item
		++$lang_index;
		if ( $lang_index >= count( $job['languages'] ) ) {
			$lang_index = 0;
			++$item_index;
			++$job['processed_items'];
		}

		$job['current_item']     = $item_index;
		$job['current_language'] = $lang_index;
		$job['updated_at']       = current_time( 'mysql' );

		// Save progress
		$this->saveJob( $job );

		// Send progress notification via AJAX if requested
		$this->notifyProgress( $job );
	}

	/**
	 * Translate a single item
	 */
	private function translateItem( $post_id, $language ) {
		try {
			$post = get_post( $post_id );

			if ( ! $post ) {
				return array(
					'success' => false,
					'error'   => 'Post not found',
				);
			}

			// Queue translations for all content
			$contents = array(
				'title'   => array(
					'text'    => $post->post_title,
					'context' => 'title',
				),
				'content' => array(
					'text'    => $post->post_content,
					'context' => 'content',
				),
				'excerpt' => array(
					'text'    => $post->post_excerpt,
					'context' => 'excerpt',
				),
			);

			// Add SEO metadata
			$seo_manager  = Plugin::getInstance()->getComponent( 'seo' );
			$seo_metadata = $seo_manager->getSEOMetadata( $post_id );

			foreach ( $seo_metadata as $key => $value ) {
				if ( is_string( $value ) && ! empty( $value ) ) {
					$contents[ 'seo_' . $key ] = array(
						'text'    => $value,
						'context' => 'seo_' . $key,
					);
				}
			}

			// Queue all content for translation
			foreach ( $contents as $key => $data ) {
				if ( ! empty( $data['text'] ) ) {
					$this->memory->queueForTranslation(
						$data['text'],
						$language,
						$data['context'],
						$post_id
					);
				}
			}

			// Process the queue immediately for this post
			$translator = Plugin::getInstance()->getComponent( 'translator' );
			$translator->processTranslationQueue();

			// Mark post as having translations (only during explicit translation process)
			if ( current_user_can( 'edit_post', $post_id ) ) {
				update_post_meta( $post_id, '_voxfor_ml_translated', true );
				update_post_meta( $post_id, '_voxfor_ml_translated_' . $language, current_time( 'mysql' ) );
			}

			return array( 'success' => true );

		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Complete a job
	 */
	private function completeJob( $job_id ) {
		$job = $this->getJob( $job_id );

		if ( ! $job ) {
			return;
		}

		$job['status']       = 'completed';
		$job['completed_at'] = current_time( 'mysql' );

		$this->saveJob( $job );
		delete_option( $this->current_job_key );

		// Log completion
		$this->logJobEvent(
			$job_id,
			'completed',
			array(
				'total_processed'  => $job['processed_items'],
				'total_operations' => $job['processed_operations'],
				'failed_count'     => count( $job['failed_items'] ),
			)
		);

		// Send notification
		do_action( 'voxfor_ml_bulk_translation_completed', $job );
	}

	/**
	 * Pause a job
	 */
	public function pauseJob( $job_id ) {
		$job = $this->getJob( $job_id );

		if ( ! $job || $job['status'] !== 'running' ) {
			return false;
		}

		$job['status']     = 'paused';
		$job['updated_at'] = current_time( 'mysql' );

		$this->saveJob( $job );

		if ( get_option( $this->current_job_key ) === $job_id ) {
			delete_option( $this->current_job_key );
		}

		$this->logJobEvent( $job_id, 'paused' );

		return true;
	}

	/**
	 * Resume a job
	 */
	public function resumeJob( $job_id ) {
		$job = $this->getJob( $job_id );

		if ( ! $job || $job['status'] !== 'paused' ) {
			return false;
		}

		$job['status']     = 'running';
		$job['updated_at'] = current_time( 'mysql' );

		$this->saveJob( $job );
		update_option( $this->current_job_key, $job_id );

		$this->logJobEvent( $job_id, 'resumed' );

		return true;
	}

	/**
	 * Cancel a job
	 */
	public function cancelJob( $job_id ) {
		$job = $this->getJob( $job_id );

		if ( ! $job || in_array( $job['status'], array( 'completed', 'cancelled' ) ) ) {
			return false;
		}

		$job['status']       = 'cancelled';
		$job['completed_at'] = current_time( 'mysql' );

		$this->saveJob( $job );

		if ( get_option( $this->current_job_key ) === $job_id ) {
			delete_option( $this->current_job_key );
		}

		$this->logJobEvent( $job_id, 'cancelled' );

		return true;
	}

	/**
	 * Get job status
	 */
	public function getJobStatus( $job_id ) {
		$job = $this->getJob( $job_id );

		if ( ! $job ) {
			return null;
		}

		$progress = 0;
		if ( $job['total_operations'] > 0 ) {
			$progress = round( ( $job['processed_operations'] / $job['total_operations'] ) * 100, 2 );
		}

		return array(
			'id'                   => $job['id'],
			'status'               => $job['status'],
			'progress'             => $progress,
			'processed_items'      => $job['processed_items'],
			'total_items'          => $job['total_items'],
			'processed_operations' => $job['processed_operations'],
			'total_operations'     => $job['total_operations'],
			'failed_count'         => count( $job['failed_items'] ),
			'started_at'           => $job['started_at'],
			'updated_at'           => $job['updated_at'],
			'completed_at'         => $job['completed_at'],
			'eta'                  => $this->calculateETA( $job ),
		);
	}

	/**
	 * Calculate ETA for job completion
	 */
	private function calculateETA( $job ) {
		if ( $job['processed_operations'] === 0 ) {
			return null;
		}

		$elapsed   = strtotime( $job['updated_at'] ) - strtotime( $job['started_at'] );
		$rate      = $job['processed_operations'] / $elapsed; // operations per second
		$remaining = $job['total_operations'] - $job['processed_operations'];

		if ( $rate > 0 ) {
			$eta_seconds = $remaining / $rate;
			return human_time_diff( time(), time() + $eta_seconds );
		}

		return null;
	}

	/**
	 * Get all jobs
	 */
	public function getAllJobs() {
		$jobs = get_option( $this->job_option_key, array() );
		return array_reverse( $jobs, true ); // Most recent first
	}

	/**
	 * Get a specific job
	 */
	private function getJob( $job_id ) {
		$jobs = get_option( $this->job_option_key, array() );
		return isset( $jobs[ $job_id ] ) ? $jobs[ $job_id ] : null;
	}

	/**
	 * Save a job
	 */
	private function saveJob( $job ) {
		$jobs               = get_option( $this->job_option_key, array() );
		$jobs[ $job['id'] ] = $job;

		// Keep only last 50 jobs
		if ( count( $jobs ) > 50 ) {
			$jobs = array_slice( $jobs, -50, 50, true );
		}

		update_option( $this->job_option_key, $jobs );
	}

	/**
	 * Log job event
	 */
	private function logJobEvent( $job_id, $event, $data = array() ) {
		$job = $this->getJob( $job_id );

		if ( ! $job ) {
			return;
		}

		$log_entry = array(
			'timestamp' => current_time( 'mysql' ),
			'event'     => $event,
			'data'      => $data,
		);

		$job['log'][] = $log_entry;

		// Keep only last 100 log entries
		if ( count( $job['log'] ) > 100 ) {
			$job['log'] = array_slice( $job['log'], -100 );
		}

		$this->saveJob( $job );

		// Also save to task log
		$this->saveToTaskLog( $job_id, $event, $data );
	}

	/**
	 * Save to global task log
	 */
	private function saveToTaskLog( $job_id, $event, $data ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'voxfor_ml_task_log';

		// Create table if not exists
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE IF NOT EXISTS {$table_name} (
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

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Insert log entry
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table_name,
			array(
				'job_id'  => $job_id,
				'event'   => $event,
				'data'    => json_encode( $data ),
				'user_id' => get_current_user_id(),
			)
		);
	}

	/**
	 * Notify progress via AJAX
	 */
	private function notifyProgress( $job ) {
		// Store progress in transient for AJAX polling
		set_transient( 'voxfor_ml_job_progress_' . $job['id'], $this->getJobStatus( $job['id'] ), 60 );
	}

	/**
	 * Clean up old jobs
	 */
	public function cleanupOldJobs( $days = 30 ) {
		$jobs   = get_option( $this->job_option_key, array() );
		$cutoff = strtotime( "-{$days} days" );

		foreach ( $jobs as $job_id => $job ) {
			if ( isset( $job['completed_at'] ) && strtotime( $job['completed_at'] ) < $cutoff ) {
				unset( $jobs[ $job_id ] );
			}
		}

		update_option( $this->job_option_key, $jobs );
	}
}
