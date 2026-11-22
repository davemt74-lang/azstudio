import { QUESTIONS } from './questions.js';
import { api, setCsrf, uploadVideo } from './api.js';
import { InterviewCapture } from './capture.js';
import { InterviewRenderer, buildTimeline, getVideoMetadata } from './media-engine.js';
import { pendingPut, pendingList, pendingDelete } from './pending.js';
import { editVersionRequest, editRequestToken, mountEditableVersions } from './edit-versions.js';

const $ = id => document.getElementById(id);
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const clock = seconds => { const n = Math.max(0, Math.round(Number(seconds) || 0)); return `${Math.floor(n / 60).toString().padStart(2, '0')}:${(n % 60).toString().padStart(2, '0')}`; };
const date = value => { try { return new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); } catch { return ''; } };
const uid = () => crypto.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
const page = document.body.dataset.page;
let boot, interview, chosen, capture, renderer, dirty = false, saveTimer, saving = Promise.resolve(), saveRequests = 0, busy = false, editBusy = false, exportURL, limit = 12, currentTrim, currentReview, latestExport, drafts = new Map(), availableCameras = [], reviewSaving = Promise.resolve(), editCancelled = false;
let editableVersions, timelineRecoveryRequest = null;
const settingsDefault = { cardDuration: 3, fadeDuration: 0.45, aspect: '16:9', resolution: 720, fit: 'contain', brand: 'AZHOTTIE', modelName: '' };

function toast(message, error = false) {
  const node = $('toast'); if (!node) return;
  node.textContent = message; node.className = `toast visible${error ? ' is-error' : ''}`;
  clearTimeout(toast.timeout); toast.timeout = setTimeout(() => node.classList.remove('visible'), error ? 10000 : 4500);
}
function fail(error) { console.error(error); toast(error.message || String(error), true); }
function on(id, event, fn) { $(id)?.addEventListener(event, e => { try { Promise.resolve(fn(e)).catch(fail); } catch (error) { fail(error); } }); }
function text(id, value) { if ($(id)) $(id).textContent = value; }
function download(blob, name) { const a = document.createElement('a'); const url = URL.createObjectURL(blob); a.href = url; a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(url), 60000); }
function slug(value) { return String(value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'interview'; }
function questionForUI(q, index = 0) {
  return { ...q, number: q.number || index + 1, kind: q.type === 'choice' ? 'multiple' : q.type === 'open' ? 'open' : q.kind, options: q.choices || q.options || [], mode: q.mode || 'Choose one' };
}
function questions() {
  const snapshot = interview?.questionnaireSnapshot;
  const base = Array.isArray(snapshot?.questions) ? snapshot.questions : QUESTIONS;
  const all = [...base, ...(interview?.production ? [] : (interview?.state?.customQuestions || []))];
  return all.filter((q, i) => all.findIndex(item => item.id === q.id) === i).map(questionForUI);
}
function getQuestion(id) { return questions().find(q => q.id === id); }

function normalize() {
  interview.state ||= {};
  interview.state.responses ||= {};
  interview.state.customQuestions ||= [];
  interview.state.edit ||= {};
  const edit = interview.state.edit;
  let removedPrivate = false;
  edit.order ||= []; edit.segments ||= {}; edit.settings = { ...settingsDefault, ...edit.settings, modelName: interview.participantName || '' };
  interview.takes ||= [];
  const known = new Set(interview.takes.map(t => t.id));
  edit.order = edit.order.filter(id => known.has(id));
  const latest = new Map();
  for (const take of interview.takes) {
    const old = latest.get(take.questionId);
    if (!old || String(take.createdAt) >= String(old.createdAt)) latest.set(take.questionId, take);
  }
  for (const take of interview.takes) {
    if (interview.timeline && Number(take.durationMs) < 100 && !edit.segments[take.id]) continue;
    if (!edit.order.includes(take.id)) edit.order.push(take.id);
    edit.segments[take.id] ||= { included: !interview.timeline && !take.isPrivate && take.reviewStatus !== 'private' && (Boolean(interview.production) || latest.get(take.questionId)?.id === take.id), title: take.questionText || getQuestion(take.questionId)?.text || take.label || 'Interview answer', trimStart: (Number(take.answerStartMs) || 0) / 1000, trimEnd: Number(take.durationMs) / 1000 };
    if (typeof edit.segments[take.id].showCard !== 'boolean') edit.segments[take.id].showCard = !interview.production || interview.production.kind === 'interview' || take.sourceKind === 'interview';
  }
  for (const take of interview.takes) if (take.reviewStatus === 'private' && edit.segments[take.id]?.included) { edit.segments[take.id].included = false; removedPrivate = true; }
  for (const id of Object.keys(edit.segments)) if (!known.has(id)) delete edit.segments[id];
  return removedPrivate;
}
function markDirty() {
  dirty = true; text('save-state', 'Unsaved changes'); clearTimeout(saveTimer);
  if (interview?.timeline && (interview.timeline.historical || interview.timeline.status !== 'active')) { text('save-state', 'Save as a new cut to keep these changes'); return; }
  saveTimer = setTimeout(() => saveInterview().catch(fail), 800);
}
function saveInterview(showToast = false) {
  if (!interview) return Promise.resolve();
  if (page === 'editor' && interview.timeline) return saveNamedTimeline(showToast);
  clearTimeout(saveTimer);
  const payload = { id: interview.id, title: interview.title, participantName: interview.participantName, ...(interview.production ? {} : {modelId: interview.modelId || null}), notes: interview.notes || '', state: structuredClone(interview.state) };
  dirty = false; text('save-state', 'Saving…');
  saveRequests++;
  saving = saving.catch(() => {}).then(async () => {
    try {
      const saved = await api('updateInterview', { ...payload, revision: interview.revision });
      interview.updatedAt = saved.updatedAt || interview.updatedAt;
      interview.revision = saved.revision || interview.revision;
      text('save-state', dirty ? 'Unsaved changes' : saveRequests > 1 ? 'Saving…' : 'All changes saved');
      if (showToast) toast('Interview saved.');
      return saved;
    } catch (error) { dirty = true; text('save-state', 'Save failed — retry'); throw error; }
    finally { saveRequests--; }
  });
  return saving;
}
function saveNamedTimeline(showToast = false) {
  clearTimeout(saveTimer);
  if (interview.timeline.historical || interview.timeline.status !== 'active') {
    if (dirty || showToast) return Promise.reject(new Error('Save this historical or archived version as a new cut before editing or rendering it.'));
    return Promise.resolve(interview.timeline);
  }
  const payload = {id:interview.timeline.id,title:interview.timeline.title,targetDurationSeconds:interview.timeline.targetDurationSeconds,edit:structuredClone(interview.state.edit)};
  dirty = false; saveRequests++; text('save-state','Saving editable cut…');
  saving = saving.catch(() => {}).then(async () => {
    try {
      if (timelineRecoveryRequest) {
        const recovered = await editVersionRequest('timeline.save', timelineRecoveryRequest, {}, boot.csrf);
        interview.timeline = recovered; editableVersions?.updated(recovered); timelineRecoveryRequest = null;
      }
      timelineRecoveryRequest = {...payload,revision:interview.timeline.revision,requestToken:editRequestToken()};
      const saved = await editVersionRequest('timeline.save', timelineRecoveryRequest, {}, boot.csrf);
      timelineRecoveryRequest = null; interview.timeline = saved; editableVersions?.updated(saved);
      text('save-state', dirty ? 'Unsaved changes' : saveRequests > 1 ? 'Saving…' : `Cut saved · revision ${saved.revision}`);
      if (showToast) toast('Editable cut saved.'); return saved;
    } catch (error) { dirty = true; text('save-state','Cut save failed · retry or save a new cut'); throw error; }
    finally { saveRequests--; }
  });
  return saving;
}
function applyTimelineSettings() {
  const s=interview.state.edit.settings;
  for (const [id,key] of [['card-duration','cardDuration'],['fade-duration','fadeDuration'],['aspect-ratio','aspect'],['export-resolution','resolution'],['video-fit','fit'],['card-mode','cardMode']]) if($(id)) $(id).value=s[key];
}
async function applyEditableTimeline(timeline) {
  const fresh = await api('interview',null,{id:interview.id});
  clearTimeout(saveTimer); timelineRecoveryRequest = null; interview.timeline = timeline;
  interview.state.edit = structuredClone(timeline.edit); dirty = false;
  interview.takes=fresh.takes || []; interview.revision=fresh.revision;
  setTimelineAvailability(timeline, fresh);
  normalize(); applyTimelineSettings(); currentTrim=null; $('trim-video')?.pause(); if($('trim-panel')) $('trim-panel').hidden=true;
  text('save-state',`Cut saved · revision ${timeline.revision}`); productionEditorNotice(); renderEditor();
}
function setTimelineAvailability(timeline, fresh) {
  const eligible=new Set((fresh.takes || []).map(t=>t.id));
  const selected=timeline.selectedTakeIds || timeline.edit.order.filter(id=>timeline.edit.segments[id]?.included);
  const unavailable=[...new Set([...(timeline.unavailableSelectedIds || []),...selected.filter(id=>!eligible.has(id))])];
  interview.production={...interview.production,...fresh.production,unavailableSelectedIds:unavailable};
}
function renderSessionHeader() {
  text('interview-heading', interview.title);
  text('interview-meta', `${interview.participantName} · ${date(interview.createdAt)}${interview.status === 'archived' ? ' · Archived' : ''}`);
  if ($('interview-title')) $('interview-title').value = interview.title;
  if ($('interview-model')) $('interview-model').value = interview.participantName;
  if ($('interview-notes')) $('interview-notes').value = interview.notes || '';
  if ($('interview-profile')) {
    $('interview-profile').innerHTML = profileOptions(interview.modelId, true);
    $('interview-profile').value = interview.modelId || '';
  }
  text('interview-questionnaire', interview.questionnaireSnapshot?.name || 'AZHottie starter question bank');
  if ($('interview-videos-link')) $('interview-videos-link').href = `videos.php?interviewId=${encodeURIComponent(interview.id)}`;
  if ($('open-editor')) $('open-editor').href = `editor.php?id=${encodeURIComponent(interview.id)}`;
  document.querySelectorAll('[data-interview-link]').forEach(a => a.href = `interview.php?id=${encodeURIComponent(interview.id)}`);
  document.title = `${interview.participantName} · ${page === 'editor' ? 'Editor' : 'Interview'} · AZHottie`;
  if (interview.production) {
    text('interview-meta', `${interview.production.title} · Production footage`);
    text('interview-questionnaire','Production shots'); text('save-interview','Save session');
    $('interview-profile')?.closest('label')?.setAttribute('hidden','');
    if(page==='interview') {
      const heading=document.querySelector('.question-bank h2');if(heading)heading.textContent='Production shots';
      if($('question-search'))$('question-search').placeholder='Find a shot…';
      text('record-answer','Record shot');text('open-phone-pairing','Production QR code');
      text('recording-status','Choose a shot, then record its footage. New takes stay private until submitted and approved in the production.');
      let link=$('production-room-link');
      if(!link){link=document.createElement('a');link.id='production-room-link';link.className='button button--small';document.querySelector('.page-heading .action-group')?.append(link);}
      link.href=`productions.php?projectId=${encodeURIComponent(interview.production.id)}`;link.textContent='Review production takes';
    }
  }
}

function profileOptions(selected = '', includeArchived = false) {
  return '<option value="">No linked profile · enter a name</option>' + (boot.profiles || []).filter(p => p.status !== 'archived' || includeArchived && p.id === selected).map(p => `<option value="${esc(p.id)}">${esc(p.stageName)}${p.status === 'archived' ? ' (archived)' : ''}</option>`).join('');
}
async function initLibrary() {
  let status = 'active';
  $('new-profile').innerHTML = profileOptions();
  $('new-questionnaire').innerHTML = '<option value="">AZHottie starter question bank</option>' + (boot.questionnaires || []).filter(q => q.status !== 'archived').map(q => `<option value="${esc(q.id)}">${esc(q.name)}</option>`).join('');
  const updateModel = () => {
    const profile = (boot.profiles || []).find(p => p.id === $('new-profile').value);
    if (profile) $('new-model').value = profile.stageName;
  };
  on('new-profile', 'change', updateModel);
  on('new-questionnaire', 'change', () => {
    const questionnaire = (boot.questionnaires || []).find(q => q.id === $('new-questionnaire').value);
    text('new-questionnaire-help', questionnaire ? `${questionnaire.questionCount ?? questionnaire.questions?.length ?? 'Selected'} questions · this interview keeps its own copy.` : 'The AZHottie starter question bank is available for this session.');
  });
  function render() {
    const all = boot.interviews || [];
    text('stats-total', all.filter(x => x.status !== 'archived').length);
    text('stats-recordings', all.reduce((n, x) => n + Number(x.takeCount || 0), 0));
    text('stats-duration', clock(all.reduce((n, x) => n + Number(x.totalDurationMs || 0), 0) / 1000));
    const term = ($('library-search')?.value || '').toLowerCase();
    const list = all.filter(x => x.status === status && `${x.title} ${x.participantName}`.toLowerCase().includes(term));
    $('library-list-count') && text('library-list-count', `${list.length} interview${list.length === 1 ? '' : 's'}`);
    $('library-empty').hidden = !!list.length;
    $('library-empty').querySelector('h2').textContent = term ? 'No matching interviews' : status === 'archived' ? 'No archived interviews' : 'Start your first interview';
    $('library-empty').querySelector('h2 + p').textContent = term ? 'Try another model name or interview title.' : status === 'archived' ? 'Archived sessions stay here until you restore or delete them.' : 'Create a session, choose a question, and record an answer. Your clips and edit settings stay together.';
    $('interview-list').innerHTML = list.map(item => `<article class="interview-card"><div class="card-topline"><span class="eyebrow">${esc(item.status === 'archived' ? 'Archived interview' : 'Interview session')}</span><span class="card-date">${esc(date(item.updatedAt))}</span></div><div class="interview-avatar" aria-hidden="true">${esc((item.participantName || '?').split(/\s+/).map(p => p[0]).slice(0, 2).join('').toUpperCase())}</div><h2>${esc(item.participantName)}</h2><p class="card-subtitle">${esc(item.title)}</p><div class="card-metrics"><span>${Number(item.takeCount || 0)} saved takes</span><span>${clock(Number(item.totalDurationMs || 0) / 1000)} recorded</span></div><div class="card-actions"><button class="button primary" data-library-action="resume" data-id="${esc(item.id)}">${status === 'archived' ? 'Restore & resume' : 'Resume interview'}</button><a class="button secondary" href="editor.php?id=${encodeURIComponent(item.id)}">Open editor</a></div><div class="card-bottom"><button class="text-button" data-library-action="archive" data-id="${esc(item.id)}">${status === 'archived' ? 'Restore' : 'Archive'}</button><button class="text-button danger" data-library-action="delete" data-id="${esc(item.id)}">Delete</button></div></article>`).join('');
    document.querySelectorAll('[data-library-status]').forEach(el => { const selected = el.dataset.libraryStatus === status; el.classList.toggle('active', selected); el.setAttribute('aria-selected', String(selected)); });
  }
  on('library-search', 'input', render);
  document.querySelectorAll('[data-library-status]').forEach(el => el.addEventListener('click', () => { status = el.dataset.libraryStatus; render(); }));
  document.querySelectorAll('[data-action="new-interview"]').forEach(el => el.addEventListener('click', () => $('new-interview-dialog').showModal()));
  on('new-interview-form', 'submit', async event => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('[type="submit"]'); button.disabled = true;
    try {
      const created = await api('createInterview', { title: $('new-title').value.trim(), participantName: $('new-model').value.trim(), notes: $('new-notes').value.trim(), modelId: $('new-profile').value || null, questionnaireId: $('new-questionnaire').value || null, state: {} });
      location.href = `interview.php?id=${encodeURIComponent(created.id)}`;
    } finally { button.disabled = false; }
  });
  on('interview-list', 'click', async event => {
    const button = event.target.closest('[data-library-action]'); if (!button) return;
    const id = button.dataset.id; const action = button.dataset.libraryAction;
    const item = boot.interviews.find(x => x.id === id);
    if (action === 'delete') {
      if (!confirm(`Delete “${item.title}”, its recorded takes, and its finished videos? This cannot be undone.`)) return;
      await api('deleteInterview', { id }); toast('Interview deleted.');
    } else if (action === 'archive' || item.status === 'archived') {
      await api('archiveInterview', { id, archived: action === 'resume' ? false : item.status !== 'archived' });
      if (action === 'archive') toast(item.status === 'archived' ? 'Interview restored.' : 'Interview archived.');
    }
    if (action === 'resume') { location.href = `interview.php?id=${encodeURIComponent(id)}`; return; }
    boot = await api('bootstrap'); setCsrf(boot.csrf); render();
  });
  render();
  const requested = new URL(location.href).searchParams;
  if (requested.get('new') === '1') {
    if ((boot.profiles || []).some(p => p.id === requested.get('modelId') && p.status !== 'archived')) $('new-profile').value = requested.get('modelId');
    if ((boot.questionnaires || []).some(q => q.id === requested.get('questionnaireId') && q.status !== 'archived')) $('new-questionnaire').value = requested.get('questionnaireId');
    updateModel();
    $('new-interview-dialog').showModal();
  }
}

function renderQuestions() {
  const category = $('question-category').value; const term = $('question-search').value.toLowerCase();
  const list = questions().filter(q => (!category || q.category === category) && `${q.text} ${q.category}`.toLowerCase().includes(term));
  const recorded = new Set(interview.takes.map(t => t.questionId));
  $('question-list').innerHTML = list.slice(0, limit).map(q => `<article class="question-row${chosen?.id === q.id ? ' selected' : ''}"><button class="question-select" data-question="${esc(q.id)}"><span class="question-number">${String(q.number || '+').padStart(2, '0')}</span><span><span class="question-category">${esc(q.category)}${q.private ? ' · Private' : ''}</span><span class="question-row-text">${esc(q.text)}</span>${recorded.has(q.id) ? '<span class="saved-label">Recorded</span>' : ''}</span></button><button class="button record-small" data-record-question="${esc(q.id)}" ${busy ? 'disabled' : ''}>${interview.production ? 'Record shot' : 'Record answer'}</button></article>`).join('') || '<p class="empty-inline">No questions match your search.</p>';
  $('questions-more').hidden = list.length <= limit;
  text('question-total', `${list.length} ${interview.production?'shots':'questions'}`);
}
function chooseQuestion(id) {
  if (busy) { toast('Finish this take before changing questions.'); return; }
  chosen = getQuestion(id) || questions()[0];
  if (!chosen) { text('current-question-text', 'This questionnaire has no questions.'); $('record-answer').disabled = true; return; }
  const selectionChanged = interview.state.selectedQuestionId !== chosen.id;
  interview.state.selectedQuestionId = chosen.id;
  text('current-question-number', `${interview.production?'Shot':'Question'} ${chosen.number || 'custom'}`);
  text('current-question-category', chosen.category + (chosen.private ? ' · Private casting' : ''));
  text('current-question-text', chosen.text);
  const response = interview.state.responses[chosen.id] || { text: '', choices: [] };
  const multiple = chosen.mode?.startsWith('Select');
  $('current-question-choices').innerHTML = chosen.options?.length ? `<p class="field-hint">${esc(chosen.mode)}</p>` + chosen.options.map((option, index) => `<label class="answer-option"><input type="${multiple ? 'checkbox' : 'radio'}" name="question-choice" value="${index}" ${(response.choices || []).includes(index) ? 'checked' : ''}><span><strong>${String.fromCharCode(65 + index)}.</strong> ${esc(option)}</span></label>`).join('') : '';
  if(interview.production && chosen.brief){const brief=document.createElement('p');brief.className='field-help';brief.textContent=chosen.brief;$('current-question-choices').append(brief);}
  $('answer-notes').value = response.text || '';
  renderQuestions(); renderTakes(); if (selectionChanged) markDirty();
  window.azhInterviewContext = { interview, question: chosen };
  window.dispatchEvent(new CustomEvent('azh:question-changed', { detail: { interviewId: interview.id, question: chosen } }));
}
function recordResponse() {
  if (!chosen) return;
  interview.state.responses[chosen.id] = { text: $('answer-notes').value, choices: [...document.querySelectorAll('input[name="question-choice"]:checked')].map(x => Number(x.value)) };
  markDirty();
}
function setRecordingUI(value) {
  busy = value; $('record-answer').disabled = value; $('stop-recording').disabled = !value; $('mark-answer-start').disabled = !value;
  $('enable-camera').disabled = value; $('microphone-select').disabled = value;
  $('recording-indicator').hidden = !value;
  renderQuestions();
}
async function enableCamera() {
  text('recording-status', 'Waiting for camera and microphone permission…');
  try {
    const devices = await capture.enable($('camera-select').value, $('microphone-select').value);
    const cameraChoice = $('camera-select').value, micChoice = $('microphone-select').value;
    availableCameras = devices.filter(d => d.kind === 'videoinput');
    renderCameraSources(cameraChoice);
    $('microphone-select').innerHTML = '<option value="">Automatic microphone</option>' + devices.filter(d => d.kind === 'audioinput').map((d, i) => `<option value="${esc(d.deviceId)}">${esc(d.label || `Microphone ${i + 1}`)}</option>`).join('');
    if (cameraChoice) $('camera-select').value = cameraChoice; $('microphone-select').value = micChoice;
    await configureCameraSwitching();
    $('camera-placeholder').hidden = true; text('enable-camera', 'Restart camera'); text('recording-status', 'Camera ready. Read the question, then record the answer.');
  } catch (error) { text('recording-status', 'Camera unavailable. Check browser permission or import a recorded clip.'); throw error; }
}
async function acceptTake(take) {
  if (take.take) take = take.take;
  if (!interview.takes.some(existing => existing.id === take.id)) interview.takes.push(take);
  const edit = interview.state.edit;
  for (const old of interview.takes) if (old.id !== take.id && old.questionId === take.questionId && edit.segments[old.id]) edit.segments[old.id].included = false;
  normalize();
  edit.segments[take.id].included = !take.isPrivate && take.reviewStatus !== 'private';
  markDirty(); await saveInterview();
  if (page === 'interview') { renderQuestions(); renderTakes(); }
  if (page === 'editor') renderEditor();
}
async function sendDraft(draft) {
  if (!draft?.blob) throw new Error('This recovery recording is no longer available in this browser.');
  const finished = draft.purpose === 'finished';
  const statusId = finished ? 'finished-save-status' : 'recording-status';
  text(statusId, finished ? 'Saving finished video to the studio…' : 'Saving video to the interview…');
  const result = (finished ? draft.savedVideo : draft.savedTake) || await uploadVideo(draft.blob, draft,
    ratio => text(statusId, `Uploading ${finished ? 'finished video' : 'video'} · ${Math.round(ratio * 100)}%`),
    async upload => { draft.uploadId = upload.uploadId; await pendingPut(draft).catch(() => {}); });
  if (finished) {
    let video = result.video || result;
    if (!video.id) throw new Error('The server did not return a saved finished video. Retry this upload.');
    draft.savedVideo = { video }; await pendingPut(draft).catch(() => {});
    if (video.title !== draft.title || video.description !== (draft.description || '') || video.status !== (draft.status || 'draft')) {
      video = await api('updateVideo', { id: video.id, title: draft.title, description: draft.description || '', status: draft.status || 'draft' });
      draft.savedVideo = { video }; await pendingPut(draft).catch(() => {});
    }
    if (latestExport?.id === draft.id) { latestExport.savedVideo = { video }; latestExport.completed = true; }
    text(statusId, `Saved as version ${video.version}.`);
    if (latestExport?.id === draft.id && $('save-finished-video')) { $('save-finished-video').disabled = true; $('save-finished-video').textContent = 'Saved to finished videos'; }
    if (page === 'editor') await renderFinishedVersions();
    toast(`Finished video saved · version ${video.version}.`);
  } else {
    draft.savedTake = result; await pendingPut(draft).catch(() => {});
    await acceptTake(result);
    text(statusId, interview.production ? 'Take saved privately. Submit it for review in the production.' : 'Take saved. It is ready in the editor.'); toast(interview.production ? 'Production take saved for review.' : 'Video answer saved.');
  }
  await pendingDelete(draft.id).catch(() => {}); drafts.delete(draft.id); await renderPending();
}

async function startRecording(questionId) {
  if (busy) return;
  if (questionId && questionId !== chosen?.id) chooseQuestion(questionId);
  if (!chosen) throw new Error('Choose a question before recording.');
  const q = { ...chosen }; setRecordingUI(true); text('recording-timer', '00:00');
  try {
    if (!capture.stream) await enableCamera();
    await configureCameraSwitching();
    const result = await capture.start({ countdown: $('countdown-select').value, onTimer: ms => text('recording-timer', clock(ms / 1000)), onState: message => { text('recording-status', message); const n = message.match(/^Starting in (\d+)/); $('recording-countdown').hidden = !n; if (n) text('recording-countdown', n[1]); } });
    $('recording-indicator').hidden = true; $('stop-recording').disabled = true; $('mark-answer-start').disabled = true;
    const draft = { id: uid(), interviewId: interview.id, questionId: q.id, questionText: q.text, isPrivate: q.private, blob: result.blob, durationMs: result.durationMs, answerStartMs: result.answerStartMs, mimeType: result.blob.type, fileName: `${slug(interview.participantName)}-${q.id}-${Date.now()}.${result.blob.type.includes('mp4') ? 'mp4' : 'webm'}` };
    drafts.set(draft.id, draft);
    try { await pendingPut(draft); } catch { toast('Browser recovery storage is full. Keep this page open until the upload finishes.', true); }
    await sendDraft(draft);
    if (result.interrupted) toast('A device stopped during the take. The recovered partial recording was saved; review it before using it.', true);
  } catch (error) { if (error.name !== 'AbortError') { text('recording-status', 'Video not saved to the server yet. Use Retry upload or Download recovery copy below.'); fail(error); } else text('recording-status', 'Recording cancelled.'); }
  finally { $('recording-countdown').hidden = true; setRecordingUI(false); await renderPending(); }
}
async function renderPending() {
  try { for (const draft of await pendingList(interview.id)) if (!drafts.has(draft.id)) drafts.set(draft.id, draft); } catch { /* Live-memory recovery remains available. */ }
  let container = $('pending-takes');
  if (!container) { container = document.createElement('section'); container.id = 'pending-takes'; container.className = 'pending-takes'; $('take-list')?.before(container); }
  container.innerHTML = drafts.size ? `<h3>Pending video saves</h3><p class="field-hint">These recovery copies are on this browser. Save original takes to the interview or finished cuts to the video library.</p>` + [...drafts.values()].map(d => `<div class="pending-row"><p>${d.purpose === 'finished' ? 'Finished cut · ' : 'Recorded answer · '}${esc(d.title || d.questionText)}</p><div class="button-row"><button class="button primary" data-retry-draft="${esc(d.id)}">Save / retry upload</button><button class="button secondary" data-download-draft="${esc(d.id)}">Download recovery copy</button></div></div>`).join('') : '';
}
function renderTakes() {
  const takes = interview.takes.filter(t => t.questionId === chosen?.id).slice().reverse();
  text('take-count', `${takes.length} take${takes.length === 1 ? '' : 's'}`);
  $('take-list').innerHTML = takes.map((take, i) => `<article class="take-row"><div><strong>Take ${takes.length - i}</strong><p>${clock(Number(take.durationMs) / 1000)} · ${esc(date(take.createdAt))}${interview.state.edit.segments[take.id]?.included ? ' · In edit' : ''}</p>${reviewSummary(take)}</div><div class="button-row">${favoriteButton(take)}<button class="button secondary" data-review-take="${esc(take.id)}">Review & notes</button><a class="text-button" href="${esc(take.mediaUrl)}&download=1">Download</a>${interview.production ? '' : `<button class="text-button danger" data-delete-take="${esc(take.id)}">Delete</button>`}</div></article>`).join('') || '<p class="empty-inline">No takes for this question yet. Record an answer or import a clip.</p>';
  if (!takes.some(t => t.id === currentReview)) { currentReview = null; $('review-video').pause(); $('review-video').removeAttribute('src'); $('review-video').hidden = true; $('take-review-panel').hidden = true; }
  renderReviewPanels();
}
async function importFile(file, question) {
  if (!file) return;
  if (busy || editBusy) throw new Error('Finish the current recording, upload, or edit playback first.');
  busy = true;
  try {
    toast('Reading video…');
    const meta = await getVideoMetadata(file);
    if (!Number.isFinite(meta.duration) || meta.duration <= 0) throw new Error('The browser could not read this video. Try an MP4 or WebM clip.');
    const draft = { id: uid(), interviewId: interview.id, questionId: question.id, questionText: question.text, isPrivate: !!question.private, blob: file, durationMs: Math.round(meta.duration * 1000), answerStartMs: 0, fileName: file.name, mimeType: file.type || 'video/mp4' };
    drafts.set(draft.id, draft); await pendingPut(draft).catch(() => {}); await sendDraft(draft);
  } finally { busy = false; if (page === 'interview') renderQuestions(); await renderPending(); }
}
async function initInterview() {
  capture = new InterviewCapture($('camera-preview'));
  $('camera-preview').style.transform = $('mirror-preview').checked ? 'scaleX(-1)' : '';
  $('question-category').innerHTML = '<option value="">All categories</option>' + [...new Set(questions().map(q => q.category))].map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
  on('question-search', 'input', () => { limit = 12; renderQuestions(); });
  on('question-category', 'change', () => { limit = 12; renderQuestions(); });
  on('questions-more', 'click', () => { limit += 12; renderQuestions(); });
  on('question-list', 'click', event => { const record = event.target.closest('[data-record-question]'); if (record) { $('current-question-text').scrollIntoView({ behavior: 'smooth', block: 'center' }); return startRecording(record.dataset.recordQuestion); } const select = event.target.closest('[data-question]'); if (select) chooseQuestion(select.dataset.question); });
  on('enable-camera', 'click', enableCamera);
  on('camera-select', 'change', async () => {
    if (!capture.stream) return;
    if (capture.switchCamera) {
      const sources = cameraSources();
      const cameraId = $('camera-select').value || sources.find(source => !source.remote)?.deviceId || capture.activeCameraId || sources[0]?.deviceId;
      if (cameraId) await capture.switchCamera(cameraId);
    } else await enableCamera();
  });
  on('microphone-select', 'change', () => capture.stream && enableCamera());
  initCameraSwitching();
  on('mirror-preview', 'change', () => $('camera-preview').style.transform = $('mirror-preview').checked ? 'scaleX(-1)' : '');
  on('record-answer', 'click', () => startRecording());
  on('stop-recording', 'click', () => { capture.stop(); text('recording-status', 'Finishing take…'); });
  on('mark-answer-start', 'click', () => { const ms = capture.markAnswerStart(); if (ms !== null) text('recording-status', `Answer starts at ${clock(ms / 1000)}. The editor will trim the spoken question.`); });
  on('answer-notes', 'input', recordResponse);
  on('current-question-choices', 'change', recordResponse);
  on('save-answer', 'click', () => { recordResponse(); return saveInterview(true); });
  on('import-clip', 'change', async event => { await importFile(event.target.files[0], chosen); event.target.value = ''; });
  on('take-list', 'click', async event => {
    const review = event.target.closest('[data-review-take]');
    if (review) { openTakeReview(review.dataset.reviewTake); return; }
    const remove = event.target.closest('[data-delete-take]'); if (remove) await deleteTake(remove.dataset.deleteTake);
  });
  chooseQuestion(interview.state.selectedQuestionId || questions()[0]?.id);
  await renderPending();
}
async function deleteTake(id) {
  if (busy || editBusy) throw new Error('Finish the current recording or edit playback first.');
  if (!confirm('Delete this take and remove it from the edit? This cannot be undone.')) return;
  await api('deleteTake', { takeId: id });
  if (currentTrim === id) { currentTrim = null; $('trim-video')?.pause(); if ($('trim-panel')) $('trim-panel').hidden = true; }
  interview.takes = interview.takes.filter(t => t.id !== id); normalize(); markDirty(); await saveInterview();
  if (page === 'interview') { renderTakes(); renderQuestions(); } else renderEditor();
  toast('Take deleted.');
}

function segments() {
  const edit = interview.state.edit;
  return edit.order.filter(id => edit.segments[id]?.included && interview.takes.some(take => take.id === id && take.reviewStatus !== 'private')).map(id => {
    const take = interview.takes.find(t => t.id === id), config = edit.segments[id];
    return { id, question: config.title, src: take.mediaUrl, showCard: config.showCard === true, trimStart: Number(config.trimStart) || 0, trimEnd: Number(config.trimEnd), duration: Number(take.durationMs) / 1000 };
  });
}
function renderEditor() {
  const edit = interview.state.edit;
  const selected = segments();
  const timeline = selected.length ? buildTimeline(selected, edit.settings) : { totalDuration: 0 };
  text('edit-count', `${selected.length} selected ${interview.production ? 'clips' : 'answers'}`); text('edit-duration', clock(timeline.totalDuration));
  $('editor-empty').hidden = !!interview.takes.length;
  $('segment-list').innerHTML = edit.order.filter(id=>interview.takes.some(t=>t.id===id) && edit.segments[id]).map((id, index) => {
    const take = interview.takes.find(t => t.id === id), s = edit.segments[id];
    return `<article class="segment-card${s.included ? ' included' : ''}"><div class="segment-top"><label class="segment-inclusion"><input type="checkbox" data-include-segment="${esc(id)}" ${s.included ? 'checked' : ''} ${editBusy || take.reviewStatus === 'private' ? 'disabled' : ''}><span>${String(index + 1).padStart(2, '0')} · ${take.isPrivate || take.reviewStatus === 'private' ? 'Private take' : 'Video answer'}</span></label><span class="segment-length">${clock(s.trimEnd - s.trimStart)}</span></div><h3>${esc(s.title)}</h3>${reviewSummary(take)}${take.reviewStatus === 'private' ? '<p class="field-help">Change the review label before including this take in an edit.</p>' : ''}<div class="segment-flow">${edit.settings.cardMode === 'none' || (edit.settings.cardMode === 'perClip' && !s.showCard) ? '' : '<span>Black title card</span>'}<span>Fade in</span><span>Clip</span><span>Fade out</span></div>${edit.settings.cardMode === 'perClip' ? `<label class="segment-inclusion"><input type="checkbox" data-card-segment="${esc(id)}" ${s.showCard ? 'checked' : ''} ${editBusy ? 'disabled' : ''}>Show title card for this clip</label>` : ''}<div class="segment-actions">${favoriteButton(take)}<button class="button secondary" data-trim-segment="${esc(id)}">Trim, text & notes</button><button class="icon-button" data-move-segment="${esc(id)}" data-direction="-1" aria-label="Move answer earlier" ${index === 0 || editBusy ? 'disabled' : ''}>↑</button><button class="icon-button" data-move-segment="${esc(id)}" data-direction="1" aria-label="Move answer later" ${index === edit.order.length - 1 || editBusy ? 'disabled' : ''}>↓</button><a class="text-button" href="${esc(take.mediaUrl)}&download=1">Original</a>${interview.production ? '' : `<button class="text-button danger" data-delete-segment="${esc(id)}">Delete</button>`}</div></article>`;
  }).join('');
  if (!editBusy) renderer.showCard(segments()[0]?.question || 'Your next answer starts here.', edit.settings);
  $('preview-edit').disabled = !segments().length || editBusy;
  $('export-video').disabled = !segments().length || editBusy;
  $('stop-preview').disabled = !editBusy;
}
function syncEditorSettings(strict = false) {
  if (page !== 'editor' || !interview) return false;
  const card = Number($('card-duration').value), fade = Number($('fade-duration').value);
  const aspect = $('aspect-ratio').value, resolution = Number($('export-resolution').value), fit = $('video-fit').value;
  let error = '';
  if ($('card-duration').value.trim() === '' || !Number.isFinite(card) || card < 0.5 || card > 15) error = 'Use a question-card duration between 0.5 and 15 seconds.';
  else if ($('fade-duration').value.trim() === '' || !Number.isFinite(fade) || fade < 0 || fade > 3) error = 'Use a fade duration between 0 and 3 seconds.';
  else if (!['16:9', '9:16', '1:1'].includes(aspect) || ![720, 1080].includes(resolution) || !['contain', 'cover'].includes(fit)) error = 'Choose a supported video frame, resolution, and fit.';
  if (error) { if (strict) throw new Error(error); return false; }
  const cardMode = $('card-mode')?.value || 'all';
  if (!['all','none','perClip'].includes(cardMode)) { if(strict) throw new Error('Choose a supported title-card mode.'); return false; }
  const settings = { ...interview.state.edit.settings, cardMode, cardDuration: card, fadeDuration: fade, aspect, resolution, fit, modelName: interview.participantName };
  if (JSON.stringify(settings) !== JSON.stringify(interview.state.edit.settings)) { interview.state.edit.settings = settings; markDirty(); }
  return true;
}
function settingsChanged() {
  if (editBusy) return;
  if (syncEditorSettings()) renderEditor();
}

function openTrim(id) {
  if (editBusy) return;
  if (currentTrim !== id) $('trim-note-text').value = '';
  currentTrim = id;
  const take = interview.takes.find(t => t.id === id), config = interview.state.edit.segments[id];
  $('trim-question').value = config.title; $('trim-start').value = Number(config.trimStart).toFixed(2); $('trim-end').value = Number(config.trimEnd).toFixed(2);
  $('trim-start').max = $('trim-end').max = (Number(take.durationMs) / 1000).toFixed(3);
  $('trim-video').src = take.mediaUrl;
  $('trim-video').onloadedmetadata = () => { $('trim-video').currentTime = Number(config.trimStart) || 0; };
  $('trim-panel')?.removeAttribute('hidden');
  text('trim-take-label', `${clock(Number(take.durationMs) / 1000)} original · Set answer start and end in seconds`);
  renderReviewPanels();
  $('trim-question').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
async function runEdit(exporting) {
  if (editBusy) return;
  if (interview.timeline && (interview.timeline.historical || interview.timeline.status !== 'active')) throw new Error('Save this historical or archived revision as a new cut before rendering.');
  if (interview.production?.unavailableSelectedIds?.length) throw new Error('Review and acknowledge the removed source takes before rendering a new edit.');
  syncEditorSettings(true);
  if (!segments().length) return;
  if (busy) throw new Error('Finish the current upload before previewing or exporting.');
  editCancelled = false; editBusy = true; renderEditor(); renderReviewPanels();
  document.querySelectorAll('[data-edit-setting], #card-duration, #fade-duration, #aspect-ratio, #export-resolution, #video-fit').forEach(x => x.disabled = true);
  $('trim-video').pause();
  $('export-progress').hidden = false;
  $('export-panel').hidden = false;
  if (exporting) { $('export-download').hidden = true; $('finished-save-form').hidden = true; }
  try {
    await reviewSaving.catch(() => {});
    await saveInterview();
    const fresh = await api('interview', null, { id: interview.id });
    if (Number(fresh.revision) !== Number(interview.revision)) throw new Error('This interview changed in another tab. Reload it before rendering an edit.');
    interview.takes = fresh.takes || [];
    if (interview.timeline) {
      const savedCut = await editVersionRequest('timeline',null,{id:interview.timeline.id},boot.csrf);
      if (Number(savedCut.revision)!==Number(interview.timeline.revision)) throw new Error('This editable cut changed in another tab. Reload it before rendering.');
      fresh.production.unavailableSelectedIds=savedCut.unavailableSelectedIds || [];
    }
    if (fresh.production?.unavailableSelectedIds?.length) { interview.production = fresh.production; normalize(); productionEditorNotice(); throw new Error('A selected source is no longer approved. Review the updated sequence before rendering.'); }
    const beforeNormalize = JSON.stringify(interview.state.edit);
    if (normalize() || (interview.timeline && beforeNormalize !== JSON.stringify(interview.state.edit))) await saveInterview();
    if (editCancelled) throw new DOMException('Render cancelled.', 'AbortError');
    if (!segments().length) throw new Error('Select a take that is available for the edit. Takes labeled Private only are excluded.');
    const callbacks = {
      onProgress: p => { $('export-progress').value = Math.round(p.ratio * 100); text('preview-time', `${clock(p.elapsed)} / ${clock(p.total)}`); text('export-status', `${exporting ? 'Rendering' : 'Preview'} · ${clock(p.elapsed)} / ${clock(p.total)} · ${Math.round(p.ratio * 100)}%`); },
      onStatus: message => text('export-status', message)
    };
    if (exporting) {
      text('export-status', 'Rendering your video. Keep this tab visible; rendering runs in real time.');
      const selectedSegments = structuredClone(segments());
      const exportSettings = structuredClone(interview.state.edit.settings);
      const sourceEdit = { interviewId: interview.id, interviewTitle: interview.title, participantName: interview.participantName, renderedAt: new Date().toISOString(), edit: structuredClone(interview.state.edit), selectedTakeIds: selectedSegments.map(s => s.id), ...(interview.timeline ? {timelineId:interview.timeline.id,timelineRevision:interview.timeline.revision} : {}) };
      const result = await renderer.export(selectedSegments, exportSettings, callbacks);
      if (exportURL) URL.revokeObjectURL(exportURL);
      exportURL = URL.createObjectURL(result.blob);
      $('export-download').href = exportURL;
      $('export-download').download = `${slug(interview.production?.title || interview.participantName)}-azhottie-${interview.production ? 'production' : 'interview'}.${result.extension}`;
      $('export-download').hidden = false;
      $('export-download').textContent = `Download finished ${result.extension.toUpperCase()} video`;
      text('export-status', `Ready · ${clock(result.duration)} · ${(result.blob.size / 1048576).toFixed(1)} MB`);
      latestExport = { id: uid(), purpose: 'finished', interviewId: interview.id, title: interview.timeline?.title || interview.title, description: '', status: 'draft', sourceEdit, blob: result.blob, mimeType: result.blob.type, durationMs: Math.round(result.duration * 1000), fileName: $('export-download').download };
      drafts.set(latestExport.id, latestExport);
      await pendingPut(latestExport).catch(() => toast('Keep this page open until the finished video is saved or downloaded. Browser recovery storage is full.', true));
      $('finished-title').value = latestExport.title; $('finished-description').value = ''; $('finished-status').value = 'draft';
      $('finished-save-form').hidden = false; $('save-finished-video').disabled = false; $('save-finished-video').textContent = 'Save finished video';
      text('finished-save-status', 'Ready to save this rendered cut.');
      await renderPending();
      toast('Your finished video is ready to save or download.');
    } else { await renderer.play(segments(), interview.state.edit.settings, callbacks); text('export-status', 'Preview complete.'); }
  } catch (error) { if (error.name === 'AbortError') text('export-status', 'Stopped. Your saved recordings and edit are unchanged.'); else { text('export-status', error.message); fail(error); } }
  finally { editBusy = false; document.querySelectorAll('[data-edit-setting], #card-duration, #fade-duration, #aspect-ratio, #export-resolution, #video-fit').forEach(x => x.disabled = false); renderEditor(); renderReviewPanels(); }
}
async function initEditor() {
  renderer = new InterviewRenderer($('edit-canvas'));
  const s = interview.state.edit.settings;
  $('card-duration').value = s.cardDuration; $('fade-duration').value = s.fadeDuration; $('aspect-ratio').value = s.aspect; $('export-resolution').value = s.resolution; $('video-fit').value = s.fit;
  if ($('card-mode')) $('card-mode').value = s.cardMode || 'all';
  for (const id of ['card-mode', 'card-duration', 'fade-duration', 'aspect-ratio', 'export-resolution', 'video-fit']) on(id, 'change', settingsChanged);
  for (const id of ['card-duration', 'fade-duration']) on(id, 'input', settingsChanged);
  on('segment-list', 'change', event => { const input = event.target.closest('[data-include-segment]'); if (input && !editBusy) { const take = interview.takes.find(t => t.id === input.dataset.includeSegment); interview.state.edit.segments[input.dataset.includeSegment].included = take?.reviewStatus !== 'private' && input.checked; markDirty(); renderEditor(); } });
  on('segment-list', 'change', event => { const input = event.target.closest('[data-card-segment]'); if(input && !editBusy){interview.state.edit.segments[input.dataset.cardSegment].showCard=input.checked;markDirty();renderEditor();} });
  on('segment-list', 'click', async event => {
    const trim = event.target.closest('[data-trim-segment]'); if (trim) return openTrim(trim.dataset.trimSegment);
    const move = event.target.closest('[data-move-segment]'); if (move && !editBusy) { const order = interview.state.edit.order; const index = order.indexOf(move.dataset.moveSegment), target = index + Number(move.dataset.direction); if (target >= 0 && target < order.length) { [order[index], order[target]] = [order[target], order[index]]; markDirty(); renderEditor(); } }
    const del = event.target.closest('[data-delete-segment]'); if (del) await deleteTake(del.dataset.deleteSegment);
  });
  on('trim-save', 'click', () => {
    if (!currentTrim) throw new Error('Choose a recorded answer to trim.');
    if (editBusy) return;
    const start = Number($('trim-start').value), end = Number($('trim-end').value), take = interview.takes.find(t => t.id === currentTrim);
    if (!Number.isFinite(start) || !Number.isFinite(end) || start < 0 || end - start < 0.1 || end > Number(take.durationMs) / 1000 + 0.1) throw new Error('Use a start and end within the clip, with at least 0.1 seconds of video.');
    const title = $('trim-question').value.trim(); if (!title) throw new Error('Enter the text for the black question card.');
    Object.assign(interview.state.edit.segments[currentTrim], { trimStart: start, trimEnd: Math.min(end, Number(take.durationMs) / 1000), title });
    markDirty(); renderEditor(); return saveInterview(true);
  });
  on('trim-start', 'change', () => { const n = Number($('trim-start').value); if (Number.isFinite(n) && n >= 0) $('trim-video').currentTime = n; });
  on('preview-edit', 'click', () => runEdit(false));
  on('export-video', 'click', () => runEdit(true));
  on('stop-preview', 'click', () => { editCancelled = true; renderer.stop(); });
  on('cancel-export', 'click', () => { editCancelled = true; renderer.stop(); });
  on('close-trim', 'click', () => { $('trim-video').pause(); $('trim-panel').hidden = true; });
  on('use-current-start', 'click', () => { $('trim-start').value = $('trim-video').currentTime.toFixed(2); });
  on('use-current-end', 'click', () => { $('trim-end').value = $('trim-video').currentTime.toFixed(2); });
  on('import-editor-clip', 'change', async event => {
    const file = event.target.files[0]; if (!file) return;
    try {
      const question = $('import-question-title').value.trim();
      if (!question) { $('import-question-title').focus(); throw new Error('Enter the question for this video, then choose the clip again.'); }
      const q = { id: `custom-${uid()}`, number: '+', category: 'Custom questions', text: question, options: [], private: false, kind: 'open' };
      interview.state.customQuestions.push(q);
      await importFile(file, q);
      $('import-question-title').value = '';
    } finally { event.target.value = ''; }
  });
  on('finished-save-form', 'submit', saveFinishedVideo);
  if (interview.production) editableVersions = await mountEditableVersions({interview,csrf:boot.csrf,getEdit:()=>{syncEditorSettings(true);return interview.state.edit;},apply:applyEditableTimeline,beforeChange:()=>{syncEditorSettings(true);return saveInterview();},isBusy:()=>busy||editBusy||saveRequests>0,notify:toast,onSettled:()=>renderEditor()});
  productionEditorNotice();
  renderEditor(); await renderPending(); await renderFinishedVersions();
}

function productionEditorNotice() {
  if (!interview.production) return;
  document.querySelector('.editor-import')?.setAttribute('hidden','');
  const p=interview.production;
  text('preview-status', `Only approved footage is available here. ${p.kind==='mixed'?'Use a title card on selected interview clips, with direct cuts for production footage.':'Choose whether to introduce each clip with a black title card.'}`);
  const heading=document.querySelector('.sequence-panel h2');if(heading)heading.textContent='Clips in the edit';
  const subheading=document.querySelector('.sequence-panel .panel-heading > .muted');if(subheading)subheading.textContent='Approved footage · optional title cards';
  const empty=$('editor-empty');if(empty){const title=empty.querySelector('h3'),copy=empty.querySelector('p');if(title)title.textContent='No approved footage yet';if(copy)copy.textContent='Submit and approve production takes before adding them to this edit.';}
  let banner=$('production-editor-notice');
  if(!banner){banner=document.createElement('div');banner.id='production-editor-notice';banner.className='archive-banner';document.querySelector('main')?.prepend(banner);}
  banner.replaceChildren();
  const back=document.createElement('a');back.className='text-button';back.href=`productions.php?projectId=${encodeURIComponent(p.id)}`;back.textContent='Open production';
  const info=document.createElement('span');info.textContent=p.unavailableSelectedIds?.length?`${p.unavailableSelectedIds.length} previously selected source take(s) are no longer approved. They were removed from this sequence. Review the remaining clips before continuing. `:`${p.title} · ${p.acceptedCount} approved source clip(s). `;
  const unknown=interview.timeline?interview.takes.filter(t=>Number(t.durationMs)<100).length:0;
  if(unknown)info.textContent+=`${unknown} source take(s) have no usable duration and are left out of this named cut. `;
  banner.append(info,back);
  if(p.unavailableSelectedIds?.length){const button=document.createElement('button');button.type='button';button.className='button button--small';button.textContent='Acknowledge removed sources';button.addEventListener('click',()=>{p.unavailableSelectedIds=[];normalize();markDirty();productionEditorNotice();renderEditor();});banner.append(button);}
}

const reviewLabels = { unreviewed: 'Unreviewed', shortlisted: 'Shortlisted', retake: 'Needs another take', private: 'Private only' };
function favoriteButton(take) {
  return `<button type="button" class="button button--small favorite-button${take.isFavorite ? ' is-favorite' : ''}" data-favorite-take="${esc(take.id)}" aria-pressed="${take.isFavorite ? 'true' : 'false'}">${take.isFavorite ? '★ Favorite' : '☆ Favorite'}</button>`;
}
function reviewSummary(take) {
  return `<div class="review-summary"><span class="badge${take.reviewStatus === 'private' ? ' badge--private' : ''}">${esc(reviewLabels[take.reviewStatus] || reviewLabels.unreviewed)}</span><span>${(take.reviewNotes || []).length} review note${(take.reviewNotes || []).length === 1 ? '' : 's'}</span></div>`;
}
function reviewControls(take) {
  if (interview.production && take.sourceKind !== 'interview') return `${favoriteButton(take)}<a class="text-button" href="productions.php?projectId=${encodeURIComponent(interview.production.id)}">Approve or request retakes in the production</a>`;
  return `${favoriteButton(take)}<label class="field review-status-field">Review label<select data-review-status="${esc(take.id)}" ${editBusy ? 'disabled' : ''}>${Object.entries(reviewLabels).map(([value, label]) => `<option value="${value}" ${value === (take.reviewStatus || 'unreviewed') ? 'selected' : ''}>${label}</option>`).join('')}</select></label>`;
}
function reviewNotes(take, target) {
  const notes = [...(take.reviewNotes || [])].sort((a, b) => a.atMs - b.atMs);
  return notes.length ? notes.map(note => `<article class="review-note"><button type="button" class="note-time" data-seek-note="${esc(note.id)}" data-note-take="${esc(take.id)}" data-note-target="${target}" aria-label="Play from ${clock(Number(note.atMs) / 1000)}">${clock(Number(note.atMs) / 1000)}</button><p>${esc(note.text)}</p><button type="button" class="text-button danger" data-delete-note="${esc(note.id)}" data-note-take="${esc(take.id)}" aria-label="Delete note at ${clock(Number(note.atMs) / 1000)}">Delete</button></article>`).join('') : '<p class="field-help">No review notes yet. Pause at a moment and add a note.</p>';
}
function renderReviewPanels() {
  for (const [id, prefix] of [[currentReview, 'take'], [currentTrim, 'trim']]) {
    const take = interview.takes.find(t => t.id === id);
    if (!take) continue;
    if ($(`${prefix}-review-controls`)) $(`${prefix}-review-controls`).innerHTML = reviewControls(take);
    if ($(`${prefix}-note-list`)) $(`${prefix}-note-list`).innerHTML = reviewNotes(take, prefix);
    if (prefix === 'take') text('take-review-title', take.label || take.questionText || 'Review this take');
  }
}
function openTakeReview(id) {
  const take = interview.takes.find(t => t.id === id); if (!take) return;
  if (currentReview !== id) { $('review-video').src = take.mediaUrl; $('take-note-text').value = ''; }
  currentReview = id; $('review-video').hidden = false; $('take-review-panel').hidden = false;
  renderReviewPanels(); $('review-video').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
async function applyReviewedTake(value, markPrivate = false) {
  const take = value.take || value;
  const index = interview.takes.findIndex(t => t.id === take.id);
  if (index < 0) return;
  interview.takes[index] = {...interview.takes[index],...take};
  if (markPrivate && take.reviewStatus === 'private' && interview.state.edit.segments[take.id]?.included) {
    interview.state.edit.segments[take.id].included = false; markDirty();
    toast('This take is now private and has been removed from the current edit.');
  }
  if (page === 'interview') renderTakes(); else renderEditor();
  renderReviewPanels();
}
function mutateTakeReview(action, body, markPrivate = false) {
  reviewSaving = reviewSaving.catch(() => {}).then(async () => {
    const result = await api(action, typeof body === 'function' ? body() : body);
    await applyReviewedTake(result, markPrivate);
    return result;
  });
  return reviewSaving;
}
function initReview() {
  document.addEventListener('click', async event => {
    const favorite = event.target.closest('[data-favorite-take]');
    const seek = event.target.closest('[data-seek-note]');
    const remove = event.target.closest('[data-delete-note]');
    try {
      if (favorite) {
        const take = interview.takes.find(t => t.id === favorite.dataset.favoriteTake); if (!take) return;
        favorite.disabled = true;
        try { await mutateTakeReview('updateTakeReview', () => ({ takeId: take.id, isFavorite: !interview.takes.find(t => t.id === take.id)?.isFavorite })); }
        finally { favorite.disabled = false; }
      }
      if (seek) {
        const take = interview.takes.find(t => t.id === seek.dataset.noteTake), note = take?.reviewNotes?.find(n => n.id === seek.dataset.seekNote);
        const video = $(seek.dataset.noteTarget === 'trim' ? 'trim-video' : 'review-video');
        if (note && video) { video.currentTime = Number(note.atMs) / 1000; video.focus(); }
      }
      if (remove) {
        remove.disabled = true;
        try { await mutateTakeReview('deleteTakeNote', { takeId: remove.dataset.noteTake, noteId: remove.dataset.deleteNote }); }
        finally { remove.disabled = false; }
      }
    } catch (error) { fail(error); }
  });
  document.addEventListener('change', async event => {
    const select = event.target.closest('[data-review-status]'); if (!select) return;
    if (editBusy) { renderReviewPanels(); toast('Finish the current render before changing a take’s review label.'); return; }
    select.disabled = true;
    try { await mutateTakeReview('updateTakeReview', { takeId: select.dataset.reviewStatus, reviewStatus: select.value }, true); }
    catch (error) { renderReviewPanels(); fail(error); }
    finally { select.disabled = false; }
  });
  for (const prefix of ['take', 'trim']) {
    const videoId = prefix === 'take' ? 'review-video' : 'trim-video';
    on(videoId, 'timeupdate', () => text(`${prefix}-review-time`, clock($(videoId).currentTime)));
    on(`${prefix}-note-form`, 'submit', async event => {
      event.preventDefault();
      const takeId = prefix === 'take' ? currentReview : currentTrim;
      const submittedText = $(`${prefix}-note-text`).value;
      const noteText = submittedText.trim();
      if (!takeId || !noteText) throw new Error('Choose a take and write a review note.');
      const button = event.currentTarget.querySelector('button[type="submit"]'); button.disabled = true;
      try {
        const take = interview.takes.find(t => t.id === takeId);
        const atMs = Math.min(Number(take.durationMs), Math.max(0, Math.round(($(videoId).currentTime || 0) * 1000)));
        await mutateTakeReview('addTakeNote', { takeId, atMs, text: noteText });
        if ((prefix === 'take' ? currentReview : currentTrim) === takeId && $(`${prefix}-note-text`).value === submittedText) $(`${prefix}-note-text`).value = '';
        toast('Timestamped note saved.');
      } finally { button.disabled = false; }
    });
  }
}

async function renderFinishedVersions() {
  if (!$('finished-version-list')) return;
  try {
    const result = await api('videos', null, { interviewId: interview.id });
    const videos = Array.isArray(result) ? result : result.videos || [];
    const active = videos.filter(video => !video.archived);
    $('finished-version-list').innerHTML = active.length ? active.map(video => `<article class="finished-version"><div><a class="version-title" href="videos.php?id=${encodeURIComponent(video.id)}&interviewId=${encodeURIComponent(interview.id)}">${esc(video.title)}</a><p class="field-help">Version ${Number(video.version)} · ${esc(({ draft: 'Draft', review: 'Awaiting review', ready: 'Ready to post', published: 'Published' })[video.status] || video.status)} · ${clock(Number(video.durationMs) / 1000)}</p></div><div class="action-group"><a class="button button--small" href="videos.php?id=${encodeURIComponent(video.id)}&interviewId=${encodeURIComponent(interview.id)}">View video</a><a class="text-button" href="${esc(video.downloadUrl || video.mediaUrl + '&download=1')}">Download</a></div></article>`).join('') : '<p class="empty-inline">No finished videos saved yet. Export your edit, then save the movie to this studio.</p>';
  } catch (error) { $('finished-version-list').innerHTML = `<p class="empty-inline">${esc(error.message)} <a href="videos.php">Open the finished video library</a>.</p>`; }
}
async function saveFinishedVideo(event) {
  event.preventDefault();
  if (!latestExport?.blob) throw new Error('Export the edit before saving a finished video.');
  if (latestExport.completed) { toast('This rendered cut is already saved. Render a new edit to create another version.'); return; }
  if (busy || editBusy) throw new Error('Finish the current recording or render first.');
  const title = $('finished-title').value.trim(); if (!title) throw new Error('Enter a title for this finished video.');
  latestExport.title = title; latestExport.description = $('finished-description').value.trim(); latestExport.status = $('finished-status').value;
  busy = true; $('save-finished-video').disabled = true; $('save-finished-video').textContent = 'Saving finished video…';
  text('finished-save-status', 'Preparing the recovery copy and upload…');
  try { await pendingPut(latestExport).catch(() => {}); await sendDraft(latestExport); }
  catch (error) { text('finished-save-status', 'Upload not complete. Retry here or use the recovery copy below.'); throw error; }
  finally { busy = false; $('save-finished-video').disabled = !!latestExport.completed; if (!latestExport.completed) $('save-finished-video').textContent = 'Save finished video'; await renderPending(); }
}

function cameraSources() {
  const exposed = capture?.getCameraSources?.();
  return Array.isArray(exposed) ? exposed.filter(d => d.kind === 'videoinput' || d.remote) : availableCameras;
}
function renderCameraSources(preferred = capture?.activeCameraId || $('camera-select')?.value || '') {
  const sources = cameraSources();
  const previous = new Map([...document.querySelectorAll('[data-rotation-camera]')].map(input => [input.value, input.checked]));
  if ($('camera-select')) {
    $('camera-select').innerHTML = '<option value="">Automatic camera</option>' + sources.map((d, i) => `<option value="${esc(d.deviceId)}">${esc(d.label || `Camera ${i + 1}`)}${d.remote ? ' · phone' : ''}</option>`).join('');
    if (sources.some(d => d.deviceId === preferred)) $('camera-select').value = preferred;
  }
  if ($('camera-rotation-list')) $('camera-rotation-list').innerHTML = sources.length ? sources.map((d, i) => `<label class="checkbox-field"><input type="checkbox" data-rotation-camera value="${esc(d.deviceId)}" ${previous.get(d.deviceId) !== false ? 'checked' : ''}><span>${esc(d.label || `Camera ${i + 1}`)}${d.remote ? ' · phone' : ''}</span></label>`).join('') : '<p class="field-help">Enable the camera or approve a live phone feed to list available cameras.</p>';
  if ($('switch-camera')) $('switch-camera').disabled = sources.length < 2;
}
async function configureCameraSwitching() {
  if (!capture?.configureSwitching) return;
  const mode = $('camera-switch-mode')?.value || 'off';
  const intervalSeconds = Math.min(120, Math.max(10, Number($('camera-switch-interval')?.value) || 30));
  const deviceIds = [...document.querySelectorAll('[data-rotation-camera]:checked')].map(input => input.value);
  await capture.configureSwitching({ mode, intervalSeconds, deviceIds });
  if ($('camera-switch-interval')) $('camera-switch-interval').disabled = mode === 'off';
}
function initCameraSwitching() {
  renderCameraSources();
  for (const id of ['camera-switch-mode', 'camera-switch-interval', 'camera-rotation-list']) on(id, 'change', configureCameraSwitching);
  on('switch-camera', 'click', async () => {
    if (!capture.stream) await enableCamera();
    await configureCameraSwitching();
    if (capture.switchCamera) await capture.switchCamera();
  });
  capture.addEventListener?.('cameraswitch', event => {
    const detail = event.detail || {};
    renderCameraSources(detail.deviceId || detail.activeCameraId || '');
    text('camera-switch-status', detail.label ? `Live camera: ${detail.label}` : 'Camera switched.');
  });
  capture.addEventListener?.('statechange', event => {
    const detail = event.detail || {};
    if (detail.activeCameraId || ['ready', 'sources-changed', 'disabled'].includes(detail.state)) renderCameraSources(detail.activeCameraId || capture.activeCameraId || '');
    if (['camera-error', 'switching-unavailable', 'camera-reconnecting'].includes(detail.state) && detail.message) text('camera-switch-status', detail.message);
  });
  window.addEventListener('azh:remote-camera', async event => {
    const { id, stream, label } = event.detail || {};
    if (!id || !stream || !capture.addRemoteCamera) return;
    try { await capture.addRemoteCamera(id, stream, label); renderCameraSources(); await configureCameraSwitching(); toast(`${label || 'Phone camera'} is available for switching.`); }
    catch (error) { fail(error); }
  });
  window.addEventListener('azh:remote-camera-ended', async event => {
    try { await capture.removeRemoteCamera?.(event.detail?.id); renderCameraSources(); await configureCameraSwitching(); }
    catch (error) { fail(error); }
  });
}

async function main() {
  boot = await api('bootstrap'); setCsrf(boot.csrf);
  document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog')?.close()));
  if (page === 'library') { await initLibrary(); return; }
  const id = new URL(location.href).searchParams.get('id');
  if (!id) { location.replace('library.php'); return; }
  interview = await api('interview', null, { id });
  const timelineId = new URL(location.href).searchParams.get('timelineId');
  if (page === 'editor' && interview.production && timelineId) {
    const timeline = await editVersionRequest('timeline',null,{id:timelineId,revision:new URL(location.href).searchParams.get('timelineRevision')},boot.csrf);
    if(timeline.interviewId!==interview.id) throw new Error('This editable cut belongs to another production.');
    const fresh=await api('interview',null,{id:interview.id});interview.takes=fresh.takes || [];interview.revision=fresh.revision;
    interview.timeline=timeline;interview.state.edit=structuredClone(timeline.edit);setTimelineAvailability(timeline,fresh);
  }
  const removedPrivate = normalize(); renderSessionHeader();
  if (removedPrivate) markDirty();
  initReview();
  if (interview.status === 'archived') {
    const banner = document.createElement('div'); banner.className = 'archive-banner'; banner.innerHTML = interview.production ? `This production is archived. <a class="text-button" href="productions.php?projectId=${encodeURIComponent(interview.production.id)}">Restore from Productions</a>` : 'This interview is archived. <button class="text-button" id="restore-current">Restore interview</button>';
    document.querySelector('main').prepend(banner);
    on('restore-current', 'click', async () => { const restored = await api('archiveInterview', { id: interview.id, archived: false }); interview.status = 'active'; interview.revision = restored.revision; banner.remove(); renderSessionHeader(); toast('Interview restored.'); });
  }
  for (const [input, key] of [['interview-title','title'],['interview-model','participantName'],['interview-notes','notes']]) on(input, 'input', () => { interview[key] = $(input).value; markDirty(); });
  on('interview-profile', 'change', () => { interview.modelId = $('interview-profile').value || null; markDirty(); });
  on('save-interview', 'click', () => { if (page === 'editor') syncEditorSettings(true); return saveInterview(true); });
  on('open-editor', 'click', async event => { event.preventDefault(); if (busy) throw new Error('Finish saving the current recording before opening the editor.'); await saveInterview(); location.href = `editor.php?id=${encodeURIComponent(interview.id)}`; });
  document.addEventListener('click', event => {
    const retry = event.target.closest('[data-retry-draft]');
    if (retry) { if (busy || editBusy) return; busy = true; retry.disabled = true; sendDraft(drafts.get(retry.dataset.retryDraft)).catch(fail).finally(() => { busy = false; retry.disabled = false; if (page === 'interview') renderQuestions(); }); }
    const copy = event.target.closest('[data-download-draft]'); if (copy) { const draft = drafts.get(copy.dataset.downloadDraft); download(draft.blob, draft.fileName); }
  });
  if (page === 'interview') await initInterview(); else if (page === 'editor') await initEditor();
  window.azhInterviewContext = { interview, question: chosen };
  window.dispatchEvent(new CustomEvent('azh:interview-ready', { detail: window.azhInterviewContext }));
  window.addEventListener('azh:phone-take-saved', async () => {
    try {
      const fresh = await api('interview', null, { id: interview.id });
      interview.takes = fresh.takes || [];
      for (const take of interview.takes) if (!getQuestion(take.questionId)) interview.state.customQuestions.push({ id: take.questionId, text: take.questionText || take.label || 'Phone footage', category: 'Phone footage', kind: 'open', private: !!take.isPrivate, options: [] });
      if (normalize()) markDirty();
      if (page === 'interview') { renderQuestions(); renderTakes(); } else renderEditor();
      toast(interview.production ? 'Phone footage saved for review in the production.' : 'New phone footage is available in this interview.');
    } catch (error) { fail(error); }
  });
  window.addEventListener('beforeunload', event => { if (dirty || saveRequests || busy || editBusy || editableVersions?.isDirty()) { event.preventDefault(); event.returnValue = ''; } });
  window.addEventListener('pagehide', () => { capture?.disable(); renderer?.stop(); });
}
main().catch(error => { fail(error); const node = document.createElement('div'); node.className = 'error-banner'; node.textContent = error.message; document.querySelector('main')?.prepend(node); });
