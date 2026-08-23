const api = require('./api');
const media = require('./media');

const BASE = '/drill/v2';
const RECOVERY_KEY = 'drill_v2_active_attempt';

function idempotencyKey() {
  return `mp-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function request(path, method = 'GET', data = {}, headers = {}) {
  return api.request({
    url: `${BASE}${path}`,
    method,
    data,
    header: headers
  });
}

function mutation(path, data) {
  return request(path, 'POST', data, { 'Idempotency-Key': idempotencyKey() });
}

function unwrap(response) {
  if (!response || response.code !== 0) {
    throw new Error((response && response.message) || '演练服务暂时不可用');
  }
  return response.data || {};
}

function rememberAttempt(attempt) {
  if (attempt && attempt.attempt_id) {
    wx.setStorageSync(RECOVERY_KEY, attempt);
  }
}

function activeAttempt() {
  return wx.getStorageSync(RECOVERY_KEY) || null;
}

async function loadDashboard() {
  const [home, catalog, assignments] = await Promise.all([
    request('/home.php'),
    request('/catalog.php'),
    request('/assignments.php'),
  ]);
  return {
    home: unwrap(home),
    catalog: unwrap(catalog),
    assignments: unwrap(assignments),
    progress: { mastery: [], growth_levels: [] }
  };
}

function loadResults(attemptId) {
  return request(`/results.php${attemptId ? `?attempt_id=${attemptId}` : ''}`).then(unwrap);
}

function loadLearning() {
  return request('/learning.php').then(unwrap);
}

function loadAttemptStatus(attemptId) {
  return request(`/attempt-status.php?attempt_id=${encodeURIComponent(attemptId)}`).then(unwrap);
}

function isRetryPending(result) {
  return result && result.status === 'retry_pending' && result.status_resource;
}

async function recoverAudioTranscription(audioAssetId, action, input = {}) {
  return unwrap(await mutation('/audio-recovery.php', {
    audio_asset_id: audioAssetId,
    action,
    reason: input.reason || '',
    text_content: input.text_content || input.textContent || ''
  }));
}

async function resumeActiveAttempt() {
  const saved = activeAttempt();
  if (!saved || !saved.attempt_id) return null;
  const data = unwrap(await mutation('/attempts.php', { action: 'resume', attempt_id: saved.attempt_id }));
  const attempt = data.attempt || data;
  rememberAttempt(attempt);
  return data;
}

async function createAttempt(input) {
  const data = unwrap(await mutation('/attempts.php', input));
  rememberAttempt(data.attempt || data);
  return data;
}

async function endAttempt(attemptId, statusVersion) {
  const data = unwrap(await mutation('/attempts.php', {
    action: 'end',
    attempt_id: attemptId,
    status_version: statusVersion
  }));
  rememberAttempt(data.attempt || data);
  return data;
}

async function submitTextTurn(attemptId, statusVersion, content) {
  return unwrap(await mutation('/turns.php', {
    attempt_id: attemptId,
    status_version: statusVersion,
    content
  }));
}

function toBase64(buffer) {
  const bytes = new Uint8Array(buffer);
  let binary = '';
  for (let index = 0; index < bytes.length; index += 0x8000) {
    binary += String.fromCharCode.apply(null, bytes.subarray(index, index + 0x8000));
  }
  return wx.arrayBufferToBase64(binaryToBuffer(binary));
}

function binaryToBuffer(binary) {
  const bytes = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index += 1) bytes[index] = binary.charCodeAt(index);
  return bytes.buffer;
}

// The backend validates SHA-256 for both assets and chunks, so this stays local and deterministic.
function sha256(buffer) {
  const bytes = new Uint8Array(buffer);
  const words = [];
  for (let i = 0; i < bytes.length; i += 1) words[i >> 2] = (words[i >> 2] || 0) | (bytes[i] << (24 - (i % 4) * 8));
  const bitLength = bytes.length * 8;
  words[bitLength >> 5] = (words[bitLength >> 5] || 0) | (0x80 << (24 - (bitLength % 32)));
  words[((bitLength + 64 >> 9) << 4) + 15] = bitLength;
  const hash = [1779033703, -1150833019, 1013904242, -1521486534, 1359893119, -1694144372, 528734635, 1541459225];
  const constants = [1116352408,1899447441,-1245643825,-373957723,961987163,1508970993,-1841331548,-1424204075,-670586216,310598401,607225278,1426881987,1925078388,-2132889090,-1680079193,-1046744716,-459576895,-272742522,264347078,604807628,770255983,1249150122,1555081692,1996064986,-1740746414,-1473132947,-1341970488,-1084653625,-958395405,-710438585,113926993,338241895,666307205,773529912,1294757372,1396182291,1695183700,1986661051,-2117940946,-1838011259,-1564481375,-1474664885,-1035236496,-949202525,331885711,955982468,1383246668,1322822218,1706088902,2064862799,-1017804583,-1154359803,-841459109,-837364680,-553551289,-430227734,-1907901428,-1767536559,-1674236239,-1090935817,-965641998];
  for (let offset = 0; offset < words.length; offset += 16) {
    const work = words.slice(offset, offset + 16);
    for (let i = 16; i < 64; i += 1) { const a = work[i - 15]; const b = work[i - 2]; work[i] = (((a >>> 7 | a << 25) ^ (a >>> 18 | a << 14) ^ a >>> 3) + work[i - 7] + ((b >>> 17 | b << 15) ^ (b >>> 19 | b << 13) ^ b >>> 10) + work[i - 16]) | 0; }
    let [a,b,c,d,e,f,g,h] = hash;
    for (let i = 0; i < 64; i += 1) { const s1 = (e >>> 6 | e << 26) ^ (e >>> 11 | e << 21) ^ (e >>> 25 | e << 7); const choice = (e & f) ^ (~e & g); const t1 = (h + s1 + choice + constants[i] + work[i]) | 0; const s0 = (a >>> 2 | a << 30) ^ (a >>> 13 | a << 19) ^ (a >>> 22 | a << 10); const majority = (a & b) ^ (a & c) ^ (b & c); h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + s0 + majority) | 0; }
    hash[0] = (hash[0] + a) | 0; hash[1] = (hash[1] + b) | 0; hash[2] = (hash[2] + c) | 0; hash[3] = (hash[3] + d) | 0; hash[4] = (hash[4] + e) | 0; hash[5] = (hash[5] + f) | 0; hash[6] = (hash[6] + g) | 0; hash[7] = (hash[7] + h) | 0;
  }
  return hash.map(value => (`00000000${(value >>> 0).toString(16)}`).slice(-8)).join('');
}

async function uploadAudioTurn(filePath, attemptId, statusVersion, durationMs, textFallback = '') {
  const buffer = await new Promise((resolve, reject) => wx.getFileSystemManager().readFile({ filePath, success: result => resolve(result.data), fail: reject }));
  const bytes = buffer.byteLength;
  const checksum = sha256(buffer);
  const cloudMedia = await media.uploadAndRegister({
    filePath,
    purpose: 'drill_audio',
    businessType: 'drill_attempt',
    businessId: attemptId,
    mime_type: 'audio/mpeg',
    byte_size: bytes,
    sha256: checksum,
    idempotencyKey: api.createIdempotencyKey(`drill_audio_${attemptId}_${statusVersion}`)
  });
  const asset = unwrap(await mutation('/audio-assets.php', {
    attempt_id: attemptId,
    mime_type: 'audio/mpeg',
    byte_size: bytes,
    checksum,
    duration_ms: durationMs,
    consent_status: 'not_required',
    cloud_media: media.normalizeMediaDescriptor(cloudMedia, 'drill_audio'),
    cloud_file_id: cloudMedia.fileID || cloudMedia.file_id || '',
    cloud_media_asset_key: cloudMedia.asset_key || ''
  }));
  const audioAssetId = asset.audio_asset_id || asset.id;
  const chunkSize = 5 * 1024 * 1024;
  const count = Math.ceil(bytes / chunkSize);
  for (let index = 0; index < count; index += 1) {
    const chunk = buffer.slice(index * chunkSize, Math.min(bytes, (index + 1) * chunkSize));
    await mutation('/audio-chunks.php', { audio_asset_id: audioAssetId, chunk_no: index + 1, checksum: sha256(chunk), byte_size: chunk.byteLength, content_base64: toBase64(chunk), cloud_media_asset_key: cloudMedia.asset_key || '' });
  }
  return unwrap(await mutation('/turns/finalize.php', { audio_asset_id: audioAssetId, attempt_id: attemptId, status_version: statusVersion, expected_chunks: count, provider: 'wechat_recorder', final_transcript_text: textFallback, cloud_media_asset_key: cloudMedia.asset_key || '' }));
}

module.exports = { activeAttempt, createAttempt, endAttempt, isRetryPending, loadAttemptStatus, loadDashboard, loadLearning, loadResults, recoverAudioTranscription, request, resumeActiveAttempt, submitTextTurn, unwrap, uploadAudioTurn };
