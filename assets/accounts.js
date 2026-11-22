import { accountApi, setAccountsCsrf } from './accounts-api.js';

const $ = id => document.getElementById(id);
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const labels = {admin:'Administrator',editor:'Live editor',model:'Model',fan:'Fan',customer:'Customer',professional:'Professional'};
const descriptions = {admin:'Manage the studio and all accounts',editor:'Direct approved live cameras and program broadcasts',model:'Join shoots, classes, and appointments',fan:'Follow AZHottie and attend eligible events',customer:'Book services and attend eligible events',professional:'Offer appointment types and manage your schedule'};
const page = document.body.dataset.page;
let viewer, modelProfile, accounts = [], modelProfiles = [], selected, busy = false, profileTouched = false;

function message(text, error = false) { const el = $('accounts-message'); el.hidden = false; el.className = `account-message${error ? ' is-error' : ''}`; el.textContent = text; }
function fail(error) { console.error(error); message(error.message || String(error), true); }
function on(id, event, fn) { $(id)?.addEventListener(event, e => { try { Promise.resolve(fn(e)).catch(fail); } catch (error) { fail(error); } }); }
function badges(roles) { return roles.map(role => `<span class="badge role-badge">${esc(labels[role] || role)}</span>`).join(''); }
async function withBusy(form, fn) {
  if (busy) return; busy = true;
  const controls = [...form.querySelectorAll('button')]; const states = controls.map(b => b.disabled); controls.forEach(b => b.disabled = true);
  try { return await fn(); } finally { busy = false; controls.forEach((b,i) => b.disabled = states[i]); }
}
function renderMember(fill = true) {
  $('member-name-heading').textContent = viewer.name; $('member-email').textContent = viewer.email; $('member-roles').innerHTML = badges(viewer.roles);
  $('member-pending').hidden = !viewer.requestedRoles.length;
  $('member-pending').textContent = `Waiting for approval: ${viewer.requestedRoles.map(r => labels[r]).join(', ')}.`;
  const options = ['model','professional'].filter(role => !viewer.roles.includes(role) && !viewer.requestedRoles.includes(role));
  $('request-role-options').innerHTML = options.length ? options.map(role => `<label class="account-check"><input type="checkbox" name="requestedRole" value="${role}"><span><strong>${labels[role]}</strong><small>${descriptions[role]}</small></span></label>`).join('') : '<p class="field-help">Your team roles are active or awaiting review.</p>';
  $('request-role-submit').hidden = !options.length;
  $('member-model-fields').hidden = !modelProfile; $('member-model-unlinked').hidden = !viewer.roles.includes('model') || !!modelProfile;
  if (fill) {
    $('member-name').value = viewer.name;
    if (modelProfile) for (const [id,key] of [['member-stage-name','stageName'],['member-bio','publicBio'],['member-social','socialHandles'],['member-interests','interests'],['member-availability','availability']]) $(id).value = modelProfile[key] || '';
  }
  $('member-content').hidden = false;
}
function initMember() {
  renderMember();
  on('member-form','submit', event => {event.preventDefault(); return withBusy(event.currentTarget, async () => {
    const body = {name:$('member-name').value.trim(),revision:viewer.revision};
    if (modelProfile) body.modelProfile = {revision:modelProfile.revision,stageName:$('member-stage-name').value.trim(),publicBio:$('member-bio').value.trim(),socialHandles:$('member-social').value.trim(),interests:$('member-interests').value.trim(),availability:$('member-availability').value.trim()};
    const saved = await accountApi('updateSelf',body); viewer = saved.user; modelProfile = saved.modelProfile; renderMember(); message('Your profile has been saved.');
  });});
  on('request-role-form','submit',event => {event.preventDefault(); return withBusy(event.currentTarget, async () => {
    const roles = [...document.querySelectorAll('[name=requestedRole]:checked')].map(input => input.value);
    if (!roles.length) throw new Error('Choose at least one role to request.');
    viewer = await accountApi('requestRoles',{roles}); renderMember(false); message('Your role request is waiting for administrator approval.');
  });});
  on('password-form','submit',event => {event.preventDefault(); const form = event.currentTarget; return withBusy(form, async () => {
    const next = $('new-password').value;
    if (next !== $('confirm-password').value) throw new Error('The new passwords do not match.');
    const saved = await accountApi('changePassword',{currentPassword:$('current-password').value,newPassword:next}); viewer = saved.user; setAccountsCsrf(saved.csrf); renderMember(false); form.reset(); message('Password updated. Your other sessions are signed out.');
  });});
}
function renderAccounts() {
  const search = $('account-search').value.trim().toLowerCase(), role = $('account-role-filter').value, status = $('account-status-filter').value;
  const visible = accounts.filter(user => (!search || `${user.name} ${user.email}`.toLowerCase().includes(search)) && (status === 'all' || user.status === status) && (role === 'all' || role === 'pending' ? role !== 'pending' || user.requestedRoles.length : user.roles.includes(role)));
  $('account-count').textContent = `${visible.length} account${visible.length === 1 ? '' : 's'}`;
  $('account-list').innerHTML = visible.length ? visible.map(user => `<article class="panel account-card"><div class="account-card-heading"><div><h2>${esc(user.name)}</h2><p class="field-help">${esc(user.email)}</p></div><span class="badge${user.status === 'suspended' ? ' badge--private' : ''}">${user.status === 'active' ? 'Active' : 'Suspended'}</span></div><div class="role-badges">${badges(user.roles)}</div>${user.requestedRoles.length ? `<p class="account-pending">Requested: ${esc(user.requestedRoles.map(r => labels[r]).join(', '))}</p>` : ''}${user.modelProfileLinked ? '<p class="field-help">Model profile linked</p>' : ''}<div class="action-group"><button type="button" class="button" data-open-account="${user.id}">Manage account</button><a class="button button--quiet" href="user.php?id=${user.id}">View social profile</a></div></article>`).join('') : '<div class="panel account-empty"><h2>No matching accounts</h2><p class="field-help">Try another filter, or share the sign-up link with your next member.</p></div>';
}
async function refreshAccounts() { [accounts, modelProfiles] = await Promise.all([accountApi('accounts'), accountApi('modelProfiles')]); renderAccounts(); }
function roleCheckboxes() { return [...document.querySelectorAll('[name=accountRole]:checked')].map(input => input.value); }
function refreshModelControls() { $('admin-model-link').hidden = !roleCheckboxes().includes('model'); $('admin-profile-select').disabled = $('admin-create-profile').checked; }
function showAccount(user, changeUrl = true) {
  selected = user; profileTouched = false;
  $('account-directory').hidden = true; $('account-detail').hidden = false;
  $('admin-account-heading').textContent = user.name; $('admin-account-email').textContent = user.email; $('admin-account-name').value = user.name; $('admin-account-status').value = user.status; $('admin-account-revision').textContent = `Revision ${user.revision}`;
  $('admin-account-roles').innerHTML = Object.entries(labels).map(([role,label]) => `<label class="account-check"><input type="checkbox" name="accountRole" value="${role}" ${user.roles.includes(role)?'checked':''}><span><strong>${label}</strong><small>${descriptions[role]}${user.requestedRoles.includes(role)?' · Requested':''}</small></span></label>`).join('');
  $('admin-account-pending').hidden = !user.requestedRoles.length; $('admin-account-pending').textContent = `Awaiting review: ${user.requestedRoles.map(r=>labels[r]).join(', ')}.`;
  $('admin-approve-requests').hidden = $('admin-decline-requests').hidden = !user.requestedRoles.length;
  $('admin-create-profile').checked = false;
  $('admin-profile-select').innerHTML = '<option value="">No linked profile</option>' + modelProfiles.filter(p => !p.linkedUserId || p.linkedUserId === user.id).map(p => `<option value="${esc(p.id)}" ${p.id===user.modelProfileId?'selected':''}>${esc(p.stageName)}${p.status==='archived'?' · Archived':''}</option>`).join('');
  $('admin-other-link').hidden = !user.modelProfileLinked || !!user.modelProfileId;
  refreshModelControls();
  if (changeUrl) { const url = new URL(location.href); url.searchParams.set('id',user.id); history.replaceState(null,'',url); }
  $('account-detail').scrollIntoView({behavior:'smooth',block:'start'});
}
async function saveAdmin(mode = 'save') {
  return withBusy($('admin-account-form'), async () => {
    let roles = roleCheckboxes();
    if (mode === 'approve') roles = [...new Set([...roles,...selected.requestedRoles])];
    if (mode === 'decline') roles = roles.filter(role => selected.roles.includes(role) || !selected.requestedRoles.includes(role));
    const body = {id:selected.id,revision:selected.revision,name:$('admin-account-name').value.trim(),roles,status:$('admin-account-status').value,declineRequested:mode==='decline'};
    if (roles.includes('model') && (profileTouched || !selected.modelProfileLinked || selected.modelProfileId)) {
      body.modelProfileId = $('admin-profile-select').value || null; body.createModelProfile = $('admin-create-profile').checked;
    }
    const saved = await accountApi('updateAccount',body);
    if (saved.id === viewer.id && (saved.status !== 'active' || !saved.roles.includes('admin'))) { location.href = saved.status === 'active' ? 'calendar.php' : 'login.php'; return; }
    await refreshAccounts(); showAccount(saved); message(mode === 'approve' ? 'Requested roles approved.' : mode === 'decline' ? 'Pending role requests declined.' : 'Account saved.');
  });
}
async function initAdmin() {
  await refreshAccounts(); $('account-directory').hidden = false;
  const initial = new URL(location.href).searchParams.get('id');
  if (initial && /^\d+$/.test(initial)) showAccount(await accountApi('account',null,{id:initial}),false);
  on('account-search','input',renderAccounts); on('account-role-filter','change',renderAccounts); on('account-status-filter','change',renderAccounts);
  on('account-list','click',async event => {const button=event.target.closest('[data-open-account]');if(button)showAccount(await accountApi('account',null,{id:button.dataset.openAccount}));});
  on('accounts-back','click',() => {if(busy)return;selected=null;$('account-detail').hidden=true;$('account-directory').hidden=false;const url=new URL(location.href);url.searchParams.delete('id');history.replaceState(null,'',url);});
  on('admin-account-roles','change',refreshModelControls);
  on('admin-profile-select','change',()=>{profileTouched=true;});
  on('admin-create-profile','change',()=>{profileTouched=true;refreshModelControls();});
  on('admin-account-form','submit',event=>{event.preventDefault();return saveAdmin();});
  on('admin-approve-requests','click',()=>saveAdmin('approve'));
  on('admin-decline-requests','click',()=>saveAdmin('decline'));
  on('copy-signup-link','click',async()=>{const url=new URL('signup.php',location.href).href;try{await navigator.clipboard.writeText(url);message('Sign-up link copied. Share it with the person joining your community.');}catch{message(`Sign-up link: ${url}`);}});
}

async function init() {
  const boot = await accountApi('bootstrap'); viewer=boot.user;modelProfile=boot.modelProfile;setAccountsCsrf(boot.csrf);
  if(page==='accounts'){if(!boot.canManageAccounts)throw new Error('Administrator access is required.');await initAdmin();}else initMember();
  $('accounts-loading').hidden=true;
}
init().catch(error=>{$('accounts-loading').hidden=true;fail(error);});
