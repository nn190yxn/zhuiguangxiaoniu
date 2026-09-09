import unittest

from knowledge_enrichment_templates import TEMPLATES, empty_draft, template_for, validate_draft


class KnowledgeEnrichmentTemplateTest(unittest.TestCase):
    def test_all_six_templates_have_required_fields(self):
        self.assertEqual(set(TEMPLATES), {'movement', 'sensory', 'fitness', 'teaching', 'development', 'family_communication'})
        for task_type in TEMPLATES:
            template = template_for(task_type)
            self.assertGreaterEqual(len(template['fields']), 6)
            self.assertEqual(len(template['fields']), len(set(template['fields'])))

    def test_empty_draft_has_source_and_citation_slots(self):
        draft = empty_draft('fitness', '原文', '原文片段')
        self.assertEqual(draft['source_content'], '原文')
        self.assertEqual(draft['source_excerpt'], '原文片段')
        self.assertEqual(set(draft['fields']), set(TEMPLATES['fitness']['fields']))
        self.assertEqual(validate_draft(draft), [])

    def test_validation_reports_missing_field_and_invalid_citations(self):
        draft = empty_draft('movement')
        del draft['fields']['安全边界']
        draft['citations'] = {}
        errors = validate_draft(draft)
        self.assertIn('fields.安全边界', errors)
        self.assertIn('citations', errors)

    def test_unknown_template_is_rejected(self):
        with self.assertRaises(ValueError):
            template_for('unknown')


if __name__ == '__main__':
    unittest.main()
