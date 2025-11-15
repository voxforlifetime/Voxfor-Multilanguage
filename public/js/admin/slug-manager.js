/**
 * Voxfor Multilanguage - Slug Manager Admin JavaScript
 * 
 * Handles URL slug generation and localization
 * 
 * @package VoxforML
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// URL Slug Generation
		$('#voxfor-ml-generate-slugs').click(function() {
			var button = $(this);
			var status = $('#voxfor-ml-slug-status');
			var progress = $('#voxfor-ml-slug-progress');
			var progressBar = $('#voxfor-ml-slug-progress-bar');
			var progressText = $('#voxfor-ml-slug-progress-text');
			var details = $('#voxfor-ml-slug-details');
			
			button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> ' + voxforMLSlugManager.localizingText);
			status.text('');
			progress.show();
			progressBar.css('width', '0%');
			progressText.text('0%');
			details.text(voxforMLSlugManager.startingText);
			
			$.ajax({
				url: voxforMLSlugManager.restUrl,
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', voxforMLSlugManager.nonce);
				},
				success: function(response) {
					progressBar.css('width', '100%');
					progressText.text('100%');
					
					if (response.success) {
						status.html('<span style="color: #46b450;">✓ ' + response.message + '</span>');
						details.html(voxforMLSlugManager.localizedForText + ' ' + response.generated + ' ' + voxforMLSlugManager.postsText);
						
						if (response.results && response.results.length > 0) {
							var resultsList = '<ul style="margin-top: 10px; max-height: 200px; overflow-y: auto;">';
							response.results.forEach(function(result) {
								resultsList += '<li><strong>' + result.title + '</strong> (' + result.languages.join(', ') + ')</li>';
							});
							resultsList += '</ul>';
							details.html(details.html() + resultsList);
						}
					} else {
						status.html('<span style="color: #dc3232;">✗ Error: ' + (response.error || 'Unknown error') + '</span>');
						details.text(response.error || voxforMLSlugManager.errorText);
					}
				},
				error: function(xhr, status, error) {
					progressBar.css('width', '100%');
					progressText.text('Error');
					$('#voxfor-ml-slug-status').html('<span style="color: #dc3232;">✗ Request failed: ' + error + '</span>');
					details.text(voxforMLSlugManager.networkErrorText);
				},
				complete: function() {
					button.prop('disabled', false).html('<span class="dashicons dashicons-admin-links"></span> ' + voxforMLSlugManager.buttonText);
				}
			});
		});
	});

})(jQuery);

