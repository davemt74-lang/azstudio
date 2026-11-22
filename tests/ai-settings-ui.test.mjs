import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = (await readFile(new URL('../assets/ai-settings.js', import.meta.url), 'utf8')).replace(/^export /gm, '');
const plain = value => JSON.parse(JSON.stringify(value));
const clone = value => structuredClone(value);
const initial = {revision: 4, enabled: true, dailyLimit: 20, shotProvider: 'openai', editProvider: 'openai', usage: {daily: 3, pending: 0}, csrf: 'fresh-csrf',
  providers: {openai: {hasKey: true, model: 'gpt-test', connection: {status: 'unchecked'}}, anthropic: {hasKey: false, model: '', workspaceId: '', connection: {status: 'unchecked'}}}};
const failure = (status, code = 'test_failure') => Object.assign(new Error('Controlled request failure.'), {status, code});
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return {promise, resolve, reject}; }

function setup(api, settings = initial) {
  const nodes = new Map(), calls = [];
  function node(selector) {
    if (!nodes.has(selector)) {
      const handlers = new Map();
      nodes.set(selector, {value: '', checked: false, disabled: false, hidden: false, className: '', innerHTML: '', textContent: '', title: '', attributes: {},
        setAttribute(name, value) { this.attributes[name] = value; },
        addEventListener(type, callback, options = {}) { const items = handlers.get(type) || []; items.push({callback, signal: options.signal}); handlers.set(type, items); },
        async dispatch(type, event = {}) { for (const item of handlers.get(type) || []) if (!item.signal?.aborted) await item.callback({preventDefault() {}, target: this, ...event}); }
      });
    }
    return nodes.get(selector);
  }
  const form = node('#ai-settings-form'), reload = node('reload-primary'), recoveryReload = node('reload-recovery');
  const formControls = ['#ai-enable', '#ai-daily-limit', '#ai-shot-provider', '#ai-edit-provider', '#ai-anthropic-workspace', '#ai-save'];
  for (const provider of ['openai', 'anthropic']) for (const type of ['key', 'remove', 'model', 'load']) formControls.push(`[data-ai-${type}="${provider}"]`);
  form.querySelectorAll = () => [...formControls.map(node), reload];
  const container = {dataset: {aiCsrf: 'initial-csrf'}, querySelector: node, querySelectorAll: () => [reload, recoveryReload]};
  const lifecycle = node('window');
  const context = {URL, AbortController};
  vm.runInNewContext(source + '\nglobalThis.mount = mountAISettings;', context);
  const mounted = context.mount(container, {lifecycle, api: async (action, payload, query, csrf) => {
    calls.push({action, payload: payload === null ? null : plain(payload), query: plain(query), csrf});
    if (api) return api(action, payload, query, csrf);
    return clone(settings);
  }});
  return {node, calls, mounted, lifecycle, form,
    input: async (selector, value, check = false) => { const control = node(selector); control[check ? 'checked' : 'value'] = value; await form.dispatch(check ? 'change' : 'input', {target: control}); },
    click: selector => node(selector).dispatch('click'),
    submit: () => form.dispatch('submit'),
    reload: () => reload.dispatch('click')};
}

test('loads only redacted settings and never hydrates password inputs from a response', async () => {
  const data = clone(initial); data.providers.openai.apiKey = 'do-not-render'; data.providers.anthropic.key = 'also-private';
  const ui = setup(null, data); await ui.mounted.ready;
  assert.equal(ui.node('[data-ai-key="openai"]').value, '');
  assert.equal(ui.node('[data-ai-key="anthropic"]').value, '');
  assert.equal(ui.node('[data-ai-key-state="openai"]').textContent, 'Key saved');
  assert.equal(ui.node('[data-ai-load="anthropic"]').disabled, true);
  assert.equal(ui.node('#ai-save').disabled, true);
  assert.match(ui.node('#ai-usage').textContent, /3 of 20/);
  assert.deepEqual(ui.calls.map(call => call.action), ['settings']);
});

test('save sends replacement credentials once, uses refreshed CSRF, and clears password inputs after success', async () => {
  let written;
  const ui = setup(async (action, payload) => {
    if (action === 'settings') return clone(initial);
    written = plain(payload);
    return {...clone(initial), revision: 5, providers: {...clone(initial.providers), anthropic: {hasKey: true, model: '', workspaceId: 'workspace-selected'}}};
  }); await ui.mounted.ready;
  await ui.input('[data-ai-key="anthropic"]', 'a-test-key');
  await ui.input('#ai-anthropic-workspace', 'workspace-selected');
  await ui.submit();
  assert.equal(written.providers.anthropic.apiKey, 'a-test-key');
  assert.equal(written.providers.anthropic.workspaceId, 'workspace-selected');
  assert.equal(written.providers.openai.apiKey, '');
  assert.equal(written.revision, 4);
  assert.equal(ui.calls[1].csrf, 'fresh-csrf');
  assert.equal(ui.node('[data-ai-key="anthropic"]').value, '');
  assert.equal(ui.mounted.isDirty(), false);
  assert.match(ui.node('#ai-settings-message').textContent, /saved/);
});

test('pending saves lock all inputs and failed writes retain the draft without claiming success', async () => {
  const write = deferred();
  const ui = setup(action => action === 'settings' ? clone(initial) : write.promise); await ui.mounted.ready;
  await ui.input('[data-ai-key="openai"]', 'replacement-draft');
  const pending = ui.submit();
  assert.equal(ui.mounted.isBusy(), true);
  assert.ok(ui.form.querySelectorAll().every(control => control.disabled));
  write.reject(failure(500)); await pending;
  assert.equal(ui.node('[data-ai-key="openai"]').value, 'replacement-draft');
  assert.equal(ui.mounted.isDirty(), true);
  assert.equal(ui.node('#ai-save').disabled, false);
  assert.match(ui.node('#ai-settings-message').className, /error/);
});

test('connection checks only use saved credentials and are blocked by local key/workspace changes', async () => {
  const data = clone(initial); data.providers.anthropic.hasKey = true; data.providers.anthropic.model = 'claude-test';
  const ui = setup(async action => action === 'settings' ? data : {provider: 'anthropic', models: [], settings: data}); await ui.mounted.ready;
  await ui.input('[data-ai-key="openai"]', 'unsaved-openai-key');
  await ui.click('[data-ai-load="openai"]');
  assert.equal(ui.calls.length, 1);
  await ui.input('#ai-anthropic-workspace', 'new-unsaved-workspace');
  await ui.click('[data-ai-load="anthropic"]');
  assert.equal(ui.calls.length, 1);
  assert.match(ui.node('[data-ai-load="anthropic"]').title, /Save/);
});

test('model access checks preserve local routing edits and escape provider-supplied option labels', async () => {
  const ui = setup(async action => action === 'settings' ? clone(initial) : {provider: 'openai', ok: true, models: [{id: 'new-test-model', name: '<img src=x onerror=alert(1)>'}], message: 'Model-list access verified.', settings: clone(initial)}); await ui.mounted.ready;
  await ui.input('#ai-daily-limit', '33');
  await ui.click('[data-ai-load="openai"]');
  assert.equal(ui.node('#ai-daily-limit').value, '33');
  assert.equal(ui.mounted.isDirty(), true);
  assert.equal(ui.node('[data-ai-model="openai"]').value, 'gpt-test');
  assert.match(ui.node('[data-ai-model="openai"]').innerHTML, /&lt;img/);
  assert.doesNotMatch(ui.node('[data-ai-model="openai"]').innerHTML, /<img/);
  assert.deepEqual(ui.calls[1].payload, {provider: 'openai', revision: 4});
  assert.equal(ui.node('[data-ai-connection="openai"]').textContent, 'Model-list access verified.');
  assert.match(ui.node('[data-ai-model-help="openai"]').textContent, /generation support is checked/);
});

test('revision conflicts retain edits and block another write until an explicit reload', async () => {
  let loads = 0;
  const ui = setup(async action => {
    if (action === 'settings') return {...clone(initial), revision: ++loads === 1 ? 4 : 5};
    throw failure(409, 'revision_conflict');
  }); await ui.mounted.ready;
  await ui.input('[data-ai-key="openai"]', 'draft-after-conflict');
  await ui.submit();
  assert.equal(ui.node('[data-ai-key="openai"]').value, 'draft-after-conflict');
  assert.equal(ui.node('#ai-save').disabled, true);
  assert.equal(ui.node('#ai-settings-recovery').hidden, false);
  await ui.submit(); assert.equal(ui.calls.length, 2);
  await ui.reload();
  assert.equal(ui.node('[data-ai-key="openai"]').value, '');
  assert.equal(ui.mounted.isDirty(), false);
  await ui.input('#ai-daily-limit', '25');
  await ui.submit();
  assert.equal(ui.calls.at(-1).payload.revision, 5);
});

test('cannot remove an active provider key without disabling or rerouting its assistants', async () => {
  const ui = setup(async (action, payload) => action === 'settings' ? clone(initial) : {...clone(initial), enabled: payload.enabled, revision: 5, providers: {...clone(initial.providers), openai: {hasKey: false, model: 'gpt-test'}}}); await ui.mounted.ready;
  await ui.input('[data-ai-remove="openai"]', true, true);
  await ui.submit();
  assert.equal(ui.calls.length, 1);
  assert.match(ui.node('#ai-settings-message').textContent, /turn off AI/);
  await ui.input('#ai-enable', false, true);
  await ui.submit();
  assert.equal(ui.calls[1].payload.providers.openai.removeKey, true);
  assert.equal(ui.node('[data-ai-key-state="openai"]').textContent, 'No saved key');
});

test('lost access clears entered secrets and initial errors provide a reload path', async () => {
  const ui = setup(async action => { if (action === 'settings') return clone(initial); throw failure(403, 'forbidden'); }); await ui.mounted.ready;
  await ui.input('[data-ai-key="openai"]', 'temporary-secret');
  await ui.submit();
  assert.equal(ui.node('[data-ai-key="openai"]').value, '');
  const broken = setup(async () => { throw failure(503); }); await broken.mounted.ready;
  assert.equal(broken.node('#ai-settings-loading').hidden, true);
  assert.equal(broken.node('#ai-settings-recovery').hidden, false);
  assert.equal(broken.node('reload-primary').disabled, false);
});

test('destroying a view wipes typed keys and ignores a late connection result', async () => {
  const check = deferred();
  const ui = setup(action => action === 'settings' ? clone(initial) : check.promise); await ui.mounted.ready;
  const pending = ui.click('[data-ai-load="openai"]');
  const oldMarkup = ui.node('[data-ai-model="openai"]').innerHTML;
  ui.mounted.destroy();
  check.resolve({provider: 'openai', models: [{id: 'late-model'}], settings: clone(initial)}); await pending;
  assert.equal(ui.node('[data-ai-model="openai"]').innerHTML, oldMarkup);
  const other = setup(); await other.mounted.ready;
  await other.input('[data-ai-key="openai"]', 'draft-secret'); other.mounted.destroy();
  assert.equal(other.node('[data-ai-key="openai"]').value, '');
});

test('a failed reload preserves the draft and unload protection follows unsaved edits', async () => {
  let loads = 0;
  const ui = setup(async () => { if (++loads === 1) return clone(initial); throw failure(503); }); await ui.mounted.ready;
  let prevented = false;
  await ui.lifecycle.dispatch('beforeunload', {preventDefault() { prevented = true; }});
  assert.equal(prevented, false);
  await ui.input('#ai-daily-limit', '44');
  await ui.lifecycle.dispatch('beforeunload', {preventDefault() { prevented = true; }});
  assert.equal(prevented, true);
  await ui.reload();
  assert.equal(ui.node('#ai-daily-limit').value, '44');
  assert.equal(ui.mounted.isDirty(), true);
});
