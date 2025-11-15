jQuery(document).ready(function($) {
	let translationInProgress = false;
	let translationCancelled = false;
	let selectedContentType = '';
	let selectedContentIds = [];
	let currentPage = 1;
	let currentFilters = {};
	let individualTranslationInProgress = false;

	// Check if we're on the comprehensive translator page
	if (typeof voxforComprehensive === 'undefined') {
		return; // Exit if localization data is not available
	}

	const strings = voxforComprehensive.strings;
	const nonce = voxforComprehensive.nonce;
	const ajaxUrl = voxforComprehensive.ajaxUrl;

	// Start comprehensive translation button
	$('#start-comprehensive-translate-btn').on('click', function() {
		const $btn = $(this);
		const $cancelBtn = $('#cancel-comprehensive-translate-btn');
		const $result = $('#comprehensive-result');
		const $progress = $('#comprehensive-translation-progress');
		const $progressBar = $('#comprehensive-progress-bar');
		const $status = $('#comprehensive-status');
		const $percentage = $('#progress-percentage');
		const $currentStep = $('#progress-current-step');
		const $progressLog = $('#progress-log');
		
		const selectedLanguages = [];
		$('input[name="comprehensive_languages[]"]:checked').each(function() {
			selectedLanguages.push($(this).val());
		});
		
		if (selectedLanguages.length === 0) {
			alert(strings.selectLanguage);
			return;
		}
		
		if (!confirm(strings.confirmTranslation)) {
			return;
		}
		
		// Reset state
		translationInProgress = true;
		translationCancelled = false;
		
		// Update UI
		$btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + strings.translating);
		$cancelBtn.show();
		$progress.show();
		$progressBar.css('width', '0%');
		$percentage.text('0%');
		$status.text(strings.starting);
		$result.empty();
		$progressLog.empty();
		
		// Start comprehensive translation
		startComprehensiveTranslation(selectedLanguages, $btn, $cancelBtn, $result, $progressBar, $status, $percentage, $currentStep, $progressLog);
	});
	
	// Cancel translation button
	$('#cancel-comprehensive-translate-btn').on('click', function() {
		if (!confirm(strings.confirmCancel)) {
			return;
		}
		
		translationCancelled = true;
		
		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'voxfor_ml_cancel_comprehensive_translate',
				nonce: nonce
			},
			success: function(response) {
				if (response.success) {
					$('#comprehensive-status').text(strings.cancelled);
					$('#comprehensive-result').html('<div class="notice notice-warning"><p>' + strings.cancelledMessage + '</p></div>');
					resetTranslationUI();
				}
			}
		});
	});
	
	function startComprehensiveTranslation(languages, $btn, $cancelBtn, $result, $progressBar, $status, $percentage, $currentStep, $progressLog) {
		let currentStep = 0;
		const totalSteps = languages.length * 6; // 6 steps per language
		
		function updateProgress(step, message, logMessage = null) {
			if (translationCancelled) return;
			
			const percent = Math.round((step / totalSteps) * 100);
			$progressBar.css('width', percent + '%');
			$percentage.text(percent + '%');
			$status.text(message);
			$currentStep.text('Step ' + step + ' of ' + totalSteps);
			
			if (logMessage) {
				$progressLog.append('<li>' + new Date().toLocaleTimeString() + ': ' + logMessage + '</li>');
				$progressLog.scrollTop($progressLog[0].scrollHeight);
			}
		}
		
		function processLanguage(langIndex) {
			if (translationCancelled) return;
			
			if (langIndex >= languages.length) {
				// All languages completed
				$result.html('<div class="notice notice-success"><p><strong>' + strings.completed + '</strong><br>' + strings.completedMessage + '</p></div>');
				resetTranslationUI();
				return;
			}
			
			const language = languages[langIndex];
			const steps = [
				strings.scanningHomepage,
				strings.processingWooCommerce,
				strings.processingElementor,
				strings.processingTheme,
				strings.processingWidgets,
				strings.finalizingTranslations
			];
			
			function processStep(stepIndex) {
				if (translationCancelled) return;
				
				if (stepIndex >= steps.length) {
					// Language completed, move to next
					updateProgress(currentStep, strings.languageCompleted, 'Completed ' + language.toUpperCase());
					processLanguage(langIndex + 1);
					return;
				}
				
				currentStep++;
				const message = steps[stepIndex] + ' for ' + language.toUpperCase();
				updateProgress(currentStep, message, message);
				
				$.ajax({
					url: ajaxUrl,
					type: 'POST',
					data: {
						action: 'voxfor_ml_comprehensive_translate',
						nonce: nonce,
						language: language,
						step: stepIndex
					},
					success: function(response) {
						if (translationCancelled) return;
						
						if (response.success) {
							setTimeout(() => processStep(stepIndex + 1), 500);
						} else {
							$result.html('<div class="notice notice-error"><p><strong>' + strings.translationFailed + '</strong> ' + (response.data || strings.unknownError) + '</p></div>');
							resetTranslationUI();
						}
					},
					error: function() {
						if (translationCancelled) return;
						
						$result.html('<div class="notice notice-error"><p><strong>' + strings.networkError + '</strong></p></div>');
						resetTranslationUI();
					}
				});
			}
			
			processStep(0);
		}
		
		processLanguage(0);
	}
	
	function resetTranslationUI() {
		translationInProgress = false;
		$('#start-comprehensive-translate-btn').prop('disabled', false).html('<span class="dashicons dashicons-translation"></span> ' + strings.startTranslation);
		$('#cancel-comprehensive-translate-btn').hide();
	}

	// Individual Content Translation functionality would go here
	// (This is a simplified version - the full implementation would include all the individual translation logic)
	
	// Load content types on page load if individual translation section exists
	if ($('#content-types-grid').length) {
		loadContentTypes();
	}
	

	
	function loadContentTypes() {
		const $grid = $('#content-types-grid');
		$grid.html('<div class="voxfor-ml-loading">' + strings.loading + '</div>');
		
		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'voxfor_ml_get_content_types',
				nonce: nonce
			},
			success: function(response) {
				if (response.success && response.data) {
					$grid.empty();
					
					response.data.forEach(function(type) {
						const iconClass = type.icon || 'dashicons-admin-post';
						const $card = $('<div class="voxfor-ml-content-type-card" data-type="' + type.value + '">' +
							'<div class="voxfor-ml-content-type-icon"><span class="dashicons ' + iconClass + '"></span></div>' +
							'<div class="voxfor-ml-content-type-label">' + type.label + '</div>' +
							'<div class="voxfor-ml-content-type-count">' + type.count + ' items</div>' +
						'</div>');
						$grid.append($card);
					});
				} else {
					$grid.html('<div class="notice notice-error"><p>' + strings.errorLoadingContent + '</p></div>');
				}
			},
			error: function(xhr, status, error) {
				$grid.html('<div class="notice notice-error"><p>' + strings.errorLoadingContent + '</p></div>');
			}
		});
	}

	// Content type card click handler
	$(document).on('click', '.voxfor-ml-content-type-card', function() {
		// Remove active class from all cards
		$('.voxfor-ml-content-type-card').removeClass('active');
		
		// Add active class to clicked card
		$(this).addClass('active');
		
		selectedContentType = $(this).data('type');
		if (selectedContentType) {
			$('#content-browser').show();
			$('#individual-translation-settings').show();
			$('#individual-translation-actions').show();
			loadContentList();
			loadFilterOptions();
		}
	});

	function loadContentList() {
		const $list = $('#individual-content-list');
		$list.html('<div class="voxfor-ml-loading">' + strings.loading + '</div>');
		
		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'voxfor_ml_get_content_list',
				nonce: nonce,
				content_type: selectedContentType,
				page: currentPage,
				filters: currentFilters
			},
			success: function(response) {
				if (response.success && response.data) {
					renderContentList(response.data);
				} else {
					$list.html('<div class="notice notice-error"><p>' + (response.data || strings.errorLoadingContent) + '</p></div>');
				}
			},
			error: function(xhr, status, error) {
				$list.html('<div class="notice notice-error"><p>' + strings.networkError + '</p></div>');
			}
		});
	}

	function renderContentList(data) {
		const $list = $('#individual-content-list');
		let html = '';
		
		if (data.items && data.items.length > 0) {
			html += '<div class="voxfor-ml-content-list">';
			data.items.forEach(function(item) {
				html += '<div class="voxfor-ml-content-item">';
				html += '<label><input type="checkbox" value="' + item.id + '"> ' + item.title + '</label>';
				html += '<div class="voxfor-ml-content-item-meta">';
				html += '<span class="voxfor-ml-content-type">' + item.type.toUpperCase() + '</span>';
				
				// Show translation status
				if (item.translation_status) {
					const statusKeys = Object.keys(item.translation_status);
					if (statusKeys.length > 0) {
						const translatedCount = statusKeys.filter(lang => item.translation_status[lang] === 'translated').length;
						const totalCount = statusKeys.length;
						const statusClass = translatedCount === totalCount ? 'fully-translated' : 
										   translatedCount > 0 ? 'partially-translated' : 'not-translated';
						const statusText = translatedCount === totalCount ? 'TRANSLATED' : 
										  translatedCount > 0 ? 'PARTIAL' : 'NOT TRANSLATED';
						html += '<span class="voxfor-ml-content-status ' + statusClass + '">' + statusText + '</span>';
					} else {
						html += '<span class="voxfor-ml-content-status not-translated">NOT TRANSLATED</span>';
					}
				} else {
					html += '<span class="voxfor-ml-content-status not-translated">NOT TRANSLATED</span>';
				}
				
				html += '</div>';
				html += '</div>';
			});
			html += '</div>';
			
			// Add pagination if needed
			if (data.total_pages > 1) {
				html += '<div class="voxfor-ml-pagination">';
				for (let i = 1; i <= data.total_pages; i++) {
					const activeClass = i === currentPage ? ' active' : '';
					html += '<button class="voxfor-ml-page-btn' + activeClass + '" data-page="' + i + '">' + i + '</button>';
				}
				html += '</div>';
			}
		} else {
			html = '<div class="notice notice-info"><p>' + strings.noContentFound + '</p></div>';
		}
		
		$list.html(html);
	}

	// Pagination handler
	$(document).on('click', '.voxfor-ml-page-btn', function() {
		currentPage = parseInt($(this).data('page'));
		loadContentList();
	});

	// Select all/none functionality
	$(document).on('change', '#select-all-content', function() {
		const isChecked = $(this).is(':checked');
		$('.voxfor-ml-content-item input[type="checkbox"]').prop('checked', isChecked);
		updateSelectedCount();
	});

	$(document).on('change', '.voxfor-ml-content-item input[type="checkbox"]', function() {
		updateSelectedCount();
	});

	function updateSelectedCount() {
		const count = $('.voxfor-ml-content-item input[type="checkbox"]:checked').length;
		$('#selected-count').text(count + ' ' + strings.selected || 'selected');
		$('#start-individual-translate-btn').prop('disabled', count === 0);
	}
	
	function loadFilterOptions() {
		// This would load filter options based on the selected content type
		// For now, we'll just show the filters container
		$('#individual-filters').show();
	}
	
	// Individual translation button handler
	$('#start-individual-translate-btn').on('click', function() {
		const $btn = $(this);
		const $cancelBtn = $('#cancel-individual-translate-btn');
		const $progress = $('#individual-translation-progress');
		
		// Get selected content IDs
		const selectedIds = [];
		$('.voxfor-ml-content-item input[type="checkbox"]:checked').each(function() {
			selectedIds.push(parseInt($(this).val()));
		});
		
		if (selectedIds.length === 0) {
			alert('Please select at least one item to translate.');
			return;
		}
		
		// Get selected languages
		const selectedLanguages = [];
		$('input[name="individual_languages[]"]:checked').each(function() {
			selectedLanguages.push($(this).val());
		});
		
		if (selectedLanguages.length === 0) {
			alert('Please select at least one target language.');
			return;
		}
		
		if (!confirm('This will translate ' + selectedIds.length + ' items to ' + selectedLanguages.length + ' languages. Continue?')) {
			return;
		}
		
		// Start translation
		individualTranslationInProgress = true;
		$btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Translating...');
		$cancelBtn.show();
		$progress.show();
		
		// Start the translation process using the existing batch API
		startIndividualTranslationBatch(selectedIds, selectedLanguages);
	});
	
	function startIndividualTranslationBatch(contentIds, languages) {
		const $status = $('#individual-progress-text');
		
		// Enhanced starting message
		$status.html(`
			<div class="progress-status-line">
				<span class="status-icon">🚀</span>
				Initializing translation process...
			</div>
			<div class="progress-details">
				Preparing to translate ${contentIds.length} items to ${languages.length} languages
			</div>
		`);
		
		// Start with 0% and animate to 5% to show initialization
		updateIndividualProgress(0, 1, false);
		setTimeout(() => {
			simulateMicroProgress(0, 0.05, 1000);
		}, 100);
		
		// Small delay to show initialization, then start processing
		setTimeout(() => {
			processIndividualBatch(contentIds, languages, 0);
		}, 1200);
	}
	
	function processIndividualBatch(contentIds, languages, batchIndex) {
		const $status = $('#individual-progress-text');
		
		// Calculate current and target progress
		const totalBatches = Math.ceil(contentIds.length / 5); // Assuming batch size of 5
		const currentProgress = batchIndex / totalBatches;
		const targetProgress = (batchIndex + 0.8) / totalBatches; // Show progress before completion
		
		// Update status with more detailed information
		$status.html(`
			<div class="progress-status-line">
				<span class="status-icon">🔄</span>
				Processing batch ${batchIndex + 1} of ${totalBatches}
			</div>
			<div class="progress-details">
				Translating ${contentIds.length} items to ${languages.length} languages
			</div>
		`);
		
		// Start micro-progress simulation
		simulateMicroProgress(currentProgress, targetProgress, 2000);
		
		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'voxfor_ml_translate_individual_content',
				nonce: nonce,
				content_ids: contentIds,
				languages: languages,
				translation_scope: 'full',
				batch_index: batchIndex,
				batch_size: 5
			},
			success: function(response) {
				if (response.success) {
					if (response.data.completed) {
						// Final progress animation to 100%
						updateIndividualProgress(1, 1, true);
						setTimeout(() => {
							completeIndividualTranslation();
						}, 1000);
					} else {
						// Calculate actual progress
						const actualProgress = (response.data.batch_index + 1) / response.data.total_batches;
						updateIndividualProgress(actualProgress, 1, true);
						
						// Enhanced status message
						$status.html(`
							<div class="progress-status-line">
								<span class="status-icon">✅</span>
								Completed batch ${response.data.batch_index + 1} of ${response.data.total_batches}
							</div>
							<div class="progress-details">
								${Math.round(actualProgress * 100)}% complete - Processing next batch...
							</div>
						`);
						
						// Small delay before next batch for better UX
						setTimeout(() => {
							processIndividualBatch(contentIds, languages, response.data.next_batch_index);
						}, 500);
					}
				} else {
					$status.html(`
						<div class="progress-status-line error">
							<span class="status-icon">❌</span>
							Translation Error
						</div>
						<div class="progress-details">
							${response.data || 'Translation failed'}
						</div>
					`);
					completeIndividualTranslation();
				}
			},
			error: function(xhr, status, error) {
				$status.html(`
					<div class="progress-status-line error">
						<span class="status-icon">⚠️</span>
						Network Error
					</div>
					<div class="progress-details">
						${error} - Please check your connection
					</div>
				`);
				completeIndividualTranslation();
			}
		});
	}
	
	function updateIndividualProgress(progress, total, animated = true) {
		const percentage = Math.round(progress * 100);
		const $progressBar = $('#individual-progress-bar');
		const $progressText = $('#individual-progress-percentage');
		
		if (animated) {
			// Smooth animation to the target percentage
			$progressBar.animate({
				width: percentage + '%'
			}, 800, 'swing');
			
			// Animate the percentage text
			animateProgressText($progressText, percentage);
		} else {
			$progressBar.css('width', percentage + '%');
			$progressText.text(percentage + '%');
		}
		
		// Update progress bar color based on completion
		updateProgressBarColor($progressBar, percentage);
	}
	
	function animateProgressText($element, targetPercentage) {
		const currentPercentage = parseInt($element.text().replace('%', '')) || 0;
		const increment = targetPercentage > currentPercentage ? 1 : -1;
		
		function updateText() {
			const current = parseInt($element.text().replace('%', '')) || 0;
			if ((increment > 0 && current < targetPercentage) || (increment < 0 && current > targetPercentage)) {
				$element.text((current + increment) + '%');
				setTimeout(updateText, 20);
			}
		}
		
		updateText();
	}
	
	function updateProgressBarColor($progressBar, percentage) {
		// Remove existing color classes
		$progressBar.removeClass('progress-starting progress-working progress-finishing progress-complete');
		
		// Add appropriate color class
		if (percentage === 0) {
			$progressBar.addClass('progress-starting');
		} else if (percentage < 30) {
			$progressBar.addClass('progress-starting');
		} else if (percentage < 80) {
			$progressBar.addClass('progress-working');
		} else if (percentage < 100) {
			$progressBar.addClass('progress-finishing');
		} else {
			$progressBar.addClass('progress-complete');
		}
	}
	
	function simulateMicroProgress(currentProgress, targetProgress, duration = 3000) {
		const startTime = Date.now();
		const progressDiff = targetProgress - currentProgress;
		
		function updateMicroProgress() {
			const elapsed = Date.now() - startTime;
			const progressRatio = Math.min(elapsed / duration, 1);
			
			// Use easing function for smooth progress
			const easedProgress = easeOutCubic(progressRatio);
			const newProgress = currentProgress + (progressDiff * easedProgress);
			
			updateIndividualProgress(newProgress, 1, false);
			
			if (progressRatio < 1) {
				requestAnimationFrame(updateMicroProgress);
			}
		}
		
		requestAnimationFrame(updateMicroProgress);
	}
	
	function easeOutCubic(t) {
		return 1 - Math.pow(1 - t, 3);
	}
	
	function completeIndividualTranslation() {
		individualTranslationInProgress = false;
		
		// Enhanced completion message
		$('#individual-progress-text').html(`
			<div class="progress-status-line">
				<span class="status-icon">🎉</span>
				Translation completed successfully!
			</div>
			<div class="progress-details">
				All content has been translated and saved
			</div>
		`);
		
		// Add completion class for final animation
		$('#individual-progress-bar').addClass('progress-complete');
		
		// Reset UI after a short delay
		setTimeout(() => {
			$('#start-individual-translate-btn').prop('disabled', false).html('<span class="dashicons dashicons-translation"></span> Translate Selected Content');
			$('#cancel-individual-translate-btn').hide();
			
			// Hide progress after completion
			setTimeout(() => {
				$('#individual-translation-progress').fadeOut(500);
			}, 2000);
		}, 1500);
		
		// Reload the content list to show updated statuses
		setTimeout(() => {
			loadContentList();
		}, 1000);
	}

	// Additional functionality for cost estimation, translation progress, etc. would be added here
	// This is a condensed version focusing on the core functionality
}); 