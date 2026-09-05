<?php
declare(strict_types=1);

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/knowledge/EmployeeKnowledgeVisibilityQuery.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$currentUserId = getCurrentUserId();
if ($currentUserId <= 0) {
    http_response_code(401);
    echo json_encode(array('error' => '请先登录'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('error' => 'Method not allowed'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$requestStream = PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input';
$payload = json_decode(file_get_contents($requestStream) ?: '{}', true);
if (!is_array($payload)) {
    $payload = array();
}

$defaults = array(
    'className' => '追光小牛成长班',
    'ageRange' => '4-5 岁',
    'trainingFocus' => '协调、平衡、核心控制',
    'classProfile' => '10 人班，2 名新生，需要持续建立课堂规则',
    'monthlyGoal' => '围绕 ACE 建立基础动作控制和规则感',
    'cycleWeeks' => 4,
    'classChallenge' => '热身衔接慢，孩子器材轮换时容易分心',
    'parentFocus' => '提醒家长配合家庭练习，强化规则感和专注度',
);

$data = array();
foreach ($defaults as $key => $default) {
    $value = $payload[$key] ?? $default;
    if (is_string($default)) {
        if (!is_scalar($value)) {
            $value = $default;
        }
        $value = trim((string) $value);
        $value = $value !== '' ? $value : $default;
        $data[$key] = function_exists('mb_substr') ? mb_substr($value, 0, 240, 'UTF-8') : substr($value, 0, 240);
        continue;
    }

    $weeks = is_scalar($value) ? (int) $value : (int) $default;
    $data[$key] = max(2, min(8, $weeks ?: (int) $default));
}

$library = array(
    'lessonFrame' => array(
        'segments' => array('热身与规则建立', '主体技能训练', '游戏与情境应用', '放松总结与反馈'),
        'defaultMaterials' => array('标志桶', '软垫', '小球', '跳绳'),
    ),
    'weeklyTemplates' => array(
        array('title' => '建立规则与动作基础', 'goal' => '建立课堂规则，观察动作基础并完成安全适应。'),
        array('title' => '稳定动作与节奏', 'goal' => '在重复练习中提高动作稳定性和课堂节奏。'),
        array('title' => '组合动作与情境应用', 'goal' => '把核心动作放入游戏情境，提升理解和迁移。'),
        array('title' => '挑战展示与阶段反馈', 'goal' => '完成阶段挑战，记录表现并形成家长反馈。'),
        array('title' => '巩固基础与个体调整', 'goal' => '巩固核心动作，并按学员表现实施升降阶。'),
        array('title' => '增加变化与协作任务', 'goal' => '通过路线和协作变化提高规则执行与投入感。'),
        array('title' => '综合应用与自主选择', 'goal' => '让学员在安全边界内完成动作选择和综合应用。'),
        array('title' => '周期复盘与成果展示', 'goal' => '复盘周期目标，展示进步并明确下一阶段重点。'),
    ),
    'resourcePacks' => array(
        array(
            'id' => 'stable-default-template',
            'sourcePriority' => 10,
            'title' => 'ACE 稳定默认模板',
            'description' => '系统内置的完整教案生成底稿。',
            'keywords' => array('ACE', '课堂', '动作', '规则', '安全', '反馈'),
            'audience' => array('全龄段'),
            'aceFocus' => array(
                'A：围绕核心动作安排可观察、可升降阶的练习任务。',
                'C：通过规则、口令和任务选择促进理解与判断。',
                'E：用成功体验、同伴协作和具体反馈保持课堂投入。',
            ),
            'warmupModules' => array('口令反应与关节激活', '移动热身与课堂规则复习', '低强度动作预演'),
            'coreModules' => array('基础动作分解练习', '分层循环训练', '动作组合与情境应用'),
            'gameModules' => array('规则闯关游戏', '小组协作挑战', '阶段成果展示'),
            'coachTips' => array('课前确认器材、保护站位和轮换路线。', '根据动作质量提供升阶或降阶选择。'),
            'parentTips' => array('反馈本节课的具体动作表现、规则变化和家庭练习建议。'),
            'references' => array(),
        ),
    ),
);

function sc_tokenize(string $text): array
{
    $tokens = preg_split('/[\s,，、\/\-]+/u', sc_lower($text)) ?: array();
    return array_values(array_filter($tokens, static fn($item) => $item !== ''));
}

function sc_lower(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function sc_contains(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    if (function_exists('mb_strpos')) {
        return mb_strpos($haystack, $needle) !== false;
    }

    return strpos($haystack, $needle) !== false;
}

function sc_unique_list(array $items): array
{
    $seen = array();
    $output = array();
    foreach ($items as $item) {
        if ($item === null || $item === '') {
            continue;
        }
        $key = is_array($item) ? md5((string) json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : (string) $item;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $output[] = $item;
    }
    return $output;
}

function sc_strip_html(string $text): string
{
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
    return trim($text);
}

function sc_fetch_json(string $url): array
{
    $context = stream_context_create(array(
        'http' => array(
            'timeout' => 6,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ),
    ));

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return array();
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : array();
}

function sc_unique_posts(array $posts): array
{
    $seen = array();
    $output = array();
    foreach ($posts as $post) {
        if (!is_array($post) || !isset($post['id']) || isset($seen[$post['id']])) {
            continue;
        }
        $seen[$post['id']] = true;
        $output[] = $post;
    }
    return $output;
}

function sc_collect_pack_values(array $packs, string $key, int $limit = 0): array
{
    $items = array();
    foreach ($packs as $pack) {
        if (!is_array($pack) || !isset($pack[$key]) || !is_array($pack[$key])) {
            continue;
        }
        $items = array_merge($items, $pack[$key]);
    }

    $items = sc_unique_list($items);
    return $limit > 0 ? array_slice($items, 0, $limit) : $items;
}

function sc_fetch_wordpress_pack(array $config, string $baseUrl): ?array
{
    $categories = sc_fetch_json($baseUrl . '/wp-json/wp/v2/categories?search=' . rawurlencode($config['categorySearch']) . '&per_page=20');
    $matchedCategories = array_values(array_filter($categories, static function ($item) use ($config) {
        if (!is_array($item) || !isset($item['name'])) {
            return false;
        }
        foreach (($config['categoryNames'] ?? array($config['name'])) as $name) {
            if ($item['name'] === $name || sc_contains((string) $item['name'], (string) $name)) {
                return true;
            }
        }
        return false;
    }));

    $posts = array();
    foreach ($matchedCategories as $category) {
        $posts = array_merge($posts, sc_fetch_json($baseUrl . '/wp-json/wp/v2/posts?categories=' . (int) $category['id'] . '&per_page=6&_fields=id,link,title,excerpt'));
    }
    foreach (($config['searchTerms'] ?? array()) as $term) {
        $posts = array_merge($posts, sc_fetch_json($baseUrl . '/wp-json/wp/v2/posts?search=' . rawurlencode($term) . '&per_page=4&_fields=id,link,title,excerpt'));
    }

    $posts = array_slice(sc_unique_posts($posts), 0, 8);
    if (!$posts) {
        return null;
    }

    $keywords = $config['keywords'] ?? array();
    foreach ($posts as $post) {
        $keywords = array_merge($keywords, sc_tokenize(sc_strip_html((string) (($post['title']['rendered'] ?? '')))));
    }

    return array(
        'pack' => array(
            'id' => 'wp-' . md5((string) $config['name']),
            'title' => $config['name'] . '实时资料包',
            'description' => '由后台资料库与已发布内容隐性驱动，不在员工前台展示原始资料明细。',
            'keywords' => array_slice(sc_unique_list($keywords), 0, 10),
            'audience' => $config['audience'] ?? array('全龄段'),
            'aceFocus' => $config['aceFocus'] ?? array(),
            'warmupModules' => $config['warmupModules'] ?? array(),
            'coreModules' => $config['coreModules'] ?? array(),
            'gameModules' => $config['gameModules'] ?? array(),
            'coachTips' => $config['coachTips'] ?? array(),
            'parentTips' => $config['parentTips'] ?? array(),
        ),
        'postCount' => count($posts),
    );
}

function sc_split_filename_tokens(string $name): array
{
    $normalized = preg_replace('/\.[^.]+$/', '', $name) ?: $name;
    $normalized = preg_replace('/[_\-]+/u', ' ', $normalized) ?: $normalized;
    $parts = preg_split('/\s+/u', $normalized) ?: array();
    $tokens = array();
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || preg_match('/^\d+$/', $part)) {
            continue;
        }
        $tokens[] = $part;
    }
    return array_slice(sc_unique_list($tokens), 0, 12);
}

function sc_fetch_excel_pack(string $baseUrl): ?array
{
    $tablesDir = __DIR__ . '/wp-content/uploads/tables';
    if (!is_dir($tablesDir)) {
        return null;
    }

    $files = glob($tablesDir . '/*.{xls,xlsx,csv}', GLOB_BRACE) ?: array();
    if (!$files) {
        return null;
    }

    rsort($files);
    $sampleFiles = array_slice($files, 0, 12);
    $keywords = array('表格', '教案', '评估', '课堂', '培训', '反馈', '升班');

    foreach ($sampleFiles as $file) {
        $keywords = array_merge($keywords, sc_split_filename_tokens(basename($file)));
    }

    return array(
        'id' => 'excel-tables-pack',
        'title' => 'Excel 教案资料包',
        'description' => '自动扫描已上传的 Excel 表格并隐性纳入教案匹配。',
        'keywords' => array_slice(sc_unique_list($keywords), 0, 18),
        'audience' => array('全龄段'),
        'aceFocus' => array(
            'A：参考已上传表格中的训练目标、评估项和课堂模板补齐执行方案。',
            'C：让周计划和课堂步骤更贴近门店已有资料与执行口径。',
            'E：把上传资料中的活动、反馈和展示模板转成可落地的课堂结果。',
        ),
        'warmupModules' => array('表格资料热身套用', '已上传模板快速选用', '班级情况对照开场'),
        'coreModules' => array('上传教案模块匹配', '评估表单要点抽取', '课堂流程模板复用'),
        'gameModules' => array('表格活动方案套用', '阶段展示模板', '反馈结构快速生成'),
        'coachTips' => array(
            '新增 Excel 教案上传到 wp-content/uploads/tables 后，后台会自动纳入后续教案匹配。',
            '表格名称尽量写清年龄段、项目和场景，方便后台自动识别。',
        ),
        'parentTips' => array(
            '家长反馈优先沿用门店已有表格中的阶段目标和家庭练习口径。',
        ),
    );
}

function sc_excerpt(string $text, int $length = 100): string
{
    $text = sc_strip_html($text);
    if ($text === '') {
        return '';
    }
    return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
}

function sc_source_score(string $text, array $data): int
{
    $source = sc_lower($text);
    $tokens = sc_tokenize(implode(' ', array(
        $data['className'],
        $data['ageRange'],
        $data['trainingFocus'],
        $data['monthlyGoal'],
        $data['classProfile'],
        $data['classChallenge'],
    )));
    $score = 1;
    foreach ($tokens as $token) {
        if (strlen($token) >= 2 && sc_contains($source, $token)) {
            $score += 3;
        }
    }
    return $score;
}

function sc_fetch_knowledge_pack(PDO $db, array $data): ?array
{
    $knowledgeSource = EmployeeKnowledgeVisibilityQuery::fromCurrentVersion();
    $statement = $db->query(
        "SELECT k.id, k.item_code, kv.version_id, "
        . "COALESCE(NULLIF(kv.title, ''), k.title) AS title, "
        . "COALESCE(NULLIF(kv.content, ''), k.content) AS content, "
        . "COALESCE(NULLIF(kv.content_type, ''), k.content_type) AS content_type, "
        . "COALESCE(NULLIF(kv.subject, ''), k.subject) AS subject, "
        . "COALESCE(NULLIF(kv.age_group, ''), k.age_group) AS age_group, "
        . "COALESCE(NULLIF(kv.training_type, ''), k.training_type) AS training_type, "
        . "COALESCE(NULLIF(kv.tags_json, ''), k.tags) AS tags "
        . "FROM " . $knowledgeSource . " "
        . "WHERE k.current_version_id IS NOT NULL "
        . "AND COALESCE(NULLIF(kv.content_type, ''), k.content_type) IN ('action', 'game', 'safety', 'training_plan') "
        . "ORDER BY k.id DESC LIMIT 2000"
    );
    $cards = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (!$cards) {
        return null;
    }

    foreach ($cards as &$card) {
        $card['score'] = sc_source_score(implode(' ', array(
            $card['title'] ?? '',
            $card['content'] ?? '',
            $card['subject'] ?? '',
            $card['age_group'] ?? '',
            $card['training_type'] ?? '',
            $card['tags'] ?? '',
        )), $data);
    }
    unset($card);
    usort($cards, static fn(array $left, array $right): int => [$right['score'], $left['id']] <=> [$left['score'], $right['id']]);

    $actions = array();
    $games = array();
    $safety = array();
    $plans = array();
    $references = array();
    $keywords = array();
    foreach ($cards as $card) {
        $type = (string) ($card['content_type'] ?? '');
        $title = trim((string) ($card['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        if ($type === 'action' && count($actions) < 8) {
            $actions[] = $title;
        } elseif ($type === 'game' && count($games) < 8) {
            $games[] = $title;
        } elseif ($type === 'safety' && count($safety) < 6) {
            $safety[] = sc_excerpt((string) ($card['content'] ?? '')) ?: $title;
        } elseif ($type === 'training_plan' && count($plans) < 6) {
            $plans[] = $title;
        } else {
            continue;
        }
        $keywords[] = $title;
        $references[] = array(
            'sourceType' => 'knowledge_card',
            'id' => (int) $card['id'],
            'itemCode' => (string) ($card['item_code'] ?? ''),
            'versionId' => (int) ($card['version_id'] ?? 0),
            'title' => $title,
        );
    }

    if (!$actions && !$games && !$safety && !$plans) {
        return null;
    }

    return array(
        'id' => 'published-knowledge-cards',
        'sourcePriority' => 30,
        'title' => '已发布知识卡',
        'description' => '来自当前已发布知识卡版本的动作、游戏、安全和教学计划。',
        'keywords' => $keywords,
        'audience' => array($data['ageRange']),
        'aceFocus' => array(
            'A：从已发布动作卡选择核心练习，并按课堂表现实施升降阶。',
            'C：通过教学计划和任务规则帮助学员理解动作目标。',
            'E：通过已发布游戏卡建立挑战、协作和成功体验。',
        ),
        'warmupModules' => array_slice(array_merge($actions, $plans), 0, 6),
        'coreModules' => array_slice(array_merge($actions, $plans), 0, 8),
        'gameModules' => array_slice($games, 0, 8),
        'coachTips' => array_slice($safety, 0, 6),
        'parentTips' => array('结合本节课知识卡目标，反馈一个动作证据和一个规则表现。'),
        'references' => array_slice($references, 0, 20),
    );
}

function sc_html_class_values(string $html, string $className): array
{
    $pattern = '~<[^>]+class=["\'][^"\']*\\b' . preg_quote($className, '~') . '\\b[^"\']*["\'][^>]*>(.*?)</(?:div|span)>~is';
    preg_match_all($pattern, $html, $matches);
    return sc_unique_list(array_values(array_filter(array_map(static fn(string $value): string => sc_strip_html($value), $matches[1] ?? array()))));
}

function sc_fetch_static_lesson_pack(array $data): ?array
{
    $lessonsDir = realpath(__DIR__ . '/lessons');
    $manifestPath = __DIR__ . '/lessons/manifest.json';
    if ($lessonsDir === false || !is_file($manifestPath)) {
        return null;
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        return null;
    }

    $lessons = array();
    foreach ($manifest as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $filename = (string) ($entry['filename'] ?? '');
        if ($filename === '' || basename($filename) !== $filename) {
            continue;
        }
        $path = realpath($lessonsDir . DIRECTORY_SEPARATOR . $filename);
        if ($path === false || !str_starts_with($path, $lessonsDir . DIRECTORY_SEPARATOR) || !is_file($path)) {
            continue;
        }
        $entry['path'] = $path;
        $entry['score'] = sc_source_score(implode(' ', array(
            $entry['month'] ?? '',
            $entry['level'] ?? '',
            $entry['week'] ?? '',
            $entry['theme'] ?? '',
            $entry['title'] ?? '',
        )), $data);
        $lessons[] = $entry;
    }
    if (!$lessons) {
        return null;
    }
    usort($lessons, static fn(array $left, array $right): int => ($right['score'] ?? 0) <=> ($left['score'] ?? 0));

    $warmups = array();
    $cores = array();
    $games = array();
    $references = array();
    $keywords = array();
    foreach (array_slice($lessons, 0, 8) as $lesson) {
        $html = (string) file_get_contents($lesson['path']);
        $modules = array_merge(sc_html_class_values($html, 'section-name'), sc_html_class_values($html, 'section-activity'));
        foreach ($modules as $module) {
            if (preg_match('/热身|激活/u', $module)) {
                $warmups[] = $module;
            } elseif (preg_match('/游戏|竞赛|闯关/u', $module)) {
                $games[] = $module;
            } elseif (preg_match('/拉伸|放松/u', $module)) {
                continue;
            } else {
                $cores[] = $module;
            }
        }
        $theme = trim((string) ($lesson['theme'] ?? ''));
        if ($theme !== '') {
            $cores[] = $theme;
            $keywords[] = $theme;
        }
        $references[] = array(
            'sourceType' => 'static_lesson',
            'filename' => (string) $lesson['filename'],
            'title' => (string) ($lesson['title'] ?? $lesson['filename']),
        );
    }

    return array(
        'id' => 'static-lesson-library',
        'sourcePriority' => 20,
        'title' => '现有静态教案库',
        'description' => '来自 lessons/manifest.json 登记且文件存在的教案。',
        'keywords' => sc_unique_list($keywords),
        'audience' => array($data['ageRange']),
        'aceFocus' => array('A：参考现有教案的动作编排和训练顺序。', 'C：沿用现有周教案中的主题与规则任务。', 'E：通过熟悉的游戏和讲评结构保持参与感。'),
        'warmupModules' => array_slice(sc_unique_list($warmups), 0, 8),
        'coreModules' => array_slice(sc_unique_list($cores), 0, 12),
        'gameModules' => array_slice(sc_unique_list($games), 0, 8),
        'coachTips' => array('结合班级实际人数和器材条件调整现有教案模块。'),
        'parentTips' => array('按本周主题反馈课堂表现，并给出一个可执行的家庭练习。'),
        'references' => $references,
    );
}

function sc_resolve_base_url(): string
{
    $configured = trim((string) getenv('SMART_LESSONS_BASE_URL'));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $forwardedProto = trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $https = (string) ($_SERVER['HTTPS'] ?? '');
    $scheme = $forwardedProto !== '' ? $forwardedProto : (($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http');

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = trim((string) ($_SERVER['SERVER_NAME'] ?? ''));
    }
    if ($host === '') {
        throw new RuntimeException('无法确定教案资料站点地址');
    }

    return $scheme . '://' . $host;
}

$wordPressSources = array(
    array(
        'name' => '知识库',
        'categorySearch' => '知识库',
        'categoryNames' => array('知识库'),
        'keywords' => array('知识库', '课堂', '反馈', '沟通', '执行'),
        'audience' => array('全龄段'),
        'aceFocus' => array('A：沉淀课堂执行经验和关键动作证据', 'C：补充老师在课堂判断与口令切换中的执行细节', 'E：整理真实案例，帮助老师更稳定地建立课堂投入感'),
        'warmupModules' => array('知识卡片复盘热身', '口令反应破冰', '课堂规则快速对齐'),
        'coreModules' => array('课堂观察点提炼', '反馈话术套用', '家长沟通证据整理'),
        'gameModules' => array('案例演练闯关', '场景问答对练', '课堂复盘接力'),
        'coachTips' => array('先读知识库案例，再决定本节课的重点观察点。', '课堂结束前保留 2 分钟记录动作证据和孩子状态。'),
        'parentTips' => array('反馈时优先说孩子的动作表现和规则变化，再补家庭练习建议。'),
    ),
    array(
        'name' => '教学标准',
        'categorySearch' => '教学标准',
        'categoryNames' => array('教学标准'),
        'keywords' => array('ACE', '教学', '评估', 'SOP', '升班', '体测'),
        'audience' => array('4-5 岁', '5-6 岁', '6-8 岁', '全龄段'),
        'aceFocus' => array('A：对齐 ACE、SOP 和升班评估标准，保证动作训练方向稳定', 'C：让老师在每周计划中明确课堂目标、观察项和难点处理', 'E：用统一标准保持课堂节奏、评价方式和家长反馈体验'),
        'warmupModules' => array('ACE 激活热身', '评估项预检热身', '课堂节奏标准化开场'),
        'coreModules' => array('ACE 标准动作训练', '课程 SOP 对照训练', '升班评估项穿插练习'),
        'gameModules' => array('标准动作挑战赛', '评估点闯关', '课堂节奏协同赛'),
        'coachTips' => array('周计划先对齐教学标准，再安排动作模块和游戏目标。', '同一节课里只强化 1-2 个核心评估点，避免口径分散。'),
        'parentTips' => array('向家长说明本周练习与 ACE、升班或评估标准的对应关系。'),
    ),
    array(
        'name' => '培训资料库',
        'categorySearch' => '培训',
        'categoryNames' => array('培训资料库', '入职培训', '技能培训', '管理培训'),
        'searchTerms' => array('入职培训', '培训', '上岗', '资料'),
        'keywords' => array('培训', '课件', '上岗', '资料', '流程'),
        'audience' => array('全龄段'),
        'aceFocus' => array('A：把培训资料转成老师可直接执行的课堂准备动作', 'C：帮助新老员工快速理解流程、标准和资料之间的对应关系', 'E：降低上岗前的理解门槛，让培训内容能直接进入课堂与服务动作'),
        'warmupModules' => array('培训要点速览', '课前资料清单确认', '工具表单预检'),
        'coreModules' => array('培训材料拆解', '上岗流程演练', '资料模板对照应用'),
        'gameModules' => array('培训问答闯关', '流程排序挑战', '资料调用演练'),
        'coachTips' => array('先从培训资料库确认本周要统一的口径、工具和执行步骤。', '优先引用能直接指导课堂和反馈的资料，避免只堆制度标题。'),
        'parentTips' => array('向家长反馈时可引用培训统一口径，保持课堂说明和服务表达一致。'),
    ),
    array(
        'name' => '新员工学习',
        'categorySearch' => '学习',
        'categoryNames' => array('新员工学习', '人员管理', '入职培训'),
        'searchTerms' => array('新员工', '入职', '上岗', '学习'),
        'keywords' => array('新员工', '入职', '学习', '上岗', '带教'),
        'audience' => array('全龄段'),
        'aceFocus' => array('A：帮助新员工理解基础岗位动作、课堂要求和上岗标准', 'C：让学习内容能直接转成课堂执行、沟通和协作动作', 'E：降低新员工进入真实场景时的不确定感，提升投入和稳定度'),
        'warmupModules' => array('入职要点回顾', '岗位职责快速对齐', '上课前流程预演'),
        'coreModules' => array('新员工必学拆解', '课堂陪练与跟岗', '服务流程标准化训练'),
        'gameModules' => array('上岗情景模拟', '话术接龙演练', '流程找错训练'),
        'coachTips' => array('给新员工排周计划时，优先引用新员工学习里的必学内容和上岗顺序。', '输出教案时兼顾课堂动作和服务沟通，不只写训练模块。'),
        'parentTips' => array('新员工带班阶段的家长反馈要更简洁稳定，优先复用标准话术和课堂证据。'),
    ),
);

$knowledgePack = null;
$knowledgeError = null;
if (getenv('SMART_LESSONS_DISABLE_KNOWLEDGE') === '1') {
    $knowledgeError = 'knowledge_base_disabled';
} else {
    try {
        $knowledgePack = sc_fetch_knowledge_pack(getDB(), $data);
    } catch (Throwable $exception) {
        $knowledgeError = 'knowledge_base_unavailable';
        error_log('Smart lesson knowledge source unavailable: ' . $exception->getMessage());
    }
}

$staticLessonPack = getenv('SMART_LESSONS_DISABLE_STATIC') === '1' ? null : sc_fetch_static_lesson_pack($data);
$stablePacks = array_values(array_filter(array($knowledgePack, $staticLessonPack)));
$library['resourcePacks'] = array_merge($stablePacks, $library['resourcePacks']);

function sc_score_pack(array $pack, array $data): int
{
    $pool = sc_tokenize(implode(' ', array($data['ageRange'], $data['trainingFocus'], $data['monthlyGoal'], $data['classProfile'], $data['classChallenge'], $data['parentFocus'])));
    $score = 0;
    foreach (($pack['keywords'] ?? array()) as $keyword) {
        $keyword = sc_lower((string) $keyword);
        foreach ($pool as $token) {
            if (sc_contains($token, $keyword) || sc_contains($keyword, $token)) {
                $score += 3;
                break;
            }
        }
    }
    foreach (($pack['audience'] ?? array()) as $item) {
        $normalized = preg_replace('/\s+/u', '', (string) $item) ?: (string) $item;
        if (sc_contains($data['ageRange'], $normalized) || sc_contains($data['ageRange'], (string) $item)) {
            $score += 2;
        }
    }
    if ($score === 0 && ($pack['id'] ?? '') === 'ace-foundation') {
        return 1;
    }
    return $score;
}

$matches = array_map(static function ($pack) use ($data) {
    $pack['score'] = sc_score_pack($pack, $data);
    return $pack;
}, $library['resourcePacks'] ?? array());

usort($matches, static fn($left, $right) => [($right['sourcePriority'] ?? 0), ($right['score'] ?? 0)] <=> [($left['sourcePriority'] ?? 0), ($left['score'] ?? 0)]);
$matches = array_values(array_filter(array_slice($matches, 0, 3), static fn($pack, $index) => ($pack['score'] ?? 0) > 0 || $index === 0, ARRAY_FILTER_USE_BOTH));

$frame = $library['lessonFrame'] ?? array('segments' => array(), 'defaultMaterials' => array());
$templates = $library['weeklyTemplates'] ?? array();
$primaryPack = $matches[0] ?? array();
$secondaryPack = $matches[1] ?? array();

$weeks = array();
for ($index = 0; $index < $data['cycleWeeks']; $index++) {
    $base = $templates[$index] ?? end($templates) ?: array('title' => '第 ' . ($index + 1) . ' 周推进', 'goal' => '围绕本月目标继续推进。');
    $weeks[] = array(
        'title' => $base['title'],
        'goal' => $base['goal'],
        'warmup' => ($primaryPack['warmupModules'][$index % max(1, count($primaryPack['warmupModules'] ?? array()))] ?? '课堂热身'),
        'core' => ($primaryPack['coreModules'][$index % max(1, count($primaryPack['coreModules'] ?? array()))] ?? $data['trainingFocus']),
        'game' => ($primaryPack['gameModules'][$index % max(1, count($primaryPack['gameModules'] ?? array()))] ?? '闯关游戏'),
        'coachTip' => ($primaryPack['coachTips'][$index % max(1, count($primaryPack['coachTips'] ?? array()))] ?? $data['classChallenge']),
        'parentTip' => ($primaryPack['parentTips'][$index % max(1, count($primaryPack['parentTips'] ?? array()))] ?? $data['parentFocus']),
    );
}

$segments = array();
foreach (($frame['segments'] ?? array()) as $index => $segment) {
    if ($index === 0) {
        $segments[] = $segment . '：使用 ' . ($weeks[0]['warmup'] ?? '课堂热身') . ' 建立课堂节奏。';
    } elseif ($index === 1) {
        $segments[] = $segment . '：围绕 ' . $data['trainingFocus'] . ' 做 ' . ($weeks[0]['core'] ?? $data['trainingFocus']) . ' 训练。';
    } elseif ($index === 2) {
        $segments[] = $segment . '：用 ' . ($weeks[0]['game'] ?? '闯关游戏') . ' 强化参与动能和规则执行。';
    } else {
        $segments[] = $segment . '：复盘课堂表现，并围绕“' . $data['parentFocus'] . '”输出家长反馈。';
    }
}

$response = array(
    'monthlySummary' => '本月围绕“' . $data['monthlyGoal'] . '”推进，结合已发布知识卡、现有教案与 ACE 模板形成分周训练安排。',
    'aceFocus' => sc_collect_pack_values($matches, 'aceFocus', 5),
    'materials' => array_slice(array_merge($frame['defaultMaterials'] ?? array(), array_slice($primaryPack['coreModules'] ?? array(), 0, 2)), 0, 8),
    'segments' => $segments,
    'coachTips' => array_slice(array_merge($primaryPack['coachTips'] ?? array(), $secondaryPack['coachTips'] ?? array()), 0, 4),
    'parentTips' => array_slice(array_merge($primaryPack['parentTips'] ?? array(), $secondaryPack['parentTips'] ?? array()), 0, 3),
    'weeks' => $weeks,
    'libraryStatus' => array(
        'knowledgeAvailable' => $knowledgePack !== null,
        'knowledgeCardCount' => count($knowledgePack['references'] ?? array()),
        'staticLessonsAvailable' => $staticLessonPack !== null,
        'staticLessonCount' => count($staticLessonPack['references'] ?? array()),
        'defaultTemplateUsed' => $knowledgePack === null && $staticLessonPack === null,
        'degradedSources' => array_values(array_filter(array(
            $knowledgePack === null ? ($knowledgeError ?: 'knowledge_base_empty') : null,
            $staticLessonPack === null ? 'static_lessons_unavailable' : null,
        ))),
    ),
    'sourceReferences' => array_slice(sc_collect_pack_values($matches, 'references'), 0, 20),
);

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
