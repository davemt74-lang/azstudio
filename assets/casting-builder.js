/* AZHottie public casting questionnaire editor: question and answer CRUD, order and validation. */
(()=>{'use strict';
 const root=document.getElementById('az-question-editor');if(!root)return;
 const list=document.getElementById('az-question-editor-list');const error=document.getElementById('az-builder-error');
 let questions=[];
 try{questions=JSON.parse(document.getElementById('az-question-editor-data').textContent);}catch(e){error.textContent='The questions could not be loaded.';error.hidden=false;return;}
 const types={single:'Select one answer',multiple:'Select multiple answers',short:'Short written answer',long:'Long written answer'};
 const el=(tag,cls='',text='')=>{const v=document.createElement(tag);if(cls)v.className=cls;if(text)v.textContent=text;return v;};
 const input=(label,value,field,placeholder='',max=300)=>{const wrap=el('label','az-builder-field');wrap.append(el('span','',label));const ctrl=el('input');ctrl.value=value;ctrl.type='text';ctrl.maxLength=max;ctrl.dataset.field=field;ctrl.placeholder=placeholder;wrap.append(ctrl);return wrap;};
 const btn=(title,action,i,option=null)=>{const b=el('button','az-builder-mini',title);b.type='button';b.dataset.action=action;b.dataset.i=String(i);if(option!==null)b.dataset.o=String(option);return b;};
 const questionCard=(q,i)=>{
  const card=el('article','az-builder-card');card.dataset.i=String(i);
  const head=el('div','az-builder-card-header'),title=el('div','az-builder-card-id');title.append(el('span','',`QUESTION ${String(i+1).padStart(2,'0')}`));head.append(title);
  const tools=el('div','az-builder-tools');tools.append(btn('↑','up',i),btn('↓','down',i),btn('Delete question','delete',i));head.append(tools);card.append(head);
  card.append(input('Question text',q.title,'title','Write a question…',300));
  const meta=el('div','az-builder-meta');
  const ty=el('label','az-builder-field');ty.append(el('span','','Answer type'));const select=el('select');select.dataset.field='type';
  for(const [val,label] of Object.entries(types)){const opt=el('option','',label);opt.value=val;opt.selected=val===q.type;select.append(opt);}ty.append(select);meta.append(ty);
  const required=el('label','az-builder-required'),check=el('input');check.type='checkbox';check.checked=!!q.required;check.dataset.field='required';required.append(check,document.createTextNode('Required answer'));meta.append(required);card.append(meta);
  card.append(input('Optional instruction or hint',q.help||'','help','Help the applicant understand this question…',500));
  if(['single','multiple'].includes(q.type)){
   const options=el('div','az-builder-answers');options.append(el('strong','','Answer choices'));
   q.options.forEach((value,k)=>{
    const row=el('div','az-builder-answer-row');const control=el('input');control.value=value;control.type='text';control.maxLength=180;control.placeholder=`Answer ${k+1}`;control.dataset.field='option';control.dataset.o=String(k);row.append(el('span','az-option-number',String(k+1)),control,btn('Remove','remove-option',i,k));options.append(row);
   });
   const add=btn('+ Add answer choice','add-option',i);add.classList.add('az-add-answer');options.append(add);card.append(options);
  }else{const note=el('p','az-builder-help','The applicant will type an answer. No choice list is needed.');card.append(note);}
  return card;
 };
 const render=()=>{list.replaceChildren(...questions.map(questionCard));document.getElementById('az-editor-count').textContent=questions.length+' editable questions';};
 const report=msg=>{error.textContent=msg;error.hidden=false;};
 const clear=()=>{error.hidden=true;error.textContent='';};
 const update=(event)=>{const ctrl=event.target;if(!ctrl.dataset.field)return;const card=ctrl.closest('.az-builder-card');if(!card)return;
  const q=questions[Number(card.dataset.i)];if(!q)return;
  const field=ctrl.dataset.field;if(field==='option'){q.options[Number(ctrl.dataset.o)]=ctrl.value;}
  else if(field==='required'){q.required=ctrl.checked;}
  else if(field==='type'){q.type=ctrl.value;q.options=['single','multiple'].includes(q.type)?(q.options.length>=2?q.options:['Choice one','Choice two']):[];render();}
  else{q[field]=ctrl.value;}
 };
 list.addEventListener('input',update);list.addEventListener('change',update);
 list.addEventListener('click',event=>{const b=event.target.closest('[data-action]');if(!b)return;event.preventDefault();clear();
  const i=Number(b.dataset.i),q=questions[i];if(!q)return;
  switch(b.dataset.action){
   case 'up':if(i>0)[questions[i-1],questions[i]]=[questions[i],questions[i-1]];break;
   case 'down':if(i+1<questions.length)[questions[i+1],questions[i]]=[questions[i],questions[i+1]];break;
   case 'delete':if(questions.length<=1){report('Keep at least one question in the public application.');return;}questions.splice(i,1);break;
   case 'add-option':if(q.options.length>=12){report('Maximum 12 answer choices per question.');return;}q.options.push('');break;
   case 'remove-option':if(q.options.length<=2){report('Multiple-choice questions need at least two answers.');return;}q.options.splice(Number(b.dataset.o),1);break;
  }render();
 });
 document.getElementById('az-add-question').addEventListener('click',()=>{
  if(questions.length>=32){report('Maximum 32 questions.');return;}
  const id='question_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,7);
  questions.push({id,title:'',type:'single',required:false,help:'',options:['','']});clear();render();
  list.lastElementChild?.scrollIntoView({block:'nearest',behavior:'smooth'});
 });
 root.addEventListener('submit',event=>{
  clear();if(!questions.length||questions.length>32){report('Keep between 1 and 32 questions.');event.preventDefault();return;}
  for(let i=0;i<questions.length;i++){
   const q=questions[i];if(!q.title.trim()){report(`Question ${i+1} needs a title.`);event.preventDefault();return;}
   if(['single','multiple'].includes(q.type)){
    const opts=q.options.map(x=>x.trim());if(opts.length<2||opts.some(x=>!x)||new Set(opts).size!==opts.length){report(`Question ${i+1} needs at least two different, nonempty answer choices.`);event.preventDefault();return;}
    q.options=opts;
   }
  }
  document.getElementById('az-questions-json').value=JSON.stringify(questions);
 });
 render();
})();
