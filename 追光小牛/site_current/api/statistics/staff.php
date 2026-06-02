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

try {
    $db = getDB();
    $userId = getCurrentUserId();

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
        $params = [];
        $types = '';

        // 如果没传staff_id，使用当前用户关联的员工
        if ($staffId <= 0 && $userId > 0) {
            $where .= " AND s.user_id = ?";
            $params[] = $userId;
            $types .= 'i';
        } elseif ($staffId > 0) {
            $where .= " AND s.id = ?";
            $params[] = $staffId;
            $types .= 'i';
        }

        if ($storeId > 0) {
            $where .= " AND s.store_id = ?";
            $params[] = $storeId;
            $types .= 'i';
        }

        if ($role) {
            $where .= " AND s.role = ?";
            $params[] = $role;
            $types .= 's';
        }

        // 月份条件
        $monthCondition = "";
        if ($month > 0) {
            $monthCondition = " AND ms.year = ? AND ms.month = ?";
            $params[] = $year;
            $params[] = $month;
            $types .= 'ii';
        }

        // 获取员工列表（带统计数据）
        $sql = "SELECT s.*,
                st.name as store_name,
                (SELECT COALESCE(SUM(ms.courses_completed), 0) FROM monthly_statistics ms WHERE ms.staff_id = s.id $monthCondition) as total_courses,
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
        $stmt->execute($params);
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
        $countSql = "SELECT COUNT(*) FROM staffs s WHERE s.status = 1";
        if ($staffId > 0 || $userId > 0) {
            $countSql .= " AND " . ($staffId > 0 ? "s.id = $staffId" : "s.user_id = $userId");
        }
        if ($storeId > 0) {
            $countSql .= " AND s.store_id = $storeId";
        }
        if ($role) {
            $countSql .= " AND s.role = '$role'";
        }
        $stmt = $db->prepare($countSql);
        $stmt->execute();
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