#!/usr/bin/env python3
"""Scan knowledge card versions for deterministic enrichment tasks."""
import argparse
import hashlib
import json
from pathlib import Path


MIN_CONTENT_LENGTH = 240
MIN_PARAGRAPHS = 2


def text_value(record, field):
    value = record.get(field, '')
    return value if isinstance(value, str) else str(value or '')


def task_type(record):
    content_type = text_value(record, 'content_type').lower()
    domain_code = text_value(record, 'domain_code').lower()
    topic_code = text_value(record, 'topic_code').lower()
    if 'sensory' in domain_code or 'sensory' in topic_code:
        return 'sensory'
    if content_type in {'action', 'game'} or domain_code == 'course_skills':
        return 'movement'
    if content_type in {'training_plan', 'assessment'} or domain_code in {'assessment', 'physical_qualities'}:
        return 'fitness'
    if content_type in {'teaching_knowledge', 'teaching_organization'} or domain_code in {'ace_teaching', 'teaching_practice'}:
        return 'teaching'
    if content_type == 'coach_growth':
        return 'teaching'
    if domain_code == 'child_development' or topic_code == 'development':
        return 'development'
    if content_type in {'script', 'parent_communication'}:
        return 'family_communication'
    if content_type == 'safety' or domain_code == 'safety_first_aid':
        return 'teaching'
    return 'general'


def scan_record(record):
    content = text_value(record, 'content').strip()
    summary = text_value(record, 'summary').strip()
    content_type = text_value(record, 'content_type').lower()
    paragraphs = [part.strip() for part in content.replace('\r\n', '\n').split('\n') if part.strip()]
    missing = []
    if not content:
        missing += ['source_content']
    elif len(content) < MIN_CONTENT_LENGTH:
        missing += ['content_detail']
    if len(paragraphs) < MIN_PARAGRAPHS:
        missing += ['paragraph_structure']
    if not summary:
        missing += ['summary']
    if content_type in {'action', 'game', 'training_plan'} and not any(word in content for word in ['步骤', '做法', '方法', '准备', '动作']):
        missing += ['steps']
    if content and not any(word in content for word in ['安全', '注意', '避免', '停止', '风险']):
        missing += ['safety_boundary']

    risk_flags = []
    if any(word in content for word in ['疼痛', '受伤', '损伤', '康复', '治疗', '诊断']):
        risk_flags.append('professional_review')
    if any(word in content for word in ['极限', '高强度', '快速负重', '颈椎', '倒立']):
        risk_flags.append('physical_safety')
    if any(word in content for word in ['发育迟缓', '自闭', '多动', '障碍', '疾病']):
        risk_flags.append('development_boundary')

    source_hash = (
        text_value(record, 'content_sha256')
        or text_value(record, 'source_sha256')
        or hashlib.sha256(content.encode('utf-8')).hexdigest()
    )
    return {
        'knowledge_item_id': int(record.get('id') or record.get('knowledge_item_id')),
        'source_version_id': int(record.get('version_id') or record.get('source_version_id') or 1),
        'source_content_sha256': source_hash,
        'title': text_value(record, 'title'),
        'task_type': task_type(record),
        'missing_fields': sorted(set(missing)),
        'risk_flags': sorted(set(risk_flags)),
        'priority': 'source_missing' if 'source_content' in missing else ('high' if risk_flags or len(missing) >= 3 else 'normal'),
        'enrichment_required': bool(missing),
    }


def scan(records):
    tasks = [scan_record(record) for record in records]
    return sorted(tasks, key=lambda item: (item['knowledge_item_id'], item['source_version_id']))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input', type=Path, help='JSON array of knowledge card versions')
    parser.add_argument('output', type=Path, help='JSON scan output')
    args = parser.parse_args()
    records = json.loads(args.input.read_text(encoding='utf-8'))
    if not isinstance(records, list):
        raise ValueError('input must be a JSON array')
    tasks = scan(records)
    args.output.write_text(json.dumps({'schema_version': 'knowledge-enrichment-scan.v1', 'tasks': tasks}, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'records': len(records), 'tasks': sum(item['enrichment_required'] for item in tasks)}))


if __name__ == '__main__':
    main()
