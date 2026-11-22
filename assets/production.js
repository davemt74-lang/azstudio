import { api, setCsrf } from './api.js';
import { QUESTIONS } from './questions.js';

const $ = id => document.getElementById(id);
const page = document.body.dataset.page;
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const uid = () => `question-${crypto.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`}`;
const statuses = { draft: 'Draft', review: 'Awaiting review', ready: 'Ready to post', published: 'Published' };
const config = {
  profiles: { singular: 'model', plural: 'models', endpoint: 'profiles', detail: 'profile', label: item => item.stageName },
  questionnaires: { singular: 'questionnaire', plural: 'questionnaires', endpoint: 'questionnaires', detail: 'questionnaire', label: item => item.name },
  videos: { singular: 'video', plural: 'videos', endpoint: 'videos', detail: 'video', label: item => item.title },
}[page];
let items = [], current = null, dirty = false, busy = false, limit = 12, photo = null, photoURL = '', bankLimit = 12;
let interviewFilter = new URLSearchParams(location.search).get('interviewId') || '';
const selectedBank = new Set();

function on(id, event, handler) {
  $(id)?.addEventListener(event, e => Promise.resolve().then(() => handler(e)).catch(fail));
}
function toast(message, error = false) {
  const node = $('toast'); if (!node) return;
  node.textContent = message; node.className = `toast visible${error ? ' is-error' : ''}`;
  clearTimeout(toast.timer); toast.timer = setTimeout(() => node.classList.remove('visible'), error ? 12000 : 5000);
}
function fail(error) { console.error(error); toast(error.message || String(error), true); }
function value(id, data = '') { if ($(id)) $(id).value = data ?? ''; }
function text(id, data = '') { if ($(id)) $(id).textContent = data; }
function show(id, visible) { if ($(id)) $(id).hidden = !visible; }
function markDirty() { dirty = true; text('production-save-state', 'Unsaved changes'); }
function saved() { dirty = false; text('production-save-state', 'Saved'); }
function canLeave() { return !busy && (!dirty || confirm('Discard the unsaved changes on this page?')); }
function initials(name) { return String(name || '?').trim().split(/\s+/).slice(0, 2).map(p => p[0]).join('').toUpperCase(); }
function date(value) { const d = new Date(value); return Number.isNaN(d.valueOf()) ? '' : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); }
function time(value) { const n = Math.max(0, Math.round(Number(value || 0) / 1000)); return `${Math.floor(n / 60)}:${String(n % 60).padStart(2, '0')}`; }
function size(value) { const n = Number(value) || 0; return n >= 1073741824 ? `${(n / 1073741824).toFixed(1)} GB` : `${(n / 1048576).toFixed(1)} MB`; }
function archived(item) { return page === 'videos' ? Boolean(item.archived) : item.status === 'archived'; }
function safeURL(raw, external = false) {
  if (typeof raw !== 'string' || !raw.trim()) return '';
  try { const url = new URL(raw, location.href); return ['http:', 'https:'].includes(url.protocol) && (external || url.origin === location.origin) ? url.href : ''; } catch { return ''; }
}
function setLocation(id = '') {
  const url = new URL(location.href); if (id) url.searchParams.set('id', id); else url.searchParams.delete('id');
  url.searchParams.delete('new'); history.replaceState(null, '', url);
}
function replaceItem(item) {
  const index = items.findIndex(row => row.id === item.id);
  if (index >= 0) items[index] = item; else items.unshift(item);
  renderList();
}
function resetPhoto() { photo = null; if (photoURL) URL.revokeObjectURL(photoURL); photoURL = ''; if ($('profile-photo')) $('profile-photo').value = ''; text('profile-photo-status', ''); }
async function lockForm(form, operation) {
  if (busy) return;
  busy = true;
  const controls = [...(form || $('production-detail')).querySelectorAll('input,textarea,select,button')];
  const previous = controls.map(node => node.disabled); controls.forEach(node => { node.disabled = true; });
  text('production-save-state', 'Saving…');
  let completed = false;
  try { const result = await operation(); completed = true; return result; }
  finally {
    controls.forEach((node, index) => { node.disabled = previous[index]; }); busy = false;
    text('production-save-state', dirty ? 'Unsaved changes' : completed ? 'Saved' : 'Save failed');
    if (page === 'questionnaires' && $('bank-add-selected')) $('bank-add-selected').disabled = !selectedBank.size;
    if (page === 'videos') updateVideoControls();
  }
}

async function loadList() {
  $('production-list').setAttribute('aria-busy', 'true');
  show('production-error', false);
  try {
    items = await api(config.endpoint);
    if (!Array.isArray(items)) throw new Error('The server returned an unexpected list. Reload after checking the update files.');
    renderList();
  } catch (error) {
    text('production-error-text', error.message); show('production-error', true);
    text('production-count', 'The list could not be loaded.'); throw error;
  } finally { $('production-list').setAttribute('aria-busy', 'false'); }
}

function renderList() {
  const query = $('production-search').value.trim().toLocaleLowerCase();
  const filter = $('production-archive').value;
  const status = $('production-status')?.value || 'all';
  const list = items.filter(item => {
    if (filter !== 'all' && archived(item) !== (filter === 'archived')) return false;
    if (page === 'videos' && status !== 'all' && item.status !== status) return false;
    if (page === 'videos' && interviewFilter && item.interviewId !== interviewFilter) return false;
    return !query || [config.label(item), item.description, item.publicBio, item.interests, item.participantName, item.interviewTitle, item.socialHandles].filter(Boolean).join(' ').toLocaleLowerCase().includes(query);
  });
  text('production-count', `${list.length} ${list.length === 1 ? config.singular : config.plural}${interviewFilter && page === 'videos' ? ' from this interview' : ''}`);
  show('clear-interview-filter', Boolean(interviewFilter));
  $('production-list').innerHTML = list.slice(0, limit).map(renderCard).join('');
  show('production-empty', list.length === 0); show('production-more', list.length > limit);
  const filtered = query || filter !== 'active' || status !== 'all' || interviewFilter;
  text('production-empty-title', filtered ? `No matching ${config.plural}` : page === 'profiles' ? 'Meet your first model' : page === 'questionnaires' ? 'Give the conversation a shape' : 'Your finished cuts belong here');
  text('production-empty-text', filtered ? 'Try a different search or filter.' : page === 'profiles' ? 'Add a model profile, then connect interviews and production notes as you go.' : page === 'questionnaires' ? 'Create a reusable set using your own questions or the included AZHottie starter bank.' : 'Export a video in the editor, then choose Save finished video to add it to this library.');
}

function renderCard(item) {
  const id = esc(item.id), label = esc(config.label(item));
  const open = `<button type="button" class="button button--primary" data-prod-action="open" data-id="${id}">${page === 'videos' ? 'Watch & edit details' : page === 'profiles' ? 'Open profile' : 'Edit questionnaire'}</button>`;
  const archiveBadge = archived(item) ? '<span class="badge">Archived</span>' : '';
  if (page === 'profiles') {
    const photoSrc = item.photoUrl ? safeURL(item.photoUrl) : '';
    return `<article class="production-card"><div class="production-card-header"><div class="profile-portrait">${photoSrc ? `<img src="${esc(photoSrc)}" alt="${label}" loading="lazy">` : esc(initials(item.stageName))}</div><div><h2>${label}</h2>${archiveBadge}<p class="card-date">Updated ${esc(date(item.updatedAt))}</p></div></div><p class="production-card-description">${esc(item.interests || item.publicBio || 'Add interests and a short bio to this profile.')}</p><div class="production-card-meta"><span>${Number(item.interviewCount || 0)} interviews</span>${item.availability ? '<span>Availability noted</span>' : ''}</div><div class="production-card-actions">${open}${!archived(item) ? `<a class="button" href="library.php?new=1&amp;modelId=${id}">New interview</a>` : ''}</div></article>`;
  }
  if (page === 'questionnaires') {
    return `<article class="production-card"><div class="production-card-header"><div><h2>${label}</h2>${archiveBadge}<p class="card-date">Updated ${esc(date(item.updatedAt))}</p></div></div><p class="production-card-description">${esc(item.description || 'A reusable AZHottie interview question set.')}</p><div class="production-card-meta"><span>${Number(item.questionCount ?? item.questions?.length ?? 0)} questions</span><span>Revision ${Number(item.revision || 1)}</span></div><div class="production-card-actions">${open}${!archived(item) ? `<a class="button" href="library.php?new=1&amp;questionnaireId=${id}">Use set</a>` : ''}</div></article>`;
  }
  const cover = safeURL(item.coverUrl);
  return `<article class="production-card"><button type="button" class="video-card-art${cover ? ' has-cover' : ''}" data-prod-action="open" data-id="${id}" aria-label="Play ${label}">${cover ? `<img src="${esc(cover)}" alt="" loading="lazy">` : ''}<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m9 5 11 7-11 7z"/></svg><span>AZHOTTIE</span><small>${time(item.durationMs)}</small></button><h2>${label}</h2><p class="card-date">${esc(item.participantName || item.interviewTitle || 'Interview export')} · Version ${Number(item.version || 1)}</p><div><span class="badge ${item.status === 'ready' || item.status === 'published' ? 'badge--success' : ''}">${esc(statuses[item.status] || item.status)}</span> ${item.fanPublished ? '<span class="badge badge--success">On Watch</span>' : ''} ${archiveBadge}</div><p class="production-card-description">${esc(item.description || '')}</p><div class="production-card-meta"><span>${esc(date(item.createdAt))}</span><span>${size(item.sizeBytes)}</span></div><div class="production-card-actions">${open}</div></article>`;
}

function revealDetail() {
  show('production-list-view', false); show('production-detail', true); show('production-new', false);
  show('production-error', false); text('production-save-state', '');
}
function showList() {
  if (!canLeave()) return;
  $('finished-player')?.pause(); resetPhoto(); current = null; dirty = false;
  show('production-list-view', true); show('production-detail', false); show('production-new', page !== 'videos');
  setLocation(); renderList();
}
async function openDetail(id, force = false) {
  if (!force && !canLeave()) return;
  const item = await api(config.detail, null, { id });
  $('finished-player')?.pause(); resetPhoto(); current = item; dirty = false;
  revealDetail(); setLocation(item.id); populate();
  $('production-detail').scrollIntoView({ block: 'start', behavior: 'smooth' });
}
function newItem(copy = null) {
  if (!canLeave()) return;
  resetPhoto(); current = page === 'profiles' ? { stageName: '', publicBio: '', socialHandles: '', interests: '', availability: '', privateNotes: '', status: 'active', interviews: [] } : { name: '', description: '', status: 'active', questions: [] };
  if (copy) current = { ...current, name: `${copy.name} (copy)`.slice(0, 200), description: copy.description || '', questions: (copy.questions || []).map(q => ({ ...structuredClone(q), id: uid() })) };
  dirty = false; selectedBank.clear(); bankLimit = 12; revealDetail(); setLocation(); populate();
  if (copy) markDirty();
  $(page === 'profiles' ? 'profile-stage-name' : 'questionnaire-name').focus();
}
function populate() {
  if (page === 'profiles') populateProfile();
  else if (page === 'questionnaires') populateQuestionnaire();
  else populateVideo();
}

function populateProfile() {
  text('profile-heading', current.id ? current.stageName : 'New model');
  text('profile-state', current.status === 'archived' ? 'Archived' : 'Active'); show('profile-state', Boolean(current.id));
  const fields = { 'profile-stage-name': 'stageName', 'profile-public-bio': 'publicBio', 'profile-social': 'socialHandles', 'profile-interests': 'interests', 'profile-availability': 'availability', 'profile-private-notes': 'privateNotes' };
  Object.entries(fields).forEach(([id, key]) => value(id, current[key]));
  paintPhoto();
  show('profile-archive', Boolean(current.id)); show('profile-delete', Boolean(current.id));
  text('profile-archive', current.status === 'archived' ? 'Restore model' : 'Archive model');
  show('profile-new-interview', Boolean(current.id) && current.status !== 'archived');
  if (current.id) $('profile-new-interview').href = `library.php?new=1&modelId=${encodeURIComponent(current.id)}`;
  const history = current.interviews || [];
  text('profile-history-intro', current.id ? history.length ? `${history.length} linked ${history.length === 1 ? 'interview' : 'interviews'}.` : 'No linked interviews yet.' : 'Save this model to link a new interview.');
  $('profile-interviews').innerHTML = history.map(item => `<a class="production-history-item" href="interview.php?id=${esc(item.id)}"><strong>${esc(item.title)}</strong><span>${esc(date(item.updatedAt))} · ${item.status === 'archived' ? 'Archived' : 'Active'}${item.takeCount != null ? ` · ${Number(item.takeCount)} takes` : ''}</span></a>`).join('');
}
function paintPhoto() {
  const src = photoURL || (current?.photoUrl ? safeURL(current.photoUrl) : '');
  $('profile-photo-preview').innerHTML = src ? `<img src="${esc(src)}" alt="Model profile photo">` : esc(initials($('profile-stage-name').value || current?.stageName));
}
async function saveProfile(event) {
  event.preventDefault();
  if (!$('profile-form').reportValidity()) return;
  const data = Object.fromEntries(new FormData($('profile-form')));
  if (current.id) { data.id = current.id; if (current.revision) data.revision = current.revision; }
  await lockForm($('profile-form'), async () => {
    const savedProfile = await api('saveProfile', data);
    current = { ...savedProfile, interviews: savedProfile.interviews || current.interviews || [] };
    setLocation(current.id); replaceItem(current);
    if (photo) {
      const body = new FormData(); body.set('id', current.id); body.append('photo', photo, photo.name);
      try { current = { ...current, ...await api('uploadProfilePhoto', body) }; }
      catch (error) { markDirty(); throw new Error(`Model details saved. The photo could not be uploaded: ${error.message}`); }
    }
    resetPhoto(); replaceItem(current); populateProfile(); saved(); toast('Model profile saved.');
  });
}

function normalizeQuestion(question) {
  const choices = question.choices || question.options || [];
  return { id: question.id || uid(), category: question.category || 'General', text: question.text || '', type: question.type === 'open' || question.kind === 'open' || !choices.length ? 'open' : 'choice', choices: [...choices], private: Boolean(question.private) };
}
function populateQuestionnaire() {
  current.questions = (current.questions || []).map(normalizeQuestion);
  text('questionnaire-heading', current.id ? current.name : 'New questionnaire');
  value('questionnaire-name', current.name); value('questionnaire-description', current.description);
  text('questionnaire-revision', `${current.status === 'archived' ? 'Archived · ' : ''}Revision ${current.revision || 1}`); show('questionnaire-revision', Boolean(current.id));
  ['questionnaire-archive', 'questionnaire-delete', 'questionnaire-duplicate'].forEach(id => show(id, Boolean(current.id)));
  show('questionnaire-start', Boolean(current.id) && current.status !== 'archived');
  if (current.id) $('questionnaire-start').href = `library.php?new=1&questionnaireId=${encodeURIComponent(current.id)}`;
  text('questionnaire-archive', current.status === 'archived' ? 'Restore set' : 'Archive');
  renderQuestions(); renderBank();
}
function renderQuestions(focusId = '') {
  text('questionnaire-question-count', `(${current.questions.length})`);
  show('questionnaire-no-questions', !current.questions.length);
  $('questionnaire-questions').innerHTML = current.questions.map((q, index) => `<section class="question-editor" data-question-id="${esc(q.id)}"><div class="question-editor-head"><strong>Question ${index + 1}</strong><div class="question-editor-actions"><button type="button" class="button button--small" data-question-action="up" ${index === 0 ? 'disabled' : ''} aria-label="Move question ${index + 1} earlier">Move up</button><button type="button" class="button button--small" data-question-action="down" ${index === current.questions.length - 1 ? 'disabled' : ''} aria-label="Move question ${index + 1} later">Move down</button><button type="button" class="text-button danger" data-question-action="delete" aria-label="Delete question ${index + 1}">Delete</button></div></div><div class="question-editor-fields"><label class="field">Question text<textarea data-question-field="text" rows="2" maxlength="4000" required>${esc(q.text)}</textarea></label><div class="two-columns"><label class="field">Answer type<select data-question-field="type"><option value="choice" ${q.type === 'choice' ? 'selected' : ''}>Multiple choice</option><option value="open" ${q.type === 'open' ? 'selected' : ''}>Open answer</option></select></label><label class="field">Category<input data-question-field="category" value="${esc(q.category)}" maxlength="120" required></label></div><label class="field" data-choices-field ${q.type !== 'choice' ? 'hidden' : ''}>Answer choices<textarea data-question-field="choices" rows="4" maxlength="16000" ${q.type === 'choice' ? 'required' : ''}>${esc(q.choices.join('\n'))}</textarea><span class="field-help">One choice per line. Include at least two choices.</span></label><label class="checkbox-field"><input type="checkbox" data-question-field="private" ${q.private ? 'checked' : ''}><span>Private casting question <span class="field-help">— excluded from the automatic edit by default.</span></span></label></div></section>`).join('');
  if (focusId) [...$('questionnaire-questions').querySelectorAll('[data-question-id]')].find(node => node.dataset.questionId === focusId)?.querySelector('textarea')?.focus();
}
function addQuestion() {
  if (busy) return;
  if (current.questions.length >= 200) { toast('A questionnaire can contain up to 200 questions.', true); return; }
  const question = { id: uid(), category: 'General', text: '', type: 'open', choices: [], private: false };
  current.questions.push(question); markDirty(); renderQuestions(question.id);
}
function editQuestion(event) {
  const control = event.target.closest('[data-question-field]'); if (!control) return;
  const row = control.closest('[data-question-id]');
  const question = current.questions.find(q => q.id === row.dataset.questionId); if (!question) return;
  const field = control.dataset.questionField;
  question[field] = field === 'private' ? control.checked : field === 'choices' ? control.value.split('\n') : control.value;
  if (field === 'type') {
    row.querySelector('[data-choices-field]').hidden = control.value !== 'choice';
    row.querySelector('[data-question-field="choices"]').required = control.value === 'choice';
  }
  markDirty();
}
function moveQuestion(event) {
  const button = event.target.closest('[data-question-action]'); if (!button || busy) return;
  const index = current.questions.findIndex(q => q.id === button.closest('[data-question-id]').dataset.questionId); if (index < 0) return;
  const action = button.dataset.questionAction;
  if (action === 'delete') {
    if ((current.questions[index].text || current.questions[index].choices.some(Boolean)) && !confirm('Remove this question from the questionnaire draft? Existing interviews keep their saved copy.')) return;
    current.questions.splice(index, 1);
  } else {
    const next = index + (action === 'up' ? -1 : 1); if (next < 0 || next >= current.questions.length) return;
    [current.questions[index], current.questions[next]] = [current.questions[next], current.questions[index]];
  }
  markDirty(); renderQuestions();
}
function renderBank() {
  const category = $('bank-category').value;
  const bank = QUESTIONS.filter(q => category === 'all' || q.category === category);
  $('bank-list').innerHTML = bank.slice(0, bankLimit).map(q => `<label class="bank-choice"><input type="checkbox" value="${esc(q.id)}" ${selectedBank.has(q.id) ? 'checked' : ''}><span><small>${esc(q.category)}${q.private ? ' · Private casting' : ''}</small>${esc(q.text)}</span></label>`).join('');
  show('bank-more', bank.length > bankLimit); text('bank-selected-count', `${selectedBank.size} selected`);
  $('bank-add-selected').disabled = !selectedBank.size;
}
function importQuestions(all = false) {
  if (busy) return;
  const source = all ? QUESTIONS : QUESTIONS.filter(q => selectedBank.has(q.id));
  if (!source.length) return;
  if (current.questions.length + source.length > 200) { toast('This would exceed 200 questions. Select fewer prompts or use a separate questionnaire.', true); return; }
  if (all && current.questions.length && !confirm(`Append all ${QUESTIONS.length} starter questions to this set?`)) return;
  current.questions.push(...source.map(q => ({ ...normalizeQuestion(q), id: uid() })));
  selectedBank.clear(); markDirty(); renderQuestions(); renderBank(); toast(`${source.length} questions added. Save the questionnaire to keep them.`);
}
async function saveQuestionnaire(event) {
  event.preventDefault();
  if (!$('questionnaire-form').reportValidity()) return;
  if (!current.questions.length) { toast('Add at least one question before saving.', true); return; }
  const questions = current.questions.map(q => ({ ...q, text: q.text.trim(), category: q.category.trim(), choices: q.type === 'choice' ? q.choices.map(choice => choice.trim()).filter(Boolean) : [] }));
  const invalid = questions.findIndex(q => !q.text || !q.category || (q.type === 'choice' && (q.choices.length < 2 || q.choices.length > 12 || q.choices.some(choice => choice.length > 2000))));
  if (invalid >= 0) { toast(`Check question ${invalid + 1}. Use 2–12 answer choices, with no more than 2,000 characters per choice.`, true); return; }
  const data = { name: $('questionnaire-name').value.trim(), description: $('questionnaire-description').value, questions };
  if (current.id) { data.id = current.id; data.revision = current.revision; }
  await lockForm($('questionnaire-form'), async () => {
    current = await api('saveQuestionnaire', data); replaceItem(current); setLocation(current.id); populateQuestionnaire(); saved(); toast('Questionnaire saved. Existing interviews keep their original questions.');
  });
}

function populateVideo() {
  const media = safeURL(current.mediaUrl), download = safeURL(current.downloadUrl || current.mediaUrl);
  text('video-heading', current.title); text('video-version', `Version ${current.version || 1}${current.archived ? ' · Archived' : ''}`);
  text('video-meta', [current.participantName, current.interviewTitle, date(current.createdAt), time(current.durationMs), size(current.sizeBytes)].filter(Boolean).join(' · '));
  const player = $('finished-player');
  if (media && player.getAttribute('src') !== media) { player.src = media; player.load(); }
  const cover = safeURL(current.coverUrl);
  if (cover) player.poster = cover; else player.removeAttribute('poster');
  $('video-cover-preview').innerHTML = cover ? `<img src="${esc(cover)}" alt="Cover for ${esc(current.title)}">` : '<span>No cover chosen</span>';
  text('video-cover-status', cover ? 'Cover saved. It appears in the library and on this video’s public post.' : 'Upload an image or pause the video on a frame you like, then capture it.');
  show('video-cover-remove', Boolean(cover));
  $('video-download').href = download;
  $('video-source').href = current.projectUrl ? safeURL(current.projectUrl) : `interview.php?id=${encodeURIComponent(current.interviewId)}`;
  text('video-source', current.projectId ? 'Source production' : 'Source interview');
  text('video-editor', current.projectId ? 'Open production editor' : 'Open interview editor');
  $('video-editor').href = current.editorUrl || `editor.php?id=${encodeURIComponent(current.interviewId)}`;
  value('video-title', current.title); value('video-description', current.description); value('video-status', current.status || 'draft'); value('video-published-url', current.publishedUrl); value('video-credit', current.participantName || '');
  const link = current.publishedUrl ? safeURL(current.publishedUrl, true) : '';
  show('video-published-link', Boolean(link)); if (link) $('video-published-link').href = link;
  text('video-archive', current.archived ? 'Restore video' : 'Archive video');
  text('video-fan-status', current.fanPublished ? 'Published on the public fan feed.' : 'Private finished cut. This video is not on the fan feed.');
  text('video-fan-publish', current.fanPublished ? 'Update fan post' : 'Publish to fan feed');
  show('video-fan-unpublish', Boolean(current.fanPublished));
  show('video-fan-link', Boolean(current.fanWatchUrl));
  if (current.fanWatchUrl) $('video-fan-link').href = safeURL(current.fanWatchUrl);
  updateVideoControls();
}
function updateVideoControls() {
  if (!current || page !== 'videos') return;
  const player = $('finished-player');
  $('video-fan-publish').disabled = busy || Boolean(current.archived);
  $('video-cover-capture').disabled = busy || !player || player.readyState < 2 || !player.videoWidth || player.seeking;
}
function checkCoverEdit() {
  if (dirty) throw new Error('Save the video details before changing its cover.');
}
async function persistCover(blob, name) {
  const body = new FormData(); body.set('id', current.id); body.set('revision', current.revision); body.append('cover', blob, name);
  current = await api('uploadVideoCover', body); replaceItem(current); populateVideo(); saved();
}
async function uploadCover() {
  const input = $('video-cover-upload'), file = input.files?.[0];
  if (!file || busy || !current?.id) return;
  try {
    checkCoverEdit();
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) throw new Error('Choose a JPG, PNG, or WebP cover up to 5 MB.');
    await lockForm(null, async () => { await persistCover(file, file.name); toast('Cover image saved.'); });
  } finally { input.value = ''; }
}
async function captureCover() {
  if (busy || !current?.id) return;
  checkCoverEdit();
  const player = $('finished-player');
  if (player.readyState < 2 || !player.videoWidth || player.seeking) throw new Error('Wait for the video frame to load before capturing it.');
  player.pause();
  await lockForm(null, async () => {
    const frameTime = player.currentTime;
    const canvas = document.createElement('canvas'), scale = Math.min(1, 1920 / Math.max(player.videoWidth, player.videoHeight));
    canvas.width = Math.max(1, Math.round(player.videoWidth * scale)); canvas.height = Math.max(1, Math.round(player.videoHeight * scale));
    const context = canvas.getContext('2d');
    if (!context) throw new Error('This browser could not capture a video frame. Upload a cover image instead.');
    context.drawImage(player, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.88));
    if (!blob) throw new Error('The frame could not be saved as an image. Try another frame.');
    await persistCover(blob, 'video-frame-cover.jpg');
    toast(`Cover captured at ${time(frameTime * 1000)}.`);
  });
}
async function removeCover() {
  if (busy || !current?.id) return;
  checkCoverEdit();
  await lockForm(null, async () => {
    current = await api('removeVideoCover', { id: current.id, revision: current.revision });
    replaceItem(current); populateVideo(); saved(); toast('Cover image removed.');
  });
}
async function publishVideo(published) {
  if (busy || !current?.id) return;
  if (dirty) throw new Error('Save the video details before changing its fan post.');
  await lockForm(null, async () => {
    current = await api('publishVideo', { id: current.id, revision: current.revision, published });
    replaceItem(current); populateVideo(); saved();
    toast(published ? 'The finished video is published on the fan feed.' : 'The video has been removed from the fan feed.');
  });
}
async function saveVideo(event) {
  event.preventDefault();
  if (!$('video-form').reportValidity()) return;
  const data = { id: current.id, revision: current.revision, ...Object.fromEntries(new FormData($('video-form'))) };
  await lockForm($('video-form'), async () => { current = await api('updateVideo', data); replaceItem(current); populateVideo(); saved(); toast('Video details saved.'); });
}

async function archiveCurrent() {
  if (!current?.id || !canLeave()) return;
  const action = page === 'profiles' ? 'archiveProfile' : page === 'questionnaires' ? 'archiveQuestionnaire' : 'archiveVideo';
  const wasArchived = archived(current);
  await lockForm(null, async () => {
    const data = page === 'videos' ? { id: current.id, archived: !wasArchived } : { id: current.id, status: wasArchived ? 'active' : 'archived' };
    const result = await api(action, data); current = { ...current, ...result }; replaceItem(current); populate(); saved(); toast(`${config.singular[0].toUpperCase() + config.singular.slice(1)} ${wasArchived ? 'restored' : 'archived'}.`);
  });
}
async function deleteCurrent() {
  if (!current?.id || busy) return;
  const consequence = page === 'profiles' ? 'The model profile and photo will be removed. Linked interviews and their recordings will remain.' : page === 'questionnaires' ? 'This reusable set will be removed. Existing interviews will keep their saved questions.' : 'This finished video file will be permanently removed. The source interview and original takes will remain.';
  if (!confirm(`Delete “${config.label(current)}”? ${consequence}`)) return;
  const action = page === 'profiles' ? 'deleteProfile' : page === 'questionnaires' ? 'deleteQuestionnaire' : 'deleteVideo';
  await lockForm(null, async () => { await api(action, { id: current.id }); items = items.filter(item => item.id !== current.id); dirty = false; });
  showList(); toast(`${config.singular[0].toUpperCase() + config.singular.slice(1)} deleted.`);
}

function bind() {
  on('production-search', 'input', () => { limit = 12; renderList(); });
  on('production-archive', 'change', () => { limit = 12; renderList(); });
  on('production-status', 'change', () => { limit = 12; renderList(); });
  on('production-more', 'click', () => { limit += 12; renderList(); });
  on('production-back', 'click', showList); on('production-new', 'click', () => newItem());
  on('production-retry', 'click', loadList);
  on('production-list', 'click', event => { const button = event.target.closest('[data-prod-action="open"]'); if (button) return openDetail(button.dataset.id); });
  on('clear-interview-filter', 'click', () => { interviewFilter = ''; const url = new URL(location.href); url.searchParams.delete('interviewId'); history.replaceState(null, '', url); renderList(); });
  document.querySelectorAll('#production-detail form').forEach(form => form.addEventListener('input', event => {
    if (page !== 'questionnaires' || ['questionnaire-name', 'questionnaire-description'].includes(event.target.id) || event.target.closest('[data-question-field]')) markDirty();
  }));
  window.addEventListener('beforeunload', event => { if (dirty || busy) { event.preventDefault(); event.returnValue = ''; } });
  if (page === 'profiles') {
    on('profile-form', 'submit', saveProfile); on('profile-stage-name', 'input', paintPhoto);
    on('profile-photo', 'change', () => {
      const file = $('profile-photo').files?.[0]; if (!file) return;
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) { $('profile-photo').value = ''; throw new Error('Choose a JPG, PNG, or WebP image up to 5 MB.'); }
      if (photoURL) URL.revokeObjectURL(photoURL); photo = file; photoURL = URL.createObjectURL(file); paintPhoto(); text('profile-photo-status', `${file.name} · uploads when you save`); markDirty();
    });
    on('profile-archive', 'click', archiveCurrent); on('profile-delete', 'click', deleteCurrent);
  } else if (page === 'questionnaires') {
    const categories = [...new Set(QUESTIONS.map(q => q.category))];
    $('bank-category').innerHTML = '<option value="all">All categories</option>' + categories.map(category => `<option>${esc(category)}</option>`).join('');
    text('bank-add-all', `Copy all ${QUESTIONS.length} starter questions`);
    on('questionnaire-form', 'submit', saveQuestionnaire);
    on('questionnaire-add', 'click', addQuestion); on('questionnaire-add-bottom', 'click', addQuestion);
    on('questionnaire-questions', 'input', editQuestion); on('questionnaire-questions', 'change', editQuestion); on('questionnaire-questions', 'click', moveQuestion);
    on('bank-category', 'change', () => { bankLimit = 12; renderBank(); });
    on('bank-more', 'click', () => { bankLimit += 12; renderBank(); });
    on('bank-list', 'change', event => { if (event.target.type !== 'checkbox') return; if (event.target.checked) selectedBank.add(event.target.value); else selectedBank.delete(event.target.value); text('bank-selected-count', `${selectedBank.size} selected`); $('bank-add-selected').disabled = !selectedBank.size; });
    on('bank-add-selected', 'click', () => importQuestions()); on('bank-add-all', 'click', () => importQuestions(true));
    on('questionnaire-archive', 'click', archiveCurrent); on('questionnaire-delete', 'click', deleteCurrent);
    on('questionnaire-duplicate', 'click', () => { if (!canLeave()) return; const copy = structuredClone(current); copy.name = $('questionnaire-name').value || copy.name; copy.description = $('questionnaire-description').value; dirty = false; newItem(copy); });
  } else {
    on('video-form', 'submit', saveVideo); on('video-archive', 'click', archiveCurrent); on('video-delete', 'click', deleteCurrent);
    on('video-fan-publish', 'click', () => publishVideo(true)); on('video-fan-unpublish', 'click', () => publishVideo(false));
    on('video-cover-upload', 'change', uploadCover); on('video-cover-capture', 'click', captureCover); on('video-cover-remove', 'click', removeCover);
    ['loadeddata', 'seeked', 'seeking', 'emptied'].forEach(event => on('finished-player', event, updateVideoControls));
    on('finished-player', 'error', () => { if ($('finished-player').getAttribute('src')) toast('This video could not be played here. Try downloading the original finished file, or check that your sign-in is still active.', true); });
  }
}

async function init() {
  bind();
  try {
    const boot = await api('bootstrap'); setCsrf(boot.csrf); await loadList();
    const query = new URLSearchParams(location.search);
    if (query.get('id')) await openDetail(query.get('id'), true);
    else if (query.get('new') === '1' && page !== 'videos') newItem();
  } catch (error) { fail(error); text('production-error-text', error.message); show('production-error', true); }
}
init();
