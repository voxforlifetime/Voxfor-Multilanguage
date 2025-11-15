jQuery(document).ready(function($) {
	if (typeof voxforGlossary === 'undefined') {
		return;
	}

	const strings = voxforGlossary.strings;
	const nonce = voxforGlossary.nonce;
	const ajaxUrl = voxforGlossary.ajaxUrl;

	// Add glossary term
	$('.voxfor-ml-add-term').on('click', function() {
		const $button = $(this);
		$button.prop('disabled', true).text(strings.adding);
		
		const formData = $('#glossary-form').serialize();
		
		$.post(ajaxUrl, formData + '&action=voxfor_ml_add_glossary_term&nonce=' + nonce, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(response.data.message || strings.error);
				$button.prop('disabled', false).text(strings.addTerm);
			}
		});
	});
	
	// Delete glossary term
	$('.voxfor-ml-delete-term').on('click', function() {
		if (!confirm(strings.confirmDelete)) {
			return;
		}
		
		const $button = $(this);
		const termId = $button.data('term-id');
		
		$button.prop('disabled', true).text(strings.deleting);
		
		$.post(ajaxUrl, {
			action: 'voxfor_ml_delete_glossary_term',
			nonce: nonce,
			term_id: termId
		}, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(response.data.message || strings.error);
				$button.prop('disabled', false).text(strings.delete);
			}
		});
	});
}); 