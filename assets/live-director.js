import {HostPhoneFeeds,waitForIce} from './phone-live.js?v=1.19.1';
import {directorObservation} from './live-agent-health.js';
import {randomId,selectRecordingMime,uploadToPersonalGallery} from './personal-phone-core.js';
import {nextProgramId,switchDelay,fitCover,statusText,transitionProgress,safeOverlayText} from './live-core.js';
import {CameraHealth,cameraStateText,cameraSummary,cameraPermissionMessage} from './live-camera-health.js';
import {liveVideoTrack,ViewerVideoRoute,outgoingFrameSample} from './live-program-delivery.js';
import {PhoneRepairBudget} from './live-recovery.js';
import {recordingStartBlockReason,mixerReadiness} from './live-production-guards.js';
import {normalizeLayout,layoutRects,compositionReady,chooseRundownSegment,safeShowText,rundownPreview} from './live-show-core.js';

const root=document.getElementById('azh-director');
if (root) {
  const $=id=>document.getElementById(id),csrf=root.dataset.csrf;
  const apiEndpoint=new URL('live-api.php',document.baseURI),phoneEndpoint=new URL('personal-phone-api.php',document.baseURI),mediaEndpoint=new URL('personal-media-api.php',document.baseURI);
  const ctx=$('live-program-canvas').getContext('2d',{alpha:false});
  const stage=$('live-program-canvas');
  const sources=new Map(), sourceCards=new Map(), idleCards=new Map(), viewers=new Map(), timers=new Set();
  const phoneStates=new Map(),phoneRepair=new PhoneRepairBudget(),phoneRepairTimers=new Map();
  let activeLayout=normalizeLayout('single',['']),draftLayout=normalizeLayout('single',['']);
  let beforeMediaLayout=null,mediaProgramActive=false,mediaCueId='',programMediaId='',mediaLibrary=[],mediaGain=null;
  // Every Take prepares a separate media element. An invalid or undecodable next
  // video never stops the previous on-air video while it is being prepared.
  let programVideo=null;const mediaAudioNodes=new WeakMap();
  function programMediaElement(){
    const video=document.createElement('video');video.preload='auto';video.playsInline=true;
    video.addEventListener('ended',()=>{if(mediaProgramActive&&programVideo===video)stopMediaPlayback(true);});
    return video;
  }
  let rundowns=[],rundownId='',rundownRevision=0,rundownSegments=[],rundownCursor=-1,rundownTaken=-1,rundownManualSelection=false,rundownDirty=false,rundownTakeBusy=false;
  const layoutStage=$('live-layout-canvas'),layoutCtx=layoutStage?.getContext('2d',{alpha:false});
  let previewSelected=null,transitionStart=0,transitionMode='cut',programTakeEpoch=0;
  const oldFrame=document.createElement('canvas');oldFrame.width=stage.width;oldFrame.height=stage.height;const oldCtx=oldFrame.getContext('2d',{alpha:false});
  const overlay={brand:true,source:true,custom:false,text:'',position:'lower'};
  const imageOverlay={id:'',enabled:false,position:'top-right',scale:24,image:null,sequence:0};
  let savedScenes=[],savedOverlays=[],pendingSceneSettings=null;
  let selected=null,room=null,programStream=null,canvasStream=null,audioCtx=null,audioDestination=null,micStream=null,micNode=null,micGain=null,micAnalyser=null,oscillator=null,cameraAudioNodes=new Map();
  let recorder=null,chunks=[],recordedFile=null,recordUrl=null,recordRoom=null,recordToken=null,savedMediaId=null,saving=false,connectedPhones=[],switchDeadline=0,raf=0,lastFrame=0,loop=null,phonePollBusy=false,viewerPollBusy=false,inviteUrl='',phoneIceServers=null;
  const iceServers=()=>room?.iceServers||[{urls:['stun:stun.l.google.com:19302']}];
  let usedHiddenRelay=false;
  let cameraFaultActive=false,interruptedSourceId=null,recordingStarting=false,recordingFinalizing=false;
  let latestDelivery={viewerCount:0,sentFps:null,stalledOutbound:0,directRelays:0},healthSending=false;
  function localHealth(){
    const healthy=sourceIds().length;
    const count=connectedPhones.filter(device=>device.status==='approved').length;
    const mode=mediaProgramActive?'media':!activeLayout.slots.some(Boolean)?'slate':activeLayout.type==='single'?'single':'composition';
    return directorObservation({cameraCount:count,healthyCameras:healthy,
      cameraOnProgram:mode==='single'&&Boolean(selected),cameraFault:cameraFaultActive,programMode:mode,
      viewerCount:latestDelivery.viewerCount,sentFps:latestDelivery.sentFps,
      stalledOutbound:latestDelivery.stalledOutbound,directRelays:latestDelivery.directRelays,pageHidden:document.hidden});
  }
  async function reportLiveHealth(){
    if(!room||healthSending)return;
    const roomId=room.id;healthSending=true;
    try{
      await liveRequest('healthReport',{roomId,source:'director',metrics:localHealth()});
      if(room?.id===roomId)$('live-agent-health-summary').textContent='Health measurements shared with Admin Brain';
    }catch(e){
      if(room?.id===roomId)$('live-agent-health-summary').textContent='Live health reporting unavailable · streaming is unaffected';
    }finally{healthSending=false;}
  }

  function desiredViewerTrack(viewer){return liveVideoTrack({hidden:document.hidden||Boolean(viewer?.forceDirect),mediaProgramActive,activeLayout,selected,sources,canvasTrack:programStream?.getVideoTracks()[0]});}
  function routeViewerVideo(){
    for(const v of viewers.values()){
      if(!v.router)continue;const track=desiredViewerTrack(v);if(!track)continue;
      if(document.hidden&&track!==programStream?.getVideoTracks()[0])usedHiddenRelay=true;
      void v.router.setTarget(track);
    }
  }
  document.addEventListener('visibilitychange',()=>{
    routeViewerVideo();
    if(!document.hidden&&usedHiddenRelay){
      usedHiddenRelay=false;
      feedback('Full Program compositing resumed. While the Director was in the background, viewers received direct phone video (without canvas overlays). Keep this tab visible for overlays and recordings.');
    }
    if(!document.hidden)renderProgram(performance.now());
  });
  const feedback=(message,bad=false)=>{const status=$('live-status');status.textContent=message;status.hidden=!message;status.classList.toggle('is-error',bad);};
  const reportRecord=(message,bad=false)=>{$('live-record-message').textContent=message;$('live-record-message').classList.toggle('is-error',bad);};
  const escapeText=s=>String(s??'');
  async function request(endpoint,action,body=null,query={},opts={}) {
    const url=new URL(endpoint);url.searchParams.set('action',action);
    for(const [k,v] of Object.entries(query))url.searchParams.set(k,String(v));
    const form=body instanceof FormData;
    let res;
    try {res=await fetch(url,{method:body===null?'GET':'POST',credentials:'same-origin',cache:'no-store',headers:body===null?{}:{'X-CSRF-Token':csrf,...(form?{}:{'Content-Type':'application/json'})},...(body===null?{}:{body:form?body:JSON.stringify(body)}),...opts});}
    catch {throw new Error('Network connection interrupted. Keep your original recording and retry.');}
    const data=await res.json().catch(()=>null);
    if(!res.ok||!data?.ok){const error=new Error(data?.error?.message||`Studio request failed (${res.status})`);error.status=res.status;error.code=data?.error?.code;throw error;}
    return data.data;
  }
  const liveRequest=(a,b=null,q={},o={})=>request(apiEndpoint,a,b,q,o);
  const phoneRequest=(a,b=null,q={},o={})=>request(phoneEndpoint,a,b,q,o);
  const mediaRequest=(a,b=null,q={})=>request(mediaEndpoint,a,b,q);
  const button=(title,onClick,cls='azh-live-button azh-live-secondary')=>{const b=document.createElement('button');b.type='button';b.className=cls;b.textContent=title;b.addEventListener('click',onClick);return b;};
  function sourceIds(){return [...sources.keys()].filter(id=>{const item=sources.get(id);return item?.kind==='phone'&&item.stream?.getVideoTracks?.().some(t=>t.readyState==='live')&&item.health?.playable();});}
  function cameraCounts(){
    const count=cameraSummary([...sources.values()].map(item=>item.health?.update()||'starting'));
    const total=connectedPhones.filter(device=>['approved','pending'].includes(device.status)).length;
    const label=$('live-camera-count');if(label)label.textContent=`${count.live} live / ${total} cameras${total>count.live?' · '+(total-count.live)+' waiting':''}`;
  }
  function activeSourceIds(){return activeLayout?.slots||[];}
  function drawnSource(canvasContext,item,x,y,w,h){
    if(!item?.health?.playable())return false;
    const r=fitCover(item.video.videoWidth,item.video.videoHeight,w,h);
    if(!r)return false;
    try{canvasContext.drawImage(item.video,x+r.x,y+r.y,r.width,r.height);return true;}
    catch{return false;}
  }
  function paintComposition(canvasContext,layout,width,height,{preview=false}={}){
    canvasContext.fillStyle='#19121b';canvasContext.fillRect(0,0,width,height);
    const rectangles=layoutRects(layout.type,width,height);
    let painted=0;
    rectangles.forEach((r,i)=>{
      const item=sources.get(layout.slots[i]);
      canvasContext.save();canvasContext.beginPath();canvasContext.rect(r.x,r.y,r.w,r.h);canvasContext.clip();
      if(drawnSource(canvasContext,item,r.x,r.y,r.w,r.h))painted++;
      else{
        canvasContext.fillStyle=preview?'#241825':'#20141f';canvasContext.fillRect(r.x,r.y,r.w,r.h);
        canvasContext.fillStyle='#dfb8cb';canvasContext.textAlign='center';
        canvasContext.font=`bold ${Math.max(12,Math.min(24,r.w/20))}px Arial,sans-serif`;
        canvasContext.fillText(item?'CAMERA UNAVAILABLE':'CAMERA INACTIVE',r.x+r.w/2,r.y+r.h/2,r.w-12);
        canvasContext.textAlign='left';
      }
      if(layout.type!=='single'){
        canvasContext.strokeStyle='#f7e5ef';canvasContext.lineWidth=2;canvasContext.strokeRect(r.x+1,r.y+1,r.w-2,r.h-2);
      }
      canvasContext.restore();
    });
    return painted;
  }
  function paintLayoutPreview(){
    if(!layoutCtx||!layoutStage)return;
    paintComposition(layoutCtx,draftLayout,layoutStage.width,layoutStage.height,{preview:true});
    // Scene Preview may display its draft label without touching the captured Program.
    if(pendingSceneSettings?.custom&&pendingSceneSettings.text){
      layoutCtx.fillStyle='rgba(25,12,28,.75)';layoutCtx.fillRect(12,layoutStage.height-49,layoutStage.width-24,38);
      layoutCtx.fillStyle='#fff';layoutCtx.font='bold 22px Arial,sans-serif';
      layoutCtx.fillText(safeOverlayText(pendingSceneSettings.text),24,layoutStage.height-23,layoutStage.width-48);
    }
  }
  // A WebRTC phone peer can stay 'connected' while its decoded preview is frozen.
  // Close only that stale approved feed (never another Program source, approval or
  // the MediaRecorder), causing the PHONE to negotiate a fresh offer. Bounded to
  // two attempts per phone in two minutes; a human can explicitly retry.
  function cancelPhoneRepair(id){const timer=phoneRepairTimers.get(id);if(timer!==undefined)clearTimeout(timer);phoneRepairTimers.delete(id);}
  function requestPhoneRepair(item,{manual=false}={}){
    if(!item||item.kind!=='phone'||sources.get(item.id)!==item)return;
    const phoneId=item.id.slice(6);
    const device=connectedPhones.find(d=>d.id===phoneId);
    if(!device||device.status!=='approved'||!device.sharing){
      feedback('This phone is not actively sharing. Open Camera Studio on the phone and select Share live.',true);return;
    }
    if(document.hidden&&!manual)return;
    if(!manual&&item.health?.update()!=='stalled')return;
    const peer=phones.peers.get(phoneId);
    if(!peer){
      feedback('No active phone relay to restart. Keep the phone open, or stop and restart Share live on that phone.',true);
      return;
    }
    const next=phoneRepair.next(phoneId,{manual});
    if(!next){
      phoneStates.set(phoneId,'Automatic reconnect limit reached. Restart Share live on this phone.');
      feedback('Phone '+(device.name||'camera')+' has a frozen preview after two recovery attempts. Restart sharing on the phone or select Reconnect feed.',true);
      return;
    }
    // Removing a single HostPhoneFeeds peer never changes source approval.
    // Its onRemove hook drops only that source and returns an affected Program
    // to the existing holding slate; the recording remains active.
    const oldNonce=peer.nonce;
    phones.remove(phoneId,true);
    const message=(manual?'Manual':'Automatic')+' phone video recovery '+next.attempt+'/'+next.maxAttempts+' requested. Waiting for a new phone offer…';
    phoneStates.set(phoneId,message);showSources();feedback(message,true);
    // Owner-authenticated, CSRF-checked signaling reset makes the phone's
    // next poll reoffer immediately instead of waiting for WebRTC ICE timeout.
    // Nonce guard ensures any newer offer/phone connection is never touched.
    void phoneRequest('resetFeed',{id:phoneId,nonce:oldNonce}).then(response=>{
      if(response.reset)feedback('Phone relay restarted. Waiting for its fresh camera offer…');
    }).catch(error=>{
      feedback('Could not confirm phone relay reset: '+statusText(error)+'. Ask the phone to stop and restart Share live.',true);
    });
  }
  function queuePhoneRepair(item){
    if(item.kind!=='phone'||document.hidden||phoneRepairTimers.has(item.id))return;
    const timer=setTimeout(()=>{
      phoneRepairTimers.delete(item.id);
      if(sources.get(item.id)===item&&!document.hidden&&item.health?.update()==='stalled')requestPhoneRepair(item);
    },1500);
    phoneRepairTimers.set(item.id,timer);
  }
  function onSourceHealth(item,state){
    if(item.kind==='phone'){
      phoneRepair.healthy(item.id.slice(6),state==='live');
      if(state==='stalled')queuePhoneRepair(item);
      else cancelPhoneRepair(item.id);
    }
    if(!sources.has(item.id))return;
    if(selected===item.id&&!mediaProgramActive&&activeLayout.type==='single'&&['stalled','muted','offline','blocked'].includes(state)&&!(document.hidden&&state==='stalled')){
      interruptedSourceId=item.id;cameraFaultActive=Boolean(room);selected=null;activeLayout=normalizeLayout('single',['']);transitionStart=0;routeViewerVideo();feedback('The on-air camera stopped delivering decoded video. Program is showing the holding slate until a healthy feed is taken.',true);
      if($('live-switch-mode').value!=='manual')queueMicrotask(()=>{const ready=sourceIds();if(!selected&&ready.length&&activeLayout.type==='single')takeSource(ready[0]);});
    }
    showSources();
  }
  function renderProgram(now=performance.now()){
    if(now-lastFrame>=30){lastFrame=now;const W=stage.width,H=stage.height;
      ctx.fillStyle='#19121b';ctx.fillRect(0,0,W,H);
      const item=sources.get(selected);
      if(mediaProgramActive){
        if(programVideo?.readyState>=2&&programVideo.videoWidth){
          const r=fitCover(programVideo.videoWidth,programVideo.videoHeight,W,H);
          if(r)try{ctx.drawImage(programVideo,r.x,r.y,r.width,r.height);}catch{}
        }else{ctx.fillStyle='#f5d4e2';ctx.textAlign='center';ctx.font='24px Arial,sans-serif';ctx.fillText('Loading prerecorded video…',W/2,H/2);ctx.textAlign='left';}
      }else if(activeLayout.slots.some(Boolean)){
        paintComposition(ctx,activeLayout,W,H);
      }else {
        ctx.textAlign='center';ctx.fillStyle='#f4d5e2';ctx.font='bold 66px Arial, sans-serif';ctx.fillText('AZHOTTIE',W/2,H/2-12);
        ctx.font='24px Arial, sans-serif';ctx.fillText('The live presentation begins here',W/2,H/2+41);ctx.textAlign='left';
      }
      // A transition composites inside the SAME Program canvas. Viewers and the
      // recorder therefore see exactly what this monitor shows.
      if(transitionStart){
        const p=transitionProgress(transitionStart,now);
        if(p>=1){transitionStart=0;transitionMode='cut';}
        else if(transitionMode==='dissolve'){ctx.save();ctx.globalAlpha=1-p;ctx.drawImage(oldFrame,0,0);ctx.restore();}
        else if(transitionMode==='fade'){ctx.fillStyle=`rgba(0,0,0,${Math.sin(Math.PI*p).toFixed(4)})`;ctx.fillRect(0,0,W,H);}
      }
      if(overlay.source&&item&&!mediaProgramActive){
        ctx.fillStyle='rgba(14,10,16,.6)';ctx.fillRect(0,H-66,W,66);
        ctx.fillStyle='#fff';ctx.font='600 25px Arial, sans-serif';ctx.fillText(item.label,28,H-27,W-56);
      }
      if(imageOverlay.enabled&&imageOverlay.image?.naturalWidth){
        const image=imageOverlay.image;
        const width=Math.min(W*.5,W*imageOverlay.scale/100),height=Math.min(H*.7,width*image.naturalHeight/image.naturalWidth);
        const x=imageOverlay.position.endsWith('right')?W-width-24:24;
        const y=imageOverlay.position.startsWith('bottom')?H-height-24:(room?70:24);
        try{ctx.drawImage(image,x,y,width,height);}catch{/* overlay may be loading */}
      }
      if(overlay.brand){ctx.fillStyle='#fff';ctx.fillRect(20,20,190,41);ctx.fillStyle='#b52a64';ctx.font='bold 21px Arial,sans-serif';ctx.fillText('AZHOTTIE  ●',36,49);}
      if(overlay.custom&&overlay.text){
        ctx.save();ctx.textAlign='left';ctx.font='bold 33px Arial,sans-serif';
        const y=overlay.position==='upper'?124:(overlay.source?H-106:H-44);
        const width=Math.min(W-56,Math.ceil(ctx.measureText(overlay.text).width)+30);
        ctx.fillStyle='rgba(23,11,24,.74)';ctx.fillRect(20,y-39,width,50);
        ctx.fillStyle='#fff';ctx.fillText(overlay.text,34,y-5,W-76);ctx.restore();
      }
      if(room){ctx.fillStyle='#a92955';ctx.fillRect(W-140,20,116,40);ctx.fillStyle='#fff';ctx.font='bold 20px Arial,sans-serif';ctx.fillText('LIVE',W-118,47);}
      if(room&&switchDeadline&&now>=switchDeadline&&!mediaProgramActive&&activeLayout.type==='single'){nextCamera($('live-switch-mode').value==='random');scheduleSwitch();}
      $('live-program-source').textContent=mediaProgramActive?'Prerecorded · '+(mediaLibrary.find(x=>x.id===programMediaId)?.title||'Video'):
        (activeLayout.type!=='single'&&activeLayout.slots.some(Boolean)?activeLayout.type.toUpperCase()+' COMPOSITION':item?.label||'Holding slate');
      if(layoutCtx&&$('live-panel-compositions')&&!$('live-panel-compositions').hidden)paintLayoutPreview();
    }
  }
  function draw(now){renderProgram(now);raf=requestAnimationFrame(draw);}
  raf=requestAnimationFrame(draw);
  // A hidden tab does not run rAF. Keep the composition drawing when the
  // browser permits background timers; single-camera fans also get a direct
  // media-track relay because canvas background capture cannot be guaranteed.
  const hiddenRenderTimer=setInterval(()=>{if(document.hidden&&room)renderProgram(performance.now());},1000/15);
  // Every approved phone has one stable, private 16:9 preview tile. A connected
  // phone with no live frames retains its tile and shows a camera-off slate.
  // Never recreate an active <video> during a status poll or Program switch.
  function createSourceCard(item){
    const card=document.createElement('article');card.className='azh-live-source';card.dataset.sourceId=item.id;
    card.dataset.phoneId=item.id.slice(6);
    const wrap=document.createElement('div');wrap.className='azh-phone-preview';
    const inactive=document.createElement('div');inactive.className='azh-phone-inactive';inactive.innerHTML='<span class="azh-phone-inactive-icon" aria-hidden="true">◎</span><strong>Camera inactive</strong><small>Start recording or Share live on the connected phone.</small>';
    const indicator=document.createElement('span');indicator.className='azh-phone-live-indicator';indicator.textContent='● LIVE CAMERA';indicator.hidden=true;
    const resume=button('Tap to resume preview',async()=>{try{await item.video.play();item.health.playbackError=false;item.health.update();}catch{item.health.playRejected();}},'azh-source-resume');resume.hidden=true;
    wrap.append(item.video,inactive,indicator,resume);
    const meta=document.createElement('div');meta.className='azh-source-meta';
    const title=document.createElement('strong');title.textContent=item.label;
    const details=document.createElement('small');details.className='azh-source-state';meta.append(title,details);
    const actions=document.createElement('div');actions.className='azh-phone-card-actions';
    const take=button('Broadcast now',()=>takeSource(item.id),'azh-live-button azh-phone-broadcast');take.disabled=true;take.setAttribute('aria-label','Broadcast '+item.label+' now');
    const disconnect=button('Disconnect',async()=>{try{await phoneRequest('revoke',{id:item.id.slice(6)});removeSource(item.id);await refreshPhones();}catch(e){feedback('Could not disconnect phone: '+statusText(e),true);}},'azh-live-button azh-live-secondary azh-phone-disconnect');
    const repair=button('Reconnect feed',()=>requestPhoneRepair(item,{manual:true}),
      'azh-live-button azh-live-secondary azh-phone-repair');repair.hidden=true;
    repair.setAttribute('aria-label','Reconnect '+item.label+' video feed');
    actions.append(take,repair,disconnect);
    card.append(wrap,meta,actions);
    const tile={card,indicator,inactive,details,title,resume,take,repair};sourceCards.set(item.id,tile);
    return card;
  }
  function createIdlePhoneCard(device){
    const card=document.createElement('article');card.className='azh-live-source azh-phone-idle-card is-unhealthy';card.dataset.phoneId=device.id;
    const wrap=document.createElement('div');wrap.className='azh-phone-preview';
    const inactive=document.createElement('div');inactive.className='azh-phone-inactive';
    const icon=document.createElement('span');icon.className='azh-phone-inactive-icon';icon.setAttribute('aria-hidden','true');icon.textContent='◎';
    const title=document.createElement('strong');title.textContent='Camera inactive';
    const hint=document.createElement('small');hint.textContent='Start recording or Share live on this phone.';
    inactive.append(icon,title,hint);wrap.append(inactive);
    const meta=document.createElement('div');meta.className='azh-source-meta';
    const name=document.createElement('strong');name.textContent=device.name||'Phone camera';
    const state=document.createElement('small');state.className='azh-source-state';meta.append(name,state);
    const actions=document.createElement('div');actions.className='azh-phone-card-actions';
    const broadcast=button('Broadcast now',()=>{},'azh-live-button azh-phone-broadcast');broadcast.disabled=true;
    const approve=button('Approve',async()=>{try{await phoneRequest('approve',{id:device.id});await refreshPhones();}catch(e){feedback('Could not approve phone: '+statusText(e),true);}},'azh-live-button azh-live-secondary azh-phone-approve');
    const disconnect=button('Disconnect',async()=>{try{await phoneRequest('revoke',{id:device.id});removeSource('phone:'+device.id);await refreshPhones();}catch(e){feedback('Could not disconnect phone: '+statusText(e),true);}},'azh-live-button azh-live-secondary azh-phone-disconnect');
    actions.append(broadcast,approve,disconnect);card.append(wrap,meta,actions);
    const tile={card,name,state,inactive,title,hint,approve,broadcast};idleCards.set(device.id,tile);
    return tile;
  }
  function showSources(){
    const list=$('live-sources');if(!list)return;
    // The connected-phone column always shows every approved or pending camera.
    // Rendering source cards must not change streams or the active Program.
    const cards=[];
    for(const device of connectedPhones){
      if(!['approved','pending'].includes(device.status))continue;
      const id='phone:'+device.id,item=sources.get(id);
      if(item&&device.status==='approved'){
        let tile=sourceCards.get(id);
        if(!tile){createSourceCard(item);tile=sourceCards.get(id);}
        const state=item.health?.update()||'starting',ready=Boolean(item.health?.playable());
        tile.title.textContent=device.name||item.label;tile.card.classList.toggle('is-program',!mediaProgramActive&&activeLayout.slots.includes(id));
        tile.card.classList.toggle('is-preview',previewSelected===id);
        tile.card.classList.toggle('is-unhealthy',!ready);
        tile.inactive.hidden=ready;tile.indicator.hidden=!ready;
        tile.details.textContent=selected===id?'● ON PROGRAM':ready?`● LIVE · ${item.health.framesPerSecond()} fps`:cameraStateText(state);
        tile.take.disabled=!ready||(!mediaProgramActive&&activeLayout.type==='single'&&selected===id);tile.take.textContent=!mediaProgramActive&&activeLayout.type==='single'&&selected===id?'● Broadcasting':ready&&interruptedSourceId===id?'Restore to Program':'Broadcast now';
        tile.resume.hidden=state!=='blocked'&&state!=='paused';
        tile.repair.hidden=ready||!['stalled','muted','offline'].includes(state);
        tile.card.hidden=false;
        cards.push(tile.card);
        continue;
      }
      let tile=idleCards.get(device.id);if(!tile)tile=createIdlePhoneCard(device);
      tile.name.textContent=device.name||'Phone camera';
      tile.approve.hidden=device.status!=='pending';
      tile.state.textContent=device.status==='pending'?'Awaiting approval':device.sharing?(phoneStates.get(device.id)||'Phone is sharing · connecting video…'):(phoneStates.get(device.id)?.startsWith('Live')?'Approved · waiting for new live video':phoneStates.get(device.id)||'Approved · tap Share live on phone');
      tile.title.textContent=device.status==='pending'?'Waiting for approval':'Camera inactive';
      tile.hint.textContent=device.status==='pending'?'The Director must approve this phone first.':device.sharing?'Waiting for a usable video stream. Keep the phone camera open, or tap Stop sharing then Share live on the phone. If it never connects across networks, configure a TURN relay.':'Open Camera Studio on your phone and tap Share live. Recording alone does not always share a camera.';
      tile.card.hidden=false;
      cards.push(tile.card);
    }
    for(const card of Array.from(list.children))if(!cards.includes(card))card.remove();
    if(!cards.length){
      const hint=document.createElement('p');hint.className='azh-phone-empty';hint.textContent='No phones connected. Select Connect phone to invite your first camera.';
      list.append(hint);
    }else {
      for(let i=0;i<cards.length;i++)if(list.children[i]!==cards[i])list.insertBefore(cards[i],list.children[i]||null);
    }
    const valid=new Set(connectedPhones.map(d=>d.id));
    for(const [id,tile] of idleCards)if(!valid.has(id)){tile.card.remove();idleCards.delete(id);phoneRepair.clear(id);}
    for(const [id,tile] of sourceCards)if(!sources.has(id)){tile.card.remove();sourceCards.delete(id);}
    cameraCounts();
    refreshCompositionOptions();
  }
  function previewSource(id){
    const item=sources.get(id);if(!item)return;
    previewSelected=id;showSources();feedback('Selected '+item.label+' for a private preview. Press Broadcast now to send it to Program.');
  }
  function clearPreview(){previewSelected=null;showSources();}
  function addSource(id,stream,label,kind){
    if(!stream?.getVideoTracks().some(t=>t.readyState==='live'))return;
    if(sources.has(id))removeSource(id);
    const video=document.createElement('video');video.muted=true;video.defaultMuted=true;video.autoplay=true;video.playsInline=true;video.preload='auto';video.setAttribute('aria-label',label+' live thumbnail');video.srcObject=stream;
    const item={id,stream,label,kind,video,health:null};
    const health=new CameraHealth(video,stream,{onChange:state=>onSourceHealth(item,state)});item.health=health;
    sources.set(id,item);syncCameraAudio();showSources();health.start();routeViewerVideo();
    video.play().catch(()=>{if(sources.get(id)===item){health.playRejected();showSources();}});
    stream.getVideoTracks().forEach(track=>track.addEventListener('ended',()=>{if(sources.get(id)?.stream===stream)removeSource(id);},{once:true}));
    // Sources only enter the private preview list. Never expose a newly
    // connected camera to fans without an explicit Broadcast now action.
    scheduleSwitch();
  }
  function removeSource(id){
    const item=sources.get(id);if(!item)return;
    cancelPhoneRepair(id);sources.delete(id);detachCameraAudio(id);item.health?.stop();item.video.pause();item.video.srcObject=null;
    sourceCards.get(id)?.card.remove();sourceCards.delete(id);
    if(item.kind==='local')item.stream.getTracks().forEach(t=>t.stop());
    if(previewSelected===id)previewSelected=null;
    if(!mediaProgramActive&&activeLayout.type==='single'&&selected===id){interruptedSourceId=id;selected=null;activeLayout=normalizeLayout('single',['']);transitionStart=0;cameraFaultActive=Boolean(room);feedback('The on-air phone disconnected. Program returned to the holding slate.',true);}
    else if(!mediaProgramActive&&activeLayout.slots.includes(id)){cameraFaultActive=Boolean(room);feedback('One camera in the multiview disconnected. Its tile will show an inactive camera slate.',true);}
    showSources();scheduleSwitch();routeViewerVideo();
  }
  function transitionToProgram(mode){
    if(mode!=='cut'){oldCtx.drawImage(stage,0,0,oldFrame.width,oldFrame.height);transitionMode=mode;transitionStart=performance.now();}
    else{transitionMode='cut';transitionStart=0;}
  }
  function takeSlate(){interruptedSourceId=null;pendingSceneSettings=null;programTakeEpoch++;stopMediaPlayback(false);transitionToProgram($('live-transition').value);activeLayout=normalizeLayout('single',['']);selected=null;syncCameraAudio();showSources();scheduleSwitch();routeViewerVideo();feedback('Holding slate taken to Program.');}
  function takeComposition(layout){
    const requested=normalizeLayout(layout?.type,layout?.slots);
    if(!compositionReady(requested,sourceIds())){feedback('Every position needs a different healthy live phone before taking this composition to Program.',true);return false;}
    interruptedSourceId=null;programTakeEpoch++;stopMediaPlayback(false);transitionToProgram($('live-transition').value);
    activeLayout=requested;selected=requested.slots[0]||null;cameraFaultActive=false;syncCameraAudio();showSources();scheduleSwitch();routeViewerVideo();
    feedback('Took '+requested.type+' composition to Program. Only the composite Program canvas goes to viewers.');return true;
  }
  function takeSource(id){
    if(!sources.has(id)||(!mediaProgramActive&&activeLayout.type==='single'&&selected===id))return;
    if(!sources.get(id).health?.playable()){feedback('Camera inactive. Start recording or Share live on the phone and wait for live frames.',true);return;}
    const mode=$('live-transition').value;
    interruptedSourceId=null;pendingSceneSettings=null;programTakeEpoch++;stopMediaPlayback(false);transitionToProgram(mode);activeLayout=normalizeLayout('single',[id]);
    selected=id;syncCameraAudio();showSources();cameraFaultActive=false;scheduleSwitch();routeViewerVideo();feedback('Broadcasting '+sources.get(id).label+' ('+mode+').');
  }

  function nextCamera(random=false){const next=nextProgramId(sourceIds(),selected,{random});if(next)takeSource(next);else feedback('No connected camera available for switching.',true);}
  function scheduleSwitch(){const delay=room&&!mediaProgramActive&&activeLayout.type==='single'?switchDelay($('live-switch-mode').value):null;switchDeadline=delay?performance.now()+delay:0;}
  $('live-next').addEventListener('click',()=>nextCamera());$('live-switch-mode').addEventListener('change',scheduleSwitch);
  const tabButtons=Array.from(document.querySelectorAll('[data-live-tab]'));
  function refreshCompositionOptions(){
    const node=$('live-layout-slots');if(!node)return;
    const count=draftLayout.slots.length,prior=draftLayout.slots.slice();
    // Never re-create selectors during a phone status poll if neither the
    // connected source set nor the layout mode changed; preserve focus.
    const signature=[draftLayout.type,...sourceIds().sort()].join('|');
    if(node.dataset.signature===signature)return;
    node.dataset.signature=signature;node.replaceChildren();
    for(let i=0;i<count;i++){
      const wrap=document.createElement('label');wrap.textContent=['Primary','Second camera','Third camera','Fourth camera'][i];
      const select=document.createElement('select');select.dataset.slotIndex=String(i);select.setAttribute('aria-label','Camera slot '+(i+1));
      select.add(new Option('Choose a live phone',''));
      for(const id of sourceIds())select.add(new Option(sources.get(id)?.label||id,id));
      select.value=sourceIds().includes(prior[i])?prior[i]:'';
      draftLayout.slots[i]=select.value;
      select.addEventListener('change',()=>{draftLayout.slots[i]=select.value;paintLayoutPreview();});
      wrap.append(select);node.append(wrap);
    }
    paintLayoutPreview();
  }
  function setDraftLayout(layout){draftLayout=normalizeLayout(layout?.type,layout?.slots);$('live-layout-mode').value=draftLayout.type;
    $('live-layout-slots').dataset.signature='';refreshCompositionOptions();paintLayoutPreview();}
  $('live-layout-mode').addEventListener('change',()=>setDraftLayout(normalizeLayout($('live-layout-mode').value,draftLayout.slots)));
  $('live-layout-preview').addEventListener('click',()=>{paintLayoutPreview();$('live-layout-feedback').textContent='Private preview only. Take composition sends it to Program.';});
  $('live-layout-take').addEventListener('click',()=>{const taken=takeComposition(draftLayout);
    if(taken&&pendingSceneSettings){applySegmentSettings(pendingSceneSettings);pendingSceneSettings=null;scheduleSwitch();}
    $('live-layout-feedback').textContent=taken?'Composition sent to Program.':'Composition has an inactive, missing, or duplicate phone.';});
  setDraftLayout(draftLayout);
  function setDirectorTab(name,focus=false){
    for(const b of tabButtons){
      const active=b.dataset.liveTab===name,panel=$('live-panel-'+b.dataset.liveTab);
      b.setAttribute('aria-selected',String(active));b.tabIndex=active?0:-1;
      if(panel)panel.hidden=!active;
      if(active&&focus)b.focus();
    }
    if(name==='audience')void refreshAudience();
  }
  tabButtons.forEach((tab,i)=>{
    tab.addEventListener('click',()=>setDirectorTab(tab.dataset.liveTab));
    tab.addEventListener('keydown',e=>{
      const delta=e.key==='ArrowRight'?1:e.key==='ArrowLeft'?-1:0;
      const next=e.key==='Home'?0:e.key==='End'?tabButtons.length-1:delta?(i+delta+tabButtons.length)%tabButtons.length:null;
      if(next!==null){e.preventDefault();setDirectorTab(tabButtons[next].dataset.liveTab,true);}
    });
  });
  setDirectorTab('switching');
  // Operator controls affect the Program canvas and recorded output, never the
  // original camera streams. All text is painted on the canvas as plain text.
  for(const [id,key] of [['live-brand-overlay','brand'],['live-camera-overlay','source'],['live-custom-overlay','custom']]){
    $(id).addEventListener('change',()=>{overlay[key]=$(id).checked;});
  }
  $('live-overlay-text').addEventListener('input',()=>{overlay.text=safeOverlayText($('live-overlay-text').value);});
  $('live-overlay-position').addEventListener('change',()=>{overlay.position=$('live-overlay-position').value==='upper'?'upper':'lower';});

  function refreshSceneSelect(selectedId=''){
    const segmentScene=$('live-segment-scene'),prior=segmentScene.value;
    segmentScene.replaceChildren(new Option('Use current settings',''));
    for(const entry of savedScenes)segmentScene.add(new Option(entry.name,entry.id));
    if(savedScenes.some(s=>s.id===prior))segmentScene.value=prior;
    const choose=$('live-scene-select');choose.replaceChildren(new Option('Choose a saved scene',''));
    for(const entry of savedScenes)choose.add(new Option(entry.name,entry.id));
    if(savedScenes.some(s=>s.id===selectedId))choose.value=selectedId;
  }
  async function loadScenes(){
    try{const result=await liveRequest('scenes');savedScenes=result.scenes||[];refreshSceneSelect($('live-scene-select').value);}
    catch(e){feedback('Could not load saved scenes: '+statusText(e),true);}
  }
  function refreshOverlaySelect(selectedId=''){
    const select=$('live-image-select');select.replaceChildren(new Option('No image overlay',''));
    for(const entry of savedOverlays)select.add(new Option(entry.name,entry.id));
    if(savedOverlays.some(v=>v.id===selectedId))select.value=selectedId;
    else select.value='';
  }
  async function loadOverlays(){
    try{const result=await liveRequest('overlays');savedOverlays=result.overlays||[];refreshOverlaySelect(imageOverlay.id);}
    catch(e){feedback('Could not load overlays: '+statusText(e),true);}
  }
  function selectOverlayImage(id){
    imageOverlay.id=id;imageOverlay.image=null;const epoch=++imageOverlay.sequence;
    const selectedImage=savedOverlays.find(img=>img.id===id);
    if(!selectedImage){$('live-image-enable').checked=false;imageOverlay.enabled=false;return;}
    const img=new Image();img.onload=()=>{if(epoch===imageOverlay.sequence){imageOverlay.image=img;feedback('Image overlay ready for Program.');}};
    img.onerror=()=>{if(epoch===imageOverlay.sequence){imageOverlay.enabled=false;$('live-image-enable').checked=false;feedback('Overlay image could not be loaded.',true);}};
    img.src=selectedImage.url;
  }
  $('live-image-select').addEventListener('change',()=>selectOverlayImage($('live-image-select').value));
  $('live-image-enable').addEventListener('change',()=>{imageOverlay.enabled=$('live-image-enable').checked&&!!imageOverlay.id;});
  $('live-image-position').addEventListener('change',()=>{imageOverlay.position=$('live-image-position').value;});
  $('live-image-scale').addEventListener('input',()=>{imageOverlay.scale=Number($('live-image-scale').value);});
  $('live-image-upload').addEventListener('change',async()=>{
    const file=$('live-image-upload').files?.[0];if(!file)return;
    if(file.size>4*1024*1024){feedback('Image must be 4 MB or smaller.',true);return;}
    const form=new FormData();form.set('file',file);
    try{const result=await liveRequest('uploadOverlay',form);await loadOverlays();refreshOverlaySelect(result.id);selectOverlayImage(result.id);
      imageOverlay.enabled=true;$('live-image-enable').checked=true;feedback('Image overlay uploaded privately. It will appear on Program when loaded.');}
    catch(e){feedback('Overlay upload: '+statusText(e),true);}finally{$('live-image-upload').value='';}
  });
  $('live-image-remove').addEventListener('click',async()=>{
    if(!imageOverlay.id)return;
    if(!confirm('Delete this image overlay from your Director account?'))return;
    try{await liveRequest('deleteOverlay',{id:imageOverlay.id});selectOverlayImage('');await loadOverlays();feedback('Image overlay removed.');}
    catch(e){feedback('Could not delete overlay: '+statusText(e),true);}
  });
  let audienceBusy=false;
  function paintAudience(data){
    $('live-chat-enabled').checked=!!data.chatEnabled;
    $('live-audience-reactions').textContent=`♥ ${data.reactions?.heart||0} · 👏 ${data.reactions?.applause||0} · 🔥 ${data.reactions?.fire||0}`;
    const list=$('live-audience-messages');list.replaceChildren();
    for(const message of data.messages||[]){
      const row=document.createElement('div');row.className='azh-audience-message';
      const body=document.createElement('div');body.className='azh-fan-chat-entry';
      const author=document.createElement('strong');author.textContent=message.name;
      const text=document.createElement('span');text.textContent=message.text;body.append(author,text);
      const remove=button('Remove',async()=>{if(!room)return;
        try{paintAudience(await liveRequest('removeChat',{roomId:room.id,id:message.id}));}catch(e){feedback('Moderation: '+statusText(e),true);}},'azh-live-button azh-live-secondary');
      row.append(body,remove);list.append(row);
    }
    $('live-audience-status').textContent=data.chatEnabled?'Chat enabled for signed-in members.':'Chat closed; members can still react.';
  }
  async function refreshAudience(){
    if(!room||audienceBusy||$('live-panel-audience').hidden)return;audienceBusy=true;
    const id=room.id;
    try{const result=await liveRequest('directorEngagement',null,{roomId:id});if(room?.id===id)paintAudience(result);}
    catch(e){$('live-audience-status').textContent='Audience refresh failed: '+statusText(e);}
    finally{audienceBusy=false;}
  }
  $('live-chat-enabled').addEventListener('change',async()=>{
    if(!room){$('live-chat-enabled').checked=false;feedback('Go live before enabling member chat.',true);return;}
    const checked=$('live-chat-enabled').checked;$('live-chat-enabled').disabled=true;
    try{paintAudience(await liveRequest('enableChat',{roomId:room.id,enabled:checked}));}
    catch(e){$('live-chat-enabled').checked=!checked;feedback('Chat setting failed: '+statusText(e),true);}
    finally{$('live-chat-enabled').disabled=false;}
  });
  function sceneSettings(){
    return {transition:$('live-transition').value,switchMode:$('live-switch-mode').value,
      brand:overlay.brand,source:overlay.source,custom:overlay.custom,text:overlay.text,position:overlay.position,
      imageId:imageOverlay.id,imageEnabled:imageOverlay.enabled,imagePosition:imageOverlay.position,imageScale:imageOverlay.scale,
      cameraId:previewSelected||selected||'',cameraLabel:sources.get(previewSelected||selected)?.label||'',
      layoutType:draftLayout.type,layoutSources:draftLayout.slots,layoutLabels:draftLayout.slots.map(id=>sources.get(id)?.label||'')};
  }
  $('live-save-scene').addEventListener('click',async()=>{
    const name=$('live-scene-name').value.trim();if(!name){feedback('Enter a scene name first.',true);return;}
    const button=$('live-save-scene');button.disabled=true;
    try {const result=await liveRequest('saveScene',{id:$('live-scene-select').value||'',name,settings:sceneSettings()});
      savedScenes=result.scenes||[];refreshSceneSelect(result.savedId);feedback('Scene saved. Recall it without taking its camera to Program automatically.');}
    catch(e){feedback('Could not save scene: '+statusText(e),true);}finally{button.disabled=false;}
  });
  $('live-recall-scene').addEventListener('click',()=>{
    const item=savedScenes.find(v=>v.id===$('live-scene-select').value);if(!item)return;
    const settings=item.settings;
    $('live-scene-name').value=item.name;
    // Preview must not mutate ANY Program overlay, audio, or transition.
    // Apply the staged settings only after an explicit Take composition.
    pendingSceneSettings=structuredClone(settings);
    if(settings.layoutType){
      const ids=(settings.layoutSources||[]).map((id,i)=>sourceIds().includes(id)?id:([...sources.values()].find(x=>x.kind==='phone'&&x.health?.playable()&&x.label===(settings.layoutLabels||[])[i])?.id||''));
      setDraftLayout(normalizeLayout(settings.layoutType,ids));
    }
    if(!settings.layoutType){
      const legacy=sourceIds().includes(settings.cameraId)?settings.cameraId:
        ([...sources.values()].find(x=>x.health?.playable()&&x.label===settings.cameraLabel)?.id||'');
      if(legacy)setDraftLayout(normalizeLayout('single',[legacy]));
    }
    if(settings.cameraId&&sources.has(settings.cameraId))previewSource(settings.cameraId);
    else if(settings.cameraLabel){const candidate=[...sources.values()].find(src=>src.label===settings.cameraLabel);if(candidate)previewSource(candidate.id);}
    paintLayoutPreview();feedback('Scene recalled. Camera is in private Preview; press Take composition to apply its layout and overlays to viewers.');
  });
  $('live-delete-scene').addEventListener('click',async()=>{
    const id=$('live-scene-select').value;if(!id)return;
    if(!confirm('Delete this saved scene?'))return;
    try{const result=await liveRequest('deleteScene',{id});savedScenes=result.scenes||[];refreshSceneSelect();feedback('Saved scene deleted.');}
    catch(e){feedback('Could not delete scene: '+statusText(e),true);}
  });
  void loadScenes();void loadOverlays();
  // Pre-recorded videos are read ONLY from the Director's own private gallery.
  // The file endpoint enforces account ownership and byte-range responses.
  async function loadMediaLibrary(){
    try{
      const result=await mediaRequest('list');mediaLibrary=(result.items||[]).filter(x=>/^video\//.test(x.mimeType||'')&&/^[a-f0-9]{32}$/.test(x.id));
      const picker=$('live-media-select'),value=picker.value;picker.replaceChildren(new Option('Choose a private video',''));
      for(const item of mediaLibrary)picker.add(new Option(item.title+' · '+(item.fileName||'Video'),item.id));
      if(mediaLibrary.some(v=>v.id===value))picker.value=value;
      $('live-media-status').textContent=`${mediaLibrary.length} private videos available. Cue a video before you take it to Program.`;
    }catch(e){$('live-media-status').textContent='Media library unavailable: '+statusText(e);}
  }
  $('live-media-refresh').addEventListener('click',()=>void loadMediaLibrary());
  function mediaItem(id){return mediaLibrary.find(item=>item.id===id)||null;}
  async function cueMedia(id){
    const item=mediaItem(id);if(!item)throw Error('Choose a video from your own media library.');
    // Cueing is PRIVATE. Never pause, seek, or replace the on-air media element
    // until the operator explicitly presses Take video to Program.
    const video=$('live-media-preview');video.pause();video.src=item.videoUrl;video.load();
    mediaCueId=id;
    $('live-media-select').value=id;$('live-media-status').textContent='Cued privately: '+item.title+'. Press Take to Program to broadcast.';
    return item;
  }
  $('live-media-cue').addEventListener('click',async()=>{try{await cueMedia($('live-media-select').value);}catch(e){feedback('Media cue: '+statusText(e),true);}});
  async function waitForVideo(video){
    if(video.readyState>=2&&video.videoWidth)return;
    await new Promise((resolve,reject)=>{
      const timeout=setTimeout(()=>{cleanup();reject(Error('Video could not be decoded. Try another video format.'));},10000);
      const onReady=()=>{cleanup();resolve();},onFail=()=>{cleanup();reject(Error('Video format or source could not be played.'));};
      const cleanup=()=>{clearTimeout(timeout);video.removeEventListener('loadeddata',onReady);video.removeEventListener('error',onFail);};
      video.addEventListener('loadeddata',onReady,{once:true});video.addEventListener('error',onFail,{once:true});
    });
  }
  async function ensureMediaAudio(video){
    await ensureAudio();
    if(!audioCtx||!audioDestination)throw Error('This browser cannot mix video sound into Program. Try Chrome or Edge.');
    let entry=mediaAudioNodes.get(video);
    if(!entry){
      const node=audioCtx.createMediaElementSource(video),gain=audioCtx.createGain();
      gain.gain.value=0;node.connect(gain);gain.connect(audioDestination);
      entry={node,gain};mediaAudioNodes.set(video,entry);
    }
    return entry;
  }
  function stopMediaPlayback(restore=true){
    if(!mediaProgramActive)return;
    mediaProgramActive=false;programMediaId='';programVideo?.pause();
    if(mediaGain&&audioCtx)mediaGain.gain.setTargetAtTime(0,audioCtx.currentTime,.02);
    if(restore&&beforeMediaLayout){activeLayout=beforeMediaLayout.layout;selected=beforeMediaLayout.selected;
      if(activeLayout.type==='single'&&!sourceIds().includes(selected)){selected=null;activeLayout=normalizeLayout('single',['']);}
    }
    beforeMediaLayout=null;syncCameraAudio();showSources();scheduleSwitch();routeViewerVideo();
    $('live-media-status').textContent='Video playback ended. Program returned to '+(selected?'camera':'holding slate')+'.';
  }
  async function takeMedia(id){
    const epoch=++programTakeEpoch;
    const item=mediaItem(id);if(!item)throw Error('The private video is unavailable. Refresh your library.');
    if(mediaCueId!==id)await cueMedia(id);
    const candidate=programMediaElement();candidate.src=item.videoUrl;candidate.muted=false;
    try{
      const sound=await ensureMediaAudio(candidate);await audioCtx.resume();
      candidate.load();
      // Do not touch the old Program or its sound until the candidate can
      // actually play and produce decodable frames.
      await candidate.play();await waitForVideo(candidate);
      if(epoch!==programTakeEpoch){candidate.pause();candidate.removeAttribute('src');candidate.load();return false;}
      const wasMedia=mediaProgramActive;
      if(!wasMedia)beforeMediaLayout={layout:normalizeLayout(activeLayout.type,activeLayout.slots),selected};
      const oldVideo=programVideo,oldGain=mediaGain;
      transitionToProgram($('live-transition').value);
      programVideo=candidate;mediaGain=sound.gain;mediaProgramActive=true;programMediaId=id;switchDeadline=0;routeViewerVideo();
      pendingSceneSettings=null;
      sound.gain.gain.setTargetAtTime(Number($('live-media-volume').value)/100,audioCtx.currentTime,.02);
      if(wasMedia){oldVideo?.pause();if(oldGain&&audioCtx){oldGain.gain.setTargetAtTime(0,audioCtx.currentTime,.02);oldGain.disconnect();}}
      updateAudioGains();$('live-media-status').textContent='● ON PROGRAM · '+item.title+' — sound is mixed into the recorded Program.';
      feedback('Prerecorded media taken to Program: '+item.title);return true;
    }catch(e){
      candidate.pause();candidate.removeAttribute('src');candidate.load();
      // The original on-air Program stays untouched on a rejected Take.
      throw Error('Video playback failed: '+statusText(e));
    }
  }
  $('live-media-take').addEventListener('click',async()=>{const btn=$('live-media-take');if(btn.disabled)return;btn.disabled=true;try{await takeMedia($('live-media-select').value);}catch(e){feedback('Cannot take video: '+statusText(e),true);}finally{btn.disabled=false;}});
  $('live-media-stop').addEventListener('click',()=>{programTakeEpoch++;stopMediaPlayback(true);});
  $('live-media-volume').addEventListener('input',()=>{$('live-media-volume-label').textContent=$('live-media-volume').value+'%';
    if(mediaGain&&audioCtx)mediaGain.gain.setTargetAtTime(mediaProgramActive?Number($('live-media-volume').value)/100:0,audioCtx.currentTime,.02);});
  void loadMediaLibrary();

  // A saved show rundown is an ordered plan, not permission to broadcast.
  // Preview is always local; Take next is the only publishing operation.
  function currentSegments(){return rundowns.find(r=>r.id===rundownId)||null;}
  function showRundownList(){
    const list=$('live-rundown-list');list.replaceChildren();
    for(let i=0;i<rundownSegments.length;i++){
      const seg=rundownSegments[i],li=document.createElement('li');li.classList.toggle('is-current',i===rundownTaken);li.classList.toggle('is-selected',i===rundownCursor);
      const main=button('',()=>{rundownCursor=i;rundownManualSelection=true;showRundownList();},'azh-segment-main');
      const title=document.createElement('strong');title.textContent=`${i+1}. ${seg.title}`;
      const detail=document.createElement('small');detail.textContent=seg.kind==='camera'?`Cameras · ${seg.layout}`:seg.kind==='media'?'Prerecorded video':'Holding slate';main.append(title,detail);
      const up=button('↑',()=>{if(i){[rundownSegments[i-1],rundownSegments[i]]=[rundownSegments[i],rundownSegments[i-1]];
        if(rundownCursor===i)rundownCursor=i-1;else if(rundownCursor===i-1)rundownCursor=i;
        rundownTaken=-1;rundownDirty=true;showRundownList();}},'azh-mini-action');up.disabled=i===0;
      const down=button('↓',()=>{if(i<rundownSegments.length-1){[rundownSegments[i+1],rundownSegments[i]]=[rundownSegments[i],rundownSegments[i+1]];
        if(rundownCursor===i)rundownCursor=i+1;else if(rundownCursor===i+1)rundownCursor=i;
        rundownTaken=-1;rundownDirty=true;showRundownList();}},'azh-mini-action');down.disabled=i===rundownSegments.length-1;
      const remove=button('✕',()=>{rundownSegments.splice(i,1);rundownCursor=Math.min(rundownCursor,rundownSegments.length-1);rundownTaken=-1;rundownDirty=true;showRundownList();},'azh-mini-action');remove.setAttribute('aria-label','Remove '+seg.title);
      li.append(main,up,down,remove);list.append(li);
    }
    if(!rundownSegments.length){const empty=document.createElement('li');empty.textContent='No segments. Add a camera, media, or holding segment.';list.append(empty);}
    $('live-rundown-next').disabled=!rundownSegments.length;
    $('live-rundown-preview').disabled=!rundownSegments.length;
  }
  function selectRundown(item){
    rundownId=item?.id||'';rundownRevision=item?.revision||0;rundownSegments=structuredClone(item?.segments||[]);
    rundownCursor=rundownSegments.length?0:-1;rundownTaken=-1;rundownManualSelection=false;rundownDirty=false;
    $('live-rundown-name').value=item?.name||'';showRundownList();
  }
  function refreshRundownSelect(){const list=$('live-rundown-select');list.replaceChildren(new Option('New rundown',''));
    for(const r of rundowns)list.add(new Option(r.name,r.id));list.value=rundownId||'';}
  async function loadRundowns(){try{const r=await liveRequest('rundowns');rundowns=r.rundowns||[];refreshRundownSelect();}
    catch(e){$('live-rundown-feedback').textContent='Rundowns unavailable: '+statusText(e);}}
  $('live-rundown-name').addEventListener('input',()=>{rundownDirty=true;});
  $('live-rundown-select').addEventListener('change',()=>{
    if(rundownDirty&&!confirm('Discard unsaved rundown changes?')){refreshRundownSelect();return;}
    selectRundown(rundowns.find(r=>r.id===$('live-rundown-select').value));
  });
  $('live-rundown-new').addEventListener('click',()=>{if(rundownDirty&&!confirm('Discard unsaved rundown changes?'))return;selectRundown(null);refreshRundownSelect();});
  $('live-segment-add').addEventListener('click',()=>{
    if(rundownSegments.length>=30){feedback('A rundown supports up to 30 segments.',true);return;}
    const title=safeShowText($('live-segment-name').value,100),kind=$('live-segment-kind').value;
    if(!title){feedback('Enter a segment title.',true);return;}
    const slots=draftLayout.slots.slice(),mediaId=kind==='media'?(mediaCueId||$('live-media-select').value):'';
    if(kind==='media'&&!mediaItem(mediaId)){feedback('Cue an approved video from the Media playback tab first.',true);return;}
    const scene=savedScenes.find(x=>x.id===$('live-segment-scene').value);
    const settings=scene?structuredClone(scene.settings):sceneSettings();
    const finalLayout=normalizeLayout(settings.layoutType||draftLayout.type,settings.layoutSources||slots);
    const seg={id:randomId(),title,kind,layout:finalLayout.type,slots:finalLayout.slots,
      slotLabels:scene?settings.layoutLabels:slots.map(x=>sources.get(x)?.label||''),
      mediaId,sceneId:scene?.id||'',transition:settings.transition||$('live-transition').value,
      overlayText:settings.custom?settings.text:'',settings};
    rundownSegments.push(seg);rundownCursor=rundownSegments.length-1;rundownManualSelection=true;rundownDirty=true;showRundownList();
    $('live-segment-name').value='';$('live-rundown-feedback').textContent='Added '+title+'. Save the rundown to keep changes.';
  });
  $('live-rundown-save').addEventListener('click',async()=>{
    if(!rundownSegments.length){feedback('Add a segment before saving.',true);return;}
    const name=safeShowText($('live-rundown-name').value,100);if(!name){feedback('Enter a rundown name.',true);return;}
    try{const result=await liveRequest('saveRundown',{id:rundownId,revision:rundownRevision,name,segments:rundownSegments});
      rundowns=result.rundowns||[];selectRundown(rundowns.find(r=>r.id===result.savedId));refreshRundownSelect();
      $('live-rundown-feedback').textContent='Saved show rundown. Each Take remains an explicit Director action.';
    }catch(e){$('live-rundown-feedback').textContent='Could not save: '+statusText(e);}
  });
  $('live-rundown-delete').addEventListener('click',async()=>{
    if(!rundownId||!confirm('Delete this saved rundown?'))return;
    try{const result=await liveRequest('deleteRundown',{id:rundownId,revision:rundownRevision});rundowns=result.rundowns||[];
      selectRundown(null);refreshRundownSelect();$('live-rundown-feedback').textContent='Rundown deleted; saved recordings remain unchanged.';
    }catch(e){$('live-rundown-feedback').textContent='Delete failed: '+statusText(e);}
  });
  async function previewSegment(i){
    const seg=rundownSegments[i],info=rundownPreview(seg);if(!info)return;
    rundownCursor=i;rundownManualSelection=true;showRundownList();
    if(info.kind==='camera'){
      const slots=info.layout.slots.map((id,index)=>sourceIds().includes(id)?id:
        ([...sources.values()].find(x=>x.kind==='phone'&&x.health?.playable()&&x.label===seg.slotLabels[index])?.id||''));
      setDraftLayout(normalizeLayout(info.layout.type,slots));
      setDirectorTab('compositions');paintLayoutPreview();
    }else if(info.kind==='media'){
      await cueMedia(info.mediaId);setDirectorTab('media');
    }else{setDirectorTab('rundown');}
    $('live-rundown-feedback').textContent='Private preview of '+seg.title+'. Use Take next segment to put it on Program.';
  }
  $('live-rundown-prev').addEventListener('click',()=>{if(!rundownSegments.length)return;
    const i=chooseRundownSegment(rundownSegments,rundownCursor,-1);void previewSegment(i).catch(e=>feedback(statusText(e),true));});
  $('live-rundown-preview').addEventListener('click',()=>{if(rundownCursor>=0)void previewSegment(rundownCursor).catch(e=>feedback(statusText(e),true));});
  function applySegmentSettings(settings){
    if(!settings)return;
    for(const [key,id] of [['brand','live-brand-overlay'],['source','live-camera-overlay'],['custom','live-custom-overlay']]){
      overlay[key]=!!settings[key];$(id).checked=overlay[key];
    }
    overlay.text=safeOverlayText(settings.text||'');$('live-overlay-text').value=overlay.text;
    overlay.position=settings.position==='upper'?'upper':'lower';$('live-overlay-position').value=overlay.position;
    imageOverlay.position=settings.imagePosition||'top-right';$('live-image-position').value=imageOverlay.position;
    imageOverlay.scale=Number(settings.imageScale)||24;$('live-image-scale').value=String(imageOverlay.scale);
    refreshOverlaySelect(settings.imageId||'');selectOverlayImage($('live-image-select').value);
    imageOverlay.enabled=!!settings.imageEnabled&&!!imageOverlay.id;$('live-image-enable').checked=imageOverlay.enabled;
    if(settings.switchMode&&['manual','fixed','random'].includes(settings.switchMode))$('live-switch-mode').value=settings.switchMode;
  }
  $('live-rundown-next').addEventListener('click',async()=>{
    if(rundownTakeBusy)return;
    if(!rundownManualSelection&&rundownTaken===rundownSegments.length-1){
      $('live-rundown-feedback').textContent='End of rundown. Preview a segment to replay it or take the holding slate.';return;
    }
    const i=rundownManualSelection?rundownCursor:chooseRundownSegment(rundownSegments,rundownTaken,1);
    if(i<0||i>=rundownSegments.length)return;
    const seg=rundownSegments[i];rundownTakeBusy=true;$('live-rundown-next').disabled=true;
    const priorTransition=$('live-transition').value;
    try{
      $('live-transition').value=seg.transition||'cut';
      if(seg.kind==='slate')takeSlate();
      else if(seg.kind==='media'){
        if(!(await takeMedia(seg.mediaId)))throw Error('Newer Program selection took priority; video was not broadcast.');
      }else{
        await previewSegment(i);
        if(!takeComposition(draftLayout))throw Error('A required live camera is unavailable; preview or substitute a healthy source.');
      }
      // A failed Take MUST NOT change on-air titles or image overlays.
      // Set the rundown's saved visuals only after the new Program is active.
      if(seg.settings)applySegmentSettings(seg.settings);
      else if(seg.overlayText){overlay.text=safeOverlayText(seg.overlayText);overlay.custom=true;
        $('live-custom-overlay').checked=true;$('live-overlay-text').value=overlay.text;}
      pendingSceneSettings=null;scheduleSwitch();
      rundownTaken=i;rundownCursor=i;rundownManualSelection=false;showRundownList();setDirectorTab('rundown');
      $('live-rundown-feedback').textContent='● ON PROGRAM · '+seg.title;
    }catch(e){$('live-transition').value=priorTransition;$('live-rundown-feedback').textContent='Cannot take segment: '+statusText(e);feedback(statusText(e),true);}
    finally{rundownTakeBusy=false;$('live-rundown-next').disabled=!rundownSegments.length;}
  });
  showRundownList();void loadRundowns();

  async function enumerate(){
    if(!navigator.mediaDevices?.enumerateDevices)return;
    const list=await navigator.mediaDevices.enumerateDevices();
    const mics=list.filter(d=>d.kind==='audioinput'),select=$('live-mic-list'),prior=select.value;
    select.replaceChildren(new Option('Off · video only',''));
    mics.forEach((m,i)=>select.add(new Option(m.label||`Microphone ${i+1}`,m.deviceId)));
    if(mics.some(m=>m.deviceId===prior))select.value=prior;
  }
  void enumerate().catch(()=>{});
  navigator.mediaDevices?.addEventListener?.('devicechange',()=>void enumerate().catch(()=>{}));
  const cameraWatch=setInterval(()=>{for(const item of sources.values()){item.health?.update();if(item.kind==='phone')phoneRepair.healthy(item.id.slice(6),Boolean(item.health?.playable()));}if(sources.size)showSources();},1600);
  async function ensureAudio() {
    if(audioDestination){if(audioCtx?.state==='suspended')await audioCtx.resume();return;}
    const Ctor=globalThis.AudioContext||globalThis.webkitAudioContext;
    if(!Ctor)return;
    audioCtx=new Ctor();audioDestination=audioCtx.createMediaStreamDestination();
    oscillator=audioCtx.createOscillator();const silent=audioCtx.createGain();silent.gain.value=0;oscillator.connect(silent).connect(audioDestination);oscillator.start();
    await audioCtx.resume();syncCameraAudio();
  }
  /** Mixer status is only a local capability check. It never claims remote
   * audio can actually be heard; that requires the physical fan test.
   */
  function reportMixerReadiness(){
    const label=$('live-audio-health'),resume=$('live-audio-resume');
    if(!label||!resume)return;
    const mic=Boolean(micStream?.getAudioTracks().some(t=>t.readyState==='live'&&!t.muted))&&!$('live-mic-mute').checked&&Number($('live-mic-volume').value)>0;
    const camera=Boolean(sources.get(selected)?.stream?.getAudioTracks?.().some(t=>t.readyState==='live'&&!t.muted))
      &&$('live-camera-audio').checked&&Number($('live-camera-volume').value)>0&&!mediaProgramActive;
    const state=mixerReadiness({programTrack:Boolean(programStream?.getAudioTracks().some(t=>t.readyState==='live')),
      contextState:audioCtx?.state||'unavailable',hostMic:mic,cameraMic:camera});
    label.textContent=state.message;label.dataset.state=state.state;
    resume.hidden=Boolean(audioCtx&&audioCtx.state==='running');
  }
  $('live-audio-resume')?.addEventListener('click',async()=>{
    try{
      await ensureAudio();
      if(!audioCtx||!audioDestination)throw Error('This browser does not support a Program audio mixer.');
      await audioCtx.resume();
      reportMixerReadiness();
    }catch(e){feedback('Program mixer could not resume: '+statusText(e),true);reportMixerReadiness();}
  });
  function updateAudioGains(){
    if(micGain){const volume=$('live-mic-mute').checked?0:Number($('live-mic-volume').value)/100;micGain.gain.setTargetAtTime(volume,audioCtx.currentTime,.035);}
    const cameraVolume=$('live-camera-audio').checked?Number($('live-camera-volume').value)/100:0;
    for(const [id,node] of cameraAudioNodes)node.gain.gain.setTargetAtTime(selected===id&&!mediaProgramActive&&sources.get(id)?.health?.playable()?cameraVolume:0,audioCtx.currentTime,.035);
    $('live-mic-volume-label').textContent=$('live-mic-volume').value+'%';
    $('live-camera-volume-label').textContent=$('live-camera-volume').value+'%';
  }
  function detachCameraAudio(id){
    const existing=cameraAudioNodes.get(id);if(!existing)return;
    existing.source.disconnect();existing.analyser.disconnect();existing.gain.disconnect();cameraAudioNodes.delete(id);
  }
  function syncCameraAudio(){
    if(!audioCtx||!audioDestination)return;
    for(const [id,item] of sources){
      const tracks=item.stream.getAudioTracks?.().filter(track=>track.readyState==='live')||[];
      const existing=cameraAudioNodes.get(id);
      if(existing&&tracks[0]?.id===existing.trackId)continue;
      if(existing)detachCameraAudio(id);
      if(!tracks.length)continue;
      try{
        const source=audioCtx.createMediaStreamSource(new MediaStream([tracks[0]]));
        const analyser=audioCtx.createAnalyser();analyser.fftSize=512;
        const gain=audioCtx.createGain();gain.gain.value=0;
        source.connect(analyser);analyser.connect(gain);gain.connect(audioDestination);
        cameraAudioNodes.set(id,{source,analyser,gain,trackId:tracks[0].id});
      }catch(e){feedback('Camera audio unavailable: '+statusText(e),true);}
    }
    for(const id of cameraAudioNodes.keys())if(!sources.has(id))detachCameraAudio(id);
    updateAudioGains();reportMixerReadiness();
  }
  function rms(analyser){
    if(!analyser)return 0;const a=new Uint8Array(analyser.fftSize);analyser.getByteTimeDomainData(a);
    let power=0;for(const x of a){const y=(x-128)/128;power+=y*y;}
    return Math.min(1,Math.sqrt(power/a.length)*3);
  }
  const audioReadinessTimer=setInterval(reportMixerReadiness,2500);
  const audioMeterLoop=setInterval(()=>{
    $('live-mic-meter').value=rms(micAnalyser);
    $('live-camera-meter').value=$('live-camera-audio').checked?rms(cameraAudioNodes.get(selected)?.analyser):0;
  },180);
  for(const id of ['live-mic-volume','live-mic-mute','live-camera-volume','live-camera-audio'])$(id).addEventListener('input',updateAudioGains);
  async function applyMicrophone(){
    const id=$('live-mic-list').value;
    try {
      if(id){
        const next=await navigator.mediaDevices.getUserMedia({audio:{deviceId:{exact:id}},video:false});
        await ensureAudio();if(!audioDestination){next.getTracks().forEach(t=>t.stop());throw Error('This browser does not support program audio.');}
        micNode?.disconnect();micGain?.disconnect();micAnalyser?.disconnect();micStream?.getTracks().forEach(t=>t.stop());
        micStream=next;micNode=audioCtx.createMediaStreamSource(next);micGain=audioCtx.createGain();micAnalyser=audioCtx.createAnalyser();micAnalyser.fftSize=512;
        micNode.connect(micAnalyser);micAnalyser.connect(micGain);micGain.connect(audioDestination);updateAudioGains();
        feedback('Program microphone enabled.');
      } else {micNode?.disconnect();micGain?.disconnect();micAnalyser?.disconnect();micNode=null;micGain=null;micAnalyser=null;micStream?.getTracks().forEach(t=>t.stop());micStream=null;feedback('Program microphone off.');}
      await enumerate();reportMixerReadiness();
    } catch(e){feedback('Could not set the microphone: '+statusText(e),true);reportMixerReadiness();}
  }
  $('live-apply-mic').addEventListener('click',()=>void applyMicrophone());
  async function ensureProgram(){
    if(programStream)return programStream;
    if(!stage.captureStream)throw Error('This browser cannot capture the program canvas. Use a browser with canvas.captureStream().');
    canvasStream=stage.captureStream(24);
    await ensureAudio();
    programStream=new MediaStream([...canvasStream.getVideoTracks(),...(audioDestination?.stream.getAudioTracks()||[])]);
    if(!programStream.getVideoTracks().length)throw Error('No program video track could be created.');
    reportMixerReadiness();return programStream;
  }
  async function liveStart(){
    if(room)return;
    try {
      if(!window.isSecureContext)throw Error('Use the HTTPS AZHottie site for live video.');
      if(!window.RTCPeerConnection)throw Error('This browser does not support WebRTC live streaming.');
      await ensureProgram();
      const started=await liveRequest('start',{title:$('live-title').value.trim()});room={...started.room,iceServers:started.iceServers};
      cameraFaultActive=false;$('live-go').disabled=true;$('live-end').disabled=false;updateRecordControls();reportMixerReadiness();$('live-onair-pill').textContent='● ON AIR';$('live-onair-pill').classList.add('is-live');
      feedback('On air. Keep this Director tab open so members can watch.');setTimeout(()=>void reportLiveHealth(),1200);scheduleSwitch();void pollViewers();void refreshAudience();
    } catch(e){feedback(statusText(e),true);}
  }
  function closeViewer(id){const v=viewers.get(id);if(!v)return;viewers.delete(id);clearTimeout(v.timeout);clearTimeout(v.lossTimer);v.router?.close();v.peer.onconnectionstatechange=null;v.peer.close();}
  async function acceptViewer(offer,version){
    if(!room||room.id!==version||viewers.has(offer.id))return;
    if(!globalThis.RTCPeerConnection||!programStream)return;
    const peer=new RTCPeerConnection({iceServers:iceServers()});
    const item={peer,nonce:offer.nonce,timeout:null,lossTimer:null,router:null,quality:null,forceDirect:false};viewers.set(offer.id,item);
    item.timeout=setTimeout(()=>{if(viewers.get(offer.id)===item)closeViewer(offer.id);},30000);
    peer.onconnectionstatechange=()=>{
      if(viewers.get(offer.id)!==item)return;
      if(peer.connectionState==='connected'){clearTimeout(item.timeout);clearTimeout(item.lossTimer);}
      else if(peer.connectionState==='disconnected'){clearTimeout(item.lossTimer);item.lossTimer=setTimeout(()=>{if(viewers.get(offer.id)===item&&peer.connectionState==='disconnected')closeViewer(offer.id);},5000);}
      else if(['failed','closed'].includes(peer.connectionState))closeViewer(offer.id);
    };
    try {
      await peer.setRemoteDescription({type:'offer',sdp:offer.offer});
      for(const track of programStream.getTracks()){
        const sender=peer.addTrack(track,programStream);
        if(track.kind==='video')item.router=new ViewerVideoRoute(sender,track,e=>feedback('Could not switch a viewer to reliable video: '+statusText(e),true));
      }
      await peer.setLocalDescription(await peer.createAnswer());await waitForIce(peer);
      if(!room||room.id!==version||viewers.get(offer.id)!==item)return;
      await liveRequest('answer',{roomId:version,viewerId:offer.id,nonce:offer.nonce,sdp:peer.localDescription?.sdp||''});
      if(viewers.get(offer.id)===item)routeViewerVideo();
    }catch(e){closeViewer(offer.id);feedback('A viewer could not connect: '+statusText(e),true);}
  }
  async function pollViewers(){
    if(!room||viewerPollBusy)return;viewerPollBusy=true;const version=room.id;
    try {
      const state=await liveRequest('heartbeat',{roomId:version});
      if(room?.id!==version)return;
      $('live-view-count').textContent=`${state.viewers} viewer${state.viewers===1?'':'s'}`;
      const {offers}=await liveRequest('offers',null,{roomId:version});
      if(room?.id!==version)return;
      const available=new Map(offers.map(o=>[o.id,o]));
      for(const [id,item] of viewers)if(!available.has(id)||available.get(id).nonce!==item.nonce)closeViewer(id);
      for(const offer of offers)if(!viewers.has(offer.id)&&offer.status==='waiting')void acceptViewer(offer,version);
      void refreshAudience();
    }catch(e){if(room?.id===version){if(e.status===409){liveEnd();feedback('Broadcast connection expired. The program is off air; start a new session to resume.',true);}else feedback('Broadcast signaling: '+statusText(e),true);}}
    finally{viewerPollBusy=false;}
  }
  let deliveryBusy=false;
  async function monitorOutgoingVideo(){
    if(deliveryBusy||!room)return;deliveryBusy=true;
    try{
      let liveCount=0,reported=0,totalFps=0,stalls=0,relayed=0;
      for(const [id,item] of viewers){
        if(item.peer.connectionState!=='connected'||!item.router?.sender?.getStats)continue;
        liveCount++;
        try{
          const report=await item.router.sender.getStats();
          if(!viewers.has(id)||viewers.get(id)!==item)continue;
          const outbound=[...report.values()].find(stat=>stat.type==='outbound-rtp'&&(stat.kind==='video'||stat.mediaType==='video')&&!stat.isRemote);
          if(!outbound)continue;
          const frameCount=Number(outbound.framesSent??outbound.framesEncoded);
          if(!Number.isFinite(frameCount))continue;
          item.quality=outgoingFrameSample(item.quality,frameCount,performance.now());reported++;
          totalFps+=item.quality.fps||0;
          if(item.quality.state!=='stalled')continue;
          stalls++;
          const candidate=liveVideoTrack({hidden:true,mediaProgramActive,activeLayout,selected,sources,canvasTrack:programStream?.getVideoTracks()[0]});
          if(!item.forceDirect&&candidate&&candidate!==programStream?.getVideoTracks()[0]){
            item.forceDirect=true;item.quality=null;routeViewerVideo();
            feedback('Program output stopped sending frames to a viewer. Switched that viewer to the selected phone directly. Canvas overlays are temporarily bypassed for that viewer.',true);
          }
        }catch{/* stats are optional; never terminate a live connection for diagnostics */}
        if(item.forceDirect)relayed++;
      }
      latestDelivery={viewerCount:liveCount,sentFps:reported?Math.round(totalFps/reported*100)/100:null,stalledOutbound:stalls,directRelays:relayed};
      const label=$('live-delivery-state');
      if(!label)return;
      if(!liveCount)label.textContent='No connected fan viewers';
      else if(!reported)label.textContent='Viewer stream connected · checking frames';
      else if(stalls)label.textContent='Program delivery stalled · '+stalls+' viewer(s)';
      else label.textContent=`Program sent ${Math.round(totalFps/reported)} fps · ${reported} viewer${reported===1?'':'s'}`+(relayed?' · direct relay':'');
    }finally{deliveryBusy=false;}
  }
  const deliveryTimer=setInterval(()=>void monitorOutgoingVideo(),2500);
  $('live-agent-recheck').addEventListener('click',()=>void reportLiveHealth());
  $('live-agent-direct').addEventListener('click',()=>{
    if(!room||mediaProgramActive||activeLayout.type!=='single'||!selected||!sources.get(selected)?.health?.playable()){
      feedback('A healthy, selected single phone is required before direct relay is available.',true);return;
    }
    if(![...viewers.values()].some(v=>v.peer.connectionState==='connected')){feedback('No connected viewers require a relay.');return;}
    if(!window.confirm('Send the selected phone directly to connected fans? Program overlays and transitions will be bypassed for them until you restore Program.'))return;
    for(const v of viewers.values())if(v.peer.connectionState==='connected'){v.forceDirect=true;v.quality=null;}
    routeViewerVideo();feedback('Direct camera relay approved. Verify fan playback; the studio recording remains the composited Program.');
    void reportLiveHealth();
  });
  $('live-agent-restore').addEventListener('click',()=>{
    if(!room)return;
    if(!window.confirm('Return all fan viewers to the full composited Program feed? Verify their playback after switching.'))return;
    for(const v of viewers.values()){v.forceDirect=false;v.quality=null;}
    routeViewerVideo();feedback('Full Program delivery requested. Verify it is moving in a fan browser.');
    void reportLiveHealth();
  });
  const liveHealthTimer=setInterval(()=>void reportLiveHealth(),12000);
  function liveEnd(quiet=false){
    const old=room;room=null;switchDeadline=0;cameraFaultActive=false;
    $('live-chat-enabled').checked=false;$('live-audience-messages').replaceChildren();
    $('live-go').disabled=false;$('live-end').disabled=true;$('live-record').disabled=true;$('live-onair-pill').textContent='OFF AIR';$('live-onair-pill').classList.remove('is-live');
    for(const id of [...viewers.keys()])closeViewer(id);
    latestDelivery={viewerCount:0,sentFps:null,stalledOutbound:0,directRelays:0};
    $('live-agent-health-summary').textContent='Off air · live health monitoring paused';
    $('live-delivery-state').textContent='Off air';
    if(old)void liveRequest('stop',{roomId:old.id},{},{keepalive:quiet}).catch(e=>{if(!quiet)feedback('Could not confirm broadcast ended: '+e.message,true);});
    if(recorder?.state==='recording')finishRecording();
    updateRecordControls();
    if(!quiet)feedback('Broadcast ended. Finalize and verify any active recording before leaving this page.');
  }
  $('live-go').addEventListener('click',()=>void liveStart());$('live-end').addEventListener('click',()=>liveEnd());
  async function refreshPhones(){
    if(phonePollBusy)return;phonePollBusy=true;
    try {
      const devices=await phoneRequest('devices');connectedPhones=devices.devices||[];phoneIceServers=Array.isArray(devices.iceServers)&&devices.iceServers.length?devices.iceServers:iceServers();
      showSources();
      const offers=await phoneRequest('offers');for(const d of connectedPhones)if(!d.sharing&&phoneStates.get(d.id)==='Connecting…')phoneStates.delete(d.id);phones.sync((offers.offers||[]).map(o=>({...o,label:o.name})),phoneIceServers||iceServers());
    }catch(e){feedback('Phone sync: '+statusText(e),true);}
    finally{phonePollBusy=false;}
  }
  const phones=new HostPhoneFeeds({
    request:(action,body)=>phoneRequest(action,body),
    onStream:(id,stream,label)=>{phoneStates.set(id,'Live video connected');addSource('phone:'+id,stream,label||'Approved phone','phone');feedback('Connected phone camera: '+label);},
    onRemove:(id,reason)=>{phoneStates.set(id,reason||'Camera disconnected — the phone will reconnect if Share live remains on');removeSource('phone:'+id);showSources();},
    onView:(id,view)=>{phoneStates.set(id,view.message||'Connecting…');if(view.stream&&sources.has('phone:'+id))syncCameraAudio();showSources();},
  });
  $('live-invite').addEventListener('click',async()=>{
    try {
      const data=await phoneRequest('invite',{});
      const url=new URL('my-camera.php',document.baseURI);url.searchParams.set('invite',data.inviteId);url.searchParams.set('code',data.code);url.searchParams.set('studio','live');
      inviteUrl=url.href;$('live-invite-qr').innerHTML=window.LuckyQR?.svg(inviteUrl)||'';
      $('live-invite-url').value=inviteUrl;$('live-invite-box').hidden=false;
      feedback('Camera invitation generated. Scan and approve the phone before it shares video.');
    }catch(e){feedback(statusText(e),true);}
  });
  $('live-copy-invite').addEventListener('click',async()=>{try{await navigator.clipboard.writeText(inviteUrl);feedback('Invitation copied.');}catch{$('live-invite-url').select();feedback('Copy the selected invitation link.');}});
  function updateRecordControls(){
    const blocked=recordingStartBlockReason({onAir:Boolean(room),starting:recordingStarting,
      finalizing:recordingFinalizing,saving,recorderState:recorder?.state||'inactive',
      backupPending:Boolean(recordedFile)});
    $('live-record').disabled=Boolean(blocked);
    $('live-record-stop').disabled=recorder?.state!=='recording'&&recorder?.state!=='paused';
  }
  function finishRecording(){
    if(!recorder||!['recording','paused'].includes(recorder.state))return;
    recordingFinalizing=true;updateRecordControls();
    try{recorder.stop();}
    catch(e){recordingFinalizing=false;updateRecordControls();reportRecord('Could not finalize recording: '+statusText(e),true);}
  }
  function discardLocalRecording(){
    if(saving||recordingFinalizing||!recordedFile)return;
    if(!window.confirm('Discard this unsaved local recording from the Director tab? Download a backup first if you need to keep it. This cannot be undone.'))return;
    recordedFile=null;recordToken=null;savedMediaId=null;recordRoom=null;
    if(recordUrl){URL.revokeObjectURL(recordUrl);recordUrl=null;}
    $('live-record-backup').replaceChildren();
    reportRecord('Unsaved local recording discarded at your request. You can record another Program clip.');
    updateRecordControls();
  }
  async function saveRecording(file,title,roomId){
    if(saving)return;saving=true;
    updateRecordControls();reportRecord('Saving the complete program recording to your private gallery…');
    try {
      const saved=savedMediaId?{id:savedMediaId}:await uploadToPersonalGallery(mediaRequest,file,title,{token:recordToken,onProgress:(done,total)=>reportRecord(`Saving program recording: ${Math.round(done/total*100)}%`),onRetry:({attempt})=>reportRecord('Retrying upload attempt '+attempt+'…')});
      savedMediaId=saved.id;
      // Verify the server has committed the complete bytes before linking the
      // broadcast or discarding the local download backup.
      const listing=await mediaRequest('list');
      const persisted=(listing.items||[]).find(item=>item.id===saved.id);
      if(!persisted||Number(persisted.sizeBytes)!==file.size)throw Error('Saved file verification failed. Keep your local backup and retry; no recording is published.');
      if(roomId)await liveRequest('attach',{roomId,mediaId:saved.id});
      reportRecord('✓ Recording byte size verified in your private gallery. Open Saved Live Productions and play it to confirm video and audio.');
      $('live-record-backup').replaceChildren();if(recordUrl){URL.revokeObjectURL(recordUrl);recordUrl=null;}
      recordedFile=null;recordToken=null;savedMediaId=null;// Saved shows are managed on the dedicated live-productions.php admin page.
    }catch(e){reportRecord('Upload interrupted. Keep this page open, save a backup, or retry: '+statusText(e),true);
      $('live-record-backup').append(button('Retry save',()=>void saveRecording(file,title,roomId)));
    }finally{saving=false;updateRecordControls();}
  }
  async function startRecord(){
    const reason=recordingStartBlockReason({onAir:Boolean(room),starting:recordingStarting,
      finalizing:recordingFinalizing,saving,recorderState:recorder?.state||'inactive',
      backupPending:Boolean(recordedFile)});
    if(reason){reportRecord(reason,true);return;}
    recordingStarting=true;updateRecordControls();
    try {
      const stream=await ensureProgram();
      if(!room)throw Error('The live broadcast ended before recording started. Start a new session.');
      recordToken=randomId();savedMediaId=null;
      if(!globalThis.MediaRecorder)throw Error('This browser does not support program recording.');
      const mime=selectRecordingMime(x=>MediaRecorder.isTypeSupported(x));
      recorder=new MediaRecorder(stream,mime?{mimeType:mime}:undefined);chunks=[];recordRoom=room?.id||null;
      recorder.ondataavailable=e=>{if(e.data?.size)chunks.push(e.data);};
      recorder.onerror=e=>{reportRecord('The browser encountered a recording error. Keep the downloadable backup, then retry: '+statusText(e.error||e),true);};
      const startedRecorder=recorder,startedRoom=recordRoom;
      const startAt=Date.now();const clock=setInterval(()=>{if(startedRecorder?.state==='recording'){$('live-record-clock').textContent=new Date(Date.now()-startAt).toISOString().slice(11,19);}},1000);
      recorder.onstop=async()=>{
        recordingFinalizing=true;updateRecordControls();
        try{
        clearInterval(clock);document.body.classList.remove('azh-is-recording');$('live-record-stop').disabled=true;
        if(!chunks.length){reportRecord('No recording data was captured. Check camera and browser recording support.',true);return;}
        const type=(startedRecorder.mimeType||mime||'video/webm').split(';')[0];const ext=type.includes('mp4')?'mp4':'webm';
        const title=`${$('live-title').value.trim()||'AZHottie Live'} · ${new Date(startAt).toLocaleDateString()}`;
        const file=new File([new Blob(chunks,{type})],`azhottie-program-${startAt}.${ext}`,{type});recordedFile=file;chunks=[];
        const invalidRecording=file.size<1024;
        if(invalidRecording)reportRecord('Recording contains too few bytes to verify. Keep the local backup; it will not be uploaded automatically.',true);
        if(recordUrl)URL.revokeObjectURL(recordUrl);recordUrl=URL.createObjectURL(file);
        const area=$('live-record-backup');area.replaceChildren();const a=document.createElement('a');a.href=recordUrl;a.download=file.name;a.className='azh-live-button azh-live-secondary';a.textContent='↓ Download recording backup';area.append(a);
        area.append(button('Discard local copy',discardLocalRecording,'azh-live-button azh-live-secondary'));
        // The recording's original room ID is immutable, even if the next
        // session starts while the previous file finishes uploading.
        if(!invalidRecording)await saveRecording(file,title,startedRoom);
        }finally{recordingFinalizing=false;updateRecordControls();}
      };
      recorder.start(1000);updateRecordControls();document.body.classList.add('azh-is-recording');reportRecord('● RECORDING — the complete Program output, camera switches, overlays, and mixed audio.');
    }catch(e){reportRecord(statusText(e),true);}
    finally{recordingStarting=false;updateRecordControls();}
  }
  $('live-record').addEventListener('click',()=>void startRecord());
  $('live-record-stop').addEventListener('click',finishRecording);
  loop=setInterval(()=>{void refreshPhones();if(room)void pollViewers();},2500);void refreshPhones();
  window.addEventListener('beforeunload',e=>{if(recorder?.state==='recording'||recordingStarting||recordingFinalizing||saving||recordedFile){e.preventDefault();e.returnValue='';}});
  window.addEventListener('pagehide',()=>{
    clearInterval(loop);clearInterval(liveHealthTimer);clearInterval(cameraWatch);clearInterval(audioReadinessTimer);clearInterval(audioMeterLoop);clearInterval(hiddenRenderTimer);clearInterval(deliveryTimer);for(const id of phoneRepairTimers.keys())cancelPhoneRepair(id);cancelAnimationFrame(raf);phones.stop();for(const id of [...viewers.keys()])closeViewer(id);
    if(room)liveEnd(true);
    clearPreview();for(const id of [...sources.keys()])removeSource(id);
    micStream?.getTracks().forEach(t=>t.stop());for(const id of [...cameraAudioNodes.keys()])detachCameraAudio(id);canvasStream?.getTracks().forEach(t=>t.stop());oscillator?.stop();audioCtx?.close();
  });
}
