/**
 * Auto-translate header and footer when language is switched
 */
(function($) {
    'use strict';
    
    let currentLanguage = null;
    let translationCache = {};
    
    // Function to detect language from URL or cookie
    function getCurrentLanguage() {
        // Check URL first
        const pathParts = window.location.pathname.split('/');
        const supportedLanguages = ['es', 'it', 'fr', 'de', 'pt', 'ru', 'ja', 'zh', 'ko', 'ar', 'he'];
        
        for (let part of pathParts) {
            if (supportedLanguages.includes(part)) {
                return part;
            }
        }
        
        // Check cookie
        const cookies = document.cookie.split(';');
        for (let cookie of cookies) {
            const [name, value] = cookie.trim().split('=');
            if (name === 'voxfor_ml_lang') {
                return value;
            }
        }
        
        return 'en';
    }
    
    // Function to preload translations for a language
    function preloadTranslations(language) {
        if (language === 'en' || !language) {
            return Promise.resolve();
        }
        
        // Check in-memory cache first
        if (translationCache[language]) {
            return Promise.resolve();
        }
        
        // Check localStorage cache
        const cacheKey = 'voxfor_ml_header_footer_' + language;
        const cached = localStorage.getItem(cacheKey);
        if (cached) {
            try {
                const data = JSON.parse(cached);
                // Check if cache is still valid (24 hours)
                if (data.expires && data.expires > Date.now()) {
                    translationCache[language] = data.translations;
                    return Promise.resolve();
                }
            } catch (e) {
                localStorage.removeItem(cacheKey);
            }
        }
        
        // Load from server
        return $.ajax({
            url: voxfor_ml_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'voxfor_ml_get_header_footer_translations',
                language: language,
                nonce: voxfor_ml_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.translations) {
                    translationCache[language] = response.data.translations;
                    
                    // Store in localStorage with expiry
                    try {
                        localStorage.setItem(cacheKey, JSON.stringify({
                            translations: response.data.translations,
                            expires: Date.now() + (24 * 60 * 60 * 1000) // 24 hours
                        }));
                    } catch (e) {
                        // Ignore localStorage errors (quota exceeded, etc.)
                    }
                }
            }
        });
    }
    
    // Function to translate text nodes in real-time
    function translateTextNode(node, translations) {
        const text = node.textContent.trim();
        if (text && translations[text]) {
            node.textContent = translations[text];
        }
    }
    
    // Function to translate element attributes
    function translateAttributes(element, translations) {
        const attrs = ['placeholder', 'alt', 'title', 'aria-label'];
        attrs.forEach(attr => {
            const value = element.getAttribute(attr);
            if (value && translations[value]) {
                element.setAttribute(attr, translations[value]);
            }
        });
    }
    
    // Function to translate header and footer content
    function translateHeaderFooter(language) {
        if (language === 'en' || !language || !translationCache[language]) {
            return;
        }
        
        const translations = translationCache[language];
        const selectors = [
            'header', 'footer', 'nav',
            '[id*="header"]', '[class*="header"]',
            '[id*="footer"]', '[class*="footer"]',
            '[class*="menu"]', '[class*="navigation"]',
            '[class*="widget"]', '[class*="sidebar"]'
        ];
        
        selectors.forEach(selector => {
            const elements = document.querySelectorAll(selector);
            elements.forEach(element => {
                // Skip script and style elements
                if (element.tagName === 'SCRIPT' || element.tagName === 'STYLE') {
                    return;
                }
                
                // Walk through all text nodes
                const walker = document.createTreeWalker(
                    element,
                    NodeFilter.SHOW_TEXT,
                    {
                        acceptNode: function(node) {
                            // Skip if parent is script or style
                            const parent = node.parentElement;
                            if (parent && (parent.tagName === 'SCRIPT' || parent.tagName === 'STYLE')) {
                                return NodeFilter.FILTER_REJECT;
                            }
                            return NodeFilter.FILTER_ACCEPT;
                        }
                    }
                );
                
                let node;
                while (node = walker.nextNode()) {
                    translateTextNode(node, translations);
                }
                
                // Translate attributes
                translateAttributes(element, translations);
                
                // Also translate child elements' attributes
                const childElements = element.querySelectorAll('*');
                childElements.forEach(child => {
                    translateAttributes(child, translations);
                });
            });
        });
    }
    
    // Initialize on page load
    $(document).ready(function() {
        currentLanguage = getCurrentLanguage();
        
        // Only preload translations for current language
        if (currentLanguage !== 'en') {
            preloadTranslations(currentLanguage).then(() => {
                translateHeaderFooter(currentLanguage);
            });
        }
        
        // REMOVED: Preloading all languages - this was causing 11 unnecessary AJAX requests
        // Languages will be loaded on-demand when user switches
        
        // Handle language switching without page refresh
        $(document).on('click', '.voxfor-language-switcher a, .language-switcher a, [class*="language-switch"] a', function(e) {
            // Check if the feature is enabled
            const enableHeaderFooterTranslation = $(this).closest('[data-header-footer-translation]').data('header-footer-translation');
            if (enableHeaderFooterTranslation === false) {
                // Feature disabled, proceed with normal navigation
                return true;
            }
            
            e.preventDefault();
            
            const href = $(this).attr('href');
            const newLang = detectLanguageFromUrl(href);
            
            if (newLang && newLang !== currentLanguage && newLang !== 'en') {
                currentLanguage = newLang;
                
                // Set cookie
                document.cookie = 'voxfor_ml_lang=' + newLang + '; path=/';
                
                // Show loading indicator
                const $link = $(this);
                const originalText = $link.text();
                $link.text('Loading...');
                
                // Translate immediately if we have cached translations
                if (translationCache[newLang]) {
                    translateHeaderFooter(newLang);
                    
                    // Navigate to the new URL after translation
                    setTimeout(() => {
                        window.location.href = href;
                    }, 100);
                } else {
                    // Load translations first, then navigate
                    preloadTranslations(newLang).then(() => {
                        translateHeaderFooter(newLang);
                        setTimeout(() => {
                            window.location.href = href;
                        }, 100);
                    }).catch(() => {
                        // On error, just navigate
                        window.location.href = href;
                    });
                }
            } else {
                // Just navigate if it's English or same language
                window.location.href = href;
            }
        });
    });
    
    // Helper function to detect language from URL
    function detectLanguageFromUrl(url) {
        const supportedLanguages = ['es', 'it', 'fr', 'de', 'pt', 'ru', 'ja', 'zh', 'ko', 'ar', 'he'];
        const urlParts = url.split('/');
        
        for (let part of urlParts) {
            if (supportedLanguages.includes(part)) {
                return part;
            }
        }
        
        // Check for language parameter
        const urlParams = new URLSearchParams(url.split('?')[1] || '');
        const langParam = urlParams.get('lang');
        if (langParam && supportedLanguages.includes(langParam)) {
            return langParam;
        }
        
        return null;
    }
    
})(jQuery);
