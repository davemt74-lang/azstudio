/* Progressive enhancement only: every action remains a CSRF-protected POST. */
(() => {
  'use strict';
  document.querySelectorAll('[data-club-audience]').forEach(select => {
    const form = select.closest('form');
    const field = form.querySelector('[data-club-tier-field]');
    const tier = field && field.querySelector('select');
    if (!tier) return;
    const sync = () => {
      const isTier = select.value === 'tier';
      field.hidden = !isTier;
      tier.required = isTier;
      tier.disabled = !isTier;
    };
    select.addEventListener('change', sync);
    sync();
  });
  document.querySelectorAll('form[data-club-form]').forEach(form => {
    let submitting = false;
    const feedback = document.createElement('p');
    feedback.className = 'club-form-message club-wide';
    feedback.setAttribute('role', 'alert');
    feedback.hidden = true;
    form.append(feedback);
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (submitting) return;
      const data = new FormData(form);
      if (event.submitter && event.submitter.name) data.set(event.submitter.name, event.submitter.value);
      const note = form.querySelector('[name="note"]');
      if (data.get('decision') === 'reject' && note && !note.value.trim()) {
        feedback.textContent = 'Add a short note so the creator knows what to change.';
        feedback.hidden = false;
        note.focus();
        return;
      }
      submitting = true;
      feedback.hidden = true;
      form.setAttribute('aria-busy', 'true');
      const buttons = Array.from(form.querySelectorAll('button[type="submit"], button:not([type])'));
      buttons.forEach(button => {button.disabled = true;});
      try {
        // Hidden input name=action shadows the form.action property in browsers.
        const endpoint = new URL(form.getAttribute('action') || '', document.baseURI);
        if (endpoint.origin !== location.origin) throw new Error('The form destination is invalid. Refresh the page.');
        const response = await fetch(endpoint.href, {method: 'POST', body: data, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        const type = response.headers.get('content-type') || '';
        if (!type.includes('application/json')) throw new Error('Your session may have expired. Refresh the page and try again.');
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error?.message || 'Your change could not be saved. Please try again.');
        const target = new URL(result.redirect, location.href);
        if (target.origin !== location.origin) throw new Error('The saved change returned an invalid destination. Refresh the page.');
        location.assign(target.href);
      } catch (error) {
        feedback.textContent = error.message || 'A connection problem prevented saving. Your entries are still here.';
        feedback.hidden = false;
        feedback.tabIndex = -1;
        feedback.focus();
      } finally {
        submitting = false;
        form.removeAttribute('aria-busy');
        buttons.forEach(button => {button.disabled = false;});
      }
    });
  });
})();
