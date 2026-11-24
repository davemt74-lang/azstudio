/** v1.56: presentation only. Product checkout still uses existing verified commerce modules. */
const shop=document.getElementById('azh-shop');
if(shop){
  const items=[...shop.querySelectorAll('[data-shop-product]')];
  const grid=shop.querySelector('#azh-shop-products');
  const query=shop.querySelector('#azh-shop-query');
  const sort=shop.querySelector('#azh-shop-sort');
  const count=shop.querySelector('#azh-shop-count');
  const empty=shop.querySelector('#azh-shop-no-matches');
  let current='all';
  const normalize=s=>String(s||'').toLocaleLowerCase().normalize('NFKC').trim();
  function update(){
    if(!grid)return;
    const search=normalize(query?.value),sortMode=sort?.value||'featured';
    const visible=items.filter(item=>{
      const matches=(current==='all'||item.dataset.category===current) && normalize(item.dataset.search).includes(search);
      item.hidden=!matches;
      return matches;
    });
    const ordered=[...items].sort((a,b)=>{
      if(sortMode==='price-low')return Number(a.dataset.price)-Number(b.dataset.price);
      if(sortMode==='price-high')return Number(b.dataset.price)-Number(a.dataset.price);
      if(sortMode==='name')return a.dataset.title.localeCompare(b.dataset.title);
      return Number(b.dataset.featured)-Number(a.dataset.featured);
    });
    for(const item of ordered)grid.append(item);
    if(count)count.textContent=visible.length+' '+(visible.length===1?'product':'products')+' shown';
    if(empty)empty.hidden=visible.length!==0 || items.length===0;
  }
  for(const button of shop.querySelectorAll('[data-shop-filter]'))button.addEventListener('click',()=>{
    current=button.dataset.shopFilter;
    for(const chip of shop.querySelectorAll('[data-shop-filter]')){
      const selected=chip===button;chip.classList.toggle('is-current',selected);chip.setAttribute('aria-pressed',selected?'true':'false');
    }
    update();
  });
  query?.addEventListener('input',update);
  sort?.addEventListener('change',update);
  update();
  const currentImage=document.getElementById('azh-shop-current-image');
  for(const button of shop.querySelectorAll('[data-gallery-src]'))button.addEventListener('click',()=>{
    if(!currentImage)return;
    currentImage.src=button.dataset.gallerySrc;currentImage.alt=button.dataset.galleryAlt;
    for(const other of shop.querySelectorAll('[data-gallery-src]'))other.setAttribute('aria-pressed',other===button?'true':'false');
  });
}
