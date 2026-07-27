<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/OrganizationService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    jsonResponse(405, '请求方法不被支持', null);
}

try {
    [$userId, $user, $operatorStaff] = adminRequirePermission('organization.manage');
    $operatorUser = is_array($user) ? $user : ['user_id' => (int) $userId];

    $service = new OrganizationService(getDB());
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $positionId = (int) ($_GET['id'] ?? 0);
        if ($positionId > 0) {
            jsonResponse(0, 'ok', $service->getPosition($positionId));
        }

        $positions = $service->listPositions([
            'status' => $_GET['status'] ?? 'all',
            'keyword' => $_GET['keyword'] ?? '',
        ]);
        jsonResponse(0, 'ok', ['list' => $positions, 'total' => count($positions)]);
    }

    $input = adminJsonInput();
    $action = strtolower(trim((string) ($input['action'] ?? '')));
    if ($action === '') {
        $action = !empty($input['id']) ? 'update' : 'create';
    }

    if ($action === 'create') {
        $position = $service->createPosition($input, $operatorUser, $operatorStaff ?: []);
        jsonResponse(0, '岗位创建成功', $position);
    }

    $positionId = (int) ($input['id'] ?? 0);
    if ($action === 'update') {
        $position = $service->updatePosition($positionId, $input, $operatorUser, $operatorStaff ?: []);
        jsonResponse(0, '岗位更新成功', $position);
    }
    if ($action === 'set_status') {
        if (!array_key_exists('status', $input)) {
            throw new OrganizationPositionValidationException('缺少岗位状态');
        }
        $position = $service->setPositionStatus(
            $positionId,
            $input['status'],
            $operatorUser,
            $operatorStaff ?: []
        );
        jsonResponse(0, $position['status'] === 1 ? '岗位已启用' : '岗位已停用', $position);
    }

    throw new OrganizationPositionValidationException('岗位操作类型无效');
} catch (OrganizationPositionReferenceException $error) {
    jsonResponse(409, $error->getMessage(), ['reference_summary' => $error->referenceSummary()]);
} catch (OrganizationPositionConflictException $error) {
    jsonResponse(409, $error->getMessage(), ['conflict_field' => $error->conflictField()]);
} catch (OrganizationPositionValidationException $error) {
    jsonResponse(400, $error->getMessage(), null);
} catch (Throwable $error) {
    error_log('Organization positions API failed: ' . $error->getMessage());
    jsonResponse(500, '岗位字典操作失败', null);
}
