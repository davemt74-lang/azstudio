/* v1.45 Admin Operations Learning: evidence-backed READ-ONLY analytics.
 * No task mutation, provider action, applicant screening or model training.
 */
const root=document.getElementById('azd-ops-learning');
if(root){
  const period=root.querySelector('[data-ops-period]');
  const refresh=root.querySelector('[data-ops-refresh]');
  const status=root.querySelector('[data-ops-status]');
  const results=root.querySelector('[data-ops-results]');
  const validDays=new Set(['7','30','90']);
  const create=(tag,text='',cls='')=>{
    const node=document.createElement(tag);
    node.textContent=String(text??'');
    if(cls)node.className=cls;
    return node;
  };
  const add=(parent,tag,text='',cls='')=>{const node=create(tag,text,cls);parent.append(node);return node;};
  const safeHref=href=>typeof href==='string'&&[
    'admin-studio.php','commerce.php','casting.php','user-submissions.php','productions.php'
  ].includes(href)?href:null;
  const n=value=>Number.isFinite(Number(value))?Math.max(0,Number(value)):0;
  const fmt=value=>new Intl.NumberFormat(undefined,{maximumFractionDigits:1}).format(n(value));
  const bucketed=(trend,size)=>{
    const rows=[];
    for(let i=0;i<trend.length;i+=size){
      const slice=trend.slice(i,i+size);
      rows.push({
        day:slice[0]?.day||'',
        created:slice.reduce((t,r)=>t+n(r.created),0),
        resolvedEvents:slice.reduce((t,r)=>t+n(r.resolvedEvents),0),
        reopenedEvents:slice.reduce((t,r)=>t+n(r.reopenedEvents),0)
      });
    }
    return rows;
  };
  function render(data){
    results.replaceChildren();
    const totals=data.totals||{};
    const cards=add(results,'div','','azd-ops-stats');
    const card=(label,value,detail)=>{
      const node=add(cards,'article','','azd-ops-stat');
      add(node,'span',label);
      add(node,'strong',value);
      add(node,'small',detail);
    };
    card('New tracked tasks',fmt(totals.created),'Created during the selected period');
    card('Resolved tasks',fmt(totals.resolvedTasks),'Distinct tasks with a resolution event');
    card('Median resolution time',totals.medianResolutionHours===null?'—':fmt(totals.medianResolutionHours)+'h',
      'Task creation to first resolution event observed in this period');
    card('Reopened tasks',fmt(totals.reopenedTasks),'Distinct tasks with a reopening event');
    card('Overdue now',fmt(totals.overdueNow),'Open or acknowledged tasks past their due dates');
    card('New-task cohort resolved',totals.cohortResolvedPct===null?'—':fmt(totals.cohortResolvedPct)+'%',
      'Currently resolved share of tasks created during this period');
    const band=add(results,'p','','azd-ops-context');
    band.textContent=fmt(totals.resolvedEvents)+' resolution events · '+fmt(totals.reopenEvents)+
      ' reopen events · '+fmt(totals.acknowledgmentEvents)+' acknowledgments in this period';
    if(totals.sampleTruncated){
      add(results,'p','Resolution-time statistics use a capped sample of 5,000 history events. Review the full audit for complete results.','azd-ops-warning');
    }
    const title=add(results,'div','','azd-ops-subhead');
    add(title,'h3','Operations activity trend');
    add(title,'span',(Number(data.periodDays)||30)+'-day period · UTC');
    const trend=Array.isArray(data.trend)?data.trend:[];
    if(trend.length){
      const weeks=Number(data.periodDays)>7;
      const bars=bucketed(trend,weeks?7:1);
      const peak=Math.max(1,...bars.flatMap(r=>[r.created,r.resolvedEvents]));
      const legend=add(results,'p','New tasks · Resolution events','azd-ops-legend');
      legend.setAttribute('aria-label','The bar chart compares task creation and resolution events, which are not the same task cohort.');
      const chart=add(results,'div','','azd-ops-chart');
      chart.setAttribute('role','img');
      chart.setAttribute('aria-label','Activity chart, '+(weeks?'weekly groups':'daily groups')+
        '. '+bars.map(r=>r.day+': '+r.created+' created, '+r.resolvedEvents+' resolutions').join('; '));
      chart.style.gridTemplateColumns='repeat('+bars.length+',minmax(0,1fr))';
      for(const row of bars){
        const col=add(chart,'div','','azd-ops-chart-day');
        const barContainer=add(col,'div','','azd-ops-chart-bars');
        const created=add(barContainer,'i','','azd-ops-created');
        created.style.height=(row.created/peak*100)+'%';
        const resolved=add(barContainer,'i','','azd-ops-resolved');
        resolved.style.height=(row.resolvedEvents/peak*100)+'%';
        const day=add(col,'small',row.day.slice(5),'azd-ops-chart-date');
        day.title=row.day+': '+row.created+' created, '+row.resolvedEvents+
          ' resolved events, '+row.reopenedEvents+' reopen events';
      }
      const trendDetail=add(results,'details','','azd-ops-trend-details');
      add(trendDetail,'summary','Review daily counts');
      const details=add(trendDetail,'div','','azd-ops-daily-list');
      for(const day of trend){
        const item=add(details,'p','','azd-ops-daily');
        add(item,'strong',day.day);
        add(item,'span',fmt(day.created)+' new · '+fmt(day.resolvedEvents)+' resolutions · '+fmt(day.reopenedEvents)+' reopens');
      }
    }else add(results,'p','No recorded activity dates were available.','azd-empty');
    const grids=add(results,'div','','azd-ops-columns');
    const recommendations=add(grids,'section','','azd-ops-section');
    add(recommendations,'h3','Evidence-backed recommendations');
    const recommendationsList=add(recommendations,'div','','azd-ops-recommendations');
    if(!Array.isArray(data.recommendations)||!data.recommendations.length){
      add(recommendationsList,'p','No recommendation threshold was reached. Continue watching recorded activity; no problems have been inferred.','azd-empty');
    }else for(const rec of data.recommendations){
      const item=add(recommendationsList,'article','','azd-ops-recommendation');
      add(item,'strong',rec.title);
      add(item,'p',rec.evidence,'azd-ops-evidence');
      add(item,'p',rec.nextStep);
      const href=safeHref(rec.href);
      if(href){const link=add(item,'a','Review original records ↗');link.href=href;}
    }
    const patterns=add(grids,'section','','azd-ops-section');
    add(patterns,'h3','Recurring workflow categories');
    const pList=add(patterns,'div','','azd-ops-patterns');
    if(!Array.isArray(data.patterns)||!data.patterns.length){
      add(pList,'p','Not enough recorded tasks for a pattern comparison.','azd-empty');
    }else for(const pattern of data.patterns){
      const item=add(pList,'div','','azd-ops-pattern');
      add(item,'strong',pattern.label);
      add(item,'small',fmt(pattern.count)+' created · '+fmt(pattern.active)+' still active');
    }
    const next=add(results,'p','','azd-ops-next');
    add(next,'strong','Recommended next step');
    add(next,'span',data.nextStep||'Review current Action Center records.');
    add(results,'p',data.basis||'Recorded workflow events only.','azd-disclaimer');
    status.textContent='Report refreshed from staff-only workflow records. No actions were performed.';
  }
  let loading=false;
  async function load(){
    if(loading)return;
    loading=true;refresh.disabled=true;period.disabled=true;status.textContent='Checking recorded operational history…';
    try{
      const days=validDays.has(period.value)?period.value:'30';
      const url=new URL('agent-workspace-api.php',document.baseURI);
      url.searchParams.set('action','operationsLearning');
      url.searchParams.set('days',days);
      const res=await fetch(url,{method:'GET',credentials:'same-origin',cache:'no-store',
        headers:{Accept:'application/json'}});
      const payload=await res.json().catch(()=>null);
      if(!res.ok||!payload?.ok)throw new Error(payload?.error?.message||'Could not load operations history.');
      render(payload.data||{});
    }catch(error){
      results.replaceChildren();
      status.textContent=error?.message||'Operations learning report is temporarily unavailable.';
    }finally{refresh.disabled=false;period.disabled=false;loading=false;}
  }
  refresh.addEventListener('click',()=>void load());
  period.addEventListener('change',()=>void load());
  void load();
}
