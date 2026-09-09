import hashlib
import unittest

from scan_knowledge_enrichment import scan, scan_record


class KnowledgeEnrichmentScanTest(unittest.TestCase):
    def test_classifies_and_reports_missing_fields(self):
        record = {
            'id': 7,
            'version_id': 11,
            'title': '基础动作',
            'summary': '',
            'content': '准备软垫。步骤：先走再跑。注意安全，出现疼痛时停止。',
            'content_type': 'action',
            'domain_code': 'course_skills',
        }
        result = scan_record(record)
        self.assertEqual(result['task_type'], 'movement')
        self.assertIn('summary', result['missing_fields'])
        self.assertNotIn('steps', result['missing_fields'])
        self.assertEqual(result['source_content_sha256'], hashlib.sha256(record['content'].encode()).hexdigest())

    def test_empty_source_gets_highest_priority(self):
        result = scan_record({'id': 8, 'version_id': 12, 'content': '', 'content_type': 'action'})
        self.assertEqual(result['priority'], 'source_missing')
        self.assertIn('source_content', result['missing_fields'])
        self.assertTrue(result['enrichment_required'])

    def test_scan_is_stable_and_sorted(self):
        records = [
            {'id': 2, 'version_id': 4, 'content': '完整内容', 'content_type': 'teaching_knowledge', 'domain_code': 'ace_teaching'},
            {'id': 1, 'version_id': 3, 'content': '完整内容', 'content_type': 'game', 'domain_code': 'course_skills'},
        ]
        first = scan(records)
        second = scan(list(reversed(records)))
        self.assertEqual(first, second)
        self.assertEqual([item['knowledge_item_id'] for item in first], [1, 2])


if __name__ == '__main__':
    unittest.main()
