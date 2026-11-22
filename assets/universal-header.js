(() => {
 'use strict';
 const header=document.getElementById('azh-global-header');
 if(!header || header.dataset.initialized==='true')return;
 header.dataset.initialized='true';
 if(header.dataset.authenticated==='true'){
   const menu=header.querySelector('#azh-account-menu');
   document.addEventListener('click',event=>{if(menu&&!menu.contains(event.target))menu.open=false});
   document.addEventListener('keydown',event=>{if(event.key==='Escape'&&menu)menu.open=false});
   // The shared sidebar script is also used on public pages viewed while logged in.
   if(document.querySelector('.azh-floating-sidebar')){
     const script=document.createElement('script');script.src='assets/member-layout.js';script.defer=true;document.head.append(script);
   }
   return;
 }
 const toggle=header.querySelector('.azh-global-header__menu-toggle');
 const close=()=>{header.dataset.menuOpen='false';toggle?.setAttribute('aria-expanded','false');};
 toggle?.addEventListener('click',()=>{const open=header.dataset.menuOpen!=='true';header.dataset.menuOpen=String(open);toggle.setAttribute('aria-expanded',String(open));});
 document.addEventListener('keydown',event=>{if(event.key==='Escape')close();});
 document.addEventListener('click',event=>{if(!header.contains(event.target))close();});
 window.addEventListener('resize',()=>{if(window.innerWidth>1240)close();});
})();
