import test from 'node:test';
import assert from 'node:assert/strict';
import { HostPhoneFeeds, PhoneLiveCamera } from '../assets/phone-live.js';

class Track {
  constructor(kind = 'video') { this.kind = kind; this.readyState = 'live'; this.stopped = false; }
  addEventListener() {}
  stop() { this.stopped = true; this.readyState = 'ended'; }
}
class Stream {
  constructor(tracks = []) { this.tracks = tracks; }
  getTracks() { return this.tracks; }
  getVideoTracks() { return this.tracks.filter(track => track.kind === 'video'); }
  getAudioTracks() { return this.tracks.filter(track => track.kind === 'audio'); }
  addTrack(track) { this.tracks.push(track); }
  removeTrack(track) { this.tracks = this.tracks.filter(value => value !== track); }
}
class Peer {
  constructor() { this.connectionState = 'new'; this.iceGatheringState = 'complete'; this.tracks = []; Peer.instances.push(this); }
  addEventListener() {} removeEventListener() {}
  addTrack(track) { this.tracks.push(track); }
  async createOffer() { return { type: 'offer', sdp: 'v=0\r\nphone-offer' }; }
  async createAnswer() { return { type: 'answer', sdp: 'v=0\r\nhost-answer' }; }
  async setLocalDescription(description) { this.localDescription = description; }
  async setRemoteDescription(description) { this.currentRemoteDescription = description; }
  close() { this.connectionState = 'closed'; }
}
Peer.instances = [];
globalThis.RTCPeerConnection = Peer; globalThis.MediaStream = Stream;
const offer = (number = 1) => ({ id: number.toString(16).padStart(32, '0'), nonce: (number + 50).toString(16).padStart(32, '0'), label: `Phone ${number}`, offer: 'v=0\r\nphone-offer' });

test('host exposes a phone source only after connection is actually connected', async () => {
  const added = [], ended = [];
  const host = new HostPhoneFeeds({ request: async () => ({}), onStream: (...args) => added.push(args), onRemove: id => ended.push(id) });
  try {
    const value = offer(); await host.connect(value, []);
    const peer = host.peers.get(value.id).peer, track = new Track();
    peer.ontrack({ track }); assert.equal(added.length, 0);
    peer.connectionState = 'connected'; peer.onconnectionstatechange(); assert.equal(added.length, 1);
    peer.connectionState = 'disconnected'; peer.onconnectionstatechange();
    assert.deepEqual(ended, [], 'brief network losses should retain the source during a grace period');
    assert.equal(track.stopped, false);
    peer.connectionState = 'connected'; peer.onconnectionstatechange();
    assert.deepEqual(ended, [], 'restored video should remain connected');
    peer.connectionState = 'failed'; peer.onconnectionstatechange();
    assert.deepEqual(ended, [value.id]); assert.equal(track.stopped, true); assert.equal(host.peers.size, 0);
  } finally { host.stop(); }
});

test('host waits for a video track while keeping opted-in audio separate and caps at eight', async () => {
  const added = [];
  const host = new HostPhoneFeeds({ request: async () => ({}), onStream: (...args) => added.push(args) });
  try {
    host.sync(Array.from({ length: 10 }, (_, index) => offer(index + 1)), []);
    assert.equal(host.peers.size, 8);
    const peer = host.peers.values().next().value.peer;
    const optionalAudio = new Track('audio'); peer.ontrack({ track: optionalAudio }); peer.connectionState = 'connected'; peer.onconnectionstatechange();
    assert.equal(added.length, 0);
    peer.ontrack({ track: new Track('video') });
    assert.equal(added.length, 1);
    assert.equal(added[0][1].getAudioTracks()[0],optionalAudio);
  } finally { host.stop(); }
});

test('phone requests video only and stops tracks when retry budget is exhausted', async () => {
  let constraints; const states = [], track = new Track();
  Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { mediaDevices: { getUserMedia: async value => { constraints = value; return new Stream([track]); } } } });
  const live = new PhoneLiveCamera({ request: async action => action === 'livePoll' ? { answer: null } : {}, getStatus: () => ({ connection: { status: 'approved' }, iceServers: [] }), onState: state => states.push(state) });
  try {
    await live.start('user'); assert.equal(constraints.audio, false); assert.equal(constraints.video.facingMode.ideal, 'user');
    assert.equal(states.some(state => state.state === 'live'), false);
    live.retries = 3; live.retry(live.current, 'network failure');
    assert.equal(track.stopped, true); assert.equal(live.stream, null); assert.match(states.at(-1).message, /still upload footage/);
  } finally { live.stop(); }
});

test('a late camera permission result is closed after the phone page stops sharing', async () => {
  let allow; const track = new Track(), offered = [];
  Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { mediaDevices: { getUserMedia: () => new Promise(resolve => { allow = resolve; }) } } });
  const live = new PhoneLiveCamera({ request: async action => { offered.push(action); return {}; }, getStatus: () => ({ connection: { status: 'approved' } }) });
  const starting = live.start(); live.stop(); allow(new Stream([track])); await starting;
  assert.equal(track.stopped, true); assert.equal(offered.includes('liveOffer'), false);
});
