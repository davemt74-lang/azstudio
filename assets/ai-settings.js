const PROVIDERS = ['openai', 'anthropic'];
const NAMES = {openai: 'OpenAI', anthropic: 'Claude'};
const escape = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch]));

export async function aiSettingsApi(action, payload = null, query = {}, csrf = '') {
  const url = new URL('ai-settings-api.php', location.href);
  url.searchParams.set('action', action);
  for (const [name, value] of Object.entries(query)) url.searchParams.set(name, value);
  const options = {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}};
  if (payload !== null) {
    options.method = 'POST';
    options.headers['Content-Type'] = 'application/json';
    options.headers['X-CSRF-Token'] = csrf;
    options.body = JSON.stringify(payload);
  }
  const response = await fetch(url, options);
  let result;
  try { result = await response.json(); }
  catch { throw Object.assign(new Error(`The server could not complete this settings request (${response.status}). Reload saved settings and try again.`), {status: response.status}); }
  if (!response.ok || result.ok === false) {
    throw Object.assign(new Error(result.error?.message || 'The AI settings request could not be completed.'), {status: response.status, code: result.error?.code});
  }
  return result.data ?? result;
}

export function mountAISettings(container, {csrf = container.dataset?.aiCsrf || '', api = aiSettingsApi, lifecycle = globalThis.window} = {}) {
  const form = container.querySelector('#ai-settings-form');
  const message = container.querySelector('#ai-settings-message');
  const loading = container.querySelector('#ai-settings-loading');
  const recovery = container.querySelector('#ai-settings-recovery');
  const save = container.querySelector('#ai-save');
  const saveState = container.querySelector('#ai-save-state');
  const enabledStatus = container.querySelector('#ai-enabled-status');
  const usage = container.querySelector('#ai-usage');
  const reloads = [...container.querySelectorAll('[data-ai-reload]')];
  const abort = new AbortController();
  const on = (target, type, listener) => target.addEventListener(type, listener, {signal: abort.signal});
  const controls = {
    enabled: container.querySelector('#ai-enable'),
    dailyLimit: container.querySelector('#ai-daily-limit'),
    shotProvider: container.querySelector('#ai-shot-provider'),
    editProvider: container.querySelector('#ai-edit-provider'),
    workspace: container.querySelector('#ai-anthropic-workspace')
  };
  const providers = Object.fromEntries(PROVIDERS.map(provider => [provider, {
    key: container.querySelector(`[data-ai-key="${provider}"]`),
    remove: container.querySelector(`[data-ai-remove="${provider}"]`),
    model: container.querySelector(`[data-ai-model="${provider}"]`),
    load: container.querySelector(`[data-ai-load="${provider}"]`),
    status: container.querySelector(`[data-ai-key-state="${provider}"]`),
    connection: container.querySelector(`[data-ai-connection="${provider}"]`),
    help: container.querySelector(`[data-ai-model-help="${provider}"]`)
  }]));
  let settings = null, busy = false, dirty = false, stale = false, destroyed = false;
  const lists = {openai: [], anthropic: []};

  function notify(text = '', kind = '') {
    message.hidden = !text;
    message.textContent = text;
    message.className = `ai-notice${kind ? ' is-' + kind : ''}`;
  }
  function localCredentialChange(provider) {
    const item = providers[provider];
    return !!item.key.value.trim() || item.remove.checked || (provider === 'anthropic' && controls.workspace.value.trim() !== (settings?.providers.anthropic.workspaceId || ''));
  }
  function lock() {
    form.setAttribute('aria-busy', busy ? 'true' : 'false');
    for (const control of form.querySelectorAll('input,select,button')) control.disabled = busy || !settings;
    for (const reload of reloads) {
      reload.disabled = busy;
      reload.textContent = dirty ? 'Discard edits & reload' : 'Reload saved settings';
    }
    for (const provider of PROVIDERS) {
      const item = providers[provider];
      item.load.disabled = busy || stale || !settings?.providers[provider].hasKey || localCredentialChange(provider);
      item.remove.disabled = busy || !settings?.providers[provider].hasKey;
      item.key.disabled = busy || !settings || item.remove.checked;
      item.load.title = localCredentialChange(provider) ? 'Save your key and workspace changes before checking this connection.' : '';
    }
    save.disabled = busy || !settings || !dirty || stale;
    save.textContent = busy ? 'Please wait…' : 'Save AI settings';
    saveState.textContent = busy ? 'Working…' : stale ? 'Reload saved settings to resolve the conflict.' : dirty ? 'You have unsaved changes.' : 'All changes saved.';
  }
  function setConnection(provider, text, kind = '') {
    const item = providers[provider];
    item.connection.textContent = text;
    item.connection.className = `ai-connection-message${kind ? ' is-' + kind : ''}`;
  }
  function renderModels(provider, selected) {
    const seen = new Set();
    const available = lists[provider].filter(model => typeof model?.id === 'string' && model.id && !seen.has(model.id) && seen.add(model.id));
    const hasSelected = available.some(model => model.id === selected);
    const missing = selected && !hasSelected ? `<option value="${escape(selected)}">${escape(selected)} · saved selection</option>` : '';
    providers[provider].model.innerHTML = '<option value="">Choose a model</option>' + missing + available.map(model => `<option value="${escape(model.id)}">${escape(model.name || model.id)}${model.name && model.name !== model.id ? ' · ' + escape(model.id) : ''}</option>`).join('');
    providers[provider].model.value = selected || '';
  }
  function validateSettings(data) {
    if (!data || !Number.isInteger(data.revision) || !data.providers || PROVIDERS.some(provider => !data.providers[provider])) throw new Error('The server returned incomplete AI settings. Reload and try again.');
    return data;
  }
  function applySettings(data) {
    settings = validateSettings(data);
    if (typeof settings.csrf === 'string' && settings.csrf) csrf = settings.csrf;
    controls.enabled.checked = !!settings.enabled;
    controls.dailyLimit.value = String(settings.dailyLimit);
    controls.shotProvider.value = settings.shotProvider;
    controls.editProvider.value = settings.editProvider;
    controls.workspace.value = settings.providers.anthropic.workspaceId || '';
    for (const provider of PROVIDERS) {
      const item = providers[provider], saved = settings.providers[provider];
      // Never put a credential from a response into the DOM, even if a server
      // accidentally adds one. These password inputs contain user input only.
      item.key.value = '';
      item.remove.checked = false;
      item.key.placeholder = saved.hasKey ? 'Saved key — leave blank to keep' : 'Paste your API key';
      item.status.textContent = saved.hasKey ? 'Key saved' : 'No saved key';
      item.status.className = 'ai-key-state' + (saved.hasKey ? ' is-saved' : '');
      renderModels(provider, saved.model);
      const connection = saved.connection || {};
      setConnection(provider, connection.message || (saved.hasKey ? 'Check your saved key to load available models.' : 'Save a key to check this connection.'), ['ok', 'success', 'connected'].includes(connection.status) ? 'success' : ['error', 'failed'].includes(connection.status) ? 'error' : '');
    }
    enabledStatus.textContent = settings.enabled ? 'AI enabled' : 'AI is off';
    enabledStatus.className = 'ai-status-pill' + (settings.enabled ? ' is-enabled' : '');
    const daily = Number(settings.usage?.daily || 0), pending = Number(settings.usage?.pending || 0);
    usage.textContent = `${daily} of ${settings.dailyLimit} generation requests used today${pending ? ` · ${pending} request in progress` : ''}. Connection checks do not use this generation allowance.`;
    dirty = false; stale = false;
    loading.hidden = true; recovery.hidden = true; form.hidden = false;
  }
  function failure(error) {
    if (error.status === 409) {
      stale = true;
      recovery.hidden = false;
      notify('Your saved settings changed while this page was open. Your edits are still here. Reload the saved settings before making another change.', 'error');
    } else {
      notify(error.message || 'The request could not be completed. Please try again.', 'error');
      if (!settings || error.code === 'csrf_invalid') recovery.hidden = false;
    }
    if (error.status === 401 || error.status === 403) for (const provider of PROVIDERS) providers[provider].key.value = '';
  }
  async function reload() {
    if (busy || destroyed) return;
    busy = true; lock(); notify();
    try {
      const data = validateSettings(await api('settings', null, {}, csrf));
      if (destroyed) return;
      for (const provider of PROVIDERS) lists[provider] = [];
      applySettings(data);
    } catch (error) { if (!destroyed) { loading.hidden = true; failure(error); } }
    finally { if (!destroyed) { busy = false; lock(); } }
  }
  function payload() {
    return {revision: settings.revision, enabled: controls.enabled.checked,
      dailyLimit: Number(controls.dailyLimit.value), shotProvider: controls.shotProvider.value, editProvider: controls.editProvider.value,
      providers: Object.fromEntries(PROVIDERS.map(provider => [provider, {
        apiKey: providers[provider].key.value.trim(), removeKey: providers[provider].remove.checked, model: providers[provider].model.value,
        ...(provider === 'anthropic' ? {workspaceId: controls.workspace.value.trim()} : {})
      }]))};
  }
  function validateDraft(body) {
    if (!Number.isInteger(body.dailyLimit) || body.dailyLimit < 1 || body.dailyLimit > 100) throw new Error('Choose a daily generation limit from 1 to 100.');
    if (body.enabled) {
      for (const provider of new Set([body.shotProvider, body.editProvider])) {
        const item = body.providers[provider];
        if (!item || !item.model || item.removeKey || (!item.apiKey && !settings.providers[provider].hasKey)) throw new Error(`Save a ${NAMES[provider] || 'provider'} key and choose its model before enabling that assistant. To remove an active key, turn off AI or choose another configured provider.`);
      }
    }
  }
  on(form, 'submit', async event => {
    event.preventDefault();
    if (busy || destroyed || !settings || stale || !dirty) return;
    let body;
    try { body = payload(); validateDraft(body); }
    catch (error) { failure(error); return; }
    const changedKeys = PROVIDERS.filter(provider => localCredentialChange(provider));
    busy = true; lock(); notify();
    try {
      const data = validateSettings(await api('save', body, {}, csrf));
      if (destroyed) return;
      for (const provider of changedKeys) lists[provider] = [];
      applySettings(data);
      notify('AI settings saved. New generation requests will use these provider choices.', 'success');
    } catch (error) { if (!destroyed) failure(error); }
    finally { if (!destroyed) { busy = false; lock(); } }
  });
  async function loadModels(provider) {
    if (busy || destroyed || stale || !settings || !settings.providers[provider].hasKey || localCredentialChange(provider)) return;
    busy = true; lock(); notify();
    setConnection(provider, 'Checking your saved key and loading models…');
    try {
      const result = await api('models', {provider, revision: settings.revision}, {}, csrf);
      if (destroyed) return;
      if (result.provider !== provider || !Array.isArray(result.models)) throw new Error('The provider returned an incomplete model list. Please try again.');
      if (result.settings && result.settings.revision !== settings.revision) throw Object.assign(new Error('Saved settings changed.'), {status: 409});
      const selected = providers[provider].model.value;
      lists[provider] = result.models;
      renderModels(provider, selected);
      if (result.settings?.providers?.[provider]?.connection) settings.providers[provider].connection = result.settings.providers[provider].connection;
      if (result.settings?.csrf) csrf = result.settings.csrf;
      setConnection(provider, result.message || `${NAMES[provider]} accepted the saved key. ${result.models.length} models loaded.`, 'success');
      providers[provider].help.textContent = result.models.length ? 'Choose a model and save your settings. This check verifies model-list access; generation support is checked when you make a proposal.' : 'The provider returned no usable models for this account. Check account access with the provider.';
    } catch (error) {
      if (!destroyed) { setConnection(provider, error.message || 'The connection could not be checked.', 'error'); failure(error); }
    } finally { if (!destroyed) { busy = false; lock(); } }
  }
  function edited(event) {
    if (busy || destroyed || !settings) return;
    for (const provider of PROVIDERS) {
      const item = providers[provider];
      if (event.target === item.remove && item.remove.checked) item.key.value = '';
      if (event.target === item.key && item.key.value) item.remove.checked = false;
    }
    dirty = true;
    lock();
  }
  on(form, 'input', edited);
  on(form, 'change', edited);
  for (const provider of PROVIDERS) on(providers[provider].load, 'click', () => loadModels(provider));
  for (const button of reloads) on(button, 'click', reload);
  if (lifecycle?.addEventListener) on(lifecycle, 'beforeunload', event => {
    if (!dirty && !busy) return;
    event.preventDefault(); event.returnValue = '';
  });
  const ready = reload();
  return {ready, reload, isBusy: () => busy, isDirty: () => dirty, destroy() {
    destroyed = true; abort.abort();
    for (const provider of PROVIDERS) providers[provider].key.value = '';
  }};
}

if (typeof document !== 'undefined') {
  const app = document.querySelector('#ai-settings-app');
  if (app) mountAISettings(app);
}
