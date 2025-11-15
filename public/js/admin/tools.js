jQuery(document).ready(function($) {
	// Check if we're on the tools page
	if (typeof voxforTools === 'undefined') {
		return; // Exit if localization data is not available
	}

	const strings = voxforTools.strings;
	const nonce = voxforTools.nonce;
	const ajaxUrl = voxforTools.ajaxUrl;

	// Process queue button
	$('#process-queue-btn').on('click', function() {
		const $btn = $(this);
		const $progress = $('#queue-progress');
		const $progressBar = $('#queue-progress-bar');
		const $status = $('#queue-status');
		
		$btn.prop('disabled', true);
		$progress.show();
		$progressBar.val(0);
		$status.text(strings.startingQueue);
		
		// Simulate progress (in real implementation, this would poll the server)
		let progress = 0;
		const interval = setInterval(function() {
			progress += Math.random() * 15;
			if (progress >= 100) {
				progress = 100;
				clearInterval(interval);
				$status.text(strings.queueComplete);
				$btn.prop('disabled', false);
				
				setTimeout(function() {
					location.reload();
				}, 2000);
			} else {
				$progressBar.val(progress);
				$status.text(strings.processing + ' ' + Math.round(progress) + '%');
			}
		}, 500);
		
		// Make actual API call
		$.post(ajaxUrl, {
			action: 'voxfor_ml_process_queue',
			_wpnonce: nonce
		});
	});

}); 