import { getVideoMetadata, supportedRecordingType } from './media-engine.js';

const cancelled = () => new DOMException('Recording cancelled.', 'AbortError');
const notify = (callback, value) => { try { callback(value); } catch { /* A UI error must not discard a take. */ } };
const stopTracks = stream => stream?.getTracks().forEach(track => track.stop());
const stopVideoTracks = stream => stream?.getVideoTracks().forEach(track => track.stop());
const liveVideo = stream => stream?.getVideoTracks().some(track => track.readyState === 'live');

function wait(milliseconds, signal) {
  if (signal.aborted) return Promise.reject(signal.reason || cancelled());
  return new Promise((resolve, reject) => {
    const abort = () => { clearTimeout(timer); reject(signal.reason || cancelled()); };
    const timer = setTimeout(() => { signal.removeEventListener('abort', abort); resolve(); }, milliseconds);
    signal.addEventListener('abort', abort, {once: true});
  });
}

function makeRecorder(stream) {
  const formats = [...new Set([supportedRecordingType(), 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4', ''])];
  let failure;
  for (const mimeType of formats) {
    if (mimeType && !MediaRecorder.isTypeSupported(mimeType)) continue;
    try { return new MediaRecorder(stream, { ...(mimeType ? {mimeType} : {}), videoBitsPerSecond: 5000000, audioBitsPerSecond: 128000 }); }
    catch (error) { failure = error; }
  }
  throw failure || new Error('This browser could not start a video recorder. Import a video file instead.');
}

export class InterviewCapture extends EventTarget {
  constructor(video, {orientation = 'landscape', fit = 'contain'} = {}) {
    super();
    this.video = video;
    this.outputFrame = orientation === 'portrait' ? {width:720,height:1280} : orientation === 'square' ? {width:720,height:720} : {width:1280,height:720};
    this.outputFit = fit === 'cover' ? 'cover' : 'contain';
    this.stream = null;
    this.recorder = null;
    this.busy = false;
    this.answerStartMs = 0;
    this.enableVersion = 0;
    this.operation = null;
    this.activeSource = null;
    this.localSource = null;
    this.activeCameraId = '';
    this.localDevices = [];
    this.remoteCameras = new Map();
    this.switchVersion = 0;
    this.switchTimer = null;
    this.switching = {mode: 'off', intervalSeconds: 30, deviceIds: null};
  }

  async enable(cameraId = '', microphoneId = '') {
    if (!navigator.mediaDevices?.getUserMedia) throw new Error('Camera recording needs an HTTPS address or localhost and a browser with camera support.');
    if (this.busy) throw new Error('Finish the current recording before changing devices.');
    const remote = this.remoteCameras.get(cameraId);
    this.disable();
    const version = this.enableVersion;
    let stream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: remote ? false : {width: {ideal: this.outputFrame.width}, height: {ideal: this.outputFrame.height}, frameRate: {ideal: 30}, ...(cameraId ? {deviceId: {exact: cameraId}} : {facingMode: 'user'})},
        audio: {echoCancellation: true, noiseSuppression: true, ...(microphoneId ? {deviceId: {exact: microphoneId}} : {})},
      });
      if (version !== this.enableVersion) { stopTracks(stream); throw cancelled(); }
      if (!(remote ? liveVideo(remote.stream) && this.remoteCameras.get(cameraId) === remote : liveVideo(stream)) || !stream.getAudioTracks().some(track => track.readyState === 'live')) {
        throw new Error('A working camera and microphone are both required. Check the selected devices and try again.');
      }
      this.stream = stream;
      this.localSource = remote ? null : {deviceId: stream.getVideoTracks()[0]?.getSettings?.().deviceId || cameraId || 'default', label: stream.getVideoTracks()[0]?.label || 'Current camera', stream, remote: false};
      this.activateSource(remote || this.localSource, 'enabled');
      this.video.srcObject = remote?.stream || stream;
      this.video.muted = true;
      this.video.playsInline = true;
      await this.video.play();
      if (version !== this.enableVersion) { stopTracks(stream); throw cancelled(); }
      const devices = navigator.mediaDevices.enumerateDevices ? await navigator.mediaDevices.enumerateDevices().catch(() => []) : [];
      if (version !== this.enableVersion) { stopTracks(stream); throw cancelled(); }
      if (remote && (!liveVideo(remote.stream) || this.remoteCameras.get(cameraId) !== remote)) throw new Error('The selected phone camera disconnected. Reconnect it or choose another camera.');
      this.localDevices = devices.filter(device => device.kind === 'videoinput').slice(0, 8);
      this.emit('statechange', {state: 'ready'});
      return devices;
    } catch (error) {
      stopTracks(stream);
      if (this.stream === stream) {
        this.stream = null;
        this.detachActiveSource();
        this.activeSource = this.localSource = null;
        this.activeCameraId = '';
        this.video.srcObject = null;
      }
      throw error;
    }
  }

  disable() {
    this.enableVersion += 1;
    this.switchVersion += 1;
    clearTimeout(this.switchTimer); this.switchTimer = null;
    this.detachActiveSource();
    if (this.operation?.state === 'recording') this.requestStop(new Error('Recording ended because the camera was disabled.'));
    else if (this.operation?.state === 'countdown') this.operation.controller.abort(cancelled());
    stopVideoTracks(this.localSource?.stream);
    stopTracks(this.stream);
    this.stream = null;
    this.localSource = this.activeSource = null;
    this.activeCameraId = '';
    if (this.video) this.video.srcObject = null;
    this.emit('statechange', {state: 'disabled'});
  }

  hasDevices() {
    return liveVideo(this.activeSource?.stream || this.stream) && this.stream?.getAudioTracks().some(track => track.readyState === 'live');
  }

  emit(type, detail) {
    const event = new Event(type);
    Object.defineProperty(event, 'detail', {value: detail});
    this.dispatchEvent(event);
  }

  detachActiveSource() {
    if (this.activeEnded && this.activeSource) {
      this.activeSource.stream.getVideoTracks().forEach(track => track.removeEventListener('ended', this.activeEnded));
    }
    this.activeEnded = null;
  }

  activateSource(source, reason = 'manual') {
    this.detachActiveSource();
    this.activeSource = source;
    this.activeCameraId = source.deviceId;
    this.activeEnded = () => { if (this.activeSource === source) this.recoverCamera(source.deviceId); };
    source.stream.getVideoTracks().forEach(track => track.addEventListener('ended', this.activeEnded));
    this.emit('cameraswitch', {deviceId: source.deviceId, label: source.label, remote: source.remote, reason});
  }

  getCameraSources() {
    const local = this.localDevices.map(device => ({deviceId: device.deviceId, label: device.label || 'Camera', kind: 'videoinput', remote: false}));
    if (this.localSource && !local.some(device => device.deviceId === this.localSource.deviceId)) {
      local.unshift({deviceId: this.localSource.deviceId, label: this.localSource.label, kind: 'videoinput', remote: false});
    }
    return [...local, ...[...this.remoteCameras.values()].filter(source => liveVideo(source.stream)).map(source => ({deviceId: source.deviceId, label: source.label, kind: 'videoinput', remote: true}))].slice(0, 8);
  }

  addRemoteCamera(id, stream, label = 'Paired phone') {
    if (typeof id !== 'string' || !id || id.length > 160 || !liveVideo(stream)) throw new Error('The phone camera is not ready yet.');
    if (this.localDevices.some(source => source.deviceId === id) || this.localSource?.deviceId === id) throw new Error('This camera identifier is already in use.');
    if (!this.remoteCameras.has(id) && this.getCameraSources().length >= 8) throw new Error('Use no more than eight connected cameras.');
    const source = {deviceId: id, stream, label: String(label).slice(0, 120), remote: true};
    const wasActive = this.activeCameraId === id;
    this.remoteCameras.set(id, source);
    if (wasActive) { void this.switchCamera(id, 'reconnected').catch(error => this.emit('statechange', {state: 'camera-error', message: error.message})); }
    this.scheduleSwitch();
    this.emit('statechange', {state: 'sources-changed'});
    return {deviceId: id, label: source.label, kind: 'videoinput', remote: true};
  }

  removeRemoteCamera(id) {
    this.remoteCameras.delete(id);
    if (this.activeCameraId === id) this.recoverCamera(id);
    this.scheduleSwitch();
    this.emit('statechange', {state: 'sources-changed'});
  }

  configureSwitching({mode = 'off', intervalSeconds = 30, deviceIds = null} = {}) {
    if (!['off', 'interval', 'random'].includes(mode)) throw new Error('Choose manual, interval, or random camera switching.');
    const interval = Number(intervalSeconds);
    if (!Number.isFinite(interval) || interval < 5 || interval > 3600) throw new Error('Camera switching intervals must be between 5 seconds and 60 minutes.');
    if (deviceIds !== null && (!Array.isArray(deviceIds) || deviceIds.length > 8 || deviceIds.some(id => typeof id !== 'string'))) throw new Error('Choose up to eight cameras.');
    if (mode !== 'off' && this.operation?.state === 'recording' && !this.operation.canvas) throw new Error('This browser cannot switch cameras during a take. Stop recording before changing cameras.');
    this.switching = {mode, intervalSeconds: interval, deviceIds: deviceIds === null ? null : [...new Set(deviceIds)]};
    this.scheduleSwitch();
    this.emit('statechange', {state: 'switching-configured', switching: this.switching});
    return this.switching;
  }

  selectedSources() {
    const sources = this.getCameraSources();
    return this.switching.deviceIds === null ? sources : sources.filter(source => this.switching.deviceIds.includes(source.deviceId));
  }

  nextCameraId(random = false) {
    const sources = this.selectedSources();
    const others = sources.filter(source => source.deviceId !== this.activeCameraId);
    if (!others.length) return null;
    if (random) return others[Math.floor(Math.random() * others.length)].deviceId;
    const current = sources.findIndex(source => source.deviceId === this.activeCameraId);
    return sources[(current + 1) % sources.length]?.deviceId || others[0].deviceId;
  }

  scheduleSwitch() {
    clearTimeout(this.switchTimer); this.switchTimer = null;
    if (this.operation?.state !== 'recording' || this.operation.stoppedAt != null || !this.operation.canvas || this.switching.mode === 'off' || this.selectedSources().length < 2) return;
    const delay = this.switching.intervalSeconds * 1000 * (this.switching.mode === 'random' ? 0.5 + Math.random() : 1);
    this.switchTimer = setTimeout(async () => {
      this.switchTimer = null;
      if (this.operation?.state !== 'recording' || this.operation.stoppedAt != null) return;
      const id = this.nextCameraId(this.switching.mode === 'random');
      if (id) {
        try { await this.switchCamera(id, 'automatic'); }
        catch (error) { if (error.name !== 'AbortError') this.emit('statechange', {state: 'camera-error', message: error.message}); }
      }
      this.scheduleSwitch();
    }, delay);
  }

  async switchCamera(deviceId = '', reason = 'manual') {
    if (!this.stream?.getAudioTracks().some(track => track.readyState === 'live')) throw new Error('Enable the camera and microphone before switching cameras.');
    if (this.operation?.state === 'recording' && !this.operation.canvas) throw new Error('This browser cannot switch cameras during a take. Stop recording before changing cameras.');
    const id = deviceId || this.nextCameraId(this.switching.mode === 'random');
    if (!id) throw new Error('Connect or select a second camera first.');
    if (id === this.activeCameraId && this.activeSource === this.remoteCameras.get(id)) return this.getCameraSources().find(source => source.deviceId === id);
    if (id === this.activeCameraId && !this.activeSource?.remote && liveVideo(this.activeSource?.stream)) return this.getCameraSources().find(source => source.deviceId === id);
    const version = ++this.switchVersion, enabled = this.enableVersion;
    let source = this.remoteCameras.get(id), acquired = false;
    try {
      if (!source && this.localSource?.deviceId === id && liveVideo(this.localSource.stream)) source = this.localSource;
      if (!source) {
        if (!this.getCameraSources().some(item => item.deviceId === id && !item.remote)) throw new Error('This camera is no longer connected.');
        const stream = await navigator.mediaDevices.getUserMedia({video: {deviceId: {exact: id}, width: {ideal: this.outputFrame.width}, height: {ideal: this.outputFrame.height}, frameRate: {ideal: 30}}, audio: false});
        acquired = true;
        source = {deviceId: id, stream, label: this.localDevices.find(item => item.deviceId === id)?.label || 'Camera', remote: false};
      }
      if (version !== this.switchVersion || enabled !== this.enableVersion) throw cancelled();
      if (!liveVideo(source.stream) || (source.remote && this.remoteCameras.get(id) !== source)) throw new Error('The selected camera disconnected before switching.');
      const previous = this.activeSource;
      this.video.srcObject = source.stream;
      try { await this.video.play(); }
      catch (error) {
        if (version === this.switchVersion && previous) { this.video.srcObject = previous.stream; void this.video.play().catch(() => {}); }
        throw error;
      }
      if (version !== this.switchVersion || enabled !== this.enableVersion) throw cancelled();
      if (!liveVideo(source.stream) || (source.remote && this.remoteCameras.get(id) !== source)) throw new Error('The selected camera disconnected before switching.');
      const oldLocal = this.localSource;
      this.activateSource(source, reason);
      if (!source.remote) {
        this.localSource = source;
        if (oldLocal && oldLocal !== source) stopVideoTracks(oldLocal.stream);
      }
      this.scheduleSwitch();
      return this.getCameraSources().find(item => item.deviceId === id);
    } catch (error) {
      if (version === this.switchVersion && enabled === this.enableVersion && this.activeSource && this.activeSource !== source) {
        this.video.srcObject = this.activeSource.stream;
        void this.video.play().catch(() => {});
      }
      if (acquired && this.activeSource !== source) stopTracks(source?.stream);
      if (error.name !== 'AbortError' && !liveVideo(this.activeSource?.stream) && this.operation?.state === 'recording') {
        this.requestStop(new Error('The camera disconnected and the replacement could not start. The available recording will be saved as an interrupted take.'));
      }
      throw error;
    }
  }

  recoverCamera(lostId) {
    if (!this.stream || this.activeCameraId !== lostId) return;
    this.emit('statechange', {state: 'camera-reconnecting', message: 'Camera disconnected. Switching to an available camera…'});
    const fallback = this.localSource && this.localSource.deviceId !== lostId && liveVideo(this.localSource.stream)
      ? this.localSource.deviceId : this.getCameraSources().find(source => source.deviceId !== lostId)?.deviceId;
    const failed = cause => {
      if (cause?.name === 'AbortError' || (this.activeCameraId !== lostId && liveVideo(this.activeSource?.stream))) return;
      const error = new Error('The camera disconnected. The available recording will be saved as an interrupted take.');
      if (this.operation?.state === 'countdown') this.operation.controller.abort(error);
      else if (this.operation?.state === 'recording') this.requestStop(error);
      this.emit('statechange', {state: 'camera-error', message: error.message});
    };
    if (!fallback) { failed(); return; }
    void this.switchCamera(fallback, 'fallback').catch(failed);
  }

  prepareRecordingStream(operation) {
    const audio = this.stream.getAudioTracks().filter(track => track.readyState === 'live');
    let canvas, context, stream;
    try {
      canvas = globalThis.document?.createElement('canvas');
      if (!canvas?.captureStream || !globalThis.MediaStream) throw new Error('Canvas capture unavailable');
      const frame=this.outputFrame;
      canvas.width = frame.width; canvas.height = frame.height;
      context = canvas.getContext('2d', {alpha: false});
      if (!context) throw new Error('Canvas unavailable');
      stream = canvas.captureStream(30);
      if (!liveVideo(stream)) throw new Error('Canvas capture unavailable');
      operation.canvas = canvas; operation.canvasStream = stream;
      const combined = new MediaStream([...stream.getVideoTracks(), ...audio]);
      let frameId = null, last = -Infinity;
      const draw = () => {
        const now = performance.now();
        if (now - last < 22) return;
        last = now;
        context.fillStyle = '#000'; context.fillRect(0, 0, frame.width, frame.height);
        const width = this.video.videoWidth, height = this.video.videoHeight;
        if (!width || !height) return;
        const scale = (this.outputFit==='cover'?Math.max:Math.min)(frame.width / width, frame.height / height);
        try { context.drawImage(this.video, (frame.width - width * scale) / 2, (frame.height - height * scale) / 2, width * scale, height * scale); } catch { /* Keep the black frame while a newly selected camera starts. */ }
      };
      const loop = () => { draw(); frameId = globalThis.requestAnimationFrame?.(loop); };
      loop();
      const timer = setInterval(() => { if (performance.now() - last >= 32) draw(); }, 33);
      operation.releaseCanvas = () => { clearInterval(timer); if (frameId != null) globalThis.cancelAnimationFrame?.(frameId); stopVideoTracks(stream); };
      return combined;
    } catch {
      stopVideoTracks(stream);
      operation.canvas = null; operation.canvasStream = null;
      this.emit('statechange', {state: 'switching-unavailable', message: 'This browser records one camera in its native frame. Stop before switching cameras; adjust portrait or square framing in the editor if needed.'});
      return globalThis.MediaStream ? new MediaStream([...(this.activeSource?.stream || this.stream).getVideoTracks(), ...audio]) : this.stream;
    }
  }

  releaseRecordingStream(operation) {
    operation.releaseCanvas?.();
    operation.releaseCanvas = null;
  }

  destroy() {
    this.disable();
    this.remoteCameras.clear();
  }

  async start({countdown = 3, onTimer = () => {}, onState = () => {}} = {}) {
    if (this.busy) throw new Error('A recording is already in progress.');
    if (!this.hasDevices()) throw new Error('Enable a working camera and microphone first.');
    if (!globalThis.MediaRecorder) throw new Error('This browser cannot record video. Import a video file instead.');
    const operation = {
      controller: new AbortController(), state: 'countdown', onTimer, onState,
      startedAt: null, stoppedAt: null, error: null, recorder: null, parts: [],
      timer: null, finalizer: null, finalize: null,
    };
    this.operation = operation;
    this.busy = true;
    this.answerStartMs = 0;
    // The original microphone remains connected for the entire take. Camera loss
    // is handled by the active-source listener and can recover without stopping.
    const tracks = this.stream.getAudioTracks();
    const trackEnded = event => {
      const error = new Error('The ' + (event.target.kind === 'audio' ? 'microphone' : 'camera') + ' disconnected. The available recording will be saved as an interrupted take.');
      if (operation.state === 'countdown') operation.controller.abort(error);
      else if (operation.state === 'recording') this.requestStop(error);
    };
    tracks.forEach(track => track.addEventListener('ended', trackEnded));
    try {
      const seconds = Math.min(10, Math.max(0, Math.floor(Number(countdown) || 0)));
      for (let n = seconds; n > 0; n--) {
        notify(onState, 'Starting in ' + n + '…');
        await wait(1000, operation.controller.signal);
      }
      if (operation.controller.signal.aborted) throw operation.controller.signal.reason || cancelled();
      if (!this.hasDevices()) throw new Error('The camera or microphone disconnected before recording started.');
      const recorderStream = this.prepareRecordingStream(operation);
      const recorder = makeRecorder(recorderStream);
      operation.recorder = this.recorder = recorder;
      operation.state = 'recording';
      operation.startedAt = performance.now();
      this.finished = new Promise((resolve, reject) => {
        let finalized = false;
        operation.finalize = async (forcedError = null) => {
          if (finalized) return;
          finalized = true;
          operation.state = 'finalizing';
          clearInterval(operation.timer);
          clearTimeout(this.switchTimer); this.switchTimer = null;
          clearTimeout(operation.finalizer);
          if (forcedError) operation.error ||= forcedError;
          if (operation.stoppedAt == null) {
            operation.stoppedAt = performance.now();
            operation.error ||= new Error('The recorder stopped unexpectedly. The available part of the take was recovered.');
          }
          const blob = new Blob(operation.parts, {type: recorder.mimeType || operation.parts[0]?.type || 'video/webm'});
          operation.parts = [];
          if (!blob.size) { reject(operation.error || new Error('The recording was empty. Please check the camera and microphone.')); return; }
          let durationMs = Math.max(1, Math.round(operation.stoppedAt - operation.startedAt));
          let durationEstimated = true;
          try {
            const metadata = await getVideoMetadata(blob, {timeout: 4000});
            if (Number.isFinite(metadata.duration) && metadata.duration > 0) {
              durationMs = Math.max(1, Math.round(metadata.duration * 1000));
              durationEstimated = false;
            }
          } catch { /* Retain usable media even when WebM metadata is incomplete. */ }
          resolve({blob, durationMs, durationEstimated, answerStartMs: Math.min(this.answerStartMs, Math.max(0, durationMs - 150)), interrupted: Boolean(operation.error), interruptionReason: operation.error?.message || ''});
        };
        recorder.onstart = () => { if (operation.stoppedAt == null) operation.startedAt = performance.now(); };
        recorder.ondataavailable = event => { if (!finalized && event.data?.size) operation.parts.push(event.data); };
        recorder.onerror = event => this.requestStop(event.error || new Error('The browser could not finish the recording.'));
        recorder.onstop = () => { void operation.finalize(); };
        try {
          recorder.start(1000);
          notify(onState, 'Recording question and answer');
          operation.timer = setInterval(() => notify(onTimer, Math.max(0, (operation.stoppedAt ?? performance.now()) - operation.startedAt)), 100);
          this.emit('statechange', {state: 'recording', switchingAvailable: Boolean(operation.canvas)});
          this.scheduleSwitch();
        } catch (error) {
          finalized = true;
          reject(error);
        }
      });
      return await this.finished;
    } finally {
      clearInterval(operation.timer);
      clearTimeout(operation.finalizer);
      clearTimeout(this.switchTimer); this.switchTimer = null;
      this.releaseRecordingStream(operation);
      tracks.forEach(track => track.removeEventListener('ended', trackEnded));
      if (operation.recorder) {
        operation.recorder.onstart = operation.recorder.ondataavailable = operation.recorder.onerror = operation.recorder.onstop = null;
        if (operation.recorder.state !== 'inactive') { try { operation.recorder.stop(); } catch { /* Recorder is already closing. */ } }
      }
      if (this.operation === operation) {
        this.operation = null;
        this.recorder = null;
        this.busy = false;
        this.emit('statechange', {state: this.hasDevices() ? 'ready' : 'disabled'});
      }
    }
  }

  markAnswerStart() {
    const operation = this.operation;
    if (operation?.state !== 'recording' || operation.stoppedAt != null || operation.recorder?.state !== 'recording') return null;
    this.answerStartMs = Math.round(performance.now() - operation.startedAt);
    return this.answerStartMs;
  }

  requestStop(error = null) {
    const operation = this.operation;
    if (!operation || operation.state !== 'recording') return;
    if (error) {
      operation.error ||= error;
      notify(operation.onState, operation.error.message);
    }
    if (operation.stoppedAt != null) return;
    operation.stoppedAt = performance.now();
    clearTimeout(this.switchTimer); this.switchTimer = null;
    clearInterval(operation.timer);
    notify(operation.onTimer, Math.max(0, operation.stoppedAt - operation.startedAt));
    operation.finalizer = setTimeout(() => { void operation.finalize?.(new Error('The recorder did not finalize normally. The available part of the take was recovered.')); }, 10000);
    if (operation.recorder?.state !== 'inactive') {
      try { operation.recorder.stop(); }
      catch (stopError) { operation.error ||= stopError; }
    }
  }

  stop() {
    if (this.operation?.state === 'countdown') this.operation.controller.abort(cancelled());
    else this.requestStop();
  }
}
