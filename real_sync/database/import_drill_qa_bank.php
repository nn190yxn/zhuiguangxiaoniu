<?php
/**
 * 导入《追光小牛儿童运动Q&A》题库到销售 Q&A 练习模块
 * 数据源：database/import_data/drill-qa-bank.v1.json
 * 幂等：按 section_code + question_no 做插入/更新，可重复执行。
 * 运行方式：php database/import_drill_qa_bank.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../api/config.php';

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $error) {
    fwrite(STDERR, "数据库连接失败：" . $error->getMessage() . "\n");
    exit(1);
}

$migrationFile = __DIR__ . '/migrations/202608290001_drill_qa_bank.sql';
$dataFile = __DIR__ . '/import_data/drill-qa-bank.v1.json';

// 1. 确保表结构存在（迁移未执行时兜底建表）
$tableCheck = $db->query("SHOW TABLES LIKE 'drill_qa_sections'")->fetch(PDO::FETCH_COLUMN);
if (!$tableCheck) {
    if (!is_file($migrationFile)) {
        fwrite(STDERR, "迁移 SQL 缺失：" . $migrationFile . "\n");
        exit(1);
    }
    $sql = (string) file_get_contents($migrationFile);
    foreach (splitSqlStatements($sql) as $statement) {
        $db->exec($statement);
    }
    echo "已按迁移 SQL 创建 drill_qa 四张表。\n";
}

// 2. 读取题库数据
if (!is_file($dataFile)) {
    fwrite(STDERR, "题库数据文件缺失：" . $dataFile . "\n");
    exit(1);
}
$bank = json_decode((string) file_get_contents($dataFile), true);
if (!is_array($bank) || !is_array($bank['sections'] ?? null)) {
    fwrite(STDERR, "题库数据文件格式无效。\n");
    exit(1);
}

echo "=== 追光小牛 Q&A 题库导入 ===\n\n";

$upsertSection = $db->prepare(
    'INSERT INTO drill_qa_sections (section_code, section_name, sort_order, status, created_at) '
    . 'VALUES (?, ?, ?, ?, NOW()) '
    . 'ON DUPLICATE KEY UPDATE section_name = VALUES(section_name), sort_order = VALUES(sort_order), status = VALUES(status)'
);
$upsertQuestion = $db->prepare(
    'INSERT INTO drill_qa_questions (section_id, question_no, question, reference_answer, sort_order, status, created_at) '
    . 'VALUES (?, ?, ?, ?, ?, ?, NOW()) '
    . 'ON DUPLICATE KEY UPDATE question = VALUES(question), reference_answer = VALUES(reference_answer), sort_order = VALUES(sort_order), status = VALUES(status)'
);

$sectionCount = 0;
$questionCount = 0;
$db->beginTransaction();
try {
    foreach ($bank['sections'] as $section) {
        $code = trim((string) ($section['code'] ?? ''));
        $name = trim((string) ($section['name'] ?? ''));
        if ($code === '' || $name === '') {
            continue;
        }
        $upsertSection->execute([
            $code,
            $name,
            (int) ($section['sort_order'] ?? 0),
            'active',
        ]);
        $sectionId = (int) $db->query(
            "SELECT id FROM drill_qa_sections WHERE section_code = " . $db->quote($code) . " LIMIT 1"
        )->fetchColumn();
        if ($sectionId <= 0) {
            continue;
        }
        $sectionCount++;

        $index = 0;
        foreach ((array) ($section['questions'] ?? []) as $question) {
            $no = (int) ($question['no'] ?? 0);
            $text = trim((string) ($question['question'] ?? ''));
            $answer = trim((string) ($question['reference_answer'] ?? ''));
            if ($no <= 0 || $text === '' || $answer === '') {
                fwrite(STDERR, "跳过无效题目：" . $name . " #" . $no . "\n");
                continue;
            }
            $upsertQuestion->execute([
                $sectionId,
                $no,
                $text,
                $answer,
                ++$index,
                'active',
            ]);
            $questionCount++;
        }
    }
    $db->commit();
} catch (Throwable $error) {
    $db->rollBack();
    fwrite(STDERR, "导入失败：" . $error->getMessage() . "\n");
    exit(1);
}

echo "篇目：{$sectionCount} 个，题目：{$questionCount} 题。\n\n";

echo "=== 当前题库统计 ===\n";
$rows = $db->query(
    'SELECT s.section_code, s.section_name, COUNT(q.id) AS cnt '
    . 'FROM drill_qa_sections s LEFT JOIN drill_qa_questions q ON q.section_id = s.id '
    . 'GROUP BY s.id ORDER BY s.sort_order ASC, s.id ASC'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    echo sprintf("%-10s %-12s %d 题\n", $row['section_code'], $row['section_name'], (int) $row['cnt']);
}

function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $quote = null;
    $length = strlen($sql);
    for ($index = 0; $index < $length; $index++) {
        $char = $sql[$index];
        if ($quote !== null) {
            $buffer .= $char;
            if ($char === $quote) {
                if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                    $buffer .= $sql[$index + 1];
                    $index++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }
        if ($char === "'" || $char === '`') {
            $quote = $char;
            $buffer .= $char;
            continue;
        }
        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }
    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }
    return $statements;
}
