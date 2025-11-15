<?php
namespace VoxforML\Admin;

use VoxforML\Core\Plugin;

/**
 * Translation Status Meta Box for posts/pages
 */
class TranslationStatusMetaBox {
	private $plugin;
	private $translator;
	private $memory;
	private $router;
	private $slug_manager;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin       = Plugin::getInstance();
		$this->translator   = $this->plugin->getComponent( 'translator' );
		$this->memory       = $this->plugin->getComponent( 'translation_memory' );
		$this->router       = $this->plugin->getComponent( 'router' );
		$this->slug_manager = $this->plugin->getComponent( 'slug_manager' );

		$this->initHooks();
	}

	/**
	 * Initialize hooks
	 */
	private function initHooks() {
		add_action( 'add_meta_boxes', array( $this, 'addMetaBox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueScripts' ) );

		// AJAX handlers
		add_action( 'wp_ajax_voxfor_ml_create_translation', array( $this, 'ajaxCreateTranslation' ) );
		add_action( 'wp_ajax_voxfor_ml_get_translation_status', array( $this, 'ajaxGetTranslationStatus' ) );
		add_action( 'wp_ajax_voxfor_ml_translate_all_languages', array( $this, 'ajaxTranslateAllLanguages' ) );
	}

	/**
	 * Add meta box
	 */
	public function addMetaBox() {
		$post_types = get_post_types( array( 'public' => true ) );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'voxfor_ml_translation_status',
				__( 'Translation Status', 'voxfor-multilanguage' ),
				array( $this, 'renderMetaBox' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Enqueue scripts and styles
	 */
	public function enqueueScripts( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
			return;
		}

		wp_enqueue_script(
			'voxfor-ml-translation-status',
			VOXFOR_ML_PLUGIN_URL . 'public/js/translation-status.js',
			array( 'jquery' ),
			VOXFOR_ML_VERSION,
			true
		);

		wp_enqueue_style(
			'voxfor-ml-translation-status',
			VOXFOR_ML_PLUGIN_URL . 'public/css/translation-status.css',
			array(),
			VOXFOR_ML_VERSION
		);

		// Meta box specific styles
		wp_enqueue_style(
			'voxfor-ml-meta-box-translation-status',
			VOXFOR_ML_PLUGIN_URL . 'public/css/admin/meta-box-translation-status.css',
			array( 'voxfor-ml-translation-status' ),
			VOXFOR_ML_VERSION
		);

		wp_localize_script(
			'voxfor-ml-translation-status',
			'voxforMLStatus',
			array(
				'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
				'nonce'                 => wp_create_nonce( 'voxfor_ml_ajax' ),
				'productTranslateNonce' => wp_create_nonce( 'voxfor_ml_product_translate' ),
				'strings'               => array(
					'creating'      => __( 'Creating translation...', 'voxfor-multilanguage' ),
					'created'       => __( 'Translation created!', 'voxfor-multilanguage' ),
					'error'         => __( 'Error creating translation', 'voxfor-multilanguage' ),
					'view'          => __( 'View', 'voxfor-multilanguage' ),
					'edit'          => __( 'Edit', 'voxfor-multilanguage' ),
					'create'        => __( 'Create', 'voxfor-multilanguage' ),
					'translate'     => __( 'Translate', 'voxfor-multilanguage' ),
					'translating'   => __( 'Translating...', 'voxfor-multilanguage' ),
					'translated'    => __( 'Translated', 'voxfor-multilanguage' ),
					'notTranslated' => __( 'Not translated', 'voxfor-multilanguage' ),
					'inProgress'    => __( 'In progress', 'voxfor-multilanguage' ),
					'refresh'       => __( 'Refresh status', 'voxfor-multilanguage' ),
				),
			)
		);
	}

	/**
	 * Render meta box
	 */
	public function renderMetaBox( $post ) {
		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();
		$current_lang = $this->getCurrentEditLanguage();

		// Get translation status for all languages
		$translation_status = $this->getTranslationStatus( $post->ID );

		?>
		<div class="voxfor-ml-translation-status-box">
			<div class="voxfor-ml-status-header">
				<h4><?php esc_html_e( 'Available Languages', 'voxfor-multilanguage' ); ?></h4>
				<button type="button" class="button button-small" id="voxfor-ml-refresh-status">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'voxfor-multilanguage' ); ?>
				</button>
			</div>
			
			<div class="voxfor-ml-language-grid">
				<?php foreach ( $languages as $lang ) : ?>
					<?php
					// Skip the default language (source language)
					if ( $lang === $default_lang ) {
						continue;
					}

					$status     = $translation_status[ $lang ] ?? array();
					$is_current = ( $lang === $current_lang );
					$is_default = ( $lang === $default_lang );
					?>
					
					<div class="voxfor-ml-language-item <?php echo $is_current ? 'current' : ''; ?>" 
						data-language="<?php echo esc_attr( $lang ); ?>">
						
						<div class="voxfor-ml-language-header">
							<span class="voxfor-ml-flag">
								<?php echo wp_kses_post( $this->getLanguageFlag( $lang ) ); ?>
							</span>
							<span class="voxfor-ml-language-name">
								<?php echo esc_html( $this->getLanguageName( $lang ) ); ?>
							</span>
							<?php if ( $is_current ) : ?>
								<span class="voxfor-ml-current-badge"><?php esc_html_e( 'Current', 'voxfor-multilanguage' ); ?></span>
							<?php endif; ?>
						</div>
						
						<div class="voxfor-ml-translation-info">
							<?php if ( $is_default && $post->ID ) : ?>
								<!-- Default language (source) -->
								<div class="voxfor-ml-status voxfor-ml-status-source">
									<span class="dashicons dashicons-admin-home"></span>
									<?php esc_html_e( 'Source Language', 'voxfor-multilanguage' ); ?>
								</div>
								<div class="voxfor-ml-title">
									<?php echo esc_html( $post->post_title ); ?>
								</div>
							<?php elseif ( ! empty( $status['translated'] ) ) : ?>
								<!-- Translated -->
								<div class="voxfor-ml-status voxfor-ml-status-translated">
									<span class="dashicons dashicons-yes-alt"></span>
									<?php echo esc_html( $status['status_text'] ); ?>
								</div>
								<div class="voxfor-ml-title">
									<?php echo esc_html( $status['title'] ?? $post->post_title ); ?>
								</div>
								<div class="voxfor-ml-meta">
									<?php if ( ! empty( $status['last_updated'] ) ) : ?>
									<small>
									<?php
									printf(
										/* translators: %s is the human-readable time difference (e.g., "2 hours ago") */
										esc_html__( 'Updated: %s', 'voxfor-multilanguage' ),
										esc_html( human_time_diff( strtotime( $status['last_updated'] ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'voxfor-multilanguage' ) )
									);
									?>
											</small>
									<?php endif; ?>
									<?php if ( ! empty( $status['completion'] ) ) : ?>
										<div class="voxfor-ml-progress">
											<div class="voxfor-ml-progress-bar" style="width: <?php echo intval( $status['completion'] ); ?>%"></div>
										</div>
										<small><?php echo intval( $status['completion'] ); ?>% <?php esc_html_e( 'complete', 'voxfor-multilanguage' ); ?></small>
									<?php endif; ?>
								</div>
							<?php elseif ( ! empty( $status['in_progress'] ) ) : ?>
								<!-- In Progress -->
								<div class="voxfor-ml-status voxfor-ml-status-progress">
									<span class="dashicons dashicons-update spin"></span>
									<?php esc_html_e( 'Translation in progress', 'voxfor-multilanguage' ); ?>
								</div>
							<?php else : ?>
								<!-- Not translated -->
								<div class="voxfor-ml-status voxfor-ml-status-missing">
									<span class="dashicons dashicons-warning"></span>
									<?php esc_html_e( 'Not translated', 'voxfor-multilanguage' ); ?>
								</div>
							<?php endif; ?>
						</div>
						
						<div class="voxfor-ml-actions">
							<?php if ( $is_default && $post->ID ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" class="button button-small">
									<span class="dashicons dashicons-edit"></span>
									<?php esc_html_e( 'Edit Source', 'voxfor-multilanguage' ); ?>
								</a>
							<?php elseif ( ! empty( $status['translated'] ) ) : ?>
								<a href="<?php echo esc_url( $status['view_url'] ?? '#' ); ?>" 
									class="button button-small" target="_blank">
									<span class="dashicons dashicons-visibility"></span>
									<?php esc_html_e( 'View', 'voxfor-multilanguage' ); ?>
								</a>
								<a href="<?php echo esc_url( $status['edit_url'] ?? '#' ); ?>" 
									class="button button-small button-primary">
									<span class="dashicons dashicons-edit"></span>
									<?php esc_html_e( 'Edit', 'voxfor-multilanguage' ); ?>
								</a>
							<?php elseif ( ! empty( $status['in_progress'] ) ) : ?>
								<button type="button" class="button button-small" disabled>
									<span class="dashicons dashicons-clock"></span>
									<?php esc_html_e( 'Please wait...', 'voxfor-multilanguage' ); ?>
								</button>
							<?php else : ?>
								<?php if ( $post->post_type === 'product' ) : ?>
									<button type="button" class="button button-small button-primary voxfor-ml-translate-product"
											data-product-id="<?php echo intval( $post->ID ); ?>"
											data-language="<?php echo esc_attr( $lang ); ?>"
											onclick="return false;">
										<span class="dashicons dashicons-translation"></span>
										<?php esc_html_e( 'Translate', 'voxfor-multilanguage' ); ?>
									</button>
								<?php else : ?>
									<button type="button" class="button button-small button-primary voxfor-ml-create-translation"
											data-post-id="<?php echo intval( $post->ID ); ?>"
											data-language="<?php echo esc_attr( $lang ); ?>">
										<span class="dashicons dashicons-translation"></span>
										<?php esc_html_e( 'Translate Page', 'voxfor-multilanguage' ); ?>
									</button>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			
			<div class="voxfor-ml-status-summary">
				<?php
				$translated_count = count(
					array_filter(
						$translation_status,
						function ( $s ) {
							return ! empty( $s['translated'] );
						}
					)
				);
				$total_languages  = count( $languages ) - 1; // Exclude default language
				?>
				<p>
					<?php
					printf(
						/* translators: %1$d is the number of languages translated to, %2$d is the total number of languages */
						esc_html__( 'Translated to %1$d of %2$d languages', 'voxfor-multilanguage' ),
						intval( $translated_count ),
						intval( $total_languages )
					);
					?>
				</p>
				<?php if ( $translated_count < $total_languages ) : ?>
					<button type="button" class="button voxfor-ml-translate-all"
							data-post-id="<?php echo intval( $post->ID ); ?>">
						<span class="dashicons dashicons-translation"></span>
						<?php esc_html_e( 'Translate to All Languages', 'voxfor-multilanguage' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get translation status for all languages
	 */
	private function getTranslationStatus( $post_id ) {
		$status       = array();
		$languages    = $this->plugin->getEnabledLanguages();
		$default_lang = $this->plugin->getDefaultLanguage();

		foreach ( $languages as $lang ) {
			if ( $lang === $default_lang ) {
				continue;
			}

			$status[ $lang ] = $this->getLanguageTranslationStatus( $post_id, $lang );
		}

		return $status;
	}

	/**
	 * Get translation status for a specific language
	 */
	private function getLanguageTranslationStatus( $post_id, $language ) {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'translated' => false );
		}

		// Check if translation exists in database
		$translation_table = $wpdb->prefix . 'voxfor_ml_translations';
		$has_translations  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `%1s` WHERE (source_text = %s OR source_text = %s) AND language_code = %s", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$translation_table,
				$post->post_title,
				$post->post_content,
				$language
			)
		);

		// Check memory for translations with proper context detection
		$title_translation   = false;
		$content_translation = false;

		// For WooCommerce products, check product-specific contexts
		if ( $post->post_type === 'product' ) {
			// Check product name with multiple contexts
			$title_contexts = array( 'product_name', 'title', 'text_fragment', 'general' );
			foreach ( $title_contexts as $context ) {
				$title_translation = $this->memory->getTranslation( $post->post_title, $language, $context );
				if ( $title_translation ) {
					break;
				}
			}

			// Check product description/short description
			$product = wc_get_product( $post_id );
			if ( $product ) {
				$short_desc = $product->get_short_description();
				$full_desc  = $product->get_description();

				$content_contexts = array( 'product_short_description', 'product_description', 'content', 'text_fragment' );
				foreach ( $content_contexts as $context ) {
					if ( $short_desc ) {
						$content_translation = $this->memory->getTranslation( $short_desc, $language, $context );
						if ( $content_translation ) {
							break;
						}
					}
					if ( ! $content_translation && $full_desc ) {
						$content_translation = $this->memory->getTranslation( $full_desc, $language, $context );
						if ( $content_translation ) {
							break;
						}
					}
				}
			}
		} else {
			// For regular posts/pages - check multiple contexts
			$title_contexts = array( 'title', 'post_title', 'text_fragment', 'general' );
			foreach ( $title_contexts as $context ) {
				$title_translation = $this->memory->getTranslation( $post->post_title, $language, $context );
				if ( $title_translation && $title_translation !== $post->post_title ) {
					break;
				}
			}

			$content_contexts = array( 'content', 'post_content', 'text_fragment', 'general' );
			foreach ( $content_contexts as $context ) {
				$content_translation = $this->memory->getTranslation( $post->post_content, $language, $context );
				if ( $content_translation && $content_translation !== $post->post_content ) {
					break;
				}
			}
		}

		// Check if in translation queue (if table exists)
		$queue_table = $wpdb->prefix . 'voxfor_ml_queue';
		$in_queue    = 0;

		// Check if queue table exists first
		$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $queue_table ) ) === $queue_table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $table_exists ) {
			$in_queue = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `%1s` WHERE post_id = %d AND language_code = %s AND status IN ('pending', 'processing')", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
					$queue_table,
					$post_id,
					$language
				)
			);
		}

		if ( $in_queue > 0 ) {
			return array(
				'translated'  => false,
				'in_progress' => true,
				'status_text' => __( 'Translation in progress', 'voxfor-multilanguage' ),
			);
		}

		if ( $has_translations > 0 || $title_translation || $content_translation ) {
			// Calculate completion percentage
			$total_segments      = 2; // title + content
			$translated_segments = ( $title_translation ? 1 : 0 ) + ( $content_translation ? 1 : 0 );
			$completion          = round( ( $translated_segments / $total_segments ) * 100 );

			// Get translated title
			$translated_title = $title_translation ?: $post->post_title;

			// Get last update time
			$translation_table = $wpdb->prefix . 'voxfor_ml_translations';
			$last_updated      = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT MAX(updated_at) FROM `%1s` WHERE source_hash IN (%s, %s) AND language_code = %s", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
					$translation_table,
					md5( $post->post_title ),
					md5( $post->post_content ),
					$language
				)
			);

			// Get URLs - correct parameter order: (url, language)
			$original_permalink = get_permalink( $post_id );

			$view_url = $this->router->getLanguageUrl( $original_permalink, $language );

			$edit_url = $view_url . '?voxfor_ml_edit=1';

			// Get translated slug if exists
			$translated_slug = $this->slug_manager->getSlug( $post_id, $language );

			return array(
				'translated'   => true,
				'title'        => $translated_title,
				'completion'   => $completion,
				'last_updated' => $last_updated,
				'view_url'     => $view_url,
				'edit_url'     => $edit_url,
				'slug'         => $translated_slug,
				'status_text'  => $completion === 100 ? __( 'Fully translated', 'voxfor-multilanguage' ) : __( 'Partially translated', 'voxfor-multilanguage' ),
			);
		}

		return array( 'translated' => false );
	}

	/**
	 * AJAX: Create translation
	 */
	public function ajaxCreateTranslation() {
		// Prevent any output that could corrupt JSON response
		// Note: Using WordPress-native error handling instead of ini_set for host compatibility

		// Clean any existing output
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		ob_start();

		// Verify nonce
		if ( ! check_ajax_referer( 'voxfor_ml_translation_status', 'nonce', false ) ) {
			ob_get_clean();
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'voxfor-multilanguage' ) ) );
			return;
		}

		// Check permissions
		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'voxfor-multilanguage' ) ) );
		}

		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

		if ( empty( $language ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid language', 'voxfor-multilanguage' ) ) );
		}

		try {
			// Get post data
			$post = get_post( $post_id );
			if ( ! $post ) {
				wp_send_json_error( array( 'message' => __( 'Post not found', 'voxfor-multilanguage' ) ) );
				return;
			}

			// Use the main translator which handles glossary rules properly
			$translator = $this->plugin->getComponent( 'translator' );
			if ( ! $translator ) {
				wp_send_json_error( array( 'message' => __( 'Translation system not available', 'voxfor-multilanguage' ) ) );
				return;
			}

			// Use testTranslation method for immediate translation with specific language
			$translated_title   = '';
			$translated_content = '';

			if ( ! empty( $post->post_title ) ) {
				$translated_title = $translator->testTranslation( $post->post_title, $language, 'post_title' );
			}

			if ( ! empty( $post->post_content ) ) {
				$translated_content = $translator->testTranslation( $post->post_content, $language, 'post_content' );
			}

			// Check if we got valid translations
			if ( ! empty( $translated_title ) || ! empty( $translated_content ) ) {
				// Clean any remaining output
				if ( ob_get_level() ) {
					ob_clean();
				}

				// Send clean response with explicit header
				header( 'Content-Type: application/json' );
				wp_send_json_success(
					array(
						'message' => __( 'Translation completed successfully', 'voxfor-multilanguage' ),
						'status'  => $this->getLanguageTranslationStatus( $post_id, $language ),
					)
				);
			} else {
				// Clean any remaining output
				if ( ob_get_level() ) {
					ob_clean();
				}

				header( 'Content-Type: application/json' );
				wp_send_json_error( array( 'message' => __( 'No content to translate or translation failed', 'voxfor-multilanguage' ) ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => 'Translation error: ' . $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Get translation status
	 */
	public function ajaxGetTranslationStatus() {
		// Verify nonce
		if ( ! check_ajax_referer( 'voxfor_ml_ajax', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'voxfor-multilanguage' ) ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID', 'voxfor-multilanguage' ) ) );
		}

		$status = $this->getTranslationStatus( $post_id );

		wp_send_json_success( $status );
	}

	/**
	 * AJAX: Translate to all languages
	 */
	public function ajaxTranslateAllLanguages() {
		// Verify nonce
		if ( ! check_ajax_referer( 'voxfor_ml_translation_status', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'voxfor-multilanguage' ) ) );
		}

		// Check permissions
		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'voxfor-multilanguage' ) ) );
		}

		try {
			// Get post data
			$post = get_post( $post_id );
			if ( ! $post ) {
				wp_send_json_error( array( 'message' => __( 'Post not found', 'voxfor-multilanguage' ) ) );
				return;
			}

			$languages    = $this->plugin->getEnabledLanguages();
			$default_lang = $this->plugin->getDefaultLanguage();
			$translator   = $this->plugin->getComponent( 'translator' );

			if ( ! $translator ) {
				wp_send_json_error( array( 'message' => __( 'Translation system not available', 'voxfor-multilanguage' ) ) );
				return;
			}

			$translated_count = 0;
			$total_languages  = 0;

			// Translate to each language except default
			foreach ( $languages as $language ) {
				if ( $language === $default_lang ) {
					continue;
				}

				++$total_languages;

				// Translate title and content
				$title_translated   = false;
				$content_translated = false;

				if ( ! empty( $post->post_title ) ) {
					$translated_title = $translator->testTranslation( $post->post_title, $language, 'post_title' );
					if ( ! empty( $translated_title ) ) {
						$title_translated = true;
					}
				}

				if ( ! empty( $post->post_content ) ) {
					$translated_content = $translator->testTranslation( $post->post_content, $language, 'post_content' );
					if ( ! empty( $translated_content ) ) {
						$content_translated = true;
					}
				}

				if ( $title_translated || $content_translated ) {
					++$translated_count;
				}
			}

			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %1$d is the number of languages translation completed for, %2$d is the total number of languages */
						__( 'Translation completed for %1$d of %2$d languages', 'voxfor-multilanguage' ),
						$translated_count,
						$total_languages
					),
					'status'  => $this->getTranslationStatus( $post_id ),
				)
			);

		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => 'Translation error: ' . $e->getMessage() ) );
		}
	}

	/**
	 * Get current edit language
	 */
	private function getCurrentEditLanguage() {
		// Check if we're editing a specific language version
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['lang'] ) ) {
			$lang = sanitize_text_field( wp_unslash( $_GET['lang'] ) );
			// Optional nonce soft-check: if provided, verify; if invalid, ignore requested lang
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( $nonce && ! wp_verify_nonce( $nonce, 'voxfor_ml_edit_lang' ) ) {
				$lang = '';
			}
			// Validate against enabled languages (whitelist)
			try {
				$enabled = $this->plugin->getEnabledLanguages();
				if ( $lang && is_array( $enabled ) && in_array( $lang, $enabled, true ) ) {
					return $lang;
				}
			} catch ( \Exception $e ) {
				// Fallback below
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Default to site language
		return $this->plugin->getDefaultLanguage();
	}

	/**
	 * Get language flag emoji or icon
	 */
	private function getLanguageFlag( $code ) {
		$flags = array(
			'en' => '🇬🇧',
			'he' => '🇮🇱',
			'fr' => '🇫🇷',
			'de' => '🇩🇪',
			'es' => '🇪🇸',
			'it' => '🇮🇹',
			'pt' => '🇵🇹',
			'ru' => '🇷🇺',
			'ja' => '🇯🇵',
			'zh' => '🇨🇳',
			'ar' => '🇸🇦',
			'nl' => '🇳🇱',
			'sv' => '🇸🇪',
			'no' => '🇳🇴',
			'da' => '🇩🇰',
			'fi' => '🇫🇮',
			'pl' => '🇵🇱',
			'tr' => '🇹🇷',
			'ko' => '🇰🇷',
			'hi' => '🇮🇳',
		);

		return isset( $flags[ $code ] ) ? $flags[ $code ] : '🌐';
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
			'nl' => 'Nederlands',
			'sv' => 'Svenska',
			'no' => 'Norsk',
			'da' => 'Dansk',
			'fi' => 'Suomi',
			'pl' => 'Polski',
			'tr' => 'Türkçe',
			'ko' => '한국어',
			'hi' => 'हिन्दी',
		);

		return isset( $languages[ $code ] ) ? $languages[ $code ] : strtoupper( $code );
	}
}