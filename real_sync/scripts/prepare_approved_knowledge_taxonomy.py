"""Prepare the user-approved full knowledge taxonomy release."""
import csv
import hashlib
import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
spec = root / '.monkeycode/specs/2026-09-08-knowledge-fulltext-taxonomy'
with (spec / 'normalized-proposals.csv').open(newline='', encoding='utf-8') as handle:
    rows = list(csv.DictReader(handle))
with (spec / 'pending-normalization.csv').open(newline='', encoding='utf-8') as handle:
    pending_rows = list(csv.DictReader(handle))
assert len(rows) == 1311 and len(pending_rows) == 336
assert len({row['id'] for row in rows + pending_rows}) == 1647
catalog = json.loads((spec / 'taxonomy-catalog.json').read_text())
topics = {}
for topic in catalog['topics']:
    topics[topic['code']] = {
        'label': topic['label'],
        'subcategories': {child['code']: child['label'] for child in topic['children']},
    }

def pending_code(row):
    """Convert the already reviewed descriptive subtopic into the stable catalog code."""
    text = row['subtopic'] + ' ' + row['evidence']
    topic = row['primary_topic']
    if topic == '耐力与体适能':
        if any(word in text for word in ['恢复', '强度', '负荷', '周期', '配比', '训练计划']): return 'endurance_load'
        if any(word in text for word in ['循环', '闯关', '接力']): return 'endurance_circuit'
        if any(word in text for word in ['心肺', '持续跑', '往返跑', '追逐跑', '有氧', '奔跑', '限时']): return 'endurance_aerobic'
        return 'endurance_general'
    if topic == '柔韧与活动度':
        if any(word in text for word in ['热身', '拉伸方法', '运动前', '运动后', '训练后', '全身', '日常']): return 'mobility_methods' if any(word in text for word in ['热身', '方法', '运动前', '运动后']) else 'mobility_fullbody'
        if any(word in text for word in ['踝', '小腿']): return 'mobility_ankle'
        if any(word in text for word in ['髋', '臀', '大腿', '股四头', '腘绳', '腿部']): return 'mobility_hip'
        if any(word in text for word in ['脊柱', '背部', '躯干', '胸腰', '腰方', '腹部']): return 'mobility_spine'
        return 'mobility_neck_shoulder'
    if topic == '感觉统合':
        if any(word in text for word in ['定义', '自查', '观察', '分龄', '启蒙', '早期']): return 'sensory_foundations'
        if any(word in text for word in ['调节', '课堂支持', '课堂情境', '安全感', '敏感', '防御', '适应']): return 'sensory_regulation'
        if any(word in text for word in ['触觉', '温度', '纹理', '材质', '泥土', '沙地', '湿滑', '足底', '轻触', '深层触']): return 'sensory_tactile'
        if any(word in text for word in ['本体', '负重', '压力', '阻力', '用力', '重量']): return 'sensory_proprioception'
        if any(word in text for word in ['视觉', '听觉', '嗅觉', '味觉']): return 'sensory_visual_auditory'
        if any(word in text for word in ['多感觉', '整合', '双侧']): return 'sensory_integration'
        return 'sensory_vestibular'
    if topic == '认知注意与执行功能':
        if any(word in text for word in ['记忆']): return 'cognition_memory'
        if any(word in text for word in ['抑制', '动停', '等待']): return 'cognition_inhibition'
        if any(word in text for word in ['转换', '切换', '灵活']): return 'cognition_flexibility'
        if any(word in text for word in ['规划', '计划', '策略', '问题解决', '推理']): return 'cognition_planning'
        if any(word in text for word in ['空间', '观察', '辨别', '定位', '视觉']): return 'cognition_perception'
        if any(word in text for word in ['语言', '词汇', '读写', '学习', '动机', '表达']): return 'cognition_learning'
        return 'cognition_attention'
    if topic == '儿童生长与发育':
        if any(word in text for word in ['语言', '发音', '沟通', '阅读', '迟语']): return 'development_language'
        if any(word in text for word in ['动作', '运动', '里程碑']): return 'development_motor'
        if any(word in text for word in ['认知', '记忆', '学习']): return 'development_cognition'
        if any(word in text for word in ['观察', '转介', '判断', '边界', '适配']): return 'development_observation'
        return 'development_growth'
    raise ValueError(f'Unsupported pending topic: {topic}')

for row in pending_rows:
    topic_aliases = {'感觉统合': 'sensory'}
    row['topic_code'] = topic_aliases.get(row['primary_topic']) or next(code for code, topic in topics.items() if topic['label'] == row['primary_topic'])
    row['subtopic_code'] = pending_code(row)
    row['topic_label'] = topics[row['topic_code']]['label']
    row['subtopic_label'] = topics[row['topic_code']]['subcategories'][row['subtopic_code']]
    row['normalization_note'] = '用户确认的逐卡提案转换为稳定目录代码。'
    row['source_group'] = 'confirmed-full-set'
    row['normalization_status'] = 'user_confirmed'
rows += pending_rows
used = {row['subtopic_code'] for row in rows}
entries = {}
for row in rows:
    assert row['validation_status'] == 'passed'
    assert row['subtopic_code'] in topics[row['topic_code']]['subcategories']
    entries[row['id']] = {
        key: row[key] for key in ['version_id', 'content_sha256', 'title', 'topic_code', 'subtopic_code',
                                 'evidence', 'reason', 'normalization_note', 'quality_flag']
    }
    entries[row['id']]['source_review_status'] = row['review_status']
release = {
    'schema_version': 'knowledge-reviewed-taxonomy.v1',
    'release_version': 'fulltext-20260908-approved-1647-v3',
    'approval': 'User confirmed review of all 1647 records and requested production deployment in this session.',
    'source_sha256': hashlib.sha256((spec / 'classification-proposals.csv').read_bytes()).hexdigest(),
    'topics': topics,
    'entries': entries,
}
(root / 'database/knowledge_reviewed_taxonomy.v1.json').write_text(
    json.dumps(release, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(json.dumps({'approved_count': len(entries), 'topics': len(topics), 'subtopics': len(used)}))
