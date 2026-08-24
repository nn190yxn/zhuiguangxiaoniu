#!/usr/bin/env python3
"""Deterministically convert the 75 sales Markdown source cards into import JSON."""
from __future__ import annotations
import argparse, hashlib, json, os, re, shutil, sys, tempfile
from collections import Counter
from pathlib import Path

VERSION = "1.0.0"
SECTIONS = ("核心要点", "标准话术", "演练场景", "通关标准")
TYPE_MAP = (("K", "知识", "核心要点"), ("S", "话术", "标准话术"),
            ("D", "演练", "演练场景"), ("C", "通关", "通关标准"))
RANGES = (
    (1, 25, "sales-ability-foundation", "销售能力·初阶", "beginner", "easy"),
    (26, 46, "sales-ability-advanced", "销售能力·中阶", "intermediate", "medium"),
    (47, 75, "sales-ability-expert", "销售能力·高阶", "advanced", "hard"),
)

class GenerationError(ValueError): pass

def scalar(value: str):
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'": return value[1:-1]
    if value.startswith("[") and value.endswith(")"): raise GenerationError("非法 frontmatter 列表")
    if value.startswith("[") and value.endswith("]"):
        return [scalar(x) for x in value[1:-1].split(",") if x.strip()]
    return value

def parse_frontmatter(text: str, path: Path):
    lines = text.lstrip("\ufeff").splitlines()
    if not lines or lines[0].strip() != "---": raise GenerationError(f"{path.name}: 缺少 frontmatter")
    try: end = next(i for i in range(1, len(lines)) if lines[i].strip() == "---")
    except StopIteration: raise GenerationError(f"{path.name}: frontmatter 未闭合")
    fm, current, current_item = {}, None, None
    for raw in lines[1:end]:
        if not raw.strip() or raw.lstrip().startswith("#"): continue
        m = re.match(r"^([A-Za-z_][\w-]*):(?:\s*(.*))?$", raw)
        if m:
            current, value = m.group(1), (m.group(2) or "")
            fm[current] = scalar(value) if value else []
            current_item = None; continue
        m = re.match(r'^\s+-\s+([A-Za-z_][\w-]*):\s*(.*)$', raw)
        if m and isinstance(fm.get(current), list):
            current_item = {m.group(1): scalar(m.group(2))}; fm[current].append(current_item); continue
        m = re.match(r'^\s+([A-Za-z_][\w-]*):\s*(.*)$', raw)
        if m and current_item is not None:
            current_item[m.group(1)] = scalar(m.group(2)); continue
        raise GenerationError(f"{path.name}: 不支持的 frontmatter 行: {raw}")
    return fm, "\n".join(lines[end + 1:])

def parse_body(body: str, path: Path):
    titles = re.findall(r"^#\s+(.+?)\s*$", body, re.M)
    if len(titles) != 1 or not titles[0].strip(): raise GenerationError(f"{path.name}: 一级标题必须唯一且非空")
    matches = list(re.finditer(r"^##\s+(.+?)\s*$", body, re.M))
    sections = {}
    for i, m in enumerate(matches):
        name = m.group(1).strip(); content = body[m.end():matches[i+1].start() if i+1 < len(matches) else len(body)].strip()
        if name in sections: raise GenerationError(f"{path.name}: 重复章节 {name}")
        sections[name] = content
    for name in SECTIONS:
        if not sections.get(name): raise GenerationError(f"{path.name}: 缺少或为空章节 {name}")
    return titles[0].strip(), sections, [m.group(1).strip() for m in matches]

def checklist(text: str):
    # Only explicitly labelled pass/check items are reliable answer options; scoring rows are not choices.
    lines = text.splitlines(); active = False; result = []
    for line in lines:
        if re.match(r"^\*{0,2}(通关项|检查项|通关检查项)", line.strip()): active = True; continue
        if active and re.match(r"^\*{1,2}[^*].*\*{1,2}:?\s*$", line.strip()): break
        if active:
            m = re.match(r"^\s*(?:[-*]|\d+[.)]|[□☐✓])\s+(.+?)\s*$", line)
            if m:
                item = re.sub(r"\s*\([^)]*分\)\s*$", "", m.group(1)).strip()
                if item and item not in result: result.append(item)
    return result or None

def module_for(number):
    return next(r for r in RANGES if r[0] <= number <= r[1])

def validate_lengths(modules, cards):
    levels={"beginner","intermediate","advanced"}; difficulties={"easy","medium","hard"}
    for m in modules:
        if len(m["module_code"]) > 50 or len(m["module_name"]) > 100 or m["level"] not in levels: raise GenerationError("模块字段超长或枚举非法")
    for c in cards:
        if len(c["card_code"]) > 50 or len(c["title"]) > 200 or c["difficulty"] not in difficulties or c["card_type"] not in "KSDC": raise GenerationError("卡片字段超长或枚举非法")
        if c["options"] is not None and (not isinstance(c["options"], list) or not all(isinstance(x,str) and x for x in c["options"])): raise GenerationError("非法选项")

def build(source: Path):
    files = sorted(source.rglob("SALES-*.md")); parsed=[]; ids=[]; heading_variants=Counter(); fm_variants=Counter()
    for path in files:
        fm, body = parse_frontmatter(path.read_text(encoding="utf-8"), path)
        title, sections, headings = parse_body(body, path)
        cid = fm.get("card_id", "")
        if not re.fullmatch(r"SALES-\d{4}", cid): raise GenerationError(f"{path.name}: card_id 非法")
        ids.append(cid); fm_variants[",".join(fm.keys())] += 1
        for h in headings: heading_variants[h] += 1
        parsed.append((int(cid[-4:]), cid, path, fm, title, sections))
    expected=[f"SALES-{i:04d}" for i in range(1,76)]
    if len(ids)!=75 or sorted(ids)!=expected or len(set(ids))!=75:
        missing=sorted(set(expected)-set(ids)); duplicates=sorted(x for x,n in Counter(ids).items() if n>1)
        raise GenerationError(f"card_id 必须精确、唯一、连续覆盖 SALES-0001~SALES-0075；缺失={missing}，重复={duplicates}，实际样例={ids[:3]!r}")
    modules=[]
    for lo,hi,code,name,level,_ in RANGES:
        modules.append({"module_code":code,"module_name":name,"description":f"销售培训知识卡 {lo:04d}-{hi:04d}","level":level,"role_code":"consultant","category":"销售能力","required_score":60,"total_cards":0,"status":1})
    cards=[]
    for number,cid,path,fm,title,sections in sorted(parsed):
        lo,hi,module_code,_,_,difficulty=module_for(number)
        rel=path.relative_to(source).as_posix()
        sources=fm.get("source_articles", [])
        source_lines=[]
        for item in sources if isinstance(sources,list) else []:
            if isinstance(item,dict): source_lines.append(" / ".join(str(item.get(k,"")) for k in ("file","location") if item.get(k)))
        tips_parts=[f"card_id: {cid}",f"源相对路径: {rel}","source_articles:"]+[f"- {x}" for x in source_lines]
        if sections.get("来源说明"): tips_parts += ["来源说明:", sections["来源说明"]]
        tips="\n".join(tips_parts)
        for seq,(kind,suffix,section) in enumerate(TYPE_MAP,1):
            content = sections[section] if kind != "S" else f"适用主题：{title}"
            card={"module_code":module_code,"card_code":f"sales-{number:04d}-{kind.lower()}","card_type":kind,"title":f"{title}｜{suffix}","content":content,"options":checklist(sections[section]) if kind=="C" else None,"standard_answer":sections[section] if kind=="S" else None,"tips":tips,"difficulty":difficulty,"score":100,"sort_order":number*10+seq,"status":1}
            cards.append(card)
    counts=Counter(c["module_code"] for c in cards)
    for m in modules: m["total_cards"]=counts[m["module_code"]]
    validate_lengths(modules,cards)
    package={"schema_version":"sales-training-cards.v1","generator_version":VERSION,"source_range":{"first":"SALES-0001","last":"SALES-0075"},"counts":{"source_cards":75,"modules":3,"cards":300,"K":75,"S":75,"D":75,"C":75},"modules":modules,"cards":cards}
    report={"schema_version":"sales-training-cards-report.v1","generator_version":VERSION,"source_range":{"first":"SALES-0001","last":"SALES-0075"},"counts":package["counts"],"module_card_counts":dict(sorted(counts.items())),"parse_variants":{"title_heading_styles":{"# <title>":len(parsed)},"unique_titles":len({x[4] for x in parsed}),"frontmatter_key_orders":dict(sorted(fm_variants.items())),"level_2_headings":dict(sorted(heading_variants.items()))}}
    return package,report

def encoded(obj): return (json.dumps(obj,ensure_ascii=False,indent=2,separators=(",", ": "))+"\n").encode("utf-8")
def atomic_outputs(outputs):
    """Publish a related file set with rollback if any individual replace fails."""
    temps, backups, replaced = [], {}, []
    try:
        # No target is touched until every new file is durable.
        for path,data in outputs:
            path.parent.mkdir(parents=True,exist_ok=True)
            fd,tmp=tempfile.mkstemp(prefix=path.name+".",suffix=".tmp",dir=path.parent)
            with os.fdopen(fd,"wb") as f: f.write(data); f.flush(); os.fsync(f.fileno())
            temps.append((Path(tmp),path))
        # Backups are copies, so targets not yet published remain unchanged.
        for _,path in temps:
            if path.exists():
                fd,bak=tempfile.mkstemp(prefix=path.name+".",suffix=".backup",dir=path.parent)
                os.close(fd)
                try: shutil.copy2(path,bak)
                except BaseException:
                    try: Path(bak).unlink()
                    except OSError: pass
                    raise
                backups[path]=Path(bak)
        fail_at=int(os.environ.get("SALES_GENERATOR_FAIL_REPLACE_AT", "0") or 0)
        for index,(tmp,path) in enumerate(temps,1):
            if fail_at == index: raise OSError(f"injected replace failure at {index}")
            os.replace(tmp,path); replaced.append(path)
    except BaseException:
        # Preserve the original publish exception; rollback and cleanup are best effort.
        for path in reversed(replaced):
            try:
                backup=backups.get(path)
                if backup is not None and backup.exists(): os.replace(backup,path)
                else: path.unlink(missing_ok=True)
            except OSError: pass
        for tmp,_ in temps:
            try: tmp.unlink(missing_ok=True)
            except OSError: pass
        for backup in backups.values():
            try: backup.unlink(missing_ok=True)
            except OSError: pass
        raise
    else:
        for backup in backups.values():
            try: backup.unlink(missing_ok=True)
            except OSError: pass
    finally:
        for tmp,_ in temps:
            try: tmp.unlink(missing_ok=True)
            except OSError: pass

def main(argv=None):
    p=argparse.ArgumentParser(); p.add_argument("--source",required=True,type=Path); p.add_argument("--output",required=True,type=Path); p.add_argument("--report",required=True,type=Path); p.add_argument("--checksum",required=True,type=Path)
    a=p.parse_args(argv)
    try:
        package,report=build(a.source); data=encoded(package); digest=hashlib.sha256(data).hexdigest()
        checksum=f"{digest}  {a.output.name}\n".encode("ascii")
        atomic_outputs([(a.output,data),(a.report,encoded(report)),(a.checksum,checksum)])
        print(f"generated 75 sources, 3 modules, 300 cards; sha256={digest}")
        return 0
    except (GenerationError,OSError,UnicodeError) as e:
        print(f"generation failed: {e}",file=sys.stderr); return 1
if __name__=="__main__": raise SystemExit(main())
