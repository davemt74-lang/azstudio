import test from 'node:test';
import assert from 'node:assert/strict';
import {createWorkUploadClient,workDraftKey,belongsToWorkDraft,restartWorkDraft} from '../assets/work-upload.js';

const assignment='a'.repeat(32),uploadId='b'.repeat(32),token='recording-recovery-token';
const blob=()=>new Blob(['abcdef'],{type:'video/webm'});
const metadata=(extra={})=>({assignmentId:assignment,clientToken:token,durationMs:1500,fileName:'take.webm',...extra});
const checkpoint=(extra={})=>({uploadId,assignmentId:assignment,totalBytes:6,chunkBytes:2,receivedBytes:0,nextIndex:0,status:'pending',...extra});
const response=(data,status=200)=>new Response(JSON.stringify(status<400?{ok:true,data:data?.saved?{submissionId:'submission',assignmentId:assignment,...data}:data}:{ok:false,error:data}),{status,headers:{'Content-Type':'application/json'}});
const action=url=>new URL(url).searchParams.get('action');

test('work drafts use both user and assignment identities, even when tokens match',()=>{
  const draft={userId:'1',assignmentId:assignment,clientToken:token,id:workDraftKey(1,assignment,token)};
  assert.ok(belongsToWorkDraft(draft,1,assignment));
  assert.equal(belongsToWorkDraft(draft,2,assignment),false);
  assert.equal(belongsToWorkDraft(draft,1,'other-assignment'),false);
  assert.notEqual(workDraftKey(1,'a:b','c'),workDraftKey(1,'a','b:c'));
});

test('assignment upload resumes from the server checkpoint with exactly the remaining bytes',async()=>{
  const chunks=[],checkpoints=[];
  const client=createWorkUploadClient({csrf:'test',fetcher:async(url,options)=>{
    if(action(url)==='upload')return response(checkpoint({receivedBytes:2,nextIndex:1}));
    if(action(url)==='uploadChunk'){const i=Number(options.body.get('index'));chunks.push([i,await options.body.get('chunk').text(),options.body.get('assignmentId')]);return response(checkpoint({receivedBytes:(i+1)*2,nextIndex:i+1}));}
    return response({saved:true,submissionId:'submission'});
  }});
  const receipt=await client.upload(blob(),metadata({uploadId}),{onCheckpoint:async cp=>checkpoints.push(cp.nextIndex)});
  assert.equal(receipt.saved,true);assert.deepEqual(chunks,[[1,'cd',assignment],[2,'ef',assignment]]);assert.deepEqual(checkpoints,[1,2,3]);
});

test('a lost begin receipt reuses its token and checkpoints before any chunk',async()=>{
  let dropped=false,created=0;const tokens=new Set(),order=[];
  const client=createWorkUploadClient({fetcher:async(url,options)=>{
    const name=action(url);order.push(name);
    if(name==='beginUpload'){const body=JSON.parse(options.body);if(!tokens.has(body.clientToken)){tokens.add(body.clientToken);created++;}if(!dropped){dropped=true;throw new Error('connection lost');}return response(checkpoint());}
    if(name==='uploadChunk'){const i=Number(options.body.get('index'));return response(checkpoint({receivedBytes:(i+1)*2,nextIndex:i+1}));}
    return response({saved:true});
  }});
  await assert.rejects(client.upload(blob(),metadata()),/connection stopped/);
  await client.upload(blob(),metadata(),{onCheckpoint:async()=>order.push('checkpoint')});
  assert.equal(created,1);assert.equal(tokens.size,1);assert.ok(order.indexOf('checkpoint')<order.indexOf('uploadChunk'));
});

test('lost chunk responses retry the same bytes and then recover the finish receipt',async()=>{
  let dropped=false;const chunks=[];
  const client=createWorkUploadClient({fetcher:async(url,options)=>{
    if(action(url)==='beginUpload')return response(checkpoint());
    if(action(url)==='uploadChunk'){const i=Number(options.body.get('index'));chunks.push([i,await options.body.get('chunk').text()]);if(i===0&&!dropped){dropped=true;throw new Error('lost response');}return response(checkpoint({receivedBytes:(i+1)*2,nextIndex:i+1}));}
    return response({saved:true});
  }});
  assert.equal((await client.upload(blob(),metadata())).saved,true);
  assert.deepEqual(chunks,[[0,'ab'],[0,'ab'],[1,'cd'],[2,'ef']]);
});

test('completed uploads ask only for their existing receipt and never resend media',async()=>{
  const calls=[];
  const client=createWorkUploadClient({fetcher:async(url)=>{calls.push(action(url));return response(action(url)==='upload'?checkpoint({status:'completed',receivedBytes:6,nextIndex:3}):{saved:true,submissionId:'same'});}});
  assert.equal((await client.upload(blob(),metadata({uploadId}))).submissionId,'same');
  assert.deepEqual(calls,['upload','finishUpload']);
});

test('revoked contributors stop once, keep their draft, and never finish',async()=>{
  const calls=[],clip=blob(),saved=metadata();
  const client=createWorkUploadClient({fetcher:async(url)=>{calls.push(action(url));if(action(url)==='beginUpload')return response(checkpoint());return response({code:'assignment_not_uploadable',message:'Assignment revoked.'},403);}});
  await assert.rejects(client.upload(clip,saved,{onCheckpoint:async cp=>{saved.uploadId=cp.uploadId;}}),error=>error.status===403);
  assert.deepEqual(calls,['beginUpload','uploadChunk']);assert.equal(saved.uploadId,uploadId);assert.equal(await clip.text(),'abcdef');
});

test('a mismatched assignment receipt is rejected before sending any footage',async()=>{
  const calls=[];
  const client=createWorkUploadClient({fetcher:async(url)=>{calls.push(action(url));return response(checkpoint({assignmentId:'other'}));}});
  await assert.rejects(client.upload(blob(),metadata()),/does not match this assignment/);assert.deepEqual(calls,['beginUpload']);
});

test('pausing after checkpoint keeps the upload ID and never finalizes',async()=>{
  const controller=new AbortController(),calls=[],saved=metadata();
  const client=createWorkUploadClient({fetcher:async(url)=>{calls.push(action(url));return response(checkpoint());}});
  await assert.rejects(client.upload(blob(),saved,{signal:controller.signal,onCheckpoint:async cp=>{saved.uploadId=cp.uploadId;controller.abort();}}),error=>error.name==='AbortError');
  assert.equal(saved.uploadId,uploadId);assert.deepEqual(calls,['beginUpload']);
});

test('an unconfirmed finish response does not discard a local recording as saved',async()=>{
  const client=createWorkUploadClient({fetcher:async(url)=>response(action(url)==='upload'?checkpoint({status:'completed',receivedBytes:6,nextIndex:3}):{})});
  await assert.rejects(client.upload(blob(),metadata({uploadId})),/has not confirmed this take/);
});

test('assignment-change restart preserves the recording and replaces identity only after persistence',async()=>{
  const previous={...metadata({uploadId}),userId:'9',id:workDraftKey(9,assignment,token),blob:blob(),label:'Original take',errorCode:'assignment_changed',receivedBytes:4},order=[];
  const before={...previous};let durable;
  const replacement=await restartWorkDraft(previous,{makeToken:()=>token+'-new',abandon:async(a,id)=>{assert.equal(a,assignment);assert.equal(id,uploadId);order.push('abandon');return {abandoned:true};},replace:async(old,next)=>{assert.equal(old,previous);assert.deepEqual(previous,before);assert.equal(next.blob,previous.blob);durable=next;order.push('persist replacement');}});
  assert.deepEqual(previous,before);assert.equal(replacement,durable);assert.equal(replacement.blob,previous.blob);
  assert.equal(replacement.uploadId,null);assert.equal(replacement.receivedBytes,0);assert.equal(replacement.label,'Original take');
  assert.equal(replacement.assignmentId,assignment);assert.notEqual(replacement.id,previous.id);assert.deepEqual(order,['abandon','persist replacement']);
});

test('restart persistence failure keeps the original draft and its entire blob',async()=>{
  const previous={...metadata({uploadId}),userId:'9',id:workDraftKey(9,assignment,token),blob:blob(),receivedBytes:4};
  await assert.rejects(restartWorkDraft(previous,{makeToken:()=>token+'-new',abandon:async()=>({abandoned:true}),replace:async()=>{throw new Error('Storage full');}}),/Storage full/);
  assert.equal(previous.uploadId,uploadId);assert.equal(previous.clientToken,token);assert.equal(await previous.blob.text(),'abcdef');assert.equal(previous.receivedBytes,4);
});

test('restart refuses a completed or unconfirmed server abort without replacing the local draft',async()=>{
  const previous={...metadata({uploadId}),userId:'9',id:workDraftKey(9,assignment,token),blob:blob()};let writes=0;
  for(const receipt of [{completed:true},{}])await assert.rejects(restartWorkDraft(previous,{abandon:async()=>receipt,replace:async()=>{writes++;}}));
  assert.equal(writes,0);assert.equal(previous.uploadId,uploadId);assert.equal(previous.blob.size,6);
});
