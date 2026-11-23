/**
 * Pure Live Director / fan session safeguards.
 * These return decisions and labels only; never switch Program, stream media,
 * approve cameras or modify recording files or payment state.
 */
export function validViewerSessionId(id){
  return typeof id==='string'&&/^[a-f0-9]{32}$/.test(id);
}
let lastIssuedJoinMs=0;
/** Embed a monotonic start timestamp in the first 12 hex digits of a 32-byte
 * viewer nonce. The remaining 20 hex digits are random (80 bits). This permits
 * PHP to reject an older in-flight Join that arrives after a newer session.
 * Existing random nonces remain supported by the server.
 */
export function orderedViewerNonce(now=Date.now(),newRandom=()=>globalThis.crypto?.randomUUID?.().replaceAll('-','')){
  const millis=Math.max(Math.floor(now),lastIssuedJoinMs+1);
  lastIssuedJoinMs=millis;
  const suffix=(newRandom()||'').replaceAll('-','').toLowerCase();
  if(!/^[a-f0-9]{32}$/.test(suffix))throw Error('Secure random viewer nonce is required.');
  return millis.toString(16).padStart(12,'0').slice(-12)+suffix.slice(12);
}
export function lateViewerJoinNeedsLeave(joined,version,currentEpoch){
  return version!==currentEpoch&&validViewerSessionId(joined?.viewerId);
}
export function recordingStartBlockReason({
  onAir=false, starting=false, finalizing=false, saving=false,
  recorderState='inactive', backupPending=false
}={}){
  if(!onAir)return 'Start a live broadcast before recording Program.';
  if(starting||finalizing)return 'The previous recording is still starting or finalizing. Recording a second clip now could overwrite its data.';
  if(saving)return 'Your previous recording is still being saved. Keep this page open until it finishes.';
  if(backupPending)return 'Your previous recording is not yet verified and is still stored in this tab. Save or download the backup, or explicitly discard it, before recording another clip.';
  if(recorderState==='recording'||recorderState==='paused')return 'A Program recording is already active.';
  if(recorderState!=='inactive')return 'The recording device is not ready to start a new clip.';
  return null;
}
export function mixerReadiness({
  programTrack=false,contextState='unavailable',hostMic=false,cameraMic=false
}={}){
  if(!programTrack)return {state:'unknown',message:'Program audio track is not available. Check browser audio support before relying on sound.'};
  if(contextState!=='running')return {state:'attention',
    message:contextState==='suspended'||contextState==='interrupted'
      ?'Program audio mixer is suspended. Use Resume mixer, then verify sound on a separate fan device.'
      :'Program audio mixer is not running. Check browser audio permissions and resume it manually.'};
  if(!hostMic&&!cameraMic)return {state:'attention',
    message:'Program audio mixer is running, but no microphone input is active. Enable host microphone or the approved phone audio source, then test remotely.'};
  return {state:'ready',
    message:'Program audio mixer is running with an active input. This does not prove a remote fan can hear it; test fan audio separately.'};
}
