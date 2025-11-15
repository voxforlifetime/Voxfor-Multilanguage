/**
 * Voxfor Multilanguage - Visual Editor
 */
(function($) {
    'use strict';
    
    const VoxforMLVisualEditor = {
        segments: [],
        currentSegment: null,
        isActive: false,
        
        init: function() {
            if (!$('body').hasClass('voxfor-ml-edit-mode')) {
                return;
            }
            
            this.isActive = true;
            this.bindEvents();
            this.loadSegments();
        },
        
        bindEvents: function() {
            // Click on translatable elements
            $(document).on('click', '.voxfor-ml-translatable', this.onSegmentClick.bind(this));
            
            // Editor actions
            $(document).on('click', '#voxfor-ml-save-translation', this.saveTranslation.bind(this));
            $(document).on('click', '#voxfor-ml-cancel-edit', this.cancelEdit.bind(this));
            $(document).on('click', '#voxfor-ml-lock-translation', this.toggleLock.bind(this));
            $(document).on('change', '#voxfor-ml-translation-status', this.updateStatus.bind(this));
            
            // Keyboard shortcuts
            $(document).on('keydown', this.handleKeyboard.bind(this));
        },
        
        loadSegments: function() {
            $.post(voxforMLEditor.ajaxUrl, {
                action: 'voxfor_ml_get_segments',
                nonce: voxforMLEditor.nonce,
                post_id: this.getPostId(),
                language: voxforMLEditor.currentLang
            }, (response) => {
                if (response.success) {
                    this.segments = response.data;
                    // Loaded segments for translation
                    this.highlightSegments();
                } else {
                    // Failed to load segments - error logging removed for production
                }
            }).fail((xhr, status, error) => {
                // AJAX error loading segments - error logging removed for production
            });
        },
        
        highlightSegments: function() {
            // Clear existing highlights
            $('.voxfor-ml-translatable').removeClass('voxfor-ml-translatable');
            $('.voxfor-ml-segment-overlay').remove();
            
            this.segments.forEach(segment => {
                this.highlightSegment(segment);
            });
        },
        
        highlightSegment: function(segment) {
            let $targetElement = null;
            
            // Different highlighting strategies based on element type
            switch(segment.type) {
                case 'title':
                    // Product title - look for h1, .product_title, or entry-title
                    $targetElement = this.findProductTitle(segment.original);
                    break;
                    
                case 'short_description':
                    // Short description - look for .woocommerce-product-details__short-description
                    $targetElement = this.findShortDescription(segment.original);
                    break;
                    
                case 'description':
                    // Full description - look for .woocommerce-Tabs-panel--description
                    $targetElement = this.findProductDescription(segment.original);
                    break;
                    
                case 'button':
                    // Add to cart button
                    $targetElement = this.findAddToCartButton(segment.original);
                    break;
                    
                case 'category':
                case 'tag':
                    // Categories and tags
                    $targetElement = this.findCategoryOrTag(segment.original);
                    break;
                    
                case 'attribute':
                    // Product attributes
                    $targetElement = this.findAttribute(segment.original);
                    break;
                    
                case 'tab_title':
                    // Tab titles
                    $targetElement = this.findTabTitle(segment.original);
                    break;
                    
                default:
                    // Fallback: find by text content
                    $targetElement = this.findByTextContent(segment.original);
                    break;
            }
            
            if ($targetElement && $targetElement.length > 0) {
                this.makeElementTranslatable($targetElement, segment);
            } else {
                // If we can't find the element, create a virtual segment
                this.createVirtualSegment(segment);
            }
        },
        
        findProductTitle: function(text) {
            // Look for product title in various possible locations
            const selectors = [
                'h1.product_title',
                '.product_title',
                'h1.entry-title',
                '.entry-title',
                'h1:contains("' + this.escapeSelector(text) + '")',
                '.product-title:contains("' + this.escapeSelector(text) + '")'
            ];
            
            for (let selector of selectors) {
                const $el = $(selector).filter(function() {
                    return $(this).text().trim() === text;
                });
                if ($el.length > 0) return $el.first();
            }
            return null;
        },
        
        findShortDescription: function(text) {
            const selectors = [
                '.woocommerce-product-details__short-description',
                '.product-short-description',
                '.short-description'
            ];
            
            for (let selector of selectors) {
                const $el = $(selector).filter(function() {
                    return $(this).text().trim().includes(text.substring(0, 50));
                });
                if ($el.length > 0) return $el.first();
            }
            return null;
        },
        
        findProductDescription: function(text) {
            const selectors = [
                '.woocommerce-Tabs-panel--description',
                '#tab-description',
                '.product-description',
                '.description'
            ];
            
            for (let selector of selectors) {
                const $el = $(selector).filter(function() {
                    return $(this).text().trim().includes(text.substring(0, 50));
                });
                if ($el.length > 0) return $el.first();
            }
            return null;
        },
        
        findAddToCartButton: function(text) {
            const selectors = [
                '.single_add_to_cart_button',
                '.add_to_cart_button',
                'button[name="add-to-cart"]',
                '.cart button'
            ];
            
            for (let selector of selectors) {
                const $el = $(selector).filter(function() {
                    return $(this).text().trim() === text || $(this).val() === text;
                });
                if ($el.length > 0) return $el.first();
            }
            return null;
        },
        
        findCategoryOrTag: function(text) {
            const selectors = [
                '.posted_in a:contains("' + this.escapeSelector(text) + '")',
                '.product_meta a:contains("' + this.escapeSelector(text) + '")',
                '.product-categories a:contains("' + this.escapeSelector(text) + '")',
                '.product-tags a:contains("' + this.escapeSelector(text) + '")'
            ];
            
            for (let selector of selectors) {
                const $el = $(selector).filter(function() {
                    return $(this).text().trim() === text;
                });
                if ($el.length > 0) return $el.first();
            }
            return null;
        },
        
        findAttribute: function(text) {
            const selectors = [
                '.woocommerce-product-attributes-item__label:contains("' + this.escapeSelector(text) + '")',
                '.product-attributes .attribute-label:contains("' + this.escapeSelector(text) + '")',
                'table.woocommerce-product-attributes th:contains("' + this.escapeSelector(text) + '")'
            ];
            
            for (let selector of selectors) {
                const $el = $(selector).filter(function() {
                    return $(this).text().trim() === text;
                });
                if ($el.length > 0) return $el.first();
            }
            return null;
        },
        
        findTabTitle: function(text) {
            const selectors = [
                '.woocommerce-tabs .tabs li a:contains("' + this.escapeSelector(text) + '")',
                '.wc-tabs li a:contains("' + this.escapeSelector(text) + '")',
                '.product-tabs a:contains("' + this.escapeSelector(text) + '")'
            ];
            
            for (let selector of selectors) {
                const $el = $(selector).filter(function() {
                    return $(this).text().trim() === text;
                });
                if ($el.length > 0) return $el.first();
            }
            return null;
        },
        
        findByTextContent: function(text) {
            // Fallback method - find by exact text content
            return $('*:contains("' + this.escapeSelector(text) + '")').filter(function() {
                return $(this).children().length === 0 && $(this).text().trim() === text;
            }).first();
        },
        
        makeElementTranslatable: function($element, segment) {
            $element.addClass('voxfor-ml-translatable');
            $element.attr('data-segment-id', segment.id);
            
            // Add status indicator
            const statusClass = 'voxfor-ml-status-' + segment.status;
            $element.addClass(statusClass);
            
            if (segment.locked) {
                $element.addClass('voxfor-ml-locked');
            }
            
            // Add type-specific styling
            $element.addClass('voxfor-ml-type-' + segment.type);
        },
        
        createVirtualSegment: function(segment) {
            // Create a floating segment indicator for elements we can't find
            const $indicator = $('<div class="voxfor-ml-segment-overlay">')
                .text(segment.label || segment.type)
                .attr('data-segment-id', segment.id)
                .addClass('voxfor-ml-translatable voxfor-ml-virtual-segment')
                .addClass('voxfor-ml-type-' + segment.type);
            
            // Position it in a segments panel
            this.ensureSegmentsPanel();
            $('#voxfor-ml-segments-panel .segments-list').append($indicator);
        },
        
        ensureSegmentsPanel: function() {
            if ($('#voxfor-ml-segments-panel').length === 0) {
                const $panel = $('<div id="voxfor-ml-segments-panel">')
                    .html(`
                        <div class="panel-header">
                            <h4>Translatable Elements</h4>
                            <button class="close-panel">&times;</button>
                        </div>
                        <div class="segments-list"></div>
                    `);
                
                $('body').append($panel);
                
                // Handle panel close
                $panel.find('.close-panel').on('click', function() {
                    $panel.hide();
                });
            }
        },
        
        escapeSelector: function(text) {
            return text.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
        },
        
        onSegmentClick: function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const $element = $(e.currentTarget);
            const segmentId = $element.data('segment-id');
            const segment = this.segments.find(s => s.id === segmentId);
            
            if (!segment) {
                return;
            }
            
            this.currentSegment = segment;
            this.showEditor(segment, $element);
        },
        
        showEditor: function(segment, $element) {
            // Remove existing editor
            $('#voxfor-ml-editor-popup').remove();
            
            // Create editor popup
            const elementLabel = segment.label || this.getElementTypeLabel(segment.type);
            const editorHtml = `
                <div id="voxfor-ml-editor-popup" class="voxfor-ml-editor-popup">
                    <div class="voxfor-ml-editor-header">
                        <h3>${voxforMLEditor.strings.edit}: ${elementLabel}</h3>
                        <button type="button" class="voxfor-ml-close" id="voxfor-ml-cancel-edit">&times;</button>
                    </div>
                    <div class="voxfor-ml-editor-body">
                        <div class="voxfor-ml-field voxfor-ml-element-info">
                            <div class="voxfor-ml-element-type">
                                <span class="voxfor-ml-type-badge voxfor-ml-type-${segment.type}">${elementLabel}</span>
                            </div>
                        </div>
                        <div class="voxfor-ml-field">
                            <label>Original (${voxforMLEditor.defaultLang}):</label>
                            <div class="voxfor-ml-original">${this.escapeHtml(segment.original)}</div>
                        </div>
                        <div class="voxfor-ml-field">
                            <label>Translation (${voxforMLEditor.currentLang}):</label>
                            <textarea id="voxfor-ml-translation-text" class="voxfor-ml-translation" 
                                ${segment.locked ? 'disabled' : ''}>${segment.translation || ''}</textarea>
                        </div>
                        <div class="voxfor-ml-field voxfor-ml-meta">
                            <label>Status:</label>
                            <select id="voxfor-ml-translation-status" ${segment.locked ? 'disabled' : ''}>
                                <option value="draft" ${segment.status === 'draft' ? 'selected' : ''}>
                                    ${voxforMLEditor.strings.draft}
                                </option>
                                <option value="reviewed" ${segment.status === 'reviewed' ? 'selected' : ''}>
                                    ${voxforMLEditor.strings.reviewed}
                                </option>
                                ${segment.status === 'locked' ? 
                                    `<option value="locked" selected>${voxforMLEditor.strings.locked_status}</option>` : 
                                    ''}
                            </select>
                            ${this.canLock() ? 
                                `<button type="button" id="voxfor-ml-lock-translation" class="voxfor-ml-lock-btn">
                                    ${segment.locked ? '🔓 Unlock' : '🔒 Lock'}
                                </button>` : 
                                ''}
                        </div>
                    </div>
                    <div class="voxfor-ml-editor-footer">
                        <button type="button" id="voxfor-ml-save-translation" class="button button-primary" 
                            ${segment.locked ? 'disabled' : ''}>${voxforMLEditor.strings.save}</button>
                        <button type="button" id="voxfor-ml-cancel-edit" class="button">
                            ${voxforMLEditor.strings.cancel}
                        </button>
                    </div>
                </div>
            `;
            
            // Position near element
            const $editor = $(editorHtml);
            $('body').append($editor);
            
            const offset = $element.offset();
            const editorHeight = $editor.outerHeight();
            const windowHeight = $(window).height();
            const scrollTop = $(window).scrollTop();
            
            let top = offset.top + $element.outerHeight() + 10;
            
            // Check if editor would go below viewport
            if (top + editorHeight > scrollTop + windowHeight) {
                // Position above element instead
                top = offset.top - editorHeight - 10;
            }
            
            $editor.css({
                top: top + 'px',
                left: offset.left + 'px'
            });
            
            // Focus on textarea
            $('#voxfor-ml-translation-text').focus();
        },
        
        saveTranslation: function() {
            const translation = $('#voxfor-ml-translation-text').val();
            const status = $('#voxfor-ml-translation-status').val();
            
            if (!translation.trim()) {
                alert('Please enter a translation');
                return;
            }
            
            // Show saving state
            const $saveBtn = $('#voxfor-ml-save-translation');
            $saveBtn.text(voxforMLEditor.strings.saving).prop('disabled', true);
            
            $.post(voxforMLEditor.ajaxUrl, {
                action: 'voxfor_ml_save_segment',
                nonce: voxforMLEditor.nonce,
                segment_id: this.currentSegment.id,
                original: this.currentSegment.original,
                translation: translation,
                language: voxforMLEditor.currentLang,
                context: this.currentSegment.context,
                status: status
            }, (response) => {
                if (response.success) {
                    // Update segment data
                    this.currentSegment.translation = translation;
                    this.currentSegment.status = status;
                    
                    // Update display
                    const $element = $(`[data-segment-id="${this.currentSegment.id}"]`);
                    $element.removeClass('voxfor-ml-status-untranslated voxfor-ml-status-draft voxfor-ml-status-reviewed');
                    $element.addClass('voxfor-ml-status-' + status);
                    
                    // CRITICAL FIX: Update the actual text content that users see
                    $element.text(translation);
                    
                    // Show success
                    $saveBtn.text(voxforMLEditor.strings.saved);
                    setTimeout(() => {
                        this.closeEditor();
                    }, 1000);
                } else {
                    alert(response.data.message || voxforMLEditor.strings.error);
                    $saveBtn.text(voxforMLEditor.strings.save).prop('disabled', false);
                }
            });
        },
        
        toggleLock: function() {
            const newLocked = !this.currentSegment.locked;
            
            $.post(voxforMLEditor.ajaxUrl, {
                action: 'voxfor_ml_lock_segment',
                nonce: voxforMLEditor.nonce,
                segment_id: this.currentSegment.id,
                original: this.currentSegment.original,
                language: voxforMLEditor.currentLang,
                context: this.currentSegment.context,
                locked: newLocked
            }, (response) => {
                if (response.success) {
                    this.currentSegment.locked = newLocked;
                    
                    // Update UI
                    const $element = $(`[data-segment-id="${this.currentSegment.id}"]`);
                    if (newLocked) {
                        $element.addClass('voxfor-ml-locked');
                    } else {
                        $element.removeClass('voxfor-ml-locked');
                    }
                    
                    // Refresh editor
                    this.showEditor(this.currentSegment, $element);
                }
            });
        },
        
        updateStatus: function() {
            // Status will be saved with translation
        },
        
        cancelEdit: function() {
            this.closeEditor();
        },
        
        closeEditor: function() {
            $('#voxfor-ml-editor-popup').remove();
            this.currentSegment = null;
        },
        
        handleKeyboard: function(e) {
            // ESC to close editor
            if (e.keyCode === 27 && this.currentSegment) {
                this.closeEditor();
            }
            
            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.keyCode === 83 && this.currentSegment) {
                e.preventDefault();
                this.saveTranslation();
            }
        },
        
        getPostId: function() {
            // Try to get post ID from various sources
            return $('body').attr('class').match(/postid-(\d+)/)?.[1] || 
                   $('article').first().attr('id')?.replace('post-', '') ||
                   0;
        },
        
        canLock: function() {
            // Check if user can manage options (admin capability)
            // This should be passed from PHP, but for now we'll check if lock button should show
            return true; // Will be filtered server-side
        },
        
        escapeHtml: function(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            
            return text.replace(/[&<>"']/g, m => map[m]);
        },
        
        getElementTypeLabel: function(type) {
            const labels = {
                'title': 'Product Name',
                'short_description': 'Short Description',
                'description': 'Product Description',
                'button': 'Add to Cart Button',
                'category': 'Category',
                'tag': 'Tag',
                'attribute': 'Attribute',
                'tab_title': 'Tab Title',
                'database': 'Database Entry',
                'meta': 'Meta Field',
                'content': 'Content'
            };
            
            return labels[type] || type.charAt(0).toUpperCase() + type.slice(1);
        }
    };
    
    // Initialize when ready
    $(document).ready(function() {
        VoxforMLVisualEditor.init();
    });
    
})(jQuery);