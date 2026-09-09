<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../api/config.php';

const AGE_BUCKETS = [
    'age_0_3' => [0, 3],
    'age_3_4' => [3, 4],
    'age_4_5' => [4, 5],
    'age_5_6' => [5, 6],
    'age_6_9' => [6, 9],
    'age_9_12' => [9, 12],
    'age_12_plus' => [12, null],
];

function bucketsForRange(int $min, ?int $max): array
{
    $codes = [];
    foreach (AGE_BUCKETS as $code => [$bucketMin, $bucketMax]) {
        $overlaps = $max === null ? $bucketMax === null || $bucketMax > $min : $bucketMin < $max && ($bucketMax === null || $bucketMax > $min);
        if ($overlaps) {
            $codes[] = $code;
        }
    }
    return $codes;
}

function predictAge(array $row): array
{
    $text = trim(implode(' ', array_filter([
        $row['age_group'] ?? '', $row['title'] ?? '', $row['summary'] ?? '', $row['content'] ?? '',
    ], static fn($value): bool => trim((string)$value) !== '')));
    $reasons = [];
    $codes = [];
    $confidence = 0.55;

    if (preg_match_all('/(\d{1,2}(?:\.\d+)?)\s*(?:岁)?\s*(?:至|到|-|~|～)\s*(\d{1,2}(?:\.\d+)?)\s*岁?/u', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $codes = array_merge($codes, bucketsForRange((int)$match[1], (int)$match[2]));
        }
        $reasons[] = '正文存在明确年龄区间';
        $confidence = 0.95;
        $codes = array_values(array_unique($codes));
        if ($codes !== []) {
            return [$codes, $confidence, implode('；', $reasons)];
        }
    } elseif (preg_match('/(\d{1,2})\s*岁以上|适合\s*(\d{1,2})\s*岁/u', $text, $match)) {
        $min = (int)($match[1] ?: $match[2]);
        $codes = bucketsForRange($min, null);
        $reasons[] = '正文存在年龄下限线索';
        $confidence = 0.82;
    } elseif (preg_match('/(\d{1,2})\s*岁(?:以前|之前|前)/u', $text, $match)) {
        $codes = bucketsForRange(0, (int)$match[1]);
        $reasons[] = '正文存在年龄上限线索';
        $confidence = 0.82;
        $codes = array_values(array_unique($codes));
        if ($codes !== []) {
            return [$codes, $confidence, implode('；', $reasons)];
        }
    }

    $stageRanges = [
        '托班' => [2, 3], '小班' => [3, 4], '中班' => [4, 5], '大班' => [5, 6],
        '学龄前' => [3, 6], '学前儿童' => [3, 6], '小学生' => [6, 12], '儿童青少年' => [6, null],
        '小学低年级' => [6, 9], '小学高年级' => [9, 12], '中学生' => [12, null], '高中' => [15, null],
    ];
    foreach ($stageRanges as $stage => [$minAge, $maxAge]) {
        if (mb_stripos($text, $stage) !== false) {
            $codes = bucketsForRange($minAge, $maxAge);
            if ($codes !== []) {
                return [$codes, 0.9, '正文存在明确教学阶段：' . $stage];
            }
        }
    }

    foreach (['婴儿', '宝宝', '爬行', '趴卧', '翻身', '学步'] as $signal) {
        if (mb_stripos($text, $signal) !== false) {
            return [['age_0_3'], 0.9, '正文存在明确婴幼儿运动线索：' . $signal];
        }
    }

    $signals = [
        'age_0_3' => ['婴儿', '宝宝', '爬行', '趴卧', '翻身', '学步', '托班'],
        'age_3_4' => ['幼儿园', '学前', '启蒙', '模仿', '颜色认知', '简单指令', '音乐律动', '找朋友', '手指游戏'],
        'age_4_5' => ['幼儿园', '学前', '启蒙', '跑跳投', '基本动作', '平衡', '协调', '追逐', '躲闪', '跳圈', '木头人', '红绿灯', '丢手绢', '老鹰抓小鸡', '老狼老狼', '踩尾巴', '抓小鸟', '鱼网抓鱼', '大鱼抓小鱼', '小马过河'],
        'age_5_6' => ['幼儿园', '学前', '跑跳投', '基本动作', '接球', '踢球', '拍球', '动物模仿', '障碍游戏', '寻宝', '沙包投球', '跳大绳', '反应球'],
        'age_6_9' => ['小学低年级', '小学一', '小学二', '小学三', '运球', '传球', '接力', '篮球', '足球', '传球接力', '运球接力', '躲避球', '移动球门', '体前变向运球', '平衡垫', '瑞士球', '弹力带', '平板支撑', '俯卧撑', '闭眼单腿'],
        'age_9_12' => ['小学高年级', '小学四', '小学五', '小学六', '对抗', '战术配合', '攻防', '得分', '三对三', '五人制', '攻防转换', '平衡垫', '瑞士球', '弹力带', '平板支撑', '俯卧撑', '闭眼单腿'],
        'age_12_plus' => ['青少年', '中学生', '高中', '力量训练', '力量素质', '耐力素质', '爆发力', '专项训练', '负重', '杠铃', '壶铃', '抗阻', '俄罗斯旋转'],
    ];
    $signalHits = [];
    foreach ($signals as $code => $words) {
        foreach ($words as $word) {
            if (mb_stripos($text, $word) !== false) {
                $signalHits[$code] = ($signalHits[$code] ?? 0) + 1;
            }
        }
    }
    if ($signalHits !== []) {
        arsort($signalHits);
        $top = array_slice($signalHits, 0, 3, true);
        $codes = array_merge($codes, array_keys($top));
        $reasons[] = '结合动作难度、教学目标或运动场景关键词';
        if ($confidence < 0.8) {
            $confidence = count($top) === 1 && reset($top) >= 2 ? 0.76 : 0.64;
        }
    }

    $codes = array_values(array_unique($codes));
    if ($codes === []) {
        $codes = ['age_3_4', 'age_4_5', 'age_5_6', 'age_6_9'];
        $reasons[] = '缺少明确年龄证据，按基础运动启蒙活动给出宽范围预判';
        $confidence = 0.45;
    }
    if (preg_match('/原文未说明|未说明|全年龄|不限年龄/u', (string)($row['age_group'] ?? ''))) {
        $reasons[] = '原始年龄字段缺少可验证年龄信息';
        $confidence = min($confidence, 0.55);
    }

    return [$codes, round($confidence, 2), implode('；', $reasons)];
}

$db = getDB();
$rows = $db->query(
    "SELECT r.knowledge_item_id, r.version_id, r.source_text AS age_group,
            COALESCE(NULLIF(kv.title, ''), k.title) AS title,
            COALESCE(NULLIF(kv.summary, ''), k.summary) AS summary,
            COALESCE(NULLIF(kv.content, ''), k.content) AS content
     FROM knowledge_item_age_ranges r
     INNER JOIN knowledge_items k ON k.id = r.knowledge_item_id
     INNER JOIN knowledge_item_versions kv ON kv.version_id = r.version_id
     WHERE r.review_status = 'pending' AND r.age_code = 'age_all_review'
     ORDER BY r.knowledge_item_id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$plan = [];
foreach ($rows as $row) {
    [$codes, $confidence, $reason] = predictAge($row);
    foreach ($codes as $code) {
        $plan[] = [
            (int)$row['knowledge_item_id'], (int)$row['version_id'], $code,
            $row['age_group'] ?: null, $confidence, 'pending', 'expert_prediction_v3', $reason,
        ];
    }
}

$result = ['dry_run' => !in_array('--apply', $argv, true), 'cards' => count($rows), 'predicted_rows' => count($plan), 'rule_version' => 'expert_prediction_v3'];
if (!in_array('--apply', $argv, true)) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

$db->beginTransaction();
try {
    $db->exec("UPDATE knowledge_item_age_ranges SET review_status = 'rejected', decision_source = 'superseded', review_note = CONCAT(COALESCE(review_note, ''), '；已由 expert_prediction_v3 替代') WHERE decision_source IN ('expert_prediction', 'expert_prediction_v2') AND review_status = 'pending'");
    $stmt = $db->prepare(
        'INSERT INTO knowledge_item_age_ranges '
        . '(knowledge_item_id, version_id, age_code, source_text, confidence, review_status, decision_source, review_note) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE source_text = VALUES(source_text), confidence = VALUES(confidence), '
        . 'review_status = VALUES(review_status), decision_source = VALUES(decision_source), review_note = VALUES(review_note)'
    );
    foreach ($plan as $entry) {
        $stmt->execute($entry);
    }
    $db->commit();
    $result['applied'] = true;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    $db->rollBack();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
