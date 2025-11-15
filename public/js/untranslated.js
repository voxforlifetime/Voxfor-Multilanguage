/**
 * Voxfor Multilanguage - Untranslated Page Handler
 */

(function($) {
    'use strict';

    class UntranslatedPageHandler {
        constructor() {
            this.postId = window.voxforMLUntranslated.postId;
            this.language = window.voxforMLUntranslated.language;
            this.checkInterval = window.voxforMLUntranslated.checkInterval;
            this.ajaxUrl = window.voxforMLUntranslated.ajaxUrl;
            this.nonce = window.voxforMLUntranslated.nonce;
            
            this.intervalId = null;
            this.isChecking = false;
            this.startTime = Date.now();
            this.maxWaitTime = 5 * 60 * 1000; // 5 minutes max
            this.checkCount = 0;
            this.maxChecks = 30; // Maximum 30 checks (5 minutes at 10s intervals)
            
            this.init();
        }

        init() {
            // Start checking for translation
            this.startChecking();
            
            // Bind events
            this.bindEvents();
            
            // Show initial notice
            this.showNotice();
        }

        bindEvents() {
            // Dismiss notice
            $(document).on('click', '.voxfor-ml-dismiss-notice', () => {
                $('#voxfor-ml-translation-notice').fadeOut();
                this.stopChecking();
            });
            
            // View translation button
            $(document).on('click', '.voxfor-ml-view-translation', () => {
                // Reload page to show translated version
                window.location.reload();
            });
            
            // Manual trigger translation
            $(document).on('click', '.voxfor-ml-trigger-translation', (e) => {
                e.preventDefault();
                this.triggerTranslation();
            });
        }

        showNotice() {
            const $notice = $('#voxfor-ml-translation-notice');
            
            // Animate in
            setTimeout(() => {
                $notice.addClass('visible');
            }, 500);
        }

        startChecking() {
            // Initial check
            this.checkTranslationStatus();
            
            // Set up interval
            this.intervalId = setInterval(() => {
                this.checkTranslationStatus();
            }, this.checkInterval);
        }

        stopChecking() {
            if (this.intervalId) {
                clearInterval(this.intervalId);
                this.intervalId = null;
            }
        }

        checkTranslationStatus() {
            if (this.isChecking) {
                return;
            }
            
            // Check if we've exceeded maximum wait time or checks
            this.checkCount++;
            const elapsedTime = Date.now() - this.startTime;
            
            if (elapsedTime > this.maxWaitTime || this.checkCount > this.maxChecks) {
                                    // Translation timeout reached, hiding notice
                this.hideNoticeWithTimeout();
                this.stopChecking();
                return;
            }
            
            this.isChecking = true;
            
            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'voxfor_ml_check_translation',
                    post_id: this.postId,
                    language: this.language,
                    nonce: this.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.handleStatusUpdate(response.data);
                    }
                },
                error: () => {
                    // Translation check failed
                    // If multiple failures, hide notice
                    if (this.checkCount > 5) {
                        this.hideNoticeWithTimeout();
                        this.stopChecking();
                    }
                },
                complete: () => {
                    this.isChecking = false;
                }
            });
        }

        handleStatusUpdate(data) {
            if (data.status === 'ready') {
                // Translation is ready
                this.showTranslationReady();
                this.stopChecking();
            } else {
                // Update progress
                this.updateProgress(data.progress);
            }
        }

        updateProgress(progress) {
            const $progressSection = $('.voxfor-ml-notice-progress');
            const $progressFill = $('.voxfor-ml-progress-fill');
            const $progressText = $('.voxfor-ml-progress-text');
            
            // Show progress section
            $progressSection.show();
            
            // Update progress bar
            $progressFill.css('width', progress.percentage + '%');
            
            // Update text
            $progressText.text(
                progress.segments_done + ' / ' + progress.total_segments + ' segments (' + progress.percentage + '%)'
            );
        }

        showTranslationReady() {
            // Hide preparing notice
            $('#voxfor-ml-translation-notice').fadeOut();
            
            // Show ready notice
            const $ready = $('#voxfor-ml-translation-ready');
            $ready.fadeIn().addClass('visible');
            
            // Auto-hide after 10 seconds
            setTimeout(() => {
                if (!$ready.is(':hover')) {
                    $ready.fadeOut();
                }
            }, 10000);
        }

        hideNoticeWithTimeout() {
            const $notice = $('#voxfor-ml-translation-notice');
            const $noticeText = $notice.find('.voxfor-ml-notice-text');
            
            // Update notice to show timeout message
            $noticeText.html(`
                <h4>Translation Taking Longer Than Expected</h4>
                <p>The translation is still processing in the background. You can continue browsing the original content.</p>
                <p><small>This notice will disappear automatically.</small></p>
            `);
            
            // Hide progress bar
            $('.voxfor-ml-notice-progress').hide();
            
            // Auto-hide after 10 seconds
            setTimeout(() => {
                $notice.fadeOut();
            }, 10000);
        }

        triggerTranslation() {
            const $button = $('.voxfor-ml-trigger-translation');
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Triggering...');
            
            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'voxfor_ml_trigger_translation',
                    post_id: this.postId,
                    language: this.language,
                    nonce: this.nonce
                },
                success: (response) => {
                    if (response.success) {
                        $button.text('Translation Queued');
                        // Start checking more frequently
                        this.checkInterval = 5000;
                        this.stopChecking();
                        this.startChecking();
                    } else {
                        $button.text('Error').addClass('error');
                    }
                },
                error: () => {
                    $button.text('Error').addClass('error');
                },
                complete: () => {
                    setTimeout(() => {
                        $button.prop('disabled', false).text(originalText).removeClass('error');
                    }, 3000);
                }
            });
        }
    }

    // Initialize when document is ready
    $(document).ready(() => {
        if (window.voxforMLUntranslated) {
            new UntranslatedPageHandler();
        }
    });

})(jQuery);