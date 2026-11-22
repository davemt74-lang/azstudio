import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {selectRecordingMime,phoneUploadIdentity,cameraFileSupported,cameraUploadType,uploadToPersonalGallery} from '../assets/personal-phone-core.js';
const read=path=>readFileSync(new URL(path,import.meta.url),'utf8');
test('camera recording prioritizes MP4 and supports WebM on browsers without H264',()=>{
  assert.equal(selectRecordingMime(t=>t.startsWith('video/mp4')),'video/mp4;codecs=avc1.42E01E,mp4a.40.2');
  assert.equal(selectRecordingMime(t=>t.includes('webm')),'video/webm;codecs=vp9,opus');
  assert.equal(selectRecordingMime(()=>false),'');
});
test('phone capability identities require a full random ID and bearer secret',()=>{
  assert.equal(phoneUploadIdentity('a'.repeat(32),'b'.repeat(64)),true);
  assert.equal(phoneUploadIdentity('a'.repeat(32),'b'.repeat(63)),false);
  assert.equal(phoneUploadIdentity('1234','b'.repeat(64)),false);
});
test('camera gallery uploader accepts supported video formats with codec suffixes',()=>{
  assert.equal(cameraUploadType({type:'video/webm;codecs=vp8,opus'}),'video/webm');
  assert.equal(cameraFileSupported({size:14,type:'video/mp4; codecs=avc1'}),true);
  assert.equal(cameraFileSupported({size:14,type:'image/jpeg'}),false);
  assert.equal(cameraFileSupported({size:0,type:'video/mp4'}),false);
});
test('phone uploader resumes at the server-confirmed offset after a lost chunk response',async()=>{
  const file=new Blob(['abcdefghij'],{type:'video/webm'});file.name='test.webm';
  let checkpoint={uploadId:'ab'.repeat(16),receivedBytes:0,nextIndex:0,chunkBytes:5,totalBytes:10,status:'pending'};
  let lost=false,seen=[];
  const api=async(action,body,query)=>{
    seen.push(action);
    if(action==='beginUpload')return {...checkpoint};
    if(action==='uploadChunk'){
      const index=Number(body.get('index'));
      assert.equal(index,checkpoint.nextIndex);
      checkpoint={...checkpoint,receivedBytes:Math.min(10,checkpoint.receivedBytes+5),nextIndex:index+1};
      if(!lost){lost=true;throw Error('Network dropped after chunk saved');}
      return {...checkpoint};
    }
    if(action==='uploadStatus')return {...checkpoint};
    if(action==='finishUpload')return {id:'cd'.repeat(16),title:'Test clip'};
    throw Error('Unexpected '+action);
  };
  const result=await uploadToPersonalGallery(api,file,'Test clip',{token:'e'.repeat(32)});
  assert.equal(result.title,'Test clip');
  assert.deepEqual(seen,['beginUpload','uploadChunk','uploadStatus','uploadChunk','finishUpload']);
});
test('owner phone pairing and guest upload use separate endpoints and permissions',()=>{
  const api=read('../personal-phone-api.php'),lib=read('../includes/personal-phone.php');
  assert.match(api,/az_require_auth\(true\)/);
  assert.match(api,/az_require_csrf\(\)/);
  assert.match(api,/az_personal_phone_device\(\$id,\$token,\$action!==[']status[']\)/);
  assert.match(api,/parentMediaId'\s*=>\s*''/);
  assert.match(lib,/hash_equals\(/);
  assert.match(lib,/camera_upload_denied/);
  assert.match(lib,/status='approved'/);
  assert.match(lib,/personal_phone_live/);
  assert.match(lib,/expires_epoch/);
});
test('signed-in phones upload directly without pairing and remote phones need owner approval',()=>{
  const page=read('../my-camera.php'),script=read('../assets/personal-camera.js');
  assert.match(page,/data-mode="<\?= \$user\?'signed-in':'paired'/);
  assert.match(page,/az_redirect\('my-camera.php'\)/);
  assert.match(script,/mode==='signed-in'/);
  assert.match(script,/Waiting for the owner to approve/);
  assert.match(script,/getUserMedia/);
  assert.match(script,/direct&&action==='uploadStatus'\?'status':action/);
  assert.match(script,/A dropped preview or camera change must not invalidate an in-progress/);
  assert.match(page,/if \(\$user && !\$guest\)az_personal_media_role\(\$user\)/);
});
test('model editor offers library upload, signed-in camera and second-phone connection',()=>{
  const page=read('../my-editor.php');
  for(const piece of ['pt-add-upload','pt-refresh-library','pt-invite-phone','pt-phone-devices','pt-phone-live-feeds','my-camera.php','personal-phone-host.js'])assert.ok(page.includes(piece),piece);
});
test('member navigation hides hamburger at desktop and forms use light surfaces',()=>{
  const css=read('../assets/universal-header.css');
  assert.match(css,/@media\(min-width:851px\)/);
  assert.match(css,/azh-global-header__menu-toggle--member\{display:none!important\}/);
  assert.match(read('../assets/form-light.css'),/color:#211b22!important/);
});
