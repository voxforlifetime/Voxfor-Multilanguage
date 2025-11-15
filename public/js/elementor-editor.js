/**
 * Voxfor Multilanguage Elementor Editor Integration
 */
(function($) {
    'use strict';
    
    // Wait for Elementor to be ready
    $(window).on('elementor:init', function() {
        initVoxforMLElementorIntegration();
    });
    
    function initVoxforMLElementorIntegration() {
        // Add translation panel to Elementor editor
        addTranslationPanel();
        
        // Hook into widget save events
        hookWidgetSaveEvents();
        
        // Add translation buttons to widgets
        addTranslationButtons();
    }
    
    function addTranslationPanel() {
        // This would add a translation panel to the Elementor editor
        // Elementor translation integration loaded
    }
    
    function hookWidgetSaveEvents() {
        // Hook into Elementor's widget save events to track content changes
        if (typeof elementor !== 'undefined' && elementor.hooks) {
            elementor.hooks.addAction('panel/open_editor/widget', function(panel, model, view) {
                // Widget editor opened - could add translation controls here
            });
        }
    }
    
    function addTranslationButtons() {
        // Add translation buttons to relevant widget controls
        // This would integrate with Elementor's control system
    }
    
    // Utility function to translate widget content
    window.voxforMLTranslateWidget = function(widgetId, language) {
        if (!window.voxforMLElementor) {
            return;
        }
        
        $.post(voxforMLElementor.ajaxUrl, {
            action: 'voxfor_ml_translate_elementor_widget',
            nonce: voxforMLElementor.nonce,
            widget_id: widgetId,
            language: language
        })
        .done(function(response) {
            if (response.success) {
                // Widget translated successfully
                // Refresh the widget in editor
                if (typeof elementor !== 'undefined') {
                    elementor.trigger('document:loaded');
                }
            }
        });
    };
    
})(jQuery);
