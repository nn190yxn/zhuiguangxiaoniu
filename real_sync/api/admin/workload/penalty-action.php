<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__, 2) . '/workload/_common.php';
require_once dirname(__DIR__, 2) . '/workload/services/WorkloadPenaltyService.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonResponse(405, '仅支持 POST 请求');
    }
    [$userId, $user, $operatorStaff] = adminRequireAuth(static function (array $user, ?array $staff): bool {
        return in_array(adminEffectiveRole($user, $staff ?: []), ['operation', 'admin', 'ceo'], true);
    });
    $input = adminJsonInput();
    $penaltyId = (int) ($input['penalty_id'] ?? 0);
    $action = trim((string) ($input['action'] ?? ''));
    $reason = trim((string) ($input['reason'] ?? ''));
    $operatorStaffId = (int) ($operatorStaff['id'] ?? 0);
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $record = (new WorkloadPenaltyService($pdo))->applyAction($penaltyId, $action, $reason, $operatorStaffId);
    adminRecordOperation($pdo, $user, $operatorStaff, [
        'module' => 'workload',
        'action' => 'penalty.' . $action,
        'target_type' => 'workload_penalty_record',
        'target_id' => $penaltyId,
        'after' => $record,
    ]);
    jsonResponse(0, '处罚处理成功', ['record' => $record]);
} catch (InvalidArgumentException $e) {
    jsonResponse(400, $e->getMessage());
} catch (RuntimeException $e) {
    jsonResponse(409, $e->getMessage());
} catch (Throwable $e) {
    appLogEvent('admin.workload.penalty_action_error', ['error' => $e->getMessage()]);
    jsonResponse(500, '处理处罚失败');
}
