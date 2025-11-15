jQuery(document).ready(function($) {
	// Check if we're on a page with ACF integration
	if (typeof voxforACF === 'undefined') {
		return; // Exit if localization data is not available
	}

	const strings = voxforACF.strings;
	const nonce = voxforACF.nonce;
	const languages = voxforACF.languages;

	// Translate single ACF field
	$('.voxfor-ml-translate-acf').on('click', function() {
		const $button = $(this);
		const postId = $button.data('post-id');
		const language = $button.data('language');
		
		$button.prop('disabled', true).text(strings.translating);
		
		$.post(ajaxurl, {
			action: 'voxfor_ml_translate_acf_fields',
			nonce: nonce,
			post_id: postId,
			language: language
		}, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(response.data.message || strings.error);
				$button.prop('disabled', false).text(strings.translate);
			}
		});
	});
	
	// Translate all ACF fields
	$('.voxfor-ml-translate-all-acf').on('click', function() {
		const $button = $(this);
		const postId = $button.data('post-id');
		
		if (!confirm(strings.confirmTranslateAll)) {
			return;
		}
		
		$button.prop('disabled', true).text(strings.translating);
		
		let completed = 0;
		
		languages.forEach(function(language) {
			$.post(ajaxurl, {
				action: 'voxfor_ml_translate_acf_fields',
				nonce: nonce,
				post_id: postId,
				language: language
			}, function() {
				completed++;
				if (completed === languages.length) {
					location.reload();
				}
			});
		});
	});
}); 