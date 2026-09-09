import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

from knowledge_enrichment_generator import generate_draft
from scan_knowledge_enrichment import scan_record


TASK_TYPES = ['movement', 'sensory', 'fitness', 'teaching', 'development', 'family_communication']
CONTENT_TYPES = {'movement': 'action', 'sensory': 'knowledge_card', 'fitness': 'training_plan', 'teaching': 'teaching_knowledge', 'development': 'knowledge_card', 'family_communication': 'parent_communication'}


class KnowledgeEnrichmentPilotTest(unittest.TestCase):
    def test_hundred_card_mixed_type_pilot(self):
        records = []
        for index in range(100):
            task_type = TASK_TYPES[index % len(TASK_TYPES)]
            content = (
                f'目标：完成第{index}张知识卡训练。准备器材和场地。'
                '步骤：先示范，然后接着练习。安全注意：出现不适时停止。'
                '观察反应并记录表现，家长可以在家庭中按照建议练习。'
                '提示保持控制，避免错误，支持策略用于帮助引导，进阶标准和调整方式按表现变化。'
                '反馈方式包括提醒，专业边界需要转介评估，沟通示例和课堂流程用于教学。'
            )
            records.append({'id': index + 1, 'version_id': index + 1001, 'title': f'试点卡片 {index + 1}', 'content': content, 'summary': '试点摘要', 'content_type': CONTENT_TYPES[task_type], 'domain_code': 'sensory' if task_type == 'sensory' else ('child_development' if task_type == 'development' else ''), 'topic_code': task_type})

        tasks = [scan_record(record) for record in records]
        self.assertEqual(len(tasks), 100)
        self.assertEqual({task['task_type'] for task in tasks}, set(TASK_TYPES))
        for record, task in zip(records, tasks):
            draft = generate_draft(record | {'task_type': task['task_type']})
            self.assertEqual(len(draft['source_content_sha256']), 64)
            self.assertTrue(draft['citations'])
            self.assertFalse(draft['validation_errors'])
            self.assertGreaterEqual(sum(bool(value) for value in draft['fields'].values()), 1)


if __name__ == '__main__':
    unittest.main()
