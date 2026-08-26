<?php
declare(strict_types=1);

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
        $domainCode = trim((string)($filters['domain_code'] ?? ''));
        $riskLevel = trim((string)($filters['risk_level'] ?? ''));
        $difficulty = (int)($filters['difficulty'] ?? 0);
        $favoriteOnly = (string)($filters['favorite'] ?? '') === '1';
        $recentOnly = !$favoriteOnly && (string)($filters['recent'] ?? '') === '1';
        $page = max(1, (int)($filters['page'] ?? 1));
        $pageSize = max(1, min((int)($filters['page_size'] ?? 20), 50));
        $offset = ($page - 1) * $pageSize;

        $where = "WHERE k.status = 1 AND k.publication_status = 'published'";
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
            $where .= ' AND (k.title LIKE ? OR k.summary LIKE ? OR k.content LIKE ? OR k.tags LIKE ? '
                . 'OR c.name LIKE ? OR k.subject LIKE ? OR k.training_type LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }
        $this->appendFilter($where, $params, 'k.subject', $subject);
        $this->appendFilter($where, $params, 'k.age_group', $ageGroup);
        $this->appendFilter($where, $params, 'k.training_type', $trainingType);
        $this->appendFilter($where, $params, 'k.content_type', $contentType);
        $this->appendFilter($where, $params, 'k.domain_code', $domainCode);
        $this->appendRiskFilter($where, $params, $riskLevel);
        $this->appendFilter($where, $params, 'k.difficulty', $difficulty > 0 ? $difficulty : null);

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM knowledge_items k '
            . $joins . ' LEFT JOIN knowledge_categories c ON k.category_id = c.id ' . $where
        );
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = 'SELECT k.id, k.title, k.summary, k.media_url, k.media_type, k.category_id, '
            . 'k.is_public, k.target_roles, k.target_stages, k.tags, k.sort_order, '
            . 'k.subject, k.age_group, k.training_type, k.difficulty, k.created_at, k.updated_at, '
            . 'k.item_code, k.content_type, k.domain_code, k.risk_level, k.publication_status, '
            . '(SELECT COUNT(*) FROM knowledge_favorites f WHERE f.user_id = ? AND f.knowledge_id = k.id) AS is_favorite, '
            . '(SELECT rv.last_viewed_at FROM knowledge_recent_views rv WHERE rv.user_id = ? AND rv.knowledge_id = k.id) AS last_viewed_at, '
            . 'k.status, LEFT(k.content, 500) AS content, '
            . 'c.name AS category_name, c.code AS category_code, c.type AS category_type, '
            . 'c.icon AS category_icon, c.description AS category_description, '
            . '(SELECT is_completed FROM user_knowledge_progress WHERE user_id = ? AND knowledge_id = k.id) AS is_completed, '
            . '(SELECT score FROM user_knowledge_progress WHERE user_id = ? AND knowledge_id = k.id) AS progress_score '
            . 'FROM knowledge_items k ' . $joins . ' LEFT JOIN knowledge_categories c ON k.category_id = c.id '
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
        }
        unset($item);

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'keyword' => $keyword,
            'mode' => $favoriteOnly ? 'favorite' : ($recentOnly ? 'recent' : 'all'),
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
        $where .= ' AND k.risk_level IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        array_push($params, ...$values);
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
