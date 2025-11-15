/**
 * Voxfor Multilanguage - Admin JavaScript
 */

(function($) {
    'use strict';

    // Admin handler
    class VoxforMLAdmin {
        constructor() {
            this.init();
        }

        init() {
            this.bindEvents();
            this.initTabs();
            this.initAjaxForms();
            this.initApiKeyTesting();
        }

        bindEvents() {
            // Delete confirmations
            $(document).on('click', '.voxfor-ml-delete', this.handleDelete.bind(this));
            
            // Toggle switches
            $(document).on('change', '.voxfor-ml-toggle', this.handleToggle.bind(this));
            
            // Bulk actions
            $('#doaction, #doaction2').on('click', this.handleBulkAction.bind(this));
            
            // Search forms
            $('.voxfor-ml-search-form').on('submit', this.handleSearch.bind(this));
            
            // Translation buttons
            $(document).on('click', '.voxfor-ml-quick-translate', this.handleQuickTranslate.bind(this));
            $(document).on('click', '.voxfor-ml-translate-all-langs', this.handleTranslateAllLanguages.bind(this));
        }

        initTabs() {
            $('.voxfor-ml-tabs').each(function() {
                const $tabs = $(this);
                const $links = $tabs.find('.nav-tab');
                const $panels = $tabs.find('.tab-panel');
                
                $links.on('click', function(e) {
                    e.preventDefault();
                    const target = $(this).attr('href');
                    
                    $links.removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    
                    $panels.hide();
                    $(target).show();
                    
                    // Update URL without reload
                    if (history.pushState) {
                        history.pushState(null, null, target);
                    }
                });
                
                // Show active tab on load
                const hash = window.location.hash || $links.first().attr('href');
                $links.filter('[href="' + hash + '"]').click();
            });
        }

        initAjaxForms() {
            $('.voxfor-ml-ajax-form').on('submit', function(e) {
                e.preventDefault();
                
                const $form = $(this);
                const $submit = $form.find('[type="submit"]');
                const originalText = $submit.text();
                
                // Show loading state
                $submit.text(voxforMLAdmin.strings.saving).prop('disabled', true);
                $form.addClass('voxfor-ml-loading');
                
                // Prepare data
                const formData = new FormData(this);
                formData.append('action', $form.data('action'));
                formData.append('nonce', voxforMLAdmin.nonce);
                
                // Send request
                $.ajax({
                    url: voxforMLAdmin.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: (response) => {
                        if (response.success) {
                            this.showNotice('success', response.data.message || voxforMLAdmin.strings.saved);
                            
                            // Trigger custom event
                            $form.trigger('voxfor-ml-saved', [response.data]);
                        } else {
                            this.showNotice('error', response.data.message || voxforMLAdmin.strings.error);
                        }
                    },
                    error: () => {
                        this.showNotice('error', voxforMLAdmin.strings.error);
                    },
                    complete: () => {
                        $submit.text(originalText).prop('disabled', false);
                        $form.removeClass('voxfor-ml-loading');
                    }
                });
            }.bind(this));
        }

        handleDelete(e) {
            e.preventDefault();
            
            if (!confirm(voxforMLAdmin.strings.confirmDelete)) {
                return;
            }
            
            const $link = $(e.currentTarget);
            const url = $link.attr('href');
            
            $link.addClass('voxfor-ml-loading');
            
            $.post(url, { _wpnonce: voxforMLAdmin.nonce }, (response) => {
                if (response.success) {
                    $link.closest('tr, .voxfor-ml-item').fadeOut(() => {
                        $(this).remove();
                    });
                } else {
                    this.showNotice('error', response.data.message || voxforMLAdmin.strings.error);
                }
            }).fail(() => {
                this.showNotice('error', voxforMLAdmin.strings.error);
            }).always(() => {
                $link.removeClass('voxfor-ml-loading');
            });
        }

        handleToggle(e) {
            const $toggle = $(e.currentTarget);
            const url = $toggle.data('url');
            const value = $toggle.is(':checked') ? 1 : 0;
            
            $.post(url, {
                value: value,
                _wpnonce: voxforMLAdmin.nonce
            }, (response) => {
                if (!response.success) {
                    // Revert toggle
                    $toggle.prop('checked', !$toggle.is(':checked'));
                    this.showNotice('error', response.data.message || voxforMLAdmin.strings.error);
                }
            }).fail(() => {
                // Revert toggle
                $toggle.prop('checked', !$toggle.is(':checked'));
                this.showNotice('error', voxforMLAdmin.strings.error);
            });
        }

        handleBulkAction(e) {
            const $button = $(e.currentTarget);
            const $form = $button.closest('form');
            const action = $button.prev('select').val();
            
            if (!action || action === '-1') {
                return;
            }
            
            const $checked = $form.find('input[name="post[]"]:checked, input[name="item[]"]:checked');
            
            if ($checked.length === 0) {
                alert('Please select at least one item.');
                e.preventDefault();
                return;
            }
            
            if (action.includes('delete') && !confirm(voxforMLAdmin.strings.confirmDelete)) {
                e.preventDefault();
                return;
            }
        }

        handleSearch(e) {
            const $form = $(e.currentTarget);
            const $input = $form.find('input[type="search"]');
            
            if ($input.val().trim() === '') {
                e.preventDefault();
                window.location.href = window.location.pathname + window.location.search.replace(/&s=[^&]*/, '');
            }
        }



        showNotice(type, message) {
            const $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            
            $('.wrap > h1').after($notice);
            
            // Auto dismiss after 5 seconds
            setTimeout(() => {
                $notice.fadeOut(() => {
                    $notice.remove();
                });
            }, 5000);
            
            // Make dismissible
            $notice.on('click', '.notice-dismiss', function() {
                $notice.fadeOut(() => {
                    $notice.remove();
                });
            });
        }
        

        initApiKeyTesting() {
            $('#voxfor-ml-test-api-key').on('click', (e) => {
                e.preventDefault();
                
                const $button = $(e.currentTarget);
                const $result = $('#voxfor-ml-api-test-result');
                const apiKey = $('#voxfor_ml_anthropic_api_key').val();
                
                // Show loading state
                $button.prop('disabled', true).text(voxforMLAdmin.strings.testing || 'Testing...');
                $result.html('<span class="spinner is-active" style="float: none; margin: 0;"></span>');
                
                $.post(voxforMLAdmin.ajaxUrl, {
                    action: 'voxfor_ml_test_api_key',
                    nonce: voxforMLAdmin.nonce,
                    api_key: apiKey
                }, (response) => {
                    $button.prop('disabled', false).text(voxforMLAdmin.strings.testApiKey || 'Test API Key');
                    
                    if (response.success) {
                        $result.html('<span style="color: #46b450;"><span class="dashicons dashicons-yes-alt"></span> ' + response.data.message + '</span>');
                    } else {
                        $result.html('<span style="color: #dc3232;"><span class="dashicons dashicons-warning"></span> ' + response.data.message + '</span>');
                    }
                    
                    // Clear result after 10 seconds
                    setTimeout(() => {
                        $result.fadeOut();
                    }, 10000);
                }).fail(() => {
                    $button.prop('disabled', false).text(voxforMLAdmin.strings.testApiKey || 'Test API Key');
                    $result.html('<span style="color: #dc3232;"><span class="dashicons dashicons-warning"></span> Connection error</span>');
                });
            });
        }
        
        handleQuickTranslate(e) {
            e.preventDefault();
            const $link = $(e.currentTarget);
            const postId = $link.data('post-id');
            const language = $link.data('language');
            
            if (!postId || !language) {
                this.showNotice('error', 'Invalid translation parameters');
                return;
            }
            
            // Show loading state
            const originalText = $link.text();
            $link.text(voxforMLAdmin.strings.translating || 'Translating...');
            
            $.post(voxforMLAdmin.ajaxUrl, {
                action: 'voxfor_ml_create_translation',
                nonce: voxforMLAdmin.translationNonce || voxforMLAdmin.nonce,
                post_id: postId,
                language: language
            }, (response) => {
                if (response.success) {
                    // Reload the page to show updated status
                    location.reload();
                } else {
                    this.showNotice('error', response.data.message || 'Translation failed');
                    $link.text(originalText);
                }
            }).fail(() => {
                this.showNotice('error', 'Connection error');
                $link.text(originalText);
            });
        }
        
        handleTranslateAllLanguages(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const postId = $button.data('post-id');
            
            if (!postId) {
                this.showNotice('error', 'Invalid post ID');
                return;
            }
            
            if (!confirm(voxforMLAdmin.strings.confirmTranslateAll || 'Translate this content to all languages?')) {
                return;
            }
            
            // Show loading state
            const originalText = $button.text();
            $button.prop('disabled', true).text(voxforMLAdmin.strings.translating || 'Translating...');
            
            // Get all untranslated languages for this post
            const languages = [];
            $button.closest('tr').find('.voxfor-ml-status-icon').each(function() {
                if ($(this).text() === '✗') {
                    const lang = $(this).closest('td').find('.voxfor-ml-quick-translate').data('language');
                    if (lang) languages.push(lang);
                }
            });
            
            if (languages.length === 0) {
                $button.prop('disabled', false).text(originalText);
                this.showNotice('info', 'No languages need translation');
                return;
            }
            
            // Create translations for all languages
            let completed = 0;
            let failed = 0;
            
            languages.forEach((language) => {
                $.post(voxforMLAdmin.ajaxUrl, {
                    action: 'voxfor_ml_create_translation',
                    nonce: voxforMLAdmin.translationNonce || voxforMLAdmin.nonce,
                    post_id: postId,
                    language: language
                }, (response) => {
                    completed++;
                    if (!response.success) failed++;
                    
                    if (completed === languages.length) {
                        if (failed === 0) {
                            location.reload();
                        } else {
                            this.showNotice('warning', `${completed - failed} translations completed, ${failed} failed`);
                            $button.prop('disabled', false).text(originalText);
                        }
                    }
                }).fail(() => {
                    completed++;
                    failed++;
                    
                    if (completed === languages.length) {
                        this.showNotice('error', `${completed - failed} translations completed, ${failed} failed`);
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            });
        }
    }

    // Translation Memory Manager
    class TranslationMemoryManager {
        constructor() {
            this.init();
        }

        init() {
            this.bindEvents();
            this.initInlineEdit();
        }

        bindEvents() {
            // Lock/Unlock translations
            $(document).on('click', '.voxfor-ml-lock-toggle', this.handleLockToggle.bind(this));
            
            // Edit translation
            $(document).on('click', '.voxfor-ml-edit-translation', this.handleEdit.bind(this));
            
            // Filter by language/context
            $('#voxfor-ml-filter-language, #voxfor-ml-filter-context').on('change', this.handleFilter.bind(this));
        }

        initInlineEdit() {
            $('.voxfor-ml-editable').on('click', function() {
                const $cell = $(this);
                const originalText = $cell.text();
                const $input = $('<textarea class="voxfor-ml-inline-edit">' + originalText + '</textarea>');
                
                $cell.html($input);
                $input.focus().select();
                
                $input.on('blur', function() {
                    const newText = $(this).val();
                    
                    if (newText !== originalText) {
                        // Save via API
                        $.post(voxforMLAdmin.restUrl + 'translations/' + $cell.data('id'), {
                            translated_text: newText,
                            _wpnonce: voxforMLAdmin.nonce
                        }, (response) => {
                            if (response.success) {
                                $cell.text(newText);
                            } else {
                                $cell.text(originalText);
                                alert(voxforMLAdmin.strings.error);
                            }
                        });
                    } else {
                        $cell.text(originalText);
                    }
                });
                
                $input.on('keydown', function(e) {
                    if (e.key === 'Escape') {
                        $(this).val(originalText).blur();
                    } else if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        $(this).blur();
                    }
                });
            });
        }

        handleLockToggle(e) {
            e.preventDefault();
            
            const $button = $(e.currentTarget);
            const id = $button.data('id');
            const isLocked = $button.hasClass('locked');
            
            $.ajax({
                url: voxforMLAdmin.restUrl + 'translations/' + id,
                method: 'PUT',
                headers: {
                    'X-WP-Nonce': voxforMLAdmin.nonce
                },
                data: JSON.stringify({
                    is_locked: !isLocked
                }),
                contentType: 'application/json',
                success: (response) => {
                    if (response.success) {
                        $button.toggleClass('locked');
                        $button.find('.dashicons')
                            .toggleClass('dashicons-lock')
                            .toggleClass('dashicons-unlock');
                    }
                }
            });
        }

        handleEdit(e) {
            e.preventDefault();
            
            const $button = $(e.currentTarget);
            const id = $button.data('id');
            
            // Open edit modal
            this.openEditModal(id);
        }

        handleFilter() {
            const language = $('#voxfor-ml-filter-language').val();
            const context = $('#voxfor-ml-filter-context').val();
            
            let url = window.location.pathname + '?page=' + this.getQueryParam('page');
            
            if (language) {
                url += '&lang=' + language;
            }
            
            if (context) {
                url += '&context=' + context;
            }
            
            window.location.href = url;
        }

        openEditModal(id) {
            // Implementation for edit modal
                            // Edit translation functionality
        }

        getQueryParam(param) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(param);
        }
    }

    // Initialize on document ready
    $(document).ready(() => {
        new VoxforMLAdmin();
        
        // Initialize specific managers based on current page
        if ($('.voxfor-ml-translation-table').length) {
            new TranslationMemoryManager();
        }
    });

})(jQuery);