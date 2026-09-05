import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtempSync, mkdirSync, cpSync, readFileSync, writeFileSync, existsSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '..', '..');
const generator = join(here, 'generate_sales_training_cards.py');
const source = process.env.SALES_TRAINING_CARD_SOURCE || 'E:\\知识库\\追光小牛\\专业知识库\\销售培训知识卡库';
const committed = join(repo, 'real_sync', 'database', 'import_data');
const artifactNames = ['sales-training-cards.v1.json', 'sales-training-cards.v1.report.json', 'sales-training-cards.v1.sha256'];
const sourceExists = existsSync(source);
const sourceTest = (name, fn) => test(name, {
  skip: sourceExists ? false : `需要真实销售培训知识卡源目录: ${source}`,
}, fn);

function paths(root) { return Object.fromEntries(artifactNames.map(n => [n, join(root, n)])); }
function run(sourceDir, outDir, env = {}) {
  mkdirSync(outDir, { recursive: true }); const p = paths(outDir);
  return spawnSync('python', [generator, '--source', sourceDir, '--output', p[artifactNames[0]], '--report', p[artifactNames[1]], '--checksum', p[artifactNames[2]]], { encoding: 'utf8', env: { ...process.env, ...env } });
}
function packageAt(root) { return JSON.parse(readFileSync(paths(root)[artifactNames[0]], 'utf8')); }
function copySource() { const root=mkdtempSync(join(tmpdir(),'sales-source-')); cpSync(source,root,{recursive:true}); return root; }
function mdFiles(root) { return readdirSync(root,{recursive:true}).filter(x=>String(x).endsWith('.md')&&String(x).includes('SALES-')).map(x=>join(root,String(x))); }

sourceTest('generator runs on real source and satisfies the complete package contract', () => {
  const out=mkdtempSync(join(tmpdir(),'sales-out-')); const result=run(source,out);
  assert.equal(result.status,0,result.stderr); const data=packageAt(out);
  assert.equal(data.schema_version,'sales-training-cards.v1'); assert.match(data.generator_version,/^\d+\.\d+\.\d+$/);
  assert.deepEqual(data.source_range,{first:'SALES-0001',last:'SALES-0075'});
  assert.deepEqual(data.counts,{source_cards:75,modules:3,cards:300,K:75,S:75,D:75,C:75});
  assert.equal(data.modules.length,3); assert.equal(data.cards.length,300);
  assert.deepEqual(data.modules.map(x=>x.total_cards),[100,84,116]);
  assert.ok(data.modules.every(x=>x.role_code==='consultant'&&x.module_code.length<=50&&x.module_name.length<=100));
  assert.deepEqual(data.modules.map(x=>[x.level,x.module_code]),[['beginner','sales-ability-foundation'],['intermediate','sales-ability-advanced'],['advanced','sales-ability-expert']]);
  const codes=new Set(), types={K:0,S:0,D:0,C:0};
  for (const c of data.cards) {
    assert.match(c.card_code,/^sales-\d{4}-[ksdc]$/); assert.ok(!codes.has(c.card_code)); codes.add(c.card_code); types[c.card_type]++;
    const n=Number(c.card_code.slice(6,10)), seq={K:1,S:2,D:3,C:4}[c.card_type]; assert.equal(c.sort_order,n*10+seq);
    assert.equal(c.difficulty,n<=25?'easy':n<=46?'medium':'hard'); assert.ok(c.title&&c.title.length<=200&&c.card_code.length<=50);
    assert.ok(typeof c.content==='string'&&c.content); assert.ok(c.options===null||(Array.isArray(c.options)&&c.options.every(x=>typeof x==='string'&&x)));
    assert.doesNotMatch(c.tips,/[A-Z]:\\|E:\\|E:\//i); assert.match(c.tips,/card_id: SALES-\d{4}/); assert.match(c.tips,/源相对路径:/); assert.match(c.tips,/source_articles:/);
  }
  assert.deepEqual(types,{K:75,S:75,D:75,C:75});
  for (const n of [1,26,47,75]) for (const t of 'KSDC') assert.ok(codes.has(`sales-${String(n).padStart(4,'0')}-${t.toLowerCase()}`));
  const checksum=readFileSync(paths(out)[artifactNames[2]],'ascii'); const m=checksum.match(/^([a-f0-9]{64})  (sales-training-cards\.v1\.json)\n$/);
  assert.ok(m,'sha256 文件格式必须是“64位小写哈希+两个空格+文件名+LF”');
  assert.equal(m[1],createHash('sha256').update(readFileSync(paths(out)[artifactNames[0]])).digest('hex'));
});

sourceTest('same input is byte deterministic and committed artifacts exactly match generation', () => {
  const a=mkdtempSync(join(tmpdir(),'sales-a-')), b=mkdtempSync(join(tmpdir(),'sales-b-'));
  assert.equal(run(source,a).status,0); assert.equal(run(source,b).status,0);
  for(const name of artifactNames) {
    assert.deepEqual(readFileSync(paths(a)[name]),readFileSync(paths(b)[name]),`${name} 重复生成不一致`);
    assert.deepEqual(readFileSync(paths(a)[name]),readFileSync(join(committed,name)),`${name} 与已提交 artifact 不一致`);
  }
  assert.doesNotMatch(readFileSync(paths(a)[artifactNames[0]],'utf8'),/generated_at|生成时间/i);
});

sourceTest('second and third replace failures roll back absent and existing target sets without residue', () => {
  for (const failAt of [2, 3]) {
    const absent=mkdtempSync(join(tmpdir(),`sales-atomic-absent-${failAt}-`));
    let result=run(source,absent,{SALES_GENERATOR_FAIL_REPLACE_AT:String(failAt)});
    assert.notEqual(result.status,0); assert.match(result.stderr,/injected replace failure/);
    for(const name of artifactNames) assert.equal(existsSync(paths(absent)[name]),false,`${name} 不应部分发布`);
    assert.deepEqual(readdirSync(absent),[],`第 ${failAt} 次失败后不应残留 temp/backup`);

    const existing=mkdtempSync(join(tmpdir(),`sales-atomic-existing-${failAt}-`));
    const old=new Map();
    for(const [index,name] of artifactNames.entries()) {
      const content=Buffer.from(`old-${index}-${name}\n`); old.set(name,content); writeFileSync(paths(existing)[name],content);
    }
    result=run(source,existing,{SALES_GENERATOR_FAIL_REPLACE_AT:String(failAt)});
    assert.notEqual(result.status,0); assert.match(result.stderr,/injected replace failure/);
    for(const name of artifactNames) assert.deepEqual(readFileSync(paths(existing)[name]),old.get(name),`${name} 应恢复旧内容`);
    assert.deepEqual(readdirSync(existing).sort(),[...artifactNames].sort(),`第 ${failAt} 次失败后不应残留 temp/backup`);
  }
});

sourceTest('missing section, duplicate id and overlong title fail as a batch without final files', () => {
  const mutations=[
    text=>text.replace(/^## 通关标准\s*$/m,'## 被删除章节'),
    (text,root)=>{ const second=mdFiles(root)[1]; writeFileSync(second,readFileSync(second,'utf8').replace(/card_id: SALES-\d{4}/,'card_id: SALES-0001'),'utf8'); return text; },
    text=>text.replace(/^# .+$/m,'# '+'超'.repeat(205)),
  ];
  for(const mutate of mutations) {
    const src=copySource(), first=mdFiles(src)[0], out=join(mkdtempSync(join(tmpdir(),'sales-bad-')),'final');
    writeFileSync(first,mutate(readFileSync(first,'utf8'),src),'utf8'); const result=run(src,out);
    assert.notEqual(result.status,0); for(const name of artifactNames) assert.equal(existsSync(paths(out)[name]),false,`${name} 不应残留`);
  }
});
