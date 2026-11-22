import test from 'node:test';
import assert from 'node:assert/strict';
import { buildTimeline } from '../assets/media-engine.js';

function srgb(n){const c=n/255;return c<=.04045?c/12.92:((c+.055)/1.055)**2.4;}
function luminance(hex){const c=hex.replace('#','');const [r,g,b]=[0,2,4].map(i=>srgb(parseInt(c.slice(i,i+2),16)));return .2126*r+.7152*g+.0722*b;}
function contrast(a,b){const x=luminance(a),y=luminance(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);}

test('calendar palette is at least WCAG AA contrast for standard text',()=>{
  for(const [fg,bg,label] of [
    ['#241d29','#f2f1f4','calendar'],['#504c5a','#e9e7ec','weekday'],
    ['#342b39','#ffffff','day'],['#662744','#fff0f7','event'],
    ['#25477f','#eef3ff','class'],['#706577','#ffffff','placeholder'],
    ['#302432','#ffffff','booking slot'],['#ffffff','#b12b66','selected slot']
  ]) assert.ok(contrast(fg,bg)>=4.5,`${label} contrast ${contrast(fg,bg).toFixed(2)} < 4.5`);
});

test('camera trim reuses existing timeline renderer without title card or destructive edits',()=>{
  const segment={id:'recording',src:'blob:original-recording',duration:12.6,trimStart:3.4,trimEnd:7.9,question:'',transition:'cut'};
  const timeline=buildTimeline([segment],{cardMode:'none',fadeDuration:0,aspect:'9:16',resolution:720,brand:''});
  assert.equal(timeline.segments[0].trimStart,3.4);
  assert.equal(timeline.segments[0].trimEnd,7.9);
  assert.equal(timeline.entries.length,1);
  assert.equal(timeline.entries[0].type,'answer');
  assert.ok(Math.abs(timeline.totalDuration-4.5)<.001);
  assert.equal(timeline.width,720);
  assert.ok(timeline.height>timeline.width);
});
