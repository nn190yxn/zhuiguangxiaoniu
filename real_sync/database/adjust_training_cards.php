<?php
/**
 * 培训卡片模块调整脚本
 * 目标：让七步曲和十问正确分布到初阶/进阶，销售/教练
 *
 * 正确分布逻辑：
 * 模块1(品牌): 七步曲概述(全局概览)
 * 模块2(接待-销售初阶): 十问(全)+七步曲破冰+七步曲需求+消费力判断
 * 模块4(体验课-销售进阶): 七步曲异议+七步曲逼单+预言成交
 * 模块5(沟通-教练): 保留家长沟通内容
 * 模块6(续费-销售进阶): 七步曲续费+七步曲转介绍+七步曲通关
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$dbHost = 'localhost';
$dbName = '_122_51_223_46';
$dbUser = '_122_51_223_46';
$dbPass = 'Yaoxiuning190';

try {
    $db = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

echo "=== 培训卡片模块调整脚本 ===\n\n";

echo "【调整前的分布】\n";
$stmt = $db->query("
    SELECT tc.id, tc.module_id, tc.card_code, tc.title,
           tm.module_name, tm.role_code, tm.level
    FROM training_cards tc
    JOIN training_modules tm ON tc.module_id = tm.id
    WHERE tc.card_code LIKE '%seven%' OR tc.card_code LIKE '%ten%'
       OR tc.card_code LIKE '%销售%' OR tc.title LIKE '%七步%'
       OR tc.title LIKE '%十问%' OR tc.title LIKE '%预言%'
       OR tc.title LIKE '%消费力%'
    ORDER BY tc.module_id, tc.id
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "- [模块{$row['module_id']}]{$row['module_name']}: {$row['title']}\n";
}

echo "\n【开始调整】\n";

// 定义调整映射 card_code => 新module_id
$adjustments = [
    // 模块1: 七步曲概述(全局概览，所有销售相关岗位都要学)
    'seven-steps-overview' => 1,

    // 模块2(销售初阶-接待): 十问全流程 + 破冰 + 需求挖掘
    'ten-questions-overview' => 2,       // 十问概述
    'ten-questions-warmup-cards' => 2,    // 十问破冰话术
    'ten-questions-needs-cards' => 2,     // 十问需求挖掘
    'ten-questions-exam' => 2,            // 十问通关卡
    'ten-questions-consumer-analysis' => 2, // 消费力判断
    'ten-questions-close-script' => 2,    // 预言成交话术
    'seven-steps-warmup' => 2,            // 七步曲-暖场破冰
    'seven-steps-needs' => 2,            // 七步曲-需求挖掘

    // 模块4(销售进阶-体验课): 异议处理 + 逼单成交
    'seven-steps-objection' => 4,        // 七步曲-异议处理
    'seven-steps-close' => 4,            // 七步曲-逼单成交

    // 模块5(教练进阶-家长沟通): 保留原样（家长沟通相关）
    // 不调整任何卡片

    // 模块6(销售进阶-续费转介绍): 续费 + 转介绍 + 整体考核
    'seven-steps-renewal' => 6,         // 七步曲-转教续费
    'seven-steps-referral' => 6,         // 七步曲-主动转介绍
    'seven-steps-exam' => 6,             // 七步曲-整体流程考核
];

foreach ($adjustments as $cardCode => $newModuleId) {
    // 获取卡片当前信息
    $stmt = $db->prepare("SELECT id, module_id, title FROM training_cards WHERE card_code = ?");
    $stmt->execute([$cardCode]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($card) {
        $oldModuleId = $card['module_id'];
        if ($oldModuleId != $newModuleId) {
            // 获取新旧模块名称
            $stmtOld = $db->prepare("SELECT module_name FROM training_modules WHERE id = ?");
            $stmtOld->execute([$oldModuleId]);
            $oldModuleName = $stmtOld->fetchColumn();

            $stmtNew = $db->prepare("SELECT module_name FROM training_modules WHERE id = ?");
            $stmtNew->execute([$newModuleId]);
            $newModuleName = $stmtNew->fetchColumn();

            // 执行更新
            $updateStmt = $db->prepare("UPDATE training_cards SET module_id = ? WHERE card_code = ?");
            $updateStmt->execute([$newModuleId, $cardCode]);

            echo "调整: {$card['title']}\n";
            echo "  {$oldModuleName}[{$oldModuleId}] → {$newModuleName}[{$newModuleId}]\n";
        } else {
            echo "保持: {$card['title']} (模块{$newModuleId}不变)\n";
        }
    } else {
        echo "未找到: {$cardCode}\n";
    }
}

echo "\n【调整后的分布】\n";
$stmt = $db->query("
    SELECT tc.id, tc.module_id, tc.card_code, tc.title, tc.card_type,
           tm.module_name, tm.role_code, tm.level
    FROM training_cards tc
    JOIN training_modules tm ON tc.module_id = tm.id
    WHERE tc.card_code LIKE '%seven%' OR tc.card_code LIKE '%ten%'
       OR tc.card_code LIKE '%销售%' OR tc.title LIKE '%七步%'
       OR tc.title LIKE '%十问%' OR tc.title LIKE '%预言%'
       OR tc.title LIKE '%消费力%'
    ORDER BY tc.module_id, tc.card_type, tc.id
");
$currentModule = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($currentModule != $row['module_id']) {
        $currentModule = $row['module_id'];
        $levelNames = ['beginner'=>'初阶', 'intermediate'=>'进阶', 'advanced'=>'高阶'];
        $roleNames = ['newbie'=>'新员工', 'consultant'=>'销售', 'coach'=>'教练', 'manager'=>'店长'];
        echo "\n>>> 模块{$row['module_id']}: {$row['module_name']} ";
        echo "({$roleNames[$row['role_code']]}/{$levelNames[$row['level']]})\n";
    }
    echo "  [{$row['card_type']}] {$row['title']}\n";
}

// 统计各模块最终卡片数
echo "\n【各模块最终卡片数】\n";
$stmt = $db->query("
    SELECT tm.id, tm.module_name, tm.role_code, tm.level, COUNT(tc.id) as cnt
    FROM training_modules tm
    LEFT JOIN training_cards tc ON tm.id = tc.module_id AND tc.status = 1
    WHERE tm.status = 1
    GROUP BY tm.id
    ORDER BY tm.id
");
$levelNames = ['beginner'=>'初阶', 'intermediate'=>'进阶', 'advanced'=>'高阶'];
$roleNames = ['newbie'=>'新员工', 'consultant'=>'销售', 'coach'=>'教练', 'manager'=>'店长'];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "模块{$row['id']}: {$row['module_name']} ";
    echo "({$roleNames[$row['role_code']]}/{$levelNames[$row['level']]}) - {$row['cnt']}张\n";
}

echo "\n=== 调整完成 ===\n";
