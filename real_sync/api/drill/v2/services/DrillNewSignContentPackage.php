<?php

declare(strict_types=1);

final class DrillNewSignContentPackage
{
    public const BATCH_CODE = 'new-sign-skill-v2-20260727';

    public static function payload(): array
    {
        $dimensions = self::dimensions();
        return [
            'domain_code' => 'new_signing',
            'batch_code' => self::BATCH_CODE,
            'source_name' => '新签skill-1.zip',
            'personas' => self::personas(),
            'rubrics' => [
                self::rubric('new_sign_real_call_v1', '新签实操录音评分 V1', ['real_call_review'], $dimensions, false),
                self::rubric('new_sign_training_demo_v1', '新签培训演练评分 V1', ['ai_roleplay', 'training_demo'], $dimensions, true),
            ],
            'mappings' => self::mappings(),
            'reference_materials' => self::referenceMaterials(),
            'calibrations' => self::calibrations(),
            'review_issues' => self::reviewIssues(),
        ];
    }

    public static function hash(): string
    {
        return hash('sha256', self::json(self::payload()));
    }

    private static function dimensions(): array
    {
        $definitions = [
            ['needs_discovery', '需求挖掘', 15, ['购买动机', '核心痛点', '竞品经历', '决策链'], ['三问框架', '孤立核心需求'], ['问答原文与时间戳'], ['只讲课程且不问需求不高于 7 分']],
            ['fab_conversion', 'FAB 价值转化', 20, ['痛点到特征', '机制解释', '价值落地', '品牌背书'], ['痛点到特征到机制到价值', '认知纠偏'], ['课程表述原文与资料版本'], ['至少四门课形成完整 FAB 链路']],
            ['case_insertion', '案例植入', 15, ['命名或化名', '初始状态', '课程周期', '变化结果'], ['匹配客户需求的案例'], ['案例原文、时间戳与授权资料'], ['无案例为 0 至 4 分']],
            ['solution_planning', '方案匹配与规划', 10, ['体测匹配', '分阶段规划', '年包对应', '顾问承接'], ['基础到专项到进阶'], ['体测数据与方案原文'], ['无法独立设计方案不高于 4 分']],
            ['pricing_negotiation', '报价与谈判策略', 10, ['主动报价', '均价法', '条件承诺', '合理紧迫感'], ['先确认再申请'], ['报价原文与价格资料版本'], ['等待家长主动询价扣除主动报价分']],
            ['objection_handling', '异议处理', 15, ['隐形异议识别', '孤立担忧', '确认异议', '解决后推进'], ['除了这个问题还有别的疑问吗'], ['异议信号和处理原文'], ['回避异议为 0 至 4 分']],
            ['trial_close', '试关闭', 10, ['方案后试关闭', '报价前试关闭', '解决异议后再次推进'], ['确认频率与方案选择'], ['试关闭原文与时间戳'], ['实操至少两次，演练至少完整示范一次']],
            ['urgency_creation', '紧迫感制造', 5, ['关键期', '名额', '成长记录', '运动周期'], ['合理紧迫感且禁止恐吓'], ['紧迫感原文与依据资料'], ['恐吓式表达计为风险项']],
        ];
        return array_map(static fn(array $item): array => [
            'code' => $item[0],
            'name' => $item[1],
            'weight' => $item[2],
            'key_actions' => $item[3],
            'standard_expressions' => $item[4],
            'evidence_requirements' => $item[5],
            'calibration_anchors' => $item[6],
        ], $definitions);
    }

    private static function rubric(string $code, string $name, array $contexts, array $dimensions, bool $training): array
    {
        return [
            'rubric_code' => $code,
            'name' => $name,
            'mode' => 'hybrid',
            'status' => 'draft',
            'contexts' => $contexts,
            'dimensions' => $dimensions,
            'critical_items' => ['speaker_mapping_complete', 'evidence_traceable', 'no_fabricated_quote'],
            'score_policy' => [
                'capability_weight' => 0.8,
                'script_match_weight' => 0.2,
                'grade_thresholds' => ['excellent' => 85, 'good' => 70, 'qualified' => 60],
                'exclude_coach_supplements' => $training,
                'training_readiness_rules' => $training ? [
                    'independent_reception' => ['needs_discovery' => 10, 'fab_conversion' => 14, 'trial_close' => 6],
                    'independent_negotiation' => ['total' => 70, 'objection_handling' => 10, 'pricing_negotiation' => 7],
                    'core_dimension_floor_ratio' => 0.5,
                ] : null,
            ],
            'max_score' => 100,
            'pass_score' => 60,
            'source_ref' => $training
                ? '新签skill/追光小牛新签复盘-演练版/SKILL.md@2.0.0'
                : '新签skill/追光小牛新签复盘-实操录音版/SKILL.md@2.0.0',
        ];
    }

    private static function mappings(): array
    {
        return [
            ['dimension_code' => 'needs_discovery', 'stage_code' => 'needs_diagnosis', 'weight' => 1.0],
            ['dimension_code' => 'fab_conversion', 'stage_code' => 'solution_value', 'weight' => 1.0],
            ['dimension_code' => 'case_insertion', 'stage_code' => 'solution_value', 'weight' => 1.0],
            ['dimension_code' => 'solution_planning', 'stage_code' => 'assessment_experience', 'weight' => 0.5],
            ['dimension_code' => 'solution_planning', 'stage_code' => 'solution_value', 'weight' => 0.5],
            ['dimension_code' => 'pricing_negotiation', 'stage_code' => 'objection_signing_handoff', 'weight' => 1.0],
            ['dimension_code' => 'objection_handling', 'stage_code' => 'objection_signing_handoff', 'weight' => 1.0],
            ['dimension_code' => 'trial_close', 'stage_code' => 'objection_signing_handoff', 'weight' => 1.0],
            ['dimension_code' => 'urgency_creation', 'stage_code' => 'objection_signing_handoff', 'weight' => 0.5],
            ['dimension_code' => 'urgency_creation', 'stage_code' => 'followup_referral', 'weight' => 0.5],
        ];
    }

    private static function personas(): array
    {
        return [
            'parent_personality' => ['rational' => '理性分析', 'cautious' => '谨慎观望', 'decisive' => '果断决策', 'anxious' => '焦虑敏感'],
            'child_age' => ['age_2_3' => '2 至 3 岁', 'age_4_6' => '4 至 6 岁', 'age_7_9' => '7 至 9 岁', 'age_10_12' => '10 至 12 岁'],
            'child_trait' => ['slow_to_warm' => '慢热', 'active' => '活跃', 'low_confidence' => '信心不足', 'low_coordination' => '协调性待提升'],
            'core_need' => ['fitness' => '体能提升', 'height' => '身高关注', 'confidence' => '自信表达', 'exam' => '体育达标'],
            'main_concern' => ['price' => '价格', 'distance' => '距离', 'persistence' => '坚持性', 'decision_maker' => '决策人未到场'],
            'intent_stage' => ['exploring' => '初步了解', 'comparing' => '对比机构', 'ready' => '接近决策'],
            'communication_difficulty' => ['basic' => '基础', 'intermediate' => '进阶', 'advanced' => '高难'],
        ];
    }

    private static function referenceMaterials(): array
    {
        return [
            ['material_code' => 'new_sign_brand_course_fab_v1', 'name' => '品牌与课程 FAB 速查', 'material_type' => 'fab', 'source_ref' => '新签skill/references/品牌与课程FAB速查.md', 'content' => ['brand_claims' => ['贵阳本土 7 年品牌', '大众点评 4.8 分及好评榜第 1', '5 家校区通用', '世界冠军联合创始人邓书弟', 'ACE 成长体系'], 'courses' => ['感统体操', '跑酷', '增高体能', '篮球', '跳绳', '搏击'], 'safety_rules' => ['禁止医学化表达', '效果数字发布前核验']]],
            ['material_code' => 'new_sign_packages_pricing_v1', 'name' => '课包与定价速查', 'material_type' => 'pricing', 'source_ref' => '新签skill/references/课包与定价速查.md', 'content' => ['packages' => [['name' => '体验包', 'price' => 1980, 'lessons' => 20], ['name' => '半年包', 'price' => 3688, 'lessons' => 40], ['name' => '年包', 'price' => 6688, 'lessons' => 80]], 'conflicting_offer' => ['base_lessons' => 75, 'gift_lessons' => 3, 'claimed_total' => 78], 'effect_claims' => ['6 至 9 个月运动能力提升 50% 至 65%', '1 至 3 个月能力提升 1% 至 30%']]],
            ['material_code' => 'new_sign_case_library_v1', 'name' => '新签案例库', 'material_type' => 'case_library', 'source_ref' => '新签skill/references/案例库.md', 'content' => ['cases' => ['陈沐言身高案例', '赵夏楠表达案例', '跳绳学员案例', '依依慢热案例', '感统体操学员案例'], 'required_elements' => ['姓名或化名', '初始状态', '训练课程与周期', '变化结果'], 'privacy_rule' => '敏感信息使用化名并完成授权核验']],
            ['material_code' => 'new_sign_calibration_anchors_v1', 'name' => '新签评分校准锚点', 'material_type' => 'calibration_anchor', 'source_ref' => '新签skill/references/评分校准锚点.md', 'content' => ['grades' => ['excellent' => [85, 100], 'good' => [70, 84], 'qualified' => [60, 69], 'unqualified' => [0, 59]], 'max_anchor_deviation' => 15, 'training_rules' => ['只评分示范动作', '排除带教补充', '标注培训传递风险']]],
        ];
    }

    private static function calibrations(): array
    {
        return [
            ['rubric_code' => 'new_sign_real_call_v1', 'evaluation_context' => 'real_call_review', 'anchors' => ['讲解员型' => 30, '陪聊型' => 55, '合格但不精型' => 70, '销冠型' => 85]],
            ['rubric_code' => 'new_sign_training_demo_v1', 'evaluation_context' => 'training_demo', 'anchors' => ['未达独立接待' => 30, '持续带练' => 55, '简单场景可接待' => 70, '学习标杆' => 85]],
            ['rubric_code' => 'new_sign_training_demo_v1', 'evaluation_context' => 'ai_roleplay', 'anchors' => ['未达独立接待' => 30, '持续带练' => 55, '简单场景可接待' => 70, '学习标杆' => 85]],
        ];
    }

    private static function reviewIssues(): array
    {
        return [
            ['code' => 'package_lessons_conflict', 'category' => 'source_conflict', 'subject' => '年包课时口径冲突', 'details' => ['package_table' => 80, 'offer_formula' => '75 + 3 = 78']],
            ['code' => 'brand_numbers_unverified', 'category' => 'business_number', 'subject' => '品牌与门店数字待业务确认', 'details' => ['claims' => ['7 年', '2000+ 家庭', '3000+ 会员', '100000 人次', '大众点评 4.8 分及榜首', '5 家校区']]],
            ['code' => 'effect_claims_unverified', 'category' => 'effect_claim', 'subject' => '效果表达缺少审核依据', 'details' => ['claims' => ['最大额外增长 2 公分', '运动能力提升 50% 至 65%', '能力提升 1% 至 30%']]],
            ['code' => 'case_authorization_missing', 'category' => 'authorization', 'subject' => '案例授权及匿名化待确认', 'details' => ['case_count' => 5, 'sensitive_case_present' => true]],
            ['code' => 'material_validity_missing', 'category' => 'validity', 'subject' => '参考资料有效期未提供', 'details' => ['required_fields' => ['effective_from', 'effective_until']]],
        ];
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
