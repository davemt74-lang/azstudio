/* Admin Command Center — explicit, CSRF-protected follow-up check. */
const check=document.getElementById('azd-management-check');
const host=document.getElementById('azh-admin-command');
const status=document.getElementById('azd-management-status');
if(check&&host&&status){
  check.addEventListener('click',async()=>{
    if(check.disabled)return;
    if(!window.confirm('Check for age-based casting and video review reminders? This may add in-app Agent notifications but will not approve applicants, publish videos or move money.'))return;
    check.disabled=true;
    status.textContent='Checking existing casting and video workflow records…';
    try{
      const url=new URL('agent-workspace-api.php',document.baseURI);
      url.searchParams.set('action','checkManagement');
      const response=await fetch(url,{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':host.dataset.csrf||''},
        body:'{}'
      });
      const json=await response.json().catch(()=>null);
      if(!response.ok||!json?.ok)throw new Error(json?.error?.message||'Management follow-up check failed.');
      const r=json.data||{};
      status.textContent='Checked '+Number(r.checked||0)+' records. '+Number(r.delivered||0)+' new Agent reminders delivered.'+
        (r.truncated?' Additional items remain in the full workspaces.':'')+
        ' This did not change applicant, video, or financial status.';
    }catch(error){status.textContent=error?.message||'Unable to check follow-ups. Review the existing workflow pages.';}
    finally{check.disabled=false;}
  });
}
