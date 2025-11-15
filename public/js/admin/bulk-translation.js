jQuery(document).ready(function($) {
	// Check if we're on the bulk translation page
	if (typeof voxforBulkTranslation === 'undefined') {
		return; // Exit if localization data is not available
	}

	const strings = voxforBulkTranslation.strings;
	const nonce = voxforBulkTranslation.nonce;

	// Estimate bulk translation
	$('#voxfor-ml-estimate-bulk').on('click', function() {
		const languages = $('input[name="languages[]"]:checked').length;
		const postTypes = $('input[name="post_types[]"]:checked').map(function() {
			return $(this).val();
		}).get();
		
		if (languages === 0) {
			alert(strings.selectLanguage);
			return;
		}
		
		// Make AJAX call to get post count
		$.post(ajaxurl, {
			action: 'voxfor_ml_estimate_bulk',
			post_types: postTypes,
			_wpnonce: nonce
		}, function(response) {
			if (response.success) {
				const postCount = response.data.count;
				const totalOperations = postCount * languages;
				const minutesNeeded = totalOperations; // 1 per minute
				const hoursNeeded = Math.ceil(minutesNeeded / 60);
				
				let timeEstimate = minutesNeeded + ' ' + strings.minutes;
				if (hoursNeeded > 1) {
					timeEstimate = hoursNeeded + ' ' + strings.hours;
				}
				
				const message = strings.estimateMessage
					.replace('%1$d', postCount)
					.replace('%2$d', languages)
					.replace('%3$d', totalOperations)
					.replace('%4$s', timeEstimate);
				
				$('#voxfor-ml-estimate-text').text(message);
				$('#voxfor-ml-bulk-estimate').show();
			}
		});
	});
	
	// Handle bulk translation form submission
	$('#voxfor-ml-bulk-translation-form').on('submit', function(e) {
		e.preventDefault();
		
		const $form = $(this);
		const $submitBtn = $form.find('button[type="submit"]');
		
		if (!confirm(strings.confirmStart)) {
			return;
		}
		
		$submitBtn.prop('disabled', true).text(strings.starting);
		
		$.post(ajaxurl, {
			action: 'voxfor_ml_start_bulk_translation',
			languages: $form.find('input[name="languages[]"]:checked').map(function() {
				return $(this).val();
			}).get(),
			post_types: $form.find('input[name="post_types[]"]:checked').map(function() {
				return $(this).val();
			}).get(),
			post_status: $form.find('select[name="post_status[]"]').val(),
			translate_existing: $form.find('input[name="translate_existing"]').is(':checked') ? 1 : 0,
			items_per_batch: $form.find('select[name="items_per_batch"]').val(),
			_wpnonce: $form.find('#voxfor_ml_bulk_nonce').val()
		}, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(response.data.message || strings.errorStarting);
				$submitBtn.prop('disabled', false).text(strings.startBulkTranslation);
			}
		});
	});
	
	// Pause job
	$(document).on('click', '.voxfor-ml-pause-job', function() {
		const jobId = $(this).data('job-id');
		const $btn = $(this);
		
		$btn.prop('disabled', true);
		
		$.post(ajaxurl, {
			action: 'voxfor_ml_pause_job',
			job_id: jobId,
			_wpnonce: nonce
		}, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(response.data.message || strings.errorPausing);
				$btn.prop('disabled', false);
			}
		});
	});
	
	// Resume job
	$(document).on('click', '.voxfor-ml-resume-job', function() {
		const jobId = $(this).data('job-id');
		const $btn = $(this);
		
		$btn.prop('disabled', true);
		
		$.post(ajaxurl, {
			action: 'voxfor_ml_resume_job',
			job_id: jobId,
			_wpnonce: nonce
		}, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(response.data.message || strings.errorResuming);
				$btn.prop('disabled', false);
			}
		});
	});
	
	// Cancel job
	$(document).on('click', '.voxfor-ml-cancel-job', function() {
		if (!confirm(strings.confirmCancel)) {
			return;
		}
		
		const jobId = $(this).data('job-id');
		const $btn = $(this);
		
		$btn.prop('disabled', true);
		
		$.post(ajaxurl, {
			action: 'voxfor_ml_cancel_job',
			job_id: jobId,
			_wpnonce: nonce
		}, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(response.data.message || strings.errorCancelling);
				$btn.prop('disabled', false);
			}
		});
	});
	
	// View job log
	$(document).on('click', '.voxfor-ml-view-job-log', function() {
		const jobId = $(this).data('job-id');
		
		$.post(ajaxurl, {
			action: 'voxfor_ml_get_job_log',
			job_id: jobId,
			_wpnonce: nonce
		}, function(response) {
			if (response.success) {
				$('#voxfor-ml-job-log-content').text(response.data.log);
				$('#voxfor-ml-job-log-modal').show();
			}
		});
	});
	
	// Close modal
	$('.voxfor-ml-modal-close, .voxfor-ml-modal').on('click', function(e) {
		if (e.target === this) {
			$('#voxfor-ml-job-log-modal').hide();
		}
	});
	
	// Auto-refresh active jobs
	if ($('.voxfor-ml-active-jobs').length > 0) {
		setInterval(function() {
			$('.voxfor-ml-job-card').each(function() {
				const $card = $(this);
				const jobId = $card.data('job-id');
				
				$.post(ajaxurl, {
					action: 'voxfor_ml_get_job_status',
					job_id: jobId,
					_wpnonce: nonce
				}, function(response) {
					if (response.success) {
						const status = response.data;
						$card.find('.voxfor-ml-progress-fill').css('width', status.progress + '%');
						$card.find('.voxfor-ml-progress-info span:first').text(status.progress + '%');
						$card.find('.voxfor-ml-progress-info span:last').text(
							status.processed_items + ' / ' + status.total_items + ' items'
						);
						
						if (status.status === 'completed' || status.status === 'cancelled') {
							location.reload();
						}
					}
				});
			});
		}, 10000); // Every 10 seconds
	}
}); 