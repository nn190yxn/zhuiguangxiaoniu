<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/common.php';
require_once dirname(__DIR__, 3) . '/drill/v2/_common.php';
require_once dirname(__DIR__, 3) . '/drill/v2/services/DrillAdminApiService.php';

final class DrillAdminScopeDeniedException extends DomainException
{
}

function drillAdminV2Bootstrap(string $permission, array $allowedMethods = ['GET', 'POST']): array
{
    $context = drillV2Bootstrap($allowedMethods);
    $user = getJwtCurrentUser() ?: [];
    $staff = getStaffByUserId((int) $context['user_id']) ?: [];
    if (!adminHasPermission($permission, $user, $staff)) {
        appLogEvent('drill.v2.admin_permission_denied', [
            'permission' => $permission,
            'staff_id' => $context['staff_id'] ?? null,
        ]);
        drillV2Error(403, '你没有权限访问该演练管理模块', [], 403);
    }
    return [$context, $user, $staff];
}

function drillAdminV2Handle(string $resource, string $permission): void
{
    try {
        [$context, $user, $staff] = drillAdminV2Bootstrap($permission);
        $input = drillV2Input();
        if ($input === []) {
            $input = $_GET;
        }
        $service = new DrillAdminApiService(getDB());
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $result = drillV2RunIdempotent(
                getDB(),
                $context,
                'drill.admin.' . $resource . '.' . (string) ($input['action'] ?? 'write'),
                $input,
                fn(): array => $service->write($resource, $input, $context, $staff)
            );
            drillV2Success($result, 'success', 202);
        }
        drillV2Success($service->read($resource, $input, $context, $staff));
    } catch (DrillIdempotencyException $error) {
        drillV2Error($error->statusCode(), $error->getMessage(), [], $error->statusCode());
    } catch (DrillAdminScopeDeniedException $error) {
        drillV2Error(403, $error->getMessage(), [], 403);
    } catch (DomainException|InvalidArgumentException $error) {
        drillV2Error(400, $error->getMessage(), [], 400);
    } catch (Throwable $error) {
        error_log('Drill v2 admin ' . $resource . ' failed: ' . $error->getMessage());
        drillV2Error(500, '演练管理数据处理失败', [], 500);
    }
}
