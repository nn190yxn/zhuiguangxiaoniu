<?php

declare(strict_types=1);

final class DrillContentVersionStateMachine
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    private const TRANSITIONS = [
        self::STATUS_DRAFT => [
            'submit_review' => self::STATUS_IN_REVIEW,
        ],
        self::STATUS_IN_REVIEW => [
            'reject' => self::STATUS_DRAFT,
            'approve' => self::STATUS_PUBLISHED,
        ],
        self::STATUS_PUBLISHED => [
            'archive' => self::STATUS_ARCHIVED,
        ],
        self::STATUS_ARCHIVED => [],
    ];

    public static function transition(string $currentStatus, string $event): string
    {
        if (!array_key_exists($currentStatus, self::TRANSITIONS)) {
            throw new InvalidArgumentException('Unknown content version status.');
        }

        $nextStatus = self::TRANSITIONS[$currentStatus][$event] ?? null;
        if ($nextStatus === null) {
            throw new DomainException(sprintf(
                'Content version cannot handle event "%s" from status "%s".',
                $event,
                $currentStatus
            ));
        }

        return $nextStatus;
    }

    public static function assertContentMutable(string $status): void
    {
        if ($status !== self::STATUS_DRAFT) {
            throw new DomainException('Only draft content versions can be changed.');
        }
    }

    public static function nextVersionNo(array $existingVersionNumbers): int
    {
        $versionNumbers = array_map('intval', $existingVersionNumbers);
        $highestVersion = $versionNumbers === [] ? 0 : max($versionNumbers);

        return $highestVersion + 1;
    }

    public static function snapshotHash(array $snapshot): string
    {
        $normalized = self::normalize($snapshot);

        return hash('sha256', json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
