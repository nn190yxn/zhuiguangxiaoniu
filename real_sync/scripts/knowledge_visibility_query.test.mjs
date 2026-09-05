import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const root = new URL('..', import.meta.url);
const hasPhpSqlite = spawnSync('php', ['-r', 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);']).status === 0;

function runPhp(source) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', source], {
    cwd: root,
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr);
  assert.doesNotMatch(result.stdout, /Warning|Notice|Deprecated/);
  return JSON.parse(result.stdout);
}

test('员工知识可见性查询只返回已启用且已发布的所属 active 当前版本', { skip: !hasPhpSqlite }, () => {
  const output = runPhp(String.raw`
    require 'api/knowledge/EmployeeKnowledgeVisibilityQuery.php';

    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE knowledge_items (id INTEGER PRIMARY KEY, current_version_id INTEGER NULL, status INTEGER NOT NULL, publication_status TEXT NOT NULL)');
    $db->exec('CREATE TABLE knowledge_item_versions (version_id INTEGER PRIMARY KEY, knowledge_item_id INTEGER NOT NULL, status TEXT NOT NULL)');

    $versions = [
      [101, 1, 'active'],
      [102, 1, 'active'],
      [201, 2, 'active'],
      [301, 3, 'active'],
      [401, 4, 'superseded'],
      [501, 5, 'active'],
      [601, 6, 'active'],
      [701, 7, 'active'],
      [702, 7, 'superseded']
    ];
    $insertVersion = $db->prepare('INSERT INTO knowledge_item_versions VALUES (?, ?, ?)');
    foreach ($versions as $version) $insertVersion->execute($version);

    $items = [
      [1, 102, 1, 'published'],
      [2, 201, 0, 'published'],
      [3, 301, 1, 'reviewing'],
      [4, 401, 1, 'published'],
      [5, 601, 1, 'published'],
      [6, null, 1, 'published'],
      [7, 702, 1, 'published']
    ];
    $insertItem = $db->prepare('INSERT INTO knowledge_items VALUES (?, ?, ?, ?)');
    foreach ($items as $item) $insertItem->execute($item);

    $sql = 'SELECT item.id, version.version_id FROM '
      . EmployeeKnowledgeVisibilityQuery::fromCurrentVersion('item', 'version')
      . ' ORDER BY item.id';
    echo json_encode(['rows' => $db->query($sql)->fetchAll(PDO::FETCH_ASSOC), 'sql' => $sql]);
  `);

  assert.deepEqual(output.rows, [{ id: 1, version_id: 102 }]);
  assert.match(output.sql, /version\.knowledge_item_id = item\.id/);
  assert.match(output.sql, /version\.status = 'active'/);
  assert.match(output.sql, /item\.status = 1/);
  assert.match(output.sql, /item\.publication_status = 'published'/);
});

test('员工知识可见性查询支持默认别名和安全的关联查询别名', () => {
  const output = runPhp(String.raw`
    require 'api/knowledge/EmployeeKnowledgeVisibilityQuery.php';
    echo json_encode([
      'default' => EmployeeKnowledgeVisibilityQuery::fromCurrentVersion(),
      'related' => EmployeeKnowledgeVisibilityQuery::fromCurrentVersion('related_k', 'related_kv')
    ]);
  `);

  assert.match(output.default, /^knowledge_items k INNER JOIN knowledge_item_versions kv ON /);
  assert.match(output.related, /^knowledge_items related_k INNER JOIN knowledge_item_versions related_kv ON /);
});

test('员工知识可见性查询拒绝无效、重复和超长 SQL 别名', () => {
  const output = runPhp(String.raw`
    require 'api/knowledge/EmployeeKnowledgeVisibilityQuery.php';
    $aliases = [
      ['', 'kv'],
      ['knowledge-item', 'kv'],
      ['1item', 'kv'],
      ['k; DROP TABLE knowledge_items', 'kv'],
      ['same', 'same'],
      [str_repeat('a', 65), 'kv']
    ];
    $rejected = [];
    foreach ($aliases as [$itemAlias, $versionAlias]) {
      try {
        EmployeeKnowledgeVisibilityQuery::fromCurrentVersion($itemAlias, $versionAlias);
        $rejected[] = false;
      } catch (InvalidArgumentException) {
        $rejected[] = true;
      }
    }
    echo json_encode($rejected);
  `);

  assert.deepEqual(output, [true, true, true, true, true, true]);
});
