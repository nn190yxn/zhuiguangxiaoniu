#!/usr/bin/env python3
"""Create deterministic, auditable enrichment batches from a knowledge snapshot."""
import argparse
import hashlib
import json
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

from knowledge_enrichment_generator import generate_draft
from scan_knowledge_enrichment import scan_record


SCHEMA_VERSION = 'knowledge-enrichment-batch.v1'
GENERATOR_VERSION = 'knowledge-enrichment-generator.v1'


def stable_json(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':'))


def snapshot_hash(records):
    payload = stable_json(records).encode('utf-8')
    return hashlib.sha256(payload).hexdigest()


def normalize_record(record, index):
    normalized = dict(record)
    normalized.setdefault('id', index + 1)
    normalized.setdefault('version_id', normalized.get('source_version_id') or 1)
    if not normalized.get('content_sha256') and normalized.get('source_sha256'):
        normalized['content_sha256'] = normalized['source_sha256']
    return normalized


def build_batch(records, batch_id, offset=0, limit=None):
    selected = [normalize_record(record, index) for index, record in enumerate(records)]
    selected = selected[offset:] if limit is None else selected[offset:offset + limit]
    source_snapshot_sha256 = snapshot_hash(selected)
    results = []
    for record in selected:
        # The source snapshot uses item_code/source_card_id instead of database ids.
        # Normalize first so scanning and importing share stable identifiers.
        record.setdefault('id', record.get('knowledge_item_id') or record.get('source_card_id') or index + 1)
        record.setdefault('version_id', record.get('source_version_id') or 1)
        scan = scan_record(record)
        result = {
            **scan,
            'batch_id': batch_id,
            'source_snapshot_sha256': source_snapshot_sha256,
            'generator_version': GENERATOR_VERSION,
            'enrichment_status': 'scanned',
            'review_status': 'pending',
        }
        if not str(record.get('content') or '').strip():
            result.update({'enrichment_status': 'failed', 'failure_reason': 'source_missing'})
        else:
            try:
                draft = generate_draft({**record, 'task_type': scan['task_type']})
                result.update({
                    'enriched_content': draft,
                    'enriched_content_sha256': hashlib.sha256(stable_json(draft).encode('utf-8')).hexdigest(),
                    'enrichment_status': 'needs_review' if draft['needs_human_review'] else 'draft',
                })
            except (ValueError, KeyError, TypeError) as exc:
                result.update({'enrichment_status': 'failed', 'failure_reason': str(exc)})
        results.append(result)
    counts = Counter(item['enrichment_status'] for item in results)
    return {
        'schema_version': SCHEMA_VERSION,
        'batch_id': batch_id,
        'created_at': datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        'source_snapshot_sha256': source_snapshot_sha256,
        'offset': offset,
        'limit': limit,
        'total_count': len(results),
        'counts': dict(sorted(counts.items())),
        'tasks': results,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input', type=Path, help='JSON snapshot containing a records array or a JSON array')
    parser.add_argument('output', type=Path, help='JSON batch output')
    parser.add_argument('--batch-id', required=True)
    parser.add_argument('--offset', type=int, default=0)
    parser.add_argument('--limit', type=int)
    args = parser.parse_args()
    source = json.loads(args.input.read_text(encoding='utf-8'))
    records = source.get('records') if isinstance(source, dict) else source
    if not isinstance(records, list):
        raise ValueError('input must be a JSON array or object with records array')
    if args.offset < 0 or args.limit is not None and args.limit <= 0:
        raise ValueError('offset must be non-negative and limit must be positive')
    batch = build_batch(records, args.batch_id, args.offset, args.limit)
    args.output.write_text(json.dumps(batch, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'batch_id': args.batch_id, 'total_count': batch['total_count'], 'counts': batch['counts']}, ensure_ascii=False))


if __name__ == '__main__':
    main()
