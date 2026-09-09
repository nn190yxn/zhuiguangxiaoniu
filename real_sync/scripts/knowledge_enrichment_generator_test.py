import hashlib
import unittest

from knowledge_enrichment_generator import generate_draft


class KnowledgeEnrichmentGeneratorTest(unittest.TestCase):
    def test_generated_fields_keep_source_evidence(self):
        content = '目标：提升下肢力量。准备软垫。步骤：先深蹲，再起身。注意安全，出现疼痛时停止。'
        draft = generate_draft({'task_type': 'movement', 'content': content})
        self.assertEqual(draft['source_content_sha256'], hashlib.sha256(content.encode()).hexdigest())
        self.assertEqual(draft['fields']['核心目标'], '目标：提升下肢力量。')
        self.assertEqual(draft['fields']['安全边界'], '注意安全，出现疼痛时停止。')
        self.assertTrue(draft['citations'])
        self.assertEqual(draft['validation_errors'], [])

    def test_missing_evidence_requires_review(self):
        draft = generate_draft({'task_type': 'fitness', 'content': '提升体能。'})
        self.assertTrue(draft['needs_human_review'])
        self.assertEqual(draft['fields']['组数与时间'], '')

    def test_risk_content_is_marked(self):
        draft = generate_draft({'task_type': 'sensory', 'content': '出现疼痛或不适时停止，必要时转介专业评估。'})
        self.assertEqual(draft['risk_flags'], ['professional_review'])
        self.assertTrue(draft['needs_human_review'])

    def test_unknown_task_type_is_rejected(self):
        with self.assertRaises(ValueError):
            generate_draft({'task_type': 'unknown', 'content': '原文'})


if __name__ == '__main__':
    unittest.main()
