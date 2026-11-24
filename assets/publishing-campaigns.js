/* v1.53 campaign handoff: staff-only, no social API calls, no automatic external posting. */
const root=document.getElementById('pg-campaign-workspace');
if(root){
 const $=id=>document.getElementById(id);
 const make=(tag,cls,text)=>{const e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=String(text);return e;};
 const platforms={instagram:'Instagram',tiktok:'TikTok',youtube:'YouTube Shorts',facebook:'Facebook',email:'Email newsletter',other:'Other / manual'};
 let csrf=root.dataset.csrf||'',campaigns=[],promotions=[],busy=false;
 const tell=(message,error=false)=>{const el=$('pg-campaign-message');el.textContent=message;el.hidden=!message;el.classList.toggle('error',error)};
 const fmt=iso=>iso?new Date(iso).toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short',timeZone:'America/Phoenix'}):'No time set';
 async function api(action,body){const url=new URL('publishing-campaign-api.php',document.baseURI);url.searchParams.set('action',action);
   const response=await fetch(url,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},...(body?{body:JSON.stringify(body)}:{})});
   const data=await response.json().catch(()=>null);if(!response.ok||!data?.ok)throw Error(data?.error?.message||'Campaign request failed');return data.data;
 }
 function button(label,handle,cls='pg-button-outline'){
   const btn=make('button','pg-button '+cls,label);btn.type='button';btn.disabled=busy;btn.addEventListener('click',handle);return btn;
 }
 const shareUrl=row=>new URL(row.shareUrl,document.baseURI).href;
 async function copy(value){if(navigator.clipboard?.writeText)await navigator.clipboard.writeText(value);else{const text=make('textarea');text.value=value;document.body.append(text);text.select();document.execCommand('copy');text.remove();}}
 const shareText=row=>`${row.headline}\n\n${row.copy_text}\n\nWatch: ${shareUrl(row)}`;
 async function makeArtwork(row){
   if(!row.coverUrl)throw Error('This video does not have a public cover image. Use the campaign link and caption instead.');
   const url=new URL(row.coverUrl,document.baseURI);if(url.origin!==location.origin)throw Error('Only approved same-site cover images can be used.');
   const img=new Image();img.crossOrigin='anonymous';img.src=url.href;
   await new Promise((resolve,reject)=>{img.onload=resolve;img.onerror=()=>reject(Error('Cover image could not be loaded.'));});
   const canvas=document.createElement('canvas');canvas.width=1080;canvas.height=1920;const cx=canvas.getContext('2d');if(!cx)throw Error('Graphic export is unavailable.');
   cx.fillStyle='#170b14';cx.fillRect(0,0,1080,1920);
   const scale=Math.max(1080/img.naturalWidth,1920/img.naturalHeight);const w=img.naturalWidth*scale,h=img.naturalHeight*scale;
   cx.drawImage(img,(1080-w)/2,(1920-h)/2,w,h);
   const grad=cx.createLinearGradient(0,650,0,1920);grad.addColorStop(0,'rgba(12,9,14,0)');grad.addColorStop(0.65,'rgba(12,9,14,.68)');grad.addColorStop(1,'rgba(12,9,14,.94)');cx.fillStyle=grad;cx.fillRect(0,650,1080,1270);
   cx.fillStyle='#fff';cx.font='700 53px system-ui';cx.fillText('AZHOTTIE',72,122);
   cx.font='700 73px system-ui';const words=(row.headline||'Watch on AZHottie').split(/\s+/);let lines=[],line='';for(const word of words){if(cx.measureText(`${line} ${word}`.trim()).width>920 && line){lines.push(line);line=word;}else line=`${line} ${word}`.trim();}if(line)lines.push(line);
   lines=lines.slice(0,4);lines.forEach((part,i)=>cx.fillText(part,72,1490+i*92));
   cx.font='32px system-ui';cx.fillStyle='#f8c5de';cx.fillText('Watch the full approved video on AZHottie',72,1850);
   const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));
   if(!blob)throw Error('Unable to export the graphic.');const obj=URL.createObjectURL(blob);const a=make('a');a.href=obj;a.download=`azhottie-${row.platform}-${row.id.slice(0,8)}-story.png`;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(obj),15000);
 }
 function render(){
   const list=$('pg-campaigns');list.replaceChildren();
   const active=campaigns.filter(x=>x.state==='active').length;const due=campaigns.filter(x=>x.handoffDue).length;
   $('pg-campaign-summary').textContent=`${campaigns.length} campaign(s) · ${active} active · ${due} scheduled handoff(s) due · ${campaigns.reduce((n,c)=>n+Number(c.opens||0),0)} measured link opens`;
   const select=$('pg-campaign-source');const chosen=select.value;select.replaceChildren();
   const ready=promotions.filter(x=>x.state==='approved'&&x.channel==='social');for(const p of ready){const opt=make('option','',`${p.headline} — ${p.title}`);opt.value=p.id;select.append(opt)}
   if(chosen&&ready.some(p=>p.id===chosen))select.value=chosen;
   $('pg-campaign-create').disabled=!ready.length||busy;
   if(!ready.length)$('pg-campaign-source-note').textContent='Approve a social promotion in Promotion studio first.';
   else $('pg-campaign-source-note').textContent='Only approved, currently published creator-video copy may be used.';
   for(const row of campaigns){const card=make('article','pg-campaign');const heading=make('div','pg-campaign-head');
     heading.append(make('strong','',row.headline),make('span','pg-campaign-status',`${platforms[row.platform]||row.platform} · ${row.state}${row.handoffDue?' · HANDOFF DUE':''}`));card.append(heading);
     card.append(make('p','',row.copy_text),make('small','',`Scheduled handoff: ${fmt(row.schedule_at)} · Revision ${row.revision} · ${row.opens} link opens overall · ${row.opens7d||0} in last 7 days vs ${row.opensPrevious7d||0} prior 7 days. Opens ≠ unique visitors or sales.`));
     const controls=make('div','pg-actions');
     controls.append(button('Copy caption + link',()=>{void copy(shareText(row)).then(()=>tell('Caption and tracking link copied. Posting is manual.')).catch(e=>tell(e.message,true));}));
     controls.append(button('Copy tracking link',()=>{void copy(shareUrl(row)).then(()=>tell('Link copied.')).catch(e=>tell(e.message,true));}));
     if(row.coverUrl)controls.append(button('Download 9:16 story card',()=>void makeArtwork(row).then(()=>tell('PNG story card exported. Video was not transcoded.')).catch(e=>tell(e.message,true))));
     if(row.state==='draft'&&row.schedule_at)controls.append(button('Approve handoff schedule',()=>void decide(row,'schedule')));
     if(['draft','scheduled'].includes(row.state))controls.append(button('Activate tracking link',()=>void decide(row,'activate'),'pg-button'));
     if(row.state!=='retired')controls.append(button('Retire',()=>void decide(row,'retire')));
     card.append(controls);
     if(!row.ready)card.append(make('p','pg-warning','Source approval, publication or revision changed. Tracking link is disabled until a new, approved campaign is created.'));
     list.append(card);
   }
   if(!campaigns.length)list.append(make('p','pg-empty','No campaigns yet. Approve a social promotion and create your first campaign handoff.'));
 }
 async function refresh(){const [r,p]=await Promise.all([api('state'),fetch(new URL('publishing-agent-api.php?action=state',document.baseURI),{cache:'no-store',credentials:'same-origin'}).then(x=>x.json())]);
   if(!p?.ok)throw Error('Approved promotion list could not be loaded.');
   csrf=r.csrf||csrf;campaigns=r.campaigns||[];promotions=p.data?.promotions||[];render();
 }
 async function decide(row,decision){if(busy)return;const text=decision==='activate'?'Activate the link? This does not publish to an external social account.':decision==='retire'?'Retire this campaign and disable its tracking link?':'Schedule the internal handoff? No social message will be sent.';
   if(!window.confirm(text))return;
   busy=true;try{await api('decide',{id:row.id,revision:row.revision,decision});await refresh();tell('Campaign decision saved.');}catch(e){tell(e.message,true);await refresh().catch(()=>{});}finally{busy=false;render()}
 }
 $('pg-campaign-form').addEventListener('submit',async event=>{event.preventDefault();if(busy)return;busy=true;try{await api('create',{promotionId:$('pg-campaign-source').value,platform:$('pg-campaign-platform').value,scheduleAt:$('pg-campaign-time').value});$('pg-campaign-time').value='';await refresh();tell('Campaign draft created. Explicit activation required.');}catch(e){tell(e.message,true);}finally{busy=false;render()}});
 $('pg-campaign-refresh').addEventListener('click',()=>void refresh().then(()=>tell('Campaign records refreshed.')).catch(e=>tell(e.message,true)));
 refresh().catch(e=>tell(e.message,true));
}
