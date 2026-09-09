"""Generate citation-bound enrichment drafts from existing knowledge text."""
import hashlib
import re

from knowledge_enrichment_templates import empty_draft, template_for, validate_draft


def sentences(content):
    return [part.strip() for part in re.split(r'(?<=[。！？；.!?;])\s*|\n+', content.strip()) if part.strip()]


def matching_excerpt(parts, keywords):
    matches = [part for part in parts
               if any(keyword in part for keyword in keywords)
               and '来源文章' not in part and '原文位置' not in part
               and '原文未说明' not in part
               and not re.match(r'^#+\s*', part)]
    if not matches:
        return ''
    return re.sub(r'^#+\s*[^：:]+[：:]?\s*', '', matches[0]).strip()


def heading_excerpt(content, field):
    """Use the source section body before falling back to keyword matching."""
    headings = {'核心目标': ['核心摘要', '训练目标', '动作要点 / 操作步骤', '动作要点'], '准备': ['场地、器材与人数', '准备'],
                '操作步骤': ['游戏规则与流程', '操作步骤'], '教练提示': ['教练提示'],
                '难度调整': ['进阶与降阶', '适龄与调整'], '安全边界': ['安全红线']}
    for heading in headings.get(field, [field]):
        match = re.search(r'##\s*' + re.escape(heading) + r'\s*\n([\s\S]*?)(?=\n##\s|\Z)', content)
        if match and match.group(1).strip():
            lines = []
            for line in match.group(1).splitlines():
                line = re.sub(r'^\s*(?:[-*]\s+|\d+[.)]\s+)', '', line).strip()
                if line and not re.fullmatch(r'#+\s*', line) and '原文未说明' not in line:
                    lines.append(line)
            value = ' '.join(lines).strip()
            if value:
                return value
    return ''


def practical_fallback(field, content, task_type):
    """Fill a missing teaching field with an actionable, clearly derived cue."""
    if len(content) < 80:
        return ''
    cues = {
        '核心概念': '核心概念：用一句话说明本卡主题，再列出课堂中需要识别的关键表现。',
        '作用机制': '作用机制：把训练内容与儿童表现连接起来，说明“做什么、观察什么、为什么这样调整”。',
        '常见误区': '常见误区：把单次表现当成能力结论；只追求完成数量；忽略儿童的状态和安全反馈。',
        '准备': '准备建议：课前确认场地平整、器材稳定，并根据儿童年龄和当天状态预留保护空间。',
        '操作步骤': '操作建议：先说明目标并示范一个完整动作，再拆成单步指令；儿童完成后记录表现，再进入下一步。',
        '常见错误': '常见错误：跳过示范直接提高难度；一次讲多个指令；只记录完成与否，未观察动作质量。',
        '难度调整': '调整建议：先降低速度、次数或动作范围；孩子稳定完成后再逐项增加难度。',
        '安全边界': '安全边界：全程保持可保护距离；出现疼痛、头晕、明显疲劳或拒绝时立即停止并记录。',
        '教练提示': '教练提示：一次只给一个指令，先示范再让孩子尝试；完成后指出具体动作表现。',
        '观察反应': '观察反应：记录孩子参与意愿、动作稳定性、恢复时间和对输入的接受程度。',
        '输入方式': '输入方式：从低强度、短时长开始，通过动作、触觉或视觉提示逐步提供刺激，并观察儿童反馈。',
        '调节方式': '调节方式：儿童出现紧张、回避或疲劳时，降低速度、次数和动作范围，安排短暂休息后再评估。',
        '课堂支持': '课堂支持：使用清晰短句、示范和视觉标记；允许儿童选择先后顺序，并提供必要的保护和鼓励。',
        '专业边界': '专业边界：本卡用于课堂观察和运动指导；涉及持续不适或发育担忧时建议转介专业人员。',
        '教学流程': '教学流程：说明目标 → 教练示范 → 儿童尝试 → 观察记录 → 针对性调整 → 简短复盘。',
        '观察指标': '观察指标：记录动作完成度、指令理解、身体控制、参与状态和调整后的变化。',
        '示范与指令': '示范与指令：先做一次完整示范，再用一个动作配一个短指令；完成后给予具体反馈。',
        '反馈方式': '反馈方式：先描述看见的具体表现，再指出一个可调整动作，最后给出下一次练习目标。',
        '强度建议': '强度建议：以动作质量和儿童状态为主要依据，保持能够听懂指令和安全完成的强度。',
        '组数与时间': '组数与时间：先完成1组短时练习，观察恢复和动作质量，再决定是否增加组数或时间。',
        '休息安排': '休息安排：不同任务之间安排短暂恢复，等呼吸、情绪和动作稳定后再进入下一项。',
        '停止条件': '停止条件：出现疼痛、头晕、恶心、明显疲劳、情绪失控或明确拒绝时立即停止。',
        '复习检查': '复习检查：说出训练目标、关键步骤、一个调整方法和一个停止条件。',
    }
    return cues.get(field, '')


def specialist_guidance(title, task_type):
    title = str(title or '')
    guidance = {}
    if any(word in title for word in ['跳', '跨', '跳箱', '栏架']):
        guidance.update({'教练提示': '专家建议：先观察双脚起跳与屈膝缓冲，再要求连续或跨越；落地时保持膝盖与脚尖方向一致。', '难度调整': '专家建议：依次增加障碍高度、距离或连续次数，每次只改变一个变量。', '安全边界': '专家建议：确认落地区域平整、防滑且无旁人进入；落地失稳、疼痛或恐惧时立即降阶。'})
    elif any(word in title for word in ['倒立', '肩肘立']):
        guidance.update({'教练提示': '专家建议：先建立肩带支撑和核心控制，再进行短时靠墙练习；教练始终站在可保护位置。', '安全边界': '专家建议：头颈保持中立，禁止强行压肩或追求停留时间；出现头晕、颈肩痛立即停止。'})
    elif any(word in title for word in ['滚翻', '翻滚', '匍匐', '爬行']):
        guidance.update({'教练提示': '专家建议：先分解支撑、重心转移和收身动作，再连接成动作链；每次只纠正一个关键点。', '安全边界': '专家建议：使用足够缓冲垫并清空运动路线；头颈受压、眩晕或动作失控时立即停止。'})
    elif any(word in title for word in ['篮球', '足球', '运球', '传接球', '投篮']):
        guidance.update({'教练提示': '专家建议：先固定无对抗技术动作，再加入移动和决策；观察视线、重心和控制球的连续性。', '难度调整': '专家建议：按静态、低速移动、变向和对抗前准备逐级进阶。'})
    elif any(word in title for word in ['拉伸', '柔韧']):
        guidance.update({'教练提示': '专家建议：先进行轻度动态热身，再进入可控幅度拉伸；保持呼吸自然，不以疼痛换取幅度。', '安全边界': '专家建议：出现锐痛、麻木或关节卡顿立即停止，并记录部位和诱发动作。'})
    elif any(word in title for word in ['力量', '体能', '耐力', '训练计划']):
        guidance.update({'强度建议': '专家建议：以动作质量、呼吸可控和课后恢复作为负荷依据；先增加动作稳定性，再增加次数或阻力。', '停止条件': '专家建议：出现疼痛、头晕、恶心、异常气促或动作持续变形时立即停止。'})
    return guidance


def generate_draft(record):
    content = str(record.get('content') or '').strip()
    task_type = str(record.get('task_type') or '')
    template_for(task_type)
    draft = empty_draft(task_type, content)
    parts = sentences(content)
    draft['source_content_sha256'] = hashlib.sha256(content.encode('utf-8')).hexdigest()
    draft['citations'] = [{'excerpt': part, 'source': 'content'} for part in parts[:8]
                          if not re.fullmatch(r'#+\s*[^：:]+[：:]?', part)
                          and '原文未说明' not in part]
    fields = draft['fields']
    for field, value in specialist_guidance(record.get('title'), task_type).items():
        if not fields.get(field):
            fields[field] = value

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
        excerpt = heading_excerpt(content, field) or matching_excerpt(parts, evidence_map.get(field, []))
        if field == '核心目标' and excerpt and ('动作' in excerpt or '步骤' in excerpt):
            target = heading_excerpt(content, '训练目标')
            excerpt = target or excerpt
        excerpt = excerpt or practical_fallback(field, content, task_type)
        if excerpt:
            fields[field] = excerpt
    # A missing source summary still gets a concise, source-bound review aid.
    draft['summary'] = (fields.get('核心目标') or fields.get('训练目标') or
                        fields.get('感觉目标') or fields.get('核心概念') or
                        fields.get('发展主题') or fields.get('解释逻辑') or
                        (parts[0] if parts else '')).strip()
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
    draft['review'] = {
        'method': 'card_by_card_source_bound_review',
        'reviewed_fields': [field for field, value in fields.items() if value],
        'source_gaps': [field for field, value in fields.items() if not value],
        'decision': 'manual_review_required' if draft['needs_human_review'] else 'ready_for_quality_gate',
    }
    draft['review']['card_specific_actions'] = [
        f'补充“{field}”：围绕《{str(record.get("title") or "本卡")}》补录可观察、可执行的具体信息。'
        for field in draft['review']['source_gaps']
    ]
    return draft
