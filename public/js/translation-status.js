/**
 * Voxfor Multilanguage - Translation Status Management
 */
(function($) {
    'use strict';
    
    const VoxforMLTranslationStatus = {
        postId: null,
        refreshInterval: null,
        
        init: function() {
            this.postId = $('#post_ID').val() || $('input[name="post_ID"]').val();
            
            if (!this.postId) {
                return;
            }
            
            this.bindEvents();
            this.startAutoRefresh();
        },
        
        bindEvents: function() {
            const self = this; // Store reference for use in handlers
            
            // Create translation button
            $(document).on('click', '.voxfor-ml-create-translation', this.createTranslation.bind(this));
            
            // Product translate button - delegated event handler with MEGA AGGRESSIVE prevention
            $(document).on('click', '.voxfor-ml-translate-product', function(e) {
                // NUCLEAR OPTION: Disable ALL forms on the page immediately
                $('form').each(function() {
                    $(this).data('voxfor-original-onsubmit', this.onsubmit);
                    this.onsubmit = function() {
                        return false;
                    };
                    $(this).on('submit.voxfor-nuclear', function(submitEvent) {
                        submitEvent.preventDefault();
                        submitEvent.stopImmediatePropagation();
                        return false;
                    });
                });
                
                // Call the actual translation function
                self.translateProduct(e);
            });
            
            // Fallback: Direct button binding for any buttons that might exist now
            $('.voxfor-ml-translate-product').each(function(i, btn) {
                if (!$(btn).data('voxfor-bound')) {
                    $(btn).data('voxfor-bound', true);
                    $(btn).on('click.voxfor-direct', function(e) {
                        self.translateProduct(e);
                    });
                }
            });
            
            // Generic fallback for any translate-product buttons
            $(document).on('click', '[data-action="translate-product"]', function(e) {
                self.translateProduct(e);
            });
        },
        
        createTranslation: function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const $button = $(e.currentTarget);
            const language = $button.data('language');
            const postId = $button.data('post-id') || this.postId;
            
            if (!language || !postId) {
                alert('Missing language or post ID');
                return;
            }
            
            // Disable button and show loading
            $button.prop('disabled', true).text('Translating...');
            
            const data = {
                action: 'voxfor_ml_create_translation',
                nonce: voxforMLStatus.nonce,
                post_id: postId,
                language: language
            };
            
            $.ajax({
                url: voxforMLStatus.ajaxUrl,
                type: 'POST',
                data: data,
                success: (response) => {
                    if (response.success) {
                        // Show progress bar if available
                        if (response.data.progress_key) {
                            this.showProgressBar(response.data.progress_key);
                        } else {
                            // Refresh the page to show updated status
                            location.reload();
                        }
                    } else {
                        alert('Translation failed: ' + (response.data.message || 'Unknown error'));
                        $button.prop('disabled', false).text('Translate');
                    }
                },
                error: (xhr, status, error) => {
                    alert('Translation request failed: ' + error);
                    $button.prop('disabled', false).text('Translate');
                }
            });
        },
        
        translateProduct: function(e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            
            // Set global flag to prevent form submissions
            window.translationInProgress = true;
            
            const $button = $(e.currentTarget);
            const productId = $button.data('product-id') || $button.closest('form').find('input[name="post_ID"]').val();
            const language = $button.data('language');
            
            if (!productId || !language) {
                alert('Missing product ID or language');
                window.translationInProgress = false;
                return;
            }
            
            // Disable the button and show loading state
            const originalText = $button.text();
            $button.prop('disabled', true).text('Translating...');
            
            const ajaxData = {
                action: 'voxfor_ml_translate_product',
                nonce: voxforMLStatus.productTranslateNonce || voxforMLStatus.nonce,
                product_id: productId,
                language: language
            };
            
            $.ajax({
                url: voxforMLStatus.ajaxUrl,
                type: 'POST',
                data: ajaxData,
                timeout: 120000, // 2 minutes timeout
                success: (response) => {
                    if (response.success) {
                        // Show success message
                        if (response.data && response.data.message) {
                            alert('Success: ' + response.data.message);
                        } else {
                            alert('Product translated successfully!');
                        }
                        
                        // Update button text
                        $button.text('Translated ✓').removeClass('button-primary').addClass('button-secondary');
                        
                        // Keep button disabled to prevent double-translation
                        // $button.prop('disabled', false);
                        
                    } else {
                        // Show error message
                        const errorMessage = response.data && response.data.message ? response.data.message : 'Translation failed';
                        alert('Error: ' + errorMessage);
                        
                        // Re-enable button
                        $button.prop('disabled', false).text(originalText);
                    }
                    
                    // Clear global flag
                    window.translationInProgress = false;
                    
                    // Restore form handlers after a short delay
                    setTimeout(() => {
                        $('form').each(function() {
                            const originalOnSubmit = $(this).data('voxfor-original-onsubmit');
                            if (originalOnSubmit !== undefined) {
                                this.onsubmit = originalOnSubmit;
                            }
                            $(this).off('submit.voxfor-nuclear');
                        });
                    }, 1000);
                    
                    // Prevent any navigation for a few seconds after successful translation
                    if (response.success) {
                        setTimeout(() => {
                            // Remove navigation prevention after delay
                        }, 3000);
                    }
                },
                error: (xhr, status, error) => {
                    let errorMessage = 'Translation request failed';
                    if (status === 'timeout') {
                        errorMessage = 'Translation timed out. Please try again.';
                    } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMessage = xhr.responseJSON.data.message;
                    } else if (error) {
                        errorMessage += ': ' + error;
                    }
                    
                    alert('Error: ' + errorMessage);
                    
                    // Re-enable button
                    $button.prop('disabled', false).text(originalText);
                    
                    // Clear global flag
                    window.translationInProgress = false;
                    
                    // Restore form handlers
                    setTimeout(() => {
                        $('form').each(function() {
                            const originalOnSubmit = $(this).data('voxfor-original-onsubmit');
                            if (originalOnSubmit !== undefined) {
                                this.onsubmit = originalOnSubmit;
                            }
                            $(this).off('submit.voxfor-nuclear');
                        });
                    }, 1000);
                }
            });
        },
        
        showProgressBar: function(progressKey) {
            // Create or show progress bar
            let $progressContainer = $('#voxfor-ml-progress');
            if ($progressContainer.length === 0) {
                $progressContainer = $('<div id="voxfor-ml-progress" style="margin: 20px 0;"><div class="progress-bar"><div class="progress-fill" style="width: 0%;"></div></div><div class="progress-text">Starting...</div></div>');
                $('.voxfor-ml-translation-status').append($progressContainer);
            }
            
            $progressContainer.show();
            
            // Poll for progress updates
            const pollProgress = () => {
                $.ajax({
                    url: voxforMLStatus.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'voxfor_ml_get_translation_progress',
                        progress_key: progressKey,
                        _wpnonce: voxforMLStatus.nonce
                    },
                    success: (response) => {
                        if (response.success && response.data) {
                            const progress = response.data;
                            const percentage = Math.round((progress.current / progress.total) * 100);
                            
                            $progressContainer.find('.progress-fill').css('width', percentage + '%');
                            $progressContainer.find('.progress-text').text(progress.message || 'Processing...');
                            
                            if (progress.current < progress.total) {
                                setTimeout(pollProgress, 1000);
                            } else {
                                // Translation complete
                                setTimeout(() => {
                                    $progressContainer.hide();
                                    location.reload();
                                }, 2000);
                            }
                        }
                    },
                    error: () => {
                        $progressContainer.find('.progress-text').text('Progress update failed');
                    }
                });
            };
            
            pollProgress();
        },
        
        startAutoRefresh: function() {
            // Refresh translation status every 30 seconds
            this.refreshInterval = setInterval(() => {
                this.refreshStatus();
            }, 30000);
        },
        
        refreshStatus: function() {
            if (!this.postId) return;
            
            $.ajax({
                url: voxforMLStatus.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'voxfor_ml_get_translation_status',
                    post_id: this.postId,
                    nonce: voxforMLStatus.nonce
                },
                success: (response) => {
                    if (response.success && response.data) {
                        this.updateStatusDisplay(response.data);
                    }
                }
            });
        },
        
        updateStatusDisplay: function(statusData) {
            // Update the translation status display
            const $statusContainer = $('.voxfor-ml-translation-status');
            if ($statusContainer.length && statusData.html) {
                $statusContainer.html(statusData.html);
                this.bindEvents(); // Re-bind events for new elements
            }
        }
    };
    
    // Global form submission blocker
    $(document).on('submit', 'form', function(e) {
        if (window.translationInProgress) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        }
    });
    
    // Initialize when document is ready
    $(document).ready(function() {
        VoxforMLTranslationStatus.init();
        
        // Fallback initialization with more aggressive binding
        setTimeout(function() {
            // Set up fallback handlers
            $(document).off('click.voxfor-fallback').on('click.voxfor-fallback', '.voxfor-ml-create-translation', function(e) {
                e.preventDefault();
                
                const $this = $(this);
                const postId = $this.data('post-id');
                const language = $this.data('language');
                
                $.ajax({
                    url: voxforMLStatus.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'voxfor_ml_create_translation',
                        nonce: voxforMLStatus.nonce,
                        post_id: postId,
                        language: language
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Translation failed: ' + (response.data ? response.data.message : 'Unknown error'));
                        }
                    }
                });
            });
            
            // Check if we have the expected elements
            const allButtons = $('.voxfor-ml-create-translation, .voxfor-ml-translate-product');
        }, 1000);
    });
    
})(jQuery);