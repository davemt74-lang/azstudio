import {waitForIce} from './phone-live.js';
import {fanVideoState} from './live-agent-health.js';
import {randomId} from './personal-phone-core.js';
import {statusText} from './live-core.js';
import {appendLiveTrack} from './live-viewer-core.js';
import {lateViewerJoinNeedsLeave,orderedViewerNonce} from './live-production-guards.js';
import {FanRecoveryBudget,fanRecoveryReason} from './live-recovery.js';
import {resetViewerForRoomChange} from './live-v151-guards.js';
const root=document.getElementById('azh-fan-live');
if(root){
  const $=id=>document.getElementById(id),csrf=root.dataset.csrf,endpoint=new URL('live-api.php',document.baseURI);
  let room=null,viewerId=null,peer=null,pollTimer=null,checking=false,connecting=false,iceServers=[{urls:['stun:stun.l.google.com:19302']}],epoch=0;
  let playerStream=null,receivedVideo=false,frameMonitorTimer=null,frameCallbackId=null,lastDecodedAt=0,frameObserved=false,frameCount=0,visibilityResetAt=0,playRejected=false;
  let healthPreviousFrames=0,healthPreviousTime=performance.now(),healthPending=false,wasPlayingWhenHidden=false;
  const recovery=new FanRecoveryBudget();
  let recoveryTimer=null,recoveryPending=false,recoveryExhausted=false,joinStartedAt=0,disconnectedAt=0,pollFailures=0;
  const recoveryBadge=$('fan-live-recovery');
  function recoveryNotice(message='',bad=false){
    if(!recoveryBadge)return;
    recoveryBadge.hidden=!message;recoveryBadge.textContent=message;recoveryBadge.classList.toggle('is-error',bad);
  }
  const video=$('fan-live-video'),soundButton=$('fan-live-sound');
  // A delayed WebRTC ontrack callback is outside the Join button's gesture.
  // The Program always has an audio track, so unmuted autoplay can be blocked.
  video.muted=true;video.defaultMuted=true;
  function setSoundLabel(){soundButton.textContent=video.muted?'Unmute sound':'Mute sound';soundButton.setAttribute('aria-pressed',String(!video.muted));}
  setSoundLabel();
  soundButton.addEventListener('click',()=>{
    video.muted=!video.muted;setSoundLabel();
    if(video.paused)void video.play().catch(()=>status('Tap Play on the video to resume.',true));
  });
  const played=()=>{playRejected=false;if(!video.hidden)status('● Live program playing'+(video.muted?' · sound muted':' · sound on'));};
  video.addEventListener('playing',played);
  document.addEventListener('visibilitychange',()=>{
    if(document.hidden){wasPlayingWhenHidden=Boolean(viewerId&&!video.paused);return;}
    visibilityResetAt=performance.now();
    if(wasPlayingWhenHidden&&viewerId&&video.paused){
      void video.play().catch(()=>{playRejected=true;status('Playback paused in the background. Tap Play to resume.',true);});
    }
    wasPlayingWhenHidden=false;
  });
  function clearFrameMonitor(){
    clearInterval(frameMonitorTimer);frameMonitorTimer=null;
    if(frameCallbackId!==null&&video.cancelVideoFrameCallback)video.cancelVideoFrameCallback(frameCallbackId);
    frameCallbackId=null;frameObserved=false;frameCount=0;
  }
  function beginFrameMonitor(version){
    clearFrameMonitor();lastDecodedAt=performance.now();visibilityResetAt=lastDecodedAt;
    if(typeof video.requestVideoFrameCallback==='function'){
      frameObserved=true;const tick=()=>{if(epoch!==version)return;lastDecodedAt=performance.now();frameCount++;recovery.observeHealthy(true);if(frameCount===2)recoveryNotice('● Video frames moving · live connection healthy');frameCallbackId=video.requestVideoFrameCallback(tick);};
      frameCallbackId=video.requestVideoFrameCallback(tick);
    }else if(typeof video.getVideoPlaybackQuality==='function'){
      frameObserved=true;frameCount=video.getVideoPlaybackQuality().totalVideoFrames||0;
    }
    if(!frameObserved)return;
    frameMonitorTimer=setInterval(()=>{
      if(epoch!==version||!peer||document.hidden||video.hidden||peer.connectionState!=='connected')return;
      if(!video.requestVideoFrameCallback&&video.getVideoPlaybackQuality){
        const frames=video.getVideoPlaybackQuality().totalVideoFrames||0;
        if(frames!==frameCount){frameCount=frames;lastDecodedAt=performance.now();recovery.observeHealthy(true);if(frameCount>=2)recoveryNotice('● Video frames moving · live connection healthy');}
      }
      const age=performance.now()-Math.max(lastDecodedAt,visibilityResetAt);
      if(age<8000)return;
      if(video.paused){video.muted=true;setSoundLabel();void video.play().catch(()=>{playRejected=true;});}
      status('Live connection is connected but no new video frames are arriving. Keep Director open and visible; check its camera and Program output.',true);
      if(age>=12000&&!video.paused&&!playRejected)scheduleRecovery('frozen_video');
    },3000);
  }
  const status=(s,bad=false)=>{$('fan-live-status').textContent=s;$('fan-live-status').classList.toggle('is-error',bad);};
  async function request(action,body=null,params={}){
    const url=new URL(endpoint);url.searchParams.set('action',action);for(const [k,v] of Object.entries(params))url.searchParams.set(k,String(v));
    let response;try{response=await fetch(url,{method:body===null?'GET':'POST',credentials:'same-origin',cache:'no-store',headers:body===null?{}:{'X-CSRF-Token':csrf,'Content-Type':'application/json'},...(body===null?{}:{body:JSON.stringify(body)})});}
    catch{throw Error('Network unavailable. Your membership and other activity are unaffected.');}
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data?.ok){const error=new Error(data?.error?.message||`Live connection failed (${response.status}).`);error.status=response.status;throw error;}
    return data.data;
  }
  function clearPlayer(notify=false){
    epoch++;clearTimeout(pollTimer);pollTimer=null;clearTimeout(recoveryTimer);recoveryTimer=null;recoveryPending=false;
    connecting=false;joinStartedAt=0;disconnectedAt=0;pollFailures=0;clearFrameMonitor();playerStream=null;receivedVideo=false;
    healthPreviousFrames=0;healthPreviousTime=performance.now();wasPlayingWhenHidden=false;
    const oldId=viewerId;viewerId=null;
    if(peer){const old=peer;peer=null;old.ontrack=null;old.onconnectionstatechange=null;old.close();}
    video.pause();video.srcObject=null;video.hidden=true;playRejected=false;video.muted=true;setSoundLabel();soundButton.hidden=true;$('fan-live-pill').hidden=true;
    $('fan-live-offline').hidden=false;$('fan-live-join').disabled=false;$('fan-live-join').hidden=!room;$('fan-live-leave').hidden=true;
    if(notify&&oldId)void request('leave',{viewerId:oldId}).catch(()=>{});
  }
  function scheduleRecovery(reason){
    if(!room||recoveryPending||recovery.stopped)return;
    if(document.hidden){recoveryNotice('Playback paused in the background. Return to this tab to check the connection.');return;}
    const activeRoom=room.id;
    const attempt=recovery.next();
    clearPlayer(true);
    if(!attempt){
      recoveryExhausted=true;
      recoveryNotice('Automatic recovery limit reached. Check the Director camera, Program output and TURN relay, then choose Retry live.',true);
      $('fan-live-join').textContent='Retry live';
      status('The program could not recover after three attempts. Tap Retry live to try again.',true);
      return;
    }
    recoveryPending=true;
    $('fan-live-join').hidden=true;
    recoveryNotice(`Live video interrupted (${reason.replaceAll('_',' ')}). Reconnecting ${attempt.attempt}/${attempt.maxAttempts}…`,true);
    status('Attempting a fresh live connection without changing your account or the Director broadcast.');
    recoveryTimer=setTimeout(()=>{
      recoveryTimer=null;
      if(!room||room.id!==activeRoom||recovery.stopped){recoveryPending=false;return;}
      if(document.hidden){recoveryPending=false;$('fan-live-join').hidden=false;
        recoveryNotice('Return to this tab and select Join live to resume.');return;}
      recoveryPending=false;
      void join({automatic:true});
    },attempt.delayMs);
  }
  async function check(){
    if(checking)return;checking=true;
    try{
      const data=await request('status');const previous=room?.id;room=data.room||null;iceServers=data.iceServers||iceServers;
      $('fan-live-title').textContent=room?.title||'AZHottie Live';
      if(!room){recovery.enterRoom(null);recoveryExhausted=false;
        if(resetViewerForRoomChange({roomChanged:true,connecting,hasViewer:Boolean(viewerId),recovering:recoveryPending,hasPeer:Boolean(peer)}))clearPlayer(true);recoveryNotice();$('fan-live-join').hidden=true;status('The studio is offline. Live programs appear when the Director starts broadcasting.');}
      else if(previous!==room.id){
        if(resetViewerForRoomChange({roomChanged:true,connecting,hasViewer:Boolean(viewerId),recovering:recoveryPending,hasPeer:Boolean(peer)}))clearPlayer(true);recovery.enterRoom(room.id);recoveryExhausted=false;recoveryNotice();$('fan-live-join').textContent='Join live';$('fan-live-join').hidden=false;status('The live program is on air. Select Join live to watch.');}
      else if(!viewerId&&!connecting&&!recoveryPending){$('fan-live-join').hidden=false;if(!recovery.stopped&&!recoveryExhausted)status('On air · tap Join live to watch.');}
    }catch(e){status(statusText(e),true);}
    finally{checking=false;}
  }
  function schedulePoll(version){clearTimeout(pollTimer);pollTimer=setTimeout(()=>void poll(version),1800);}
  async function poll(version){
    if(epoch!==version||!viewerId)return;
    try{
      const data=await request('poll',null,{viewerId});
      if(epoch!==version||!peer)return;
      if(data.answer&&!peer.currentRemoteDescription)await peer.setRemoteDescription({type:'answer',sdp:data.answer});
      pollFailures=0;
      schedulePoll(version);
    }catch(e){
      if(epoch!==version)return;
      if([401,403,409,429].includes(e.status)){
        recovery.leave();clearPlayer();recoveryNotice('Broadcast unavailable or access ended.',true);
        status('Live connection ended: '+statusText(e),true);return;
      }
      if(++pollFailures<3){status('Checking live connection again after a signaling delay…');schedulePoll(version);return;}
      scheduleRecovery('signaling_interrupted');
    }
  }
  async function join({automatic=false}={}){
    if(!room||connecting||viewerId||recoveryPending)return;
    recovery.enterRoom(room.id);
    if(!automatic){recovery.manualJoin();recoveryExhausted=false;recoveryNotice();}
    connecting=true;joinStartedAt=performance.now();disconnectedAt=0;pollFailures=0;
    // Leave must remain available during a slow signaling request so the
    // visitor can cancel before the server grants a viewer slot.
    $('fan-live-join').textContent='Join live';$('fan-live-join').disabled=true;
    $('fan-live-join').hidden=true;$('fan-live-leave').hidden=false;
    video.muted=true;setSoundLabel();
    const version=++epoch;
    try{
      if(!globalThis.RTCPeerConnection)throw Error('Your browser does not support WebRTC viewing.');
      const p=new RTCPeerConnection({iceServers});peer=p;
      p.addTransceiver('video',{direction:'recvonly'});p.addTransceiver('audio',{direction:'recvonly'});
      p.ontrack=e=>{
        if(epoch!==version||peer!==p)return;
        playerStream=appendLiveTrack(video,e,playerStream);
        if(e.track.kind!=='video'&&!receivedVideo)return;
        video.hidden=false;soundButton.hidden=false;$('fan-live-offline').hidden=true;$('fan-live-pill').hidden=false;
        if(e.track.kind==='video'&&!receivedVideo){receivedVideo=true;beginFrameMonitor(version);recoveryNotice('Video connected · checking decoded frames');}
        if(video.paused)void video.play().then(()=>{if(epoch===version)played();}).catch(()=>{if(epoch===version){playRejected=true;status('The live video is connected. Tap Play to start viewing.',true);}});
      };
      p.onconnectionstatechange=()=>{
        if(epoch!==version||peer!==p)return;
        if(p.connectionState==='connected'){disconnectedAt=0;status('● Live program connected');}
        else if(['failed','closed'].includes(p.connectionState))scheduleRecovery('peer_failed');
        else if(p.connectionState==='disconnected'){
          if(!disconnectedAt)disconnectedAt=performance.now();
          status('Reconnecting to the Director…');
        }
      };
      await p.setLocalDescription(await p.createOffer());await waitForIce(p,7000);
      if(epoch!==version)return;
      const joined=await request('join',{roomId:room.id,nonce:orderedViewerNonce(Date.now(),randomId),sdp:p.localDescription?.sdp||''});
      // A delayed server reply may arrive AFTER Leave or an automatic retry.
      // Release only that stale 32-hex viewer ID; never bind the old SDP/peer
      // to a newer Program session or leave a slot consuming fan capacity.
      if(lateViewerJoinNeedsLeave(joined,version,epoch)){
        void request('leave',{viewerId:joined.viewerId}).catch(()=>{});
        return;
      }
      if(epoch!==version)return;
      viewerId=joined.viewerId;$('fan-live-join').hidden=true;$('fan-live-leave').hidden=false;
      status('Connecting to the Director…');void poll(version);
    }catch(e){if(epoch===version){
      if([401,403,409,429].includes(e.status)||!globalThis.RTCPeerConnection){
        recovery.leave();clearPlayer();status(statusText(e),true);recoveryNotice('Unable to reconnect automatically.',true);
      }else scheduleRecovery('connection_setup_failed');
    }}
    finally{
      // An old join's finally block must not overwrite the new connection UI.
      if(epoch===version){connecting=false;$('fan-live-join').disabled=false;}
    }
  }
  $('fan-live-join').addEventListener('click',()=>void join());
  $('fan-live-leave').addEventListener('click',()=>{
    recovery.leave();clearPlayer(true);recoveryNotice();
    status('You left the live presentation. You can rejoin manually while it remains on air.');
  });
  async function reportViewerHealth(){
    if(!viewerId||!room||!peer||peer.connectionState!=='connected'||healthPending)return;
    const version=epoch,roomId=room.id,viewerSession=viewerId;
    const now=performance.now();
    if(!video.requestVideoFrameCallback && video.getVideoPlaybackQuality && frameObserved){
      const frames=video.getVideoPlaybackQuality().totalVideoFrames||0;
      if(frames!==frameCount){frameCount=frames;lastDecodedAt=now;recovery.observeHealthy(true);if(frameCount>=2)recoveryNotice('● Video frames moving · live connection healthy');}
    }
    const state=fanVideoState({connected:true,visible:!document.hidden,hasVideo:receivedVideo&&!video.hidden,
      canMeasure:frameObserved,secondsSinceFrame:(now-Math.max(lastDecodedAt,visibilityResetAt))/1000,
      playRejected,paused:video.paused});
    const elapsed=now-healthPreviousTime;
    const decodedFps=frameObserved&&elapsed>0?Math.max(0,(frameCount-healthPreviousFrames)*1000/elapsed):null;
    healthPreviousFrames=frameCount;healthPreviousTime=now;
    healthPending=true;
    try {await request('healthReport',{roomId,viewerId:viewerSession,source:'viewer',metrics:{videoState:state,decodedFps,pageHidden:document.hidden}});}
    catch{/* Diagnostics must never interrupt fan playback. */}
    finally{healthPending=false;}
  }
  const recoveryMonitor=setInterval(()=>{
    if(!room||!viewerId||!peer||connecting||recoveryPending||document.hidden)return;
    const time=performance.now();
    const reason=fanRecoveryReason({visible:!document.hidden,connection:peer.connectionState,
      elapsedMs:joinStartedAt?time-joinStartedAt:0,
      disconnectedMs:disconnectedAt?time-disconnectedAt:0,
      hasVideo:receivedVideo,canMeasure:frameObserved,
      frameAgeMs:time-Math.max(lastDecodedAt,visibilityResetAt),
      paused:receivedVideo&&video.paused,autoplayBlocked:playRejected});
    if(reason)scheduleRecovery(reason);
  },2500);
  const healthTimer=setInterval(()=>void reportViewerHealth(),12000);
  const timer=setInterval(()=>void check(),4500);void check();
  window.addEventListener('pagehide',()=>{
    recovery.leave();clearInterval(timer);clearInterval(healthTimer);clearInterval(recoveryMonitor);clearPlayer(true);
  });
}

/* Member-only archive: video files are only streamed by the server after access checks. */
const archive=document.getElementById('fan-archive-grid');
if(archive){
  const endpoint=new URL('live-api.php',document.baseURI),status=document.getElementById('fan-archive-status');
  const $=id=>document.getElementById(id);
  let loading=false;
  function showRecording(item){
    const panel=$('fan-replay-panel'),video=$('fan-replay-video');
    video.pause();video.src=item.videoUrl;video.poster=item.coverUrl||'';
    $('fan-replay-title').textContent=item.title;$('fan-replay-description').textContent=item.description||'';
    panel.hidden=false;panel.scrollIntoView({block:'nearest',behavior:'smooth'});
    void video.play().catch(()=>{});
  }
  async function refreshArchive(){
    if(loading)return;loading=true;status.textContent='Loading past live productions…';archive.replaceChildren();
    try{
      const url=new URL(endpoint);url.searchParams.set('action','archive');
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store'});const payload=await response.json().catch(()=>null);
      if(!response.ok||!payload?.ok)throw Error(payload?.error?.message||'Could not load the live archive.');
      const items=payload.data?.recordings||[];
      status.textContent=items.length?`${items.length} saved live show${items.length===1?'':'s'} available to members.`:'No recordings are published yet.';
      for(const item of items){
        const card=document.createElement('button');card.type='button';card.className='azh-live-archive-card';card.setAttribute('aria-label','Watch recorded show: '+item.title);
        const cover=document.createElement('div');cover.className='azh-live-archive-cover';
        if(item.coverUrl){const image=document.createElement('img');image.src=item.coverUrl;image.alt='';image.loading='lazy';cover.append(image);}
        else{const mark=document.createElement('span');mark.textContent='▶';cover.append(mark);}
        const details=document.createElement('div');details.className='azh-live-archive-card-body';
        const title=document.createElement('strong');title.textContent=item.title;
        const date=document.createElement('small');date.textContent=new Date(item.recordedAt).toLocaleDateString();
        details.append(title,date);card.append(cover,details);card.addEventListener('click',()=>showRecording(item));archive.append(card);
      }
    }catch(error){status.textContent=error.message||'Live archive unavailable.';}
    finally{loading=false;}
  }
  $('fan-archive-refresh').addEventListener('click',()=>void refreshArchive());
  $('fan-replay-close').addEventListener('click',()=>{$('fan-replay-video').pause();$('fan-replay-video').removeAttribute('src');$('fan-replay-video').load();$('fan-replay-panel').hidden=true;});
  void refreshArchive();
  const archiveTimer=setInterval(()=>{if(document.visibilityState==='visible')void refreshArchive();},45000);
  window.addEventListener('pagehide',()=>{clearInterval(archiveTimer);$('fan-replay-video').pause();});
}


/* v1.19 member-only live reaction/chat widget; never accesses camera media. */
const fanChat=document.getElementById('fan-live-engagement');
if(fanChat){
  const root=document.getElementById('azh-fan-live'),csrf=root.dataset.csrf,
        endpoint=new URL('live-api.php',document.baseURI),field=document.getElementById('fan-chat-text'),form=document.getElementById('fan-chat-form'),status=document.getElementById('fan-chat-status');
  let roomId='',loading=false,sending=false;
  async function call(action,body=null){
    const url=new URL(endpoint);url.searchParams.set('action',action);
    if(body===null)url.searchParams.set('roomId',roomId);
    const res=await fetch(url,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',
      headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},...(body?{body:JSON.stringify({...body,roomId})}:{})});
    const parsed=await res.json().catch(()=>null);
    if(!res.ok||!parsed?.ok)throw Error(parsed?.error?.message||'Live interaction unavailable.');return parsed.data;
  }
  function paint(data){
    document.querySelectorAll('[data-reaction-count]').forEach(el=>{el.textContent=String(data.reactions?.[el.dataset.reactionCount]||0);});
    const messages=document.getElementById('fan-chat-messages');messages.replaceChildren();
    for(const msg of data.messages||[]){const row=document.createElement('div');row.className='azh-fan-chat-entry';
      const name=document.createElement('strong');name.textContent=msg.name;
      const content=document.createElement('span');content.textContent=msg.text;row.append(name,content);messages.append(row);
    }
    field.disabled=!data.chatEnabled;form.querySelector('button').disabled=!data.chatEnabled;
    status.textContent=data.chatEnabled?'Chat is open for members. Be respectful.':'The Director has chat disabled. Reactions remain available.';
  }
  async function refresh(){if(loading||!roomId)return;loading=true;
    try{paint(await call('engagement'));}catch(e){status.textContent=e.message||'Unable to refresh chat.';}finally{loading=false;}}
  async function checkRoom(){
    try{const url=new URL(endpoint);url.searchParams.set('action','status');const res=await fetch(url,{credentials:'same-origin',cache:'no-store'});
      const response=await res.json();const next=response?.data?.room?.id||'';
      if(next!==roomId){roomId=next;fanChat.hidden=!roomId;document.getElementById('fan-chat-messages').replaceChildren();if(roomId)await refresh();}
      else if(roomId)await refresh();
    }catch{status.textContent='Live reactions are temporarily unavailable.';}
  }
  document.querySelectorAll('[data-fan-reaction]').forEach(button=>button.addEventListener('click',async()=>{
    if(!roomId)return;button.disabled=true;
    try{paint(await call('react',{kind:button.dataset.fanReaction}));}catch(e){status.textContent=e.message;}
    finally{button.disabled=false;}
  }));
  form.addEventListener('submit',async e=>{e.preventDefault();if(sending||!roomId||!field.value.trim())return;
    sending=true;const message=field.value.trim();form.querySelector('button').disabled=true;
    try{paint(await call('sendChat',{text:message}));field.value='';}catch(e){status.textContent=e.message;}
    finally{sending=false;if(!field.disabled)form.querySelector('button').disabled=false;}
  });
  void checkRoom();const timer=setInterval(()=>{if(document.visibilityState==='visible')void checkRoom();},4500);
  window.addEventListener('pagehide',()=>clearInterval(timer));
}
