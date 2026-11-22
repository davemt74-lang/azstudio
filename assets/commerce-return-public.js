const root=document.getElementById('azh-return-public');
if(root){
  const form=document.getElementById('azh-return-public-form');
  const status=document.getElementById('azh-return-public-status');
  let busy=false;
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy)return;
    busy=true;const button=form.querySelector('[type=submit]');button.disabled=true;
    status.dataset.error='false';status.textContent='Submitting request for store review…';
    try{
      const url=new URL('commerce-returns-api.php',document.baseURI);
      url.searchParams.set('action','request');
      const data=Object.fromEntries(new FormData(form).entries());
      const response=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf||''},
        body:JSON.stringify({...data,quantity:Number(data.quantity)})});
      const result=await response.json().catch(()=>null);
      if(!response.ok||!result?.ok)
        throw new Error(result?.error?.message||'Your request could not be submitted.');
      status.textContent=result.data?.message||
        'If your order reference matches an eligible order, staff will review the request.';
      form.reset();
    }catch(error){
      status.dataset.error='true';
      status.textContent=error?.message||'The request could not be submitted.';
    }finally{button.disabled=false;busy=false;}
  });
}
