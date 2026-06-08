<?php
declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once dirname(__DIR__) . '/common/context.php';

function summerCampDb(): PDO
{
    return getDB();
}

function summerCampEnsureSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS summer_camp_assessments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            camp_type VARCHAR(32) NOT NULL COMMENT '营类型',
            student_name VARCHAR(100) NOT NULL COMMENT '学员姓名',
            student_gender VARCHAR(10) COMMENT '性别',
            student_grade VARCHAR(50) COMMENT '年级',
            student_age INT COMMENT '年龄',
            student_height DECIMAL(10,2) COMMENT '身高cm',
            student_weight DECIMAL(10,2) COMMENT '体重kg',
            phone VARCHAR(20) COMMENT '联系电话',
            coach_diagnosis TEXT COMMENT '教练诊断数据',
            staff_id BIGINT UNSIGNED NOT NULL COMMENT '教练ID',
            store_id BIGINT UNSIGNED COMMENT '门店ID',
            assessment_date DATE NOT NULL COMMENT '评估日期',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_camp_type (camp_type),
            INDEX idx_staff_id (staff_id),
            INDEX idx_store_id (store_id),
            INDEX idx_date (assessment_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='暑假班评估记录主表'
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS summer_camp_test_data (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            assessment_id BIGINT UNSIGNED NOT NULL,
            metric_code VARCHAR(50) NOT NULL COMMENT '测试项目代码',
            metric_value DECIMAL(10,2) COMMENT '测试数值',
            metric_text VARCHAR(100) COMMENT '测试文本值',
            rating VARCHAR(20) COMMENT '评级',
            percentile INT COMMENT '同龄百分位',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_assessment_id (assessment_id),
            FOREIGN KEY (assessment_id) REFERENCES summer_camp_assessments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='暑假班测试数据表'
    ");

    try {
        $pdo->exec("ALTER TABLE summer_camp_test_data ADD COLUMN metric_text VARCHAR(100) COMMENT '测试文本值' AFTER metric_value");
    } catch (Throwable $exception) {
        if (stripos($exception->getMessage(), 'Duplicate column') === false) {
            throw $exception;
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS summer_camp_reports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            assessment_id BIGINT UNSIGNED NOT NULL,
            ai_content TEXT COMMENT 'AI生成的报告内容',
            coach_remarks TEXT COMMENT '教练寄语',
            coach_name VARCHAR(100) COMMENT '教练姓名',
            coach_phone VARCHAR(20) COMMENT '教练电话',
            coach_store VARCHAR(100) COMMENT '教练门店',
            report_date DATE COMMENT '报告生成日期',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_assessment_id (assessment_id),
            FOREIGN KEY (assessment_id) REFERENCES summer_camp_assessments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='暑假班AI报告表'
    ");
}

function summerCampValidateCampType(string $type): bool
{
    $validTypes = ['zhongkao', 'tineng', 'tiaosheng', 'lanqiu', 'tuobei'];
    return in_array($type, $validTypes, true);
}

function summerCampGetCampName(string $type): string
{
    $names = [
        'zhongkao' => '中考体训达标营',
        'tineng' => '体能达标营',
        'tiaosheng' => '跳绳达标营',
        'lanqiu' => '篮球体能营',
        'tuobei' => '驼背体态调整营'
    ];
    return $names[$type] ?? '未知营类型';
}

function summerCampJsonError(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function summerCampJsonSuccess(array $data, string $message = 'success'): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 0, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
