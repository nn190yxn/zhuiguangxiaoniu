UPDATE metric_definitions
SET is_active = 0,
    updated_at = NOW()
WHERE role_code = 'coach'
  AND metric_code IN ('coach_plan_hours', 'coach_actual_hours')
  AND is_active <> 0;

UPDATE workload_metric_rules
SET enabled = 0,
    updated_at = NOW()
WHERE role_code = 'coach'
  AND metric_code IN ('coach_plan_hours', 'coach_actual_hours')
  AND enabled <> 0;
