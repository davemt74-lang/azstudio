import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {
  uploadToPersonalGallery,phoneUploadErrorMessage,isRetryablePhoneUploadError,
  cameraFileFingerprint
} from '../assets/personal-phone-core.js';
const read=path=>readFileSync(new URL(path,import.meta.url),'utf8');
const film=(content='video-test')=>{const blob=new Blob([content],{type:'video/webm'});blob.name='test.webm';return blob;};
const token='a'.repeat(32),uploadId='b'.repeat(32);
const state=(size)=>({uploadId,chunkBytes:3,receivedBytes:0,nextIndex:0,totalBytes:size,status:'pending'});

test('mobile uploads request smaller chunks and use a stable client token',async()=>{
  const file=film('abc');const seen=[];
  const api=async(action,body)=>{
    seen.push([action,body]);
    if(action==='beginUpload')return state(file.size);
    if(action==='uploadChunk')return {...state(file.size),receivedBytes:file.size,nextIndex:1};
    if(action==='finishUpload')return {title:'Saved'};
  };
  await uploadToPersonalGallery(api,file,'A title',{token});
  assert.equal(seen[0][1].preferredChunkBytes,262144);
  assert.equal(seen[0][1].clientToken,token);
  assert.equal(seen[1][1].get('chunk').name,'camera-part');
});

test('failed chunk receives bounded retry after server confirms the same offset',async()=>{
  const file=film('abc');let attempts=0,queries=0;
  const api=async(action)=>{
    if(action==='beginUpload')return state(file.size);
    if(action==='uploadChunk'){
      if(++attempts===1){const error=new Error('Network dropped');error.httpStatus=0;throw error;}
      return {...state(file.size),receivedBytes:file.size,nextIndex:1};
    }
    if(action==='uploadStatus'){queries++;return state(file.size);}
    if(action==='finishUpload')return {title:'Saved'};
  };
  const result=await uploadToPersonalGallery(api,file,'Film',{token,maxRetries:1});
  assert.equal(result.title,'Saved');assert.equal(attempts,2);assert.equal(queries,1);
});

test('a lost chunk response advances only after reading confirmed server checkpoint',async()=>{
  const file=film('abcdef');let saved=state(file.size),sent=[];
  const api=async(action,body)=>{
    if(action==='beginUpload')return {...saved};
    if(action==='uploadChunk'){
      sent.push(Number(body.get('index')));
      saved={...saved,receivedBytes:saved.receivedBytes+3,nextIndex:saved.nextIndex+1};
      if(sent.length===1){const e=new Error('Lost response');e.httpStatus=0;throw e;}
      return {...saved};
    }
    if(action==='uploadStatus')return {...saved};
    if(action==='finishUpload')return {title:'Saved'};
  };
  const result=await uploadToPersonalGallery(api,file,'Film',{token,maxRetries:0});
  assert.equal(result.title,'Saved');assert.deepEqual(sent,[0,1]);
});

test('lost begin response can retry with the same recovery token',async()=>{
  const file=film('abc');let starts=0,tokens=[];
  const api=async(action,body)=>{
    if(action==='beginUpload'){
      starts++;tokens.push(body.clientToken);
      if(starts===1){const e=new Error('Request timeout');e.httpStatus=504;throw e;}
      return state(file.size);
    }
    if(action==='uploadChunk')return {...state(file.size),receivedBytes:file.size,nextIndex:1};
    if(action==='finishUpload')return {title:'Saved'};
  };
  await uploadToPersonalGallery(api,file,'Film',{token,maxRetries:1});
  assert.equal(starts,2);assert.deepEqual(tokens,[token,token]);
});

test('finalization loss is safe to retry without making a new upload',async()=>{
  const file=film('abc');let finalized=0,started=0;
  const api=async(action)=>{
    if(action==='beginUpload'){started++;return state(file.size);}
    if(action==='uploadChunk')return {...state(file.size),receivedBytes:file.size,nextIndex:1};
    if(action==='finishUpload'){
      if(++finalized===1){const e=new Error('Network timeout');e.httpStatus=0;throw e;}
      return {title:'Saved'};
    }
  };
  await uploadToPersonalGallery(api,file,'Film',{token,maxRetries:1});
  assert.equal(finalized,2);assert.equal(started,1);
});

test('an explicit 403 does not retry blindly and the message identifies host security',async()=>{
  const file=film('abc');let chunks=0;
  const api=async(action)=>{
    if(action==='beginUpload')return state(file.size);
    if(action==='uploadStatus')return state(file.size);
    if(action==='uploadChunk'){
      chunks++;const error=new Error('Forbidden');error.httpStatus=403;throw error;
    }
  };
  await assert.rejects(()=>uploadToPersonalGallery(api,file,'Film',{token}),/Forbidden/);
  assert.equal(chunks,1);
  assert.equal(isRetryablePhoneUploadError({httpStatus:403}),false);
  assert.match(phoneUploadErrorMessage({httpStatus:403,message:'Denied'}),/ModSecurity/);
});

test('camera page is full viewport, preserves guest approval and private gallery upload',()=>{
  const php=read('../my-camera.php'),css=read('../assets/personal-phone.css'),js=read('../assets/personal-camera.js');
  for(const id of ['phone-fullscreen','phone-stage','phone-record','phone-stop','phone-claim','phone-preview','phone-upload-drawer','phone-upload-progress','phone-recording-badge'])assert.match(php,new RegExp(`id="${id}"`));
  assert.match(php,/viewport-fit=cover/);assert.match(php,/camera.webmanifest/);
  assert.match(css,/height:100dvh/);assert.match(css,/safe-area-inset-bottom/);
  assert.match(js,/mode==='signed-in'/);assert.match(js,/Waiting for the owner to approve/);
  assert.match(js,/device\?\.inviteId!==inviteId/);
  assert.match(js,/openDrawer\(true\)/);
  assert.match(js,/direct&&action==='uploadStatus'\?'status':action/);
  assert.match(php,/Download backup/); // camera keeps a downloadable local copy
  assert.match(php,/No login is required/);
  assert.match(js,/sessionStorage\.setItem\(recoveryKey\(\)/);
  const manifest=JSON.parse(read('../camera.webmanifest'));
  assert.equal(manifest.display,'standalone');
});

test('the server honors a requested small chunk size within configured limits',()=>{
  const backend=read('../includes/personal-media.php');
  assert.match(backend,/preferredChunkBytes/);
  assert.match(backend,/az_integer\(\$requestedChunk,65536,1048576/);
  assert.match(backend,/\$chunkBytes=min\(az_limits\(\)\['chunkBytes'\],\$requestedChunk\)/);
  assert.equal(cameraFileFingerprint({name:'x.webm',size:10,lastModified:1,type:'video/webm'}),'x.webm|10|1|video/webm');
});
