import {InterviewRenderer, getVideoMetadata} from './media-engine.js';
import {normalizeTrim, uploadIdentity, personalMediaMime, originalSelectionDuration, formatClock} from './personal-media-core.js';

const root=document.querySelector('.personal-media');
if (root) {
  const byId=id=>document.getElementById(id);
  const qs=new URL('personal-media-api.php',document.baseURI);
  let csrf=root.dataset.csrf;
  let items=[], current=null, editDuration=0, busy=false, upload=null, previewLimit=0, renderer=null;
  const video=byId('pm-video');
  const status=(message,error=false)=>{const element=byId('pm-editor-status');element.textContent=message;element.style.color=error?'#b52c4b':'#735361';};
  const uploadStatus=(message,error=false)=>{const element=byId('pm-upload-status');element.textContent=message;element.style.color=error?'#b52c4b':'#735361';};
  function setProgress(received,total){byId('pm-progress-fill').style.width=`${Math.round(100*received/Math.max(1,total))}%`;}
  function uid(){return globalThis.crypto?.randomUUID?.().replaceAll('-','')||Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');}
  async function request(action,body=null,query={}) {
    const url=new URL(qs);url.searchParams.set('action',action);
    Object.entries(query).forEach(([name,value])=>url.searchParams.set(name,String(value)));
    const multipart=body instanceof FormData;
    let response;
    try {
      response=await fetch(url.href,{method:body===null?'GET':'POST',cache:'no-store',credentials:'same-origin',headers:body===null?{}:{'X-CSRF-Token':csrf,...(multipart?{}:{'Content-Type':'application/json'})},...(body===null?{}:{body:multipart?body:JSON.stringify(body)})});
    } catch {throw new Error('Connection interrupted. Your original file is unchanged; retry the upload or reload.');}
    const data=await response.json().catch(()=>null);
    if(!data || !response.ok || !data.ok) {
      const error=new Error(data?.error?.message||'The studio could not complete the request.');
      error.code=data?.error?.code||'server_error';
      throw error;
    }
    return data.data;
  }
  const bytes=value=>Number(value)<1048576?`${(Number(value)/1024).toFixed(0)} KB`:`${(Number(value)/1048576).toFixed(1)} MB`;
  const el=(tag,cls,text)=>{const node=document.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=text;return node;};
  async function refresh(openId=null) {
    const data=await request('list');csrf=data.csrf||csrf;items=data.items||[];
    byId('pm-count').textContent=`${items.length} video${items.length===1?'':'s'}`;
    byId('pm-storage').textContent=`${bytes(data.quota.usedBytes)} of ${bytes(data.quota.limitBytes)} stored`;
    renderPending(data.pendingUploads||[]);
    const grid=byId('pm-grid');grid.replaceChildren();
    if(!items.length)grid.append(el('p','pm-empty','Your gallery is empty. Upload a video to start your private media library.'));
    items.forEach(media=>{
      const card=el('article','pm-card');
      const poster=el('div','pm-poster','▷');
      if(media.coverUrl){const img=el('img');img.src=media.coverUrl;img.alt='Video cover';img.loading='lazy';poster.textContent='';poster.append(img);}
      const body=el('div','pm-card-body');body.append(el('strong','',media.title),el('small','',`${media.durationMs?formatClock(media.durationMs/1000):'Duration unavailable'} · ${bytes(media.sizeBytes)} · Private`));
      const btn=el('a','pm-button pm-button-secondary','Edit in timeline');btn.href='my-editor.php?source='+encodeURIComponent(media.id);
      const quick=el('button','pm-button pm-button-secondary','Quick trim');quick.type='button';quick.addEventListener('click',()=>selectMedia(media));
      body.append(btn,quick);card.append(poster,body);grid.append(card);
    });
    if(openId){const found=items.find(x=>x.id===openId);if(found)await selectMedia(found);}
    else if(current){const updated=items.find(x=>x.id===current.id);if(updated)current=updated;else closeEditor();}
  }
  function renderPending(pending) {
    const wrap=byId('pm-pending-wrap'),area=byId('pm-pending');area.replaceChildren();wrap.hidden=!pending.length;
    for(const row of pending) {
      const div=el('div','pm-pending-row'),label=el('div');
      label.append(el('strong','',row.title||row.fileName),el('small','',`${row.fileName} · ${Math.round(100*row.receivedBytes/row.totalBytes)}% saved`));
      const resume=el('button','pm-button pm-button-secondary','Resume'),discard=el('button','pm-button pm-button-danger','Discard');
      resume.type='button';discard.type='button';
      resume.addEventListener('click',()=>{
        const file=byId('pm-file').files?.[0];
        if(!file || file.name!==row.fileName || file.size!==row.totalBytes){uploadStatus(`To resume “${row.title}”, select the original file named ${row.fileName} (${bytes(row.totalBytes)}), then press Resume.`,true);return;}
        upload={file,title:row.title,token:row.clientToken,parentMediaId:'',uploadId:row.uploadId};void doUpload();
      });
      discard.addEventListener('click',async()=>{
        if(!confirm(`Discard the unfinished upload for “${row.title}”? No saved gallery videos will be affected.`))return;
        try{await request('cancelUpload',{uploadId:row.uploadId});await refresh();uploadStatus('Incomplete upload discarded.');}
        catch(error){uploadStatus(error.message,true);}
      });
      div.append(label,resume,discard);area.append(div);
    }
  }
  async function saveUpload(file,title,parentMediaId='',token=uid()) {
    if(!file || !file.size)throw new Error('Select a video file.');
    if(!personalMediaMime(file.type||''))throw new Error('Please select an MP4, MOV, WebM or Ogg video.');
    if(!uploadIdentity(token,file.size,file.name))throw new Error('The selected recording has invalid upload details.');
    upload={file,title,token,parentMediaId,uploadId:null};
    byId('pm-retry').hidden=true;
    const metadata={clientToken:token,fileName:file.name,totalBytes:file.size,mimeType:(file.type||'application/octet-stream').split(';')[0].toLowerCase().trim(),title,parentMediaId};
    let checkpoint=await request('beginUpload',metadata);
    upload.uploadId=checkpoint.uploadId;
    while(checkpoint.status!=='completed' && checkpoint.receivedBytes<file.size) {
      const offset=Number(checkpoint.receivedBytes), index=Number(checkpoint.nextIndex), chunkSize=Number(checkpoint.chunkBytes);
      if(!Number.isSafeInteger(offset)||!Number.isSafeInteger(chunkSize)||chunkSize<=0||offset>=file.size)throw new Error('The server returned an invalid upload checkpoint.');
      const form=new FormData();form.append('uploadId',checkpoint.uploadId);form.append('index',String(index));form.append('chunk',file.slice(offset,Math.min(file.size,offset+chunkSize)),'chunk.bin');
      try {checkpoint=await request('uploadChunk',form);}
      catch(error){
        // If the upload response was lost, the checkpoint tells us whether it was saved.
        try {const latest=await request('status',null,{uploadId:checkpoint.uploadId});if(latest.receivedBytes>offset){checkpoint=latest;continue;}} catch { /* keep original error */ }
        throw error;
      }
      setProgress(checkpoint.receivedBytes,file.size);
      uploadStatus(`Uploading ${title}: ${Math.round(checkpoint.receivedBytes/file.size*100)}%`);
    }
    const saved=await request('finishUpload',{uploadId:checkpoint.uploadId});
    setProgress(file.size,file.size);upload=null;byId('pm-retry').hidden=true;
    uploadStatus('Saved to your private media gallery.');
    byId('pm-file').value='';
    await refresh(saved.id);
    return saved;
  }
  async function doUpload() {
    if(busy || !upload)return;
    busy=true;byId('pm-upload-btn').disabled=true;byId('pm-export').disabled=true;
    try {await saveUpload(upload.file,upload.title,upload.parentMediaId,upload.token);}
    catch(error){uploadStatus(error.message,true);byId('pm-retry').hidden=false;}
    finally {busy=false;byId('pm-upload-btn').disabled=false;byId('pm-export').disabled=false;}
  }
  byId('pm-upload-form').addEventListener('submit',event=>{
    event.preventDefault();if(busy)return;
    const file=byId('pm-file').files?.[0];
    if(!file){uploadStatus('Choose a video first.',true);return;}
    upload={file,title:byId('pm-new-title').value.trim()||file.name.replace(/\.[^.]+$/,''),parentMediaId:'',token:uid()};
    void doUpload();
  });
  byId('pm-retry').addEventListener('click',()=>void doUpload());
  function closeEditor(){current=null;previewLimit=0;video.pause();video.removeAttribute('src');video.load();byId('pm-editor').hidden=true;if(renderer?.isRunning)renderer.stop();byId('pm-render-canvas').hidden=true;video.hidden=false;}
  byId('pm-close').addEventListener('click',closeEditor);
  async function selectMedia(media){
    if(busy){status('Finish the current export or upload before switching videos.',true);return;}
    current=media;editDuration=(media.durationMs||0)/1000;
    const section=byId('pm-editor');section.hidden=false;
    byId('pm-title').value=media.title;byId('pm-description').value=media.description||'';
    byId('pm-start').value=Number(media.trimStartMs||0)/1000;
    byId('pm-end').value=Number(media.trimEndMs||media.durationMs||0)/1000;
    byId('pm-video-duration').textContent=media.durationMs?formatClock(media.durationMs/1000):'--:--';
    byId('pm-current-time').textContent='00:00';byId('pm-download').href=media.downloadUrl;
    byId('pm-render-canvas').hidden=true;video.hidden=false;video.src=media.videoUrl;video.load();
    status('Your original footage stays unchanged. Save trim points or export a new copy to the gallery.');
    section.scrollIntoView({behavior:'smooth',block:'start'});
  }
  video.addEventListener('loadedmetadata',()=>{
    if(!current)return;
    const duration=video.duration;
    if(Number.isFinite(duration)&&duration>0){
      editDuration=duration;
      if(!current.trimEndMs && !byId('pm-end').value || +byId('pm-end').value===0)byId('pm-end').value=(Math.floor(duration*1000)/1000).toFixed(3);
      byId('pm-video-duration').textContent=formatClock(duration);
    }
  });
  video.addEventListener('timeupdate',()=>{
    byId('pm-current-time').textContent=formatClock(video.currentTime);
    if(previewLimit>0 && video.currentTime>=previewLimit-0.03){video.pause();previewLimit=0;}
  });
  const currentTrim=()=>normalizeTrim(byId('pm-start').value,byId('pm-end').value,editDuration);
  byId('pm-mark-start').addEventListener('click',()=>{byId('pm-start').value=video.currentTime.toFixed(1);status('Start point marked. Save edit points to keep it.');});
  byId('pm-mark-end').addEventListener('click',()=>{byId('pm-end').value=video.currentTime.toFixed(1);status('End point marked. Save edit points to keep it.');});
  byId('pm-preview').addEventListener('click',async()=>{
    if(!current)return;
    try {const t=currentTrim();previewLimit=(t.trimEndMs||t.durationMs)/1000;video.currentTime=t.trimStartMs/1000;await video.play();status('Previewing the selected section.');}
    catch(error){previewLimit=0;status(error.message,true);}
  });
  byId('pm-edit-form').addEventListener('submit',async event=>{
    event.preventDefault();if(!current || busy)return;
    try {
      const trim=currentTrim();
      const item=await request('update',{id:current.id,revision:current.revision,title:byId('pm-title').value.trim(),description:byId('pm-description').value.trim(),...trim});
      current=item;status('Saved. Trim points and notes are private until you export or submit footage.');
      await refresh();
    } catch(error){status(error.message,true);}
  });
  function editedDownload(blob,extension){
    const link=el('a','pm-button pm-button-secondary','Download edited copy');
    const url=URL.createObjectURL(blob);link.href=url;link.download=`azhottie-edit-${Date.now()}.${extension}`;
    link.addEventListener('click',()=>setTimeout(()=>URL.revokeObjectURL(url),30000),{once:true});
    const statusNode=byId('pm-editor-status');statusNode.after(link);
    return link;
  }
  byId('pm-export').addEventListener('click',async()=>{
    if(!current||busy)return;
    let t;
    try {t=currentTrim();if(originalSelectionDuration(t.durationMs,t.trimStartMs,t.trimEndMs)<.1)throw new Error('Choose a usable section before exporting.');}
    catch(e){status(e.message,true);return;}
    const source={...current};busy=true;byId('pm-export').disabled=true;
    const canvas=byId('pm-render-canvas');
    try {
      if(!renderer)renderer=new InterviewRenderer(canvas);
      video.pause();video.hidden=true;canvas.hidden=false;
      status('Exporting in your browser. Keep this tab visible until the edited copy is saved.');
      const shape=video.videoWidth>0&&video.videoHeight>0 ? video.videoWidth/video.videoHeight : 16/9;
      const aspect=shape<.8?'9:16':shape<1.18?'1:1':'16:9';
      const exportResult=await renderer.export([{src:source.videoUrl,duration:t.durationMs/1000,trimStart:t.trimStartMs/1000,trimEnd:(t.trimEndMs||t.durationMs)/1000,question:'',showCard:false}],{cardMode:'none',fadeDuration:0.25,aspect,resolution:720,fit:'contain',brand:'AZHOTTIE'},{onProgress:result=>status(`Rendering edited copy: ${Math.round(result.ratio*100)}%`)});
      const file=new File([exportResult.blob],`${source.title.replace(/[^\w -]/g,'').slice(0,70)||'azhottie'}-edit.${exportResult.extension||'webm'}`,{type:exportResult.mimeType});
      status('Edit rendered. Saving a new private media copy…');
      const download=editedDownload(exportResult.blob,exportResult.extension||'webm');
      try {await saveUpload(file,`${source.title} · edited`,source.id,uid());download.remove();status('Edited copy saved to your gallery. The original is unchanged.');}
      catch(error){status(`Edited copy is ready, but the upload failed: ${error.message}. Use Download edited copy or retry the upload above.`,true);byId('pm-retry').hidden=false;}
    } catch(error){status(error.message,true);}
    finally {busy=false;byId('pm-export').disabled=false;canvas.hidden=true;video.hidden=false;}
  });
  async function sendCover(blob,name){
    if(!current)return;
    const form=new FormData();form.append('id',current.id);form.append('cover',blob,name);
    const updated=await request('cover',form);current=updated;status('Cover image saved for your private video.');await refresh();
  }
  byId('pm-capture-cover').addEventListener('click',async()=>{
    if(!current)return;
    try {
      if(!video.videoWidth || !video.videoHeight)throw new Error('Load a video frame before capturing the cover.');
      const canvas=document.createElement('canvas');const scale=Math.min(1,1200/video.videoWidth,1200/video.videoHeight);
      canvas.width=Math.max(1,Math.round(video.videoWidth*scale));canvas.height=Math.max(1,Math.round(video.videoHeight*scale));
      canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);
      const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',.85));
      if(!blob)throw new Error('This browser could not capture a cover from the video.');
      await sendCover(blob,'cover.jpg');
    } catch(error){status(error.message,true);}
  });
  byId('pm-cover-file').addEventListener('change',async event=>{
    const file=event.target.files?.[0];if(!file||!current)return;
    try {await sendCover(file,file.name);}catch(error){status(error.message,true);}finally {event.target.value='';}
  });
  byId('pm-delete').addEventListener('click',async()=>{
    if(!current||busy)return;
    if(!confirm(`Permanently delete “${current.title}” and its private cover? This cannot be undone.`))return;
    const id=current.id;
    try {await request('delete',{id});closeEditor();await refresh();uploadStatus('The video was deleted from your gallery.');}
    catch(error){status(error.message,true);}
  });
  void refresh().catch(error=>uploadStatus(`Could not load your gallery: ${error.message}`,true));
}
