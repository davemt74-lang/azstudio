/** AZHottie broadcast transport recovery policies.
 * Pure, bounded decisions; never grant camera approval, select a Program source,
 * send a provider action, or touch a recording. Used by viewers and Director.
 */
export class FanRecoveryBudget {
  constructor({ now=()=>Date.now(), maxAttempts=3, windowMs=120000,
    stableMs=45000, delays=[1200,3000,7000] }={}){
    Object.assign(this,{now,maxAttempts,windowMs,stableMs,delays});
    this.roomId=null;this.attempts=[];this.stopped=false;this.healthySince=null;
  }
  enterRoom(id){
    if(this.roomId===id)return;
    this.roomId=id||null;this.attempts=[];this.healthySince=null;this.stopped=false;
  }
  manualJoin(){this.stopped=false;this.attempts=[];this.healthySince=null;}
  leave(){this.stopped=true;this.healthySince=null;}
  observeHealthy(healthy){
    if(!healthy){this.healthySince=null;return;}
    const time=this.now();
    if(this.healthySince===null){this.healthySince=time;return;}
    if(time-this.healthySince>=this.stableMs){this.attempts=[];this.healthySince=time;}
  }
  next(){
    if(!this.roomId||this.stopped)return null;
    const time=this.now();
    this.attempts=this.attempts.filter(t=>time-t<this.windowMs);
    if(this.attempts.length>=this.maxAttempts)return null;
    const index=this.attempts.length;
    this.attempts.push(time);this.healthySince=null;
    return {delayMs:this.delays[Math.min(index,this.delays.length-1)]||1200,
      attempt:index+1,maxAttempts:this.maxAttempts};
  }
}

/** Trigger viewer recovery only for a confirmed failure in a visible player.
 * Autoplay blocked or user-paused playback is not a network failure.
 */
export function fanRecoveryReason({visible=true,connection='new',elapsedMs=0,
  disconnectedMs=0,hasVideo=false,canMeasure=false,frameAgeMs=0,
  paused=false,autoplayBlocked=false}={}){
  if(!visible)return null;
  if(['failed','closed'].includes(connection))return 'peer_failed';
  if(connection==='disconnected'&&disconnectedMs>=6000)return 'peer_disconnected';
  if(paused||autoplayBlocked)return null;
  if(!hasVideo&&elapsedMs>=30000)return 'no_video';
  if(connection==='connected'&&hasVideo&&canMeasure&&frameAgeMs>=12000)return 'frozen_video';
  return null;
}

/** Per-approved-phone recovery throttle. A stable working preview clears its
 * budget only after a sustained healthy interval; rapid fail/success loops do
 * not restart the counter. Operators may explicitly reset a single phone.
 */
export class PhoneRepairBudget {
  constructor({now=()=>Date.now(),maxAttempts=2,windowMs=120000,stableMs=45000}={}){
    Object.assign(this,{now,maxAttempts,windowMs,stableMs});
    this.devices=new Map();
  }
  get(id){if(!this.devices.has(id))this.devices.set(id,{attempts:[],healthySince:null});return this.devices.get(id);}
  clear(id){this.devices.delete(id);}
  healthy(id,active){
    const item=this.get(id);
    if(!active){item.healthySince=null;return;}
    const time=this.now();
    if(item.healthySince===null){item.healthySince=time;return;}
    if(time-item.healthySince>=this.stableMs){item.attempts=[];item.healthySince=time;}
  }
  next(id,{manual=false}={}){
    const item=this.get(id);
    if(manual)item.attempts=[];
    const time=this.now();item.attempts=item.attempts.filter(t=>time-t<this.windowMs);
    if(item.attempts.length>=this.maxAttempts)return null;
    item.healthySince=null;item.attempts.push(time);
    return {attempt:item.attempts.length,maxAttempts:this.maxAttempts};
  }
}
