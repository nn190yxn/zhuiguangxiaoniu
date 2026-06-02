<?php
/**
 * 根据品牌介绍手册更新话术知识库
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

echo "Connected. Updating knowledge database with brand manual content...\n";

// 需要更新的knowledge条目
$updates = [
    // 1. 品牌故事 - 更新为最新版本
    [
        'scene_code' => 'brand-story',
        'scene_name' => '追光小牛品牌故事',
        'keywords' => json_encode(['品牌', '追光小牛', '哪里', '贵州', '贵阳']),
        'standard_script' => '追光小牛运动成长中心是贵阳本土专业儿童运动品牌。\n\n品牌使命：让运动成为孩子的超能力\n品牌口号：运动，塑造强者精神\n\n门店信息（5家）：\n- 未来方舟开心蘑菇城店\n- 小河万科店\n- 小十字童乐湾校区\n- 未来方舟青少儿运动中心店\n- 观山湖玖福城店\n\n品牌实力：\n- 5家直营连锁门店，覆盖贵阳核心区域\n- 40+专业教练，持证上岗，持续培训\n- 100%安全环境，专业运动地垫，环保材料\n- 正式会员3000+，服务10万+人次\n\n品牌优势：运动改造大脑，科学训练让孩子更聪明。',
        'tips' => '强调贵阳本土品牌、5店覆盖、专业安全'
    ],
    // 2. 课程体系 - 新版本按年龄段
    [
        'scene_code' => 'course-system-age',
        'scene_name' => '课程体系（按年龄段）',
        'keywords' => json_encode(['课程', '年龄', '2岁', '14岁', '体系']),
        'standard_script' => '追光小牛课程体系按年龄段科学分龄：\n\n2-6岁·启蒙探索期\n- 快乐体操/感统体操\n  解决：平衡感差、注意力不集中、胆小不敢尝试\n  效果：感统协调、专注力提升\n\n3-12岁·能力发展期\n- 儿童体能：体质弱、力量不足\n- 篮球专项\n- 增高体能\n  效果：上下肢力量、平衡力全面提升，体质变好少生病\n\n4-14岁·技能提升期\n- 少儿跑酷：培养勇气与身体控制力\n- 跳绳达标：针对体测\n- 搏击防身：提升自我保护\n  效果：从不敢做到自信展示，跳绳每分钟157个\n\n7-14岁·体考冲刺期\n- 中考体训\n  针对：BMI超标、立定跳远、跳绳、仰卧起坐\n  效果：体测从"一般/肥胖"提升至"良好/优秀"',
        'tips' => '根据孩子年龄推荐对应阶段课程'
    ],
    // 3. 八大品牌优势
    [
        'scene_code' => 'brand-advantages',
        'scene_name' => '八大品牌优势',
        'keywords' => json_encode(['优势', '冠军', '连锁', '专业', '安全']),
        'standard_script' => '追光小牛八大品牌优势：\n\n1. 世界冠军联合研发课程\n   - 体操世界冠军邓书弟研发，课程接轨国际\n\n2. 5家直营连锁门店\n   - 贵阳最大规模儿童运动连锁，统一教学标准\n\n3. 标准化教案体系\n   - LTAD运动员长期发展模型，无目标不训练\n\n4. 三个月数据化体测反馈\n   - 全国同龄对比报告，进步看得见\n\n5. 多对一家庭群服务\n   - 教练+课程顾问+店长多对一服务\n\n6. 每月教学目标·每节课后反馈\n   - 家长随时了解训练进度\n\n7. 运动改造大脑·科学训练\n   - 改善感统能力，促进脑部神经发育\n\n8. 七年10万+人次安全记录\n   - 贵阳运动培训好评榜第1名（4.8分）',
        'tips' => '强调冠军研发、专业安全、数据可见'
    ],
    // 4. 品牌实力
    [
        'scene_code' => 'brand-strength',
        'scene_name' => '品牌实力数据',
        'keywords' => json_encode(['实力', '门店', '教练', '会员', '人次']),
        'standard_script' => '追光小牛品牌实力：\n\n- 5家全城门店：覆盖贵阳核心区域，家门口的运动课堂\n- 40+专业教练：持证上岗，持续培训，懂运动更懂孩子\n- 100%安全环境：专业运动地垫，环保材料，全方位安全保障\n- 10万+服务人次：正式会员3000+，贵阳家长的信赖之选\n\n品牌荣誉：\n- 贵阳运动培训好评榜第1名\n- 4.8分高评分\n- 7年安全运营记录',
        'tips' => '用数据说话，展示品牌实力'
    ],
    // 5. 课程价值FABE
    [
        'scene_code' => 'course-fabe',
        'scene_name' => '课程价值FABE',
        'keywords' => json_encode(['FABE', '课程价值', '家长关注']),
        'standard_script' => '课程FABE模型：\n\n【2-6岁快乐体操】\n- 家长关注：平衡感差、容易摔跤、注意力不集中、胆小不敢尝试\n- 课程亮点：通过跳跃、平衡、追逐、攀爬等游戏化活动，在快乐中训练感统协调\n- 孩子变化：掌握单脚跳、连续跳等动作要领，身体协调性和专注力显著提升\n\n【3-12岁体能/篮球/增高】\n- 家长关注：体质弱、容易生病、上肢/下肢力量不足、同龄人中偏矮\n- 课程亮点：科学体能训练+篮球专项+增高拉伸训练，循序渐进\n- 孩子变化：上下肢力量全面提升，体质明显变好，少生病\n\n【4-14岁跑酷/跳绳/搏击】\n- 家长关注：胆小不敢尝试、跳绳不达标、缺乏自我保护能力\n- 课程亮点：跑酷培养勇气、跳绳针对体测达标、搏击提升自我保护\n- 孩子变化：从不敢做到自信展示，跳绳每分钟157个，学会自我保护\n\n【7-14岁中考体训】\n- 家长关注：体育成绩拖后腿影响升学、BMI超标、体测不达标\n- 课程亮点：针对贵阳中考体育项目专项训练，评估后制定个性化方案\n- 孩子变化：跳绳158次（优秀）、立定跳远135cm（良好）',
        'tips' => '使用FABE模型介绍课程：家长关注→课程亮点→孩子变化'
    ],
    // 6. 体测反馈体系
    [
        'scene_code' => 'assessment-feedback',
        'scene_name' => '体测反馈体系',
        'keywords' => json_encode(['体测', '反馈', '三个月', '数据']),
        'standard_script' => '追光小牛体测反馈体系：\n\n每三个月进行专业体测，生成全国同龄对比报告。\n\n体测流程：\n1. 初次评估：了解孩子运动基础和身体状况\n2. 制定方案：根据评估结果制定个性化训练计划\n3. 定期追踪：每三个月复测，对比进步\n\n体测指标：\n- 身高、体重、BMI\n- 立定跳远\n- 跳绳\n- 仰卧起坐\n- 感统能力（平衡、协调、专注力等）\n\n服务承诺：\n- 评估结果真实可见\n- 全国同龄孩子对比\n- 进步曲线追踪\n- 定期家长会反馈',
        'tips' => '强调三个月复测、数据化反馈、进步可见'
    ],
    // 7. 安全感统训练
    [
        'scene_code' => 'safety-sensory',
        'scene_name' => '安全与感统训练',
        'keywords' => json_encode(['安全', '感统', '保护', '环境']),
        'standard_script' => '追光小牛安全与感统训练：\n\n【安全保障】\n- 专业运动地垫：国家体操专业队同款\n- 环保材料：除甲醛处理，校区每周每日清洁\n- 紫外线消毒：闭校后专业消毒\n- 保护手法：教练均经过专业培训\n\n【感统训练】\n运动是唯一医学验证有效提高主动专注力的方式。\n\n感统训练改善：\n- 前庭觉：平衡感、协调、专注力\n- 本体觉：身体控制、运动能力\n- 触觉：情绪稳定、社交能力\n- 视觉：追视能力、学习效率\n\n课程效果：\n有效改善前庭觉、本体觉、触觉、视觉等感统能力，促进脑部神经发育，提高学习效率。\n\n爱运动的孩子更聪明，学习成绩更好。',
        'tips' => '用医学研究背书，强调感统对大脑和学习的帮助'
    ],
    // 8. 客户服务
    [
        'scene_code' => 'customer-service',
        'scene_name' => '客户服务体系',
        'keywords' => json_encode(['服务', 'VIP', '家长', '反馈']),
        'standard_script' => '追光小牛客户服务体系：\n\n【多对一VIP服务】\n- 专属家庭服务群\n- 教练+课程顾问+店长多对一服务\n- 定期户外教育课程、家庭日、家长沙龙\n\n【课后反馈】\n- 每节课后通过家庭群发送训练反馈\n- 每月制定明确阶段训练目标\n- 家长随时了解训练进度、课堂表现\n\n【成长档案】\n- 记录每个孩子的成长轨迹\n- 定期体测对比报告\n- 进步曲线可视化\n\n家长好评：\n"老师课后及时反馈，进步看得见"\n"不仅服务孩子，更服务一个家庭"',
        'tips' => '强调VIP服务、每课反馈、成长档案'
    ],
];

// 更新/插入数据
$dim_id = 2; // knowledge dimension
$sort_order = 100;

foreach ($updates as $entry) {
    $keywords = is_string($entry['keywords']) ? $entry['keywords'] : json_encode($entry['keywords']);

    // 检查是否存在
    $stmt = $db->prepare("SELECT id FROM script_knowledge WHERE scene_code = ?");
    $stmt->execute([$entry['scene_code']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $updateSql = "UPDATE script_knowledge SET scene_name = ?, keywords = ?, standard_script = ?, tips = ? WHERE scene_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([$entry['scene_name'], $keywords, $entry['standard_script'], $entry['tips'], $entry['scene_code']]);
        echo "Updated: {$entry['scene_name']}\n";
    } else {
        $insertSql = "INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([$dim_id, $entry['scene_code'], $entry['scene_name'], $keywords, $entry['standard_script'], $entry['tips'], $sort_order++]);
        echo "Inserted: {$entry['scene_name']}\n";
    }
}

echo "\nBrand manual content updated successfully!\n";
