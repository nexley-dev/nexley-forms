/**
 * Nexley Forms - Drag-and-Drop Admin Builder
 * Vanilla JS, accessible, zero frontend bleed.
 */
(() => {
  'use strict';

  const root = document.getElementById('nexley-builder-root');
  if (!root) return;

  const schemaInput = document.getElementById('nexley_form_schema_json');
  let schema = {};
  try {
    schema = JSON.parse(schemaInput.value || '{}');
  } catch (e) {
    schema = {};
  }

  // Ensure baseline schema structure
  schema.layout = schema.layout || { columns: 12, breakpoint: 640 };
  schema.rows = schema.rows || [];
  schema.fields = schema.fields || [];
  schema.settings = schema.settings || {};
  schema.notifications = schema.notifications || [];
  schema.actions = schema.actions || [];

  // Undo / Redo history
  const undoStack = [];
  const redoStack = [];
  let isDirty = false;
  let activeFieldId = null;

  function pushState() {
    undoStack.push(JSON.stringify(schema));
    if (undoStack.length > 30) undoStack.shift();
    redoStack.length = 0;
    isDirty = true;
    updateHistoryButtons();
    syncSchemaInput();
    scheduleAutosave();
  }

  function doUndo() {
    if (undoStack.length === 0) return;
    redoStack.push(JSON.stringify(schema));
    const prev = undoStack.pop();
    try {
      schema = JSON.parse(prev);
      syncSchemaInput();
      renderCanvas();
      if (activeFieldId) {
        const found = schema.fields.find(f => f.id === activeFieldId);
        if (found) renderDrawerSettings(found);
        else closeDrawer();
      }
      updateHistoryButtons();
      announce('Undo applied');
    } catch (e) {}
  }

  function doRedo() {
    if (redoStack.length === 0) return;
    undoStack.push(JSON.stringify(schema));
    const next = redoStack.pop();
    try {
      schema = JSON.parse(next);
      syncSchemaInput();
      renderCanvas();
      if (activeFieldId) {
        const found = schema.fields.find(f => f.id === activeFieldId);
        if (found) renderDrawerSettings(found);
        else closeDrawer();
      }
      updateHistoryButtons();
      announce('Redo applied');
    } catch (e) {}
  }

  function updateHistoryButtons() {
    const undoBtn = root.querySelector('.nexley-undo-btn');
    const redoBtn = root.querySelector('.nexley-redo-btn');
    if (undoBtn) undoBtn.disabled = undoStack.length === 0;
    if (redoBtn) redoBtn.disabled = redoStack.length === 0;
  }

  const undoBtnEl = root.querySelector('.nexley-undo-btn');
  const redoBtnEl = root.querySelector('.nexley-redo-btn');
  if (undoBtnEl) undoBtnEl.addEventListener('click', doUndo);
  if (redoBtnEl) redoBtnEl.addEventListener('click', doRedo);

  let autosaveTimer = null;
  function scheduleAutosave() {
    if (autosaveTimer) clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(performAutosave, 3000);
  }

  function performAutosave() {
    const formId = root.dataset.formId || '0';
    const ajaxUrl = root.dataset.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
    const nonce = root.dataset.autosaveNonce || '';

    const statusEl = document.getElementById('nexley-autosave-indicator');
    const timeEl = statusEl ? statusEl.querySelector('.nexley-autosave-text') : null;
    if (statusEl) {
      statusEl.style.display = 'inline-flex';
      if (timeEl) timeEl.textContent = 'Autosaving draft...';
    }

    try {
      localStorage.setItem('nexley_builder_autosave_' + formId, JSON.stringify(schema));
    } catch (e) {}

    const formData = new FormData();
    formData.append('action', 'nexley_autosave_draft');
    formData.append('form_id', formId);
    formData.append('schema', JSON.stringify(schema));
    formData.append('nonce', nonce);

    fetch(ajaxUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(res => {
      if (res && res.success) {
        if (timeEl) {
          timeEl.textContent = 'Draft autosaved ' + (res.data.autosaved_at || new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
        }
        announce('Form draft autosaved to server');
      }
    })
    .catch(() => {
      if (timeEl) {
        timeEl.textContent = 'Autosaved locally ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      }
    });
  }

  function syncSchemaInput() {
    const tTitle = document.getElementById('nexley-topbar-title');
    const wTitle = document.getElementById('title');
    const currentTitle = (tTitle && tTitle.value.trim()) || (wTitle && wTitle.value.trim()) || '';
    if (currentTitle && schema) {
      schema.title = currentTitle;
    }
    schemaInput.value = JSON.stringify(schema);
  }

  function announce(msg) {
    const el = document.getElementById('nexley-aria-announcer');
    if (el) el.textContent = msg;
  }

  /* --------------------------------------------------------------------------
   * 1. Top Tabs & Navigation
   * -------------------------------------------------------------------------- */
  const tabButtons = root.querySelectorAll('.nexley-tab-btn');
  const tabPanes = root.querySelectorAll('.nexley-tab-pane');

  tabButtons.forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const target = btn.dataset.tab;

      tabButtons.forEach((b) => {
        const active = b === btn;
        b.classList.toggle('is-active', active);
        b.setAttribute('aria-selected', active ? 'true' : 'false');
      });

      tabPanes.forEach((p) => {
        const active = p.id === 'nexley-pane-' + target;
        p.classList.toggle('is-active', active);
        p.style.display = active ? 'block' : 'none';
      });

      if (target === 'preview') renderLivePreview();
      if (target === 'actions') renderNotificationsList();
    });
  });

  /* --------------------------------------------------------------------------
   * 2. Palette Search & Click-to-Add
   * -------------------------------------------------------------------------- */
  const searchInput = document.getElementById('nexley-palette-search');
  if (searchInput) {
    searchInput.addEventListener('input', () => {
      const q = searchInput.value.toLowerCase().trim();
      root.querySelectorAll('.nexley-palette-item').forEach((item) => {
        const title = (item.querySelector('.nexley-palette-item-title')?.textContent || '').toLowerCase();
        item.style.display = title.includes(q) ? 'flex' : 'none';
      });
    });
  }

  // Click-to-add buttons on palette items
  root.querySelectorAll('.nexley-add-field-btn').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const item = btn.closest('.nexley-palette-item');
      if (!item) return;
      const type = item.dataset.type;
      addFieldToCanvas(type);
    });
  });

  // Palette dragging
  root.querySelectorAll('.nexley-palette-item').forEach((item) => {
    item.addEventListener('dragstart', (e) => {
      e.dataTransfer.setData('text/plain', JSON.stringify({ action: 'create', type: item.dataset.type }));
      e.dataTransfer.effectAllowed = 'copy';
    });
  });

  /* --------------------------------------------------------------------------
   * 3. Canvas Rendering & Row Width Tracking
   * -------------------------------------------------------------------------- */
  const canvasContainer = document.getElementById('nexley-canvas-rows');

  function renderCanvas() {
    canvasContainer.innerHTML = '';

    if (!schema.rows || !schema.rows.length) {
      if (schema.fields && schema.fields.length) {
        deriveRowsFromFields();
      } else {
        canvasContainer.innerHTML = '<div class="nexley-canvas-empty"><p>Your form is empty. Drag fields from the palette on the left or click to add.</p></div>';
        return;
      }
    }

    schema.rows.forEach((row, rowIdx) => {
      const rowEl = document.createElement('div');
      rowEl.className = 'nexley-canvas-row';
      rowEl.dataset.rowId = row.id;
      rowEl.dataset.rowIdx = String(rowIdx);

      // Calculate total span sum and check if row equals 12
      let totalSpan = 0;
      (row.columns || []).forEach(col => { totalSpan += (col.span || 12); });
      const remaining = 12 - totalSpan;
      const isComplete = (remaining === 0);

      const headerEl = document.createElement('div');
      headerEl.className = 'nexley-row-header';

      let statusBadge = isComplete
        ? `<span class="nexley-badge nexley-badge--complete">12 / 12 cols (Complete)</span>`
        : `<span class="nexley-badge nexley-badge--warning">Row sum: ${totalSpan}/12 (${remaining > 0 ? remaining + ' remaining' : 'exceeds by ' + Math.abs(remaining)})</span>`;

      headerEl.innerHTML = `
        <span class="nexley-row-title">Row ${rowIdx + 1}</span>
        ${statusBadge}
        <div class="nexley-row-actions">
          <button type="button" class="button button-small nexley-del-row-btn" title="Delete Row" aria-label="Delete Row ${rowIdx + 1}">
            <svg class="nexley-icon" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#nexley-icon-trash"></use></svg>
          </button>
        </div>
      `;
      rowEl.appendChild(headerEl);

      headerEl.querySelector('.nexley-del-row-btn').addEventListener('click', () => {
        deleteRow(rowIdx);
      });

      // Row fields grid (12-column flat layout inside row container)
      const gridEl = document.createElement('div');
      gridEl.className = 'nexley-row-grid';
      gridEl.style.setProperty('--nexley-cols', '12');

      const rowItems = [];

      (row.columns || []).forEach((col, colIdx) => {
        (col.fields || []).forEach((fieldId) => {
          const field = schema.fields.find(f => f.id === fieldId);
          if (!field) return;

          const fieldEl = document.createElement('div');
          fieldEl.className = 'nexley-canvas-field' + (activeFieldId === field.id ? ' is-selected' : '');
          fieldEl.dataset.fieldId = field.id;
          fieldEl.dataset.rowIdx = String(rowIdx);
          fieldEl.dataset.colIdx = String(colIdx);
          fieldEl.draggable = true;
          fieldEl.tabIndex = 0;
          fieldEl.setAttribute('role', 'region');
          fieldEl.setAttribute('aria-label', `${field.label || field.name}, column span ${col.span || 12}`);
          fieldEl.style.setProperty('--span', String(col.span || 12));

          fieldEl.addEventListener('focus', () => {
            if (activeFieldId !== field.id) selectField(field.id);
          });

          const reqMark = field.required ? '<span class="nexley-req">*</span>' : '';
          const spanLabel = getSpanLabel(col.span || 12);

          fieldEl.innerHTML = `
            <div class="nexley-field-bar">
              <span class="nexley-field-handle" title="Drag to reorder" aria-label="Drag to reorder" tabindex="0" role="button">
                <svg class="nexley-icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#nexley-icon-drag"></use></svg>
              </span>
              <span class="nexley-field-name">${escapeHtml(field.label || field.name)} ${reqMark}</span>
              <span class="nexley-field-span-badge">${spanLabel}</span>
              <div class="nexley-field-controls">
                <button type="button" class="nexley-btn-ctrl nexley-field-edit-btn" title="Settings" aria-label="Field Settings">
                  <svg class="nexley-icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#nexley-icon-settings"></use></svg>
                </button>
                <button type="button" class="nexley-btn-ctrl nexley-field-dup-btn" title="Duplicate" aria-label="Duplicate Field">
                  <svg class="nexley-icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#nexley-icon-duplicate"></use></svg>
                </button>
                <button type="button" class="nexley-btn-ctrl nexley-field-del-btn" title="Delete" aria-label="Delete Field">
                  <svg class="nexley-icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#nexley-icon-trash"></use></svg>
                </button>
              </div>
            </div>
            <div class="nexley-field-preview">
              ${renderFieldPreviewInput(field)}
            </div>
            <div class="nexley-field-resize-handle" title="Drag to resize column span" aria-label="Resize column span" tabindex="0"></div>
          `;

          // Click to open settings
          fieldEl.addEventListener('click', (e) => {
            if (e.target.closest('.nexley-btn-ctrl') || e.target.closest('.nexley-field-resize-handle')) return;
            selectField(field.id);
          });

          // Control buttons
          fieldEl.querySelector('.nexley-field-edit-btn').addEventListener('click', () => selectField(field.id));
          fieldEl.querySelector('.nexley-field-dup-btn').addEventListener('click', () => duplicateField(field.id));
          fieldEl.querySelector('.nexley-field-del-btn').addEventListener('click', () => deleteField(field.id));

          // Drag and drop for reordering
          bindFieldDragEvents(fieldEl, rowIdx, colIdx);

          gridEl.appendChild(fieldEl);

          rowItems.push({
            fieldEl,
            col,
            field,
            colIdx
          });
        });
      });

      // Compute line placement and bind resize handles (single or dual/gutter)
      let lineSpan = 0;
      for (let i = 0; i < rowItems.length; i++) {
        const item = rowItems[i];
        const s = item.col.span || (item.field && item.field.span) || 12;
        if (lineSpan + s > 12) {
          lineSpan = 0;
        }
        item.lineStart = lineSpan;
        lineSpan += s;
        item.lineEnd = lineSpan;
      }

      for (let i = 0; i < rowItems.length; i++) {
        const item = rowItems[i];
        const nextItem = rowItems[i + 1];
        const nextSpan = nextItem ? (nextItem.col.span || (nextItem.field && nextItem.field.span) || 12) : 0;
        const isAdjacentOnSameLine = nextItem && (item.lineEnd + nextSpan <= 12);

        if (isAdjacentOnSameLine) {
          // If they reference the same col object, decouple so each has its own independent span
          if (item.col === nextItem.col) {
            const newCol = {
              id: 'col_' + nextItem.field.id,
              span: nextItem.field.span || item.col.span || 6,
              fields: [nextItem.field.id]
            };
            item.col.fields = item.col.fields.filter(id => id !== nextItem.field.id);
            const cIdx = row.columns.indexOf(item.col);
            row.columns.splice(cIdx + 1, 0, newCol);
            nextItem.col = newCol;
          }
          bindResizeHandle(item.fieldEl, item.col, item.field, nextItem.fieldEl, nextItem.col, nextItem.field, row, gridEl);
        } else {
          bindResizeHandle(item.fieldEl, item.col, item.field, null, null, null, row, gridEl);
        }
      }

      // Allow dragging into empty row areas
      gridEl.addEventListener('dragover', (e) => {
        e.preventDefault();
        gridEl.classList.add('is-dragover');
      });
      gridEl.addEventListener('dragleave', () => gridEl.classList.remove('is-dragover'));
      gridEl.addEventListener('drop', (e) => {
        e.preventDefault();
        gridEl.classList.remove('is-dragover');
        handleCanvasDrop(e, rowIdx);
      });

      rowEl.appendChild(gridEl);
      canvasContainer.appendChild(rowEl);
    });
  }

  function getSpanLabel(span) {
    if (span === 12) return 'Full (12)';
    if (span === 6) return '1/2 (6)';
    if (span === 4) return '1/3 (4)';
    if (span === 8) return '2/3 (8)';
    if (span === 3) return '1/4 (3)';
    return `${span}/12`;
  }

  function renderFieldPreviewInput(field) {
    const type = field.type || 'text';
    const ph = escapeHtml(field.placeholder || '');
    if (type === 'textarea') return `<textarea disabled class="widefat" placeholder="${ph}"></textarea>`;
    if (type === 'select') return `<select disabled class="widefat"><option>${ph || 'Select option'}</option></select>`;
    if (type === 'submit') return `<button type="button" class="button button-primary">${escapeHtml(field.label || 'Submit')}</button>`;
    if (type === 'heading') return `<h3>${escapeHtml(field.label || 'Section Heading')}</h3>`;
    if (type === 'divider') return `<hr />`;
    if (type === 'spacer') return `<div style="height:20px;background:#f1f5f9;border:1px dashed #cbd5e1;"></div>`;
    return `<input type="text" disabled class="widefat" placeholder="${ph}" />`;
  }

  function escapeHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* --------------------------------------------------------------------------
   * 4. Add / Duplicate / Delete Fields
   * -------------------------------------------------------------------------- */
  function addFieldToCanvas(type, targetRowIdx = -1) {
    pushState();

    const idSuffix = Math.random().toString(36).substring(2, 7);
    const newFieldId = 'f_' + type + '_' + idSuffix;
    const newName = type + '-' + idSuffix;
    const label = type.charAt(0).toUpperCase() + type.slice(1).replace('-', ' ');

    const newField = {
      id: newFieldId,
      type: type,
      name: newName,
      label: label,
      placeholder: '',
      default: '',
      required: false,
      span: 12,
      help_text: '',
      css_class: '',
      logic: null
    };

    schema.fields.push(newField);

    // Place into target row or new row
    if (targetRowIdx >= 0 && schema.rows[targetRowIdx]) {
      const row = schema.rows[targetRowIdx];
      row.columns = row.columns || [];
      let currentSum = 0;
      row.columns.forEach(c => { currentSum += (c.span || 12); });
      let rem = 12 - currentSum;
      let span = 12;

      if (rem > 0) {
        span = rem;
      } else if (row.columns.length === 1 && (row.columns[0].span || 12) === 12) {
        // Automatically split single full-width field into two side-by-side (span 6 each)
        row.columns[0].span = 6;
        const f0 = schema.fields.find(f => (row.columns[0].fields || []).includes(f.id));
        if (f0) f0.span = 6;
        span = 6;
      } else {
        span = 6;
      }

      newField.span = span;
      row.columns.push({
        id: 'col_' + newFieldId,
        span: span,
        fields: [newFieldId]
      });
    } else {
      newField.span = 12;
      schema.rows.push({
        id: 'row_' + Math.random().toString(36).substring(2, 7),
        columns: [{
          id: 'col_' + newFieldId,
          span: 12,
          fields: [newFieldId]
        }]
      });
    }

    renderCanvas();
    selectField(newFieldId);
    announce(`Added ${label} field to form`);
  }

  function duplicateField(fieldId) {
    pushState();
    const orig = schema.fields.find(f => f.id === fieldId);
    if (!orig) return;

    const dupId = orig.id + '_copy';
    const dupName = orig.name + '-copy';
    const copy = JSON.parse(JSON.stringify(orig));
    copy.id = dupId;
    copy.name = dupName;
    copy.label = orig.label + ' (Copy)';

    schema.fields.push(copy);

    // Insert directly adjacent to original in rows
    schema.rows.forEach(row => {
      (row.columns || []).forEach(col => {
        if ((col.fields || []).includes(fieldId)) {
          col.fields.push(dupId);
        }
      });
    });

    renderCanvas();
    selectField(dupId);
    announce(`Duplicated ${copy.label}`);
  }

  function deleteField(fieldId) {
    if (!confirm('Are you sure you want to delete this field?')) return;
    pushState();

    schema.fields = schema.fields.filter(f => f.id !== fieldId);

    // Remove from rows and clean up empty columns/rows
    schema.rows.forEach(row => {
      (row.columns || []).forEach(col => {
        col.fields = (col.fields || []).filter(id => id !== fieldId);
      });
      row.columns = (row.columns || []).filter(col => col.fields.length > 0);
    });
    schema.rows = schema.rows.filter(row => (row.columns || []).length > 0);

    if (activeFieldId === fieldId) {
      activeFieldId = null;
      closeDrawer();
    }

    renderCanvas();
    announce('Field deleted');
  }

  function deleteRow(rowIdx) {
    if (!confirm('Delete this entire row and its fields?')) return;
    pushState();

    const row = schema.rows[rowIdx];
    if (row) {
      (row.columns || []).forEach(col => {
        (col.fields || []).forEach(fid => {
          schema.fields = schema.fields.filter(f => f.id !== fid);
        });
      });
      schema.rows.splice(rowIdx, 1);
    }

    renderCanvas();
    announce('Row deleted');
  }

  const addRowBtn = document.getElementById('nexley-add-row-btn');
  if (addRowBtn) {
    addRowBtn.addEventListener('click', () => {
      pushState();
      schema.rows.push({
        id: 'row_' + Math.random().toString(36).substring(2, 7),
        columns: []
      });
      renderCanvas();
      announce('New empty row added');
    });
  }

  /* --------------------------------------------------------------------------
   * 5. Drag and Drop Reordering
   * -------------------------------------------------------------------------- */
  function bindFieldDragEvents(fieldEl, rowIdx, colIdx) {
    fieldEl.addEventListener('dragstart', (e) => {
      fieldEl.classList.add('is-dragging');
      e.dataTransfer.setData('text/plain', JSON.stringify({
        action: 'move',
        fieldId: fieldEl.dataset.fieldId,
        fromRow: rowIdx,
        fromCol: colIdx
      }));
    });

    fieldEl.addEventListener('dragend', () => {
      fieldEl.classList.remove('is-dragging');
    });
  }

  function handleCanvasDrop(e, targetRowIdx) {
    let data;
    try {
      data = JSON.parse(e.dataTransfer.getData('text/plain'));
    } catch (err) {
      return;
    }

    if (data.action === 'create') {
      addFieldToCanvas(data.type, targetRowIdx);
    } else if (data.action === 'move') {
      moveFieldToRow(data.fieldId, data.fromRow, targetRowIdx);
    }
  }

  function moveFieldToRow(fieldId, fromRowIdx, toRowIdx) {
    if (fromRowIdx === toRowIdx) return;
    pushState();

    // Remove from source row
    const srcRow = schema.rows[fromRowIdx];
    if (srcRow) {
      srcRow.columns.forEach(col => {
        col.fields = (col.fields || []).filter(id => id !== fieldId);
      });
      srcRow.columns = srcRow.columns.filter(col => col.fields.length > 0);
    }

    // Add to target row
    const targetRow = schema.rows[toRowIdx];
    if (targetRow) {
      targetRow.columns = targetRow.columns || [];
      targetRow.columns.push({
        id: 'col_' + fieldId,
        span: 12,
        fields: [fieldId]
      });
    }

    schema.rows = schema.rows.filter(r => (r.columns || []).length > 0);
    renderCanvas();
    announce('Field moved to new row');
  }

  /* --------------------------------------------------------------------------
   * 6. Resize Handles & Column Spans (Single & Dual/Middle Gutter)
   * -------------------------------------------------------------------------- */
  function bindResizeHandle(fieldElA, colA, fieldA, fieldElB, colB, fieldB, row, gridEl) {
    const handle = fieldElA.querySelector('.nexley-field-resize-handle');
    if (!handle) return;

    const isDual = !!(fieldElB && colB && fieldB);

    if (isDual) {
      handle.classList.add('is-dual');
      handle.setAttribute('title', 'Drag to resize adjacent fields');
      handle.setAttribute('aria-label', `Resize between ${fieldA.label || fieldA.name} and ${fieldB.label || fieldB.name}`);
    } else {
      handle.classList.remove('is-dual');
      handle.setAttribute('title', 'Drag to resize column span');
      handle.setAttribute('aria-label', `Resize column span for ${fieldA ? (fieldA.label || fieldA.name) : 'field'}`);
    }

    handle.addEventListener('mousedown', (e) => {
      e.preventDefault();
      e.stopPropagation();

      const startX = e.clientX;
      const initialSpanA = colA.span || (fieldA && fieldA.span) || 6;
      handle.classList.add('is-resizing');
      document.body.classList.add('nexley-is-resizing-col');

      const gridWidth = gridEl ? gridEl.getBoundingClientRect().width : 0;
      const colPitch = gridWidth > 0 ? (gridWidth / 12) : 50;

      if (isDual) {
        const initialSpanB = colB.span || (fieldB && fieldB.span) || 6;
        const totalCombined = initialSpanA + initialSpanB;

        function onMouseMove(moveEvent) {
          const deltaX = moveEvent.clientX - startX;
          const spanChange = Math.round(deltaX / colPitch);

          // Moving left (deltaX < 0) shrinks A and enlarges B
          // Moving right (deltaX > 0) enlarges A and shrinks B
          const newSpanA = Math.max(1, Math.min(totalCombined - 1, initialSpanA + spanChange));
          const newSpanB = totalCombined - newSpanA;

          if (newSpanA !== colA.span || newSpanB !== colB.span) {
            colA.span = newSpanA;
            colB.span = newSpanB;
            if (fieldA) fieldA.span = newSpanA;
            if (fieldB) fieldB.span = newSpanB;

            fieldElA.style.setProperty('--span', String(newSpanA));
            fieldElB.style.setProperty('--span', String(newSpanB));

            const badgeA = fieldElA.querySelector('.nexley-field-span-badge');
            if (badgeA) badgeA.textContent = getSpanLabel(newSpanA);

            const badgeB = fieldElB.querySelector('.nexley-field-span-badge');
            if (badgeB) badgeB.textContent = getSpanLabel(newSpanB);

            fieldElA.setAttribute('aria-label', `${fieldA.label || fieldA.name}, column span ${newSpanA}`);
            fieldElB.setAttribute('aria-label', `${fieldB.label || fieldB.name}, column span ${newSpanB}`);

            const spanSelect = document.getElementById('nexley_dr_span');
            if (spanSelect) {
              if (activeFieldId === fieldA.id) spanSelect.value = String(newSpanA);
              if (activeFieldId === fieldB.id) spanSelect.value = String(newSpanB);
            }
          }
        }

        function onMouseUp() {
          document.removeEventListener('mousemove', onMouseMove);
          document.removeEventListener('mouseup', onMouseUp);
          document.body.classList.remove('nexley-is-resizing-col');
          handle.classList.remove('is-resizing');
          pushState();
          renderCanvas();
          announce(`Resized columns: ${fieldA.label || fieldA.name} to ${colA.span}, ${fieldB.label || fieldB.name} to ${colB.span}`);
        }

        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
      } else {
        // Single field resize
        function onMouseMove(moveEvent) {
          const deltaX = moveEvent.clientX - startX;
          const spanChange = Math.round(deltaX / colPitch);
          const newSpan = Math.max(1, Math.min(12, initialSpanA + spanChange));

          if (newSpan !== colA.span) {
            colA.span = newSpan;
            if (fieldA) fieldA.span = newSpan;
            fieldElA.style.setProperty('--span', String(newSpan));

            const badge = fieldElA.querySelector('.nexley-field-span-badge');
            if (badge) badge.textContent = getSpanLabel(newSpan);

            fieldElA.setAttribute('aria-label', `${fieldA.label || fieldA.name}, column span ${newSpan}`);

            const spanSelect = document.getElementById('nexley_dr_span');
            if (spanSelect && activeFieldId === fieldA.id) {
              spanSelect.value = String(newSpan);
            }
          }
        }

        function onMouseUp() {
          document.removeEventListener('mousemove', onMouseMove);
          document.removeEventListener('mouseup', onMouseUp);
          document.body.classList.remove('nexley-is-resizing-col');
          handle.classList.remove('is-resizing');
          pushState();
          renderCanvas();
          announce(`Column span updated to ${colA.span}`);
        }

        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
      }
    });

    // Keyboard support on handle
    handle.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        e.preventDefault();
        e.stopPropagation();

        if (isDual) {
          const spanA = colA.span || (fieldA && fieldA.span) || 6;
          const spanB = colB.span || (fieldB && fieldB.span) || 6;
          const total = spanA + spanB;
          let nextSpanA = spanA;

          if (e.key === 'ArrowLeft' && spanA > 1) {
            nextSpanA--;
          } else if (e.key === 'ArrowRight' && spanA < (total - 1)) {
            nextSpanA++;
          }

          if (nextSpanA !== spanA) {
            const nextSpanB = total - nextSpanA;
            colA.span = nextSpanA;
            colB.span = nextSpanB;
            if (fieldA) fieldA.span = nextSpanA;
            if (fieldB) fieldB.span = nextSpanB;
            pushState();
            renderCanvas();
            announce(`Resized columns: ${fieldA.label || fieldA.name} to ${nextSpanA}, ${fieldB.label || fieldB.name} to ${nextSpanB}`);
          }
        } else {
          let currentSpan = colA.span || (fieldA && fieldA.span) || 12;
          if (e.key === 'ArrowLeft' && currentSpan > 1) currentSpan--;
          if (e.key === 'ArrowRight' && currentSpan < 12) currentSpan++;
          if (currentSpan !== (colA.span || 12)) {
            colA.span = currentSpan;
            if (fieldA) fieldA.span = currentSpan;
            pushState();
            renderCanvas();
            announce(`Column span updated to ${currentSpan}`);
          }
        }
      }
    });
  }

  function deriveRowsFromFields() {
    schema.rows = [];
    schema.fields.forEach((f) => {
      schema.rows.push({
        id: 'row_' + f.id,
        columns: [{
          id: 'col_' + f.id,
          span: f.span || 12,
          fields: [f.id]
        }]
      });
    });
  }

  /* --------------------------------------------------------------------------
   * 7. Field Settings Drawer
   * -------------------------------------------------------------------------- */
  const drawer = document.getElementById('nexley-field-drawer');
  const drawerFields = document.getElementById('nexley-drawer-fields');

  function selectField(fieldId) {
    activeFieldId = fieldId;
    renderCanvas();

    const field = schema.fields.find(f => f.id === fieldId);
    if (!field) {
      closeDrawer();
      return;
    }

    drawer.style.display = 'block';
    renderDrawerSettings(field);
  }

  function closeDrawer() {
    drawer.style.display = 'none';
    activeFieldId = null;
    root.querySelectorAll('.nexley-canvas-field.is-selected').forEach(el => el.classList.remove('is-selected'));
  }

  root.querySelector('.nexley-drawer-close').addEventListener('click', closeDrawer);

  function renderDrawerSettings(field) {
    const isChoiceField = ['select', 'radio', 'checkbox'].includes(field.type);
    const isHtmlField = field.type === 'html';

    let html = `
      <div class="nexley-drawer-group">
        <label for="nexley_dr_label"><strong>Field Label</strong></label>
        <input type="text" id="nexley_dr_label" class="widefat" value="${escapeHtml(field.label || '')}" />
      </div>

      <div class="nexley-drawer-group">
        <label for="nexley_dr_name"><strong>Field Name (Slug)</strong></label>
        <input type="text" id="nexley_dr_name" class="widefat" value="${escapeHtml(field.name || '')}" />
        <span class="description">Identifier used in email tags: {${field.name}}</span>
      </div>

      <div class="nexley-drawer-group">
        <label for="nexley_dr_span"><strong>Column Span (Width)</strong></label>
        <select id="nexley_dr_span" class="widefat">
          <option value="12" ${field.span === 12 ? 'selected' : ''}>Full Width (12 columns)</option>
          <option value="11" ${field.span === 11 ? 'selected' : ''}>11/12 (11 columns)</option>
          <option value="10" ${field.span === 10 ? 'selected' : ''}>5/6 (10 columns)</option>
          <option value="9" ${field.span === 9 ? 'selected' : ''}>3/4 (9 columns)</option>
          <option value="8" ${field.span === 8 ? 'selected' : ''}>2/3 (8 columns)</option>
          <option value="7" ${field.span === 7 ? 'selected' : ''}>7/12 (7 columns)</option>
          <option value="6" ${field.span === 6 ? 'selected' : ''}>1/2 (6 columns)</option>
          <option value="5" ${field.span === 5 ? 'selected' : ''}>5/12 (5 columns)</option>
          <option value="4" ${field.span === 4 ? 'selected' : ''}>1/3 (4 columns)</option>
          <option value="3" ${field.span === 3 ? 'selected' : ''}>1/4 (3 columns)</option>
          <option value="2" ${field.span === 2 ? 'selected' : ''}>1/6 (2 columns)</option>
          <option value="1" ${field.span === 1 ? 'selected' : ''}>1/12 (1 column)</option>
        </select>
      </div>

      <div class="nexley-drawer-group">
        <label>
          <input type="checkbox" id="nexley_dr_required" value="1" ${field.required ? 'checked' : ''} />
          <strong>Required field</strong>
        </label>
      </div>

      <div class="nexley-drawer-group">
        <label for="nexley_dr_placeholder"><strong>Placeholder Text</strong></label>
        <input type="text" id="nexley_dr_placeholder" class="widefat" value="${escapeHtml(field.placeholder || '')}" />
      </div>

      <div class="nexley-drawer-group">
        <label for="nexley_dr_help"><strong>Help Text (Hint)</strong></label>
        <input type="text" id="nexley_dr_help" class="widefat" value="${escapeHtml(field.help_text || '')}" />
      </div>

      <div class="nexley-drawer-group">
        <label for="nexley_dr_css"><strong>Custom CSS Class</strong></label>
        <input type="text" id="nexley_dr_css" class="widefat" value="${escapeHtml(field.css_class || '')}" />
      </div>
    `;

    if (isHtmlField) {
      html += `
        <div class="nexley-drawer-group">
          <label for="nexley_dr_html_content"><strong>HTML Content</strong></label>
          <textarea id="nexley_dr_html_content" rows="6" class="widefat code">${escapeHtml(field.content || '')}</textarea>
          <p class="description">Sanitized via wp_kses. Respects column grid span.</p>
        </div>
      `;
    }

    if (isChoiceField) {
      const opts = field.options || [];
      html += `
        <div class="nexley-drawer-group">
          <label><strong>Choice Options</strong></label>
          <div id="nexley_dr_options_list">
            ${opts.map((opt, i) => `
              <div class="nexley-opt-row" style="display:flex;gap:4px;margin-bottom:4px;align-items:center;">
                <input type="text" class="widefat nexley-opt-val" value="${escapeHtml(opt.value)}" placeholder="Value" />
                <input type="text" class="widefat nexley-opt-lbl" value="${escapeHtml(opt.label)}" placeholder="Label" />
                <button type="button" class="button button-small nexley-btn-ctrl nexley-opt-del" data-idx="${i}" title="Delete Option" aria-label="Delete Option">
                  <svg class="nexley-icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#nexley-icon-trash"></use></svg>
                </button>
              </div>
            `).join('')}
          </div>
          <button type="button" class="button button-small" id="nexley_dr_add_opt"><svg class="nexley-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#nexley-icon-plus"></use></svg> <span>Add Option</span></button>
        </div>
      `;
    }

    // Conditional Logic section
    const logic = field.logic || null;
    const hasLogic = logic && logic.rules && logic.rules.length > 0;

    html += `
      <hr style="margin:16px 0;border:0;border-top:1px solid #e2e8f0;" />
      <div class="nexley-drawer-group">
        <label>
          <input type="checkbox" id="nexley_dr_logic_enable" ${hasLogic ? 'checked' : ''} />
          <strong>Enable Conditional Logic</strong>
        </label>
      </div>

      <div id="nexley_dr_logic_box" style="${hasLogic ? '' : 'display:none;'}">
        <div style="display:flex;gap:8px;margin-bottom:8px;">
          <select id="nexley_dr_logic_action">
            <option value="show" ${logic?.action === 'show' ? 'selected' : ''}>Show this field if</option>
            <option value="hide" ${logic?.action === 'hide' ? 'selected' : ''}>Hide this field if</option>
          </select>
          <select id="nexley_dr_logic_match">
            <option value="all" ${logic?.match === 'all' ? 'selected' : ''}>All rules match</option>
            <option value="any" ${logic?.match === 'any' ? 'selected' : ''}>Any rule matches</option>
          </select>
        </div>
        <div id="nexley_dr_logic_rules">
          ${(logic?.rules || []).map((r, i) => `
            <div class="nexley-logic-rule-row" style="display:flex;gap:4px;margin-bottom:4px;align-items:center;">
              <input type="text" class="regular-text nexley-rule-field" value="${escapeHtml(r.field)}" placeholder="Target Field" />
              <select class="nexley-rule-op">
                <option value="equals" ${r.operator === 'equals' ? 'selected' : ''}>Equals</option>
                <option value="not_equals" ${r.operator === 'not_equals' ? 'selected' : ''}>Not Equals</option>
                <option value="contains" ${r.operator === 'contains' ? 'selected' : ''}>Contains</option>
                <option value="is_empty" ${r.operator === 'is_empty' ? 'selected' : ''}>Is Empty</option>
                <option value="is_not_empty" ${r.operator === 'is_not_empty' ? 'selected' : ''}>Is Not Empty</option>
              </select>
              <input type="text" class="regular-text nexley-rule-val" value="${escapeHtml(r.value)}" placeholder="Value" />
              <button type="button" class="button button-small nexley-btn-ctrl nexley-rule-del" data-idx="${i}" title="Delete Rule" aria-label="Delete Rule">
                <svg class="nexley-icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#nexley-icon-trash"></use></svg>
              </button>
            </div>
          `).join('')}
        </div>
        <button type="button" class="button button-small" id="nexley_dr_add_rule"><svg class="nexley-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#nexley-icon-plus"></use></svg> <span>Add Rule</span></button>
      </div>
    `;

    drawerFields.innerHTML = html;

    // Bind real-time input events
    bindDrawerEvents(field);
  }

  function bindDrawerEvents(field) {
    const labelInput = document.getElementById('nexley_dr_label');
    const nameInput  = document.getElementById('nexley_dr_name');
    const spanSelect = document.getElementById('nexley_dr_span');
    const reqCheck   = document.getElementById('nexley_dr_required');
    const phInput    = document.getElementById('nexley_dr_placeholder');
    const helpInput  = document.getElementById('nexley_dr_help');
    const cssInput   = document.getElementById('nexley_dr_css');

    [labelInput, nameInput, phInput, helpInput, cssInput].forEach(inp => {
      if (inp) {
        inp.addEventListener('input', () => {
          field.label = labelInput.value;
          field.name = nameInput.value.toLowerCase().replace(/[^a-z0-9_-]/g, '-');
          field.placeholder = phInput.value;
          field.help_text = helpInput.value;
          field.css_class = cssInput.value;
          pushState();
          renderCanvas();
        });
      }
    });

    if (spanSelect) {
      spanSelect.addEventListener('change', () => {
        const val = parseInt(spanSelect.value, 10) || 12;
        field.span = val;
        // Sync column span in rows
        schema.rows.forEach(r => {
          (r.columns || []).forEach(c => {
            if ((c.fields || []).includes(field.id)) c.span = val;
          });
        });
        pushState();
        renderCanvas();
      });
    }

    if (reqCheck) {
      reqCheck.addEventListener('change', () => {
        field.required = reqCheck.checked;
        pushState();
        renderCanvas();
      });
    }

    // HTML Content
    const htmlArea = document.getElementById('nexley_dr_html_content');
    if (htmlArea) {
      htmlArea.addEventListener('input', () => {
        field.content = htmlArea.value;
        pushState();
      });
    }

    // Options manager
    const addOptBtn = document.getElementById('nexley_dr_add_opt');
    if (addOptBtn) {
      addOptBtn.addEventListener('click', () => {
        field.options = field.options || [];
        const count = field.options.length + 1;
        field.options.push({ value: 'Option ' + count, label: 'Option ' + count });
        pushState();
        renderDrawerSettings(field);
      });
    }

    drawerFields.querySelectorAll('.nexley-opt-del').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = parseInt(btn.dataset.idx, 10);
        if (field.options) field.options.splice(idx, 1);
        pushState();
        renderDrawerSettings(field);
      });
    });

    // Conditional Logic toggle
    const logicToggle = document.getElementById('nexley_dr_logic_enable');
    const logicBox = document.getElementById('nexley_dr_logic_box');
    if (logicToggle && logicBox) {
      logicToggle.addEventListener('change', () => {
        if (logicToggle.checked) {
          logicBox.style.display = 'block';
          field.logic = field.logic || { action: 'show', match: 'all', rules: [{ field: '', operator: 'equals', value: '' }] };
        } else {
          logicBox.style.display = 'none';
          field.logic = null;
        }
        pushState();
        renderDrawerSettings(field);
      });
    }

    const addRuleBtn = document.getElementById('nexley_dr_add_rule');
    if (addRuleBtn) {
      addRuleBtn.addEventListener('click', () => {
        field.logic = field.logic || { action: 'show', match: 'all', rules: [] };
        field.logic.rules.push({ field: '', operator: 'equals', value: '' });
        pushState();
        renderDrawerSettings(field);
      });
    }

    drawerFields.querySelectorAll('.nexley-rule-del').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = parseInt(btn.dataset.idx, 10);
        if (field.logic && field.logic.rules) field.logic.rules.splice(idx, 1);
        pushState();
        renderDrawerSettings(field);
      });
    });
  }

  /* --------------------------------------------------------------------------
   * 8. Keyboard Accessibility (Shift+Arrows resize, Alt+Arrows reorder, Undo/Redo)
   * -------------------------------------------------------------------------- */
  document.addEventListener('keydown', (e) => {
    // Undo / Redo keyboard shortcuts
    if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z')) {
      if (!['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) {
        e.preventDefault();
        if (e.shiftKey) {
          doRedo();
        } else {
          doUndo();
        }
        return;
      }
    }
    if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || e.key === 'Y')) {
      if (!['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) {
        e.preventDefault();
        doRedo();
        return;
      }
    }

    if (!activeFieldId) return;
    const field = schema.fields.find(f => f.id === activeFieldId);
    if (!field) return;

    // Shift + Left/Right: resize column span
    if (e.shiftKey && (e.key === 'ArrowRight' || e.key === 'ArrowLeft')) {
      e.preventDefault();
      let currentSpan = field.span || 12;
      if (e.key === 'ArrowRight' && currentSpan < 12) currentSpan++;
      if (e.key === 'ArrowLeft' && currentSpan > 1) currentSpan--;
      field.span = currentSpan;
      schema.rows.forEach(r => {
        (r.columns || []).forEach(c => {
          if ((c.fields || []).includes(field.id)) c.span = currentSpan;
        });
      });
      pushState();
      renderCanvas();
      selectField(field.id);
      announce(`Resized field to ${currentSpan} columns`);
    }

    // Alt + Up/Down: reorder field position across rows
    if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
      e.preventDefault();
      reorderFieldByKeyboard(field.id, e.key === 'ArrowUp' ? -1 : 1);
    }

    // Delete key to remove active field
    if (e.key === 'Delete' && !['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) {
      e.preventDefault();
      deleteField(field.id);
    }
  });

  function reorderFieldByKeyboard(fieldId, direction) {
    let fromRow = -1;
    let fromCol = -1;

    schema.rows.forEach((r, rIdx) => {
      (r.columns || []).forEach((c, cIdx) => {
        if ((c.fields || []).includes(fieldId)) {
          fromRow = rIdx;
          fromCol = cIdx;
        }
      });
    });

    if (fromRow === -1) return;

    const targetRow = fromRow + direction;
    if (targetRow < 0 || targetRow >= schema.rows.length) return;

    pushState();

    const colToMove = schema.rows[fromRow].columns.splice(fromCol, 1)[0];
    if (schema.rows[fromRow].columns.length === 0 && schema.rows.length > 1) {
      schema.rows.splice(fromRow, 1);
      const adjustedTarget = direction === 1 ? targetRow - 1 : targetRow;
      schema.rows[adjustedTarget].columns.push(colToMove);
    } else {
      schema.rows[targetRow].columns.push(colToMove);
    }

    renderCanvas();
    selectField(fieldId);
    const fieldObj = schema.fields.find(f => f.id === fieldId);
    announce(`Moved ${fieldObj ? (fieldObj.label || fieldObj.name) : 'field'} ${direction === -1 ? 'up' : 'down'}`);
  }

  /* --------------------------------------------------------------------------
   * 9. Emails & Actions Tab
   * -------------------------------------------------------------------------- */
  const notifContainer = document.getElementById('nexley-notifications-list');

  function renderNotificationsList() {
    if (!notifContainer) return;
    notifContainer.innerHTML = '';

    (schema.notifications || []).forEach((n, idx) => {
      const card = document.createElement('div');
      card.className = 'nexley-action-card';
      card.innerHTML = `
        <div class="nexley-action-card-header">
          <strong>${escapeHtml(n.name || 'Notification ' + (idx + 1))}</strong>
          <button type="button" class="button button-small nexley-del-notif-btn" data-idx="${idx}" title="Delete Notification" aria-label="Delete Notification">
            <svg class="nexley-icon" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#nexley-icon-trash"></use></svg>
          </button>
        </div>
        <table class="form-table">
          <tr>
            <th scope="row">To Email</th>
            <td><input type="text" class="regular-text nexley-notif-to" data-idx="${idx}" value="${escapeHtml(n.to || '{admin_email}')}" /></td>
          </tr>
          <tr>
            <th scope="row">Reply-To</th>
            <td><input type="text" class="regular-text nexley-notif-reply" data-idx="${idx}" value="${escapeHtml(n.reply_to || '')}" placeholder="{email}" /></td>
          </tr>
          <tr>
            <th scope="row">Subject</th>
            <td><input type="text" class="large-text nexley-notif-sub" data-idx="${idx}" value="${escapeHtml(n.subject || '')}" /></td>
          </tr>
          <tr>
            <th scope="row">Message Body</th>
            <td>
              <textarea rows="5" class="large-text code nexley-notif-body" data-idx="${idx}">${escapeHtml(n.body || '{_all_fields}')}</textarea>
              <p class="description" style="margin-top:6px;font-size:12px;color:var(--nx-text-muted);">
                Supported tags: <code>{_all_fields}</code>, <code>{admin_email}</code>, <code>{page_url}</code>, <code>{post_title}</code>, <code>{site_title}</code>, <code>{site_url}</code>, <code>{date}</code>, <code>{time}</code>, <code>{ip}</code>, and field tags (e.g. <code>{email}</code>).
              </p>
            </td>
          </tr>
        </table>
      `;

      card.querySelector('.nexley-del-notif-btn').addEventListener('click', () => {
        schema.notifications.splice(idx, 1);
        pushState();
        renderNotificationsList();
      });

      card.querySelectorAll('input, textarea').forEach(inp => {
        inp.addEventListener('input', () => {
          n.to = card.querySelector('.nexley-notif-to').value;
          n.reply_to = card.querySelector('.nexley-notif-reply').value;
          n.subject = card.querySelector('.nexley-notif-sub').value;
          n.body = card.querySelector('.nexley-notif-body').value;
          pushState();
        });
      });

      notifContainer.appendChild(card);
    });
  }

  const addNotifBtn = document.getElementById('nexley-add-notif-btn');
  if (addNotifBtn) {
    addNotifBtn.addEventListener('click', () => {
      schema.notifications = schema.notifications || [];
      schema.notifications.push({
        id: 'notif_' + Math.random().toString(36).substring(2, 7),
        name: 'Notification ' + (schema.notifications.length + 1),
        active: true,
        to: '{admin_email}',
        reply_to: '',
        subject: 'New submission: {form_title}',
        body: 'You received a new submission:\n\n{_all_fields}',
        conditions: []
      });
      pushState();
      renderNotificationsList();
    });
  }

  /* --------------------------------------------------------------------------
   * 10. Settings Tab Sync
   * -------------------------------------------------------------------------- */
  root.querySelectorAll('input[name="nexley_setting_style_mode"]').forEach(radio => {
    radio.addEventListener('change', () => {
      schema.settings = schema.settings || {};
      schema.settings.style_mode = radio.value;
      pushState();
    });
  });

  const presetSelect = document.getElementById('nexley_setting_preset');
  if (presetSelect) {
    presetSelect.addEventListener('change', () => {
      schema.settings = schema.settings || {};
      schema.settings.preset = presetSelect.value;
      pushState();
    });
  }

  const hpCheck = document.getElementById('nexley_setting_honeypot');
  if (hpCheck) {
    hpCheck.addEventListener('change', () => {
      schema.settings = schema.settings || {};
      schema.settings.honeypot = hpCheck.checked;
      pushState();
    });
  }

  const ttCheck = document.getElementById('nexley_setting_time_trap');
  if (ttCheck) {
    ttCheck.addEventListener('change', () => {
      schema.settings = schema.settings || {};
      schema.settings.time_trap = ttCheck.checked;
      pushState();
    });
  }

  const rateInput = document.getElementById('nexley_setting_rate_limit');
  if (rateInput) {
    rateInput.addEventListener('input', () => {
      schema.settings = schema.settings || {};
      schema.settings.rate_limit = parseInt(rateInput.value, 10) || 0;
      pushState();
    });
  }

  const bpInput = document.getElementById('nexley_setting_breakpoint');
  if (bpInput) {
    bpInput.addEventListener('input', () => {
      schema.layout = schema.layout || { columns: 12, breakpoint: 640 };
      schema.layout.breakpoint = parseInt(bpInput.value, 10) || 640;
      pushState();
    });
  }

  const successMsg = document.getElementById('nexley_success_msg');
  if (successMsg) {
    successMsg.addEventListener('input', () => {
      schema.settings = schema.settings || {};
      schema.settings.success_message = successMsg.value;
      pushState();
    });
  }

  /* --------------------------------------------------------------------------
   * 11. Live Preview Tab
   * -------------------------------------------------------------------------- */
  const previewContainer = document.getElementById('nexley-preview-container');

  function renderLivePreview() {
    if (!previewContainer) return;
    previewContainer.innerHTML = '';

    const previewForm = document.createElement('div');
    const mode = schema.settings?.style_mode || 'base';
    const preset = schema.settings?.preset || 'minimal';

    previewForm.className = `nexley-forms nexley-forms--${mode === 'preset' ? 'preset-' + preset : mode}`;
    previewForm.style.setProperty('--nexley-cols', '12');

    (schema.fields || []).forEach(f => {
      if (f.type === 'hidden') return;
      const fWrap = document.createElement('div');
      fWrap.className = `nexley-forms__group nexley-forms__group--${f.type}`;
      fWrap.style.setProperty('--span', String(f.span || 12));

      if (f.type === 'submit') {
        fWrap.innerHTML = `<button type="button" class="nexley-forms__submit">${escapeHtml(f.label || 'Submit')}</button>`;
      } else if (f.type === 'textarea') {
        fWrap.innerHTML = `
          <label class="nexley-forms__label">${escapeHtml(f.label || f.name)}${f.required ? ' *' : ''}</label>
          <textarea class="nexley-forms__field nexley-forms__control nexley-forms__textarea" rows="4" placeholder="${escapeHtml(f.placeholder || '')}"></textarea>
        `;
      } else if (f.type === 'select') {
        const opts = (f.options || []).map(o => `<option value="${escapeHtml(o.value)}">${escapeHtml(o.label)}</option>`).join('');
        fWrap.innerHTML = `
          <label class="nexley-forms__label">${escapeHtml(f.label || f.name)}${f.required ? ' *' : ''}</label>
          <select class="nexley-forms__field nexley-forms__control nexley-forms__select"><option value="">${escapeHtml(f.placeholder || 'Select...')}</option>${opts}</select>
        `;
      } else {
        fWrap.innerHTML = `
          <label class="nexley-forms__label">${escapeHtml(f.label || f.name)}${f.required ? ' *' : ''}</label>
          <input type="${f.type === 'email' ? 'email' : (f.type === 'number' ? 'number' : 'text')}" class="nexley-forms__field nexley-forms__control" placeholder="${escapeHtml(f.placeholder || '')}" />
        `;
      }
      previewForm.appendChild(fWrap);
    });

    previewContainer.appendChild(previewForm);
  }

  // Device switcher in Preview
  root.querySelectorAll('.nexley-device-switcher button').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      root.querySelectorAll('.nexley-device-switcher button').forEach(b => b.classList.remove('is-active'));
      btn.classList.add('is-active');

      const dev = btn.dataset.device;
      const viewport = document.getElementById('nexley-preview-viewport');
      if (viewport) {
        if (dev === 'mobile') viewport.style.maxWidth = '375px';
        else if (dev === 'tablet') viewport.style.maxWidth = '768px';
        else viewport.style.maxWidth = '100%';
      }
    });
  });
  /* --------------------------------------------------------------------------
   * 12. Top Bar Controls, Save Flow & Unload Handling
   * -------------------------------------------------------------------------- */
  const postForm = root.closest('form') || document.getElementById('post');
  const topbarTitle = document.getElementById('nexley-topbar-title');
  const wpTitle = document.getElementById('title');

  // Bidirectional title sync
  if (topbarTitle) {
    if (wpTitle) {
      wpTitle.removeAttribute('name');
      if (!topbarTitle.value && wpTitle.value) {
        topbarTitle.value = wpTitle.value;
      }
    }
    topbarTitle.addEventListener('input', () => {
      if (wpTitle) wpTitle.value = topbarTitle.value;
      if (schema) schema.title = topbarTitle.value.trim();
      isDirty = true;
    });
    topbarTitle.addEventListener('change', () => {
      if (wpTitle) wpTitle.value = topbarTitle.value;
      if (schema) schema.title = topbarTitle.value.trim();
      syncSchemaInput();
    });
  }

  // Ensure schema and title are synced before form submission
  if (postForm) {
    postForm.addEventListener('submit', () => {
      syncSchemaInput();
      if (wpTitle && topbarTitle) {
        wpTitle.value = topbarTitle.value;
      }
      isDirty = false;
    });
  }

  // Top Bar Preview Button -> activates Preview Tab
  const topbarPreviewBtn = root.querySelector('.nexley-topbar-preview-btn');
  if (topbarPreviewBtn) {
    topbarPreviewBtn.addEventListener('click', (e) => {
      e.preventDefault();
      const previewTab = root.querySelector('.nexley-tab-btn[data-tab="preview"]');
      if (previewTab) {
        previewTab.click();
        previewTab.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });
  }

  // Top Bar Save Draft button
  const draftBtn = root.querySelector('.nexley-topbar-draft-btn');
  if (draftBtn) {
    draftBtn.addEventListener('click', () => {
      const statusInput = document.getElementById('post_status');
      if (statusInput) statusInput.value = 'draft';
      const hiddenStatusInput = document.getElementById('hidden_post_status');
      if (hiddenStatusInput) hiddenStatusInput.value = 'draft';
      isDirty = false;
    });
  }

  // Top Bar Publish button
  const publishBtn = root.querySelector('.nexley-topbar-publish-btn');
  if (publishBtn) {
    publishBtn.addEventListener('click', () => {
      const statusInput = document.getElementById('post_status');
      if (statusInput) statusInput.value = 'publish';
      isDirty = false;
    });
  }

  // Top Bar Update button
  const updateBtn = root.querySelector('.nexley-topbar-save-btn');
  if (updateBtn) {
    updateBtn.addEventListener('click', () => {
      const statusInput = document.getElementById('post_status');
      if (statusInput) statusInput.value = 'publish';
      isDirty = false;
    });
  }

  // Top Bar Switch to Draft (Unpublish) button
  const unpublishBtn = document.getElementById('nexley-btn-unpublish');
  if (unpublishBtn) {
    unpublishBtn.addEventListener('click', () => {
      const statusInput = document.getElementById('post_status');
      if (statusInput) statusInput.value = 'draft';
      const hiddenStatusInput = document.getElementById('hidden_post_status');
      if (hiddenStatusInput) hiddenStatusInput.value = 'draft';
      isDirty = false;
    });
  }

  // Copy Shortcode
  const copyBtn = root.querySelector('.nexley-copy-shortcode-btn');
  if (copyBtn) {
    copyBtn.addEventListener('click', () => {
      const sc = copyBtn.dataset.shortcode;
      if (navigator.clipboard) {
        navigator.clipboard.writeText(sc).then(() => {
          copyBtn.classList.add('button-primary');
          const span = copyBtn.querySelector('span:last-child');
          const orig = span ? span.textContent : '';
          if (span) span.textContent = 'Copied!';
          setTimeout(() => {
            copyBtn.classList.remove('button-primary');
            if (span) span.textContent = orig;
          }, 1500);
        });
      }
    });
  }

  // Unsaved changes warning
  window.addEventListener('beforeunload', (e) => {
    if (isDirty) {
      e.preventDefault();
      e.returnValue = 'You have unsaved changes in your form.';
    }
  });

  // Initial render
  renderCanvas();
})();
