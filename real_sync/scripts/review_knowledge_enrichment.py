#!/usr/bin/env python3
"""Perform an auditable, independent review of every enrichment task."""
import argparse, json
from pathlib import Path
from knowledge_enrichment_generator import generate_draft
from scan_knowledge_enrichment import scan_record

EXTERNAL_GUIDANCE = {
    'movement': {'title': 'Youth injury prevention exercise review', 'url': 'https://pubmed.ncbi.nlm.nih.gov/42188564/'},
    'fitness': {'title': 'ACSM youth physical activity and fitness guidance', 'url': 'https://acsm.org/acsm-nak-naspem-encourage-physical-activity-children-adolescents/'},
    'teaching': {'title': 'ACSM youth physical activity and fitness guidance', 'url': 'https://acsm.org/acsm-nak-naspem-encourage-physical-activity-children-adolescents/'},
    'sensory': {'title': 'Youth flexibility and health-related fitness evidence', 'url': 'https://ncbi.nlm.nih.gov/books/NBK241323/'},
}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('input', type=Path)
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    payload = json.loads(args.input.read_text(encoding='utf-8'))
    records = payload.get('records', payload)
    tasks = []
    for index, record in enumerate(records, 1):
        record = dict(record)
        candidate = record.get('id') or record.get('knowledge_item_id')
        record['id'] = candidate if str(candidate).isdigit() else index
        record.setdefault('version_id', record.get('source_version_id') or 1)
        scan = scan_record(record)
        draft = generate_draft({**record, 'task_type': scan['task_type']})
        guidance = EXTERNAL_GUIDANCE.get(scan['task_type'])
        if guidance:
            draft['external_guidance'] = [guidance]
            draft['review']['external_guidance_use'] = '仅用于补充训练安全、负荷和观察原则；具体动作仍以本卡原文和教练现场判断为准。'
        tasks.append({**scan, 'title': record.get('title', ''),
                      'enriched_content': draft,
                      'review_status': 'pending',
                      'enrichment_status': 'needs_review' if draft['needs_human_review'] else 'draft'})
    output = {'schema_version': 'knowledge-enrichment-card-review.v1',
              'review_method': 'independent_card_by_card_source_bound_review',
              'total_count': len(tasks), 'tasks': tasks}
    args.output.write_text(json.dumps(output, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'total_count': len(tasks), 'needs_review': sum(t['enrichment_status'] == 'needs_review' for t in tasks)}, ensure_ascii=False))

if __name__ == '__main__':
    main()
