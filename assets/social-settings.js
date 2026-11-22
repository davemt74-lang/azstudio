const $=id=>document.getElementById(id);
const form=$('social-settings-form'),status=$('social-settings-status');
if(form){
  let revision=0,csrf='';
  const report=(message,bad=false)=>{status.textContent=message;status.classList.toggle('is-error',bad)};
  const request=async(action,body)=>{
    const options={credentials:'same-origin',cache:'no-store'};
    if(body!==undefined){options.method='POST';options.headers={'Content-Type':'application/json','X-CSRF-Token':csrf};options.body=JSON.stringify(body)}
    const res=await fetch('social-api.php?action='+encodeURIComponent(action),options);
    const result=await res.json().catch(()=>null);
    if(!res.ok||!result?.ok)throw Error(result?.error?.message||`Profile request failed (HTTP ${res.status})`);
    return result.data;
  };
  const fill=p=>{revision=p.revision;$('social-remove-avatar').hidden=!p.hasAvatar;$('social-remove-cover').hidden=!p.hasCover;for(const key of ['handle','tagline','bio','specialties','visibility'])if(form.elements.namedItem(key))form.elements.namedItem(key).value=p[key]|| (key==='visibility'?'private':'')};
  (async()=>{try{const data=await request('self');csrf=data.csrf;fill(data.profile);form.hidden=false;report('Your social profile is private until you change its visibility.')}catch(e){report(e.message,true)}})();
  for(const [id,kind] of [['social-remove-avatar','avatar'],['social-remove-cover','cover']])$(id)?.addEventListener('click',async()=>{if(!confirm('Remove this '+(kind==='avatar'?'profile picture':'cover image')+'?'))return;const button=$(id);button.disabled=true;report('Removing image…');try{const r=await request('removeImage',{type:kind});fill(r.profile);report('Image removed from your social profile.')}catch(e){report(e.message,true)}finally{button.disabled=false}});
  form.addEventListener('submit',async e=>{
    e.preventDefault();const button=form.querySelector('button[type=submit]');button.disabled=true;report('Saving profile…');
    try{const body={revision};for(const key of ['handle','tagline','bio','specialties','visibility'])body[key]=form.elements.namedItem(key).value.trim();const data=await request('save',body);fill(data.profile);report('Social profile saved. Your changes appear on My profile.');}
    catch(error){report(error.message,true)}finally{button.disabled=false}
  });
}
