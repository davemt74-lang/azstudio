/** v1.19: pure, testable scene and show-rundown geometry/validation.
 * No browser permissions or camera access are granted by this module.
 */
export const LIVE_LAYOUT_TYPES = Object.freeze(['single','split','pip','grid']);
export const LIVE_SEGMENT_TYPES = Object.freeze(['camera','media','slate']);
export const SLOT_COUNT = Object.freeze({single:1,split:2,pip:2,grid:4});
export function normalizeLayout(type,slots=[]) {
  const layout=LIVE_LAYOUT_TYPES.includes(type)?type:'single';
  const count=SLOT_COUNT[layout];
  return {type:layout, slots:Array.from({length:count},(_,i)=>typeof slots[i]==='string'?slots[i].slice(0,100):'')};
}
/** Rectangles are in PROGRAM canvas pixels, keyed by slot index.
 * Unavailable inputs must draw an inactive slate rather than a stale frame.
 */
export function layoutRects(type,width,height) {
  if(!Number.isFinite(width)||!Number.isFinite(height)||width<=0||height<=0)return [];
  const w=width,h=height;
  switch(type){
    case 'split':return [{x:0,y:0,w:w/2,h,z:0},{x:w/2,y:0,w:w/2,h,z:0}];
    case 'pip':return [{x:0,y:0,w,h,z:0},{x:w*.69,y:h*.61,w:w*.28,h:h*.28,z:1}];
    case 'grid':return [0,1,2,3].map(i=>({x:(i%2)*w/2,y:Math.floor(i/2)*h/2,w:w/2,h:h/2,z:0}));
    default:return [{x:0,y:0,w,h,z:0}];
  }
}
export function compositionReady(layout,validIds,{allowPartial=false}={}) {
  const current=normalizeLayout(layout?.type,layout?.slots),ids=new Set(validIds||[]);
  const filled=current.slots.filter(x=>ids.has(x));
  return allowPartial?filled.length>0:filled.length===current.slots.length&&new Set(filled).size===filled.length;
}
export function chooseRundownSegment(segments,current,move=1){
  if(!Array.isArray(segments)||!segments.length)return -1;
  const next=Number(current)+Number(move);return Math.min(segments.length-1,Math.max(0,next));
}
export function safeShowText(input,max=140){return String(input??'').replace(/[\u0000-\u001f\u007f]/g,' ').replace(/\s+/g,' ').trim().slice(0,max);}
export function rundownPreview(segment){
  if(!segment||!LIVE_SEGMENT_TYPES.includes(segment.kind))return null;
  if(segment.kind==='camera')return {kind:'camera',layout:normalizeLayout(segment.layout,segment.slots),sceneId:segment.sceneId||''};
  if(segment.kind==='media')return {kind:'media',mediaId:segment.mediaId||''};
  return {kind:'slate'};
}
