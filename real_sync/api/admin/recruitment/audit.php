<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/RecruitmentAuditService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.audit_view', ['GET']);
    $service = new RecruitmentAuditService($context['db'], $context['permission_service']);
    jsonResponse(0, 'ok', $service->dashboard($context['recruitment_scope'], $_GET));
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '招聘质量与审计查询失败');
}
