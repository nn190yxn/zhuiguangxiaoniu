<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeReviewService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_contact', ['POST']);
    recruitmentAdminRequireIdempotency($context);
    $input = recruitmentAdminInput();
    $service = new ResumeReviewService($context['db'], $context['permission_service']);
    $result = $service->resolveDuplicate(
        (int) ($input['canonical_candidate_id'] ?? 0),
        (int) ($input['related_candidate_id'] ?? 0),
        strtolower(trim((string) ($input['action'] ?? ''))),
        (string) ($input['reason'] ?? ''),
        $context['recruitment_scope'],
        (int) ($context['staff']['id'] ?? 0)
    );
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'resume.candidate_duplicate.' . (string) ($input['action'] ?? ''),
        'target_type' => 'recruitment_candidate',
        'target_id' => (string) ($result['related_candidate_id'] ?? ''),
        'after' => $result,
    ]);
    jsonResponse(0, '重复候选人处理完成', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '重复候选人处理失败');
}
