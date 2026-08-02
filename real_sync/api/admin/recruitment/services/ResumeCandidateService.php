<?php

declare(strict_types=1);

final class ResumeCandidateService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function applicationForProcessing(int $processingVersionId, array $profile): array
    {
        $context = $this->processingContext($processingVersionId);
        $phone = is_array($profile['phone']['protected'] ?? null) ? $profile['phone']['protected'] : [];
        $email = is_array($profile['email']['protected'] ?? null) ? $profile['email']['protected'] : [];
        $name = mb_substr(trim((string) ($profile['name']['value'] ?? '')), 0, 120, 'UTF-8');
        $candidate = $this->findCandidate((string) ($phone['lookup_hash'] ?? ''), (string) ($email['lookup_hash'] ?? ''));

        $this->pdo->beginTransaction();
        try {
            if ($candidate === null) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO recruitment_candidates (name, name_confidence, phone_ciphertext, phone_display_ciphertext, phone_lookup_hash, phone_confidence, phone_key_version, email_ciphertext, email_lookup_hash, email_confidence) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $name !== '' ? $name : null,
                    (float) ($profile['name']['confidence'] ?? 0),
                    $phone['ciphertext'] ?? null,
                    $phone['display_ciphertext'] ?? null,
                    $phone['lookup_hash'] ?? null,
                    (float) ($profile['phone']['confidence'] ?? 0),
                    $phone['key_version'] ?? null,
                    $email['ciphertext'] ?? null,
                    $email['lookup_hash'] ?? null,
                    (float) ($profile['email']['confidence'] ?? 0),
                ]);
                $candidateId = (int) $this->pdo->lastInsertId();
                if (($phone['lookup_hash'] ?? '') === '' && $name !== '') {
                    $this->recordNameSuspicions($candidateId, $name, $processingVersionId);
                }
            } else {
                $candidateId = (int) $candidate['id'];
            }

            $informationStatus = empty($phone['lookup_hash']) ? 'missing_contact' : $this->informationStatus($profile);
            $stmt = $this->pdo->prepare(
                'INSERT IGNORE INTO recruitment_applications (candidate_id, document_id, requirement_id, rule_version_id, current_processing_version_id, extracted_profile_json, highlights_json, information_status) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $candidateId,
                (int) $context['document_id'],
                (int) $context['requirement_id'],
                (int) $context['rule_version_id'],
                $processingVersionId,
                json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode($this->highlights($profile), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $informationStatus,
            ]);
            $application = $this->applicationByDocument((int) $context['document_id'], (int) $context['requirement_id']);
            $update = $this->pdo->prepare('UPDATE recruitment_applications SET current_processing_version_id = ?, extracted_profile_json = ?, highlights_json = ?, information_status = ? WHERE id = ?');
            $update->execute([
                $processingVersionId,
                json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode($this->highlights($profile), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $informationStatus,
                (int) $application['id'],
            ]);
            $linkExtraction = $this->pdo->prepare('UPDATE recruitment_extraction_results SET application_id = ? WHERE processing_version_id = ?');
            $linkExtraction->execute([(int) $application['id'], $processingVersionId]);
            $linkModel = $this->pdo->prepare('UPDATE recruitment_model_results SET application_id = ? WHERE processing_version_id = ?');
            $linkModel->execute([(int) $application['id'], $processingVersionId]);
            $this->pdo->commit();
            return $this->applicationById((int) $application['id']);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function findCandidate(string $phoneHash, string $emailHash): ?array
    {
        if ($phoneHash !== '') {
            $stmt = $this->pdo->prepare("SELECT * FROM recruitment_candidates WHERE phone_lookup_hash = ? AND record_status = 'active' ORDER BY id ASC LIMIT 1");
            $stmt->execute([$phoneHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }
        if ($emailHash !== '') {
            $stmt = $this->pdo->prepare("SELECT * FROM recruitment_candidates WHERE email_lookup_hash = ? AND record_status = 'active' ORDER BY id ASC LIMIT 1");
            $stmt->execute([$emailHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }
        return null;
    }

    private function recordNameSuspicions(int $candidateId, string $name, int $processingVersionId): void
    {
        $stmt = $this->pdo->prepare("SELECT id FROM recruitment_candidates WHERE name = ? AND id <> ? AND record_status = 'active' ORDER BY id ASC LIMIT 10");
        $stmt->execute([$name, $candidateId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $existingId) {
            $relation = $this->pdo->prepare(
                "INSERT IGNORE INTO recruitment_candidate_relations (relation_type, canonical_candidate_id, related_candidate_id, before_snapshot_json, reason) VALUES ('suspected_duplicate', ?, ?, ?, ?)"
            );
            $relation->execute([
                (int) $existingId,
                $candidateId,
                json_encode(['processing_version_id' => $processingVersionId, 'name' => $name], JSON_UNESCAPED_UNICODE),
                '手机号缺失，姓名一致，需人工核验内容相似度',
            ]);
            $update = $this->pdo->prepare("UPDATE recruitment_candidates SET duplicate_status = 'suspected' WHERE id IN (?, ?)");
            $update->execute([(int) $existingId, $candidateId]);
        }
    }

    private function processingContext(int $processingVersionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_processing_versions WHERE id = ? LIMIT 1');
        $stmt->execute([$processingVersionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentAdminException('处理版本不存在', 404);
        }
        return $row;
    }

    private function applicationByDocument(int $documentId, int $requirementId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_applications WHERE document_id = ? AND requirement_id = ? LIMIT 1');
        $stmt->execute([$documentId, $requirementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentAdminException('候选人投递创建失败', 500);
        }
        return $row;
    }

    private function applicationById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_applications WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id];
    }

    private function informationStatus(array $profile): string
    {
        foreach (['name', 'phone', 'current_or_latest_role', 'total_work_years'] as $field) {
            if (($profile[$field]['status'] ?? 'unknown') !== 'verified') {
                return 'needs_confirmation';
            }
        }
        return 'complete';
    }

    private function highlights(array $profile): array
    {
        return array_values(array_merge(
            $profile['responsibility_highlights']['items'] ?? [],
            $profile['performance_achievements']['items'] ?? []
        ));
    }
}
