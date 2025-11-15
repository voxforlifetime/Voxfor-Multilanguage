jQuery(document).ready(function($) {
	// Check if we're on a page with Elementor integration
	if (typeof voxforElementor === 'undefined') {
		return; // Exit if localization data is not available
	}

	const strings = voxforElementor.strings;
	const nonce = voxforElementor.nonce;
	const languages = voxforElementor.languages;

	// Translate single Elementor page
	$('.voxfor-ml-translate-elementor').on('click', function(e) {
		e.preventDefault();
		e.stopPropagation();
		
		const $button = $(this);
		const postId = $button.data('post-id');
		const language = $button.data('language');
		
		$button.prop('disabled', true).text(strings.translating);
		
		$.post(ajaxurl, {
			action: 'voxfor_ml_translate_elementor_page',
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
		}).fail(function(xhr, status, error) {
			alert(strings.ajaxError + ': ' + error);
			$button.prop('disabled', false).text(strings.translate);
		});
	});
	
	// Translate all Elementor elements
	$('.voxfor-ml-translate-all-elementor').on('click', function() {
		const $button = $(this);
		const postId = $button.data('post-id');
		
		if (!confirm(strings.confirmTranslateAll)) {
			return;
		}
		
		$button.prop('disabled', true).text(strings.translating);
		
		let completed = 0;
		
		languages.forEach(function(language) {
			$.post(ajaxurl, {
				action: 'voxfor_ml_translate_elementor_page',
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