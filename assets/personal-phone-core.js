/* Private model phone-camera helpers: resumable uploads, bounded retries, and recovery. */
export function selectRecordingMime(supported) {
  for (const mime of ['video/mp4;codecs=avc1.42E01E,mp4a.40.2','video/webm;codecs=vp9,opus','video/webm;codecs=vp8,opus','video/mp4','video/webm']) {
    if (supported(mime))return mime;
  }
  return '';
}
export function phoneUploadIdentity(id,token){return /^[a-f0-9]{32}$/.test(String(id))&&/^[a-f0-9]{64}$/.test(String(token));}
export function cameraUploadName(file){return String(file?.name||'camera-recording.webm').replace(/[\r\n]/g,'').slice(0,250);}
export function cameraUploadType(file){return String(file?.type||'application/octet-stream').split(';')[0].trim().toLowerCase();}
export function cameraFileSupported(file){return Boolean(file&&file.size>0&&['video/mp4','video/quicktime','video/webm','video/ogg','application/octet-stream'].includes(cameraUploadType(file)));}
export function randomId(){return globalThis.crypto?.randomUUID?.().replaceAll('-','')||Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('');}
export function cameraFileFingerprint(file){return `${cameraUploadName(file)}|${Number(file?.size)||0}|${Number(file?.lastModified)||0}|${cameraUploadType(file)}`;}
export function isRetryablePhoneUploadError(error){
  const status=Number(error?.httpStatus||0),code=String(error?.code||'');
  if(['upload_busy','chunk_order'].includes(code))return true;
  return status===0||status===408||status===425||status===429||status===500||status===502||status===503||status===504;
}
export function phoneUploadErrorMessage(error){
  const status=Number(error?.httpStatus||0),code=String(error?.code||''),message=String(error?.message||'Unknown upload error.');
  if(code==='camera_disconnected'||code==='camera_pending'||code==='camera_auth_invalid'||status===401)return 'Camera authorization expired or was revoked. Reconnect using a new editor QR code. '+message;
  if(code==='origin_invalid'||code==='https_required')return 'The phone upload was rejected by the website connection check. Confirm both devices use the HTTPS site and the same domain. '+message;
  if(status===403)return 'The server refused the upload (HTTP 403). Check the host security/ModSecurity logs and camera approval. '+message;
  if(status===413||code==='upload_too_large')return 'The host rejected this upload size (HTTP 413). Check PHP upload limits and the server security rules. '+message;
  if(status===507||code==='quota_exceeded'||code==='storage_full')return 'Storage or quota prevented the upload. '+message;
  if(status===0)return 'Network connection failed. Keep this page open and retry on a stable connection. '+message;
  return message;
}
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const retryPause=(attempt)=>Math.min(2000,250*Math.pow(2,attempt-1));
async function withRetry(operation,stage,{onRetry,maxRetries}){
  for(let attempt=0;;attempt++){
    try{return await operation();}catch(error){
      if(attempt>=maxRetries||!isRetryablePhoneUploadError(error))throw error;
      onRetry({stage,attempt:attempt+1,maxRetries,error});
      await sleep(retryPause(attempt+1));
    }
  }
}
/**
 * The same client token is safe to retry. After a lost chunk response, ask the
 * server for its authoritative checkpoint before ever resending a chunk.
 * This avoids double writes on unreliable mobile networks.
 */
export async function uploadToPersonalGallery(api,file,title,{
  onProgress=()=>{},onCheckpoint=()=>{},onStage=()=>{},onRetry=()=>{},
  token=randomId(),maxRetries=3,preferredChunkBytes=262144
}={}){
  if(!cameraFileSupported(file))throw Error('Choose a supported video (MP4, MOV, WebM or Ogg).');
  const fileName=cameraUploadName(file);
  onStage('starting');
  let checkpoint=await withRetry(()=>api('beginUpload',{
    clientToken:token,title:String(title||fileName).trim().slice(0,200),fileName,
    totalBytes:file.size,mimeType:cameraUploadType(file),preferredChunkBytes
  }),'start',{onRetry,maxRetries});
  onCheckpoint(checkpoint);onProgress(Number(checkpoint.receivedBytes)||0,file.size);
  while(checkpoint.status!=='completed'&&checkpoint.receivedBytes<file.size){
    const offset=Number(checkpoint.receivedBytes),size=Number(checkpoint.chunkBytes);
    if(!Number.isSafeInteger(offset)||!Number.isSafeInteger(size)||size<1||offset<0||offset>=file.size)
      throw Error('Invalid saved upload checkpoint.');
    const index=Number(checkpoint.nextIndex);
    if(!Number.isSafeInteger(index)||index<0)throw Error('Invalid next upload position.');
    const part=file.slice(offset,Math.min(offset+size,file.size));
    const form=new FormData();form.append('uploadId',checkpoint.uploadId);form.append('index',String(index));
    // No .bin extension: some shared-host upload filters reject generic .bin files.
    // The server validates the completed video bytes before adding it to a gallery.
    form.append('chunk',part,'camera-part');
    onStage('uploading');
    let completed=false;
    for(let attempt=0;!completed;attempt++){
      try{
        const next=await api('uploadChunk',form);
        if(Number(next.receivedBytes)<offset||Number(next.receivedBytes)>file.size)throw Error('Server returned an invalid upload position.');
        checkpoint=next;completed=true;
      }catch(error){
        // Ask the server if it committed the chunk before the response was lost.
        // Only retry if it still reports the same verified position.
        let latest;
        try{latest=await api('uploadStatus',null,{uploadId:checkpoint.uploadId});}catch{
          // Keep the original failure, which usually identifies the real issue.
        }
        if(latest?.status==='completed'||(latest&&Number(latest.receivedBytes)>offset)){
          checkpoint=latest;completed=true;break;
        }
        if(latest&&Number(latest.receivedBytes)!==offset)throw Error('The server checkpoint changed unexpectedly. Keep the recording and retry.');
        if(attempt>=maxRetries||!isRetryablePhoneUploadError(error))throw error;
        onRetry({stage:'chunk',attempt:attempt+1,maxRetries,error});
        await sleep(retryPause(attempt+1));
      }
    }
    onCheckpoint(checkpoint);onProgress(Number(checkpoint.receivedBytes)||0,file.size);
  }
  onStage('finalizing');
  // A completed upload can be read again with finishUpload. Idempotent server
  // finalization also allows recovering a lost final success response.
  const saved=await withRetry(()=>api('finishUpload',{uploadId:checkpoint.uploadId}),'finish',{onRetry,maxRetries});
  onStage('completed');onProgress(file.size,file.size);
  return saved;
}
