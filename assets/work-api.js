let csrf='';
export function setWorkCsrf(value){csrf=value||'';}
export function workPage(page,query={}){const url=new URL(page+'.php',location.href);for(const [key,value] of Object.entries(query))if(value!==undefined&&value!==null&&value!=='')url.searchParams.set(key,String(value));return url.pathname+url.search;}
async function request(endpoint,action,payload=null,query={},retry=true){
  const url=new URL(endpoint,location.href);url.searchParams.set('action',action);
  for(const [key,value] of Object.entries(query))if(value!==undefined&&value!==null&&value!=='')url.searchParams.set(key,String(value));
  const options={credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
  if(payload!==null){options.method='POST';options.headers['X-CSRF-Token']=csrf;if(payload instanceof FormData)options.body=payload;else{options.headers['Content-Type']='application/json';options.body=JSON.stringify(payload);}}
  const response=await fetch(url,options);let result;
  try{result=await response.json();}catch{throw new Error('The server could not return this production. Check the hosting PHP error log.');}
  if(!response.ok||result.ok===false){
    if(retry&&payload!==null&&result.error?.code==='csrf_invalid'){const fresh=await workApi('bootstrap');setWorkCsrf(fresh.csrf);return request(endpoint,action,payload,query,false);}
    const error=new Error(result.error?.message||'This production request could not be completed.');error.status=response.status;error.code=result.error?.code;
    if(response.status===401)error.message='Your session expired. Sign in in another tab, then retry. Your unsaved entries are still here.';
    throw error;
  }
  return result.data;
}
const pendingIntents=new Map();
export async function workApi(action,payload=null,query={}){
  let key='';
  if(payload!==null&&!(payload instanceof FormData)&&!payload.requestId){key=action+'\n'+JSON.stringify(payload);if(!pendingIntents.has(key))pendingIntents.set(key,crypto.randomUUID());payload={...payload,requestId:pendingIntents.get(key)};}
  const result=await request('work-api.php',action,payload,query);
  if(key)pendingIntents.delete(key);
  return result;
}
export const workMediaApi=(action,payload=null,query={})=>request('work-upload-api.php',action,payload,query);
