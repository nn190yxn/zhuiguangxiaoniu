<?php
/**
 * 导入销售七步曲到话术知识库和培训卡片
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

echo "=== 销售七步曲导入脚本 ===\n\n";

// 销售七步曲数据
$sevenSteps = [
    [
        'step' => 1,
        'name' => '暖场破冰',
        'keywords' => ['暖场', '破冰', '打招呼', '自我介绍', '建立信任'],
        'qa_script' => 'Q: 首次见面如何破冰？' . "\n" .
                      'A: "您好！我是追光小牛的XX教练，非常高兴认识您和宝贝！请问怎么称呼您呢？孩子叫什么名字？今年多大了？"' . "\n\n" .
                      'Q: 如何了解客户来源渠道？' . "\n" .
                      'A: "您是怎么了解到我们追光小牛的呢？是朋友推荐还是看到我们的宣传？"' . "\n\n" .
                      'Q: 如何消除家长戒备心理？' . "\n" .
                      'A: "您可以先带孩子熟悉一下环境，我们这里是专业儿童运动中心，所有器材都是为孩子设计的，很安全。让孩子先玩一玩，看看喜不喜欢。"',
        'knowledge' => '【暖场破冰关键点】' . "\n" .
                      '1. 主动热情：第一时间打招呼，展现专业形象' . "\n" .
                      '2. 标准自我介绍："您好！我是追光小牛的XX教练"' . "\n" .
                      '3. 孩子信息：姓名、年龄、来源渠道、平时运动情况' . "\n" .
                      '4. 环境介绍：专业器材、安全环境、课程特色' . "\n" .
                      '5. 拉近距离：用孩子感兴趣的话题切入',
        'deal_tips' => '【暖场破冰谈单要点】' . "\n" .
                      '1. 第一印象决定成交：着装整洁、热情专业' . "\n" .
                      '2. 用孩子打开话题：让孩子喜欢你' . "\n" .
                      '3. 快速建立信任：展示专业资质和成功案例' . "\n" .
                      '4. 不要急于销售：先建立关系，再谈成交'
    ],
    [
        'step' => 2,
        'name' => '需求挖掘',
        'keywords' => ['需求', '挖掘', '背景', '期望', '痛点'],
        'qa_script' => 'Q: 如何挖掘家长真实需求？' . "\n" .
                      'A: "您希望孩子通过运动改善哪方面呢？是体质、专注力，还是想让孩子多运动、少生病？或者是为了准备某项考试？"',
        'knowledge' => '【需求挖掘关键点】' . "\n" .
                      '1. 了解孩子成长背景：出生情况、发育状况' . "\n" .
                      '2. 了解孩子兴趣爱好：喜欢什么运动、怕什么' . "\n" .
                      '3. 了解运动经历：之前学过什么、为什么没继续' . "\n" .
                      '4. 了解家长期望：想达到什么效果、时间安排' . "\n" .
                      '5. 了解决策人：谁决定、谁付费、谁陪伴',
        'deal_tips' => '【需求挖掘谈单要点】' . "\n" .
                      '1. 多问开放式问题：什么、为什么、怎么想' . "\n" .
                      '2. 挖掘痛点需求：孩子有什么问题让家长困扰' . "\n" .
                      '3. 确认决策人：谁才是真正能拍板的人' . "\n" .
                      '4. 记录家长需求：方便后续跟进和个性化推荐'
    ],
    [
        'step' => 3,
        'name' => '产品介绍',
        'keywords' => ['产品', '介绍', '品牌', '课程', '服务'],
        'qa_script' => 'Q: 如何介绍品牌实力？' . "\n" .
                      'A: "追光小牛是贵阳本土专业儿童运动品牌，5家直营门店，40+专业教练，世界冠军参与课程研发，已服务10万+会员。"' . "\n\n" .
                      'Q: 如何介绍课程体系？' . "\n" .
                      'A: "我们根据孩子年龄和需求科学分龄：2-6岁感统训练、3-12岁体能篮球、4-14岁跑酷跳绳、7-14岁中考体测，每阶段都有针对性方案。"',
        'knowledge' => '【产品介绍关键点】' . "\n" .
                      '1. 品牌实力：冠军研发、5店连锁、专业安全' . "\n" .
                      '2. 教练资质：持证上岗、专业培训、爱孩子' . "\n" .
                      '3. 课程体系：分龄分阶段、科学训练、效果可见' . "\n" .
                      '4. 服务流程：体测评估、定制方案、三个月反馈' . "\n" .
                      '5. 差异化：其他机构没有的，我们有',
        'deal_tips' => '【产品介绍谈单要点】' . "\n" .
                      '1. 用数据说话：5家店、40+教练、10万+会员' . "\n" .
                      '2. 用案例说话：某某孩子来之前怎样，现在怎样' . "\n" .
                      '3. 用权威说话：世界冠军研发、专业机构认证' . "\n" .
                      '4. 匹配需求：家长想要什么，我们就重点介绍什么'
    ],
    [
        'step' => 4,
        'name' => '异议处理',
        'keywords' => ['异议', '处理', '价格', '时间', '效果', '安全'],
        'qa_script' => 'Q: 家长说"太贵了"怎么处理？' . "\n" .
                      'A: "我理解您的顾虑。不过运动是影响孩子一生的事，现在投资运动改造成本最低。您算一下，一周几节课，每节课不到XX元，还包含专业体测、个性方案、运动保险。而且我们最近有优惠活动..."' . "\n\n" .
                      'Q: 家长说"没时间"怎么处理？' . "\n" .
                      'A: "我完全理解您忙。其实一周只需要2-3个小时，运动不仅不浪费时间，反而能提升孩子专注力，学习效率更高。很多家长发现，孩子运动后成绩反而进步了。"' . "\n\n" .
                      'Q: 家长说"效果不明显"怎么处理？' . "\n" .
                      'A: "您关注效果是对的。我们每三个月做体测对比，同龄全国排名进步您能看到。而且运动改造大脑，孩子专注力好了、吃饭香了、睡得好了，这些都是变化。三个月后您再看。"',
        'knowledge' => '【异议处理四步法】' . "\n" .
                      '1. 认同：先认可家长的顾虑，"我理解您的想法"' . "\n" .
                      '2. 提问：了解真实原因，"您主要是担心什么？"' . "\n" .
                      '3. 解答：针对性解决，给出证据和案例' . "\n" .
                      '4. 引导：把话题引回到课程价值上',
        'deal_tips' => '【异议处理谈单要点】' . "\n" .
                      '1. 价格异议：拆解成本、强调价值、适时优惠' . "\n" .
                      '2. 时间异议：强调效率、亲子陪伴、习惯养成' . "\n" .
                      '3. 效果异议：数据对比、案例展示、耐心解释' . "\n" .
                      '4. 安全异议：专业器材、专业教练、安全制度'
    ],
    [
        'step' => 5,
        'name' => '逼单成交',
        'keywords' => ['逼单', '成交', '优惠', '签单', '限额'],
        'qa_script' => 'Q: 如何自然提出成交？' . "\n" .
                      'A: "今天报名可以享受XX优惠，而且这个活动本周就截止了。如果您觉得合适的话，我可以帮您准备合同。"' . "\n\n" .
                      'Q: 家长犹豫不决怎么办？' . "\n" .
                      'A: "我理解要决定一件事需要考虑。不过这个优惠名额有限，今天报名的话还能赠送XX体验课。您看是先定下来，如果之后有变化我们也可以调整。"',
        'knowledge' => '【逼单成交六种方法】' . "\n" .
                      '1. 优惠限时：今天报名享XX折，仅限本月' . "\n" .
                      '2. 限额逼单：本期名额仅剩XX个' . "\n" .
                      '3. 赠品促成：报名即送运动装备/体验课' . "\n" .
                      '4. 假设成交："您是刷信用卡还是微信？"' . "\n" .
                      '5. 小点成交：先定一个月试试' . "\n" .
                      '6. 家庭投票：邀请家人一起做决定',
        'deal_tips' => '【逼单成交谈单要点】' . "\n" .
                      '1. 捕捉成交信号：家长问细节、问优惠、点头认可' . "\n" .
                      '2. 主动提出成交：不要等家长自己说' . "\n" .
                      '3. 优惠要真实：不要虚构优惠，给真实的好处' . "\n" .
                      '4. 签单要快：一旦有意向，立即促成'
    ],
    [
        'step' => 6,
        'name' => '转教续费',
        'keywords' => ['转教', '续费', '规划', '课时', '关系'],
        'qa_script' => 'Q: 如何做课程规划？' . "\n" .
                      'A: "根据孩子的情况，我建议先报XX课时，大约X个月的学习周期。我们会在1个月后做第一次复评，3个月后做体测对比，您可以看到明显的进步。"' . "\n\n" .
                      'Q: 如何邀请下次到店？' . "\n" .
                      'A: "孩子今天的体验非常好。下周我们有一节公开课，XX教练主讲，您可以带孩子来参加，我帮您预约。""',
        'knowledge' => '【转教续费关键点】' . "\n" .
                      '1. 制定规划：根据孩子需求制定1-3年学习规划' . "\n" .
                      '2. 课时建议：告知合理课时数和预计效果' . "\n" .
                      '3. 预约下次：现场预约下次到店时间' . "\n" .
                      '4. 关系维护：加微信、建群、发送课后反馈' . "\n" .
                      '5. 续费预警：提前15天提醒续费',
        'deal_tips' => '【转教续费谈单要点】' . "\n" .
                      '1. 现场规划：当场制定学习计划，展示专业性' . "\n" .
                      '2. 目标锁定：让孩子有明确的学习目标' . "\n" .
                      '3. 情感账户：多互动、多关心、不只是卖课' . "\n" .
                      '4. 续费激励：提前通知优惠，培养忠诚度'
    ],
    [
        'step' => 7,
        'name' => '主动转介绍',
        'keywords' => ['转介绍', '口碑', '推荐', '资源', '裂变'],
        'qa_script' => 'Q: 如何请求转介绍？' . "\n" .
                      'A: "非常感谢您选择追光小牛！您的孩子在我们这里训练效果好的话，希望您能推荐给身边有需要的朋友。我们有老带新奖励，您推荐一位家长报名，双方都能获得XX奖励。"' . "\n\n" .
                      'Q: 家长说"我没有朋友需要"怎么办？' . "\n" .
                      'A: "没关系，以后如果有朋友想了解运动方面的事情，可以加我微信，有什么问题随时问我。我们也会不定期举办家长沙龙活动，到时候邀请您参加。"',
        'knowledge' => '【主动转介绍四种方式】' . "\n" .
                      '1. 老带新奖励：推荐报名各得XX优惠' . "\n" .
                      '2. 朋友圈分享：分享孩子运动照片/视频' . "\n" .
                      '3. 口碑传播：让家长主动向朋友推荐' . "\n" .
                      '4. 资源收集：收集家长联系方式便于后续跟进',
        'deal_tips' => '【主动转介绍谈单要点】' . "\n" .
                      '1. 最佳时机：家长签单满意时请求转介绍' . "\n" .
                      '2. 给出理由：让家长知道推荐对他朋友也有价值' . "\n" .
                      '3. 简化流程：提供联系方式，让家长轻松推荐' . "\n" .
                      '4. 情感账户：日常维护，让家长愿意帮你推荐'
    ]
];

// 1. 导入到话术知识库 - qa维度
echo "=== 导入话术知识库（qa维度）===\n";
$dim_qa = 1;
$sort_order = 100;

foreach ($sevenSteps as $step) {
    $scene_code = 'seven-steps-step' . $step['step'];

    // 检查是否存在
    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$scene_code]);
    $existing = $stmt->fetch();

    $keywords = json_encode($step['keywords']);

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            '销售七步曲-' . $step['name'],
            $keywords,
            $step['qa_script'],
            '掌握' . $step['name'] . '的标准问答话术',
            $scene_code
        ]);
        echo "Updated (qa): 销售七步曲-{$step['name']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $dim_qa,
            $scene_code,
            '销售七步曲-' . $step['name'],
            $keywords,
            $step['qa_script'],
            '掌握' . $step['name'] . '的标准问答话术',
            $sort_order++
        ]);
        echo "Inserted (qa): 销售七步曲-{$step['name']}\n";
    }
}

// 2. 导入到话术知识库 - knowledge维度
echo "\n=== 导入话术知识库（knowledge维度）===\n";
$dim_knowledge = 2;
$sort_order = 200;

foreach ($sevenSteps as $step) {
    $scene_code = 'seven-steps-knowledge-' . $step['step'];

    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$scene_code]);
    $existing = $stmt->fetch();

    $keywords = array_map(function($k) { return $k . ',销售七步曲'; }, $step['keywords']);

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            '销售七步曲知识点-' . $step['name'],
            json_encode($keywords),
            $step['knowledge'],
            '理解' . $step['name'] . '的专业知识点',
            $scene_code
        ]);
        echo "Updated (knowledge): 销售七步曲知识点-{$step['name']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $dim_knowledge,
            $scene_code,
            '销售七步曲知识点-' . $step['name'],
            json_encode($keywords),
            $step['knowledge'],
            '理解' . $step['name'] . '的专业知识点',
            $sort_order++
        ]);
        echo "Inserted (knowledge): 销售七步曲知识点-{$step['name']}\n";
    }
}

// 3. 导入到话术知识库 - deal维度
echo "\n=== 导入话术知识库（deal维度）===\n";
$dim_deal = 4;
$sort_order = 100;

foreach ($sevenSteps as $step) {
    $scene_code = 'seven-steps-deal-' . $step['step'];

    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$scene_code]);
    $existing = $stmt->fetch();

    $keywords = array_map(function($k) { return $k . ',谈单'; }, $step['keywords']);

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            '销售七步曲谈单技巧-' . $step['name'],
            json_encode($keywords),
            $step['deal_tips'],
            '掌握' . $step['name'] . '的谈单技巧',
            $scene_code
        ]);
        echo "Updated (deal): 销售七步曲谈单技巧-{$step['name']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $dim_deal,
            $scene_code,
            '销售七步曲谈单技巧-' . $step['name'],
            json_encode($keywords),
            $step['deal_tips'],
            '掌握' . $step['name'] . '的谈单技巧',
            $sort_order++
        ]);
        echo "Inserted (deal): 销售七步曲谈单技巧-{$step['name']}\n";
    }
}

// 4. 导入到培训卡片 - 创建销售七步曲模块或融入现有模块
echo "\n=== 导入培训卡片 ===\n";

$trainingCards = [
    // 融入家长沟通话术模块(mod-communication=5)
    [
        'module_id' => 5,
        'card_type' => 'K',
        'card_code' => 'seven-steps-overview',
        'title' => '销售七步曲概述',
        'content' => '销售七步曲是追光小牛的标准销售流程：' . "\n" .
                         '1. 暖场破冰 - 建立信任' . "\n" .
                         '2. 需求挖掘 - 了解真实需求' . "\n" .
                         '3. 产品介绍 - 展示品牌价值' . "\n" .
                         '4. 异议处理 - 解答家长疑虑' . "\n" .
                         '5. 逼单成交 - 促成签单' . "\n" .
                         '6. 转教续费 - 服务续费' . "\n" .
                         '7. 主动转介绍 - 口碑裂变',
        'tips' => '销售流程,七步曲,标准话术'
    ],
    [
        'module_id' => 5,
        'card_type' => 'S',
        'card_code' => 'seven-steps-warmup',
        'title' => '销售七步曲话术卡-暖场破冰',
        'content' => '【暖场破冰标准话术】' . "\n" .
                         '开场白："您好！我是追光小牛的XX教练，非常高兴认识您和宝贝！"' . "\n" .
                         '获取信息："请问怎么称呼您呢？孩子叫什么名字？今年多大了？"',
        'tips' => '暖场,破冰,话术'
    ],
    [
        'module_id' => 5,
        'card_type' => 'S',
        'card_code' => 'seven-steps-needs',
        'title' => '销售七步曲话术卡-需求挖掘',
        'content' => '【需求挖掘标准话术】' . "\n" .
                         '"您希望孩子通过运动改善哪方面呢？是体质、专注力，还是想让孩子多运动、少生病？""' . "\n" .
                         '追问："孩子平时有什么兴趣爱好？之前有报过其他运动课程吗？"',
        'tips' => '需求,挖掘,话术'
    ],
    [
        'module_id' => 5,
        'card_type' => 'D',
        'card_code' => 'seven-steps-objection',
        'title' => '销售七步曲演练卡-异议处理',
        'content' => '【异议处理四步法演练】' . "\n" .
                         '1. 认同："我理解您的想法"' . "\n" .
                         '2. 提问："您主要是担心什么？"' . "\n" .
                         '3. 解答：针对性解决' . "\n" .
                         '4. 引导：回归课程价值' . "\n\n" .
                         '【常见异议应对】' . "\n" .
                         '- 价格贵：拆解成本+强调价值' . "\n" .
                         '- 没时间：效率+习惯养成' . "\n" .
                         '- 效果不明显：数据+案例展示',
        'tips' => '异议处理,演练,应对'
    ],
    [
        'module_id' => 5,
        'card_type' => 'S',
        'card_code' => 'seven-steps-close',
        'title' => '销售七步曲话术卡-逼单成交',
        'content' => '【逼单成交六种方法】' . "\n" .
                         '1. 优惠限时："今天报名享XX折"' . "\n" .
                         '2. 限额逼单："名额仅剩XX个"' . "\n" .
                         '3. 赠品促成："报名即送XX"' . "\n" .
                         '4. 假设成交："您是刷信用卡还是微信？"' . "\n" .
                         '5. 小点成交："先定一个月试试"' . "\n" .
                         '6. 家庭投票："邀请家人一起决定"',
        'tips' => '逼单,成交,话术'
    ],
    // 融入续费转介绍模块(mod-renewal=6)
    [
        'module_id' => 6,
        'card_type' => 'K',
        'card_code' => 'seven-steps-renewal',
        'title' => '销售七步曲知识点-转教续费',
        'content' => '【转教续费关键点】' . "\n" .
                         '1. 制定规划：根据需求制定1-3年学习规划' . "\n" .
                         '2. 课时建议：合理课时数和预计效果' . "\n" .
                         '3. 预约下次：现场预约下次到店' . "\n" .
                         '4. 关系维护：加微信、建群、课后反馈' . "\n" .
                         '5. 续费预警：提前15天提醒',
        'tips' => '转教,续费,知识点'
    ],
    [
        'module_id' => 6,
        'card_type' => 'S',
        'card_code' => 'seven-steps-referral',
        'title' => '销售七步曲话术卡-主动转介绍',
        'content' => '【请求转介绍标准话术】' . "\n" .
                         '"非常感谢您选择追光小牛！您的孩子在我们这里训练效果好的话，希望您能推荐给身边有需要的朋友。我们有老带新奖励，您推荐一位家长报名，双方都能获得XX奖励。"',
        'tips' => '转介绍,话术,老带新'
    ],
    [
        'module_id' => 6,
        'card_type' => 'C',
        'card_code' => 'seven-steps-exam',
        'title' => '销售七步曲通关卡-整体流程',
        'content' => '【销售七步曲通关考核】' . "\n" .
                         '1. 能完整说出销售七步曲名称' . "\n" .
                         '2. 能针对每个步骤说出关键动作' . "\n" .
                         '3. 能模拟演练暖场破冰到逼单成交' . "\n" .
                         '4. 能处理3种以上常见异议' . "\n" .
                         '5. 能说出转介绍的老带新政策',
        'tips' => '考核,通关,七步曲'
    ]
];

foreach ($trainingCards as $card) {
    // 检查是否已存在
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
    echo "模块{$row['module_id']}: {$row['count']}张卡片\n";
}
