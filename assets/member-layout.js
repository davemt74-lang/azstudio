/* One responsive sidebar toggle, located in the universal header. */
(() => {
 'use strict';
 const header=document.getElementById('azh-global-header');
 if(!header || header.dataset.authenticated!=='true') return;
 const toggle=document.getElementById('azh-member-menu-button');
 const layout=document.getElementById('azh-member-layout');
 const floating=document.querySelector('.azh-floating-sidebar');
 if(!toggle || (!layout && !floating)) return;
 const narrow=()=>window.matchMedia('(max-width:850px)').matches;
 let currentOpen=layout?!narrow():false;
 const sync=()=>{
   if(layout) layout.dataset.sideOpen=String(currentOpen);
   if(floating) floating.hidden=!currentOpen;
   toggle.setAttribute('aria-expanded',String(currentOpen));
   toggle.setAttribute('aria-label',currentOpen?'Close sidebar navigation':'Open sidebar navigation');
 };
 sync();
 toggle.addEventListener('click',()=>{if(!narrow())return;currentOpen=!currentOpen;sync();});
 document.addEventListener('keydown',event=>{if(event.key==='Escape'&&currentOpen){currentOpen=false;sync();toggle.focus();}});
 document.addEventListener('click',event=>{
   if(floating&&!floating.hidden&&!floating.contains(event.target)&&!toggle.contains(event.target)){currentOpen=false;sync();}
 });
 if(layout) {
   layout.querySelectorAll('.azh-side-link').forEach(link=>link.addEventListener('click',()=>{
     if(narrow()) {currentOpen=false;sync();}
   }));
   let wasNarrow=narrow();
   window.addEventListener('resize',()=>{
     const now=narrow();if(now!==wasNarrow){wasNarrow=now;currentOpen=!now;sync();}
   });
 }
})();
