<?php

declare(strict_types=1);

final class LessonAceRuleChecker
{
    private const HIGH_RISK_KEYWORDS = ['跳箱', '翻滚', '前滚翻', '后滚翻', '倒立', '攀爬', '越障', '对练', '搏击'];
    private const PROTECTION_KEYWORDS = ['保护', '站位', '手法', '并发', '距离', '停手', '信号'];

    public function check(array $content): array
    {
        $findings = [];
        $metadata = is_array($content['metadata'] ?? null) ? $content['metadata'] : [];
        foreach (['store_name' => '门店', 'author_name' => '作者', 'course_line' => '课程线', 'class_level' => '班级或级别', 'lesson_date' => '上课日期', 'title' => '教案标题'] as $field => $label) {
            if (!$this->hasContent($metadata[$field] ?? null)) {
                $this->finding($findings, 'metadata_required', 'error', 'metadata.' . $field, $label . '不能为空', '补充基本信息', 'ACE 教案标准模板：基本信息');
            }
        }

        $objectives = is_array($content['objectives'] ?? null) ? $content['objectives'] : [];
        foreach (['athletic' => 'A 运动能力', 'cognitive' => 'C 认知能力', 'engagement' => 'E 参与动能'] as $field => $label) {
            if (!$this->hasContent($objectives[$field] ?? null)) {
                $this->finding($findings, 'ace_objective_required', 'error', 'objectives.' . $field, $label . '目标不能为空', '补充可观察、可执行的课堂目标', 'ACE 教案标准模板：本课 ACE 目标');
            }
        }

        $rawPhases = is_array($content['phases'] ?? null) ? $content['phases'] : [];
        $phases = array_values(array_filter($rawPhases, fn(mixed $phase): bool => is_array($phase) && $this->hasContent($phase)));
        if ($phases === []) {
            $this->finding($findings, 'phases_required', 'error', 'phases', '至少需要一个课程环节', '补充热身、主体活动、游戏或放松等环节', 'ACE 教案标准模板：课程流程');
        }
        $totalMinutes = 0.0;
        foreach ($phases as $index => $phase) {
            $path = 'phases.' . $index;
            if (!$this->hasContent($phase['name'] ?? $phase['title'] ?? null)) {
                $this->finding($findings, 'phase_name_required', 'error', $path . '.name', '课程环节缺少名称', '填写环节名称', 'ACE 教案标准模板：课程流程');
            }
            $duration = $phase['duration_minutes'] ?? $phase['duration'] ?? null;
            if (!is_numeric($duration) || (float) $duration <= 0) {
                $this->finding($findings, 'phase_duration_required', 'error', $path . '.duration_minutes', '课程环节必须填写正数时长', '填写该环节预计分钟数', 'ACE 六大原则：时间分配合理');
            } else {
                $totalMinutes += (float) $duration;
            }
            if (!$this->hasContent($phase['activity'] ?? $phase['content'] ?? null)) {
                $this->finding($findings, 'phase_activity_required', 'error', $path . '.activity', '课程环节缺少活动内容', '填写动作、游戏或教学任务', 'ACE 教案标准模板：课程流程');
            }
        }
        $courseMinutes = $metadata['course_duration_minutes'] ?? $metadata['duration_minutes'] ?? null;
        if (is_numeric($courseMinutes) && (float) $courseMinutes > 0 && $totalMinutes > (float) $courseMinutes) {
            $this->finding($findings, 'lesson_duration_exceeded', 'error', 'phases', '课程环节合计时长超过课程总时长', '缩短环节或修正课程总时长', 'ACE 六大原则：各环节时间不得超过课程时长');
        }

        $safety = is_array($content['safety'] ?? null) ? $content['safety'] : [];
        foreach (['physical' => '身体安全', 'psychological' => '心理安全'] as $field => $label) {
            if (!$this->hasContent($safety[$field] ?? null)) {
                $this->finding($findings, 'safety_required', 'error', 'safety.' . $field, $label . '计划不能为空', '填写风险预防、观察和处理动作', 'ACE 六大原则：身体与心理安全');
            }
        }
        $phaseText = $this->text($phases);
        $physicalSafety = $this->text($safety['physical'] ?? null);
        if ($this->containsAny($phaseText, self::HIGH_RISK_KEYWORDS) && !$this->containsAny($physicalSafety, self::PROTECTION_KEYWORDS)) {
            $this->finding($findings, 'high_risk_protection_required', 'error', 'safety.physical', '课程包含高风险动作，身体安全计划缺少具体保护措施', '补充保护站位、手法、并发上限或安全信号', 'ACE 安全原则：高风险动作必须写明保护措施');
        }
        if (!$this->hasContent($content['equipment'] ?? null)) {
            $this->finding($findings, 'equipment_required', 'error', 'equipment', '器材清单不能为空', '填写课堂所需器材及替代方案', 'ACE 六大原则：器材可行');
        }
        if (!$this->hasContent($content['progressions'] ?? null)) {
            $this->finding($findings, 'progression_required', 'error', 'progressions', '缺少动作升阶或降阶方案', '补充不同能力学员的动作调整方案', 'ACE 渐进原则：核心动作应有升阶方向和降阶方案');
        }
        if (!$this->hasContent($content['assistant_responsibilities'] ?? null)) {
            $this->finding($findings, 'assistant_required', 'error', 'assistant_responsibilities', '助教分工不能为空', '明确站位、器材、安全和个别支持职责', 'ACE 六大原则：主教与助教分工清楚');
        }
        $reflection = is_array($content['reflection'] ?? null) ? $content['reflection'] : [];
        foreach (['athletic' => 'A 运动能力', 'cognitive' => 'C 认知能力', 'engagement' => 'E 参与动能'] as $field => $label) {
            if (!$this->hasContent($reflection[$field] ?? null)) {
                $this->finding($findings, 'reflection_required', 'error', 'reflection.' . $field, $label . '课后反思不能为空', '填写课后观察和下一步调整', 'ACE 教案标准模板：课后 ACE 反思');
            }
        }

        $errorCount = count(array_filter($findings, static fn(array $finding): bool => $finding['severity'] === 'error'));
        $warningCount = count(array_filter($findings, static fn(array $finding): bool => $finding['severity'] === 'warning'));
        return ['valid' => $errorCount === 0, 'findings' => $findings, 'error_count' => $errorCount, 'warning_count' => $warningCount, 'total_phase_minutes' => $totalMinutes, 'checked_fields' => ['metadata', 'objectives.athletic', 'objectives.cognitive', 'objectives.engagement', 'phases', 'safety', 'equipment', 'progressions', 'assistant_responsibilities', 'reflection']];
    }

    private function finding(array &$findings, string $code, string $severity, string $fieldPath, string $message, string $action, string $basis): void
    {
        $findings[] = ['code' => $code, 'severity' => $severity, 'priority' => $severity === 'error' ? 'high' : 'medium', 'field_path' => $fieldPath, 'message' => $message, 'action' => $action, 'basis' => $basis];
    }

    private function hasContent(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasContent($item)) return true;
            }
            return false;
        }
        return is_scalar($value) && trim((string) $value) !== '';
    }

    private function text(mixed $value): string
    {
        if (!is_array($value)) return is_scalar($value) ? trim((string) $value) : '';
        return implode(' ', array_filter(array_map(fn(mixed $item): string => $this->text($item), $value)));
    }

    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (mb_strpos($text, $keyword, 0, 'UTF-8') !== false) return true;
        }
        return false;
    }
}
