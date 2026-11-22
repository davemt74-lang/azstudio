let csrf = '';
export function setCsrf(value) { csrf = value || ''; }
export async function api(action, payload = null, query = {}, refreshSession = true) {
  const url = new URL('api.php', location.href);
  url.searchParams.set('action', action);
  for (const [key, value] of Object.entries(query)) url.searchParams.set(key, value);
  const options = { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' };
  if (payload !== null) {
    options.method = 'POST';
    options.headers['X-CSRF-Token'] = csrf;
    if (payload instanceof FormData) options.body = payload;
    else { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(payload); }
  }
  const response = await fetch(url, options);
  let result;
  try { result = await response.json(); } catch { throw new Error(`The server returned an unreadable response (${response.status}). Check the hosting PHP error log.`); }
  if (!response.ok || result.ok === false) {
    if (payload !== null && refreshSession && response.status === 403 && result.error?.code === 'csrf_invalid') {
      const fresh = await api('bootstrap');
      setCsrf(fresh.csrf);
      return api(action, payload, query, false);
    }
    const message = typeof result.error === 'string' ? result.error : result.error?.message || result.message || `Request failed (${response.status}).`;
    const error = new Error(message);
    error.status = response.status;
    error.code = result.error?.code;
    if (response.status === 401) error.message = 'Your login expired. Keep this page open and sign in again in another tab, then retry.';
    throw error;
  }
  return result.data ?? result;
}
export async function uploadVideo(blob, metadata, onProgress = () => {}, onCheckpoint = async () => {}) {
  const { blob: recoveryBlob, savedTake, savedVideo, uploadId, ...fields } = metadata;
  let begin;
  if (uploadId) {
    try { begin = await api('upload', null, { uploadId }); }
    catch (error) { if (error.status !== 404) throw error; }
  }
  if (!begin || !['pending', 'completed'].includes(begin.status)) begin = await api('beginUpload', { ...fields, totalBytes: blob.size, mimeType: blob.type || metadata.mimeType });
  if (Number(begin.totalBytes) !== blob.size) throw new Error('This upload does not match the recovery video. Download the recovery copy and import it again.');
  // Save the server upload ID before transferring bytes. A lost finish response can
  // be retried against this same ID without creating a duplicate take or version.
  await onCheckpoint(begin);
  if (begin.status === 'completed') { onProgress(1); return api('finishUpload', { uploadId: begin.uploadId }); }
  const chunkBytes = Math.max(1, Number(begin.chunkBytes) || 1048576);
  const count = Math.ceil(blob.size / chunkBytes);
  const nextIndex = Math.max(0, Number(begin.nextIndex) || 0);
  onProgress(Math.min(1, Number(begin.receivedBytes || 0) / blob.size));
  for (let i = nextIndex; i < count; i++) {
    const chunk = blob.slice(i * chunkBytes, Math.min((i + 1) * chunkBytes, blob.size));
    let failure;
    for (let attempt = 0; attempt < 3; attempt++) {
      try {
        const form = new FormData();
        form.set('uploadId', begin.uploadId); form.set('index', String(i));
        form.append('chunk', chunk, 'chunk.bin');
        await api('uploadChunk', form); failure = null; break;
      } catch (error) { failure = error; if (error.status === 401 || error.status === 403) break; await new Promise(r => setTimeout(r, 350 * (attempt + 1))); }
    }
    if (failure) throw failure;
    onProgress((i + 1) / count);
  }
  return api('finishUpload', { uploadId: begin.uploadId });
}
