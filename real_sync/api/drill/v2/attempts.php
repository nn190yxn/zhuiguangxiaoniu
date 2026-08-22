<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/DrillConversationService.php';

$context = drillV2Bootstrap(['POST']);
$input = drillV2Input();
$pdo = getDB();
$staffId = (int) ($context['staff_id'] ?? 0);

try {
    $result = drillV2RunIdempotent($pdo, $context, 'drill.attempts.' . (string) ($input['action'] ?? 'create'), $input, function () use ($pdo, $staffId, $input): array {
        $conversation = new DrillConversationService($pdo);
        $action = (string) ($input['action'] ?? 'create');
        $attemptId = (int) ($input['attempt_id'] ?? 0);
        $expectedVersion = (int) ($input['status_version'] ?? 0);
        if ($action === 'resume') {
            return $conversation->resumeAttempt($attemptId, $staffId);
        }
        if ($action === 'pause') {
            return $conversation->pauseAttempt($attemptId, $staffId, $expectedVersion);
        }
        if ($action === 'resume_paused') {
            return $conversation->resumePausedAttempt($attemptId, $staffId, $expectedVersion);
        }
        if ($action === 'end') {
            $result = $conversation->endAttempt($attemptId, $staffId, $expectedVersion, new DateTimeImmutable('now'));
            return $result + ['status_resource' => '/api/drill/v2/attempt-status.php?attempt_id=' . $attemptId];
        }
        if ($action === 'create_self_practice') {
            $created = $conversation->createSelfPractice(
                $staffId,
                (int) ($input['scenario_version_id'] ?? 0),
                (array) ($input['session_goal'] ?? []),
                new DateTimeImmutable('now'),
                (array) ($input['selection_context'] ?? [])
            );
            $id = (int) $created['attempt']['attempt_id'];
            return $created + ['status_resource' => '/api/drill/v2/attempt-status.php?attempt_id=' . $id];
        }
        if ($action !== 'create' || (int) ($input['assignment_id'] ?? 0) <= 0 || (int) ($input['plan_item_id'] ?? 0) <= 0) {
            throw new DomainException('员工端实例创建必须关联本人必修任务和计划项。');
        }
        $created = $conversation->createFromAssignment((int) $input['assignment_id'], $staffId, (int) $input['plan_item_id'], (array) ($input['session_goal'] ?? []), new DateTimeImmutable('now'));
        $id = (int) $created['attempt']['id'];
        $participants = (array) ($input['participants'] ?? [['participant_key' => 'employee', 'role_code' => 'employee']]);
        $scoreSubject = trim((string) ($input['score_subject_key'] ?? 'employee'));
        $insert = $pdo->prepare("INSERT IGNORE INTO drill_attempt_participants (attempt_id, participant_key, staff_id, role_code, source_type, mapping_status, mapping_confidence, confirmed_by, confirmed_at) VALUES (?, ?, ?, ?, 'employee_input', 'confirmed', 1, ?, CURRENT_TIMESTAMP)");
        foreach ($participants as $participant) {
            $key = trim((string) ($participant['participant_key'] ?? ''));
            $role = trim((string) ($participant['role_code'] ?? ''));
            if ($key === '' || $role === '') { throw new DomainException('参与者角色信息不完整。'); }
            $insert->execute([$id, $key, $key === 'employee' ? $staffId : null, $role, $staffId]);
        }
        $subject = $pdo->prepare("INSERT IGNORE INTO drill_attempt_score_subjects (attempt_id, participant_key, subject_type, status, confirmed_by, confirmed_at) VALUES (?, ?, 'employee', 'confirmed', ?, CURRENT_TIMESTAMP)");
        $subject->execute([$id, $scoreSubject, $staffId]);
        return $created + ['recording_authorization' => (array) ($input['recording_authorization'] ?? []), 'status_resource' => '/api/drill/v2/attempt-status.php?attempt_id=' . $id];
    });
    $status = in_array((string) ($input['action'] ?? 'create'), ['end'], true) ? 202 : 201;
    drillV2Success($result, 'success', $status);
} catch (DrillIdempotencyException $error) { drillV2Error($error->statusCode(), $error->getMessage(), [], $error->statusCode());
} catch (DomainException $error) { drillV2Error(400, $error->getMessage(), [], 400);
} catch (Throwable $error) { error_log('Drill v2 attempts failed: ' . $error->getMessage()); drillV2Error(500, '演练实例处理失败', [], 500); }
