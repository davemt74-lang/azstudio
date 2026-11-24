/** Shared Shop 2.2 preview: accepts only the authenticated editor's own frame. */
(() => {
  'use strict';
  if(document.body.dataset.shopPreview!=='1')return;
  const embedded=window.parent!==window;
  let root=document.getElementById('azh-shop'),heightFrame=0,lastHeight=0;
  if(!root)return;
  if(embedded){
    document.documentElement.style.overflow='hidden';
    document.body.style.overflow='hidden';
    const agentBell=document.getElementById('azh-aw-bell');
    if(agentBell){agentBell.disabled=true;agentBell.title='Agent actions are unavailable in the design preview';}
    const agentDrawer=document.getElementById('azh-aw-drawer');
    if(agentDrawer)agentDrawer.inert=true;
  }
  function reportHeight(){
    if(!embedded||location.origin==='null')return;
    cancelAnimationFrame(heightFrame);
    heightFrame=requestAnimationFrame(()=>{
      // scrollHeight is at least the old viewport height and cannot shrink.
      const height=Math.max(1,Math.ceil(root.getBoundingClientRect().bottom+scrollY));
      if(height!==lastHeight){
        lastHeight=height;
        window.parent.postMessage({type:'azh-shop-preview-height',height},location.origin);
      }
    });
  }
  const observer=typeof ResizeObserver==='function'?new ResizeObserver(reportHeight):null;
  function observeRoot(){
    observer?.disconnect();observer?.observe(root);
    reportHeight();
  }
  function renderedMain(html){
    if(typeof html!=='string'||html.length>2000000)return null;
    const template=document.createElement('template');template.innerHTML=html;
    if(template.content.children.length!==1)return null;
    const next=template.content.firstElementChild;
    if(!next?.matches('main#azh-shop.azh-shop'))return null;
    // Server-rendered markup is escaped. Disallow executable content even for
    // an unexpected same-origin parent message.
    next.querySelectorAll('script,iframe,object,embed,link,style,base,meta').forEach(node=>node.remove());
    for(const node of [next,...next.querySelectorAll('*')]){
      for(const attribute of [...node.attributes]){
        if(/^on/i.test(attribute.name)||attribute.name==='srcdoc')node.removeAttribute(attribute.name);
        if(/^(href|src|action|formaction|xlink:href)$/i.test(attribute.name)&&/^\s*(javascript|vbscript|data):/i.test(attribute.value))node.removeAttribute(attribute.name);
      }
    }
    return next;
  }
  window.addEventListener('message',event=>{
    if(!embedded||event.origin!==location.origin||event.source!==window.parent)return;
    if(event.data?.type==='azh-shop-render-preview'){
      const next=renderedMain(event.data.html);if(!next)return;
      root.replaceWith(next);root=next;
      document.dispatchEvent(new Event('azh-shop-rendered'));
      observeRoot();
      window.parent.postMessage({type:'azh-shop-preview-applied',seq:event.data.seq??null},location.origin);
    }else if(event.data?.type==='azh-shop-preview-measure'){
      lastHeight=0;reportHeight();
    }
  });
  // A design preview stays on the draft. Category and CTA anchors remain
  // interactive; checkout and navigation require the published storefront.
  document.addEventListener('click',event=>{
    const anchor=event.target.closest?.('a[href]');
    if(anchor&&(embedded||root.contains(anchor))&&!anchor.getAttribute('href').startsWith('#'))event.preventDefault();
  },true);
  document.addEventListener('submit',event=>{
    if(embedded||root.contains(event.target)){event.preventDefault();if(embedded)event.stopImmediatePropagation();}
  },true);
  document.addEventListener('load',reportHeight,true);
  window.addEventListener('resize',reportHeight);
  document.fonts?.ready.then(reportHeight);
  observeRoot();
  if(embedded&&location.origin!=='null')window.parent.postMessage({type:'azh-shop-preview-ready'},location.origin);
})();
