<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/RecruitmentExportService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_export', ['GET', 'POST']);
    if (!adminHasPermission('recruitment.resume_contact', $context['user'], $context['staff'])) {
        throw new RecruitmentAdminException('导出完整联系信息需要候选人联系权限', 403);
    }
    $service = new RecruitmentExportService($context['db'], $context['permission_service']);
    if ($context['method'] === 'POST') {
        recruitmentAdminRequireIdempotency($context);
        $input = recruitmentAdminInput();
        $result = recruitmentAdminIdempotent($context['db'], 'resume.export.create', $context['idempotency_key'], [
            'scope_mode' => strtolower(trim((string) ($input['scope_mode'] ?? 'all'))),
            'requirement_id' => (int) ($input['requirement_id'] ?? 0),
            'batch_id' => (int) ($input['batch_id'] ?? 0),
            'grade' => strtoupper(trim((string) ($input['grade'] ?? ''))),
            'date_from' => trim((string) ($input['date_from'] ?? '')),
            'date_to' => trim((string) ($input['date_to'] ?? '')),
        ], fn (): array => $service->create($input, $context['recruitment_scope'], (int) ($context['staff']['id'] ?? 0)));
        adminRecordOperation($context['db'], $context['user'], $context['staff'], [
            'module' => 'recruitment',
            'action' => 'resume.export.create',
            'target_type' => 'recruitment_export_job',
            'target_id' => (string) ($result['id'] ?? ''),
            'after' => ['row_count' => $result['row_count'] ?? 0, 'scope_mode' => strtolower(trim((string) ($input['scope_mode'] ?? 'all'))), 'requirement_id' => (int) ($input['requirement_id'] ?? 0), 'batch_id' => (int) ($input['batch_id'] ?? 0), 'grade' => strtoupper(trim((string) ($input['grade'] ?? ''))), 'date_from' => trim((string) ($input['date_from'] ?? '')), 'date_to' => trim((string) ($input['date_to'] ?? ''))],
        ]);
        jsonResponse(0, '导出任务已完成', $result);
    }
    $jobId = (int) ($_GET['job_id'] ?? $_GET['id'] ?? 0);
    $action = strtolower(trim((string) ($_GET['action'] ?? 'status')));
    if ($action !== 'download') {
        jsonResponse(0, 'ok', $service->job($jobId, $context['recruitment_scope']));
    }
    $result = $service->download($jobId, $context['recruitment_scope']);
    $job = $result['job'];
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'resume.export.download',
        'target_type' => 'recruitment_export_job',
        'target_id' => (string) $jobId,
        'after' => ['row_count' => (int) $job['row_count']],
    ]);
    $fileName = preg_replace('/[\r\n\"]+/', '_', (string) $job['file_name']) ?: 'recruitment-resumes.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="recruitment-resumes.xlsx"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    header('Content-Length: ' . (string) filesize($result['path']));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($result['path']);
    exit;
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '招聘候选人导出失败');
}
