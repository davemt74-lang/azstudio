/* AZHottie Camera Studio: full-viewport recording for the signed-in owner or an approved guest phone.
 * All recordings stay private; guest tokens do not grant account or editor access.
 */
import {
  selectRecordingMime,phoneUploadIdentity,randomId,uploadToPersonalGallery,
  cameraFileSupported,cameraFileFingerprint,phoneUploadErrorMessage
} from './personal-phone-core.js';
const root=document.getElementById('phone-camera-root');
if(root){
  const $=id=>document.getElementById(id);
  const mode=root.dataset.mode,csrf=root.dataset.csrf||'';
  let device=null,stream=null,peer=null,liveAudioStream=null,liveNonce='',recorder=null,chunks=[],captured=null,backupUrl='',busy=false;
  let backFacing=false,liveTimer=null,uploadToken=null,uploadFile=null,approvalTimer=null,recordingTimer=null,recordingStarted=0,previewTrimAt=0,trimSource=null,trimDuration=0,autoSharedByRecording=false,liveIceServers=[{urls:['stun:stun.l.google.com:19302']}],liveWanted=false,liveRetry=0,liveRetryTimer=null;
  const tokenKey='azhottie:phone-camera:v1';
  const mediaApi=new URL('personal-media-api.php',document.baseURI),phoneApi=new URL('personal-phone-api.php',document.baseURI);
  const secure=window.isSecureContext||['localhost','127.0.0.1'].includes(location.hostname);
  const statusEl=$('phone-status');
  const setStatus=(txt,error=false)=>{statusEl.textContent=txt;statusEl.classList.toggle('is-error',error);};
  const setConnection=(label,active=false)=>{const p=$('phone-connection-pill');p.textContent=label;p.classList.toggle('is-approved',active);};
  const setCameraReady=ready=>{$('phone-stage-placeholder').hidden=ready;$('phone-start-camera').hidden=ready;};
  function openDrawer(open=true){$('phone-upload-drawer').hidden=!open;$('phone-open-library').setAttribute('aria-expanded',String(open));}
  $('phone-open-library').addEventListener('click',()=>openDrawer($('phone-upload-drawer').hidden));
  $('phone-close-library').addEventListener('click',()=>openDrawer(false));
  $('phone-fullscreen').addEventListener('click',async()=>{
    if(document.fullscreenElement){await document.exitFullscreen?.();return;}
    if(!root.requestFullscreen){setStatus('For an app-like full-screen view, add Camera Studio to your phone Home Screen.');return;}
    try{await root.requestFullscreen();}catch{setStatus('Your browser limited full-screen mode. The camera still fills the available screen.');}
  });
  const recoveryKey=()=>`azhottie:camera-upload:v2:${mode}:${device?.id||'owner'}`;
  function getRecovery(file){
    try{
      const old=JSON.parse(sessionStorage.getItem(recoveryKey())||'null');
      if(old?.fingerprint===cameraFileFingerprint(file)&&/^[a-f0-9]{32}$/.test(old.token||'')&&Date.now()-old.savedAt<86400000)return old.token;
    }catch{/* A damaged recovery record must not prevent a new upload. */}
    return null;
  }
  function rememberRecovery(file,token){
    try{sessionStorage.setItem(recoveryKey(),JSON.stringify({fingerprint:cameraFileFingerprint(file),token,savedAt:Date.now()}));}catch{}
  }
  function clearRecovery(){try{sessionStorage.removeItem(recoveryKey());}catch{}}
  function getDevice(){try{const value=JSON.parse(sessionStorage.getItem(tokenKey)||'null');return phoneUploadIdentity(value?.id,value?.token)?value:null;}catch{return null;}}
  async function api(action,body=null,params={}){
    const direct=mode==='signed-in';
    // The signed-in owner endpoint calls this lookup `status`; the temporary
    // phone endpoint calls it `uploadStatus`. Reuse the same resumable uploader.
    const route=direct&&action==='uploadStatus'?'status':action;
    const url=new URL(direct?mediaApi:phoneApi);url.searchParams.set('action',route);
    if(!direct)url.searchParams.set('id',device?.id||'');
    for(const [k,v] of Object.entries(params))url.searchParams.set(k,String(v));
    const form=body instanceof FormData;
    if(form&&!direct)form.set('id',device.id);
    const req={method:body===null?'GET':'POST',credentials:direct?'same-origin':'omit',cache:'no-store',headers:{...(direct?(body===null?{}:{'X-CSRF-Token':csrf}):device?{'X-Azh-Phone-Token':device.token}:{}),...(body!==null&&!form?{'Content-Type':'application/json'}:{})}};
    if(body!==null)req.body=form?body:JSON.stringify(direct?body:{...body,id:device?.id});
    let res;
    try{res=await fetch(url,req);}catch(e){const error=new Error('Network request did not reach the server.');error.httpStatus=0;error.cause=e;throw error;}
    const json=await res.json().catch(()=>null);
    if(!res.ok||!json?.ok){
      const error=new Error(json?.error?.message||`The server returned HTTP ${res.status}${!json?' instead of a camera response':''}.`);
      error.httpStatus=res.status;error.code=json?.error?.code||'';error.action=action;throw error;
    }
    return json.data;
  }
  async function authorize(){
    if(!secure){setStatus('Use HTTPS to open your phone camera.',true);$('phone-start-camera').hidden=true;setConnection('HTTPS required');return;}
    if(mode==='signed-in'){
      $('phone-record-controls').hidden=false;$('phone-live').hidden=true;
      setConnection('Signed in · Private',true);setStatus('Ready. Start camera or open Footage to upload from your phone.');return;
    }
    device=getDevice();
    const args=new URL(location.href),inviteId=args.searchParams.get('invite'),code=args.searchParams.get('code'),liveProductionInvite=args.searchParams.get('studio')==='live';
    // Never use a previously paired camera when a different QR invite was scanned.
    if(inviteId&&code&&device?.inviteId!==inviteId){device=null;sessionStorage.removeItem(tokenKey);}
    if(!device&&inviteId&&code){
      $('phone-start-camera').hidden=true;$('phone-claim').hidden=false;
      setConnection('Connection required');setStatus('Name this phone and request connection approval.');
      $('phone-claim-button').onclick=async()=>{
        const name=$('phone-display-name').value.trim();if(!name){setStatus('Enter a phone name.',true);return;}
        $('phone-claim-button').disabled=true;
        try{
          const r=await fetch(new URL('personal-phone-api.php?action=claim',document.baseURI),{
            method:'POST',credentials:'omit',cache:'no-store',headers:{'Content-Type':'application/json'},
            body:JSON.stringify({inviteId,code,displayName:name})
          });
          const json=await r.json().catch(()=>null);
          if(!r.ok||!json?.ok)throw Error(json?.error?.message||`Could not join (HTTP ${r.status}).`);
          device={...json.data,inviteId,liveProduction:liveProductionInvite};sessionStorage.setItem(tokenKey,JSON.stringify(device));
          $('phone-claim').hidden=true;
          // Remove the single-use invitation from the visible address bar.
          history.replaceState({},'',new URL('my-camera.php',document.baseURI).pathname);
          await checkApproval();
        }catch(e){setStatus(e.message,true);}finally{$('phone-claim-button').disabled=false;}
      };
    }else if(device){history.replaceState({},'',new URL('my-camera.php',document.baseURI).pathname);await checkApproval();}
    else{$('phone-start-camera').hidden=true;setConnection('No connection');setStatus('Scan a fresh QR code from the model editor, or sign in on your own phone.',true);}
  }
  async function checkApproval(){
    if(!device)return;
    clearTimeout(approvalTimer);
    try{
      const answer=await api('status');
      if(answer.status==='approved'){
        if(Array.isArray(answer.iceServers)&&answer.iceServers.length)liveIceServers=answer.iceServers;
        $('phone-record-controls').hidden=false;$('phone-live').hidden=false;
        $('phone-record-share-choice').hidden=!device?.liveProduction;
        if(!stream)$('phone-start-camera').hidden=false;
        setConnection('Approved camera',true);
        if(!recorder||recorder.state!=='recording')setStatus('Connected. Record footage or share this camera with the editor.');
        approvalTimer=setTimeout(checkApproval,12000);return;
      }
      $('phone-record-controls').hidden=true;$('phone-start-camera').hidden=true;
      setConnection('Waiting for approval');
      setStatus('Request sent. Waiting for the owner to approve this phone on their editor…');
      approvalTimer=setTimeout(checkApproval,2000);
    }catch(e){
      sessionStorage.removeItem(tokenKey);clearRecovery();device=null;stopCamera();
      $('phone-record-controls').hidden=true;$('phone-start-camera').hidden=true;setConnection('Disconnected');
      setStatus(phoneUploadErrorMessage(e)+' Scan a new QR code to reconnect.',true);
    }
  }
  function stopLive(preserveIntent=false){
    if(!preserveIntent){liveWanted=false;liveRetry=0;clearTimeout(liveRetryTimer);liveRetryTimer=null;}
    const old=liveNonce;liveNonce='';clearTimeout(liveTimer);liveTimer=null;
    // A dropped preview or camera change must not invalidate an in-progress
    // gallery upload. Keep uploadToken/uploadFile until the upload succeeds.
    try{peer?.close();}catch{}peer=null;
    liveAudioStream?.getTracks().forEach(track=>track.stop());liveAudioStream=null;
    if(old&&device)void signal('end',{nonce:old}).catch(()=>{});
    $('phone-live-badge').hidden=true;if(!preserveIntent)autoSharedByRecording=false;
    const btn=$('phone-live');btn.disabled=false;btn.classList.remove('is-sharing');btn.setAttribute('aria-pressed','false');btn.setAttribute('aria-label','Share live camera');btn.querySelector('.azh-phone-live-text').textContent='Share live';
  }
  function stopCamera(){
    if(stream){for(const track of stream.getTracks())track.stop();stream=null;}
    $('phone-preview').srcObject=null;setCameraReady(false);stopLive();
  }
  async function startCamera(){
    if(!navigator.mediaDevices?.getUserMedia)throw Error('This browser cannot use a camera on the current connection.');
    if(recorder?.state==='recording')throw Error('Stop recording before switching cameras.');
    stopCamera();
    stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:backFacing?'environment':'user'}},audio:true});
    $('phone-preview').srcObject=stream;
    await $('phone-preview').play().catch(()=>{});
    setCameraReady(true);setStatus('Camera ready. Frame your shot, then tap Record.');
  }
  $('phone-start-camera').addEventListener('click',()=>startCamera().catch(e=>setStatus(e.message,true)));
  $('phone-switch').addEventListener('click',()=>{backFacing=!backFacing;void startCamera().catch(e=>setStatus(e.message,true));});
  const mediaMime=()=>selectRecordingMime(m=>MediaRecorder.isTypeSupported(m));
  function savedClip(){return $('phone-file').files?.[0]||captured;}
  $('phone-file').addEventListener('change',()=>{
    uploadFile=$('phone-file').files?.[0]||null;
    uploadToken=uploadFile?getRecovery(uploadFile):null;
    previewTrimAt=0;trimDuration=0;$('phone-trim-editor').hidden=true;
    if(trimSource?.url){URL.revokeObjectURL(trimSource.url);trimSource=null;}
    if(uploadFile){
      trimSource={file:uploadFile,url:URL.createObjectURL(uploadFile)};
      $('phone-playback').src=trimSource.url;
      $('phone-download').href=trimSource.url;$('phone-download').download=uploadFile.name;
      $('phone-recorded').hidden=false;
      openDrawer(true);setStatus('Selected footage: '+uploadFile.name);
    }
  });
  function tickRecording(){
    const elapsed=Math.max(0,Math.floor((Date.now()-recordingStarted)/1000));
    $('phone-recording-clock').textContent=String(Math.floor(elapsed/60)).padStart(2,'0')+':'+String(elapsed%60).padStart(2,'0');
  }
  function stopClock(){clearInterval(recordingTimer);recordingTimer=null;$('phone-recording-badge').hidden=true;}
  $('phone-record').addEventListener('click',async()=>{
    if(busy)return;
    try{
      if(!stream)await startCamera();
      if(!window.MediaRecorder)throw Error('Recording is unsupported in this browser. Upload a camera-roll video instead.');
      const mime=mediaMime();chunks=[];captured=null;trimDuration=0;$('phone-file').value='';uploadFile=null;uploadToken=null;clearRecovery();$('phone-trim-editor').hidden=true;
      recorder=new MediaRecorder(stream,mime?{mimeType:mime}:undefined);
      recorder.ondataavailable=e=>{if(e.data?.size)chunks.push(e.data);};
      recorder.onerror=()=>setStatus('Recorder interrupted. Stop and download any saved footage before leaving.',true);
      recorder.onstop=()=>{
        stopClock();const type=recorder.mimeType||mime||'video/webm';const extension=type.includes('mp4')?'mp4':'webm';
        const blob=new Blob(chunks,{type});
        $('phone-record').disabled=false;$('phone-stop').disabled=true;
        if(!blob.size){setStatus('The camera did not produce a playable file. Try another recording.',true);return;}
        captured=new File([blob],`azhottie-phone-${Date.now()}.${extension}`,{type});
        if(backupUrl)URL.revokeObjectURL(backupUrl);
        backupUrl=URL.createObjectURL(blob);uploadFile=captured;
        $('phone-playback').src=backupUrl;$('phone-download').href=backupUrl;$('phone-download').download=captured.name;
        $('phone-recorded').hidden=false;openDrawer(true);
        setStatus('Recording ready. Save it to the private gallery or download a backup.');
      };
      recorder.start(750);recordingStarted=Date.now();tickRecording();recordingTimer=setInterval(tickRecording,500);
      $('phone-recording-badge').hidden=false;$('phone-record').disabled=true;$('phone-stop').disabled=false;
      openDrawer(false);setStatus('Recording… Tap Stop when finished.');
      // A QR-invited Director camera can opt in to preview automatically on
      // Record. Sharing only starts after the phone operator has chosen the
      // visible checkbox; it never takes the camera to public Program.
      if(mode==='paired'&&device?.liveProduction&&$('phone-share-on-record')?.checked&&!liveNonce){
        const automaticPreview=shareLive();
        autoSharedByRecording=true;
        void automaticPreview.catch(retryLive);
      }
    }catch(e){stopClock();setStatus(e.message,true);$('phone-record').disabled=false;}
  });
  $('phone-stop').addEventListener('click',()=>{if(recorder?.state==='recording')recorder.stop();if(autoSharedByRecording&&liveWanted)stopLive();});
  $('phone-upload').addEventListener('click',async()=>{
    if(busy)return;
    if(recorder?.state==='recording'){setStatus('Stop recording before uploading.',true);return;}
    const file=savedClip();
    if(!cameraFileSupported(file)){setStatus('Record a clip or select a video file first.',true);openDrawer(true);return;}
    if(file!==uploadFile){uploadFile=file;uploadToken=getRecovery(file);}
    if(!uploadToken)uploadToken=getRecovery(file)||randomId();
    rememberRecovery(file,uploadToken);
    const title=$('phone-title').value.trim()||file.name.replace(/\.[^.]+$/,'');
    busy=true;$('phone-upload').disabled=true;$('phone-record').disabled=true;
    $('phone-upload-progress').hidden=false;
    $('phone-upload-hint').textContent='Upload in progress. Keep this camera open until the server confirms the save.';
    let stage='starting';
    try{
      const result=await uploadToPersonalGallery(api,file,title,{
        token:uploadToken,preferredChunkBytes:262144,maxRetries:3,
        onStage:s=>{stage=s;},
        onProgress:(done,total)=>{
          const pct=Math.round(100*done/total);$('phone-upload-progress').value=pct;
          setStatus(pct>=100?'Finalizing video in your private gallery…':`Uploading ${pct}%…`);
        },
        onRetry:({stage:phase,attempt,maxRetries})=>setStatus(`Connection interrupted at ${phase}. Retrying ${attempt}/${maxRetries}…`)
      });
      setStatus(`Upload complete. “${result.title}” is saved privately in the model’s editor.`);
      $('phone-upload-hint').textContent='Saved to the model’s private gallery. You can record another take.';
      $('phone-file').value='';uploadToken=null;uploadFile=null;clearRecovery();
      $('phone-upload-progress').value=100;
      // Keep captured/backup available until the next recording, for safety.
    }catch(e){
      const msg=phoneUploadErrorMessage(e);
      setStatus(`Upload interrupted during ${stage}: ${msg}`,true);
      $('phone-upload-hint').textContent='The recorded clip is still on this phone. Tap Upload again to resume, or save the backup before leaving. '+msg;
    }finally{busy=false;$('phone-upload').disabled=false;$('phone-record').disabled=false;}
  });
  // Lightweight edit for the dedicated phone-camera app. Original footage is
  // never overwritten; rendering creates a second locally backed-up file.
  const trimPanel=$('phone-trim-editor'),trimPlayer=$('phone-playback');
  function trimStatus(message,error=false){const el=$('phone-trim-status');el.textContent=message;el.classList.toggle('is-error',error);}
  function closeTrim(){previewTrimAt=0;trimPanel.hidden=true;$('phone-edit').setAttribute('aria-expanded','false');trimPlayer.pause();}
  function durationOfTrim(){const d=Number(trimPlayer.duration);return Number.isFinite(d)&&d>0?d:trimDuration;}
  $('phone-edit').addEventListener('click',async()=>{
    if(busy||recorder?.state==='recording')return;
    if(!cameraFileSupported(savedClip())){setStatus('Record or choose a video first.',true);return;}
    if(savedClip()!==captured && trimSource?.file!==savedClip()){
      // Uploaded camera-roll files need a local preview, not an account upload.
      if(trimSource?.url)URL.revokeObjectURL(trimSource.url);
      trimSource={file:savedClip(),url:URL.createObjectURL(savedClip())};
      trimPlayer.src=trimSource.url;
      $('phone-recorded').hidden=false;
    }
    trimPanel.hidden=!trimPanel.hidden;
    $('phone-edit').setAttribute('aria-expanded',String(!trimPanel.hidden));
    if(trimPanel.hidden)return;
    openDrawer(true);
    if(trimPlayer.readyState<1)await new Promise(resolve=>{
      const done=()=>{trimPlayer.removeEventListener('loadedmetadata',done);trimPlayer.removeEventListener('error',done);resolve();};
      trimPlayer.addEventListener('loadedmetadata',done,{once:true});trimPlayer.addEventListener('error',done,{once:true});
      setTimeout(done,6000);
    });
    // Some phone-generated WebM files report Infinity duration until probed.
    // The existing media engine contains a bounded metadata-seek recovery.
    if(!durationOfTrim()){
      try{const {getVideoMetadata}=await import('./media-engine.js');trimDuration=(await getVideoMetadata(savedClip())).duration||0;}
      catch{trimDuration=0;}
    }
    $('phone-trim-in').value='0';$('phone-trim-out').value=String(Math.floor(durationOfTrim()*10)/10);
    trimStatus(durationOfTrim()?'Move the playback position, then choose Start here or End here.':'Preview metadata unavailable. Try another video.');
  });
  $('phone-trim-mark-in').addEventListener('click',()=>{$('phone-trim-in').value=trimPlayer.currentTime.toFixed(1);});
  $('phone-trim-mark-out').addEventListener('click',()=>{$('phone-trim-out').value=trimPlayer.currentTime.toFixed(1);});
  trimPlayer.addEventListener('timeupdate',()=>{
    if(previewTrimAt>0&&trimPlayer.currentTime>=previewTrimAt){trimPlayer.pause();previewTrimAt=0;}
  });
  $('phone-trim-preview').addEventListener('click',async()=>{
    const start=Number($('phone-trim-in').value),end=Number($('phone-trim-out').value),duration=durationOfTrim();
    if(!(start>=0&&end>start+.05&&end<=duration+.05)){trimStatus('Choose a valid start and end within the video.',true);return;}
    trimPlayer.currentTime=start;previewTrimAt=end;
    try{await trimPlayer.play();trimStatus('Previewing your trimmed selection.');}catch(e){previewTrimAt=0;trimStatus('Preview unavailable: '+e.message,true);}
  });
  $('phone-trim-cancel').addEventListener('click',closeTrim);
  $('phone-trim-save').addEventListener('click',async()=>{
    if(busy)return;
    const original=savedClip(),duration=durationOfTrim(),start=Number($('phone-trim-in').value),end=Number($('phone-trim-out').value);
    if(!cameraFileSupported(original)){trimStatus('Select a recording first.',true);return;}
    if(!(Number.isFinite(start)&&Number.isFinite(end)&&start>=0&&end>start+.1&&end<=duration+.05)){
      trimStatus('Choose start/end times within the clip, at least 0.1 seconds apart.',true);return;
    }
    if(end-start>600){trimStatus('Trim up to 10 minutes at a time on a phone to avoid memory problems.',true);return;}
    busy=true;$('phone-trim-save').disabled=true;$('phone-upload').disabled=true;
    const sourceUrl=URL.createObjectURL(original);
    try{
      trimStatus('Preparing trimmed video. Keep the camera open while it renders…');
      const {InterviewRenderer}=await import('./media-engine.js');
      const renderer=new InterviewRenderer($('phone-trim-canvas'));
      const aspect=trimPlayer.videoWidth>trimPlayer.videoHeight?'16:9':trimPlayer.videoHeight>trimPlayer.videoWidth?'9:16':'1:1';
      const result=await renderer.export([{src:sourceUrl,duration,trimStart:start,trimEnd:end,question:'',transition:'cut'}],{
        cardMode:'none',fadeDuration:0,fit:'contain',aspect,resolution:720,brand:'',modelName:''
      },{onStatus:message=>trimStatus(message),onProgress:progress=>{
        const pct=Math.min(99,Math.round((progress.ratio||0)*100));trimStatus('Trimming video… '+pct+'%');
      }});
      const ext=result.extension||'webm';const file=new File([result.blob],`azhottie-trim-${Date.now()}.${ext}`,{type:result.mimeType||result.blob.type});
      // Reset the upload identity so the new bytes cannot resume an old upload.
      captured=file;uploadFile=file;uploadToken=null;$('phone-file').value='';clearRecovery();
      if(backupUrl)URL.revokeObjectURL(backupUrl);
      if(trimSource?.url){URL.revokeObjectURL(trimSource.url);trimSource=null;}
      backupUrl=URL.createObjectURL(result.blob);
      trimPlayer.pause();trimPlayer.src=backupUrl;$('phone-download').href=backupUrl;$('phone-download').download=file.name;
      $('phone-recorded').hidden=false;closeTrim();openDrawer(true);
      setStatus('Trimmed clip ready. Preview it, upload privately, or download your backup.');
    }catch(e){trimStatus('Could not trim this video: '+e.message+'. Your original recording is unchanged.',true);}
    finally{URL.revokeObjectURL(sourceUrl);busy=false;$('phone-trim-save').disabled=false;$('phone-upload').disabled=false;}
  });
  async function signal(action,body=null,params={}){
    if(mode==='signed-in')throw Error('Paired camera permission is required for guest live sharing.');
    return api(action,body,params);
  }
  async function gather(pc){if(pc.iceGatheringState==='complete')return;await new Promise(resolve=>{
    const finish=()=>{clearTimeout(t);pc.removeEventListener('icegatheringstatechange',check);resolve();};
    const check=()=>{if(pc.iceGatheringState==='complete')finish();};
    const t=setTimeout(finish,8000);pc.addEventListener('icegatheringstatechange',check);
  });}
  async function shareLive(){
    if(!window.RTCPeerConnection)throw Error('Live preview requires WebRTC. You can still upload recordings.');
    if(!stream)await startCamera();
    liveWanted=true;stopLive(true);liveNonce=randomId();const current=liveNonce;
    peer=new RTCPeerConnection({iceServers:liveIceServers});
    // Never send the recorder microphone automatically. Only explicitly
    // enabled live microphone sharing may add audio below.
    for(const track of stream.getVideoTracks())peer.addTrack(track,stream);
    if($('phone-share-audio')?.checked){
      try {
        liveAudioStream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true},video:false});
        if(current!==liveNonce){liveAudioStream.getTracks().forEach(t=>t.stop());liveAudioStream=null;return;}
        for(const track of liveAudioStream.getAudioTracks())peer.addTrack(track,liveAudioStream);
      } catch(e){stopLive();throw Error('Could not share the phone microphone. Uncheck the microphone option to share video only. '+e.message);}
    }
    if(current!==liveNonce)return;
    await peer.setLocalDescription(await peer.createOffer());await gather(peer);
    if(current!==liveNonce)return;
    await signal('offer',{nonce:current,sdp:peer.localDescription.sdp});
    const liveButton=$('phone-live');liveButton.disabled=false;liveButton.classList.add('is-sharing');liveButton.setAttribute('aria-pressed','true');liveButton.setAttribute('aria-label','Stop sharing live camera');liveButton.querySelector('.azh-phone-live-text').textContent='Stop sharing';
    setStatus('Offering live preview to the desktop editor. Waiting for connection…');
    const offeredAt=Date.now();
    peer.onconnectionstatechange=()=>{if(liveNonce===current&&peer.connectionState==='connected'){liveRetry=0;$('phone-live-badge').hidden=false;setStatus('Live camera connected to Director. You can record locally at the same time.');}};
    async function poll(){
      if(liveNonce!==current)return;
      try{
        const response=await signal('poll',null,{nonce:current});
        if(response.answer&&!peer.currentRemoteDescription)await peer.setRemoteDescription({type:'answer',sdp:response.answer});
        if(peer.connectionState==='connected'){$('phone-live-badge').hidden=false;liveRetry=0;}
        if(['disconnected','failed','closed'].includes(peer.connectionState))throw Error('Live video disconnected.');
        if(peer.connectionState!=='connected'&&Date.now()-offeredAt>23000)throw Error('No video path to the Director after 23 seconds. Check network/TURN settings.');
        if(liveNonce===current)liveTimer=setTimeout(poll,1800);
      }catch(e){retryLive(e);}
    }
    await poll();
  }
  function retryLive(error){
    if(!liveWanted)return;
    const reason=String(error?.message||'Connection interrupted');
    if([401,403].includes(error?.httpStatus)||['camera_disconnected','camera_pending'].includes(error?.code)||liveRetry>=3||!stream?.getVideoTracks().some(t=>t.readyState==='live')){
      stopLive();setStatus('Live preview unavailable: '+reason+'. Recording continues privately. Tap Share live to retry.',true);return;
    }
    liveRetry++;stopLive(true);const attempt=liveRetry;
    const delay=Math.min(8000,1600*2**(attempt-1));
    $('phone-live').disabled=false;$('phone-live').setAttribute('aria-pressed','true');$('phone-live').setAttribute('aria-label','Cancel live camera reconnection');$('phone-live').querySelector('.azh-phone-live-text').textContent='Cancel reconnect';setStatus(`Reconnecting live camera ${attempt}/3 in ${Math.ceil(delay/1000)}s… Local recording continues.`,true);
    liveRetryTimer=setTimeout(()=>{liveRetryTimer=null;if(liveWanted)void shareLive().catch(retryLive);},delay);
  }
  $('phone-live').addEventListener('click',()=>{
    if(liveWanted){stopLive();setStatus('Live preview stopped. Recordings and uploads still work.');return;}
    $('phone-live').disabled=true;
    liveWanted=true;liveRetry=0;void shareLive().catch(retryLive);
  });
  window.addEventListener('online',()=>{if(liveWanted&&!liveNonce&&!liveRetryTimer)void shareLive().catch(retryLive);});
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible'&&liveWanted&&!liveNonce&&!liveRetryTimer)void shareLive().catch(retryLive);});
  window.addEventListener('pagehide',()=>{
    clearTimeout(approvalTimer);stopClock();stopCamera();if(backupUrl)URL.revokeObjectURL(backupUrl);
    if(trimSource?.url)URL.revokeObjectURL(trimSource.url);
  });
  void authorize();
}
