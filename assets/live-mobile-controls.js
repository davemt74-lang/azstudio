/** Mobile shortcuts share the Director's existing guarded controls; they
 * never create a second Program switch, recorder, or broadcast lifecycle.
 */
export function wireMobileDirectorShortcuts({root,sourcePanel,nextCamera,startRecord,stopRecord,showAudio,showRecording,
  recordStatus=()=>({canStart:false,canStop:false,backupPending:false}),canTake=()=>false}={}){
  if(!root)return ()=>{};
  const get=id=>root.querySelector('#'+id);
  const camera=get('live-mobile-cameras'),next=get('live-mobile-next'),record=get('live-mobile-record'),audio=get('live-mobile-audio');
  if(!camera||!next||!record||!audio)return ()=>{};
  const onCamera=()=>{sourcePanel?.scrollIntoView?.({block:'nearest',behavior:'smooth'});sourcePanel?.querySelector?.('#live-invite')?.focus?.({preventScroll:true});};
  const onNext=()=>{if(canTake())nextCamera();};
  const onRecord=()=>{
    const state=recordStatus();
    if(state.canStop)stopRecord();
    else if(state.backupPending)showRecording();
    else if(state.canStart)startRecord();
  };
  const onAudio=()=>showAudio();
  const events=[[camera,onCamera],[next,onNext],[record,onRecord],[audio,onAudio]];
  for(const [element,handler] of events)element.addEventListener('click',handler);
  const refresh=()=>{
    const state=recordStatus();
    next.disabled=!canTake();
    record.disabled=!(state.canStop||state.backupPending||state.canStart);
    record.textContent=state.canStop?'■ Stop recording':state.backupPending?'Review backup':'● Record';
    record.setAttribute('aria-label',state.canStop?'Stop Program recording':state.backupPending?'Review unsaved Program recording':'Start Program recording');
  };
  refresh();
  return Object.assign(()=>{for(const [element,handler] of events)element.removeEventListener('click',handler);},{refresh});
}
