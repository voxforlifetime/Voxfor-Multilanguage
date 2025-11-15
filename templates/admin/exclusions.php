<?php
/**
 * Exclusions Management Template
 *
 * @var array $rules
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap voxfor-ml-admin-wrap">
	<h1>
		<?php echo esc_html( get_admin_page_title() ); ?>
		<a href="#add-new-rule" class="page-title-action"><?php esc_html_e( 'Add New Rule', 'voxfor-multilanguage' ); ?></a>
	</h1>
	
	<?php settings_errors( 'voxfor_ml_messages' ); ?>
	
	<?php if ( isset( $_GET['cleaned_translations'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				$count = isset( $_GET['cleaned_translations'] ) ? intval( $_GET['cleaned_translations'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( $count > 0 ) {
					printf(
						/* translators: %d is the number of deleted translations */
						esc_html( _n(
							'🧹 Successfully deleted %d translation for excluded URLs.',
							'🧹 Successfully deleted %d translations for excluded URLs.',
							$count,
							'voxfor-multilanguage'
						) ),
						intval( $count )
					);
				} else {
					esc_html_e( '🧹 No translations found for excluded URLs.', 'voxfor-multilanguage' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>
	
	<div class="voxfor-ml-exclusions-container">
		<!-- Existing Rules -->
		<div class="voxfor-ml-exclusions-list">
			<h2><?php esc_html_e( 'Exclusion Rules', 'voxfor-multilanguage' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Define rules to exclude specific content from translation. These rules prevent content from being sent to the translation API.', 'voxfor-multilanguage' ); ?>
			</p>
			
			<?php if ( ! empty( $rules ) ) : ?>
				<?php
				$rules_by_type = array();
				foreach ( $rules as $rule ) {
					$rules_by_type[ $rule->rule_type ][] = $rule;
				}
				?>
				
				<?php foreach ( $rules_by_type as $type => $type_rules ) : ?>
					<div class="voxfor-ml-rule-type-section">
						<h3>
							<?php
							$type_labels = array(
								'css'       => __( 'CSS Selectors', 'voxfor-multilanguage' ),
								'namespace' => __( 'Namespaces', 'voxfor-multilanguage' ),
							);
							echo esc_html( $type_labels[ $type ] ?? $type );
							?>
						</h3>
						
						<div class="voxfor-ml-rules-list">
							<?php foreach ( $type_rules as $rule ) : ?>
								<div class="voxfor-ml-rule-item <?php echo ! $rule->is_active ? 'inactive' : ''; ?>">
									<div class="voxfor-ml-rule-content">
										<span class="voxfor-ml-rule-type"><?php echo esc_html( $type ); ?></span>
										<code class="voxfor-ml-rule-value"><?php echo esc_html( $rule->rule_value ); ?></code>
										<?php if ( $rule->description ) : ?>
											<span class="voxfor-ml-rule-description"><?php echo esc_html( $rule->description ); ?></span>
										<?php endif; ?>
									</div>
									<div class="voxfor-ml-rule-actions">
										<label class="voxfor-ml-toggle-switch">
											<input type="checkbox" 
													class="voxfor-ml-rule-toggle" 
													data-id="<?php echo esc_attr( $rule->id ); ?>"
													<?php checked( $rule->is_active ); ?> />
											<span class="voxfor-ml-toggle-slider"></span>
										</label>
										<button class="button button-small voxfor-ml-edit-rule" 
												data-id="<?php echo esc_attr( $rule->id ); ?>"
												data-type="<?php echo esc_attr( $rule->rule_type ); ?>"
												data-value="<?php echo esc_attr( $rule->rule_value ); ?>"
												data-description="<?php echo esc_attr( $rule->description ); ?>">
											<?php esc_html_e( 'Edit', 'voxfor-multilanguage' ); ?>
										</button>
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=voxfor_ml_delete_exclusion&id=' . $rule->id ), 'voxfor_ml_delete_exclusion' ) ); ?>" 
											class="button button-small button-link-delete voxfor-ml-delete"
											onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this rule?', 'voxfor-multilanguage' ); ?>');">
											<?php esc_html_e( 'Delete', 'voxfor-multilanguage' ); ?>
										</a>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'No exclusion rules found. Add your first rule below.', 'voxfor-multilanguage' ); ?></p>
			<?php endif; ?>
		</div>
		
		<!-- Add/Edit Form -->
		<div class="voxfor-ml-exclusion-form" id="add-new-rule">
			<h2><?php esc_html_e( 'Add New Exclusion Rule', 'voxfor-multilanguage' ); ?></h2>
			
			<form method="post" action="">
				<?php wp_nonce_field( 'voxfor_ml_exclusion_action', 'voxfor_ml_exclusion_nonce' ); ?>
				<input type="hidden" name="add_exclusion_rule" value="1" />
				<input type="hidden" name="rule_id" id="edit-rule-id" value="" />
				
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="rule_type"><?php esc_html_e( 'Rule Type', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<select id="rule_type" name="rule_type" required>
								<option value=""><?php esc_html_e( 'Select Type', 'voxfor-multilanguage' ); ?></option>
								<option value="css"><?php esc_html_e( 'CSS Selector', 'voxfor-multilanguage' ); ?></option>
								<option value="namespace"><?php esc_html_e( 'Namespace', 'voxfor-multilanguage' ); ?></option>
							</select>
							<div class="voxfor-ml-rule-type-help">
								<p class="description" data-type="css" style="display: none;">
									<?php esc_html_e( 'CSS selectors for elements to exclude. Example: .no-translate, #header', 'voxfor-multilanguage' ); ?>
								</p>
								<p class="description" data-type="namespace" style="display: none;">
									<?php esc_html_e( 'Context namespaces to exclude. Example: woocommerce_checkout, admin_notices', 'voxfor-multilanguage' ); ?>
								</p>
							</div>
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="rule_value"><?php esc_html_e( 'Rule Value', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<input type="text" 
									id="rule_value" 
									name="rule_value" 
									class="regular-text code" 
									required />
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="description"><?php esc_html_e( 'Description', 'voxfor-multilanguage' ); ?></label>
						</th>
						<td>
							<input type="text" 
									id="description" 
									name="description" 
									class="regular-text" />
							<p class="description">
								<?php esc_html_e( 'Optional description to help identify this rule', 'voxfor-multilanguage' ); ?>
							</p>
						</td>
					</tr>
				</table>
				
				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Add Exclusion Rule', 'voxfor-multilanguage' ); ?>
					</button>
					<button type="button" class="button" id="cancel-edit" style="display: none;">
						<?php esc_html_e( 'Cancel', 'voxfor-multilanguage' ); ?>
					</button>
				</p>
			</form>
		</div>
		
		<!-- Common Exclusions -->
		<div class="voxfor-ml-common-exclusions">
			<h2><?php esc_html_e( 'Quick Add Common Exclusions', 'voxfor-multilanguage' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Click to quickly add commonly used exclusion rules.', 'voxfor-multilanguage' ); ?>
			</p>
			
			<div style="margin-bottom: 20px;">
				<button type="button" class="button button-primary" id="seed-common-exclusions">
					<?php esc_html_e( '🚀 Add All Recommended Exclusions', 'voxfor-multilanguage' ); ?>
				</button>
				<p class="description">
					<?php esc_html_e( 'Automatically adds exclusions for WooCommerce, page builders, and technical elements.', 'voxfor-multilanguage' ); ?>
				</p>
			</div>
			
			<div class="voxfor-ml-quick-rules">
				<button class="button voxfor-ml-quick-add" 
						data-type="css" 
						data-value=".no-translate" 
						data-description="<?php esc_attr_e( 'Elements with no-translate class', 'voxfor-multilanguage' ); ?>">
					<?php esc_html_e( 'No Translate Class', 'voxfor-multilanguage' ); ?>
				</button>
				
				<button class="button voxfor-ml-quick-add" 
						data-type="css" 
						data-value="code, pre" 
						data-description="<?php esc_attr_e( 'Code blocks', 'voxfor-multilanguage' ); ?>">
					<?php esc_html_e( 'Code Blocks', 'voxfor-multilanguage' ); ?>
				</button>
				
				<button class="button voxfor-ml-quick-add" 
						data-type="css" 
						data-value="script, style" 
						data-description="<?php esc_attr_e( 'Script and style tags', 'voxfor-multilanguage' ); ?>">
					<?php esc_html_e( 'Scripts & Styles', 'voxfor-multilanguage' ); ?>
				</button>
				
				<button class="button voxfor-ml-quick-add" 
						data-type="css" 
						data-value=".woocommerce-checkout" 
						data-description="<?php esc_attr_e( 'WooCommerce checkout form', 'voxfor-multilanguage' ); ?>">
					<?php esc_html_e( 'WooCommerce Checkout', 'voxfor-multilanguage' ); ?>
				</button>
				
				<button class="button voxfor-ml-quick-add" 
						data-type="namespace" 
						data-value="woocommerce_checkout" 
						data-description="<?php esc_attr_e( 'WooCommerce checkout namespace', 'voxfor-multilanguage' ); ?>">
					<?php esc_html_e( 'Checkout Namespace', 'voxfor-multilanguage' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>

<?php
// Exclusions styles are now in public/css/admin/exclusions.css
// Styles are properly enqueued via wp_enqueue_style
?>

<?php
// Exclusions JavaScript is now in public/js/admin/exclusions.js
// Script is properly enqueued via wp_enqueue_script with localization
?>
