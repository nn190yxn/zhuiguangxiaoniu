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
        $domainCode = trim((string)($filters['domain_code'] ?? ''));
        $riskLevel = trim((string)($filters['risk_level'] ?? ''));
        $difficulty = (int)($filters['difficulty'] ?? 0);
        $favoriteOnly = (string)($filters['favorite'] ?? '') === '1';
        $recentOnly = !$favoriteOnly && (string)($filters['recent'] ?? '') === '1';
        $page = max(1, (int)($filters['page'] ?? 1));
        $pageSize = max(1, min((int)($filters['page_size'] ?? 20), 50));
        $offset = ($page - 1) * $pageSize;

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
        if ($keyword !== '') {
            $where .= " AND (COALESCE(NULLIF(kv.title, ''), k.title) LIKE ? OR COALESCE(NULLIF(kv.summary, ''), k.summary) LIKE ? OR COALESCE(NULLIF(kv.content, ''), k.content) LIKE ? OR COALESCE(NULLIF(kv.tags_json, ''), k.tags) LIKE ? "
                . 'OR c.name LIKE ? OR k.subject LIKE ? OR k.training_type LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }
        $this->appendFilter($where, $params, "COALESCE(NULLIF(kv.subject, ''), k.subject)", $subject);
        $this->appendFilter($where, $params, "COALESCE(NULLIF(kv.age_group, ''), k.age_group)", $ageGroup);
        $this->appendFilter($where, $params, "COALESCE(NULLIF(kv.training_type, ''), k.training_type)", $trainingType);
        $this->appendFilter($where, $params, "COALESCE(NULLIF(kv.content_type, ''), k.content_type)", $contentType);
        $this->appendFilter($where, $params, "COALESCE(NULLIF(kv.domain_code, ''), k.domain_code)", $domainCode);
        $this->appendPrimaryCategoryFilter($where, $params, $primaryCategory);
        $this->appendSubcategoryFilter($where, $params, $subcategoryCode);
        $this->appendRiskFilter($where, $params, $riskLevel);
        $this->appendFilter($where, $params, 'COALESCE(kv.difficulty, k.difficulty)', $difficulty > 0 ? $difficulty : null);

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $knowledgeSource
            . $joins . ' LEFT JOIN knowledge_categories c ON k.category_id = c.id ' . $where
        );
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = "SELECT k.id, kv.version_id AS version_id, COALESCE(NULLIF(kv.title, ''), k.title) AS title, COALESCE(NULLIF(kv.summary, ''), k.summary) AS summary, k.media_url, k.media_type, k.category_id, "
            . 'k.is_public, k.target_roles, k.target_stages, k.tags, k.sort_order, '
            . "COALESCE(NULLIF(kv.subject, ''), k.subject) AS subject, COALESCE(NULLIF(kv.age_group, ''), k.age_group) AS age_group, COALESCE(NULLIF(kv.training_type, ''), k.training_type) AS training_type, COALESCE(kv.difficulty, k.difficulty) AS difficulty, k.created_at, kv.created_at AS updated_at, "
            . "k.item_code, COALESCE(NULLIF(kv.content_type, ''), k.content_type) AS content_type, COALESCE(NULLIF(kv.domain_code, ''), k.domain_code) AS domain_code, COALESCE(NULLIF(kv.risk_level, ''), k.risk_level) AS risk_level, k.publication_status, "
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
            $item = array_merge($item, KnowledgeTaxonomy::classify($item));
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
        $where .= " AND COALESCE(NULLIF(kv.risk_level, ''), k.risk_level) IN (" . implode(',', array_fill(0, count($values), '?')) . ')';
        array_push($params, ...$values);
    }

    private function appendPrimaryCategoryFilter(string &$where, array &$params, string $primaryCategory): void
    {
        if (!isset(KnowledgeTaxonomy::lines()[$primaryCategory])) {
            return;
        }
        $domainExpression = "LOWER(COALESCE(NULLIF(kv.domain_code, ''), k.domain_code, ''))";
        $typeExpression = "LOWER(COALESCE(NULLIF(kv.content_type, ''), k.content_type, ''))";
        $mappedDomainCodes = array_keys(KnowledgeTaxonomy::domainMappings());
        $categoryDomainCodes = KnowledgeTaxonomy::domainCodesForPrimaryCategory($primaryCategory);
        $mappedPlaceholders = implode(',', array_fill(0, count($mappedDomainCodes), '?'));
        $categoryPredicate = $categoryDomainCodes === []
            ? '0 = 1'
            : $domainExpression . ' IN (' . implode(',', array_fill(0, count($categoryDomainCodes), '?')) . ')';

        if ($primaryCategory === 'sales') {
            $where .= " AND (($categoryPredicate)"
                . " OR ($domainExpression NOT IN ($mappedPlaceholders) AND ($domainExpression = 'sales' OR $typeExpression = 'script')))";
        } else {
            $where .= " AND (($categoryPredicate)"
                . " OR ($domainExpression NOT IN ($mappedPlaceholders) AND $domainExpression <> 'sales' AND $typeExpression <> 'script'))";
        }
        array_push($params, ...$categoryDomainCodes, ...$mappedDomainCodes);
    }

    private function appendSubcategoryFilter(string &$where, array &$params, string $subcategoryCode): void
    {
        if ($subcategoryCode === '') {
            return;
        }
        $domainCodes = array_keys(array_filter(
            KnowledgeTaxonomy::domainMappings(),
            static fn(array $mapping): bool => ($mapping['subcategory_code'] ?? '') === $subcategoryCode
        ));
        $domainExpression = "LOWER(COALESCE(NULLIF(kv.domain_code, ''), k.domain_code, ''))";
        $typeExpression = "LOWER(COALESCE(NULLIF(kv.content_type, ''), k.content_type, ''))";
        $predicates = [];
        if ($domainCodes !== []) {
            $predicates[] = $domainExpression . ' IN (' . implode(',', array_fill(0, count($domainCodes), '?')) . ')';
            array_push($params, ...$domainCodes);
        }
        if ($subcategoryCode === 'action_game') {
            $predicates[] = $typeExpression . " IN ('action', 'game')";
        } elseif ($subcategoryCode === 'lesson_reference') {
            $predicates[] = $typeExpression . " = 'lesson'";
        } elseif ($subcategoryCode === 'coach_growth') {
            $predicates[] = $domainExpression . " IN ('coach', 'coach_growth', 'g08')";
        }
        if ($predicates !== []) {
            $where .= ' AND (' . implode(' OR ', $predicates) . ')';
        }
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
