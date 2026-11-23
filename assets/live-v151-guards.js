/** v1.51 lifecycle guards. Pure decisions; no camera approval, Program Take,
 * playback, server request, recording change or financial action occurs here.
 */
export function resetViewerForRoomChange({roomChanged=false,connecting=false,hasViewer=false,recovering=false,hasPeer=false}={}) {
  return Boolean(roomChanged && (connecting || hasViewer || recovering || hasPeer));
}

/** Backgrounded browsers throttle video-frame callbacks. Allow decoded frames
 * to resume after foregrounding before classifying a phone as truly stalled.
 * This postpones automatic repair, never makes a nonmoving camera playable.
 */
export function deferForegroundPhoneRepair({visible=true,foregroundAgeMs=Infinity,graceMs=7000}={}) {
  return !visible || (Number.isFinite(foregroundAgeMs) && foregroundAgeMs < graceMs);
}

export function recordingAutoSaveAllowed({bytes=0,recorderError=false,localPlaybackVerified=false}={}) {
  return Number.isFinite(bytes) && bytes >= 1024 && !recorderError && Boolean(localPlaybackVerified);
}

/** Only decoded video (not byte count or just container metadata) can advance
 * a recording to its automatic save stage. No remote audio/lip-sync claim.
 */
export function videoProbeReady({videoWidth=0,videoHeight=0,readyState=0}={}) {
  return videoWidth > 0 && videoHeight > 0 && readyState >= 2;
}
