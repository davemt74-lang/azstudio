import {normalizeAudioKeyframes,volumeAt} from './personal-audio-envelope.js?v=1.19.1';
/**
 * AZHottie browser video compositor.
 *
 * Each segment is a question card on black, then a trimmed answer with matching
 * picture/audio fades. Exports are rendered in real time in the visible tab.
 * Sources are same-origin video URLs or Blobs. No media leaves this module.
 *
 * play() resolves when the preview finishes; export() resolves with a Blob.
 * stop() aborts either operation with an AbortError. onProgress receives
 * {elapsed,total,ratio,segmentIndex,phase}; onStatus receives plain text.
 */

const TYPE_CANDIDATES = [
  'video/mp4;codecs=avc1.42E028,mp4a.40.2',
  'video/mp4;codecs=avc1.42E01E,mp4a.40.2',
  'video/webm;codecs=vp8,opus',
  'video/webm;codecs=vp9,opus',
  'video/webm',
  // An unspecified MP4 can select VP9/Opus, which many MP4 players cannot use.
  'video/mp4',
];

const finite = (value, fallback = 0) => Number.isFinite(Number(value)) ? Number(value) : fallback;
const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
const abortError = (message = 'Playback stopped.') => new DOMException(message, 'AbortError');

function throwIfAborted(signal) {
  if (signal?.aborted) throw signal.reason instanceof Error ? signal.reason : abortError();
}

/** Returns a supported MIME string, or an empty string for browser defaults. */
export function supportedRecordingType() {
  if (typeof MediaRecorder === 'undefined') return '';
  return TYPE_CANDIDATES.find(type => MediaRecorder.isTypeSupported(type)) || '';
}

export function normalizeSettings(settings = {}) {
  const aspect = ['16:9', '9:16', '1:1'].includes(settings.aspect) ? settings.aspect : '16:9';
  const resolution = Number(settings.resolution) === 1080 ? 1080 : 720;
  return {
    cardMode: ['all', 'none', 'perClip'].includes(settings.cardMode) ? settings.cardMode : 'all',
    cardDuration: clamp(finite(settings.cardDuration, 3), 0.5, 20),
    fadeDuration: clamp(finite(settings.fadeDuration, 0.45), 0, 4),
    aspect,
    resolution,
    fit: settings.fit === 'cover' ? 'cover' : 'contain',
    brand: String(settings.brand ?? 'AZHOTTIE').slice(0, 100),
    modelName: String(settings.modelName ?? '').slice(0, 160),
    timedTitles: Array.isArray(settings.timedTitles) ? settings.timedTitles.slice(0,32).filter(t=>t&&Number.isFinite(Number(t.startMs))&&Number.isFinite(Number(t.durationMs))).map(t=>({text:String(t.text||'').slice(0,200),startMs:Math.max(0,Number(t.startMs)),durationMs:Math.max(300,Number(t.durationMs)),position:['top','center','bottom'].includes(t.position)?t.position:'bottom'})) : [],
    background: settings.background && typeof settings.background.url==='string' && settings.background.url ? {
      url:settings.background.url, startMs:Math.max(0,finite(settings.background.startMs,0)),
      trimStartMs:Math.max(0,finite(settings.background.trimStartMs,0)),
      volume:clamp(finite(settings.background.volume,.18),0,1)
    } : null,
    detachedAudio:Array.isArray(settings.detachedAudio)?settings.detachedAudio.slice(0,24).filter(a=>a&&typeof a.url==='string'&&a.url).map(a=>{
      const durationMs=Math.max(100,finite(a.trimEndMs,0)-finite(a.trimStartMs,0));
      return {url:a.url,startMs:Math.max(0,finite(a.startMs,0)),trimStartMs:Math.max(0,finite(a.trimStartMs,0)),
        trimEndMs:Math.max(0,finite(a.trimEndMs,0)),volume:clamp(finite(a.volume,1),0,1),
        audioKeyframes:normalizeAudioKeyframes(a.audioKeyframes||[],durationMs)};
    }):[],
  };
}

function dimensions(settings) {
  const short = settings.resolution;
  const long = Math.round(short * 16 / 9 / 2) * 2;
  if (settings.aspect === '9:16') return {width: short, height: long};
  if (settings.aspect === '1:1') return {width: short, height: short};
  return {width: long, height: short};
}

/** Pure timeline calculation. Invalid or zero-length selected clips are errors. */
export function buildTimeline(segments, settings = {}) {
  if (!Array.isArray(segments) || segments.length === 0) throw new Error('Select at least one recorded answer.');
  const config = normalizeSettings(settings);
  const normalized = [];
  const entries = [];
  let total = 0;
  segments.forEach((segment, index) => {
    if (!segment || !segment.src) throw new Error(`Answer ${index + 1} has no video source.`);
    const sourceDuration = finite(segment.duration, finite(segment.trimEnd, NaN));
    if (!(sourceDuration > 0)) throw new Error(`Answer ${index + 1} has no valid video duration.`);
    const trimStart = clamp(finite(segment.trimStart, 0), 0, sourceDuration);
    const requestedEnd = segment.trimEnd == null || segment.trimEnd === ''
      ? sourceDuration : finite(segment.trimEnd, sourceDuration);
    const trimEnd = clamp(requestedEnd, 0, sourceDuration);
    if (trimEnd - trimStart < 0.05) throw new Error(`Answer ${index + 1} needs an end time after its start time.`);
    const answerDuration = trimEnd - trimStart;
    const normalizedSegment = {
      ...segment,
      id: segment.id ?? `segment-${index + 1}`,
      question: String(segment.question || 'Interview question').slice(0, 3000),
      duration: sourceDuration,
      trimStart,
      trimEnd,
      answerDuration,
      fadeDuration: Math.min(segment.transition==='cut'?0:config.fadeDuration, answerDuration / 2),
      filter: ['none','vivid','warm','mono','cinematic','vintage'].includes(segment.filter)?segment.filter:'none',
      segmentIndex: index,
      overlayText: String(segment.overlayText||'').slice(0,200),
      overlayPosition: ['top','center','bottom'].includes(segment.overlayPosition)?segment.overlayPosition:'bottom',
      volume: clamp(finite(segment.volume,1),0,1),
      audioKeyframes:normalizeAudioKeyframes(segment.audioKeyframes||[],Math.round(answerDuration*1000)),
    };
    normalized.push(normalizedSegment);
    const showCard = config.cardMode === 'all' || (config.cardMode === 'perClip' && segment.showCard === true);
    normalizedSegment.showCard = showCard;
    if (showCard) {
      entries.push({type: 'card', start: total, end: total + config.cardDuration, duration: config.cardDuration, segmentIndex: index, segment: normalizedSegment});
      total += config.cardDuration;
    }
    entries.push({type: 'answer', start: total, end: total + answerDuration, duration: answerDuration, segmentIndex: index, segment: normalizedSegment});
    total += answerDuration;
  });
  return {segments: normalized, entries, totalDuration: total, duration: total, ...dimensions(config), settings: config};
}

/** Linear picture/audio fade, shared by preview and export. */
export function fadeAtTime(time, duration, fadeDuration) {
  if (time < 0 || time > duration) return 0;
  const fade = Math.min(Math.max(0, fadeDuration), duration / 2);
  if (fade === 0) return 1;
  return clamp(Math.min(time / fade, (duration - time) / fade), 0, 1);
}

function makeVideo() {
  if (typeof document === 'undefined') throw new Error('Video rendering needs a browser.');
  const video = document.createElement('video');
  video.preload = 'auto';
  video.playsInline = true;
  video.setAttribute('playsinline', '');
  video.setAttribute('aria-hidden', 'true');
  video.tabIndex = -1;
  // Keep a real rendered media element: display:none is unreliable on iOS.
  video.style.cssText = 'position:fixed;left:-10000px;top:0;width:2px;height:2px;opacity:0;pointer-events:none';
  document.body.append(video);
  return video;
}

function disposeVideo(video) {
  if (!video) return;
  video.pause();
  video.removeAttribute('src');
  video.load();
  video.remove();
}

function bindSource(video, source, urls) {
  if (typeof Blob !== 'undefined' && source instanceof Blob) {
    const url = URL.createObjectURL(source);
    urls.push(url);
    video.src = url;
  } else {
    video.src = String(source);
  }
  video.load();
}

function waitFor(video, predicate, events, {signal, timeout = 20000, message = 'The video did not finish loading.'} = {}) {
  throwIfAborted(signal);
  if (predicate()) return Promise.resolve();
  return new Promise((resolve, reject) => {
    let settled = false;
    const cleanup = () => {
      clearTimeout(timer);
      events.forEach(name => video.removeEventListener(name, check));
      video.removeEventListener('error', fail);
      signal?.removeEventListener('abort', abort);
    };
    const finish = (error) => {
      if (settled) return;
      settled = true;
      cleanup();
      error ? reject(error) : resolve();
    };
    const check = () => { if (predicate()) finish(); };
    const fail = () => finish(new Error('This video could not be decoded. Try another recording or a supported MP4/WebM file.'));
    const abort = () => finish(signal.reason instanceof Error ? signal.reason : abortError());
    const timer = setTimeout(() => finish(new Error(message)), timeout);
    events.forEach(name => video.addEventListener(name, check));
    video.addEventListener('error', fail);
    signal?.addEventListener('abort', abort, {once: true});
    check();
  });
}

async function seek(video, position, signal) {
  throwIfAborted(signal);
  if (Math.abs(video.currentTime - position) < 0.015 && video.readyState >= 2) return;
  video.currentTime = Math.max(0, position);
  await waitFor(video, () => !video.seeking && video.readyState >= 2 && Math.abs(video.currentTime - position) < 0.15,
    ['seeked', 'loadeddata', 'canplay', 'timeupdate'], {signal, timeout: 25000, message: 'The video could not seek to the selected start time.'});
}

/** Reads duration/dimensions, including streamed WebM files with Infinity metadata. */
export async function getVideoMetadata(blobOrUrl, options = {}) {
  const video = makeVideo();
  const urls = [];
  video.muted = true;
  try {
    bindSource(video, blobOrUrl, urls);
    await waitFor(video, () => video.readyState >= 1, ['loadedmetadata', 'durationchange'], options);
    if (!(Number.isFinite(video.duration) && video.duration > 0)) {
      // Some MediaRecorder WebM files only expose duration after an end seek.
      video.currentTime = Number.MAX_SAFE_INTEGER;
      await waitFor(video, () => Number.isFinite(video.duration) && video.duration > 0,
        ['durationchange', 'timeupdate', 'seeked'], {...options, timeout: options.timeout ?? 15000, message: 'This clip does not expose a readable duration. Re-record it or import a finalized MP4/WebM file.'});
    }
    return {duration: video.duration, width: video.videoWidth, height: video.videoHeight};
  } finally {
    disposeVideo(video);
    urls.forEach(url => URL.revokeObjectURL(url));
  }
}

function nextFrame(signal) {
  throwIfAborted(signal);
  return new Promise((resolve, reject) => {
    let settled = false;
    let frame, timer;
    const cleanup = () => {
      cancelAnimationFrame(frame);
      clearTimeout(timer);
      signal?.removeEventListener('abort', cancel);
    };
    const finish = time => {
      if (settled) return;
      settled = true;
      cleanup();
      resolve(time);
    };
    const cancel = () => {
      if (settled) return;
      settled = true;
      cleanup();
      reject(signal.reason instanceof Error ? signal.reason : abortError());
    };
    frame = requestAnimationFrame(finish);
    // Some visible, occluded, or remote windows receive sparse compositor
    // callbacks. Keep media controls/fades on time without changing browser state.
    timer = setTimeout(() => finish(performance.now()), 1000 / 30);
    signal?.addEventListener('abort', cancel, {once: true});
  });
}

function silenceGain(gain, audio) {
  gain.gain.cancelScheduledValues(audio.currentTime);
  gain.gain.setValueAtTime(0, audio.currentTime);
}

/** Shared volume envelope for both live preview and recorded export. */
export function audioGainAt(segment,positionSeconds){
  return fadeAtTime(positionSeconds,segment.answerDuration,segment.fadeDuration)*
    volumeAt(segment.audioKeyframes||[],positionSeconds*1000,segment.volume);
}
function scheduleAudioFade(gain,audio,segment,mediaTime){
  const position=clamp(mediaTime-segment.trimStart,0,segment.answerDuration);
  const now=audio.currentTime,parameter=gain.gain,duration=segment.answerDuration,fade=segment.fadeDuration;
  parameter.cancelScheduledValues(now);
  parameter.setValueAtTime(audioGainAt(segment,position),now);
  // Schedule at exact keyframe boundaries, clip boundaries, and a few fade
  // samples. WebAudio interpolates gains without animation-frame jitter.
  const stops=new Set([duration,fade,duration-fade]);
  for(const key of segment.audioKeyframes||[])stops.add(key.timeMs/1000);
  if(fade>0){for(let i=1;i<8;i++){stops.add(fade*i/8);stops.add(duration-fade*i/8);}}
  for(const t of [...stops].filter(t=>t>position&&t<=duration).sort((a,b)=>a-b))
    parameter.linearRampToValueAtTime(audioGainAt(segment,t),now+t-position);
}

function withDeadline(promise, {signal, timeout, message}) {
  throwIfAborted(signal);
  return new Promise((resolve, reject) => {
    let settled = false;
    const finish = (error, value) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      signal?.removeEventListener('abort', abort);
      error ? reject(error) : resolve(value);
    };
    const abort = () => finish(signal.reason instanceof Error ? signal.reason : abortError());
    const timer = setTimeout(() => finish(new Error(message)), timeout);
    signal?.addEventListener('abort', abort, {once: true});
    Promise.resolve(promise).then(value => finish(null, value), error => finish(error));
  });
}

function wrapText(ctx, text, maxWidth) {
  const paragraphs = String(text).split(/\r?\n/);
  const lines = [];
  for (const paragraph of paragraphs) {
    let line = '';
    for (const word of paragraph.split(/\s+/).filter(Boolean)) {
      const candidate = line ? `${line} ${word}` : word;
      if (ctx.measureText(candidate).width <= maxWidth) { line = candidate; continue; }
      if (line) { lines.push(line); line = ''; }
      if (ctx.measureText(word).width <= maxWidth) { line = word; continue; }
      // User-supplied URLs/unbroken strings must not escape the safe frame.
      for (const character of Array.from(word)) {
        if (line && ctx.measureText(line + character).width > maxWidth) { lines.push(line); line = ''; }
        line += character;
      }
    }
    lines.push(line);
  }
  return lines.length ? lines : [''];
}

function drawCard(ctx, canvas, question, settings) {
  const {width, height} = canvas;
  ctx.globalAlpha = 1;
  ctx.fillStyle = '#000000';
  ctx.fillRect(0, 0, width, height);
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  const maxWidth = width * 0.82;
  const maxHeight = height * 0.58;
  let size = Math.round(Math.min(width * 0.055, height * 0.078));
  const minimumSize = Math.max(16, Math.round(Math.min(width, height) * 0.022));
  let lines;
  do {
    ctx.font = `600 ${size}px Arial, sans-serif`;
    lines = wrapText(ctx, question, maxWidth);
    if (lines.length * size * 1.3 <= maxHeight) break;
    size -= 1;
  } while (size > minimumSize);
  // Scale exceptionally long questions vertically instead of clipping them.
  const lineHeight = size * 1.3;
  const scale = Math.min(1, maxHeight / (lines.length * lineHeight));
  ctx.save();
  ctx.translate(width / 2, height / 2);
  ctx.scale(scale, scale);
  ctx.fillStyle = '#ffffff';
  lines.forEach((line, index) => ctx.fillText(line, 0, (index - (lines.length - 1) / 2) * lineHeight));
  ctx.restore();
  const labelSize = Math.max(16, Math.round(Math.min(width, height) * 0.025));
  ctx.font = `600 ${labelSize}px Arial, sans-serif`;
  ctx.fillStyle = '#c5c5ca';
  if (settings.brand) ctx.fillText(settings.brand.toUpperCase(), width / 2, height * 0.12, maxWidth);
  if (settings.modelName) {
    ctx.font = `400 ${labelSize}px Arial, sans-serif`;
    ctx.fillText(settings.modelName, width / 2, height * 0.88, maxWidth);
  }
}

export const VIDEO_FILTERS = Object.freeze({none:'none',vivid:'saturate(1.45) contrast(1.12)',warm:'sepia(.17) saturate(1.2)',mono:'grayscale(1)',cinematic:'contrast(1.22) saturate(.76) brightness(.9)',vintage:'sepia(.32) contrast(.9) saturate(.85)'});
function drawVideo(ctx, canvas, video, opacity, fit, filter='none') {
  const {width, height} = canvas;
  ctx.globalAlpha = 1;
  ctx.fillStyle = '#000000';
  ctx.fillRect(0, 0, width, height);
  if (video.readyState < 2 || !video.videoWidth || !video.videoHeight) return;
  const ratio = fit === 'cover'
    ? Math.max(width / video.videoWidth, height / video.videoHeight)
    : Math.min(width / video.videoWidth, height / video.videoHeight);
  const drawWidth = video.videoWidth * ratio;
  const drawHeight = video.videoHeight * ratio;
  ctx.globalAlpha = opacity;
  ctx.save();
  if ('filter' in ctx) ctx.filter=VIDEO_FILTERS[filter]||'none';
  ctx.drawImage(video, (width - drawWidth) / 2, (height - drawHeight) / 2, drawWidth, drawHeight);
  ctx.restore();
  ctx.globalAlpha = 1;
}

/** Optional creator titles appear on the footage, not just on black question cards. */
function drawClipOverlay(ctx,canvas,text,position,opacity) {
  if (!text||opacity<=0)return;
  const {width,height}=canvas;
  const x=width/2;
  const y=position==='top'?height*.16:position==='center'?height*.5:height*.83;
  ctx.save();ctx.globalAlpha=opacity;
  const size=Math.max(20,Math.round(Math.min(width*.047,height*.065)));
  ctx.font=`700 ${size}px Arial, sans-serif`;
  const lines=wrapText(ctx,text,width*.81).slice(0,3);
  const lineHeight=size*1.22,top=y-(lines.length-1)*lineHeight/2;
  const maxLineWidth=Math.min(width*.89,Math.max(0,...lines.map(line=>ctx.measureText(line).width))+size*1.5);
  const boxHeight=Math.min(height*.42,lines.length*lineHeight+size);
  ctx.fillStyle='rgba(0,0,0,.58)';
  ctx.fillRect(x-maxLineWidth/2,y-boxHeight/2,maxLineWidth,boxHeight);
  ctx.fillStyle='#fff';ctx.textAlign='center';ctx.textBaseline='middle';
  ctx.shadowColor='rgba(0,0,0,.65)';ctx.shadowBlur=size*.13;
  lines.forEach((line,i)=>ctx.fillText(line,x,top+i*lineHeight,width*.83));
  ctx.restore();
}

function drawTimedTitles(ctx,canvas,titles,timeSeconds,alpha) {
  if (!Array.isArray(titles)) return;
  for(const title of titles){
    if(timeSeconds*1000 >= title.startMs && timeSeconds*1000 < title.startMs+title.durationMs)
      drawClipOverlay(ctx,canvas,title.text,title.position,alpha);
  }
}

function createRecorder(stream, settings) {
  if (typeof MediaRecorder === 'undefined') throw new Error('This browser cannot record video exports. Use a current desktop Chrome, Edge, or Safari browser.');
  let lastError;
  const options = {videoBitsPerSecond: settings.resolution === 1080 ? 8000000 : 4500000, audioBitsPerSecond: 160000};
  const candidates = TYPE_CANDIDATES.filter(type => MediaRecorder.isTypeSupported(type));
  for (const mimeType of [...candidates, '']) {
    try { return new MediaRecorder(stream, {...options, ...(mimeType ? {mimeType} : {})}); }
    catch (error) { lastError = error; }
  }
  throw new Error(`The browser could not start a video encoder. ${lastError?.message || ''}`.trim());
}

function safeCallback(fn, value) {
  if (typeof fn === 'function') fn(value);
}

export class InterviewRenderer {
  constructor(canvas) {
    if (!canvas?.getContext) throw new TypeError('InterviewRenderer needs a canvas element.');
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d', {alpha: false});
    if (!this.ctx) throw new Error('Canvas rendering is unavailable in this browser.');
    this.operation = null;
    this.showCard('Your interview edit', {});
  }

  get isRunning() { return Boolean(this.operation); }
  get isExporting() { return Boolean(this.operation?.exporting); }

  /** Display a static title-card preview when no operation is running. */
  showCard(question, settings = {}) {
    if (this.operation) return;
    const config = normalizeSettings(settings);
    Object.assign(this.canvas, dimensions(config));
    drawCard(this.ctx, this.canvas, String(question || 'Interview question'), config);
  }

  stop() { this.operation?.controller.abort(abortError()); }

  play(segments, settings = {}, callbacks = {}) { return this._run(segments, settings, callbacks, false); }

  export(segments, settings = {}, callbacks = {}) { return this._run(segments, settings, callbacks, true); }

  async _run(inputSegments, inputSettings, callbacks, exporting) {
    if (this.operation) throw new Error('Stop the current preview or export before starting another.');
    if (exporting && document.hidden) throw new Error('Keep the editor tab visible while exporting.');
    const operation = {controller: new AbortController(), exporting};
    this.operation = operation;
    const {signal} = operation.controller;
    const urls = [];
    let video, audio, source, gain, silence, destination, capture, stream, recorder, musicVideo, musicSource, musicGain;
    const detachedVoices=[];
    let recorderError = null;
    let wakeLock;
    let stopped = Promise.resolve();
    const chunks = [];
    const externalAbort = () => operation.controller.abort(callbacks.signal?.reason || abortError('Export cancelled.'));
    const visibility = () => {
      if (exporting && document.hidden) operation.controller.abort(new Error('Export stopped because the editor tab was hidden. Keep this tab visible and export again; your saved takes are unchanged.'));
    };
    callbacks.signal?.addEventListener('abort', externalAbort, {once: true});
    if (callbacks.signal?.aborted) externalAbort();
    document.addEventListener('visibilitychange', visibility);
    try {
      throwIfAborted(signal);
      if (!Array.isArray(inputSegments) || inputSegments.length === 0) throw new Error('Select at least one recorded answer.');
      const AudioContextClass = globalThis.AudioContext || globalThis.webkitAudioContext;
      if (!AudioContextClass) throw new Error('This browser does not support the audio features needed to render an interview.');
      // Run immediately in the click handler, before asynchronous media loading.
      audio = new AudioContextClass();
      await withDeadline(audio.resume(), {signal, timeout: 8000, message: 'Audio playback is blocked. Click Preview or Export again to enable it.'});
      throwIfAborted(signal);
      video = makeVideo();
      video.muted = false;
      source = audio.createMediaElementSource(video);
      gain = audio.createGain();
      gain.gain.value = 0;
      source.connect(gain);
      if (exporting) {
        destination = audio.createMediaStreamDestination();
        gain.connect(destination);
        silence = audio.createConstantSource();
        silence.offset.value = 0;
        silence.connect(destination);
        silence.start();
      } else gain.connect(audio.destination);
      safeCallback(callbacks.onStatus, 'Preparing interview clips…');
      const hydrated = [];
      for (const segment of inputSegments || []) {
        throwIfAborted(signal);
        if (!(finite(segment.duration, finite(segment.trimEnd)) > 0)) {
          const metadata = await getVideoMetadata(segment.src, {signal});
          hydrated.push({...segment, duration: metadata.duration});
        } else hydrated.push(segment);
      }
      const timeline = buildTimeline(hydrated, inputSettings);
      Object.assign(this.canvas, {width: timeline.width, height: timeline.height});
      const music=timeline.settings.background;
      if(music){
        musicVideo=makeVideo();musicVideo.muted=false;
        bindSource(musicVideo,music.url,urls);
        await waitFor(musicVideo,()=>musicVideo.readyState>=1,['loadedmetadata','canplay'],{signal,timeout:20000,message:'Background source could not load.'});
        musicSource=audio.createMediaElementSource(musicVideo);
        musicGain=audio.createGain();musicGain.gain.value=0;
        musicSource.connect(musicGain);
        musicGain.connect(exporting?destination:audio.destination);
      }
      for(const a of timeline.settings.detachedAudio){
        throwIfAborted(signal);
        const track=makeVideo();track.muted=false;
        bindSource(track,a.url,urls);
        await waitFor(track,()=>track.readyState>=1,['loadedmetadata','canplay'],{signal,timeout:20000,message:'A detached audio source could not load.'});
        const trackSource=audio.createMediaElementSource(track),trackGain=audio.createGain();trackGain.gain.value=0;
        trackSource.connect(trackGain);trackGain.connect(exporting?destination:audio.destination);
        detachedVoices.push({track,trackSource,trackGain,a});
      }
      function syncDetachedAudio(globalTime){
        for(const {track,trackGain,a} of detachedVoices){
          const offset=globalTime*1000-a.startMs,trimDuration=a.trimEndMs-a.trimStartMs;
          if(!Number.isFinite(globalTime)||offset<0||offset>=trimDuration){
            trackGain.gain.value=0;if(!track.paused)track.pause();continue;
          }
          const wanted=a.trimStartMs/1000+offset/1000;
          if(!Number.isFinite(track.duration)||wanted>=track.duration-.025){
            trackGain.gain.value=0;if(!track.paused)track.pause();continue;
          }
          if(Math.abs(track.currentTime-wanted)>.38){try{track.currentTime=wanted;}catch{/* Seeking can be transient during decode. */}}
          trackGain.gain.value=volumeAt(a.audioKeyframes,offset,a.volume);
          if(track.paused)void track.play().catch(()=>{trackGain.gain.value=0;});
        }
      }
      function syncMusic(globalTime){
        syncDetachedAudio(globalTime);
        if(!music||!musicVideo||!musicGain)return;
        if(!Number.isFinite(globalTime)||globalTime*1000<music.startMs){
          musicGain.gain.value=0;if(!musicVideo.paused)musicVideo.pause();return;
        }
        const wanted=music.trimStartMs/1000+(globalTime-music.startMs/1000);
        if(wanted>=musicVideo.duration-.05||!Number.isFinite(wanted)){
          musicGain.gain.value=0;if(!musicVideo.paused)musicVideo.pause();return;
        }
        if(Math.abs(musicVideo.currentTime-wanted)>.45){try{musicVideo.currentTime=wanted;}catch{/* Retry after ready. */}}
        musicGain.gain.value=music.volume;
        if(musicVideo.paused)void musicVideo.play().catch(()=>{musicGain.gain.value=0;});
      }
      drawCard(this.ctx, this.canvas, timeline.segments[0].question, timeline.settings);
      if (exporting) {
        if (typeof this.canvas.captureStream !== 'function') throw new Error('This browser cannot export a canvas video. Use a current desktop Chrome, Edge, or Safari browser.');
        capture = this.canvas.captureStream(30);
        stream = new MediaStream([...capture.getVideoTracks(), ...destination.stream.getAudioTracks()]);
        recorder = createRecorder(stream, timeline.settings);
        stopped = new Promise(resolve => recorder.addEventListener('stop', resolve, {once: true}));
        recorder.addEventListener('dataavailable', event => { if (event.data?.size) chunks.push(event.data); });
        recorder.addEventListener('error', event => {
          recorderError = event.error || new Error('The video encoder stopped unexpectedly.');
          operation.controller.abort(recorderError);
        });
        if (navigator.wakeLock?.request) {
          try { wakeLock = await navigator.wakeLock.request('screen'); } catch { /* Optional on unsupported devices. */ }
        }
      }
      let elapsed = 0;
      const report = (phase, segmentIndex, time) => safeCallback(callbacks.onProgress, {
        elapsed: clamp(time, 0, timeline.totalDuration), total: timeline.totalDuration,
        ratio: clamp(time / timeline.totalDuration, 0, 1), segmentIndex, phase,
      });
      for (const segment of timeline.segments) {
        throwIfAborted(signal);
        silenceGain(gain, audio);
        syncMusic(null);
        video.pause();
        if (recorder?.state === 'recording') recorder.pause();
        safeCallback(callbacks.onStatus, `Loading answer ${segment.segmentIndex + 1} of ${timeline.segments.length}…`);
        bindSource(video, segment.src, urls);
        await waitFor(video, () => video.readyState >= 1, ['loadedmetadata', 'durationchange'], {signal});
        if (Number.isFinite(video.duration) && segment.trimStart >= video.duration) throw new Error(`Answer ${segment.segmentIndex + 1} starts after the end of its recording.`);
        await seek(video, segment.trimStart, signal);
        // Production footage can run directly into the next clip. Interview
        // cards still use the same measured timeline as preview and export.
        if (segment.showCard) {
          drawCard(this.ctx, this.canvas, segment.question, timeline.settings);
          if (recorder?.state === 'inactive') recorder.start(1000);
          else if (recorder?.state === 'paused') recorder.resume();
          safeCallback(callbacks.onStatus, `Title ${segment.segmentIndex + 1} of ${timeline.segments.length}`);
          const cardStart = performance.now();
          while ((performance.now() - cardStart) / 1000 < timeline.settings.cardDuration) {
            throwIfAborted(signal);
            drawCard(this.ctx, this.canvas, segment.question, timeline.settings);
            syncMusic(elapsed + Math.min(timeline.settings.cardDuration, (performance.now()-cardStart)/1000));
            report('card', segment.segmentIndex, elapsed + Math.min(timeline.settings.cardDuration, (performance.now() - cardStart) / 1000));
            await nextFrame(signal);
          }
          elapsed += timeline.settings.cardDuration;
        }
        safeCallback(callbacks.onStatus, `Answer ${segment.segmentIndex + 1} of ${timeline.segments.length}`);
        // Decoder startup is preparation, not additional title-card footage.
        if (recorder?.state === 'recording') recorder.pause();
        try { await withDeadline(video.play(), {signal, timeout: 20000, message: 'The selected answer did not start playing.'}); }
        catch (error) {
          throwIfAborted(signal);
          throw new Error(`Video playback was blocked. Click Preview or Export again. ${error?.message || ''}`.trim());
        }
        scheduleAudioFade(gain, audio, segment, video.currentTime);
        drawVideo(this.ctx, this.canvas, video, fadeAtTime(0, segment.answerDuration, segment.fadeDuration), timeline.settings.fit,segment.filter);
        drawClipOverlay(this.ctx,this.canvas,segment.overlayText,segment.overlayPosition,fadeAtTime(0,segment.answerDuration,segment.fadeDuration));
        drawTimedTitles(this.ctx,this.canvas,timeline.settings.timedTitles,elapsed,1);
        syncMusic(elapsed);
        if (recorder?.state === 'inactive') recorder.start(1000);
        else if (recorder?.state === 'paused') recorder.resume();
        let lastTime = video.currentTime;
        let lastAdvance = performance.now();
        while (!video.ended && video.currentTime < segment.trimEnd - 0.015) {
          throwIfAborted(signal);
          if (video.readyState < 3 && video.currentTime < segment.trimEnd - 0.12) {
            // Exclude network buffering from the rendered movie as well.
            video.pause();
            silenceGain(gain, audio);
            syncMusic(null);
            if (recorder?.state === 'recording') recorder.pause();
            safeCallback(callbacks.onStatus, `Buffering answer ${segment.segmentIndex + 1}…`);
            await waitFor(video, () => video.readyState >= 3, ['canplay', 'canplaythrough', 'progress', 'loadeddata'], {signal, timeout: 30000, message: 'The answer could not finish buffering. Check the connection and try again.'});
            await withDeadline(video.play(), {signal, timeout: 15000, message: 'The answer could not resume after buffering.'});
            scheduleAudioFade(gain, audio, segment, video.currentTime);
            if (recorder?.state === 'paused') recorder.resume();
            lastAdvance = performance.now();
            safeCallback(callbacks.onStatus, `Answer ${segment.segmentIndex + 1} of ${timeline.segments.length}`);
          }
          const clipTime = clamp(video.currentTime - segment.trimStart, 0, segment.answerDuration);
          const alpha = fadeAtTime(clipTime, segment.answerDuration, segment.fadeDuration);
          drawVideo(this.ctx, this.canvas, video, alpha, timeline.settings.fit,segment.filter);
          drawClipOverlay(this.ctx,this.canvas,segment.overlayText,segment.overlayPosition,alpha);
          drawTimedTitles(this.ctx,this.canvas,timeline.settings.timedTitles,elapsed+clipTime,alpha);
          syncMusic(elapsed+clipTime);
          report('answer', segment.segmentIndex, elapsed + clipTime);
          if (Math.abs(video.currentTime - lastTime) > 0.005) { lastTime = video.currentTime; lastAdvance = performance.now(); }
          if (performance.now() - lastAdvance > 20000) throw new Error(`Answer ${segment.segmentIndex + 1} stopped playing. Check the recording and try again.`);
          await nextFrame(signal);
        }
        video.pause();
        syncMusic(null);
        silenceGain(gain, audio);
        this.ctx.globalAlpha = 1;
        this.ctx.fillStyle = '#000000';
        this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
        elapsed += segment.answerDuration;
        report('answer', segment.segmentIndex, elapsed);
        // Do not leave the recorder running while awaiting another paint/source.
        if (recorder?.state === 'recording') recorder.pause();
      }
      throwIfAborted(signal);
      let result = {duration: timeline.totalDuration};
      if (recorder) {
        recorder.stop();
        await withDeadline(stopped, {signal, timeout: 10000, message: 'The browser did not finalize the video export. Please try again.'});
        if (recorderError) throw recorderError;
        const mimeType = recorder.mimeType || chunks[0]?.type || 'video/webm';
        const blob = new Blob(chunks, {type: mimeType});
        if (!blob.size) throw new Error('The browser produced an empty video. Please try again.');
        result = {...result, blob, mimeType, extension: mimeType.includes('mp4') ? 'mp4' : 'webm'};
      }
      report('complete', timeline.segments.length - 1, timeline.totalDuration);
      safeCallback(callbacks.onStatus, exporting ? 'Video export is ready.' : 'Preview finished.');
      safeCallback(callbacks.onEnded, result);
      return result;
    } finally {
      if (recorder && recorder.state !== 'inactive') { try { recorder.stop(); } catch { /* Already stopped. */ } }
      if (gain && audio) { try { silenceGain(gain, audio); } catch { /* Context closed. */ } }
      disposeVideo(video);
      disposeVideo(musicVideo);
      for(const {track,trackSource,trackGain} of detachedVoices){disposeVideo(track);try{trackSource.disconnect();trackGain.disconnect();}catch{/* Already disposed. */}}
      try { source?.disconnect(); gain?.disconnect(); musicSource?.disconnect();musicGain?.disconnect(); silence?.stop(); silence?.disconnect(); } catch { /* Cleanup is idempotent. */ }
      stream?.getTracks().forEach(track => track.stop());
      capture?.getTracks().forEach(track => track.stop());
      destination?.stream.getTracks().forEach(track => track.stop());
      urls.forEach(url => URL.revokeObjectURL(url));
      try { await wakeLock?.release(); } catch { /* Released by the browser. */ }
      try { await audio?.close(); } catch { /* Context already closed. */ }
      document.removeEventListener('visibilitychange', visibility);
      callbacks.signal?.removeEventListener('abort', externalAbort);
      if (this.operation === operation) this.operation = null;
    }
  }
}
