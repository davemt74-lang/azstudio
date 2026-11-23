import test from 'node:test';
import assert from 'node:assert/strict';
import {HostPhoneFeeds} from '../assets/phone-live.js';

test('two approved phones keep independent previews across a one-phone relay interruption',async()=>{
 const savedPeer=globalThis.RTCPeerConnection,savedMedia=globalThis.MediaStream;
 const peers=[];
 class FakeStream{
   constructor(){this.tracks=[];}
   addTrack(track){this.tracks.push(track);}
   getTracks(){return this.tracks;}
   getVideoTracks(){return this.tracks.filter(t=>t.kind==='video');}
   getAudioTracks(){return this.tracks.filter(t=>t.kind==='audio');}
 }
 class FakeRTC{
   constructor(){this.connectionState='connecting';this.iceGatheringState='complete';this.localDescription=null;this.remote=null;peers.push(this);}
   async setRemoteDescription(desc){this.remote=desc;}
   async createAnswer(){return {type:'answer',sdp:'v=0\r\nfake-answer'};}
   async setLocalDescription(desc){this.localDescription=desc;}
   close(){this.connectionState='closed';}
 }
 const track=id=>({id,kind:'video',readyState:'live',addEventListener(){},stop(){this.readyState='ended';}});
 const a='a'.repeat(32),b='b'.repeat(32),nonce1='1'.repeat(32),nonce2='2'.repeat(32),nonce3='3'.repeat(32);
 const offers=[
  {id:a,nonce:nonce1,offer:'v=0\r\napproved phone A',label:'Phone A'},
  {id:b,nonce:nonce2,offer:'v=0\r\napproved phone B',label:'Phone B'}
 ];
 const streams=[],removed=[],answers=[],states=[];
 globalThis.RTCPeerConnection=FakeRTC;globalThis.MediaStream=FakeStream;
 try{
   const host=new HostPhoneFeeds({
     request:async(action,body)=>{assert.equal(action,'answer');answers.push(body);return {answered:true};},
     onStream:(id,stream)=>streams.push({id,stream}),
     onRemove:(id)=>removed.push(id),
     onView:(id,view)=>states.push({id,message:view.message})
   });
   host.sync(offers,[]);
   await new Promise(resolve=>setTimeout(resolve,0));
   assert.equal(host.peers.size,2,'Both phone offers should connect without replacing each other');
   assert.deepEqual(answers.map(a=>a.id).sort(),[a,b]);
   const first=host.peers.get(a).peer,second=host.peers.get(b).peer;
   first.connectionState='connected';first.onconnectionstatechange?.();
   second.connectionState='connected';second.onconnectionstatechange?.();
   first.ontrack({track:track('video-a')});
   second.ontrack({track:track('video-b')});
   assert.equal(streams.length,2);
   assert.deepEqual(streams.map(x=>x.id).sort(),[a,b]);
   assert.equal(streams.find(x=>x.id===b).stream.getVideoTracks()[0].id,'video-b');
   const unchangedB=host.peers.get(b);
   host.remove(a,true);
   assert.equal(host.peers.size,1,'Only affected phone A should be removed');
   assert.equal(host.peers.get(b),unchangedB,'Phone B and its stream must stay alive');
   assert.equal(unchangedB.peer.connectionState,'connected');
   assert.deepEqual(removed,[a]);
   host.sync(offers,[]);
   await new Promise(resolve=>setTimeout(resolve,0));
   assert.equal(host.peers.size,1,'Old nonce must not reconnect a blocked/failed relay');
   host.sync([{...offers[0],nonce:nonce3},offers[1]],[]);
   await new Promise(resolve=>setTimeout(resolve,0));
   assert.equal(host.peers.size,2,'New nonce from approved phone should rejoin independently');
   assert.equal(host.peers.get(b),unchangedB,'Phone B must not be recreated when A rejoins');
   assert.equal(answers.filter(x=>x.id===a).length,2);
   assert.equal(answers.filter(x=>x.id===b).length,1);
   host.stop();
   assert.equal(host.peers.size,0);
 }finally{
   if(savedPeer===undefined)delete globalThis.RTCPeerConnection;else globalThis.RTCPeerConnection=savedPeer;
   if(savedMedia===undefined)delete globalThis.MediaStream;else globalThis.MediaStream=savedMedia;
 }
});
