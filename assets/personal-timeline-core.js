/** Pure edit operations; persisted clips contain media IDs, never arbitrary URLs. */
import {normalizeAudioKeyframes,rebaseAudioKeyframes} from './personal-audio-envelope.js?v=1.19.1';
export const DEFAULT_SETTINGS = Object.freeze({aspect:'16:9',fit:'contain',resolution:720,fadeDuration:0.35,cardDuration:2.5,timedTitles:[],background:null,detachedAudio:[]});
const clipId=()=>globalThis.crypto?.randomUUID?.().replaceAll('-','')||Array.from(crypto.getRandomValues(new Uint8Array(16)),v=>v.toString(16).padStart(2,'0')).join('');
export const effectiveEnd=clip=>clip.trimEndMs || clip.durationMs;
export const clipSeconds=clip=>Math.max(0,(effectiveEnd(clip)-clip.trimStartMs)/1000);
export const totalSeconds=(clips,settings=DEFAULT_SETTINGS)=>clips.reduce((sum,clip)=>sum+clipSeconds(clip)+(clip.titleCard?settings.cardDuration:0),0);
export function addMedia(clips,media,durationMs){
  if(!media?.id || !Number.isFinite(durationMs)||durationMs<100||durationMs>86400000)throw Error('This video needs readable duration metadata before adding it.');
  if(clips.length>=48)throw Error('A timeline can contain up to 48 clips.');
  return [...clips,{id:clipId(),mediaId:media.id,durationMs:Math.round(durationMs),trimStartMs:0,trimEndMs:Math.round(durationMs),overlayText:'',overlayPosition:'bottom',titleCard:false,volume:1,audioKeyframes:[],audioDetached:false,filter:'none',transition:'fade'}];
}
export function changeClip(clips,id,patch){
  return clips.map(clip=>{
    if(clip.id!==id)return clip;
    const next={...clip,audioDetached:Boolean(clip.audioDetached),audioKeyframes:clip.audioKeyframes||[],...patch};
    if(!Number.isFinite(next.trimStartMs)||!Number.isFinite(next.trimEndMs)||next.trimStartMs<0||next.trimEndMs>next.durationMs||next.trimEndMs-next.trimStartMs<100)throw Error('Choose an end at least 0.1 seconds after the start, within the video.');
    if(!Number.isFinite(next.volume)||next.volume<0||next.volume>1)throw Error('Volume must be between 0% and 100%.');
    if(String(next.overlayText).length>200)throw Error('Text overlay is limited to 200 characters.');
    if(!['none','vivid','warm','mono','cinematic','vintage'].includes(next.filter||'none'))throw Error('Unknown video filter.');
    if(!['fade','cut'].includes(next.transition||'fade'))throw Error('Unknown clip transition.');
    if(typeof next.audioDetached!=='boolean')throw Error('Invalid detached audio state.');
    next.audioKeyframes=normalizeAudioKeyframes(next.audioKeyframes,next.trimEndMs-next.trimStartMs);
    return next;
  });
}
/** Trim picture on the timeline. Keyframes stay attached to their source sound. */
export function trimClipEdge(clips,id,edge,atMs){
  const clip=clips.find(c=>c.id===id);if(!clip)throw Error('Choose a clip to trim.');
  const point=Math.round(atMs),start=edge==='start'?point:clip.trimStartMs,end=edge==='end'?point:clip.trimEndMs;
  if(!['start','end'].includes(edge))throw Error('Unknown trim edge.');
  const audioKeyframes=rebaseAudioKeyframes(clip.audioKeyframes,clip.trimStartMs,start,end,clip.volume);
  return changeClip(clips,id,{trimStartMs:start,trimEndMs:end,audioKeyframes});
}
export function moveClip(clips,id,to){
  const from=clips.findIndex(c=>c.id===id);
  if(from<0||to<0||to>=clips.length||to===from)return clips;
  const result=[...clips];const [clip]=result.splice(from,1);result.splice(to,0,clip);return result;
}
export function renderSegments(clips,library){
  const sources=new Map(library.map(m=>[m.id,m]));
  return clips.map((clip,i)=>{
    const media=sources.get(clip.mediaId);
    if(!media)throw Error(`Timeline clip ${i+1} is no longer in your media library. Remove it before exporting.`);
    return {id:clip.id,src:media.videoUrl,question:clip.overlayText||media.title,
      overlayText:clip.overlayText,overlayPosition:clip.overlayPosition,volume:clip.audioDetached?0:clip.volume,
      audioKeyframes:clip.audioDetached?[]:(clip.audioKeyframes||[]),filter:clip.filter||'none',transition:clip.transition||'fade',
      showCard:clip.titleCard, duration:clip.durationMs/1000,
      trimStart:clip.trimStartMs/1000,trimEnd:effectiveEnd(clip)/1000};
  });
}
export function mediaSummary(media){return String(media?.title||'Untitled video').slice(0,200);}

/** Split without touching the owner-scoped original recording or shifting audio automation. */
export function splitClip(clips,id,atMs){
  const index=clips.findIndex(c=>c.id===id);if(index<0)throw Error('Select a clip to split.');
  const clip=clips[index],point=Math.round(atMs);
  if(!Number.isFinite(point)||point-clip.trimStartMs<100||clip.trimEndMs-point<100)
    throw Error('Choose a split point at least 0.1 seconds inside this clip.');
  const before={...clip,trimEndMs:point,audioKeyframes:rebaseAudioKeyframes(clip.audioKeyframes,clip.trimStartMs,clip.trimStartMs,point,clip.volume)};
  const after={...clip,id:clipId(),trimStartMs:point,titleCard:false,audioKeyframes:rebaseAudioKeyframes(clip.audioKeyframes,clip.trimStartMs,point,clip.trimEndMs,clip.volume)};
  return [...clips.slice(0,index),before,after,...clips.slice(index+1)];
}
export function duplicateClip(clips,id){
  const index=clips.findIndex(c=>c.id===id);if(index<0)throw Error('Choose a clip.');
  if(clips.length>=48)throw Error('A timeline can contain up to 48 clips.');
  const original=clips[index];return [...clips.slice(0,index+1),{...original,id:clipId(),audioKeyframes:(original.audioKeyframes||[]).map(k=>({...k})),titleCard:false},...clips.slice(index+1)];
}
export function detachedAudioAt(clips,settings,id){
  let time=0;
  for(const clip of clips){if(clip.id===id)return Math.round(time*1000)+(clip.titleCard?Math.round(settings.cardDuration*1000):0);
    time+=clipSeconds(clip)+(clip.titleCard?settings.cardDuration:0);
  }
  throw Error('Choose a V1 clip.');
}
export function detachClipAudio(clips,settings,id){
  const clip=clips.find(c=>c.id===id);if(!clip)throw Error('Choose a V1 clip.');
  if(clip.audioDetached)throw Error('This clip audio is already detached.');
  if((settings.detachedAudio||[]).length>=24)throw Error('Maximum 24 detached audio clips per project.');
  const audio={id:clipId(),originClipId:id,mediaId:clip.mediaId,durationMs:clip.durationMs,
    startMs:detachedAudioAt(clips,settings,id),trimStartMs:clip.trimStartMs,trimEndMs:clip.trimEndMs,
    volume:clip.volume,audioKeyframes:(clip.audioKeyframes||[]).map(k=>({...k}))};
  return {clips:changeClip(clips,id,{audioDetached:true}),settings:{...settings,detachedAudio:[...(settings.detachedAudio||[]),audio]}};
}
export function changeDetachedAudio(settings,id,patch){
  return {...settings,detachedAudio:(settings.detachedAudio||[]).map(audio=>{
    if(audio.id!==id)return audio;
    const next={...audio,...patch};
    if(!Number.isInteger(next.startMs)||next.startMs<0||next.startMs>86400000)throw Error('Invalid audio placement.');
    if(!Number.isInteger(next.trimStartMs)||!Number.isInteger(next.trimEndMs)||next.trimStartMs<0||next.trimEndMs>next.durationMs||next.trimEndMs-next.trimStartMs<100)throw Error('Audio must contain at least 0.1 seconds.');
    if(!Number.isFinite(next.volume)||next.volume<0||next.volume>1)throw Error('Audio volume must be between 0% and 100%.');
    next.audioKeyframes=normalizeAudioKeyframes(next.audioKeyframes,next.trimEndMs-next.trimStartMs);
    return next;
  })};
}
/** Duration-only, deterministic helper; does not inspect or claim to understand videos. */
export function highlightProposal(clips,targetSeconds){
  if(!clips.length)throw Error('Add footage before preparing a highlight.');
  if(!Number.isFinite(targetSeconds)||targetSeconds<1||targetSeconds>3600)throw Error('Choose a supported highlight length.');
  const available=clips.reduce((s,c)=>s+clipSeconds(c),0),target=Math.min(targetSeconds,available);
  if(target<.1)throw Error('Timeline clips have no usable duration.');
  const per=target/clips.length;
  let remaining=target;
  const next=clips.map((c,i)=>{
    const allocation=Math.min(clipSeconds(c),i===clips.length-1?remaining:Math.max(.1,per));
    remaining-=allocation;
    const trimEndMs=Math.round(c.trimStartMs+allocation*1000);
    return {...c,trimEndMs,audioKeyframes:rebaseAudioKeyframes(c.audioKeyframes,c.trimStartMs,c.trimStartMs,trimEndMs,c.volume),titleCard:false};
  });
  for(let i=0;i<next.length&&remaining>.001;i++){
    const extra=Math.min(remaining,(clips[i].trimEndMs-next[i].trimEndMs)/1000);
    if(extra>0){next[i].trimEndMs+=Math.round(extra*1000);remaining-=extra;next[i].audioKeyframes=rebaseAudioKeyframes(clips[i].audioKeyframes,clips[i].trimStartMs,next[i].trimStartMs,next[i].trimEndMs,clips[i].volume);}
  }
  return next;
}
