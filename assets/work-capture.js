import {InterviewCapture} from './capture.js';
import {getVideoMetadata} from './media-engine.js';
import {createWorkUploadClient,workDraftKey,putWorkDraft,listWorkDrafts,deleteWorkDraft,restartWorkDraft} from './work-upload.js';

const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const time=ms=>`${Math.floor(ms/60000)}:${String(Math.floor(ms/1000)%60).padStart(2,'0')}`;
const token=()=>globalThis.crypto?.randomUUID?.()||`recording-${Date.now()}-${Math.random().toString(36).slice(2)}`;

/** A mount owns its cameras, local drafts and event handlers; no global API. */
export function mountWorkCapture(container,{assignmentId,userId,csrf='',orientation='any',onSaved=()=>{},onError=()=>{}}={}) {
  if(!container || !assignmentId || userId==null) throw new Error('Choose an assignment before opening the recorder.');
  const client=createWorkUploadClient({csrf});
  const drafts=new Map(),urls=new Map();
  let destroyed=false,recording=false,uploading=false,preparing=false,controller=null,devices=[],captureNotice='';
  const listeners=new AbortController();
  container.innerHTML=`<section class="work-capture panel"><div class="panel-heading"><div><p class="eyebrow">YOUR TAKES</p><h3>Record or upload</h3></div><span data-wc-clock class="timecode">00:00</span></div><p class="field-help">Save more than one take. When the footage is ready, use Submit for review below.</p><div class="work-capture-stage"><video data-wc-preview autoplay muted playsinline aria-label="Camera preview"></video><span data-wc-camera-off>Camera preview</span></div><div class="work-capture-devices"><label class="field">Camera / USB input<select data-wc-camera><option value="">Default camera</option></select></label><label class="field">Microphone<select data-wc-mic><option value="">Default microphone</option></select></label><label class="field">Camera switching<select data-wc-switch><option value="off">Manual</option><option value="interval">Every 30 seconds</option><option value="random">Random · 15–45 seconds</option></select></label></div><label class="field">Take label<input data-wc-label maxlength="200" placeholder="For example: Close-up · take 2"></label><div class="action-group"><button type="button" class="button" data-wc-enable>Enable camera & mic</button><button type="button" class="button" data-wc-next disabled>Switch camera</button><button type="button" class="button button--primary" data-wc-record disabled>Record take</button><button type="button" class="button" data-wc-stop disabled>Stop recording</button><label class="button file-button">Import videos<input data-wc-files type="file" accept="video/*" multiple></label></div><p data-wc-status class="field-help" role="status" aria-live="polite">Enable your camera, or choose videos from this device.</p><div data-wc-drafts class="work-capture-drafts"></div><video data-wc-take-preview class="review-video" controls playsinline preload="metadata" aria-label="Saved take preview" hidden></video><div class="action-group"><button type="button" class="button button--primary" data-wc-upload-all hidden>Upload saved takes</button><button type="button" class="button" data-wc-pause hidden>Pause upload</button></div></section>`;
  const $=name=>container.querySelector(`[data-wc-${name}]`);
  const capture=new InterviewCapture($('preview'),{orientation,fit:orientation==='any'?'contain':'cover'});
  const stage=container.querySelector('.work-capture-stage');
  stage.style.aspectRatio=`${capture.outputFrame.width} / ${capture.outputFrame.height}`;
  if(orientation==='portrait')stage.style.maxWidth='400px';
  if(orientation==='square')stage.style.maxWidth='560px';
  $('preview').style.objectFit=capture.outputFit;
  $('preview').style.aspectRatio=`${capture.outputFrame.width} / ${capture.outputFrame.height}`;
  $('preview').style.maxHeight='none';
  const status=(message,error=false)=>{if(destroyed)return;$('status').textContent=message;$('status').classList.toggle('error-text',error);};
  const report=error=>{if(destroyed)return;status(error.message||String(error),error.name!=='AbortError');if(error.name!=='AbortError')onError(error);};
  const listen=(node,event,fn)=>node.addEventListener(event,e=>{try{Promise.resolve(fn(e)).catch(report);}catch(error){report(error);}},{signal:listeners.signal});
  function update() {
    if(destroyed)return;
    $('enable').disabled=recording||uploading||preparing;
    $('record').disabled=recording||uploading||preparing||!capture.hasDevices();
    $('stop').disabled=!recording;
    $('mic').disabled=recording||uploading||preparing;
    $('camera').disabled=uploading||preparing;
    $('next').disabled=uploading||preparing||capture.getCameraSources().length<2;
    $('files').disabled=recording||uploading||preparing;
    $('switch').disabled=uploading||preparing;
    $('upload-all').hidden=!drafts.size; $('upload-all').disabled=recording||uploading||preparing;
    $('pause').hidden=!uploading;
    $('camera-off').hidden=capture.hasDevices();
  }
  function renderDrafts() {
    if(destroyed)return;
    $('drafts').innerHTML=[...drafts.values()].map(d=>`<article class="work-capture-draft"><div><strong>${escape(d.label||d.fileName)}</strong><p class="field-help">${time(d.durationMs)} · ${(d.blob.size/1048576).toFixed(1)} MB · ${escape(d.status||'Saved on this device')}</p>${d.error?`<p class="error-text">${escape(d.error)}</p>`:''}<progress aria-label="Upload progress for ${escape(d.label||d.fileName)}" max="100" value="${Math.round((d.receivedBytes||0)/d.blob.size*100)}"></progress></div><div class="action-group"><button type="button" class="button button--small" data-wc-send="${escape(d.clientToken)}" ${uploading||recording||preparing?'disabled':''}>${d.uploadId?'Resume':'Upload'} take</button>${d.errorCode==='assignment_changed'?`<button type="button" class="button button--small" data-wc-restart="${escape(d.clientToken)}" ${uploading||recording||preparing?'disabled':''}>Restart upload · keep recording</button>`:''}<button type="button" class="text-button" data-wc-review="${escape(d.clientToken)}">Preview</button><button type="button" class="text-button" data-wc-download="${escape(d.clientToken)}">Download copy</button><button type="button" class="text-button danger" data-wc-discard="${escape(d.clientToken)}" ${uploading||recording||preparing?'disabled':''}>Discard</button></div></article>`).join('');
    update();
  }
  async function persist(draft) {
    try {await putWorkDraft(draft);draft.persisted=true;}
    catch(error){draft.persisted=false;status('Recovery storage is unavailable. Keep this page open and download a copy of unsaved takes.',true);}
  }
  async function queue(blob,metadata={}) {
    if(destroyed)return;
    const clientToken=token();
    const draft={...metadata,userId:String(userId),assignmentId,clientToken,id:workDraftKey(userId,assignmentId,clientToken),blob,createdAt:new Date().toISOString(),fileName:metadata.fileName||`azhottie-take-${Date.now()}.${blob.type.includes('mp4')?'mp4':'webm'}`,label:$('label').value.trim()||metadata.fileName||'Recorded take',mimeType:blob.type,status:'Saved on this device'};
    drafts.set(clientToken,draft); await persist(draft); renderDrafts();
    status(draft.persisted?'Take saved on this device. Upload it when you are ready.':'Keep this page open and download a copy before leaving.',!draft.persisted);
  }
  function refreshDevices(list=devices) {
    devices=list;
    const cameras=capture.getCameraSources(),selected=capture.activeCameraId;
    $('camera').innerHTML=cameras.length?cameras.map((d,i)=>`<option value="${escape(d.deviceId)}">${escape(d.label||`Camera ${i+1}`)}</option>`).join(''):'<option value="">Default camera</option>';
    if(cameras.some(d=>d.deviceId===selected))$('camera').value=selected;
    const microphone=$('mic').value;
    $('mic').innerHTML='<option value="">Default microphone</option>'+devices.filter(d=>d.kind==='audioinput').map((d,i)=>`<option value="${escape(d.deviceId)}">${escape(d.label||`Microphone ${i+1}`)}</option>`).join('');
    $('mic').value=microphone;
    update();
  }
  async function enable() {
    preparing=true; captureNotice=''; update(); status('Opening camera and microphone…');
    try {const list=await capture.enable($('camera').value,$('mic').value);if(!destroyed){refreshDevices(list);status('Ready. Check your framing and record a take.');}}
    finally {preparing=false;update();}
  }
  async function send(selected=[...drafts.values()]) {
    if(uploading||recording||preparing||!selected.length)return;
    uploading=true;controller=new AbortController();renderDrafts();
    const receipts=[];
    try {
      for(const draft of selected) {
        draft.error='';draft.status='Uploading';renderDrafts();
        try {
          const receipt=await client.upload(draft.blob,draft,{signal:controller.signal,onCheckpoint:async cp=>{Object.assign(draft,{uploadId:cp.uploadId,receivedBytes:cp.receivedBytes});await persist(draft);renderDrafts();},onProgress:p=>status(`Uploading ${draft.label} · ${Math.round(p.ratio*100)}%`)});
          await deleteWorkDraft(userId,assignmentId,draft.clientToken).catch(()=>{});
          drafts.delete(draft.clientToken);if(urls.has(draft.clientToken)){URL.revokeObjectURL(urls.get(draft.clientToken));urls.delete(draft.clientToken);}receipts.push(receipt);
        } catch(error) {draft.status=error.name==='AbortError'?'Paused · saved on this device':'Upload needs attention';draft.error=error.message;draft.errorCode=error.code||'';await persist(draft);report(error);break;}
      }
    } finally {
      uploading=false;controller=null;renderDrafts();
      if(receipts.length) {
        status(`${receipts.length} ${receipts.length===1?'take':'takes'} uploaded. ${drafts.size?'Remaining recordings are kept on this device.':'Submit this assignment when all your takes are ready.'}`);
        if(![...drafts.values()].some(d=>!d.persisted)) await onSaved({count:receipts.length,submissions:receipts,hasPending:drafts.size>0});
      }
    }
  }
  listen($('enable'),'click',enable);
  listen($('mic'),'change',enable);
  listen($('camera'),'change',async()=>{if(capture.hasDevices()){await capture.switchCamera($('camera').value);refreshDevices();}else await enable();});
  listen($('next'),'click',async()=>{const choices=capture.getCameraSources();const index=choices.findIndex(d=>d.deviceId===capture.activeCameraId);await capture.switchCamera(choices[(index+1)%choices.length].deviceId);refreshDevices();});
  listen($('switch'),'change',()=>capture.configureSwitching({mode:$('switch').value,intervalSeconds:30}));
  listen($('record'),'click',async()=>{
    if(recording||uploading||preparing)return;
    $('take-preview').pause();captureNotice='';recording=true;update();
    try {const take=await capture.start({countdown:3,onTimer:ms=>{if(!destroyed)$('clock').textContent=time(ms);},onState:message=>status((message==='Recording question and answer'?'Recording take':message)+(captureNotice?` · ${captureNotice}`:''))});await queue(take.blob,{durationMs:take.durationMs});if(take.interrupted)status(`Recording saved after interruption. ${take.interruptionReason}`,true);else if(captureNotice)status(`Take saved. ${captureNotice}`);}
    finally {recording=false;update();renderDrafts();}
  });
  listen($('stop'),'click',()=>capture.stop());
  listen($('files'),'change',async event=>{
    const files=[...event.target.files];event.target.value='';preparing=true;update();
    try {for(const file of files){if(!file.type.startsWith('video/'))throw new Error('Choose video files for your takes.');status(`Reading ${file.name}…`);const info=await getVideoMetadata(file);await queue(file,{durationMs:Math.round(info.duration*1000),fileName:file.name});}}
    finally {preparing=false;update();}
  });
  listen($('upload-all'),'click',()=>send());
  listen($('pause'),'click',()=>controller?.abort());
  listen($('drafts'),'click',async event=>{
    const restart=event.target.closest('[data-wc-restart]');
    if(restart){
      const draft=drafts.get(restart.dataset.wcRestart);if(!draft||uploading||recording||preparing)return;
      if(!confirm('Have you reviewed the updated shot brief? Restart this upload while keeping the same local recording.'))return;
      preparing=true;renderDrafts();
      try {
        const replacement=await restartWorkDraft(draft,{abandon:async(a,id)=>{try{return await client.abort(a,id);}catch(error){if(error.status===404)return {abandoned:true};throw error;}}});
        drafts.delete(draft.clientToken);drafts.set(replacement.clientToken,replacement);
        if(urls.has(draft.clientToken)){urls.set(replacement.clientToken,urls.get(draft.clientToken));urls.delete(draft.clientToken);}
        status('Your recording is kept. Choose Upload take to restart against the updated assignment.');
      }finally{preparing=false;renderDrafts();}
      return;
    }
    const sendButton=event.target.closest('[data-wc-send]');if(sendButton)return send([drafts.get(sendButton.dataset.wcSend)].filter(Boolean));
    const preview=event.target.closest('[data-wc-review]');
    if(preview){const d=drafts.get(preview.dataset.wcReview);if(!d)return;let url=urls.get(d.clientToken);if(!url){url=URL.createObjectURL(d.blob);urls.set(d.clientToken,url);}const player=$('take-preview');player.src=url;player.hidden=false;player.scrollIntoView({behavior:'smooth',block:'nearest'});return;}
    const download=event.target.closest('[data-wc-download]');
    if(download){const d=drafts.get(download.dataset.wcDownload);if(!d)return;let url=urls.get(d.clientToken);if(!url){url=URL.createObjectURL(d.blob);urls.set(d.clientToken,url);}const a=document.createElement('a');a.href=url;a.download=d.fileName;a.click();return;}
    const discard=event.target.closest('[data-wc-discard]');
    if(discard){const d=drafts.get(discard.dataset.wcDiscard);if(!d||uploading||recording)return;if(!confirm('Discard this local take? Download a copy first if you want to keep it.'))return;if(d.uploadId){try{await client.abort(assignmentId,d.uploadId);}catch(error){if(error.status!==404 && error.status!==403 && error.code!=='assignment_changed')throw error;}}await deleteWorkDraft(userId,assignmentId,d.clientToken);drafts.delete(d.clientToken);if(urls.has(d.clientToken)){URL.revokeObjectURL(urls.get(d.clientToken));urls.delete(d.clientToken);}renderDrafts();}
  });
  capture.addEventListener('cameraswitch',()=>refreshDevices(),{signal:listeners.signal});
  capture.addEventListener('statechange',event=>{const detail=event.detail||{};if(detail.message){captureNotice=detail.message;if(detail.state==='switching-unavailable'){$('switch').value='off';capture.configureSwitching({mode:'off',intervalSeconds:30});stage.style.aspectRatio='auto';$('preview').style.aspectRatio='auto';$('preview').style.objectFit='contain';}status(detail.message,detail.state==='camera-error');}update();},{signal:listeners.signal});
  const isBusy=()=>recording||uploading||preparing;
  const isDirty=()=>isBusy()||drafts.size>0;
  const beforeUnload=event=>{if(isDirty()){event.preventDefault();event.returnValue='';}};
  window.addEventListener('beforeunload',beforeUnload,{signal:listeners.signal});
  void listWorkDrafts(userId,assignmentId).then(list=>{if(destroyed)return;for(const draft of list){if(!drafts.has(draft.clientToken))drafts.set(draft.clientToken,{...draft,persisted:true,status:'Recovered from this device'});}if(list.length)status(`${list.length} saved ${list.length===1?'recording is':'recordings are'} ready to resume.`);renderDrafts();}).catch(report);
  update();
  return {isBusy,isDirty,destroy(){if(destroyed)return;destroyed=true;controller?.abort();$('take-preview').pause();capture.disable();listeners.abort();for(const url of urls.values())URL.revokeObjectURL(url);urls.clear();container.replaceChildren();}};
}
