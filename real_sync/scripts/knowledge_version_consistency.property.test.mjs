import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const hasPhpSqlite = spawnSync('php', ['-r', 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);']).status === 0;

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

function buildCase(seed, itemCount = 16) {
  const nextRandom = random(0x6a09e667 ^ seed);
  const versions = [];
  const items = [];
  let nextVersionId = seed * 1000;

  for (let itemId = 1; itemId <= itemCount; itemId += 1) {
    const itemVersions = [];
    const versionCount = 1 + Math.floor(nextRandom() * 4);
    for (let index = 0; index < versionCount; index += 1) {
      const version = {
        versionId: nextVersionId += 1,
        knowledgeItemId: itemId,
        status: ['active', 'superseded', 'rolled_back'][Math.floor(nextRandom() * 3)],
      };
      versions.push(version);
      itemVersions.push(version);
    }
    items.push({
      id: itemId,
      ownVersionIds: itemVersions.map(({ versionId }) => versionId),
      currentVersionId: null,
      status: nextRandom() < 0.72 ? 1 : 0,
      publicationStatus: nextRandom() < 0.68 ? 'published' : 'reviewing',
    });
  }

  for (const item of items) {
    const currentMode = Math.floor(nextRandom() * 4);
    if (currentMode === 0) {
      item.currentVersionId = item.ownVersionIds[Math.floor(nextRandom() * item.ownVersionIds.length)];
    } else if (currentMode === 1) {
      const foreignVersions = versions.filter(({ knowledgeItemId }) => knowledgeItemId !== item.id);
      item.currentVersionId = foreignVersions[Math.floor(nextRandom() * foreignVersions.length)].versionId;
    } else if (currentMode === 2) {
      item.currentVersionId = nextVersionId + item.id + 100;
    }
    delete item.ownVersionIds;
  }

  return { seed, items, versions };
}

function expectedRows(propertyCase) {
  const versions = new Map(propertyCase.versions.map((version) => [version.versionId, version]));
  return propertyCase.items.flatMap((item) => {
    const version = versions.get(item.currentVersionId);
    const visible = item.status === 1
      && item.publicationStatus === 'published'
      && version?.knowledgeItemId === item.id
      && version.status === 'active';
    return visible ? [{ id: item.id, version_id: item.currentVersionId }] : [];
  });
}

function queryVisibleRows(propertyCases) {
  const php = String.raw`
    require 'api/knowledge/EmployeeKnowledgeVisibilityQuery.php';
    $cases = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE knowledge_items (id INTEGER PRIMARY KEY, current_version_id INTEGER NULL, status INTEGER NOT NULL, publication_status TEXT NOT NULL)');
    $db->exec('CREATE TABLE knowledge_item_versions (version_id INTEGER PRIMARY KEY, knowledge_item_id INTEGER NOT NULL, status TEXT NOT NULL)');
    $insertItem = $db->prepare('INSERT INTO knowledge_items VALUES (?, ?, ?, ?)');
    $insertVersion = $db->prepare('INSERT INTO knowledge_item_versions VALUES (?, ?, ?)');
    $sql = 'SELECT k.id, kv.version_id FROM ' . EmployeeKnowledgeVisibilityQuery::fromCurrentVersion() . ' ORDER BY k.id';
    $results = [];
    foreach ($cases as $case) {
        $db->exec('DELETE FROM knowledge_item_versions');
        $db->exec('DELETE FROM knowledge_items');
        foreach ($case['versions'] as $version) {
            $insertVersion->execute([$version['versionId'], $version['knowledgeItemId'], $version['status']]);
        }
        foreach ($case['items'] as $item) {
            $insertItem->execute([$item['id'], $item['currentVersionId'], $item['status'], $item['publicationStatus']]);
        }
        $results[] = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($results, JSON_THROW_ON_ERROR);
  `;
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', php], {
    cwd: root,
    encoding: 'utf8',
    input: JSON.stringify(propertyCases),
    timeout: 20_000,
  });
  assert.equal(result.status, 0, result.stderr);
  assert.doesNotMatch(result.stdout, /Warning|Notice|Deprecated/);
  return JSON.parse(result.stdout);
}

test(`${validatesCriteria(['6.1', '6.2', 'Property 7'])} 任意知识状态组合只暴露同卡 active 当前版本`, { skip: !hasPhpSqlite }, () => {
  const propertyCases = Array.from({ length: 256 }, (_, index) => buildCase(index + 1));
  const actualRows = queryVisibleRows(propertyCases);

  propertyCases.forEach((propertyCase, index) => {
    assert.deepEqual(actualRows[index], expectedRows(propertyCase), `seed ${propertyCase.seed}`);
  });
});

test(`${validatesCriteria(['6.1', '6.2', 'Property 7'])} 五类员工知识消费入口返回共享当前版本标识`, () => {
  const list = read('api/knowledge/KnowledgeListService.php');
  const detail = read('api/knowledge/detail.php');
  const search = read('api/search/search-service.php');
  const matcher = read('api/lesson-submissions/LessonKnowledgeMatcher.php');

  for (const source of [list, detail, search, matcher]) {
    assert.match(source, /EmployeeKnowledgeVisibilityQuery::fromCurrentVersion\(\)/);
  }
  assert.match(list, /kv\.version_id AS version_id/);
  assert.equal((detail.match(/kv\.version_id AS version_id/g) ?? []).length, 2);
  assert.match(search, /SELECT k\.id, kv\.version_id/);
  assert.match(search, /'version_id' => \(int\)\$item\['version_id'\]/);
  assert.match(matcher, /kv\.version_id AS knowledge_version_id/);
  assert.match(matcher, /\$match\['knowledge_version_id'\]/);
});
