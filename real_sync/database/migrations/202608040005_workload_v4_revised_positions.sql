-- Enable the two new workload roles required by the revised V4.0 standards.

SET NAMES utf8mb4;

INSERT INTO organization_positions (
    position_code,
    position_name,
    applicable_roles_json,
    sort_order,
    status
)
VALUES
    ('teaching_supervisor', '教学主管', '["teaching_supervisor"]', 35, 1),
    ('supervisor', '督导', '["supervisor"]', 36, 1)
ON DUPLICATE KEY UPDATE
    position_name = VALUES(position_name),
    applicable_roles_json = VALUES(applicable_roles_json),
    sort_order = VALUES(sort_order),
    status = 1;
