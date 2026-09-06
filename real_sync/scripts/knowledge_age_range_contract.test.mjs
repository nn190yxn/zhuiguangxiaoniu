import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const migration = readFileSync(path.join(root, 'database', 'migrations', '202609060001_knowledge_age_ranges.sql'), 'utf8');
const service = readFileSync(path.join(root, 'api', 'knowledge', 'KnowledgeListService.php'), 'utf8');
const backfill = readFileSync(path.join(root, 'scripts', 'backfill_knowledge_age_ranges.php'), 'utf8');
const page = readFileSync(path.join(root, 'knowledge', 'index.html'), 'utf8');

test('年龄标准字典覆盖所有员工筛选段', () => {
  for (const code of ['age_0_3', 'age_3_4', 'age_4_5', 'age_5_6', 'age_6_9', 'age_9_12', 'age_12_plus', 'age_all_review']) {
    assert.match(migration, new RegExp(`'${code}'`));
    assert.match(page, new RegExp(`value="${code}"`));
  }
});

test('年龄筛选绑定当前版本且只读取已确认关联', () => {
  assert.match(service, /iar\.version_id = kv\.version_id/);
  assert.match(service, /iar\.review_status = 'confirmed'/);
  assert.match(service, /age_code/);
  assert.match(backfill, /review_status/);
  assert.match(backfill, /source_text/);
});

test('组合搜索可以把单岁数和内容类型转换为结构化筛选', () => {
  assert.match(service, /ageCodeForSingleAge/);
  assert.match(service, /\$age === 3 => 'age_3_4'/);
  assert.match(service, /\$searchKeyword = preg_replace/);
  assert.match(service, /游戏\|动作\|安全\|感统\|体测\|体能/);
});

test('低置信度年龄结果进入待审核状态', () => {
  assert.match(backfill, /return \[\['age_all_review'\], 'pending', null\]/);
  assert.match(service, /classification_review_status/);
  assert.match(service, /pending_age_range_count/);
});
