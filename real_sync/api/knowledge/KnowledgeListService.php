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
        $page = max(1, (int)($filters['page'] ?? 1));
        $pageSize = max(1, min((int)($filters['page_size'] ?? 20), 50));
        $offset = ($page - 1) * $pageSize;

        $where = "WHERE k.status = 1 AND k.publication_status = 'published'";
        $params = [];

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
        $this->appendFilter($where, $params, 'k.risk_level', $riskLevel);

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM knowledge_items k '
            . 'LEFT JOIN knowledge_categories c ON k.category_id = c.id ' . $where
        );
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = 'SELECT k.id, k.title, k.summary, k.media_url, k.media_type, k.category_id, '
            . 'k.is_public, k.target_roles, k.target_stages, k.tags, k.sort_order, '
            . 'k.subject, k.age_group, k.training_type, k.created_at, k.updated_at, '
            . 'k.item_code, k.content_type, k.domain_code, k.risk_level, k.publication_status, '
            . 'k.status, LEFT(k.content, 500) AS content, '
            . 'c.name AS category_name, c.code AS category_code, c.type AS category_type, '
            . 'c.icon AS category_icon, c.description AS category_description, '
            . '(SELECT is_completed FROM user_knowledge_progress WHERE user_id = ? AND knowledge_id = k.id) AS is_completed, '
            . '(SELECT score FROM user_knowledge_progress WHERE user_id = ? AND knowledge_id = k.id) AS progress_score '
            . 'FROM knowledge_items k LEFT JOIN knowledge_categories c ON k.category_id = c.id '
            . $where . ' ORDER BY k.is_public DESC, c.sort_order ASC, k.sort_order ASC, k.id DESC LIMIT ?, ?';
        $queryParams = array_merge([$userId, $userId], $params, [$offset, $pageSize]);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($queryParams);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($list as &$item) {
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

}
