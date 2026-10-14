/**
 * Nexley Forms - Lazy Anti-Spam / Captcha Loader
 * Dynamically loads Cloudflare Turnstile or hCaptcha strictly on first user interaction.
 * Zero bytes on initial page render.
 */
(() => {
  'use strict';

  let scriptInjected = false;

  function loadCaptchaScript(type, siteKey) {
    if (scriptInjected) return;
    scriptInjected = true;

    if (type === 'turnstile') {
      const s = document.createElement('script');
      s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
      s.async = true;
      s.defer = true;
      document.head.appendChild(s);
    } else if (type === 'hcaptcha') {
      const s = document.createElement('script');
      s.src = 'https://js.hcaptcha.com/1/api.js?render=explicit';
      s.async = true;
      s.defer = true;
      document.head.appendChild(s);
    }
  }

  function handleInteraction(e) {
    const form = e.target.closest('.nexley-forms[data-captcha-type]');
    if (!form) return;

    const type = form.dataset.captchaType;
    const key = form.dataset.captchaKey;
    if (type && type !== 'none' && key) {
      loadCaptchaScript(type, key);
      document.removeEventListener('focusin', handleInteraction);
      document.removeEventListener('pointerdown', handleInteraction);
    }
  }

  document.addEventListener('focusin', handleInteraction, { passive: true });
  document.addEventListener('pointerdown', handleInteraction, { passive: true });
})();
