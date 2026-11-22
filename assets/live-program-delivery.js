/** Program video transport selection, independent of Camera preview-frame counting.
 * Chrome and other browsers can pause requestAnimationFrame/canvas capture when
 * the Director is backgrounded. For a single selected, previously approved
 * phone, relay that SAME camera track directly while the tab is hidden.
 * In a visible tab the composed Program canvas remains authoritative.
 */
export function liveVideoTrack({ hidden, mediaProgramActive, activeLayout, selected, sources, canvasTrack }) {
  if (!canvasTrack) return null;
  if (!hidden || mediaProgramActive || activeLayout?.type !== 'single' || !selected || activeLayout.slots?.[0] !== selected)
    return canvasTrack;
  const entry=sources?.get(selected);
  if (entry?.kind !== 'phone') return canvasTrack;
  const track=entry.stream?.getVideoTracks?.().find(t=>t.readyState==='live'&&!t.muted);
  return track||canvasTrack;
}

/** Serialize replaceTrack so rapid hide/show, camera switches and unplug events
 * cannot leave the viewer bound to a stale track. No renegotiation or new peer.
 */
export class ViewerVideoRoute {
  constructor(sender, initialTrack, onError=()=>{}) {
    this.sender=sender;this.current=initialTrack;this.target=initialTrack;
    this.onError=onError;this.running=false;this.closed=false;this.pending=Promise.resolve();
  }
  setTarget(track){
    if(this.closed||!track||!this.sender)return this.pending;
    this.target=track;
    if(this.running)return this.pending;
    this.running=true;
    this.pending=(async()=>{
      try{
        while(!this.closed&&this.current!==this.target){
          const next=this.target;
          await this.sender.replaceTrack(next);
          this.current=next;
        }
      }catch(error){this.target=this.current;this.onError(error);}
      finally{this.running=false;}
    })();
    return this.pending;
  }
  close(){this.closed=true;}
}

/** Outbound RTP proves that the Program encoder is sending new frames. Phone
 * preview FPS does not. Reports without frame counts are intentionally unknown.
 */
export function outgoingFrameSample(previous, frames, now, staleMs=8500){
  if(!Number.isFinite(frames)||frames<0)return {state:'unknown',fps:null,frames:null,lastProgressAt:now,at:now};
  if(!previous||!Number.isFinite(previous.frames)||frames<previous.frames)
    return {state:'starting',fps:0,frames,lastProgressAt:now,at:now};
  const elapsed=Math.max(1,now-previous.at),delta=frames-previous.frames;
  if(delta>0)return {state:'sending',fps:Math.max(0,Math.round(delta*1000/elapsed)),frames,lastProgressAt:now,at:now};
  return {state:now-previous.lastProgressAt>=staleMs?'stalled':'waiting',fps:0,frames,lastProgressAt:previous.lastProgressAt,at:now};
}
