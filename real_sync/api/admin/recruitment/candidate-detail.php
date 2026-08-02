<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeReviewService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_view', ['GET']);
    $service = new ResumeReviewService($context['db'], $context['permission_service']);
    jsonResponse(0, 'ok', $service->detail((int) ($_GET['application_id'] ?? $_GET['id'] ?? 0), $context['recruitment_scope']));
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '候选人详情查询失败');
}
