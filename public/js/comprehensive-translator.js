/**
 * Comprehensive Translation System
 * Handles complete page translation on first language visit
 */

(function($) {
    'use strict';
    
    let translationInProgress = false;
    let translatedElements = new Set();
    
    // Main comprehensive translation function
    window.voxforMLComprehensiveTranslate = function() {
        if (translationInProgress || voxforMLTranslator.currentLanguage === 'en') {
            return;
        }
        
        translationInProgress = true;
        
        // Step 1: Translate header and footer first (highest priority)
        translateHeaderFooter()
            .then(() => {
                updateProgress(25);
                
                // Step 2: Translate navigation and menus
                return translateNavigation();
            })
            .then(() => {
                updateProgress(50);
                
                // Step 3: Translate main content
                return translateMainContent();
            })
            .then(() => {
                updateProgress(75);
                
                // Step 4: Translate remaining elements
                return translateRemainingElements();
            })
            .then(() => {
                updateProgress(100);
                
                // Hide loading indicator
                setTimeout(() => {
                    const loadingDiv = document.getElementById('voxfor-ml-translation-loading');
                    if (loadingDiv) {
                        loadingDiv.style.opacity = '0';
                        setTimeout(() => loadingDiv.remove(), 500);
                    }
                    translationInProgress = false;
                }, 1000);
            })
            .catch(error => {
                // Translation error occurred
                translationInProgress = false;
                const loadingDiv = document.getElementById('voxfor-ml-translation-loading');
                if (loadingDiv) {
                    loadingDiv.remove();
                }
            });
    };
    
    // Translate header and footer elements
    function translateHeaderFooter() {
        return new Promise((resolve) => {
            const headerFooterSelectors = [
                'header', 'footer', '.header', '.footer',
                '.site-header', '.site-footer', '.main-header', '.main-footer',
                '#header', '#footer', '#masthead', '#colophon',
                '.navbar', '.nav', '.navigation', '.menu'
            ];
            
            const texts = [];
            headerFooterSelectors.forEach(selector => {
                const elements = document.querySelectorAll(selector);
                elements.forEach(element => {
                    extractTextsFromElement(element, texts);
                });
            });
            
            if (texts.length > 0) {
                translateTexts(texts).then(() => {
                    resolve();
                });
            } else {
                resolve();
            }
        });
    }
    
    // Translate navigation elements
    function translateNavigation() {
        return new Promise((resolve) => {
            const navSelectors = [
                'nav', '.nav', '.navigation', '.menu', '.navbar',
                '.main-navigation', '.primary-navigation', '.secondary-navigation',
                '#navigation', '#nav', '#menu', '.wp-nav-menu',
                '.menu-item', '.nav-item'
            ];
            
            const texts = [];
            navSelectors.forEach(selector => {
                const elements = document.querySelectorAll(selector);
                elements.forEach(element => {
                    extractTextsFromElement(element, texts);
                });
            });
            
            if (texts.length > 0) {
                translateTexts(texts).then(() => {
                    resolve();
                });
            } else {
                resolve();
            }
        });
    }
    
    // Translate main content
    function translateMainContent() {
        return new Promise((resolve) => {
            const contentSelectors = [
                'main', '.main', '#main', '.content', '#content',
                '.site-content', '.main-content', '.page-content',
                'article', '.article', '.post', '.entry',
                '.entry-content', '.post-content', '.page-content',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'p', '.text', '.description'
            ];
            
            const texts = [];
            contentSelectors.forEach(selector => {
                const elements = document.querySelectorAll(selector);
                elements.forEach(element => {
                    extractTextsFromElement(element, texts);
                });
            });
            
            if (texts.length > 0) {
                translateTexts(texts).then(() => {
                    resolve();
                });
            } else {
                resolve();
            }
        });
    }
    
    // Translate remaining elements
    function translateRemainingElements() {
        return new Promise((resolve) => {
            const remainingSelectors = [
                '.widget', '.sidebar', '.footer-widget',
                '.button', '.btn', 'button',
                'label', '.label', '.form-label',
                '.title', '.subtitle', '.caption',
                'figcaption', '.wp-caption-text',
                'blockquote', '.quote'
            ];
            
            const texts = [];
            remainingSelectors.forEach(selector => {
                const elements = document.querySelectorAll(selector);
                elements.forEach(element => {
                    extractTextsFromElement(element, texts);
                });
            });
            
            if (texts.length > 0) {
                translateTexts(texts).then(() => {
                    resolve();
                });
            } else {
                resolve();
            }
        });
    }
    
    // Extract translatable texts from an element
    function extractTextsFromElement(element, texts) {
        if (!element || translatedElements.has(element)) {
            return;
        }
        
        // Skip elements that shouldn't be translated
        if (shouldSkipElement(element)) {
            return;
        }
        
        // Get direct text content (not from child elements)
        const walker = document.createTreeWalker(
            element,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function(node) {
                    // Skip if parent is a script, style, or already translated element
                    const parent = node.parentElement;
                    if (!parent || shouldSkipElement(parent)) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    
                    const text = node.textContent.trim();
                    if (text.length < 3 || /^\d+$/.test(text) || /^[^\w\s]+$/.test(text)) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    
                    return NodeFilter.FILTER_ACCEPT;
                }
            }
        );
        
        let textNode;
        while (textNode = walker.nextNode()) {
            const text = textNode.textContent.trim();
            if (text && text.length >= 3) {
                texts.push({
                    element: textNode.parentElement,
                    textNode: textNode,
                    originalText: text
                });
            }
        }
        
        // Mark element as processed
        translatedElements.add(element);
    }
    
    // Check if element should be skipped
    function shouldSkipElement(element) {
        if (!element || !element.tagName) {
            return true;
        }
        
        const tagName = element.tagName.toLowerCase();
        const skipTags = ['script', 'style', 'noscript', 'iframe', 'object', 'embed'];
        
        if (skipTags.includes(tagName)) {
            return true;
        }
        
        // Skip elements with certain classes or attributes
        const skipClasses = [
            'voxfor-ml-skip', 'notranslate', 'no-translate',
            'wp-admin', 'admin-bar', 'screen-reader-text',
            'skip-link', 'assistive-text'
        ];
        
        for (const className of skipClasses) {
            if (element.classList && element.classList.contains(className)) {
                return true;
            }
        }
        
        // Skip if element has translate="no" attribute
        if (element.getAttribute && element.getAttribute('translate') === 'no') {
            return true;
        }
        
        return false;
    }
    
    // Translate array of texts
    function translateTexts(texts) {
        return new Promise((resolve) => {
            if (texts.length === 0) {
                resolve();
                return;
            }
            
            // Group texts by similarity to reduce API calls
            const textGroups = groupSimilarTexts(texts);
            let completedGroups = 0;
            
            textGroups.forEach(group => {
                const textsToTranslate = group.map(item => item.originalText);
                
                $.ajax({
                    url: voxforMLTranslator.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'voxfor_ml_translate_texts',
                        texts: textsToTranslate,
                        language: voxforMLTranslator.currentLanguage,
                        nonce: voxforMLTranslator.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            // Apply translations
                            group.forEach((item, index) => {
                                if (response.data[index] && response.data[index] !== item.originalText) {
                                    applyTranslation(item, response.data[index]);
                                }
                            });
                        }
                        
                        completedGroups++;
                        if (completedGroups >= textGroups.length) {
                            resolve();
                        }
                    },
                    error: function() {
                        completedGroups++;
                        if (completedGroups >= textGroups.length) {
                            resolve();
                        }
                    }
                });
            });
        });
    }
    
    // Group similar texts to optimize API calls
    function groupSimilarTexts(texts) {
        const groups = [];
        const maxGroupSize = 10;
        
        for (let i = 0; i < texts.length; i += maxGroupSize) {
            groups.push(texts.slice(i, i + maxGroupSize));
        }
        
        return groups;
    }
    
    // Apply translation to element
    function applyTranslation(item, translatedText) {
        if (!item.textNode || !item.textNode.parentElement) {
            return;
        }
        
        try {
            // Update the text node content
            item.textNode.textContent = translatedText;
            
            // Add a class to mark as translated
            if (item.element && item.element.classList) {
                item.element.classList.add('voxfor-ml-translated');
            }
        } catch (error) {
            // Error applying translation
        }
    }
    
    // Update progress indicator
    function updateProgress(percent) {
        const progressBar = document.querySelector('#voxfor-ml-translation-loading .progress-bar');
        const progressText = document.querySelector('#voxfor-ml-translation-loading .progress-text');
        
        if (progressBar) {
            progressBar.style.width = percent + '%';
        }
        
        if (progressText) {
            const messages = {
                25: 'Translating header and footer...',
                50: 'Translating navigation...',
                75: 'Translating main content...',
                100: 'Translation complete!'
            };
            
            progressText.textContent = messages[percent] || 'Translating...';
        }
    }
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        // Auto-start comprehensive translation if enabled and not on default language
        if (window.voxforMLTranslator && 
            voxforMLTranslator.autoTranslate && 
            voxforMLTranslator.currentLanguage !== 'en') {
            
            // Small delay to ensure page is fully loaded
            setTimeout(() => {
                window.voxforMLComprehensiveTranslate();
            }, 500);
        }
    });
    
})(jQuery);
