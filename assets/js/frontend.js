/**
 * Nexley Forms - Ultra-Lightweight Vanilla JS Submission Handler
 * Zero bloat, zero dependencies, fully accessible.
 */
(() => {
  'use strict';

  // Prevent multiple initializations.
  if (window.__nexleyFormsInitialized) return;
  window.__nexleyFormsInitialized = true;

  const config = window.nexleyFormsConfig || {
    endpoint: '/wp-json/nexley/v1/submit',
    nonce: '',
    i18n: {
      submitting: 'Sending...',
      generic_error: 'Something went wrong. Please check your inputs and try again.',
      network_error: 'Network connection error. Please try again.'
    }
  };

  /**
   * Handle form submit event.
   * @param {SubmitEvent} event
   */
  async function handleSubmit(event) {
    const form = event.target;
    if (!form || !form.classList || !form.classList.contains('nexley-forms')) {
      return;
    }

    event.preventDefault();

    const statusEl = form.querySelector('.nexley-forms__status');
    const submitBtn = form.querySelector('button[type="submit"], input[type="submit"], .nexley-forms__submit');
    const originalBtnText = submitBtn ? (submitBtn.textContent || submitBtn.value) : '';

    // Clear previous field-level error styling.
    form.querySelectorAll('.is-invalid, [aria-invalid="true"]').forEach((el) => {
      el.classList.remove('is-invalid');
      el.removeAttribute('aria-invalid');
    });

    // Native browser validity check.
    if (form.checkValidity && !form.checkValidity()) {
      if (form.reportValidity) {
        form.reportValidity();
      }
      return;
    }

    // Set UI submitting state.
    form.classList.add('is-submitting');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.classList.add('is-loading');
      if (config.i18n && config.i18n.submitting) {
        if (submitBtn.tagName === 'INPUT') {
          submitBtn.value = config.i18n.submitting;
        } else {
          submitBtn.textContent = config.i18n.submitting;
        }
      }
    }

    if (statusEl) {
      statusEl.className = 'nexley-forms__status is-loading';
      statusEl.textContent = config.i18n.submitting;
    }

    // Collect form payload.
    const formData = new FormData(form);
    const endpoint = form.getAttribute('action') || config.endpoint;
    const nonce = formData.get('_nexley_nonce') || config.nonce;

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        body: formData,
        headers: {
          'X-WP-Nonce': String(nonce),
          'Accept': 'application/json'
        }
      });

      const data = await response.json().catch(() => null);

      if (response.ok && data && data.success) {
        // Success state.
        form.classList.remove('is-submitting');
        form.classList.add('is-submitted-success');

        if (statusEl) {
          statusEl.className = 'nexley-forms__status is-success';
          statusEl.textContent = data.message || 'Message sent successfully!';
        }

        form.reset();

        // Custom event for analytics / tag managers.
        form.dispatchEvent(new CustomEvent('nexley:form:success', {
          bubbles: true,
          detail: data
        }));
      } else {
        // Error state.
        form.classList.remove('is-submitting');
        form.classList.add('is-submitted-error');

        const message = (data && data.message) ? data.message : config.i18n.generic_error;
        if (statusEl) {
          statusEl.className = 'nexley-forms__status is-error';
          statusEl.textContent = message;
        }

        // Highlight specific invalid fields if returned.
        if (data && data.errors && typeof data.errors === 'object') {
          for (const [fieldName, fieldErr] of Object.entries(data.errors)) {
            const field = form.querySelector(`[name="${fieldName}"]`);
            if (field) {
              field.classList.add('is-invalid');
              field.setAttribute('aria-invalid', 'true');
            }
          }
        }

        form.dispatchEvent(new CustomEvent('nexley:form:error', {
          bubbles: true,
          detail: data || { message }
        }));
      }
    } catch (err) {
      form.classList.remove('is-submitting');
      form.classList.add('is-submitted-error');

      if (statusEl) {
        statusEl.className = 'nexley-forms__status is-error';
        statusEl.textContent = config.i18n.network_error;
      }

      form.dispatchEvent(new CustomEvent('nexley:form:error', {
        bubbles: true,
        detail: { error: err }
      }));
    } finally {
      // Restore submit button.
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('is-loading');
        if (submitBtn.tagName === 'INPUT') {
          submitBtn.value = originalBtnText;
        } else {
          submitBtn.textContent = originalBtnText;
        }
      }
    }
  }

  // Bind submit event using delegation.
  document.addEventListener('submit', handleSubmit, false);
})();
