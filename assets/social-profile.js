const root=document.getElementById('social-profile');
if(root?.dataset.owner==='true'){
 const message=document.getElementById('social-upload-feedback');
 for(const input of root.querySelectorAll('input[data-image-kind]'))input.addEventListener('change',async()=>{
   const file=input.files?.[0];if(!file)return;
   if(!['image/jpeg','image/png','image/webp'].includes(file.type)){message.textContent='Select a JPG, PNG, or WebP image.';input.value='';return;}
   const max=input.dataset.imageKind==='cover'?8*1048576:5*1048576;
   if(file.size>max){message.textContent='That image exceeds the allowed size.';input.value='';return;}
   const body=new FormData();body.set('image',file);body.set('type',input.dataset.imageKind);input.disabled=true;
   message.textContent='Uploading picture…';
   try{
     const res=await fetch('social-api.php?action=uploadImage',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':root.dataset.csrf},body,cache:'no-store'});
     const data=await res.json().catch(()=>null);
     if(!res.ok||!data?.ok)throw Error(data?.error?.message||`Upload failed (HTTP ${res.status})`);
     location.reload();
   }catch(e){message.textContent=e.message;input.disabled=false;input.value='';}
 });
}
