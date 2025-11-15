jQuery(document).ready(function($) {
	// Check if we're on the header-footer translator page
	if (typeof voxforHeaderFooter === 'undefined') {
		return; // Exit if localization data is not available
	}

	const strings = voxforHeaderFooter.strings;
	const nonce = voxforHeaderFooter.nonce;
	let collectedTexts = [];

	// Collect texts
	$('#voxfor-ml-collect-texts').on('click', function() {
		const $button = $(this);
		$button.prop('disabled', true).text(strings.collecting);
		
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'voxfor_ml_collect_header_footer_texts',
				nonce: nonce
			},
			success: function(response) {
				if (response.success) {
					collectedTexts = response.data.texts;
					displayCollectedTexts(collectedTexts);
					$('#collected-texts, #translation-section').show();
				} else {
					alert(strings.error + ': ' + response.data.message);
				}
			},
			complete: function() {
				$button.prop('disabled', false).text(strings.collectTexts);
			}
		});
	});
	
	// Display collected texts
	function displayCollectedTexts(texts) {
		const $list = $('#texts-list');
		$list.empty();
		
		if (texts.length === 0) {
			$list.html('<p>' + strings.noTextsFound + '</p>');
			return;
		}
		
		const $ul = $('<ul>');
		texts.forEach(function(text) {
			$ul.append($('<li>').text(text));
		});
		$list.html($ul);
	}
	
	// Translate header/footer
	$('#voxfor-ml-translate-header-footer').on('click', function() {
		const $button = $(this);
		const selectedLanguages = [];
		
		$('input[name="target_languages[]"]:checked').each(function() {
			selectedLanguages.push($(this).val());
		});
		
		if (selectedLanguages.length === 0) {
			alert(strings.selectLanguage);
			return;
		}
		
		if (collectedTexts.length === 0) {
			alert(strings.collectFirst);
			return;
		}
		
		$button.prop('disabled', true).text(strings.translating);
		$('#translation-progress').show();
		
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'voxfor_ml_translate_header_footer',
				nonce: nonce,
				texts: collectedTexts,
				languages: selectedLanguages
			},
			success: function(response) {
				if (response.success) {
					displayTranslationResults(response.data);
				} else {
					alert(strings.error + ': ' + response.data.message);
				}
			},
			complete: function() {
				$button.prop('disabled', false).text(strings.translateHeaderFooter);
			}
		});
	});
	
	// Display translation results
	function displayTranslationResults(data) {
		const $results = $('#translation-results');
		$results.empty();
		
		let html = '<h3>' + strings.translationResults + '</h3>';
		html += '<p>' + strings.successfullyTranslated.replace('%1$d', data.translated).replace('%2$d', data.languages.length) + '</p>';
		
		if (data.failed > 0) {
			html += '<p style="color: red;">' + strings.failed.replace('%d', data.failed) + '</p>';
			
			if (data.errors && data.errors.length > 0) {
				html += '<div style="margin-top: 10px; padding: 10px; background: #fee; border: 1px solid #fcc;">';
				html += '<strong>' + strings.errorDetails + ':</strong><ul style="margin: 5px 0 0 20px;">';
				data.errors.forEach(function(error) {
					html += '<li>' + error + '</li>';
				});
				html += '</ul></div>';
			}
		}
		
		$results.html(html);
	}
}); 