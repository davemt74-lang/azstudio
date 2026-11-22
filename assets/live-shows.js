/* Public catalog: loads only explicitly public, published and active-owner live recordings. */
const grid=document.getElementById('public-shows-grid');
if(grid){
 const status=document.getElementById('public-shows-status'),panel=document.getElementById('public-show-player'),video=document.getElementById('public-show-video');
 const endpoint=new URL('live-public-api.php',document.baseURI);
 async function load(){
   try {
     const result=await fetch(endpoint,{cache:'no-store',credentials:'omit'});const data=await result.json();
     if(!result.ok||!data?.ok)throw Error('Published shows are unavailable.');
     grid.replaceChildren();const items=data.data.recordings||[];
     status.textContent=items.length?`${items.length} published show${items.length===1?'':'s'}`:'No public live shows have been published yet.';
     for(const item of items){
       const card=document.createElement('button');card.type='button';card.className='azh-live-archive-card';
       const cover=document.createElement('div');cover.className='azh-live-archive-cover';
       if(item.coverUrl){const img=document.createElement('img');img.src=item.coverUrl;img.alt='';img.loading='lazy';cover.append(img);}
       else{const play=document.createElement('span');play.textContent='▶';cover.append(play);}
       const label=document.createElement('div');label.className='azh-live-archive-card-body';
       const title=document.createElement('strong');title.textContent=item.title;const date=document.createElement('small');date.textContent=new Date(item.recordedAt).toLocaleDateString();label.append(title,date);
       card.append(cover,label);card.addEventListener('click',()=>{
         video.pause();video.src=item.videoUrl;video.poster=item.coverUrl||'';
         document.getElementById('public-show-title').textContent=item.title;
         document.getElementById('public-show-description').textContent=item.description||'';
         panel.hidden=false;panel.scrollIntoView({block:'nearest',behavior:'smooth'});void video.play().catch(()=>{});
       });grid.append(card);
     }
   }catch(e){status.textContent=e.message||'Unable to load shows.';}
 }
 document.getElementById('public-show-close').addEventListener('click',()=>{video.pause();video.removeAttribute('src');video.load();panel.hidden=true;});
 void load();
}
