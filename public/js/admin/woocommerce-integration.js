jQuery(document).ready(function($) {
	// Check if we're on a page with WooCommerce integration
	if (typeof voxforWooCommerce === 'undefined') {
		return; // Exit if localization data is not available
	}

	const strings = voxforWooCommerce.strings;
	const nonce = voxforWooCommerce.nonce;
	const languages = voxforWooCommerce.languages;

	// Translate single product
	$('.voxfor-ml-translate-product').on('click', function() {
		const $button = $(this);
		const productId = $button.data('product-id');
		const language = $button.data('language');
		
		$button.prop('disabled', true).text(strings.translating);
		
		$.post(ajaxurl, {
			action: 'voxfor_ml_translate_product',
			nonce: nonce,
			product_id: productId,
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
	
	// Translate all products
	$('.voxfor-ml-translate-all-products').on('click', function() {
		const $button = $(this);
		const productId = $button.data('product-id');
		
		if (!confirm(strings.confirmTranslateAll)) {
			return;
		}
		
		$button.prop('disabled', true).text(strings.translating);
		
		let completed = 0;
		
		languages.forEach(function(language) {
			$.post(ajaxurl, {
				action: 'voxfor_ml_translate_product',
				nonce: nonce,
				product_id: productId,
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