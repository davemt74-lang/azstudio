import {InterviewRenderer,getVideoMetadata,VIDEO_FILTERS} from './media-engine.js?v=1.19.1';
import {DEFAULT_SETTINGS,addMedia,changeClip,moveClip,splitClip,duplicateClip,trimClipEdge,detachClipAudio,changeDetachedAudio,highlightProposal,renderSegments,totalSeconds,clipSeconds,mediaSummary} from './personal-timeline-core.js?v=1.19.1';
import {normalizeAudioKeyframes,insertAudioKeyframe,setAudioKeyframe,removeAudioKeyframe,volumeAt,rebaseAudioKeyframes} from './personal-audio-envelope.js?v=1.19.1';
import {personalMediaMime,formatClock} from './personal-media-core.js';
import {uploadToPersonalGallery} from './personal-phone-core.js';

const root=document.querySelector('.personal-timeline');
if(root){
  const $=id=>document.getElementById(id), endpoint=new URL('personal-media-api.php',document.baseURI);
  let csrf=root.dataset.csrf,library=[],projects=[],id=null,revision=0,clips=[],settings=structuredClone(DEFAULT_SETTINGS),selected=null;
  let busy=false,dirty=false,renderer=null,downloadLink=null,dragged=null,playing=false,proposed=null,selectedAudio=null;
  const canvas=$('pt-canvas'),source=$('pt-clip-video'),history={entries:[],index:-1},maxHistory=60;
  const uid=()=>crypto.randomUUID?.().replaceAll('-','')||Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('');
  const make=(tag,cls='',text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=String(text);return n;};
  const message=(text,error=false)=>{$('pt-status').textContent=text;$('pt-status').style.color=error?'#ff9ebd':'#b7e6eb';};
  const clone=value=>JSON.parse(JSON.stringify(value));
  const mediaById=key=>library.find(m=>m.id===key);
  const timelineRuntime=()=>totalSeconds(clips,settings);
  async function api(action,body=null,params={}){
    const url=new URL(endpoint);url.searchParams.set('action',action);for(const [key,v] of Object.entries(params))url.searchParams.set(key,String(v));
    let res;try{res=await fetch(url,{method:body===null?'GET':'POST',credentials:'same-origin',cache:'no-store',headers:body===null?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf},...(body===null?{}:{body:JSON.stringify(body)})});}
    catch{throw Error('Network unavailable. Saved clips remain private; retry when connected.');}
    const data=await res.json().catch(()=>null);if(!res.ok||!data?.ok){const error=Error(data?.error?.message||`Server returned ${res.status}`);error.code=data?.error?.code;throw error;}
    return data.data;
  }
  const saveLabel=()=>{document.title=dirty?'* Video Studio · AZHottie':'Video Studio · AZHottie';};
  const snap=()=>JSON.stringify({clips,settings});
  function resetHistory(){history.entries=[snap()];history.index=0;historyButtons();}
  function historyButtons(){$('vs-undo').disabled=busy||history.index<=0;$('vs-redo').disabled=busy||history.index>=history.entries.length-1;}
  function markDirty(record=true){dirty=true;root.dataset.videoDirty='true';saveLabel();if(record){const state=snap();if(history.entries[history.index]!==state){history.entries=history.entries.slice(0,history.index+1);history.entries.push(state);if(history.entries.length>maxHistory)history.entries.shift();history.index=history.entries.length-1;}}historyButtons();}
  function clean(){dirty=false;root.dataset.videoDirty='false';saveLabel();}
  function restoreHistory(direction){const index=history.index+direction;if(index<0||index>=history.entries.length)return;const state=JSON.parse(history.entries[index]);history.index=index;clips=state.clips;settings=state.settings;selected=clips.some(c=>c.id===selected)?selected:null;dirty=true;saveLabel();syncControls();renderTimeline();selectClip(selected);historyButtons();message('Restored an earlier timeline state. Save to keep it.');}
  $('vs-undo').addEventListener('click',()=>restoreHistory(-1));$('vs-redo').addEventListener('click',()=>restoreHistory(1));
  window.addEventListener('keydown',event=>{if(!(event.ctrlKey||event.metaKey)||event.altKey||!['z','y'].includes(event.key.toLowerCase()))return;if(event.target.closest('input,textarea,select,[contenteditable=true]'))return;event.preventDefault();restoreHistory(event.key.toLowerCase()==='y'||event.shiftKey?1:-1);});
  const needUnsaved=()=>!dirty||confirm('Discard unsaved editor changes?');
  function setBusy(value){busy=value;for(const name of ['pt-save','pt-new','pt-delete','pt-play','vs-preview-movie','pt-export','pt-add-upload','vs-split'])$(name).disabled=value;historyButtons();}
  function syncControls(){
    for(const [id,value] of [['pt-aspect',settings.aspect],['pt-fit',settings.fit],['pt-resolution',String(settings.resolution)],['pt-fade',settings.fadeDuration],['pt-card-duration',settings.cardDuration]])$(id).value=value;
    $('pt-delete').disabled=busy||revision===0;
    const bg=settings.background;
    $('vs-background-source').value=bg?.mediaId||'';$('vs-background-start').value=bg?bg.startMs/1000:0;$('vs-background-trim').value=bg?bg.trimStartMs/1000:0;
    $('vs-background-volume').value=Math.round((bg?.volume??.18)*100);$('vs-background-output').textContent=$('vs-background-volume').value+'%';
  }
  function renderProjectList(){const select=$('pt-project-list');select.replaceChildren(new Option('New project',''));for(const p of projects)select.append(new Option(`${p.name} · ${p.clipCount} clips`,p.id));select.value=projects.some(p=>p.id===id)?id:'';
    root.dataset.videoProjectId=revision>0?id:'';root.dataset.videoRevision=String(revision);
    window.dispatchEvent(new CustomEvent('azh:video-editor-project',{detail:{projectId:revision>0?id:null,revision}}));}

  function renderLibrary(){
    const needle=$('pt-search').value.trim().toLowerCase(),target=$('pt-media');target.replaceChildren();
    const filtered=library.filter(m=>mediaSummary(m).toLowerCase().includes(needle));$('vs-library-count').textContent=`${filtered.length} of ${library.length}`;
    if(!filtered.length){target.append(make('p','vs-hint',needle?'No matching clips.':'No private footage yet. Upload a video or connect your phone.'));return;}
    for(const m of filtered){
      const item=make('div','pt-media-row'),thumb=make('span','pt-media-thumb','▶');
      if(m.coverUrl){const img=make('img');img.src=m.coverUrl;img.alt='';img.loading='lazy';thumb.replaceChildren(img);}
      const info=make('div','pt-media-info');info.append(make('strong','',mediaSummary(m)),make('small','',m.durationMs?formatClock(m.durationMs/1000):'Duration detected on import'));
      const add=make('button','vs-btn','+ Add');add.type='button';add.disabled=busy;add.addEventListener('click',()=>void importMedia(m));item.append(thumb,info,add);target.append(item);
    }
  }
  function backgroundOptions(){const select=$('vs-background-source'),active=select.value;select.replaceChildren(new Option('No background audio',''));
    for(const m of library)select.append(new Option(mediaSummary(m),m.id));select.value=library.some(x=>x.id===active)?active:(settings.background?.mediaId||'');
  }
  async function importMedia(media){
    if(busy)return;setBusy(true);message('Reading '+mediaSummary(media)+'…');
    try{
      let duration=Number(media.durationMs)||0;
      if(!duration){const data=await getVideoMetadata(media.videoUrl);duration=Math.round(data.duration*1000);try{const saved=await api('setDuration',{id:media.id,durationMs:duration});library=library.map(m=>m.id===media.id?saved:m);}catch{ /* Imported metadata can remain in this edit. */ }}
      clips=addMedia(clips,media,duration);selected=clips.at(-1).id;markDirty();renderTimeline();selectClip(selected);
      message(`Added “${mediaSummary(media)}” to V1. Drag to reorder or open Clip inspector to trim.`);
    }catch(e){message(e.message,true);}finally{setBusy(false);syncControls();}
  }
  function makeInspectorMovable(panelId,headerId){
    const panel=$(panelId),header=$(headerId);
    header.addEventListener('pointerdown',event=>{
      if(event.button!==0||event.target.closest('button,input,select'))return;
      event.preventDefault();const rect=panel.getBoundingClientRect(),fromX=event.clientX,fromY=event.clientY;
      header.setPointerCapture(event.pointerId);
      const move=e=>{
        panel.style.left=Math.max(4,Math.min(window.innerWidth-rect.width-4,rect.left+e.clientX-fromX))+'px';
        panel.style.top=Math.max(4,Math.min(window.innerHeight-48,rect.top+e.clientY-fromY))+'px';panel.style.right='auto';
      };
      const end=()=>{header.removeEventListener('pointermove',move);header.removeEventListener('pointerup',end);header.removeEventListener('pointercancel',end);};
      header.addEventListener('pointermove',move);header.addEventListener('pointerup',end);header.addEventListener('pointercancel',end);
    });
  }
  makeInspectorMovable('pt-properties','vs-inspector-drag');makeInspectorMovable('vs-audio-properties','vs-audio-drag');
  /* The context menu is viewport-mounted so clips near the timeline edge are not clipped. */
  const menu=$('vs-timeline-menu');
  function hideMenu(){menu.hidden=true;menu.replaceChildren();}
  function openMenu(x,y,actions){
    menu.replaceChildren();
    for(const [label,callback,disabled] of actions){
      const action=make('button','vs-context-action',label);action.type='button';action.setAttribute('role','menuitem');action.disabled=Boolean(disabled);
      action.addEventListener('click',()=>{hideMenu();try{callback();}catch(e){message(e.message,true);}});menu.append(action);
    }
    menu.hidden=false;
    const rect=menu.getBoundingClientRect();menu.style.left=Math.max(6,Math.min(x,window.innerWidth-rect.width-8))+'px';menu.style.top=Math.max(6,Math.min(y,window.innerHeight-rect.height-8))+'px';
    menu.querySelector('button:not(:disabled)')?.focus();
  }
  document.addEventListener('pointerdown',event=>{if(!menu.hidden&&!menu.contains(event.target))hideMenu();});
  window.addEventListener('keydown',event=>{if(event.key==='Escape'){hideMenu();if(selectedAudio){$('vs-audio-properties').hidden=true;selectedAudio=null;}else showInspector(false);}});
  $('vs-timeline-scroll').addEventListener('scroll',hideMenu,{passive:true});
  function detachAudio(id){
    try{const change=detachClipAudio(clips,settings,id);clips=change.clips;settings=change.settings;markDirty();renderTimeline();selectClip(id);message('Detached source audio to A2. Video plays silently; move or adjust the sound independently.');}
    catch(e){message(e.message,true);}
  }
  function clipMenu(id,x,y,atMs){const clip=clips.find(c=>c.id===id);if(!clip)return;
    const at=Math.round(Number.isFinite(atMs)?atMs:(selected===id?source.currentTime*1000:clip.trimStartMs));
    openMenu(x,y,[
      ['Open clip inspector',()=>selectClip(id)],
      ['Trim start here',()=>commitTrim(id,'start',at),at>=clip.trimEndMs-100||at<0],
      ['Trim end here',()=>commitTrim(id,'end',at),at<=clip.trimStartMs+100||at>clip.durationMs],
      ['Split here',()=>{clips=splitClip(clips,id,at);markDirty();renderTimeline();selectClip(id);},at<=clip.trimStartMs+100||at>=clip.trimEndMs-100],
      ['Duplicate clip',()=>{clips=duplicateClip(clips,id);markDirty();renderTimeline();}],
      [clip.audioDetached?'Audio already detached':'Detach audio to A2',()=>detachAudio(id),clip.audioDetached],
      [clip.audioDetached?'Source audio is detached on A2':(clip.volume===0?'Unmute source audio':'Mute source audio'),()=>{clips=changeClip(clips,id,{volume:clip.volume===0?1:0,audioKeyframes:[]});markDirty();renderTimeline();selectClip(id);},clip.audioDetached],
      ['Remove picture clip',()=>{clips=clips.filter(c=>c.id!==id);if(selected===id)selected=null;markDirty();renderTimeline();selectClip(selected);}]
    ]);
  }
  function commitTrim(id,edge,atMs){
    try{clips=trimClipEdge(clips,id,edge,atMs);markDirty();renderTimeline();if(selected===id)selectClip(id);message(`Trimmed ${edge} edge. Adjacent clips ripple automatically; source files are untouched.`);}
    catch(e){message(e.message,true);}
  }
  function enableTrimHandle(handle,clip,edge){
    handle.addEventListener('pointerdown',event=>{
      if(busy)return;event.preventDefault();event.stopPropagation();hideMenu();
      const {width,duration}=timelineScale(),pxPerMs=width/(duration*1000),original=edge==='start'?clip.trimStartMs:clip.trimEndMs;
      let candidate=original;const startX=event.clientX,clipNode=handle.closest('.pt-timeline-clip');
      const initialWidth=clipNode?.getBoundingClientRect().width||0;handle.setPointerCapture(event.pointerId);
      const move=e=>{
        const delta=Math.round((e.clientX-startX)/pxPerMs);candidate=original+delta;
        if($('vs-snap').checked)candidate=Math.round(candidate/250)*250;
        candidate=edge==='start'?Math.max(0,Math.min(clip.trimEndMs-100,candidate)):Math.max(clip.trimStartMs+100,Math.min(clip.durationMs,candidate));
        if(clipNode)clipNode.style.width=Math.max(30,initialWidth+(candidate-original)*pxPerMs*(edge==='start'?-1:1))+'px';
        handle.title=`${edge==='start'?'In':'Out'} ${(candidate/1000).toFixed(2)}s`;
      };
      const cleanup=()=>{handle.removeEventListener('pointermove',move);handle.removeEventListener('pointerup',finish);handle.removeEventListener('pointercancel',cancel);};
      const finish=e=>{cleanup();if(e.type==='pointerup'&&candidate!==original)commitTrim(clip.id,edge,candidate);else if(clipNode)clipNode.style.width=initialWidth+'px';};
      const cancel=()=>{cleanup();if(clipNode)clipNode.style.width=initialWidth+'px';};
      handle.addEventListener('pointermove',move);handle.addEventListener('pointerup',finish);handle.addEventListener('pointercancel',cancel);
    });
    handle.addEventListener('keydown',event=>{
      if(busy||!['ArrowLeft','ArrowRight'].includes(event.key))return;
      event.preventDefault();event.stopPropagation();
      const step=$('vs-snap').checked?250:100,diff=event.key==='ArrowRight'?step:-step;
      const initial=edge==='start'?clip.trimStartMs:clip.trimEndMs;
      const candidate=edge==='start'?Math.max(0,Math.min(clip.trimEndMs-100,initial+diff)):
        Math.max(clip.trimStartMs+100,Math.min(clip.durationMs,initial+diff));
      if(candidate!==initial)commitTrim(clip.id,edge,candidate);
    });
  }
  function showAudioInspector(id){
    const audio=(settings.detachedAudio||[]).find(a=>a.id===id);if(!audio)return;
    selectedAudio=id;showInspector(false);selectedAudio=id;$('vs-audio-properties').hidden=false;
    $('vs-audio-title').textContent='A2 · '+(mediaById(audio.mediaId)?.title||'Detached audio');
    $('vs-audio-start').value=(audio.startMs/1000).toFixed(2);$('vs-audio-in').value=(audio.trimStartMs/1000).toFixed(2);
    $('vs-audio-out').value=(audio.trimEndMs/1000).toFixed(2);$('vs-audio-level').value=Math.round(audio.volume*100);$('vs-audio-level-output').textContent=Math.round(audio.volume*100)+'%';
  }
  function audioEdit(kind,id,transform){
    if(kind==='source'){
      const clip=clips.find(c=>c.id===id);if(!clip||clip.audioDetached)return;
      clips=changeClip(clips,id,transform({volume:clip.volume,audioKeyframes:clip.audioKeyframes||[],durationMs:clip.trimEndMs-clip.trimStartMs}));
    }else{
      const a=(settings.detachedAudio||[]).find(a=>a.id===id);if(!a)return;
      settings=changeDetachedAudio(settings,id,transform({volume:a.volume,audioKeyframes:a.audioKeyframes||[],durationMs:a.trimEndMs-a.trimStartMs}));
    }
    markDirty();renderTimeline();message('Volume automation updated. Drag points to set levels, or right-click the line for more actions.');
  }
  function audioMenu(audio,pointMs,x,y,index=-1){const target=audio.id,kind=audio.kind,dur=audio.durationMs;
    openMenu(x,y,[
      ['Add volume keyframe here',()=>audioEdit(kind,target,a=>({audioKeyframes:insertAudioKeyframe(a.audioKeyframes,pointMs,dur,a.volume)})),index>=0],
      ['Set this level to 100%',()=>audioEdit(kind,target,a=>({audioKeyframes:setAudioKeyframe(a.audioKeyframes,index,1,dur)})),index<0],
      ['Set this level to 0%',()=>audioEdit(kind,target,a=>({audioKeyframes:setAudioKeyframe(a.audioKeyframes,index,0,dur)})),index<0],
      ['Remove keyframe',()=>audioEdit(kind,target,a=>({audioKeyframes:removeAudioKeyframe(a.audioKeyframes,index,dur)})),index<0],
      ['Clear all keyframes',()=>audioEdit(kind,target,()=>({audioKeyframes:[]})),audio.points.length===0],
      ['Mute clip',()=>audioEdit(kind,target,()=>({volume:0,audioKeyframes:[]}))],
      ['Reset clip to 100%',()=>audioEdit(kind,target,()=>({volume:1,audioKeyframes:[]}))],
      ...(kind==='detached'?[['Open detached audio inspector',()=>showAudioInspector(target)]]:[])
    ]);
  }
  function renderAudioClip(parent,a,durationSec){
    const trackWidth=durationSec*1000;
    const bar=make('div','vs-audio-clip'+(a.kind==='detached'?' is-detached':''));
    bar.style.left=(a.startMs/trackWidth*100)+'%';bar.style.width=(a.durationMs/trackWidth*100)+'%';bar.title=a.title+' · Right-click volume line to add keyframe';
    bar.dataset.audioId=a.id;bar.tabIndex=0;bar.setAttribute('aria-label',`${a.kind==='detached'?'Detached':'Source'} audio ${a.title}, ${Math.round(a.volume*100)} percent`);
    const text=make('span','vs-audio-label',a.kind==='detached'?'♫ '+a.title:a.volume===0?'Muted':'♫ '+a.title);bar.append(text);
    const pathNS='http://www.w3.org/2000/svg',svg=document.createElementNS(pathNS,'svg');svg.setAttribute('viewBox','0 0 1000 100');svg.setAttribute('preserveAspectRatio','none');svg.classList.add('vs-volume-envelope');svg.setAttribute('aria-hidden','true');
    const line=document.createElementNS(pathNS,'polyline');line.setAttribute('fill','none');line.setAttribute('stroke','currentColor');line.setAttribute('stroke-width','6');line.setAttribute('stroke-linejoin','round');line.setAttribute('vector-effect','non-scaling-stroke');
    const sorted=normalizeAudioKeyframes(a.points,a.durationMs),curve=[{timeMs:0,volume:volumeAt(sorted,0,a.volume)},...sorted,{timeMs:a.durationMs,volume:volumeAt(sorted,a.durationMs,a.volume)}];
    line.setAttribute('points',curve.map(p=>`${(p.timeMs/a.durationMs*1000).toFixed(2)},${((1-p.volume)*84+8).toFixed(2)}`).join(' '));svg.append(line);bar.append(svg);
    const msFromEvent=e=>Math.min(a.durationMs,Math.max(0,Math.round((e.clientX-bar.getBoundingClientRect().left)/bar.clientWidth*a.durationMs)));
    const rightClick=e=>{e.preventDefault();e.stopPropagation();audioMenu(a,msFromEvent(e),e.clientX,e.clientY);};
    svg.addEventListener('contextmenu',rightClick);bar.addEventListener('contextmenu',rightClick);
    bar.addEventListener('click',()=>{if(a.kind==='detached')showAudioInspector(a.id);else selectClip(a.id);});
    bar.addEventListener('keydown',e=>{if(e.shiftKey&&e.key==='F10'){e.preventDefault();const r=bar.getBoundingClientRect();audioMenu(a,0,r.left+20,r.top+20);}});
    if(a.kind==='detached'){
      bar.title+=' · Drag the blank audio block to move it on A2';
      bar.addEventListener('pointerdown',event=>{
        if(busy||event.button!==0||![bar,svg].includes(event.target))return;
        hideMenu();event.preventDefault();event.stopPropagation();bar.setPointerCapture(event.pointerId);
        const original=a.startMs,startX=event.clientX,pxPerMs=parent.getBoundingClientRect().width/trackWidth;
        let candidate=original,moved=false;
        const move=e=>{
          const delta=e.clientX-startX;
          if(Math.abs(delta)>4)moved=true;
          candidate=Math.max(0,Math.round(original+delta/Math.max(.000001,pxPerMs)));
          if($('vs-snap').checked)candidate=Math.round(candidate/250)*250;
          bar.style.transform=`translateX(${(candidate-original)*pxPerMs}px)`;
          bar.title=`A2 starts ${(candidate/1000).toFixed(2)}s · release to move`;
        };
        const cleanup=()=>{bar.removeEventListener('pointermove',move);bar.removeEventListener('pointerup',finish);bar.removeEventListener('pointercancel',cancel);};
        const finish=()=>{cleanup();bar.style.transform='';if(moved&&candidate!==original){
          try{settings=changeDetachedAudio(settings,a.id,{startMs:candidate});markDirty();renderTimeline();showAudioInspector(a.id);message('Detached audio moved on A2. Save this edit to preserve the position.');}catch(e){message(e.message,true);}
        }else showAudioInspector(a.id);};
        const cancel=()=>{cleanup();bar.style.transform='';};
        bar.addEventListener('pointermove',move);bar.addEventListener('pointerup',finish);bar.addEventListener('pointercancel',cancel);
      });
    }
    for(const [index,key] of sorted.entries()){
      const dot=make('button','vs-volume-key','');dot.type='button';dot.style.left=key.timeMs/a.durationMs*100+'%';dot.style.top=(1-key.volume)*84+8+'%';
      dot.title=`${(key.timeMs/1000).toFixed(2)}s · ${Math.round(key.volume*100)}%`;dot.setAttribute('aria-label',`Volume keyframe ${index+1}: ${Math.round(key.volume*100)}%. Drag vertically to adjust.`);
      dot.addEventListener('contextmenu',e=>{e.preventDefault();e.stopPropagation();audioMenu(a,key.timeMs,e.clientX,e.clientY,index);});
      dot.addEventListener('click',e=>e.stopPropagation());dot.addEventListener('pointerdown',e=>{
        if(busy)return;e.preventDefault();e.stopPropagation();hideMenu();dot.setPointerCapture(e.pointerId);let level=key.volume;const start=e.clientY;
        const move=ev=>{level=Math.max(0,Math.min(1,key.volume+(start-ev.clientY)/Math.max(20,bar.clientHeight-12)));dot.style.top=(1-level)*84+8+'%';dot.title=Math.round(level*100)+'%';};
        const end=ev=>{dot.removeEventListener('pointermove',move);dot.removeEventListener('pointerup',end);dot.removeEventListener('pointercancel',cancel);if(ev.type==='pointerup')audioEdit(a.kind,a.id,item=>({audioKeyframes:setAudioKeyframe(item.audioKeyframes,index,level,a.durationMs)}));};
        const cancel=()=>{dot.removeEventListener('pointermove',move);dot.removeEventListener('pointerup',end);dot.removeEventListener('pointercancel',cancel);};
        dot.addEventListener('pointermove',move);dot.addEventListener('pointerup',end);dot.addEventListener('pointercancel',cancel);
      });bar.append(dot);
    }
    // Drag the line between adjacent keyframes to raise/lower the whole section.
    line.style.pointerEvents='stroke';line.addEventListener('pointerdown',e=>{
      if(busy)return;e.preventDefault();e.stopPropagation();hideMenu();
      const at=msFromEvent(e),base=volumeAt(sorted,at,a.volume),start=e.clientY;svg.setPointerCapture(e.pointerId);
      let delta=0;const move=event=>{delta=(start-event.clientY)/Math.max(20,bar.clientHeight-12);line.style.filter='brightness(1.7)';};
      const finish=event=>{
        svg.removeEventListener('pointermove',move);svg.removeEventListener('pointerup',finish);svg.removeEventListener('pointercancel',cancel);line.style.filter='';
        if(event.type!=='pointerup'||Math.abs(delta)<.015)return;
        audioEdit(a.kind,a.id,item=>{
          const list=normalizeAudioKeyframes(item.audioKeyframes,a.durationMs);
          if(!list.length)return {volume:Math.min(1,Math.max(0,item.volume+delta))};
          let left=0,right=list.length-1;
          for(let i=0;i<list.length-1;i++)if(at>=list[i].timeMs&&at<=list[i+1].timeMs){left=i;right=i+1;break;}
          if(at<list[0].timeMs){left=right=0;}if(at>list.at(-1).timeMs){left=right=list.length-1;}
          for(const i of new Set([left,right]))list[i].volume=Math.round(Math.min(1,Math.max(0,list[i].volume+delta))*1000)/1000;
          return {audioKeyframes:list};
        });
      };
      const cancel=()=>{svg.removeEventListener('pointermove',move);svg.removeEventListener('pointerup',finish);svg.removeEventListener('pointercancel',cancel);line.style.filter='';};
      svg.addEventListener('pointermove',move);svg.addEventListener('pointerup',finish);svg.addEventListener('pointercancel',cancel);
    });
    parent.append(bar);
  }

  function timelineScale(){const actual=timelineRuntime(),detachedEnd=Math.max(0,...(settings.detachedAudio||[]).map(a=>(a.startMs+a.trimEndMs-a.trimStartMs)/1000)),duration=Math.max(actual,detachedEnd,3);return {width:Math.max(520,Math.ceil(duration*38)),duration,actual};}
  function renderTimeline(){
    const {width,duration,actual}=timelineScale(),track=$('pt-timeline'),marks=$('vs-ruler-marks'),titleTrack=$('vs-titles-track'),audioTrack=$('vs-source-audio'),detachedTrack=$('vs-detached-audio'),musicTrack=$('vs-background-track');
    for(const el of [track,marks,titleTrack,audioTrack,detachedTrack,musicTrack])el.style.width=width+'px';
    $('pt-total').textContent=clips.length+' segments';$('pt-time').textContent=`00:00 / ${formatClock(actual)}`;
    track.replaceChildren();marks.replaceChildren();titleTrack.replaceChildren();audioTrack.replaceChildren();detachedTrack.replaceChildren();musicTrack.replaceChildren();
    const step=duration>180?30:duration>75?15:5;
    for(let t=0;t<=duration+step*.2;t+=step){const label=make('span','',formatClock(t));label.style.position='absolute';label.style.left=(t/duration*100)+'%';marks.append(label);}
    marks.style.position='relative';
    if(!clips.length)track.append(make('li','pt-timeline-empty','Timeline empty. Add clips from the media bin.'));
    let cursor=0;
    for(const [index,clip] of clips.entries()){
      const media=mediaById(clip.mediaId),seconds=clipSeconds(clip)+(clip.titleCard?settings.cardDuration:0);
      const li=make('li','pt-timeline-clip'+(clip.id===selected?' is-selected':''));li.draggable=true;li.dataset.clipId=clip.id;li.style.width=Math.max(30,width*seconds/duration)+'px';
      li.title=`${media?mediaSummary(media):'Unavailable source'} · ${formatClock(seconds)} · ${clip.filter||'none'} filter`;
      li.addEventListener('dragstart',event=>{if(event.target.closest('.vs-trim-handle')){event.preventDefault();return;}dragged=clip.id;event.dataTransfer?.setData('text/plain',clip.id);event.dataTransfer.effectAllowed='move';});
      li.addEventListener('dragover',event=>{event.preventDefault();event.dataTransfer.dropEffect='move';});
      li.addEventListener('drop',event=>{event.preventDefault();const from=dragged||event.dataTransfer?.getData('text/plain');const moved=moveClip(clips,from,index);if(moved!==clips){clips=moved;markDirty();renderTimeline();}});
      li.addEventListener('dragend',()=>{dragged=null;});
      const btn=make('button','pt-clip-main');btn.type='button';btn.append(make('span','pt-clip-index',`V1 · ${index+1}`),make('strong','pt-clip-name',media?mediaSummary(media):'Source missing'),make('small','',`${formatClock(seconds)}${clip.transition==='cut'?' · cut':''}`));btn.addEventListener('click',()=>selectClip(clip.id));li.append(btn);
      const leftTrim=make('button','vs-trim-handle vs-trim-left','‹'),rightTrim=make('button','vs-trim-handle vs-trim-right','›');
      for(const [handle,edge] of [[leftTrim,'start'],[rightTrim,'end']]){handle.type='button';handle.draggable=false;handle.setAttribute('aria-label',`Trim ${edge} of clip ${index+1}`);handle.title=`Drag to trim ${edge}`;enableTrimHandle(handle,clip,edge);li.append(handle);}
      li.addEventListener('contextmenu',event=>{event.preventDefault();
        const r=li.getBoundingClientRect(),f=Math.min(1,Math.max(0,(event.clientX-r.left)/Math.max(1,r.width)));
        clipMenu(clip.id,event.clientX,event.clientY,clip.trimStartMs+f*(clip.trimEndMs-clip.trimStartMs));
      });
      li.addEventListener('keydown',event=>{if(event.key==='F10'&&event.shiftKey){event.preventDefault();const r=li.getBoundingClientRect();clipMenu(clip.id,r.left+30,r.top+30,clip.trimStartMs+(clip.trimEndMs-clip.trimStartMs)/2);}});
      const actions=make('div','pt-clip-actions');for(const [glyph,to,label] of [['◀',index-1,'Move clip earlier'],['▶',index+1,'Move clip later']]){
        const b=make('button','',glyph);b.type='button';b.disabled=to<0||to>=clips.length;b.title=label;b.setAttribute('aria-label',label);b.addEventListener('click',()=>{clips=moveClip(clips,clip.id,to);markDirty();renderTimeline();});actions.append(b);}
      const more=make('button','vs-clip-more','⋯');more.type='button';more.title='Clip actions';more.setAttribute('aria-label','Open clip actions');more.addEventListener('click',event=>{const r=more.getBoundingClientRect();clipMenu(clip.id,r.left,r.bottom,selected===clip.id?Math.round(source.currentTime*1000):clip.trimStartMs+(clip.trimEndMs-clip.trimStartMs)/2);});actions.append(more);
      li.append(actions);track.append(li);
      if(!clip.audioDetached)renderAudioClip(audioTrack,{kind:'source',id:clip.id,title:media?.title||'Source audio',startMs:Math.round((cursor+(clip.titleCard?settings.cardDuration:0))*1000),durationMs:clip.trimEndMs-clip.trimStartMs,volume:clip.volume,points:clip.audioKeyframes||[]},duration);
      else {const a=make('div','vs-audio-detached-label','Audio on A2');a.style.left=cursor/duration*100+'%';a.style.width=seconds/duration*100+'%';audioTrack.append(a);}
      cursor+=seconds;
    }
for(const a of settings.detachedAudio||[])renderAudioClip(detachedTrack,{kind:'detached',id:a.id,title:mediaById(a.mediaId)?.title||'Detached audio',startMs:a.startMs,durationMs:a.trimEndMs-a.trimStartMs,volume:a.volume,points:a.audioKeyframes||[]},duration);
    for(const title of settings.timedTitles||[]){
      const b=make('button','vs-timeline-bar',title.text);b.type='button';b.style.left=title.startMs/1000/duration*100+'%';b.style.width=Math.max(2,title.durationMs/1000/duration*100)+'%';b.title=`T1: ${title.text}`;
      b.addEventListener('click',()=>{setPane('titles');$('vs-title-text').value=title.text;$('vs-title-start').value=title.startMs/1000;$('vs-title-duration').value=title.durationMs/1000;$('vs-title-position').value=title.position;});titleTrack.append(b);
    }
    const bg=settings.background;
    if(bg){const source=mediaById(bg.mediaId);const b=make('div','vs-timeline-bar',source?'♫ '+source.title:'Unavailable soundtrack');b.style.left=bg.startMs/1000/duration*100+'%';b.style.width=Math.max(6,(duration-bg.startMs/1000)/duration*100)+'%';musicTrack.append(b);}
    renderTitleList();setPaneTargets();
  }
  function renderTitleList(){const list=$('vs-title-list');list.replaceChildren();for(const title of settings.timedTitles||[]){
    const row=make('div');row.append(make('span','',`${(title.startMs/1000).toFixed(1)}s · ${title.text}`));const b=make('button','','Remove');b.type='button';b.addEventListener('click',()=>{settings={...settings,timedTitles:settings.timedTitles.filter(t=>t.id!==title.id)};markDirty();renderTimeline();});row.append(b);list.append(row);}}
  function setPaneTargets(){const media=clips.find(x=>x.id===selected);for(const p of document.querySelectorAll('[data-vs-target]'))p.textContent=media?`Selected V1 clip · ${mediaById(media.mediaId)?.title||'Unknown source'}`:'Choose a video clip in V1 first.';}
  function showInspector(value){const panel=$('pt-properties');panel.hidden=!value;$('vs-inspector-toggle').setAttribute('aria-expanded',String(value));if(value){selectedAudio=null;$('vs-audio-properties').hidden=true;}}
  $('vs-inspector-toggle').addEventListener('click',()=>showInspector($('pt-properties').hidden));$('vs-inspector-close').addEventListener('click',()=>showInspector(false));
  const inspectorIds=['pt-in','pt-out','pt-overlay','pt-overlay-position','pt-volume','pt-card','vs-transition','vs-clip-filter'];
  function selectClip(clipId){selected=clipId;for(const li of $('pt-timeline').querySelectorAll('[data-clip-id]'))li.classList.toggle('is-selected',li.dataset.clipId===clipId);
    const activeClip=clips.find(x=>x.id===clipId);
    root.dataset.videoMediaId=activeClip?.mediaId||'';
    root.dataset.videoStartMs=String(activeClip?.trimStartMs??0);
    root.dataset.videoEndMs=String(activeClip?.trimEndMs??0);
    window.dispatchEvent(new CustomEvent('azh:video-editor-clip',{detail:{mediaId:activeClip?.mediaId||null,trimStartMs:activeClip?.trimStartMs??0,trimEndMs:activeClip?.trimEndMs??0}}));
    const clip=clips.find(x=>x.id===clipId);source.pause();source.removeAttribute('src');source.load();if(!clip){showInspector(false);setPaneTargets();return;}
    showInspector(true);const media=mediaById(clip.mediaId);$('pt-clip-title').textContent=media?media.title:'Missing source video';
    $('pt-detach-audio').disabled=clip.audioDetached;$('pt-detach-audio').textContent=clip.audioDetached?'Audio detached ✓':'Detach audio to A2';$('pt-audio-status').textContent=clip.audioDetached?'Video source audio is muted; edit its independent A2 track below.':'Source audio is linked to this video clip on A1.';
    $('pt-in').value=(clip.trimStartMs/1000).toFixed(3);$('pt-out').value=(clip.trimEndMs/1000).toFixed(3);$('pt-overlay').value=clip.overlayText;
    $('pt-overlay-position').value=clip.overlayPosition;$('pt-volume').value=clip.volume*100;$('pt-volume-output').textContent=Math.round(clip.volume*100)+'%';$('pt-card').checked=clip.titleCard;
    $('vs-transition').value=clip.transition||'fade';$('vs-clip-filter').value=clip.filter||'none';$('vs-overlay-text').value=clip.overlayText;$('vs-overlay-pos').value=clip.overlayPosition;
    if(media){source.src=media.videoUrl;source.load();source.addEventListener('loadedmetadata',function ready(){source.removeEventListener('loadedmetadata',ready);source.currentTime=Math.min(clip.trimStartMs/1000,source.duration||0);});}
    setPaneTargets();
  }
  function selectedClip(){return clips.find(x=>x.id===selected);}
  function applyChange(patch){const current=selectedClip();if(!current)throw Error('Select a V1 clip first.');
    const start=patch.trimStartMs??current.trimStartMs,end=patch.trimEndMs??current.trimEndMs;
    if((start!==current.trimStartMs||end!==current.trimEndMs)&&!('audioKeyframes' in patch))
      patch={...patch,audioKeyframes:rebaseAudioKeyframes(current.audioKeyframes,current.trimStartMs,start,end,current.volume)};
    clips=changeClip(clips,selected,patch);markDirty();renderTimeline();message('Clip updated. Save the edit to preserve changes.');}
  function formPatch(){return {trimStartMs:Math.round(Number($('pt-in').value)*1000),trimEndMs:Math.round(Number($('pt-out').value)*1000),overlayText:$('pt-overlay').value.trim(),overlayPosition:$('pt-overlay-position').value,volume:Number($('pt-volume').value)/100,titleCard:$('pt-card').checked,transition:$('vs-transition').value,filter:$('vs-clip-filter').value};}
  $('pt-clip-form').addEventListener('input',event=>{if(event.target.id==='pt-volume')$('pt-volume-output').textContent=$('pt-volume').value+'%';});
  $('pt-clip-form').addEventListener('change',()=>{try{applyChange(formPatch());}catch(e){message(e.message,true);}});
  $('pt-mark-in').addEventListener('click',()=>{$('pt-in').value=source.currentTime.toFixed(3);try{applyChange(formPatch());}catch(e){message(e.message,true);}});
  $('pt-mark-out').addEventListener('click',()=>{$('pt-out').value=source.currentTime.toFixed(3);try{applyChange(formPatch());}catch(e){message(e.message,true);}});
  $('pt-detach-audio').addEventListener('click',()=>{if(selected)detachAudio(selected);});
  $('vs-split').addEventListener('click',()=>{if(!selected)return;try{clips=splitClip(clips,selected,Math.round(source.currentTime*1000));markDirty();renderTimeline();selectClip(selected);message('Split into two timeline clips. The original recording remains unchanged.');}catch(e){message(e.message,true);}});
  $('pt-remove').addEventListener('click',()=>{clips=clips.filter(c=>c.id!==selected);selected=null;markDirty();renderTimeline();selectClip(null);});
  $('vs-audio-close').addEventListener('click',()=>{$('vs-audio-properties').hidden=true;selectedAudio=null;});
  for(const field of ['vs-audio-start','vs-audio-in','vs-audio-out','vs-audio-level'])$(field).addEventListener('change',()=>{if(!selectedAudio)return;try{const audio=(settings.detachedAudio||[]).find(x=>x.id===selectedAudio);if(!audio)return;const trimStartMs=Math.round(Number($('vs-audio-in').value)*1000),trimEndMs=Math.round(Number($('vs-audio-out').value)*1000);settings=changeDetachedAudio(settings,selectedAudio,{startMs:Math.round(Number($('vs-audio-start').value)*1000),trimStartMs,trimEndMs,volume:Number($('vs-audio-level').value)/100,audioKeyframes:trimStartMs!==audio.trimStartMs||trimEndMs!==audio.trimEndMs?rebaseAudioKeyframes(audio.audioKeyframes,audio.trimStartMs,trimStartMs,trimEndMs,audio.volume):audio.audioKeyframes});markDirty();renderTimeline();showAudioInspector(selectedAudio);}catch(e){message(e.message,true);}});
  $('vs-audio-level').addEventListener('input',()=>$('vs-audio-level-output').textContent=$('vs-audio-level').value+'%');
  $('vs-audio-remove').addEventListener('click',()=>{if(!selectedAudio)return;settings={...settings,detachedAudio:settings.detachedAudio.filter(a=>a.id!==selectedAudio)};selectedAudio=null;$('vs-audio-properties').hidden=true;markDirty();renderTimeline();message('Detached audio removed from A2. Original source footage is unchanged.');});
  function setPane(name){for(const b of document.querySelectorAll('[data-vs-pane]'))b.setAttribute('aria-pressed',String(b.dataset.vsPane===name));for(const p of document.querySelectorAll('[data-vs-panel]'))p.hidden=p.dataset.vsPanel!==name;}
  document.querySelectorAll('[data-vs-pane]').forEach(btn=>btn.addEventListener('click',()=>setPane(btn.dataset.vsPane)));
  document.querySelectorAll('[data-look]').forEach(button=>button.addEventListener('click',()=>{try{applyChange({filter:button.dataset.look});$('vs-clip-filter').value=button.dataset.look;}catch(e){message(e.message,true);}}));
  $('vs-overlay-apply').addEventListener('click',()=>{try{applyChange({overlayText:$('vs-overlay-text').value.trim(),overlayPosition:$('vs-overlay-pos').value});$('pt-overlay').value=$('vs-overlay-text').value;$('pt-overlay-position').value=$('vs-overlay-pos').value;}catch(e){message(e.message,true);}});
  $('vs-overlay-remove').addEventListener('click',()=>{try{applyChange({overlayText:''});$('pt-overlay').value='';$('vs-overlay-text').value='';}catch(e){message(e.message,true);}});
  $('vs-template-clip').addEventListener('click',()=>{try{applyChange({filter:$('vs-template-look').value});$('vs-clip-filter').value=$('vs-template-look').value;}catch(e){message(e.message,true);}});
  $('vs-template-all').addEventListener('click',()=>{if(!clips.length){message('Add a clip first.',true);return;}clips=clips.map(c=>({...c,filter:$('vs-template-look').value}));markDirty();renderTimeline();message('Style applied to every clip.');});
  $('vs-title-add').addEventListener('click',()=>{
    const text=$('vs-title-text').value.trim(),start=Number($('vs-title-start').value),duration=Number($('vs-title-duration').value);
    if(!text||!Number.isFinite(start)||start<0||!Number.isFinite(duration)||duration<.3||duration>120||start+duration>86400){message('Enter valid title text, start and duration.',true);return;}
    if((settings.timedTitles||[]).length>=32){message('Maximum 32 timed titles per project.',true);return;}
    settings={...settings,timedTitles:[...(settings.timedTitles||[]),{id:uid(),text,startMs:Math.round(start*1000),durationMs:Math.round(duration*1000),position:$('vs-title-position').value}]};markDirty();renderTimeline();message('Timed title added to T1.');
  });
  $('vs-background-volume').addEventListener('input',()=>$('vs-background-output').textContent=$('vs-background-volume').value+'%');
  $('vs-background-apply').addEventListener('click',()=>{const mediaId=$('vs-background-source').value,from=Number($('vs-background-start').value),trim=Number($('vs-background-trim').value),volume=Number($('vs-background-volume').value)/100;
    if(mediaId&&!mediaById(mediaId)){message('Choose a soundtrack from your private library.',true);return;}
    if(!Number.isFinite(from)||from<0||!Number.isFinite(trim)||trim<0||trim>86400){message('Choose valid audio start times.',true);return;}
    settings={...settings,background:mediaId?{mediaId,startMs:Math.round(from*1000),trimStartMs:Math.round(trim*1000),volume}:null};markDirty();renderTimeline();message(mediaId?'Background audio added to B1.':'Background audio removed.');
  });
  $('vs-background-clear').addEventListener('click',()=>{settings={...settings,background:null};markDirty();renderTimeline();syncControls();});
  function showProposed(value){proposed=value;$('vs-assist-apply').hidden=!value;$('vs-assist-discard').hidden=!value;}
  $('vs-assist-plan').addEventListener('click',()=>{try{const secs=Number($('vs-assist-length').value),proposal=highlightProposal(clips,secs);showProposed(proposal);$('vs-assist-summary').textContent=`Suggested ${formatClock(proposal.reduce((sum,c)=>sum+clipSeconds(c),0))} using ${proposal.length} clips. These trims are based only on duration, not video analysis.`;}catch(e){message(e.message,true);}});
  $('vs-assist-apply').addEventListener('click',()=>{if(!proposed)return;clips=proposed;showProposed(null);markDirty();renderTimeline();selectClip(selected);message('Quick trim proposal applied to unsaved edit. Undo is available.');});
  $('vs-assist-discard').addEventListener('click',()=>{showProposed(null);$('vs-assist-summary').textContent='Suggestion discarded.';});
  for(const name of ['pt-aspect','pt-fit','pt-resolution','pt-fade','pt-card-duration'])$(name).addEventListener('change',()=>{
    const next={...settings,aspect:$('pt-aspect').value,fit:$('pt-fit').value,resolution:Number($('pt-resolution').value),fadeDuration:Number($('pt-fade').value),cardDuration:Number($('pt-card-duration').value)};
    if(![720,1080].includes(next.resolution)||!['16:9','9:16','1:1'].includes(next.aspect)||!['contain','cover'].includes(next.fit)||!Number.isFinite(next.fadeDuration)||next.fadeDuration<0||next.fadeDuration>2||!Number.isFinite(next.cardDuration)||next.cardDuration<.5||next.cardDuration>10){message('Choose supported movie settings.',true);syncControls();return;}
    settings=next;markDirty();renderTimeline();
  });
  $('pt-search').addEventListener('input',renderLibrary);$('pt-name').addEventListener('input',()=>{dirty=true;saveLabel();});
  function newProject(){id=uid();revision=0;selectedAudio=null;$('vs-audio-properties').hidden=true;clips=[];settings=clone(DEFAULT_SETTINGS);selected=null;$('pt-name').value='Untitled edit';clean();renderTimeline();selectClip(null);syncControls();renderProjectList();resetHistory();message('New private video edit. Add footage from Media bin.');}
  window.addEventListener('azh:video-agent-saved',event=>{
    if(event.detail?.projectId===id){if(dirty&&!window.confirm('Discard unsaved edits and load the approved or restored cut?'))return;void loadProject(id);}
  });
  $('pt-new').addEventListener('click',()=>{if(!busy&&needUnsaved())newProject();});
  async function loadProjects(){projects=(await api('projects')).projects||[];renderProjectList();}
  async function loadProject(key){setBusy(true);try{const p=await api('project',null,{id:key});id=p.id;revision=p.revision;selectedAudio=null;$('vs-audio-properties').hidden=true;clips=p.timeline.clips||[];settings={...clone(DEFAULT_SETTINGS),...(p.timeline.settings||{})};selected=null;$('pt-name').value=p.name;clean();renderTimeline();selectClip(null);syncControls();renderProjectList();resetHistory();message(clips.some(c=>!mediaById(c.mediaId))?'Some source videos are no longer available. Remove them before exporting.':'Saved private edit loaded.');}catch(e){message(e.message,true);}finally{setBusy(false);syncControls();}}
  $('pt-project-list').addEventListener('change',event=>{const key=event.target.value;if(!needUnsaved()){renderProjectList();return;}if(!key)newProject();else void loadProject(key);});
  async function save(){if(busy)return;setBusy(true);try{const p=await api('projectSave',{id,revision,name:$('pt-name').value.trim()||'Untitled edit',timeline:{settings,clips}});id=p.id;revision=p.revision;clean();await loadProjects();syncControls();message(`Saved “${p.name}” as revision ${p.revision}. Your originals remain private.`);}catch(e){message(e.message,true);}finally{setBusy(false);syncControls();}}
  $('pt-save').addEventListener('click',()=>void save());
  $('pt-delete').addEventListener('click',async()=>{if(busy||revision===0||!confirm('Delete this edit project? All original and exported videos remain saved.'))return;setBusy(true);try{await api('projectDelete',{id});newProject();await loadProjects();message('Project deleted, original videos preserved.');}catch(e){message(e.message,true);}finally{setBusy(false);syncControls();}});
  let exportAttempt=0;
  function renderConfig(){const detachedAudio=(settings.detachedAudio||[]).map(audio=>{const media=mediaById(audio.mediaId);if(!media)throw Error('A detached A2 audio source is missing. Remove it before exporting.');return {...audio,url:media.videoUrl};});let background=null;if(settings.background){const media=mediaById(settings.background.mediaId);if(!media)throw Error('Background audio source is missing. Remove it from B1.');background={...settings.background,url:media.videoUrl};}
    return {...settings,background,detachedAudio,cardMode:'perClip',brand:'AZHOTTIE'};
  }
  async function run(exporting=false){if(busy)return;let sequence,config;try{if(!clips.length)throw Error('Add a clip from the media bin first.');sequence=renderSegments(clips,library);config=renderConfig();}catch(e){message(e.message,true);return;}
    setBusy(true);playing=true;$('pt-render-progress').hidden=false;$('pt-render-progress').value=0;$('vs-monitor-empty').hidden=true;
    source.pause();const task=++exportAttempt;
    try{
      if(!renderer)renderer=new InterviewRenderer(canvas);
      const callbacks={onStatus:text=>$('pt-preview-state').textContent=text,onProgress:data=>{$('pt-render-progress').value=Math.round(data.ratio*100);$('pt-time').textContent=`${formatClock(data.elapsed)} / ${formatClock(data.total)}`;}};
      message(exporting?'Rendering your movie in the browser. Keep this tab visible…':'Playing your full assembled movie…');
      const result=exporting?await renderer.export(sequence,config,callbacks):await renderer.play(sequence,config,callbacks);
      if(exporting&&task===exportAttempt){
        const type=result.mimeType||'video/webm',ext=result.extension||'webm';const file=new File([result.blob],`azhottie-${($('pt-name').value||'personal-movie').replace(/[^a-z0-9_-]+/gi,'-').slice(0,60)}.${ext}`,{type});
        attachDownload(result.blob,ext);message('Movie rendered. Uploading a private finished copy…');
        const saved=await savePrivateVideo(file,($('pt-name').value.trim()||'Personal movie')+' · finished');
        library.unshift(saved);renderLibrary();backgroundOptions();detachDownload();message('Finished movie saved privately to your media library. Originals unchanged.');
      }else message('Preview finished. Your edit is ready to save or export.');
    }catch(e){message(`The ${exporting?'export':'preview'} stopped: ${e.message}`,true);}finally{playing=false;setBusy(false);syncControls();$('pt-preview-state').textContent='Ready to edit';}
  }
  $('pt-play').addEventListener('click',()=>void run(false));$('vs-preview-movie').addEventListener('click',()=>void run(false));$('vs-render-preview').addEventListener('click',()=>{$('vs-render-dialog').close();void run(false);});
  $('pt-export').addEventListener('click',()=>{$('vs-render-dialog').close();void run(true);});
  $('pt-stop').addEventListener('click',()=>{if(renderer?.isRunning){renderer.stop();message('Preview/export stopped. Your originals remain unchanged.');}});
  $('vs-fullscreen').addEventListener('click',()=>{if(!document.fullscreenElement){void $('vs-monitor').requestFullscreen?.().catch(e=>message(e.message,true));}else void document.exitFullscreen?.();});
  $('vs-render-dialog-open').addEventListener('click',()=>$('vs-render-dialog').showModal());$('vs-render-dialog-close').addEventListener('click',()=>$('vs-render-dialog').close());
  function attachDownload(blob,ext){detachDownload();const a=make('a','vs-btn','Download rendered copy');a.href=URL.createObjectURL(blob);a.download=`azhottie-finished.${ext}`;$('pt-status').after(a);downloadLink=a;}
  function detachDownload(){if(downloadLink){URL.revokeObjectURL(downloadLink.href);downloadLink.remove();downloadLink=null;}}
  async function savePrivateVideo(file,title){if(!personalMediaMime(file.type))throw Error('Unsupported browser video format. Download the rendered copy instead.');
    const token=uid(),mimeType=file.type.split(';')[0];let checkpoint=await api('beginUpload',{clientToken:token,title,fileName:file.name,totalBytes:file.size,mimeType,parentMediaId:''});
    while(checkpoint.status!=='completed'&&checkpoint.receivedBytes<file.size){const offset=checkpoint.receivedBytes,bytes=checkpoint.chunkBytes;if(bytes<=0)throw Error('Upload chunk size is invalid.');const chunk=file.slice(offset,Math.min(file.size,offset+bytes));const form=new FormData();form.append('uploadId',checkpoint.uploadId);form.append('index',String(checkpoint.nextIndex));form.append('chunk',chunk,'chunk.bin');
      try{const u=new URL(endpoint);u.searchParams.set('action','uploadChunk');const r=await fetch(u,{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':csrf},body:form});const payload=await r.json();if(!r.ok||!payload.ok)throw Error(payload.error?.message||'Upload failed.');checkpoint=payload.data;}
      catch(e){try{const latest=await api('status',null,{uploadId:checkpoint.uploadId});if(latest.receivedBytes>offset){checkpoint=latest;continue;}}catch{}throw Error('Upload interrupted. Use Download rendered copy for a backup. '+e.message);}
      $('pt-render-progress').value=Math.round(checkpoint.receivedBytes/file.size*100);message(`Saving finished video ${Math.round(checkpoint.receivedBytes/file.size*100)}%`);
    }
    const saved=await api('finishUpload',{uploadId:checkpoint.uploadId});try{await api('setDuration',{id:saved.id,durationMs:Math.max(100,Math.round(timelineRuntime()*1000))});}catch{}
    return saved;
  }
  async function reloadLibrary(){const data=await api('list');library=data.items||[];renderLibrary();backgroundOptions();message(`${library.length} private videos ready to import.`);}
  $('pt-refresh-library').addEventListener('click',()=>void reloadLibrary().catch(e=>message(e.message,true)));
  let uploadToken=null,uploadFile=null;
  $('pt-add-file').addEventListener('change',()=>{uploadToken=null;uploadFile=$('pt-add-file').files?.[0]||null;});
  $('pt-add-upload').addEventListener('click',async()=>{if(busy)return;const file=$('pt-add-file').files?.[0];if(!file){message('Choose a video first.',true);return;}
    if(file!==uploadFile){uploadToken=null;uploadFile=file;}if(!uploadToken)uploadToken=uid();const title=$('pt-add-title-input').value.trim()||file.name.replace(/\.[^.]+$/,'');setBusy(true);
    try{message('Uploading footage to your private library…');await uploadToPersonalGallery((action,body,params)=>api(action,body,params),file,title,{token:uploadToken,onProgress:(a,b)=>message(`Uploading private video ${Math.round(a/b*100)}%`)});uploadToken=null;uploadFile=null;$('pt-add-file').value='';$('pt-add-title-input').value='';await reloadLibrary();message('Upload complete. Click + Add to place the footage on V1.');}catch(e){message('Upload interrupted. Choose the same file again to resume. '+e.message,true);}finally{setBusy(false);syncControls();}
  });
  window.addEventListener('azh:personal-media-updated',()=>void reloadLibrary().catch(e=>message(e.message,true)));
  source.addEventListener('seeked',()=>{if(playing||!selectedClip()||source.readyState<2)return;try{const ctx=canvas.getContext('2d');if(!ctx)return;const w=canvas.width,h=canvas.height,vw=source.videoWidth||w,vh=source.videoHeight||h;ctx.fillStyle='#000';ctx.fillRect(0,0,w,h);const scale=Math.min(w/vw,h/vh),cw=vw*scale,ch=vh*scale;ctx.save();ctx.filter=VIDEO_FILTERS[selectedClip().filter||'none']||'none';ctx.drawImage(source,(w-cw)/2,(h-ch)/2,cw,ch);ctx.restore();$('vs-monitor-empty').hidden=true;}catch{/* Metadata may not be ready. */}});
  // Resize like Lucky's tool panel, bounded so the video monitor stays visible.
  let resizing=false;$('vs-bin-resizer').addEventListener('pointerdown',event=>{if(window.innerWidth<760)return;resizing=true;event.currentTarget.setPointerCapture(event.pointerId);});
  $('vs-bin-resizer').addEventListener('pointermove',event=>{if(!resizing||window.innerWidth<760)return;const area=document.querySelector('.vs-body').getBoundingClientRect();const px=Math.max(290,Math.min(area.width*.62,event.clientX-area.left));document.querySelector('.vs-body').style.gridTemplateColumns=`${px}px 7px minmax(0,1fr)`;});
  for(const name of ['pointerup','pointercancel'])$('vs-bin-resizer').addEventListener(name,()=>{resizing=false;});
  window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
  async function init(){try{const data=await api('list');csrf=data.csrf||csrf;library=data.items||[];renderLibrary();backgroundOptions();await loadProjects();newProject();const requestedProject=new URL(location.href).searchParams.get('projectId');if(requestedProject&&projects.some(p=>p.id===requestedProject))await loadProject(requestedProject);const sourceId=new URL(location.href).searchParams.get('source');if(sourceId){const media=mediaById(sourceId);if(media)await importMedia(media);else message('Requested footage unavailable in your library.',true);}}
    catch(e){message('Could not load private editor: '+e.message,true);}}
  setPane('media');void init();
}
