<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/context.php';
require_once __DIR__ . '/../knowledge/EmployeeKnowledgeVisibilityQuery.php';

function searchCurrentContext(PDO $db, int $userId): array {
    $stmt = $db->prepare("SELECT id, role, stage, store_id, name FROM staffs WHERE user_id = ? AND status = 1 LIMIT 1");
    $stmt->execute([$userId]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $role = appRoleCode((string)($staff['role'] ?? ''));

    return [
        'user_id' => $userId,
        'staff_id' => (int)($staff['id'] ?? 0),
        'role' => $role,
        'stage' => trim((string)($staff['stage'] ?? '')),
        'store_id' => (int)($staff['store_id'] ?? 0),
        'can_view_staff' => in_array($role, ['admin', 'ceo', 'operation', 'finance', 'manager'], true),
    ];
}

function searchExpandTerms(string $query): array {
    $query = trim(mb_substr($query, 0, 40));
    $terms = [$query];
    foreach (preg_split('/\s+/u', $query) ?: [] as $term) {
        $term = trim($term);
        if ($term !== '') {
            $terms[] = $term;
        }
    }

    $synonyms = [
        '顾问' => ['销售', 'sales', '话术'],
        '销售' => ['顾问', 'consultant', '话术'],
        '私教' => ['教练', '课程'],
        '教练' => ['私教', 'coach', '课程'],
        '体测' => ['ACE', '评估', '测试'],
        'ACE' => ['体测', '评估'],
        'sop' => ['SOP', '流程', '标准'],
        'SOP' => ['流程', '标准'],
        '请假' => ['考勤', '休假'],
        '绩效' => ['考核', '薪酬'],
        '课程' => ['教案', '培训', '课堂'],
        '教案' => ['课程', '教学计划', '课堂'],
        '动作' => ['训练', '练习', '游戏'],
        '游戏' => ['动作', '训练', '课堂'],
        '安全' => ['禁忌', '急救', '风险'],
        '续费' => ['复购', '留存', '家长沟通'],
    ];

    foreach ($synonyms as $needle => $values) {
        if (mb_stripos($query, $needle) !== false) {
            $terms = array_merge($terms, $values);
        }
    }

    return array_values(array_unique(array_filter($terms, fn($term) => trim((string)$term) !== '')));
}

function searchLikeSql(array $fields, array $terms, array &$params, string $alias = ''): string {
    $groups = [];
    foreach ($terms as $term) {
        $parts = [];
        foreach ($fields as $field) {
            $parts[] = $field . " LIKE ?";
            $params[] = '%' . $term . '%';
        }
        $groups[] = '(' . implode(' OR ', $parts) . ')';
    }
    return $alias . '(' . implode(' OR ', $groups) . ')';
}

function searchSnippet(?string $text, array $terms, int $length = 120): string {
    $text = trim(preg_replace('/[#\|`*\[\]>\-\s]+/u', ' ', (string)$text));
    if ($text === '') {
        return '';
    }
    foreach ($terms as $term) {
        $pos = mb_stripos($text, $term);
        if ($pos !== false) {
            return mb_substr($text, max(0, $pos - 40), $length);
        }
    }
    return mb_substr($text, 0, $length);
}

function searchRunSection(string $key, callable $callback, array &$errors): array {
    try {
        return $callback();
    } catch (Throwable $e) {
        error_log('[search.' . $key . '] ' . $e->getMessage());
        $errors[$key] = '该分类暂时不可用';
        return [];
    }
}

function searchKnowledge(PDO $db, array $terms, array $context, int $limit = 8): array {
    $params = [];
    $knowledgeSource = EmployeeKnowledgeVisibilityQuery::fromCurrentVersion();
    $where = 'WHERE 1 = 1';

    $where .= " AND " . searchLikeSql(["COALESCE(NULLIF(kv.title, ''), k.title)", "COALESCE(NULLIF(kv.summary, ''), k.summary)", "COALESCE(NULLIF(kv.content, ''), k.content)", "COALESCE(NULLIF(kv.tags_json, ''), k.tags)", 'c.name', "COALESCE(NULLIF(kv.subject, ''), k.subject)", "COALESCE(NULLIF(kv.training_type, ''), k.training_type)"], $terms, $params);
    $params[] = $limit;

    $sql = "SELECT k.id, kv.version_id, COALESCE(NULLIF(kv.title, ''), k.title) AS title, COALESCE(NULLIF(kv.summary, ''), k.summary) AS summary, COALESCE(NULLIF(kv.content, ''), k.content) AS content, k.category_id, COALESCE(NULLIF(kv.tags_json, ''), k.tags) AS tags, c.name AS category_name
            FROM $knowledgeSource
            LEFT JOIN knowledge_categories c ON k.category_id = c.id
            $where
            ORDER BY CASE WHEN COALESCE(NULLIF(kv.title, ''), k.title) LIKE ? THEN 0 WHEN COALESCE(NULLIF(kv.tags_json, ''), k.tags) LIKE ? THEN 1 ELSE 2 END, kv.created_at DESC
            LIMIT ?";

    $orderTerm = '%' . $terms[0] . '%';
    array_splice($params, count($params) - 1, 0, [$orderTerm, $orderTerm]);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return array_map(function ($item) use ($terms) {
        return [
            'id' => (int)$item['id'],
            'version_id' => (int)$item['version_id'],
            'title' => $item['title'],
            'summary' => $item['summary'] ?: searchSnippet($item['content'] ?? '', $terms),
            'category' => $item['category_name'] ?? '',
            'url' => '/mobile/knowledge-detail.html?id=' . $item['id'],
            'type_label' => '知识',
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function searchPolicies(PDO $db, array $terms, array $context, int $limit = 8): array {
    $params = [(string)($context['role'] ?? '')];
    $where = searchLikeSql(['title', 'content', 'keywords', 'category', 'workflow', 'doc_key'], $terms, $params);
    $orderTerm = '%' . $terms[0] . '%';
    $params[] = $orderTerm;
    $params[] = $orderTerm;
    $params[] = $limit;

    $sql = "SELECT id, title, doc_key, content, category, workflow, keywords, version, is_need_confirm, updated_at
            FROM policies
            WHERE status = 1 AND publication_status = 'published'
              AND (target_roles IS NULL OR JSON_LENGTH(target_roles) = 0 OR JSON_CONTAINS(target_roles, JSON_QUOTE(?)))
              AND $where
            ORDER BY CASE WHEN title LIKE ? THEN 0 WHEN keywords LIKE ? THEN 1 ELSE 2 END, updated_at DESC
            LIMIT ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return array_map(function ($item) use ($terms) {
        return [
            'id' => (int)$item['id'],
            'title' => $item['title'],
            'summary' => searchSnippet($item['content'] ?? '', $terms),
            'category' => trim((string)($item['category'] ?? '')),
            'workflow' => trim((string)($item['workflow'] ?? '')),
            'keywords' => trim((string)($item['keywords'] ?? '')),
            'version' => trim((string)($item['version'] ?? '')),
            'is_need_confirm' => (int)($item['is_need_confirm'] ?? 0),
            'updated_at' => !empty($item['updated_at']) ? date('Y-m-d H:i', strtotime((string)$item['updated_at'])) : '',
            'doc_key' => $item['doc_key'],
            'url' => $item['doc_key'] ? '/doc-viewer.html?doc=' . rawurlencode($item['doc_key']) : '/制度标准/',
            'type_label' => '制度',
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function searchSimple(PDO $db, string $sql, array $params, callable $mapper): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return array_map($mapper, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function searchSimpleTerms(PDO $db, string $selectSql, array $fields, array $terms, string $orderSql, int $limit, callable $mapper, array $baseParams = []): array {
    $params = $baseParams;
    $where = searchLikeSql($fields, $terms, $params);
    $params[] = $limit;
    $stmt = $db->prepare($selectSql . " AND $where $orderSql LIMIT ?");
    $stmt->execute($params);
    return array_map($mapper, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function searchStaticLessons(array $terms, int $limit = 8): array {
    $manifestPath = dirname(__DIR__, 2) . '/lessons/manifest.json';
    if (!is_readable($manifestPath)) return [];
    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest)) return [];
    $results = [];
    foreach ($manifest as $item) {
        $haystack = implode(' ', [(string)($item['title'] ?? ''), (string)($item['theme'] ?? ''), (string)($item['level'] ?? '')]);
        $matched = false;
        foreach ($terms as $term) if (mb_stripos($haystack, $term) !== false) { $matched = true; break; }
        if (!$matched) continue;
        $filename = basename((string)($item['filename'] ?? ''));
        if ($filename === '') continue;
        $results[] = [
            'id' => 'lesson-' . hash('sha256', $filename),
            'title' => (string)($item['title'] ?? $item['theme'] ?? '静态教案'),
            'summary' => (string)($item['theme'] ?? ''),
            'category' => '专业知识',
            'url' => '/lessons/' . rawurlencode($filename),
            'type_label' => '教案',
            'center' => '学习中心',
            'content_type' => 'lesson',
            'source_type' => 'static_manifest',
            'source_path' => 'lessons/' . $filename,
            'canonical_url' => '/lessons/' . rawurlencode($filename),
            'version_id' => null,
            'matched_fields' => ['title', 'theme', 'level'],
        ];
        if (count($results) >= $limit) break;
    }
    return $results;
}

function searchStaticContentIndex(array $terms, int $limit = 20): array {
    $indexPath = dirname(__DIR__, 2) . '/content-index.json';
    if (!is_readable($indexPath)) return [];
    $entries = json_decode((string)file_get_contents($indexPath), true);
    if (!is_array($entries)) return [];
    $results = [];
    foreach ($entries as $item) {
        $haystack = implode(' ', [
            (string)($item['title'] ?? ''),
            (string)($item['summary'] ?? ''),
            implode(' ', (array)($item['keywords'] ?? [])),
        ]);
        $matched = false;
        foreach ($terms as $term) {
            if (mb_stripos($haystack, (string)$term) !== false) {
                $matched = true;
                break;
            }
        }
        if (!$matched) continue;
        $results[] = [
            'id' => (string)($item['stable_key'] ?? ''),
            'title' => (string)($item['title'] ?? ''),
            'summary' => (string)($item['summary'] ?? ''),
            'category' => (string)($item['primary_category'] ?? ''),
            'url' => (string)($item['canonical_url'] ?? ''),
            'type_label' => (string)($item['content_type'] ?? ''),
            'center' => (string)($item['center_code'] ?? ''),
            'content_type' => (string)($item['content_type'] ?? ''),
            'source_type' => (string)($item['source_type'] ?? ''),
            'source_path' => (string)($item['source_path'] ?? ''),
            'canonical_url' => (string)($item['canonical_url'] ?? ''),
            'version_id' => $item['version_id'] ?? null,
            'matched_fields' => ['title', 'summary', 'keywords'],
        ];
        if (count($results) >= $limit) break;
    }
    return $results;
}

function searchNormalizeResults(array $results, string $section): array {
    $defaults = [
        'knowledge' => ['center' => '知识中心', 'content_type' => 'knowledge_card'],
        'policies' => ['center' => '制度中心', 'content_type' => 'policy'],
        'courses' => ['center' => '学习中心', 'content_type' => 'course'],
        'drills' => ['center' => '演练中心', 'content_type' => 'drill'],
        'training' => ['center' => '学习中心', 'content_type' => 'training'],
        'scripts' => ['center' => '演练中心', 'content_type' => 'script'],
    ];
    return array_map(function (array $item) use ($section, $defaults): array {
        $item['center'] = $item['center'] ?? ($defaults[$section]['center'] ?? '');
        $item['category'] = $item['category'] ?? '';
        $item['content_type'] = $item['content_type'] ?? ($defaults[$section]['content_type'] ?? $section);
        $item['canonical_url'] = $item['canonical_url'] ?? ($item['url'] ?? '');
        $item['url'] = $item['url'] ?? $item['canonical_url'];
        $item['matched_fields'] = $item['matched_fields'] ?? ['title'];
        $item['source_type'] = $item['source_type'] ?? 'database';
        $item['source_path'] = $item['source_path'] ?? '';
        $item['version_id'] = array_key_exists('version_id', $item) ? $item['version_id'] : null;
        return $item;
    }, $results);
}

function searchUnifiedContentIndex(PDO $db, array $terms, array $context, int $limit = 20): array {
    $params = [];
    $where = "WHERE u.status = 'active' AND u.publication_status = 'published'";
    $where .= " AND " . searchLikeSql(['u.title', 'u.summary', 'u.body', 'u.tags', 'u.primary_category'], $terms, $params);
    $params[] = $limit;

    $sql = "SELECT u.stable_key, u.center_code, u.primary_category, u.content_type, u.title,
                    u.summary, u.source_type, u.source_path, u.canonical_url, u.version_id
             FROM unified_content_index u
             $where
             ORDER BY u.updated_at DESC
             LIMIT ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return array_map(static function (array $item): array {
        return [
            'id' => $item['stable_key'],
            'title' => $item['title'],
            'summary' => $item['summary'] ?? '',
            'category' => $item['primary_category'] ?? '',
            'url' => $item['canonical_url'],
            'type_label' => $item['content_type'],
            'center' => $item['center_code'],
            'content_type' => $item['content_type'],
            'source_type' => $item['source_type'],
            'source_path' => $item['source_path'],
            'canonical_url' => $item['canonical_url'],
            'version_id' => $item['version_id'],
            'matched_fields' => ['title', 'summary', 'body', 'tags', 'category'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function searchUnifiedSection(string $contentType): string {
    if (in_array($contentType, ['knowledge_card', 'knowledge'], true)) return 'knowledge';
    if (in_array($contentType, ['policy', '制度'], true)) return 'policies';
    if (in_array($contentType, ['course', 'lesson', 'training'], true)) return 'training';
    if (in_array($contentType, ['drill', 'script'], true)) return 'drills';
    return 'training';
}

function searchAll(PDO $db, string $query, string $type, array $context): array {
    $terms = searchExpandTerms($query);
    $like = '%' . $terms[0] . '%';
    $results = [];
    $errors = [];

    $wants = function (string $key, string $label) use ($type): bool {
        return $type === 'all' || $type === $key || $type === $label;
    };

    $indexed = searchRunSection('unified_index', fn() => searchUnifiedContentIndex($db, $terms, $context), $errors);
    foreach ($indexed as $item) {
        $section = searchUnifiedSection((string)($item['content_type'] ?? ''));
        if ($wants($section, ['knowledge' => '知识', 'policies' => '制度', 'training' => '培训', 'drills' => '演练'][$section] ?? '')) {
            $results[$section][] = $item;
        }
    }

    if ($wants('knowledge', '知识')) {
        $knowledgeResults = searchRunSection('knowledge', fn() => searchKnowledge($db, $terms, $context), $errors);
        $results['knowledge'] = array_merge($results['knowledge'] ?? [], $knowledgeResults);
    }
    if ($wants('policies', '制度') || $wants('policy', '制度')) {
        $results['policies'] = searchRunSection('policies', fn() => searchPolicies($db, $terms, $context), $errors);
    }
    if ($wants('courses', '课程')) {
        $results['courses'] = searchRunSection('courses', fn() => searchSimpleTerms(
            $db,
            "SELECT c.id, c.title, c.description, cc.name AS category_name FROM courses c LEFT JOIN course_categories cc ON cc.id = c.category_id WHERE c.status = 1",
            ['c.title', 'c.description', 'cc.name'],
            $terms,
            "ORDER BY c.sort_order ASC, c.created_at DESC",
            6,
            fn($item) => ['id' => (int)$item['id'], 'title' => $item['title'], 'description' => mb_substr($item['description'] ?? '', 0, 100), 'category' => $item['category_name'] ?? '', 'url' => '/mobile/course.html?id=' . $item['id'], 'type_label' => '课程']
        ), $errors);
    }
    if ($wants('staffs', '员工') && !empty($context['can_view_staff'])) {
        $results['staffs'] = searchRunSection('staffs', fn() => searchSimple(
            $db,
            "SELECT s.id, s.name, s.phone, s.employee_no, s.role, s.job_title, st.name AS store_name FROM staffs s LEFT JOIN stores st ON st.id = s.store_id WHERE s.status = 1 AND (s.name LIKE ? OR s.phone LIKE ? OR s.employee_no LIKE ?) ORDER BY s.name ASC LIMIT 10",
            [$like, $like, $like],
            fn($item) => ['id' => (int)$item['id'], 'name' => $item['name'], 'phone' => searchMaskPhone((string)$item['phone']), 'role' => $item['role'], 'job_title' => $item['job_title'], 'store' => $item['store_name'] ?? '', 'url' => null, 'type_label' => '员工']
        ), $errors);
    }
    if ($wants('drills', '演练')) {
        $results['drills'] = searchRunSection('drills', fn() => searchSimpleTerms(
            $db,
            "SELECT id, title, description, role, stage FROM drill_templates WHERE status = 1",
            ['title', 'description', 'role', 'stage'],
            $terms,
            "ORDER BY created_at DESC",
            5,
            fn($item) => ['id' => (int)$item['id'], 'title' => $item['title'], 'description' => mb_substr($item['description'] ?? '', 0, 100), 'role' => $item['role'], 'url' => '/mobile/drill.html', 'type_label' => '演练']
        ), $errors);
    }
    if ($wants('training', '培训')) {
        $results['training'] = searchRunSection('training', function () use ($db, $terms) {
            $items = searchSimpleTerms($db, "SELECT id, module_name AS title, description, role_code, category FROM training_modules WHERE status = 1", ['module_name', 'description', 'role_code', 'category'], $terms, "ORDER BY sort_order ASC", 3, fn($item) => ['id' => (int)$item['id'], 'title' => $item['title'], 'description' => mb_substr($item['description'] ?? '', 0, 100), 'url' => '/training-module.html?id=' . $item['id'], 'type_label' => '培训模块']);
            return array_merge($items, searchSimpleTerms($db, "SELECT id, title, content, module_id, card_type FROM training_cards WHERE status = 1", ['title', 'content', 'card_type'], $terms, "ORDER BY sort_order ASC", 3, fn($item) => ['id' => (int)$item['id'], 'title' => $item['title'], 'description' => mb_substr($item['content'] ?? '', 0, 100), 'url' => '/training-card.html?id=' . $item['id'], 'type_label' => '培训卡片']));
             $items = array_merge($items, searchStaticContentIndex($terms), searchStaticLessons($terms));
            return $items;
        }, $errors);
    }
    if ($wants('exams', '考试')) {
        $results['exams'] = searchRunSection('exams', fn() => searchSimpleTerms(
            $db,
            "SELECT id, title, description, exam_type FROM exams WHERE is_active = 1",
            ['title', 'description', 'exam_type'],
            $terms,
            "ORDER BY created_at DESC",
            5,
            fn($item) => ['id' => (int)$item['id'], 'title' => $item['title'], 'description' => mb_substr($item['description'] ?? '', 0, 100), 'url' => '/mobile/exam.html?id=' . $item['id'], 'type_label' => '考试']
        ), $errors);
    }
    if ($wants('scripts', '话术')) {
        $results['scripts'] = searchRunSection('scripts', fn() => searchSimpleTerms(
            $db,
            "SELECT id, scene_code, scene_name, standard_script FROM script_knowledge WHERE 1 = 1",
            ['scene_name', 'standard_script', 'scene_code'],
            $terms,
            "ORDER BY id DESC",
            8,
            fn($item) => ['id' => (int)$item['id'], 'title' => $item['scene_name'], 'summary' => searchSnippet($item['standard_script'] ?? '', $terms), 'scene_code' => $item['scene_code'], 'url' => '/mobile/drill.html', 'type_label' => '话术']
        ), $errors);
    }

    foreach ($results as $key => $items) {
        $results[$key] = searchNormalizeResults($items, $key);
        $seen = [];
        $results[$key] = array_values(array_filter($results[$key], static function (array $item) use (&$seen): bool {
            $key = (string)($item['id'] ?? '') . '|' . (string)($item['canonical_url'] ?? '');
            if ($key === '|' || isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        }));
    }
    $labels = ['knowledge' => '知识', 'policies' => '制度', 'courses' => '课程', 'staffs' => '员工', 'scripts' => '话术', 'drills' => '演练', 'training' => '培训', 'exams' => '考试'];
    $tabs = [['type' => 'all', 'label' => '全部', 'count' => 0]];
    $total = 0;
    foreach ($labels as $key => $label) {
        $count = count($results[$key] ?? []);
        $total += $count;
        $tabs[] = ['type' => $key, 'label' => $label, 'count' => $count];
    }
    $tabs[0]['count'] = $total;

    return [
        'query' => $query,
        'terms' => $terms,
        'total' => $total,
        'tabs' => $tabs,
        'results' => $results,
        'errors' => $errors,
        'empty_reason' => $total === 0 ? 'no_match' : null,
    ];
}

function searchRecordNoResult(PDO $db, string $query, array $terms, array $context): bool {
    try {
        $stmt = $db->prepare(
            'INSERT INTO search_query_logs (query_text, expanded_terms_json, user_id, staff_id, result_count, created_at)
             VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            mb_substr(trim($query), 0, 160),
            json_encode(array_values($terms), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (int)($context['user_id'] ?? 0),
            (int)($context['staff_id'] ?? 0),
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('[search.no_result] ' . $e->getMessage());
        return false;
    }
}

function searchMaskPhone(string $phone): string {
    return strlen($phone) >= 7 ? substr($phone, 0, 3) . '****' . substr($phone, -4) : $phone;
}
