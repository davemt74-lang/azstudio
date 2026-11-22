/** Assignment-scoped uploads. Keep this adapter separate from the capture UI. */
const DATABASE = 'azhottie-work-recordings';
const STORE = 'drafts';
const abortError = () => new DOMException('Upload paused. Your recording is kept on this device.', 'AbortError');
const wait = (ms, signal) => new Promise((resolve, reject) => {
  if (signal?.aborted) return reject(abortError());
  const done = () => { signal?.removeEventListener('abort', cancel); resolve(); };
  const timer = setTimeout(done, ms);
  const cancel = () => { clearTimeout(timer); signal?.removeEventListener('abort', cancel); reject(abortError()); };
  signal?.addEventListener('abort', cancel, {once: true});
});

export function workDraftKey(userId, assignmentId, token) {
  return `${encodeURIComponent(String(userId))}:${encodeURIComponent(String(assignmentId))}:${encodeURIComponent(String(token))}`;
}

export function belongsToWorkDraft(draft, userId, assignmentId) {
  return Boolean(draft && String(draft.userId) === String(userId) && draft.assignmentId === assignmentId && draft.id === workDraftKey(userId, assignmentId, draft.clientToken));
}

async function database() {
  if (!globalThis.indexedDB) throw new Error('Browser recovery storage is unavailable. Keep this page open and download a copy of your recording.');
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DATABASE, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE, {keyPath: 'id'});
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error || new Error('Browser recovery storage could not be opened.'));
  });
}

async function transaction(mode, operation) {
  const db = await database();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, mode);
    let result;
    const request = operation(tx.objectStore(STORE));
    request.onsuccess = () => { result = request.result; };
    tx.oncomplete = () => { db.close(); resolve(result); };
    tx.onerror = tx.onabort = () => { db.close(); reject(tx.error || request.error || new Error('Browser recovery storage is full. Download a copy before leaving this page.')); };
  });
}

export async function putWorkDraft(draft) {
  if (!belongsToWorkDraft(draft, draft.userId, draft.assignmentId) || !(draft.blob instanceof Blob)) throw new Error('This recording does not match its recovery identity.');
  return transaction('readwrite', store => store.put({...draft, savedAt: new Date().toISOString()}));
}

export async function listWorkDrafts(userId, assignmentId) {
  return (await transaction('readonly', store => store.getAll())).filter(draft => belongsToWorkDraft(draft, userId, assignmentId)).sort((a,b) => a.createdAt.localeCompare(b.createdAt));
}

export async function deleteWorkDraft(userId, assignmentId, token) {
  return transaction('readwrite', store => store.delete(workDraftKey(userId, assignmentId, token)));
}

async function replaceWorkDraft(previous,replacement) {
  if(!belongsToWorkDraft(previous,previous.userId,previous.assignmentId) || !belongsToWorkDraft(replacement,previous.userId,previous.assignmentId) || !(replacement.blob instanceof Blob) || previous.id===replacement.id) throw new Error('The replacement recording does not match its assignment.');
  // The original recording is deleted only in the same successful IndexedDB
  // commit that stores the replacement. A quota/write failure keeps it intact.
  return transaction('readwrite',store=>{store.put({...replacement,savedAt:new Date().toISOString()});return store.delete(previous.id);});
}

export async function restartWorkDraft(previous,{abandon,replace=replaceWorkDraft,makeToken=()=>globalThis.crypto?.randomUUID?.()||`recording-${Date.now()}-${Math.random().toString(36).slice(2)}`}={}) {
  if(!(previous?.blob instanceof Blob))throw new Error('This local recording is unavailable. Download a recovery copy before leaving this page.');
  const clientToken=makeToken();
  const replacement={...previous,clientToken,id:workDraftKey(previous.userId,previous.assignmentId,clientToken),uploadId:null,receivedBytes:0,status:'Ready to restart · recording kept',error:'',errorCode:'',persisted:true};
  if(previous.uploadId){
    if(typeof abandon!=='function')throw new Error('The old upload must be closed before restarting it.');
    const receipt=await abandon(previous.assignmentId,previous.uploadId);
    if(receipt?.completed)throw new Error('The studio already saved this take. Resume the existing upload to recover its receipt.');
    if(receipt?.abandoned!==true)throw new Error('The server has not confirmed the old upload was closed. Your local recording is unchanged; retry when connected.');
  }
  await replace(previous,replacement);
  return replacement;
}

export function createWorkUploadClient({csrf = '', fetcher = (...args) => fetch(...args)} = {}) {
  let csrfToken = csrf;
  async function request(action, body = null, query = {}, signal, retryCsrf = true) {
    const url = new URL('work-upload-api.php', globalThis.location?.href || 'http://localhost/');
    url.searchParams.set('action', action);
    Object.entries(query).forEach(([key,value]) => url.searchParams.set(key, String(value)));
    const form = typeof FormData !== 'undefined' && body instanceof FormData;
    const headers = body === null ? {} : {'X-CSRF-Token': csrfToken, ...(form ? {} : {'Content-Type':'application/json'})};
    let response;
    try { response = await fetcher(url.href, {method: body === null ? 'GET':'POST', credentials:'same-origin', cache:'no-store', headers, signal, ...(body===null?{}:{body:form?body:JSON.stringify(body)})}); }
    catch (error) {
      if (signal?.aborted || error.name==='AbortError') throw abortError();
      throw new Error('The connection stopped. Your recording is kept here; retry when you are connected.');
    }
    let result;
    try { result = await response.json(); } catch { throw new Error('The server did not return an upload receipt. Keep the recording and retry.'); }
    if (!response.ok || !result?.ok) {
      if (response.status===403 && result?.error?.code==='csrf_invalid' && retryCsrf) {
        const bootstrapUrl = new URL('work-api.php?action=bootstrap', url);
        const fresh = await fetcher(bootstrapUrl.href,{credentials:'same-origin',cache:'no-store',signal});
        const session = await fresh.json();
        if (fresh.ok && session.ok && session.data.csrf) { csrfToken=session.data.csrf; return request(action,body,query,signal,false); }
      }
      const error = new Error(result?.error?.message || (response.status===401?'Sign in again, then resume your saved recording.':'The upload could not continue.'));
      error.status=response.status; error.code=result?.error?.code || 'upload_failed';
      throw error;
    }
    return result.data;
  }

  async function upload(blob, metadata, {onProgress=()=>{},onCheckpoint=async()=>{},signal}={}) {
    if (!(blob instanceof Blob) || !blob.size) throw new Error('Choose a nonempty video recording.');
    if (!metadata.assignmentId || !metadata.clientToken) throw new Error('This recording is missing its assignment identity.');
    const identity={assignmentId:metadata.assignmentId,clientToken:metadata.clientToken};
    let checkpoint;
    if (metadata.uploadId) {
      try { checkpoint=await request('upload',null,{assignmentId:identity.assignmentId,uploadId:metadata.uploadId},signal); }
      catch(error) { if(error.status!==404) throw error; }
    }
    if (!checkpoint) {
      checkpoint=await request('beginUpload',{...identity,totalBytes:blob.size,mimeType:blob.type||metadata.mimeType||'video/webm',durationMs:metadata.durationMs||0,fileName:metadata.fileName||'production-take.webm',label:metadata.label||''},{},signal);
    }
    if (checkpoint.assignmentId!==identity.assignmentId || Number(checkpoint.totalBytes)!==blob.size) throw new Error('The saved upload does not match this assignment and recording.');
    await onCheckpoint(checkpoint);
    if (signal?.aborted) throw abortError();
    while (checkpoint.status!=='completed' && Number(checkpoint.receivedBytes)<blob.size) {
      const offset=Number(checkpoint.receivedBytes), index=Number(checkpoint.nextIndex), bytes=Number(checkpoint.chunkBytes);
      if (!Number.isInteger(index) || index<0 || !Number.isInteger(bytes) || bytes<1 || !Number.isInteger(offset) || offset<0) throw new Error('The server returned an invalid upload checkpoint.');
      let lastError;
      for (let attempt=0;attempt<3;attempt++) {
        if (signal?.aborted) throw abortError();
        const form=new FormData();
        form.set('assignmentId',identity.assignmentId); form.set('uploadId',checkpoint.uploadId); form.set('index',String(index));
        form.set('chunk',blob.slice(offset,Math.min(blob.size,offset+bytes)),'chunk.bin');
        try { checkpoint=await request('uploadChunk',form,{},signal); lastError=null; break; }
        catch(error) { lastError=error; if(error.name==='AbortError' || (error.status && error.status<500)) throw error; if(attempt<2) await wait(500*(attempt+1),signal); }
      }
      if(lastError) throw lastError;
      if(Number(checkpoint.receivedBytes)<=offset) throw new Error('The server did not advance the upload. Keep this recording and retry.');
      await onCheckpoint(checkpoint); onProgress({receivedBytes:Number(checkpoint.receivedBytes),totalBytes:blob.size,ratio:Number(checkpoint.receivedBytes)/blob.size});
    }
    if(signal?.aborted) throw abortError();
    const receipt=await request('finishUpload',{assignmentId:identity.assignmentId,uploadId:checkpoint.uploadId},{},signal);
    if(!receipt?.saved || typeof receipt.submissionId!=='string' || !receipt.submissionId || receipt.assignmentId!==identity.assignmentId) throw new Error('The server has not confirmed this take was saved to its assignment. Keep the local recording and retry.');
    return receipt;
  }

  return {request,upload,abort:(assignmentId,uploadId)=>request('abandonUpload',{assignmentId,uploadId})};
}
