/** Deterministic helpers shared by the live Director and regression tests. */
export function nextProgramId(ids, current, {random=false, randomNumber=Math.random()}={}) {
  const options = [...new Set(ids.filter(id=>typeof id==='string'&&id.length>0))];
  if (!options.length) return null;
  if (options.length===1) return options[0];
  const candidate = options.filter(id=>id!==current);
  if (random) return candidate[Math.min(candidate.length-1,Math.max(0,Math.floor(randomNumber*candidate.length)))];
  const index=options.indexOf(current);
  return options[(index+1)%options.length];
}
export function switchDelay(mode,{randomNumber=Math.random()}={}) {
  if (mode==='fixed') return 30000;
  if (mode==='random') return 15000 + Math.floor(Math.max(0,Math.min(.999999,randomNumber))*30001);
  return null;
}
export function fanSessionState({room,hostAge,hostTtl=25}={}) {
  return Boolean(room?.id && /^[a-f0-9]{32}$/.test(room.id) && Number.isFinite(hostAge) && hostAge <= hostTtl);
}
export function fitCover(srcW,srcH,targetW,targetH) {
  if (![srcW,srcH,targetW,targetH].every(n=>Number.isFinite(n)&&n>0)) return null;
  const scale=Math.max(targetW/srcW,targetH/srcH),w=srcW*scale,h=srcH*scale;
  return {x:(targetW-w)/2,y:(targetH-h)/2,width:w,height:h};
}
export function statusText(error) {return String(error?.message||error||'Unknown connection error.').slice(0,350);}

/** Transition composition timing, independent of canvas/browser state. */
export function transitionProgress(start,now,duration=600){
 if (!Number.isFinite(start)||!Number.isFinite(now)||!Number.isFinite(duration)||duration<=0)return 1;
 return Math.max(0,Math.min(1,(now-start)/duration));
}
export function safeOverlayText(text,max=90){
 return String(text??'').replace(/[\u0000-\u001f\u007f]/g,' ').replace(/\s+/g,' ').trim().slice(0,max);
}
