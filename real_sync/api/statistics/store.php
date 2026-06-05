<?php
/**
 * 门店统计API
 * GET /api/statistics/store.php
 *
 * 参数:
 *   store_id  - 门店ID（可选，不传则返回所有门店）
 *   year      - 年份（默认当前年份）
 *   month     - 月份（默认当前月份，不传则返回本月）
 *   page      - 页码
 *   page_size - 每页数量
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

function statisticsStoreCanViewAll(array $user = null, array $staff = null): bool {
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
    $staff = getStaffByUserId($userId);
    $role = $user ? normalizeStaffRoleCode($user['role'] ?? '') : '';
    $isAdmin = statisticsStoreCanViewAll($user, $staff) || $role === 'admin';
    $isManager = $role === 'manager';
    $currentStoreId = (int)($staff['store_id'] ?? 0);

    if ($method === 'GET') {
        $storeId = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $pageSize = min(50, max(1, isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10));
        $offset = ($page - 1) * $pageSize;

        // 获取门店列表（带统计数据）
        $where = "WHERE s.status = 1";
        $params = [];

        if (!$isAdmin) {
            if ($isManager && $currentStoreId > 0) {
                if ($storeId > 0 && $storeId !== $currentStoreId) {
                    jsonResponse(403, '你没有权限查看其他门店统计');
                }
                $storeId = $currentStoreId;
            } elseif ($currentStoreId > 0) {
                $storeId = $currentStoreId;
            } else {
                jsonResponse(403, '未找到员工所属门店');
            }
        }

        if ($storeId > 0) {
            $where .= " AND s.id = ?";
            $params[] = $storeId;
        }

        // 获取门店列表
        $sql = "SELECT s.*,
                (SELECT COUNT(*) FROM staffs WHERE store_id = s.id AND status = 1) as staff_count,
                (SELECT SUM(total_learning_time) FROM monthly_statistics WHERE store_id = s.id AND year = ? AND month = ?) as total_learning_time,
                (SELECT SUM(courses_completed) FROM monthly_statistics WHERE store_id = s.id AND year = ? AND month = ?) as courses_completed,
                (SELECT SUM(knowledge_cards_completed) FROM monthly_statistics WHERE store_id = s.id AND year = ? AND month = ?) as knowledge_completed,
                (SELECT SUM(drills_completed) FROM monthly_statistics WHERE store_id = s.id AND year = ? AND month = ?) as drills_completed,
                (SELECT AVG(pass_rate) FROM monthly_statistics WHERE store_id = s.id AND year = ? AND month = ?) as avg_pass_rate,
                (SELECT SUM(points_earned) FROM monthly_statistics WHERE store_id = s.id AND year = ? AND month = ?) as total_points
                FROM stores s
                $where
                ORDER BY s.sort_order ASC
                LIMIT $offset, $pageSize";

        $allParams = array_merge([$year, $month, $year, $month, $year, $month, $year, $month, $year, $month, $year, $month], $params);
        $stmt = $db->prepare($sql);
        $stmt->execute($allParams);
        $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 格式化数据
        foreach ($stores as &$store) {
            $store['total_learning_time'] = (int)($store['total_learning_time'] ?? 0);
            $store['courses_completed'] = (int)($store['courses_completed'] ?? 0);
            $store['knowledge_completed'] = (int)($store['knowledge_completed'] ?? 0);
            $store['drills_completed'] = (int)($store['drills_completed'] ?? 0);
            $store['avg_pass_rate'] = round((float)($store['avg_pass_rate'] ?? 0), 1);
            $store['total_points'] = (int)($store['total_points'] ?? 0);
        }

        // 获取总数
        $countSql = "SELECT COUNT(*) FROM stores s $where";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        jsonResponse(0, 'success', [
            'list' => $stores,
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
