<?php

declare(strict_types=1);

final class DrillLearningPolicy
{
    private const TRANSITIONS = [
        'draft' => ['submit_review' => 'review_pending'],
        'review_pending' => ['reject' => 'draft', 'approve' => 'published'],
        'published' => ['retire' => 'retired'],
        'retired' => [],
    ];

    public static function transition(string $currentStatus, string $event): string
    {
        $nextStatus = self::TRANSITIONS[$currentStatus][$event] ?? null;
        if ($nextStatus === null) {
            throw new DomainException(sprintf('学习内容状态 %s 不支持事件 %s。', $currentStatus, $event));
        }
        return $nextStatus;
    }

    public static function assertKnowledgePayload(array $payload): void
    {
        if (trim((string) ($payload['knowledge_code'] ?? '')) === '') {
            throw new DomainException('知识点编码不能为空。');
        }
        if (trim((string) ($payload['title'] ?? '')) === '' || !is_array($payload['content'] ?? null) || $payload['content'] === []) {
            throw new DomainException('知识点必须包含标题和结构化内容。');
        }
    }

    public static function assertResourcePayload(array $payload): void
    {
        $allowedTypes = ['article', 'audio', 'video', 'card', 'course', 'exercise'];
        if (trim((string) ($payload['resource_code'] ?? '')) === '') {
            throw new DomainException('学习资源编码不能为空。');
        }
        if (trim((string) ($payload['title'] ?? '')) === '' || !is_array($payload['content'] ?? null) || $payload['content'] === []) {
            throw new DomainException('学习资源必须包含标题和结构化内容。');
        }
        if (!in_array((string) ($payload['resource_type'] ?? ''), $allowedTypes, true)) {
            throw new DomainException('学习资源类型无效。');
        }
        if (trim((string) ($payload['mobile_locator'] ?? '')) === '') {
            throw new DomainException('学习资源必须提供移动端入口。');
        }
        if ((int) ($payload['estimated_minutes'] ?? 0) <= 0) {
            throw new DomainException('学习资源预计时长必须为正整数。');
        }
    }

    public static function reinforceableCriteria(array $criticalItems): array
    {
        $criteria = [];
        foreach ($criticalItems as $item) {
            if (is_string($item)) {
                $code = trim($item);
                $reinforceable = true;
            } elseif (is_array($item)) {
                $code = trim((string) ($item['code'] ?? $item['criterion_code'] ?? ''));
                $reinforceable = ($item['reinforceable'] ?? true) === true;
            } else {
                throw new DomainException('评分关键项结构无效。');
            }
            if ($code === '' || isset($criteria[$code])) {
                throw new DomainException('评分关键项编码必须存在且保持唯一。');
            }
            if ($reinforceable) {
                $criteria[$code] = true;
            }
        }
        return array_keys($criteria);
    }

    public static function assertMappingLinks(array $links, array $expectedCriteria): void
    {
        $expected = array_fill_keys($expectedCriteria, true);
        $seen = [];
        foreach ($links as $link) {
            $dimensionCode = trim((string) ($link['dimension_code'] ?? ''));
            $criterionCode = trim((string) ($link['criterion_code'] ?? ''));
            $pointVersionId = (int) ($link['knowledge_point_version_id'] ?? 0);
            $resourceVersionIds = $link['learning_resource_version_ids'] ?? [];
            if ($dimensionCode === '' || $criterionCode === '' || $pointVersionId <= 0 || !is_array($resourceVersionIds)) {
                throw new DomainException('知识映射必须包含评分维度、关键项、知识点版本和资源版本列表。');
            }
            if (!isset($expected[$criterionCode])) {
                throw new DomainException('知识映射引用了评分规则外的可补强关键项。');
            }
            $identity = $criterionCode . ':' . $pointVersionId;
            if (isset($seen[$identity])) {
                throw new DomainException('同一关键项与知识点版本只能映射一次。');
            }
            $seen[$identity] = true;
            foreach ($resourceVersionIds as $resourceVersionId) {
                if ((int) $resourceVersionId <= 0) {
                    throw new DomainException('学习资源版本 ID 必须为正整数。');
                }
            }
        }
    }

    public static function failedCriteria(array $criticalResults): array
    {
        $failed = [];
        foreach ($criticalResults as $key => $value) {
            if (is_string($key) && is_bool($value)) {
                if (!$value) {
                    $failed[$key] = true;
                }
                continue;
            }
            if (!is_array($value)) {
                throw new DomainException('关键项评分结果结构无效。');
            }
            $code = trim((string) ($value['code'] ?? $value['criterion_code'] ?? (is_string($key) ? $key : '')));
            $passed = $value['passed'] ?? $value['met'] ?? null;
            if ($code === '' || !is_bool($passed)) {
                throw new DomainException('关键项评分结果必须包含编码和布尔通过状态。');
            }
            if (!$passed) {
                $failed[$code] = true;
            }
        }
        return array_keys($failed);
    }

    public static function publishedRecommendations(array $rows, int $mappingVersionId, int $domainId): array
    {
        return array_values(array_filter($rows, static fn(array $row): bool =>
            (int) ($row['mapping_version_id'] ?? 0) === $mappingVersionId
            && (int) ($row['domain_id'] ?? 0) === $domainId
            && ($row['mapping_status'] ?? null) === 'published'
            && ($row['knowledge_status'] ?? null) === 'published'
            && ($row['resource_status'] ?? null) === 'published'
            && trim((string) ($row['mobile_locator'] ?? '')) !== ''
        ));
    }

    public static function gapFingerprint(
        int $domainId,
        int $mappingVersionId,
        int $rubricVersionId,
        string $dimensionCode,
        string $criterionCode,
        ?int $knowledgePointId,
        string $gapType
    ): string {
        return hash('sha256', implode('|', [
            $domainId,
            $mappingVersionId,
            $rubricVersionId,
            $dimensionCode,
            $criterionCode,
            $knowledgePointId ?? 0,
            $gapType,
        ]));
    }
}
