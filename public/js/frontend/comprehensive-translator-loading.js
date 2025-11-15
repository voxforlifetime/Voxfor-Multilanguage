document.addEventListener('DOMContentLoaded', function() {
	// Check if we need to show translation loading
	if (typeof voxforComprehensiveLoading === 'undefined') {
		return; // Exit if localization data is not available
	}

	const currentLanguage = voxforComprehensiveLoading.currentLanguage;
	const loadingText = voxforComprehensiveLoading.loadingText;

	// Show loading indicator
	var loadingDiv = document.createElement('div');
	loadingDiv.id = 'voxfor-ml-translation-loading';
	loadingDiv.innerHTML = '<div style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:999999;display:flex;align-items:center;justify-content:center;color:white;font-size:18px;"><div><div style="margin-bottom:20px;">🌐 ' + loadingText.replace('%s', currentLanguage) + '</div><div style="width:200px;height:4px;background:#333;border-radius:2px;"><div id="translation-progress" style="width:0%;height:100%;background:#4CAF50;border-radius:2px;transition:width 0.3s;"></div></div></div></div>';
	document.body.appendChild(loadingDiv);
	
	// Start comprehensive translation
	if (typeof voxforMLComprehensiveTranslate === 'function') {
		voxforMLComprehensiveTranslate();
	}
}); 