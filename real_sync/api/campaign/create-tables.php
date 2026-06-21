<?php
/**
 * 7周年庆数据看板 - 创建数据库表
 */
require_once __DIR__ . '/../config.php';
handleCORS();

// Auth + admin check
$user = getJwtCurrentUser();
if (!$user || !in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    jsonResponse(403, '无权限访问');
}

try {
    $pdo = getDB();

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_daily_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_date DATE NOT NULL COMMENT '填报日期',
        store VARCHAR(50) NOT NULL COMMENT '门店',
        role_type VARCHAR(20) NOT NULL COMMENT '角色类型：销售/教练/店长/直播运营/内容运营',
        person_name VARCHAR(50) NULL COMMENT '填报人姓名',
        new_members INT NOT NULL DEFAULT 0 COMMENT '新增会员',
        renewal_members INT NOT NULL DEFAULT 0 COMMENT '续费会员',
        trial_conversions INT NOT NULL DEFAULT 0 COMMENT '试课转化',
        revenue DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '营收',
        notes TEXT NULL COMMENT '备注',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_entry_date (entry_date),
        INDEX idx_store (store)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo json_encode(['code' => 0, 'message' => '表创建成功'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['code' => 1, 'message' => '创建失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
