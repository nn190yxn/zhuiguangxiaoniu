<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
handleCORS();

$pdo = summerCampDb();
summerCampEnsureSchema($pdo);

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        throw new InvalidArgumentException('不支持的请求方法');
    }
    
    $context = appRequireStaffContext();
    $staffId = (int) ($context['staff_id'] ?? 0);
    $storeId = (int) ($context['store_id'] ?? 0);
    
    if ($staffId <= 0) {
        throw new InvalidArgumentException('无法获取教练身份');
    }
    
    $input = appInputArray();
    $action = trim((string) ($input['action'] ?? ''));
    
    if ($action === 'save_record') {
        $campType = trim((string) ($input['camp_type'] ?? ''));
        if (!summerCampValidateCampType($campType)) {
            throw new InvalidArgumentException('营类型无效');
        }
        
        $studentName = trim((string) ($input['student_name'] ?? ''));
        if ($studentName === '') {
            throw new InvalidArgumentException('学员姓名不能为空');
        }
        
        $studentGender = trim((string) ($input['student_gender'] ?? ''));
        $studentGrade = trim((string) ($input['student_grade'] ?? ''));
        $studentAge = (int) ($input['student_age'] ?? 0);
        $studentHeight = (float) ($input['student_height'] ?? 0);
        $studentWeight = (float) ($input['student_weight'] ?? 0);
        $phone = trim((string) ($input['phone'] ?? ''));
        $assessmentDate = trim((string) ($input['assessment_date'] ?? date('Y-m-d')));
        
        if ($assessmentDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $assessmentDate)) {
            throw new InvalidArgumentException('评估日期格式无效');
        }
        
        $testData = (array) ($input['test_data'] ?? []);
        
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO summer_camp_assessments 
            (camp_type, student_name, student_gender, student_grade, student_age, student_height, student_weight, phone, staff_id, store_id, assessment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $campType, $studentName, $studentGender, $studentGrade, $studentAge, 
            $studentHeight, $studentWeight, $phone, $staffId, $storeId, $assessmentDate
        ]);
        $assessmentId = (int) $pdo->lastInsertId();
        
        if (!empty($testData)) {
            $stmt = $pdo->prepare("
                INSERT INTO summer_camp_test_data 
                (assessment_id, metric_code, metric_value, rating, percentile)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($testData as $item) {
                $metricCode = trim((string) ($item['metric_code'] ?? ''));
                $metricValue = (float) ($item['metric_value'] ?? 0);
                $rating = trim((string) ($item['rating'] ?? ''));
                $percentile = (int) ($item['percentile'] ?? 0);
                
                if ($metricCode !== '') {
                    $stmt->execute([$assessmentId, $metricCode, $metricValue, $rating, $percentile]);
                }
            }
        }
        
        $pdo->commit();
        
        appJsonSuccess([
            'assessment_id' => $assessmentId,
            'camp_name' => summerCampGetCampName($campType)
        ], '保存成功');
    }
    
    if ($action === 'save_report') {
        $assessmentId = (int) ($input['assessment_id'] ?? 0);
        if ($assessmentId <= 0) {
            throw new InvalidArgumentException('缺少评估记录ID');
        }
        
        $stmt = $pdo->prepare("SELECT id, staff_id FROM summer_camp_assessments WHERE id = ? LIMIT 1");
        $stmt->execute([$assessmentId]);
        $assessment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assessment || (int) $assessment['staff_id'] !== $staffId) {
            throw new InvalidArgumentException('记录不存在或无权限');
        }
        
        $aiContent = trim((string) ($input['ai_content'] ?? ''));
        $coachRemarks = trim((string) ($input['coach_remarks'] ?? ''));
        $coachName = trim((string) ($input['coach_name'] ?? ''));
        $coachPhone = trim((string) ($input['coach_phone'] ?? ''));
        $coachStore = trim((string) ($input['coach_store'] ?? ''));
        $reportDate = trim((string) ($input['report_date'] ?? date('Y-m-d')));
        
        $stmt = $pdo->prepare("SELECT id FROM summer_camp_reports WHERE assessment_id = ? LIMIT 1");
        $stmt->execute([$assessmentId]);
        $existingReport = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingReport) {
            $stmt = $pdo->prepare("
                UPDATE summer_camp_reports 
                SET ai_content = ?, coach_remarks = ?, coach_name = ?, coach_phone = ?, coach_store = ?, report_date = ?
                WHERE assessment_id = ?
            ");
            $stmt->execute([$aiContent, $coachRemarks, $coachName, $coachPhone, $coachStore, $reportDate, $assessmentId]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO summer_camp_reports 
                (assessment_id, ai_content, coach_remarks, coach_name, coach_phone, coach_store, report_date)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$assessmentId, $aiContent, $coachRemarks, $coachName, $coachPhone, $coachStore, $reportDate]);
        }
        
        appJsonSuccess(['assessment_id' => $assessmentId], '报告保存成功');
    }
    
    if ($action === 'get_records') {
        $campType = trim((string) ($input['camp_type'] ?? ''));
        $dateFrom = trim((string) ($input['date_from'] ?? ''));
        $dateTo = trim((string) ($input['date_to'] ?? ''));
        
        $where = 'WHERE staff_id = ?';
        $params = [$staffId];
        
        if ($campType !== '' && summerCampValidateCampType($campType)) {
            $where .= ' AND camp_type = ?';
            $params[] = $campType;
        }
        
        if ($dateFrom !== '') {
            $where .= ' AND assessment_date >= ?';
            $params[] = $dateFrom;
        }
        
        if ($dateTo !== '') {
            $where .= ' AND assessment_date <= ?';
            $params[] = $dateTo;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM summer_camp_assessments {$where} ORDER BY assessment_date DESC, created_at DESC LIMIT 100");
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($records as &$record) {
            $record['camp_name'] = summerCampGetCampName($record['camp_type']);
        }
        
        appJsonSuccess(['records' => $records]);
    }
    
    if ($action === 'get_record_detail') {
        $recordId = (int) ($input['id'] ?? 0);
        if ($recordId <= 0) {
            throw new InvalidArgumentException('缺少记录ID');
        }
        
        $stmt = $pdo->prepare("SELECT * FROM summer_camp_assessments WHERE id = ? AND staff_id = ? LIMIT 1");
        $stmt->execute([$recordId, $staffId]);
        $assessment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assessment) {
            throw new InvalidArgumentException('记录不存在或无权限查看');
        }
        
        $assessment['camp_name'] = summerCampGetCampName($assessment['camp_type']);
        
        $stmt = $pdo->prepare("SELECT * FROM summer_camp_test_data WHERE assessment_id = ? ORDER BY id ASC");
        $stmt->execute([$recordId]);
        $assessment['test_data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT * FROM summer_camp_reports WHERE assessment_id = ? LIMIT 1");
        $stmt->execute([$recordId]);
        $assessment['report'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        
        appJsonSuccess(['record' => $assessment]);
    }
    
    throw new InvalidArgumentException('未知的action');
    
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appJsonError(400, $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('summer_camp_api_error: ' . $e->getMessage());
    appJsonError(500, '服务器错误');
}