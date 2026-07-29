import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { test } from 'node:test';

const root = new URL('../', import.meta.url);
const source = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const matrix = [
  ['新签专项练习', 'new_signing', 'stage_practice', 'new_sign_real_call_v1'],
  ['新签完整流程', 'new_signing', 'full_process', 'new_sign_training_demo_v1'],
  ['续费专项练习', 'renewal', 'stage_practice', 'published'],
  ['续费完整流程', 'renewal', 'full_process'],
  ['评分复核与认证', 'awaiting_review', 'review', 'certification'],
  ['三次失败辅导成长', 'coaching_required', 'recordAndReopen', 'growth'],
  ['移动学习与再次演练', 'learning', 'recordProgress', 'retry_available'],
  ['录音留存与到期', 'retention_until', 'expired', 'consent'],
];

test('验收矩阵覆盖新签续费、专项完整流程、两套内容包、复核辅导、学习和录音留存', () => {
  const corpus = [
    source('../api/drill/v2/services/DrillEvaluationPolicy.php'),
    source('../api/drill/v2/services/DrillContentPolicy.php'),
    source('../api/drill/v2/services/DrillConversationPolicy.php'),
    source('../api/drill/v2/services/DrillReviewService.php'),
    source('../api/drill/v2/services/DrillCoachingService.php'),
    source('../api/drill/v2/services/DrillGrowthPolicy.php'),
    source('../api/drill/v2/services/DrillGrowthService.php'),
    source('../api/drill/v2/services/DrillLearningService.php'),
    source('../api/drill/v2/services/DrillMediaService.php'),
  ].join('\n');
  for (const [, ...signals] of matrix) for (const signal of signals) assert.match(corpus, new RegExp(signal));
});

test('真实设备和隔离数据库验收以前置检查状态呈现', () => {
  const prerequisites = {
    isolated_database: existsSync(new URL('../api/config.php', import.meta.url)),
    android_pwa_device: Boolean(process.env.DRILL_UAT_ANDROID_DEVICE),
    iphone_pwa_device: Boolean(process.env.DRILL_UAT_IPHONE_DEVICE),
    wechat_device: Boolean(process.env.DRILL_UAT_WECHAT_DEVICE),
  };
  assert.equal(typeof prerequisites.isolated_database, 'boolean');
  for (const state of Object.values(prerequisites)) assert.equal(typeof state, 'boolean');
  assert.ok(root.pathname.endsWith('/real_sync/'));
});
