<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/common/mb-compat.php';

final class ResumeProfileSchema
{
    public const SCALAR_FIELDS = [
        'name', 'phone', 'email', 'current_or_latest_role', 'total_work_years',
        'relevant_work_years', 'education_level', 'major',
    ];
    public const LIST_FIELDS = [
        'industry_experience', 'employment_history', 'responsibility_highlights',
        'performance_achievements', 'skills', 'certificates', 'job_keywords', 'manual_checks',
    ];

    public static function emptyProfile(): array
    {
        $profile = [];
        foreach (self::SCALAR_FIELDS as $field) {
            $profile[$field] = [
                'value' => in_array($field, ['total_work_years', 'relevant_work_years'], true) ? null : '',
                'confidence' => 0.0,
                'evidence' => [],
                'status' => 'unknown',
            ];
        }
        foreach (self::LIST_FIELDS as $field) {
            $profile[$field] = ['items' => [], 'confidence' => 0.0, 'evidence' => [], 'status' => 'unknown'];
        }
        return $profile;
    }

    public static function validate(array $profile, array $pages): array
    {
        $validated = self::emptyProfile();
        foreach ($validated as $field => $empty) {
            $candidate = is_array($profile[$field] ?? null) ? $profile[$field] : [];
            $confidence = (float) ($candidate['confidence'] ?? 0);
            $confidence = max(0.0, min(1.0, $confidence));
            if (in_array($field, self::LIST_FIELDS, true)) {
                $items = is_array($candidate['items'] ?? null) ? $candidate['items'] : [];
                $validated[$field]['items'] = array_values(array_slice(array_filter(array_map(static function ($item): string {
                    return mb_substr(trim(is_scalar($item) ? (string) $item : ''), 0, 500, 'UTF-8');
                }, $items)), 0, 100));
            } else {
                $value = $candidate['value'] ?? $empty['value'];
                if (in_array($field, ['total_work_years', 'relevant_work_years'], true)) {
                    $value = is_numeric($value) ? max(0.0, min(80.0, (float) $value)) : null;
                } else {
                    $value = mb_substr(trim(is_scalar($value) ? (string) $value : ''), 0, 1000, 'UTF-8');
                }
                $validated[$field]['value'] = $value;
            }
            $validated[$field]['confidence'] = $confidence;
            $validated[$field]['evidence'] = self::validEvidence($candidate['evidence'] ?? [], $pages);
            if (in_array($field, ['phone', 'email'], true) && is_array($candidate['protected'] ?? null)) {
                $validated[$field]['protected'] = $candidate['protected'];
            }
            $hasValue = in_array($field, self::LIST_FIELDS, true)
                ? $validated[$field]['items'] !== []
                : $validated[$field]['value'] !== null && $validated[$field]['value'] !== '';
            $hasEvidence = $validated[$field]['evidence'] !== [];
            if ($hasValue && $hasEvidence && $confidence >= 0.6) {
                $validated[$field]['status'] = 'verified';
            } elseif ($hasValue) {
                $validated[$field]['status'] = 'manual_check';
            }
        }
        return $validated;
    }

    public static function merge(array $base, array $candidate, array $pages): array
    {
        $candidate = self::validate($candidate, $pages);
        $base = self::validate($base, $pages);
        foreach ($base as $field => $value) {
            if ((float) $candidate[$field]['confidence'] > (float) $value['confidence']) {
                $base[$field] = $candidate[$field];
            }
        }
        return $base;
    }

    private static function validEvidence($evidence, array $pages): array
    {
        if (!is_array($evidence)) {
            return [];
        }
        $pageText = [];
        foreach ($pages as $page) {
            $pageText[(int) ($page['page_no'] ?? 0)] = (string) ($page['text'] ?? '');
        }
        $valid = [];
        foreach (array_slice($evidence, 0, 20) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pageNo = (int) ($item['page_no'] ?? 0);
            $text = mb_substr(trim((string) ($item['text'] ?? $item['source_text'] ?? '')), 0, 1000, 'UTF-8');
            if ($pageNo < 1 || $text === '' || !isset($pageText[$pageNo])) {
                continue;
            }
            if (mb_strpos($pageText[$pageNo], $text, 0, 'UTF-8') === false) {
                continue;
            }
            $valid[] = ['page_no' => $pageNo, 'text' => $text];
        }
        return $valid;
    }
}
