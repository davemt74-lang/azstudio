import {scheduleApi,setScheduleCsrf} from './schedule-api.js';

/** Calendar workspace: verified, authenticated read model, never a second booking authority. */
export const WEEKDAYS=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
export function weeklyFromRows(rows){
  const weekly={};for(let day=0;day<7;day++) weekly[String(day)]=[];
  for(const row of rows){if(!row.enabled)continue;
    if(!Number.isInteger(row.day)||row.day<0||row.day>6)throw new Error('Unknown weekday');
    if(!/^\d\d:\d\d$/.test(row.start)||!/^\d\d:\d\d$/.test(row.end)||row.start>=row.end)throw new Error('Finish time must follow start time.');
    weekly[String(row.day)].push({start:row.start,end:row.end});
  }
  return weekly;
}
export function utcPhoenixTime(utc){
  if(!utc)return 'Time unavailable';
  return new Intl.DateTimeFormat('en-US',{timeZone:'America/Phoenix',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date(utc));
}
export function scheduleTheme(item){return ({appointment:'Appointment',event:'Event',class:'Class',production:'Production',assignment:'Deadline'})[item.type]||'Commitment';}

function mount(){
  const region=document.getElementById('schedule-agent-panel');if(!region)return;
  const holder=document.getElementById('schedule-agent-content'),status=document.getElementById('schedule-agent-status');
  const el=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=String(text);return n;};
  const link=(label,href)=>{const n=el('a','button button--small',label);n.href=href;return n;};
  const button=(label,onClick,cls='button button--small')=>{const n=el('button',cls,label);n.type='button';n.addEventListener('click',onClick);return n;};
  const label=(caption,input)=>{const n=el('label','sa-field');n.append(el('span','',caption),input);return n;};
  const input=(type,value='')=>{const n=document.createElement('input');n.type=type;n.value=value;return n;};
  let overview=null,boot=null,loading=false;
  const note=(msg,bad=false)=>{status.textContent=msg;status.dataset.error=bad?'true':'false';};
  async function reload(){
    if(loading)return;loading=true;note('Checking current schedule…');
    try{boot=await scheduleApi('bootstrap');setScheduleCsrf(boot.csrf);overview=await scheduleApi('unified');render();note(`Verified for your account · ${overview.timezone} · ${overview.counts.upcoming} upcoming commitments`);}
    catch(e){note(e.message||'Schedule unavailable.',true);}
    finally{loading=false;}
  }
  async function mutate(action,payload,message){
    if(loading)return;loading=true;note('Saving…');
    try{await scheduleApi(action,payload);loading=false;await reload();note(message);}
    catch(e){note(e.message||'Could not save. Reload and retry.',true);}
    finally{loading=false;}
  }
  function render(){
    holder.replaceChildren();if(!overview)return;
    const head=el('div','sa-metrics');
    for(const [key,name] of [['upcoming','Upcoming'],['appointments','Appointments'],['assignments','Assignment deadlines'],['conflicts','Conflicts']]){
      const cell=el('div','sa-metric');cell.append(el('strong','',overview.counts[key]??0),el('span','',name));head.append(cell);
    }holder.append(head);
    const agenda=el('section','sa-section');agenda.append(el('h3','','Upcoming commitments'));
    if(!overview.items?.length)agenda.append(el('p','sa-muted','No upcoming registered events, bookings, or assignment deadlines were found.'));
    for(const item of (overview.items||[]).slice(0,12)){
      const row=el('article','sa-item');const desc=el('div','');desc.append(el('small','',scheduleTheme(item)+' · '+utcPhoenixTime(item.startAt)),el('strong','',item.title));
      row.append(desc,link('Open',item.href));agenda.append(row);
    }
    if((overview.items||[]).length>12)agenda.append(el('p','sa-muted','Showing the next 12 commitments. Older items remain in their source workspaces.'));
    holder.append(agenda);
    if(overview.conflicts?.length){const conflict=el('section','sa-section');conflict.append(el('h3','','Needs scheduling review'),el('p','sa-muted',`${overview.conflicts.length} pair(s) of overlapping appointments or registered events. Existing bookings are not changed automatically.`));conflict.append(link('Review appointments','appointments.php'));holder.append(conflict);}
    if(overview.availability)renderAvailability(overview.availability);
    if(boot?.canManageEvents)void renderAdminMatching();
  }
  function renderAvailability(data){
    const section=el('details','sa-section sa-availability');section.open=!data.configured;
    section.append(el('summary','',data.configured?'My model availability · Edit':'Set my model availability'));
    const help=el('p','sa-muted','Private weekly windows in Arizona time. Unconfigured availability is never assumed available. Adjustments do not cancel existing appointments.');section.append(help);
    const form=el('form','sa-form');const all=el('div','sa-week');
    function addWindow(day,window={start:'09:00',end:'17:00'}){
      const row=el('div','sa-window');row.dataset.day=String(day);const from=input('time',window.start),to=input('time',window.end);from.step='900';to.step='900';
      from.setAttribute('aria-label',`${WEEKDAYS[day]} start`);to.setAttribute('aria-label',`${WEEKDAYS[day]} end`);
      row.append(from,to,button('Remove',()=>row.remove(),'button button--quiet button--small'));return row;
    }
    WEEKDAYS.forEach((day,index)=>{const line=el('div','sa-day');line.append(el('strong','',day));const windows=el('div','sa-day-windows');for(const w of data.weekly?.[index]||[])windows.append(addWindow(index,w));
      line.append(windows,button('Add time',()=>{if(windows.children.length>=3){note('Maximum three windows per day.',true);return;}windows.append(addWindow(index));}));all.append(line);});
    form.append(all);
    form.append(button('Save weekly availability',()=>{const rows=[...form.querySelectorAll('.sa-window')].map(n=>({enabled:true,day:Number(n.dataset.day),start:n.querySelectorAll('input')[0].value,end:n.querySelectorAll('input')[1].value}));
      try{const weekly=weeklyFromRows(rows);void mutate('saveModelAvailability',{weekly,revision:data.revision},'Availability saved.');}catch(e){note(e.message,true);}
    },'button button--primary'));section.append(form);
    const blocks=el('section','sa-blocks');blocks.append(el('h4','','Unavailable dates'));
    for(const block of data.blackouts||[]){const row=el('div','sa-item');row.append(el('span','',`${utcPhoenixTime(block.startAt)} to ${utcPhoenixTime(block.endAt)} · ${block.reason}`),button('Remove',()=>void mutate('removeModelBlackout',{id:block.id},'Unavailable time removed.')));blocks.append(row);}
    const bform=el('form','sa-blackout-form');const start=input('datetime-local'),end=input('datetime-local'),reason=input('text','Unavailable');reason.maxLength=160;
    bform.append(label('From (Arizona time)',start),label('Until',end),label('Reason',reason));
    bform.append(button('Block time',()=>{if(!start.value||!end.value){note('Select both dates and times.',true);return;}void mutate('addModelBlackout',{startLocal:start.value,endLocal:end.value,reason:reason.value},'Unavailable time added.');}));
    blocks.append(bform);section.append(blocks);holder.append(section);
  }
  async function renderAdminMatching(){
    const section=el('section','sa-section');section.append(el('h3','','Production scheduling recommendations'),el('p','sa-muted','Choose a production and proposed time. Suggestions include only onboarded models already on its active roster. No bookings or assignments are changed.'));
    try{const productions=await scheduleApi('productionOptions');if(!productions?.length){section.append(el('p','sa-muted','No active productions available for scheduling.'));holder.append(section);return;}
      const form=el('form','sa-match-form');const select=el('select');for(const project of productions){const o=el('option','',project.title);o.value=project.id;select.append(o);}
      const start=input('datetime-local'),end=input('datetime-local');form.append(label('Production',select),label('Start (Arizona)',start),label('End (Arizona)',end));
      const results=el('div','sa-match-results');form.append(button('Check eligible models',async()=>{
        if(!start.value||!end.value){note('Choose the proposed production time.',true);return;}results.replaceChildren(el('p','sa-muted','Checking roster availability…'));
        try{const data=await scheduleApi('recommendModels',null,{projectId:select.value,startLocal:start.value,endLocal:end.value});results.replaceChildren();
          if(!data.models.length)results.append(el('p','sa-muted','No fully onboarded active model roster members match this production.'));
          for(const model of data.models){const row=el('div','sa-item');row.append(el('strong','',model.label),el('span','sa-availability-'+model.availability,`${model.availability.toUpperCase()} · ${model.reason}`));results.append(row);}
          results.append(link('Open production assignments','productions.php'));
        }catch(e){results.replaceChildren(el('p','sa-error',e.message||'Model availability could not be checked.'));}
      },'button button--primary'),results);section.append(form);
    }catch(e){section.append(el('p','sa-error',e.message||'Unable to load production options.'));}
    holder.append(section);
  }
  void reload();
}
if(typeof document!=='undefined')mount();
