<?php
/**
 * Admin Settings Page Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


?>

<div class="wrap voxfor-ml-admin-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	
	<?php 
	settings_errors( 'voxfor_ml_messages' ); 
	settings_errors( 'voxfor_ml_settings' );
	?>
	
	<div class="voxfor-ml-settings-container">
		<div class="voxfor-ml-settings-main">
			<form method="post" action="options.php">
				<?php
				settings_fields( 'voxfor_ml_settings' );
				?>
				
				<!-- API Settings -->
				<div class="voxfor-ml-settings-section">
					<h2><?php esc_html_e( 'API Configuration', 'voxfor-multilanguage' ); ?></h2>
					
					<table class="voxfor-ml-form-table">
						<tr>
							<th scope="row">
								<label for="voxfor_ml_deepl_api_key"><?php esc_html_e( 'DeepL API Key', 'voxfor-multilanguage' ); ?></label>
							</th>
							<td>
								<?php
								$current_key = \VoxforML\Utils\EncryptionHandler::getApiKey();
								$masked_key  = ! empty( $current_key ) ? \VoxforML\Utils\EncryptionHandler::maskApiKey( $current_key ) : '';
								?>
								<input type="text" 
										id="voxfor_ml_deepl_api_key" 
										name="voxfor_ml_deepl_api_key" 
										value="<?php echo esc_attr( $masked_key ); ?>" 
										placeholder="<?php esc_attr_e( 'Enter your DeepL API key', 'voxfor-multilanguage' ); ?>"
										class="regular-text" 
										autocomplete="off" />
								<!-- Hidden field to track if user has entered a new key -->
								<input type="hidden" id="voxfor_ml_api_key_changed" name="voxfor_ml_api_key_changed" value="0" />
								<!-- Store original masked value for comparison -->
								<input type="hidden" id="voxfor_ml_api_key_original" name="voxfor_ml_api_key_original" value="<?php echo esc_attr( $masked_key ); ?>" />
								<button type="button" id="voxfor-ml-test-api-key" class="button button-secondary">
									<?php esc_html_e( 'Test API Key', 'voxfor-multilanguage' ); ?>
								</button>
								<div id="voxfor-ml-api-test-result" style="margin-top: 5px;"></div>
								<p class="voxfor-ml-description">
									<?php
									printf(
										/* translators: %s is a link to the DeepL Pro API website */
										esc_html__( 'Get your API key from %s', 'voxfor-multilanguage' ),
										'<a href="https://www.deepl.com/pro-api" target="_blank">DeepL Pro API</a>'
									);
									?>
									<?php if ( ! empty( $current_key ) ) : ?>
										<br><small style="color: #46b450;">
											<span class="dashicons dashicons-lock" style="font-size: 14px; vertical-align: middle;"></span>
											<?php esc_html_e( 'Current key is encrypted and secure. Leave empty to keep current key.', 'voxfor-multilanguage' ); ?>
										</small>
									<?php endif; ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row">
								<label><?php esc_html_e( 'Translation Service', 'voxfor-multilanguage' ); ?></label>
							</th>
							<td>
								<div class="voxfor-ml-service-info">
									<strong>DeepL Pro API</strong>
									<p class="voxfor-ml-description">
									<?php esc_html_e( 'Professional translation service with high accuracy and natural language processing.', 'voxfor-multilanguage' ); ?><br>
									<strong><?php esc_html_e( 'Free Plan:', 'voxfor-multilanguage' ); ?></strong> <?php esc_html_e( '500,000 characters/month', 'voxfor-multilanguage' ); ?><br>
									<strong><?php esc_html_e( 'Pro Plan:', 'voxfor-multilanguage' ); ?></strong> <?php esc_html_e( 'Unlimited characters, faster processing', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
				
				<!-- Language Settings -->
				<div class="voxfor-ml-settings-section">
					<h2><?php esc_html_e( 'Language Settings', 'voxfor-multilanguage' ); ?></h2>
					
					<table class="voxfor-ml-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Default Language Display Customization', 'voxfor-multilanguage' ); ?></th>
							<td>
								<?php
								// Get available languages for dropdown
								$admin_manager       = new \VoxforML\Admin\AdminManager();
								$available_languages = $admin_manager->getAvailableLanguagesForAdmin();

								// Get current values
								$current_label  = get_option( 'voxfor_ml_display_label', 'English' );
								$current_flag   = get_option( 'voxfor_ml_display_flag', '🇺🇸' );
								$current_prefix = get_option( 'voxfor_ml_display_prefix', 'en' );
								
								// Only set defaults if ALL values are empty (first time setup)
								if ( empty( $current_prefix ) && empty( $current_label ) && empty( $current_flag ) ) {
									$current_prefix = 'en';
									$current_label = 'English';
									$current_flag = '🇺🇸';
									update_option( 'voxfor_ml_display_prefix', 'en' );
									update_option( 'voxfor_ml_display_label', 'English' );
									update_option( 'voxfor_ml_display_flag', '🇺🇸' );
								}

								// Find the currently selected language code by matching current values
								$selected_language_code = '';
								
								foreach ( $available_languages as $code => $lang_data ) {
									// Primary match: prefix matches (most reliable)
									if ( $code === $current_prefix ) {
										$selected_language_code = $code;
										break; // Break on first prefix match since it's most reliable
									}
								}
								
								?>
								
								<table class="voxfor-ml-custom-display-table" style="width: 100%; border-collapse: collapse;">
									<tr>
										<td style="width: 120px; padding: 5px 10px 5px 0; vertical-align: middle;">
											<label for="voxfor_ml_language_selector"><?php esc_html_e( 'Select Language:', 'voxfor-multilanguage' ); ?></label>
										</td>
										<td style="padding: 5px 0;">
											<select id="voxfor_ml_language_selector" style="width: 250px;">
												<?php if ( empty( $selected_language_code ) ) : ?>
													<option value="" selected><?php esc_html_e( '-- Select a Language --', 'voxfor-multilanguage' ); ?></option>
												<?php else : ?>
													<option value=""><?php esc_html_e( '-- Select a Language --', 'voxfor-multilanguage' ); ?></option>
												<?php endif; ?>
												<?php foreach ( $available_languages as $code => $lang_data ) : ?>
													<option value="<?php echo esc_attr( $code ); ?>" 
															<?php selected( $selected_language_code, $code ); ?>
															data-label="<?php echo esc_attr( $lang_data['name'] ); ?>"
															data-native="<?php echo esc_attr( $lang_data['native'] ); ?>"
															data-flag="<?php echo esc_attr( $lang_data['flag'] ); ?>"
															data-prefix="<?php echo esc_attr( $code ); ?>">
														<?php echo esc_html( $lang_data['flag'] . ' ' . $lang_data['name'] . ' (' . $lang_data['native'] . ')' ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<p class="voxfor-ml-description" style="margin: 5px 0 0 0; font-size: 12px; color: #666;">
												<?php if ( ! empty( $selected_language_code ) ) : ?>
													<?php
													$current_lang_display = $available_languages[ $selected_language_code ];
												printf(
													/* translators: %1$s is the language flag emoji, %2$s is the language name */
													esc_html__( 'Currently set to: %1$s %2$s. Choose a different language or keep current selection.', 'voxfor-multilanguage' ),
													esc_html( $current_lang_display['flag'] ),
													esc_html( $current_lang_display['name'] )
												);
													?>
												<?php else : ?>
													<?php esc_html_e( 'Choose how you want the default language to appear in language switchers and SEO tags.', 'voxfor-multilanguage' ); ?>
												<?php endif; ?>
											</p>
											<p style="margin: 10px 0 0 0;">
												<a href="#" id="voxfor-ml-toggle-advanced" style="font-size: 12px; text-decoration: none;">
													<span class="dashicons dashicons-admin-generic" style="font-size: 12px; vertical-align: middle;"></span>
													<?php esc_html_e( 'Show Advanced Options', 'voxfor-multilanguage' ); ?>
												</a>
											</p>
										</td>
									</tr>
									<!-- Hidden input fields - auto-filled by dropdown selection -->
									<tr class="voxfor-ml-hidden-fields" style="display: none;">
										<td style="padding: 5px 10px 5px 0; vertical-align: middle;">
											<label for="voxfor_ml_display_label"><?php esc_html_e( 'Label:', 'voxfor-multilanguage' ); ?></label>
										</td>
										<td style="padding: 5px 0;">
											<input type="text" 
													id="voxfor_ml_display_label"
													name="voxfor_ml_display_label" 
													value="<?php echo esc_attr( $current_label ); ?>" 
													placeholder="e.g., Spanish, Español"
													style="width: 200px;" />
										</td>
									</tr>
									<tr class="voxfor-ml-hidden-fields" style="display: none;">
										<td style="padding: 5px 10px 5px 0; vertical-align: middle;">
											<label for="voxfor_ml_display_flag"><?php esc_html_e( 'Flag:', 'voxfor-multilanguage' ); ?></label>
										</td>
										<td style="padding: 5px 0;">
											<input type="text" 
													id="voxfor_ml_display_flag"
													name="voxfor_ml_display_flag" 
													value="<?php echo esc_attr( $current_flag ); ?>" 
													placeholder="e.g., 🇪🇸, 🇫🇷, 🇩🇪"
													style="width: 100px;" />
										</td>
									</tr>
									<tr class="voxfor-ml-hidden-fields" style="display: none;">
										<td style="padding: 5px 10px 5px 0; vertical-align: middle;">
											<label for="voxfor_ml_display_prefix"><?php esc_html_e( 'SEO Prefix:', 'voxfor-multilanguage' ); ?></label>
										</td>
										<td style="padding: 5px 0;">
											<input type="text" 
													id="voxfor_ml_display_prefix"
													name="voxfor_ml_display_prefix" 
													value="<?php echo esc_attr( $current_prefix ); ?>" 
													placeholder="e.g., es, fr, de"
													maxlength="5"
													style="width: 100px;" />
										</td>
									</tr>
								</table>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Customize how the default language appears in language switchers and SEO tags. This is for presentation only - all functionality remains the same.', 'voxfor-multilanguage' ); ?>
									<br>
									<strong><?php esc_html_e( 'Examples:', 'voxfor-multilanguage' ); ?></strong>
									<?php esc_html_e( 'Label: "Spanish" or "Español", Flag: "🇪🇸", SEO Prefix: "es"', 'voxfor-multilanguage' ); ?>
								</p>
								

							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Enabled Languages', 'voxfor-multilanguage' ); ?></th>
							<td>
								<div class="voxfor-ml-language-selector">
									<?php
									$enabled_languages = get_option( 'voxfor_ml_languages', array( 'en' ) );
									// Ensure $enabled_languages is always an array
									if ( ! is_array( $enabled_languages ) ) {
										$enabled_languages = array( 'en' );
									}
				$all_languages     = array(
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
					'sk' => 'Slovak',
					'sl' => 'Slovenian',
					'hu' => 'Hungarian',
					'ro' => 'Romanian',
					'bg' => 'Bulgarian',
					'el' => 'Greek',
					'et' => 'Estonian',
					'lv' => 'Latvian',
					'lt' => 'Lithuanian',
					'th' => 'Thai',
					'vi' => 'Vietnamese',
					'id' => 'Indonesian',
					'uk' => 'Ukrainian',
				);

									foreach ( $all_languages as $code => $name ) :
										$checked  = in_array( $code, $enabled_languages );
										$disabled = ( $code === 'en' ); // English is always enabled
										$extra_class = ( $code === 'en' ) ? ' voxfor-ml-english-option' : '';
										?>
										<label class="voxfor-ml-language-option <?php echo $checked ? 'selected' : ''; ?><?php echo esc_attr( $extra_class ); ?>">
											<input type="checkbox" 
													name="voxfor_ml_languages[]" 
													value="<?php echo esc_attr( $code ); ?>"
													<?php checked( $checked ); ?>
													<?php disabled( $disabled ); ?> />
											<?php echo esc_html( $name ); ?>
										</label>
									<?php endforeach; ?>
								</div>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Select the languages you want to make available on your site', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Auto-Redirect', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_auto_redirect" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_auto_redirect', false ) ); ?> />
									<?php esc_html_e( 'Automatically redirect visitors based on browser language', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'When enabled, first-time visitors will be redirected to their browser language if available', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
				
				<!-- SEO Settings -->
				<div class="voxfor-ml-settings-section">
					<h2><?php esc_html_e( 'SEO Settings', 'voxfor-multilanguage' ); ?></h2>
					
					<table class="voxfor-ml-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Hreflang Tags', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_enable_hreflang" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_enable_hreflang', true ) ); ?> />
									<?php esc_html_e( 'Enable automatic hreflang tag generation', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Recommended for SEO. Helps search engines understand language relationships', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Image ALT Text', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_translate_image_alt" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_translate_image_alt', true ) ); ?> />
									<?php esc_html_e( 'Translate image ALT text for better SEO', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Improves image search visibility in different languages', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'URL Slugs', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_translate_slugs" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_translate_slugs', false ) ); ?> />
									<?php esc_html_e( 'Translate URL slugs', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Creates localized URLs for each language (experimental)', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Sitemap Tags', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_include_post_tags_sitemap" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_include_post_tags_sitemap', false ) ); ?> />
									<?php esc_html_e( 'Include Post Tags in sitemaps', 'voxfor-multilanguage' ); ?>
								</label>
								<br><br>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_include_product_tags_sitemap" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_include_product_tags_sitemap', false ) ); ?> />
									<?php esc_html_e( 'Include Product Tags in sitemaps', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Control whether post tags and product tags appear in your multilingual sitemaps. Disabled by default to match SEO best practices.', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'SEO Protection', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_noindex_preparing" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_noindex_preparing', true ) ); ?> />
									<?php esc_html_e( 'Add noindex to pages while translation is being prepared', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Prevents search engines from indexing incomplete translations (recommended)', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Translation Mode', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_immediate_translation" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_immediate_translation', false ) ); ?> />
									<?php esc_html_e( 'Enable immediate translation (for testing)', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<strong style="color: #d63638;"><?php esc_html_e( '⚠️ WARNING:', 'voxfor-multilanguage' ); ?></strong>
									<?php esc_html_e( 'This setting will consume API credits when users switch languages! Only enable for testing. For production, use the Translation menu to pre-translate content.', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
				
				<!-- Widget Settings -->
				<div class="voxfor-ml-settings-section">
					<h2><?php esc_html_e( 'Language Switcher Settings', 'voxfor-multilanguage' ); ?></h2>
					
					<table class="voxfor-ml-form-table">
						<tr>
							<th scope="row">
								<label for="voxfor_ml_widget_style"><?php esc_html_e( 'Default Style', 'voxfor-multilanguage' ); ?></label>
							</th>
							<td>
								<select id="voxfor_ml_widget_style" name="voxfor_ml_widget_style">
									<option value="dropdown" <?php selected( get_option( 'voxfor_ml_widget_style', 'dropdown' ), 'dropdown' ); ?>>
										<?php esc_html_e( 'Dropdown', 'voxfor-multilanguage' ); ?>
									</option>
								</select>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Language switcher will be displayed as a dropdown menu', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Display Options', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_show_flags" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_show_flags', true ) ); ?> />
									<?php esc_html_e( 'Show flag icons', 'voxfor-multilanguage' ); ?>
								</label>
								<br>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_show_native_names" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_show_native_names', true ) ); ?> />
									<?php esc_html_e( 'Show native language names', 'voxfor-multilanguage' ); ?>
								</label>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Floating Switcher', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_floating_switcher" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_floating_switcher', true ) ); ?> />
									<?php esc_html_e( 'Enable floating language switcher', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Shows a floating button for easy language switching', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Floating Position', 'voxfor-multilanguage' ); ?></th>
							<td>
								<select id="voxfor_ml_floating_position" name="voxfor_ml_floating_position">
									<option value="bottom-right" <?php selected( get_option( 'voxfor_ml_floating_position', 'bottom-right' ), 'bottom-right' ); ?>>
										<?php esc_html_e( 'Bottom Right', 'voxfor-multilanguage' ); ?>
									</option>
									<option value="bottom-left" <?php selected( get_option( 'voxfor_ml_floating_position', 'bottom-right' ), 'bottom-left' ); ?>>
										<?php esc_html_e( 'Bottom Left', 'voxfor-multilanguage' ); ?>
									</option>
								</select>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Choose where the floating language switcher appears on your website', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
				
				<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<!-- WooCommerce Settings -->
				<div class="voxfor-ml-settings-section">
					<h2><?php esc_html_e( 'WooCommerce Integration', 'voxfor-multilanguage' ); ?></h2>
					
					<table class="voxfor-ml-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Product Translation', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_wc_translate_products" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_wc_translate_products', true ) ); ?> />
									<?php esc_html_e( 'Enable automatic product translation', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Automatically translate product titles, descriptions, and short descriptions', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Product Categories', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_wc_translate_categories" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_wc_translate_categories', true ) ); ?> />
									<?php esc_html_e( 'Translate product categories and tags', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Translate category names, descriptions, and product tags', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Product Attributes', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_wc_translate_attributes" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_wc_translate_attributes', false ) ); ?> />
									<?php esc_html_e( 'Translate product attributes and variations', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Translate attribute names and values (experimental)', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Shop Pages', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_wc_translate_shop_pages" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_wc_translate_shop_pages', true ) ); ?> />
									<?php esc_html_e( 'Translate WooCommerce shop pages', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Translate shop, cart, checkout, and account pages', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Currency Display', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_wc_preserve_currency" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_wc_preserve_currency', true ) ); ?> />
									<?php esc_html_e( 'Preserve currency symbols and formatting', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Keep prices and currency symbols unchanged during translation', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Cart Persistence', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_wc_preserve_cart" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_wc_preserve_cart', true ) ); ?> />
									<?php esc_html_e( 'Preserve cart contents when switching languages', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Keep items in cart when users switch languages (recommended)', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
				<?php endif; ?>
				
				<!-- API Management Settings -->
				<div class="voxfor-ml-settings-section">
					<h2><?php esc_html_e( 'API Management', 'voxfor-multilanguage' ); ?></h2>
					
					<table class="voxfor-ml-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'API Status', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_api_enabled" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_api_enabled', true ) ); ?> />
									<?php esc_html_e( 'Enable API translations', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Pause/Resume API translation requests. When disabled, no new translations will be processed.', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row">
								<label for="voxfor_ml_daily_credit_limit"><?php esc_html_e( 'Daily Credit Limit', 'voxfor-multilanguage' ); ?></label>
							</th>
							<td>
								<input type="number" 
										id="voxfor_ml_daily_credit_limit"
										name="voxfor_ml_daily_credit_limit" 
										value="<?php echo esc_attr( get_option( 'voxfor_ml_daily_credit_limit', 100000 ) ); ?>" 
										min="0" 
										max="10000000" />
								<span><?php esc_html_e( 'characters', 'voxfor-multilanguage' ); ?></span>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Maximum characters to translate per day. Set to 0 for unlimited.', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row">
								<label for="voxfor_ml_monthly_credit_limit"><?php esc_html_e( 'Monthly Credit Limit', 'voxfor-multilanguage' ); ?></label>
							</th>
							<td>
								<input type="number" 
										id="voxfor_ml_monthly_credit_limit"
										name="voxfor_ml_monthly_credit_limit" 
										value="<?php echo esc_attr( get_option( 'voxfor_ml_monthly_credit_limit', 500000 ) ); ?>" 
										min="0" 
										max="100000000" />
								<span><?php esc_html_e( 'characters', 'voxfor-multilanguage' ); ?></span>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Maximum characters to translate per month. Set to 0 for unlimited.', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Usage Alerts', 'voxfor-multilanguage' ); ?></th>
							<td>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_alert_daily_80" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_alert_daily_80', true ) ); ?> />
									<?php esc_html_e( 'Alert at 80% daily usage', 'voxfor-multilanguage' ); ?>
								</label>
								<br>
								<label>
									<input type="checkbox" 
											name="voxfor_ml_alert_monthly_80" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_alert_monthly_80', true ) ); ?> />
									<?php esc_html_e( 'Alert at 80% monthly usage', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Receive notifications when API usage reaches high levels.', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php esc_html_e( 'Usage Statistics', 'voxfor-multilanguage' ); ?></th>
							<td>
								<?php
								$credit_manager = \VoxforML\Core\Plugin::getInstance()->getComponent( 'api_credit_manager' );
								if ( $credit_manager ) {
									$daily_usage   = $credit_manager->getDailyUsage();
									$monthly_usage = $credit_manager->getMonthlyUsage();
									$daily_limit   = get_option( 'voxfor_ml_daily_credit_limit', 100000 );
									$monthly_limit = get_option( 'voxfor_ml_monthly_credit_limit', 500000 );

									$daily_percentage   = $daily_limit > 0 ? round( ( $daily_usage / $daily_limit ) * 100, 1 ) : 0;
									$monthly_percentage = $monthly_limit > 0 ? round( ( $monthly_usage / $monthly_limit ) * 100, 1 ) : 0;
									?>
									<div class="voxfor-ml-usage-stats">
										<div class="usage-bar">
										<label><?php esc_html_e( 'Daily Usage:', 'voxfor-multilanguage' ); ?></label>
										<div class="progress-bar">
											<div class="progress" style="width: <?php echo esc_attr( min( $daily_percentage, 100 ) ); ?>%"></div>
										</div>
										<span><?php echo number_format( $daily_usage ); ?> / <?php echo $daily_limit > 0 ? number_format( $daily_limit ) : '∞'; ?> (<?php echo esc_html( $daily_percentage ); ?>%)</span>
										</div>
										
										<div class="usage-bar">
										<label><?php esc_html_e( 'Monthly Usage:', 'voxfor-multilanguage' ); ?></label>
										<div class="progress-bar">
											<div class="progress" style="width: <?php echo esc_attr( min( $monthly_percentage, 100 ) ); ?>%"></div>
										</div>
										<span><?php echo number_format( $monthly_usage ); ?> / <?php echo $monthly_limit > 0 ? number_format( $monthly_limit ) : '∞'; ?> (<?php echo esc_html( $monthly_percentage ); ?>%)</span>
										</div>
									</div>
								<?php } else { ?>
									<p class="voxfor-ml-description"><?php esc_html_e( 'Usage statistics unavailable', 'voxfor-multilanguage' ); ?></p>
								<?php } ?>
							</td>
						</tr>
					</table>
				</div>
				
				<!-- Performance Settings -->
				<div class="voxfor-ml-settings-section">
					<h2><?php esc_html_e( 'Performance Settings', 'voxfor-multilanguage' ); ?></h2>
					
					<table class="voxfor-ml-form-table">
						<tr>
							<th scope="row">
								<label for="voxfor_ml_enable_object_cache"><?php esc_html_e( 'Object Caching', 'voxfor-multilanguage' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" 
											id="voxfor_ml_enable_object_cache"
											name="voxfor_ml_enable_object_cache" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_enable_object_cache', true ) ); ?> />
									<?php esc_html_e( 'Enable object caching for translations', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Improves performance by caching translations in memory. Recommended if you have Redis or Memcached.', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row">
								<label for="voxfor_ml_enable_lazy_loading"><?php esc_html_e( 'Lazy Loading', 'voxfor-multilanguage' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" 
											id="voxfor_ml_enable_lazy_loading"
											name="voxfor_ml_enable_lazy_loading" 
											value="1" 
											<?php checked( get_option( 'voxfor_ml_enable_lazy_loading', true ) ); ?> />
									<?php esc_html_e( 'Enable lazy loading for images', 'voxfor-multilanguage' ); ?>
								</label>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'Delays loading of images until they are needed, improving page load speed.', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row">
								<label for="voxfor_ml_cache_ttl"><?php esc_html_e( 'Cache TTL', 'voxfor-multilanguage' ); ?></label>
							</th>
							<td>
								<input type="number" 
										id="voxfor_ml_cache_ttl"
										name="voxfor_ml_cache_ttl" 
										value="<?php echo esc_attr( get_option( 'voxfor_ml_cache_ttl', 3600 ) ); ?>" 
										min="60" 
										max="86400" />
								<span><?php esc_html_e( 'seconds', 'voxfor-multilanguage' ); ?></span>
								<p class="voxfor-ml-description">
									<?php esc_html_e( 'How long to cache translations in memory (60-86400 seconds).', 'voxfor-multilanguage' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
				
				<?php submit_button( __( 'Save Changes', 'voxfor-multilanguage' ), 'primary voxfor-ml-save-settings', 'submit' ); ?>
			</form>
		</div>
		
		<div class="voxfor-ml-settings-sidebar">
			<!-- Help Box -->
			<div class="voxfor-ml-tool-box">
				<h3><?php esc_html_e( 'Need Help?', 'voxfor-multilanguage' ); ?></h3>
				<p><?php esc_html_e( 'Check out our documentation for detailed setup instructions and best practices.', 'voxfor-multilanguage' ); ?></p>
				<a href="https://voxfor.com/docs/multilanguage" target="_blank" class="button">
					<?php esc_html_e( 'View Documentation', 'voxfor-multilanguage' ); ?>
				</a>
			</div>
			
		</div>
	</div>
</div>

<?php
// Settings JavaScript is now in public/js/admin/settings.js
// Strings and nonce are passed via wp_localize_script as voxforSettings object
?>

<?php
// Settings CSS is now in public/css/admin/settings.css
// Styles are properly enqueued via wp_enqueue_style
?>