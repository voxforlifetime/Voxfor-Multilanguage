document.addEventListener('DOMContentLoaded', function() {
	// Check if we need to translate footer content
	if (typeof voxforFooterTranslator === 'undefined') {
		return; // Exit if localization data is not available
	}

	// Only run if not on English
	if (voxforFooterTranslator.currentLanguage === 'en') {
		return;
	}

	// Translate common footer elements
	var footerElements = document.querySelectorAll('footer *');
	footerElements.forEach(function(element) {
		if (element.children.length === 0 && element.textContent.trim()) {
			var text = element.textContent.trim();
			// Skip if already translated or is a number/date
			if (text.length > 2 && !/^\d+$/.test(text) && !/^\d{4}-\d{2}-\d{2}/.test(text)) {
				// This would need AJAX call to translate
				// For now, we'll handle static translations
			}
		}
	});
}); 