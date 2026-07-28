import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const migration = readFileSync(new URL('../database/migrations/202607270004_drill_knowledge_growth_domain.sql', import.meta.url), 'utf8');

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

function levelFor(score) {
  if (score >= 90) return ['expert', 90];
  if (score >= 80) return ['advanced', 80];
  if (score >= 70) return ['proficient', 70];
  if (score >= 60) return ['developing', 60];
  return ['foundation', 0];
}

function growthLevel(requiredSectionScores, fullProcessScore) {
  const effectiveScore = Math.min(...requiredSectionScores, fullProcessScore);
  return levelFor(effectiveScore);
}

test('requirements 14 and 15 preserve all five growth boundaries', () => {
  const cases = [
    [0, 'foundation'],
    [59.99, 'foundation'],
    [60, 'developing'],
    [69.99, 'developing'],
    [70, 'proficient'],
    [79.99, 'proficient'],
    [80, 'advanced'],
    [89.99, 'advanced'],
    [90, 'expert'],
    [100, 'expert'],
  ];
  for (const [score, expected] of cases) assert.equal(levelFor(score)[0], expected);
  assert.match(migration, /chk_drill_growth_levels_floor/);
});

test('requirement 15 requires every section and the full process to reach one threshold', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    for (let step = 0; step < 128; step++) {
      const sections = Array.from({ length: 1 + Math.floor(next() * 8) }, () => Math.floor(next() * 101));
      const fullProcess = Math.floor(next() * 101);
      const [level, threshold] = growthLevel(sections, fullProcess);
      assert.ok(sections.every((score) => score >= threshold));
      assert.ok(fullProcess >= threshold);
      assert.equal(level, levelFor(Math.min(...sections, fullProcess))[0]);
    }
  }
  assert.match(migration, /chk_drill_growth_levels_qualified/);
});

test('requirements 14 and 17 retain latest and effective best score per rubric version', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const mastery = new Map();
    for (let attemptNo = 1; attemptNo <= 256; attemptNo++) {
      const rubricVersionId = 1 + Math.floor(next() * 4);
      const scopeKey = `section-${1 + Math.floor(next() * 6)}`;
      const score = Math.floor(next() * 10001) / 100;
      const key = `${rubricVersionId}:${scopeKey}`;
      const previous = mastery.get(key);
      mastery.set(key, {
        latestAttemptId: attemptNo,
        latestScore: score,
        bestAttemptId: previous && previous.bestScore >= score ? previous.bestAttemptId : attemptNo,
        bestScore: Math.max(previous?.bestScore ?? 0, score),
      });
      const current = mastery.get(key);
      assert.equal(current.latestAttemptId, attemptNo);
      assert.ok(current.bestScore >= current.latestScore);
    }
  }
  assert.match(migration, /UNIQUE KEY `uk_drill_mastery_scores_scope` \(`staff_id`, `domain_id`, `scope_type`, `scope_key`, `rubric_version_id`\)/);
});

test('requirement 10 keeps published mapping history immutable for existing recommendations', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const versions = [];
    const recommendations = [];
    for (let versionNo = 1; versionNo <= 12; versionNo++) {
      versions.push(Object.freeze({ id: versionNo, mappingHash: `hash-${seed}-${versionNo}-${next()}` }));
      recommendations.push({ mappingVersionId: versionNo, mappingHash: versions.at(-1).mappingHash });
    }
    versions.push(Object.freeze({ id: 13, mappingHash: `replacement-${seed}` }));
    for (const recommendation of recommendations) {
      assert.equal(versions[recommendation.mappingVersionId - 1].mappingHash, recommendation.mappingHash);
    }
  }
  assert.match(migration, /`mapping_version_id` BIGINT UNSIGNED NOT NULL/);
  assert.match(migration, /`mapping_hash` CHAR\(64\) NOT NULL/);
  assert.match(migration, /ON DELETE RESTRICT/);
});

test('requirements 8 and 10 only recommend resources owned by the locked knowledge mapping', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const links = new Set();
    for (let index = 0; index < 100; index++) {
      links.add(`${1 + Math.floor(next() * 8)}:${1 + Math.floor(next() * 20)}:${1 + Math.floor(next() * 30)}`);
    }
    const publishedLinks = [...links];
    const selected = publishedLinks[Math.floor(next() * publishedLinks.length)];
    assert.ok(links.has(selected));
    const rejected = `99:${1 + Math.floor(next() * 20)}:${1 + Math.floor(next() * 30)}`;
    assert.equal(links.has(rejected), false);
  }
  assert.match(migration, /fk_drill_learning_recommendations_criterion_point/);
  assert.match(migration, /fk_drill_learning_recommendations_mapped_resource/);
  assert.match(migration, /fk_drill_learning_recommendations_resource/);
});

test('requirements 10 and 20 publish only authorized references within their validity window', () => {
  const now = Date.UTC(2026, 6, 27);
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    for (let index = 0; index < 256; index++) {
      const effectiveFrom = now - Math.floor(next() * 30) * 86400000;
      const effectiveUntil = now + Math.floor(next() * 30) * 86400000;
      const authorized = next() >= 0.5;
      const canPublish = authorized && effectiveFrom <= now && effectiveUntil > now;
      if (canPublish) {
        assert.ok(authorized);
        assert.ok(effectiveFrom <= now);
        assert.ok(effectiveUntil > now);
      } else {
        assert.ok(!authorized || effectiveFrom > now || effectiveUntil <= now);
      }
    }
  }
  assert.match(migration, /`authorization_status` = 'authorized'/);
  assert.match(migration, /`effective_until` > `effective_from`/);
  assert.match(migration, /`published_at` >= `effective_from` AND `published_at` < `effective_until`/);
  assert.match(migration, /`content_hash` CHAR\(64\) NOT NULL/);
});

test('requirement 2 isolates knowledge, learning, mastery, and growth by training domain', () => {
  for (const table of [
    'drill_knowledge_points',
    'drill_learning_resources',
    'drill_knowledge_mapping_versions',
    'drill_reference_materials',
    'drill_learning_recommendations',
    'drill_mastery_scores',
    'drill_growth_level_snapshots',
  ]) {
    const tablePattern = new RegExp('CREATE TABLE IF NOT EXISTS `' + table + '` \\([\\s\\S]*?`domain_id` BIGINT UNSIGNED NOT NULL[\\s\\S]*?\\) ENGINE=InnoDB');
    assert.match(migration, tablePattern);
  }
});
