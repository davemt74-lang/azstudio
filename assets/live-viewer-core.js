/** Preserve one fan MediaStream across independent video/audio ontrack events.
 * Replacing srcObject on audio arrival can interrupt or restart video playback.
 */
export function appendLiveTrack(video,event,previous,createStream=()=>new MediaStream()){
  let stream=previous;
  if(!stream){
    stream=event.streams?.[0]||createStream();
    video.srcObject=stream;
  }
  const track=event.track;
  if(track&&!stream.getTracks().some(t=>t===track||(t.id&&track.id&&t.id===track.id)))stream.addTrack(track);
  return stream;
}
