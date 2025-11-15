jQuery(document).ready(function($) {
	if (typeof voxforTranslationMemory === 'undefined') {
		return;
	}

	const strings = voxforTranslationMemory.strings;
	const nonce = voxforTranslationMemory.nonce;
	const ajaxUrl = voxforTranslationMemory.ajaxUrl;

	// Handle mark/unmark for review
	$(document).on('click', '.voxfor-ml-mark-review, .voxfor-ml-unmark-review', function() {
		const $button = $(this);
		const translationId = $button.data('id');
		const needsReview = $button.hasClass('voxfor-ml-mark-review') ? 1 : 0;
		
		$button.prop('disabled', true);
		
		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'voxfor_ml_toggle_needs_review',
				translation_id: translationId,
				needs_review: needsReview,
				_wpnonce: nonce
			},
			success: function(response) {
				if (response.success) {
					// Toggle button appearance
					if (needsReview) {
						$button.removeClass('voxfor-ml-mark-review')
							.addClass('voxfor-ml-unmark-review')
							.html('✓ ' + (strings.reviewed || 'Reviewed'));
					} else {
						$button.removeClass('voxfor-ml-unmark-review')
							.addClass('voxfor-ml-mark-review')
							.html(strings.needReview || 'Need Review');
					}
				} else {
					alert(response.data.message || 'Failed to update review status');
				}
			},
			error: function() {
				alert('Error updating review status');
			},
			complete: function() {
				$button.prop('disabled', false);
			}
		});
	});

	// Inline editing functionality
	let currentEditingElement = null;
	let originalText = '';

	// Handle click on editable elements
	$(document).on('click', '.voxfor-ml-editable', function() {
		const $element = $(this);
		const isLocked = $element.data('locked') === 1;
		
		// Don't allow editing locked translations
		if (isLocked) {
			return;
		}
		
		// Don't start editing if already editing another element
		if (currentEditingElement && currentEditingElement !== this) {
			return;
		}
		
		startEditing($element);
	});
	
	function startEditing($element) {
		if (currentEditingElement) {
			return;
		}
		
		currentEditingElement = $element[0];
		originalText = $element.text().trim();
		
		const translationId = $element.data('id');
		const $wrapper = $element.closest('.voxfor-ml-editable-wrapper');
		
		
		// Create textarea
		const $textarea = $('<textarea class="voxfor-ml-edit-textarea"></textarea>');
		$textarea.val(originalText);
		
		// Add keyboard shortcuts
		$textarea.on('keydown', function(e) {
			if (e.key === 'Escape') {
				e.preventDefault();
				cancelEdit($element);
			} else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
				e.preventDefault();
				$actions.find('.voxfor-ml-save-edit').click();
			}
		});
		
		// Create action buttons
		const $actions = $('<div class="voxfor-ml-edit-actions"></div>');
		const $saveBtn = $('<button type="button" class="button button-primary button-small voxfor-ml-save-edit">Save</button>');
		const $cancelBtn = $('<button type="button" class="button button-secondary button-small voxfor-ml-cancel-edit">Cancel</button>');
		
		$actions.append($saveBtn).append($cancelBtn);
		
		
		// Hide original text and show editing interface
		$element.hide();
		$wrapper.append($textarea).append($actions);
		
		
		// Focus textarea and select all text
		$textarea.focus().select();
		
		// Handle save
		$saveBtn.on('click', function() {
			saveEdit($element, $textarea.val().trim(), translationId);
		});
		
		// Handle cancel
		$cancelBtn.on('click', function() {
			cancelEdit($element);
		});
		
		// Handle Enter key (save) and Escape key (cancel)
		$textarea.on('keydown', function(e) {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
				e.preventDefault();
				saveEdit($element, $textarea.val().trim(), translationId);
			} else if (e.key === 'Escape') {
				e.preventDefault();
				cancelEdit($element);
			}
		});
	}
	
	function saveEdit($element, newText, translationId) {
		if (newText === originalText) {
			cancelEdit($element);
			return;
		}
		
		const $wrapper = $element.closest('.voxfor-ml-editable-wrapper');
		const $actions = $wrapper.find('.voxfor-ml-edit-actions');
		const $textarea = $wrapper.find('.voxfor-ml-edit-textarea');
		
		// Disable buttons and show loading
		$actions.find('button').prop('disabled', true);
		$actions.find('.voxfor-ml-save-edit').text('Saving...');
		
		// Send AJAX request to save
		
		$.post(ajaxUrl, {
			action: 'voxfor_ml_update_translation',
			_wpnonce: nonce,
			translation_id: translationId,
			translated_text: newText
		}, function(response) {
			if (response && response.success) {
				// Update the text and clean up
				$element.text(newText);
				cleanupEdit($element);
				
				// Show success message briefly
				showMessage('Translation updated successfully', 'success');
			} else {
				// Show error message
				showMessage(response.data ? response.data.message : 'Failed to update translation', 'error');
				
				// Re-enable buttons
				$actions.find('button').prop('disabled', false);
				$actions.find('.voxfor-ml-save-edit').text('Save');
			}
		}).fail(function(xhr, status, error) {
			showMessage('Network error occurred', 'error');
			$actions.find('button').prop('disabled', false);
			$actions.find('.voxfor-ml-save-edit').text('Save');
		});
	}
	
	function cancelEdit($element) {
		cleanupEdit($element);
	}
	
	function cleanupEdit($element) {
		const $wrapper = $element.closest('.voxfor-ml-editable-wrapper');
		
		// Remove textarea and actions
		$wrapper.find('.voxfor-ml-edit-textarea').remove();
		$wrapper.find('.voxfor-ml-edit-actions').remove();
		
		// Show original element
		$element.show();
		
		// Reset state
		currentEditingElement = null;
		originalText = '';
	}
	
	function showMessage(message, type) {
		// Remove any existing messages
		$('.voxfor-ml-message').remove();
		
		const $message = $('<div class="voxfor-ml-message ' + type + '">' + message + '</div>');
		$('body').append($message);
		
		// Auto-dismiss after 3 seconds
		setTimeout(function() {
			$message.fadeOut(function() {
				$message.remove();
			});
		}, 3000);
	}



	// Search translation memory
	$('.voxfor-ml-search-memory').on('click', function() {
		const $button = $(this);
		const searchTerm = $('#memory-search').val();
		
		$button.prop('disabled', true).text(strings.searching);
		
		$.post(ajaxUrl, {
			action: 'voxfor_ml_search_translation_memory',
			nonce: nonce,
			search_term: searchTerm
		}, function(response) {
			if (response.success) {
				$('#memory-results').html(response.data.html);
			} else {
				$('#memory-results').html('<div class="notice notice-error"><p>' + (response.data.message || strings.error) + '</p></div>');
			}
		}).always(function() {
			$button.prop('disabled', false).text(strings.search);
		});
	});
	
	// Clear translation memory
	$('.voxfor-ml-clear-memory').on('click', function() {
		if (!confirm(strings.confirmClear)) {
			return;
		}
		
		const $button = $(this);
		$button.prop('disabled', true).text(strings.clearing);
		
		$.post(ajaxUrl, {
			action: 'voxfor_ml_clear_translation_memory',
			nonce: nonce
		}, function(response) {
			if (response.success) {
				location.reload();
			} else {
				alert(response.data.message || strings.error);
			}
		}).always(function() {
			$button.prop('disabled', false).text(strings.clear);
		});
	});
	
	// Export translation memory
	$('.voxfor-ml-export-memory').on('click', function() {
		window.location.href = ajaxUrl + '?action=voxfor_ml_export_translation_memory&nonce=' + nonce;
	});
}); 