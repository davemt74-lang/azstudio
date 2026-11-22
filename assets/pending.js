function openDB() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('azhottie-pending-recordings', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('drafts', { keyPath: 'id' });
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}
export async function pendingPut(record) {
  const db = await openDB();
  try { await new Promise((resolve, reject) => { const tx = db.transaction('drafts', 'readwrite'); tx.objectStore('drafts').put(record); tx.oncomplete = resolve; tx.onerror = () => reject(tx.error); }); }
  finally { db.close(); }
}
export async function pendingDelete(id) {
  const db = await openDB();
  try { await new Promise((resolve, reject) => { const tx = db.transaction('drafts', 'readwrite'); tx.objectStore('drafts').delete(id); tx.oncomplete = resolve; tx.onerror = () => reject(tx.error); }); }
  finally { db.close(); }
}
export async function pendingList(interviewId) {
  const db = await openDB();
  try { return await new Promise((resolve, reject) => { const req = db.transaction('drafts').objectStore('drafts').getAll(); req.onsuccess = () => resolve(req.result.filter(x => x.interviewId === interviewId)); req.onerror = () => reject(req.error); }); }
  finally { db.close(); }
}

