jQuery(document).ready(function($) {
	if (typeof voxforLanguageSEO === 'undefined') {
		return;
	}

	const strings = voxforLanguageSEO.strings;
	const nonce = voxforLanguageSEO.nonce;
	const ajaxUrl = voxforLanguageSEO.ajaxUrl;

	// Save SEO settings
	$('.voxfor-ml-save-seo').on('click', function() {
		const $button = $(this);
		$button.prop('disabled', true).text(strings.saving);
		
		const formData = $('#seo-form').serialize();
		
		$.post(ajaxUrl, formData + '&action=voxfor_ml_save_seo_settings&nonce=' + nonce, function(response) {
			if (response.success) {
				$('#seo-message').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
			} else {
				$('#seo-message').html('<div class="notice notice-error"><p>' + (response.data.message || strings.error) + '</p></div>');
			}
		}).always(function() {
			$button.prop('disabled', false).text(strings.save);
		});
	});
	
	// Generate sitemap
	$('.voxfor-ml-generate-sitemap').on('click', function() {
		const $button = $(this);
		$button.prop('disabled', true).text(strings.generating);
		
		$.post(ajaxUrl, {
			action: 'voxfor_ml_generate_sitemap',
			nonce: nonce
		}, function(response) {
			if (response.success) {
				$('#sitemap-message').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
			} else {
				$('#sitemap-message').html('<div class="notice notice-error"><p>' + (response.data.message || strings.error) + '</p></div>');
			}
		}).always(function() {
			$button.prop('disabled', false).text(strings.generate);
		});
	});
}); 