import { api, setCsrf } from './api.js';
import { getVideoMetadata } from './media-engine.js';
import { phoneDraftGet, phoneDraftPut, phoneDraftDelete, uploadPhoneDraft } from './phone-storage.js';
import { PhoneLiveCamera, HostPhoneFeeds } from './phone-live.js';

const $ = id => document.getElementById(id);
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const validId = value => /^[a-f0-9]{32}$/.test(String(value || ''));
const validToken = value => /^[a-f0-9]{64}$/.test(String(value || ''));
const text = (id, value) => { if ($(id)) $(id).textContent = value; };
const show = (id, visible) => { if ($(id)) $(id).hidden = !visible; };
const size = value => Number(value) >= 1073741824 ? `${(Number(value) / 1073741824).toFixed(1)} GB` : `${(Number(value) / 1048576).toFixed(1)} MB`;
const clock = value => { const seconds = Math.round(Number(value) / 1000) || 0; return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`; };
let pageAbort = new AbortController(), activePage = true;

function listen(id, event, handler, failed = error => console.error(error)) {
  $(id)?.addEventListener(event, e => Promise.resolve(handler(e)).catch(failed));
}
function sessionRead(key) { try { return JSON.parse(sessionStorage.getItem(key) || 'null'); } catch { return null; } }
function sessionWrite(key, value) { try { sessionStorage.setItem(key, JSON.stringify(value)); return true; } catch { return false; } }

function makeRequest({ role, subject, token = () => '', csrf = async () => '' }) {
  return async function request(action, payload = null, query = {}, options = {}, refreshed = false) {
    const url = new URL('phone-api.php', location.href); url.searchParams.set('role', role); url.searchParams.set('action', action);
    const context = action === 'claim' ? {} : subject();
    const headers = { Accept: 'application/json' };
    const config = { method: payload === null ? 'GET' : 'POST', credentials: role === 'phone' ? 'omit' : 'same-origin', mode: 'same-origin', cache: 'no-store', headers };
    if (role === 'phone' && token()) headers['X-Phone-Token'] = token();
    if (payload === null) Object.entries({ ...context, ...query }).forEach(([key, value]) => url.searchParams.set(key, String(value)));
    else {
      if (role === 'host') headers['X-CSRF-Token'] = await csrf(refreshed);
      if (payload instanceof FormData) { Object.entries(context).forEach(([key, value]) => payload.set(key, String(value))); config.body = payload; }
      else { headers['Content-Type'] = 'application/json'; config.body = JSON.stringify({ ...payload, ...context }); }
    }
    if (options.keepalive) config.keepalive = true;
    const controller = new AbortController(); config.signal = controller.signal;
    const abort = () => controller.abort(options.signal?.reason || new DOMException('Request interrupted.', 'AbortError'));
    const pageSignal = pageAbort.signal;
    options.signal?.addEventListener('abort', abort, { once: true });
    if (!options.keepalive) pageSignal.addEventListener('abort', abort, { once: true });
    if (options.signal?.aborted || (!options.keepalive && pageSignal.aborted)) abort();
    let timedOut = false;
    const timer = setTimeout(() => { timedOut = true; controller.abort(); }, options.timeout || 25000);
    try {
      const response = await fetch(url, config);
      let result;
      try { result = await response.json(); } catch { throw new Error(`The studio returned an unreadable response (${response.status}). Try again in a moment.`); }
      if (!response.ok || !result.ok) {
        if (role === 'host' && response.status === 403 && result.error?.code === 'csrf_invalid' && !refreshed) return request(action, payload, query, options, true);
        const error = new Error(result.error?.message || `This request failed (${response.status}).`); error.status = response.status; error.code = result.error?.code; throw error;
      }
      return result.data;
    } catch (error) {
      if (timedOut) throw new Error('The studio took too long to respond. Your local clip is still available; retry when the connection improves.');
      throw error;
    } finally {
      clearTimeout(timer); options.signal?.removeEventListener('abort', abort); pageSignal.removeEventListener('abort', abort);
    }
  };
}

async function initHost() {
  if (!$('phone-connection-content')) return;
  let interview = null, csrfValue = '', hostTimer, hostRunning = false, hostQueued = false, hostFailures = 0, lastTakeCount = null, lastList = '', roomState = null, invitation = null, expiryTimer, pendingQuestion = null, contextRunning = false;
  const context = $('phone-connection-content');
  context.innerHTML = '<div class="phone-host-body"><div class="phone-host-summary"><p id="phone-host-status" role="status">Loading phone connections…</p><div class="action-group"><button id="phone-host-refresh" type="button" class="button button--small">Refresh</button><button id="phone-host-close" type="button" class="button button--small" hidden>Disconnect all</button></div></div><p id="phone-host-error" class="phone-host-error" role="status" hidden></p><div id="phone-connection-list" class="phone-connection-list"></div><div id="phone-live-grid" class="phone-live-grid"></div><p class="phone-host-footer">Approve each phone before it can send footage. Up to 8 live views can be connected at once.</p></div>';
  const error = failure => { if (failure.name === 'AbortError') return; text('phone-host-error', failure.message); show('phone-host-error', true); };
  const request = makeRequest({ role: 'host', subject: () => ({ interviewId: interview?.id || '' }), csrf: async refresh => {
    if (!csrfValue || refresh) { const boot = await api('bootstrap'); csrfValue = boot.csrf; setCsrf(boot.csrf); }
    return csrfValue;
  } });
  const feeds = new HostPhoneFeeds({
    request,
    onView(id, view) {
      let card = document.getElementById(`phone-feed-${id}`);
      if (!card) {
        card = document.createElement('section'); card.id = `phone-feed-${id}`; card.className = 'phone-feed';
        const video = document.createElement('video'); video.autoplay = true; video.muted = true; video.playsInline = true; video.setAttribute('aria-label', 'Live phone camera preview');
        const copy = document.createElement('div'); copy.append(document.createElement('strong'), document.createElement('p')); copy.lastChild.setAttribute('role', 'status'); card.append(video, copy); $('phone-live-grid').append(card);
      }
      card.querySelector('strong').textContent = view.label || 'Phone camera'; card.querySelector('p').textContent = view.message;
      const video = card.querySelector('video');
      if (view.stream && video.srcObject !== view.stream) { video.srcObject = view.stream; video.play().catch(() => {}); }
    },
    onRemove(id) { const card = document.getElementById(`phone-feed-${id}`); if (card) { card.querySelector('video').srcObject = null; card.remove(); } window.dispatchEvent(new CustomEvent('azh:remote-camera-ended', { detail: { id: `phone:${id}` } })); },
    onStream(id, stream, label) { window.dispatchEvent(new CustomEvent('azh:remote-camera', { detail: { id: `phone:${id}`, stream, label } })); },
  });
  function renderConnections(data) {
    roomState = data;
    const connected = (data.connections || []).filter(phone => ['pending', 'approved'].includes(phone.status));
    const approved = connected.filter(phone => phone.status === 'approved').length;
    const pending = connected.filter(phone => phone.status === 'pending').length;
    text('phone-host-status', data.enabled ? `${approved} approved ${approved === 1 ? 'phone' : 'phones'}${pending ? ` · ${pending} waiting for approval` : ''}` : 'Phone access is closed. Open an interview QR code to invite the team.');
    show('phone-host-close', data.enabled || connected.length > 0);
    const signature = JSON.stringify(connected.map(phone => [phone.id, phone.label, phone.status, phone.online]));
    if (signature !== lastList) {
      lastList = signature;
      $('phone-connection-list').innerHTML = connected.length ? connected.map(phone => `<div class="phone-connection-row"><div><strong>${esc(phone.label)}</strong><p>${phone.status === 'pending' ? 'Waiting for your approval' : `Approved · ${phone.online ? 'Online' : 'Not currently online'}`}</p></div><div class="action-group">${phone.status === 'pending' ? `<button type="button" class="button button--primary button--small" data-phone-action="approve" data-phone-id="${esc(phone.id)}">Approve</button><button type="button" class="button button--small" data-phone-action="reject" data-phone-id="${esc(phone.id)}">Decline</button>` : `<button type="button" class="button button--small" data-phone-action="revoke" data-phone-id="${esc(phone.id)}">Disconnect</button>`}</div></div>`).join('') : '<p class="phone-host-empty">Phones appear here after they scan the QR code and request access.</p>';
    }
    feeds.sync(data.enabled ? data.offers : [], data.iceServers || []);
    const count = Number(data.takeCount);
    if (lastTakeCount !== null && count > lastTakeCount) window.dispatchEvent(new CustomEvent('azh:phone-take-saved', { detail: { interviewId: interview.id, takeCount: count } }));
    lastTakeCount = count;
  }
  async function pollHost() {
    clearTimeout(hostTimer);
    if (!activePage || !interview) return;
    if (hostRunning) { hostQueued = true; return; }
    hostRunning = true;
    try { const result = await request('list'); if (!activePage) return; hostFailures = 0; show('phone-host-error', false); renderConnections(result); }
    catch (failure) { if (activePage) { hostFailures++; error(failure); if ([401, 403, 404].includes(failure.status)) feeds.stop(); } }
    finally { hostRunning = false; if (activePage && interview) { const wait = hostQueued ? 0 : Math.min(15000, 2200 * Math.max(1, hostFailures)); hostQueued = false; hostTimer = setTimeout(pollHost, wait); } }
  }
  async function flushQuestion() {
    if (contextRunning || !interview || !pendingQuestion) return;
    contextRunning = true;
    try {
      while (pendingQuestion && activePage) {
        const question = pendingQuestion; pendingQuestion = null;
        try { await request('context', { questionId: String(question.id || 'phone-footage'), questionText: String(question.text || 'Behind the scenes').slice(0, 4000), isPrivate: Boolean(question.private) }); }
        catch (failure) { if (!pendingQuestion) pendingQuestion = question; error(failure); break; }
      }
    } finally { contextRunning = false; }
  }
  function ready(detail) {
    if (!validId(detail?.interview?.id)) return;
    if (interview?.id && interview.id !== detail.interview.id) { feeds.stop(); lastTakeCount = null; }
    interview = detail.interview;
    pendingQuestion = detail.question || pendingQuestion;
    pollHost(); flushQuestion();
  }
  function expiration() {
    if (!invitation) return;
    const remaining = Math.max(0, invitation.expiresAt - Math.floor(Date.now() / 1000));
    text('phone-invite-expiry', remaining ? `This code expires in ${Math.ceil(remaining / 60)} ${Math.ceil(remaining / 60) === 1 ? 'minute' : 'minutes'}.` : 'This invitation expired. Refresh the code to invite another phone.');
    $('phone-pairing-content').classList.toggle('phone-pairing-expired', remaining === 0);
    if (!remaining) { clearInterval(expiryTimer); $('phone-copy-invite').disabled = true; }
  }
  async function invite() {
    if (!interview) throw new Error('Wait for the interview to finish loading.');
    const dialog = $('phone-pairing-dialog'); if (!dialog.open) dialog.showModal();
    $('phone-pairing-content').innerHTML = '<p class="field-help">Creating an invitation for this interview…</p>';
    clearInterval(expiryTimer);
    try {
      const result = await request('invite', {}); const url = new URL(result.url, location.href);
      if (url.origin !== location.origin || !window.LuckyQR?.svg) throw new Error('The local QR generator could not be loaded. Reload the studio page.');
      invitation = { ...result, url: url.href };
      $('phone-pairing-content').className = 'phone-pairing-body';
      $('phone-pairing-content').innerHTML = `<p>Scan this code with a phone camera, enter a name, then approve the phone here.</p><div class="phone-qr">${window.LuckyQR.svg(url.href)}</div><p id="phone-invite-expiry" class="phone-invite-expiry" role="status"></p><label class="field">Invitation link<input id="phone-invite-link" type="text" readonly value="${esc(url.href)}"></label><div class="phone-pairing-actions"><button id="phone-copy-invite" type="button" class="button">Copy link</button><button id="phone-renew-invite" type="button" class="button">Refresh code</button></div><p class="field-help">Refreshing the code keeps approved phones connected. Every new phone still needs your approval.</p>`;
      listen('phone-copy-invite', 'click', async () => { try { await navigator.clipboard.writeText(invitation.url); text('phone-invite-expiry', 'Invitation link copied.'); } catch { $('phone-invite-link').focus(); $('phone-invite-link').select(); text('phone-invite-expiry', 'Select and copy the invitation link above.'); } }, error);
      listen('phone-renew-invite', 'click', invite, error); expiration(); expiryTimer = setInterval(expiration, 15000); await flushQuestion(); pollHost();
    } catch (failure) { $('phone-pairing-content').innerHTML = `<p class="phone-host-error">${esc(failure.message)}</p><button type="button" id="phone-invite-retry" class="button">Try again</button>`; listen('phone-invite-retry', 'click', invite, error); throw failure; }
  }
  window.addEventListener('azh:interview-ready', event => ready(event.detail));
  window.addEventListener('azh:question-changed', event => { if (!interview || event.detail?.interviewId === interview.id) { pendingQuestion = event.detail?.question; flushQuestion(); } });
  listen('open-phone-pairing', 'click', invite, error); listen('phone-host-refresh', 'click', async () => { await flushQuestion(); await pollHost(); }, error);
  listen('phone-host-close', 'click', async () => {
    if (!confirm('Disconnect all phones and close the invitation for this interview?')) return;
    await request('close', {}); feeds.stop(); invitation = null; clearInterval(expiryTimer); $('phone-pairing-dialog').close(); await pollHost();
  }, error);
  listen('phone-connection-list', 'click', async event => {
    const button = event.target.closest('[data-phone-action]'); if (!button || button.disabled) return;
    button.disabled = true;
    try { await request(button.dataset.phoneAction, { id: button.dataset.phoneId }); if (button.dataset.phoneAction !== 'approve') feeds.remove(button.dataset.phoneId); await pollHost(); }
    finally { if (button.isConnected) button.disabled = false; }
  }, error);
  window.addEventListener('pagehide', () => { clearTimeout(hostTimer); clearInterval(expiryTimer); feeds.stop(); });
  window.addEventListener('pageshow', event => { if (event.persisted) { pollHost(); if (invitation) expiryTimer = setInterval(expiration, 15000); } });
  if (window.azhInterviewContext) ready(window.azhInterviewContext);
  else setTimeout(async () => {
    if (interview || !activePage) return;
    const id = new URLSearchParams(location.search).get('id'); if (!validId(id)) return;
    try { const loaded = await api('interview', null, { id }); ready({ interview: loaded, question: window.azhInterviewContext?.question }); }
    catch (failure) { error(failure); }
  }, 1200);
}

async function initPhone() {
  const parameters = new URLSearchParams(location.search), fragment = new URLSearchParams(location.hash.slice(1));
  const inviteId = parameters.get('invite') || '';
  let key = fragment.get('key') || '', capability = null, status = null, statusTimer, statusRunning = false, statusFailures = 0, terminal = false, draft = null, clipURL = '', selecting = false, cancelling = false, uploadController = null, uploadPromise = null, durable = true;
  const invitationKey = `azhottie:phone:invitation:${inviteId}`;
  const mapKey = `azhottie:phone:invite:${inviteId}`;
  if (validToken(key)) sessionWrite(invitationKey, key); else key = sessionRead(invitationKey) || '';
  const interviewId = sessionRead(mapKey);
  if (validId(interviewId)) {
    const saved = sessionRead(`azhottie:phone:${interviewId}:${inviteId}`);
    if (validId(saved?.id) && validToken(saved?.token) && saved.interviewId === interviewId && saved.inviteId === inviteId) capability = saved;
  }
  const request = makeRequest({ role: 'phone', subject: () => ({ id: capability?.id || '' }), token: () => capability?.token || '' });
  function notice(message, error = false) { text('phone-notice', message); $('phone-notice').classList.toggle('is-error', error); }
  function failure(error) { if (error.name !== 'AbortError') notice(error.message, true); }
  function approved() { return status?.connection?.status === 'approved' && !terminal; }
  const live = new PhoneLiveCamera({ request, getStatus: () => status,
    onState(state) {
      text('phone-live-status', state.message || 'Your live camera is off.'); text('phone-live-badge', state.state === 'live' ? 'Live' : ['connecting', 'reconnecting'].includes(state.state) ? 'Connecting' : 'Off');
      $('phone-live-badge').classList.toggle('phone-live-is-on', state.state === 'live');
      const sharing = ['connecting', 'live', 'reconnecting'].includes(state.state);
      show('phone-live-start', !sharing); show('phone-live-stop', sharing); $('phone-facing').disabled = sharing;
      $('phone-live-start').disabled = !approved() || !status?.hostOnline;
    },
    onStream(stream) { const player = $('phone-live-preview'); player.srcObject = stream; show('phone-live-preview', Boolean(stream)); if (stream) player.play().catch(() => {}); },
  });
  async function persist() {
    if (!draft || draft.saved) return;
    try { await phoneDraftPut(draft); durable = true; text('phone-recovery-status', 'A recovery copy is saved in this browser until the studio confirms the upload.'); }
    catch { durable = false; text('phone-recovery-status', 'This browser could not keep a recovery copy. Keep this page open, or download the local clip before leaving.'); }
  }
  function repaintClip() {
    show('phone-clip', Boolean(draft));
    if (!draft) { if (clipURL) URL.revokeObjectURL(clipURL); clipURL = ''; const player = $('phone-clip-preview'); player.pause(); player.removeAttribute('src'); player.load(); return; }
    if (clipURL) URL.revokeObjectURL(clipURL); clipURL = URL.createObjectURL(draft.blob);
    $('phone-clip-preview').src = clipURL; $('phone-download-local').href = clipURL; $('phone-download-local').download = draft.fileName;
    $('phone-clip-label').value = draft.label || ''; $('phone-duration').value = draft.durationMs ? String(draft.durationMs / 1000) : '';
    text('phone-clip-meta', `${draft.fileName} · ${size(draft.blob.size)}${draft.durationMs ? ` · ${clock(draft.durationMs)}` : ''}`);
    show('phone-duration-field', !draft.durationMs); updateControls();
  }
  function updateControls() {
    const uploading = Boolean(uploadController) || cancelling;
    show('phone-upload', approved() || Boolean(draft)); show('phone-live', approved());
    for (const id of ['phone-camera-file', 'phone-gallery-file']) $(id).disabled = !approved() || uploading || selecting;
    $('phone-send').disabled = !approved() || !draft || uploading || selecting || Boolean(draft.saved);
    text('phone-send', draft?.saved ? 'Saved to interview' : draft?.uploadId ? 'Resume upload' : 'Upload to interview');
    show('phone-cancel-upload', uploading); $('phone-discard').disabled = uploading || selecting;
    $('phone-clip-label').disabled = uploading || Boolean(draft?.uploadId) || Boolean(draft?.saved);
    $('phone-duration').disabled = uploading || Boolean(draft?.uploadId) || Boolean(draft?.saved);
    $('phone-live-start').disabled = !approved() || !status?.hostOnline;
  }
  function disconnect(message) {
    terminal = true; clearTimeout(statusTimer); live.stop(message); uploadController?.abort(new DOMException('Phone connection ended.', 'AbortError'));
    status = status ? { ...status, connection: { ...status.connection, status: 'disconnected' } } : null;
    text('phone-approval-badge', 'Disconnected'); text('phone-session-status', 'Scan a fresh interview QR code to request access again.');
    notice(message, true); updateControls();
  }
  async function pollStatus() {
    clearTimeout(statusTimer);
    if (!activePage || !capability || terminal || statusRunning) return;
    statusRunning = true;
    try {
      const next = await request('status'); if (!activePage) return;
      status = next; statusFailures = 0; show('phone-join', false); show('phone-session', true);
      text('phone-interview-title', next.interview?.title || 'Interview');
      const isApproved = approved();
      text('phone-approval-badge', isApproved ? 'Approved' : 'Waiting');
      text('phone-session-status', isApproved ? next.hostOnline ? 'The host’s recording room is open.' : 'The host’s recording room is not currently open. You can still upload a recorded clip.' : 'Keep this page open while the host approves your phone.');
      text('phone-question-text', next.question?.text || 'Behind the scenes'); show('phone-question-wrap', isApproved); show('phone-question-private', Boolean(next.question?.private));
      if (!draft?.saved && !uploadController) notice(isApproved ? 'You’re connected. Choose a clip or share your live camera when you’re ready.' : 'Request sent. Waiting for the host to approve this phone.');
      updateControls();
    } catch (error) {
      if (!activePage || error.name === 'AbortError') return;
      statusFailures++;
      if ([401, 403, 404].includes(error.status)) disconnect(error.message);
      else { notice(`Connection interrupted. Retrying… ${draft ? 'Your local clip is still available.' : ''}`, true); if (statusFailures >= 4) live.stop('The studio is unreachable. Your camera has stopped; try again when the connection returns.'); }
    } finally { statusRunning = false; if (activePage && capability && !terminal) statusTimer = setTimeout(pollStatus, Math.min(15000, 2500 * Math.max(1, statusFailures))); }
  }
  async function restoreDraft() {
    if (!capability) return;
    try { draft = await phoneDraftGet(`${capability.interviewId}:${capability.id}`) || null; }
    catch { durable = false; }
    if (draft?.blob instanceof Blob) { repaintClip(); text('phone-recovery-status', 'Recovered an unfinished clip from this browser. Resume uploading or download a local copy.'); }
    else draft = null;
  }
  async function claim(event) {
    event.preventDefault(); if (!$('phone-join-form').reportValidity()) return;
    $('phone-join-button').disabled = true;
    try {
      const result = await request('claim', { inviteId, key, label: $('phone-label').value.trim() });
      if (!validId(result.id) || !validId(result.interviewId) || !validToken(result.token)) throw new Error('The studio returned an invalid connection. Scan the QR code again.');
      capability = { ...result, inviteId, label: $('phone-label').value.trim() }; terminal = false;
      const stored = sessionWrite(`azhottie:phone:${result.interviewId}:${inviteId}`, capability) && sessionWrite(mapKey, result.interviewId);
      if (!stored) notice('Request sent. Keep this page open; this browser could not remember the connection for refresh.');
      const clean = new URL(location.href); clean.hash = ''; history.replaceState(null, '', clean);
      show('phone-join', false); show('phone-session', true); text('phone-interview-title', 'Waiting for approval');
      await restoreDraft(); await pollStatus();
    } finally { $('phone-join-button').disabled = false; }
  }
  async function abandonDraft() {
    if (!draft?.uploadId || draft.saved) return;
    const result = await request('abandonUpload', { uploadId: draft.uploadId });
    if (result.completed) {
      draft.saved = true; draft.takeId = result.takeId;
      await phoneDraftDelete(draft.key).catch(() => {});
      text('phone-recovery-status', 'The studio confirmed this clip before cancellation. It remains saved to the interview.');
      return;
    }
    if (!result.abandoned) throw new Error('The studio did not confirm that this upload was cancelled.');
    draft.uploadId = null; await persist();
  }
  async function pickFile(file, input) {
    input.value = ''; if (!file || !approved() || uploadController || selecting) return;
    if (draft && !draft.saved && !confirm('Replace the unfinished clip? Download a local copy first if you want to keep it.')) return;
    const mime = file.type || ({ mp4: 'video/mp4', mov: 'video/quicktime', webm: 'video/webm', ogv: 'video/ogg' }[file.name.split('.').pop().toLowerCase()] || 'application/octet-stream');
    const maximum = Number(status?.limits?.maxUploadBytes) || 2147483648;
    if ((file.type && !file.type.startsWith('video/')) || file.size < 1 || file.size > maximum) throw new Error(`Choose a video smaller than ${size(maximum)}.`);
    selecting = true; updateControls(); live.stop('Live camera stopped while you prepare a recorded clip.');
    try {
      if (draft?.uploadId && !draft.saved) await abandonDraft();
      const old = draft;
      draft = { key: `${capability.interviewId}:${capability.id}`, connectionId: capability.id, interviewId: capability.interviewId, blob: file, fileName: file.name || 'phone-video.mp4', mimeType: mime, label: '', durationMs: 0, uploadId: null, createdAt: new Date().toISOString() };
      if (old?.key && old.key !== draft.key) await phoneDraftDelete(old.key).catch(() => {});
      await persist(); repaintClip(); text('phone-file-status', 'Reading your video…'); show('phone-upload-progress-wrap', false);
      try { const metadata = await getVideoMetadata(file, { timeout: 15000 }); draft.durationMs = Math.round(metadata.duration * 1000); await persist(); text('phone-file-status', 'Your clip is ready to upload.'); }
      catch { text('phone-file-status', 'This browser could not read the video’s duration. You can enter it below or upload the original for the host to review.'); }
      repaintClip();
    } finally { selecting = false; updateControls(); }
  }
  async function send() {
    if (!draft || draft.saved || !approved() || uploadController || selecting || cancelling) return;
    draft.label = $('phone-clip-label').value.trim();
    if (!draft.durationMs) draft.durationMs = Math.max(0, Math.round(Number($('phone-duration').value || 0) * 1000));
    await persist();
    uploadController = new AbortController(); const controller = uploadController;
    updateControls(); show('phone-upload-progress-wrap', true); text('phone-upload-status', 'Checking saved upload progress…');
    notice('Uploading your clip. Keep this page open until it is confirmed.');
    uploadPromise = uploadPhoneDraft(draft, request, { signal: controller.signal, persist, onProgress: ratio => { $('phone-upload-progress').value = Math.round(ratio * 100); text('phone-upload-status', `${Math.round(ratio * 100)}% uploaded${ratio === 1 ? ' · confirming with the studio…' : ''}`); } });
    try {
      const receipt = await uploadPromise; draft.saved = true; draft.takeId = receipt.takeId;
      try { await phoneDraftDelete(draft.key); } catch { /* A stale recovery entry can safely re-check the completed receipt. */ }
      text('phone-upload-status', 'Saved to the interview. The host can now review this clip.'); text('phone-recovery-status', 'The studio confirmed this upload. You can keep a local download or choose your next clip.');
      notice('Your clip is saved to the interview.');
    } catch (error) {
      if (error.name === 'AbortError' || controller.signal.aborted) text('phone-upload-status', 'Upload paused. Your local clip is still available.');
      else { text('phone-upload-status', `Upload paused: ${error.message}`); failure(error); }
      if ([401, 403, 404].includes(error.status) && error.code !== 'phone_upload_not_found') disconnect(error.message);
      await persist();
    } finally { if (uploadController === controller) uploadController = null; uploadPromise = null; updateControls(); }
  }
  async function cancelUpload() {
    if (!uploadController || cancelling) return;
    cancelling = true;
    $('phone-cancel-upload').disabled = true;
    uploadController.abort(new DOMException('Upload cancelled.', 'AbortError'));
    await uploadPromise?.catch(() => {});
    try {
      await abandonDraft();
      text('phone-upload-status', draft?.saved ? 'The studio already confirmed this clip. It is saved to the interview.' : 'Upload cancelled. The local clip is kept so you can upload it again.');
    }
    catch (error) { text('phone-upload-status', 'Upload paused. The studio did not confirm cancellation; Resume upload will check its progress.'); throw error; }
    finally { cancelling = false; $('phone-cancel-upload').disabled = false; updateControls(); }
  }
  async function discard() {
    if (!draft || uploadController || selecting) return;
    if (!draft.saved && !confirm('Remove this unfinished local clip? Download it first if you want to keep it.')) return;
    if (draft.uploadId && !draft.saved && !terminal) await abandonDraft();
    await phoneDraftDelete(draft.key).catch(() => {}); draft = null; repaintClip(); updateControls(); text('phone-file-status', 'Choose or record another clip.');
  }
  listen('phone-join-form', 'submit', claim, failure);
  listen('phone-status-refresh', 'click', pollStatus, failure);
  listen('phone-camera-file', 'change', event => pickFile(event.target.files?.[0], event.target), failure);
  listen('phone-camera-file', 'click', () => { if (live.stream || live.starting) live.stop('Live camera stopped so you can record a clip with your phone.'); }, failure);
  listen('phone-gallery-file', 'change', event => pickFile(event.target.files?.[0], event.target), failure);
  listen('phone-send', 'click', send, failure); listen('phone-cancel-upload', 'click', cancelUpload, failure); listen('phone-discard', 'click', discard, failure);
  listen('phone-clip-label', 'change', async () => { if (draft) { draft.label = $('phone-clip-label').value.trim(); await persist(); } }, failure);
  listen('phone-duration', 'change', async () => { if (draft) { draft.durationMs = Math.max(0, Math.round(Number($('phone-duration').value || 0) * 1000)); await persist(); } }, failure);
  listen('phone-live-start', 'click', async () => { if (!approved()) throw new Error('Wait for the host to approve this phone.'); await live.start($('phone-facing').value); }, failure);
  listen('phone-live-stop', 'click', () => live.stop(), failure);
  window.addEventListener('beforeunload', event => { if (uploadController || (draft && !draft.saved && !durable)) { event.preventDefault(); event.returnValue = ''; } });
  window.addEventListener('pagehide', () => { clearTimeout(statusTimer); uploadController?.abort(new DOMException('Page left.', 'AbortError')); live.stop(); });
  window.addEventListener('pageshow', event => { if (event.persisted) { pollStatus(); updateControls(); } });
  if (!validId(inviteId)) { notice('Scan the QR code shown in an open AZHottie interview to connect this phone.', true); return; }
  if (capability) { await restoreDraft(); show('phone-session', true); text('phone-interview-title', 'Reconnecting to your interview'); await pollStatus(); }
  else if (validToken(key)) { notice('Enter a name so the host can recognize your phone.'); show('phone-join', true); }
  else notice('This invitation link is incomplete. Scan the full QR code from the host’s interview.', true);
}

window.addEventListener('pagehide', () => { activePage = false; pageAbort.abort(new DOMException('Page left.', 'AbortError')); });
window.addEventListener('pageshow', event => { if (event.persisted) { activePage = true; pageAbort = new AbortController(); } });
if (document.body.dataset.page === 'phone') initPhone().catch(error => { text('phone-notice', error.message); $('phone-notice')?.classList.add('is-error'); });
else if (document.body.dataset.page === 'interview') initHost().catch(error => console.error(error));
