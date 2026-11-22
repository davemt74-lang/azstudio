import test from 'node:test';
import assert from 'node:assert/strict';
import { uploadPhoneDraft } from '../assets/phone-storage.js';

const id = 'a'.repeat(32);
const draft = (extra = {}) => ({ blob: new Blob(['abcdef'], { type: 'video/webm' }), fileName: 'clip.webm', mimeType: 'video/webm', durationMs: 3000, label: 'Phone angle', ...extra });
const pending = (extra = {}) => ({ uploadId: id, status: 'pending', totalBytes: 6, chunkBytes: 2, nextIndex: 0, ...extra });

test('phone upload resumes at the server-committed chunk and preserves exact remaining bytes', async () => {
  const clip = draft({ uploadId: id }), calls = [];
  const result = await uploadPhoneDraft(clip, async (action, body) => {
    if (action === 'upload') return pending({ nextIndex: 1, receivedBytes: 2 });
    if (action === 'uploadChunk') { calls.push([Number(body.get('index')), await body.get('chunk').text()]); return {}; }
    if (action === 'finishUpload') return { saved: true, takeId: 't' };
    throw new Error(`Unexpected action ${action}`);
  });
  assert.equal(result.saved, true); assert.deepEqual(calls, [[1, 'cd'], [2, 'ef']]); assert.equal(clip.blob.size, 6);
});

test('a new phone upload persists its recovery token before begin and its ID before video bytes', async () => {
  const order = [], clip = draft();
  await uploadPhoneDraft(clip, async action => {
    order.push(action);
    if (action === 'beginUpload') return pending();
    if (action === 'finishUpload') return { saved: true };
    return {};
  }, { persist: async stored => { assert.ok(stored.clientToken.length >= 16); if(stored.uploadId){assert.equal(stored.uploadId, id); order.push('persist');}else order.push('persistToken'); } });
  assert.equal(order.indexOf('persistToken') < order.indexOf('beginUpload'), true);
  assert.equal(order.indexOf('persist') < order.indexOf('uploadChunk'), true);
});

test('a lost chunk response retries the same index instead of skipping video bytes', async () => {
  const calls = [], clip = draft(); let dropped = false;
  await uploadPhoneDraft(clip, async (action, body) => {
    if (action === 'beginUpload') return pending();
    if (action === 'uploadChunk') {
      const index = Number(body.get('index')); calls.push(index);
      if (index === 0 && !dropped) { dropped = true; throw new Error('Response lost after server write'); }
      return {};
    }
    return { saved: true };
  }, { retryDelay: 1 });
  assert.deepEqual(calls, [0, 0, 1, 2]);
});

test('a completed upload recovers its receipt after an interrupted finish response', async () => {
  const clip = draft({ uploadId: id }), calls = [];
  const result = await uploadPhoneDraft(clip, async action => {
    calls.push(action);
    if (action === 'upload') return pending({ status: 'completed', nextIndex: 3, takeId: 'saved-take' });
    return { saved: true, takeId: 'saved-take' };
  });
  assert.equal(result.takeId, 'saved-take'); assert.deepEqual(calls, ['upload', 'finishUpload']);
});

test('revoked phone upload fails once and keeps the recovery blob and upload ID', async () => {
  const clip = draft({ uploadId: id }); let chunks = 0;
  await assert.rejects(uploadPhoneDraft(clip, async action => {
    if (action === 'upload') return pending();
    if (action === 'uploadChunk') { chunks++; const error = new Error('Disconnected'); error.status = 403; throw error; }
    throw new Error('Unexpected action');
  }), /Disconnected/);
  assert.equal(chunks, 1); assert.equal(clip.uploadId, id); assert.equal(await clip.blob.text(), 'abcdef');
});

test('cancelling between chunks never finalizes or discards the local clip', async () => {
  const controller = new AbortController(), clip = draft(), calls = [];
  await assert.rejects(uploadPhoneDraft(clip, async action => {
    calls.push(action);
    if (action === 'beginUpload') return pending();
    if (action === 'uploadChunk') { controller.abort(new DOMException('Cancelled', 'AbortError')); return {}; }
    throw new Error('Should not finalize');
  }, { signal: controller.signal }), { name: 'AbortError' });
  assert.equal(calls.includes('finishUpload'), false); assert.equal(clip.blob.size, 6);
});

test('mismatched resumed file size is rejected before any chunks are uploaded', async () => {
  const calls = [];
  await assert.rejects(uploadPhoneDraft(draft({ uploadId: id }), async action => { calls.push(action); return pending({ totalBytes: 8 }); }), /different file/);
  assert.deepEqual(calls, ['upload']);
});

test('an unconfirmed finish response does not report success', async () => {
  const clip = draft();
  await assert.rejects(uploadPhoneDraft(clip, async action => action === 'beginUpload' ? pending() : {}), /not confirmed/);
  assert.equal(clip.blob.size, 6); assert.equal(clip.uploadId, id);
});
