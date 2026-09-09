"""Structured templates used by the knowledge-card enrichment workflow."""

TEMPLATES = {
    'movement': {
        'label': '动作与游戏',
        'fields': ['核心目标', '准备', '操作步骤', '教练提示', '常见错误', '难度调整', '安全边界'],
    },
    'sensory': {
        'label': '感觉统合',
        'fields': ['感觉目标', '输入方式', '观察反应', '调节方式', '课堂支持', '专业边界'],
    },
    'fitness': {
        'label': '体能训练',
        'fields': ['训练目标', '强度建议', '组数与时间', '休息安排', '进阶标准', '停止条件'],
    },
    'teaching': {
        'label': '教学方法',
        'fields': ['核心概念', '适用场景', '作用机制', '教学流程', '示范与指令', '观察指标', '反馈方式', '常见误区', '常见调整', '复习检查'],
    },
    'development': {
        'label': '儿童发展',
        'fields': ['核心概念', '发展主题', '可能表现', '作用机制', '观察重点', '支持策略', '家庭建议', '常见误区', '专业边界', '复习检查'],
    },
    'family_communication': {
        'label': '家长沟通',
        'fields': ['核心概念', '沟通场景', '家长关切', '解释逻辑', '沟通示例', '可执行建议', '常见误区', '边界提醒', '复习检查'],
    },
}

SECTION_GROUPS = {
    'movement': {'theory': ['核心目标'], 'action': ['准备', '操作步骤', '难度调整'], 'focus': ['教练提示', '常见错误', '安全边界']},
    'sensory': {'theory': ['感觉目标'], 'action': ['输入方式', '调节方式', '课堂支持'], 'focus': ['观察反应', '专业边界']},
    'fitness': {'theory': ['训练目标'], 'action': ['强度建议', '组数与时间', '休息安排', '进阶标准'], 'focus': ['停止条件']},
    'teaching': {'theory': ['适用场景'], 'action': ['教学流程', '示范与指令', '常见调整'], 'focus': ['观察指标', '反馈方式']},
    'development': {'theory': ['发展主题'], 'action': ['支持策略', '家庭建议'], 'focus': ['可能表现', '观察重点', '专业边界']},
    'family_communication': {'theory': ['解释逻辑'], 'action': ['可执行建议'], 'focus': ['沟通场景', '家长关切', '沟通示例', '边界提醒']},
}

PLAIN_FIELD_LABELS = {
    '核心目标': '这节课主要练什么', '准备': '上课前准备什么', '操作步骤': '怎么做',
    '教练提示': '教练提醒', '常见错误': '容易出现的问题', '难度调整': '跟不上时怎么调', '安全边界': '安全提醒',
    '感觉目标': '主要练什么感觉', '输入方式': '怎么给刺激', '观察反应': '重点看什么', '调节方式': '孩子不舒服时怎么调',
    '课堂支持': '课堂上怎么帮孩子', '专业边界': '什么时候需要请专业人员', '训练目标': '这次训练主要练什么',
    '强度建议': '练到什么程度', '组数与时间': '练几组、每组多久', '休息安排': '什么时候休息', '进阶标准': '什么时候增加难度',
    '停止条件': '什么时候停下来', '适用场景': '什么时候适用', '教学流程': '上课怎么进行', '示范与指令': '怎么示范和下指令',
    '观察指标': '重点看什么', '反馈方式': '课后怎么反馈', '常见调整': '需要调整时怎么做', '发展主题': '这张卡主要讲什么',
    '可能表现': '孩子可能有哪些表现', '观察重点': '重点观察什么', '支持策略': '怎么帮助孩子', '家庭建议': '家里可以怎么做',
    '沟通场景': '什么时候这样沟通', '家长关切': '家长可能担心什么', '解释逻辑': '可以怎么解释', '沟通示例': '可以直接怎么说',
    '可执行建议': '接下来可以做什么', '边界提醒': '需要注意什么',
}


def template_for(task_type):
    """Return a copy so callers cannot mutate the shared template registry."""
    if task_type not in TEMPLATES:
        raise ValueError(f'unsupported enrichment task type: {task_type}')
    template = TEMPLATES[task_type]
    return {'task_type': task_type, 'label': template['label'], 'fields': list(template['fields'])}


def empty_draft(task_type, source_content='', source_excerpt=''):
    template = template_for(task_type)
    return {
        'task_type': template['task_type'],
        'template_label': template['label'],
        'source_content': source_content,
        'source_excerpt': source_excerpt,
        'fields': {field: '' for field in template['fields']},
        'field_labels': {field: PLAIN_FIELD_LABELS.get(field, field) for field in template['fields']},
        'section_groups': SECTION_GROUPS.get(task_type, {}),
        'citations': [],
        'needs_human_review': False,
    }


def validate_draft(draft):
    """Return field-level errors without requiring generated text to be present."""
    errors = []
    task_type = draft.get('task_type')
    if task_type not in TEMPLATES:
        return ['task_type']
    expected = set(TEMPLATES[task_type]['fields'])
    fields = draft.get('fields')
    if not isinstance(fields, dict):
        return ['fields']
    for field in expected:
        if field not in fields:
            errors.append(f'fields.{field}')
    citations = draft.get('citations')
    if not isinstance(citations, list):
        errors.append('citations')
    if draft.get('needs_human_review') not in {True, False}:
        errors.append('needs_human_review')
    return sorted(errors)
