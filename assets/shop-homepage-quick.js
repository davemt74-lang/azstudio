/** Explicit admin-only ecommerce visibility and sample preview toggles. */
const root=document.getElementById('azh-shop-homepage-quick');
if(root){
 const $=id=>document.getElementById(id);let state=null,busy=false;
 const status=(s,err=false)=>{$('azh-shop-quick-status').textContent=s;$('azh-shop-quick-status').dataset.error=err?'true':'false';};
 async function api(action,body=null){
  const endpoint=new URL('shop-homepage-api.php',document.baseURI);endpoint.searchParams.set('action',action);
  const res=await fetch(endpoint,{credentials:'same-origin',cache:'no-store',method:body?'POST':'GET',headers:body?{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf}:{},...(body?{body:JSON.stringify(body)}:{})});
  const result=await res.json().catch(()=>null);if(!res.ok||!result?.ok)throw Error(result?.error?.message||'Store setting unavailable.');return result.data;
 }
 const draw=()=>{if(!state)return;
  for(const key of ['storeEnabled','sampleEnabled']){const btn=$('azh-toggle-'+key);btn.textContent=(state.published[key]?'Disable ':'Enable ')+(key==='storeEnabled'?'Ecommerce':'Sample Data');btn.dataset.enabled=state.published[key]?'true':'false';}
  status('Store: '+(state.published.storeEnabled?'enabled':'disabled')+' · Sample catalog: '+(state.published.sampleEnabled?'enabled':'disabled')+'.');
 };
 const refresh=async()=>{state=await api('state');draw();};
 for(const key of ['storeEnabled','sampleEnabled'])$('azh-toggle-'+key).addEventListener('click',async()=>{
  if(busy||!state)return;const enabled=!state.published[key];
  if(key==='storeEnabled'&&!enabled&&!confirm('Disable the public storefront? Existing products and orders will remain saved.'))return;
  busy=true;for(const b of root.querySelectorAll('button'))b.disabled=true;
  try{await api('toggle',{key,enabled,revision:state.revision});await refresh();}
  catch(e){status(e.message,true);}finally{busy=false;for(const b of root.querySelectorAll('button'))b.disabled=false;}
 });
 refresh().catch(e=>status(e.message,true));
}
