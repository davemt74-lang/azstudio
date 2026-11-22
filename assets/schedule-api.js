let csrf = '';
export function setScheduleCsrf(value) { csrf = value || ''; }
export function schedulePage(page, query = {}) { const url=new URL(page+'.php',location.href);for(const [k,v] of Object.entries(query))url.searchParams.set(k,String(v));return url.pathname+url.search; }
export async function scheduleApi(action, payload = null, query = {}, retry = true) {
  const url = new URL('schedule-api.php', location.href);
  url.searchParams.set('action', action);
  for (const [key, value] of Object.entries(query)) if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value));
  const options = {credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
  if (payload !== null) { options.method='POST'; options.headers['Content-Type']='application/json'; options.headers['X-CSRF-Token']=csrf; options.body=JSON.stringify(payload); }
  const response = await fetch(url,options);
  let result;
  try { result=await response.json(); } catch { throw new Error('The server could not return this schedule. Check the hosting PHP error log.'); }
  if (!response.ok || result.ok === false) {
    if (retry && payload !== null && result.error?.code === 'csrf_invalid') { const fresh=await scheduleApi('bootstrap');setScheduleCsrf(fresh.csrf);return scheduleApi(action,payload,query,false); }
    const error=new Error(result.error?.message || 'This schedule request could not be completed.');error.status=response.status;error.code=result.error?.code;
    if(response.status===401)error.message='Your session expired. Sign in in another tab, then retry this form. Your entries are still here.';
    throw error;
  }
  return result.data;
}
