/* Pure validation shared between the model editor and release tests. */
export function normalizeTrim(startSeconds, endSeconds, durationSeconds) {
  const start = Number(startSeconds), end = Number(endSeconds), duration = Number(durationSeconds);
  if (![start,end,duration].every(Number.isFinite) || start < 0 || end < 0 || duration < 0) throw new Error('Enter valid nonnegative trim times.');
  if (duration === 0) {
    if (start || end) throw new Error('Load the video duration before setting trim points.');
    return {durationMs:0,trimStartMs:0,trimEndMs:0};
  }
  const trimStartMs = Math.round(start*1000);
  const trimEndMs = Math.round(end*1000);
  const durationMs = Math.round(duration*1000);
  if (trimStartMs >= durationMs || trimEndMs > durationMs || trimEndMs > 0 && trimEndMs-trimStartMs < 100) throw new Error('Choose at least 0.1 seconds within the video.');
  return {durationMs,trimStartMs,trimEndMs};
}
export function uploadIdentity(token, size, filename) {
  return Boolean(/^[a-zA-Z0-9._:-]{16,120}$/.test(String(token)) && Number.isSafeInteger(size) && size>0 && typeof filename==='string' && filename.length>0 && filename.length<=250);
}
export function personalMediaMime(mime) {
  return ['', 'application/octet-stream','video/mp4','video/quicktime','video/webm','video/ogg'].includes(String(mime).toLowerCase().split(';')[0].trim());
}
export function originalSelectionDuration(durationMs, trimStartMs, trimEndMs) {
  const duration=Number(durationMs)||0;
  if (duration<=0) return 0;
  return (Math.min(duration,Number(trimEndMs)||duration)-Math.max(0,Number(trimStartMs)||0))/1000;
}
export function formatClock(seconds) {
  const value=Math.max(0,Math.floor(Number(seconds)||0));
  return `${String(Math.floor(value/60)).padStart(2,'0')}:${String(value%60).padStart(2,'0')}`;
}
