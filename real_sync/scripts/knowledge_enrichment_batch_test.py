import hashlib
import json
import unittest

from knowledge_enrichment_batch import build_batch, snapshot_hash


class KnowledgeEnrichmentBatchTest(unittest.TestCase):
    def setUp(self):
        self.records = [
            {'id': 10, 'version_id': 3, 'title': '动作', 'content_type': 'action', 'domain_code': 'course_skills', 'content': '目标：练习。步骤：先示范，然后操作。安全：出现不适就停止。'},
            {'id': 11, 'version_id': 4, 'title': '安全', 'content_type': 'safety', 'domain_code': 'safety_first_aid', 'content': '课堂安全提醒。发现异常反应时停止并记录。'},
            {'id': 12, 'version_id': 5, 'title': '空卡', 'content_type': 'action', 'domain_code': 'course_skills', 'content': ''},
        ]

    def test_batch_is_stable_and_bounded(self):
        first = build_batch(self.records, 'pilot-001')
        second = build_batch(self.records, 'pilot-001')
        self.assertEqual(first['source_snapshot_sha256'], second['source_snapshot_sha256'])
        self.assertEqual([item['knowledge_item_id'] for item in first['tasks']], [10, 11, 12])
        self.assertEqual(first['counts']['failed'], 1)

    def test_batch_supports_paging(self):
        batch = build_batch(self.records, 'pilot-002', offset=1, limit=1)
        self.assertEqual(batch['total_count'], 1)
        self.assertEqual(batch['tasks'][0]['knowledge_item_id'], 11)

    def test_hash_matches_canonical_snapshot(self):
        self.assertEqual(snapshot_hash(self.records), hashlib.sha256(
            json.dumps(self.records, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode('utf-8')
        ).hexdigest())


if __name__ == '__main__':
    unittest.main()
