import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const taxonomyPath = path.join(root, 'database', 'knowledge_taxonomy_mapping.v1.json');
const packagePath = path.join(root, 'database', 'import_data', 'knowledge-cards-phase2.isolated-package.json');
const builderPath = path.join(root, 'scripts', 'build_knowledge_card_classification_report.py');
const taxonomyClassPath = path.join(root, 'api', 'knowledge', 'KnowledgeTaxonomy.php');

function readJson(filePath) {
  return JSON.parse(readFileSync(filePath, 'utf8'));
}

function activeTaxonomy() {
  const source = readJson(taxonomyPath);
  return source.versions.find((version) => version.mapping_version === source.active_mapping_version);
}

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (state + 0x6D2B79F5) >>> 0;
    let value = state;
    value = Math.imul(value ^ (value >>> 15), value | 1);
    value ^= value + Math.imul(value ^ (value >>> 7), value | 61);
    return ((value ^ (value >>> 14)) >>> 0) / 4294967296;
  };
}

function shuffled(values, seed) {
  const result = [...values];
  const random = seededRandom(seed);
  for (let index = result.length - 1; index > 0; index -= 1) {
    const target = Math.floor(random() * (index + 1));
    [result[index], result[target]] = [result[target], result[index]];
  }
  return result;
}

function semanticReport(report) {
  const normalized = structuredClone(report);
  delete normalized.report_sha256;
  delete normalized.inputs.package_file_sha256;
  return normalized;
}

test(`${validatesCriteria(['6.3', 'Property 6'])} 固定种子生成的 domain 变体始终映射到同一 taxonomy 目标`, () => {
  const taxonomy = activeTaxonomy();
  const domainCodes = Object.keys(taxonomy.domain_mappings);
  const samples = [];

  for (let seed = 0; seed < 256; seed += 1) {
    const random = seededRandom(0x10050000 + seed);
    const domainCode = domainCodes[Math.floor(random() * domainCodes.length)];
    const varied = [...domainCode].map((character) => (
      random() < 0.5 ? character.toUpperCase() : character
    )).join('');
    samples.push({ domainCode, input: `${' '.repeat(seed % 3)}${varied}${' '.repeat((seed + 1) % 3)}` });
  }

  const php = [
    `require ${JSON.stringify(taxonomyClassPath)};`,
    '$inputs = json_decode(stream_get_contents(STDIN), true);',
    '$results = [];',
    'foreach ($inputs as $input) {',
    '    $mapping = KnowledgeTaxonomy::mapDomain((string)$input);',
    '    $results[] = $mapping === null ? null : [$mapping["primary_category"], $mapping["subcategory_code"]];',
    '}',
    'echo json_encode($results, JSON_UNESCAPED_UNICODE);',
  ].join('\n');
  const result = spawnSync('php', ['-r', php], {
    cwd: root,
    encoding: 'utf8',
    input: JSON.stringify(samples.map(({ input }) => input)),
  });

  assert.equal(result.status, 0, result.stderr);
  const actual = JSON.parse(result.stdout);
  for (const [index, sample] of samples.entries()) {
    const expected = taxonomy.domain_mappings[sample.domainCode];
    assert.deepEqual(actual[index], [expected.primary_category, expected.subcategory_code], `seed ${index}`);
  }
});

test(`${validatesCriteria(['6.3', '6.4', '6.5'])} 输入顺序扰动保持分类审核语义和稳定排序`, () => {
  const knowledgePackage = readJson(packagePath);
  const records = knowledgePackage.records.slice(0, 64);
  const directory = mkdtempSync(path.join(os.tmpdir(), 'knowledge-taxonomy-order-property-'));
  const fixturePackagePath = path.join(directory, 'package.json');
  const outputPath = path.join(directory, 'report.json');
  let baseline = null;

  for (let seed = 0; seed < 24; seed += 1) {
    const fixture = { ...knowledgePackage, record_count: records.length, records: shuffled(records, 0x10060000 + seed) };
    writeFileSync(fixturePackagePath, JSON.stringify(fixture), 'utf8');
    const result = spawnSync('python3', [
      builderPath,
      '--package', fixturePackagePath,
      '--taxonomy', taxonomyPath,
      '--output', outputPath,
      '--expected-record-count', String(records.length),
    ], { cwd: root, encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr);

    const report = readJson(outputPath);
    const itemCodes = report.review_items.map(({ item_code: itemCode }) => itemCode);
    assert.deepEqual(itemCodes, [...itemCodes].sort(), `seed ${seed}`);
    baseline ??= semanticReport(report);
    assert.deepEqual(semanticReport(report), baseline, `seed ${seed}`);
  }
});

test(`${validatesCriteria(['6.3', '6.4', '6.5'])} 正式 1417 张卡逐条命中激活映射和有效分类目标`, () => {
  const taxonomy = activeTaxonomy();
  const knowledgePackage = readJson(packagePath);
  const categories = taxonomy.primary_categories;

  assert.equal(knowledgePackage.records.length, 1417);
  for (const record of knowledgePackage.records) {
    const mapping = taxonomy.domain_mappings[record.domain_code];
    assert.ok(mapping, `${record.item_code}: ${record.domain_code}`);
    assert.equal(mapping.status, 'active', record.item_code);
    assert.equal(record.domain_mapping_status, 'mapped', record.item_code);
    assert.equal(
      typeof categories[mapping.primary_category]?.subcategories?.[mapping.subcategory_code],
      'string',
      record.item_code,
    );
  }
});
