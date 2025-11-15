/**
 * Voxfor Multilanguage - Frontend JavaScript
 * Handles language switcher interactions and visual editor
 */

// Global function for inline onclick - define immediately
window.voxforMLSwitchLanguage = function(selectedUrl) {
    if (selectedUrl) {
        // Extract language from URL
        let lang = 'en'; // default
        const urlParts = selectedUrl.split('/');
        const hostIndex = urlParts.findIndex(part => part.includes('localhost'));
        if (hostIndex >= 0 && urlParts[hostIndex + 1] && urlParts[hostIndex + 1].length === 2) {
            lang = urlParts[hostIndex + 1];
        }
        
        // Update cookie
        const date = new Date();
        date.setTime(date.getTime() + (365 * 24 * 60 * 60 * 1000));
        document.cookie = `voxfor_ml_lang=${lang};expires=${date.toUTCString()};path=/`;
        
        window.location.href = selectedUrl;
    }
};

(function($) {
    'use strict';

    // Quiet console in production unless explicitly enabled
    function voxforDebugLog() {
        // Debug logging disabled in production
        if (window.voxforML && window.voxforML.debug === true) {
            // Console logging removed for production
        }
    }
    
    // Frontend Translation Cache Utility
    const VoxforCache = {
        // Cache configuration
        config: {
            prefix: 'voxfor_ml_',
            defaultTTL: 24 * 60 * 60 * 1000, // 24 hours
            maxSize: 5 * 1024 * 1024, // 5MB max localStorage
            enableCompression: true
        },
        
        // Get item from cache
        get: function(key) {
            try {
                const fullKey = this.config.prefix + key;
                const cached = localStorage.getItem(fullKey);
                
                if (!cached) return null;
                
                const data = JSON.parse(cached);
                
                // Check if expired
                if (data.expires && data.expires < Date.now()) {
                    localStorage.removeItem(fullKey);
                    return null;
                }
                
                // Update last accessed time
                data.lastAccessed = Date.now();
                localStorage.setItem(fullKey, JSON.stringify(data));
                
                return data.value;
            } catch (e) {
                voxforDebugLog('Cache get error:', e);
                return null;
            }
        },
        
        // Set item in cache
        set: function(key, value, ttl = null) {
            try {
                const fullKey = this.config.prefix + key;
                const data = {
                    value: value,
                    expires: Date.now() + (ttl || this.config.defaultTTL),
                    created: Date.now(),
                    lastAccessed: Date.now()
                };
                
                const serialized = JSON.stringify(data);
                
                // Check size before storing
                if (serialized.length > this.config.maxSize / 10) {
                    voxforDebugLog('Cache item too large, skipping:', key);
                    return false;
                }
                
                // Try to store, handle quota exceeded
                try {
                    localStorage.setItem(fullKey, serialized);
                } catch (e) {
                    if (e.name === 'QuotaExceededError') {
                        this.cleanup();
                        // Try once more after cleanup
                        localStorage.setItem(fullKey, serialized);
                    } else {
                        throw e;
                    }
                }
                
                return true;
            } catch (e) {
                voxforDebugLog('Cache set error:', e);
                return false;
            }
        },
        
        // Remove item from cache
        remove: function(key) {
            try {
                localStorage.removeItem(this.config.prefix + key);
                return true;
            } catch (e) {
                return false;
            }
        },
        
        // Clear all cache items
        clear: function() {
            try {
                const keys = Object.keys(localStorage);
                keys.forEach(key => {
                    if (key.startsWith(this.config.prefix)) {
                        localStorage.removeItem(key);
                    }
                });
                return true;
            } catch (e) {
                return false;
            }
        },
        
        // Cleanup expired items and least recently used if needed
        cleanup: function() {
            try {
                const items = [];
                const keys = Object.keys(localStorage);
                
                // Collect all cache items
                keys.forEach(key => {
                    if (key.startsWith(this.config.prefix)) {
                        try {
                            const data = JSON.parse(localStorage.getItem(key));
                            items.push({
                                key: key,
                                data: data,
                                size: localStorage.getItem(key).length
                            });
                        } catch (e) {
                            // Remove corrupted items
                            localStorage.removeItem(key);
                        }
                    }
                });
                
                // Remove expired items
                const now = Date.now();
                items.forEach(item => {
                    if (item.data.expires && item.data.expires < now) {
                        localStorage.removeItem(item.key);
                    }
                });
                
                // If still need space, remove least recently used
                const totalSize = items.reduce((sum, item) => sum + item.size, 0);
                if (totalSize > this.config.maxSize * 0.8) {
                    // Sort by last accessed time
                    items.sort((a, b) => (a.data.lastAccessed || 0) - (b.data.lastAccessed || 0));
                    
                    // Remove oldest 20%
                    const toRemove = Math.floor(items.length * 0.2);
                    for (let i = 0; i < toRemove; i++) {
                        localStorage.removeItem(items[i].key);
                    }
                }
                
                voxforDebugLog('Cache cleanup completed');
            } catch (e) {
                voxforDebugLog('Cache cleanup error:', e);
            }
        },
        
        // Get cache statistics
        getStats: function() {
            let count = 0;
            let size = 0;
            const keys = Object.keys(localStorage);
            
            keys.forEach(key => {
                if (key.startsWith(this.config.prefix)) {
                    count++;
                    size += (localStorage.getItem(key) || '').length;
                }
            });
            
            return {
                count: count,
                size: size,
                sizeInMB: (size / (1024 * 1024)).toFixed(2)
            };
        }
    };
    
    // Expose cache utility globally
    window.VoxforCache = VoxforCache;
    
    // Run cleanup on load
    if (Math.random() < 0.1) { // 10% chance to run cleanup
        setTimeout(() => VoxforCache.cleanup(), 5000);
    }

    // Language Switcher Handler
    class VoxforLanguageSwitcher {
        constructor(element) {
            this.$element = $(element);
            this.type = this.detectType();
            this.init();
        }

        detectType() {
            if (this.$element.hasClass('voxfor-ml-switcher-dropdown')) return 'dropdown';
            if (this.$element.hasClass('voxfor-ml-switcher-compact')) return 'compact';
            return 'default';
        }

        init() {
            if (this.type === 'dropdown' || this.type === 'compact') {
                this.initDropdown();
            }
            
            // Handle language switching
            this.$element.on('click', 'a[hreflang]', this.handleLanguageSwitch.bind(this));
        }

        initDropdown() {
            const $toggle = this.$element.find('.voxfor-ml-switcher-toggle, .voxfor-ml-compact-toggle');
            const $menu = this.$element.find('.voxfor-ml-switcher-dropdown-menu, .voxfor-ml-compact-menu');

            // Toggle menu
            $toggle.on('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                const isExpanded = $toggle.attr('aria-expanded') === 'true';
                $toggle.attr('aria-expanded', !isExpanded);
                
                if (!isExpanded) {
                    // Close other dropdowns
                    $('.voxfor-ml-switcher-toggle, .voxfor-ml-compact-toggle')
                        .not($toggle)
                        .attr('aria-expanded', 'false');
                }
            });

            // Close on outside click
            $(document).on('click', (e) => {
                if (!this.$element.is(e.target) && this.$element.has(e.target).length === 0) {
                    $toggle.attr('aria-expanded', 'false');
                }
            });

            // Keyboard navigation
            $menu.on('keydown', 'a', (e) => {
                const $items = $menu.find('a');
                const currentIndex = $items.index(e.target);
                
                switch(e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        $items.eq((currentIndex + 1) % $items.length).focus();
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        $items.eq((currentIndex - 1 + $items.length) % $items.length).focus();
                        break;
                    case 'Escape':
                        e.preventDefault();
                        $toggle.attr('aria-expanded', 'false').focus();
                        break;
                }
            });

            // Focus management
            $toggle.on('keydown', (e) => {
                if (e.key === 'ArrowDown' && $toggle.attr('aria-expanded') === 'true') {
                    e.preventDefault();
                    $menu.find('a').first().focus();
                }
            });
        }

        handleLanguageSwitch(e) {
            e.preventDefault(); // Prevent default link behavior
            
            const $link = $(e.currentTarget);
            const lang = $link.attr('hreflang');
            const targetUrl = $link.attr('href');
            
                            // Language switch clicked
            
            // Store language preference
            this.setLanguageCookie(lang);
            
            // Store in localStorage as backup
            if (typeof Storage !== 'undefined') {
                localStorage.setItem('voxfor_ml_lang', lang);
            }
            
            // Track language switch (for analytics)
            if (typeof gtag !== 'undefined') {
                gtag('event', 'language_switch', {
                    'event_category': 'engagement',
                    'event_label': lang
                });
            }
            
            // Navigate to the new language URL
            if (targetUrl) {
                // Redirecting to target URL
                window.location.href = targetUrl;
            }
        }

        setLanguageCookie(lang) {
            const date = new Date();
            date.setTime(date.getTime() + (365 * 24 * 60 * 60 * 1000));
            document.cookie = `${voxforML.cookieName}=${lang};expires=${date.toUTCString()};path=/`;
        }
    }

    // Visual Editor Handler
    class VoxforVisualEditor {
        constructor() {
            this.isActive = false;
            this.currentElement = null;
            this.init();
        }

        init() {
            // Check if visual editor is enabled
            if (!$('body').hasClass('voxfor-ml-editing')) {
                return;
            }

            this.setupEditableElements();
            this.setupEditPanel();
            this.bindEvents();
        }

        setupEditableElements() {
            // For WooCommerce products, ONLY allow editing of core product fields
            if ($('body').hasClass('single-product')) {
                this.setupProductEditableElements();
                return;
            }

            // Progressive loading configuration
            this.progressiveConfig = {
                batchSize: 20,
                delay: 50,
                viewportMargin: 200, // Process elements 200px before they come into view
                processedElements: new WeakSet()
            };

            // For regular posts/pages, mark translatable elements
            const selectors = [
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'p', 'li', 'td', 'th',
                '.entry-title', '.entry-content',
                '[alt]', '[title]', '[placeholder]'
            ];

            // Get all elements but process them progressively
            const allElements = $(selectors.join(', ')).toArray();
            
            // Process visible elements first
            this.processVisibleElements(allElements);
            
            // Setup intersection observer for progressive loading
            if ('IntersectionObserver' in window) {
                this.setupIntersectionObserver(allElements);
            } else {
                // Fallback: process in batches with delay
                this.processBatchesWithDelay(allElements);
            }
        }
        
        processVisibleElements(elements) {
            const viewportHeight = window.innerHeight;
            const viewportTop = window.scrollY;
            const viewportBottom = viewportTop + viewportHeight;
            
            elements.forEach(element => {
                const rect = element.getBoundingClientRect();
                const elementTop = rect.top + viewportTop;
                const elementBottom = elementTop + rect.height;
                
                // Check if element is in or near viewport
                if (elementBottom >= viewportTop - this.progressiveConfig.viewportMargin && 
                    elementTop <= viewportBottom + this.progressiveConfig.viewportMargin) {
                    this.processElement(element);
                }
            });
        }
        
        setupIntersectionObserver(elements) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        this.processElement(entry.target);
                        observer.unobserve(entry.target);
                    }
                });
            }, {
                rootMargin: this.progressiveConfig.viewportMargin + 'px'
            });
            
            elements.forEach(element => {
                if (!this.progressiveConfig.processedElements.has(element)) {
                    observer.observe(element);
                }
            });
        }
        
        processBatchesWithDelay(elements) {
            const unprocessed = elements.filter(el => !this.progressiveConfig.processedElements.has(el));
            
            const processBatch = (startIndex) => {
                const endIndex = Math.min(startIndex + this.progressiveConfig.batchSize, unprocessed.length);
                
                for (let i = startIndex; i < endIndex; i++) {
                    this.processElement(unprocessed[i]);
                }
                
                if (endIndex < unprocessed.length) {
                    setTimeout(() => processBatch(endIndex), this.progressiveConfig.delay);
                }
            };
            
            processBatch(0);
        }
        
        processElement(element) {
            // Skip if already processed
            if (this.progressiveConfig.processedElements.has(element)) {
                return;
            }
            
            const $element = $(element);
            
            // Skip if already marked or has no-translate class
            if ($element.hasClass('voxfor-ml-editable') || 
                $element.hasClass('no-translate') || 
                $element.hasClass('notranslate')) {
                this.progressiveConfig.processedElements.add(element);
                return;
            }

            // Skip empty elements
            const text = $element.text().trim();
            const alt = $element.attr('alt');
            const title = $element.attr('title');
            const placeholder = $element.attr('placeholder');

            if (!text && !alt && !title && !placeholder) {
                this.progressiveConfig.processedElements.add(element);
                return;
            }

            $element.addClass('voxfor-ml-editable');
            this.progressiveConfig.processedElements.add(element);
            
            // For elements on translated pages, we need to find the original English text
            if (voxforML.currentLang && voxforML.currentLang !== 'en') {
                // We're on a translated page, so the current text might be translated
                // Store the current text as 'translated' and try to find original via AJAX
                $element.attr('data-voxfor-ml-translated', text || alt || title || placeholder);
                $element.attr('data-voxfor-ml-original', 'LOADING...'); // Placeholder
                
                // Try to find the original English text via reverse lookup
                this.findOriginalText($element, text || alt || title || placeholder);
            } else {
                // We're on English page, so current text is the original
                $element.attr('data-voxfor-ml-original', text || alt || title || placeholder);
            }
        }

        setupProductEditableElements() {
            // For WooCommerce products, ONLY mark these 3 elements as editable:
            // 1. Product title, 2. Short description, 3. Full description
            
            // 1. Product title
            const $productTitle = $('h1.product_title, .entry-title').first();
            if ($productTitle.length && $productTitle.text().trim()) {
                $productTitle.addClass('voxfor-ml-editable');
                this.setupElementTranslationData($productTitle);
            }
            
            // 2. Product short description
            const $shortDesc = $('.woocommerce-product-details__short-description').first();
            if ($shortDesc.length && $shortDesc.text().trim()) {
                $shortDesc.addClass('voxfor-ml-editable');
                this.setupElementTranslationData($shortDesc);
            }
            
            // 3. Product full description (inside Description tab)
            const $fullDesc = $('#tab-description .woocommerce-Tabs-panel__content, #tab-description').first();
            if ($fullDesc.length && $fullDesc.text().trim()) {
                $fullDesc.addClass('voxfor-ml-editable');
                this.setupElementTranslationData($fullDesc);
            }
            
            // DO NOT mark anything else as editable for products:
            // - No price elements
            // - No tab titles
            // - No related products
            // - No UI elements
        }

        setupElementTranslationData($element) {
            const text = $element.text().trim();
            if (!text) return;
            
            // For elements on translated pages, we need to find the original English text
            if (voxforML.currentLang && voxforML.currentLang !== 'en') {
                // We're on a translated page, so the current text might be translated
                // Store the current text as 'translated' and try to find original via AJAX
                $element.attr('data-voxfor-ml-translated', text);
                $element.attr('data-voxfor-ml-original', 'LOADING...'); // Placeholder
                
                // Try to find the original English text via reverse lookup
                this.findOriginalText($element, text);
            } else {
                // We're on English page, so current text is the original
                $element.attr('data-voxfor-ml-original', text);
            }
        }

        findOriginalText($element, translatedText) {
            // Add to batch queue instead of immediate AJAX
            if (!this.batchQueue) {
                this.batchQueue = [];
                this.batchTimer = null;
            }
            
            this.batchQueue.push({
                element: $element,
                translatedText: translatedText
            });
            
            // Clear existing timer
            if (this.batchTimer) {
                clearTimeout(this.batchTimer);
            }
            
            // Set new timer to process batch after 100ms of no new requests
            this.batchTimer = setTimeout(() => {
                this.processBatchQueue();
            }, 100);
        }
        
        processBatchQueue() {
            if (!this.batchQueue || this.batchQueue.length === 0) {
                return;
            }
            
            const batch = [...this.batchQueue];
            this.batchQueue = [];
            
            // Deduplicate texts
            const uniqueTexts = {};
            const elementMap = {};
            const uncachedTexts = [];
            
            batch.forEach(item => {
                const text = item.translatedText;
                if (!uniqueTexts[text]) {
                    uniqueTexts[text] = true;
                    elementMap[text] = [];
                    
                    // Check cache first
                    const cacheKey = 'original_' + voxforML.currentLang + '_' + this.getPostId() + '_' + btoa(encodeURIComponent(text)).substring(0, 20);
                    const cached = VoxforCache.get(cacheKey);
                    
                    if (cached) {
                        // Use cached value immediately
                        item.element.attr('data-voxfor-ml-original', cached);
                        voxforDebugLog('Found in cache:', text, '->', cached);
                    } else {
                        uncachedTexts.push(text);
                    }
                }
                
                if (elementMap[text]) {
                    elementMap[text].push(item.element);
                }
            });
            
            // If all texts were cached, we're done
            if (uncachedTexts.length === 0) {
                voxforDebugLog('All texts found in cache, no AJAX needed');
                return;
            }
            
            voxforDebugLog('Processing batch of', uncachedTexts.length, 'uncached texts from', batch.length, 'elements');
            
            // Make single AJAX request for uncached texts only
            $.ajax({
                url: voxforML.ajaxUrl,
                type: 'POST',
                timeout: 10000, // 10 second timeout for batch
                data: {
                    action: 'voxfor_ml_batch_find_original_texts',
                    nonce: voxforML.nonce,
                    texts: uncachedTexts,
                    language: voxforML.currentLang,
                    post_id: this.getPostId()
                }
            })
            .done((response) => {
                if (response.success && response.data.translations) {
                    // Apply results to all elements and cache them
                    Object.keys(response.data.translations).forEach(translatedText => {
                        const originalText = response.data.translations[translatedText];
                        const elements = elementMap[translatedText] || [];
                        
                        elements.forEach($element => {
                            $element.attr('data-voxfor-ml-original', originalText || translatedText);
                        });
                        
                        // Cache the result
                        const cacheKey = 'original_' + voxforML.currentLang + '_' + this.getPostId() + '_' + btoa(encodeURIComponent(translatedText)).substring(0, 20);
                        VoxforCache.set(cacheKey, originalText || translatedText);
                    });
                    
                    voxforDebugLog('Batch processing complete, cached', Object.keys(response.data.translations).length, 'translations');
                } else {
                    // Fallback: use translated text as original for uncached items
                    uncachedTexts.forEach(text => {
                        const elements = elementMap[text] || [];
                        elements.forEach($element => {
                            $element.attr('data-voxfor-ml-original', text);
                        });
                    });
                }
            })
            .fail((xhr, status, error) => {
                // Fallback: use translated text as original for uncached items
                uncachedTexts.forEach(text => {
                    const elements = elementMap[text] || [];
                    elements.forEach($element => {
                        $element.attr('data-voxfor-ml-original', text);
                    });
                });
                voxforDebugLog('Batch processing failed:', status, error);
            });
        }

        setupEditPanel() {
            // Create edit panel if it doesn't exist
            if ($('#voxfor-ml-edit-panel').length === 0) {
                const panel = `
                    <div id="voxfor-ml-edit-panel" class="voxfor-ml-edit-panel">
                        <div class="voxfor-ml-edit-header">
                            <h3>${voxforML.strings.editTranslation || 'Edit Translation'}</h3>
                            <button class="voxfor-ml-edit-close">&times;</button>
                        </div>
                        <div class="voxfor-ml-edit-content">
                            <div class="voxfor-ml-edit-original">
                                <label>Original:</label>
                                <div class="voxfor-ml-original-text"></div>
                            </div>
                            <div class="voxfor-ml-edit-translation">
                                <label>Translation:</label>
                                <textarea class="voxfor-ml-translation-input"></textarea>
                            </div>
                            <div class="voxfor-ml-edit-actions">
                                <button class="voxfor-ml-save-translation">Save</button>
                                <button class="voxfor-ml-cancel-edit">Cancel</button>
                            </div>
                        </div>
                    </div>
                `;
                $('body').append(panel);
            }
        }

        bindEvents() {
            // quiet log
            // Click on editable element
            $(document).on('click', '.voxfor-ml-editable', (e) => {
                // Don't open editor if clicking on toolbar buttons
                if ($(e.target).closest('.voxfor-ml-toolbar-button, .voxfor-ml-save-all, .voxfor-ml-toggle-highlights').length) {
                    // quiet
                    return;
                }
                
                e.preventDefault();
                e.stopPropagation();
                this.openEditor($(e.currentTarget));
            });

            // Close button
            $(document).on('click', '.voxfor-ml-edit-close, .voxfor-ml-cancel-edit', () => {
                this.closeEditor();
            });

            // Save button
            $(document).on('click', '.voxfor-ml-save-translation', () => {
                this.saveTranslation();
            });
            
            // Visual editor toolbar controls - use more specific selector
            $(document).on('click', '.voxfor-ml-toolbar-button.voxfor-ml-save-all', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.saveAllChanges();
            });
            
            $(document).on('click', '.voxfor-ml-toggle-highlights', () => {
                this.toggleHighlights();
            });

            // Keyboard shortcuts
            $(document).on('keydown', (e) => {
                if (e.key === 'Escape' && this.isActive) {
                    this.closeEditor();
                }
            });
        }

        openEditor($element) {
            this.currentElement = $element;
            const original = $element.attr('data-voxfor-ml-original');
            const current = $element.text() || $element.attr('alt') || $element.attr('title') || $element.attr('placeholder');

            $('#voxfor-ml-edit-panel').addClass('active');
            $('.voxfor-ml-original-text').text(original);
            $('.voxfor-ml-translation-input').val(current).focus();

            this.isActive = true;
        }

        closeEditor() {
            $('#voxfor-ml-edit-panel').removeClass('active');
            this.currentElement = null;
            this.isActive = false;
        }

        saveTranslation() {
            if (!this.currentElement) {
                return;
            }

            const original = this.currentElement.attr('data-voxfor-ml-original');
            const translation = $('.voxfor-ml-translation-input').val();
            

            if (!translation.trim()) {
                alert('Please enter a translation');
                return;
            }

            // Store translation temporarily
            const translationData = {
                original: original,
                translated: translation,
                language: voxforML.currentLang,
                context: this.detectContext(),
                post_id: this.getPostId()
            };
            
            
            // Add to temporary storage
            const savedTranslations = JSON.parse(localStorage.getItem('voxfor_ml_temp_translations') || '[]');
            
            
            // Remove any existing translation for this original text
            const filteredTranslations = savedTranslations.filter(t => t.original !== original);
            filteredTranslations.push(translationData);
            
            
            localStorage.setItem('voxfor_ml_temp_translations', JSON.stringify(filteredTranslations));

            // Update the element immediately for visual feedback
            if (this.currentElement.is('[alt]')) {
                this.currentElement.attr('alt', translation);
            } else if (this.currentElement.is('[title]')) {
                this.currentElement.attr('title', translation);
            } else if (this.currentElement.is('[placeholder]')) {
                this.currentElement.attr('placeholder', translation);
            } else {
                this.currentElement.text(translation);
            }

            // Mark as translated and staged
            this.currentElement.addClass('voxfor-ml-translated voxfor-ml-staged');
            
            // Update save all button to show pending changes
            const pendingCount = filteredTranslations.length;
            $('.voxfor-ml-save-all').html(`<span class="dashicons dashicons-saved"></span> Save All (${pendingCount})`);
            
            // Show success feedback
            $('.voxfor-ml-save-translation').text('Staged!').addClass('success');
            setTimeout(() => {
                $('.voxfor-ml-save-translation').text('Save').removeClass('success');
            }, 1000);
            
            // Close editor
            setTimeout(() => {
                this.closeEditor();
            }, 500);
        }

        detectContext() {
            if (!this.currentElement) return 'general';

            // WooCommerce product page detection
            if ($('body').hasClass('single-product')) {
                // Product title
                if (this.currentElement.is('h1.product_title, .entry-title, .woocommerce-loop-product__title')) {
                    return 'product_name';
                }
                
                // Product short description
                if (this.currentElement.is('.woocommerce-product-details__short-description, .woocommerce-product-details__short-description *')) {
                    return 'product_short_description';
                }
                
                // Product description (in tabs) - improved detection
                if (this.currentElement.is('#tab-description *, .wc-tab#tab-description *, [role="tabpanel"]#tab-description *')) {
                    return 'product_description';
                }
                
                // Description tab title/heading
                if (this.currentElement.is('.wc-tab h2, .woocommerce-tabs h2, .woocommerce-tabs .nav-tab')) {
                    return 'product_description';
                }
                
                // Tab navigation text (like "Description" tab label)
                if (this.currentElement.is('.woocommerce-tabs .tabs li a, #tab-title-description, a[href="#tab-description"]')) {
                    return 'product_description';
                }
                
                // Any element inside description tab
                if (this.currentElement.closest('#tab-description, .wc-tab').length > 0) {
                    return 'product_description';
                }
            }

            // General page contexts
            if (this.currentElement.is('h1, .entry-title')) return 'title';
            if (this.currentElement.is('.entry-content *')) return 'content';
            if (this.currentElement.is('[alt]')) return 'image_alt';
            if (this.currentElement.is('[title]')) return 'image_title';
            
            return 'general';
        }

        getPostId() {
            // Try to get post ID from body class
            const bodyClasses = $('body').attr('class');
            const match = bodyClasses ? bodyClasses.match(/postid-(\d+)/) : null;
            return match ? parseInt(match[1]) : null;
        }
        
        saveAllChanges() {
            // Starting saveAllChanges process
            const $button = $('.voxfor-ml-save-all');
            const savedTranslations = JSON.parse(localStorage.getItem('voxfor_ml_temp_translations') || '[]');
            
            if (savedTranslations.length === 0) {
                alert('No changes to save.');
                return;
            }
            
            // Setting button to saving state
            // Show saving state
            $button.html('<span class="dashicons dashicons-update spin"></span> Saving...')
                   .prop('disabled', true);
            
            // Process all saved translations
            let completed = 0;
            let total = savedTranslations.length;
            let errors = 0;
            
            // Processing translations
            
            savedTranslations.forEach((translation, index) => {
                $.ajax({
                    url: voxforML.restUrl + 'visual-editor/save',
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': voxforML.nonce
                    },
                    data: JSON.stringify(translation),
                    contentType: 'application/json',
                    success: (response) => {
                        // Translation saved successfully
                        completed++;
                        this.checkAllSavesComplete(completed, total, errors, $button);
                    },
                    error: (xhr, status, error) => {
                        // Translation save failed
                        completed++;
                        errors++;
                        this.checkAllSavesComplete(completed, total, errors, $button);
                    }
                });
            });
        }
        
        checkAllSavesComplete(completed, total, errors, $button) {
            // Checking completion status
            
            if (completed === total) {
                // All translations processed, clearing localStorage
                localStorage.removeItem('voxfor_ml_temp_translations');
                
                if (errors === 0) {
                    // All translations saved successfully
                    $button.html('<span class="dashicons dashicons-yes"></span> All Saved!')
                           .addClass('success');
                    
                    // Show success message (without auto-refresh)
                    alert(`Successfully saved ${total} translation(s)!`);
                    
                    // All translations saved - page refresh disabled
                    // REMOVED: Auto page refresh that interferes with visual editor
                    // Users can manually refresh if needed
                } else {
                    $button.html('<span class="dashicons dashicons-warning"></span> Some Failed')
                           .addClass('error');
                    
                    alert(`Saved ${total - errors} of ${total} translations. ${errors} failed.`);
                    
                    // Reset button after delay
                    setTimeout(() => {
                        $button.html('<span class="dashicons dashicons-saved"></span>')
                               .removeClass('error')
                               .prop('disabled', false);
                    }, 3000);
                }
            }
        }
        
        toggleHighlights() {
            $('body').toggleClass('voxfor-ml-hide-highlights');
        }
        
        // Debug helper - call from browser console: voxforMLVisualEditor.debugState()
        debugState() {
            // Debug state information available for troubleshooting
        }
    }

    // Initialize on document ready
    $(document).ready(() => {
        
        // Initialize all language switchers
        $('.voxfor-ml-language-switcher, .voxfor-ml-switcher').each(function() {
            new VoxforLanguageSwitcher(this);
        });

        // Handle select-based language switcher
        
        // jQuery version
        $('.voxfor-ml-language-select').on('change', function() {
            const selectedUrl = $(this).val();
            handleLanguageChange(selectedUrl);
        });
        
        // Vanilla JS version as backup
        const languageSelects = document.querySelectorAll('.voxfor-ml-language-select');
        languageSelects.forEach(function(select) {
            select.addEventListener('change', function() {
                const selectedUrl = this.value;
                handleLanguageChange(selectedUrl);
            });
        });
        
        function handleLanguageChange(selectedUrl) {
            if (selectedUrl) {
                // Extract language from URL
                let lang = 'en'; // default
                const urlParts = selectedUrl.split('/');
                const hostIndex = urlParts.findIndex(part => part.includes('localhost'));
                if (hostIndex >= 0 && urlParts[hostIndex + 1] && urlParts[hostIndex + 1].length === 2) {
                    lang = urlParts[hostIndex + 1];
                }
                
                // Update cookie
                const date = new Date();
                date.setTime(date.getTime() + (365 * 24 * 60 * 60 * 1000));
                document.cookie = `voxfor_ml_lang=${lang};expires=${date.toUTCString()};path=/`;
                window.location.href = selectedUrl;
            }
        }

        // Initialize visual editor if enabled
        if ($('body').hasClass('voxfor-ml-editing')) {
            window.voxforMLVisualEditor = new VoxforVisualEditor();
        }

        // Handle AJAX content
        $(document).on('voxfor-ml-init', () => {
            $('.voxfor-ml-language-switcher, .voxfor-ml-switcher').not('[data-initialized]').each(function() {
                $(this).attr('data-initialized', 'true');
                new VoxforLanguageSwitcher(this);
            });
        });
    });

    // Expose API
    window.VoxforML = window.VoxforML || {};
    window.VoxforML.initSwitchers = () => {
        $(document).trigger('voxfor-ml-init');
    };

    // Static Text Translation Handler
    class VoxforStaticTranslator {
        constructor() {
            this.translations = {
                'de': {
                    'My Blog': 'Mein Blog',
                    'Sample Page': 'Beispielseite',
                    'Blog': 'Blog',
                    'About': 'Über uns',
                    'FAQs': 'Häufige Fragen',
                    'Authors': 'Autoren',
                    'Events': 'Veranstaltungen',
                    'Shop': 'Geschäft',
                    'Patterns': 'Muster',
                    'Themes': 'Themen',
                    'My WordPress Blog': 'Mein WordPress Blog',
                    'Twenty Twenty-Five': 'Twenty Twenty-Five',
                    'Designed with': 'Entworfen mit',
                    'We respect your privacy': 'Wir respektieren Ihre Privatsphäre',
                    'Cookies help us improve your experience, deliver personalized content, and analyze traffic. You can choose which cookies to allow by clicking': 'Cookies helfen uns, Ihre Erfahrung zu verbessern, personalisierte Inhalte zu liefern und den Verkehr zu analysieren. Sie können wählen, welche Cookies Sie zulassen möchten, indem Sie auf',
                    'Customize': 'Anpassen',
                    'Accept All': 'Alle akzeptieren',
                    'Reject All': 'Alle ablehnen',
                    'Search': 'Suchen'
                },
                'fr': {
                    'My Blog': 'Mon Blog',
                    'Sample Page': 'Page d\'exemple',
                    'Blog': 'Blog',
                    'About': 'À propos',
                    'FAQs': 'FAQ',
                    'Authors': 'Auteurs',
                    'Events': 'Événements',
                    'Shop': 'Boutique',
                    'Patterns': 'Modèles',
                    'Themes': 'Thèmes',
                    'My WordPress Blog': 'Mon Blog WordPress',
                    'Twenty Twenty-Five': 'Twenty Twenty-Five',
                    'Designed with': 'Conçu avec',
                    'We respect your privacy': 'Nous respectons votre vie privée',
                    'Cookies help us improve your experience, deliver personalized content, and analyze traffic. You can choose which cookies to allow by clicking': 'Les cookies nous aident à améliorer votre expérience, à fournir du contenu personnalisé et à analyser le trafic. Vous pouvez choisir quels cookies autoriser en cliquant sur',
                    'Customize': 'Personnaliser',
                    'Accept All': 'Tout accepter',
                    'Reject All': 'Tout rejeter',
                    'Search': 'Rechercher'
                },
                'es': {
                    'My Blog': 'Mi Blog',
                    'Sample Page': 'Página de ejemplo',
                    'Blog': 'Blog',
                    'About': 'Acerca de',
                    'FAQs': 'Preguntas frecuentes',
                    'Authors': 'Autores',
                    'Events': 'Eventos',
                    'Shop': 'Tienda',
                    'Patterns': 'Patrones',
                    'Themes': 'Temas',
                    'My WordPress Blog': 'Mi Blog de WordPress',
                    'Twenty Twenty-Five': 'Twenty Twenty-Five',
                    'Designed with': 'Diseñado con',
                    'We respect your privacy': 'Respetamos tu privacidad',
                    'Cookies help us improve your experience, deliver personalized content, and analyze traffic. You can choose which cookies to allow by clicking': 'Las cookies nos ayudan a mejorar tu experiencia, entregar contenido personalizado y analizar el tráfico. Puedes elegir qué cookies permitir haciendo clic en',
                    'Customize': 'Personalizar',
                    'Accept All': 'Aceptar todo',
                    'Reject All': 'Rechazar todo',
                    'Search': 'Buscar'
                },
                'it': {
                    'My Blog': 'Il Mio Blog',
                    'Sample Page': 'Pagina di esempio',
                    'Blog': 'Blog',
                    'About': 'Chi siamo',
                    'FAQs': 'Domande frequenti',
                    'Authors': 'Autori',
                    'Events': 'Eventi',
                    'Shop': 'Negozio',
                    'Patterns': 'Modelli',
                    'Themes': 'Temi',
                    'My WordPress Blog': 'Il Mio Blog WordPress',
                    'Twenty Twenty-Five': 'Twenty Twenty-Five',
                    'Designed with': 'Progettato con',
                    'We respect your privacy': 'Rispettiamo la tua privacy',
                    'Cookies help us improve your experience, deliver personalized content, and analyze traffic. You can choose which cookies to allow by clicking': 'I cookie ci aiutano a migliorare la tua esperienza, fornire contenuti personalizzati e analizzare il traffico. Puoi scegliere quali cookie consentire facendo clic su',
                    'Customize': 'Personalizza',
                    'Accept All': 'Accetta tutto',
                    'Reject All': 'Rifiuta tutto',
                    'Search': 'Cerca'
                }
            };
            
            this.init();
        }
        
        init() {
            // Get current language from URL
            const currentLang = this.getCurrentLanguage();
            if (currentLang === 'en' || !this.translations[currentLang]) {
                return; // No translation needed for English or unsupported language
            }
            
            // Wait for page to load completely
            setTimeout(() => {
                this.translateStaticElements(currentLang);
            }, 500);
        }
        
        getCurrentLanguage() {
            const path = window.location.pathname;
            const langMatch = path.match(/^\/([a-z]{2})\//);
            return langMatch ? langMatch[1] : 'en';
        }
        
        translateStaticElements(lang) {
            const translations = this.translations[lang];
            if (!translations) return;
            
            // Translate all text nodes
            this.walkTextNodes(document.body, (textNode) => {
                const text = textNode.textContent.trim();
                if (translations[text]) {
                    textNode.textContent = textNode.textContent.replace(text, translations[text]);
                }
            });
            
            // Translate specific attributes
            this.translateAttributes(translations);
        }
        
        walkTextNodes(node, callback) {
            if (node.nodeType === Node.TEXT_NODE) {
                if (node.textContent.trim()) {
                    callback(node);
                }
            } else {
                for (let child of node.childNodes) {
                    this.walkTextNodes(child, callback);
                }
            }
        }
        
        translateAttributes(translations) {
            // Translate placeholder attributes
            document.querySelectorAll('[placeholder]').forEach(element => {
                const placeholder = element.getAttribute('placeholder');
                if (translations[placeholder]) {
                    element.setAttribute('placeholder', translations[placeholder]);
                }
            });
            
            // Translate title attributes
            document.querySelectorAll('[title]').forEach(element => {
                const title = element.getAttribute('title');
                if (translations[title]) {
                    element.setAttribute('title', translations[title]);
                }
            });
            
            // Translate alt attributes
            document.querySelectorAll('[alt]').forEach(element => {
                const alt = element.getAttribute('alt');
                if (translations[alt]) {
                    element.setAttribute('alt', translations[alt]);
                }
            });
        }
    }
    
    // Initialize static translator
    $(document).ready(() => {
        new VoxforStaticTranslator();
    });

})(jQuery);

// Vanilla JS initialization as backup
document.addEventListener('DOMContentLoaded', function() {
    // Vanilla JS DOM ready, initializing language switchers
    
    const languageSelects = document.querySelectorAll('.voxfor-ml-language-select');
    
    languageSelects.forEach(function(select) {
        select.addEventListener('change', function() {
            const selectedUrl = this.value;
            
            if (selectedUrl) {
                // Extract language from URL
                let lang = 'en'; // default
                const urlParts = selectedUrl.split('/');
                const hostIndex = urlParts.findIndex(part => part.includes('localhost'));
                if (hostIndex >= 0 && urlParts[hostIndex + 1] && urlParts[hostIndex + 1].length === 2) {
                    lang = urlParts[hostIndex + 1];
                }
                
                // Update cookie
                const date = new Date();
                date.setTime(date.getTime() + (365 * 24 * 60 * 60 * 1000));
                document.cookie = `voxfor_ml_lang=${lang};expires=${date.toUTCString()};path=/`;
                
                // Updated cookie to selected language
                window.location.href = selectedUrl;
            }
        });
    });
});