/**
 * Nexley Forms - Gutenberg Dynamic Block
 * Server-side rendered block for inserting Nexley Forms. Zero frontend JS.
 */
(function (wp) {
  'use strict';

  if (!wp || !wp.blocks || !wp.element || !wp.components) {
    return;
  }

  const { registerBlockType } = wp.blocks;
  const { createElement: el, Fragment } = wp.element;
  const { InspectorControls } = wp.blockEditor || wp.editor;
  const { PanelBody, SelectControl, ToggleControl, Placeholder } = wp.components;
  const ServerSideRender = wp.serverSideRender || wp.components.ServerSideRender;
  const { __ } = wp.i18n;

  const blockData = window.nexleyBlockData || { forms: [] };
  const formOptions = [
    { label: __('— Select a Form —', 'nexley-forms'), value: 0 },
    ...blockData.forms.map((f) => ({
      label: f.title ? `${f.title} (#${f.id})` : `Form #${f.id}`,
      value: f.id,
    })),
  ];

  const presetOptions = [
    { label: __('Default (Form Settings)', 'nexley-forms'), value: '' },
    { label: __('Minimal (Zero Styling)', 'nexley-forms'), value: 'minimal' },
    { label: __('Rounded (Subtle Borders)', 'nexley-forms'), value: 'rounded' },
    { label: __('Outlined (Clean Modern)', 'nexley-forms'), value: 'outlined' },
    { label: __('Dark Mode (High Contrast)', 'nexley-forms'), value: 'dark' },
  ];

  registerBlockType('nexley-forms/form-selector', {
    title: __('Nexley Form', 'nexley-forms'),
    description: __('Embed an ultra-lightweight, zero-bloat Nexley Form into your layout.', 'nexley-forms'),
    icon: 'feedback',
    category: 'widgets',
    keywords: [__('form', 'nexley-forms'), __('contact', 'nexley-forms'), __('nexley', 'nexley-forms')],
    attributes: {
      formId: {
        type: 'number',
        default: 0,
      },
      displayTitle: {
        type: 'boolean',
        default: false,
      },
      stylePreset: {
        type: 'string',
        default: '',
      },
    },

    edit: function (props) {
      const { attributes, setAttributes } = props;
      const { formId, displayTitle, stylePreset } = attributes;

      const inspector = el(
        InspectorControls,
        {},
        el(
          PanelBody,
          { title: __('Form Settings', 'nexley-forms'), initialOpen: true },
          el(SelectControl, {
            label: __('Select Form', 'nexley-forms'),
            value: formId,
            options: formOptions,
            onChange: function (val) {
              setAttributes({ formId: parseInt(val, 10) || 0 });
            },
          }),
          el(ToggleControl, {
            label: __('Display Form Title', 'nexley-forms'),
            checked: displayTitle,
            onChange: function (val) {
              setAttributes({ displayTitle: val });
            },
          }),
          el(SelectControl, {
            label: __('Override Style Preset', 'nexley-forms'),
            value: stylePreset,
            options: presetOptions,
            onChange: function (val) {
              setAttributes({ stylePreset: val });
            },
          })
        )
      );

      if (!formId) {
        return el(
          Fragment,
          {},
          inspector,
          el(
            Placeholder,
            {
              icon: 'feedback',
              label: __('Nexley Forms', 'nexley-forms'),
              instructions: __('Select a form from the dropdown to embed it here.', 'nexley-forms'),
            },
            el(SelectControl, {
              value: formId,
              options: formOptions,
              onChange: function (val) {
                setAttributes({ formId: parseInt(val, 10) || 0 });
              },
            })
          )
        );
      }

      if (ServerSideRender) {
        return el(
          Fragment,
          {},
          inspector,
          el(ServerSideRender, {
            block: 'nexley-forms/form-selector',
            attributes: attributes,
          })
        );
      }

      return el(
        Fragment,
        {},
        inspector,
        el(
          'div',
          { className: 'nexley-block-preview-box', style: { padding: '20px', border: '1px solid #e2e8f0', borderRadius: '6px' } },
          el('strong', {}, __('Nexley Form #', 'nexley-forms') + formId)
        )
      );
    },

    save: function () {
      // Dynamic server-rendered block
      return null;
    },
  });
})(window.wp);
