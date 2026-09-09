#!/usr/bin/env python3
"""Convert the card-by-card review artifact into the import batch schema."""
import argparse, hashlib, json
from datetime import datetime, timezone
from pathlib import Path

def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':'))

def main():
    p = argparse.ArgumentParser()
    p.add_argument('input', type=Path)
    p.add_argument('output', type=Path)
    p.add_argument('--batch-id', required=True)
    a = p.parse_args()
    source = json.loads(a.input.read_text(encoding='utf-8'))
    tasks = source['tasks']
    for task in tasks:
        task['review_status'] = 'pending'
        task['enrichment_status'] = 'draft' if task.get('enrichment_status') != 'failed' else 'failed'
    snapshot = hashlib.sha256(canonical(tasks).encode()).hexdigest()
    out = {'schema_version': 'knowledge-enrichment-batch.v1', 'batch_id': a.batch_id,
           'created_at': datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
           'source_snapshot_sha256': snapshot, 'offset': 0, 'limit': None,
           'total_count': len(tasks), 'counts': {'draft': len(tasks)}, 'tasks': tasks}
    a.output.write_text(json.dumps(out, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'batch_id': a.batch_id, 'total_count': len(tasks), 'source_snapshot_sha256': snapshot}, ensure_ascii=False))

if __name__ == '__main__': main()
