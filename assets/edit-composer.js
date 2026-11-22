const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
export const composerToken = () => globalThis.crypto?.randomUUID?.().replaceAll('-', '') || Array.from(globalThis.crypto.getRandomValues(new Uint8Array(24)), value => value.toString(16).padStart(2,'0')).join('');

export async function composerApi(action, payload = null, query = {}, csrf = '') {
  const url = new URL('edit-composer-api.php', location.href);
  url.searchParams.set('action', action);
  for (const [key, value] of Object.entries(query)) url.searchParams.set(key, String(value));
  const options = {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}};
  if (payload !== null) Object.assign(options, {method:'POST', body:JSON.stringify(payload), headers:{...options.headers,'Content-Type':'application/json','X-CSRF-Token':csrf}});
  const response = await fetch(url, options);
  let result;
  try { result = await response.json(); } catch { throw new Error('The assistant response could not be read. Check the saved request before retrying.'); }
  if (!response.ok || result.ok === false) {
    const error = new Error(result.error?.message || 'The edit assistant could not finish this request.');
    error.code = result.error?.code; error.status = response.status; throw error;
  }
  return result.data;
}

export function composerCanUse(proposal, snapshot) {
  return !!(proposal?.state === 'ready' && !proposal.stale && proposal.edit && snapshot?.fingerprint === proposal.fingerprint && snapshot?.project?.status !== 'archived');
}

export function mountEditComposer(container, {project, csrf = '', user, getDirector = () => null, canRequest = () => true, onProposal = () => {}, api = composerApi, storage = globalThis.sessionStorage} = {}) {
  if (!container || !project?.id) return {destroy(){}, isBusy:()=>false, isDirty:()=>false};
  const abort = new AbortController(); let destroyed = false, busy = false, settings = null, proposal = null;
  const storageKey = `azhottie-edit-assistant:${user?.id || 'admin'}:${project.id}`;
  const readLocal = () => { try { return JSON.parse(storage?.getItem(storageKey) || 'null') || {}; } catch { return {}; } };
  let saved = readLocal(); let pending = saved.pending || null;
  const call = (action, data = null, query = {}) => api(action, data, query, csrf);
  container.innerHTML = `<details class="director-assistant" open><summary>Optional AI edit proposal</summary>
    <p class="field-help">Suggest an order using your approved clip labels, review notes and usable sections. The assistant receives this production’s brief and editorial text; it does not receive video, audio, reference files or account records.</p>
    <p class="field-help"><a href="admin.php">Manage OpenAI and Claude in Admin → AI settings</a>. Shot lists and edit proposals share your daily request limit.</p>
    <p data-composer-status class="field-help" role="status">Loading assistant settings…</p>
    <div data-composer-message class="work-inline-notice" role="status" hidden></div>
    <form data-composer-form class="work-form"><div class="work-form-grid">
      <label class="field">Target duration<select name="preset"><option value="30">30-second teaser</option><option value="60" selected>60-second feature</option><option value="0">Full selection</option><option value="custom">Custom duration</option></select></label>
      <label class="field" data-custom-field hidden>Target seconds<input name="seconds" type="number" min="1" max="3600" step="1" value="60"></label>
      <label class="field field--wide">Editorial direction<textarea name="direction" rows="3" maxlength="2000" placeholder="For example: open with the introduction, show the product detail, then finish with a reaction."></textarea></label>
    </div><div class="work-actions"><button type="submit" class="button button--primary" data-generate disabled>Propose a first cut</button><button type="button" class="button button--quiet" data-check>Check saved proposal</button></div></form>
    <div data-composer-result></div></details>`;
  const $ = selector => container.querySelector(selector);
  const form = $('[data-composer-form]');
  const presetFor = target => [0,30,60].includes(Number(target)) ? String(target) : 'custom';
  const initial = pending || saved;
  form.elements.preset.value = presetFor(initial.targetDurationSeconds ?? 60);
  form.elements.seconds.value = String(initial.targetDurationSeconds || 60);
  form.elements.direction.value = initial.direction || '';
  function directions() { return {targetDurationSeconds:form.elements.preset.value === 'custom' ? Number(form.elements.seconds.value) : Number(form.elements.preset.value), direction:form.elements.direction.value}; }
  function remember() { try { storage?.setItem(storageKey, JSON.stringify({...directions(), pending})); } catch {} }
  function message(text, error = false) { if (destroyed) return; const element = $('[data-composer-message]'); element.hidden = !text; element.textContent = text; element.setAttribute('role', error ? 'alert' : 'status'); }
  function sync() {
    if (destroyed) return;
    container.setAttribute('aria-busy', String(busy));
    $('[data-custom-field]').hidden = form.elements.preset.value !== 'custom';
    for (const input of form.querySelectorAll('input,select,textarea')) input.disabled = busy || !!pending;
    $('[data-check]').disabled = busy;
    $('[data-generate]').disabled = busy || !settings?.enabled || !settings?.configured || getDirector()?.project?.status === 'archived';
    $('[data-generate]').textContent = pending ? 'Retry same request' : 'Propose a first cut';
    const use = $('[data-use-proposal]'); if (use) use.disabled = busy || !composerCanUse(proposal, getDirector());
    $('[data-composer-status]').textContent = settings ? settings.enabled && settings.configured ? `${settings.provider === 'anthropic' ? 'Claude' : 'OpenAI'} · ${settings.model} · ${settings.usage?.daily ?? 0} of ${settings.dailyLimit} shared requests used today. Proposals remain editable before saving a cut.` : 'Optional assistant is off. The manual first-cut builder is available above.' : 'Assistant settings are unavailable. Use Check saved proposal to retry.';
  }
  function render() {
    if (destroyed) return;
    const target = $('[data-composer-result]');
    if (!proposal) { target.innerHTML = ''; sync(); return; }
    if (proposal.state === 'pending') target.innerHTML = '<p class="work-inline-notice">The assistant is preparing this proposal. Check the saved request to recover its result after an interruption.</p>';
    else if (proposal.state === 'failed') target.innerHTML = `<p class="work-inline-notice" role="alert">${escapeHtml(proposal.error || 'No proposal was created. You can revise the direction and try again.')}</p>`;
    else if (proposal.state === 'applied') target.innerHTML = `<p class="work-inline-notice">This proposal has been saved as an editable cut.</p><a class="button" href="editor.php?id=${encodeURIComponent(project.interviewId || '')}&amp;timelineId=${encodeURIComponent(proposal.appliedTimelineId || '')}&amp;timelineRevision=${Number(proposal.appliedTimelineRevision) || 1}">Open saved cut</a>`;
    else target.innerHTML = `<div class="work-inline-notice"><strong>Saved edit proposal</strong><p>${escapeHtml(proposal.provider === 'anthropic' ? 'Claude' : 'OpenAI')} · ${escapeHtml(proposal.model || 'saved proposal')}</p><p>${escapeHtml(proposal.summary)}</p><p>${proposal.items?.length || 0} sections · ${(Number(proposal.durationMs || 0) / 1000).toFixed(1)} seconds including cards.</p>${!composerCanUse(proposal,getDirector()) ? '<p>Coverage or footage has changed. Refresh the production and generate a new proposal before using this draft.</p>' : ''}<button type="button" class="button" data-use-proposal>Review in the first-cut builder</button></div>`;
    sync();
  }
  function acceptReceipt(receipt) {
    proposal = receipt;
    if (proposal && proposal.state !== 'pending') pending = null;
    remember(); render();
  }
  async function refresh() {
    if (busy || destroyed) return;
    busy = true; sync();
    try {
      settings = await call('settings');
      const receipt = pending ? await call('request', null, {projectId:project.id,requestToken:pending.requestToken}) : await call('latest', null, {projectId:project.id});
      if (destroyed) return; acceptReceipt(receipt); message(receipt ? 'Saved proposal checked.' : 'No edit proposal has been generated yet.');
    } catch (error) { if (!destroyed) message(error.message, true); }
    finally { busy = false; sync(); }
  }
  container.addEventListener('input', () => { sync(); remember(); }, {signal:abort.signal});
  container.addEventListener('change', () => { sync(); remember(); }, {signal:abort.signal});
  container.addEventListener('click', async event => {
    if (event.target.closest('[data-check]')) { await refresh(); return; }
    if (!event.target.closest('[data-use-proposal]') || busy) return;
    if (!composerCanUse(proposal,getDirector())) { render(); message('This proposal is stale. Generate a proposal from the current production.',true); return; }
    busy = true; sync();
    try { const accepted = await onProposal(structuredClone(proposal)); if (!destroyed && accepted !== false) message('Proposal loaded into the first-cut builder. Review it and save a named draft.'); }
    catch (error) { message(error.message,true); }
    finally { busy = false; sync(); }
  }, {signal:abort.signal});
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (busy || destroyed) return;
    if (!pending && !canRequest()) { message('Save your coverage requirement or usable-section changes before requesting a proposal.',true); return; }
    const request = pending || {...directions(),requestToken:composerToken()};
    if (!Number.isInteger(request.targetDurationSeconds) || request.targetDurationSeconds < 0 || request.targetDurationSeconds > 3600) { message('Choose a target from 1 to 3600 seconds, or Full selection.',true); return; }
    pending = request; remember(); busy = true; sync(); message('Drafting an edit proposal… Your existing cuts remain available.');
    try {
      const result = await call('generate',{projectId:project.id,...request});
      if (destroyed) return; acceptReceipt(result);
      try { settings = await call('settings'); } catch {}
      message(result.state === 'ready' ? 'Proposal saved. Review it in the first-cut builder.' : result.state === 'failed' ? result.error : 'The request is still processing. Check its saved result.',result.state === 'failed');
    } catch (error) {
      if (destroyed) return;
      // A definite rejected request did not start a new provider job.
      if (error.status === 400 || ['ai_disabled','ai_daily_limit','ai_busy','ai_no_footage','ai_input_limit','project_closed','project_archived','invalid_request_token','request_conflict','target_too_short','coverage_required','usable_footage_required'].includes(error.code)) pending = null;
      remember(); message(error.message + (pending ? ' Use Check saved proposal or Retry same request to recover it.' : ''),true);
    } finally { busy = false; sync(); }
  }, {signal:abort.signal});
  refresh();
  return {destroy(){remember();destroyed = true;abort.abort();},isBusy:()=>busy,isDirty:()=>!!pending,refresh};
}
