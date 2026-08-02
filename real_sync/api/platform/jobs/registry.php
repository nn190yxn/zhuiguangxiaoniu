<?php
declare(strict_types=1);

if (!isset($db) || !$db instanceof PDO) {
    throw new LogicException('platform_job_registry_requires_pdo');
}

require_once __DIR__ . '/ReminderJobHandler.php';
require_once __DIR__ . '/WecomJobHandler.php';
require_once __DIR__ . '/SkillReviewJobHandler.php';
require_once __DIR__ . '/DrillGovernanceJobHandler.php';
require_once __DIR__ . '/WorkloadExportJobHandler.php';
require_once __DIR__ . '/WorkloadAlertJobHandler.php';
require_once __DIR__ . '/RecruitmentResumeJobHandler.php';

return [
    'reminder.schedule.tick' => new ReminderJobHandler($db),
    'wecom.members.sync' => new WecomJobHandler($db),
    'skill.review.process' => new SkillReviewJobHandler($db),
    'drill.governance.expire_audio' => new DrillGovernanceJobHandler($db),
    'workload.export.process' => new WorkloadExportJobHandler($db),
    'workload.alert.run' => new WorkloadAlertJobHandler($db),
    'recruitment.resume.process' => new RecruitmentResumeJobHandler($db),
];
