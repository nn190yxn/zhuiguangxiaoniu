<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/common/mb-compat.php';

final class ResumeClassificationService
{
    private const CLASSIFIER_VERSION = 'mixed-resume-v2';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function classify(int $documentId, array $profile): array
    {
        $document = $this->document($documentId);
        if ((string) $document['intake_mode'] !== 'mixed_requirements') {
            return ['status' => 'single_requirement', 'selected_requirement_id' => (int) $document['requirement_id']];
        }
        if ((int) ($document['classification_version_id'] ?? 0) > 0 && in_array((string) $document['classification_status'], ['classified', 'needs_confirmation'], true)) {
            return $this->existingClassification((int) $document['classification_version_id']);
        }

        $candidates = $this->candidates($documentId, $profile);
        if (!$candidates) {
            return $this->persist($document, [], null, null, 'awaiting_rule', 'awaiting_rule');
        }

        $direct = $this->uniquePositionMatch($candidates, 'filename_matches_position');
        $reason = 'filename_unique_position';
        if ($direct === null) {
            $direct = $this->uniquePositionMatch($candidates, 'profile_role_matches_position');
            $reason = 'profile_role_unique_position';
        }
        $assigned = $direct !== null;
        $selected = $direct ?? $candidates[0];
        $status = $assigned ? 'classified' : 'needs_confirmation';
        $reason = $assigned ? $reason : 'position_not_unique';
        $level = $assigned ? 'high' : ((float) $selected['score'] >= 50.0 ? 'medium' : 'low');

        return $this->persist(
            $document,
            $candidates,
            $assigned ? (int) $selected['requirement_id'] : null,
            ['level' => $level, 'score' => (float) $selected['score']],
            $status,
            $reason
        );
    }

    private function candidates(int $documentId, array $profile): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT scope.requirement_id, requirement.position_name_snapshot, rule.hard_conditions_json, rule.experience_rules_json, rule.keyword_rules_json '
            . 'FROM recruitment_resume_documents document '
            . 'JOIN recruitment_resume_batch_requirements scope ON scope.batch_id = document.batch_id '
            . 'JOIN recruitment_requirements requirement ON requirement.id = scope.requirement_id '
            . 'JOIN recruitment_rule_versions rule ON rule.id = scope.rule_version_id '
            . 'WHERE document.id = ? AND scope.classification_ready = 1 ORDER BY scope.requirement_id ASC'
        );
        $stmt->execute([$documentId]);
        $filename = $this->filename($documentId);
        $profileRole = trim((string) ($profile['current_or_latest_role']['value'] ?? ''));
        $candidates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rule) {
            $evidence = [];
            $score = 0.0;
            $position = trim((string) $rule['position_name_snapshot']);
            $filenameMatchLength = $this->positionMatchLength($filename, $position);
            $filenameMatch = $filenameMatchLength > 0;
            if ($filenameMatch) {
                $score += 45.0;
                $evidence[] = ['type' => 'filename', 'key' => 'position_name', 'matched' => true, 'score' => 45.0];
            }
            $profileRoleMatchLength = $this->positionMatchLength($profileRole, $position);
            $profileRoleMatch = $profileRoleMatchLength > 0;
            if ($profileRoleMatch) {
                $score += 45.0;
                $evidence[] = ['type' => 'profile_role', 'key' => 'current_or_latest_role', 'matched' => true, 'score' => 45.0];
            }

            $keywords = json_decode((string) $rule['keyword_rules_json'], true) ?: [];
            $required = is_array($keywords['required_for_a'] ?? null) ? $keywords['required_for_a'] : [];
            $keywordScore = $required ? 35.0 / count($required) : 0.0;
            foreach ($required as $index => $keyword) {
                $matched = $this->matchesProfile(trim((string) $keyword), $profile);
                if ($matched) {
                    $score += $keywordScore;
                }
                $evidence[] = ['type' => 'keyword', 'key' => 'required_' . $index, 'matched' => $matched, 'score' => round($matched ? $keywordScore : 0.0, 3)];
            }

            $conditions = json_decode((string) $rule['hard_conditions_json'], true) ?: [];
            $conditionScore = $conditions ? 15.0 / count($conditions) : 0.0;
            foreach ($conditions as $index => $condition) {
                $needle = trim((string) (is_array($condition) ? ($condition['keyword'] ?? $condition['condition'] ?? '') : $condition));
                $matched = $this->matchesProfile($needle, $profile);
                if ($matched) {
                    $score += $conditionScore;
                }
                $evidence[] = ['type' => 'hard_condition', 'key' => 'hard_' . $index, 'matched' => $matched, 'score' => round($matched ? $conditionScore : 0.0, 3)];
            }

            $experience = json_decode((string) $rule['experience_rules_json'], true) ?: [];
            $minimumYears = (float) ($experience['a_min_related_years'] ?? 0);
            $years = $profile['relevant_work_years']['value'] ?? null;
            $matched = $years !== null && (float) $years >= $minimumYears;
            if ($matched) {
                $score += 5.0;
            }
            $evidence[] = ['type' => 'experience', 'key' => 'a_min_related_years', 'matched' => $matched, 'score' => $matched ? 5.0 : 0.0];
            $candidates[] = [
                'requirement_id' => (int) $rule['requirement_id'],
                'score' => round(min(100.0, $score), 3),
                'evidence' => $evidence,
                'filename_matches_position' => $filenameMatch,
                'filename_matches_position_length' => $filenameMatchLength,
                'profile_role_matches_position' => $profileRoleMatch,
                'profile_role_matches_position_length' => $profileRoleMatchLength,
            ];
        }
        usort($candidates, static fn (array $left, array $right): int => $right['score'] <=> $left['score'] ?: $left['requirement_id'] <=> $right['requirement_id']);
        return $candidates;
    }

    private function uniquePositionMatch(array $candidates, string $field): ?array
    {
        $matches = array_values(array_filter($candidates, static fn (array $candidate): bool => !empty($candidate[$field])));
        if (!$matches) {
            return null;
        }
        $lengthField = $field . '_length';
        $maxLength = max(array_map(static fn (array $candidate): int => (int) ($candidate[$lengthField] ?? 0), $matches));
        $mostSpecific = array_values(array_filter($matches, static fn (array $candidate): bool => (int) ($candidate[$lengthField] ?? 0) === $maxLength));
        return count($mostSpecific) === 1 ? $mostSpecific[0] : null;
    }

    private function positionMatchLength(string $value, string $position): int
    {
        $normalizedValue = $this->normalizePositionName($value);
        $normalizedPosition = $this->normalizePositionName($position);
        return $normalizedValue !== '' && $normalizedPosition !== '' && mb_stripos($normalizedValue, $normalizedPosition, 0, 'UTF-8') !== false
            ? mb_strlen($normalizedPosition, 'UTF-8')
            : 0;
    }

    private function normalizePositionName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[\s\p{P}\p{S}]+/u', '', $normalized) ?? '';
        $normalized = str_replace(['少儿', '幼儿', '教练员', '体适能'], ['儿童', '儿童', '教练', '体能'], $normalized);
        return preg_replace('/^(?:全职|兼职|实习|见习|初级|中级|高级|资深|主任|主管)+/u', '', $normalized) ?? '';
    }

    private function persist(array $document, array $candidates, ?int $selectedRequirementId, ?array $confidence, string $status, string $reason): array
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT classification_version_id FROM recruitment_resume_documents WHERE id = ? FOR UPDATE');
            $lock->execute([(int) $document['document_id']]);
            $next = $this->pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM recruitment_resume_classification_versions WHERE document_id = ?');
            $next->execute([(int) $document['document_id']]);
            $versionNo = (int) $next->fetchColumn();
            $insert = $this->pdo->prepare(
                'INSERT INTO recruitment_resume_classification_versions (document_id, version_no, candidate_scope_hash, classifier_version, status, selected_requirement_id, confidence_level, confidence_score, reason_code, evidence_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                (int) $document['document_id'], $versionNo, (string) $document['candidate_scope_hash'], self::CLASSIFIER_VERSION,
                $status, $selectedRequirementId, $confidence['level'] ?? null, $confidence['score'] ?? null, $reason,
                json_encode(['candidate_count' => count($candidates)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $versionId = (int) $this->pdo->lastInsertId();
            $candidateInsert = $this->pdo->prepare('INSERT INTO recruitment_resume_classification_candidates (classification_version_id, requirement_id, rank_no, score, evidence_json) VALUES (?, ?, ?, ?, ?)');
            foreach ($candidates as $index => $candidate) {
                $candidateInsert->execute([$versionId, $candidate['requirement_id'], $index + 1, $candidate['score'], json_encode($candidate['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
            $update = $this->pdo->prepare('UPDATE recruitment_resume_documents SET assigned_requirement_id = ?, classification_status = ?, classification_version_id = ? WHERE id = ?');
            $update->execute([$selectedRequirementId, $status === 'classified' ? 'classified' : ($status === 'awaiting_rule' ? 'awaiting_rule' : 'needs_confirmation'), $versionId, (int) $document['document_id']]);
            $this->pdo->commit();
            return ['status' => $status, 'selected_requirement_id' => $selectedRequirementId, 'classification_version_id' => $versionId, 'candidates' => $candidates];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function document(int $documentId): array
    {
        $stmt = $this->pdo->prepare('SELECT document.id AS document_id, document.classification_status, document.classification_version_id, batch.intake_mode, batch.requirement_id, batch.candidate_scope_hash FROM recruitment_resume_documents document JOIN recruitment_resume_batches batch ON batch.id = document.batch_id WHERE document.id = ? LIMIT 1');
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            throw new RecruitmentAdminException('简历文档不存在', 404);
        }
        return $document;
    }

    private function existingClassification(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT status, selected_requirement_id FROM recruitment_resume_classification_versions WHERE id = ? LIMIT 1');
        $stmt->execute([$versionId]);
        $version = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'status' => (string) ($version['status'] ?? 'needs_confirmation'),
            'selected_requirement_id' => isset($version['selected_requirement_id']) ? (int) $version['selected_requirement_id'] : null,
            'classification_version_id' => $versionId,
            'candidates' => [],
        ];
    }

    private function filename(int $documentId): string
    {
        $stmt = $this->pdo->prepare('SELECT file.original_name FROM recruitment_resume_document_pages page JOIN recruitment_resume_files file ON file.id = page.resume_file_id WHERE page.document_id = ? ORDER BY page.page_order ASC LIMIT 1');
        $stmt->execute([$documentId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    private function matchesProfile(string $needle, array $profile): bool
    {
        if ($needle === '') {
            return false;
        }
        foreach ($profile as $field) {
            $values = isset($field['items']) ? $field['items'] : [$field['value'] ?? ''];
            foreach ($values as $value) {
                if (mb_stripos((string) $value, $needle, 0, 'UTF-8') !== false) {
                    return true;
                }
            }
        }
        return false;
    }
}
