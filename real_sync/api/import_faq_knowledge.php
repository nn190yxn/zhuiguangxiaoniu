<?php
/**
 * 导入常见疑问Q&A到话术知识库
 * 数据来源: 追光小牛儿童运动常见疑问回答（2023.3月）.pdf
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

echo "Connected to database. Starting import...\n";

// Knowledge dimension entries (dimension_id = 2)
$knowledge_entries = [
    // Brand section
    [
        'scene_code' => 'brand-story',
        'scene_name' => '追光小牛品牌故事',
        'keywords' => json_encode(['品牌', '追光小牛', '哪里', '贵州']),
        'standard_script' => '追光小牛是一家致力于青少年儿童体育教育的连锁机构，是贵州青少年儿童体育教育品牌的领先者。主要针对2-12岁的儿童/青少年的体能和专项训练。快乐体操是我们的特色核心课程。我们的服务包括：快乐体操、儿童体适能、篮球体能、安防体能等。追光小牛目前在贵州地区开设3家校区，服务学员超过20000人次。品牌使命：强壮中国百万家庭。品牌愿景：让运动成为孩子成长的核心竞争力。',
        'tips' => '强调品牌使命和愿景，展示专业性和规模'
    ],
    [
        'scene_code' => 'hlgp-philosophy',
        'scene_name' => 'HLGP教学理念',
        'keywords' => json_encode(['HLGP', '教学理念', '快乐', '成就感']),
        'standard_script' => '追光小牛的HLGP教学理念：1. 我们希望孩子在快乐中获得成就感；2. 我们希望孩子拥有主动学习，不畏困难坚韧的性格；3. 我们希望孩子拥有自我成长的自信；4. 我们希望孩子拥有良好的身体，有更好的身体力量与强大的心灵。',
        'tips' => '用爱与专业帮助孩子与家庭成长'
    ],
    [
        'scene_code' => 'course-goals',
        'scene_name' => '课程目标',
        'keywords' => json_encode(['课程目标', '体魄', '性格', '自信']),
        'standard_script' => '追光小牛的课程目标：1. 强壮的身体体魄 — 通过科学的训练方式，让孩子拥有良好的身体体魄，促进头脑发育，为将来的学习做好准备；2. 坚韧的性格 — 提高孩子的抗挫折能力以及积极乐观的主动性格；3. 独立自信的心态 — 有效的提高孩子的表达以及独立思考，独立行动的能力；4. 学习能力的储备 — 想象力、专注力、规则意识、协调能力等重要的学习能力储备。总结："我能" + "体能" = "学能"',
        'tips' => '强调我能+体能=学能的核心公式'
    ],
    [
        'scene_code' => 'what-is-fitness',
        'scene_name' => '什么是儿童体适能',
        'keywords' => json_encode(['体适能', '是什么', '基础', '运动']),
        'standard_script' => '儿童体适能是孩子身体适应环境的能力，也是进行一切运动的基础。良好的身体体魄，是孩子生活成长与学习的必备基础。根据儿童不同年龄段的体质、运动能力、心理情况，定制专业且具有场景化趣味的运动训练方案。以增强孩子的综合运动能力为主，正向性格心理引导为辅，让孩子在养成正确运动的习惯，并在此过程更好的了解自己，塑造坚韧、独立自信的性格。',
        'tips' => '强调体适能是一切运动的基础'
    ],
    [
        'scene_code' => 'why-fitness',
        'scene_name' => '为什么要进行儿童体适能训练',
        'keywords' => json_encode(['为什么', '体适能', '好处', '发育']),
        'standard_script' => '孩子进行儿童体适能的好处：1. 促进大脑发育以及促进感觉统合的处理能力，让孩子有更强的学习能力以及积极的性格；2. 让孩子掌握对应年龄的运动素质和运动动作技能；3. 让孩子的运动能力得到综合的发展从而更好的迎接专项运动；4. 让孩子不断的突破自我，提高独立性，更加勇敢坚韧，同时提高他的社交能力。',
        'tips' => '从大脑发育、运动技能、社交能力等多角度说明'
    ],
    [
        'scene_code' => 'why-gymnastics',
        'scene_name' => '为什么学龄前孩子适合学体操',
        'keywords' => json_encode(['体操', '学龄前', '适合', '感统']),
        'standard_script' => '学龄前的孩子身体力量、指令、理解能力、沟通能力等还没有发育完全，让孩子去练习专项的运动孩子会很难跟上老师的节奏和动作。快乐体操是去发展孩子身体、头脑、社交这三个维度，让孩子在未来的专项运动和学习中能更好的去理解和跟随老师的节奏，使得孩子能事半功倍。体操作为运动之父，对于孩子的感觉统合能力有明显的提升作用。',
        'tips' => '强调感统发展和为专项运动打基础'
    ],
    [
        'scene_code' => 'age-range',
        'scene_name' => '课程年龄段',
        'keywords' => json_encode(['年龄', '多大', '2岁', '12岁', '孩子']),
        'standard_script' => '我们针对2-12岁的孩子。以儿童生长发育的基本规律为课程基础，以儿童正确运动模式的建立和身体素质提升为教学核心，根据孩子不同的年龄以及不同的运动项目分为多个阶段，通过多样化、趣味化的球类、体能、体操等运动技术的练习，帮助儿童培养良好运动习惯、掌握正确运动方法、养成良好身体姿态及心理健康的运动课程。',
        'tips' => '明确2-12岁全年龄段覆盖'
    ],
    [
        'scene_code' => 'coach-qualification',
        'scene_name' => '教练资质',
        'keywords' => json_encode(['教练', '资质', '专业', '经验']),
        'standard_script' => '追光小牛的教练必须通过公司的严格筛选和培训，老师至少有超过1年的教学经验，同时会优先录取体育专业以及拥有对应项目资格证书的教练。我们的教练都会定期参加公司统一的教学类、儿童心理类、安全知识等培训，确保老师的专业能够不断的更新提升，更好的服务孩子。主课老师全部要求全职。',
        'tips' => '强调专业资质和定期培训'
    ],
    [
        'scene_code' => 'class-size',
        'scene_name' => '班级人数',
        'keywords' => json_encode(['人数', '班级', '几个', '小朋友']),
        'standard_script' => '我们的课堂每节课按照1:6的师资配比，根据不同年龄段，超过一定人数会有2位指导老师（一位主教老师和一位助教老师）。运动是非常需要团体性的，小朋友多也能帮助孩子提高自信心，在教练把控规则感的前提下，人越多运动和学习的氛围会更加好。',
        'tips' => '强调1:6师资配比和专业师资团队'
    ],
    [
        'scene_code' => 'safety',
        'scene_name' => '安全保障',
        'keywords' => json_encode(['安全', '保护', '受伤', '保障']),
        'standard_script' => '安全是追光小牛运动馆最重要的原则。硬件上：使用的是国家体操专业队专用垫，所有运动设备的基座都用海绵或者橡胶包裹；整个教室地面全部铺设有专业垫子。老师都经过专业培训，掌握专业的保护手法。场馆每周、每日都有卫生标准，闭校后有紫外线灯进行消毒。',
        'tips' => '强调专业设备+专业手法+安全环境'
    ],
    [
        'scene_code' => 'height-improvement',
        'scene_name' => '身高提升',
        'keywords' => json_encode(['身高', '长高', '矮', '个子']),
        'standard_script' => '影响身高的因素包括遗传、营养、睡眠和运动。除了遗传因素外，营养方面补充维生素D和钙，多晒太阳；睡眠方面保证8小时的睡眠时间。现在是最重要的长高黄金时期，只要孩子养成科学系统的运动习惯，孩子的身高会呈脉冲式增长。经常运动的孩子比不运动的孩子身高要高出3-5厘米。追光小牛的体能课程通过科学合理的运动方式，根据孩子每个年龄阶段发展运动技能，避免不必要的运动损伤、早熟、肥胖等影响孩子的生长发育。',
        'tips' => '引用科学研究数据说明运动对身高的帮助'
    ],
    [
        'scene_code' => 'weight-management',
        'scene_name' => '体重控制',
        'keywords' => json_encode(['胖', '减肥', '体重', '肥胖']),
        'standard_script' => '孩子身体过重易患上高血压、冠心病、糖尿病等疾病，还可能导致孩子内分泌失调对其生长发育产生抑制作用。肥胖会影响孩子的性格心理问题。从小开始规律系统的运动习惯养成，对于孩子体重控制有非比寻常的意义。儿童期脂肪细胞数量是可以控制的，成年后就定型了。场馆的体能运动课可以消耗多余热能，避免脂肪堆积，有效促进新陈代谢，更可强化骨骼、肌肉、心肺系统。',
        'tips' => '从健康和美观角度说明体重控制的重要性'
    ],
    [
        'scene_code' => 'vestibular-training',
        'scene_name' => '前庭觉训练',
        'keywords' => json_encode(['前庭', '平衡', '平衡感']),
        'standard_script' => '前庭觉训练孩子的平衡感，协调身体与地心引力之间的关系。前庭觉反应不足的小朋友会出现：危险意识低，喜欢爬高爬低，很难静得下来注意学习，缺乏指令感，语言发展较慢，学习秩序性差，注意力无法集中。前庭觉反应过分敏感的小朋友会出现：对高低、速度的变化特别害怕，情绪容易紧张，胆小依赖。前庭觉的训练直接和未来孩子的自信心以及学习的专注力息息相关。',
        'tips' => '解释前庭觉对学习能力和自信心的影响'
    ],
    [
        'scene_code' => 'proprioception-training',
        'scene_name' => '本体觉训练',
        'keywords' => json_encode(['本体觉', '本体感', '身体控制']),
        'standard_script' => '本体觉是孩子的运动能力以及身体的控制能力。本体觉不好的孩子会出现：不喜欢运动，特别是球类活动；计划性差，学习新事物能力差；反应较慢，容易被人欺侮不会抵抗；颈部与背部肌肉张力不足易驼背，喜欢趴在桌上看书；自我驱策力弱，做事较不积极。本体觉的训练本质上就是提高孩子的自信、积极的性格、学习能力以及自我保护的意识。',
        'tips' => '强调本体觉对自信和学习能力的重要性'
    ],
    [
        'scene_code' => 'tactile-training',
        'scene_name' => '触觉训练',
        'keywords' => json_encode(['触觉', '脱鞋', '皮肤']),
        'standard_script' => '人体的最大器官是皮肤，帮助我们感受变化和危险。现在的小朋友生活环境比较单一，缺乏触觉训练会导致：过份防御，情绪不稳定容易莫名哭闹、动怒；偏食、挑食，语言发展慢；在长大以后，不太愿意学习新鲜事物，情绪也不太稳定，容易放弃。体操课程中，脱鞋训练以及针对性的动作，就是有效提高孩子触觉感知能力的方法。',
        'tips' => '解释触觉训练对情绪和语言发展的影响'
    ],
    [
        'scene_code' => 'flexibility-training',
        'scene_name' => '柔韧性训练',
        'keywords' => json_encode(['柔韧', '拉伸', '僵硬']),
        'standard_script' => '柔韧性首先能够让孩子更容易坚持学习。身体肩颈酸疼会影响工作效率，孩子也是一样的。另外，孩子的柔韧性强，可以更好地控制或形成身体姿势，也可以防止在孩子玩耍运动的时候，将受伤的机会降至最低。',
        'tips' => '从学习效率和安全性角度说明'
    ],
    [
        'scene_code' => 'fitness-vs-specialty',
        'scene_name' => '体能训练与专项运动的区别',
        'keywords' => json_encode(['体能', '专项', '篮球', '轮滑', '区别']),
        'standard_script' => '当孩子没有良好运动基础的情况下，长期进行单一的专项运动，容易造成不可逆的运动损伤，甚至影响发育中的骨骼生长。6岁以下的小朋友往往存在下肢力量不足的问题，骨骼尚未发育完全。体能训练是所有运动的必修课程，是一切运动的基础。当孩子有了良好的运动基础，再练习专项运动，就能达到事倍功半的效果。',
        'tips' => '强调体能是专项运动的基础'
    ],
    [
        'scene_code' => 'gymnastics-vs-today',
        'scene_name' => '体操与早教的区别',
        'keywords' => json_encode(['体操', '早教', '金宝贝', '美吉姆', '区别']),
        'standard_script' => '追光小牛运动馆的目标是通过运动、种类丰富的韵律音乐、想象力游戏和集体活动，增强孩子的身体素质，促进智力发展，塑造坚韧、积极的性格，提高孩子的自信与独立性。追光小牛的课程老师会给每个小朋友个别、正面的鼓励，同时会告诉孩子下次怎么做会更好，帮助孩子循序渐进地进步。追光小牛的技能是有升降阶的，从易到难，真正帮助孩子掌握获取技能，体验到成就感。',
        'tips' => '强调循序渐进和成就感培养'
    ],
];

// Insert knowledge entries
$dim_id = 2; // knowledge dimension
$sort_order = 100;

foreach ($knowledge_entries as $entry) {
    $keywords = is_string($entry['keywords']) ? $entry['keywords'] : json_encode($entry['keywords']);

    $stmt = $db->prepare("
        INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, tips, sort_order, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE scene_name = VALUES(scene_name), keywords = VALUES(keywords),
            standard_script = VALUES(standard_script), tips = VALUES(tips)
    ");
    $stmt->execute([
        $dim_id,
        $entry['scene_code'],
        $entry['scene_name'],
        $keywords,
        $entry['standard_script'],
        $entry['tips'],
        $sort_order++
    ]);
    echo "Inserted/Updated: " . $entry['scene_name'] . "\n";
}

echo "\nKnowledge entries imported: " . count($knowledge_entries) . "\n";
echo "Import completed!\n";
