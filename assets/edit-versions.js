const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
export const editRequestToken = () => crypto.randomUUID?.() || `edit-${Date.now()}-${Math.random().toString(16).slice(2)}`;

export async function editVersionRequest(action, payload = null, query = {}, csrf = '') {
  const url = new URL('edit-api.php', location.href); url.searchParams.set('action', action);
  for (const [key, value] of Object.entries(query)) if (value !== null && value !== undefined) url.searchParams.set(key, value);
  const options = { credentials: 'same-origin', cache: 'no-store', headers: {Accept:'application/json'} };
  if (payload !== null) { options.method = 'POST'; options.headers['Content-Type'] = 'application/json'; options.headers['X-CSRF-Token'] = csrf; options.body = JSON.stringify(payload); }
  const response = await fetch(url, options); let result;
  try { result = await response.json(); } catch { throw new Error(`The server returned an unreadable editable-cut response (${response.status}).`); }
  if (!response.ok || result.ok === false) { const error = new Error(result.error?.message || 'The editable cut could not be saved.'); error.status = response.status; error.code = result.error?.code; throw error; }
  return result.data;
}

/** The controller owns edit state. This panel never mutates a timeline before its save succeeds. */
export async function mountEditableVersions(options) {
  const {interview, csrf, getEdit, apply, beforeChange, isBusy, notify} = options;
  if (!interview.production) return null;
  let current = interview.timeline || null, timelines = [], loading = false, requestGeneration = 0, createReceipt = null, nameDirty = false;
  const panel = document.createElement('section'); panel.id = 'editable-versions'; panel.className = 'panel editable-versions-panel';
  panel.innerHTML = `<div class="panel-heading"><div><p class="eyebrow">EDITABLE CUTS</p><h2>Keep every version of the story</h2></div><a class="button button--small" href="director.php?projectId=${encodeURIComponent(interview.production.id)}">Production Director</a></div><p class="field-help">Save named timelines with their own clip order, trims, cards and format. Each saved change keeps a restore point.</p><div class="two-columns"><label class="field">Open an editable cut<select id="editable-cut-list"><option value="">Current production edit</option></select></label><label class="field">Cut name<input id="editable-cut-name" maxlength="200" placeholder="Launch teaser"></label></div><div class="action-group"><button class="button button--primary" type="button" data-edit-version="create">Save as new cut</button><button class="button" type="button" data-edit-version="save">Save cut</button><button class="button button--quiet" type="button" data-edit-version="archive">Archive cut</button><button class="text-button danger" type="button" data-edit-version="delete">Delete draft</button></div><p id="editable-cut-state" class="field-help" role="status"></p><details id="editable-cut-history"><summary>Revision history</summary><div id="editable-cut-history-list"></div></details><div id="editable-cut-renders"></div>`;
  document.querySelector('.editor-layout')?.before(panel);
  const find = selector => panel.querySelector(selector);
  function render() {
    find('#editable-cut-list').innerHTML = '<option value="">Current production edit</option>' + timelines.map(t => `<option value="${escape(t.id)}" ${t.id === current?.id ? 'selected' : ''}>${escape(t.title)}${t.status === 'archived' ? ' · Archived' : ''}</option>`).join('');
    if (!nameDirty) find('#editable-cut-name').value = current?.title || '';
    find('#editable-cut-state').textContent = current ? `${current.title} · Revision ${current.revision}${current.historical ? ` of ${current.latestRevision} · Historical revision: save as a new cut to edit it` : current.status === 'archived' ? ' · Archived: restore it to edit' : ' · Changes save automatically'}` : 'The current production edit can be saved as a named cut.';
    find('[data-edit-version="save"]').disabled = !current || current.historical || current.status !== 'active';
    find('[data-edit-version="archive"]').disabled = !current || current.historical;
    find('[data-edit-version="archive"]').textContent = current?.status === 'archived' ? 'Restore cut' : 'Archive cut';
    find('[data-edit-version="delete"]').disabled = !current || current.historical || !!current.renders?.length;
    find('#editable-cut-history').hidden = !current;
    find('#editable-cut-history-list').innerHTML = (current?.history || []).map(h => `<div class="finished-version"><div><strong>Revision ${h.revision}</strong><p class="field-help">${escape(h.reason)} · ${escape(new Date(h.savedAt).toLocaleString())}</p></div><div class="action-group"><button class="button button--small" type="button" data-view-edit-revision="${h.revision}">View</button><button class="button button--small" type="button" data-restore-edit-revision="${h.revision}" ${Number(h.revision) === Number(current.latestRevision) ? 'disabled' : ''}>Restore</button></div></div>`).join('');
    find('#editable-cut-renders').innerHTML = current?.lastRender ? `<p class="field-help">Latest finished video: <a href="${escape(current.lastRender.url)}">${escape(current.lastRender.title)}</a> · from revision ${current.lastRender.timelineRevision}</p>` : '';
  }
  async function refresh() { const generation = ++requestGeneration; const data = await editVersionRequest('list', null, {projectId:interview.production.id}, csrf); if (generation !== requestGeneration) return; timelines = data.timelines || []; render(); }
  async function select(timeline) { await apply(timeline); current = timeline; nameDirty = false; render(); const url = new URL(location.href); url.searchParams.set('timelineId', timeline.id); url.searchParams.set('timelineRevision', timeline.revision); history.replaceState(null, '', url); }
  async function flushBeforeChange() {
    if (nameDirty) {
      const name = find('#editable-cut-name').value.trim();
      if (current && !current.historical && current.status === 'active') {
        if (!name) throw new Error('Give this editable cut a name before switching.');
        current.title = name; interview.timeline.title = name;
      } else if (!confirm('Discard this unsaved cut name before switching? Use Save as new cut to keep it.')) {
        throw new Error('Your unsaved cut name is still here.');
      }
    }
    await beforeChange(); nameDirty = false;
  }
  async function perform(callback) {
    if (loading || isBusy()) throw new Error('Finish the current save or render before changing editable cuts.');
    loading = true; panel.setAttribute('aria-busy', 'true');
    const editorControls = [...document.querySelectorAll('#editor-view button,#editor-view input,#editor-view select,#editor-view textarea')].filter(el => !panel.contains(el)).map(el => [el,el.disabled]);
    editorControls.forEach(([el]) => { el.disabled = true; });
    panel.querySelectorAll('button,input,select').forEach(el => { el.disabled = true; });
    try { await callback(); }
    finally { loading = false; panel.removeAttribute('aria-busy'); editorControls.forEach(([el,disabled]) => { el.disabled = disabled; }); panel.querySelectorAll('button,input,select').forEach(el => { el.disabled = false; }); render(); options.onSettled?.(); }
  }
  panel.addEventListener('click', event => {
    const button = event.target.closest('button'); if (!button || button.disabled) return;
    const name = find('#editable-cut-name').value.trim();
    perform(async () => {
      const action = button.dataset.editVersion;
      if (action === 'create') {
        if (!name) throw new Error('Give the new editable cut a name.');
        const proposed = {projectId:interview.production.id, title:name, targetDurationSeconds:current?.targetDurationSeconds || 0, edit:structuredClone(getEdit())};
        const signature = JSON.stringify(proposed);
        if (!createReceipt || createReceipt.signature !== signature) createReceipt = {signature, body:{...proposed,requestToken:editRequestToken()}};
        const saved = await editVersionRequest('timeline.create', createReceipt.body, {}, csrf);
        await select(saved); createReceipt = null; await refresh(); notify('New editable cut saved.'); return;
      }
      if (button.dataset.viewEditRevision) {
        await flushBeforeChange(); const saved = await editVersionRequest('timeline', null, {id:current.id,revision:button.dataset.viewEditRevision}, csrf); await select(saved); return;
      }
      if (button.dataset.restoreEditRevision) {
        await flushBeforeChange();
        const saved = await editVersionRequest('timeline.restore', {id:current.id,revision:current.latestRevision,restoreRevision:Number(button.dataset.restoreEditRevision),requestToken:editRequestToken()}, {}, csrf);
        await select(saved); await refresh(); notify('Revision restored as a new saved revision.'); return;
      }
      if (!current) throw new Error('Save or open an editable cut first.');
      if (action === 'save') {
        if (!name) throw new Error('Give this editable cut a name.');
        current.title = name; interview.timeline.title = name; await flushBeforeChange(); await refresh(); notify('Editable cut saved.');
      } else if (action === 'archive') {
        if (current.status === 'active') await flushBeforeChange();
        const saved = await editVersionRequest('timeline.save', {id:current.id,revision:current.latestRevision,status:current.status === 'archived' ? 'active' : 'archived',requestToken:editRequestToken()}, {}, csrf);
        await select(saved); await refresh();
      } else if (action === 'delete') {
        if (!confirm('Delete this editable draft and its saved revisions? The original recordings stay available.')) return;
        await editVersionRequest('timeline.delete', {id:current.id,revision:current.latestRevision,requestToken:editRequestToken()}, {}, csrf);
        const url = new URL(location.href); url.searchParams.delete('timelineId'); url.searchParams.delete('timelineRevision'); location.href = url.href;
      }
    }).catch(error => { notify(error.message, true); });
  });
  find('#editable-cut-name').addEventListener('input', () => { nameDirty = true; });
  find('#editable-cut-list').addEventListener('change', event => {
    const id = event.target.value;
    perform(async () => {
      await flushBeforeChange();
      if (!id) { const url = new URL(location.href); url.searchParams.delete('timelineId'); url.searchParams.delete('timelineRevision'); location.href = url.href; return; }
      const saved = await editVersionRequest('timeline', null, {id}, csrf); await select(saved);
    }).catch(error => { notify(error.message, true); });
  });
  await refresh();
  return {updated(timeline) { current = timeline; if (current) { const index = timelines.findIndex(t => t.id === current.id); if (index >= 0) timelines[index] = current; const url = new URL(location.href); url.searchParams.set('timelineId',current.id); url.searchParams.set('timelineRevision',current.revision); history.replaceState(null,'',url); } render(); }, isBusy:() => loading, isDirty:()=>nameDirty, refresh};
}
