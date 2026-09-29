<?php
declare(strict_types=1);

/**
 * 培训卡（training_cards）到知识库"实战演练"板块的幂等同步 CLI。
 *
 * 安全边界（对齐二期知识卡导入器治理口径）：
 * - 仅允许 CLI 执行；数据库连接统一走 api/config.php::getDB()。
 * - 默认 dry-run：无锁、无备份、无 manifest、无任何 INSERT/UPDATE/DELETE。
 * - apply：命名锁 -> 事务 -> 导入前备份 -> manifest -> 审计留痕。
 * - rollback 按 manifest 精确回滚本批插入/更新的条目，不触碰其他内容。
 * - 数据源固定为模块 8/9/10 的 sales-XXXX-[ksdc] 四卡组；命名不规整的旧模块不投影。
 *
 * 用法：
 *   php sync_training_cards_to_knowledge.php [import]
 *   php sync_training_cards_to_knowledge.php import --apply --backup-dir DIR --manifest PATH
 *   php sync_training_cards_to_knowledge.php rollback --manifest PATH --apply --backup-dir DIR
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require __DIR__ . '/../api/config.php'; // getDB()

const SYNC_PARSER_VERSION = 'training-practice-sync.v1';
const SYNC_LOCK_NAME = 'supercalf_training_practice_sync_v1';
const SYNC_ITEM_PREFIX = 'TRAIN-';

const SYNC_MODULE_DOMAINS = [
    8 => 'training_practice_sales_junior',
    9 => 'training_practice_sales_intermediate',
    10 => 'training_practice_sales_senior',
];

const SYNC_CATEGORY_META = [
    'training_practice_sales_junior' => ['实战演练·销售初阶', 41],
    'training_practice_sales_intermediate' => ['实战演练·销售中阶', 42],
    'training_practice_sales_senior' => ['实战演练·销售高阶', 43],
];

final class SyncCliException extends RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode = 2)
    {
        parent::__construct($message);
    }
}

function failSync(string $message, int $code = 2): void
{
    fwrite(STDERR, '[error] ' . $message . PHP_EOL);
    throw new SyncCliException($message, $code);
}

function parseSyncArgs(array $argv): array
{
    $args = [
        'command' => 'import', 'apply' => false,
        'backup_dir' => null, 'manifest' => null,
    ];
    $positional = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--apply') {
            $args['apply'] = true;
        } elseif ($arg === '--backup-dir') {
            $args['backup_dir'] = (string)($argv[++$i] ?? '');
        } elseif ($arg === '--manifest') {
            $args['manifest'] = (string)($argv[++$i] ?? '');
        } else {
            $positional[] = $arg;
        }
    }
    if ($positional !== []) {
        $args['command'] = array_shift($positional);
    }
    if ($positional !== []) {
        failSync('unexpected extra arguments');
    }
    if (!in_array($args['command'], ['import', 'rollback'], true)) {
        failSync('command must be import or rollback');
    }
    if ($args['apply'] && ($args['backup_dir'] === null || $args['manifest'] === null || $args['backup_dir'] === '' || $args['manifest'] === '')) {
        failSync('--apply requires --backup-dir DIR and --manifest PATH');
    }
    if ($args['command'] === 'rollback' && ($args['manifest'] === null || $args['manifest'] === '')) {
        failSync('rollback requires --manifest PATH');
    }
    return $args;
}

function syncNormalize(string $text): string
{
    $text = str_replace("\r\n", "\n", $text);
    return trim(preg_replace('/[ \t]+/', ' ', $text) ?? '');
}

/** 读 training_cards，按 sales-XXXX 分组为四板块知识卡记录。 */
function buildRecords(PDO $db): array
{
    $q = $db->prepare(
        'SELECT module_id, card_code, card_type, title, content, updated_at
         FROM training_cards
         WHERE module_id IN (8,9,10) AND status = 1 AND card_code LIKE ?
         ORDER BY card_code, card_type'
    );
    $q->execute(['sales-%']);
    $groups = [];
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        $parts = explode('-', (string)$row['card_code']);
        if (count($parts) !== 3 || $parts[0] !== 'sales' || !ctype_digit($parts[1]) || !in_array($parts[2], ['k', 's', 'd', 'c'], true)) {
            failSync('unexpected card_code: ' . $row['card_code']);
        }
        $groups['sales-' . $parts[1]]['type_' . $parts[2]] = $row;
    }
    ksort($groups);
    $records = [];
    foreach ($groups as $groupKey => $cards) {
        foreach (['k', 's', 'd', 'c'] as $need) {
            if (!isset($cards['type_' . $need])) {
                failSync("group {$groupKey} missing card type {$need}; aborting for manual review");
            }
        }
        $moduleId = (int)$cards['type_k']['module_id'];
        $domain = SYNC_MODULE_DOMAINS[$moduleId] ?? null;
        if ($domain === null) {
            failSync("module {$moduleId} has no practice domain mapping");
        }
        $baseTitle = syncNormalize(preg_replace('/｜(知识|话术|演练|通关)$/', '', (string)$cards['type_k']['title']) ?? '');
        $kContent = syncNormalize((string)($cards['type_k']['content'] ?? ''));
        $sContent = syncNormalize((string)($cards['type_s']['content'] ?? ''));
        $dContent = syncNormalize((string)($cards['type_d']['content'] ?? ''));
        $cContent = syncNormalize((string)($cards['type_c']['content'] ?? ''));
        $sections = [
            '## 这张卡是什么',
            $kContent !== '' ? $kContent : '（源卡片暂无正文，请回培训卡后台补充）',
            '',
            '## 怎么跟家长说',
            $sContent !== '' ? $sContent : '（源卡片暂无正文，请回培训卡后台补充）',
            '',
            '## 怎么练',
            $dContent !== '' ? $dContent : '（源卡片暂无正文，请回培训卡后台补充）',
            '',
            '## 怎么算通关',
            $cContent !== '' ? $cContent : '（源卡片暂无正文，请回培训卡后台补充）',
        ];
        $content = implode("\n", $sections);
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/[#*>`|-]/', '', $kContent) ?? '')) ?? '');
        $summary = mb_substr($plain !== '' ? $plain : $baseTitle, 0, 110);
        $sourceRaw = json_encode([
            'group' => $groupKey,
            'cards' => [
                ['card_code' => (string)$cards['type_k']['card_code'], 'updated_at' => (string)$cards['type_k']['updated_at'], 'content' => (string)$cards['type_k']['content']],
                ['card_code' => (string)$cards['type_s']['card_code'], 'updated_at' => (string)$cards['type_s']['updated_at'], 'content' => (string)$cards['type_s']['content']],
                ['card_code' => (string)$cards['type_d']['card_code'], 'updated_at' => (string)$cards['type_d']['updated_at'], 'content' => (string)$cards['type_d']['content']],
                ['card_code' => (string)$cards['type_c']['card_code'], 'updated_at' => (string)$cards['type_c']['updated_at'], 'content' => (string)$cards['type_c']['content']],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $number = (int)explode('-', $groupKey)[1];
        $records[] = [
            'item_code' => SYNC_ITEM_PREFIX . 'SALES-' . str_pad((string)$number, 4, '0', STR_PAD_LEFT),
            'group_key' => $groupKey,
            'module_id' => $moduleId,
            'domain_code' => $domain,
            'category_code' => $domain,
            'title' => mb_substr($baseTitle !== '' ? $baseTitle : $groupKey, 0, 200),
            'summary' => $summary,
            'content' => $content,
            'normalized_hash' => hash('sha256', $content),
            'source_sha256' => hash('sha256', $sourceRaw),
            'source_card_id' => $groupKey,
        ];
    }
    if (count($records) < 1) {
        failSync('no training card groups found; refusing empty package');
    }
    return $records;
}

function packageIdentity(array $records): string
{
    $parts = [SYNC_PARSER_VERSION, (string)count($records)];
    foreach ($records as $record) {
        $parts[] = $record['item_code'];
        $parts[] = $record['normalized_hash'];
        $parts[] = $record['source_sha256'];
    }
    return hash('sha256', implode("\0", $parts));
}

function ensureCategories(PDO $db): array
{
    $ids = [];
    $select = $db->prepare('SELECT id FROM knowledge_categories WHERE code = ?');
    $insert = $db->prepare(
        'INSERT INTO knowledge_categories (name, code, type, description, icon, sort_order, status)
         VALUES (?, ?, ?, ?, NULL, ?, 1)'
    );
    foreach (SYNC_CATEGORY_META as $code => [$name, $sortOrder]) {
        $select->execute([$code]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $ids[$code] = (int)$row['id'];
            continue;
        }
        $insert->execute([$name, $code, 'knowledge_card', '由培训卡同步生成的实战演练板块，数据源 training_cards 模块组。', $sortOrder]);
        $ids[$code] = (int)$db->lastInsertId();
    }
    return $ids;
}

function computeDiff(PDO $db, array $records): array
{
    $diff = ['insert' => [], 'update' => [], 'skip' => [], 'absent' => []];
    $codes = array_column($records, 'item_code');
    $in = implode(',', array_fill(0, count($codes), '?'));
    $q = $db->prepare(
        "SELECT k.id, k.item_code, k.current_version_id, v.content
         FROM knowledge_items k
         LEFT JOIN knowledge_item_versions v ON v.version_id = k.current_version_id
         WHERE k.item_code LIKE ? AND (k.source_batch_id IS NULL OR k.source_batch_id IN (SELECT batch_id FROM knowledge_import_batches WHERE parser_version = ?))"
    );
    $q->execute([SYNC_ITEM_PREFIX . '%', SYNC_PARSER_VERSION]);
    $existing = [];
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        $existing[(string)$row['item_code']] = $row;
    }
    foreach ($records as $record) {
        $row = $existing[$record['item_code']] ?? null;
        if ($row === null) {
            $diff['insert'][] = $record['item_code'];
        } elseif (hash_equals($record['normalized_hash'], hash('sha256', (string)($row['content'] ?? '')))) {
            $diff['skip'][] = $record['item_code'];
        } else {
            $diff['update'][] = $record['item_code'];
        }
    }
    foreach (array_keys($existing) as $code) {
        if (!in_array($code, $codes, true)) {
            $diff['absent'][] = $code;
        }
    }
    return $diff;
}

function tableCounts(PDO $db): array
{
    $counts = [];
    foreach (['knowledge_items', 'knowledge_item_versions', 'knowledge_item_sources', 'knowledge_import_batches', 'knowledge_categories'] as $table) {
        $q = $db->query('SELECT COUNT(*) AS n FROM ' . $table);
        $counts[$table] = (int)$q->fetch(PDO::FETCH_ASSOC)['n'];
    }
    return $counts;
}

function atomicJsonSync(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        failSync('cannot create directory: ' . $dir);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json) === false || !rename($tmp, $path)) {
        failSync('cannot write file: ' . $path);
    }
}

function writeBackup(PDO $db, string $dir, string $label, string $packageSha, array $affectedIds): array
{
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        failSync('cannot create backup dir: ' . $dir);
    }
    $items = [];
    $versions = [];
    $sources = [];
    if ($affectedIds !== []) {
        $in = implode(',', array_fill(0, count($affectedIds), '?'));
        $qi = $db->prepare("SELECT * FROM knowledge_items WHERE id IN ({$in})");
        $qi->execute($affectedIds);
        $items = $qi->fetchAll(PDO::FETCH_ASSOC);
        $qv = $db->prepare("SELECT * FROM knowledge_item_versions WHERE knowledge_item_id IN ({$in})");
        $qv->execute($affectedIds);
        $versions = $qv->fetchAll(PDO::FETCH_ASSOC);
        $qs = $db->prepare("SELECT * FROM knowledge_item_sources WHERE knowledge_item_id IN ({$in})");
        $qs->execute($affectedIds);
        $sources = $qs->fetchAll(PDO::FETCH_ASSOC);
    }
    $data = [
        'schema_version' => 'training-practice-backup.v1',
        'created_at' => date('Y-m-d H:i:s'),
        'label' => $label,
        'package_sha256' => $packageSha,
        'items' => $items,
        'versions' => $versions,
        'sources' => $sources,
    ];
    $path = rtrim($dir, '/\\') . '/training-practice-backup-' . date('Ymd-His') . '-' . $label . '.json';
    atomicJsonSync($path, $data);
    return ['path' => $path, 'sha256' => hash('sha256', (string)file_get_contents($path))];
}

function insertPracticeItem(PDO $db, int $categoryId, int $batchId, array $record): array
{
    $targetRoles = json_encode(['sales', 'coach', 'manager'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $targetStages = json_encode(['通用'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $q = $db->prepare(
        'INSERT INTO knowledge_items
            (category_id, title, summary, content, media_type, subject, age_group, training_type, difficulty, is_public,
             target_roles, target_stages, tags, view_count, like_count, collect_count, source, sort_order, status,
             item_code, content_type, domain_code, risk_level, publication_status, source_batch_id)
         VALUES (?, ?, ?, ?, NULL, NULL, NULL, NULL, 3, 0, ?, ?, NULL, 0, 0, 0, ?, 0, 1, ?, ?, ?, ?, ?, ?)'
    );
    $q->execute([
        $categoryId,
        $record['title'],
        $record['summary'],
        $record['content'],
        $targetRoles,
        $targetStages,
        mb_substr('培训卡同步 training_cards:' . $record['group_key'], 0, 50),
        $record['item_code'],
        'knowledge_card',
        $record['domain_code'],
        '低',
        'published',
        $batchId,
    ]);
    $itemId = (int)$db->lastInsertId();
    $sourceSnapshot = json_encode(
        ['source' => 'training_cards', 'group' => $record['group_key'], 'module_id' => $record['module_id']],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $q = $db->prepare(
        'INSERT INTO knowledge_item_versions
            (knowledge_item_id, version_no, title, summary, content, content_format, content_type, domain_code, risk_level,
             subject, age_group, training_type, difficulty, tags_json, source_snapshot_json, raw_markdown, change_reason, changed_by, status)
         VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, 3, NULL, ?, ?, ?, NULL, ?)'
    );
    $q->execute([
        $itemId,
        $record['title'],
        $record['summary'],
        $record['content'],
        'markdown',
        'knowledge_card',
        $record['domain_code'],
        '低',
        $sourceSnapshot,
        $record['content'],
        'training practice sync',
        'active',
    ]);
    $versionId = (int)$db->lastInsertId();
    $q = $db->prepare(
        'INSERT INTO knowledge_item_sources
            (knowledge_item_id, batch_id, source_card_id, source_path, source_sha256, normalized_hash,
             source_articles_json, source_images_json, raw_frontmatter_json, raw_markdown)
         VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?)'
    );
    $q->execute([
        $itemId,
        $batchId,
        $record['group_key'],
        'training_cards:' . $record['group_key'],
        $record['source_sha256'],
        $record['normalized_hash'],
        $record['content'],
    ]);
    $q = $db->prepare('UPDATE knowledge_items SET current_version_id = ? WHERE id = ? AND item_code = ?');
    $q->execute([$versionId, $itemId, $record['item_code']]);
    return ['item_id' => $itemId, 'version_id' => $versionId];
}

function updatePracticeItem(PDO $db, int $categoryId, int $batchId, array $existing, array $record): array
{
    $itemId = (int)$existing['id'];
    $previousVersionId = (int)($existing['current_version_id'] ?? 0);
    $q = $db->prepare('SELECT COALESCE(MAX(version_no), 0) AS max_no FROM knowledge_item_versions WHERE knowledge_item_id = ?');
    $q->execute([$itemId]);
    $nextNo = (int)$q->fetch(PDO::FETCH_ASSOC)['max_no'] + 1;
    $sourceSnapshot = json_encode(
        ['source' => 'training_cards', 'group' => $record['group_key'], 'module_id' => $record['module_id']],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $q = $db->prepare(
        'INSERT INTO knowledge_item_versions
            (knowledge_item_id, version_no, title, summary, content, content_format, content_type, domain_code, risk_level,
             subject, age_group, training_type, difficulty, tags_json, source_snapshot_json, raw_markdown, change_reason, changed_by, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, 3, NULL, ?, ?, ?, NULL, ?)'
    );
    $q->execute([
        $itemId,
        $nextNo,
        $record['title'],
        $record['summary'],
        $record['content'],
        'markdown',
        'knowledge_card',
        $record['domain_code'],
        '低',
        $sourceSnapshot,
        $record['content'],
        'training practice sync update',
        'active',
    ]);
    $versionId = (int)$db->lastInsertId();
    if ($previousVersionId > 0) {
        $q = $db->prepare('UPDATE knowledge_item_versions SET status = \'superseded\' WHERE version_id = ? AND knowledge_item_id = ?');
        $q->execute([$previousVersionId, $itemId]);
    }
    $q = $db->prepare(
        'UPDATE knowledge_items
         SET category_id = ?, title = ?, summary = ?, content = ?, domain_code = ?, publication_status = \'published\',
             source_batch_id = ?, current_version_id = ?
         WHERE id = ? AND item_code = ?'
    );
    $q->execute([
        $categoryId,
        $record['title'],
        $record['summary'],
        $record['content'],
        $record['domain_code'],
        $batchId,
        $versionId,
        $itemId,
        $record['item_code'],
    ]);
    $q = $db->prepare(
        'INSERT INTO knowledge_item_sources
            (knowledge_item_id, batch_id, source_card_id, source_path, source_sha256, normalized_hash,
             source_articles_json, source_images_json, raw_frontmatter_json, raw_markdown)
         VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?)'
    );
    $q->execute([
        $itemId,
        $batchId,
        $record['group_key'],
        'training_cards:' . $record['group_key'],
        $record['source_sha256'],
        $record['normalized_hash'],
        $record['content'],
    ]);
    return ['item_id' => $itemId, 'version_id' => $versionId, 'previous_version_id' => $previousVersionId];
}

function commandImport(PDO $db, array $args): void
{
    $records = buildRecords($db);
    $byCode = [];
    foreach ($records as $record) {
        $byCode[$record['item_code']] = $record;
    }
    $packageSha = packageIdentity($records);
    $diff = computeDiff($db, $records);
    echo '[info] parser=' . SYNC_PARSER_VERSION
        . ' package_sha256=' . $packageSha
        . ' groups=' . count($records)
        . ' insert=' . count($diff['insert'])
        . ' update=' . count($diff['update'])
        . ' skip=' . count($diff['skip'])
        . ' absent=' . count($diff['absent']) . PHP_EOL;
    foreach (['insert', 'update', 'absent'] as $bucket) {
        foreach ($diff[$bucket] as $code) {
            echo '[detail] ' . $bucket . ' ' . $code . PHP_EOL;
        }
    }
    if (!$args['apply']) {
        echo '[info] dry-run only; pass --apply to write' . PHP_EOL;
        return;
    }
    if (count($diff['insert']) === 0 && count($diff['update']) === 0 && count($diff['absent']) === 0) {
        echo '[info] nothing to apply; target already in sync' . PHP_EOL;
        return;
    }

    $lockStmt = $db->prepare('SELECT GET_LOCK(?, 0) AS got');
    $lockStmt->execute([SYNC_LOCK_NAME]);
    $lockRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
    if (!$lockRow || (int)$lockRow['got'] !== 1) {
        failSync('another training practice sync is running (lock busy)');
    }

    try {
        $affectedIds = [];
        $existingQ = $db->prepare(
            "SELECT id, item_code, current_version_id FROM knowledge_items WHERE item_code LIKE ?"
        );
        $existingQ->execute([SYNC_ITEM_PREFIX . '%']);
        while ($row = $existingQ->fetch(PDO::FETCH_ASSOC)) {
            $affectedIds[] = (int)$row['id'];
        }
        $backup = writeBackup($db, (string)$args['backup_dir'], 'before-apply', $packageSha, $affectedIds);
        $before = tableCounts($db);

        $db->beginTransaction();
        try {
            $categoryIds = ensureCategories($db);
            $qb = $db->prepare(
                "INSERT INTO knowledge_import_batches (package_sha256, source_root, parser_version, status, before_counts_json, manifest_path)
                 VALUES (?, ?, ?, 'imported', ?, ?)"
            );
            $qb->execute([
                $packageSha,
                'training_cards:modules-8-9-10',
                SYNC_PARSER_VERSION,
                json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $args['manifest'],
            ]);
            $batchId = (int)$db->lastInsertId();

            $applied = ['insert' => [], 'update' => [], 'skip' => $diff['skip'], 'absent' => $diff['absent']];
            $existingStmt = $db->prepare('SELECT id, item_code, current_version_id FROM knowledge_items WHERE item_code = ?');
            foreach (array_merge($diff['insert'], $diff['update']) as $code) {
                $record = $byCode[$code];
                $categoryId = (int)$categoryIds[$record['category_code']];
                $existingStmt->execute([$code]);
                $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
                if (in_array($code, $diff['insert'], true)) {
                    if ($existing !== false) {
                        failSync('unexpected existing item during insert: ' . $code);
                    }
                    $result = insertPracticeItem($db, $categoryId, $batchId, $record);
                    $applied['insert'][$code] = $result;
                } else {
                    if ($existing === false) {
                        failSync('missing item during update: ' . $code);
                    }
                    $result = updatePracticeItem($db, $categoryId, $batchId, $existing, $record);
                    $applied['update'][$code] = $result;
                }
                $q = $db->prepare(
                    'INSERT INTO knowledge_audit_logs
                        (batch_id, actor_user_id, actor_staff_id, action, target_type, target_id, before_json, after_json, metadata_json, ip_address, user_agent)
                     VALUES (?, NULL, NULL, ?, ?, ?, ?, ?, ?, NULL, ?)'
                );
                $q->execute([
                    $batchId,
                    in_array($code, $diff['insert'], true) ? 'training_practice.insert' : 'training_practice.update',
                    'knowledge_item',
                    (string)$result['item_id'],
                    json_encode($existing ?: [], JSON_UNESCAPED_UNICODE),
                    json_encode(['item_code' => $code, 'normalized_hash' => $record['normalized_hash']], JSON_UNESCAPED_UNICODE),
                    json_encode(['parser' => SYNC_PARSER_VERSION], JSON_UNESCAPED_UNICODE),
                    'cli',
                ]);
            }

            $after = tableCounts($db);
            $qc = $db->prepare('UPDATE knowledge_import_batches SET after_counts_json = ?, completed_at = NOW() WHERE batch_id = ?');
            $qc->execute([json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $batchId]);

            $manifest = [
                'schema_version' => 'training-practice-manifest.v1',
                'batch_id' => $batchId,
                'package_sha256' => $packageSha,
                'parser_version' => SYNC_PARSER_VERSION,
                'created_at' => date('Y-m-d H:i:s'),
                'backup' => $backup,
                'applied' => $applied,
                'counts_before' => $before,
                'counts_after' => $after,
            ];
            atomicJsonSync((string)$args['manifest'], $manifest);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            failSync('apply failed, rolled back: ' . $e->getMessage());
        }
        echo '[info] applied batch=' . $batchId
            . ' inserted=' . count($applied['insert'])
            . ' updated=' . count($applied['update'])
            . ' manifest=' . $args['manifest'] . PHP_EOL;
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([SYNC_LOCK_NAME]);
    }
}

function commandRollback(PDO $db, array $args): void
{
    $manifestPath = (string)$args['manifest'];
    if (!is_file($manifestPath)) {
        failSync('manifest not found: ' . $manifestPath);
    }
    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest) || ($manifest['schema_version'] ?? '') !== 'training-practice-manifest.v1') {
        failSync('manifest is not a training-practice manifest');
    }
    if (is_string($manifest['backup']['sha256'] ?? null) && is_string($manifest['backup']['path'] ?? null) && is_file($manifest['backup']['path'])) {
        if (!hash_equals((string)$manifest['backup']['sha256'], hash('sha256', (string)file_get_contents($manifest['backup']['path'])))) {
            failSync('backup file hash mismatch; refusing rollback');
        }
    }
    $batchId = (int)($manifest['batch_id'] ?? 0);
    $q = $db->prepare('SELECT batch_id, parser_version, status FROM knowledge_import_batches WHERE batch_id = ?');
    $q->execute([$batchId]);
    $batch = $q->fetch(PDO::FETCH_ASSOC);
    if (!$batch || (string)$batch['parser_version'] !== SYNC_PARSER_VERSION) {
        failSync('manifest batch not found or parser mismatch');
    }
    if ((string)$batch['status'] === 'rolled_back') {
        echo '[info] batch already rolled back' . PHP_EOL;
        return;
    }
    if (!$args['apply']) {
        echo '[info] rollback dry-run: batch=' . $batchId
            . ' insert=' . count($manifest['applied']['insert'] ?? [])
            . ' update=' . count($manifest['applied']['update'] ?? []) . PHP_EOL;
        echo '[info] pass --apply to execute rollback' . PHP_EOL;
        return;
    }

    $lockStmt = $db->prepare('SELECT GET_LOCK(?, 0) AS got');
    $lockStmt->execute([SYNC_LOCK_NAME]);
    $lockRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
    if (!$lockRow || (int)$lockRow['got'] !== 1) {
        failSync('another training practice sync is running (lock busy)');
    }
    try {
        $backup = json_decode((string)file_get_contents((string)$manifest['backup']['path']), true);
        $itemsById = [];
        foreach ((array)($backup['items'] ?? []) as $row) {
            $itemsById[(int)$row['id']] = $row;
        }
        $versionsById = [];
        foreach ((array)($backup['versions'] ?? []) as $row) {
            $versionsById[(int)$row['version_id']] = $row;
        }

        $db->beginTransaction();
        try {
            $deleted = 0;
            $restored = 0;
            $deleteStmt = $db->prepare('DELETE FROM knowledge_items WHERE id = ? AND item_code = ? AND source_batch_id = ?');
            $delVersions = $db->prepare('DELETE FROM knowledge_item_versions WHERE knowledge_item_id = ?');
            $delSources = $db->prepare('DELETE FROM knowledge_item_sources WHERE knowledge_item_id = ? AND batch_id = ?');
            $delFav = $db->prepare('DELETE FROM knowledge_favorites WHERE knowledge_item_id = ?');
            $delRecent = $db->prepare('DELETE FROM knowledge_recent_views WHERE knowledge_item_id = ?');
            foreach ((array)($manifest['applied']['insert'] ?? []) as $code => $result) {
                $itemId = (int)$result['item_id'];
                $delFav->execute([$itemId]);
                $delRecent->execute([$itemId]);
                $delSources->execute([$itemId, $batchId]);
                $delVersions->execute([$itemId]);
                $deleteStmt->execute([$itemId, $code, $batchId]);
                if ($deleteStmt->rowCount() !== 1) {
                    failSync('insert rollback target missing: ' . $code);
                }
                $deleted++;
            }
            $updateVersionActive = $db->prepare('UPDATE knowledge_item_versions SET status = \'active\' WHERE version_id = ? AND knowledge_item_id = ?');
            $deleteVersionRow = $db->prepare('DELETE FROM knowledge_item_versions WHERE version_id = ? AND knowledge_item_id = ?');
            $restoreItem = $db->prepare(
                'UPDATE knowledge_items
                 SET category_id = ?, title = ?, summary = ?, content = ?, domain_code = ?, publication_status = ?,
                     source_batch_id = ?, current_version_id = ?
                 WHERE id = ? AND item_code = ?'
            );
            foreach ((array)($manifest['applied']['update'] ?? []) as $code => $result) {
                $itemId = (int)$result['item_id'];
                $previous = (int)($result['previous_version_id'] ?? 0);
                $newVersion = (int)($result['version_id'] ?? 0);
                $original = $itemsById[$itemId] ?? null;
                if ($previous > 0 && isset($versionsById[$previous])) {
                    $deleteVersionRow->execute([$newVersion, $itemId]);
                    $updateVersionActive->execute([$previous, $itemId]);
                    $restoreItem->execute([
                        $original['category_id'],
                        $original['title'],
                        $original['summary'],
                        $original['content'],
                        $original['domain_code'],
                        $original['publication_status'],
                        $original['source_batch_id'],
                        $previous,
                        $itemId,
                        $code,
                    ]);
                    $restored++;
                } else {
                    $fail = 'update rollback missing previous version for ' . $code;
                    failSync($fail);
                }
            }
            $qa = $db->prepare(
                "INSERT INTO knowledge_audit_logs
                    (batch_id, actor_user_id, actor_staff_id, action, target_type, target_id, before_json, after_json, metadata_json, ip_address, user_agent)
                 VALUES (?, NULL, NULL, 'training_practice.rollback', 'batch', ?, ?, ?, ?, NULL, 'cli')"
            );
            $qa->execute([$batchId, (string)$batchId, json_encode([], JSON_UNESCAPED_SLASHES), json_encode(['deleted' => $deleted, 'restored' => $restored], JSON_UNESCAPED_SLASHES), json_encode([], JSON_UNESCAPED_SLASHES)]);
            $qc = $db->prepare("UPDATE knowledge_import_batches SET status = 'rolled_back', completed_at = NOW() WHERE batch_id = ?");
            $qc->execute([$batchId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            failSync('rollback failed, transaction reverted: ' . $e->getMessage());
        }
        echo '[info] rolled back batch=' . $batchId . ' deleted=' . $deleted . ' restored=' . $restored . PHP_EOL;
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([SYNC_LOCK_NAME]);
    }
}

try {
    if (!extension_loaded('pdo_mysql') || !function_exists('getDB')) {
        failSync('pdo_mysql and api/config.php getDB() are required');
    }
    $args = parseSyncArgs($argv);
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($args['command'] === 'rollback') {
        commandRollback($db, $args);
    } else {
        commandImport($db, $args);
    }
    exit(0);
} catch (SyncCliException $e) {
    exit($e->exitCode);
} catch (Throwable $e) {
    fwrite(STDERR, '[error] ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
