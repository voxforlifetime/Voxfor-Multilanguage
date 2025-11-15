jQuery(document).ready(function($) {
	// Check if we're on the exclusions page
	if (typeof voxforExclusions === 'undefined') {
		return; // Exit if localization data is not available
	}

	const strings = voxforExclusions.strings;
	const nonce = voxforExclusions.nonce;
	const ajaxUrl = voxforExclusions.ajaxUrl;
	
	// Show message function
	function showMessage(message, type) {
		// Remove any existing messages
		$('.voxfor-ml-message').remove();
		
		const $message = $('<div class="voxfor-ml-message ' + type + '">' + message + '</div>');
		$('body').append($message);
		
		// Auto-dismiss after 3 seconds
		setTimeout(function() {
			$message.fadeOut(function() {
				$message.remove();
			});
		}, 3000);
	}

	// Show/hide help text based on rule type
	$('#rule_type').on('change', function() {
		const type = $(this).val();
		$('.voxfor-ml-rule-type-help .description').hide();
		if (type) {
			$('.voxfor-ml-rule-type-help .description[data-type="' + type + '"]').show();
		}
		
		// Update placeholder based on rule type
		const $ruleValue = $('#rule_value');
		switch(type) {
			case 'css':
				$ruleValue.attr('placeholder', 'e.g., .no-translate, #header, .menu');
				break;
			case 'namespace':
				$ruleValue.attr('placeholder', 'e.g., woocommerce_checkout, admin_notices');
				break;
			default:
				$ruleValue.attr('placeholder', '');
		}
	});
	
	// Toggle rule active state
	$('.voxfor-ml-rule-toggle').on('change', function() {
		const $toggle = $(this);
		const ruleId = $toggle.data('id');
		const isActive = $toggle.is(':checked');
		const $ruleItem = $toggle.closest('.voxfor-ml-rule-item');
		
		// Disable toggle during request
		$toggle.prop('disabled', true);
		
		$.post(ajaxUrl, {
			action: 'voxfor_ml_toggle_exclusion',
			rule_id: ruleId,
			is_active: isActive ? 1 : 0,
			_wpnonce: nonce
		}, function(response) {
			if (response.success) {
				$ruleItem.toggleClass('inactive', !isActive);
				showMessage(isActive ? 'Rule activated' : 'Rule deactivated', 'success');
			} else {
				// Revert toggle
				$toggle.prop('checked', !isActive);
				showMessage(response.data.message || strings.error, 'error');
			}
		}).fail(function() {
			// Revert toggle
			$toggle.prop('checked', !isActive);
			showMessage('Failed to update rule', 'error');
		}).always(function() {
			$toggle.prop('disabled', false);
		});
	});
	
	// Edit rule
	$('.voxfor-ml-edit-rule').on('click', function() {
		const $btn = $(this);
		
		$('#edit-rule-id').val($btn.data('id'));
		$('#rule_type').val($btn.data('type')).trigger('change');
		$('#rule_value').val($btn.data('value'));
		$('#description').val($btn.data('description'));
		
		$('.voxfor-ml-exclusion-form h2').text(strings.editRule);
		$('.voxfor-ml-exclusion-form button[type="submit"]').text(strings.updateRule);
		$('#cancel-edit').show();
		
		$('html, body').animate({
			scrollTop: $('#add-new-rule').offset().top - 50
		}, 500);
	});
	
	// Cancel edit
	$('#cancel-edit').on('click', function() {
		$('#edit-rule-id').val('');
		$('.voxfor-ml-exclusion-form')[0].reset();
		$('#rule_type').trigger('change');
		
		$('.voxfor-ml-exclusion-form h2').text(strings.addNewRule);
		$('.voxfor-ml-exclusion-form button[type="submit"]').text(strings.addRule);
		$(this).hide();
	});
	
	// Quick add
	$('.voxfor-ml-quick-add').on('click', function() {
		const $btn = $(this);
		
		$('#rule_type').val($btn.data('type')).trigger('change');
		$('#rule_value').val($btn.data('value'));
		$('#description').val($btn.data('description'));
		
		$('html, body').animate({
			scrollTop: $('#add-new-rule').offset().top - 50
		}, 500);
	});
	
	// Seed common exclusions
	$('#seed-common-exclusions').on('click', function() {
		const $btn = $(this);
		const originalText = $btn.text();
		
		if (!confirm(strings.confirmSeed)) {
			return;
		}
		
		$btn.prop('disabled', true).html('<span class="voxfor-ml-loading">' + strings.addingExclusions + '</span>');
		
		$.post(ajaxUrl, {
			action: 'voxfor_ml_seed_exclusions',
			_wpnonce: nonce
		}, function(response) {
			if (response.success) {
				showMessage(response.data.message, 'success');
				if (response.data.added > 0) {
					setTimeout(function() {
						location.reload(); // Refresh to show new rules
					}, 1500);
				}
			} else {
				showMessage(response.data.message || strings.failedToAdd, 'error');
			}
		}).fail(function() {
			showMessage(strings.failedToConnect, 'error');
		}).always(function() {
			$btn.prop('disabled', false).text(originalText);
		});
	});
	
	// Form validation
	$('.voxfor-ml-exclusion-form').on('submit', function(e) {
		const $form = $(this);
		const ruleType = $('#rule_type').val();
		const ruleValue = $('#rule_value').val().trim();
		
		// Clear previous validation states
		$form.find('.required').removeClass('required');
		
		if (!ruleType) {
			$('#rule_type').addClass('required');
			showMessage('Please select a rule type', 'error');
			e.preventDefault();
			return false;
		}
		
		if (!ruleValue) {
			$('#rule_value').addClass('required');
			showMessage('Please enter a rule value', 'error');
			e.preventDefault();
			return false;
		}
		
		// Show loading state
		const $submitBtn = $form.find('button[type="submit"]');
		const originalText = $submitBtn.text();
		$submitBtn.prop('disabled', true).html('<span class="voxfor-ml-loading">' + originalText + '</span>');
		
		// Re-enable button after a delay (form will redirect/reload)
		setTimeout(function() {
			$submitBtn.prop('disabled', false).text(originalText);
		}, 3000);
	});
}); 