/**
 * Voxfor Multilanguage - Gutenberg Blocks
 */

const { registerBlockType } = wp.blocks;
const { __ } = wp.i18n;
const { InspectorControls } = wp.blockEditor;
const { PanelBody, SelectControl, ToggleControl } = wp.components;
const { Fragment, useState } = wp.element;
const { serverSideRender: ServerSideRender } = wp;

// Language Switcher Block
registerBlockType('voxfor-ml/language-switcher', {
    title: __('Language Switcher', 'voxfor-multilanguage'),
    description: __('Display a language switcher for your multilingual site', 'voxfor-multilanguage'),
    icon: 'translation',
    category: 'widgets',
    keywords: [
        __('language', 'voxfor-multilanguage'),
        __('switcher', 'voxfor-multilanguage'),
        __('multilingual', 'voxfor-multilanguage')
    ],
    attributes: {
        style: {
            type: 'string',
            default: 'dropdown'
        },
        showFlags: {
            type: 'boolean',
            default: true
        },
        showNativeNames: {
            type: 'boolean',
            default: true
        }
    },
    example: {
        attributes: {
            style: 'dropdown',
            showFlags: true,
            showNativeNames: true
        }
    },
    edit: ({ attributes, setAttributes }) => {
        const { style, showFlags, showNativeNames } = attributes;

        return (
            <Fragment>
                <InspectorControls>
                    <PanelBody title={__('Language Switcher Settings', 'voxfor-multilanguage')}>
                        <SelectControl
                            label={__('Style', 'voxfor-multilanguage')}
                            value={style}
                            options={[
                                { label: __('Dropdown', 'voxfor-multilanguage'), value: 'dropdown' },
                                { label: __('Inline List', 'voxfor-multilanguage'), value: 'inline' },
                                { label: __('Flags Only', 'voxfor-multilanguage'), value: 'flags' },
                                { label: __('Compact', 'voxfor-multilanguage'), value: 'compact' }
                            ]}
                            onChange={(value) => setAttributes({ style: value })}
                        />
                        
                        <ToggleControl
                            label={__('Show Flags', 'voxfor-multilanguage')}
                            checked={showFlags}
                            onChange={(value) => setAttributes({ showFlags: value })}
                        />
                        
                        <ToggleControl
                            label={__('Show Native Names', 'voxfor-multilanguage')}
                            checked={showNativeNames}
                            onChange={(value) => setAttributes({ showNativeNames: value })}
                            help={__('Display language names in their native script', 'voxfor-multilanguage')}
                        />
                    </PanelBody>
                </InspectorControls>
                
                <div className="voxfor-ml-block-preview">
                    <ServerSideRender
                        block="voxfor-ml/language-switcher"
                        attributes={attributes}
                    />
                </div>
            </Fragment>
        );
    },
    save: () => {
        // Server-side rendered
        return null;
    }
});

// Current Language Block
registerBlockType('voxfor-ml/current-language', {
    title: __('Current Language', 'voxfor-multilanguage'),
    description: __('Display the current language name', 'voxfor-multilanguage'),
    icon: 'flag',
    category: 'widgets',
    keywords: [
        __('language', 'voxfor-multilanguage'),
        __('current', 'voxfor-multilanguage')
    ],
    attributes: {
        format: {
            type: 'string',
            default: 'name'
        }
    },
    edit: ({ attributes, setAttributes }) => {
        const { format } = attributes;

        return (
            <Fragment>
                <InspectorControls>
                    <PanelBody title={__('Display Settings', 'voxfor-multilanguage')}>
                        <SelectControl
                            label={__('Format', 'voxfor-multilanguage')}
                            value={format}
                            options={[
                                { label: __('Language Name', 'voxfor-multilanguage'), value: 'name' },
                                { label: __('Native Name', 'voxfor-multilanguage'), value: 'native' },
                                { label: __('Language Code', 'voxfor-multilanguage'), value: 'code' },
                                { label: __('Flag', 'voxfor-multilanguage'), value: 'flag' }
                            ]}
                            onChange={(value) => setAttributes({ format: value })}
                        />
                    </PanelBody>
                </InspectorControls>
                
                <div className="voxfor-ml-current-language-preview">
                    {format === 'flag' ? '🇬🇧' : 'English'}
                </div>
            </Fragment>
        );
    },
    save: () => {
        return null;
    }
});

// Translatable Content Block
registerBlockType('voxfor-ml/translatable', {
    title: __('Translatable Content', 'voxfor-multilanguage'),
    description: __('Wrap content that should be translated', 'voxfor-multilanguage'),
    icon: 'editor-paragraph',
    category: 'common',
    keywords: [
        __('translate', 'voxfor-multilanguage'),
        __('content', 'voxfor-multilanguage')
    ],
    attributes: {
        content: {
            type: 'string',
            default: ''
        },
        context: {
            type: 'string',
            default: 'general'
        }
    },
    edit: ({ attributes, setAttributes }) => {
        const { content, context } = attributes;

        return (
            <Fragment>
                <InspectorControls>
                    <PanelBody title={__('Translation Settings', 'voxfor-multilanguage')}>
                        <SelectControl
                            label={__('Context', 'voxfor-multilanguage')}
                            value={context}
                            options={[
                                { label: __('General', 'voxfor-multilanguage'), value: 'general' },
                                { label: __('Title', 'voxfor-multilanguage'), value: 'title' },
                                { label: __('Description', 'voxfor-multilanguage'), value: 'description' },
                                { label: __('Button', 'voxfor-multilanguage'), value: 'button' },
                                { label: __('Menu', 'voxfor-multilanguage'), value: 'menu' }
                            ]}
                            onChange={(value) => setAttributes({ context: value })}
                            help={__('Context helps provide better translations', 'voxfor-multilanguage')}
                        />
                    </PanelBody>
                </InspectorControls>
                
                <div className="voxfor-ml-translatable-content">
                    <textarea
                        value={content}
                        onChange={(e) => setAttributes({ content: e.target.value })}
                        placeholder={__('Enter content to be translated...', 'voxfor-multilanguage')}
                        rows="4"
                        style={{ width: '100%' }}
                    />
                </div>
            </Fragment>
        );
    },
    save: ({ attributes }) => {
        const { content, context } = attributes;
        
        return (
            <div className="voxfor-ml-translatable" data-context={context}>
                {content}
            </div>
        );
    }
});

// No Translate Block
registerBlockType('voxfor-ml/no-translate', {
    title: __('No Translate', 'voxfor-multilanguage'),
    description: __('Wrap content that should NOT be translated', 'voxfor-multilanguage'),
    icon: 'dismiss',
    category: 'common',
    keywords: [
        __('exclude', 'voxfor-multilanguage'),
        __('no translate', 'voxfor-multilanguage')
    ],
    edit: ({ children }) => {
        return (
            <div className="voxfor-ml-no-translate-wrapper" style={{ 
                border: '2px dashed #dc3232', 
                padding: '10px',
                backgroundColor: '#fff5f5'
            }}>
                <p style={{ margin: '0 0 10px', color: '#dc3232', fontSize: '12px' }}>
                    {__('⚠️ This content will NOT be translated', 'voxfor-multilanguage')}
                </p>
                {children}
            </div>
        );
    },
    save: ({ children }) => {
        return (
            <div className="no-translate">
                {children}
            </div>
        );
    }
});