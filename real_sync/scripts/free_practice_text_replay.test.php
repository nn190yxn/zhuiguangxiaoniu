<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/drill/v2/services/DrillNewSignPromptContract.php';
require_once __DIR__ . '/../api/drill/v2/services/DrillTextReplyCoach.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$stageCodes = [
    'lead_preparation',
    'invitation_confirmation',
    'arrival_reception',
    'needs_diagnosis',
    'assessment_experience',
    'solution_value',
    'objection_signing_handoff',
    'followup_referral',
];

foreach ($stageCodes as $stageCode) {
    $contract = DrillNewSignPromptContract::forStage($stageCode);
    foreach (['core_goal', 'must_say', 'must_avoid', 'standard_expressions', 'validation_actions', 'practical_next_steps'] as $field) {
        $check(isset($contract[$field]) && $contract[$field] !== [], $stageCode . ' 缺少提示词契约字段：' . $field);
    }
}

$covered = DrillTextReplyCoach::review(
    'needs_diagnosis',
    '我想先了解您今天为什么带孩子来，您最希望孩子在哪个问题上有变化？之前体验过其他机构吗？平时爸爸妈妈谁一起商量并做决定？'
);
$check($covered['status'] === 'covered', '需求诊断完整回复应判定为 covered');
$check($covered['missing'] === [], '需求诊断完整回复不应有缺口');

$risky = DrillTextReplyCoach::review(
    'objection_signing_handoff',
    '这个课程一定能让孩子长高，不报名就会错过最后机会，我私下给您额外优惠。'
);
$check($risky['status'] === 'risk', '命中绝对承诺、恐吓和私下优惠应判定为 risk');
$check(count($risky['risks']) >= 3, '风险回复应至少识别三类风险');
$check($risky['suggestions'] !== [], '风险回复必须给出替代表达和下一步建议');

$practical = DrillTextReplyCoach::review(
    'followup_referral',
    '感谢您今天带孩子来体验，我把体测和课堂反馈整理给您。您回去和家人商量一下，明天下午我再联系您。'
);
$check($practical['suggestions'] !== [], '跟进回复必须给出务实建议');

fwrite(STDOUT, "free_practice_text_replay.test.php passed\n");
