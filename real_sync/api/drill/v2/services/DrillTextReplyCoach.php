<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillNewSignPromptContract.php';

final class DrillTextReplyCoach
{
    public static function review(string $stageCode, string $content): array
    {
        $content = trim($content);
        $contract = DrillNewSignPromptContract::forStage($stageCode);
        $missing = [];
        $covered = [];
        foreach (self::signals($stageCode) as $label => $keywords) {
            if (self::containsAny($content, $keywords)) {
                $covered[] = $label;
            } else {
                $missing[] = $label;
            }
        }

        $risks = [];
        foreach (self::riskPatterns() as $risk) {
            if (self::containsAny($content, $risk['keywords'])) {
                $risks[] = ['type' => $risk['type'], 'matched' => $risk['keywords'][0], 'replacement' => $risk['replacement']];
            }
        }

        $suggestions = [];
        if ($missing !== []) {
            $suggestions[] = '补齐：' . implode('、', $missing) . '。';
        }
        if ($risks !== []) {
            $suggestions[] = '将风险表达改为：' . $risks[0]['replacement'];
        }
        $suggestions[] = '可直接使用：' . (string) ($contract['standard_expressions'][0] ?? '先确认事实，再回应需求，最后约定下一步。');
        $suggestions[] = '下一步：' . (string) ($contract['practical_next_steps'][0] ?? '给出一个明确、可执行的下一步。');

        return [
            'status' => $risks !== [] ? 'risk' : ($missing === [] ? 'covered' : 'incomplete'),
            'covered' => $covered,
            'missing' => $missing,
            'risks' => $risks,
            'suggestions' => $suggestions,
        ];
    }

    private static function signals(string $stageCode): array
    {
        return [
            'needs_diagnosis' => ['购买动机' => ['为什么', '原因', '主动', '推荐'], '核心痛点' => ['最希望', '最关心', '问题', '痛点'], '决策链' => ['谁决定', '谁做决定', '商量', '爸爸', '妈妈']],
            'assessment_experience' => ['客观观察' => ['观察到', '看到', '表现'], '基础解释' => ['基础', '能力', '需要加强'], '课程承接' => ['训练', '课程', '后续']],
            'solution_value' => ['痛点对应' => ['需求', '问题', '关注'], '课程特征' => ['小班', '课程', '训练', '体系'], '孩子收益' => ['帮助', '提升', '建立', '变化'], '训练安排' => ['每周', '频率', '计划', '安排']],
            'objection_signing_handoff' => ['真实顾虑' => ['顾虑', '担心', '预算', '价格', '考虑'], '解决方案' => ['可以', '建议', '安排', '方案'], '试关闭' => ['您看', '先报', '是否合适', '定下来']],
            'followup_referral' => ['体验总结' => ['今天', '体验', '体测'], '下一联系时间' => ['明天', '后天', '联系', '回访', '时间']],
        ][$stageCode] ?? ['当前目标' => ['建议', '安排', '下一步']];
    }

    private static function riskPatterns(): array
    {
        return [
            ['type' => 'medical_claim', 'keywords' => ['治疗', '诊断', '失调'], 'replacement' => '我观察到孩子在相关动作上需要加强，建议结合训练持续观察变化。'],
            ['type' => 'absolute_promise', 'keywords' => ['保证有效', '一定', '绝对', '百分之百'], 'replacement' => '我们会根据孩子的表现制定训练计划，效果需要结合持续参与和阶段反馈观察。'],
            ['type' => 'fear_pressure', 'keywords' => ['错过就没有', '再不练就晚了', '最后机会', '不报名就'], 'replacement' => '当前班级和时间可以先帮您确认，您结合家庭安排决定即可。'],
            ['type' => 'unauthorized_discount', 'keywords' => ['我私下给您', '额外优惠', '保证最低价'], 'replacement' => '我先按公开方案说明费用和服务，再帮您确认当前可用政策。'],
        ];
    }

    private static function containsAny(string $content, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            $position = function_exists('mb_strpos') ? mb_strpos($content, $keyword) : strpos($content, $keyword);
            if ($keyword !== '' && $position !== false) {
                return true;
            }
        }
        return false;
    }
}
