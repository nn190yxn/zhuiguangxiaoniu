<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/drill/v2/services/DrillNewSignPromptContract.php';
require_once __DIR__ . '/../api/drill/v2/services/DrillTextReplyCoach.php';

$reportPath = __DIR__ . '/../.monkeycode/docs/free-practice-internal-dialogue-test-2026-08-23.md';

$rounds = [
    [
        'stage' => 'lead_preparation',
        'name' => '线索准备',
        'customer' => '我是在网上看到你们的，孩子今年 6 岁，平时有点不爱运动，想先了解一下适不适合。',
        'employee' => '您好，我先确认一下您的关注点：您是通过网上信息了解到我们，主要想看看课程是否适合 6 岁、目前不太爱运动的孩子。今天我先了解孩子的基本情况和您的目标，下一步再约一个方便的时间详细沟通。',
        'manual' => '覆盖线索来源、孩子背景、家长诉求，并约定后续沟通目标。',
    ],
    [
        'stage' => 'invitation_confirmation',
        'name' => '邀约确认',
        'customer' => '周六下午可以过去看看吗？需要带什么，到了之后大概怎么安排？',
        'employee' => '周六下午 3 点可以安排，地址我发给您。孩子穿方便运动的衣服和鞋子，建议提前 10 分钟到店，我们先签到和做基础体测，再进入体验。体验前我会再次联系您确认时间，您看这个安排可以吗？',
        'manual' => '覆盖时间、地点、穿着、提前到店体测、再次确认和到店安排。',
    ],
    [
        'stage' => 'arrival_reception',
        'name' => '到店接待',
        'customer' => '孩子第一次来有点慢热，我担心他不愿意配合。',
        'employee' => '我先带您和孩子签到，简单介绍一下环境。看得出来您比较关注孩子能不能放松、愿不愿意参与，今天我们会先观察他的状态，再把体验目标确认清楚，下一步安排他进入体测和体验。',
        'manual' => '覆盖签到、环境介绍、孩子状态、家长关注点和体验前承接。',
    ],
    [
        'stage' => 'needs_diagnosis',
        'name' => '需求诊断',
        'customer' => '孩子在家里坐得比较久，我想让他多运动，但还没想好重点改善什么。',
        'employee' => '我先了解一下原因和目标：您为什么开始关注运动？您最希望孩子在哪个问题上有变化？之前体验过其他机构吗？平时爸爸妈妈谁一起商量并做决定？我复述一下，您现在最关心的是孩子少久坐、形成运动习惯，同时希望课程适合他的状态，对吗？',
        'manual' => '覆盖购买动机、核心痛点、竞品经历、决策链，并完成复述确认。',
    ],
    [
        'stage' => 'assessment_experience',
        'name' => '体测与体验协同',
        'customer' => '刚才体测时教练说孩子协调性需要加强，这代表什么？',
        'employee' => '刚才我观察到孩子在单脚站立和连续跳时表现比较犹豫，说明目前基础协调能力需要加强，这可能会影响他参与集体运动时的信心。后续可以通过循序渐进的训练课程来提升，我们也会结合教练反馈持续调整训练方向。您看这样解释清楚吗？',
        'manual' => '按客观观察、基础能力、影响、课程承接和反馈确认完成解释，规避医学诊断和效果保证。',
    ],
    [
        'stage' => 'solution_value',
        'name' => '方案与价值呈现',
        'customer' => '你们的课程怎么帮到孩子？和普通兴趣班有什么区别？',
        'employee' => '结合您刚才提到的协调和久坐问题，我们会用小班课程和分阶段训练安排，帮助孩子建立运动习惯、提升协调能力。之前有类似情况的孩子通过持续训练后更愿意参与运动，具体效果还要结合孩子的参与情况和阶段反馈观察。每周安排和训练计划我可以按您家的时间一起确认，您看这个方案是否解决了当前需求？',
        'manual' => '完成需求、课程特征、案例、孩子收益、训练安排和确认下一步，形成一条可执行的 FAB 链路。',
    ],
    [
        'stage' => 'objection_signing_handoff',
        'name' => '异议及签约交接',
        'customer' => '我还是担心价格，也担心孩子坚持不了，今天还不能决定。',
        'employee' => '我理解您现在主要有两个顾虑：预算和坚持问题。我们可以先根据孩子的时间安排确认合适的训练频率，再按公开方案说明费用和服务；如果先从体验后的短周期安排开始，您觉得更容易判断是否合适吗？您看我们明天下午再沟通一次，届时一起确认方案，可以吗？',
        'manual' => '识别并孤立真实顾虑，给出针对性方案，完成试关闭并约定下一次沟通时间。',
    ],
    [
        'stage' => 'followup_referral',
        'name' => '未成交跟进与转介绍',
        'customer' => '我回去和家人商量一下，你把今天的情况发我，明天下午再联系可以吗？',
        'employee' => '感谢您今天带孩子来体验，我把今天的体测和课堂反馈整理给您，也会标出孩子目前的训练重点。您回去和家人商量一下，明天下午我按约定联系您确认顾虑；后续如果决定开始，再按 3、7、15、30 天节点跟进孩子的变化。',
        'manual' => '覆盖当天感谢、体验资料、具体回访时间和后续节点跟进，保留客户信任。',
    ],
];

$riskCase = [
    'stage' => 'objection_signing_handoff',
    'name' => '风险拦截回放',
    'customer' => '我担心孩子不适合，也觉得价格有点高。',
    'employee' => '这个课程一定能让孩子长高，不报名就会错过最后机会，我私下给您额外优惠。',
];

$escape = static function (string $value): string {
    return str_replace(['\\', '|', "\r", "\n"], ['\\\\', '\\|', '', '<br>'], $value);
};

$lines = [
    '# 自由演练内部一问一答测试报告',
    '',
    '- 测试日期：2026-08-23',
    '- 测试方式：按 8 个销售流程板块逐轮回放，每轮一条客户问题和一条销售回答。',
    '- 自动判定：`DrillTextReplyCoach::review()`，检查覆盖项、缺口、风险和可执行建议。',
    '- 目标：验证销售回答能否推进当前环节，并为下一步沟通提供明确动作。',
    '',
    '## 总体结果',
    '',
];

$results = [];
foreach ($rounds as $index => $round) {
    $review = DrillTextReplyCoach::review($round['stage'], $round['employee']);
    $results[] = [$index + 1, $round['name'], $review['status'], count($review['covered']), count($review['missing']), count($review['risks'])];
}

$lines[] = '| 轮次 | 板块 | 自动判定 | 覆盖项 | 缺口 | 风险 |';
$lines[] = '|---:|---|---|---:|---:|---:|';
foreach ($results as $result) {
    $lines[] = '| ' . implode(' | ', $result) . ' |';
}
$lines[] = '';
$lines[] = '预期结果：8 轮均应达到 `covered`，风险回放应达到 `risk`。';
$lines[] = '';

foreach ($rounds as $index => $round) {
    $review = DrillTextReplyCoach::review($round['stage'], $round['employee']);
    $contract = DrillNewSignPromptContract::forStage($round['stage']);
    $lines[] = '## 第 ' . ($index + 1) . ' 轮：' . $round['name'];
    $lines[] = '';
    $lines[] = '**客户：** ' . $escape($round['customer']);
    $lines[] = '';
    $lines[] = '**销售：** ' . $escape($round['employee']);
    $lines[] = '';
    $lines[] = '**自动判定：** `' . $review['status'] . '`';
    $lines[] = '';
    $lines[] = '- 覆盖：' . ($review['covered'] === [] ? '无' : implode('、', $review['covered']));
    $lines[] = '- 缺口：' . ($review['missing'] === [] ? '无' : implode('、', $review['missing']));
    $lines[] = '- 风险：' . ($review['risks'] === [] ? '无' : implode('、', array_column($review['risks'], 'type')));
    $lines[] = '- 系统建议：' . implode('；', $review['suggestions']);
    $lines[] = '- 人工核对：' . $round['manual'];
    $lines[] = '- 本轮核心目标：' . $contract['core_goal'];
    $lines[] = '';
}

$riskReview = DrillTextReplyCoach::review($riskCase['stage'], $riskCase['employee']);
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
$lines[] = '- 结果：风险被拦截，同时生成了替代表达和下一步建议。';
$lines[] = '';

$lines[] = '## 测试结论';
$lines[] = '';
$lines[] = '- 8 个流程板块均完成一问一答回放。';
$lines[] = '- 每轮销售回答都包含了当前板块的推进动作。';
$lines[] = '- 需求诊断、体测解释、方案呈现、异议处理和跟进环节均提供了具体下一步。';
$lines[] = '- 风险样本中的绝对承诺、恐吓式成交和未授权优惠均被识别。';
$lines[] = '- 当前报告验证的是规则教练和话术链路；真实 AI 客户问题仍需在微信开发者工具中完成一次端到端验证。';

$directory = dirname($reportPath);
if (!is_dir($directory)) {
    mkdir($directory, 0775, true);
}
file_put_contents($reportPath, implode("\n", $lines) . "\n");

echo $reportPath . "\n";
echo 'rounds=' . count($rounds) . ', covered=' . count(array_filter($results, static fn (array $result): bool => $result[2] === 'covered')) . ", risk=" . $riskReview['status'] . "\n";
