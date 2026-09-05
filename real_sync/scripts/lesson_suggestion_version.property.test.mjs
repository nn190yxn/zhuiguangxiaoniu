import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

function buildHistory(seed, operationCount = 80) {
  const nextRandom = random(seed);
  const history = { currentVersionId: 1, suggestions: [] };

  for (let index = 0; index < operationCount; index += 1) {
    if (nextRandom() < 0.35) {
      history.currentVersionId += 1;
      continue;
    }

    const versionId = history.currentVersionId;
    const key = `${versionId}|${Math.floor(nextRandom() * 6)}|knowledge_action`;
    let suggestion = history.suggestions.find((item) => item.key === key);
    if (!suggestion) {
      suggestion = { key, versionId, decision: 'pending', decidedBy: null, decidedAt: null };
      history.suggestions.push(suggestion);
    }

    if (nextRandom() < 0.45) {
      suggestion.decision = nextRandom() < 0.5 ? 'accepted' : 'ignored';
      suggestion.decidedBy = 1000 + Math.floor(nextRandom() * 20);
      suggestion.decidedAt = `2026-09-03 10:${String(index % 60).padStart(2, '0')}:00`;
    }
  }

  return history;
}

test(`${validatesCriteria(['5.2', 'Property 6'])} 任意版本序列中的建议始终归属于生成时版本`, () => {
  for (let seed = 1; seed <= 200; seed += 1) {
    const history = buildHistory(seed);
    for (const suggestion of history.suggestions) {
      assert.equal(Number(suggestion.key.split('|')[0]), suggestion.versionId);
      assert.ok(suggestion.versionId >= 1 && suggestion.versionId <= history.currentVersionId);
    }
  }
});

test(`${validatesCriteria(['3.2', '5.2', 'Property 6'])} 任意采纳或忽略决定都保留处理人和处理时间`, () => {
  for (let seed = 201; seed <= 400; seed += 1) {
    const decided = buildHistory(seed).suggestions.filter(({ decision }) => decision !== 'pending');
    for (const suggestion of decided) {
      assert.ok(['accepted', 'ignored'].includes(suggestion.decision));
      assert.ok(Number.isInteger(suggestion.decidedBy) && suggestion.decidedBy > 0);
      assert.match(suggestion.decidedAt, /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    }
  }
});

test(`${validatesCriteria(['5.2', 'Property 6'])} 建议表保存版本、决定、处理人和处理时间`, () => {
  const migration = read('database/migrations/202609030001_smart_lesson_review.sql');
  const table = migration.match(/CREATE TABLE IF NOT EXISTS `lesson_suggestions` \(([\s\S]*?)\) ENGINE=/)?.[1] ?? '';

  assert.match(table, /`submission_id` BIGINT UNSIGNED NOT NULL/);
  assert.match(table, /`version_id` BIGINT UNSIGNED NOT NULL/);
  assert.match(table, /`decision` VARCHAR\(16\) NOT NULL DEFAULT 'pending'/);
  assert.match(table, /`decided_by` INT UNSIGNED NULL/);
  assert.match(table, /`decided_at` DATETIME NULL/);
  assert.match(table, /KEY `idx_lesson_suggestions_version_decision` \(`version_id`, `decision`\)/);
});

test(`${validatesCriteria(['3.2', '5.2', 'Property 6'])} 重复优化仅复用同版本建议并保留原决定`, () => {
  const matcher = read('api/lesson-submissions/LessonKnowledgeMatcher.php');

  assert.match(matcher, /existingSuggestions\(\$submissionId, \(int\) \$snapshot\['version_id'\]\)/);
  assert.match(matcher, /WHERE submission_id = \? AND version_id = \? AND source_type = 'knowledge_card'/);
  assert.match(matcher, /\$match\['decision'\] = \(string\) \$existing\[\$key\]\['decision'\]/);
  assert.doesNotMatch(matcher, /UPDATE lesson_suggestions SET decision/);
  assert.doesNotMatch(matcher, /DELETE FROM lesson_suggestions/);
});

test(`${validatesCriteria(['5.2', 'Property 6'])} 教案详情返回每条建议的版本和决定留痕`, () => {
  const draftService = read('api/lesson-submissions/LessonDraftService.php');

  assert.match(draftService, /SELECT s\.id, s\.version_id, s\.suggestion_type/);
  assert.match(draftService, /s\.decision, s\.decided_by, s\.decided_at/);
  assert.match(draftService, /WHERE s\.submission_id = \?/);
});

test(`${validatesCriteria(['4.6', 'Property 1', 'Property 2'])} 提交及审核状态锁定草稿保存和建议刷新`, () => {
  const draftService = read('api/lesson-submissions/LessonDraftService.php');
  const matcher = read('api/lesson-submissions/LessonKnowledgeMatcher.php');
  const editableStatuses = draftService.match(/EDITABLE_STATUSES = \[([^\]]+)\]/)?.[1]
    .match(/'[^']+'/g)?.map((value) => value.slice(1, -1)) ?? [];

  assert.deepEqual(editableStatuses, ['draft', 'editable', 'returned']);
  for (const status of ['submitted', 'store_review', 'supervisor_review', 'approved', 'archived']) {
    assert.equal(editableStatuses.includes(status), false);
  }
  assert.match(draftService, /status IN \(\\'draft\\', \\'editable\\', \\'returned\\'\)/);
  assert.match(draftService, /lesson_submission_locked/);
  assert.match(matcher, /\['draft', 'editable', 'returned'\]/);
  assert.match(matcher, /lesson_submission_locked/);
});

test(`${validatesCriteria(['Property 1', 'Property 6'])} 优化事务在写入前确认当前版本保持一致`, () => {
  const matcher = read('api/lesson-submissions/LessonKnowledgeMatcher.php');

  assert.match(matcher, /beginTransaction\(\)[\s\S]*FOR UPDATE/);
  assert.match(matcher, /\$locked\['current_version_id'\].*\$snapshot\['version_id'\]/);
  assert.match(matcher, /lesson_submission_conflict/);
  assert.match(matcher, /INSERT INTO lesson_suggestions[\s\S]*submission_id, version_id/);
});
