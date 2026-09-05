<?php
declare(strict_types=1);

final class LessonLibraryQueryService
{
    private const VISIBILITY_SQL = "submission.status = 'approved' AND submission.library_status = 'published' AND submission.approved_version_id IS NOT NULL AND submission.library_published_at IS NOT NULL AND version.is_submitted = 1 AND version.is_immutable = 1";

    public function __construct(private PDO $pdo)
    {
    }

    public function list(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = max(1, min(50, (int) ($filters['page_size'] ?? 20)));
        $where = [self::VISIBILITY_SQL];
        $params = [];

        foreach (['course_line', 'class_level'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $where[] = 'submission.' . $field . ' = ?';
                $params[] = $value;
            }
        }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(submission.title LIKE ? OR submission.store_name LIKE ? OR submission.author_name LIKE ?)';
            $term = '%' . $query . '%';
            array_push($params, $term, $term, $term);
        }

        $from = ' FROM lesson_submissions submission JOIN lesson_versions version ON version.id = submission.approved_version_id AND version.submission_id = submission.id';
        $condition = ' WHERE ' . implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $from . $condition);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT submission.id AS submission_id, submission.store_id, submission.store_name, submission.author_staff_id, submission.author_name, submission.course_line, submission.class_level, submission.lesson_date, submission.title, submission.approved_version_id, submission.library_published_at, submission.library_published_by_staff_id, version.version_no, version.version_type, version.created_at AS approved_version_created_at'
            . $from . $condition
            . ' ORDER BY submission.library_published_at DESC, submission.id DESC LIMIT ? OFFSET ?';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $index => $value) {
            $statement->bindValue($index + 1, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(count($params) + 1, $pageSize, PDO::PARAM_INT);
        $statement->bindValue(count($params) + 2, ($page - 1) * $pageSize, PDO::PARAM_INT);
        $statement->execute();
        $items = array_map([$this, 'lesson'], $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return [
            'list' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'filters' => [
                'q' => $query,
                'course_line' => trim((string) ($filters['course_line'] ?? '')),
                'class_level' => trim((string) ($filters['class_level'] ?? '')),
            ],
        ];
    }

    public function detail(int $submissionId): array
    {
        if ($submissionId <= 0) {
            throw new InvalidArgumentException('正式教案 ID 无效');
        }
        $statement = $this->pdo->prepare(
            'SELECT submission.id AS submission_id, submission.store_id, submission.store_name, submission.author_staff_id, submission.author_name, submission.course_line, submission.class_level, submission.lesson_date, submission.title, submission.approved_version_id, submission.library_published_at, submission.library_published_by_staff_id, version.id AS version_id, version.version_no, version.content_json, version.source_snapshot_json, version.version_type, version.is_submitted, version.is_immutable, version.created_by AS version_created_by, version.created_at AS approved_version_created_at '
            . 'FROM lesson_submissions submission JOIN lesson_versions version ON version.id = submission.approved_version_id AND version.submission_id = submission.id '
            . 'WHERE submission.id = ? AND ' . self::VISIBILITY_SQL . ' LIMIT 1'
        );
        $statement->execute([$submissionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new PlatformApiException(404, 'lesson_library_item_not_found', '正式教案不存在');
        }

        $lesson = $this->lesson($row);
        $version = [
            'id' => (int) $row['version_id'],
            'submission_id' => (int) $row['submission_id'],
            'version_no' => (int) $row['version_no'],
            'version_type' => (string) $row['version_type'],
            'is_submitted' => (int) $row['is_submitted'],
            'is_immutable' => (int) $row['is_immutable'],
            'created_by' => (int) $row['version_created_by'],
            'created_at' => (string) $row['approved_version_created_at'],
            'content' => $this->decode($row['content_json'], 'content_json'),
            'source_snapshot' => $this->decode($row['source_snapshot_json'], 'source_snapshot_json'),
        ];

        return ['lesson' => $lesson, 'approved_version' => $version];
    }

    private function lesson(array $row): array
    {
        $submissionId = (int) $row['submission_id'];
        return [
            'submission_id' => $submissionId,
            'title' => (string) $row['title'],
            'store_id' => isset($row['store_id']) ? (int) $row['store_id'] : null,
            'store_name' => (string) $row['store_name'],
            'author_staff_id' => isset($row['author_staff_id']) ? (int) $row['author_staff_id'] : null,
            'author_name' => (string) $row['author_name'],
            'course_line' => (string) $row['course_line'],
            'class_level' => (string) $row['class_level'],
            'lesson_date' => (string) $row['lesson_date'],
            'approved_version_id' => (int) $row['approved_version_id'],
            'version_no' => (int) $row['version_no'],
            'version_type' => (string) $row['version_type'],
            'library_published_at' => (string) $row['library_published_at'],
            'library_published_by_staff_id' => isset($row['library_published_by_staff_id']) ? (int) $row['library_published_by_staff_id'] : null,
            'approved_version_created_at' => (string) $row['approved_version_created_at'],
            'canonical_route' => '/lesson-library.html?id=' . $submissionId,
        ];
    }

    private function decode(mixed $value, string $field): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new PlatformApiException(500, 'lesson_library_version_invalid', '正式教案版本数据无效', ['field' => $field], $error);
        }
        if (!is_array($decoded)) {
            throw new PlatformApiException(500, 'lesson_library_version_invalid', '正式教案版本数据无效', ['field' => $field]);
        }
        return $decoded;
    }
}
