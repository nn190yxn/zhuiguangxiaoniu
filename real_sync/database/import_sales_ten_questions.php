<?php
/**
 * 导入销售基础十问到话术知识库和培训卡片
 * 用于新签流程的破冰和需求挖掘
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

echo "=== 销售基础十问导入脚本 ===\n\n";

// 销售基础十问数据
$salesTenQuestions = [
    [
        'number' => 1,
        'question' => '家长之前有了解过我们追光小牛品牌吗？',
        'purpose' => '了解客户认知度',
        'response' => '家长"没有"时回答："我给您简单介绍一下"（有引导性的切入销售环节，而不是硬销售）',
        'keywords' => ['品牌了解', '开场白', '认知度', '破冰'],
        'scenario' => 'warmup'
    ],
    [
        'number' => 2,
        'question' => '您今天是怎么过来的呢？',
        'purpose' => '判断距离',
        'response' => '通过询问交通方式判断家长住址与门店的距离，为后续服务半径和服务频次做参考',
        'keywords' => ['距离', '交通', '住址', '破冰'],
        'scenario' => 'warmup'
    ],
    [
        'number' => 3,
        'question' => '看孩子的性格挺好的，之前有上过兴趣班吗？',
        'purpose' => '了解教育理念和消费力',
        'response' => '家长"有上过画画和钢琴"时：（了解家长的教育理念，同时画画和钢琴在市场上属于课单价偏高的课程，判断家长的消费力）',
        'keywords' => ['兴趣班', '教育理念', '消费力', '需求挖掘'],
        'scenario' => 'needs'
    ],
    [
        'number' => 4,
        'question' => '他上钢琴这个兴趣班学了多久了？',
        'purpose' => '判断续费意愿和报名周期',
        'response' => '看看家长近期是否续费，同时知道当时他报名的课包是半年还是一年，提前判断家长的消费习惯和续费意愿',
        'keywords' => ['续费', '课包周期', '消费习惯', '需求挖掘'],
        'scenario' => 'needs'
    ],
    [
        'number' => 5,
        'question' => '那当时学这个兴趣班是您给孩子报名的吗？',
        'purpose' => '判断决策人',
        'response' => '了解谁才是最终决策人，方便后续谈单时找对关键人',
        'keywords' => ['决策人', '决策链', '需求挖掘'],
        'scenario' => 'needs'
    ],
    [
        'number' => 6,
        'question' => '平时上兴趣班是妈妈您带她去还是爸爸呢？',
        'purpose' => '判断接送人',
        'response' => '了解谁是主要接送人，后续服务时需要维护好与接送人的关系',
        'keywords' => ['接送人', '服务对象', '需求挖掘'],
        'scenario' => 'needs'
    ],
    [
        'number' => 7,
        'question' => '您当时给孩子买咱们这个体验课包是为什么呢？',
        'purpose' => '判断体验动机',
        'response' => '了解家长为什么让孩子体验运动课，是主动想做还是被动安排',
        'keywords' => ['体验动机', '需求挖掘', '意向判断'],
        'scenario' => 'needs'
    ],
    [
        'number' => 8,
        'question' => '平时周末放假有带孩子在小区楼下做运动吗？',
        'purpose' => '判断运动理念',
        'response' => '了解家长对运动的重视程度和日常运动习惯',
        'keywords' => ['运动理念', '日常习惯', '需求挖掘'],
        'scenario' => 'needs'
    ],
    [
        'number' => 9,
        'question' => '之前有没有上过运动课？',
        'purpose' => '判断运动经历',
        'response' => '如果不是第一次上运动课，顾问要将自身机构和其他运动机构的"差异化"和"价值"体现出来',
        'keywords' => ['运动经历', '差异化', '竞争', '需求挖掘'],
        'scenario' => 'needs'
    ],
    [
        'number' => 10,
        'question' => '平时您是周中有空还是周末有空呢？我给您看看校区的课表，您看看哪个时间段有空？',
        'purpose' => '锁定时间，预言成交法则',
        'response' => '提前锁定家长时间，再进行报价，是"预言成交法则"的体现',
        'keywords' => ['时间', '排课', '预言成交', '逼单'],
        'scenario' => 'close'
    ]
];

// 1. 导入到话术知识库 - qa维度
echo "=== 导入话术知识库（qa维度）===\n";
$dim_qa = 1;
$sort_order = 150;

foreach ($salesTenQuestions as $q) {
    $scene_code = 'ten-questions-q' . $q['number'];

    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$scene_code]);
    $existing = $stmt->fetch();

    $standard_script = "【问题】{$q['question']}\n\n【目的】{$q['purpose']}\n\n【参考回答】{$q['response']}";
    $keywords = json_encode($q['keywords']);

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            '销售十问Q' . $q['number'] . '-' . $q['scenario'],
            $keywords,
            $standard_script,
            '第' . $q['number'] . '问：' . $q['purpose'],
            $scene_code
        ]);
        echo "Updated (qa): 销售十问Q{$q['number']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $dim_qa,
            $scene_code,
            '销售十问Q' . $q['number'] . '-' . $q['scenario'],
            $keywords,
            $standard_script,
            '第' . $q['number'] . '问：' . $q['purpose'],
            $sort_order++
        ]);
        echo "Inserted (qa): 销售十问Q{$q['number']}\n";
    }
}

// 2. 导入到话术知识库 - knowledge维度（每个问题的深度解析）
echo "\n=== 导入话术知识库（knowledge维度）===\n";
$dim_knowledge = 2;
$sort_order = 250;

$knowledgeItems = [
    [
        'scene_code' => 'ten-questions-warmup',
        'scene_name' => '销售十问-破冰环节要点',
        'keywords' => ['破冰', '开场', '品牌介绍', '距离判断'],
        'standard_script' => '【破冰环节三要素】' . "\n" .
                           '1. 主动热情：第一印象决定后续成交' . "\n" .
                           '2. 标准开场：简单自我介绍+品牌介绍' . "\n" .
                           '3. 了解信息：来源渠道、交通方式、距离远近' . "\n\n" .
                           '【Q1-Q2 破冰问题】' . "\n" .
                           '- Q1了解认知度：没听过的要简单介绍品牌' . "\n" .
                           '- Q2判断距离：了解客户来源方向和服务半径',
        'tips' => '破冰是建立信任的关键阶段'
    ],
    [
        'scene_code' => 'ten-questions-needs',
        'scene_name' => '销售十问-需求挖掘要点',
        'keywords' => ['需求挖掘', '消费力', '决策人', '教育理念'],
        'standard_script' => '【需求挖掘五步法】' . "\n" .
                           '1. 了解教育理念：上过哪些兴趣班（Q3）' . "\n" .
                           '2. 判断消费力：通过兴趣班类型判断（画画钢琴=高消费）' . "\n" .
                           '3. 判断续费意愿：之前课程学多久了（Q4）' . "\n" .
                           '4. 找准决策人：谁给孩子报名的（Q5）' . "\n" .
                           '5. 确定接送人：谁来带孩子上课（Q6）' . "\n\n" .
                           '【关键信号】' . "\n" .
                           '- 兴趣班多=教育重视' . "\n" .
                           '- 钢琴画画=消费力强' . "\n" .
                           '- 近期续费=习惯良好',
        'tips' => '需求挖掘决定成交质量'
    ],
    [
        'scene_code' => 'ten-questions-motivation',
        'scene_name' => '销售十问-体验动机判断',
        'keywords' => ['体验动机', '意向判断', '主动vs被动'],
        'standard_script' => '【体验动机分析】' . "\n" .
                           'Q7: 为什么买体验课？' . "\n" .
                           '- 主动想了解=高意向' . "\n" .
                           '- 朋友推荐=中等意向' . "\n" .
                           '- 被动安排=低意向' . "\n\n" .
                           'Q8: 平时有带孩子运动吗？' . "\n" .
                           '- 经常运动=运动理念好' . "\n" .
                           '- 偶尔运动=需要教育' . "\n" .
                           '- 不运动=需要培养' . "\n\n" .
                           'Q9: 上过运动课吗？' . "\n" .
                           '- 没上过=教育成本高' . "\n" .
                           '- 上过其他=要突出差异化',
        'tips' => '根据动机调整谈单策略'
    ],
    [
        'scene_code' => 'ten-questions-close',
        'scene_name' => '销售十问-预言成交法则',
        'keywords' => ['预言成交', '时间锁定', '逼单', '签单'],
        'standard_script' => '【预言成交法则】' . "\n" .
                           'Q10: 平时您是周中有空还是周末有空呢？' . "\n" .
                           '→ 我给您看看校区的课表' . "\n" .
                           '→ 您看看哪个时间段有空？' . "\n\n" .
                           '【为什么要先锁时间】' . "\n" .
                           '1. 家长时间已定=意向度高' . "\n" .
                           '2. 锁定时间后再报价=减少价格抗拒' . "\n" .
                           '3. 提前约课=降低流失率' . "\n\n" .
                           '【使用时机】' . "\n" .
                           '在报价之前使用，先让家长感受到服务，再谈价格',
        'tips' => '先服务后成交，降低价格抗拒'
    ]
];

foreach ($knowledgeItems as $item) {
    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$item['scene_code']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            $item['scene_name'],
            json_encode($item['keywords']),
            $item['standard_script'],
            $item['tips'],
            $item['scene_code']
        ]);
        echo "Updated (knowledge): {$item['scene_name']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $dim_knowledge,
            $item['scene_code'],
            $item['scene_name'],
            json_encode($item['keywords']),
            $item['standard_script'],
            $item['tips'],
            $sort_order++
        ]);
        echo "Inserted (knowledge): {$item['scene_name']}\n";
    }
}

// 3. 导入到话术知识库 - deal维度（谈单技巧）
echo "\n=== 导入话术知识库（deal维度）===\n";
$dim_deal = 4;
$sort_order = 150;

$dealItems = [
    [
        'scene_code' => 'ten-questions-deal-warmup',
        'scene_name' => '销售十问谈单技巧-破冰阶段',
        'keywords' => ['破冰谈单', '开场', '建立信任', '第一印象'],
        'standard_script' => '【破冰阶段谈单要点】' . "\n" .
                           '1. 第一印象：着装专业、热情真诚' . "\n" .
                           '2. 让孩子喜欢你：先跟孩子玩起来' . "\n" .
                           '3. 用问题开场：开放式问题了解信息' . "\n" .
                           '4. 不要急于销售：先建立关系' . "\n" .
                           '5. 观察家长反应：判断意向程度',
        'tips' => '破冰决定家长愿不愿意继续听你讲'
    ],
    [
        'scene_code' => 'ten-questions-deal-needs',
        'scene_name' => '销售十问谈单技巧-需求挖掘阶段',
        'keywords' => ['需求挖掘谈单', '消费力判断', '决策人分析', '意向判断'],
        'standard_script' => '【需求挖掘谈单核心要点】' . "\n" .
                           '1. 通过兴趣班判断消费力' . "\n" .
                           '   - 钢琴/画画/马术 = 高消费力' . "\n" .
                           '   - 街舞/跆拳道 = 中等消费力' . "\n" .
                           '   - 没上过 = 需教育培养' . "\n\n" .
                           '2. 通过续费判断习惯' . "\n" .
                           '   - 续费过 = 习惯良好' . "\n" .
                           '   - 没续费 = 需强调效果' . "\n\n" .
                           '3. 找准决策人' . "\n" .
                           '   - 妈妈主导 = 重点说服妈妈' . "\n" .
                           '   - 爸爸主导 = 爸爸逻辑强，重数据' . "\n" .
                           '   - 一起决定 = 需要同时维护',
        'tips' => '需求挖掘越深，成交越容易'
    ],
    [
        'scene_code' => 'ten-questions-deal-close',
        'scene_name' => '销售十问谈单技巧-预言成交应用',
        'keywords' => ['预言成交', '时间锁定', '逼单技巧', '签单时机'],
        'standard_script' => '【预言成交法则详解】' . "\n" .
                           '核心话术：' . "\n" .
                           '"平时您是周中有空还是周末有空呢？"' . "\n" .
                           '"我给您看看校区的课表"' . "\n" .
                           '"您看看哪个时间段有空？" ' . "\n\n" .
                           '【应用时机】' . "\n" .
                           '1. 在报价之前用' . "\n" .
                           '2. 家长表示有兴趣时用' . "\n" .
                           '3. 处理完价格异议后用' . "\n\n" .
                           '【效果】' . "\n" .
                           '1. 锁定时间=意向确认' . "\n" .
                           '2. 先约课后报价=降低抗拒' . "\n" .
                           '3. 约定时间=承诺成交',
        'tips' => '用好预言成交，成单率提升50%'
    ]
];

foreach ($dealItems as $item) {
    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$item['scene_code']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            $item['scene_name'],
            json_encode($item['keywords']),
            $item['standard_script'],
            $item['tips'],
            $item['scene_code']
        ]);
        echo "Updated (deal): {$item['scene_name']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $dim_deal,
            $item['scene_code'],
            $item['scene_name'],
            json_encode($item['keywords']),
            $item['standard_script'],
            $item['tips'],
            $sort_order++
        ]);
        echo "Inserted (deal): {$item['scene_name']}\n";
    }
}

// 4. 导入到培训卡片
echo "\n=== 导入培训卡片 ===\n";

$trainingCards = [
    // 融入首次到店接待模块(mod-reception=2) - 破冰相关
    [
        'module_id' => 2,
        'card_type' => 'K',
        'card_code' => 'ten-questions-overview',
        'title' => '销售基础十问概述',
        'content' => '【销售基础十问】用于新签流程的破冰和需求挖掘：' . "\n" .
                     'Q1-Q2 破冰环节：了解认知度、判断距离' . "\n" .
                     'Q3-Q6 需求挖掘：了解教育理念、消费力、决策人' . "\n" .
                     'Q7-Q9 动机分析：体验动机、运动理念、运动经历' . "\n" .
                     'Q10 成交锁定：预言成交法则，锁定时间',
        'tips' => '十问是新签开口的关键'
    ],
    [
        'module_id' => 2,
        'card_type' => 'S',
        'card_code' => 'ten-questions-warmup-cards',
        'title' => '销售十问话术卡-破冰环节',
        'content' => '【Q1-Q2 破冰话术】' . "\n\n" .
                     'Q1: "家长之前有了解过我们追光小牛品牌吗？"' . "\n" .
                     '→ 没有："我给您简单介绍一下"' . "\n\n" .
                     'Q2: "您今天是怎么过来的呢？"' . "\n" .
                     '→ 判断距离和服务半径',
        'tips' => '开场要热情自然，不要一上来就销售'
    ],
    [
        'module_id' => 2,
        'card_type' => 'D',
        'card_code' => 'ten-questions-needs-cards',
        'title' => '销售十问话术卡-需求挖掘',
        'content' => '【Q3-Q9 需求挖掘话术】' . "\n\n" .
                     'Q3: "之前有上过兴趣班吗？"' . "\n" .
                     '→ 钢琴/画画=高消费力' . "\n\n" .
                     'Q4: "学了多久了？"' . "\n" .
                     '→ 判断续费意愿' . "\n\n" .
                     'Q5: "是您给孩子报名的吗？"' . "\n" .
                     '→ 判断决策人' . "\n\n" .
                     'Q6: "谁带孩子上课？"' . "\n" .
                     '→ 判断接送人',
        'tips' => '通过兴趣班类型和数量判断消费力'
    ],
    [
        'module_id' => 2,
        'card_type' => 'C',
        'card_code' => 'ten-questions-exam',
        'title' => '销售十问通关卡',
        'content' => '【销售十问通关考核】' . "\n" .
                     '1. 能完整说出销售十问的内容' . "\n" .
                     '2. 能说出每问的目的和判断逻辑' . "\n" .
                     '3. 能模拟演练Q1-Q2破冰话术' . "\n" .
                     '4. 能通过兴趣班类型判断消费力' . "\n" .
                     '5. 能说出预言成交法则的使用时机',
        'tips' => '考核通过才能进行实际谈单'
    ],
    // 融入家长沟通话术模块(mod-communication=5)
    [
        'module_id' => 5,
        'card_type' => 'K',
        'card_code' => 'ten-questions-consumer-analysis',
        'title' => '消费力判断知识点',
        'content' => '【通过兴趣班判断消费力】' . "\n\n" .
                     '高消费力特征：' . "\n" .
                     '- 钢琴（课单价高，需长期投入）' . "\n" .
                     '- 画画/美术（审美培养，高投入）' . "\n" .
                     '- 马术/高尔夫（高端运动）' . "\n" .
                     '- 外语/全脑开发' . "\n\n" .
                     '中等消费力特征：' . "\n" .
                     '- 街舞/跆拳道' . "\n" .
                     '- 游泳/羽毛球' . "\n\n" .
                     '需教育培养：' . "\n" .
                     '- 没上过任何兴趣班',
        'tips' => '消费力判断是需求挖掘的核心'
    ],
    [
        'module_id' => 5,
        'card_type' => 'S',
        'card_code' => 'ten-questions-close-script',
        'title' => '预言成交话术卡',
        'content' => '【Q10预言成交话术】' . "\n\n" .
                     '标准话术：' . "\n" .
                     '"平时您是周中有空还是周末有空呢？"' . "\n" .
                     '"我给您看看校区的课表"' . "\n" .
                     '"您看看哪个时间段有空？" ' . "\n\n" .
                     '使用时机：报价之前' . "\n" .
                     '目的：锁定时间后再报价，减少价格抗拒',
        'tips' => '先锁时间后报价，这是签单的关键一步'
    ]
];

foreach ($trainingCards as $card) {
    $stmt = $db->prepare("SELECT id FROM training_cards WHERE card_code = ?");
    $stmt->execute([$card['card_code']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $updateSql = "UPDATE training_cards SET module_id = ?, card_type = ?, title = ?, content = ?, tips = ? WHERE card_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            $card['module_id'],
            $card['card_type'],
            $card['title'],
            $card['content'],
            $card['tips'],
            $card['card_code']
        ]);
        echo "Updated (card): {$card['title']}\n";
    } else {
        $insertSql = "INSERT INTO training_cards (module_id, card_type, card_code, title, content, tips, status)
                       VALUES (?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $card['module_id'],
            $card['card_type'],
            $card['card_code'],
            $card['title'],
            $card['content'],
            $card['tips']
        ]);
        echo "Inserted (card): {$card['title']}\n";
    }
}

echo "\n=== 导入完成 ===\n";

// 统计各维度数据
echo "\n=== 当前数据统计 ===\n";
$stmt = $db->query("SELECT dimension_id, COUNT(*) as count FROM script_knowledge GROUP BY dimension_id ORDER BY dimension_id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $dimNames = ['', '问答话术', '专业知识点', '课后点评', '独立谈单'];
    echo "{$dimNames[$row['dimension_id']]}: {$row['count']}条\n";
}

$stmt = $db->query("SELECT module_id, COUNT(*) as count FROM training_cards GROUP BY module_id ORDER BY module_id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $moduleNames = [1=>'品牌', 2=>'接待', 3=>'评估', 4=>'体验', 5=>'沟通', 6=>'续费', 7=>'管理'];
    echo "模块{$row['module_id']}({$moduleNames[$row['module_id']]}): {$row['count']}张卡片\n";
}
