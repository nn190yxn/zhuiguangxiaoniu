<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillContentPolicy.php';

final class DrillReferenceMaterialService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function publish(
        int $versionId,
        int $actorStaffId,
        string $authorizationReference,
        string $effectiveFrom,
        string $effectiveUntil
    ): array {
        if ($actorStaffId <= 0) {
            throw new InvalidArgumentException('操作员工 ID 必须为正整数。');
        }
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT * FROM drill_reference_material_versions WHERE id = ? LIMIT 1 FOR UPDATE');
            $select->execute([$versionId]);
            $version = $select->fetch(PDO::FETCH_ASSOC);
            if (!$version || !in_array($version['status'], ['draft', 'review_pending'], true)) {
                throw new DomainException('参考资料版本不存在或当前状态不可发布。');
            }
            $issues = $this->openIssuesForVersion($versionId);
            $candidate = $version + [
                'authorization_status' => 'authorized',
                'authorization_reference' => trim($authorizationReference),
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
            ];
            $publishedAt = new DateTimeImmutable('now');
            $failures = DrillContentPolicy::referencePreflight($candidate, $publishedAt, $issues);
            if ($failures !== []) {
                throw new DomainException('参考资料发布前核验失败：' . implode(', ', $failures));
            }
            $update = $this->pdo->prepare(
                "UPDATE drill_reference_material_versions SET authorization_status = 'authorized', authorization_reference = ?, "
                . "effective_from = ?, effective_until = ?, status = 'published', published_by_staff_id = ?, published_at = ? WHERE id = ?"
            );
            $update->execute([$authorizationReference, $effectiveFrom, $effectiveUntil, $actorStaffId, $publishedAt->format('Y-m-d H:i:s'), $versionId]);
            $this->pdo->commit();
            return ['version_id' => $versionId, 'status' => 'published'];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function assertBindable(int $versionId, DateTimeImmutable $at, string $expectedHash): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_reference_material_versions WHERE id = ? LIMIT 1');
        $stmt->execute([$versionId]);
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$version || $version['status'] !== 'published') {
            throw new DomainException('评分只能绑定已发布参考资料版本。');
        }
        $failures = DrillContentPolicy::referencePreflight($version, $at, $this->openIssuesForVersion($versionId));
        if ($failures !== [] || !hash_equals((string) $version['content_hash'], $expectedHash)) {
            throw new DomainException('参考资料已失效、哈希变化或存在发布阻断项。');
        }
        return $version;
    }

    private function openIssuesForVersion(int $versionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT issue.status, issue.severity FROM drill_content_review_issues issue '
            . 'INNER JOIN drill_content_import_items item ON item.id = issue.item_id '
            . "WHERE item.target_version_id = ? AND issue.status = 'open'"
        );
        $stmt->execute([$versionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
