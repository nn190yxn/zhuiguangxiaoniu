import csv
import json
import tempfile
import unittest
from pathlib import Path

from assemble_knowledge_normalization import MAPPING_FIELDS, PROPOSAL_FIELDS, assemble, csv_text


class NormalizationAssemblyTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.spec = Path(self.directory.name)
        self.archive = self.spec / 'normalization-inputs'
        self.archive.mkdir()
        catalog = {'version': 'test-draft', 'topics': [
            {'code': 'movement', 'label': 'Movement', 'children': [
                {'code': 'balance', 'label': 'Balance'}]},
            {'code': 'strength', 'label': 'Strength', 'children': [
                {'code': 'core', 'label': 'Core'}]},
        ]}
        (self.spec / 'taxonomy-catalog.json').write_text(json.dumps(catalog))
        self.proposals = []
        for identifier in ('1', '2'):
            self.proposals.append(dict(zip(PROPOSAL_FIELDS, [
                identifier, identifier, 'a' * 64, 'Example card', 'Movement',
                'Detailed description', '', 'Original quotation', 'Original reason',
                'needs_review', 'professional_review', 'passed',
            ])))
        self.write_proposals()
        self.mapping = {'id': '1', 'topic_code': 'strength', 'subtopic_code': 'core',
                        'normalization_note': 'Existing review rationale'}
        self.write_mapping()

    def write_proposals(self):
        (self.spec / 'classification-proposals.csv').write_text(csv_text(self.proposals, PROPOSAL_FIELDS))

    def write_mapping(self):
        (self.archive / 'group-01.csv').write_text(csv_text([self.mapping], MAPPING_FIELDS))

    def test_partial_snapshot_preserves_evidence_and_review_flags(self):
        summary = assemble(self.spec)
        self.assertEqual((summary['normalized_count'], summary['remaining_count']), (1, 1))
        self.assertEqual(summary['assembly_status'], 'partial')
        self.assertEqual(summary['publication_status'], 'not_published')
        with (self.spec / 'normalized-proposals.csv').open() as handle:
            row = next(csv.DictReader(handle))
        self.assertEqual({field: row[field] for field in PROPOSAL_FIELDS}, self.proposals[0])
        self.assertEqual(row['normalization_status'], 'ai_proposal')
        view = json.loads((self.spec / 'review/data.json').read_text())
        self.assertEqual([item['id'] for item in view['records']], ['1', '2'])
        self.assertEqual(view['records'][0]['normalization_status'], 'ai_proposal')
        self.assertEqual(view['records'][1]['normalization_status'], 'pending')
        self.assertNotIn('topic_code', view['records'][1])
        self.assertEqual(view['records'][1]['quality_flag'], 'professional_review')
        with (self.spec / 'pending-normalization.csv').open() as handle:
            self.assertEqual(list(csv.DictReader(handle)), [self.proposals[1]])
        before = (self.spec / 'normalized-proposals.csv').read_bytes()
        assemble(self.spec)
        self.assertEqual((self.spec / 'normalized-proposals.csv').read_bytes(), before)

    def test_duplicate_mapping_leaves_existing_output_untouched(self):
        assemble(self.spec)
        before = (self.spec / 'normalized-proposals.csv').read_bytes()
        (self.archive / 'group-02.csv').write_text(csv_text([self.mapping], MAPPING_FIELDS))
        with self.assertRaisesRegex(ValueError, 'duplicate mapping'):
            assemble(self.spec)
        self.assertEqual((self.spec / 'normalized-proposals.csv').read_bytes(), before)

    def test_new_source_version_cannot_reuse_old_mapping_silently(self):
        assemble(self.spec)
        before = (self.spec / 'normalized-proposals.csv').read_bytes()
        self.proposals[0]['version_id'] = '99'
        self.proposals[0]['content_sha256'] = 'b' * 64
        self.write_proposals()
        with self.assertRaisesRegex(ValueError, 'Snapshot changed'):
            assemble(self.spec)
        self.assertEqual((self.spec / 'normalized-proposals.csv').read_bytes(), before)

    def test_invalid_parent_pair_is_rejected(self):
        self.mapping['subtopic_code'] = 'balance'
        self.write_mapping()
        with self.assertRaisesRegex(ValueError, 'parent-child'):
            assemble(self.spec)
        self.assertFalse((self.spec / 'normalized-proposals.csv').exists())

    def test_unknown_id_and_unexplained_change_are_rejected(self):
        self.mapping['id'] = '3'
        self.write_mapping()
        with self.assertRaisesRegex(ValueError, 'Unknown'):
            assemble(self.spec)
        self.mapping['id'] = '1'
        self.mapping['normalization_note'] = ''
        self.write_mapping()
        with self.assertRaisesRegex(ValueError, 'explanation'):
            assemble(self.spec)

    def test_unvalidated_proposal_and_invalid_hash_are_rejected(self):
        self.proposals[0]['validation_status'] = 'needs_correction'
        self.write_proposals()
        with self.assertRaisesRegex(ValueError, 'unresolved'):
            assemble(self.spec)
        self.proposals[0]['validation_status'] = 'passed'
        self.proposals[0]['content_sha256'] = 'incomplete'
        self.write_proposals()
        with self.assertRaisesRegex(ValueError, 'snapshot hash'):
            assemble(self.spec)


if __name__ == '__main__':
    unittest.main()
