<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeReviewService.php';
require_once __DIR__ . '/platform/RecruitmentPlatformFileAdapter.php';
require_once dirname(__DIR__, 2) . '/kernel/bootstrap.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_original_view', ['GET']);
    $applicationId = (int) ($_GET['application_id'] ?? $_GET['id'] ?? 0);
    $pageOrder = max(1, (int) ($_GET['page'] ?? 1));
    $service = new ResumeReviewService($context['db'], $context['permission_service']);
    $application = $service->accessibleApplication($applicationId, $context['recruitment_scope']);
    $stmt = $context['db']->prepare(
        'SELECT file.id, file.original_name, file.storage_key, file.platform_asset_id, file.mime_type, file.byte_size, page.page_order, page.file_page_no '
        . 'FROM recruitment_resume_document_pages page JOIN recruitment_resume_files file ON file.id = page.resume_file_id '
        . 'WHERE page.document_id = ? AND page.page_order = ? LIMIT 1'
    );
    $stmt->execute([(int) $application['document_id'], $pageOrder]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$file) {
        throw new RecruitmentAdminException('简历原始文件页不存在', 404);
    }
    $platformContext = platformApiContext(['domain' => 'recruitment', 'action' => 'recruitment.resume_original_view']);
    $platformAuth = platformApiAuthContext();
    $platformAuth->requirePermission('recruitment.resume_original_view');
    $adapter = new RecruitmentPlatformFileAdapter($context['db']);
    $download = $adapter->prepareDownload($file, [
        'type' => 'staff',
        'id' => (string) ($context['staff']['id'] ?? 0),
    ], $platformContext->requestId());
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'resume.original.view',
        'target_type' => 'recruitment_resume_file',
        'target_id' => (string) $file['id'],
        'after' => ['application_id' => $applicationId, 'page_order' => $pageOrder],
    ]);
    $adapter->stream($download);
    exit;
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '简历原始文件访问失败');
}
