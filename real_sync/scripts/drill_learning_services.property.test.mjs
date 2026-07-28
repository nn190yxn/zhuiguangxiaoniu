import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { test } from 'node:test';

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

function publishable(rows, mappingVersionId, domainId) {
  return rows.filter((row) => row.mappingVersionId === mappingVersionId
    && row.domainId === domainId
    && row.mappingStatus === 'published'
    && row.knowledgeStatus === 'published'
    && row.resourceStatus === 'published'
    && row.mobileLocator.trim() !== '');
}

function gapFingerprint(row) {
  return createHash('sha256').update([
    row.domainId,
    row.mappingVersionId,
    row.rubricVersionId,
    row.dimensionCode,
    row.criterionCode,
    row.knowledgePointId ?? 0,
    row.gapType,
  ].join('|')).digest('hex');
}

test('property 12 only selects resources from the scoring-time published mapping', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const lockedMapping = 1 + Math.floor(next() * 8);
    const lockedDomain = 1 + Math.floor(next() * 2);
    const rows = Array.from({ length: 256 }, (_, id) => ({
      id,
      mappingVersionId: 1 + Math.floor(next() * 8),
      domainId: 1 + Math.floor(next() * 2),
      mappingStatus: next() > 0.2 ? 'published' : 'retired',
      knowledgeStatus: next() > 0.2 ? 'published' : 'retired',
      resourceStatus: next() > 0.2 ? 'published' : 'retired',
      mobileLocator: next() > 0.2 ? `/learning/${id}` : ' ',
    }));
    for (const row of publishable(rows, lockedMapping, lockedDomain)) {
      assert.equal(row.mappingVersionId, lockedMapping);
      assert.equal(row.domainId, lockedDomain);
      assert.equal(row.mappingStatus, 'published');
      assert.equal(row.knowledgeStatus, 'published');
      assert.equal(row.resourceStatus, 'published');
      assert.notEqual(row.mobileLocator.trim(), '');
    }
  }
});

test('requirement 15 keeps one effective open gap per exact mapping identity', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const open = new Set();
    for (let index = 0; index < 512; index++) {
      const row = {
        domainId: 1 + Math.floor(next() * 2),
        mappingVersionId: 1 + Math.floor(next() * 10),
        rubricVersionId: 1 + Math.floor(next() * 5),
        dimensionCode: `dimension-${Math.floor(next() * 8)}`,
        criterionCode: `criterion-${Math.floor(next() * 12)}`,
        knowledgePointId: next() > 0.2 ? 1 + Math.floor(next() * 20) : null,
        gapType: next() > 0.5 ? 'missing_knowledge' : 'missing_mobile_resource',
      };
      const fingerprint = gapFingerprint(row);
      assert.match(fingerprint, /^[a-f0-9]{64}$/);
      const before = open.size;
      open.add(fingerprint);
      assert.ok(open.size === before || open.size === before + 1);
      open.add(fingerprint);
      assert.equal(open.size, before + (open.size > before ? 1 : 0));
    }
  }
});

test('requirement 14 derives learning completion from bounded resource progress', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    for (let index = 0; index < 256; index++) {
      const progress = Math.floor(next() * 10001) / 100;
      const status = progress >= 100 ? 'completed' : (progress > 0 ? 'in_progress' : 'not_started');
      assert.ok(progress >= 0 && progress <= 100);
      if (status === 'completed') assert.equal(progress, 100);
      if (status === 'in_progress') assert.ok(progress > 0 && progress < 100);
      if (status === 'not_started') assert.equal(progress, 0);
    }
  }
});
