import {mountEditComposer} from './edit-composer.js';

const PURPOSES = [['opening','Opening'],['product_detail','Product detail'],['demonstration','Demonstration'],['answer','Interview answer'],['reaction','Reaction'],['b_roll','Supporting footage'],['closing','Closing'],['other','Other']];
const STATUS_NAMES = {ready:'Ready',missing:'Missing footage',awaiting_review:'Awaiting review',retake_needed:'Retake needed'};
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const $ = id => document.getElementById(id);
const state = {projectId:'',snapshot:null,csrf:'',tab:'coverage',busy:false,readSerial:0,versionSerial:0,proposal:null,proposalDirty:false,requirementEdit:null,drafts:new Map(),composer:null,versions:[],versionError:'',search:'',filter:'all',selectionEdits:new Map()};
const receiptTokens = new Map();

export function localUrl(value, base = globalThis.location?.href || 'https://studio.invalid/') {
    if(typeof value!=='string'||!value.trim())return '';
    try { const url = new URL(value, base), origin = new URL(base).origin; return ['http:','https:'].includes(url.protocol) && url.origin === origin ? url.pathname + url.search + url.hash : ''; } catch { return ''; }
}
export function formatTime(milliseconds) {
    const ms = Math.max(0, Number(milliseconds) || 0), seconds = Math.floor(ms / 1000);
    return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0') + (ms % 1000 ? '.' + String(Math.floor(ms % 1000 / 100)).slice(0,1) : '');
}
export function targetSeconds(preset, custom) {
    const value = preset === 'custom' ? Number(custom) : Number(preset);
    if (!Number.isInteger(value) || value < 0 || value > 3600 || (preset === 'custom' && value === 0)) throw new Error('Choose a target between 1 and 3,600 seconds, or choose Full selection.');
    return value;
}
export function rangeMilliseconds(start, end, duration) {
    const startMs = Math.round(Number(start) * 1000), endMs = Math.round(Number(end) * 1000);
    if (!Number.isFinite(startMs) || !Number.isFinite(endMs) || start === '' || end === '' || startMs < 0 || endMs - startMs < 100) throw new Error('Choose an end at least 0.1 seconds after the start.');
    if (!Number.isFinite(Number(duration)) || Number(duration) <= 0) throw new Error('This take needs a known duration before you can save a usable section.');
    if (endMs > Number(duration)) throw new Error('The selected section extends beyond this take.');
    return {startMs, endMs};
}
export function proposalDuration(edit) {
    const settings = edit.settings || {}, cardDuration = Number(settings.cardDuration ?? 3);
    return (edit.order || []).reduce((total, id) => { const segment = edit.segments?.[id]; if (!segment || segment.included === false) return total; const showCard = settings.cardMode === 'all' || (settings.cardMode === 'perClip' && segment.showCard === true); return total + Math.max(0, Number(segment.trimEnd) - Number(segment.trimStart)) * 1000 + (showCard ? cardDuration * 1000 : 0); }, 0);
}
export function validateDraftEdit(edit, snapshot) {
    if (!edit || !Array.isArray(edit.order) || !edit.segments) throw new Error('Build a first-cut proposal before saving a draft.');
    const takes = new Map((snapshot.eligibleTakes || []).map(take => [take.id, take]));
    const order = [], segments = {}, seen = new Set();
    for (const id of edit.order) {
        const segment = edit.segments[id];
        if (!segment || segment.included === false) continue;
        if (seen.has(id)) throw new Error('A take can appear once in this first-cut draft.');
        seen.add(id);
        const take = takes.get(id);
        if (!take) throw new Error('A selected take is no longer available. Refresh this production and build a new proposal.');
        const range = rangeMilliseconds(segment.trimStart, segment.trimEnd, take.durationMs);
        const candidates = (snapshot.candidates || []).filter(candidate => candidate.takeId === id);
        if (!candidates.some(candidate => range.startMs >= Number(candidate.startMs) && range.endMs <= Number(candidate.endMs))) throw new Error('Keep each clip inside a marked usable section. Update the selection in Usable footage if you need a wider range.');
        order.push(id); segments[id] = {...segment, included:true, title:String(segment.title || take.label || take.questionText || 'Video clip').slice(0,1000), trimStart:range.startMs/1000, trimEnd:range.endMs/1000, showCard:segment.showCard === true};
    }
    if (!order.length) throw new Error('Include at least one usable clip in this version.');
    const settings = {...edit.settings};
    if (!Number.isFinite(Number(settings.cardDuration)) || Number(settings.cardDuration) < 0.5 || Number(settings.cardDuration) > 15) throw new Error('Question cards must last between 0.5 and 15 seconds.');
    if (!Number.isFinite(Number(settings.fadeDuration)) || Number(settings.fadeDuration) < 0 || Number(settings.fadeDuration) > 3) throw new Error('Fades must last between 0 and 3 seconds.');
    settings.cardMode = 'perClip';
    return {order, segments, settings};
}

function pageUrl(page, values = {}) { const url = new URL(page + '.php', location.href); for (const [key, value] of Object.entries(values)) if (value !== null && value !== undefined && value !== '') url.searchParams.set(key, String(value)); return url.pathname + url.search; }
function button(label, action, id = '', primary = false, attributes = '') { return '<button type="button" class="button' + (primary ? ' button--primary' : '') + '" data-director-action="' + action + '" data-id="' + esc(id) + '" data-director-lock ' + attributes + '>' + esc(label) + '</button>'; }
function link(label, url, primary = false) { const href = localUrl(url); return href ? '<a class="button' + (primary ? ' button--primary' : '') + '" href="' + esc(href) + '">' + esc(label) + '</a>' : ''; }
function field(name, label, value = '', type = 'text', attributes = '') { return '<label class="field">' + esc(label) + '<input name="' + name + '" type="' + type + '" value="' + esc(value) + '" data-director-lock ' + attributes + '></label>'; }
function select(name, label, options, value = '') { return '<label class="field">' + esc(label) + '<select name="' + name + '" data-director-lock>' + options.map(([id, title]) => '<option value="' + esc(id) + '"' + (String(id) === String(value) ? ' selected' : '') + '>' + esc(title) + '</option>').join('') + '</select></label>'; }
function checkbox(name, label, checked, value = '1') { return '<label class="director-checkbox"><input type="checkbox" name="' + esc(name) + '" value="' + esc(value) + '"' + (checked ? ' checked' : '') + ' data-director-lock>' + esc(label) + '</label>'; }
function empty(title, description, actions = '') { return '<div class="director-empty"><h3>' + esc(title) + '</h3><p>' + esc(description) + '</p>' + (actions ? '<div class="director-actions">' + actions + '</div>' : '') + '</div>'; }
function statusBadge(status) { return '<span class="director-status director-status--' + (Object.hasOwn(STATUS_NAMES, status) ? status : 'missing') + '">' + esc(STATUS_NAMES[status] || 'Missing footage') + '</span>'; }
function readOnly() { return ['archived','completed'].includes(state.snapshot?.project.status); }
function clearError() { $('director-error').hidden = true; $('director-error').textContent = ''; }
function toast(message, error = false) { const node = $('director-toast'); node.textContent = message; node.className = 'toast visible' + (error ? ' is-error' : ''); clearTimeout(toast.timer); toast.timer = setTimeout(() => node.classList.remove('visible'), error ? 9000 : 5000); }
function fail(error) { console.error(error); if(!state.snapshot)$('director-loading').hidden=true; $('director-error').textContent = error.message || 'This production could not be updated. Please try again.'; $('director-error').hidden = false; toast(error.message || 'Please try again.', true); }

async function request(endpoint, action, payload = null, query = {}, retry = true) {
    const url = new URL(endpoint, location.href); url.searchParams.set('action', action);
    for (const [key, value] of Object.entries(query)) url.searchParams.set(key, String(value));
    const options = {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}};
    let receiptKey = '';
    if (payload !== null) {
        payload = {...payload};
        if (!payload.requestId && !payload.requestToken) {
            receiptKey = endpoint + '\n' + action + '\n' + JSON.stringify(payload);
            if (!receiptTokens.has(receiptKey)) receiptTokens.set(receiptKey, crypto.randomUUID());
            payload[endpoint === 'edit-api.php' ? 'requestToken' : 'requestId'] = receiptTokens.get(receiptKey);
        }
        options.method = 'POST'; options.headers['Content-Type'] = 'application/json'; options.headers['X-CSRF-Token'] = state.csrf; options.body = JSON.stringify(payload);
    }
    const response = await fetch(url, options); let result;
    try { result = await response.json(); } catch { throw new Error('The server response was interrupted. Retry the same action to confirm whether it was saved.'); }
    if (!response.ok || result.ok === false) {
        if (retry && payload !== null && result.error?.code === 'csrf_invalid') {
            const fresh = await request('director-api.php', 'project', null, {projectId:state.projectId}, false); state.csrf = fresh.csrf || state.csrf;
            const saved = await request(endpoint, action, payload, query, false); if (receiptKey) receiptTokens.delete(receiptKey); return saved;
        }
        const error = new Error(result.error?.message || 'This production request could not be completed.'); error.status = response.status; error.code = result.error?.code;
        if (response.status === 401) error.message = 'Your session expired. Sign in in another tab, then retry. Your unsaved entries are still here.';
        throw error;
    }
    if (receiptKey) receiptTokens.delete(receiptKey);
    return result.data;
}
const api = (action, payload = null, query = {}) => request('director-api.php', action, payload, query);
const editApi = (action, payload = null, query = {}) => request('edit-api.php', action, payload, query);

function formDraft(form) {
    return [...form.querySelectorAll('input[name],textarea[name],select[name]')].map(control => ({name:control.name, type:control.type, value:control.value, checked:control.checked}));
}
function rememberForm(form) { if (form?.dataset.formKey) state.drafts.set(form.dataset.formKey, formDraft(form)); }
function restoreDrafts(container = $('director-app')) {
    for (const form of container.querySelectorAll('[data-form-key]')) {
        const record = state.drafts.get(form.dataset.formKey); if (!record) continue;
        for (const control of form.querySelectorAll('input[name],textarea[name],select[name]')) {
            const entry = record.find(item => item.name === control.name && (control.type !== 'checkbox' || item.value === control.value));
            if (!entry) continue;
            if (control.type === 'checkbox') control.checked = entry.checked; else control.value = entry.value;
        }
    }
}
function captureVisibleDrafts() { for (const form of $('director-app').querySelectorAll('[data-form-key]')) if (state.drafts.has(form.dataset.formKey)) rememberForm(form); }
function hasEditorialDrafts() { return [...state.drafts.keys()].some(key => key.startsWith('requirement:') || key.startsWith('selection:')); }
function dirty() { return state.drafts.size > 0 || state.proposalDirty || !!state.composer?.isDirty?.(); }
function mayNavigate() { if (state.busy || state.composer?.isBusy?.()) { toast('Let the current save or proposal finish before leaving.'); return false; } return !dirty() || confirm('Leave this page? Unsaved requirements, selections, and first-cut changes will be lost.'); }
async function write(source, operation) {
    if (state.busy) return;
    if (state.composer?.isBusy?.()) { toast('Let the editing assistant finish before changing this production.'); return; }
    state.busy = true; state.readSerial++; clearError(); captureVisibleDrafts();
    const controls = [...document.querySelectorAll('[data-director-lock]')].map(node => [node, node.disabled]); controls.forEach(([node]) => node.disabled = true);
    const message = source?.querySelector?.('[data-form-status]'); if (message) message.textContent = 'Saving…';
    try { return await operation(); }
    catch (error) { if (message?.isConnected) message.textContent = 'Not confirmed. Your entries are still here. Retry to check the save.'; throw error; }
    finally { state.busy = false; controls.forEach(([node, previous]) => { if (node.isConnected) node.disabled = previous; }); applyReadOnly(); }
}
function applyReadOnly() {
    if (!state.snapshot) return;
    const closed = readOnly();
    for (const node of document.querySelectorAll('[data-director-action="new-requirement"],[data-director-action="edit-requirement"],[data-director-action="delete-requirement"],[data-director-action="create-shot"],[data-director-form="requirement"] [data-director-lock]')) node.disabled = closed || state.busy;
    const archived = state.snapshot.project.status === 'archived';
    for (const node of document.querySelectorAll('[data-director-action="edit-selection"],[data-director-action="delete-selection"],[data-director-form="selection"] [data-director-lock]')) node.disabled = archived || state.busy;
    for (const node of $('director-cut-form').querySelectorAll('[data-director-lock]')) node.disabled = archived || state.busy;
    const save = $('director-save-draft'); if (save) save.disabled = archived || state.busy || state.proposal?.fingerprint !== state.snapshot.fingerprint;
}

function showTab(tab) {
    if (!['coverage','footage','cut'].includes(tab)) return;
    state.tab = tab;
    for (const button of document.querySelectorAll('[data-director-tab]')) button.setAttribute('aria-pressed', String(button.dataset.directorTab === tab));
    for (const section of document.querySelectorAll('[data-director-panel]')) section.hidden = !state.snapshot || section.dataset.directorPanel !== tab;
    const url = new URL(location.href); url.hash = tab; history.replaceState(null, '', url.pathname + url.search + url.hash);
}
function renderHeader() {
    const {project, coverage, eligibleTakes, selections} = state.snapshot;
    $('director-title').textContent = project.title; $('director-brief').textContent = project.brief || 'Check your coverage, mark the moments to use, and shape a first edit.';
    $('director-production-link').href = localUrl(project.productionUrl) || pageUrl('productions', {projectId:project.id});
    const editorUrl = localUrl(project.editorUrl); $('director-editor-link').hidden = !editorUrl; if (editorUrl) $('director-editor-link').href = editorUrl;
    $('director-metrics').innerHTML = [['Required coverage ready',String(coverage.readyCount || 0) + ' / ' + String(coverage.requiredCount || 0)],['Eligible accepted takes',eligibleTakes.length],['Marked usable sections',selections.filter(item => item.eligible).length],['Requirements awaiting review',state.snapshot.requirements.filter(item=>Number(item.coverage?.awaitingReviewCount)>0).length]].map(([label, value]) => '<div class="director-metric"><strong>' + esc(value) + '</strong><span>' + esc(label) + '</span></div>').join('');
    const closed = $('director-closed'); closed.hidden = !readOnly(); closed.textContent = project.status === 'archived' ? 'This production is archived. Restore it in Productions to change coverage or create another cut.' : 'This production is completed. Coverage requirements are kept for review; you can still mark usable sections and create editable cuts from available footage.';
    $('director-loading').hidden = true; $('director-tabs').hidden = false;
}
function requirementForm(item = null) {
    state.requirementEdit = item ? {...item} : {id:null};
    const r = item || {title:'', purpose:'opening', minimumSelections:1, targetDurationSeconds:0, required:true, sortOrder:state.snapshot.requirements.length, shotIds:[]};
    const shots = state.snapshot.project.shots || [];
    $('director-requirement-form').innerHTML = '<form class="director-panel director-form" data-director-form="requirement" data-form-key="requirement:' + esc(r.id || 'new') + '"><div class="director-section-heading"><div><h3>' + (r.id ? 'Edit coverage requirement' : 'Add coverage requirement') + '</h3><p>Require usable footage, then link the shots that can supply it.</p></div></div>' + field('title','Requirement title',r.title,'text','required maxlength="200" placeholder="A strong opening introduction"') + '<div class="director-form-grid">' + select('purpose','Purpose',PURPOSES,r.purpose) + field('minimumSelections','Minimum accepted takes',r.minimumSelections,'number','required min="1" max="20" step="1"') + field('targetDurationSeconds','Minimum usable seconds · 0 for no minimum',r.targetDurationSeconds,'number','required min="0" max="3600" step="1"') + field('sortOrder','Story order · starts at 1',Number(r.sortOrder)+1,'number','required min="1" max="10001" step="1"') + '</div>' + checkbox('required','Required for this production to be ready',r.required) + '<details' + (r.shotIds.length ? ' open' : '') + '><summary>Link existing shots · ' + shots.length + ' available</summary>' + (shots.length ? '<fieldset><legend>Shots that supply this requirement</legend><div class="director-shot-options">' + shots.map(shot => checkbox('shotIds',shot.title + (shot.status !== 'active' ? ' · ' + shot.status : ''),r.shotIds.includes(shot.id),shot.id)).join('') + '</div></fieldset>' : '<p class="field-help">Save this requirement, then use Create missing shot to add a brief for contributors.</p>') + '</details><div class="director-actions"><button type="submit" class="button button--primary" data-director-lock>Save requirement</button>' + button('Cancel','cancel-requirement') + '</div><p class="director-form-status" data-form-status role="status"></p></form>';
    restoreDrafts($('director-requirement-form')); applyReadOnly();
}
function renderRequirements() {
    const requirements = state.snapshot.requirements, shots = new Map((state.snapshot.project.shots || []).map(shot => [shot.id,shot]));
    $('director-requirements').innerHTML = requirements.length ? requirements.map(r => {
        const coverage = r.coverage || {}, percent = Math.min(100, (Number(coverage.acceptedCount)||0) / Math.max(1,Number(r.minimumSelections)) * 100), availableSelections = state.snapshot.selections.filter(item => item.requirementId === r.id);
        const actions = (coverage.actions || []).filter(action => action.url && localUrl(action.url));
        return '<article class="director-requirement"><div class="director-card-top"><div><p class="eyebrow">' + esc(PURPOSES.find(p => p[0] === r.purpose)?.[1] || r.purpose) + (r.required ? '' : ' · OPTIONAL') + '</p><h3>' + esc(r.title) + '</h3></div>' + statusBadge(coverage.status) + '</div><div class="director-meta"><span>' + esc(coverage.acceptedCount || 0) + ' / ' + esc(r.minimumSelections) + ' accepted takes</span><span>' + esc(formatTime(coverage.durationMs || 0)) + ' usable' + (r.targetDurationSeconds ? ' / ' + esc(formatTime(r.targetDurationSeconds*1000)) + ' needed' : '') + '</span></div><div class="director-progress" aria-hidden="true"><span style="width:' + percent + '%"></span></div>' + (coverage.durationMissingMs > 0 ? '<p class="field-help">Another ' + esc(formatTime(coverage.durationMissingMs)) + ' of usable coverage is needed.</p>' : '') + '<div class="director-actions">' + (coverage.status !== 'ready' ? button('Create missing shot','create-shot',r.id,false,readOnly()?'disabled':'') : '') + actions.map(action => link(action.label || 'Open work',action.url)).join('') + '</div>' + ((r.shotIds || []).length ? '<ul class="director-shot-links">' + r.shotIds.map(id => '<li><a href="' + esc(pageUrl('productions',{shotId:id})) + '">' + esc(shots.get(id)?.title || 'Open linked shot') + ' · manage contributors</a></li>').join('') + '</ul>' : '<p class="field-help">No linked shots. Link an existing shot or mark a usable section for this requirement.</p>') + (availableSelections.length ? '<ul class="director-selection-list">' + availableSelections.map(selectionSummary).join('') + '</ul>' : '') + '<div class="director-actions">' + button('Edit requirement','edit-requirement',r.id) + button('Remove requirement','delete-requirement',r.id) + '</div></article>';
    }).join('') : empty('Give this production its shape','Add an opening, an answer, a product detail, or another requirement. The Director will show what is ready and what needs a shot.',button('Add the first requirement','new-requirement','',true));
}
function selectionSummary(selection) {
    return '<li class="director-selection' + (!selection.eligible ? ' is-unavailable' : '') + '"><strong>' + esc(selection.label || 'Usable section') + (selection.preferred ? ' · Preferred' : '') + '</strong><p>' + esc(formatTime(selection.startMs)) + '–' + esc(formatTime(selection.endMs)) + (selection.eligible ? '' : ' · Source no longer available for editing') + '</p>' + (selection.notes ? '<p>' + esc(selection.notes) + '</p>' : '') + '<div class="director-actions">' + (selection.eligible ? button('Edit section','edit-selection',selection.id) : '') + button('Remove section','delete-selection',selection.id) + '</div></li>';
}
function selectionForm(take) {
    const current = state.selectionEdits.get(take.id), selection = current ? state.snapshot.selections.find(item => item.id === current) : null;
    const item = selection || {requirementId:state.snapshot.requirements[0]?.id || '',label:take.label || take.questionText || '',notes:'',startMs:0,endMs:Number(take.durationMs)||0,preferred:false};
    if (!state.snapshot.requirements.length) return '<div class="director-notice">Add a coverage requirement before marking a usable section.</div>' + button('Add requirement','new-requirement');
    if (!Number(take.durationMs)) return '<p class="director-notice">This take has no recorded duration. Review its source recording before using it in a duration-based edit.</p>';
    return '<form class="director-form" data-director-form="selection" data-take-id="' + esc(take.id) + '" data-form-key="selection:' + esc(take.id) + '"><h3>' + (selection ? 'Edit usable section' : 'Mark a usable section') + '</h3><input type="hidden" name="id" value="' + esc(selection?.id || '') + '"><input type="hidden" name="revision" value="' + esc(selection?.revision || '') + '">' + select('requirementId','Covers this requirement',state.snapshot.requirements.map(r => [r.id,r.title]),item.requirementId) + field('label','Section label',item.label,'text','required maxlength="200"') + '<div class="director-range-fields"><div class="director-range-control">' + field('start','Start · seconds',(item.startMs/1000).toFixed(3),'number','required min="0" max="' + Number(take.durationMs/1000) + '" step="0.001"') + button('Mark start here','mark-start',take.id) + '</div><div class="director-range-control">' + field('end','End · seconds',(item.endMs/1000).toFixed(3),'number','required min="0.1" max="' + Number(take.durationMs/1000) + '" step="0.001"') + button('Mark end here','mark-end',take.id) + '</div></div><label class="field">Editorial note · optional<textarea name="notes" rows="3" maxlength="4000" data-director-lock>' + esc(item.notes) + '</textarea></label>' + checkbox('preferred','Prefer this section when building the first cut',item.preferred) + '<div class="director-actions"><button type="submit" class="button button--primary" data-director-lock>' + (selection ? 'Save section changes' : 'Use this section') + '</button>' + button('Play selected section','preview-selection',take.id) + (selection ? button('Cancel edit','cancel-selection',take.id) : '') + '</div><p class="director-form-status" data-form-status role="status"></p></form>';
}
function takeCard(take) {
    const src = localUrl(take.mediaUrl), selections = state.snapshot.selections.filter(item => item.takeId === take.id), notes = take.reviewNotes || [];
    return '<article class="director-take" id="director-take-' + esc(take.id) + '"><div class="director-card-top"><div><p class="eyebrow">' + esc(take.sourceKind === 'interview' ? 'INTERVIEW FOOTAGE' : 'ACCEPTED FOOTAGE') + '</p><h3>' + esc(take.label || take.questionText || 'Video take') + '</h3></div>' + (take.isFavorite ? '<span class="director-status director-status--ready">Favorite take</span>' : '') + '</div><div class="director-meta"><span>' + esc(formatTime(take.durationMs)) + '</span><span>' + selections.length + ' usable section' + (selections.length===1?'':'s') + '</span></div>' + (src ? '<video id="director-video-' + esc(take.id) + '" controls playsinline preload="metadata" src="' + esc(src) + '"></video>' : '<p class="director-notice">The video is unavailable.</p>') + (notes.length ? '<details class="director-take-notes"><summary>Review notes · ' + notes.length + '</summary>' + notes.map(note => '<div class="director-note"><button type="button" class="text-button" data-director-action="seek-note" data-id="' + esc(take.id) + '" data-at="' + Number(note.atMs||0) + '">' + esc(formatTime(note.atMs)) + '</button><p>' + esc(note.text) + '</p></div>').join('') + '</details>' : '') + selectionForm(take) + (selections.length ? '<ul class="director-selection-list">' + selections.map(selectionSummary).join('') + '</ul>' : '') + '</article>';
}
function renderFootage() {
    if (!state.snapshot) return;
    const query = state.search.trim().toLowerCase(), selected = new Set(state.snapshot.selections.filter(item => item.eligible).map(item => item.takeId));
    const takes = state.snapshot.eligibleTakes.filter(take => (!query || [take.label,take.questionText].join(' ').toLowerCase().includes(query)) && (state.filter !== 'favorite' || take.isFavorite) && (state.filter !== 'unselected' || !selected.has(take.id)));
    $('director-footage-count').textContent = takes.length + ' of ' + state.snapshot.eligibleTakes.length + ' eligible takes';
    $('director-takes').innerHTML = takes.length ? takes.map(takeCard).join('') : empty(state.snapshot.eligibleTakes.length ? 'No matching takes' : 'Accepted footage will appear here',state.snapshot.eligibleTakes.length ? 'Change the search or filter to see more footage.' : 'Review submissions in Productions. Accepted, available takes can be marked for the first cut.',link('Open production',state.snapshot.project.productionUrl));
    restoreDrafts($('director-takes')); applyReadOnly();
}
function proposalTitle() { const seconds = (Number(state.proposal?.targetDurationMs)||0)/1000; return state.snapshot.project.title + (seconds ? ' · ' + seconds + '-second cut' : ' · Full selection'); }
function renderProposal() {
    const mount = $('director-proposal'), proposal = state.proposal;
    if (!proposal) { mount.innerHTML = ''; return; }
    const stale = proposal.fingerprint !== state.snapshot.fingerprint, takes = new Map(state.snapshot.eligibleTakes.map(take => [take.id,take])), edit = proposal.edit;
    mount.innerHTML = '<form class="director-panel director-form" data-director-form="save-draft" data-form-key="proposal"><div class="director-section-heading"><div><p class="eyebrow">REVIEW YOUR FIRST CUT</p><h3>' + (proposal.proposalId ? 'Assisted editing proposal' : 'First-cut proposal') + '</h3><p id="director-proposal-duration">' + esc(formatTime(proposalDuration(edit))) + ' including question cards · ' + edit.order.length + ' selected takes</p></div></div>' + (stale ? '<p class="director-notice">The production changed after this proposal was made. Your draft is still visible. Build a fresh proposal before saving a version.</p>' : '') + (proposal.summary ? '<p class="director-proposal-summary">' + esc(proposal.summary) + '</p>' : '') + ((proposal.warnings||[]).length ? '<ul class="director-warning-list">' + proposal.warnings.map(warning => '<li>' + esc(typeof warning === 'string' ? warning : warning.message || warning.label || '') + '</li>').join('') + '</ul>' : '') + field('title','Editable version name',proposalTitle(),'text','required maxlength="200"') + '<div class="director-form-grid">' + field('cardDuration','Question card · seconds',edit.settings?.cardDuration ?? 3,'number','required min="0.5" max="15" step="0.1"') + field('fadeDuration','Fade · seconds',edit.settings?.fadeDuration ?? 0.45,'number','required min="0" max="3" step="0.05"') + '</div><p class="director-preview-explanation">Preview individual clips below. Open the saved version in the editor for the combined sequence, black question cards, and fades.</p><ol class="director-proposal-clips">' + edit.order.map((id,index) => {
        const segment = edit.segments[id], take = takes.get(id), item = (proposal.items||[]).find(item => item.takeId===id), src = take && localUrl(take.mediaUrl), showCard = edit.settings?.cardMode==='all' || (edit.settings?.cardMode==='perClip' && segment.showCard);
        return '<li class="director-proposal-clip" data-proposal-take="' + esc(id) + '"><h4>' + (index+1) + '. ' + esc(take?.label || take?.questionText || 'Unavailable source') + '</h4>' + checkbox('include:'+id,'Include this clip',segment.included !== false) + field('title:'+id,'Question or title card text',segment.title || take?.questionText || '', 'text','maxlength="1000"') + '<div class="director-form-grid">' + field('start:'+id,'Start · seconds',segment.trimStart,'number','required min="0" step="0.001"') + field('end:'+id,'End · seconds',segment.trimEnd,'number','required min="0.1" step="0.001"') + '</div>' + checkbox('card:'+id,'Show a black question card before this clip',showCard) + (item?.reason ? '<p class="field-help">' + esc(item.reason) + '</p>' : '') + '<div class="director-actions">' + button('Move earlier','move-clip',id,false,index===0?'disabled':'data-offset="-1"') + button('Move later','move-clip',id,false,index===edit.order.length-1?'disabled':'data-offset="1"') + (src ? button('Preview clip','preview-clip',id) : '') + '</div>' + (src ? '<video id="director-proposal-video-' + esc(id) + '" controls playsinline preload="none" src="' + esc(src) + '" hidden></video>' : '<p class="director-notice">This source is no longer available.</p>') + '</li>';
    }).join('') + '</ol><div class="director-actions"><button id="director-save-draft" type="submit" class="button button--primary" data-director-lock' + (stale?' disabled':'') + '>Save named draft & open editor</button>' + button('Discard proposal','discard-proposal') + '</div><p class="director-form-status" data-form-status role="status"></p></form>';
    restoreDrafts(mount); applyReadOnly();
}
function collectProposal(form = $('director-proposal').querySelector('form')) {
    if (!state.proposal || !form) throw new Error('Build a proposal first.');
    const values = Object.fromEntries(new FormData(form)), edit = structuredClone(state.proposal.edit);
    edit.settings = {...edit.settings,cardMode:'perClip',cardDuration:Number(values.cardDuration),fadeDuration:Number(values.fadeDuration)};
    for (const id of edit.order) edit.segments[id] = {...edit.segments[id],included:values['include:'+id]==='1',title:values['title:'+id]||'',trimStart:Number(values['start:'+id]),trimEnd:Number(values['end:'+id]),showCard:values['card:'+id]==='1'};
    return {edit,title:String(values.title||'').trim()};
}
function rememberProposal() { const form=$('director-proposal').querySelector('form'); if (!form || !state.proposal) return; state.proposal.edit=collectProposal(form).edit; rememberForm(form); state.proposalDirty=true; }
function acceptProposal(proposal) {
    if (!proposal?.edit?.order || !proposal.edit.segments) throw new Error('The proposal did not contain an editable sequence.');
    if (state.proposalDirty && !confirm('Replace your unsaved first-cut proposal with this new proposal?')) return false;
    state.proposal=structuredClone(proposal); state.proposalDirty=true; state.drafts.delete('proposal'); renderProposal(); showTab('cut'); $('director-proposal').scrollIntoView({behavior:'smooth',block:'start'}); return true;
}
function renderVersions() {
    if (state.versionError) { $('director-versions').innerHTML='<p class="director-notice">'+esc(state.versionError)+'</p>'; return; }
    $('director-versions').innerHTML=state.versions.length?'<div class="director-version-list">'+state.versions.map(version=>'<article class="director-version"><div><strong>'+esc(version.title||'Editable version')+'</strong><p>Revision '+esc(version.revision||1)+(version.updatedAt?' · Updated '+esc(new Intl.DateTimeFormat('en-US',{timeZone:'America/Phoenix',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date(version.updatedAt))):'')+'</p></div>'+link('Open version',version.editorUrl)+'</article>').join('')+'</div>':empty('Your first draft starts here','Build a proposal and save it with a name. Your editable versions will stay connected to this production.');
}
async function loadVersions() { const serial=++state.versionSerial; try { const data=await editApi('list',null,{projectId:state.projectId}); if(serial!==state.versionSerial)return; state.versions=Array.isArray(data.timelines)?data.timelines:[];state.versionError=''; } catch(error) { if(serial!==state.versionSerial)return;state.versionError=error.message; } renderVersions(); }
function applySnapshot(snapshot) {
    const firstLoad=!state.snapshot;
    if (!snapshot?.project || snapshot.project.id!==state.projectId) throw new Error('The server returned a different production. Reload this page.');
    state.snapshot={...snapshot,requirements:snapshot.requirements||[],selections:snapshot.selections||[],eligibleTakes:snapshot.eligibleTakes||[],candidates:snapshot.candidates||[],coverage:snapshot.coverage||{}};
    state.csrf=snapshot.csrf||state.csrf;renderHeader();renderRequirements();renderFootage();
    if(firstLoad){const cardMode=$('director-cut-form').querySelector('[name=cardMode]');if(cardMode)cardMode.value=snapshot.project.kind==='interview'?'all':snapshot.project.kind==='mixed'?'perClip':'none';}
    if (state.requirementEdit) { const key='requirement:'+(state.requirementEdit.id||'new'); requirementForm(state.drafts.has(key)?state.requirementEdit:state.requirementEdit.id?state.snapshot.requirements.find(r=>r.id===state.requirementEdit.id)||state.requirementEdit:null); }
    if(state.proposal){captureVisibleDrafts();renderProposal();}
    showTab(state.tab);applyReadOnly();
}
function ensureComposer(){if(state.composer||!state.snapshot)return;state.composer=mountEditComposer($('director-ai-mount'),{project:state.snapshot.project,csrf:state.csrf,user:{id:Number(document.body.dataset.directorUser)},getDirector:()=>state.snapshot,canRequest:()=>!state.busy&&!hasEditorialDrafts(),onProposal:async proposal=>{if(state.busy)throw new Error('Let the current save finish before opening an editing proposal.');return acceptProposal(proposal);}});}
async function loadProject() { const serial=++state.readSerial;const snapshot=await api('project',null,{projectId:state.projectId});if(serial!==state.readSerial)return false;applySnapshot(snapshot);ensureComposer();return true; }
function selectedRange(takeId, proposal=false) {
    const take=state.snapshot.eligibleTakes.find(item=>item.id===takeId);if(!take)throw new Error('This source is no longer available.');
    if(proposal){const {edit}=collectProposal();const segment=edit.segments[takeId];return rangeMilliseconds(segment.trimStart,segment.trimEnd,take.durationMs);}
    const form=$('director-take-'+takeId)?.querySelector('[data-director-form="selection"]');if(!form)throw new Error('Open a usable-section form for this take.');
    return rangeMilliseconds(form.querySelector('[name=start]').value,form.querySelector('[name=end]').value,take.durationMs);
}
async function playRange(takeId, proposal=false) {
    const range=selectedRange(takeId,proposal),video=$((proposal?'director-proposal-video-':'director-video-')+takeId);if(!video)throw new Error('This video is not available.');
    video.hidden=false;video.currentTime=range.startMs/1000;video.dataset.previewEnd=String(range.endMs/1000);
    video.ontimeupdate=()=>{if(Number(video.dataset.previewEnd)>0&&video.currentTime>=Number(video.dataset.previewEnd)){video.pause();delete video.dataset.previewEnd;}};
    await video.play();
}

async function action(node) {
    const name=node.dataset.directorAction,id=node.dataset.id;
    if(name==='new-requirement'||name==='edit-requirement') { if(readOnly())return; const previousKey=state.requirementEdit?'requirement:'+(state.requirementEdit.id||'new'):'';if(previousKey&&state.drafts.has(previousKey)){if(!confirm('Replace the unsaved requirement form?'))return;state.drafts.delete(previousKey);} showTab('coverage');requirementForm(name==='edit-requirement'?state.snapshot.requirements.find(r=>r.id===id):null);$('director-requirement-form').scrollIntoView({behavior:'smooth',block:'start'});return; }
    if(name==='cancel-requirement') { const key='requirement:'+(state.requirementEdit?.id||'new');if(state.drafts.has(key)&&!confirm('Discard unsaved requirement changes?'))return;state.drafts.delete(key);state.requirementEdit=null;$('director-requirement-form').innerHTML='';return; }
    if(name==='delete-requirement') { const r=state.snapshot.requirements.find(item=>item.id===id);if(!r||!confirm('Remove this requirement and its marked sections? Original shots, assignments, and footage will remain.'))return;return write(node,async()=>{const result=await api('requirement.delete',{projectId:state.projectId,id,revision:r.revision});state.drafts.delete('requirement:'+id);if(state.requirementEdit?.id===id){state.requirementEdit=null;$('director-requirement-form').innerHTML='';}applySnapshot(result);toast('Requirement removed.');}); }
    if(name==='create-shot') { const r=state.snapshot.requirements.find(item=>item.id===id);if(!r)return;return write(node,async()=>{const result=await api('requirement.shot',{projectId:state.projectId,requirementId:id,revision:r.revision});applySnapshot(result.overview);toast(result.created?'Shot created. Open its contributor link to assign the work.':'The existing linked shot is ready to manage.');}); }
    if(name==='edit-selection') { const selection=state.snapshot.selections.find(item=>item.id===id);if(!selection)return; const key='selection:'+selection.takeId;if(state.drafts.has(key)&&!confirm('Replace the unsaved section form?'))return;state.drafts.delete(key);state.selectionEdits.set(selection.takeId,id);state.search='';state.filter='all';$('director-footage-search').value='';$('director-footage-filter').value='all';showTab('footage');renderFootage();$('director-take-'+selection.takeId)?.scrollIntoView({behavior:'smooth',block:'start'});return; }
    if(name==='cancel-selection') { const key='selection:'+id;if(state.drafts.has(key)&&!confirm('Discard unsaved section changes?'))return;state.drafts.delete(key);state.selectionEdits.delete(id);renderFootage();return; }
    if(name==='delete-selection') { const selection=state.snapshot.selections.find(item=>item.id===id);if(!selection||!confirm('Remove this usable-section label? The original footage will remain.'))return;return write(node,async()=>{const result=await api('selection.delete',{projectId:state.projectId,id,revision:selection.revision});if(state.selectionEdits.get(selection.takeId)===id){state.selectionEdits.delete(selection.takeId);state.drafts.delete('selection:'+selection.takeId);}applySnapshot(result);toast('Usable-section label removed.');}); }
    if(name==='mark-start'||name==='mark-end') { const video=$('director-video-'+id),form=node.closest('form');if(!video||!form)return;form.querySelector('[name='+(name==='mark-start'?'start':'end')+']').value=Math.max(0,Number(video.currentTime)||0).toFixed(3);rememberForm(form);return; }
    if(name==='seek-note') { const video=$('director-video-'+id);if(video){delete video.dataset.previewEnd;video.currentTime=Math.max(0,Number(node.dataset.at)||0)/1000;video.focus();}return; }
    if(name==='preview-selection')return playRange(id);
    if(name==='preview-clip')return playRange(id,true);
    if(name==='move-clip') { rememberProposal();const index=state.proposal.edit.order.indexOf(id),next=index+Number(node.dataset.offset);if(index<0||next<0||next>=state.proposal.edit.order.length)return;[state.proposal.edit.order[index],state.proposal.edit.order[next]]=[state.proposal.edit.order[next],state.proposal.edit.order[index]];renderProposal();return; }
    if(name==='discard-proposal') { if(state.proposalDirty&&!confirm('Discard this unsaved first-cut proposal? Saved editable versions will remain.'))return;state.proposal=null;state.proposalDirty=false;state.drafts.delete('proposal');renderProposal();return; }
    if(name==='refresh-versions')return write(node,loadVersions);
}
async function submit(form) {
    const values=Object.fromEntries(new FormData(form)),kind=form.dataset.directorForm;
    if(kind==='requirement')return write(form,async()=>{const current=state.requirementEdit,body={projectId:state.projectId,title:values.title,purpose:values.purpose,minimumSelections:Number(values.minimumSelections),targetDurationSeconds:Number(values.targetDurationSeconds),required:values.required==='1',sortOrder:Number(values.sortOrder)-1,shotIds:[...form.querySelectorAll('[name=shotIds]:checked')].map(node=>node.value)};if(current?.id)Object.assign(body,{id:current.id,revision:current.revision});const result=await api('requirement.save',body);state.drafts.delete(form.dataset.formKey);state.requirementEdit=null;$('director-requirement-form').innerHTML='';applySnapshot(result);toast('Coverage requirement saved.');});
    if(kind==='selection')return write(form,async()=>{const take=state.snapshot.eligibleTakes.find(item=>item.id===form.dataset.takeId);if(!take)throw new Error('This take is no longer available.');const range=rangeMilliseconds(values.start,values.end,take.durationMs),body={projectId:state.projectId,requirementId:values.requirementId,takeId:take.id,label:values.label,notes:values.notes,preferred:values.preferred==='1',...range};if(values.id)Object.assign(body,{id:values.id,revision:Number(values.revision)});const result=await api('selection.save',body);state.drafts.delete(form.dataset.formKey);state.selectionEdits.delete(take.id);applySnapshot(result);toast('Usable section saved. It is available for the first-cut builder.');});
    if(kind==='cut') { if(hasEditorialDrafts())throw new Error('Save your requirement and usable-section changes before building a first cut.');if(state.proposalDirty&&!confirm('Build a new proposal and replace the unsaved first cut?'))return;return write(form,async()=>{const result=await api('cut.propose',{projectId:state.projectId,targetDurationSeconds:targetSeconds(values.preset,values.customTarget),cardMode:values.cardMode,fingerprint:state.snapshot.fingerprint});state.proposalDirty=false;state.drafts.delete(form.dataset.formKey);acceptProposal(result);toast('First-cut proposal ready. Review the selected clips before saving.');}); }
    if(kind==='save-draft'){const collected=collectProposal(form);return write(form,async()=>{if(hasEditorialDrafts())throw new Error('Save your requirement and usable-section changes before saving an editable version.');if(state.proposal.fingerprint!==state.snapshot.fingerprint)throw new Error('The production changed. Build a fresh proposal before saving this version.');if(!collected.title)throw new Error('Name this editable version.');const edit=validateDraftEdit(collected.edit,state.snapshot);const result=await editApi('timeline.create',{projectId:state.projectId,title:collected.title,targetDurationSeconds:Math.round((state.proposal.targetDurationMs||0)/1000),edit,directorFingerprint:state.proposal.fingerprint,proposalId:state.proposal.proposalId||null});const href=localUrl(result.editorUrl);if(!href)throw new Error('The draft was saved, but its editor link is unavailable. Refresh Saved editable versions.');state.proposalDirty=false;state.drafts.delete('proposal');state.busy=false;location.href=href;});}
}

async function start() {
    state.projectId=document.body.dataset.directorProject||'';state.csrf=document.body.dataset.directorCsrf||'';
    if(!/^[a-f0-9]{32}$/.test(state.projectId)){ $('director-loading').hidden=true;throw new Error('Open a production first, then choose Production Director.'); }
    state.tab=['coverage','footage','cut'].includes(location.hash.slice(1))?location.hash.slice(1):'coverage';
    const loaded=await loadProject();if(!loaded)return;
    ensureComposer();
    await loadVersions();
}

if(typeof document!=='undefined'&&document.body?.dataset.directorProject!==undefined){
    document.addEventListener('click',event=>{const tab=event.target.closest('[data-director-tab]');if(tab){event.preventDefault();showTab(tab.dataset.directorTab);return;}const node=event.target.closest('[data-director-action]');if(node){event.preventDefault();if(state.busy||node.disabled)return;Promise.resolve().then(()=>action(node)).catch(fail);return;}const anchor=event.target.closest('a[href]');if(anchor&&!anchor.target&&new URL(anchor.href,location.href).pathname!==location.pathname&&!mayNavigate())event.preventDefault();});
    document.addEventListener('submit',event=>{const form=event.target;if(!form.matches('[data-director-form]'))return;event.preventDefault();if(state.busy)return;Promise.resolve().then(()=>submit(form)).catch(fail);});
    document.addEventListener('input',event=>{const form=event.target.closest('[data-director-form]');if(!form)return;rememberForm(form);if(form.dataset.directorForm==='save-draft'){state.proposalDirty=true;try{$('director-proposal-duration').textContent=formatTime(proposalDuration(collectProposal(form).edit))+' including question cards';}catch{}}});
    document.addEventListener('change',event=>{const form=event.target.closest('[data-director-form]');if(form){rememberForm(form);if(form.dataset.directorForm==='save-draft')state.proposalDirty=true;}if(event.target.id==='director-target-preset')$('director-custom-target').hidden=event.target.value!=='custom';});
    $('director-refresh').addEventListener('click',()=>{if(state.busy)return;if(dirty()&&!confirm('Refresh this production? Your unsaved entries will be kept, but a first-cut proposal may become out of date.'))return;write($('director-refresh'),async()=>{await loadProject();await loadVersions();toast('Production refreshed.');}).catch(fail);});
    $('director-footage-search').addEventListener('input',event=>{captureVisibleDrafts();state.search=event.target.value;renderFootage();});
    $('director-footage-filter').addEventListener('change',event=>{captureVisibleDrafts();state.filter=event.target.value;renderFootage();});
    window.addEventListener('beforeunload',event=>{if(state.busy||dirty()||state.composer?.isBusy?.()){event.preventDefault();event.returnValue='';}});
    start().catch(fail);
}

export const directorTest={state,request,loadProject,applySnapshot,acceptProposal,write,collectProposal,renderProposal,renderFootage,renderRequirements,formDraft,rememberForm,restoreDrafts,showTab,submit,action};
