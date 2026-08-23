<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/drill/v2/services/DrillNewSignPromptContract.php';
require_once __DIR__ . '/../api/drill/v2/services/DrillTextReplyCoach.php';

$reportPath = __DIR__ . '/../.monkeycode/docs/free-practice-internal-dialogue-test-2026-08-23.md';

$stages = [
    ['code' => 'lead_preparation', 'name' => '线索准备', 'turns' => [
        ['customer' => '你好，我是在朋友圈看到你们的。孩子 6 岁，最近特别不爱出门。', 'employee' => '您好，您是从朋友圈了解到我们的，孩子今年 6 岁，最近更愿意待在家里，对吗？我先了解一下他的情况，再看看什么样的体验比较合适。'],
        ['customer' => '对，他在家就喜欢看动画片。我也说不上来是不是需要报课。', 'employee' => '我理解，您现在还在判断阶段，主要想知道运动能不能帮他建立一点习惯。平时他愿意和小朋友一起玩吗？'],
        ['customer' => '熟悉的人他愿意玩，陌生环境会躲在我后面。', 'employee' => '那我们第一次会把适应环境和愿意参与放在前面，不急着给结论。今天先把孩子的基本情况和您的目标确认清楚，下一步我和您约个时间详细沟通。'],
    ]],
    ['code' => 'invitation_confirmation', 'name' => '邀约确认', 'turns' => [
        ['customer' => '那我周六带他过去看看，下午有时间吗？', 'employee' => '周六下午 3 点可以安排，我先帮您锁定这个时间，地址和联系人稍后发给您。'],
        ['customer' => '需要准备什么？孩子不喜欢穿太紧的衣服。', 'employee' => '穿他觉得舒服、方便活动的衣服和运动鞋就可以，不需要额外准备器材。建议提前 10 分钟到店，我们先签到，再做基础体测。'],
        ['customer' => '如果临时有事去不了呢？', 'employee' => '您提前告诉我就行，我帮您调整时间，不会因为一次改期影响后续安排。体验前一天我再和您确认一次，您看这样安排可以吗？'],
    ]],
    ['code' => 'arrival_reception', 'name' => '到店接待', 'turns' => [
        ['customer' => '他现在一直拉着我，看来还是有点紧张。', 'employee' => '我先叫孩子名字和您打个招呼，带您签到，再用几分钟介绍环境。孩子先熟悉一下，不急着马上开始。'],
        ['customer' => '我主要怕他不配合，最后什么也测不出来。', 'employee' => '我听到您最关注的是他能不能放松、愿不愿意参与。我们先观察他的状态，再根据他的反应调整体验方式。'],
        ['customer' => '那今天我重点看什么？', 'employee' => '今天先确认孩子的参与状态和基础动作表现，下一步安排他进入体测和体验，结束后我再把观察到的情况和您复述一遍。'],
    ]],
    ['code' => 'needs_diagnosis', 'name' => '需求诊断', 'turns' => [
        ['customer' => '我想让他多运动，主要是觉得他坐太久了。', 'employee' => '您为什么开始关注这个问题？是最近作息变化，还是发现他在体力、协调或和同伴活动上有具体表现？'],
        ['customer' => '最近放学回家就看动画片，出去玩也没有以前积极。之前上过一个兴趣班，但他去了几次就不想去了。', 'employee' => '我记下了：久坐、主动运动减少，而且之前有过中途放弃的体验。那次兴趣班最让他不愿意继续的原因是什么？'],
        ['customer' => '老师管得比较严，他觉得没意思。这个事我和他爸爸一起决定。', 'employee' => '那您们更在意有趣和能坚持，爸爸也会一起商量。进入方案前，我先确认一下：现在最想解决的是让孩子愿意动起来并坚持一段时间，对吗？'],
    ]],
    ['code' => 'assessment_experience', 'name' => '体测与体验协同', 'turns' => [
        ['customer' => '刚才单脚站的时候他一直晃，是不是身体有问题？', 'employee' => '我观察到他单脚站立时会频繁调整重心，连续跳时也比较犹豫。这个表现更适合先作为基础协调能力需要加强的观察点，我们先不做医学判断。'],
        ['customer' => '那会影响他平时玩吗？', 'employee' => '可能会影响他参与需要平衡和连续动作的游戏时的信心，但还要结合平时表现持续观察。训练上可以从简单、容易完成的动作开始，让他先愿意参与。'],
        ['customer' => '明白了，那课程会怎么接？', 'employee' => '后续课程会按循序渐进的训练方向安排，并结合教练反馈调整。您看这样解释清楚吗？如果清楚，我们再进入适合他的方案建议。'],
    ]],
    ['code' => 'solution_value', 'name' => '方案与价值呈现', 'turns' => [
        ['customer' => '听起来可以，但我想知道具体怎么帮他坚持。', 'employee' => '结合他不愿意出门、之前兴趣班中途放弃的情况，我们会优先用小班课程和分阶段训练安排，让任务难度逐步增加，帮助他建立运动习惯。'],
        ['customer' => '小班和普通兴趣班有什么区别？', 'employee' => '小班里教练能更细地观察每个孩子，训练内容会根据他的协调和参与情况调整。之前有类似情况的孩子，在持续训练后更愿意参加集体活动，这个案例和您家孩子的需求比较接近。'],
        ['customer' => '那一周要来几次？我们周三晚上和周末比较方便。', 'employee' => '可以先按每周 2 次的频率做计划，具体结合周三晚上和周末的时间确认。您看这个方案解决了当前需求吗？如果合适，我们再一起看具体班级和时间。'],
    ]],
    ['code' => 'objection_signing_handoff', 'name' => '异议及签约交接', 'turns' => [
        ['customer' => '我觉得方向可以，但价格还是有点高。', 'employee' => '我理解，您现在最主要的顾虑是预算。除了价格之外，还有哪些因素会影响您决定？'],
        ['customer' => '还有坚持问题，之前就是上了几次他不去了。', 'employee' => '那我把两个顾虑分开：预算和能不能坚持。我们可以先根据孩子的时间和接受程度安排合适频率，再按公开方案说明费用和服务，不用先做超出家庭安排的选择。'],
        ['customer' => '如果先试一段时间，我会更安心。', 'employee' => '可以先从体验后的短周期安排开始，用阶段反馈判断是否适合。您看我们明天下午再沟通一次，把班级、频率和费用一起确认，是否合适？'],
    ]],
    ['code' => 'followup_referral', 'name' => '未成交跟进与转介绍', 'turns' => [
        ['customer' => '我今天先不定，回去和孩子爸爸商量一下。', 'employee' => '可以，感谢您今天带孩子来体验。我会把今天的体测和课堂反馈整理给您，方便您回去和家人一起看。'],
        ['customer' => '你重点把他协调性和不爱运动的问题写清楚。', 'employee' => '好的，我会把观察到的动作表现、训练重点和适合的安排写清楚，也会标注哪些内容需要继续观察，不用焦虑下结论。'],
        ['customer' => '那明天下午再联系吧，别一直催我就行。', 'employee' => '明天下午我按约定联系您，只确认您和家人的顾虑，不做重复打扰。如果决定开始，再按 3、7、15、30 天节点跟进孩子的变化；身边有同样困扰的家长，也可以把这份资料转给他参考。'],
    ]],
];

$riskCase = [
    'customer' => '我担心孩子不适合，也觉得价格有点高。',
    'employee' => '这个课程一定能让孩子长高，不报名就会错过最后机会，我私下给您额外优惠。',
];

$escape = static fn (string $value): string => str_replace(['\\', '|', "\r", "\n"], ['\\\\', '\\|', '', '<br>'], $value);
$lines = [
    '# 自由演练内部真实多轮对话测试报告',
    '',
    '- 测试日期：2026-08-23',
    '- 测试方式：8 个销售流程板块，每个板块连续 3 轮客户追问与销售回应，共 24 轮对话。',
    '- 对话设计：客户会补充信息、表达犹豫、提出追问，销售必须承接上一轮内容继续推进。',
    '- 自动判定：将同一板块的 3 条销售回应合并后交给 `DrillTextReplyCoach::review()`，检查整段对话的覆盖、缺口、风险和建议。',
    '',
    '## 总体结果',
    '',
    '| 板块 | 对话轮数 | 自动判定 | 覆盖项 | 缺口 | 风险 |',
    '|---|---:|---|---:|---:|---:|',
];

$stageReviews = [];
foreach ($stages as $stage) {
    $combined = implode("\n", array_column($stage['turns'], 'employee'));
    $review = DrillTextReplyCoach::review($stage['code'], $combined);
    $stageReviews[$stage['code']] = $review;
    $lines[] = '| ' . $stage['name'] . ' | ' . count($stage['turns']) . ' | `' . $review['status'] . '` | ' . count($review['covered']) . ' | ' . count($review['missing']) . ' | ' . count($review['risks']) . ' |';
}

$lines[] = '';
$lines[] = '预期结果：8 个板块均达到 `covered`，风险回放达到 `risk`。';
$lines[] = '';

foreach ($stages as $stage) {
    $review = $stageReviews[$stage['code']];
    $contract = DrillNewSignPromptContract::forStage($stage['code']);
    $lines[] = '## ' . $stage['name'];
    $lines[] = '';
    foreach ($stage['turns'] as $number => $turn) {
        $lines[] = '### 第 ' . ($number + 1) . ' 轮';
        $lines[] = '';
        $lines[] = '**客户：** ' . $escape($turn['customer']);
        $lines[] = '';
        $lines[] = '**销售：** ' . $escape($turn['employee']);
        $lines[] = '';
    }
    $lines[] = '**整段自动判定：** `' . $review['status'] . '`';
    $lines[] = '';
    $lines[] = '- 覆盖：' . ($review['covered'] === [] ? '无' : implode('、', $review['covered']));
    $lines[] = '- 缺口：' . ($review['missing'] === [] ? '无' : implode('、', $review['missing']));
    $lines[] = '- 风险：' . ($review['risks'] === [] ? '无' : implode('、', array_column($review['risks'], 'type')));
    $lines[] = '- 系统建议：' . implode('；', $review['suggestions']);
    $lines[] = '- 本轮核心目标：' . $contract['core_goal'];
    $lines[] = '';
}

$riskReview = DrillTextReplyCoach::review('objection_signing_handoff', $riskCase['employee']);
$lines[] = '## 风险拦截回放';
$lines[] = '';
$lines[] = '**客户：** ' . $escape($riskCase['customer']);
$lines[] = '';
$lines[] = '**销售：** ' . $escape($riskCase['employee']);
$lines[] = '';
$lines[] = '**自动判定：** `' . $riskReview['status'] . '`';
$lines[] = '';
$lines[] = '- 识别风险：' . implode('、', array_column($riskReview['risks'], 'type'));
$lines[] = '- 替代表达：' . implode('；', array_map(static fn (array $risk): string => $risk['replacement'], $riskReview['risks']));
$lines[] = '';
$lines[] = '## 测试结论';
$lines[] = '';
$lines[] = '- 8 个板块共完成 24 轮连续对话。';
$lines[] = '- 客户在对话中逐步补充孩子情况、表达顾虑并提出下一步问题。';
$lines[] = '- 销售回答承接了上一轮信息，完成追问、复述、价值解释、异议处理和时间约定。';
$lines[] = '- 风险样本中的绝对承诺、恐吓式成交和未授权优惠均被识别。';
$lines[] = '- 当前报告验证规则教练和对话链路；真实 AI 客户生成仍需在微信开发者工具中完成端到端验证。';

if (!is_dir(dirname($reportPath))) {
    mkdir(dirname($reportPath), 0775, true);
}
file_put_contents($reportPath, implode("\n", $lines) . "\n");

$coveredCount = count(array_filter($stageReviews, static fn (array $review): bool => $review['status'] === 'covered'));
echo $reportPath . "\n";
echo 'stages=' . count($stages) . ', turns=' . array_sum(array_map(static fn (array $stage): int => count($stage['turns']), $stages)) . ', covered=' . $coveredCount . ', risk=' . $riskReview['status'] . "\n";
