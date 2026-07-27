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
        $storeId = (int) ($_GET['id'] ?? 0);
        if ($storeId > 0) {
            jsonResponse(0, 'ok', $service->getStore($storeId));
        }

        $stores = $service->listStores([
            'status' => $_GET['status'] ?? 'all',
            'keyword' => $_GET['keyword'] ?? '',
        ]);
        jsonResponse(0, 'ok', ['list' => $stores, 'total' => count($stores)]);
    }

    $input = adminJsonInput();
    $action = strtolower(trim((string) ($input['action'] ?? '')));
    if ($action === '') {
        $action = !empty($input['id']) ? 'update' : 'create';
    }

    if ($action === 'create') {
        $store = $service->createStore($input, $operatorUser, $operatorStaff ?: []);
        jsonResponse(0, '门店创建成功', $store);
    }

    $storeId = (int) ($input['id'] ?? 0);
    if ($action === 'update') {
        $store = $service->updateStore($storeId, $input, $operatorUser, $operatorStaff ?: []);
        jsonResponse(0, '门店更新成功', $store);
    }
    if ($action === 'set_status') {
        if (!array_key_exists('status', $input)) {
            throw new OrganizationStoreValidationException('缺少门店状态');
        }
        $store = $service->setStoreStatus(
            $storeId,
            $input['status'],
            $operatorUser,
            $operatorStaff ?: []
        );
        jsonResponse(0, $store['status'] === 1 ? '门店已启用' : '门店已停用', $store);
    }

    throw new OrganizationStoreValidationException('门店操作类型无效');
} catch (OrganizationStoreReferenceException $error) {
    jsonResponse(409, $error->getMessage(), ['reference_summary' => $error->referenceSummary()]);
} catch (OrganizationStoreConflictException $error) {
    jsonResponse(409, $error->getMessage(), ['conflict_field' => $error->conflictField()]);
} catch (OrganizationStoreValidationException $error) {
    jsonResponse(400, $error->getMessage(), null);
} catch (Throwable $error) {
    error_log('Organization stores API failed: ' . $error->getMessage());
    jsonResponse(500, '门店管理操作失败', null);
}
