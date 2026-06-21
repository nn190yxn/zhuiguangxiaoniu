<?php
/**
 * 训练模块和卡片数据初始化
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
    die("Database connection failed: " . $e->getMessage());
}

echo "Starting data initialization...\n";

// Insert roles
$roles = [
    ['consultant', '课程顾问', '负责客户接待、销售转化、续费维护'],
    ['coach', '教练', '负责课堂教学、学员评估、反馈沟通'],
    ['manager', '店长', '负责门店运营、团队管理、业绩达成'],
    ['newbie', '新员工', '全面学习，所有模块都需要通关'],
];

foreach ($roles as $role) {
    $stmt = $db->prepare("INSERT IGNORE INTO user_roles (role_code, role_name, description, sort_order) VALUES (?, ?, ?, ?)");
    $stmt->execute([$role[0], $role[1], $role[2], array_search($role[0], array_column($roles, 0))]);
}
echo "Roles inserted.\n";

// Insert training modules
$modules = [
    ['mod-brand', '品牌与产品认知', '了解追光小牛品牌理念、课程体系、产品优势', 'newbie', '基础', 'beginner', 10],
    ['mod-reception', '首次到店接待', '掌握接待流程、服务标准、破冰技巧', 'consultant', '销售基础', 'beginner', 15],
    ['mod-assessment', '体测评估技能', '掌握儿童体能评估方法、ACE评估体系', 'coach', '专业技能', 'beginner', 12],
    ['mod-trial', '体验课转化', '体验课流程管理、课程推荐、异议处理', 'consultant', '销售进阶', 'intermediate', 15],
    ['mod-communication', '家长沟通话术', '各类场景沟通话术、反馈表达、投诉处理', 'coach', '服务技能', 'intermediate', 15],
    ['mod-renewal', '续费与转介绍', '续费时机把握、话术技巧、转介绍激励', 'consultant', '销售进阶', 'intermediate', 12],
    ['mod-management', '门店日常管理', '店长职责、数据管理、团队带领', 'manager', '管理技能', 'advanced', 15],
];

$sortOrder = 0;
foreach ($modules as $module) {
    $stmt = $db->prepare("INSERT IGNORE INTO training_modules (module_code, module_name, description, role_code, category, level, total_cards, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute(array_merge($module, [$sortOrder++]));
}
echo "Modules inserted.\n";

// Get module IDs
$moduleIds = [];
$stmt = $db->query("SELECT id, module_code FROM training_modules");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $moduleIds[$row['module_code']] = $row['id'];
}

// Insert training cards for each module
$cards = [
    // mod-brand: 品牌与产品认知
    ['mod-brand', 'K', 'card-K-001', '追光小牛品牌故事', "追光小牛是一家专注于3-12岁儿童体能培训的连锁机构。我们的使命是'用科学运动帮助孩子健康成长'。品牌名称寓意：追光 - 追逐光明未来，牛 - 成就每一个小小运动员。", '追光象征积极向上的人生观，牛代表坚韧和成就。'],
    ['mod-brand', 'K', 'card-K-002', '我们的课程体系', "追光小牛课程体系分为四大板块：\n1. 感统训练（3-6岁）- 促进感觉统合能力发展\n2. 体能训练（6-9岁）- 提升基础运动素质\n3. 技能训练（9-12岁）- 学习专项运动技能\n4. 体测达标（各年龄段）- 针对学校体测进行专项训练", '根据孩子年龄选择合适课程板块'],
    ['mod-brand', 'S', 'card-S-001', '介绍品牌理念', "家长您好！追光小牛是一家专业做儿童体能培训的机构。我们不是那种综合早教，而是专注做体能和感统训练。很多家长选择我们，是因为我们有三甲医院运动康复科背景的教练团队，还有自主研发的课程体系。", '强调专业性和差异化优势'],
    ['mod-brand', 'S', 'card-S-002', '解答价格疑问', "我们的课程是按课时包来收费的，平均每节课在150-200元之间。对比市场上同类专业机构，我们的性价比很高。因为我们的教练都有专业资质，课程都是自主研发的，而且每个孩子都有独立的训练档案。", '先讲价值，再提价格'],
    ['mod-brand', 'C', 'card-C-001', '品牌知识检查', "请检查以下知识点是否掌握：\n1. 追光小牛专注哪个年龄段？\n2. 三大课程板块是什么？\n3. 品牌使命是什么？\n4. 相比早教机构的差异点？", '全部掌握才能通过'],
    // mod-reception: 首次到店接待
    ['mod-reception', 'K', 'card-K-101', '接待流程8步法', "首次到店接待8步法：\n1. 预约确认（预约后2h内）\n2. 建立服务群（预约后4h内）\n3. 到访前1天确认\n4. 到店迎接+签到\n5. 环境介绍+需求沟通（5min内）\n6. 体验课（45-60min）\n7. 课后反馈+方案推荐（10min内）\n8. 当日成交交接/未成交分流", '8步法必须全部掌握'],
    ['mod-reception', 'S', 'card-S-101', '破冰话术', "宝宝今天表现很棒呀！刚才我在旁边观察，他在平衡木上走得越来越稳了，一看就是爱运动的小朋友。请问宝宝平时喜欢玩什么游戏呀？", '先夸孩子，再问家长，拉近距离'],
    ['mod-reception', 'S', 'card-S-102', '需求挖掘话术', "您今天最想通过体验课了解孩子哪方面的情况呢？是想看看孩子的运动基础，还是想了解他跟同龄孩子比有什么优势或需要加强的地方？", '了解家长真实目的'],
    ['mod-reception', 'D', 'card-D-101', '接待演练场景', "场景：一位4岁女孩第一次到店，有些害羞躲在妈妈身后。\n请模拟完整的接待破冰过程，包括：\n1. 如何称呼孩子\n2. 如何消除孩子陌生感\n3. 如何与家长开始沟通", '演练后AI评分'],
    ['mod-reception', 'C', 'card-C-101', '接待服务通关项', "请检查以下服务是否做到：\n1. 主动叫出孩子名字\n2. 介绍门店环境（洗手间、休息区）\n3. 给孩子佩戴体验名牌\n4. 顾问全程陪同家长\n5. 教练课后3分钟内给出口头反馈", '全部做到才能通过'],
    // mod-assessment: 体测评估
    ['mod-assessment', 'K', 'card-K-201', 'ACE评估体系', "ACE儿童体能评估体系包含五大维度：\n1. 身体素质（力量、速度、耐力）\n2. 感统能力（前庭觉、本体觉、触觉）\n3. 协调性（平衡感、节奏感）\n4. 专注力（注意力、反应速度）\n5. 运动技能（跑跳投掷等）", 'ACE体系是核心评估工具'],
    ['mod-assessment', 'K', 'card-K-202', '各年龄段体测重点', "3-4岁：重点评估感统基础、平衡能力\n5-6岁：加入协调性、专注力评估\n7-9岁：开始评估体能素质和运动技能\n10-12岁：针对体测达标项目专项评估", '不同年龄评估重点不同'],
    ['mod-assessment', 'S', 'card-S-201', '体测反馈话术', "XX妈妈，今天宝宝在体测中表现非常好！他在平衡木上走了15步才掉下来，这个年龄段能达到这个水平很不错。稍微需要加强的是他的专注力，在持续注意力方面还有提升空间。不过别担心，这些都是可以通过训练来改善的。", '先说亮点，再提建议'],
    ['mod-assessment', 'D', 'card-D-201', '体测评估演练', "场景：6岁男孩，体测显示协调性偏弱，跳跃能力达标。\n请向家长反馈体测结果，并推荐合适的课程。", 'AI评估沟通效果'],
    ['mod-assessment', 'C', 'card-C-201', '体测操作通关项', "请确认以下操作是否规范：\n1. 测试前是否询问孩子身体状况\n2. 是否按标准流程进行测试\n3. 是否有记录测试数据\n4. 是否当场给出初步评估", '规范操作确保准确性'],
];

// Insert cards
$stmt = $db->prepare("INSERT IGNORE INTO training_cards (module_id, card_type, card_code, title, content, tips, difficulty, score) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($cards as $card) {
    if (isset($moduleIds[$card[0]])) {
        $stmt->execute([
            $moduleIds[$card[0]],
            $card[1],
            $card[2],
            $card[3],
            $card[4],
            $card[5] ?? null,
            $card[1] === 'K' ? 'easy' : ($card[1] === 'D' ? 'hard' : 'medium'),
            100
        ]);
    }
}
echo "Cards inserted.\n";

// Update module card counts
$updateStmt = $db->prepare("
    UPDATE training_modules tm
    SET tm.total_cards = (
        SELECT COUNT(*) FROM training_cards WHERE module_id = tm.id
    )
");
$updateStmt->execute();
echo "Module card counts updated.\n";

echo "Data initialization completed!\n";
