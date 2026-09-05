<?php

declare(strict_types=1);

require_once __DIR__ . '/../knowledge/EmployeeKnowledgeVisibilityQuery.php';

final class LessonKnowledgeMatcher
{
    private const CONTENT_TYPES = ['action', 'game', 'safety'];
    private const HIGH_RISK_KEYWORDS = ['跳箱', '翻滚', '前滚翻', '后滚翻', '倒立', '攀爬', '越障', '对练', '搏击'];
    private const STOP_TERMS = ['训练', '课程', '活动', '游戏', '教学', '学生', '学员', '动作', '练习', '能力', '目标', '环节', '进行'];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function optimize(int $submissionId, int $actorStaffId, ?int $actorUserId = null): array
    {
        $pdo = $this->requireDatabase();
        $snapshot = $this->submissionSnapshot($submissionId, $actorStaffId);
        $content = json_decode((string) $snapshot['content_json'], true);
        if (!is_array($content)) {
            throw new PlatformApiException(409, 'lesson_version_unavailable', '当前教案版本内容无效');
        }

        $matches = $this->match($content, $this->loadPublishedCandidates());
        $pdo->beginTransaction();
        try {
            $locked = $this->lockedSubmission($submissionId, $actorStaffId);
            if ((int) $locked['current_version_id'] !== (int) $snapshot['version_id']) {
                throw new PlatformApiException(409, 'lesson_submission_conflict', '教案版本已变化，请重新执行优化');
            }
            $existing = $this->existingSuggestions($submissionId, (int) $snapshot['version_id']);
            $insert = $pdo->prepare(
                'INSERT INTO lesson_suggestions '
                . '(submission_id, version_id, suggestion_type, priority, field_path, message, reason, source_type, knowledge_item_id, knowledge_version_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertedCount = 0;
            foreach ($matches as &$match) {
                $key = $this->suggestionKey($match);
                if (isset($existing[$key])) {
                    $match['suggestion_id'] = (int) $existing[$key]['id'];
                    $match['decision'] = (string) $existing[$key]['decision'];
                    continue;
                }
                $insert->execute([
                    $submissionId,
                    (int) $snapshot['version_id'],
                    $match['suggestion_type'],
                    $match['priority'],
                    $match['field_path'],
                    $match['message'],
                    $match['reason'],
                    'knowledge_card',
                    $match['knowledge_item_id'],
                    $match['knowledge_version_id'],
                ]);
                $match['suggestion_id'] = (int) $pdo->lastInsertId();
                $match['decision'] = 'pending';
                $insertedCount++;
            }
            unset($match);
            $this->audit($submissionId, (int) $snapshot['version_id'], $actorStaffId, $actorUserId, count($matches), $insertedCount);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }

        return [
            'submission_id' => $submissionId,
            'version_id' => (int) $snapshot['version_id'],
            'version_no' => (int) $snapshot['version_no'],
            'suggestions' => $matches,
            'suggestion_count' => count($matches),
            'inserted_count' => $insertedCount,
        ];
    }

    public function match(array $content, array $candidates): array
    {
        $eligible = array_values(array_filter($candidates, static function (array $candidate): bool {
            return (int) ($candidate['status'] ?? 0) === 1
                && (string) ($candidate['publication_status'] ?? '') === 'published'
                && in_array((string) ($candidate['content_type'] ?? ''), self::CONTENT_TYPES, true);
        }));
        $suggestions = [];
        $selectedCards = [];
        $phases = is_array($content['phases'] ?? null) ? $content['phases'] : [];
        foreach ($phases as $index => $phase) {
            if (!is_array($phase) || trim($this->text($phase)) === '') continue;
            $context = $this->context($content, $phase);
            foreach ([['action', 2], ['game', 1]] as [$type, $limit]) {
                foreach ($this->rank($eligible, $type, $context, $limit) as $ranked) {
                    $suggestions[] = $this->phaseSuggestion($ranked, (int) $index);
                    $selectedCards[(int) $ranked['id']] = $ranked;
                }
            }
        }

        $globalContext = $this->context($content, ['name' => $this->text($phases), 'activity' => $this->text($phases)]);
        foreach ($this->rank($eligible, 'safety', $globalContext, 3) as $ranked) {
            $suggestions[] = $this->safetySuggestion($ranked);
        }
        $suggestions = [...$suggestions, ...$this->supportingSuggestions($content, array_values($selectedCards))];

        $unique = [];
        foreach ($suggestions as $suggestion) {
            $key = $this->suggestionKey($suggestion);
            if (!isset($unique[$key]) || (int) $suggestion['score'] > (int) $unique[$key]['score']) $unique[$key] = $suggestion;
        }
        $result = array_values($unique);
        usort($result, static fn(array $left, array $right): int => [$right['score'], $left['knowledge_item_id']] <=> [$left['score'], $right['knowledge_item_id']]);
        return $result;
    }

    private function submissionSnapshot(int $submissionId, int $actorStaffId): array
    {
        if ($submissionId <= 0 || $actorStaffId <= 0) throw new InvalidArgumentException('教案或员工身份无效');
        $stmt = $this->requireDatabase()->prepare(
            'SELECT s.author_staff_id, s.status, s.current_version_id, s.status_version, '
            . 'v.id AS version_id, v.version_no, v.content_json FROM lesson_submissions s '
            . 'LEFT JOIN lesson_versions v ON v.id = s.current_version_id AND v.submission_id = s.id WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PlatformApiException(404, 'lesson_submission_not_found', '教案不存在');
        $this->assertEditableByAuthor($row, $actorStaffId);
        if ((int) ($row['version_id'] ?? 0) <= 0) throw new PlatformApiException(409, 'lesson_version_unavailable', '当前教案没有可优化的结构化版本');
        return $row;
    }

    private function lockedSubmission(int $submissionId, int $actorStaffId): array
    {
        $stmt = $this->requireDatabase()->prepare('SELECT author_staff_id, status, current_version_id FROM lesson_submissions WHERE id = ? FOR UPDATE');
        $stmt->execute([$submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PlatformApiException(404, 'lesson_submission_not_found', '教案不存在');
        $this->assertEditableByAuthor($row, $actorStaffId);
        return $row;
    }

    private function assertEditableByAuthor(array $submission, int $actorStaffId): void
    {
        if ((int) ($submission['author_staff_id'] ?? 0) !== $actorStaffId) {
            throw new PlatformApiException(403, 'lesson_submission_forbidden', '只能优化自己创建的教案');
        }
        if (!in_array((string) ($submission['status'] ?? ''), ['draft', 'editable', 'returned'], true)) {
            throw new PlatformApiException(409, 'lesson_submission_locked', '当前教案状态不允许刷新优化建议');
        }
    }

    private function loadPublishedCandidates(): array
    {
        $knowledgeSource = EmployeeKnowledgeVisibilityQuery::fromCurrentVersion();
        $sql = "SELECT k.id, k.item_code, kv.version_id AS knowledge_version_id, "
            . "COALESCE(NULLIF(kv.title, ''), k.title) AS title, COALESCE(NULLIF(kv.summary, ''), k.summary) AS summary, "
            . "COALESCE(NULLIF(kv.content, ''), k.content) AS content, COALESCE(NULLIF(kv.content_type, ''), k.content_type) AS content_type, "
            . "COALESCE(NULLIF(kv.domain_code, ''), k.domain_code) AS domain_code, COALESCE(NULLIF(kv.risk_level, ''), k.risk_level) AS risk_level, "
            . "COALESCE(NULLIF(kv.subject, ''), k.subject) AS subject, COALESCE(NULLIF(kv.age_group, ''), k.age_group) AS age_group, "
            . "COALESCE(NULLIF(kv.training_type, ''), k.training_type) AS training_type, COALESCE(kv.tags_json, k.tags) AS tags, k.status, k.publication_status, "
            . "(SELECT kis.raw_frontmatter_json FROM knowledge_item_sources kis WHERE kis.knowledge_item_id = k.id "
            . "ORDER BY (kis.batch_id = k.source_batch_id) DESC, kis.source_id DESC LIMIT 1) AS source_metadata_json "
            . "FROM " . $knowledgeSource . " "
            . "WHERE 1 = 1 "
            . "AND COALESCE(NULLIF(kv.content_type, ''), k.content_type) IN ('action', 'game', 'safety') "
            . 'ORDER BY k.id DESC LIMIT 2000';
        return $this->requireDatabase()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function existingSuggestions(int $submissionId, int $versionId): array
    {
        $stmt = $this->requireDatabase()->prepare(
            "SELECT id, suggestion_type, field_path, knowledge_item_id, knowledge_version_id, decision FROM lesson_suggestions "
            . "WHERE submission_id = ? AND version_id = ? AND source_type = 'knowledge_card'"
        );
        $stmt->execute([$submissionId, $versionId]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $existing[$this->suggestionKey($row)] = $row;
        return $existing;
    }

    private function rank(array $candidates, string $type, array $context, int $limit): array
    {
        $ranked = [];
        foreach ($candidates as $candidate) {
            if ((string) ($candidate['content_type'] ?? '') !== $type) continue;
            [$score, $matches] = $this->score($candidate, $context, $type);
            if ($score < 20) continue;
            $candidate['score'] = $score;
            $candidate['matched_dimensions'] = $matches;
            $candidate['_metadata'] = $this->metadata($candidate);
            $ranked[] = $candidate;
        }
        usort($ranked, static fn(array $left, array $right): int => [$right['score'], $left['id']] <=> [$left['score'], $right['id']]);
        return array_slice($ranked, 0, $limit);
    }

    private function score(array $candidate, array $context, string $type): array
    {
        $score = 0;
        $matches = [];
        $metadata = $this->metadata($candidate);
        $title = $this->normalize((string) ($candidate['title'] ?? ''));
        $searchable = $this->normalize($this->text([$candidate['title'] ?? '', $candidate['subject'] ?? '', $candidate['tags'] ?? '', $candidate['content'] ?? '']));
        if ($title !== '' && mb_strlen($title, 'UTF-8') >= 2 && str_contains($context['activity'], $title)) {
            $score += 35;
            $matches[] = '训练项目';
        }
        $tagHits = 0;
        foreach ($this->candidateTags($candidate) as $tag) {
            $normalized = $this->normalize($tag);
            if (mb_strlen($normalized, 'UTF-8') >= 2 && str_contains($context['all'], $normalized)) $tagHits++;
        }
        if ($tagHits > 0) {
            $score += min(40, $tagHits * 20);
            $matches[] = '主题标签';
        }
        $keywordHits = 0;
        foreach ($context['keywords'] as $keyword) {
            if (str_contains($searchable, $keyword)) $keywordHits++;
        }
        if ($keywordHits > 0) {
            $score += min(20, $keywordHits * 4);
            $matches[] = '内容关键词';
        }
        if ($this->valuesMatch([$context['course_line']], $metadata['setting']['class_type'] ?? [])) {
            $score += 15;
            $matches[] = '课程线';
        }
        if ($this->ageMatches($context['age'], [$candidate['age_group'] ?? '', $metadata['primary_age'] ?? '', $metadata['applicable_ages'] ?? []])) {
            $score += 20;
            $matches[] = '年龄';
        }
        if ($this->valuesMatch([$context['phase']], $metadata['setting']['lesson_phase'] ?? [])) {
            $score += 15;
            $matches[] = '课堂阶段';
        }
        if ($this->valuesMatch($context['equipment'], $metadata['setting']['equipment'] ?? [])) {
            $score += 10;
            $matches[] = '器材';
        }
        $candidateRisk = $this->risk((string) ($candidate['risk_level'] ?? $metadata['risk_level'] ?? ''));
        if ($context['risk'] !== '' && $candidateRisk === $context['risk']) {
            $score += 10;
            $matches[] = '风险等级';
        }
        if ($type === 'safety' && $context['risk'] === 'high' && $keywordHits > 0) {
            $score += 20;
            $matches[] = '高风险安全';
        }
        return [$score, array_values(array_unique($matches))];
    }

    private function context(array $content, array $phase): array
    {
        $metadata = is_array($content['metadata'] ?? null) ? $content['metadata'] : [];
        $activity = $this->text([$phase['activity'] ?? '', $phase['content'] ?? '', $phase['name'] ?? '', $phase['title'] ?? '']);
        $all = $this->text([$metadata, $content['objectives'] ?? [], $content['safety'] ?? [], $content['equipment'] ?? [], $content['progressions'] ?? [], $phase]);
        $normalizedAll = $this->normalize($all);
        return [
            'all' => $normalizedAll,
            'activity' => $this->normalize($activity),
            'keywords' => $this->keywords($activity . ' ' . $this->text($content['objectives'] ?? [])),
            'course_line' => (string) ($metadata['course_line'] ?? ''),
            'age' => $this->text([$metadata['age_group'] ?? '', $metadata['target_age'] ?? '', $metadata['class_level'] ?? '']),
            'phase' => (string) ($phase['name'] ?? $phase['title'] ?? ''),
            'equipment' => $this->listValues($content['equipment'] ?? []),
            'risk' => $this->containsAny($normalizedAll, self::HIGH_RISK_KEYWORDS) ? 'high' : '',
        ];
    }

    private function phaseSuggestion(array $candidate, int $phaseIndex): array
    {
        $type = (string) $candidate['content_type'];
        $label = $type === 'action' ? '动作设计' : '游戏设计';
        return $this->suggestion($candidate, 'knowledge_' . $type, 'phases.' . $phaseIndex . '.activity', '可参考知识卡《' . $candidate['title'] . '》完善' . $label);
    }

    private function safetySuggestion(array $candidate): array
    {
        return $this->suggestion($candidate, 'knowledge_safety', 'safety.physical', '可参考知识卡《' . $candidate['title'] . '》完善安全预案');
    }

    private function supportingSuggestions(array $content, array $cards): array
    {
        $suggestions = [];
        $currentEquipment = $this->listValues($content['equipment'] ?? []);
        $needsProgression = trim($this->text($content['progressions'] ?? [])) === '';
        foreach ($cards as $candidate) {
            $equipment = $this->meaningfulValues($candidate['_metadata']['setting']['equipment'] ?? []);
            $missingEquipment = array_values(array_filter($equipment, fn(string $item): bool => !$this->valuesMatch([$item], $currentEquipment)));
            if ($missingEquipment !== []) {
                $copy = $candidate;
                $copy['matched_dimensions'] = [...$candidate['matched_dimensions'], '器材配置'];
                $suggestions[] = $this->suggestion($copy, 'knowledge_equipment', 'equipment', '知识卡《' . $candidate['title'] . '》建议准备：' . implode('、', array_slice($missingEquipment, 0, 5)));
            }
            if ($needsProgression && preg_match('/升阶|进阶|降阶|退阶|难度调整/u', (string) ($candidate['content'] ?? ''))) {
                $copy = $candidate;
                $copy['matched_dimensions'] = [...$candidate['matched_dimensions'], '升降阶'];
                $suggestions[] = $this->suggestion($copy, 'knowledge_progression', 'progressions', '知识卡《' . $candidate['title'] . '》包含可参考的升降阶方案');
            }
        }
        return $suggestions;
    }

    private function suggestion(array $candidate, string $type, string $fieldPath, string $message): array
    {
        $dimensions = array_values(array_unique($candidate['matched_dimensions'] ?? []));
        $risk = $this->risk((string) ($candidate['risk_level'] ?? ''));
        return [
            'suggestion_type' => $type,
            'priority' => $risk === 'high' || $type === 'knowledge_safety' ? 'high' : 'medium',
            'field_path' => $fieldPath,
            'message' => $message,
            'reason' => '匹配维度：' . implode('、', $dimensions),
            'source_type' => 'knowledge_card',
            'knowledge_item_id' => (int) $candidate['id'],
            'knowledge_version_id' => (int) ($candidate['knowledge_version_id'] ?? 0),
            'knowledge_item_code' => (string) ($candidate['item_code'] ?? ''),
            'knowledge_item_title' => (string) ($candidate['title'] ?? ''),
            'score' => (int) $candidate['score'],
            'matched_dimensions' => $dimensions,
        ];
    }

    private function metadata(array $candidate): array
    {
        if (is_array($candidate['_metadata'] ?? null)) return $candidate['_metadata'];
        $decoded = json_decode((string) ($candidate['source_metadata_json'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function candidateTags(array $candidate): array
    {
        return $this->meaningfulValues([$candidate['subject'] ?? '', $candidate['training_type'] ?? '', $this->decodeList($candidate['tags'] ?? [])]);
    }

    private function meaningfulValues(mixed $value): array
    {
        return array_values(array_filter($this->listValues($value), static fn(string $item): bool => !in_array(trim($item), ['', '原文未说明', '未说明'], true)));
    }

    private function listValues(mixed $value): array
    {
        if (!is_array($value)) return is_scalar($value) && trim((string) $value) !== '' ? [trim((string) $value)] : [];
        $values = [];
        foreach ($value as $item) $values = [...$values, ...$this->listValues($item)];
        return $values;
    }

    private function decodeList(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : preg_split('/[,，、;；]/u', $value);
    }

    private function valuesMatch(mixed $left, mixed $right): bool
    {
        foreach ($this->meaningfulValues($left) as $leftValue) {
            $a = $this->normalize($leftValue);
            if ($a === '') continue;
            foreach ($this->meaningfulValues($right) as $rightValue) {
                $b = $this->normalize($rightValue);
                if ($b !== '' && (str_contains($a, $b) || str_contains($b, $a))) return true;
            }
        }
        return false;
    }

    private function ageMatches(string $lessonAge, mixed $candidateAges): bool
    {
        if ($lessonAge === '') return false;
        if ($this->valuesMatch([$lessonAge], $candidateAges)) return true;
        preg_match_all('/\d{1,2}/u', $lessonAge, $lessonNumbers);
        preg_match_all('/\d{1,2}/u', $this->text($candidateAges), $candidateNumbers);
        return array_intersect($lessonNumbers[0] ?? [], $candidateNumbers[0] ?? []) !== [];
    }

    private function keywords(string $text): array
    {
        preg_match_all('/[\p{Han}A-Za-z0-9]{2,16}/u', mb_strtolower($text, 'UTF-8'), $matches);
        $keywords = [];
        foreach ($matches[0] ?? [] as $part) {
            $length = mb_strlen($part, 'UTF-8');
            for ($size = 2; $size <= min(4, $length); $size++) {
                for ($index = 0; $index <= $length - $size; $index++) {
                    $term = mb_substr($part, $index, $size, 'UTF-8');
                    if (!in_array($term, self::STOP_TERMS, true)) $keywords[$term] = true;
                    if (count($keywords) >= 60) break 3;
                }
            }
        }
        return array_map(static fn(string|int $keyword): string => (string) $keyword, array_keys($keywords));
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^\p{Han}A-Za-z0-9]+/u', '', mb_strtolower(trim($value), 'UTF-8')) ?? '';
    }

    private function text(mixed $value): string
    {
        if (!is_array($value)) return is_scalar($value) ? trim((string) $value) : '';
        return implode(' ', array_filter(array_map(fn(mixed $item): string => $this->text($item), $value)));
    }

    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) if (str_contains($text, $this->normalize($keyword))) return true;
        return false;
    }

    private function risk(string $risk): string
    {
        return match (mb_strtolower(trim($risk), 'UTF-8')) {
            '高', 'high' => 'high',
            '中', 'medium' => 'medium',
            '低', 'low' => 'low',
            default => '',
        };
    }

    private function suggestionKey(array $suggestion): string
    {
        return implode('|', [(string) ($suggestion['knowledge_item_id'] ?? ''), (string) ($suggestion['knowledge_version_id'] ?? ''), (string) ($suggestion['field_path'] ?? ''), (string) ($suggestion['suggestion_type'] ?? '')]);
    }

    private function audit(int $submissionId, int $versionId, int $staffId, ?int $userId, int $count, int $insertedCount): void
    {
        $this->requireDatabase()->prepare(
            'INSERT INTO lesson_audit_logs (submission_id, version_id, actor_user_id, actor_staff_id, action, metadata_json) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$submissionId, $versionId, $userId, $staffId, 'knowledge_optimize', json_encode(['suggestion_count' => $count, 'inserted_count' => $insertedCount], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    private function requireDatabase(): PDO
    {
        if (!$this->pdo) throw new LogicException('知识卡匹配服务缺少数据库连接');
        return $this->pdo;
    }
}
