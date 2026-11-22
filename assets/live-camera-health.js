/** AZHottie Live Director source diagnostics; no camera frames or track data leave the browser. */
export function cameraPermissionMessage(error) {
  const name=String(error?.name||'');
  if (name==='NotAllowedError'||name==='PermissionDeniedError'||name==='SecurityError')
    return 'Camera access was blocked or dismissed. Use the site-permissions icon beside the address, allow Camera for AZHottie, then select Find cameras again.';
  if (name==='NotFoundError'||name==='DevicesNotFoundError')return 'No camera was found. Connect a webcam or USB/HDMI capture device, then select Find cameras.';
  if (name==='NotReadableError'||name==='TrackStartError')return 'This camera is in use or cannot start. Close other camera apps, unplug/reconnect the device, then retry.';
  if (name==='OverconstrainedError')return 'The requested camera or video format is unavailable. Refresh the device list and choose another camera.';
  if (name==='AbortError')return 'Camera initialization stopped. Check the device connection and retry.';
  return String(error?.message||'Camera unavailable. Check HTTPS, browser permissions, and device connection.').slice(0,240);
}
export function cameraStateText(state) {
  return ({starting:'Connecting…',live:'● LIVE VIDEO',paused:'Preview paused',muted:'Camera muted',stalled:'No new frames',offline:'Disconnected',blocked:'Preview blocked'})[state]||'Checking…';
}
export function cameraSummary(states) {
  const list=Array.from(states||[]);const total=list.length;
  const live=list.filter(s=>s==='live').length;
  const pending=list.filter(s=>s==='starting'||s==='paused').length;
  const issues=total-live-pending;
  return {total,live,pending,issues,text:`${live} live / ${total} cameras${issues?` · ${issues} need attention`:''}`};
}
export class CameraHealth {
  constructor(video,stream,{now=()=>performance.now(),staleMs=4500,startupMs=9000,onChange=()=>{}}={}) {
    this.video=video;this.stream=stream;this.now=now;this.staleMs=staleMs;this.startupMs=startupMs;this.onChange=onChange;
    this.startedAt=now();this.lastFrameAt=null;this.frames=0;this.frameWindow=[];this.lastMediaTime=null;
    this.playbackError=false;this.state='starting';this.vfcId=null;this.running=false;this.listeners=[];
  }
  tracks(){return this.stream?.getVideoTracks?.()||[];}
  frame(time=this.now(),mediaTime=null){
    if(!this.running)return;
    this.lastFrameAt=time;this.frames++;
    this.frameWindow.push(time);this.frameWindow=this.frameWindow.filter(t=>time-t<=2000);
    if(Number.isFinite(mediaTime))this.lastMediaTime=mediaTime;
    this.update(time);
  }
  framesPerSecond(){
    const elapsed=Math.min(2000,this.now()-(this.frameWindow[0]??this.now()));
    return this.frameWindow.length>=2&&elapsed>0?Math.round((this.frameWindow.length-1)*1000/elapsed):0;
  }
  snapshot(time=this.now()){
    const tracks=this.tracks();
    if(!tracks.length||tracks.every(t=>t.readyState==='ended'))return 'offline';
    if(tracks.some(t=>t.muted))return 'muted';
    if(this.playbackError)return 'blocked';
    if(this.video.paused&&this.video.readyState<2)return 'paused';
    if(this.lastFrameAt===null)return time-this.startedAt>=this.startupMs?'stalled':'starting';
    return time-this.lastFrameAt>this.staleMs?'stalled':'live';
  }
  update(time=this.now()){
    const state=this.snapshot(time);
    if(state!==this.state){this.state=state;this.onChange(state,this);}
    return state;
  }
  playable(){return this.update()==='live'&&this.video.readyState>=2&&this.video.videoWidth>0;}
  observe(target,event,handler){target?.addEventListener?.(event,handler);this.listeners.push([target,event,handler]);}
  start(){
    if(this.running)return this;
    this.running=true;
    for(const track of this.tracks()){
      for(const event of ['mute','unmute','ended'])this.observe(track,event,()=>this.update());
    }
    this.observe(this.video,'loadeddata',()=>this.frame());
    this.observe(this.video,'playing',()=>{this.playbackError=false;this.update();});
    this.observe(this.video,'pause',()=>this.update());
    this.observe(this.video,'error',()=>{this.playbackError=true;this.update();});
    if(typeof this.video.requestVideoFrameCallback==='function'){
      const tick=(time,metadata)=>{
        if(!this.running)return;
        this.frame(this.now(),metadata?.mediaTime);
        this.vfcId=this.video.requestVideoFrameCallback(tick);
      };
      this.vfcId=this.video.requestVideoFrameCallback(tick);
    } else {
      // Older browsers: timeupdate confirms decoded moving playback, not merely an open video track.
      this.observe(this.video,'timeupdate',()=>{
        const time=Number(this.video.currentTime);
        if(this.video.readyState>=2&&this.video.videoWidth>0&&time!==this.lastMediaTime)this.frame(this.now(),time);
      });
    }
    return this;
  }
  playRejected(){this.playbackError=true;this.update();}
  stop(){
    this.running=false;
    if(this.vfcId!==null&&typeof this.video.cancelVideoFrameCallback==='function')this.video.cancelVideoFrameCallback(this.vfcId);
    this.vfcId=null;
    for(const [target,event,handler] of this.listeners)target?.removeEventListener?.(event,handler);
    this.listeners=[];
  }
}
