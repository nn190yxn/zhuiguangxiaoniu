<?php
/**
 * 补充feedback维度内容
 * 课后点评/课前反馈话术模板
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

echo "=== 补充feedback维度内容 ===\n\n";

$dim_feedback = 3; // feedback维度ID
$sort_order = 100;

$feedbackItems = [
    // 课后点评模板
    [
        'scene_code' => 'feedback-after-class-template',
        'scene_name' => '课后点评标准话术模板',
        'keywords' => json_encode(['课后点评', '模板', '标准话术', '教练']),
        'standard_script' => '【课后点评标准结构】' . "\n\n" .
                           '1. 开场暖场' . "\n" .
                           '   "今天XX的课程表现非常棒！"' . "\n\n" .
                           '2. 具体亮点（说2-3个）' . "\n" .
                           '   "今天在跳跃练习中，单脚跳进步很明显，已经能连续跳3下了"' . "\n" .
                           '   "团队配合环节，主动帮助其他小朋友，社交能力提升"' . "\n\n" .
                           '3. 需要提升（说1个）' . "\n" .
                           '   "平衡感还需要加强，单脚站立时还有些晃动"' . "\n\n" .
                           '4. 家庭配合建议' . "\n" .
                           '   "建议在家可以练习单脚站立，每次30秒，每天3组"' . "\n\n" .
                           '5. 鼓励结束' . "\n" .
                           '   "整体进步很大，继续加油！下周见！"',
        'tips' => '教练必背：课后点评=亮点+亮点+提升+建议+鼓励'
    ],
    [
        'scene_code' => 'feedback-first-class',
        'scene_name' => '首次体验课课后反馈',
        'keywords' => json_encode(['首次课', '体验课', '课后反馈', '家长']),
        'standard_script' => '【首次体验课反馈话术】' . "\n\n" .
                           '开场：' . "\n" .
                           '"XX妈妈/爸爸您好！今天XX的第一次体验课顺利完成啦！"' . "\n\n" .
                           '孩子表现（先扬后抑）：' . "\n" .
                           '正面："XX今天表现很勇敢，第一次来就能跟着教练完成大部分动作"' . "\n" .
                           '建议："前期会有点陌生感，这是正常的，适应几节课就好了"' . "\n\n" .
                           '课程内容反馈：' . "\n" .
                           '"今天主要体验了感统训练，XX在平衡木上表现不错，就是跳跃时还有点犹豫"' . "\n\n" .
                           '后续建议：' . "\n" .
                           '"建议可以报一个短期课程让孩子适应，一般2-3周就能完全融入"' . "\n\n" .
                           '邀约下次：' . "\n" .
                           '"我们这周六还有一节体验课，XX喜欢的话可以和小朋友一起参加。"',
        'tips' => '首次课反馈决定是否续费，要重点说亮点和后续价值'
    ],
    [
        'scene_code' => 'feedback-monthly',
        'scene_name' => '月度学习总结反馈',
        'keywords' => json_encode(['月度', '总结', '反馈', '阶段性', '家长会']),
        'standard_script' => '【月度学习总结反馈话术】' . "\n\n" .
                           '开场：' . "\n" .
                           '"XX妈妈您好！这一个月XX的进步非常明显，我来给您做个总结。"' . "\n\n" .
                           '身体素质进步：' . "\n" .
                           '"和刚入学时相比，XX的平衡能力提升了2个等级，跳绳从每分钟20个到现在的45个。"' . "\n\n" .
                           '运动习惯养成：' . "\n" .
                           '"现在每次上课都很积极出勤，基本不请假了，团队意识也增强了。"' . "\n\n" .
                           '下月目标：' . "\n" .
                           '"下个月我们重点提升XX的上肢力量，目标是能够完成5个标准俯卧撑。"' . "\n\n" .
                           '家长配合：' . "\n" .
                           '"在家可以每天做10个蹲起和5分钟跳绳，对提升很有帮助。"' . "\n\n" .
                           '续费引导：' . "\n" .
                           '"XX现在正处于运动敏感期，持续训练效果最好。这边给您申请了老学员续费优惠..."',
        'tips' => '月度反馈是续费的关键时机，要用数据说话'
    ],
    [
        'scene_code' => 'feedback-trial-warning',
        'scene_name' => '体验课预警反馈',
        'keywords' => json_encode(['预警', '预警反馈', '风险', '流失', '流失预警']),
        'standard_script' => '【体验课风险预警反馈话术】' . "\n\n" .
                           '适用场景：孩子连续3次课表现下滑/出勤下降/情绪抵触' . "\n\n" .
                           '发现问题：' . "\n" .
                           '"XX妈妈，我发现最近XX上课时有点注意力不集中，跳跃练习时也不太积极。"' . "\n\n" .
                           '了解原因：' . "\n" .
                           '"您在家有发现什么变化吗？是在学校遇到什么问题了，还是我们课程内容不太合适？"'. "\n\n" .
                           '方案建议：' . "\n" .
                           '"针对XX的情况，我建议：1) 我们换一种教学方式，从游戏入手；2) 您晚上多带孩子到楼下做做运动；3) 我们下周先停一节课让孩子调整一下。"' . "\n\n" .
                           '持续跟进：' . "\n" .
                           '"我会持续关注XX的状态，有什么问题随时和您沟通。"',
        'tips' => '预警反馈要及时，发现问题后24小时内必须联系家长'
    ],
    [
        'scene_code' => 'feedback-pre-class',
        'scene_name' => '课前预习提醒话术',
        'keywords' => json_encode(['课前', '预习', '提醒', '准备', '家长']),
        'standard_script' => '【课前预习提醒话术】' . "\n\n" .
                           '提醒内容（课前一天）：' . "\n" .
                           '"XX妈妈您好！明天XX有体能课，记得穿运动服和运动鞋，晚上早点休息不要熬夜哦。"' . "\n\n" .
                           '本次课内容预告：' . "\n" .
                           '"明天我们会练习跳绳和平衡木，如果在家有练习过的话明天会更容易跟上。"' . "\n" .
                           '"可以让孩子今天晚上练习一下双脚跳50个，为明天的课程做好准备。"' . "\n\n" .
                           '后勤提醒：' . "\n" .
                           '"记得带水杯，上课前30分钟不要吃太多东西。"' . "\n\n" .
                           '确认出席：' . "\n" .
                           '"确认一下明天XX能来上课吗？有什么变化请提前告诉我。"',
        'tips' => '课前提醒能提高出勤率，也能让家长感受到服务'
    ],
    [
        'scene_code' => 'feedback-special-progress',
        'scene_name' => '特殊进步专项反馈',
        'keywords' => json_encode(['特殊进步', '突破', '里程碑', '好消息', '家长']),
        'standard_script' => '【特殊进步专项反馈话术】' . "\n\n" .
                           '激动开场：' . "\n" .
                           '"XX妈妈！今天有个特别好的消息要告诉您！"' . "\n\n" .
                           '具体进步（数据化）：' . "\n" .
                           '"今天XX的体测成绩出来了！立定跳远从95cm提升到115cm，超过同龄人前30%了！" ' . "\n" .
                           '"跳绳从每分钟30个提升到80个，这个进步速度非常快！"' . "\n\n" .
                           '原因分析：' . "\n" .
                           '"这和XX这段时间的认真训练分不开，也说明我们的训练方案非常有效。"' . "\n\n" .
                           '肯定家长：' . "\n" .
                           '"您在家也配合得很好，每天带孩子练习的效果很明显。"' . "\n\n" .
                           '鼓励持续：' . "\n" .
                           '"继续保持这个节奏，3个月后XX的体能能达到同龄人中上水平了！"',
        'tips' => '好消息要第一时间分享，好消息能带来续费和转介绍'
    ],
    [
        'scene_code' => 'feedback-assessment-result',
        'scene_name' => '体测结果反馈话术',
        'keywords' => json_encode(['体测', '结果', '反馈', '数据', '进步']),
        'standard_script' => '【体测结果反馈话术】' . "\n\n" .
                           '预约时间：' . "\n" .
                           '"XX妈妈您好！XX的三个月体测报告出来了，方便的话我给您详细讲解一下。"' . "\n\n" .
                           '整体评价：' . "\n" .
                           '"整体来看，XX这三个月进步很明显，有3项指标达到良好，2项需要继续加强。"' . "\n\n" .
                           '逐项解读：' . "\n" .
                           '"立定跳远：从C提升到B，超过63%的同龄孩子"' . "\n" .
                           '"跳绳：从每分钟45个提升到78个，达到A等水平"' . "\n" .
                           '"柔韧性：还需要加强，是下阶段的训练重点"' . "\n\n" .
                           '与全国对比：' . "\n" .
                           '"根据我们的体测系统，XX的体能综合评分达到了78分，超过58%的同龄男孩。"' . "\n\n" .
                           '后续方案：' . "\n" .
                           '"针对柔韧性，我们会在课程中增加拉伸训练，您在家也可以帮孩子做做被动拉伸。"',
        'tips' => '体测结果反馈要数据化、可视化，让家长看到进步'
    ]
];

echo "开始导入{$dim_feedback}维度...\n\n";

foreach ($feedbackItems as $item) {
    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$item['scene_code']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            $item['scene_name'],
            $item['keywords'],
            $item['standard_script'],
            $item['tips'],
            $item['scene_code']
        ]);
        echo "Updated: {$item['scene_name']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $dim_feedback,
            $item['scene_code'],
            $item['scene_name'],
            $item['keywords'],
            $item['standard_script'],
            $item['tips'],
            $sort_order++
        ]);
        echo "Inserted: {$item['scene_name']}\n";
    }
}

echo "\n=== 导入完成 ===\n";

// 统计各维度
echo "\n【话术知识库各维度统计】\n";
$stmt = $db->query("SELECT dimension_id, COUNT(*) as cnt FROM script_knowledge GROUP BY dimension_id ORDER BY dimension_id");
$dimNames = ['', '问答话术', '专业知识点', '课后点评', '独立谈单'];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$dimNames[$row['dimension_id']]}: {$row['cnt']}条\n";
}
