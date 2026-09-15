"""Generate citation-bound enrichment drafts from existing knowledge text."""
import hashlib
import re

from knowledge_enrichment_templates import empty_draft, template_for, validate_draft


def sentences(content):
    return [part.strip() for part in re.split(r'(?<=[。！？；.!?;])\s*|\n+', content.strip()) if part.strip()]


def matching_excerpt(parts, keywords):
    matches = [part for part in parts if any(keyword in part for keyword in keywords)]
    return matches[0] if matches else ''


def generate_draft(record):
    content = str(record.get('content') or '').strip()
    task_type = str(record.get('task_type') or '')
    template_for(task_type)
    draft = empty_draft(task_type, content)
    parts = sentences(content)
    draft['source_content_sha256'] = hashlib.sha256(content.encode('utf-8')).hexdigest()
    draft['citations'] = [{'excerpt': part, 'source': 'content'} for part in parts[:8]]
    fields = draft['fields']

    evidence_map = {
        '核心目标': ['目标', '增强', '提高', '训练'], '感觉目标': ['感觉', '前庭', '本体', '触觉'],
        '训练目标': ['目标', '耐力', '力量', '速度', '体能'], '发展主题': ['发展', '成长', '里程碑'],
        '适用场景': ['适用', '课堂', '场景'], '沟通场景': ['家长', '沟通', '反馈'],
        '准备': ['准备', '器材', '场地'], '操作步骤': ['步骤', '先', '然后', '接着', '动作'],
        '教学流程': ['流程', '示范', '指令', '步骤'], '输入方式': ['输入', '刺激', '提供'],
        '观察反应': ['观察', '反应', '表现'], '可能表现': ['表现', '出现', '行为'],
        '观察重点': ['观察', '判断', '记录'], '观察指标': ['指标', '观察', '记录'],
        '家长关切': ['家长', '担心', '问题'], '解释逻辑': ['原因', '因为', '解释'],
        '沟通示例': ['可以', '建议', '告诉', '沟通'], '可执行建议': ['建议', '练习', '可以'],
        '示范与指令': ['示范', '指令', '口令'], '反馈方式': ['反馈', '纠正', '提醒'],
        '教练提示': ['提示', '注意', '保持', '控制'], '常见错误': ['错误', '避免', '不要'],
        '难度调整': ['进阶', '降低', '增加', '调整'], '支持策略': ['支持', '帮助', '引导'],
        '家庭建议': ['家庭', '家中', '家长'], '专业边界': ['转介', '专业', '评估', '诊断'],
        '安全边界': ['安全', '注意', '停止', '避免', '风险'], '停止条件': ['停止', '疼痛', '不适'],
        '边界提醒': ['边界', '专业', '评估'], '课堂支持': ['课堂', '支持', '调节'],
        '调节方式': ['调节', '放松', '降低'], '强度建议': ['强度', '速度', '负荷'],
        '组数与时间': ['组', '次', '分钟', '时间'], '休息安排': ['休息', '间歇', '恢复'],
        '进阶标准': ['进阶', '标准', '达标'], '教学流程': ['流程', '步骤', '先', '然后'],
        '常见调整': ['调整', '变化', '改为'], '发展主题': ['发展', '关键期', '可塑性'],
    }
    for field in fields:
        excerpt = matching_excerpt(parts, evidence_map.get(field, []))
        if excerpt:
            fields[field] = excerpt
    risk_flags = []
    if any(word in content for word in ['疼痛', '受伤', '损伤', '康复', '治疗', '诊断']):
        risk_flags.append('professional_review')
    if any(word in content for word in ['极限', '高强度', '快速负重', '颈椎', '倒立']):
        risk_flags.append('physical_safety')
    if any(word in content for word in ['发育迟缓', '自闭', '多动', '障碍', '疾病']):
        risk_flags.append('development_boundary')
    draft['risk_flags'] = sorted(set(risk_flags))
    draft['needs_human_review'] = bool(risk_flags or any(not fields[field] for field in fields))
    draft['validation_errors'] = validate_draft(draft)
    return draft
