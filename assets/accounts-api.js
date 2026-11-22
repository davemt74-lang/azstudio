let csrf = '';
export function setAccountsCsrf(value) { csrf = value || ''; }
export async function accountApi(action, payload = null, query = {}, refresh = true) {
  const url = new URL('accounts-api.php', location.href); url.searchParams.set('action', action);
  for (const [key, value] of Object.entries(query)) url.searchParams.set(key, value);
  const options = {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}};
  if (payload !== null) { options.method = 'POST'; options.headers['Content-Type'] = 'application/json'; options.headers['X-CSRF-Token'] = csrf; options.body = JSON.stringify(payload); }
  const response = await fetch(url, options); let result;
  try { result = await response.json(); } catch { throw new Error(`The server could not read this account request (${response.status}). Please reload and try again.`); }
  if (!response.ok || result.ok === false) {
    if (payload !== null && refresh && result.error?.code === 'csrf_invalid') { const fresh = await accountApi('bootstrap'); setAccountsCsrf(fresh.csrf); return accountApi(action, payload, query, false); }
    const error = new Error(result.error?.message || 'The account request could not be completed.'); error.status = response.status; error.code = result.error?.code; throw error;
  }
  return result.data ?? result;
}
