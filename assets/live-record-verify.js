import {videoProbeReady} from './live-v151-guards.js';

/** Attempt to decode the first frame of a recording in the current browser.
 * Cannot certify an entire movie, its audio, a different codec/device, or
 * successful playback on a real fan handset. Never auto-publish a recording.
 */
export function verifyRecordedVideo(url,{timeoutMs=12000,makeVideo=()=>document.createElement('video'),
  createTimer=(fn,ms)=>setTimeout(fn,ms),cancelTimer=id=>clearTimeout(id)}={}) {
  return new Promise(resolve=>{
    if(!url){resolve({ok:false,reason:'No recording URL available.'});return;}
    const video=makeVideo();let finished=false,timer=null;
    const finish=(ok,reason)=>{
      if(finished)return;finished=true;cancelTimer(timer);
      video.removeEventListener('loadeddata',ready);
      video.removeEventListener('canplay',ready);
      video.removeEventListener('error',error);
      const videoWidth=video.videoWidth||0,videoHeight=video.videoHeight||0;
      try{video.pause();video.removeAttribute('src');video.load();}catch{}
      resolve({ok,reason,videoWidth,videoHeight});
    };
    const ready=()=>{if(videoProbeReady(video))finish(true,'First video frame decoded.');};
    const error=()=>finish(false,'Browser could not decode the recording video.');
    video.preload='auto';video.muted=true;video.playsInline=true;
    video.addEventListener('loadeddata',ready);
    video.addEventListener('canplay',ready);
    video.addEventListener('error',error);
    timer=createTimer(()=>finish(false,'Playback preview timed out; recording retained for manual review.'),timeoutMs);
    try{video.src=url;video.load();ready();}catch{finish(false,'Browser could not open the recording preview.');}
  });
}
