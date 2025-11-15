/**
 * Voxfor ML Progress Bar - Real-time progress tracking with live logs
 */
(function($) {
    'use strict';
    
    window.VoxforMLProgressBar = {
        progressKey: null,
        updateInterval: null,
        modal: null,
        
        /**
         * Show progress bar modal
         */
        show: function(progressKey) {
            this.progressKey = progressKey;
            this.createModal();
            this.startUpdating();
        },
        
        /**
         * Hide progress bar modal
         */
        hide: function() {
            if (this.updateInterval) {
                clearInterval(this.updateInterval);
                this.updateInterval = null;
            }
            
            if (this.modal) {
                this.modal.remove();
                this.modal = null;
            }
            
            this.progressKey = null;
        },
        
        /**
         * Create progress modal
         */
        createModal: function() {
            const modalHtml = `
                <div id="voxfor-ml-progress-modal" class="voxfor-ml-modal-overlay">
                    <div class="voxfor-ml-modal-container">
                        <div class="voxfor-ml-modal-header">
                            <h2>
                                <span class="dashicons dashicons-admin-site-alt3"></span>
                                Translating Complete Website
                            </h2>
                            <button type="button" class="voxfor-ml-modal-close" title="Minimize (translation continues in background)">
                                <span class="dashicons dashicons-minus"></span>
                            </button>
                        </div>
                        
                        <div class="voxfor-ml-modal-body">
                            <div class="voxfor-ml-progress-section">
                                <div class="voxfor-ml-progress-bar-container">
                                    <div class="voxfor-ml-progress-bar">
                                        <div class="voxfor-ml-progress-fill" style="width: 0%"></div>
                                    </div>
                                    <div class="voxfor-ml-progress-text">0%</div>
                                </div>
                                
                                <div class="voxfor-ml-current-status">
                                    <span class="dashicons dashicons-update spin"></span>
                                    <span class="status-text">Initializing...</span>
                                </div>
                            </div>
                            
                            <div class="voxfor-ml-stats-section">
                                <div class="voxfor-ml-stat-item">
                                    <span class="stat-label">Items Processed:</span>
                                    <span class="stat-value" id="items-processed">0</span>
                                </div>
                                <div class="voxfor-ml-stat-item">
                                    <span class="stat-label">Successful:</span>
                                    <span class="stat-value success" id="translations-success">0</span>
                                </div>
                                <div class="voxfor-ml-stat-item">
                                    <span class="stat-label">Failed:</span>
                                    <span class="stat-value error" id="translations-failed">0</span>
                                </div>
                                <div class="voxfor-ml-stat-item">
                                    <span class="stat-label">Elapsed:</span>
                                    <span class="stat-value" id="elapsed-time">0:00</span>
                                </div>
                            </div>
                            
                            <div class="voxfor-ml-log-section">
                                <h3>
                                    <span class="dashicons dashicons-list-view"></span>
                                    Translation Log
                                </h3>
                                <div class="voxfor-ml-log-container" id="translation-log">
                                    <div class="log-entry">
                                        <span class="log-time">${this.getCurrentTime()}</span>
                                        <span class="log-message">Starting website translation...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="voxfor-ml-modal-footer">
                            <button type="button" class="button" id="pause-translation" disabled>
                                <span class="dashicons dashicons-controls-pause"></span>
                                Pause
                            </button>
                            <button type="button" class="button" id="cancel-translation">
                                <span class="dashicons dashicons-no"></span>
                                Cancel
                            </button>
                            <button type="button" class="button button-primary" id="minimize-modal">
                                <span class="dashicons dashicons-minus"></span>
                                Minimize
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if any
            $('#voxfor-ml-progress-modal').remove();
            
            // Add modal to body
            $('body').append(modalHtml);
            this.modal = $('#voxfor-ml-progress-modal');
            
            // Bind events
            this.bindModalEvents();
            
            // Show modal
            this.modal.fadeIn(300);
            
            // Start timer
            this.startTime = Date.now();
        },
        
        /**
         * Bind modal events
         */
        bindModalEvents: function() {
            const self = this;
            
            // Close/minimize button
            this.modal.find('.voxfor-ml-modal-close, #minimize-modal').on('click', function() {
                self.modal.fadeOut(300);
            });
            
            // Cancel button
            this.modal.find('#cancel-translation').on('click', function() {
                if (confirm('Are you sure you want to cancel the website translation? Progress will be lost.')) {
                    self.cancelTranslation();
                }
            });
            
            // Pause button (for future implementation)
            this.modal.find('#pause-translation').on('click', function() {
                // TODO: Implement pause functionality
                alert('Pause functionality coming soon!');
            });
            
            // Prevent modal close on background click during translation
            this.modal.on('click', function(e) {
                if (e.target === this) {
                    // Don't close during active translation
                    self.modal.find('.voxfor-ml-modal-container').addClass('shake');
                    setTimeout(() => {
                        self.modal.find('.voxfor-ml-modal-container').removeClass('shake');
                    }, 600);
                }
            });
        },
        
        /**
         * Start updating progress
         */
        startUpdating: function() {
            const self = this;
            
            // Update immediately
            this.updateProgress();
            
            // Then update every 2 seconds
            this.updateInterval = setInterval(function() {
                self.updateProgress();
            }, 2000);
        },
        
        /**
         * Update progress from server
         */
        updateProgress: function() {
            const self = this;
            
            if (!this.progressKey) {
                return;
            }
            
            $.post(ajaxurl, {
                action: 'voxfor_ml_get_translation_progress',
                progress_key: this.progressKey,
                nonce: voxforMLStatus?.nonce || ''
            })
            .done(function(response) {
                if (response.success && response.data) {
                    self.updateUI(response.data);
                } else {
                    // No progress data received - warning removed for production
                }
            })
            .fail(function(xhr, status, error) {
                // Failed to get progress - error logging removed for production
                self.addLogEntry('Error: Failed to get progress update', 'error');
            });
        },
        
        /**
         * Update UI with progress data
         */
        updateUI: function(data) {
            const percent = Math.round(data.percent || 0);
            const message = data.message || 'Processing...';
            
            // Update progress bar
            this.modal.find('.voxfor-ml-progress-fill').css('width', percent + '%');
            this.modal.find('.voxfor-ml-progress-text').text(percent + '%');
            
            // Update status message
            this.modal.find('.status-text').text(message);
            
            // Update stats if available
            if (data.stats) {
                this.modal.find('#items-processed').text(data.stats.processed || 0);
                this.modal.find('#translations-success').text(data.stats.successful || 0);
                this.modal.find('#translations-failed').text(data.stats.failed || 0);
            }
            
            // Update elapsed time
            this.updateElapsedTime();
            
            // Add to log
            this.addLogEntry(message);
            
            // Check if complete
            if (percent >= 100) {
                this.handleCompletion(data);
            }
        },
        
        /**
         * Add entry to translation log
         */
        addLogEntry: function(message, type = 'info') {
            const logContainer = this.modal.find('#translation-log');
            const time = this.getCurrentTime();
            
            const logClass = type === 'error' ? 'log-error' : 
                           type === 'success' ? 'log-success' : 'log-info';
            
            const logEntry = `
                <div class="log-entry ${logClass}">
                    <span class="log-time">${time}</span>
                    <span class="log-message">${this.escapeHtml(message)}</span>
                </div>
            `;
            
            logContainer.append(logEntry);
            
            // Auto-scroll to bottom
            logContainer.scrollTop(logContainer[0].scrollHeight);
            
            // Keep only last 50 entries
            const entries = logContainer.find('.log-entry');
            if (entries.length > 50) {
                entries.first().remove();
            }
        },
        
        /**
         * Handle translation completion
         */
        handleCompletion: function(data) {
            // Stop updating
            if (this.updateInterval) {
                clearInterval(this.updateInterval);
                this.updateInterval = null;
            }
            
            // Update status icon
            this.modal.find('.voxfor-ml-current-status .dashicons')
                .removeClass('dashicons-update spin')
                .addClass('dashicons-yes-alt');
            
            // Add completion log
            this.addLogEntry('Website translation completed!', 'success');
            
            // Update buttons
            this.modal.find('#pause-translation, #cancel-translation').prop('disabled', true);
            this.modal.find('#minimize-modal')
                .removeClass('button-primary')
                .addClass('button-secondary')
                .html('<span class="dashicons dashicons-yes"></span> Close');
            
            // Show completion notification
            setTimeout(() => {
                if (confirm('Website translation completed! Would you like to view the results?')) {
                    // Redirect to translation overview or reload page
                    window.location.reload();
                }
            }, 2000);
        },
        
        /**
         * Update elapsed time
         */
        updateElapsedTime: function() {
            if (!this.startTime) return;
            
            const elapsed = Date.now() - this.startTime;
            const minutes = Math.floor(elapsed / 60000);
            const seconds = Math.floor((elapsed % 60000) / 1000);
            
            const timeString = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
            this.modal.find('#elapsed-time').text(timeString);
        },
        
        /**
         * Cancel translation
         */
        cancelTranslation: function() {
            const self = this;
            
            $.post(ajaxurl, {
                action: 'voxfor_ml_cancel_website_translation',
                progress_key: this.progressKey,
                nonce: voxforMLStatus?.nonce || ''
            })
            .done(function(response) {
                self.addLogEntry('Translation cancelled by user', 'error');
                self.hide();
            })
            .fail(function() {
                self.addLogEntry('Failed to cancel translation', 'error');
            });
        },
        
        /**
         * Get current time string
         */
        getCurrentTime: function() {
            const now = new Date();
            return now.getHours().toString().padStart(2, '0') + ':' + 
                   now.getMinutes().toString().padStart(2, '0') + ':' + 
                   now.getSeconds().toString().padStart(2, '0');
        },
        
        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
    };
    
})(jQuery);
