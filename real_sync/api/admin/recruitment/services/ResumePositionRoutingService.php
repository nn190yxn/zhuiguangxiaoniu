<?php

declare(strict_types=1);

final class ResumePositionRoutingService
{
    private PDO $pdo;
    private RecruitmentPermissionService $permissionService;

    public function __construct(PDO $pdo, RecruitmentPermissionService $permissionService)
    {
        $this->pdo = $pdo;
        $this->permissionService = $permissionService;
    }

    public function identify(string $filename, string $resumeText, array $scope): array
    {
        [$scopeWhere, $scopeParams] = $this->permissionService->requirementWhereClause($scope, 'requirement');
        $stmt = $this->pdo->prepare(
            'SELECT requirement.id, requirement.requirement_no, requirement.position_id, requirement.position_name_snapshot, '
            . 'store.name AS store_name FROM recruitment_requirements requirement '
            . 'LEFT JOIN stores store ON store.id = requirement.store_id '
            . "WHERE requirement.status = 'approved' AND " . $scopeWhere
        );
        $stmt->execute($scopeParams);
        return self::rankPositionCandidates($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $filename, $resumeText);
    }

    public static function rankPositionCandidates(array $requirements, string $filename, string $resumeText): array
    {
        $sources = [
            'filename' => self::normalized($filename),
            'resume_text' => self::normalized($resumeText),
        ];
        $positions = [];
        foreach ($requirements as $requirement) {
            $positionName = trim((string) ($requirement['position_name_snapshot'] ?? ''));
            if ($positionName === '') {
                continue;
            }
            $positionId = (int) ($requirement['position_id'] ?? 0);
            $key = $positionId > 0 ? 'id:' . $positionId : 'name:' . self::normalized($positionName);
            if (!isset($positions[$key])) {
                $positions[$key] = [
                    'position_id' => $positionId ?: null,
                    'position_name' => $positionName,
                    'requirements' => [],
                    'evidence' => [],
                    'confidence' => 0.0,
                ];
            }
            $positions[$key]['requirements'][] = [
                'requirement_id' => (int) ($requirement['id'] ?? 0),
                'requirement_no' => (string) ($requirement['requirement_no'] ?? ''),
                'store_name' => (string) ($requirement['store_name'] ?? ''),
            ];
        }

        foreach ($positions as &$position) {
            foreach (self::aliases((string) $position['position_name']) as $alias) {
                $needle = self::normalized($alias);
                foreach ($sources as $source => $haystack) {
                    if ($needle === '' || $haystack === '' || !str_contains($haystack, $needle)) {
                        continue;
                    }
                    $score = $source === 'filename' ? 0.70 : 0.55;
                    $position['confidence'] = max((float) $position['confidence'], $score);
                    $position['evidence'][] = ['source' => $source, 'matched_text' => $alias, 'score' => $score];
                }
            }
            $position['confidence'] = round((float) $position['confidence'], 4);
        }
        unset($position);

        $matched = array_values(array_filter($positions, static fn (array $position): bool => $position['confidence'] > 0));
        usort($matched, static fn (array $left, array $right): int => $right['confidence'] <=> $left['confidence']);
        return $matched;
    }

    private static function aliases(string $positionName): array
    {
        $aliases = [$positionName];
        $normalized = self::normalized($positionName);
        if (str_contains($normalized, '线上') && str_contains($normalized, '顾问')) {
            array_push($aliases, '线上顾问', '在线顾问', '网络顾问');
        } elseif (str_contains($normalized, '店长')) {
            array_push($aliases, '店长', '门店店长');
        } elseif (str_contains($normalized, '教练')) {
            array_push($aliases, '教练', '健身教练', '体适能教练', '少儿体能教练');
        } elseif (str_contains($normalized, '顾问')) {
            array_push($aliases, '顾问', '课程顾问', '销售顾问');
        }
        return array_values(array_unique(array_filter(array_map('trim', $aliases))));
    }

    private static function normalized(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', trim($value)), 'UTF-8');
    }
}
