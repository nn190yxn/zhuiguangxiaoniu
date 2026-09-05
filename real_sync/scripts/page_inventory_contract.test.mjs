import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

const projectRoot = new URL('../', import.meta.url).pathname;
const inventory = JSON.parse(readFileSync(join(projectRoot, '.monkeycode/page-inventory.json'), 'utf8'));

function htmlFiles(relativeDirectory) {
  const directory = join(projectRoot, relativeDirectory);
  return readdirSync(directory).flatMap(name => {
    const path = join(directory, name);
    return statSync(path).isDirectory() ? [] : (name.endsWith('.html') ? [name] : []);
  });
}

test('受保护页面清单覆盖培训卡片、静态教案和工作量页面', () => {
  assert.deepEqual(inventory.status_values, ['active', 'archived', 'public']);
  assert.equal(inventory.collections.length, 2);
  for (const collection of inventory.collections) {
    const [directory, pattern] = collection.path_glob.split('/*');
    const files = htmlFiles(directory);
    assert.equal(files.length, collection.expected_count, collection.path_glob);
    assert.equal(collection.status, 'archived');
    assert.ok(collection.center);
    assert.match(collection.canonical_entry, /^\//);
    assert.equal(pattern, '.html');
  }
  assert.deepEqual(inventory.pages, [{
    path: 'mobile/workload-v2.html',
    status: 'active',
    center: 'tools',
    canonical_entry: '/mobile/workload-v2.html',
  }]);
  assert.deepEqual(inventory.public, []);
});

test('页面清单声明统一认证壳和资源发布号', () => {
  assert.deepEqual(inventory.resource_contract, {
    auth_script: '/internal-auth.js',
    ops_stylesheet: '/assets/internal-ops.css',
    release_id: '20260905-page-shell-v1',
  });
});

test('活跃页面加载统一认证壳和资源版本，归档集合声明受控入口', () => {
  const activePage = readFileSync(join(projectRoot, 'mobile/workload-v2.html'), 'utf8');
  const { auth_script: authScript, ops_stylesheet: opsStylesheet, release_id: releaseId } = inventory.resource_contract;
  assert.match(activePage, new RegExp(`src="${authScript}\\?v=${releaseId}"`));
  assert.match(activePage, new RegExp(`href="${opsStylesheet}\\?v=${releaseId}"`));
  assert.equal((activePage.match(/<html\b/gi) || []).length, 1);
  assert.equal((activePage.match(/<body\b/gi) || []).length, 1);
  assert.equal((activePage.match(/<\/html>/gi) || []).length, 1);
  for (const collection of inventory.collections) {
    assert.match(collection.canonical_entry, /^\/(training-center|lesson-library\.html)/);
  }
});

test('移动页面壳保留核心 DOM、事件和一级导航', () => {
  const page = readFileSync(join(projectRoot, 'mobile/workload-v2.html'), 'utf8');
  for (const id of ['backButton', 'reportDate', 'roleCode', 'storeId', 'metricCard', 'draftButton', 'submitButton']) {
    assert.match(page, new RegExp(`id="${id}"`));
  }
  for (const event of ['DOMContentLoaded', 'pageshow', 'focus', 'pwa:network-restored', 'pwa:session-restored', 'beforeunload']) {
    assert.match(page, new RegExp(`addEventListener\\(['"]${event}`));
  }
  assert.match(page, /class="bottom-nav mobile-shell-nav"/);
  assert.match(page, /href="\/mobile\/workload-v2\.html"[^>]+aria-current="page"/);
  assert.match(page, /href="\/mobile\/drill\.html"/);
  assert.match(page, /href="\/mobile\/mine\.html"/);
});
