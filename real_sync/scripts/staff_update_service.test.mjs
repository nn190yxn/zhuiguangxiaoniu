import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const lifecycle = readFileSync(
  new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url),
  'utf8',
);
const organization = readFileSync(
  new URL('../api/admin/services/OrganizationService.php', import.meta.url),
  'utf8',
);
const endpoint = readFileSync(
  new URL('../api/admin/staff/update.php', import.meta.url),
  'utf8',
);

class StaffUpdateModel {
  constructor() {
    this.staff = new Map([[1, {
      id: 1,
      name: 'Alice',
      phone: '13800000001',
      storeId: 1,
      positionId: 1,
      role: 'sales',
      status: 1,
      lifecycle: 'active',
    }]]);
    this.phones = new Map([['13800000001', 1], ['13800000002', 2]]);
    this.stores = new Map([[1, true], [2, true], [3, false]]);
    this.positions = new Map([[1, ['sales']], [2, ['coach']]]);
    this.assignments = [{ staffId: 1, storeId: 1, positionId: 1, role: 'sales', startDate: '2026-01-01', endDate: null }];
    this.audits = [];
  }

  update(input) {
    const snapshot = structuredClone({
      staff: [...this.staff],
      phones: [...this.phones],
      assignments: this.assignments,
      audits: this.audits,
    });
    try {
      const before = structuredClone(this.staff.get(1));
      assert.equal(before.lifecycle === 'offboarded', false);
      const next = {
        ...before,
        name: input.name ?? before.name,
        phone: input.phone ?? before.phone,
        status: input.status ?? before.status,
      };
      assert.ok(next.name.length > 0 && next.name.length <= 100);
      assert.match(next.phone, /^1[3-9]\d{9}$/);
      assert.ok(next.status === 0 || next.status === 1);
      if (next.phone !== before.phone) {
        assert.equal(this.phones.has(next.phone), false, 'phone conflict');
        this.phones.delete(before.phone);
        this.phones.set(next.phone, 1);
      }

      const target = {
        storeId: input.storeId ?? before.storeId,
        positionId: input.positionId ?? before.positionId,
        role: input.role ?? before.role,
      };
      const organizationChanged = target.storeId !== before.storeId
        || target.positionId !== before.positionId
        || target.role !== before.role;
      if (organizationChanged) {
        assert.equal(next.status, 1);
        assert.equal(this.stores.get(target.storeId), true, 'store unavailable');
        assert.equal(this.positions.get(target.positionId)?.includes(target.role), true, 'role mismatch');
        assert.match(input.effectiveDate ?? '', /^\d{4}-\d{2}-\d{2}$/);
        assert.ok((input.reason ?? '').trim().length > 0);
        const current = this.assignments.find(
          (assignment) => assignment.staffId === 1
            && assignment.startDate <= input.effectiveDate
            && (assignment.endDate === null || assignment.endDate >= input.effectiveDate),
        );
        current.endDate = previousDate(input.effectiveDate);
        this.assignments.push({ staffId: 1, ...target, startDate: input.effectiveDate, endDate: null });
        if (input.effectiveDate <= '2026-07-24') Object.assign(next, target);
      }
      next.lifecycle = next.status === 1 ? 'active' : 'inactive';
      this.staff.set(1, next);
      this.audits.push({ before, after: structuredClone(next), reason: input.reason ?? null });
      return structuredClone(next);
    } catch (error) {
      this.staff = new Map(snapshot.staff);
      this.phones = new Map(snapshot.phones);
      this.assignments = structuredClone(snapshot.assignments);
      this.audits = structuredClone(snapshot.audits);
      throw error;
    }
  }
}

function previousDate(date) {
  const value = new Date(`${date}T00:00:00Z`);
  value.setUTCDate(value.getUTCDate() - 1);
  return value.toISOString().slice(0, 10);
}

test('staff lifecycle exposes a transactional update with explicit row locking', () => {
  assert.match(lifecycle, /public function update\(int \$staffId, array \$input, array \$operatorUser, array \$operatorStaff\): array/);
  assert.match(lifecycle, /SELECT id, employee_no, name, phone, store_id, primary_position_id, role, job_title,/);
  assert.match(lifecycle, /FROM staffs WHERE id = \? FOR UPDATE/);
  assert.match(lifecycle, /\$this->db->beginTransaction\(\)/);
  assert.match(lifecycle, /\$this->db->commit\(\)/);
  assert.match(lifecycle, /\$this->db->rollBack\(\)/);
});

test('staff updates validate fields, phone uniqueness, and read-only offboarded records', () => {
  assert.match(lifecycle, /offboarded staff is read-only/);
  assert.match(lifecycle, /name is required and cannot exceed 100 characters/);
  assert.match(lifecycle, /phone format is invalid/);
  assert.match(lifecycle, /status must be 0 or 1/);
  assert.match(lifecycle, /change reason is required and cannot exceed 500 characters/);
  assert.match(lifecycle, /FROM staffs WHERE phone = \? AND id <> \? FOR UPDATE/);
  assert.match(lifecycle, /throw new StaffIdentityConflictException\(\['phone'\], \$profiles\)/);
});

test('organization changes delegate to the assignment service inside the outer transaction', () => {
  assert.match(lifecycle, /new OrganizationService\(\$this->db\)/);
  assert.match(lifecycle, /->changePrimaryAssignment\(/);
  for (const field of ['store_id', 'position_id', 'system_role', 'effective_date', 'change_reason']) {
    assert.match(lifecycle, new RegExp(`'${field}' =>`));
  }
  assert.match(organization, /\$ownsTransaction = !\$this->pdo->inTransaction\(\)/);
  assert.match(organization, /commitIdempotentAssignment\(\$current, \$ownsTransaction\)/);
  assert.match(organization, /if \(\$ownsTransaction\) \{\s*\$this->pdo->commit\(\)/);
});

test('status edits synchronize staff lifecycle and linked account availability', () => {
  assert.match(lifecycle, /\$sets\[\] = 'lifecycle_status = \?'/);
  assert.match(lifecycle, /UPDATE wp_users SET user_status = \? WHERE ID = \?/);
  assert.match(lifecycle, /\$data\['status'\] === 1 \? 'active' : 'inactive'/);
});

test('staff update audit contains before and after snapshots plus organization context', () => {
  assert.match(lifecycle, /'action' => 'update'/);
  assert.match(lifecycle, /'before' => \[[\s\S]*?'staff' => \$before,[\s\S]*?'permissions' => \$permissionChange\['before_permissions'\]/);
  assert.match(lifecycle, /'organization_assignment' => \$assignment/);
  assert.match(lifecycle, /'effective_date' => \$organizationChanged/);
  assert.match(lifecycle, /'change_reason' => \$organizationChanged/);
  assert.match(lifecycle, /'permissions' => \$permissionChange\['after_permissions'\]/);
  assert.match(lifecycle, /'privileged_role_approval' => \$permissionChange\['approval'\]/);
});

test('staff update endpoint preserves management authorization and structured conflicts', () => {
  assert.match(endpoint, /\$_SERVER\['REQUEST_METHOD'\] !== 'POST'/);
  assert.match(endpoint, /adminRequirePermission\('staff\.edit'\)/);
  assert.match(endpoint, /\$service->update\(\$staffId, \$input, \$operatorUser, \$operatorStaff \?: \[\]\)/);
  assert.match(endpoint, /catch \(StaffIdentityConflictException \$error\)/);
  assert.match(endpoint, /catch \(OrganizationAssignmentConflictException \$error\)/);
  assert.match(endpoint, /jsonResponse\(409/);
});

test('integrated update model commits basic and organization changes together', () => {
  const model = new StaffUpdateModel();
  const updated = model.update({
    name: 'Alice Chen',
    phone: '13900000001',
    storeId: 2,
    positionId: 2,
    role: 'coach',
    effectiveDate: '2026-07-24',
    reason: 'transfer',
  });

  assert.equal(updated.name, 'Alice Chen');
  assert.equal(updated.storeId, 2);
  assert.equal(updated.positionId, 2);
  assert.equal(model.assignments[0].endDate, '2026-07-23');
  assert.equal(model.assignments.length, 2);
  assert.equal(model.audits.length, 1);
});

test('integrated update model rolls every state back after validation failure', () => {
  const model = new StaffUpdateModel();
  const beforeStaff = structuredClone(model.staff.get(1));
  const beforeAssignments = structuredClone(model.assignments);
  assert.throws(() => model.update({
    name: 'Changed Before Failure',
    phone: '13800000002',
    storeId: 3,
    positionId: 2,
    role: 'coach',
    effectiveDate: '2026-07-24',
    reason: 'invalid transfer',
  }), /phone conflict/);

  assert.deepEqual(model.staff.get(1), beforeStaff);
  assert.deepEqual(model.assignments, beforeAssignments);
  assert.equal(model.audits.length, 0);
});
