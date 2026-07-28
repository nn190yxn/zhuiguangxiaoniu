import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

function canonicalize(value) {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]));
  }
  return value;
}

function lockReferences(scenarioVersionId, personaSnapshot, rubricVersionId) {
  const frozenPersona = structuredClone(personaSnapshot);
  return Object.freeze({
    scenario_version_id: scenarioVersionId,
    persona_snapshot: frozenPersona,
    persona_snapshot_hash: createHash('sha256').update(JSON.stringify(canonicalize(frozenPersona))).digest('hex'),
    rubric_version_id: rubricVersionId,
  });
}

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

test('historical attempts retain scenario, persona, and rubric versions after later publications', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const scenarioVersionId = 1 + Math.floor(next() * 10_000);
    const rubricVersionId = 1 + Math.floor(next() * 10_000);
    const persona = {
      age_band: ['20-29', '30-39', '40-49'][Math.floor(next() * 3)],
      objection_level: 1 + Math.floor(next() * 5),
      tags: ['new_customer', `seed_${seed}`],
    };
    const attempt = lockReferences(scenarioVersionId, persona, rubricVersionId);
    const historicalSnapshot = structuredClone(attempt);

    persona.objection_level = 99;
    persona.tags.push('later_revision');
    const nextAttempt = lockReferences(scenarioVersionId + 1, persona, rubricVersionId + 1);

    assert.deepEqual(attempt, historicalSnapshot);
    assert.equal(attempt.scenario_version_id, scenarioVersionId);
    assert.equal(attempt.rubric_version_id, rubricVersionId);
    assert.notEqual(nextAttempt.scenario_version_id, attempt.scenario_version_id);
    assert.notEqual(nextAttempt.rubric_version_id, attempt.rubric_version_id);
    assert.notEqual(nextAttempt.persona_snapshot_hash, attempt.persona_snapshot_hash);
  }
});

test('production binding stores copied version identities and a persona snapshot hash', () => {
  const source = readFileSync(new URL('../api/drill/v2/services/DrillContentVersionBinding.php', import.meta.url), 'utf8');
  assert.match(source, /'scenario_version_id'\s*=>\s*\$scenarioVersionId/);
  assert.match(source, /'persona_snapshot'\s*=>\s*\$personaSnapshot/);
  assert.match(source, /snapshotHash\(\$personaSnapshot\)/);
  assert.match(source, /'rubric_version_id'\s*=>\s*\$rubricVersionId/);
});
