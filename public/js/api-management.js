/**
 * Voxfor ML API Management JavaScript
 */
(function($) {
    'use strict';
    
    // Global functions for admin bar
    window.voxforMLToggleAPI = function() {
        if (!confirm(voxforMLApiMgmt.strings.confirmToggle)) {
            return false;
        }
        
        const formData = new FormData();
        formData.append('action', 'voxfor_ml_toggle_api');
        formData.append('nonce', voxforMLApiMgmt.nonces.toggle_api);
        
        fetch(voxforMLApiMgmt.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update admin bar visual state
                updateAdminBarStatus(data.data.status);
                
                // Show success message
                showNotification(data.data.message, 'success');
            } else {
                showNotification(data.data.message || voxforMLApiMgmt.strings.error, 'error');
            }
        })
        .catch(error => {
            showNotification(voxforMLApiMgmt.strings.requestFailed + error.message, 'error');
        });
        
        return false;
    };
    
    window.voxforMLEmergencyStop = function() {
        if (!confirm(voxforMLApiMgmt.strings.confirmEmergencyStop)) {
            return false;
        }
        
        const formData = new FormData();
        formData.append('action', 'voxfor_ml_emergency_stop');
        formData.append('nonce', voxforMLApiMgmt.nonces.emergency_stop);
        
        fetch(voxforMLApiMgmt.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update admin bar to show emergency stop state
                updateAdminBarEmergencyState();
                
                // Show emergency stop message
                showNotification(data.data.message, 'warning');
            } else {
                showNotification(data.data.message || voxforMLApiMgmt.strings.error, 'error');
            }
        })
        .catch(error => {
            showNotification(voxforMLApiMgmt.strings.requestFailed + error.message, 'error');
        });
        
        return false;
    };
    
    function updateAdminBarStatus(apiEnabled) {
        const adminBarItem = $('#wp-admin-bar-voxfor-ml-api-control');
        const icon = adminBarItem.find('.ab-icon');
        const toggleItem = $('#wp-admin-bar-voxfor-ml-toggle-api a');
        
        if (apiEnabled) {
            icon.css('color', '#46b450');
            toggleItem.text(voxforMLApiMgmt.strings.pauseApi);
        } else {
            icon.css('color', '#dc3232');
            toggleItem.text(voxforMLApiMgmt.strings.resumeApi);
        }
        
        // Update usage stats if available
        refreshUsageStats();
    }
    
    function updateAdminBarEmergencyState() {
        const adminBarItem = $('#wp-admin-bar-voxfor-ml-api-control');
        const icon = adminBarItem.find('.ab-icon');
        
        // Make icon red and add warning indicator
        icon.css('color', '#dc3232');
        
        // Hide emergency stop button, show resume
        $('#wp-admin-bar-voxfor-ml-emergency-stop').hide();
        
        const toggleItem = $('#wp-admin-bar-voxfor-ml-toggle-api a');
        toggleItem.text(voxforMLApiMgmt.strings.resumeApi);
    }
    
    function refreshUsageStats() {
        const formData = new FormData();
        formData.append('action', 'voxfor_ml_get_usage_stats');
        
        fetch(voxforMLApiMgmt.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateUsageDisplay(data.data);
            }
        })
        .catch(error => {
                            // Failed to refresh usage stats
        });
    }
    
    function updateUsageDisplay(stats) {
        const usageItem = $('#wp-admin-bar-voxfor-ml-usage-today .ab-item');
        
        if (stats.limits.daily_limit > 0) {
            const percentage = ((stats.limits.daily_used / stats.limits.daily_limit) * 100).toFixed(1);
            const remaining = stats.limits.daily_limit - stats.limits.daily_used;
            
            usageItem.text(
                voxforMLApiMgmt.strings.todayUsage
                    .replace('%used%', stats.limits.daily_used.toLocaleString())
                    .replace('%limit%', stats.limits.daily_limit.toLocaleString())
                    .replace('%percentage%', percentage)
            );
        } else {
            usageItem.text(
                voxforMLApiMgmt.strings.todayUsageNoLimit
                    .replace('%used%', stats.limits.daily_used.toLocaleString())
            );
        }
    }
    
    function showNotification(message, type) {
        // Create notification element
        const notification = $('<div class="voxfor-ml-notification voxfor-ml-notification-' + type + '">')
            .text(message)
            .css({
                position: 'fixed',
                top: '32px',
                right: '20px',
                background: type === 'success' ? '#46b450' : type === 'warning' ? '#f56e28' : '#dc3232',
                color: '#fff',
                padding: '12px 20px',
                borderRadius: '4px',
                zIndex: 100000,
                fontWeight: '500',
                boxShadow: '0 2px 8px rgba(0,0,0,0.2)',
                maxWidth: '400px'
            });
        
        // Add to page
        $('body').append(notification);
        
        // Fade in
        notification.fadeIn(300);
        
        // Auto-remove after 5 seconds
        setTimeout(function() {
            notification.fadeOut(300, function() {
                notification.remove();
            });
        }, 5000);
        
        // Click to dismiss
        notification.on('click', function() {
            notification.fadeOut(300, function() {
                notification.remove();
            });
        });
    }
    
    // Page-specific functionality
    $(document).ready(function() {
        
        // API Management page enhancements
        if ($('.voxfor-ml-api-management-container').length) {
            
            // Auto-refresh usage stats every 30 seconds
            setInterval(refreshUsageStats, 30000);
            
            // Confirm form submission for critical settings
            $('form').on('submit', function(e) {
                const apiEnabled = $('input[name="voxfor_ml_api_enabled"]').is(':checked');
                const dailyLimit = parseInt($('input[name="voxfor_ml_daily_credit_limit"]').val()) || 0;
                const monthlyLimit = parseInt($('input[name="voxfor_ml_monthly_credit_limit"]').val()) || 0;
                
                // Warn about removing limits
                if (dailyLimit === 0 && monthlyLimit === 0 && apiEnabled) {
                    if (!confirm(voxforMLApiMgmt.strings.confirmNoLimits)) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
            
            // Real-time limit calculations
            $('input[name="voxfor_ml_daily_credit_limit"], input[name="voxfor_ml_monthly_credit_limit"]').on('input', function() {
                calculateEstimatedCosts();
            });
        }
    });
    
    function calculateEstimatedCosts() {
        const dailyLimit = parseInt($('input[name="voxfor_ml_daily_credit_limit"]').val()) || 0;
        const monthlyLimit = parseInt($('input[name="voxfor_ml_monthly_credit_limit"]').val()) || 0;
        
        // DeepL pricing: ~$20 per 1M characters
        const costPerChar = 0.00002;
        
        if (dailyLimit > 0) {
            const dailyCost = dailyLimit * costPerChar;
            showCostEstimate('daily', dailyCost);
        }
        
        if (monthlyLimit > 0) {
            const monthlyCost = monthlyLimit * costPerChar;
            showCostEstimate('monthly', monthlyCost);
        }
    }
    
    function showCostEstimate(period, cost) {
        const input = $('input[name="voxfor_ml_' + period + '_credit_limit"]');
        let estimateElement = input.siblings('.cost-estimate');
        
        if (estimateElement.length === 0) {
            estimateElement = $('<span class="cost-estimate" style="color: #646970; font-size: 12px; margin-left: 10px;"></span>');
            input.after(estimateElement);
        }
        
        estimateElement.text('(~$' + cost.toFixed(2) + ' max)');
    }
    
})(jQuery);
