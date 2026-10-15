/**
 * Nexley Forms - Admin JavaScript
 * Handles Tag Generators, Cursor-based Insertion, Copy Shortcode, and UI toggles.
 */
(function ($) {
  'use strict';

  $(function () {
    const i18n = (window.nexleyAdminConfig && window.nexleyAdminConfig.i18n) || {
      copied: 'Copied!',
      copy: 'Copy',
      insertTag: 'Insert Tag',
      missingName: 'Please enter a field name.'
    };

    const $modal = $('#nexley-tag-modal');
    const $textarea = $('#nexley_form_content');

    let currentTagType = 'text';

    /* ==========================================================================
       1. Tag Generator Modal & Buttons
       ========================================================================== */

    $('.nexley-tag-btn').on('click', function (e) {
      e.preventDefault();
      currentTagType = $(this).data('tag-type') || 'text';
      openTagModal(currentTagType);
    });

    function openTagModal(type) {
      // Reset form fields
      $('#nexley_modal_required').prop('checked', false);
      $('#nexley_modal_name').val('');
      $('#nexley_modal_placeholder').val('');
      $('#nexley_modal_options').val('');
      $('#nexley_modal_submit_label').val('Send Message');

      // Adjust title and visible fields based on tag type
      $('#nexley-modal-title').text('Generate ' + capitalize(type) + ' Tag');

      if (type === 'submit') {
        $('.nexley-modal-field--required').hide();
        $('.nexley-modal-field--name').hide();
        $('.nexley-modal-field--placeholder').hide();
        $('.nexley-modal-field--options').hide();
        $('.nexley-modal-field--submit-label').show();
      } else if (type === 'select') {
        $('.nexley-modal-field--required').show();
        $('.nexley-modal-field--name').show();
        $('.nexley-modal-field--placeholder').show();
        $('.nexley-modal-field--options').show();
        $('.nexley-modal-field--submit-label').hide();
        $('#nexley_modal_placeholder').attr('placeholder', 'e.g. Choose an option...');
      } else {
        $('.nexley-modal-field--required').show();
        $('.nexley-modal-field--name').show();
        $('.nexley-modal-field--placeholder').show();
        $('.nexley-modal-field--options').hide();
        $('.nexley-modal-field--submit-label').hide();
        $('#nexley_modal_placeholder').attr('placeholder', 'e.g. Enter ' + type);
      }

      updateTagPreview();
      $modal.show().attr('aria-hidden', 'false');
      if (type === 'submit') {
        $('#nexley_modal_submit_label').focus().select();
      } else {
        $('#nexley_modal_name').focus();
      }
    }

    function closeTagModal() {
      $modal.hide().attr('aria-hidden', 'true');
    }

    $('.nexley-modal-close, .nexley-modal-cancel').on('click', function (e) {
      e.preventDefault();
      closeTagModal();
    });

    // Close on escape key
    $(document).on('keydown', function (e) {
      if (e.key === 'Escape' && $modal.is(':visible')) {
        closeTagModal();
      }
    });

    // Close on clicking backdrop
    $modal.on('click', function (e) {
      if ($(e.target).is($modal)) {
        closeTagModal();
      }
    });

    // Live update tag preview
    $('#nexley_modal_required, #nexley_modal_name, #nexley_modal_placeholder, #nexley_modal_options, #nexley_modal_submit_label').on('input change', function () {
      updateTagPreview();
    });

    function generateTagString() {
      const isRequired = $('#nexley_modal_required').is(':checked');
      const reqMark = isRequired ? '*' : '';

      if (currentTagType === 'submit') {
        const label = ($('#nexley_modal_submit_label').val() || '').trim() || 'Send';
        return `[submit "${escapeQuotes(label)}"]`;
      }

      const rawName = ($('#nexley_modal_name').val() || '').trim();
      const safeName = rawName.toLowerCase().replace(/[^a-z0-9_-]/g, '-') || 'field-name';
      const placeholder = ($('#nexley_modal_placeholder').val() || '').trim();

      if (currentTagType === 'select') {
        const rawOptions = ($('#nexley_modal_options').val() || '').trim();
        const optionsList = rawOptions ? rawOptions.split('\n').map(o => o.trim()).filter(Boolean) : [];
        let tag = `[select${reqMark} ${safeName}`;
        if (placeholder) {
          tag += ` "${escapeQuotes(placeholder)}"`;
        }
        optionsList.forEach(opt => {
          tag += ` "${escapeQuotes(opt)}"`;
        });
        tag += ']';
        return tag;
      }

      let tag = `[${currentTagType}${reqMark} ${safeName}`;
      if (placeholder) {
        tag += ` placeholder "${escapeQuotes(placeholder)}"`;
      }
      tag += ']';
      return tag;
    }

    function updateTagPreview() {
      $('#nexley_modal_preview').text(generateTagString());
    }

    // Insert Tag into Textarea at caret position
    $('#nexley_modal_insert').on('click', function (e) {
      e.preventDefault();

      if (currentTagType !== 'submit') {
        const nameVal = ($('#nexley_modal_name').val() || '').trim();
        if (!nameVal) {
          alert(i18n.missingName);
          $('#nexley_modal_name').focus();
          return;
        }
      }

      const tagString = generateTagString();
      insertAtCaret($textarea[0], tagString);
      closeTagModal();
      updateAvailableTagsList();
    });

    function insertAtCaret(textarea, text) {
      if (!textarea) return;

      const startPos = textarea.selectionStart;
      const endPos = textarea.selectionEnd;
      const scrollTop = textarea.scrollTop;

      textarea.value =
        textarea.value.substring(0, startPos) +
        text +
        textarea.value.substring(endPos, textarea.value.length);

      textarea.focus();
      textarea.selectionStart = startPos + text.length;
      textarea.selectionEnd = startPos + text.length;
      textarea.scrollTop = scrollTop;

      $(textarea).trigger('input');
    }

    function escapeQuotes(str) {
      return str.replace(/"/g, '&quot;');
    }

    function capitalize(str) {
      return str.charAt(0).toUpperCase() + str.slice(1);
    }

    /* ==========================================================================
       2. Dynamic Tag Pills in Notification & Auto-Responder Meta Boxes
       ========================================================================== */

    let lastFocusedInput = null;

    $('input[type="text"], textarea').on('focus', function () {
      if (!$(this).is('#nexley_form_content') && !$(this).closest('#nexley-tag-modal').length) {
        lastFocusedInput = this;
      }
    });

    $(document).on('click', '.nexley-tag-pill', function (e) {
      e.preventDefault();
      const tagText = $(this).data('tag');
      if (!tagText) return;

      if (lastFocusedInput) {
        insertAtCaret(lastFocusedInput, tagText);
      } else {
        copyTextToClipboard(tagText);
        showPillCopied($(this));
      }
    });

    function showPillCopied($pill) {
      const origText = $pill.text();
      $pill.text(i18n.copied).addClass('is-copied');
      setTimeout(function () {
        $pill.text(origText).removeClass('is-copied');
      }, 1500);
    }

    function updateAvailableTagsList() {
      const content = $textarea.val() || '';
      const regex = /\[([a-zA-Z0-9_-]+)(\*?)(?:\s+([^\]]+))?\]/g;
      let match;
      const discoveredTags = new Set();

      while ((match = regex.exec(content)) !== null) {
        const type = (match[1] || '').toLowerCase();
        if (type === 'submit') continue;
        const rawArgs = match[3] || '';
        const nameMatch = rawArgs.match(/^([a-zA-Z0-9_\-\:]+)/);
        if (nameMatch && nameMatch[1]) {
          discoveredTags.add(nameMatch[1].replace(/[^a-z0-9_-]/g, ''));
        }
      }

      // Rebuild pills container if exists
      $('.nexley-tag-pills-container').each(function () {
        const $container = $(this);
        // Keep system pills
        const $systemPills = $container.find('.nexley-tag-pill--system').clone();
        $container.empty();

        discoveredTags.forEach(name => {
          $container.append(
            $('<button type="button" class="nexley-tag-pill"></button>')
              .attr('data-tag', '{' + name + '}')
              .text('{' + name + '}')
          );
        });

        $container.append($systemPills);
      });
    }

    /* ==========================================================================
       3. Client Auto-Responder Toggle
       ========================================================================== */

    $('#nexley_autoresponder_enable').on('change', function () {
      const isChecked = $(this).is(':checked');
      $('#nexley-autoresponder-fields').stop(true, true).slideToggle(200, function () {
        if (isChecked) {
          $(this).show();
        } else {
          $(this).hide();
        }
      });
    });

    /* ==========================================================================
       4. Copy Shortcode to Clipboard
       ========================================================================== */

    $(document).on('click', '.nexley-copy-shortcode', function (e) {
      e.preventDefault();
      const $btn = $(this);
      let shortcode = $btn.data('shortcode');

      if (!shortcode) {
        const targetSelector = $btn.data('target');
        if (targetSelector) {
          shortcode = $(targetSelector).val();
        }
      }

      if (!shortcode) return;

      copyTextToClipboard(shortcode);

      const $label = $btn.find('.nexley-copy-label');
      const $use = $btn.find('.nexley-icon use');
      const origText = $label.text();

      $label.text(i18n.copied);
      if ($use.length) {
        $use.attr('href', '#nexley-icon-check');
      }
      $btn.addClass('is-copied');

      setTimeout(function () {
        $label.text(origText);
        if ($use.length) {
          $use.attr('href', '#nexley-icon-copy');
        }
        $btn.removeClass('is-copied');
      }, 2000);
    });

    function copyTextToClipboard(text) {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).catch(() => fallbackCopy(text));
      } else {
        fallbackCopy(text);
      }
    }

    function fallbackCopy(text) {
      const $temp = $('<textarea>');
      $('body').append($temp);
      $temp.val(text).select();
      try {
        document.execCommand('copy');
      } catch (e) {
        // Ignore
      }
      $temp.remove();
    }

    /* ==========================================================================
       6. Import / Export Redesign: Drop Zones, Tabs & Select-All
       ========================================================================== */

    // Accessible arrow-key navigation for Import / Export tabs
    const $tabLinks = $('.nexley-import-tab-link');
    if ($tabLinks.length) {
      $tabLinks.on('keydown', function (e) {
        const index = $tabLinks.index(this);
        let targetIndex = -1;

        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
          targetIndex = (index + 1) % $tabLinks.length;
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
          targetIndex = (index - 1 + $tabLinks.length) % $tabLinks.length;
        } else if (e.key === 'Home') {
          targetIndex = 0;
        } else if (e.key === 'End') {
          targetIndex = $tabLinks.length - 1;
        }

        if (targetIndex !== -1) {
          e.preventDefault();
          const targetLink = $tabLinks.eq(targetIndex);
          targetLink.focus();
          window.location.href = targetLink.attr('href');
        }
      });
    }

    // Drop Zone Click & Drag-and-Drop handling
    $('.nexley-dropzone').each(function () {
      const $zone = $(this);
      const $fileInput = $zone.find('.nexley-file-input');
      const $form = $zone.closest('form');
      const $submitBtns = $form.find('.nexley-dropzone-submit');
      const $filename = $zone.find('.nexley-filename');
      const $filesize = $zone.find('.nexley-filesize');
      const $removeBtn = $zone.find('.nexley-dropzone-remove-file');

      function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
      }

      function updateFileDisplay(file) {
        if (file) {
          $filename.text(file.name);
          $filesize.text('(' + formatBytes(file.size) + ')');
          $zone.addClass('has-file');
          $submitBtns.prop('disabled', false);
        } else {
          $filename.text('');
          $filesize.text('');
          $zone.removeClass('has-file');
          $submitBtns.prop('disabled', true);
        }
      }

      // Clicking dropzone triggers file input
      $zone.on('click', function (e) {
        if ($(e.target).is($removeBtn) || $(e.target).is($fileInput)) {
          return;
        }
        $fileInput.trigger('click');
      });

      // Keyboard activation via Enter/Space
      $zone.on('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          if (!$(e.target).is($removeBtn) && !$(e.target).is($fileInput)) {
            e.preventDefault();
            $fileInput.trigger('click');
          }
        }
      });

      // Native file input change
      $fileInput.on('change', function () {
        const file = this.files && this.files[0];
        updateFileDisplay(file);
      });

      // Remove button click
      $removeBtn.on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $fileInput.val('');
        updateFileDisplay(null);
      });

      // Drag and drop events
      $zone.on('dragover dragenter', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $zone.addClass('is-dragover');
      });

      $zone.on('dragleave dragend drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $zone.removeClass('is-dragover');
      });

      $zone.on('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const dt = e.originalEvent.dataTransfer;
        if (dt && dt.files && dt.files.length) {
          $fileInput[0].files = dt.files;
          updateFileDisplay(dt.files[0]);
        }
      });
    });

    // Select-all checkbox for detected forms table
    $('.nexley-select-all-forms').on('change', function () {
      const isChecked = $(this).is(':checked');
      const $table = $(this).closest('table');
      $table.find('.nexley-form-checkbox').prop('checked', isChecked);
    });

    $('.nexley-form-checkbox').on('change', function () {
      const $table = $(this).closest('table');
      const allChecked = $table.find('.nexley-form-checkbox:not(:checked)').length === 0;
      $table.find('.nexley-select-all-forms').prop('checked', allChecked);
    });

    // Third-party importer dropdown provider switcher
    const $providerSelect = $('#nexley-provider-select');
    if ($providerSelect.length) {
      $providerSelect.on('change', function () {
        const selected = $(this).val();
        $('.nexley-provider-panel').hide();
        $('#nexley-provider-panel-' + selected).show();
        if (window.history && window.history.replaceState) {
          const url = new URL(window.location.href);
          url.searchParams.set('provider', selected);
          window.history.replaceState(null, '', url.toString());
        }
      });
    }
  });
})(jQuery);

