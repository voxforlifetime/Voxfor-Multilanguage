jQuery(document).ready(function($) {
	// Check if we're on the settings page
	if (typeof voxforSettings === 'undefined') {
		return; // Exit if localization data is not available
	}

	// Language selector interaction
	$('.voxfor-ml-language-option input').on('change', function() {
		$(this).parent().toggleClass('selected', $(this).is(':checked'));
	});
	
	// Ensure English stays checked
	$('input[value="en"]').prop('checked', true).prop('disabled', true);
	
	// Track API key changes
	var $apiKeyField = $('#voxfor_ml_deepl_api_key');
	var $apiKeyChanged = $('#voxfor_ml_api_key_changed');
	var $apiKeyOriginal = $('#voxfor_ml_api_key_original');
	var originalValue = $apiKeyOriginal.val();
	
	// Mark as changed when user modifies the API key field
	$apiKeyField.on('input', function() {
		var currentValue = $(this).val().trim();
		
		// Check if the current value is different from the original masked value
		// and it's not empty and doesn't look like a masked key
		if (currentValue !== originalValue && 
			currentValue.length > 0 && 
			!currentValue.match(/^•+$/) && 
			!currentValue.match(/^\*+$/)) {
			$apiKeyChanged.val('1');
		} else {
			$apiKeyChanged.val('0');
		}
	});
	
	// Clear field when user focuses on it (if it contains masked value)
	$apiKeyField.on('focus', function() {
		var currentValue = $(this).val();
		if (currentValue === originalValue && originalValue.match(/^•+$/)) {
			$(this).val('');
		}
	});
	
	// Restore masked value if user leaves field empty
	$apiKeyField.on('blur', function() {
		var currentValue = $(this).val().trim();
		if (currentValue === '' && originalValue !== '') {
			$(this).val(originalValue);
			$apiKeyChanged.val('0');
		}
	});
	
	// Test API Key button
	$('#voxfor-ml-test-api-key').on('click', function() {
		var $button = $(this);
		var $result = $('#voxfor-ml-api-test-result');
		var apiKey = $('#voxfor_ml_deepl_api_key').val();
		var strings = voxforSettings.strings;
		
		$button.prop('disabled', true).text(strings.testing);
		$result.hide();
		
		// Don't send the masked key - let the server use the stored encrypted key
		var dataToSend = {
			action: 'voxfor_ml_test_api_key',
			nonce: voxforSettings.nonce
		};
		
		// Only send api_key if it's not a masked value and user has actually entered a new key
		if (apiKey && !apiKey.match(/^[a-f0-9-]+\*+[a-f0-9-]+$/i) && $('#voxfor_ml_api_key_changed').val() === '1') {
			dataToSend.api_key = apiKey;
		}
		
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: dataToSend,
			success: function(response) {
				if (response.success) {
					$result.html('<span style="color: green;">✓ ' + response.data.message + '</span>').show();
				} else {
					$result.html('<span style="color: red;">✗ ' + response.data.message + '</span>').show();
				}
			},
			error: function() {
				$result.html('<span style="color: red;">✗ ' + strings.ajaxError + '</span>').show();
			},
			complete: function() {
				$button.prop('disabled', false).text(strings.testApiKey);
			}
		});
	});
	
	// Language selector auto-fill functionality
	$('#voxfor_ml_language_selector').on('change', function() {
		var selectedOption = $(this).find('option:selected');
		
		if (selectedOption.val() !== '') {
			// Get data attributes from selected option
			var label = selectedOption.data('label');
			var native = selectedOption.data('native');
			var flag = selectedOption.data('flag');
			var prefix = selectedOption.data('prefix');
			
			// Auto-fill the form fields
			$('#voxfor_ml_display_label').val(label);
			$('#voxfor_ml_display_flag').val(flag);
			$('#voxfor_ml_display_prefix').val(prefix);
			
			// Add visual feedback if fields are visible
			if ($('.voxfor-ml-hidden-fields').is(':visible')) {
				$('#voxfor_ml_display_label, #voxfor_ml_display_flag, #voxfor_ml_display_prefix').addClass('voxfor-ml-auto-filled');
				
				// Remove visual feedback after 2 seconds
				setTimeout(function() {
					$('#voxfor_ml_display_label, #voxfor_ml_display_flag, #voxfor_ml_display_prefix').removeClass('voxfor-ml-auto-filled');
				}, 2000);
			}
		}
	});
	
	// Toggle advanced options
	$('#voxfor-ml-toggle-advanced').on('click', function(e) {
		e.preventDefault();
		var $link = $(this);
		var $fields = $('.voxfor-ml-hidden-fields');
		var $icon = $link.find('.dashicons');
		var strings = voxforSettings.strings;
		
		if ($fields.is(':visible')) {
			// Hide fields
			$fields.slideUp(300);
			$link.html('<span class="dashicons dashicons-admin-generic" style="font-size: 12px; vertical-align: middle;"></span> ' + strings.showAdvanced);
		} else {
			// Show fields
			$fields.slideDown(300);
			$link.html('<span class="dashicons dashicons-arrow-up-alt2" style="font-size: 12px; vertical-align: middle;"></span> ' + strings.hideAdvanced);
		}
	});
	
}); 