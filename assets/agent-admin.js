(() => {
  'use strict';
  const root=document.getElementById('azh-agent-admin');if(!root)return;
  const form=root.querySelector('form'),note=document.getElementById('azh-agent-admin-status');
  const enabled=document.getElementById('azh-agent-enable'),provider=document.getElementById('azh-agent-provider'),limit=document.getElementById('azh-agent-limit');
  let revision=null,busy=false;
  const status=(msg,error=false)=>{note.textContent=msg;note.classList.toggle('is-error',error);};
  async function api(action,body){
    const url=new URL('agent-chat-api.php',location.href);url.searchParams.set('action',action);
    const options={credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
    if(body){options.method='POST';options.headers['Content-Type']='application/json';options.headers['X-CSRF-Token']=root.dataset.csrf;options.body=JSON.stringify(body);}
    const response=await fetch(url,options);const data=await response.json();
    if(!response.ok||!data.ok)throw new Error(data.error?.message||'Could not save chat settings.');
    return data.data;
  }
  async function load(){try{const saved=await api('settings');revision=saved.revision;enabled.checked=saved.enabled;provider.value=saved.provider;limit.value=saved.dailyLimit;form.hidden=false;status(saved.available?'Agent chat is enabled and connected.':'Agent chat is disabled or its provider needs a saved key and model.');}catch(e){status(e.message,true);}}
  form.addEventListener('submit',async e=>{e.preventDefault();if(busy||revision===null)return;busy=true;status('Saving member chat settings…');
    try{const saved=await api('saveSettings',{revision,enabled:enabled.checked,provider:provider.value,dailyLimit:Number(limit.value)});revision=saved.revision;status(saved.available?'Member chat is enabled.':'Member chat settings saved. Chat is currently off.');}
    catch(error){status(error.message,true);if(/changed/i.test(error.message))void load();}finally{busy=false;}
  });
  void load();
})();
