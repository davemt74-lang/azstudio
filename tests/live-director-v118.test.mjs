import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resolve,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const file=p=>readFileSync(resolve(root,p),'utf8');
const director=file('assets/live-director.js'),html=file('live-director.php');
test('every approved phone has a persistent playing thumbnail; inactive phones remain visible',()=>{
 assert.match(director,/function createSourceCard\(item\)/);
 assert.match(director,/function createIdlePhoneCard\(device\)/);
 assert.match(director,/sourceCards=new Map\(\), idleCards=new Map\(\)/);
 assert.match(director,/video\.muted=true;video\.defaultMuted=true;video\.autoplay=true;video\.playsInline=true/);
 assert.match(director,/wrap\.append\(item\.video,inactive,indicator,resume\)/);
 assert.match(director,/tile\.inactive\.hidden=ready;tile\.indicator\.hidden=!ready/);
 assert.match(director,/tile\.take\.disabled=!ready\|\|\(!mediaProgramActive&&activeLayout\.type==='single'&&selected===id\)/);
 assert.match(director,/if\(!tile\)\{createSourceCard\(item\)/);
 assert.match(director,/if\(list\.children\[i\]!==cards\[i\]\)list\.insertBefore/);
 assert.match(html,/class="azh-phone-camera-grid"/);
});
test('unhealthy feeds cannot be broadcast and automatic switching uses only decoded phone frames',()=>{
 assert.match(director,/item\?\.kind==='phone'&&item\.stream\?\.getVideoTracks\?\.\(\)\.some\(t=>t\.readyState==='live'\)&&item\.health\?\.playable\(\)/);
 assert.match(director,/if\(selected===item\.id&&!mediaProgramActive&&activeLayout\.type==='single'&&\['stalled','muted','offline','blocked'\]\.includes\(state\)&&!\(document\.hidden&&state==='stalled'\)\)/);
 assert.match(director,/selected=null;activeLayout=normalizeLayout\('single',\[''\]\);transitionStart=0/);
 assert.match(director,/if\(!sources\.get\(id\)\.health\?\.playable\(\)\)/);
 assert.match(director,/Never expose a newly/);
});
test('phone camera previews remain private from fan stream and audio is opt-in',()=>{
 assert.match(director,/stage\.captureStream\(24\)/);
 assert.match(director,/peer\.addTrack\(track,programStream\)/);
 assert.doesNotMatch(director,/peer\.addTrack\(track,item\.stream\)/);
 const camera=file('my-camera.php'),phone=file('assets/personal-camera.js');
 assert.match(camera,/id="phone-share-audio"/);
 assert.match(phone,/if\(\$\('phone-share-audio'\)\?\.checked\)/);
 assert.match(phone,/liveAudioStream\?\.getTracks\(\)\.forEach\(track=>track\.stop\(\)\)/);
 assert.match(file('assets/phone-live.js'),/event\.track\.kind==='audio'/);
});
test('director has an independent host mic and opt-in camera audio mixer with visible meters',()=>{
 for(const id of ['live-mic-volume','live-mic-mute','live-camera-volume','live-camera-audio','live-mic-meter','live-camera-meter'])assert.match(html,new RegExp(`id="${id}"`));
 assert.match(director,/const cameraVolume=\$\('live-camera-audio'\)\.checked\?/);
 assert.match(director,/selected===id&&!mediaProgramActive&&sources\.get\(id\)\?\.health\?\.playable\(\)\?cameraVolume:0/);
 assert.match(director,/const audioMeterLoop=setInterval/);
 assert.match(director,/createMediaStreamDestination/);
});
test('scene settings and image upload require admin/editor and remain private',()=>{
 const api=file('live-api.php'),settings=file('includes/live-director-settings.php'),reader=file('live-director-overlay.php');
 assert.match(api,/az_require_auth\(true\)/);assert.match(api,/az_live_require_editor\(true\)/);
 for(const action of ['scenes','saveScene','deleteScene','overlays','uploadOverlay','deleteOverlay'])assert.match(api,new RegExp(`'${action}'`));
 assert.match(settings,/AZ_STORAGE\.'\/live-director'/);
 assert.match(settings,/move_uploaded_file/);assert.match(settings,/is_uploaded_file/);
 assert.match(settings,/image\/png','image\/jpeg','image\/webp'/);
 assert.match(settings,/scenes\.lock/);assert.match(settings,/flock\(\$lock,LOCK_EX\)/);
 assert.match(reader,/az_live_require_editor\(\)/);assert.match(reader,/Content-Type/);
 assert.doesNotMatch(file('live.php'),/live-director-overlay/);
});
test('scene recall never takes camera directly to public program',()=>{
 assert.match(director,/Scene recalled\. Camera is in private Preview/);
 assert.match(director,/if\(settings\.cameraId&&sources\.has\(settings\.cameraId\)\)previewSource\(settings\.cameraId\)/);
 const body=director.split("$('live-recall-scene').addEventListener('click',()=>{")[1].split("$('live-delete-scene')")[0];
 assert.doesNotMatch(body,/takeSource\(/);
});
test('program image overlays are composited into the same captured canvas',()=>{
 assert.match(director,/ctx\.drawImage\(image,x,y,width,height\)/);
 assert.match(director,/imageOverlay\.enabled&&imageOverlay\.image\?\.naturalWidth/);
 assert.match(director,/async function ensureProgram\(/);
 assert.match(director,/canvasStream=stage\.captureStream\(24\)/);
});
test('recording retains backup until server verifies bytes and links the finished show',()=>{
 assert.match(director,/const persisted=\(listing\.items\|\|\[\]\)\.find\(item=>item\.id===saved\.id\)/);
 assert.match(director,/Number\(persisted\.sizeBytes\)!==file\.size/);
 const v=director.indexOf('const persisted='),attach=director.indexOf("await liveRequest('attach'"),revoke=director.indexOf('URL.revokeObjectURL(recordUrl);recordUrl=null;',attach);
 assert.ok(v>=0&&v<attach&&attach<revoke);
 assert.match(director,/onRetry:\(\{attempt\}\)=>reportRecord/);
 assert.match(director,/beforeunload/);
});
