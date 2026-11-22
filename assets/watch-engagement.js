/* Public Watch player analytics: opt-in by playback, aggregate-only, best effort.
 * Never interferes with video controls or transmits user/device identifiers. */
const video=document.querySelector('.watch-player video[data-engagement-kind]');
if(video){
  const kind=video.dataset.engagementKind,id=video.dataset.engagementId,csrf=video.dataset.engagementCsrf;
  let started=false,active=false,lastTick=0,seconds=0,queue=Promise.resolve();
  const send=(event,amount=0)=>{
    // Ordered requests ensure progress cannot race ahead of the first start.
    queue=queue.catch(()=>{}).then(()=>fetch('watch-engagement.php',{
      method:'POST',credentials:'same-origin',cache:'no-store',keepalive:true,
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({kind,id,event,seconds:amount})
    }).then(()=>{}).catch(()=>{}));
  };
  function tick(){
    if(!active)return;
    const now=performance.now();
    if(lastTick && !document.hidden && !video.paused && !video.seeking && !video.ended)
      seconds+=Math.min((now-lastTick)/1000,2);
    lastTick=now;
    if(seconds>=10) flush();
  }
  function flush(){
    if(!started)return;
    const whole=Math.min(30,Math.floor(seconds));
    if(whole>0){seconds-=whole;send('progress',whole);}
  }
  video.addEventListener('playing',()=>{
    if(!started){started=true;send('start');}
    active=true;lastTick=performance.now();
  });
  video.addEventListener('timeupdate',tick);
  for(const event of ['pause','waiting','seeking','emptied'])video.addEventListener(event,()=>{tick();active=false;lastTick=0;flush();});
  video.addEventListener('ended',()=>{tick();active=false;flush();send('complete');});
  document.addEventListener('visibilitychange',()=>{if(document.hidden){tick();lastTick=0;flush();}else if(active)lastTick=performance.now();});
  window.addEventListener('pagehide',flush);
}
