import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = (await readFile(new URL('../assets/work.js', import.meta.url), 'utf8'))
  .replace(/^import .*;\n/gm, '').replace(/start\(\)\.catch\(fail\);\s*$/, '');
const plain = value => JSON.parse(JSON.stringify(value));
const decode = value => value.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return {promise, resolve, reject}; }
function member(userId, extra = {}) { return {userId, name: 'Model ' + userId, roles: ['model'], accountStatus: 'active', eligible: true, assignmentCount: 0, activeAssignmentCount: 0, ...extra}; }
function candidate(id, extra = {}) { return {id, name: 'Model ' + id, roles: ['model'], ...extra}; }
function roster(extra = {}) { return {id: 'p1', projectId: 'p1', revision: 7, members: [], candidates: [], canManage: true, canEdit: true, maxMembers: 200, ...extra}; }
function project(extra = {}) { return {id: 'p1', title: 'Desert collection', brief: 'A shared creative brief.', revision: 41, status: 'active', kind: 'production', canManage: true, shots: [], references: [], videos: [], activity: [], shotCount: 0, roster: roster(), ...extra}; }
function shot(extra = {}) { return {id: 's1', projectId: 'p1', title: 'Collection introduction', brief: 'Introduce the collection.', status: 'active', mode: 'open', eligibleRoles: ['model'], slotsRemaining: 2, maxContributors: 2, contributorCount: 0, assignments: [], references: [], canManage: true, project: project(), roster: roster(), ...extra}; }

function setup(options = {}) {
  const elements = new Map(), calls = [], listeners = {}, extraForms = [];
  let rosterControls = [], checkboxes = [], optionsNodes = [], rosterForm = null, apiHandler = options.api;
  const makeElement = (id = '', extra = {}) => ({id, dataset: {}, value: '', defaultValue: '', innerHTML: '', textContent: '', hidden: false, disabled: false, className: '', isConnected: true, tagName: 'DIV', scrollIntoView() {}, classList: {remove() {}, add() {}, toggle() {}}, setAttribute(name, value) { this[name] = value; }, querySelector() { return null; }, querySelectorAll() { return []; }, addEventListener() {}, ...extra});
  const element = id => { if (!elements.has(id)) elements.set(id, makeElement(id)); return elements.get(id); };
  const register = (id, extra = {}) => { const node = makeElement(id, extra); elements.set(id, node); return node; };
  const detail = element('work-detail'), panel = element('work-roster');
  const forms = () => [...(rosterForm ? [rosterForm] : []), ...extraForms];
  detail.querySelectorAll = selector => selector === '[data-work-form]' ? forms() : [];

  function renderRoster(html) {
    for (const node of rosterControls) node.isConnected = false;
    if (rosterForm) rosterForm.isConnected = false;
    rosterControls = []; checkboxes = []; optionsNodes = []; rosterForm = null;
    const formMatch = html.match(/<form\b[^>]*data-work-form="roster-add"[^>]*>/);
    const reload = register('test-reload', {tagName: 'BUTTON', dataset: {action: 'roster-reload', id: 'p1'}});
    if (html.includes('data-action="roster-reload"')) rosterControls.push(reload);
    const removes = [...html.matchAll(/data-action="roster-remove" data-id="([^"]+)"/g)].map(match => makeElement('', {tagName: 'BUTTON', dataset: {action: 'roster-remove', id: match[1]}}));
    rosterControls.push(...removes);
    register('work-roster-notice', {hidden: true});
    register('work-roster-search', {tagName: 'INPUT', type: 'search'});
    register('work-roster-match-count'); register('work-roster-selected');
    const add = register('work-roster-add', {tagName: 'BUTTON', type: 'submit', disabled: true});
    const more = register('work-roster-more', {tagName: 'BUTTON', dataset: {action: 'roster-more', id: 'p1'}});
    if (!formMatch) return;
    const status = makeElement();
    for (const match of html.matchAll(/<label\b[^>]*data-roster-option[^>]*>[\s\S]*?<\/label>/g)) {
      const markup = match[0], value = markup.match(/name="memberIds" value="(\d+)"/)[1];
      const input = makeElement('', {tagName: 'INPUT', type: 'checkbox', name: 'memberIds', value, defaultChecked: false, checked: false});
      checkboxes.push(input);
      optionsNodes.push(makeElement('', {dataset: {search: decode(markup.match(/data-search="([^"]*)"/)[1])}, hidden: /\shidden>/.test(markup)}));
    }
    rosterControls.push(element('work-roster-search'), ...checkboxes, add, more);
    rosterForm = makeElement('', {tagName: 'FORM', dataset: {workForm: 'roster-add', projectId: 'p1'}, controls: checkboxes,
      querySelectorAll(selector) {
        if (selector === '[name=memberIds]:checked') return checkboxes.filter(input => input.checked);
        if (selector === 'input[name],textarea[name],select[name]') return checkboxes;
        if (selector === 'button,input[type=submit]') return [add, more];
        return [];
      }, querySelector(selector) { return selector === '[data-form-status]' ? status : null; }
    });
    for (const input of checkboxes) input.closest = () => rosterForm;
  }
  let panelHtml = '', detailHtml = '';
  Object.defineProperty(panel, 'innerHTML', {get: () => panelHtml, set(html) { panelHtml = html; renderRoster(html); }});
  Object.defineProperty(detail, 'innerHTML', {get: () => detailHtml, set(html) { detailHtml = html; if (html.includes('id="work-roster"')) panel.innerHTML = html; }});
  panel.querySelectorAll = selector => selector === 'input,button' ? rosterControls : selector === '[data-roster-option]' ? optionsNodes : selector === '[name=memberIds]:checked' ? checkboxes.filter(input => input.checked) : [];
  class FixtureFormData {
    constructor(form) { this.entries = (form?.controls || []).filter(control => !control.disabled && (control.type !== 'checkbox' || control.checked)).map(control => [control.name, control.value]); }
    [Symbol.iterator]() { return this.entries[Symbol.iterator](); }
  }
  const location = {href: 'https://studio.example.test/' + (options.page || 'productions') + '.php', pathname: '/' + (options.page || 'productions') + '.php', search: '', origin: 'https://studio.example.test'};
  const api = async (name, payload = null, query = {}) => { calls.push({name, payload, query}); if (apiHandler) { const value = await apiHandler(name, payload, query); if (value !== undefined) return value; } return name === 'notifications' ? {notifications: [], unreadCount: 0} : name === 'myWork' ? {projects: [], assigned: [], open: [], submitted: [], completed: []} : []; };
  const plannerStats = {mounted: 0, destroyed: 0};
  const context = {console, Intl, Date, Map, Set, URL, URLSearchParams, FormData: FixtureFormData, structuredClone, setTimeout: () => 1, clearTimeout() {}, api, mediaApi: api, setWorkCsrf() {}, workPage: page => '/' + page + '.php',
    mountWorkPlanner() { plannerStats.mounted++; return {destroy() { plannerStats.destroyed++; }, isBusy: () => false, isDirty: () => false}; },
    mountWorkCapture() { return {destroy() {}, isBusy: () => false, isDirty: () => false}; }, location,
    history: {pushState(a, b, url) { const next = new URL(url, location.href); Object.assign(location, {href: next.href, pathname: next.pathname, search: next.search}); }, replaceState(a, b, url) { this.pushState(a, b, url); }},
    window: {addEventListener() {}}, document: {body: {dataset: {workPage: options.page || 'productions'}}, getElementById: element, addEventListener(name, handler) { listeners[name] = handler; }, querySelectorAll() { return []; }, querySelector() { return null; }}, confirm: options.confirm || (() => true)};
  vm.runInNewContext(source + '\nglobalThis.ui={state,rosterMarkup,rosterEditable,filterRosterPicker,updateRoster,assignmentCandidates,openProject,openShot,shotCard,renderList,loadList,chooseTab,route,action,submit,write};', context);
  const ui = context.ui;
  ui.state.boot = {user: {id: 1, name: 'Producer', roles: ['admin']}, canManage: true, links: {events: [], appointments: []}, users: [candidate(999, {name: 'Outside this production'})]};
  element('work-project-status').value = 'active';
  return {...ui, element, calls, location, plannerStats, listeners,
    setApi(handler) { apiHandler = handler; },
    mount(data = project()) { ui.state.detail = {kind: 'project', data}; panel.innerHTML = ui.rosterMarkup(data); ui.filterRosterPicker(); },
    checkbox(id) { return checkboxes.find(input => input.value === String(id)); },
    form() { return rosterForm; }, controls() { return rosterControls; }, options() { return optionsNodes; },
    addDraft(value = '') { const input = makeElement('', {tagName: 'INPUT', type: 'text', name: 'title', value, defaultValue: ''}); const form = makeElement('', {tagName: 'FORM', dataset: {workForm: 'reference', projectId: 'p1'}, querySelectorAll: () => [input]}); extraForms.push(form); return input; }
  };
}

test('roster shows account status and escaped display names without private profile fields', () => {
  const ui = setup(), html = ui.rosterMarkup(project({roster: roster({members: [member(2, {name: '<img onerror="unsafe">', email: 'private@example.test', accountStatus: 'suspended', eligible: false, activeAssignmentCount: 2})], candidates: [candidate(3, {name: 'A & B', email: 'another@example.test'})]})}));
  assert.match(html, /Add models to this production/);
  assert.match(html, /&lt;img onerror=&quot;unsafe&quot;&gt;/);
  assert.match(html, /A &amp; B/);
  assert.match(html, /Account inactive/);
  assert.match(html, /2 current assignments/);
  assert.doesNotMatch(html, /<img|private@example|another@example/);
  const empty = ui.rosterMarkup(project());
  assert.match(empty, /Create or approve model accounts in Accounts/);
  assert.match(empty, /href="\/accounts.php"/);
  assert.match(empty, /unlinked casting profile does not receive assignments/);
});

test('full and read-only rosters always have a reload control and respect canEdit', () => {
  const ui = setup();
  const full = ui.rosterMarkup(project({roster: roster({maxMembers: 1, members: [member(2)]})}));
  assert.match(full, /data-action="roster-reload"/);
  assert.match(full, /1-member limit/);
  assert.doesNotMatch(full, /data-work-form="roster-add"/);
  const locked = ui.rosterMarkup(project({roster: roster({canEdit: false, members: [member(2)], candidates: [candidate(3)]})}));
  assert.match(locked, /data-action="roster-reload"/);
  assert.match(locked, /Existing assignments and footage remain available/);
  assert.doesNotMatch(locked, /data-action="roster-remove"|data-work-form="roster-add"/);
});

test('searching and showing more models preserves choices outside the visible results', () => {
  const ui = setup(); ui.mount(project({roster: roster({candidates: Array.from({length: 15}, (_, index) => candidate(index + 2))})}));
  assert.equal(ui.options().filter(item => !item.hidden).length, 12);
  assert.equal(ui.element('work-roster-more').hidden, false);
  ui.checkbox(16).checked = true;
  ui.element('work-roster-search').value = 'model 2'; ui.filterRosterPicker();
  assert.equal(ui.options().filter(item => !item.hidden).length, 1);
  assert.equal(ui.checkbox(16).checked, true);
  assert.equal(ui.element('work-roster-selected').textContent, '1 selected');
  assert.equal(ui.element('work-roster-add').disabled, false);
  assert.match(ui.element('work-roster-match-count').textContent, /Showing 1 of 1 matching account/);
  ui.element('work-roster-search').value = ''; ui.state.rosterLimit = 24; ui.filterRosterPicker();
  assert.equal(ui.options().filter(item => !item.hidden).length, 15);
  assert.equal(ui.element('work-roster-more').hidden, true);
});

test('direct assignment offers only eligible included models and the producer exception', () => {
  const ui = setup(), data = shot({roster: roster({members: [member(2), member(3, {accountStatus: 'suspended'}), member(4, {roles: ['professional']}), member(5), member(6), member(7), member(8, {eligible: false})]}), assignments: [{userId: 5, status: 'accepted'}, {userId: 6, status: 'declined'}, {userId: 7, status: 'cancelled'}]});
  assert.deepEqual(plain(ui.assignmentCandidates(data).map(item => item.id)), [2, 6, 7, 1]);
  assert.equal(ui.assignmentCandidates(data).at(-1).studio, true);
  data.slotsRemaining = 0;
  assert.deepEqual(plain(ui.assignmentCandidates(data).map(item => item.id)), [1]);
  data.assignments.push({userId: 1, status: 'accepted'});
  assert.equal(ui.assignmentCandidates(data).length, 0);
});

test('draft productions permit named assignments and provide a link back to the roster', async () => {
  const data = shot({project: project({status: 'draft'}), roster: roster({members: [member(2)]})});
  const ui = setup({api: async name => name === 'shot' ? data : undefined});
  await ui.openShot('s1');
  const html = ui.element('work-detail').innerHTML;
  assert.match(html, /data-work-form="assign"/);
  assert.match(html, /value="2">Model 2/);
  assert.match(html, /Studio · me/);
  assert.match(html, /data-action="roster-project"/);
  assert.match(html, /Assignments can be prepared while the production is a draft/);
  assert.doesNotMatch(html, /Outside this production/);
});

test('historical private work omits undisclosed capacity from cards and details', async () => {
  const data = shot({canManage: false, slotsRemaining: null, contributorCount: null, assignmentCount: null});
  const ui = setup({page: 'my-work', api: async name => name === 'shot' ? data : undefined});
  assert.doesNotMatch(ui.shotCard(data), /null|places? available/);
  await ui.openShot('s1');
  assert.doesNotMatch(ui.element('work-detail').innerHTML, /null|places? available/);
});

test('adding members sends the roster revision once and preserves live planner and form edits', async () => {
  const pending = deferred(), original = project({roster: roster({candidates: [candidate(2), candidate(3)]})});
  const ui = setup({api: async name => name === 'project' ? original : name === 'addMembers' ? pending.promise : undefined});
  await ui.openProject('p1');
  const planner = ui.state.planner, form = ui.form(), oldDraft = ui.addDraft('Earlier reference title'), newDraft = ui.addDraft();
  // Give the second reference form a distinct key, just as two shot-reference forms have.
  ui.element('work-detail').querySelectorAll('[data-work-form]').at(-1).dataset.shotId = 's2';
  ui.checkbox(2).checked = true; ui.state.formDirty = true; ui.filterRosterPicker();
  const first = ui.submit(form);
  assert.equal(ui.state.mutationBusy, true);
  assert.ok(ui.controls().every(control => control.disabled));
  await ui.submit(form);
  oldDraft.value = 'Edited while the roster saves'; newDraft.value = 'Started while the roster saves';
  pending.resolve(roster({revision: 8, members: [member(2)], candidates: [candidate(3)]}));
  await first;
  const writes = ui.calls.filter(call => call.payload);
  assert.deepEqual(plain(writes), [{name: 'addMembers', payload: {projectId: 'p1', revision: 7, userIds: [2]}, query: {}}]);
  assert.equal(ui.state.detail.data.revision, 41);
  assert.equal(ui.state.detail.data.roster.revision, 8);
  assert.equal(ui.state.planner, planner);
  assert.equal(ui.plannerStats.mounted, 1); assert.equal(ui.plannerStats.destroyed, 0);
  assert.equal(oldDraft.value, 'Edited while the roster saves');
  assert.equal(newDraft.value, 'Started while the roster saves');
  assert.equal(ui.state.formDirty, true);
  assert.equal(ui.state.mutationBusy, false);
  assert.equal(ui.element('work-roster-search').disabled, false);
});

test('a failed roster save leaves choices, search, and unsaved state intact for retry', async () => {
  const ui = setup({api: async name => { if (name === 'addMembers') throw new Error('Connection interrupted'); }});
  ui.mount(project({roster: roster({candidates: [candidate(2)]})}));
  const form = ui.form(); ui.checkbox(2).checked = true; ui.state.formDirty = true;
  ui.element('work-roster-search').value = 'model'; ui.filterRosterPicker();
  await assert.rejects(ui.submit(form), /Connection interrupted/);
  assert.equal(ui.checkbox(2).checked, true);
  assert.equal(ui.element('work-roster-search').value, 'model');
  assert.equal(ui.element('work-roster-add').disabled, false);
  assert.equal(ui.state.formDirty, true);
  assert.equal(ui.state.rosterStale, false);
  assert.equal(ui.state.mutationBusy, false);
  assert.equal(form.querySelector('[data-form-status]').textContent, 'Not saved. Your entries are still here.');
});

test('conflicting roster writes block retry until reload and retain only remaining selections', async () => {
  const ui = setup({api: async name => {
    if (name === 'addMembers') throw Object.assign(new Error('Roster changed'), {code: 'revision_conflict'});
    if (name === 'roster') return roster({revision: 9, members: [member(2), member(7)], candidates: [candidate(4), candidate(6)]});
  }});
  ui.mount(project({roster: roster({members: [member(7)], candidates: [candidate(2), candidate(4), candidate(6)]})}));
  ui.checkbox(2).checked = true; ui.checkbox(4).checked = true; ui.state.formDirty = true;
  await assert.rejects(ui.submit(ui.form()), /Roster changed/);
  assert.equal(ui.state.rosterStale, true);
  assert.equal(ui.checkbox(2).checked, true);
  assert.equal(ui.element('work-roster-add').disabled, true);
  assert.ok(ui.controls().filter(control => control.dataset.action === 'roster-remove').every(control => control.disabled));
  assert.equal(ui.element('work-roster-notice').hidden, false);
  await ui.submit(ui.form());
  assert.equal(ui.calls.filter(call => call.name === 'addMembers').length, 1);
  await ui.action(ui.element('test-reload'));
  assert.equal(ui.state.rosterStale, false);
  assert.equal(ui.state.detail.data.roster.revision, 9);
  assert.equal(ui.checkbox(2), undefined);
  assert.equal(ui.checkbox(4).checked, true);
  assert.equal(ui.checkbox(6).checked, false);
  assert.equal(ui.element('work-roster-selected').textContent, '1 selected');
  assert.equal(ui.element('work-roster-add').disabled, false);
});

test('removing a member is a roster-only mutation and keeps unfinished member selections', async () => {
  const ui = setup({api: async name => name === 'removeMember' ? roster({revision: 8, candidates: [candidate(2), candidate(3)]}) : undefined});
  ui.mount(project({roster: roster({members: [member(2, {activeAssignmentCount: 1})], candidates: [candidate(3)]})}));
  ui.checkbox(3).checked = true; ui.state.formDirty = true;
  await ui.action(ui.controls().find(control => control.dataset.action === 'roster-remove'));
  assert.deepEqual(plain(ui.calls.filter(call => call.payload)), [{name: 'removeMember', payload: {projectId: 'p1', revision: 7, userId: 2}, query: {}}]);
  assert.equal(ui.checkbox(3).checked, true);
  assert.equal(ui.state.formDirty, true);
  assert.match(ui.element('work-toast').textContent, /Existing assignments remain/);
});

test('a stale roster response cannot overwrite another selected production', () => {
  const ui = setup(); ui.mount(project({id: 'p2', roster: roster({id: 'p2', projectId: 'p2', revision: 3})}));
  const before = ui.element('work-roster').innerHTML;
  assert.equal(ui.updateRoster(roster({revision: 99}), 'p1'), false);
  assert.equal(ui.state.detail.data.roster.revision, 3);
  assert.equal(ui.element('work-roster').innerHTML, before);
});

test('included models can open My productions before any individual shot exists', async () => {
  const data = project({canManage: false, isMember: true, roster: undefined, assignmentCount: null});
  const ui = setup({page: 'my-work', api: async name => name === 'myWork' ? {projects: [data], assigned: [], open: [], submitted: [], completed: []} : name === 'project' ? data : undefined});
  await ui.chooseTab('projects');
  assert.match(ui.location.search, /tab=projects/);
  assert.equal(ui.element('work-list-count').textContent, '1 production');
  assert.match(ui.element('work-list').innerHTML, /Desert collection|View available shots/);
  assert.doesNotMatch(ui.element('work-list').innerHTML, /null|assignment count/);
  await ui.openProject('p1');
  const html = ui.element('work-detail').innerHTML;
  assert.match(html, /No shots available yet/);
  assert.match(html, /The producer will add open shots or assign work to you/);
  assert.doesNotMatch(html, /The first shot is yours|data-action="new-shot"|id="work-roster"/);
});
