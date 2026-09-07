<?php
declare(strict_types=1);

require_once __DIR__ . '/KnowledgeTaxonomy.php';
require_once __DIR__ . '/EmployeeKnowledgeVisibilityQuery.php';

final class KnowledgeListService
{
    private Closure $resourceUrl;

    public function __construct(private PDO $db, callable $resourceUrl)
    {
        $this->resourceUrl = Closure::fromCallable($resourceUrl);
    }

    public function list(int $userId, array $_staffContext, array $filters): array
    {
        $categoryId = (int)($filters['category_id'] ?? 0);
        $type = trim((string)($filters['type'] ?? ''));
        $keyword = trim((string)($filters['keyword'] ?? ''));
        $subject = trim((string)($filters['subject'] ?? ''));
        $ageGroup = trim((string)($filters['age_group'] ?? ''));
        $trainingType = trim((string)($filters['training_type'] ?? ''));
        $contentType = trim((string)($filters['content_type'] ?? ''));
        $primaryCategory = trim((string)($filters['primary_category'] ?? ''));
        $subcategoryCode = trim((string)($filters['subcategory_code'] ?? ''));
        $ageCode = trim((string)($filters['age_code'] ?? ''));
        $domainCode = trim((string)($filters['domain_code'] ?? ''));
        $riskLevel = trim((string)($filters['risk_level'] ?? ''));
        $difficulty = (int)($filters['difficulty'] ?? 0);
        $favoriteOnly = (string)($filters['favorite'] ?? '') === '1';
        $recentOnly = !$favoriteOnly && (string)($filters['recent'] ?? '') === '1';
        $page = max(1, (int)($filters['page'] ?? 1));
        $pageSize = max(1, min((int)($filters['page_size'] ?? 20), 50));
        $offset = ($page - 1) * $pageSize;
        $categories = KnowledgeTaxonomy::subcategories();
        if ($primaryCategory !== '' && !isset($categories[$primaryCategory])) {
            throw new InvalidArgumentException('无效的知识主分类');
        }
        $subcategories = $primaryCategory !== '' ? $categories[$primaryCategory] : array_merge(...array_values($categories));
        if ($subcategoryCode !== '' && !isset($subcategories[$subcategoryCode])) {
            throw new InvalidArgumentException('无效的知识子分类');
        }

        // Parse common combined queries before applying exact filters.
        $searchKeyword = $keyword;
        if ($keyword !== '') {
            if ($contentType === '') {
                foreach (['游戏' => 'game', '动作' => 'action', '安全' => 'safety'] as $term => $value) if (mb_stripos($keyword, $term) !== false) { $contentType = $value; break; }
            }
            if ($domainCode === '') {
                foreach (['感统' => 'sensory_integration', '体测' => 'assessment', '体能' => 'physical_qualities'] as $term => $value) if (mb_stripos($keyword, $term) !== false) { $domainCode = $value; break; }
            }
            foreach (['游戏'=>'game', '动作'=>'action', '安全'=>'safety', '感统'=>'sensory_integration', '体测'=>'assessment', '体能'=>'physical_qualities'] as $term => $value) {
                if ($contentType === $value || $domainCode === $value) $searchKeyword = str_replace($term, '', $searchKeyword);
            }
            $searchKeyword = trim(preg_replace('/\s+/u', ' ', (string)$searchKeyword));
        }
        $ageCodes = $ageCode !== '' ? [$ageCode] : [];
        $normalizeAge = function (array $match) use (&$ageCodes): string {
            $code = isset($match[2]) && $match[2] !== ''
                ? $this->ageCodeForRange((int)$match[1], (int)$match[2])
                : $this->ageCodeForSingleAge((int)$match[1]);
            if ($code === '') return $match[0];
            $ageCodes[] = $code;
            return '';
        };
        $agePattern = '/(?<!\d)(\d{1,2})\s*(?:至|-|到)\s*(\d{1,2})\s*岁?|(?<!\d)(\d{1,2})\s*岁/u';
        $normalizeMatch = static function (array $match) use ($normalizeAge): string {
            return $normalizeAge(isset($match[3]) && $match[3] !== '' ? [$match[0], $match[3]] : $match);
        };
        $searchKeyword = trim((string)preg_replace_callback($agePattern, $normalizeMatch, $searchKeyword));
        $ageGroup = trim((string)preg_replace_callback($agePattern, $normalizeMatch, $ageGroup));
        $ageCodes = array_values(array_unique($ageCodes));
        $ageCode = $ageCodes[0] ?? '';

        $knowledgeSource = EmployeeKnowledgeVisibilityQuery::fromCurrentVersion();
        $where = 'WHERE 1 = 1';
        $joins = '';
        $params = [];
        if ($favoriteOnly) {
            $joins .= ' JOIN knowledge_favorites selected_favorite ON selected_favorite.knowledge_id = k.id AND selected_favorite.user_id = ?';
            $params[] = $userId;
        } elseif ($recentOnly) {
            $joins .= ' JOIN knowledge_recent_views selected_recent ON selected_recent.knowledge_id = k.id AND selected_recent.user_id = ?';
            $params[] = $userId;
        }

        $this->appendFilter($where, $params, 'k.category_id', $categoryId > 0 ? $categoryId : null);
        $this->appendFilter($where, $params, 'c.type', $type);
        if ($searchKeyword !== '') {
            $where .= ' AND (' . $this->versionedTextExpression('title') . ' LIKE ? OR ' . $this->versionedTextExpression('summary') . ' LIKE ? OR ' . $this->versionedTextExpression('content') . ' LIKE ? OR ' . $this->versionedTextExpression('tags_json', 'tags') . ' LIKE ? '
                . 'OR c.name LIKE ? OR k.subject LIKE ? OR k.training_type LIKE ?)';
            $like = '%' . $searchKeyword . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }
        $this->appendFilter($where, $params, $this->versionedTextExpression('subject'), $subject);
        $this->appendFilter($where, $params, $this->versionedTextExpression('age_group'), $ageGroup);
        foreach ($ageCodes as $confirmedAgeCode) {
            $where .= " AND EXISTS (SELECT 1 FROM knowledge_item_age_ranges iar WHERE iar.knowledge_item_id = k.id AND iar.version_id = kv.version_id AND iar.age_code = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci AND iar.review_status = 'confirmed')";
            $params[] = $confirmedAgeCode;
        }
        $this->appendFilter($where, $params, $this->versionedTextExpression('training_type'), $trainingType);
        $this->appendFilter($where, $params, $this->versionedTextExpression('content_type'), $contentType);
        $this->appendFilter($where, $params, $this->versionedTextExpression('domain_code'), $domainCode);
        $classification = KnowledgeTaxonomy::classificationSql(
            'LOWER(TRIM(' . $this->versionedTextExpression('domain_code') . '))',
            'LOWER(TRIM(' . $this->versionedTextExpression('content_type') . '))',
            "CONCAT(" . $this->versionedTextExpression('title') . ", ' ', " . $this->versionedTextExpression('summary') . ", ' ', " . $this->versionedTextExpression('tags_json', 'tags') . ')'
        );
        $this->appendFilter($where, $params, '(' . $classification['primary_category'] . ')', $primaryCategory);
        $this->appendFilter($where, $params, '(' . $classification['subcategory_code'] . ')', $subcategoryCode);
        $this->appendRiskFilter($where, $params, $riskLevel);
        $this->appendFilter($where, $params, 'COALESCE(kv.difficulty, k.difficulty)', $difficulty > 0 ? $difficulty : null);

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $knowledgeSource
            . $joins . ' LEFT JOIN knowledge_categories c ON k.category_id = c.id ' . $where
        );
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = "SELECT k.id, kv.version_id AS version_id, COALESCE(NULLIF(kv.title, ''), k.title) AS title, COALESCE(NULLIF(kv.summary, ''), k.summary) AS summary, k.media_url, k.media_type, k.category_id, "
            . "k.is_public, k.target_roles, k.target_stages, COALESCE(NULLIF(kv.tags_json, ''), k.tags) AS tags, k.sort_order, "
             . "COALESCE(NULLIF(kv.subject, ''), k.subject) AS subject, COALESCE(NULLIF(kv.age_group, ''), k.age_group) AS age_group, COALESCE(NULLIF(kv.training_type, ''), k.training_type) AS training_type, COALESCE(kv.difficulty, k.difficulty) AS difficulty, k.created_at, kv.created_at AS updated_at, "
            . "k.item_code, COALESCE(NULLIF(kv.content_type, ''), k.content_type) AS content_type, COALESCE(NULLIF(kv.domain_code, ''), k.domain_code) AS domain_code, COALESCE(NULLIF(kv.risk_level, ''), k.risk_level) AS risk_level, k.publication_status, "
            . "(SELECT GROUP_CONCAT(DISTINCT iar.age_code ORDER BY ar.sort_order SEPARATOR ',') FROM knowledge_item_age_ranges iar INNER JOIN knowledge_age_ranges ar ON ar.age_code = iar.age_code AND ar.status = 'active' WHERE iar.knowledge_item_id = k.id AND iar.version_id = kv.version_id AND iar.review_status = 'confirmed') AS age_codes, "
            . "(SELECT GROUP_CONCAT(DISTINCT ar.label ORDER BY ar.sort_order SEPARATOR ',') FROM knowledge_item_age_ranges iar INNER JOIN knowledge_age_ranges ar ON ar.age_code = iar.age_code AND ar.status = 'active' WHERE iar.knowledge_item_id = k.id AND iar.version_id = kv.version_id AND iar.review_status = 'confirmed') AS age_labels, "
            . "(SELECT COUNT(*) FROM knowledge_item_age_ranges iar WHERE iar.knowledge_item_id = k.id AND iar.version_id = kv.version_id AND iar.review_status = 'pending') AS pending_age_range_count, "
            . '(SELECT COUNT(*) FROM knowledge_favorites f WHERE f.user_id = ? AND f.knowledge_id = k.id) AS is_favorite, '
            . '(SELECT rv.last_viewed_at FROM knowledge_recent_views rv WHERE rv.user_id = ? AND rv.knowledge_id = k.id) AS last_viewed_at, '
            . "k.status, LEFT(COALESCE(NULLIF(kv.content, ''), k.content), 500) AS content, "
            . 'c.name AS category_name, c.code AS category_code, c.type AS category_type, '
            . 'c.icon AS category_icon, c.description AS category_description, '
            . '(SELECT is_completed FROM user_knowledge_progress WHERE user_id = ? AND knowledge_id = k.id) AS is_completed, '
            . '(SELECT score FROM user_knowledge_progress WHERE user_id = ? AND knowledge_id = k.id) AS progress_score '
            . 'FROM ' . $knowledgeSource . $joins . ' LEFT JOIN knowledge_categories c ON k.category_id = c.id '
            . $where . ' ORDER BY ' . ($recentOnly ? 'selected_recent.last_viewed_at DESC, ' : '') . 'k.is_public DESC, c.sort_order ASC, k.sort_order ASC, k.id DESC LIMIT ?, ?';
        $queryParams = array_merge([$userId, $userId, $userId, $userId], $params, [$offset, $pageSize]);
        $stmt = $this->db->prepare($sql);
        foreach ($queryParams as $index => $value) {
            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($index + 1, $value, $paramType);
        }
        $stmt->execute();
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($list as &$item) {
            $itemClassification = KnowledgeTaxonomy::classify($item);
            if (trim((string)($item['summary'] ?? '')) === '') {
                $item['summary'] = $this->buildSummary((string)($item['content'] ?? ''));
            }
            $item['cover_image'] = !empty($item['media_url']) && $item['media_type'] === 'image'
                ? ($this->resourceUrl)((string)$item['media_url'])
                : null;
            foreach (['target_roles', 'target_stages', 'tags'] as $jsonField) {
                $item[$jsonField] = !empty($item[$jsonField])
                    ? (json_decode((string)$item[$jsonField], true) ?: [])
                    : [];
            }
            $item['age_codes'] = !empty($item['age_codes']) ? explode(',', (string)$item['age_codes']) : [];
            $item['age_labels'] = !empty($item['age_labels']) ? explode(',', (string)$item['age_labels']) : [];
            $item['classification_review_status'] = trim((string)($item['domain_code'] ?? '')) === ''
                || (int)($item['pending_age_range_count'] ?? 0) > 0
                ? 'pending'
                : 'confirmed';
            unset($item['pending_age_range_count']);
            $item = array_merge($item, $itemClassification);
            $item['match_reason'] = $this->matchReason($keyword, $item);
        }
        unset($item);

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'keyword' => $keyword,
            'mode' => $favoriteOnly ? 'favorite' : ($recentOnly ? 'recent' : 'all'),
            'taxonomy_mapping_version' => KnowledgeTaxonomy::mappingVersion(),
            'filters' => [
                'type' => $type,
                'category_id' => $categoryId,
                'subject' => $subject,
                 'age_group' => $ageGroup,
                 'age_code' => $ageCode,
                'training_type' => $trainingType,
                'content_type' => $contentType,
                'domain_code' => $domainCode,
                'difficulty' => $difficulty,
                'risk_level' => $riskLevel,
                'primary_category' => $primaryCategory,
                'subcategory_code' => $subcategoryCode,
            ],
        ];
    }

    private function matchReason(string $keyword, array $item): string
    {
        if ($keyword === '') return '';
        $reasons = [];
        if (preg_match('/(?:全年龄段|\d{1,2}\s*(?:至|-|到)\s*\d{1,2})\s*岁?/u', $keyword)) $reasons[] = '年龄匹配';
        if (mb_stripos($keyword, '游戏') !== false && ($item['content_type'] ?? '') === 'game') $reasons[] = '类型匹配';
        if (mb_stripos($keyword, '动作') !== false && ($item['content_type'] ?? '') === 'action') $reasons[] = '类型匹配';
        if (mb_stripos($keyword, '感统') !== false && ($item['domain_code'] ?? '') === 'sensory_integration') $reasons[] = '领域匹配';
        return implode('、', $reasons) ?: '关键词匹配';
    }

    private function ageCodeForSingleAge(int $age): string
    {
        return match (true) {
            $age <= 2 => 'age_0_3', $age === 3 => 'age_3_4', $age === 4 => 'age_4_5',
            $age === 5 => 'age_5_6', $age <= 9 => 'age_6_9', $age <= 12 => 'age_9_12',
            default => 'age_12_plus',
        };
    }

    private function ageCodeForRange(int $start, int $end): string
    {
        $ranges = [[0, 3, 'age_0_3'], [3, 4, 'age_3_4'], [4, 5, 'age_4_5'], [5, 6, 'age_5_6'], [6, 9, 'age_6_9'], [9, 12, 'age_9_12']];
        foreach ($ranges as [$rangeStart, $rangeEnd, $code]) if ($start === $rangeStart && $end === $rangeEnd) return $code;
        return $start >= 12 && $end >= $start ? 'age_12_plus' : '';
    }

    private function appendFilter(string &$where, array &$params, string $column, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $where .= ' AND ' . $column . ' = ?';
        $params[] = $value;
    }

    private function appendRiskFilter(string &$where, array &$params, string $riskLevel): void
    {
        if ($riskLevel === '') {
            return;
        }
        $riskVariants = [
            'low' => ['low', '低'],
            'medium' => ['medium', '中'],
            'high' => ['high', '高'],
            '低' => ['low', '低'],
            '中' => ['medium', '中'],
            '高' => ['high', '高'],
        ];
        $values = $riskVariants[$riskLevel] ?? [$riskLevel];
        $where .= ' AND ' . $this->versionedTextExpression('risk_level') . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        array_push($params, ...$values);
    }

    private function versionedTextExpression(string $versionField, ?string $itemField = null): string
    {
        $allowedVersionFields = ['age_group', 'content', 'content_type', 'domain_code', 'risk_level', 'subject', 'summary', 'tags_json', 'title', 'training_type'];
        $allowedItemFields = ['age_group', 'content', 'content_type', 'domain_code', 'risk_level', 'subject', 'summary', 'tags', 'title', 'training_type'];
        if (!in_array($versionField, $allowedVersionFields, true)) {
            throw new InvalidArgumentException('Invalid knowledge version text field');
        }
        $itemField ??= $versionField;
        if (!in_array($itemField, $allowedItemFields, true)) {
            throw new InvalidArgumentException('Invalid knowledge item text field');
        }
        return "CONVERT(COALESCE(NULLIF(kv.$versionField, ''), k.$itemField, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    }

    private function buildSummary(string $content): string
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $coreLines = [];
        $fallbackLines = [];
        $insideCoreSummary = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^#{2,}\s*核心摘要\s*$/u', $line)) {
                $insideCoreSummary = true;
                continue;
            }
            if (preg_match('/^#{1,6}\s+/u', $line)) {
                if ($insideCoreSummary && $coreLines !== []) {
                    break;
                }
                continue;
            }
            $plainLine = preg_replace([
                '/!\[([^\]]*)\]\([^)]*\)/u',
                '/\[([^\]]+)\]\([^)]*\)/u',
                '/^[>*+\-\s]+/u',
                '/^\d+[.)、]\s*/u',
                '/[*_`~]+/u',
            ], ['', '$1', '', '', ''], strip_tags($line));
            $plainLine = trim((string)$plainLine);
            if ($plainLine === '') {
                continue;
            }
            if ($insideCoreSummary) {
                $coreLines[] = $plainLine;
            } elseif (count($fallbackLines) < 2) {
                $fallbackLines[] = $plainLine;
            }
        }

        $summary = implode(' ', $coreLines !== [] ? $coreLines : $fallbackLines);
        if (mb_strlen($summary) <= 120) {
            return $summary;
        }
        return rtrim(mb_substr($summary, 0, 120), "，。；、 \t\n\r\0\x0B") . '…';
    }

}
