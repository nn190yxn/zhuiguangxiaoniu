<?php
/**
 * 导入销售话术Q&A到话术知识库
 * 数据来源: 追光小牛儿童运动常见疑问回答（2023.3月）.pdf - 销售篇
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

echo "Connected to database. Starting Q&A import...\n";

// Q&A entries for sales objections (dimension_id = 1)
$qa_entries = [
    [
        'scene_code' => 'child-not-interested',
        'scene_name' => '孩子不喜欢，等有兴趣再来',
        'keywords' => json_encode(['不喜欢', '没兴趣', '等一等']),
        'standard_script' => '家长您好，首先运动对于孩子来说，不是一个选项，而是一个必须项。孩子提高体质，有好的身体，才能未来适应激烈的学习环境，体育中考现在分值越来越重，也充分说明国家对于孩子身体体魄的重视。同时，运动也能让孩子有更坚韧的性格，更加的独立自信。您不会看到任何一个爱运动的人是消极、孤僻抑郁的，运动是一个必须项且是终身的投资。',
        'customer_intent_signals' => json_encode([
            'high' => ['那先报一期试试', '多少钱一节课'],
            'low' => ['不需要', '算了'],
            'medium' => ['我再想想', '回去商量一下']
        ]),
        'tips' => '强调运动的必需性和长期价值'
    ],
    [
        'scene_code' => 'price-too-high',
        'scene_name' => '价格太高',
        'keywords' => json_encode(['太贵', '价格高', '便宜点']),
        'standard_script' => '首先非常感谢对追光小牛课程的认可，我们品牌是贵州省儿童运动的领先品牌。追光小牛是一家连锁品牌，在选址环境（租金）、师资、设备以及教研上我们的投入都会比个体户门店高许多。价格是一方面，但是品质是更重要的，我们的价格绝对是性价比的。而我们的课程体系不仅仅是关于孩子体能上的提升，我们更加关注孩子在性格塑造，让孩子养成坚韧的性格，并且更加独立自信。',
        'customer_intent_signals' => json_encode([
            'high' => ['那有什么优惠', '能便宜多少'],
            'low' => ['太贵了', '不需要'],
            'medium' => ['有点贵', '考虑一下']
        ]),
        'tips' => '强调品质和品牌价值，而非单纯价格'
    ],
    [
        'scene_code' => 'need-consult-husband',
        'scene_name' => '要回去问老公',
        'keywords' => json_encode(['问老公', '商量', '回家想想']),
        'standard_script' => 'XX妈妈，您一看就是一位既重视孩子的教育，又非常照顾老公感受的好妈妈。不过有几个问题，您给老公介绍咱们课程的时候，能像我一样讲述得完整详细吗？这可能会影响老公的判断。您问老公的意见无非就是想综合考量权衡一下，不妨您先给孩子报，让孩子先学着。等老公有时间了再告诉他，如果他本来就会同意的话，那这就算是一个惊喜。如果他有不同的意见，可以等孩子上课时让他送过来，到时候我再跟他详细介绍。',
        'customer_intent_signals' => json_encode([
            'high' => ['那我先报', '你跟他说'],
            'low' => ['还是等他同意'],
            'medium' => ['我回去问问']
        ]),
        'tips' => '帮助家长做决定，消除顾虑'
    ],
    [
        'scene_code' => 'distance-far',
        'scene_name' => '距离远，老人接送不便',
        'keywords' => json_encode(['距离远', '太远', '接送']),
        'standard_script' => '家长非常理解，平日工作就不容易还要接送。我们机构也有很多是在10公里外过来上课的，也有很多一周上三节课的。那您觉得他们为什么会选择这么久都坚持下来？说明我们的课程家长是认可的，他们觉得花这点时间让孩子体质上得到增强、体能上得到变化、性格上得到塑造是值得的。既然选择让宝贝学，肯定选择最专业、最有保障的。',
        'customer_intent_signals' => json_encode([
            'high' => ['也是', '有道理'],
            'low' => ['太远了'],
            'medium' => ['考虑考虑']
        ]),
        'tips' => '用其他家长的坚持案例说服'
    ],
    [
        'scene_code' => 'father-disagree',
        'scene_name' => '爸爸不同意',
        'keywords' => json_encode(['爸爸', '老公', '不同意', '觉得没必要']),
        'standard_script' => '相信爸爸一定非常爱孩子，只要是对孩子成长有帮助的课程，一定愿意让孩子参加。但今天爸爸没来，可能还不太了解课程对孩子的益处。因为这个班级固定学位不多了，可以先给孩子定下来。下回可以让爸爸来一趟校区，我非常愿意跟爸爸分享体育教育的理念，到时候爸爸一定会欣赏妈妈您做得明智的决定！',
        'customer_intent_signals' => json_encode([
            'high' => ['那我先定下来', '下次带他来'],
            'low' => ['等他同意再说'],
            'medium' => ['我再想想']
        ]),
        'tips' => '赞美妈妈的决定，同时邀请爸爸来了解'
    ],
    [
        'scene_code' => 'how-long-effect',
        'scene_name' => '多久有效果',
        'keywords' => json_encode(['多久', '效果', '见效']),
        'standard_script' => '运动是一件需要持续坚持的事情，一般孩子2-3个月就有一个明显的初步变化，可能是身体体质方面的、运动素质方面的提升，包括掌握一些基本的运动技能。在这期间，孩子的性格、心理也会有很积极的变化，比如说孩子变得更有规则意识、更加自信、更加积极阳光、更愿意跟小朋友沟通协作。在追光小牛，每一个运动项目都有对应的课程体系，定期的考级和测试让孩子以及家长明确知道不同学习周期对应的学习效果。',
        'customer_intent_signals' => json_encode([
            'high' => ['那就报名', '先试一下'],
            'low' => ['太久了'],
            'medium' => ['考虑考虑']
        ]),
        'tips' => '给出具体时间预期，建立合理期望'
    ],
    [
        'scene_code' => 'too-much-study',
        'scene_name' => '学太多东西了，不想孩子太累',
        'keywords' => json_encode(['太累', '学太多', '压力大']),
        'standard_script' => '妈妈给孩子选择了那么多的课程，说明妈妈对孩子有着强烈的教育意识。我们这里的课程都是以孩子享受运动的快乐为主，会融入很多的游戏在里面。专业有趣的运动课不仅能够帮助孩子增强体质、提升免疫力，还能很好的释放孩子因为过多的学习带来的压抑情绪。良好的体魄，才能让孩子更好的应对繁忙的学业。运动课已经是孩子成长的刚需了。',
        'customer_intent_signals' => json_encode([
            'high' => ['也是', '有道理'],
            'low' => ['还是算了'],
            'medium' => ['我再想想']
        ]),
        'tips' => '将运动定位为放松而非负担'
    ],
    [
        'scene_code' => 'school-pe-enough',
        'scene_name' => '学校有体育课，不需要额外报',
        'keywords' => json_encode(['体育课', '学校', '不需要']),
        'standard_script' => '学校体育课因为是大班课教学，通常训练项目不全面，没有针对性、趣味性低，很多孩子在学校的体育课中表现不好，甚至会丧失对于运动的兴趣，从而失去自信心。而我们追光小牛本身围绕着孩子生长发育的周期特点，不断研发具有趣味性、也有挑战感、成就感的课程。小班制能够更好的关注孩子，及时调整训练方案。',
        'customer_intent_signals' => json_encode([
            'high' => ['确实', '有道理'],
            'low' => ['学校就够了'],
            'medium' => ['考虑一下']
        ]),
        'tips' => '对比学校体育课的局限性'
    ],
    [
        'scene_code' => 'already-has-other-class',
        'scene_name' => '已经在学舞蹈/跆拳道了',
        'keywords' => json_encode(['舞蹈', '跆拳道', '已经有', '不需要']),
        'standard_script' => '家长，根据3到12岁孩子的发育特点，体能课最适合孩子，也有助于孩子的生长发育。过早的接触专项课程，一方面容易让孩子受伤，另一方面因为缺乏体能基础，孩子学习的较慢，不容易建立自信心。同时大部分的专项运动都是单边运动，容易造成孩子体态发生问题。我们的课程是所有运动最基础和最核心的训练，是不冲突的。如果拿吃饭来比喻，舞蹈、跆拳道这类课程就是菜，体能课程就是米饭。',
        'customer_intent_signals' => json_encode([
            'high' => ['有道理', '那可以一起学'],
            'low' => ['不需要了'],
            'medium' => ['考虑一下']
        ]),
        'tips' => '用比喻说明体能是基础'
    ],
    [
        'scene_code' => 'worry-child-cannot-persist',
        'scene_name' => '担心孩子坚持不下来',
        'keywords' => json_encode(['坚持', '坚持不下来', '退']),
        'standard_script' => '妈妈是担心孩子坚持不下来，是么？很能理解妈妈的担忧。关于坚持，是和目标和过程中的分解有关系的。我们的课程有对应的时间段和对应的阶段目标。1. 老师的授课是否有趣味、形式、教师本人的影响力都很重要，决定孩子的兴趣点；2. 过程中家校都要清晰孩子在这里学习的长期目标；3. 一旦孩子进入认真期，自然就入门了。孩子有进步，家长有信心有方法，学校有监督，三方紧密结合，一定是可以坚持的。',
        'customer_intent_signals' => json_encode([
            'high' => ['有道理', '那就报名'],
            'low' => ['还是算了'],
            'medium' => ['我再想想']
        ]),
        'tips' => '分解坚持的要素，建立信心'
    ],
    [
        'scene_code' => 'frequently-absent',
        'scene_name' => '家长频繁请假',
        'keywords' => json_encode(['请假', '经常不来', '缺课']),
        'standard_script' => 'XX妈妈，首先感谢您每次都给我打个招呼请假，没有让小明直接旷课。咱把孩子送过来培训，毕竟也花了不少钱，想要孩子能够训练出效果。经常请假容易导致孩子的培训进度跟不上去，一旦拉开的差距比较大之后，对于孩子的培训兴趣和培训自信心肯定会有影响。另外，也容易让他产生不管做任何事情原来都是可以随意请假的错觉，不利于孩子养成坚持的好品质以及坚韧的性格。',
        'customer_intent_signals' => json_encode([
            'high' => ['好的', '我注意一下'],
            'low' => ['没办法'],
            'medium' => ['知道了']
        ]),
        'tips' => '温和提醒长期坚持的重要性'
    ],
    [
        'scene_code' => 'child-not-want-renew',
        'scene_name' => '孩子不想学了，不续费',
        'keywords' => json_encode(['不续费', '不想学', '放弃了']),
        'standard_script' => 'XX妈妈，其实这种学了一段时间就不想学了的情况在很多孩子身上都有所体现，这很正常。运动本身就是孩子成长的刚需，运动能力的高低甚至影响学习的能力。养成运动习惯，也能帮助孩子变得更优秀。我们现在应该一起携手彼此配合，多跟孩子沟通，多去鼓励他，多去引导他，帮他排解掉这种厌学情绪。其实之前孩子有过这方面的表现，我们都帮他很好的调整过来了。',
        'customer_intent_signals' => json_encode([
            'high' => ['那再试试', '好吧'],
            'low' => ['算了'],
            'medium' => ['我再想想']
        ]),
        'tips' => '理解孩子，给出鼓励和支持'
    ],
    [
        'scene_code' => 'closing-techniques',
        'scene_name' => '关单话术',
        'keywords' => json_encode(['关单', '成交', '报名']),
        'standard_script' => '直接关单：您这边没有其他问题，咱们今天办一下手续吧！您是刷卡还是支付宝呢？假定成交：目前适合宝贝这个年龄段有周二周四周日这几个班，您看看你们哪个时间方便。"保证"关单：孩子交给我们，您放心。相信我们，一定会让您觉得物超所值，学得好了多帮我推荐些朋友过来！"造梦"关单：咱们宝贝现在开始锻炼起来，半年后体质比同龄小朋友好，其他妈妈在为孩子经常感冒发烧跑医院的时候，您不用，要省多少心，省多少时间，孩子也少遭多少罪啊！',
        'customer_intent_signals' => json_encode([
            'high' => ['刷卡', '支付宝'],
            'low' => ['再考虑'],
            'medium' => ['哪个时间']
        ]),
        'tips' => '根据家长类型选择合适的关单方式'
    ],
];

// Insert Q&A entries
$dim_id = 1; // qa dimension
$sort_order = 100;

foreach ($qa_entries as $entry) {
    $keywords = is_string($entry['keywords']) ? $entry['keywords'] : json_encode($entry['keywords']);
    $intent = isset($entry['customer_intent_signals']) ?
        (is_string($entry['customer_intent_signals']) ? $entry['customer_intent_signals'] : json_encode($entry['customer_intent_signals'])) : null;

    $stmt = $db->prepare("
        INSERT INTO script_knowledge (dimension_id, scene_code, scene_name, keywords, standard_script, customer_intent_signals, tips, sort_order, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE scene_name = VALUES(scene_name), keywords = VALUES(keywords),
            standard_script = VALUES(standard_script), customer_intent_signals = VALUES(customer_intent_signals), tips = VALUES(tips)
    ");
    $stmt->execute([
        $dim_id,
        $entry['scene_code'],
        $entry['scene_name'],
        $keywords,
        $entry['standard_script'],
        $intent,
        $entry['tips'],
        $sort_order++
    ]);
    echo "Inserted/Updated: " . $entry['scene_name'] . "\n";
}

echo "\nQ&A entries imported: " . count($qa_entries) . "\n";
echo "Import completed!\n";
