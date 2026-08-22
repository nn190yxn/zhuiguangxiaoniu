import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const mediaAdapter = require('../cloudrun/media-adapter/index.js');

function sha256(value) {
  return createHash('sha256').update(value).digest('hex');
}

function createAudioFixture(seed = 1, chunkCount = 3) {
  const chunks = Array.from({ length: chunkCount }, (_, index) => Buffer.from(`audio-${seed}-chunk-${index + 1}`));
  const content = Buffer.concat([Buffer.from('RIFF0000WAVE', 'ascii'), ...chunks]);
  return { content, sha256: sha256(content) };
}

function createSession() {
  const idempotency = new Map();
  const assets = new Map();
  let nextAudioAssetId = 100;

  function idempotent(operation, handler) {
    const digest = sha256(JSON.stringify(operation.body));
    const previous = idempotency.get(operation.idempotency_key);
    if (previous) {
      if (previous.digest !== digest) {
        return { statusCode: 409, code: 'idempotency_key_reuse', body: previous.body };
      }
      if (operation.endpoint === '/drill/v2/audio-chunks.php') return { ...previous.body, idempotent_replay: true };
      return previous.body;
    }
    const body = handler();
    if (!body.statusCode || body.statusCode < 400) idempotency.set(operation.idempotency_key, { digest, body });
    return body;
  }

  function forward(operation) {
    return idempotent(operation, () => {
      if (operation.endpoint === '/drill/v2/audio-assets.php') {
        const audioAssetId = nextAudioAssetId;
        nextAudioAssetId += 1;
        assets.set(audioAssetId, {
          audio_asset_id: audioAssetId,
          checksum: operation.body.checksum,
          byte_size: operation.body.byte_size,
          attempt_id: operation.body.attempt_id,
          status_version: null,
          status: 'uploading',
          chunks: new Map(),
          finalized: null
        });
        return { audio_asset_id: audioAssetId, status: 'uploading' };
      }

      const asset = assets.get(operation.body.audio_asset_id);
      if (!asset) return { statusCode: 404, code: 'audio_asset_missing' };

      if (operation.endpoint === '/drill/v2/audio-chunks.php') {
        const previous = asset.chunks.get(operation.body.chunk_no);
        if (previous && previous.checksum !== operation.body.checksum) {
          return { statusCode: 409, code: 'chunk_content_conflict' };
        }
        asset.chunks.set(operation.body.chunk_no, {
          checksum: operation.body.checksum,
          byte_size: operation.body.byte_size,
          content: operation.chunk_content
        });
        return { chunk_no: operation.body.chunk_no, idempotent_replay: Boolean(previous) };
      }

      if (operation.endpoint === '/drill/v2/turns/finalize.php') {
        if (asset.status_version !== null && asset.status_version !== operation.body.status_version) {
          return { statusCode: 409, code: 'status_version_conflict' };
        }
        const expected = operation.body.expected_chunks;
        const missing = [];
        for (let chunkNo = 1; chunkNo <= expected; chunkNo += 1) {
          if (!asset.chunks.has(chunkNo)) missing.push(chunkNo);
        }
        if (missing.length) return { statusCode: 400, code: 'missing_chunks', missing };
        if (operation.body.provider === 'timeout_provider') {
          asset.status = 'transcription_timeout';
          return { status: 'retry_pending', status_resource: `/api/drill/v2/attempt-status.php?attempt_id=${asset.attempt_id}` };
        }
        asset.status = 'uploaded';
        asset.status_version = operation.body.status_version;
        asset.finalized = {
          chunk_numbers: Array.from(asset.chunks.keys()).sort((a, b) => a - b),
          transcript: operation.body.final_transcript_text || Array.from(asset.chunks.entries()).sort((a, b) => a[0] - b[0]).map(([, chunk]) => chunk.checksum).join('\n')
        };
        return { status: 'completed', chunk_numbers: asset.finalized.chunk_numbers, content: asset.finalized.transcript };
      }

      return { statusCode: 404, code: 'unknown_endpoint' };
    });
  }

  return { assets, forward };
}

function createVerified(content, assetKey = 'drill-cloud-path-key') {
  return mediaAdapter.verifyPayload({
    asset_key: assetKey,
    purpose: 'drill_audio',
    business_type: 'drill_attempt',
    business_id: '55',
    mime_type: 'audio/wav',
    sha256: sha256(content),
    content_base64: content.toString('base64')
  });
}

test('Drill 云路径覆盖幂等重试、冲突、缺失分片、重复分片和 AI 超时', async () => {
  const content = Buffer.concat([Buffer.from('RIFF0000WAVE', 'ascii'), Buffer.alloc(5 * 1024 * 1024 + 32, 7)]);
  const fixture = { content, sha256: sha256(content) };
  const verified = createVerified(fixture.content);
  const session = createSession();
  const operations = mediaAdapter.buildDrillV2Operations(verified, { attempt_id: 55, status_version: 2, final_transcript_text: '最终文本' });

  const asset = session.forward(operations[0]);
  const assetReplay = session.forward(operations[0]);
  assert.equal(assetReplay.audio_asset_id, asset.audio_asset_id);

  const chunkOne = { ...operations[1], body: { ...operations[1].body, audio_asset_id: asset.audio_asset_id } };
  const chunkTwo = { ...operations[2], body: { ...operations[2].body, audio_asset_id: asset.audio_asset_id } };
  assert.equal(session.forward(chunkOne).chunk_no, 1);
  assert.equal(session.forward(chunkOne).idempotent_replay, true);

  const conflictingChunk = { ...chunkOne, body: { ...chunkOne.body, checksum: sha256('different') } };
  assert.equal(session.forward(conflictingChunk).code, 'idempotency_key_reuse');

  const earlyFinalize = { ...operations.at(-1), body: { ...operations.at(-1).body, audio_asset_id: asset.audio_asset_id } };
  assert.deepEqual(session.forward(earlyFinalize).missing, [2]);

  assert.equal(session.forward(chunkTwo).chunk_no, 2);
  const final = session.forward(earlyFinalize);
  assert.equal(final.status, 'completed');
  assert.deepEqual(final.chunk_numbers, [1, 2]);
  assert.equal(session.forward(earlyFinalize).status, 'completed');

  const statusConflict = { ...earlyFinalize, idempotency_key: 'drill-audio:drill-cloud-path-key:finalize-v2', body: { ...earlyFinalize.body, status_version: 3 } };
  assert.equal(session.forward(statusConflict).code, 'status_version_conflict');

  const timeoutSession = createSession();
  const timeoutAsset = timeoutSession.forward(operations[0]);
  timeoutSession.forward({ ...chunkOne, body: { ...chunkOne.body, audio_asset_id: timeoutAsset.audio_asset_id } });
  timeoutSession.forward({ ...chunkTwo, body: { ...chunkTwo.body, audio_asset_id: timeoutAsset.audio_asset_id } });
  const timeoutFinalize = { ...earlyFinalize, body: { ...earlyFinalize.body, audio_asset_id: timeoutAsset.audio_asset_id, provider: 'timeout_provider' } };
  assert.equal(timeoutSession.forward(timeoutFinalize).status, 'retry_pending');
});

test('Drill 媒体协议属性：随机分片顺序和重试保持摘要、集合和状态版本契约', () => {
  for (let seed = 1; seed <= 12; seed += 1) {
    const fixture = createAudioFixture(seed, 4);
    const verified = createVerified(fixture.content, `drill-property-${seed}`);
    const session = createSession();
    const operations = mediaAdapter.buildDrillV2Operations(verified, { attempt_id: 55 + seed, status_version: seed });
    const asset = session.forward(operations[0]);
    const chunkOperations = operations.slice(1, -1).map((operation) => ({ ...operation, body: { ...operation.body, audio_asset_id: asset.audio_asset_id } }));
    const indexes = chunkOperations.map((_, index) => index);
    const order = indexes.sort((left, right) => ((left * 31 + seed * 17) % 7) - ((right * 31 + seed * 17) % 7));

    for (const index of order) {
      const result = session.forward(chunkOperations[index]);
      assert.equal(result.chunk_no, chunkOperations[index].body.chunk_no);
      assert.equal(chunkOperations[index].body.checksum, sha256(chunkOperations[index].chunk_content));
      if (index % 2 === 0) assert.equal(session.forward(chunkOperations[index]).idempotent_replay, true);
    }

    const final = session.forward({ ...operations.at(-1), body: { ...operations.at(-1).body, audio_asset_id: asset.audio_asset_id } });
    assert.equal(final.status, 'completed');
    assert.deepEqual(final.chunk_numbers, chunkOperations.map((_, index) => index + 1));
    assert.equal(session.assets.get(asset.audio_asset_id).status_version, seed);
    assert.equal(session.assets.get(asset.audio_asset_id).checksum, fixture.sha256);
  }
});
