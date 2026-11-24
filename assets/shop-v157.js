/** Shop2.1 category tiles reuse existing catalog filters, never create checkout requests. */
const root=document.getElementById('azh-shop');
if(root){
  for(const tile of root.querySelectorAll('[data-shop-category-jump]')){
    tile.addEventListener('click',()=>{
      const slug=tile.dataset.shopCategoryJump;
      const target=[...root.querySelectorAll('[data-shop-filter]')].find(el=>el.dataset.shopFilter===slug);
      if(target)target.click();
    });
  }
  // Admin draft preview receives only same-origin design events. Public pages ignore them.
  if(document.body.dataset.shopPreview==='1')window.addEventListener('message',event=>{
    if(event.origin!==location.origin||event.source!==window.parent||event.data?.type!=='azh-shop-draft-preview')return;
    const v=event.data.design;if(!v||typeof v!=='object')return;
    const set=(id,value)=>{const el=document.getElementById(id);if(el&&typeof value==='string')el.textContent=value;};
    set('azh-hero-eyebrow',v.eyebrow);set('azh-hero-heading',v.heading);set('azh-hero-accent',v.accent);
    set('azh-hero-description',v.description);set('azh-categories-title',v.categoriesTitle);set('azh-featured-title',v.featuredTitle);
    const link=document.getElementById('azh-hero-cta');if(link){link.querySelector('span').textContent=v.ctaText||'';
      if(['#shop-products','#shop-categories','#shop-browse','watch.php','creator-clubs.php'].includes(v.ctaHref))link.href=v.ctaHref;}
    const css={accentColor:'--az-accent',buttonColor:'--az-button',textColor:'--az-hero-text',backgroundColor:'--az-shop-bg'};
    for(const [key,name] of Object.entries(css))if(/^#[0-9a-fA-F]{6}$/.test(v[key]||''))root.style.setProperty(name,v[key]);
    if(Number.isInteger(v.heroHeight))root.style.setProperty('--az-hero-height',Math.max(300,Math.min(700,v.heroHeight))+'px');
    if(Number.isInteger(v.headingSize))root.style.setProperty('--az-hero-size',Math.max(35,Math.min(90,v.headingSize))+'px');
    if(['modern','editorial','classic'].includes(v.fontStyle))root.dataset.font=v.fontStyle;
    if(['left','center'].includes(v.heroAlignment))root.dataset.heroAlign=v.heroAlignment;
    for(const section of root.querySelectorAll('[data-section]')){
      if(typeof v.sections?.[section.dataset.section]==='boolean')section.hidden=!v.sections[section.dataset.section];
      if(Array.isArray(v.order))section.style.order=String(Math.max(0,v.order.indexOf(section.dataset.section)));
    }
  });
}
