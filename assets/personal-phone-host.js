import {selectRecordingMime,randomId,uploadToPersonalGallery} from './personal-phone-core.js';
const host=document.getElementById('pt-phone-panel');
if(host){
  const $=id=>document.getElementById(id),csrf=document.querySelector('.personal-timeline')?.dataset.csrf||'';
  const phoneApi=new URL('personal-phone-api.php',document.baseURI),personalApi=new URL('personal-media-api.php',document.baseURI);
  let devices=[],peers=new Map(),inviteUrl='',polling=false,timer=null;
  const status=(text,error=false)=>{$('pt-phone-status').textContent=text;$('pt-phone-status').style.color=error?'#a8254e':'#685462';};
  const button=(label,callback,style='pm-button pm-button-secondary')=>{const b=document.createElement('button');b.type='button';b.className=style;b.textContent=label;b.addEventListener('click',callback);return b;};
  async function api(endpoint,action,body=null,params={}){
    const url=new URL(endpoint);url.searchParams.set('action',action);for(const [key,value] of Object.entries(params))url.searchParams.set(key,String(value));
    const form=body instanceof FormData;const response=await fetch(url,{method:body===null?'GET':'POST',credentials:'same-origin',cache:'no-store',headers:body===null?{}:{'X-CSRF-Token':csrf,...(form?{}:{'Content-Type':'application/json'})},...(body===null?{}:{body:form?body:JSON.stringify(body)})});
    const json=await response.json().catch(()=>null);if(!response.ok||!json?.ok)throw Error(json?.error?.message||`Camera request failed (${response.status}).`);return json.data;
  }
  const phoneRequest=(action,body=null,params={})=>api(phoneApi,action,body,params);
  const mediaRequest=(action,body=null,params={})=>api(personalApi,action,body,params);
  $('pt-invite-phone').addEventListener('click',async()=>{
    if(!window.isSecureContext && !['localhost','127.0.0.1'].includes(location.hostname)){status('Use HTTPS to pair a camera.',true);return;}
    try{
      const invite=await phoneRequest('invite',{});
      const url=new URL('my-camera.php',document.baseURI);url.searchParams.set('invite',invite.inviteId);url.searchParams.set('code',invite.code);
      inviteUrl=url.href;const panel=$('pt-phone-invite');panel.hidden=false;
      $('pt-phone-qr').innerHTML=window.LuckyQR?.svg(inviteUrl)||'';
      $('pt-phone-link').value=inviteUrl;
      status('QR expires in five minutes. The phone will wait for your approval.');await refresh();
    }catch(e){status(e.message,true);}
  });
  $('pt-phone-copy').addEventListener('click',async()=>{try{await navigator.clipboard.writeText(inviteUrl);status('Private camera link copied.');}catch{status('Select and copy the link manually.',true);$('pt-phone-link').select();}});
  function removePeer(id){const p=peers.get(id);if(!p)return;peers.delete(id);if(p.recorder?.state==='recording')p.recorder.stop();try{p.peer.close();}catch{}p.card.remove();}
  function renderDevices(){
    const area=$('pt-phone-devices');area.replaceChildren();
    if(!devices.length){const p=document.createElement('p');p.className='pt-note';p.textContent='No other phones connected. A signed-in phone can upload without pairing.';area.append(p);}
    for(const d of devices){
      const row=document.createElement('div');row.className='pt-phone-device';
      const title=document.createElement('strong');title.textContent=d.name;
      const st=document.createElement('span');st.textContent=d.status==='pending'?'Waiting for approval':d.status==='approved'?'Approved':'Unavailable';
      row.append(title,st);
      if(d.status==='pending')row.append(button('Approve',async()=>{try{await phoneRequest('approve',{id:d.id});status(`${d.name} approved.`);await refresh();}catch(e){status(e.message,true);}}));
      row.append(button('Disconnect',async()=>{if(!confirm('Disconnect this phone? Any unfinished upload from this phone will stop.'))return;try{await phoneRequest('revoke',{id:d.id});removePeer(d.id);await refresh();status('Phone disconnected.');}catch(e){status(e.message,true);}}));
      area.append(row);
    }
  }
  async function iceComplete(peer){if(peer.iceGatheringState==='complete')return;await new Promise(resolve=>{const done=()=>{clearTimeout(t);peer.removeEventListener('icegatheringstatechange',check);resolve();};const check=()=>{if(peer.iceGatheringState==='complete')done();};const t=setTimeout(done,8000);peer.addEventListener('icegatheringstatechange',check);});}
  async function acceptStream(offer){
    if(!window.RTCPeerConnection)return;
    const peer=new RTCPeerConnection({iceServers:[{urls:'stun:stun.l.google.com:19302'}]});
    const card=document.createElement('article');card.className='pt-phone-live-card';
    const heading=document.createElement('strong');heading.textContent=offer.name;
    const video=document.createElement('video');video.autoplay=true;video.muted=true;video.playsInline=true;video.setAttribute('aria-label',offer.name+' live camera preview');
    const note=document.createElement('small');note.textContent='Connecting live preview…';
    const recordButton=button('● Record this camera',()=>recordStream(offer.id));recordButton.disabled=true;
    const stopButton=button('■ Stop recording',()=>stopRecording(offer.id));stopButton.disabled=true;
    card.append(heading,video,note,recordButton,stopButton);$('pt-phone-live-feeds').append(card);
    const item={peer,card,video,note,recordButton,stopButton,nonce:offer.nonce,stream:null,recorder:null,chunks:[]};peers.set(offer.id,item);
    peer.ontrack=event=>{
      if(peers.get(offer.id)!==item)return;
      item.stream=event.streams[0]||new MediaStream([event.track]);video.srcObject=item.stream;void video.play().catch(()=>{});
      note.textContent='● LIVE · Private camera';recordButton.disabled=false;
    };
    peer.onconnectionstatechange=()=>{
      if(peer.connectionState==='failed'||peer.connectionState==='disconnected'){
        recordButton.disabled=true;note.textContent='Live connection interrupted. The phone can still upload recordings.';
      }
    };
    try{
      await peer.setRemoteDescription({type:'offer',sdp:offer.offer});
      await peer.setLocalDescription(await peer.createAnswer());await iceComplete(peer);
      if(peers.get(offer.id)!==item)return;
      await phoneRequest('answer',{id:offer.id,nonce:offer.nonce,sdp:peer.localDescription.sdp});
    }catch(e){removePeer(offer.id);status('Live camera connection failed: '+e.message+' Phone uploads are still supported.',true);}
  }
  async function recordStream(id){
    const item=peers.get(id);if(!item?.stream||!window.MediaRecorder)return;
    const mime=selectRecordingMime(m=>MediaRecorder.isTypeSupported(m));item.chunks=[];
    try{
      item.recorder=new MediaRecorder(item.stream,mime?{mimeType:mime}:undefined);
      item.recorder.ondataavailable=e=>{if(e.data?.size)item.chunks.push(e.data);};
      item.recorder.onstop=async()=>{
        item.stopButton.disabled=true;item.note.textContent='Saving recorded clip to your private gallery…';
        const type=item.recorder.mimeType||mime||'video/webm',ext=type.includes('mp4')?'mp4':'webm';
        const file=new File([new Blob(item.chunks,{type})],`azhottie-live-${Date.now()}.${ext}`,{type});
        const download=document.createElement('a');const url=URL.createObjectURL(file);download.href=url;download.download=file.name;download.textContent='Download recording backup';download.className='pm-button pm-button-secondary';
        item.card.append(download);
        try{const media=await uploadToPersonalGallery(mediaRequest,file,offerName(item),{onProgress:(d,t)=>{item.note.textContent=`Saving recorded phone clip ${Math.round(100*d/t)}%`;}});
          item.note.textContent=`Saved “${media.title}” privately. Refreshing media library…`;window.dispatchEvent(new CustomEvent('azh:personal-media-updated'));
          download.remove();URL.revokeObjectURL(url);
        }catch(e){item.note.textContent='Upload interrupted. Download your recording backup, then upload it from My media. '+e.message;}
        finally{item.recordButton.disabled=false;}
      };
      item.recorder.start(750);item.recordButton.disabled=true;item.stopButton.disabled=false;item.note.textContent='Recording live phone video…';
    }catch(e){item.note.textContent=e.message;}
  }
  const offerName=item=>item.card.querySelector('strong')?.textContent||'Phone recording';
  function stopRecording(id){const item=peers.get(id);if(item?.recorder?.state==='recording')item.recorder.stop();}
  async function refresh(){if(polling)return;polling=true;
    try{
      const result=await phoneRequest('devices');devices=result.devices||[];renderDevices();
      const offers=await phoneRequest('offers');const live=offers.offers||[];const seen=new Set(live.map(x=>x.id));
      for(const id of [...peers.keys()])if(!seen.has(id))removePeer(id);
      for(const offer of live){const p=peers.get(offer.id);if(p?.nonce===offer.nonce)continue;if(p)removePeer(offer.id);void acceptStream(offer);}
    }catch(e){status(e.message,true);}finally{polling=false;}
  }
  $('pt-refresh-phones').addEventListener('click',()=>void refresh());
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)void refresh();});
  const check=()=>{if(document.visibilityState==='visible')void refresh();timer=setTimeout(check,3000);};
  void check();window.addEventListener('pagehide',()=>{clearTimeout(timer);for(const id of [...peers.keys()])removePeer(id);});
}
