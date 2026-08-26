import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (relative) => readFileSync(path.join(repoRoot, relative), 'utf8');

test('employee knowledge list supports full reading filters and reading modes', () => {
  const service = read('real_sync/api/knowledge/KnowledgeListService.php');
  for (const field of ['content_type', 'domain_code', 'risk_level', 'difficulty', 'favorite', 'recent']) {
    assert.match(service, new RegExp(field));
  }
  assert.match(service, /k\.status = 1 AND k\.publication_status = 'published'/);
  assert.match(service, /selected_favorite/);
  assert.match(service, /selected_recent/);
  assert.match(service, /last_viewed_at/);
  assert.match(service, /PDO::PARAM_INT/);
});

test('employee knowledge detail exposes display metadata and source summary without raw snapshots', () => {
  const detail = read('real_sync/api/knowledge/detail.php');
  assert.match(detail, /version_updated_at/);
  assert.match(detail, /display_meta/);
  assert.match(detail, /source_summary/);
  assert.match(detail, /buildKnowledgeSourceSummary/);
  assert.match(detail, /unset\(\$item\['source_snapshot_json'\]\)/);
  assert.doesNotMatch(detail, /'raw_markdown'\s*=>/);
  assert.doesNotMatch(detail, /'source_path'\s*=>/);
});

test('mini program knowledge pages are reading-oriented and expose favorite/recent/filter entry points', () => {
  const listJs = read('real_sync/mini-program/pages/knowledge/list.js');
  const listWxml = read('real_sync/mini-program/pages/knowledge/list.wxml');
  const detailJs = read('real_sync/mini-program/pages/knowledge/detail.js');
  const detailWxml = read('real_sync/mini-program/pages/knowledge/detail.wxml');
  assert.match(listJs, /quickMode/);
  assert.match(listJs, /favorite=1/);
  assert.match(listJs, /recent=1/);
  assert.match(listJs, /content_type/);
  assert.match(listJs, /risk_level/);
  assert.match(listWxml, /高级筛选/);
  assert.match(listWxml, /我的收藏/);
  assert.match(listWxml, /最近浏览/);
  assert.match(detailJs, /favorite\.php/);
  assert.match(detailWxml, /阅读信息/);
  assert.match(detailWxml, /来源摘要/);
  assert.doesNotMatch(detailJs, /knowledge\/progress\.php/);
  assert.doesNotMatch(detailWxml, /标记完成|完成知识学习|学习进度/);
});

test('mobile H5 knowledge pages share reading filters and remove learning completion flow', () => {
  const list = read('real_sync/mobile/knowledge.html');
  const detail = read('real_sync/mobile/knowledge-detail.html');
  assert.match(list, /quick-tab/);
  assert.match(list, /favorite=1/);
  assert.match(list, /recent=1/);
  assert.match(list, /content_type/);
  assert.match(list, /risk_level/);
  assert.match(list, /高级筛选/);
  assert.match(detail, /favorite\.php/);
  assert.match(detail, /阅读信息/);
  assert.match(detail, /来源摘要/);
  assert.match(detail, /renderSafeTextContent/);
  assert.doesNotMatch(list, /knowledge-status pending|未学习|完成学习/);
  assert.doesNotMatch(detail, /knowledge\/progress\.php|learning_time|积分|开始学习|学习完成/);
});
