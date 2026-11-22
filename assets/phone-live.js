// Adapted to interview-scoped approval from Lucky's recording live-camera flow.
export function waitForIce(peer, timeoutMs = 7000) {
  if (peer.iceGatheringState === 'complete') return Promise.resolve();
  return new Promise(resolve => {
    let timer;
    const done = () => { clearTimeout(timer); peer.removeEventListener('icegatheringstatechange', check); resolve(); };
    const check = () => { if (peer.iceGatheringState === 'complete') done(); };
    peer.addEventListener('icegatheringstatechange', check); timer = setTimeout(done, timeoutMs);
  });
}
const nonce = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');

export class PhoneLiveCamera {
  constructor({ request, getStatus, onState = () => {}, onStream = () => {} }) {
    Object.assign(this, { request, getStatus, onState, onStream, stream: null, current: null, epoch: 0, retries: 0, retryTimer: null, starting: false });
  }
  state(state, message) { this.onState({ state, message }); }
  async start(facing = 'environment') {
    this.stop('');
    const epoch = this.epoch;
    if (!globalThis.RTCPeerConnection || !navigator.mediaDevices?.getUserMedia) throw new Error('Live camera sharing needs a supported browser on the HTTPS studio address. You can still upload a recorded clip.');
    if (this.getStatus()?.connection?.status !== 'approved') throw new Error('Wait for the host to approve this phone.');
    this.starting = true; this.state('connecting', 'Opening your camera…');
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: facing }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
      if (epoch !== this.epoch) { stream.getTracks().forEach(track => track.stop()); return; }
      this.stream = stream; this.retries = 0; this.starting = false; this.onStream(stream);
      stream.getVideoTracks().forEach(track => track.addEventListener('ended', () => { if (this.stream === stream) this.stop('The camera stopped. Tap Share live camera to reconnect.'); }, { once: true }));
      await this.connect();
    } catch (error) {
      if (epoch === this.epoch) { this.starting = false; this.stop(error.message); }
      throw error;
    }
  }
  closePeer(notify = true) {
    const item = this.current; this.current = null;
    if (!item) return;
    clearTimeout(item.timer); clearTimeout(item.connectionTimer); clearTimeout(item.lossTimer);
    item.abort.abort(); item.peer.onconnectionstatechange = null; item.peer.close();
    if (notify && item.offered) this.request('liveEnd', { nonce: item.nonce }, {}, { keepalive: true }).catch(() => {});
  }
  stop(message = 'Live camera is off.', notify = true) {
    this.epoch++; this.starting = false; clearTimeout(this.retryTimer); this.retryTimer = null;
    this.closePeer(notify);
    const stream = this.stream; this.stream = null; stream?.getTracks().forEach(track => track.stop()); this.onStream(null);
    this.state(message ? 'off' : 'idle', message);
  }
  retry(item, reason) {
    if (this.current !== item || !this.stream) return;
    if (this.getStatus()?.connection?.status !== 'approved') { this.stop('Phone access ended.'); return; }
    this.closePeer();
    if (++this.retries > 3) { this.stop('Live camera could not connect on this network. You can still upload footage. Tap Share live camera to retry live.'); return; }
    const wait = Math.min(8000, 1500 * 2 ** (this.retries - 1));
    this.state('reconnecting', `Connection interrupted. Reconnecting in ${Math.ceil(wait / 1000)} seconds…`);
    this.retryTimer = setTimeout(() => { this.retryTimer = null; if (this.stream) this.connect().catch(error => this.stop(error.message)); }, wait);
  }
  async connect() {
    if (!this.stream?.getVideoTracks().some(track => track.readyState === 'live')) return;
    const peer = new RTCPeerConnection({ iceServers: this.getStatus()?.iceServers || [] });
    const item = { peer, nonce: nonce(), offered: false, timer: null, connectionTimer: null, lossTimer: null, abort: new AbortController() };
    this.current = item; this.state('connecting', 'Connecting your camera to the interview…');
    peer.onconnectionstatechange = () => {
      if (this.current !== item) return;
      if (peer.connectionState === 'connected') {
        clearTimeout(item.connectionTimer); clearTimeout(item.lossTimer); this.state('live', 'Live video connected to the host.');
      } else if (peer.connectionState === 'failed') this.retry(item, 'the camera connection failed');
      else if (peer.connectionState === 'disconnected') {
        this.state('reconnecting', 'Camera connection interrupted…');
        clearTimeout(item.lossTimer); item.lossTimer = setTimeout(() => { if (peer.connectionState !== 'connected') this.retry(item, 'the connection was interrupted'); }, 2500);
      }
    };
    item.connectionTimer = setTimeout(() => this.retry(item, 'the live connection did not become ready'), 25000);
    try {
      for (const track of this.stream.getVideoTracks()) peer.addTrack(track, this.stream);
      await peer.setLocalDescription(await peer.createOffer()); await waitForIce(peer);
      if (this.current !== item) return;
      await this.request('liveOffer', { nonce: item.nonce, sdp: peer.localDescription.sdp }, {}, { signal: item.abort.signal });
      item.offered = true;
      const heartbeat = async () => {
        if (this.current !== item) return;
        try {
          const response = await this.request('livePoll', null, { nonce: item.nonce }, { signal: item.abort.signal });
          if (this.current !== item) return;
          if (response.answer && !peer.currentRemoteDescription) await peer.setRemoteDescription({ type: 'answer', sdp: response.answer });
          if (this.current === item) item.timer = setTimeout(heartbeat, 1800);
        } catch (error) {
          if (this.current !== item) return;
          if ([401, 403].includes(error.status) || error.code === 'host_offline') this.stop(error.message);
          else this.retry(item, error.message);
        }
      };
      await heartbeat();
    } catch (error) {
      if (this.current !== item) return;
      if ([401, 403].includes(error.status) || error.code === 'host_offline') this.stop(error.message);
      else this.retry(item, error.message);
    }
  }
}

export class HostPhoneFeeds {
  constructor({ request, onView = () => {}, onRemove = () => {}, onStream = () => {} }) {
    Object.assign(this, { request, onView, onRemove, onStream, peers: new Map(), blocked: new Set(), epoch: 0 });
  }
  stop() { this.epoch++; for (const id of [...this.peers.keys()]) this.remove(id); this.blocked.clear(); }
  remove(id, block = false) {
    const item = this.peers.get(id); if (!item) return;
    if (block) this.blocked.add(`${id}:${item.nonce}`);
    this.peers.delete(id); clearTimeout(item.readyTimer); clearTimeout(item.lossTimer); item.abort.abort(); item.peer.onconnectionstatechange = null; item.peer.ontrack = null; item.peer.close();
    item.stream?.getTracks().forEach(track => track.stop()); this.onRemove(id, item.lastError || 'Live video disconnected — reconnecting from phone…');
  }
  sync(offers, iceServers = []) {
    const valid = (offers || []).filter(offer => /^[a-f0-9]{32}$/.test(offer.id || '') && /^[a-f0-9]{32}$/.test(offer.nonce || '') && typeof offer.offer === 'string');
    const seen = new Set(valid.map(offer => offer.id));
    for (const [id, item] of this.peers) if (!seen.has(id) || !valid.some(offer => offer.id === id && offer.nonce === item.nonce)) this.remove(id);
    const keys = new Set(valid.map(offer => `${offer.id}:${offer.nonce}`));
    for (const key of this.blocked) if (!keys.has(key)) this.blocked.delete(key);
    if (!globalThis.RTCPeerConnection) return;
    for (const offer of valid) {
      if (this.peers.has(offer.id) || this.blocked.has(`${offer.id}:${offer.nonce}`)) continue;
      if (this.peers.size >= 8) break;
      this.connect(offer, iceServers).catch(() => {});
    }
  }
  async connect(offer, iceServers) {
    const epoch = this.epoch, peer = new RTCPeerConnection({ iceServers });
    const item = { peer, nonce: offer.nonce, label: offer.label, stream: null, emitted: false, abort: new AbortController(), readyTimer: null, lossTimer: null, lastError: '' };
    this.peers.set(offer.id, item); this.onView(offer.id, { label: offer.label, message: 'Connecting…', stream: null });
    item.readyTimer = setTimeout(() => { if (this.peers.get(offer.id) === item) { item.lastError='Timed out waiting for video. Check phone sharing and TURN relay settings.'; this.remove(offer.id, true); } }, 25000);
    const emit = () => {
      if (this.peers.get(offer.id) !== item || peer.connectionState !== 'connected' || !item.stream?.getVideoTracks().length) return;
      clearTimeout(item.readyTimer); clearTimeout(item.lossTimer); item.lastError='';
      this.onView(offer.id, { label: offer.label, message: 'Live video connected', stream: item.stream });
      if (!item.emitted) { item.emitted = true; this.onStream(offer.id, item.stream, offer.label); }
    };
    peer.ontrack = event => {
      if (this.peers.get(offer.id) !== item || !['video','audio'].includes(event.track.kind)) return;
      // A phone may choose to share microphone audio separately. Maintain one
      // stable stream object so the Director can mix a late-arriving audio track.
      if(!item.stream)item.stream=new MediaStream();
      if(!item.stream.getTracks().some(t=>t===event.track||(t.id&&event.track.id&&t.id===event.track.id)))item.stream.addTrack(event.track);
      event.track.addEventListener('ended', () => {
        if(this.peers.get(offer.id)!==item)return;
        if(event.track.kind==='video')this.remove(offer.id,true);
        else {item.stream.removeTrack(event.track);this.onView(offer.id,{label:offer.label,message:'Phone microphone disconnected',stream:item.stream});}
      }, {once:true});
      if(event.track.kind==='audio')this.onView(offer.id,{label:offer.label,message:'Phone microphone available',stream:item.stream});
      emit();
    };
    peer.onconnectionstatechange = () => {
      if (this.peers.get(offer.id) !== item) return;
      if (peer.connectionState === 'connected') { clearTimeout(item.lossTimer); emit(); }
      else if (peer.connectionState === 'disconnected') {
        this.onView(offer.id, {label:offer.label,message:'Connection interrupted · retrying…',stream:null});
        clearTimeout(item.lossTimer); item.lossTimer=setTimeout(()=>{ if(this.peers.get(offer.id)===item&&peer.connectionState!=='connected'){item.lastError='Phone video disconnected · waiting for phone reconnection';this.remove(offer.id,true);} },5000);
      } else if (['failed','closed'].includes(peer.connectionState)) {item.lastError='WebRTC connection failed. The phone will re-offer; a TURN server may be needed across networks.';this.remove(offer.id,true);}
    };
    try {
      await peer.setRemoteDescription({ type: 'offer', sdp: offer.offer });
      await peer.setLocalDescription(await peer.createAnswer()); await waitForIce(peer);
      if (this.epoch !== epoch || this.peers.get(offer.id) !== item) return;
      await this.request('answer', { id: offer.id, nonce: offer.nonce, sdp: peer.localDescription.sdp }, {}, { signal: item.abort.signal });
    } catch (error) { if (this.peers.get(offer.id) === item) { item.lastError='Camera negotiation failed: '+String(error.message||'unknown error');this.onView(offer.id, { label: offer.label, message: item.lastError, stream: null }); this.remove(offer.id, true); } }
  }
}
