"""Preserve and validate existing taxonomy proposals without classifying content."""

import argparse
import csv
import hashlib
import io
import json
import re
from collections import Counter
from pathlib import Path


MAPPING_FIELDS = ['id', 'topic_code', 'subtopic_code', 'normalization_note']
PROPOSAL_FIELDS = [
    'id', 'version_id', 'content_sha256', 'title', 'primary_topic', 'subtopic',
    'secondary_topics', 'evidence', 'reason', 'review_status', 'quality_flag',
    'validation_status',
]
DEFAULT_SPEC = Path(__file__).resolve().parents[1] / (
    '.monkeycode/specs/2026-09-08-knowledge-fulltext-taxonomy'
)


def read_csv(path, fields):
    reader = csv.DictReader(io.StringIO(path.read_text(encoding='utf-8'), newline=''))
    if reader.fieldnames != fields:
        raise ValueError(f'{path.name}: invalid CSV header')
    rows = list(reader)
    for row in rows:
        if None in row or any(value is None for value in row.values()):
            raise ValueError(f'{path.name}: malformed CSV row')
        if not re.fullmatch(r'[1-9][0-9]*', row['id']):
            raise ValueError(f'{path.name}: invalid ID')
    return rows


def csv_text(rows, fields):
    handle = io.StringIO(newline='')
    writer = csv.DictWriter(handle, fieldnames=fields)
    writer.writeheader()
    writer.writerows(rows)
    return handle.getvalue()


def assemble(spec, source_root=None):
    catalog_path = spec / 'taxonomy-catalog.json'
    proposals_path = spec / 'classification-proposals.csv'
    catalog_hash = hashlib.sha256(catalog_path.read_bytes()).hexdigest()
    proposals_hash = hashlib.sha256(proposals_path.read_bytes()).hexdigest()
    summary_path = spec / 'normalization-summary.json'
    if summary_path.exists():
        previous = json.loads(summary_path.read_text(encoding='utf-8'))
        if (previous.get('catalog_sha256') != catalog_hash
                or previous.get('proposals_sha256') != proposals_hash):
            raise ValueError('Snapshot changed; preserve this result and assemble a separately reviewed snapshot')
    catalog = json.loads(catalog_path.read_text(encoding='utf-8'))
    topics = {}
    children = {}
    for topic in catalog['topics']:
        if topic['code'] in topics:
            raise ValueError('Duplicate topic code')
        topics[topic['code']] = topic['label']
        for child in topic['children']:
            if child['code'] in children:
                raise ValueError('Duplicate subtopic code')
            children[child['code']] = (topic['code'], child['label'])

    proposals = read_csv(proposals_path, PROPOSAL_FIELDS)
    originals = {}
    for row in proposals:
        if row['id'] in originals:
            raise ValueError(f'Duplicate proposal ID: {row["id"]}')
        if (not re.fullmatch(r'[1-9][0-9]*', row['version_id'])
                or not re.fullmatch(r'[0-9a-f]{64}', row['content_sha256'])):
            raise ValueError(f'Invalid version or snapshot hash: {row["id"]}')
        if row['review_status'] not in ('proposed', 'needs_review'):
            raise ValueError(f'Invalid review status: {row["id"]}')
        if row['validation_status'] != 'passed':
            raise ValueError(f'Proposal validation unresolved: {row["id"]}')
        originals[row['id']] = row
    if not originals:
        raise ValueError('Proposal snapshot is empty')

    archive = spec / 'normalization-inputs'
    inputs = (sorted(source_root.glob('group-*/normalized.csv')) if source_root
              else sorted(archive.glob('group-*.csv')))
    if not inputs:
        raise ValueError('No existing normalization outputs found')
    normalized = {}
    copies = {}
    provenance = []
    for path in inputs:
        group = path.parent.name if source_root else path.stem
        if not re.fullmatch(r'group-[0-9]{2}', group):
            raise ValueError('Invalid source group name')
        raw = path.read_bytes()
        if source_root and (archive / f'{group}.csv').exists():
            if (archive / f'{group}.csv').read_bytes() != raw:
                raise ValueError(f'Archived input differs: {group}')
        rows = read_csv(path, MAPPING_FIELDS)
        for row in rows:
            identifier = row['id']
            if identifier not in originals or identifier in normalized:
                raise ValueError(f'Unknown or duplicate mapping ID: {identifier}')
            child = children.get(row['subtopic_code'])
            if row['topic_code'] not in topics or not child or child[0] != row['topic_code']:
                raise ValueError(f'Invalid taxonomy parent-child pair: {identifier}')
            original = originals[identifier]
            changed = topics[row['topic_code']] != original['primary_topic']
            if changed and not row['normalization_note'].strip():
                raise ValueError(f'Missing topic-change explanation: {identifier}')
            normalized[identifier] = {
                **original,
                'topic_code': row['topic_code'],
                'topic_label': topics[row['topic_code']],
                'subtopic_code': row['subtopic_code'],
                'subtopic_label': child[1],
                'normalization_note': row['normalization_note'],
                'source_group': group,
                'normalization_status': 'ai_proposal',
            }
        copies[group] = raw
        provenance.append({'file': f'normalization-inputs/{group}.csv',
                           'sha256': hashlib.sha256(raw).hexdigest(), 'count': len(rows)})

    completed = sorted(normalized.values(), key=lambda row: int(row['id']))
    pending = sorted((row for identifier, row in originals.items() if identifier not in normalized),
                     key=lambda row: int(row['id']))
    summary = {
        'catalog_version': catalog['version'],
        'source_count': len(originals),
        'normalized_count': len(completed),
        'remaining_count': len(pending),
        'publication_status': 'not_published',
        'assembly_status': 'partial' if pending else 'complete_proposal',
        'validation_scope': 'Structure and references against local proposals; no new semantic review or production revalidation.',
        'normalized_topic_counts': dict(Counter(row['topic_label'] for row in completed)),
        'used_subtopic_count': len({row['subtopic_code'] for row in completed}),
        'primary_topic_change_count': sum(row['primary_topic'] != row['topic_label'] for row in completed),
        'review_status_counts': dict(Counter(row['review_status'] for row in completed)),
        'catalog_sha256': catalog_hash,
        'proposals_sha256': proposals_hash,
        'inputs': provenance,
    }
    # Validate every input before writing any deliverable.
    if source_root:
        archive.mkdir(exist_ok=True)
        for group, raw in copies.items():
            (archive / f'{group}.csv').write_bytes(raw)
    fields = PROPOSAL_FIELDS + [
        'topic_code', 'topic_label', 'subtopic_code', 'subtopic_label',
        'normalization_note', 'source_group', 'normalization_status',
    ]
    (spec / 'normalized-proposals.csv').write_text(csv_text(completed, fields), encoding='utf-8', newline='')
    (spec / 'pending-normalization.csv').write_text(csv_text(pending, PROPOSAL_FIELDS), encoding='utf-8', newline='')
    (spec / 'normalization-summary.json').write_text(
        json.dumps(summary, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    review_directory = spec / 'review'
    review_directory.mkdir(exist_ok=True)
    review_rows = sorted(completed + [
        {**row, 'normalization_status': 'pending'} for row in pending
    ], key=lambda row: int(row['id']))
    (review_directory / 'data.json').write_text(json.dumps({
        'summary': summary, 'catalog': catalog, 'records': review_rows,
    }, ensure_ascii=False) + '\n', encoding='utf-8')
    return summary


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--spec', type=Path, default=DEFAULT_SPEC)
    parser.add_argument('--source-root', type=Path, help='Import existing group outputs once; default uses archived inputs')
    args = parser.parse_args()
    try:
        result = assemble(args.spec, args.source_root)
    except (ValueError, KeyError, OSError) as error:
        parser.exit(1, f'{error}\n')
    print(json.dumps({key: result[key] for key in (
        'source_count', 'normalized_count', 'remaining_count', 'assembly_status', 'publication_status'
    )}, ensure_ascii=False))
