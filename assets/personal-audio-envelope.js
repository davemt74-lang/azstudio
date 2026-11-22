/** Time/value automation for a timeline clip. Times are milliseconds from its trimmed in-point.
 * Absolute volume levels (0..1) interpolate linearly between keyframes.
 */
export const MAX_AUDIO_KEYFRAMES = 32;
const clamp=(v,min,max)=>Math.max(min,Math.min(max,v));
export function normalizeAudioKeyframes(points,durationMs=86400000){
  if(points==null)return [];
  if(!Array.isArray(points)||points.length>MAX_AUDIO_KEYFRAMES)throw Error('Too many audio keyframes.');
  const duration=Math.max(0,Math.round(durationMs));let last=-1;
  return points.map(point=>{
    const timeMs=Number(point?.timeMs),volume=Number(point?.volume);
    if(!Number.isInteger(timeMs)||timeMs<0||timeMs>duration||timeMs<=last||!Number.isFinite(volume)||volume<0||volume>1)
      throw Error('Audio keyframes must have increasing times and levels from 0% to 100%.');
    last=timeMs;return {timeMs,volume};
  });
}
export function volumeAt(points,positionMs,baseVolume=1){
  if(!points?.length)return baseVolume;
  if(positionMs<=points[0].timeMs)return points[0].volume;
  const last=points.at(-1);if(positionMs>=last.timeMs)return last.volume;
  for(let i=1;i<points.length;i++){
    const right=points[i],left=points[i-1];
    if(positionMs<=right.timeMs)return left.volume+(right.volume-left.volume)*(positionMs-left.timeMs)/(right.timeMs-left.timeMs);
  }
  return baseVolume;
}
export function insertAudioKeyframe(points,positionMs,durationMs,baseVolume=1){
  const list=normalizeAudioKeyframes(points,durationMs),timeMs=clamp(Math.round(positionMs),0,Math.round(durationMs));
  if(list.some(k=>Math.abs(k.timeMs-timeMs)<40))return list;
  if(list.length>=MAX_AUDIO_KEYFRAMES)throw Error('Maximum 32 audio keyframes per clip.');
  const volume=volumeAt(list,timeMs,baseVolume);
  return normalizeAudioKeyframes([...list,{timeMs,volume}].sort((a,b)=>a.timeMs-b.timeMs),durationMs);
}
export function setAudioKeyframe(points,index,volume,durationMs){
  const list=normalizeAudioKeyframes(points,durationMs);
  if(index<0||index>=list.length||!Number.isFinite(volume))throw Error('Invalid audio keyframe.');
  list[index]={...list[index],volume:Math.round(clamp(volume,0,1)*1000)/1000};
  return list;
}
export function removeAudioKeyframe(points,index,durationMs){
  const list=normalizeAudioKeyframes(points,durationMs);if(index<0||index>=list.length)return list;
  list.splice(index,1);return list;
}
export function rebaseAudioKeyframes(points,oldStartMs,newStartMs,newEndMs,baseVolume=1){
  if(!points?.length)return [];
  const old=normalizeAudioKeyframes(points);
  const delta=newStartMs-oldStartMs,duration=Math.max(0,newEndMs-newStartMs);
  const next=[{timeMs:0,volume:volumeAt(old,delta,baseVolume)}];
  for(const p of old){const timeMs=p.timeMs-delta;if(timeMs>0&&timeMs<duration)next.push({timeMs,volume:p.volume});}
  if(duration>0)next.push({timeMs:duration,volume:volumeAt(old,delta+duration,baseVolume)});
  // Keep the clip's endpoints, even if trimming has removed intermediate points.
  return normalizeAudioKeyframes(next.slice(0,MAX_AUDIO_KEYFRAMES),duration);
}
