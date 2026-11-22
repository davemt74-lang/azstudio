// Phone footage has its own local store and never appears in the admin recorder's drafts.
const DATABASE = 'azhottie-phone-footage-v1';
let connection;
function open() {
  if (!globalThis.indexedDB) return Promise.reject(new Error('This browser cannot keep a recovery copy. Keep this page open until the upload finishes.'));
  return connection ||= new Promise((resolve, reject) => {
    const request = indexedDB.open(DATABASE, 1);
    request.onupgradeneeded = () => request.result.createObjectStore('clips', { keyPath: 'key' });
    request.onsuccess = () => { const db = request.result; db.onversionchange = () => { db.close(); connection = null; }; resolve(db); };
    request.onerror = () => { connection = null; reject(request.error || new Error('The recovery store could not be opened.')); };
  });
}
async function run(mode, operation) {
  const db = await open();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction('clips', mode);
    const request = operation(transaction.objectStore('clips'));
    let result;
    request.onsuccess = () => { result = request.result; };
    transaction.oncomplete = () => resolve(result);
    transaction.onerror = () => reject(transaction.error || request.error || new Error('The local recovery copy could not be saved.'));
    transaction.onabort = () => reject(transaction.error || new Error('The local recovery copy was interrupted.'));
  });
}
export const phoneDraftGet = key => run('readonly', store => store.get(key));
export const phoneDraftPut = draft => run('readwrite', store => store.put(draft));
export const phoneDraftDelete = key => run('readwrite', store => store.delete(key));

function check(signal) { if (signal?.aborted) throw signal.reason || new DOMException('Upload paused.', 'AbortError'); }
function delay(ms, signal) {
  return new Promise((resolve, reject) => {
    check(signal);
    const finish = () => { signal?.removeEventListener('abort', abort); resolve(); };
    const timer = setTimeout(finish, ms);
    const abort = () => { clearTimeout(timer); signal?.removeEventListener('abort', abort); reject(signal.reason || new DOMException('Upload paused.', 'AbortError')); };
    signal?.addEventListener('abort', abort, { once: true });
  });
}

/** Resume from the server's committed chunk index; never delete a local blob on failure. */
export async function uploadPhoneDraft(draft, request, { persist = async () => {}, onProgress = () => {}, signal, retryDelay = 700 } = {}) {
  check(signal);
  let upload;
  if (draft.uploadId) {
    try { upload = await request('upload', null, { uploadId: draft.uploadId }, { signal }); }
    catch (error) {
      if (error.status !== 404) throw error;
      draft.uploadId = null; await persist(draft);
    }
  }
  check(signal);
  if (!upload) {
    draft.clientToken ||= globalThis.crypto?.randomUUID?.() || `phone-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    await persist(draft);
    upload = await request('beginUpload', { clientToken: draft.clientToken, label: draft.label || '', fileName: draft.fileName, mimeType: draft.mimeType || draft.blob.type || 'application/octet-stream', totalBytes: draft.blob.size, durationMs: draft.durationMs || 0 }, {}, { signal });
    draft.uploadId = upload.uploadId; await persist(draft);
  }
  if (!/^[a-f0-9]{32}$/.test(String(upload.uploadId || ''))) throw new Error('The server did not return a valid upload receipt.');
  if (Number(upload.totalBytes) !== draft.blob.size) throw new Error('The saved upload belongs to a different file. Cancel it before starting again.');
  if (upload.status === 'completed') {
    const result = await request('finishUpload', { uploadId: upload.uploadId }, {}, { signal });
    if (!result.saved) throw new Error('The server has not confirmed this clip was saved.');
    onProgress(1); return result;
  }
  if (upload.status !== 'pending') throw new Error('This upload is no longer pending. Cancel it and upload this clip again.');
  const chunkBytes = Number(upload.chunkBytes), first = Number(upload.nextIndex);
  if (!Number.isInteger(chunkBytes) || chunkBytes < 1 || chunkBytes > 1048576 || !Number.isInteger(first) || first < 0) throw new Error('The server returned invalid upload progress.');
  const count = Math.ceil(draft.blob.size / chunkBytes);
  if (first > count) throw new Error('The server returned invalid upload progress.');
  onProgress(Math.min(first * chunkBytes / draft.blob.size, 1));
  for (let index = first; index < count; index++) {
    check(signal);
    const chunk = draft.blob.slice(index * chunkBytes, Math.min((index + 1) * chunkBytes, draft.blob.size));
    let error;
    for (let attempt = 0; attempt < 3; attempt++) {
      check(signal);
      const form = new FormData(); form.set('uploadId', upload.uploadId); form.set('index', String(index)); form.append('chunk', chunk, 'chunk.bin');
      try { await request('uploadChunk', form, {}, { signal, timeout: 90000 }); error = null; break; }
      catch (failure) {
        error = failure;
        if (signal?.aborted || failure.name === 'AbortError' || [400, 401, 403, 404, 413, 415].includes(failure.status)) throw failure;
        if (attempt < 2) await delay(retryDelay * 2 ** attempt, signal);
      }
    }
    if (error) throw error;
    onProgress(Math.min((index + 1) * chunkBytes / draft.blob.size, 1));
  }
  check(signal);
  const result = await request('finishUpload', { uploadId: upload.uploadId }, {}, { signal });
  if (!result.saved) throw new Error('The server has not confirmed this clip was saved. Keep the local copy and retry.');
  onProgress(1); return result;
}
