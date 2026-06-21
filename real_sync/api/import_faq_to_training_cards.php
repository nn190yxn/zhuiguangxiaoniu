<?php
/**
 * 将FAQ数据融合到培训卡片 training_cards
 * 按 K-知识点、S-话术卡、D-演练卡、C-通关卡 分类
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

echo "Connected. Starting FAQ to Training Cards import...\n";

// 模块ID映射
$moduleIds = [
    'mod-brand' => 1,        // 品牌与产品认知
    'mod-reception' => 2,     // 首次到店接待
    'mod-assessment' => 3,   // 体测评估技能
    'mod-trial' => 4,        // 体验课转化
    'mod-communication' => 5,// 家长沟通话术
    'mod-renewal' => 6,      // 续费与转介绍
    'mod-management' => 7,   // 门店日常管理
];

// FAQ数据，按模块和卡片类型分类
$faqCards = [
    // mod-brand: 品牌与产品认知
    [
        'module_code' => 'mod-brand',
        'card_type' => 'K',
        'card_code' => 'K-brand-001',
        'title' => '追光小牛品牌故事',
        'content' => "追光小牛是一家致力于青少年儿童体育教育的连锁机构，是贵州青少年儿童体育教育品牌的领先者。主要针对2-12岁的儿童/青少年的体能和专项训练。\n\n品牌使命：强壮中国百万家庭\n品牌愿景：让运动成为孩子成长的核心竞争力\n品牌差异化：快乐体操课程/儿童感统运动课程体系/儿童体智能\n品牌口号：强壮体魄、坚韧性格、自信独立\n\n目前贵州地区开设3家校区，服务学员超过20000人次。",
        'tips' => '熟记品牌使命和愿景，能够流利地向家长介绍品牌故事'
    ],
    [
        'module_code' => 'mod-brand',
        'card_type' => 'K',
        'card_code' => 'K-brand-002',
        'title' => 'HLGP教学理念',
        'content' => "追光小牛的HLGP教学理念：\n\nH-Happy（快乐）：我们希望孩子在快乐中获得成就感\nL-Love（关爱）：我们希望孩子拥有主动学习，不畏困难坚韧的性格\nG-Growth（成长）：我们希望孩子拥有自我成长的自信\nP-Professional（专业）：我们希望孩子拥有良好的身体，有更好的身体力量与强大的心灵\n\n核心理念：\"我能\" + \"体能\" = \"学能\"",
        'tips' => '理解HLGP每个字母的含义，能够向家长解释教学理念'
    ],
    [
        'module_code' => 'mod-brand',
        'card_type' => 'K',
        'card_code' => 'K-brand-003',
        'title' => '课程体系介绍',
        'content' => "追光小牛课程体系分为四大板块：\n\n1. 感统训练（3-6岁）- 促进感觉统合能力发展\n2. 体能训练（6-9岁）- 提升基础运动素质\n3. 技能训练（9-12岁）- 学习专项运动技能\n4. 体测达标（各年龄段）- 针对学校体测进行专项训练\n\n根据孩子年龄选择合适课程板块，科学分班，因材施教。",
        'tips' => '根据孩子年龄推荐合适的课程阶段'
    ],
    [
        'module_code' => 'mod-brand',
        'card_type' => 'S',
        'card_code' => 'S-brand-001',
        'title' => '品牌介绍话术',
        'content' => "家长您好！追光小牛是一家专业做儿童体能培训的连锁机构。我们不是那种综合早教，而是专注做体能和感统训练。\n\n我们的特色是快乐体操课程，区别于其他机构的关键是：\n1. 我们有专业的教练团队，定期培训考核\n2. 我们有完善的课程体系，根据孩子年龄科学分班\n3. 我们小班教学，1:6的师资配比\n\n很多家长选择我们，是因为我们有三甲医院运动康复科背景的教练团队，还有自主研发的课程体系。",
        'tips' => '强调专业性和差异化优势，先建立信任再介绍课程'
    ],
    [
        'module_code' => 'mod-brand',
        'card_type' => 'S',
        'card_code' => 'S-brand-002',
        'title' => '解答价格疑问',
        'content' => "我们的课程是按课时包来收费的，根据您选择的不同阶段和时长，套餐从XX元到XX元不等。\n\n比起市场上同类课程，我们的性价比是非常高的，因为：\n1. 我们的教练都有专业资质，定期内训\n2. 课程都是自主研发的，每个孩子都有独立的训练档案\n3. 1:6的师资配比，确保每个孩子得到足够关注\n\n先建立价值感，再谈价格。准备好优惠政策适时推出。",
        'tips' => '先讲价值，再提价格；准备好优惠政策'
    ],
    [
        'module_code' => 'mod-brand',
        'card_type' => 'C',
        'card_code' => 'C-brand-001',
        'title' => '品牌知识考核',
        'content' => "请检查以下知识点是否掌握：\n\n1. 追光小牛专注哪个年龄段？（2-12岁）\n2. 追光小牛的四大课程板块是什么？\n3. 品牌使命是什么？\n4. HLGP教学理念各字母代表什么？\n5. 相比早教机构的差异点是什么？\n6. 1:6师资配比是什么意思？\n\n全部掌握才能通过。",
        'tips' => '品牌知识是顾问的基础，必须全部掌握'
    ],

    // mod-trial: 体验课转化
    [
        'module_code' => 'mod-trial',
        'card_type' => 'K',
        'card_code' => 'K-trial-001',
        'title' => '体验课流程与价值',
        'content' => "追光小牛体验课流程：\n\n1. 预约确认（预约后2h内）\n2. 建立服务群（预约后4h内）\n3. 到访前1天确认\n4. 到店迎接+签到\n5. 环境介绍+需求沟通（5min内）\n6. 体验课（45-60min）\n7. 课后反馈+方案推荐（10min内）\n8. 当日成交交接/未成交分流\n\n体验课价值：让孩子感受专业教学，建立信任基础。",
        'tips' => '体验课是转化的关键环节，每个步骤都不能省略'
    ],
    [
        'module_code' => 'mod-trial',
        'card_type' => 'S',
        'card_code' => 'S-trial-001',
        'title' => '体验课邀约话术',
        'content' => "XX妈妈，您好！之前您咨询过我们的课程，非常感谢您的信任。\n\n我想跟您预约一个体验课名额，让宝宝来感受一下我们的专业课程。体验课是30-45分钟，由我们的主教老师亲自带课，让宝宝体验一下我们的训练方式和专业氛围。\n\n体验课是完全免费的，您可以放心带孩子来尝试。\n\n请问这周XX时间方便吗？我来帮您预约。",
        'tips' => '强调免费，消除顾虑；预约具体时间而非泛泛'
    ],
    [
        'module_code' => 'mod-trial',
        'card_type' => 'S',
        'card_code' => 'S-trial-002',
        'title' => '价格异议处理',
        'content' => "家长，我非常理解您对价格的关注。\n\n我们课程的价值在于：\n1. 专业师资：所有教练都经过严格培训，1:6的配比\n2. 科学课程：根据孩子年龄和发育特点设计的课程体系\n3. 安全保障：专业设备和保护手法\n4. 效果可见：定期体测，让您看到孩子的进步\n\n现在报名有XX优惠，可以先报一个短期套餐让孩子体验效果。",
        'tips' => '先建立价值感，再谈价格；准备好优惠政策'
    ],
    [
        'module_code' => 'mod-trial',
        'card_type' => 'S',
        'card_code' => 'S-trial-003',
        'title' => '对比其他机构',
        'content' => "我们和其他机构最大的区别有三点：\n\n第一，我们是专业做儿童体能培训的，有完整的课程体系和资质认证；\n\n第二，我们的教练都是经过专业培训的运动康复或体育教育背景；\n\n第三，我们会为每个孩子建立独立的成长档案，全程跟踪训练效果。\n\n这是我们和其他综合类早教机构最大的差异化优势。",
        'tips' => '突出专业性和差异化，不要贬低同行'
    ],
    [
        'module_code' => 'mod-trial',
        'card_type' => 'D',
        'card_code' => 'D-trial-001',
        'title' => '体验课转化演练',
        'content' => "场景：家长带孩子体验完体验课，孩子表现不错，但家长说\"我再考虑一下\"。\n\n请模拟：\n1. 如何观察孩子体验课的表现，找到切入点\n2. 如何与家长沟通，了解顾虑\n3. 如何推荐课程套餐\n4. 如何促成成交\n\n准备2-3种不同情况的应对方案。",
        'tips' => '演练后AI评分，重点练习逼单环节'
    ],
    [
        'module_code' => 'mod-trial',
        'card_type' => 'C',
        'card_code' => 'C-trial-001',
        'title' => '体验课转化通关项',
        'content' => "请检查以下服务是否做到：\n\n1. 体验课前是否确认预约\n2. 到店是否及时迎接\n3. 是否向家长介绍课程价值而非只讲价格\n4. 孩子体验时是否关注每个孩子的表现\n5. 课后是否给出专业反馈\n6. 是否推荐了适合的课程套餐\n7. 是否有明确的成交动作\n\n全部做到才能通过。",
        'tips' => '每个环节都是转化关键，不能跳过'
    ],

    // mod-communication: 家长沟通话术
    [
        'module_code' => 'mod-communication',
        'card_type' => 'K',
        'card_code' => 'K-comm-001',
        'title' => '儿童体适能基础知识',
        'content' => "儿童体适能是孩子身体适应环境的能力，也是进行一切运动的基础。\n\n为什么要进行儿童体适能：\n1. 促进大脑发育和感觉统合处理能力\n2. 让孩子掌握对应年龄的运动素质和运动动作技能\n3. 让孩子运动能力综合发展，更好迎接专项运动\n4. 培养独立性、勇敢坚韧，提高社交能力\n\n体适能是一切运动的基础。",
        'tips' => '这是与家长沟通专业性的基础'
    ],
    [
        'module_code' => 'mod-communication',
        'card_type' => 'K',
        'card_code' => 'K-comm-002',
        'title' => '感统训练知识',
        'content' => "感统训练包括三大方面：\n\n前庭觉：平衡感，协调身体与地心引力\n- 前庭觉不足：好动、注意力不集中、平衡差\n- 前庭觉过度：胆小、依赖、紧张\n\n本体觉：身体控制能力，运动能力\n- 本体觉不足：计划性差、学习新事物慢\n\n触觉：皮肤感知能力\n- 触觉不足：情绪不稳定、偏食挑食\n\n体操训练是感统训练的有效方式。",
        'tips' => '用通俗语言向家长解释感统概念'
    ],
    [
        'module_code' => 'mod-communication',
        'card_type' => 'S',
        'card_code' => 'S-comm-001',
        'title' => '课后反馈话术',
        'content' => "XX妈妈，今天宝宝在体测中表现非常好！\n\n他在平衡木上走了15步才掉下来，这个年龄段能达到这个水平很不错。\n\n稍微需要加强的是他的专注力，在持续注意力方面还有提升空间。不过别担心，这些都是可以通过训练来改善的。\n\n建议回家后可以多带孩子进行一些平衡类的游戏，比如走马路牙子、荡秋千等。",
        'tips' => '先说亮点，再说建议；给出具体可操作的家庭建议'
    ],
    [
        'module_code' => 'mod-communication',
        'card_type' => 'S',
        'card_code' => 'S-comm-002',
        'title' => '孩子不喜欢运动',
        'content' => "家长您好，孩子不喜欢运动的原因可能有几种：\n\n1. 父母自己是否喜欢运动？有没有给孩子正确的引导？\n2. 给孩子接触的运动难度是否过大，打击了孩子的自信？\n3. 孩子是否有足够的运动机会？\n\n建议：给孩子选择适合年龄和能力的运动，从简单的开始，培养兴趣和成就感。",
        'tips' => '先了解原因，再给出针对性建议'
    ],
    [
        'module_code' => 'mod-communication',
        'card_type' => 'S',
        'card_code' => 'S-comm-003',
        'title' => '家长频繁请假处理',
        'content' => "XX妈妈，首先感谢您每次都给我打招呼请假，没有让小明直接旷课。\n\n咱把孩子送过来培训，毕竟也花了不少钱，想要孩子能够训练出效果。经常请假容易导致孩子的培训进度跟不上，对孩子的兴趣和自信心都会有影响。\n\n另外，也容易让他产生不管做任何事情都是可以随意请假的错觉，不利于孩子养成坚持的好品质。\n\n希望得到您的支持，尽量不缺勤。",
        'tips' => '温和但坚定地提醒坚持的重要性'
    ],
    [
        'module_code' => 'mod-communication',
        'card_type' => 'D',
        'card_code' => 'D-comm-001',
        'title' => '家长沟通演练',
        'content' => "场景：家长反映孩子最近训练积极性下降，经常说不想去上课。\n\n请模拟：\n1. 如何与家长沟通了解孩子的情况\n2. 如何给出专业的分析和建议\n3. 如何与家长配合帮助孩子重新建立兴趣\n4. 如何保持与家长的长期良好关系",
        'tips' => '沟通是长期关系维护的关键'
    ],
    [
        'module_code' => 'mod-communication',
        'card_type' => 'C',
        'card_code' => 'C-comm-001',
        'title' => '家长沟通通关项',
        'content' => "请检查以下沟通要点是否做到：\n\n1. 课后是否及时给出专业反馈\n2. 反馈是否先说亮点再说建议\n3. 是否给出具体的家庭配合建议\n4. 家长有问题时是否及时回应\n5. 是否定期与家长沟通孩子进步\n6. 面对投诉是否保持专业态度\n\n全部做到才能通过。",
        'tips' => '家长沟通是服务的重要环节'
    ],

    // mod-renewal: 续费与转介绍
    [
        'module_code' => 'mod-renewal',
        'card_type' => 'K',
        'card_code' => 'K-renewal-001',
        'title' => '续费时机与策略',
        'content' => "续费最佳时机：\n\n1. 孩子取得明显进步时 - 趁热打铁\n2. 课程即将到期前2周 - 提前预警\n3. 孩子升班时 - 新阶段新目标\n4. 周年庆/优惠活动时 - 政策利好\n\n续费策略：\n1. 提前做好续费预警\n2. 展示孩子的进步和成长档案\n3. 适时推出优惠政策\n4. 强调连续性的重要性",
        'tips' => '续费是业绩的重要来源，要主动管理'
    ],
    [
        'module_code' => 'mod-renewal',
        'card_type' => 'S',
        'card_code' => 'S-renewal-001',
        'title' => '续费沟通话术',
        'content' => "XX妈妈，转眼宝宝在我们这里学习已经快一年了。\n\n您看这是孩子的成长档案，孩子从刚开始的平衡木只能走3步，到现在能走15步，专注力也提高了很多。\n\n孩子的进步是肉眼可见的。课程即将到期了，考虑到孩子的成长连续性，建议继续跟着我们的课程学习。\n\n现在正好有XX优惠，可以先定下来保留优惠。",
        'tips' => '用成长档案说话，强调进步和连续性'
    ],
    [
        'module_code' => 'mod-renewal',
        'card_type' => 'S',
        'card_code' => 'S-renewal-002',
        'title' => '转介绍话术',
        'content' => "XX妈妈，非常感谢您对我们的认可！\n\n您的宝宝在我们这里进步这么大，我也希望让更多的小朋友有机会体验我们的课程。\n\n如果您身边有朋友的孩子有类似的需求，欢迎推荐过来。您推荐的朋友报名成功后，我们会给予您XX奖励，同时朋友也能享受首次报名优惠。\n\n这是双赢的事情，感谢您支持！",
        'tips' => '真诚推荐，不要过于功利'
    ],
    [
        'module_code' => 'mod-renewal',
        'card_type' => 'S',
        'card_code' => 'S-renewal-003',
        'title' => '孩子不想续费处理',
        'content' => "XX妈妈，其实这种学了一段时间就不想学了的情况在很多孩子身上都有所体现，这很正常。\n\n运动本身就是孩子成长的刚需。养成运动习惯，必然能让孩子变得更优秀。\n\n我们应该一起携手配合，多跟孩子沟通，多鼓励多引导，帮他排解厌学情绪。\n\n之前XX也有过这方面的表现，我们都帮他调整过来了，相信这次也可以。",
        'tips' => '理解孩子，给出信心和支持'
    ],
    [
        'module_code' => 'mod-renewal',
        'card_type' => 'D',
        'card_code' => 'D-renewal-001',
        'title' => '续费谈判演练',
        'content' => "场景：课程即将到期，家长说\"孩子最近不太想学了，先不续了\"。\n\n请模拟：\n1. 如何了解家长和孩子的真实顾虑\n2. 如何用成长档案展示孩子的进步\n3. 如何与家长一起制定继续学习的计划\n4. 如何处理家长的价格顾虑\n5. 如何最终促成续费",
        'tips' => '续费是最考验沟通能力的环节'
    ],
    [
        'module_code' => 'mod-renewal',
        'card_type' => 'C',
        'card_code' => 'C-renewal-001',
        'title' => '续费工作通关项',
        'content' => "请检查续费工作是否到位：\n\n1. 是否在课程到期前2周做好预警\n2. 是否准备好每个孩子的成长档案\n3. 是否了解家长的顾虑并提前准备应对\n4. 是否及时推出优惠政策\n5. 是否有明确的续费目标和时间节点\n\n全部做到才能通过。",
        'tips' => '续费工作要提前规划，主动出击'
    ],

    // mod-management: 门店日常管理
    [
        'module_code' => 'mod-management',
        'card_type' => 'K',
        'card_code' => 'K-mgmt-001',
        'title' => '开店检查流程',
        'content' => "开店检查12项必检：\n\n1. 教室温度是否适宜（24-26度）\n2. 教具是否完好无损\n3. 地面是否干燥清洁\n4. 消毒是否完成\n5. 更衣室是否整理\n6. 洗手间是否清洁\n7. 签到台是否准备就绪\n8. 课程教案是否打印\n9. 师资是否到齐\n10. 家长等候区是否整洁\n11. 音乐设备是否正常\n12. 紧急药品是否齐全",
        'tips' => '开店检查是每天必须完成的工作'
    ],
    [
        'module_code' => 'mod-management',
        'card_type' => 'K',
        'card_code' => 'K-mgmt-002',
        'title' => '关店检查流程',
        'content' => "关店检查10项必检：\n\n1. 确认所有学员已离校\n2. 检查教具是否归位\n3. 地面清洁消毒\n4. 教具设备归位检查\n5. 确认门窗已锁\n6. 确认水电气已关\n7. 垃圾是否清理\n8. 更衣室检查\n9. 今日课程记录归档\n10. 明日课程准备",
        'tips' => '关店检查确保安全，为明天做好准备'
    ],
    [
        'module_code' => 'mod-management',
        'card_type' => 'S',
        'card_code' => 'S-mgmt-001',
        'title' => '客户投诉处理',
        'content' => "客户投诉处理原则：\n\n1. 认真倾听 - 不打断，表示理解\n2. 真诚道歉 - 不推卸责任\n3. 快速响应 - 当场能解决就当场解决\n4. 跟踪反馈 - 解决后主动回访\n5. 总结改进 - 避免同类问题再次发生\n\n案例：孩子受伤时，首先检查伤情，真诚道歉说明原因，承诺改进，事后跟踪回访。",
        'tips' => '投诉是改进的机会，要妥善处理'
    ],
    [
        'module_code' => 'mod-management',
        'card_type' => 'D',
        'card_code' => 'D-mgmt-001',
        'title' => '紧急情况处理演练',
        'content' => "场景：孩子在上课过程中意外受伤，家长情绪激动。\n\n请模拟：\n1. 如何第一时间处理孩子伤情\n2. 如何与家长沟通\n3. 如何按照公司流程处理\n4. 如何做好后续跟进\n\n准备多种紧急情况的应对方案。",
        'tips' => '安全无小事，要熟练掌握应急流程'
    ],
    [
        'module_code' => 'mod-management',
        'card_type' => 'C',
        'card_code' => 'C-mgmt-001',
        'title' => '店长日常工作检查',
        'content' => "店长日周月工作检查：\n\n日：\n□ 晨会是否召开\n□ 员工状态是否良好\n□ 课程是否正常进行\n□ 家长反馈是否记录\n\n周：\n□ 周数据是否分析\n□ 员工培训是否完成\n□ 安全隐患是否排查\n□ 续费名单是否更新\n\n月：\n□ 业绩是否达标\n□ 团队是否稳定\n□ 运营问题是否总结\n□ 下月计划是否制定",
        'tips' => '店长要有全局观，善于统筹管理'
    ],
];

// 导入卡片
$imported = 0;
$updated = 0;

foreach ($faqCards as $card) {
    $moduleId = $moduleIds[$card['module_code']] ?? 0;
    if (!$moduleId) {
        echo "Unknown module: {$card['module_code']}\n";
        continue;
    }

    // 检查是否已存在
    $checkSql = "SELECT id FROM training_cards WHERE card_code = ?";
    $stmt = $db->prepare($checkSql);
    $stmt->execute([$card['card_code']]);
    $existing = $stmt->fetch();

    $difficulty = $card['card_type'] === 'K' ? 'easy' : ($card['card_type'] === 'D' ? 'hard' : 'medium');

    if ($existing) {
        // 更新
        $updateSql = "UPDATE training_cards SET title = ?, content = ?, tips = ?, difficulty = ? WHERE card_code = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([$card['title'], $card['content'], $card['tips'], $difficulty, $card['card_code']]);
        $updated++;
        echo "Updated: [{$card['card_type']}] {$card['title']}\n";
    } else {
        // 新增
        $insertSql = "INSERT INTO training_cards (module_id, card_type, card_code, title, content, tips, difficulty, score, sort_order, status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, 100, 0, 1)";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([$moduleId, $card['card_type'], $card['card_code'], $card['title'], $card['content'], $card['tips'], $difficulty]);
        $imported++;
        echo "Imported: [{$card['card_type']}] {$card['title']}\n";
    }
}

// 更新模块卡片数量
$updateModuleSql = "
    UPDATE training_modules tm
    SET tm.total_cards = (
        SELECT COUNT(*) FROM training_cards WHERE module_id = tm.id
    )
";
$db->exec($updateModuleSql);

echo "\n=== Import Summary ===\n";
echo "New cards imported: $imported\n";
echo "Cards updated: $updated\n";
echo "Total FAQ cards processed: " . count($faqCards) . "\n";
echo "Done!\n";
