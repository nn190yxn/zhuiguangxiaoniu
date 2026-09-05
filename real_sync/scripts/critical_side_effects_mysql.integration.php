<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/platform/IdempotencyService.php';
require_once __DIR__ . '/../api/platform/PrivateFileStorage.php';
require_once __DIR__ . '/../api/points/PointsExchangeService.php';
require_once __DIR__ . '/../api/points/DailyCheckinService.php';
require_once __DIR__ . '/../api/exam/ExamSubmissionService.php';
require_once __DIR__ . '/../api/lesson-submissions/LessonSubmissionService.php';
require_once __DIR__ . '/../api/lesson-submissions/LessonExportService.php';

function testConfig(): array
{
    $config = [];
    foreach (['TEST_DB_HOST', 'TEST_DB_NAME', 'TEST_DB_USER', 'TEST_DB_PASSWORD'] as $key) {
        $value = getenv($key);
        if ($value === false || trim($value) === '') {
            throw new RuntimeException($key . ' is required');
        }
        $config[$key] = $value;
    }
    $port = getenv('TEST_DB_PORT');
    $config['TEST_DB_PORT'] = $port !== false && $port !== '' ? (int) $port : 3306;
    if ($config['TEST_DB_PORT'] < 1 || $config['TEST_DB_PORT'] > 65535) {
        throw new RuntimeException('TEST_DB_PORT must be a valid TCP port');
    }
    if (preg_match('/(test|staging|stage|qa)/i', $config['TEST_DB_NAME']) !== 1) {
        throw new RuntimeException('TEST_DB_NAME must identify a dedicated test database');
    }
    return $config;
}

function connectTestDb(array $config): PDO
{
    return new PDO(
        'mysql:host=' . $config['TEST_DB_HOST'] . ';port=' . $config['TEST_DB_PORT']
        . ';dbname=' . $config['TEST_DB_NAME'] . ';charset=utf8mb4',
        $config['TEST_DB_USER'],
        $config['TEST_DB_PASSWORD'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function ensureSchema(PDO $db): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS platform_idempotency_records (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            actor_type VARCHAR(16) NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            operation VARCHAR(80) NOT NULL,
            business_scope VARCHAR(160) NOT NULL,
            idempotency_key_hash CHAR(64) NOT NULL,
            request_fingerprint CHAR(64) NOT NULL,
            request_id VARCHAR(128) NOT NULL,
            status ENUM('processing', 'completed', 'failed') NOT NULL DEFAULT 'processing',
            http_status SMALLINT UNSIGNED NULL,
            response_json JSON NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            completed_at DATETIME(6) NULL,
            expires_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_platform_idempotency_identity (actor_type, actor_id, operation, business_scope, idempotency_key_hash),
            KEY idx_platform_idempotency_expiry (expires_at, status),
            KEY idx_platform_idempotency_request (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS user_points (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            total_points INT NOT NULL DEFAULT 0,
            accumulated_points INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS points_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL,
            points INT NOT NULL,
            status TINYINT NOT NULL DEFAULT 1,
            UNIQUE KEY uq_points_rules_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS points_records (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            rule_id BIGINT UNSIGNED NULL,
            points INT NOT NULL,
            balance INT NOT NULL,
            type VARCHAR(16) NOT NULL,
            source VARCHAR(32) NOT NULL,
            source_id BIGINT UNSIGNED NULL,
            business_date DATE NULL,
            description VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_points_records_user_business_date (user_id, business_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS points_exchange_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            points_price INT NOT NULL,
            stock INT NOT NULL,
            exchange_count INT NOT NULL DEFAULT 0,
            status TINYINT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS points_exchange_records (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            points_spent INT NOT NULL,
            receiver_name VARCHAR(50) NOT NULL,
            receiver_phone VARCHAR(20) NOT NULL,
            receiver_address VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS exams (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pass_score INT NOT NULL,
            is_active TINYINT NOT NULL DEFAULT 1,
            exam_paper VARCHAR(8) NOT NULL DEFAULT 'A'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS exam_questions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            exam_id BIGINT UNSIGNED NOT NULL,
            question_type TINYINT NOT NULL,
            answer TEXT NOT NULL,
            score INT NOT NULL,
            analysis TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS exam_records (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            module_id BIGINT UNSIGNED NOT NULL,
            exam_type VARCHAR(32) NOT NULL,
            total_score INT NOT NULL DEFAULT 0,
            passing_score INT NOT NULL DEFAULT 0,
            is_passed TINYINT NOT NULL DEFAULT 0,
            answers JSON NULL,
            wrong_answers JSON NULL,
            duration INT NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL,
            completed_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS lesson_submissions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            store_id BIGINT UNSIGNED NULL,
            store_name VARCHAR(128) NOT NULL,
            author_staff_id BIGINT UNSIGNED NOT NULL,
            author_name VARCHAR(128) NOT NULL,
            course_line VARCHAR(128) NOT NULL,
            class_level VARCHAR(128) NOT NULL,
            lesson_date DATE NOT NULL,
            title VARCHAR(255) NOT NULL,
            status VARCHAR(32) NOT NULL,
            current_version_id BIGINT UNSIGNED NULL,
            created_by BIGINT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS lesson_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            submission_id BIGINT UNSIGNED NOT NULL,
            version_no INT NOT NULL,
            content_json JSON NOT NULL,
            version_type VARCHAR(32) NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            UNIQUE KEY uq_lesson_version (submission_id, version_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS lesson_exports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            submission_id BIGINT UNSIGNED NOT NULL,
            version_id BIGINT UNSIGNED NOT NULL,
            format VARCHAR(8) NOT NULL,
            status VARCHAR(32) NOT NULL,
            storage_key VARCHAR(512) NULL,
            error_message TEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            completed_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS lesson_audit_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            submission_id BIGINT UNSIGNED NOT NULL,
            version_id BIGINT UNSIGNED NULL,
            actor_staff_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(64) NOT NULL,
            from_status VARCHAR(32) NULL,
            to_status VARCHAR(32) NULL,
            metadata_json JSON NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($statements as $statement) {
        $db->exec($statement);
    }
}

function contextFor(int $userId, ?int $staffId, string $action): PlatformRequestContext
{
    return PlatformRequestContext::fromServer([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/integration/' . $action,
        'HTTP_X_REQUEST_ID' => 'mysql-' . bin2hex(random_bytes(8)),
    ], [
        'domain' => 'integration',
        'action' => $action,
        'actor_user_id' => $userId,
        'actor_staff_id' => $staffId,
    ]);
}

function runIdempotent(
    array $config,
    int $userId,
    ?int $staffId,
    string $operation,
    string $scope,
    string $key,
    array $request,
    callable $callback
): array {
    $db = connectTestDb($config);
    $context = contextFor($userId, $staffId, $operation);
    $result = (new PlatformIdempotencyService($db))->execute(
        $context,
        $operation,
        $scope,
        $key,
        $request,
        static function () use ($callback, $db, $context): PlatformApiResponse {
            usleep(150000);
            return PlatformApiResponse::success($context, $callback($db), 'integration success');
        }
    );
    return ['status' => $result->httpStatus(), 'payload' => $result->payload(), 'replayed' => $result->replayed()];
}

function runConcurrent(callable $worker): array
{
    if (!function_exists('pcntl_fork')) {
        throw new RuntimeException('pcntl extension is required');
    }
    $children = [];
    for ($index = 0; $index < 2; $index++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Unable to create worker socket');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork integration worker');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            try {
                $output = ['ok' => true, 'result' => $worker()];
            } catch (Throwable $error) {
                $output = ['ok' => false, 'error' => get_class($error) . ': ' . $error->getMessage()];
            }
            fwrite($pair[1], json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            fclose($pair[1]);
            exit($output['ok'] ? 0 : 1);
        }
        fclose($pair[1]);
        $children[] = ['pid' => $pid, 'socket' => $pair[0]];
    }

    $results = [];
    foreach ($children as $child) {
        $json = stream_get_contents($child['socket']);
        fclose($child['socket']);
        pcntl_waitpid($child['pid'], $status);
        $output = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        if (!$output['ok'] || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException((string) ($output['error'] ?? 'Integration worker failed'));
        }
        $results[] = $output['result'];
    }
    return $results;
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function scalar(PDO $db, string $sql, array $params = []): mixed
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function verifyReplay(array $results, ?string $idField = null): void
{
    assertTrue(count($results) === 3, 'Expected two concurrent responses and one retry');
    foreach ($results as $result) {
        assertTrue($result['status'] === 200, 'Idempotent request must return HTTP 200');
        assertTrue(($result['payload']['code'] ?? null) === 0, 'Idempotent request must return business code 0');
    }
    $snapshots = array_map(
        static fn(array $result): string => json_encode($result['payload']['data'], JSON_THROW_ON_ERROR),
        $results
    );
    assertTrue(count(array_unique($snapshots)) === 1, 'All retries must replay the first business response');
    if ($idField !== null) {
        $ids = array_map(static fn(array $result): int => (int) $result['payload']['data'][$idField], $results);
        assertTrue(count(array_unique($ids)) === 1 && $ids[0] > 0, 'All retries must replay the first business identifier');
    }
    assertTrue(in_array(false, array_column($results, 'replayed'), true), 'One request must execute the business operation');
    assertTrue(in_array(true, array_column($results, 'replayed'), true), 'Duplicate requests must replay the snapshot');
}

function exerciseExchange(array $config, int $runId): array
{
    $db = connectTestDb($config);
    $userId = $runId + 1;
    $db->prepare('INSERT INTO user_points (user_id, total_points, accumulated_points) VALUES (?, 100, 100)')
        ->execute([$userId]);
    $db->prepare("INSERT INTO points_exchange_items (title, points_price, stock, exchange_count, status) VALUES (?, 30, 1, 0, 1)")
        ->execute(['integration-' . $runId]);
    $itemId = (int) $db->lastInsertId();
    unset($db);

    $request = ['item_id' => $itemId, 'receiver_name' => '测试用户', 'receiver_phone' => '13800000000', 'receiver_address' => '测试地址'];
    $worker = static fn(): array => runIdempotent(
        $config, $userId, null, 'points.exchange', 'item:' . $itemId, 'exchange-' . $runId, $request,
        static fn(PDO $pdo): array => (new PointsExchangeService($pdo))->exchange($userId, $itemId, '测试用户', '13800000000', '测试地址')
    );
    $results = [...runConcurrent($worker), $worker()];
    verifyReplay($results, 'exchange_id');

    $db = connectTestDb($config);
    assertTrue((int) scalar($db, 'SELECT stock FROM points_exchange_items WHERE id = ?', [$itemId]) === 0, 'Exchange stock must decrement once');
    assertTrue((int) scalar($db, 'SELECT total_points FROM user_points WHERE user_id = ?', [$userId]) === 70, 'Exchange points must decrement once');
    assertTrue((int) scalar($db, "SELECT COUNT(*) FROM points_records WHERE user_id = ? AND source = 'exchange'", [$userId]) === 1, 'Exchange points ledger must contain one row');
    assertTrue((int) scalar($db, 'SELECT COUNT(*) FROM points_exchange_records WHERE user_id = ? AND item_id = ?', [$userId, $itemId]) === 1, 'Exchange record must contain one row');
    return ['name' => 'points_exchange', 'requests' => 3, 'business_rows' => 1];
}

function exerciseCheckin(array $config, int $runId): array
{
    $db = connectTestDb($config);
    $userId = $runId + 2;
    $db->exec("INSERT INTO points_rules (code, points, status) VALUES ('daily_checkin', 5, 1) ON DUPLICATE KEY UPDATE points = VALUES(points), status = 1");
    unset($db);

    $date = gmdate('Y-m-d');
    $request = ['business_date' => $date];
    $worker = static fn(): array => runIdempotent(
        $config, $userId, null, 'points.daily_checkin', 'date:' . $date, 'checkin-' . $runId, $request,
        static fn(PDO $pdo): array => (new DailyCheckinService($pdo))->checkIn($userId, $date)
    );
    $results = [...runConcurrent($worker), $worker()];
    verifyReplay($results);
    $db = connectTestDb($config);
    assertTrue((int) scalar($db, "SELECT COUNT(*) FROM points_records WHERE user_id = ? AND source = 'checkin' AND business_date = ?", [$userId, $date]) === 1, 'Daily check-in must create one ledger row');
    assertTrue((int) scalar($db, 'SELECT total_points FROM user_points WHERE user_id = ?', [$userId]) === 5, 'Daily check-in points must increment once');
    return ['name' => 'daily_checkin', 'requests' => 3, 'business_rows' => 1];
}

function exerciseExam(array $config, int $runId): array
{
    $db = connectTestDb($config);
    $userId = $runId + 3;
    $db->exec("INSERT INTO exams (pass_score, is_active, exam_paper) VALUES (60, 1, 'A')");
    $examId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO exam_questions (exam_id, question_type, answer, score, analysis, sort_order) VALUES (?, 1, 'A', 100, '', 1)")
        ->execute([$examId]);
    $questionId = (int) $db->lastInsertId();
    unset($db);

    $request = ['source_exam_id' => $examId, 'selected_exam_id' => $examId, 'paper_code' => 'A', 'answers' => [(string) $questionId => 'A'], 'time_spent' => 15];
    $worker = static fn(): array => runIdempotent(
        $config, $userId, null, 'exam.submit', 'exam:' . $examId, 'exam-' . $runId, $request,
        static fn(PDO $pdo): array => (new ExamSubmissionService($pdo))->submit($userId, $request)
    );
    $results = [...runConcurrent($worker), $worker()];
    verifyReplay($results, 'exam_record_id');
    $db = connectTestDb($config);
    assertTrue((int) scalar($db, "SELECT COUNT(*) FROM exam_records WHERE user_id = ? AND module_id = ? AND status = 'completed'", [$userId, $examId]) === 1, 'Exam submission must create one completed result');
    return ['name' => 'exam_submission', 'requests' => 3, 'business_rows' => 1];
}

function lessonMetadata(int $runId): array
{
    return [
        'store_name' => '测试门店',
        'author_name' => '测试教练',
        'course_line' => 'ACE',
        'class_level' => 'L1',
        'lesson_date' => gmdate('Y-m-d'),
        'title' => 'integration-' . $runId,
    ];
}

function exerciseLessonCreate(array $config, int $runId, string $storageRoot): array
{
    $staffId = $runId + 4;
    $request = lessonMetadata($runId);
    $worker = static fn(): array => runIdempotent(
        $config, $staffId, $staffId, 'lesson_submission.create', 'staff:' . $staffId, 'lesson-create-' . $runId, $request,
        static fn(PDO $pdo): array => (new LessonSubmissionService($pdo, new PlatformPrivateFileStorage($storageRoot)))->createWithinTransaction($request, $staffId)
    );
    $results = [...runConcurrent($worker), $worker()];
    verifyReplay($results, 'id');
    $submissionId = (int) $results[0]['payload']['data']['id'];
    $db = connectTestDb($config);
    assertTrue((int) scalar($db, 'SELECT COUNT(*) FROM lesson_submissions WHERE id = ?', [$submissionId]) === 1, 'Lesson create must create one submission');
    assertTrue((int) scalar($db, 'SELECT COUNT(*) FROM lesson_versions WHERE submission_id = ?', [$submissionId]) === 1, 'Lesson create must create one initial version');
    return ['name' => 'lesson_create', 'requests' => 3, 'business_rows' => 1, 'submission_id' => $submissionId, 'staff_id' => $staffId];
}

function exerciseLessonExport(array $config, int $runId, string $storageRoot, int $submissionId, int $staffId): array
{
    $db = connectTestDb($config);
    $versionId = (int) scalar($db, 'SELECT current_version_id FROM lesson_submissions WHERE id = ?', [$submissionId]);
    unset($db);
    $request = ['submission_id' => $submissionId, 'version_id' => $versionId, 'format' => 'xlsx'];
    $scope = 'submission:' . $submissionId . ':version:' . $versionId . ':format:xlsx';
    $worker = static fn(): array => runIdempotent(
        $config, $staffId, $staffId, 'lesson_submission.export', $scope, 'lesson-export-' . $runId, $request,
        static fn(PDO $pdo): array => (new LessonExportService($pdo, new PlatformPrivateFileStorage($storageRoot)))->createWithinTransaction($submissionId, 'xlsx', $staffId, $versionId)
    );
    $results = [...runConcurrent($worker), $worker()];
    verifyReplay($results, 'export_id');
    $db = connectTestDb($config);
    assertTrue((int) scalar($db, "SELECT COUNT(*) FROM lesson_exports WHERE submission_id = ? AND version_id = ? AND format = 'xlsx' AND status = 'completed'", [$submissionId, $versionId]) === 1, 'Lesson export must create one completed result');
    return ['name' => 'lesson_export', 'requests' => 3, 'business_rows' => 1];
}

try {
    $config = testConfig();
    $db = connectTestDb($config);
    ensureSchema($db);
    $runId = random_int(100000000, 800000000);
    $storageRoot = rtrim((string) (getenv('TEST_PRIVATE_FILE_ROOT') ?: sys_get_temp_dir() . '/mc-critical-side-effects'), '/');
    unset($db);

    $checks = [];
    $checks[] = exerciseExchange($config, $runId);
    $checks[] = exerciseCheckin($config, $runId);
    $checks[] = exerciseExam($config, $runId);
    $lesson = exerciseLessonCreate($config, $runId, $storageRoot);
    $checks[] = $lesson;
    $checks[] = exerciseLessonExport($config, $runId, $storageRoot, $lesson['submission_id'], $lesson['staff_id']);

    echo json_encode([
        'ok' => true,
        'database_classification' => 'dedicated_test',
        'run_id' => $runId,
        'checks' => $checks,
        'total_checks' => count($checks),
        'total_requests' => array_sum(array_column($checks, 'requests')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error_type' => get_class($error),
        'message' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
