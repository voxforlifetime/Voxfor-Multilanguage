document.addEventListener('DOMContentLoaded', function() {
	// Check if we need to fix logo links
	if (typeof voxforLogoFixer === 'undefined') {
		return; // Exit if localization data is not available
	}

	// 🎯 FIX LOGO LINKS: Update all logo and home links to include language prefix
	var homeUrl = voxforLogoFixer.homeUrl;
	var translatedHomeUrl = voxforLogoFixer.translatedHomeUrl;
	
	// Fix logo links (common selectors)
	var logoSelectors = [
		'.custom-logo-link',
		'.site-logo a',
		'.logo a',
		'.brand a',
		'.site-branding a',
		'a[href="' + homeUrl + '"]',
		'a[href="/"]',
		'.header-logo a',
		'.navbar-brand'
	];
	
	logoSelectors.forEach(function(selector) {
		var elements = document.querySelectorAll(selector);
		elements.forEach(function(element) {
			var href = element.getAttribute('href');
			if (href === homeUrl || href === '/' || href === '') {
				element.setAttribute('href', translatedHomeUrl);
				if (voxforLogoFixer.debug) {
					                // Fixed logo link for current language
				}
			}
		});
	});
	
	// Fix any other home links in header/navigation
	var headerLinks = document.querySelectorAll('header a, nav a, .site-header a');
	headerLinks.forEach(function(link) {
		var href = link.getAttribute('href');
		if (href === homeUrl || href === '/') {
			link.setAttribute('href', translatedHomeUrl);
		}
	});
}); 