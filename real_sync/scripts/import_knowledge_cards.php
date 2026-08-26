#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * 二期知识卡安全导入 CLI（默认只读 dry-run）。
 *
 * 安全边界（复用销售一期并适配二期 schema）：
 * - 仅允许 CLI 执行；数据库连接统一走 api/config.php::getDB()，禁止硬编码凭据。
 * - 数据包必须通过 SHA-256、schema_version 与逐字段结构校验。
 * - 默认 dry-run：无锁、无备份、无 manifest、无任何 INSERT/UPDATE/DELETE。
 * - apply：命名锁 -> 加锁后二次 preflight/diff -> 导入前备份 -> 事务 ->
 *          事务内三次 diff -> 批次/条目/版本/来源/候选关系 -> 提交后断言 ->
 *          pending/recoverable/completed manifest；任何异常回滚并释放锁。
 * - 幂等：以 item_code + (batch_id, source_sha256/normalized_hash) 判定 insert/skip/update_pending；
 *          已有旧行只允许新增版本与来源映射，绝不删除重插。
 * - 回滚：只信任 completed 或明确可恢复的 pending manifest，精确核验批次 ID/编码、
 *          用户进度/收藏/浏览/演练模板引用与已人工处理关系后，再删除本批新增数据。
 * - 报告、备份、manifest 必须位于 Web 根目录之外，原子写入、0600、禁止覆盖已有文件。
 *
 * 用法：
 *   import <json> --sha256 HASH [--report PATH]
 *                [--apply --backup-dir DIR --manifest PATH]
 *                [--allow-update] [--ack-manual-review]
 *   rollback <json> --sha256 HASH --manifest PATH [--report PATH]
 *                [--apply --backup-dir DIR]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require __DIR__ . '/../api/config.php'; // getDB()

const KNOWLEDGE_PACKAGE_VERSION = 'knowledge-card-isolated-package.v2';
const KNOWLEDGE_MANIFEST_VERSION = 'knowledge-card-manifest.v1';
const KNOWLEDGE_BACKUP_VERSION = 'knowledge-card-backup.v1';
const KNOWLEDGE_LOCK_NAME = 'supercalf_knowledge_cards_phase2_v1';
const SIMILARITY_THRESHOLD = 0.80;
const IMPORT_CATEGORY_CODE = 'phase2_import';
const CARD_TYPES = ['action', 'game', 'training_plan', 'teaching_organization', 'teaching_knowledge', 'assessment', 'safety'];
const RISK_LEVELS = ['低', '中', '高'];
const SOURCE_STATUSES = ['待整理', '待审核', '已审核', '已纳入课程', '不采用'];
const PACKAGE_TOP_KEYS = [
    'card_types', 'default_category_code', 'domain_codes', 'domain_mapping', 'package_sha256',
    'parser_version', 'publication_default', 'quality_flag_counts', 'record_count', 'records',
    'risk_counts', 'schema_version', 'source_file_count', 'source_report_error_count',
    'source_report_sha256', 'source_report_valid', 'source_root_name', 'status_counts', 'type_counts',
];
const RECORD_KEYS = [
    'content', 'content_type', 'content_type_label', 'domain_code', 'domain_mapping_status',
    'item_code', 'metadata', 'normalized_hash', 'publication_status', 'raw_markdown', 'risk_level',
    'source_card_id', 'source_path', 'source_sha256', 'source_status', 'title',
];

final class CliException extends RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode = 2)
    {
        parent::__construct($message);
    }
}

function failCli(string $message, int $code = 2): never
{
    throw new CliException($message, $code);
}

function usage(): string
{
    return <<<TXT
用法:
  import <json> --sha256 HASH [--report PATH]
               [--apply --backup-dir DIR --manifest PATH]
               [--allow-update] [--ack-manual-review]
  rollback <json> --sha256 HASH --manifest PATH [--report PATH]
               [--apply --backup-dir DIR]

默认只读 dry-run；--apply 才允许写数据库。
--allow-update / --ack-manual-review 仅适用于 import --apply。
TXT;
}

function parseArgs(array $argv): array
{
    $args = ['command' => null, 'json' => null, 'sha256' => null, 'report' => null, 'apply' => false,
             'backup_dir' => null, 'manifest' => null, 'allow_update' => false, 'ack_manual_review' => false];
    $positionals = [];
    $seen = [];
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $arg = $argv[$i];
        if ($arg === '--apply' || $arg === '--allow-update' || $arg === '--ack-manual-review') {
            $key = ltrim($arg, '--');
            if (isset($seen[$key])) {
                failCli('duplicate argument: ' . $arg);
            }
            $seen[$key] = true;
            $args[str_replace('-', '_', $key)] = true;
            continue;
        }
        if (in_array($arg, ['--sha256', '--report', '--backup-dir', '--manifest'], true)) {
            if (isset($seen[$arg])) {
                failCli('duplicate argument: ' . $arg);
            }
            $seen[$arg] = true;
            $value = $argv[++$i] ?? failCli('missing value for ' . $arg);
            $key = ltrim($arg, '--');
            $args[str_replace('-', '_', $key)] = $value;
            continue;
        }
        if (strpos($arg, '--') === 0) {
            failCli('unknown argument: ' . $arg);
        }
        $positionals[] = $arg;
    }
    if (count($positionals) < 2) {
        failCli(usage());
    }
    $args['command'] = array_shift($positionals);
    $args['json'] = array_shift($positionals);
    if ($positionals !== []) {
        failCli('unexpected positional argument: ' . implode(' ', $positionals));
    }
    if (!in_array($args['command'], ['import', 'rollback'], true)) {
        failCli('unknown command: ' . $args['command']);
    }
    if ($args['sha256'] === null || !preg_match('/^[a-f0-9]{64}$/', $args['sha256'])) {
        failCli('--sha256 must be a 64-character lowercase hex digest');
    }
    if ($args['command'] === 'rollback' && $args['manifest'] === null) {
        failCli('rollback requires --manifest PATH');
    }
    if ($args['apply']) {
        if ($args['command'] === 'import' && $args['backup_dir'] === null) {
            failCli('import --apply requires --backup-dir DIR');
        }
        if ($args['backup_dir'] === null) {
            failCli('--apply requires --backup-dir DIR');
        }
        if ($args['manifest'] === null) {
            failCli('--apply requires --manifest PATH');
        }
        if ($args['allow_update'] && $args['command'] !== 'import') {
            failCli('--allow-update is only valid for import --apply');
        }
        if ($args['ack_manual_review'] && $args['command'] !== 'import') {
            failCli('--ack-manual-review is only valid for import --apply');
        }
    }
    return $args;
}

function readPackage(string $path, string $sha): array
{
    if (!is_file($path)) {
        failCli('package file not found: ' . $path);
    }
    $bytes = (string)file_get_contents($path);
    if (!hash_equals($sha, hash('sha256', $bytes))) {
        failCli('package SHA-256 mismatch');
    }
    $package = json_decode($bytes, true);
    if (!is_array($package)) {
        failCli('package is not valid JSON');
    }
    $keys = array_keys($package);
    if ($keys !== PACKAGE_TOP_KEYS) {
        failCli('unexpected package top-level keys');
    }
    if ($package['schema_version'] !== KNOWLEDGE_PACKAGE_VERSION) {
        failCli('unsupported package schema_version: ' . var_export($package['schema_version'] ?? null, true));
    }
    if (!is_array($package['records']) || count($package['records']) !== (int)$package['record_count']) {
        failCli('record_count does not match records array');
    }
    if ((int)$package['record_count'] < 1) {
        failCli('record_count must be positive');
    }
    if ($package['publication_default'] !== 'isolated') {
        failCli('publication_default must be isolated');
    }
    if ($package['default_category_code'] !== IMPORT_CATEGORY_CODE) {
        failCli('default_category_code must be ' . IMPORT_CATEGORY_CODE);
    }
    if (!is_string($package['package_sha256']) || !preg_match('/^[a-f0-9]{64}$/', $package['package_sha256'])) {
        failCli('invalid package_sha256 field');
    }
    $seenCodes = [];
    foreach ($package['records'] as $index => $record) {
        if (!is_array($record)) {
            failCli('record #' . $index . ' is not an object');
        }
        $recordKeys = array_keys($record);
        if ($recordKeys !== RECORD_KEYS) {
            failCli('unexpected keys in record #' . $index . ': ' . implode(',', $recordKeys));
        }
        if (!preg_match('/^[A-Z]+-\d{4}$/', (string)$record['item_code'])) {
            failCli('invalid item_code in record #' . $index);
        }
        if ($record['item_code'] !== $record['source_card_id']) {
            failCli('source_card_id mismatch in record #' . $index);
        }
        if (!in_array($record['content_type'], CARD_TYPES, true)) {
            failCli('invalid content_type in record #' . $index);
        }
        if (!in_array($record['risk_level'], RISK_LEVELS, true)) {
            failCli('invalid risk_level in record #' . $index);
        }
        if (!in_array($record['source_status'], SOURCE_STATUSES, true)) {
            failCli('invalid source_status in record #' . $index);
        }
        if ($record['publication_status'] !== 'isolated') {
            failCli('record #' . $index . ' must be isolated');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', (string)$record['source_sha256'])) {
            failCli('invalid source_sha256 in record #' . $index);
        }
        if (!preg_match('/^[a-f0-9]{64}$/', (string)$record['normalized_hash'])) {
            failCli('invalid normalized_hash in record #' . $index);
        }
        if (!is_string($record['title']) || $record['title'] === '') {
            failCli('missing title in record #' . $index);
        }
        if (!is_string($record['content']) || $record['content'] === '') {
            failCli('missing content in record #' . $index);
        }
        if (!is_string($record['raw_markdown']) || $record['raw_markdown'] === '') {
            failCli('missing raw_markdown in record #' . $index);
        }
        if (!hash_equals($record['normalized_hash'], hash('sha256', $record['content']))) {
            failCli('normalized_hash does not match content in record #' . $index);
        }
        if (!hash_equals($record['source_sha256'], hash('sha256', $record['raw_markdown']))) {
            failCli('source_sha256 does not match raw_markdown in record #' . $index);
        }
        if (isset($seenCodes[$record['item_code']])) {
            failCli('duplicate item_code in package: ' . $record['item_code']);
        }
        $seenCodes[$record['item_code']] = true;
    }
    $identityParts = [
        $package['schema_version'],
        $package['parser_version'],
        $package['source_root_name'],
        (string)$package['record_count'],
        $package['source_report_sha256'],
    ];
    foreach ($package['records'] as $record) {
        $identityParts[] = $record['item_code'];
        $identityParts[] = $record['source_sha256'];
        $identityParts[] = $record['normalized_hash'];
    }
    $computedIdentity = hash('sha256', implode("\0", $identityParts));
    if (!hash_equals($package['package_sha256'], $computedIdentity)) {
        failCli('package_sha256 does not match package identity');
    }
    return $package;
}

function preflight(PDO $db, array $package): int
{
    $tables = [
        'knowledge_import_batches', 'knowledge_items', 'knowledge_item_versions', 'knowledge_item_sources',
        'knowledge_item_relations', 'knowledge_favorites', 'knowledge_recent_views', 'knowledge_audit_logs',
        'knowledge_categories', 'user_knowledge_progress', 'drill_templates',
    ];
    $q = $db->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach ($tables as $table) {
        $q->execute([$table]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            failCli('required table missing: ' . $table . ' (run migration 202608260001/202608260002 first)');
        }
        if (strcasecmp((string)$row['ENGINE'], 'InnoDB') !== 0) {
            failCli('table must be InnoDB: ' . $table);
        }
    }
    $requiredColumns = ['item_code', 'content_type', 'domain_code', 'risk_level', 'publication_status', 'source_batch_id', 'current_version_id'];
    $qc = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    foreach ($requiredColumns as $column) {
        $qc->execute(['knowledge_items', $column]);
        if ($qc->fetch(PDO::FETCH_ASSOC) === false) {
            failCli('knowledge_items missing column: ' . $column . ' (run migration 202608260001 first)');
        }
    }
    $qc->execute(['knowledge_import_batches', 'manifest_sha256']);
    if ($qc->fetch(PDO::FETCH_ASSOC) === false) {
        failCli('knowledge_import_batches missing column: manifest_sha256 (run migration 202608260003 first)');
    }
    $qi = $db->prepare("SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $qi->execute(['knowledge_items', 'uk_knowledge_items_item_code']);
    $indexRow = $qi->fetch(PDO::FETCH_ASSOC);
    if ($indexRow === false || (int)$indexRow['NON_UNIQUE'] !== 0) {
        failCli('unique index uk_knowledge_items_item_code missing on knowledge_items');
    }
    $qcat = $db->prepare('SELECT id FROM knowledge_categories WHERE code = ? AND status = 1');
    $qcat->execute([IMPORT_CATEGORY_CODE]);
    $categoryId = $qcat->fetchColumn();
    if ($categoryId === false) {
        failCli('target category missing: ' . IMPORT_CATEGORY_CODE . ' (run migration 202608260002 first)');
    }
    return (int)$categoryId;
}

function selectIn(PDO $db, string $sql, array $values, string $column, array $codes): array
{
    if ($codes === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($codes), '?'));
    $q = $db->prepare(str_replace('%s', $marks, $sql));
    $params = $values;
    foreach ($codes as $code) {
        $params[] = $code;
    }
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function loadState(PDO $db, array $package): array
{
    $codes = array_column($package['records'], 'item_code');
    $items = [];
    foreach (selectIn($db, 'SELECT * FROM knowledge_items WHERE item_code IN (%s) ORDER BY id', [], 'item_code', $codes) as $row) {
        $items[$row['item_code']] = $row;
    }
    $batchRow = null;
    $q = $db->prepare('SELECT batch_id, status, manifest_path FROM knowledge_import_batches WHERE package_sha256 = ? ORDER BY batch_id DESC LIMIT 1');
    $q->execute([$package['package_sha256']]);
    $batchRow = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $sources = [];
    if ($batchRow !== null && in_array($batchRow['status'], ['isolated', 'reviewing', 'published'], true) && $codes !== []) {
        $params = [(int)$batchRow['batch_id']];
        $rows = selectIn($db, 'SELECT * FROM knowledge_item_sources WHERE batch_id = ? AND source_card_id IN (%s)', $params, 'source_card_id', $codes);
        foreach ($rows as $row) {
            $sources[$row['source_card_id']] = $row;
        }
    }
    $allItems = $db->query('SELECT id, item_code, title FROM knowledge_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    return ['items' => $items, 'sources' => $sources, 'batch' => $batchRow, 'all_items' => $allItems];
}

function similarity(string $left, string $right): float
{
    similar_text($left, $right, $percent);
    return $percent / 100.0;
}

function diffPackage(array $package, array $state): array
{
    $recordsByCode = [];
    foreach ($package['records'] as $record) {
        $recordsByCode[$record['item_code']] = $record;
    }
    $packageCodes = array_keys($recordsByCode);
    $packageCodeSet = array_fill_keys($packageCodes, true);
    $diff = [
        'items' => ['insert' => [], 'skip' => [], 'update_pending' => []],
        'manual_review' => [],
        'candidates' => [],
    ];
    foreach ($recordsByCode as $code => $record) {
        if (!isset($state['items'][$code])) {
            $diff['items']['insert'][] = $code;
            continue;
        }
        $source = $state['sources'][$code] ?? null;
        if ($source !== null
            && hash_equals((string)$source['source_sha256'], (string)$record['source_sha256'])
            && hash_equals((string)$source['normalized_hash'], (string)$record['normalized_hash'])) {
            $diff['items']['skip'][] = $code;
            continue;
        }
        $diff['items']['update_pending'][] = $code;
        $diff['manual_review'][] = ['item_code' => $code, 'reason' => 'content_or_source_changed'];
    }
    $oldItems = [];
    foreach ($state['all_items'] as $row) {
        $code = $row['item_code'] ?? '';
        if ($code === '' || !isset($packageCodeSet[$code])) {
            $oldItems[] = $row;
        }
    }
    foreach ($diff['items']['insert'] as $code) {
        $record = $recordsByCode[$code];
        foreach ($oldItems as $old) {
            $ratio = similarity((string)$record['title'], (string)$old['title']);
            if ($ratio >= SIMILARITY_THRESHOLD) {
                $diff['candidates'][] = [
                    'item_code' => $code,
                    'target_item_id' => (int)$old['id'],
                    'target_title' => (string)$old['title'],
                    'similarity' => $ratio,
                ];
            }
        }
    }
    return $diff;
}

function summary(array $package, array $state, array $diff, array $args): void
{
    $insert = count($diff['items']['insert']);
    $skip = count($diff['items']['skip']);
    $update = count($diff['items']['update_pending']);
    $out = [
        'command' => 'import dry-run',
        'package_sha256' => $package['package_sha256'],
        'record_count' => $package['record_count'],
        'insert' => $insert,
        'skip' => $skip,
        'update_pending' => $update,
        'manual_review_count' => count($diff['manual_review']),
        'candidate_count' => count($diff['candidates']),
        'type_counts' => $package['type_counts'],
        'risk_counts' => $package['risk_counts'],
    ];
    if ($args['report'] !== null) {
        $report = $out;
        $report['manual_review'] = $diff['manual_review'];
        $report['candidates'] = $diff['candidates'];
        atomicJson($args['report'], $report);
        $out['report'] = $args['report'];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    if ($insert === 0 && $update === 0) {
        echo "no changes: all records already imported (idempotent)\n";
    }
    if ($update > 0) {
        echo "update_pending=" . $update . " requires --allow-update for apply\n";
    }
    if (count($diff['manual_review']) > 0) {
        echo "manual_review=" . count($diff['manual_review']) . " requires --ack-manual-review for apply\n";
    }
}

function validateOutputPath(string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        throw new RuntimeException('output directory does not exist: ' . $dir);
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('output directory not writable: ' . $dir);
    }
    $real = realpath($dir);
    if ($real === false) {
        throw new RuntimeException('cannot resolve output directory: ' . $dir);
    }
    $webRoot = realpath(__DIR__ . '/..');
    if ($webRoot !== false && strpos($real . DIRECTORY_SEPARATOR, $webRoot . DIRECTORY_SEPARATOR) === 0) {
        throw new RuntimeException('output path must be outside the web root: ' . $path);
    }
}

function atomicJson(string $path, array $data, bool $overwrite = false): void
{
    validateOutputPath($path);
    if (!$overwrite && file_exists($path)) {
        throw new RuntimeException('refusing to overwrite existing file: ' . $path);
    }
    $dir = dirname($path);
    $temporary = tempnam($dir, '.knowledge-');
    if ($temporary === false) {
        throw new RuntimeException('cannot create temporary file in ' . $dir);
    }
    try {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new RuntimeException('cannot write temporary file: ' . $temporary);
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            throw new RuntimeException('cannot atomically publish file: ' . $path);
        }
    } finally {
        if (file_exists($temporary)) {
            @unlink($temporary);
        }
    }
}

function counts(PDO $db): array
{
    $tables = [
        'knowledge_import_batches', 'knowledge_items', 'knowledge_item_versions', 'knowledge_item_sources',
        'knowledge_item_relations', 'knowledge_favorites', 'knowledge_recent_views', 'knowledge_audit_logs',
        'knowledge_categories', 'user_knowledge_progress', 'drill_templates',
    ];
    $out = [];
    foreach ($tables as $table) {
        $out[$table] = (int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    return $out;
}

function tableSnapshot(PDO $db, string $table): array
{
    $create = $db->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC);
    $createSql = $create === false ? '' : (string)end($create);
    return [
        'table' => $table,
        'create_table' => $createSql,
        'row_count' => (int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(),
    ];
}

function rowsDigestExceptCodes(PDO $db, array $codes): string
{
    if ($codes === []) {
        return hash('sha256', 'no rows');
    }
    $marks = implode(',', array_fill(0, count($codes), '?'));
    $q = $db->prepare('SELECT * FROM knowledge_items WHERE item_code NOT IN (' . $marks . ') ORDER BY id');
    $q->execute($codes);
    return hash('sha256', (string)json_encode($q->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function rowsDigestExceptIds(PDO $db, string $table, string $idColumn, array $ids): string
{
    if ($ids === []) {
        $q = $db->query('SELECT * FROM `' . $table . '` ORDER BY `' . $idColumn . '`');
    } else {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = $db->prepare('SELECT * FROM `' . $table . '` WHERE `' . $idColumn . '` NOT IN (' . $marks . ') ORDER BY `' . $idColumn . '`');
        $q->execute($ids);
    }
    return hash('sha256', (string)json_encode($q->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function oldDigests(PDO $db, array $package, array $excludedVersionIds = []): array
{
    $codes = array_column($package['records'], 'item_code');
    return [
        'items' => rowsDigestExceptCodes($db, $codes),
        'sources' => rowsDigestExceptIds($db, 'knowledge_item_sources', 'source_id', []),
        'versions' => rowsDigestExceptIds($db, 'knowledge_item_versions', 'version_id', $excludedVersionIds),
        'excluded_version_ids' => $excludedVersionIds,
        'relations' => rowsDigestExceptIds($db, 'knowledge_item_relations', 'relation_id', []),
        'favorites' => rowsDigestExceptIds($db, 'knowledge_favorites', 'favorite_id', []),
        'recent_views' => rowsDigestExceptIds($db, 'knowledge_recent_views', 'recent_view_id', []),
        'audit' => rowsDigestExceptIds($db, 'knowledge_audit_logs', 'audit_id', []),
    ];
}

function backup(PDO $db, array $package, array $state, string $dir, string $label): array
{
    $targetItemIds = [];
    $targetItems = [];
    foreach ($state['items'] as $code => $row) {
        $targetItems[] = $row;
        $targetItemIds[] = (int)$row['id'];
    }
    $related = [];
    if ($targetItemIds !== []) {
        $marks = implode(',', array_fill(0, count($targetItemIds), '?'));
        $q = $db->prepare('SELECT * FROM user_knowledge_progress WHERE knowledge_id IN (' . $marks . ')');
        $q->execute($targetItemIds);
        $related['user_knowledge_progress'] = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach (['knowledge_favorites' => 'knowledge_id', 'knowledge_recent_views' => 'knowledge_id'] as $table => $column) {
            $q = $db->prepare('SELECT * FROM `' . $table . '` WHERE `' . $column . '` IN (' . $marks . ')');
            $q->execute($targetItemIds);
            $related[$table] = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        $q = $db->prepare('SELECT * FROM drill_templates WHERE knowledge_card_id IN (' . $marks . ')');
        $q->execute($targetItemIds);
        $related['drill_templates'] = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach (['knowledge_item_versions' => 'knowledge_item_id', 'knowledge_item_sources' => 'knowledge_item_id'] as $table => $column) {
            $q = $db->prepare('SELECT * FROM `' . $table . '` WHERE `' . $column . '` IN (' . $marks . ') ORDER BY 1');
            $q->execute($targetItemIds);
            $related[$table] = $q->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    $snapshots = [];
    foreach (array_keys(counts($db)) as $table) {
        $snapshots[] = tableSnapshot($db, $table);
    }
    $data = [
        'schema_version' => KNOWLEDGE_BACKUP_VERSION,
        'created_at' => date('Y-m-d H:i:s'),
        'label' => $label,
        'package_sha256' => $package['package_sha256'],
        'target_items' => $targetItems,
        'related' => $related,
        'tables' => $snapshots,
    ];
    $path = rtrim($dir, '/\\') . '/knowledge-cards-backup-' . date('Ymd-His') . '-' . $label . '.json';
    atomicJson($path, $data);
    return ['path' => $path, 'sha256' => hash('sha256', (string)file_get_contents($path))];
}

function insertAudit(PDO $db, ?int $batchId, string $action, string $targetType, string $targetId, array $before, array $after, array $metadata): void
{
    $q = $db->prepare(
        'INSERT INTO knowledge_audit_logs
            (batch_id, actor_user_id, actor_staff_id, action, target_type, target_id, before_json, after_json, metadata_json, ip_address, user_agent)
         VALUES (?, NULL, NULL, ?, ?, ?, ?, ?, ?, NULL, ?)'
    );
    $q->execute([
        $batchId, $action, $targetType, $targetId,
        json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'cli',
    ]);
}

function insertItem(PDO $db, int $categoryId, int $batchId, array $record): array
{
    $subjects = is_array($record['metadata']['subjects'] ?? null) ? $record['metadata']['subjects'] : [];
    $subjectsText = mb_substr(implode('、', $subjects), 0, 255);
    $ageGroup = mb_substr((string)($record['metadata']['primary_age'] ?? ''), 0, 255);
    $tagsJson = $subjects === [] ? null : json_encode($subjects, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sourceText = mb_substr((string)$record['source_path'], 0, 50);

    $q = $db->prepare(
        'INSERT INTO knowledge_items
            (category_id, title, summary, content, media_type, subject, age_group, training_type, difficulty, is_public,
             target_roles, target_stages, tags, view_count, like_count, collect_count, source, sort_order, status,
             item_code, content_type, domain_code, risk_level, publication_status, source_batch_id)
         VALUES (?, ?, NULL, ?, ?, ?, ?, NULL, NULL, 0, NULL, NULL, ?, 0, 0, 0, ?, 0, 1, ?, ?, ?, ?, ?, ?)'
    );
    $q->execute([
        $categoryId,
        mb_substr((string)$record['title'], 0, 200),
        $record['content'],
        'text',
        mb_substr($subjectsText, 0, 50),
        mb_substr($ageGroup, 0, 50),
        $tagsJson,
        $sourceText,
        $record['item_code'],
        $record['content_type'],
        $record['domain_code'] ?? null,
        $record['risk_level'],
        'isolated',
        $batchId,
    ]);
    $itemId = (int)$db->lastInsertId();

    $sourceSnapshot = [
        'source_articles' => $record['metadata']['source_articles'] ?? null,
        'source_images' => $record['metadata']['source_images'] ?? null,
        'card_type' => $record['metadata']['card_type'] ?? null,
        'source_status' => $record['source_status'],
    ];
    $q = $db->prepare(
        'INSERT INTO knowledge_item_versions
            (knowledge_item_id, version_no, title, summary, content, content_format, content_type, domain_code, risk_level,
             subject, age_group, training_type, difficulty, tags_json, source_snapshot_json, raw_markdown, change_reason, changed_by, status)
         VALUES (?, 1, ?, NULL, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, NULL, ?)'
    );
    $q->execute([
        $itemId,
        mb_substr((string)$record['title'], 0, 200),
        $record['content'],
        'markdown',
        $record['content_type'],
        $record['domain_code'] ?? null,
        $record['risk_level'],
        $subjectsText,
        $ageGroup,
        $tagsJson,
        json_encode($sourceSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $record['raw_markdown'],
        'initial import',
        'active',
    ]);
    $versionId = (int)$db->lastInsertId();

    $q = $db->prepare(
        'INSERT INTO knowledge_item_sources
            (knowledge_item_id, batch_id, source_card_id, source_path, source_sha256, normalized_hash,
             source_articles_json, source_images_json, raw_frontmatter_json, raw_markdown)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $q->execute([
        $itemId,
        $batchId,
        $record['item_code'],
        $record['source_path'],
        $record['source_sha256'],
        $record['normalized_hash'],
        json_encode($record['metadata']['source_articles'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($record['metadata']['source_images'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($record['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $record['raw_markdown'],
    ]);
    $sourceId = (int)$db->lastInsertId();

    $q = $db->prepare('UPDATE knowledge_items SET current_version_id = ? WHERE id = ? AND item_code = ?');
    $q->execute([$versionId, $itemId, $record['item_code']]);

    return ['item_id' => $itemId, 'version_id' => $versionId, 'source_id' => $sourceId];
}

function updateItem(PDO $db, int $batchId, array $state, array $record): array
{
    $existing = $state['items'][$record['item_code']];
    $itemId = (int)$existing['id'];
    $q = $db->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 AS next_no FROM knowledge_item_versions WHERE knowledge_item_id = ?');
    $q->execute([$itemId]);
    $versionNo = (int)$q->fetchColumn();
    $q = $db->prepare("UPDATE knowledge_item_versions SET status = 'superseded' WHERE knowledge_item_id = ? AND status = 'active'");
    $q->execute([$itemId]);

    $subjects = is_array($record['metadata']['subjects'] ?? null) ? $record['metadata']['subjects'] : [];
    $subjectsText = mb_substr(implode('、', $subjects), 0, 255);
    $ageGroup = mb_substr((string)($record['metadata']['primary_age'] ?? ''), 0, 255);
    $tagsJson = $subjects === [] ? null : json_encode($subjects, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sourceSnapshot = [
        'source_articles' => $record['metadata']['source_articles'] ?? null,
        'source_images' => $record['metadata']['source_images'] ?? null,
        'card_type' => $record['metadata']['card_type'] ?? null,
        'source_status' => $record['source_status'],
    ];
    $q = $db->prepare(
        'INSERT INTO knowledge_item_versions
            (knowledge_item_id, version_no, title, summary, content, content_format, content_type, domain_code, risk_level,
             subject, age_group, training_type, difficulty, tags_json, source_snapshot_json, raw_markdown, change_reason, changed_by, status)
         VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, NULL, ?)'
    );
    $q->execute([
        $itemId,
        $versionNo,
        mb_substr((string)$record['title'], 0, 200),
        $record['content'],
        'markdown',
        $record['content_type'],
        $record['domain_code'] ?? null,
        $record['risk_level'],
        $subjectsText,
        $ageGroup,
        $tagsJson,
        json_encode($sourceSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $record['raw_markdown'],
        'package v2 re-import (content changed)',
        'active',
    ]);
    $versionId = (int)$db->lastInsertId();

    $q = $db->prepare(
        'INSERT INTO knowledge_item_sources
            (knowledge_item_id, batch_id, source_card_id, source_path, source_sha256, normalized_hash,
             source_articles_json, source_images_json, raw_frontmatter_json, raw_markdown)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $q->execute([
        $itemId,
        $batchId,
        $record['item_code'],
        $record['source_path'],
        $record['source_sha256'],
        $record['normalized_hash'],
        json_encode($record['metadata']['source_articles'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($record['metadata']['source_images'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($record['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $record['raw_markdown'],
    ]);
    $sourceId = (int)$db->lastInsertId();

    $q = $db->prepare(
        'UPDATE knowledge_items
            SET title = ?, content = ?, content_type = ?, risk_level = ?, domain_code = ?,
                source_batch_id = ?, current_version_id = ?
          WHERE id = ? AND item_code = ?'
    );
    $q->execute([
        mb_substr((string)$record['title'], 0, 200),
        $record['content'],
        $record['content_type'],
        $record['risk_level'],
        $record['domain_code'] ?? null,
        $batchId,
        $versionId,
        $itemId,
        $record['item_code'],
    ]);
    return ['item_id' => $itemId, 'version_id' => $versionId, 'source_id' => $sourceId];
}

function assertImport(PDO $db, array $package, array $diff, array $applied, array $oldDigest): void
{
    $codes = array_column($package['records'], 'item_code');
    $mutableCodes = array_merge($diff['items']['insert'], $diff['items']['update_pending']);
    $insertCodes = $diff['items']['insert'];

    if (!hash_equals($oldDigest['items'], rowsDigestExceptCodes($db, $codes))) {
        throw new RuntimeException('non-target knowledge_items changed during import');
    }
    $newSourceIds = array_column($applied['rows'], 'source_id');
    $newVersionIds = array_merge($oldDigest['excluded_version_ids'], array_column($applied['rows'], 'version_id'));
    $newRelationIds = array_column($applied['relations'], 'relation_id');
    $newAuditIds = $applied['audit_ids'];
    if (!hash_equals($oldDigest['sources'], rowsDigestExceptIds($db, 'knowledge_item_sources', 'source_id', $newSourceIds))
        || !hash_equals($oldDigest['versions'], rowsDigestExceptIds($db, 'knowledge_item_versions', 'version_id', $newVersionIds))
        || !hash_equals($oldDigest['relations'], rowsDigestExceptIds($db, 'knowledge_item_relations', 'relation_id', $newRelationIds))
        || !hash_equals($oldDigest['favorites'], rowsDigestExceptIds($db, 'knowledge_favorites', 'favorite_id', []))
        || !hash_equals($oldDigest['recent_views'], rowsDigestExceptIds($db, 'knowledge_recent_views', 'recent_view_id', []))
        || !hash_equals($oldDigest['audit'], rowsDigestExceptIds($db, 'knowledge_audit_logs', 'audit_id', $newAuditIds))) {
        throw new RuntimeException('non-target rows changed during import');
    }
    if ($mutableCodes !== []) {
        $marks = implode(',', array_fill(0, count($mutableCodes), '?'));
        $q = $db->prepare('SELECT COUNT(*) FROM knowledge_items WHERE item_code IN (' . $marks . ')');
        $q->execute($mutableCodes);
        if ((int)$q->fetchColumn() !== count($mutableCodes)) {
            throw new RuntimeException('imported item count mismatch');
        }
        $q = $db->prepare(
            'SELECT COUNT(*) FROM knowledge_items WHERE item_code IN (' . $marks . ') AND publication_status = \'isolated\' AND is_public = 0 AND status = 1'
        );
        $q->execute($mutableCodes);
        if ((int)$q->fetchColumn() !== count($mutableCodes)) {
            throw new RuntimeException('imported items must be isolated, non-public, status=1');
        }
        foreach ($applied['rows'] as $row) {
            $q = $db->prepare('SELECT COUNT(*) FROM knowledge_item_sources WHERE knowledge_item_id = ? AND batch_id = ?');
            $q->execute([$row['item_id'], $row['batch_id']]);
            if ((int)$q->fetchColumn() < 1) {
                throw new RuntimeException('imported item missing source: ' . $row['item_code']);
            }
            $q = $db->prepare("SELECT COUNT(*) FROM knowledge_item_versions WHERE knowledge_item_id = ? AND status = 'active'");
            $q->execute([$row['item_id']]);
            if ((int)$q->fetchColumn() !== 1) {
                throw new RuntimeException('imported item must have exactly one active version: ' . $row['item_code']);
            }
            $q = $db->prepare('SELECT current_version_id FROM knowledge_items WHERE id = ? AND item_code = ?');
            $q->execute([$row['item_id'], $row['item_code']]);
            $currentVersionId = $q->fetchColumn();
            if ($currentVersionId === false || (int)$currentVersionId !== $row['version_id']) {
                throw new RuntimeException('current_version_id not pointing at latest version: ' . $row['item_code']);
            }
        }
        if ($insertCodes !== []) {
            $marks2 = implode(',', array_fill(0, count($insertCodes), '?'));
            $q = $db->prepare(
                'SELECT COUNT(*) FROM knowledge_item_relations r
                   JOIN knowledge_items src ON src.id = r.source_item_id
                   JOIN knowledge_items tgt ON tgt.id = r.target_item_id
                  WHERE src.item_code IN (' . $marks2 . ') AND r.reviewed_by IS NULL'
            );
            $q->execute($insertCodes);
            $candidateCount = (int)$q->fetchColumn();
            if ($candidateCount !== count($applied['relations'])) {
                throw new RuntimeException('candidate relation count mismatch: ' . $candidateCount . ' vs ' . count($applied['relations']));
            }
        }
    }
    $orphanChecks = [
        'knowledge_item_sources' => 'knowledge_item_id',
        'knowledge_item_versions' => 'knowledge_item_id',
        'knowledge_item_relations' => 'source_item_id',
        'knowledge_favorites' => 'knowledge_id',
        'knowledge_recent_views' => 'knowledge_id',
    ];
    foreach ($orphanChecks as $table => $column) {
        $q = $db->query('SELECT COUNT(*) FROM `' . $table . '` LEFT JOIN knowledge_items i ON i.id = `' . $table . '`.`' . $column . '` WHERE i.id IS NULL');
        if ((int)$q->fetchColumn() > 0) {
            throw new RuntimeException('orphan rows in ' . $table);
        }
    }
}

function bindManifestDigest(PDO $db, int $batchId, string $packageSha256, string $manifestPath): string
{
    $manifestSha256 = hash_file('sha256', $manifestPath);
    if ($manifestSha256 === false) {
        throw new RuntimeException('manifest digest cannot be calculated before commit');
    }
    $q = $db->prepare(
        'UPDATE knowledge_import_batches
            SET manifest_sha256 = ?
          WHERE batch_id = ? AND package_sha256 = ? AND manifest_path = ? AND manifest_sha256 IS NULL'
    );
    $q->execute([$manifestSha256, $batchId, $packageSha256, $manifestPath]);
    if ($q->rowCount() !== 1) {
        throw new RuntimeException('manifest digest cannot be bound inside the import transaction');
    }
    return $manifestSha256;
}

function applyImport(PDO $db, array $package, array $args): void
{
    $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([KNOWLEDGE_LOCK_NAME]);
    if ((int)$lock->fetchColumn() !== 1) {
        failCli('could not acquire named lock ' . KNOWLEDGE_LOCK_NAME);
    }
    try {
        $categoryId = preflight($db, $package);
        $state = loadState($db, $package);
        if ($state['batch'] !== null && in_array($state['batch']['status'], ['rolled_back', 'failed'], true)) {
            failCli('package already has a ' . $state['batch']['status'] . ' batch; manual reconciliation required before re-import');
        }
        $diff = diffPackage($package, $state);
        $before = counts($db);
        $excludedVersionIds = [];
        foreach ($diff['items']['update_pending'] as $code) {
            $itemId = (int)$state['items'][$code]['id'];
            $q = $db->prepare('SELECT version_id FROM knowledge_item_versions WHERE knowledge_item_id = ? ORDER BY version_id');
            $q->execute([$itemId]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $versionId) {
                $excludedVersionIds[] = (int)$versionId;
            }
        }
        $oldDigest = oldDigests($db, $package, $excludedVersionIds);
        $insertCount = count($diff['items']['insert']);
        $updateCount = count($diff['items']['update_pending']);
        if ($insertCount === 0 && $updateCount === 0) {
            echo "no changes: all " . count($package['records']) . " records already imported (idempotent)\n";
            return;
        }
        if ($state['batch'] !== null && $updateCount > 0) {
            failCli('existing package batch has changed source rows; refusing duplicate batch/source update');
        }
        if ($updateCount > 0 && !$args['allow_update']) {
            failCli('update_pending=' . $updateCount . ' requires --allow-update (existing old rows are preserved, never deleted)');
        }
        if (count($diff['manual_review']) > 0 && !$args['ack_manual_review']) {
            failCli('manual_review=' . count($diff['manual_review']) . ' requires --ack-manual-review');
        }
        $backup = backup($db, $package, $state, $args['backup_dir'], 'import-before');
        $manifest = [
            'schema_version' => KNOWLEDGE_MANIFEST_VERSION,
            'status' => 'pending',
            'package_sha256' => $package['package_sha256'],
            'batch_id' => $state['batch'] !== null ? (int)$state['batch']['batch_id'] : null,
            'backup' => $backup,
            'before_counts' => $before,
            'planned_diff' => ['insert' => $insertCount, 'update' => $updateCount, 'skip' => count($diff['items']['skip'])],
            'inserted' => ['items' => [], 'sources' => [], 'versions' => [], 'relations' => []],
            'updated' => ['items' => []],
            'skipped' => ['items' => $diff['items']['skip']],
            'recoverable_from_pending' => false,
            'requires_database_verification' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $manifestSha256 = null;
        $db->beginTransaction();
        try {
            $state2 = loadState($db, $package);
            $diff2 = diffPackage($package, $state2);
            if ($diff2['items']['insert'] !== $diff['items']['insert'] || $diff2['items']['update_pending'] !== $diff['items']['update_pending']) {
                throw new RuntimeException('diff changed after acquiring lock');
            }
            $recordsByCode = [];
            foreach ($package['records'] as $record) {
                $recordsByCode[$record['item_code']] = $record;
            }
            $newBatchId = $state['batch'] !== null ? (int)$state['batch']['batch_id'] : null;
            if ($newBatchId === null) {
                $qb = $db->prepare(
                    'INSERT INTO knowledge_import_batches (package_sha256, source_root, parser_version, status, before_counts_json, manifest_path)
                     VALUES (?, ?, ?, \'isolated\', ?, ?)'
                );
                $qb->execute([
                    $package['package_sha256'],
                    mb_substr((string)$package['source_root_name'], 0, 500),
                    mb_substr((string)$package['parser_version'], 0, 32),
                    json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $args['manifest'],
                ]);
                $newBatchId = (int)$db->lastInsertId();
            }
            $applied = ['rows' => [], 'relations' => [], 'audit_ids' => []];
            foreach ($diff2['items']['insert'] as $code) {
                $result = insertItem($db, $categoryId, $newBatchId, $recordsByCode[$code]);
                $applied['rows'][] = ['item_id' => $result['item_id'], 'version_id' => $result['version_id'], 'source_id' => $result['source_id'], 'batch_id' => $newBatchId, 'item_code' => $code];
                $manifest['inserted']['items'][] = ['id' => $result['item_id'], 'item_code' => $code];
                $manifest['inserted']['versions'][] = ['version_id' => $result['version_id'], 'item_id' => $result['item_id']];
                $manifest['inserted']['sources'][] = ['source_id' => $result['source_id'], 'item_id' => $result['item_id'], 'source_card_id' => $code];
            }
            foreach ($diff2['items']['update_pending'] as $code) {
                $result = updateItem($db, $newBatchId, $state2, $recordsByCode[$code]);
                $applied['rows'][] = ['item_id' => $result['item_id'], 'version_id' => $result['version_id'], 'source_id' => $result['source_id'], 'batch_id' => $newBatchId, 'item_code' => $code];
                $manifest['inserted']['versions'][] = ['version_id' => $result['version_id'], 'item_id' => $result['item_id']];
                $manifest['inserted']['sources'][] = ['source_id' => $result['source_id'], 'item_id' => $result['item_id'], 'source_card_id' => $code];
                $manifest['updated']['items'][] = ['id' => $result['item_id'], 'item_code' => $code];
            }
            $newItemIds = [];
            foreach ($applied['rows'] as $row) {
                if (in_array($row['item_code'], $diff2['items']['insert'], true)) {
                    $newItemIds[$row['item_code']] = $row['item_id'];
                }
            }
            foreach ($diff2['candidates'] as $candidate) {
                if (!isset($newItemIds[$candidate['item_code']])) {
                    continue;
                }
                $sourceItemId = $newItemIds[$candidate['item_code']];
                $q = $db->prepare(
                    'INSERT IGNORE INTO knowledge_item_relations (source_item_id, target_item_id, relation_type)
                     VALUES (?, ?, \'candidate\')'
                );
                $q->execute([$sourceItemId, $candidate['target_item_id']]);
                $relationId = (int)$db->lastInsertId();
                if ($relationId > 0) {
                    $manifest['inserted']['relations'][] = [
                        'relation_id' => $relationId,
                        'source_item_id' => $sourceItemId,
                        'target_item_id' => $candidate['target_item_id'],
                    ];
                }
                $applied['relations'][] = ['relation_id' => $relationId, 'source_item_id' => $sourceItemId, 'target_item_id' => $candidate['target_item_id']];
            }
            $after = counts($db);
            $qc = $db->prepare('UPDATE knowledge_import_batches SET after_counts_json = ? WHERE batch_id = ?');
            $qc->execute([json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $newBatchId]);
            insertAudit(
                $db,
                $newBatchId,
                'import',
                'knowledge_import_batches',
                (string)$newBatchId,
                $before,
                $after,
                ['package_sha256' => $package['package_sha256'], 'inserted' => $insertCount, 'updated' => $updateCount, 'candidates' => count($applied['relations'])]
            );
            $applied['audit_ids'] = [(int)$db->lastInsertId()];
            assertImport($db, $package, $diff2, $applied, $oldDigest);
            $manifest['batch_id'] = $newBatchId;
            $manifest['after_counts'] = $after;
            $manifest['prepared_at'] = date('Y-m-d H:i:s');
            atomicJson($args['manifest'], $manifest);
            $manifestSha256 = bindManifestDigest($db, $newBatchId, $package['package_sha256'], $args['manifest']);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        $completionMarker = $args['manifest'] . '.completed';
        $completion = [
            'schema_version' => 'knowledge-card-completion.v1',
            'status' => 'completed',
            'batch_id' => (int)$manifest['batch_id'],
            'package_sha256' => $package['package_sha256'],
            'manifest_path' => $args['manifest'],
            'manifest_sha256' => $manifestSha256,
            'completed_at' => date('Y-m-d H:i:s'),
        ];
        try {
            atomicJson($completionMarker, $completion);
        } catch (Throwable $error) {
            echo "database_committed: import and rollback manifest are safe, but completion marker could not be written (" . $error->getMessage() . ")\n";
            echo "recover via database-verified pending manifest: " . $args['manifest'] . "\n";
            $completionMarker = null;
        }
        echo json_encode([
            'status' => 'imported',
            'batch_id' => $manifest['batch_id'],
            'inserted' => $insertCount,
            'updated' => $updateCount,
            'skipped' => count($diff['items']['skip']),
            'candidates' => count($applied['relations']),
            'manifest' => $args['manifest'],
            'manifest_sha256' => $manifestSha256,
            'completion_marker' => $completionMarker,
            'backup' => $backup['path'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([KNOWLEDGE_LOCK_NAME]);
    }
}

function readManifest(string $path, array $package): array
{
    if (!is_file($path)) {
        failCli('manifest not found: ' . $path);
    }
    $manifest = json_decode((string)file_get_contents($path), true);
    if (!is_array($manifest) || ($manifest['schema_version'] ?? null) !== KNOWLEDGE_MANIFEST_VERSION) {
        failCli('invalid manifest: ' . $path);
    }
    if (!hash_equals($package['package_sha256'], (string)($manifest['package_sha256'] ?? ''))) {
        failCli('manifest package_sha256 does not match the supplied package');
    }
    $status = (string)($manifest['status'] ?? '');
    if (!in_array($status, ['completed', 'pending'], true)) {
        failCli('manifest is neither completed nor pending');
    }
    if (!isset($manifest['batch_id'], $manifest['inserted']['items'], $manifest['inserted']['sources'], $manifest['inserted']['versions'], $manifest['inserted']['relations'])
        || (int)$manifest['batch_id'] < 1
        || !is_array($manifest['inserted']['items'])
        || !is_array($manifest['inserted']['sources'])
        || !is_array($manifest['inserted']['versions'])
        || !is_array($manifest['inserted']['relations'])) {
        failCli('manifest is missing required batch or inserted arrays');
    }
    if (isset($manifest['updated']['items']) && $manifest['updated']['items'] !== []) {
        failCli('batch contains updates; automatic rollback is refused, restore from backup manually');
    }
    $manifest['_manifest_path'] = $path;
    return $manifest;
}

function rollbackCheck(PDO $db, array $package, array $manifest, array $state): void
{
    $q = $db->prepare('SELECT package_sha256, status, manifest_path, manifest_sha256 FROM knowledge_import_batches WHERE batch_id = ?');
    $q->execute([(int)$manifest['batch_id']]);
    $batch = $q->fetch(PDO::FETCH_ASSOC);
    if ($batch === false || !hash_equals((string)$batch['package_sha256'], (string)$manifest['package_sha256'])) {
        failCli('rollback blocked: manifest batch does not match database package');
    }
    if ((string)$batch['status'] !== 'isolated') {
        failCli('rollback blocked: database batch is not isolated');
    }
    $manifestReal = realpath((string)$manifest['_manifest_path']);
    $databaseManifestReal = realpath((string)$batch['manifest_path']);
    if ($manifestReal === false || $databaseManifestReal === false || $manifestReal !== $databaseManifestReal) {
        failCli('rollback blocked: manifest path does not match database batch');
    }
    $manifestFileSha256 = hash_file('sha256', $manifestReal);
    if ($manifestFileSha256 === false
        || !preg_match('/^[a-f0-9]{64}$/', (string)$batch['manifest_sha256'])
        || !hash_equals((string)$batch['manifest_sha256'], $manifestFileSha256)) {
        failCli('rollback blocked: manifest SHA-256 does not match database batch');
    }
    if (count($manifest['inserted']['items']) !== count($manifest['inserted']['sources'])
        || count($manifest['inserted']['items']) !== count($manifest['inserted']['versions'])) {
        failCli('rollback blocked: manifest item/source/version counts do not match');
    }
    $itemIds = [];
    $itemCodesById = [];
    foreach ($manifest['inserted']['items'] as $entry) {
        $itemId = (int)($entry['id'] ?? 0);
        if ($itemId < 1 || isset($itemCodesById[$itemId]) || !isset($entry['item_code'])) {
            failCli('rollback blocked: invalid or duplicate item in manifest');
        }
        $itemIds[] = $itemId;
        $itemCodesById[$itemId] = (string)$entry['item_code'];
        $q = $db->prepare('SELECT id, item_code, source_batch_id, publication_status FROM knowledge_items WHERE id = ?');
        $q->execute([(int)$entry['id']]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false
            || $row['item_code'] !== $entry['item_code']
            || (int)$row['source_batch_id'] !== (int)$manifest['batch_id']
            || $row['publication_status'] !== 'isolated') {
            failCli('item no longer matches manifest: id=' . $entry['id'] . ' expected ' . $entry['item_code']);
        }
    }
    if ($itemIds !== []) {
        $marks = implode(',', array_fill(0, count($itemIds), '?'));
        $checks = [
            'user_knowledge_progress' => 'knowledge_id',
            'knowledge_favorites' => 'knowledge_id',
            'knowledge_recent_views' => 'knowledge_id',
            'drill_templates' => 'knowledge_card_id',
        ];
        foreach ($checks as $table => $column) {
            $q = $db->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` IN (' . $marks . ')');
            $q->execute($itemIds);
            if ((int)$q->fetchColumn() > 0) {
                failCli('rollback blocked: rows in ' . $table . ' reference imported items');
            }
        }
        $manifestRelationsById = [];
        foreach ($manifest['inserted']['relations'] as $entry) {
            $relationId = (int)($entry['relation_id'] ?? 0);
            if ($relationId < 1 || isset($manifestRelationsById[$relationId])) {
                failCli('rollback blocked: invalid or duplicate relation in manifest');
            }
            $manifestRelationsById[$relationId] = $entry;
        }
        $manifestRelationIds = array_keys($manifestRelationsById);
        $relationParams = $itemIds;
        $relationSql = 'SELECT relation_id, source_item_id, target_item_id, relation_type, reviewed_by FROM knowledge_item_relations WHERE source_item_id IN (' . $marks . ') OR target_item_id IN (' . $marks . ')';
        $relationParams = array_merge($relationParams, $itemIds);
        $q = $db->prepare($relationSql);
        $q->execute($relationParams);
        $databaseRelations = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($databaseRelations as $relation) {
            $relationId = (int)$relation['relation_id'];
            $manifestRelation = $manifestRelationsById[$relationId] ?? null;
            if (in_array((int)$relation['target_item_id'], $itemIds, true)
                || $manifestRelation === null
                || (int)$manifestRelation['source_item_id'] !== (int)$relation['source_item_id']
                || (int)$manifestRelation['target_item_id'] !== (int)$relation['target_item_id']
                || $relation['relation_type'] !== 'candidate'
                || $relation['reviewed_by'] !== null) {
                failCli('rollback blocked: imported items have unknown, incoming, reviewed, or non-candidate relations');
            }
        }
        if (count($databaseRelations) !== count($manifestRelationsById)) {
            failCli('rollback blocked: manifest relation set does not match database');
        }
        $seenSourceIds = [];
        foreach ($manifest['inserted']['sources'] as $entry) {
            $sourceId = (int)($entry['source_id'] ?? 0);
            $itemId = (int)($entry['item_id'] ?? 0);
            if ($sourceId < 1 || isset($seenSourceIds[$sourceId]) || !isset($itemCodesById[$itemId])
                || $itemCodesById[$itemId] !== ($entry['source_card_id'] ?? null)) {
                failCli('rollback blocked: invalid source ownership in manifest');
            }
            $seenSourceIds[$sourceId] = true;
            $q = $db->prepare('SELECT knowledge_item_id FROM knowledge_item_sources WHERE source_id = ? AND source_card_id = ? AND batch_id = ?');
            $q->execute([$sourceId, $entry['source_card_id'], (int)$manifest['batch_id']]);
            if ((int)$q->fetchColumn() !== $itemId) {
                failCli('source no longer matches manifest: ' . $entry['source_card_id']);
            }
        }
        $seenVersionIds = [];
        foreach ($manifest['inserted']['versions'] as $entry) {
            $versionId = (int)($entry['version_id'] ?? 0);
            $itemId = (int)($entry['item_id'] ?? 0);
            if ($versionId < 1 || isset($seenVersionIds[$versionId]) || !isset($itemCodesById[$itemId])) {
                failCli('rollback blocked: invalid version ownership in manifest');
            }
            $seenVersionIds[$versionId] = true;
            $q = $db->prepare('SELECT COUNT(*) FROM knowledge_item_versions WHERE version_id = ? AND knowledge_item_id = ?');
            $q->execute([$versionId, $itemId]);
            if ((int)$q->fetchColumn() !== 1) {
                failCli('version no longer matches manifest: version_id=' . $entry['version_id']);
            }
        }
    }
}

function assertRollback(PDO $db, array $package, array $manifest, array $oldDigest): void
{
    $itemIds = array_map('intval', array_column($manifest['inserted']['items'], 'id'));
    $sourceIds = array_map('intval', array_column($manifest['inserted']['sources'], 'source_id'));
    $versionIds = array_map('intval', array_column($manifest['inserted']['versions'], 'version_id'));
    $relationIds = array_map('intval', array_column($manifest['inserted']['relations'], 'relation_id'));
    $codes = array_column($package['records'], 'item_code');
    if (!hash_equals($oldDigest['items'], rowsDigestExceptCodes($db, $codes))) {
        throw new RuntimeException('non-target knowledge_items changed during rollback');
    }
    if ($itemIds !== []) {
        $marks = implode(',', array_fill(0, count($itemIds), '?'));
        $q = $db->prepare('SELECT COUNT(*) FROM knowledge_items WHERE id IN (' . $marks . ')');
        $q->execute($itemIds);
        if ((int)$q->fetchColumn() !== 0) {
            throw new RuntimeException('imported items still present after rollback');
        }
    }
    $nonTargetChecks = [
        'sources' => ['knowledge_item_sources', 'source_id', $sourceIds],
        'versions' => ['knowledge_item_versions', 'version_id', $versionIds],
        'relations' => ['knowledge_item_relations', 'relation_id', $relationIds],
        'favorites' => ['knowledge_favorites', 'favorite_id', []],
        'recent_views' => ['knowledge_recent_views', 'recent_view_id', []],
    ];
    foreach ($nonTargetChecks as $digestKey => [$table, $idColumn, $excludedIds]) {
        if (!hash_equals($oldDigest[$digestKey], rowsDigestExceptIds($db, $table, $idColumn, $excludedIds))) {
            throw new RuntimeException('non-target rows changed during rollback: ' . $table);
        }
    }
    $batchId = (int)$manifest['batch_id'];
    $q = $db->prepare('SELECT status FROM knowledge_import_batches WHERE batch_id = ?');
    $q->execute([$batchId]);
    if ((string)$q->fetchColumn() !== 'rolled_back') {
        throw new RuntimeException('batch was not marked rolled_back');
    }
}

function runRollback(PDO $db, array $package, array $args): void
{
    $manifest = readManifest($args['manifest'], $package);
    if (!$args['apply']) {
        preflight($db, $package);
        $state = loadState($db, $package);
        rollbackCheck($db, $package, $manifest, $state);
        echo json_encode([
            'command' => 'rollback dry-run',
            'batch_id' => $manifest['batch_id'],
            'package_sha256' => $manifest['package_sha256'],
            'inserted_items' => count($manifest['inserted']['items']),
            'inserted_sources' => count($manifest['inserted']['sources']),
            'inserted_versions' => count($manifest['inserted']['versions']),
            'inserted_relations' => count($manifest['inserted']['relations']),
            'backup' => $manifest['backup']['path'],
            'status' => 'ready',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        return;
    }
    $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([KNOWLEDGE_LOCK_NAME]);
    if ((int)$lock->fetchColumn() !== 1) {
        failCli('could not acquire named lock ' . KNOWLEDGE_LOCK_NAME);
    }
    try {
        preflight($db, $package);
        $state = loadState($db, $package);
        $rollbackSourceIds = array_map('intval', array_column($manifest['inserted']['sources'], 'source_id'));
        $rollbackVersionIds = array_map('intval', array_column($manifest['inserted']['versions'], 'version_id'));
        $rollbackRelationIds = array_map('intval', array_column($manifest['inserted']['relations'], 'relation_id'));
        $oldDigest = oldDigests($db, $package, $rollbackVersionIds);
        $oldDigest['sources'] = rowsDigestExceptIds($db, 'knowledge_item_sources', 'source_id', $rollbackSourceIds);
        $oldDigest['relations'] = rowsDigestExceptIds($db, 'knowledge_item_relations', 'relation_id', $rollbackRelationIds);
        rollbackCheck($db, $package, $manifest, $state);
        $before = counts($db);
        $backup = backup($db, $package, $state, $args['backup_dir'], 'rollback-before');
        $db->beginTransaction();
        try {
            rollbackCheck($db, $package, $manifest, $state);
            foreach ($manifest['inserted']['relations'] as $entry) {
                $q = $db->prepare('DELETE FROM knowledge_item_relations WHERE relation_id = ? AND source_item_id = ?');
                $q->execute([(int)$entry['relation_id'], (int)$entry['source_item_id']]);
            }
            foreach ($manifest['inserted']['sources'] as $entry) {
                $q = $db->prepare('DELETE FROM knowledge_item_sources WHERE source_id = ? AND knowledge_item_id = ? AND source_card_id = ? AND batch_id = ?');
                $q->execute([(int)$entry['source_id'], (int)$entry['item_id'], $entry['source_card_id'], (int)$manifest['batch_id']]);
            }
            foreach ($manifest['inserted']['items'] as $entry) {
                $q = $db->prepare('UPDATE knowledge_items SET current_version_id = NULL WHERE id = ? AND item_code = ?');
                $q->execute([(int)$entry['id'], $entry['item_code']]);
            }
            foreach ($manifest['inserted']['versions'] as $entry) {
                $q = $db->prepare('DELETE FROM knowledge_item_versions WHERE version_id = ? AND knowledge_item_id = ?');
                $q->execute([(int)$entry['version_id'], (int)$entry['item_id']]);
            }
            foreach ($manifest['inserted']['items'] as $entry) {
                $q = $db->prepare('DELETE FROM knowledge_items WHERE id = ? AND item_code = ?');
                $q->execute([(int)$entry['id'], $entry['item_code']]);
            }
            $q = $db->prepare("UPDATE knowledge_import_batches SET status = 'rolled_back', completed_at = NOW() WHERE batch_id = ?");
            $q->execute([(int)$manifest['batch_id']]);
            $after = counts($db);
            insertAudit(
                $db,
                (int)$manifest['batch_id'],
                'rollback',
                'knowledge_import_batches',
                (string)$manifest['batch_id'],
                $before,
                $after,
                ['package_sha256' => $package['package_sha256'], 'removed_items' => count($manifest['inserted']['items'])]
            );
            assertRollback($db, $package, $manifest, $oldDigest);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        echo json_encode([
            'status' => 'rolled_back',
            'batch_id' => $manifest['batch_id'],
            'removed_items' => count($manifest['inserted']['items']),
            'removed_sources' => count($manifest['inserted']['sources']),
            'removed_versions' => count($manifest['inserted']['versions']),
            'removed_relations' => count($manifest['inserted']['relations']),
            'backup' => $backup['path'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([KNOWLEDGE_LOCK_NAME]);
    }
}

function main(array $argv): void
{
    if (!function_exists('mb_substr')) {
        failCli('mbstring extension is required');
    }
    $args = parseArgs($argv);
    $package = readPackage($args['json'], $args['sha256']);
    $db = getDB();
    if ($args['command'] === 'import') {
        if ($args['apply']) {
            applyImport($db, $package, $args);
        } else {
            preflight($db, $package);
            $state = loadState($db, $package);
            $diff = diffPackage($package, $state);
            summary($package, $state, $diff, $args);
        }
        return;
    }
    runRollback($db, $package, $args);
}

try {
    main($argv);
} catch (CliException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error->exitCode);
} catch (Throwable $error) {
    fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");
    exit(1);
}
