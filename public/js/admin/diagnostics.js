jQuery(document).ready(function($) {
	// Check if we're on the diagnostics page
	if (typeof voxforDiagnostics === 'undefined') {
		return; // Exit if localization data is not available
	}

	const strings = voxforDiagnostics.strings;
	const nonce = voxforDiagnostics.nonce;
	const ajaxUrl = voxforDiagnostics.ajaxUrl;

	// Fix database issues
	$('.voxfor-ml-fix-issue').on('click', function() {
		const $button = $(this);
		const check = $button.data('check');
		
		$button.prop('disabled', true).text(strings.fixing);
		
		$.post(ajaxUrl, {
			action: 'voxfor_ml_fix_diagnostic_issue',
			nonce: nonce,
			check: check
		}, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(response.data.message || strings.error);
				$button.prop('disabled', false).text(strings.fixIssue);
			}
		});
	});
	
	// Apply recommendations
	$('.voxfor-ml-apply-recommendation').on('click', function() {
		const $button = $(this);
		const action = $button.data('action');
		
		$button.prop('disabled', true).text(strings.applying || 'Applying...');
		
		$.post(ajaxUrl, {
			action: 'voxfor_ml_apply_recommendation',
			nonce: nonce,
			recommendation: action
		}, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(response.data.message || strings.error);
				$button.prop('disabled', false).text($button.data('original-text') || 'Apply');
			}
		});
	});
});
