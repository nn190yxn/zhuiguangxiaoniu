-- Activate the revised V4.0 workload standards from 2026-08-04.
-- Historical business dates keep their previous role rule bindings.

SET NAMES utf8mb4;

SET @workload_v4_revised_effective_from = '2026-08-04';
SET @workload_v4_revised_previous_to = DATE_SUB(@workload_v4_revised_effective_from, INTERVAL 1 DAY);

UPDATE workload_role_rule_versions
SET effective_to = @workload_v4_revised_previous_to
WHERE role_code IN ('sales', 'coach', 'manager', 'teaching_supervisor', 'supervisor')
  AND status IN ('active', 'scheduled')
  AND effective_from < @workload_v4_revised_effective_from
  AND (effective_to IS NULL OR effective_to >= @workload_v4_revised_effective_from);

UPDATE workload_role_rule_versions
SET status = 'active',
    effective_from = @workload_v4_revised_effective_from,
    effective_to = NULL,
    description = CONCAT(description, ' 已于 2026-08-04 正式启用。')
WHERE version_code IN (
    'sales-v4-revised-draft',
    'coach-v4-revised-draft',
    'manager-v4-revised-draft',
    'teaching-supervisor-v4-revised-draft',
    'supervisor-v4-revised-draft'
)
  AND status = 'draft';

UPDATE workload_conversion_rule_versions
SET status = 'active',
    effective_from = @workload_v4_revised_effective_from,
    effective_to = NULL,
    description = CONCAT(description, ' 已于 2026-08-04 正式启用。')
WHERE version_code IN (
    'sales-v4-revised-draft',
    'coach-v4-revised-draft',
    'manager-v4-revised-draft',
    'teaching-supervisor-v4-revised-draft',
    'supervisor-v4-revised-draft'
)
  AND status = 'draft';
