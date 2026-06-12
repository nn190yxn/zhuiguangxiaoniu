<?php
/**
 * 员工统计API
 * GET /api/statistics/staff.php
 *
 * 参数:
 *   staff_id   - 员工ID（可选，不传则返回当前用户）
 *   store_id   - 门店ID（可选）
 *   role       - 角色筛选
 *   year       - 年份（默认当前年份）
 *   month      - 月份（默认当前月份，0则返回所有月份汇总）
 *   page       - 页码
 *   page_size  - 每页数量
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

function statisticsStaffCanViewAll(array $user = null, array $staff = null): bool {
    if (!$user) {
        return false;
    }

    $rawRole = strtolower(trim((string)($user['role'] ?? '')));
    $staffRole = strtolower(trim((string)($staff['role'] ?? '')));
    $staffName = trim((string)($staff['name'] ?? ''));
    $staffPhone = trim((string)($staff['phone'] ?? ''));
    $tokens = array_values(array_unique(array_filter([
        $rawRole,
        normalizeStaffRoleCode($rawRole),
        $staffRole,
        normalizeStaffRoleCode($staffRole),
    ])));

    if (in_array($staffName, ['何梓辛', '周颖', '陈琪琪', '姚修宁'], true)) {
        return true;
    }

    if (in_array($staffPhone, ['18285031172', '18685147960', '13885135551', '13668501068'], true)) {
        return true;
    }

    foreach (['admin', 'ops', 'operation', 'operations', 'operator', 'finance', 'ceo'] as $allowed) {
        if (in_array($allowed, $tokens, true)) {
            return true;
        }
    }

    return false;
}

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if (!$userId) {
        jsonResponse(401, '请先登录');
    }

    $user = getJwtCurrentUser();
    $staffProfile = getStaffByUserId($userId);
    $currentStaffId = (int)($staffProfile['id'] ?? 0);
    $currentStoreId = (int)($staffProfile['store_id'] ?? 0);
    $roleCode = $user ? normalizeStaffRoleCode($user['role'] ?? '') : '';
    $isAdmin = statisticsStaffCanViewAll($user, $staffProfile) || $roleCode === 'admin';
    $isManager = $roleCode === 'manager';

    if ($method === 'GET') {
        $staffId = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
        $storeId = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
        $role = isset($_GET['role']) ? trim($_GET['role']) : '';
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $pageSize = min(50, max(1, isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10));
        $offset = ($page - 1) * $pageSize;

        $where = "WHERE s.status = 1";
        $baseParams = [];

        if ($isAdmin) {
            if ($staffId > 0) {
                $where .= " AND s.id = ?";
                $baseParams[] = $staffId;
            }
        } elseif ($isManager) {
            if ($currentStoreId <= 0) {
                jsonResponse(403, '未找到管理员所属门店');
            }
            $where .= " AND s.store_id = ?";
            $baseParams[] = $currentStoreId;

            if ($staffId > 0) {
                $where .= " AND s.id = ?";
                $baseParams[] = $staffId;
            }
        } else {
            if ($currentStaffId <= 0) {
                jsonResponse(403, '未找到当前员工档案');
            }
            $staffId = $currentStaffId;
            $storeId = 0;
            $role = '';
            $where .= " AND s.id = ?";
            $baseParams[] = $staffId;
        }

        if ($storeId > 0) {
            $where .= " AND s.store_id = ?";
            $baseParams[] = $storeId;
        }

        if ($role) {
            $where .= " AND s.role = ?";
            $baseParams[] = $role;
        }

        // 月份条件
        $monthCondition = "";
        $metricParams = [];
        $courseDateCondition = "";
        $courseParams = [];
        if ($month > 0) {
            $monthCondition = " AND ms.year = ? AND ms.month = ?";
            for ($i = 0; $i < 5; $i++) {
                $metricParams[] = $year;
                $metricParams[] = $month;
            }
            $courseDateCondition = " AND ucp.completed_at >= ? AND ucp.completed_at < DATE_ADD(?, INTERVAL 1 MONTH)";
            $monthStart = sprintf('%04d-%02d-01 00:00:00', $year, $month);
            $courseParams[] = $monthStart;
            $courseParams[] = $monthStart;
        }

        // 获取员工列表（带统计数据）
        $sql = "SELECT s.*,
                st.name as store_name,
                (SELECT COUNT(*) FROM user_course_progress ucp WHERE ucp.user_id = s.user_id AND ucp.status = 1 $courseDateCondition) as total_courses,
                (SELECT COALESCE(SUM(ms.knowledge_cards_completed), 0) FROM monthly_statistics ms WHERE ms.staff_id = s.id $monthCondition) as total_knowledge,
                (SELECT COALESCE(SUM(ms.drills_completed), 0) FROM monthly_statistics ms WHERE ms.staff_id = s.id $monthCondition) as total_drills,
                (SELECT COALESCE(AVG(ms.exam_avg_score), 0) FROM monthly_statistics ms WHERE ms.staff_id = s.id $monthCondition) as avg_exam_score,
                (SELECT COALESCE(AVG(ms.pass_rate), 0) FROM monthly_statistics ms WHERE ms.staff_id = s.id $monthCondition) as avg_pass_rate,
                (SELECT COALESCE(SUM(ms.checkin_days), 0) FROM monthly_statistics ms WHERE ms.staff_id = s.id $monthCondition) as total_checkin_days,
                (SELECT MAX(ms.points_balance) FROM monthly_statistics ms WHERE ms.staff_id = s.id) as current_points
                FROM staffs s
                LEFT JOIN stores st ON s.store_id = st.id
                $where
                ORDER BY s.store_id ASC, s.role ASC
                LIMIT $offset, $pageSize";

        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($courseParams, $metricParams, $baseParams));
        $staffs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 格式化数据
        foreach ($staffs as &$staff) {
            $staff['total_courses'] = (int)$staff['total_courses'];
            $staff['total_knowledge'] = (int)$staff['total_knowledge'];
            $staff['total_drills'] = (int)$staff['total_drills'];
            $staff['avg_exam_score'] = round((float)$staff['avg_exam_score'], 1);
            $staff['avg_pass_rate'] = round((float)$staff['avg_pass_rate'], 1);
            $staff['total_checkin_days'] = (int)$staff['total_checkin_days'];
            $staff['current_points'] = (int)($staff['current_points'] ?? 0);
            $staff['stage_name'] = ['intern' => '实习期', 'probation' => '转正期', 'advanced' => '进阶期'][$staff['stage']] ?? $staff['stage'];
            $staff['role_name'] = ['sales' => '销售', 'coach' => '教练', 'manager' => '店长'][$staff['role']] ?? $staff['role'];
        }

        // 获取总数
        $countSql = "SELECT COUNT(*) FROM staffs s $where";
        $stmt = $db->prepare($countSql);
        $stmt->execute($baseParams);
        $total = $stmt->fetchColumn();

        jsonResponse(0, 'success', [
            'list' => $staffs,
            'total' => (int)$total,
            'page' => $page,
            'page_size' => $pageSize,
            'year' => $year,
            'month' => $month
        ]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
