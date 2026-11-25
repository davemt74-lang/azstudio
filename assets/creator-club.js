/* Progressive enhancement only: every saved action remains a CSRF-protected POST. */
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

  document.querySelectorAll('form[data-club-editor]').forEach(form => {
    const preview = form.querySelector('[data-club-preview]');
    const opener = form.querySelector('[data-club-preview-open]');
    const close = form.querySelector('[data-club-preview-close]');
    const video = preview.querySelector('[data-preview-video]');
    const mediaError = preview.querySelector('[data-preview-media-error]');
    const stopVideo = () => {
      video.pause();
      video.removeAttribute('src');
      video.hidden = true;
      video.load();
    };
    video.addEventListener('error', () => {
      if (!preview.hidden && video.hasAttribute('src')) mediaError.hidden = false;
    });
    opener.addEventListener('click', () => {
      const fields = new FormData(form);
      // Creator-entered text is always rendered as text, never as HTML.
      preview.querySelector('[data-preview-title]').textContent = String(fields.get('title') || '').trim() || 'Untitled draft';
      preview.querySelector('[data-preview-body]').textContent = String(fields.get('body') || '').trim() || 'Your update will appear here.';
      const tier = form.querySelector('[name="tier_id"]');
      const tierName = tier.selectedOptions[0]?.textContent || 'Choose a tier';
      preview.querySelector('[data-preview-audience]').textContent = fields.get('audience') === 'tier' ? `Audience: ${tier.value ? tierName : 'Choose an approved membership tier'}` : 'Audience: Free followers';
      const local = String(fields.get('publishLocal') || '');
      const timezone = String(fields.get('publishTimezone') || 'America/Phoenix');
      preview.querySelector('[data-preview-schedule]').textContent = local ? `Requested release: ${local.replace('T', ' at ')} · ${timezone}. Staff approval is required.` : 'Release after staff approval. No scheduled time selected.';
      stopVideo();
      mediaError.hidden = true;
      const media = form.querySelector('[data-club-media]');
      const selected = media.selectedOptions[0];
      if (selected?.dataset.previewUrl && /^[a-f0-9]{32}$/i.test(media.value)) {
        const url = new URL(selected.dataset.previewUrl, document.baseURI);
        if (url.origin === location.origin && url.pathname.endsWith('/personal-media-file.php') && url.searchParams.get('id') === media.value) {
          video.src = url.href;
          video.hidden = false;
        }
      }
      preview.hidden = false;
      preview.focus({preventScroll: true});
      preview.scrollIntoView({block: 'nearest', behavior: 'auto'});
    });
    close.addEventListener('click', () => {
      stopVideo();
      preview.hidden = true;
      opener.focus();
    });
  });

  document.querySelectorAll('form[data-club-form]').forEach(form => {
    let submitting = false;
    const feedback = document.createElement('p');
    feedback.className = 'club-form-message club-wide';
    feedback.setAttribute('role', 'alert');
    feedback.hidden = true;
    form.append(feedback);
    const showError = message => {
      feedback.textContent = message;
      feedback.hidden = false;
      feedback.tabIndex = -1;
      feedback.focus();
    };
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (submitting) return;
      const data = new FormData(form);
      // FormData(form) alone omits the clicked Save/Submit/Approve button.
      if (event.submitter?.name) data.set(event.submitter.name, event.submitter.value);
      if (form.hasAttribute('data-club-editor')) {
        const intent = event.submitter?.value === 'draft' ? 'draft' : 'submit';
        data.set('postIntent', intent);
        if (intent === 'submit' && !String(data.get('body') || '').trim() && !data.get('media_id')) {
          showError('Add an update or choose a private video before submitting. Your entries are still here.');
          return;
        }
      }
      const note = form.querySelector('[name="note"]');
      if (data.get('decision') === 'reject' && note && !note.value.trim()) {
        showError('Add a short note so the creator knows what to change.');
        note.focus();
        return;
      }
      submitting = true;
      feedback.hidden = true;
      form.setAttribute('aria-busy', 'true');
      const buttons = Array.from(form.querySelectorAll('button[type="submit"], button:not([type])'));
      const buttonStates = buttons.map(button => button.disabled);
      buttons.forEach(button => {button.disabled = true;});
      try {
        // Hidden input name=action shadows the form.action property in browsers.
        const endpoint = new URL(form.getAttribute('action') || '', document.baseURI);
        if (endpoint.origin !== location.origin) throw new Error('The form destination is invalid. Refresh the page.');
        const response = await fetch(endpoint.href, {method: 'POST', body: data, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        const type = response.headers.get('content-type') || '';
        if (!type.includes('application/json')) throw new Error('Your session may have expired. Your entries are still here; sign in again in another tab and retry.');
        const result = await response.json();
        if (!response.ok || !result.ok) {
          const message = result.error?.message || 'Your change could not be saved. Please try again.';
          throw new Error(response.status === 409 ? `${message} Your entries are still here. Open the latest version in another tab before trying again.` : message);
        }
        if (typeof result.redirect !== 'string' || !result.redirect) throw new Error('The change was saved, but the destination was missing. Reload to check its status.');
        const target = new URL(result.redirect, location.href);
        if (target.origin !== location.origin) throw new Error('The saved change returned an invalid destination. Refresh the page.');
        location.assign(target.href);
      } catch (error) {
        showError(error.message || 'A connection problem prevented saving. Your entries are still here.');
      } finally {
        submitting = false;
        form.removeAttribute('aria-busy');
        buttons.forEach((button, index) => {button.disabled = buttonStates[index];});
      }
    });
  });
})();
