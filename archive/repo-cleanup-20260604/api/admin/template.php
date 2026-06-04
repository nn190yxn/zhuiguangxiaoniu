<?php
require_once __DIR__ . '/common.php';
handleCORS();
adminRequireAuth('adminCanAccessHeadquarter');

$type = isset($_GET['type']) ? trim($_GET['type']) : '';
$templates = [
    'script-knowledge' => [
        'dimension' => 'qa',
        'scene_code' => 'trial_price_objection',
        'scene_name' => '体验课价格异议处理',
        'keywords' => ['价格', '体验课', '优惠'],
        'standard_script' => '先理解家长顾虑，再说明课程价值和孩子收益。',
        'tips' => '每行一条话术，保持场景明确。'
    ],
    'training-cards' => [
        'module_code' => 'sales_foundation',
        'card_type' => 'K',
        'title' => '客户需求识别',
        'content' => '通过开放式问题了解孩子年龄、运动基础和家长期望。',
        'sort_order' => 1
    ],
    'feedback' => [
        'student_name' => '示例学员',
        'course' => '体适能基础课',
        'performance' => '课堂参与积极，协调性表现良好。',
        'suggestion' => '建议在家继续练习平衡和核心稳定动作。'
    ],
    'staff' => [
        [
            'employee_no' => 'CD00101',
            'name' => '张三',
            'store_id' => 1,
            'role' => 'sales',
            'job_title' => '课程顾问',
            'phone' => '13000000001',
            'entry_date' => '2026-04-28',
            'stage' => 'intern',
            'status' => 1,
            'username' => 'CD00101',
            'email' => 'CD00101@staff.local',
            'password' => '请替换为初始密码',
            'openid' => ''
        ]
    ]
];

if (!isset($templates[$type])) {
    jsonResponse(1, '模板类型不存在');
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $type . '_template.json"');
echo json_encode($templates[$type], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
