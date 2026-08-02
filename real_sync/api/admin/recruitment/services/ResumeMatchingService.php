<?php

declare(strict_types=1);

final class ResumeMatchingService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function match(int $applicationId, array $profile, array $rule): array
    {
        $this->pdo->prepare('DELETE FROM recruitment_match_evidence WHERE application_id = ?')->execute([$applicationId]);
        $evidence = [];
        $hardConditions = json_decode((string) ($rule['hard_conditions_json'] ?? '[]'), true) ?: [];
        foreach ($hardConditions as $index => $condition) {
            $condition = is_array($condition) ? $condition : ['condition' => (string) $condition];
            $needle = trim((string) ($condition['keyword'] ?? $condition['condition'] ?? ''));
            $evidence[] = $this->evaluateTextRule('hard_condition', 'hard_' . $index, $needle, $profile, 0.0);
        }
        $experienceRules = json_decode((string) ($rule['experience_rules_json'] ?? '[]'), true) ?: [];
        $minimumYears = (float) ($experienceRules['a_min_related_years'] ?? 0);
        $yearsField = $profile['relevant_work_years'] ?? [];
        $years = $yearsField['value'] ?? null;
        $evidence[] = $this->evidence(
            'experience', 'a_min_related_years',
            $years === null ? 'unknown' : ((float) $years >= $minimumYears ? 'matched' : 'unmatched'),
            $years === null ? 0.0 : min(40.0, $minimumYears > 0 ? ((float) $years / $minimumYears) * 40.0 : 40.0),
            $yearsField
        );

        $keywordRules = json_decode((string) ($rule['keyword_rules_json'] ?? '[]'), true) ?: [];
        $requiredKeywords = is_array($keywordRules['required_for_a'] ?? null) ? $keywordRules['required_for_a'] : [];
        $keywordScore = $requiredKeywords ? 40.0 / count($requiredKeywords) : 0.0;
        foreach ($requiredKeywords as $index => $keyword) {
            $evidence[] = $this->evaluateTextRule('keyword', 'required_' . $index, trim((string) $keyword), $profile, $keywordScore);
        }
        foreach (($profile['skills']['items'] ?? []) as $index => $skill) {
            $evidence[] = $this->evidence('transferable_skill', 'skill_' . $index, 'matched', min(5.0, 20.0 / max(1, count($profile['skills']['items']))), $profile['skills']);
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO recruitment_match_evidence (application_id, dimension_type, rule_key, match_status, score, source_text, page_no, confidence) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($evidence as $item) {
            $insert->execute([
                $applicationId, $item['dimension_type'], $item['rule_key'], $item['match_status'], $item['score'],
                $item['source_text'], $item['page_no'], $item['confidence'],
            ]);
        }
        return $evidence;
    }

    private function evaluateTextRule(string $type, string $key, string $needle, array $profile, float $score): array
    {
        if ($needle === '') {
            return ['dimension_type' => $type, 'rule_key' => $key, 'match_status' => 'unknown', 'score' => 0.0, 'source_text' => null, 'page_no' => null, 'confidence' => 0.0];
        }
        foreach ($profile as $field) {
            $values = isset($field['items']) ? $field['items'] : [$field['value'] ?? ''];
            foreach ($values as $value) {
                if (mb_stripos((string) $value, $needle, 0, 'UTF-8') !== false) {
                    return $this->evidence($type, $key, 'matched', $score, $field);
                }
            }
            foreach (($field['evidence'] ?? []) as $source) {
                if (mb_stripos((string) ($source['text'] ?? ''), $needle, 0, 'UTF-8') !== false) {
                    return $this->evidence($type, $key, 'matched', $score, $field);
                }
            }
        }
        return ['dimension_type' => $type, 'rule_key' => $key, 'match_status' => 'unknown', 'score' => 0.0, 'source_text' => null, 'page_no' => null, 'confidence' => 0.0];
    }

    private function evidence(string $type, string $key, string $status, float $score, array $field): array
    {
        $source = is_array($field['evidence'][0] ?? null) ? $field['evidence'][0] : [];
        if ($status === 'matched' && (!$source || ($field['status'] ?? '') !== 'verified')) {
            $status = 'manual_check';
            $score = 0.0;
        }
        return [
            'dimension_type' => $type,
            'rule_key' => $key,
            'match_status' => $status,
            'score' => round(max(0.0, $score), 2),
            'source_text' => isset($source['text']) ? mb_substr((string) $source['text'], 0, 1000, 'UTF-8') : null,
            'page_no' => isset($source['page_no']) ? (int) $source['page_no'] : null,
            'confidence' => (float) ($field['confidence'] ?? 0),
        ];
    }
}
