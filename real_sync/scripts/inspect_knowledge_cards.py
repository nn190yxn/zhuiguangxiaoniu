#!/usr/bin/env python3
"""Read-only contract checker and manifest builder for phase-two knowledge cards."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from collections import Counter
from pathlib import Path
from typing import Any

try:
    import yaml
except ImportError as exc:  # pragma: no cover - environment guard
    raise SystemExit("PyYAML 6 is required to inspect knowledge-card frontmatter") from exc

CARD_DIRS = {
    "动作卡": "action",
    "游戏卡": "game",
    "训练计划卡": "training_plan",
    "教学组织卡": "teaching_organization",
    "教学知识卡": "teaching_knowledge",
    "测评卡": "assessment",
    "安全与禁忌卡": "safety",
}
CARD_PREFIXES = {
    "动作卡": {"ACTION"},
    "游戏卡": {"GAME"},
    "训练计划卡": {"PLAN"},
    "教学组织卡": {"ORG"},
    "教学知识卡": {"KNOW"},
    "测评卡": {"ASSESSMENT"},
    "安全与禁忌卡": {"SAFE", "SAFETY"},
}
CARD_TYPES = tuple(CARD_DIRS.values())
TAXONOMY_MAPPING_PATH = Path(__file__).resolve().parent.parent / "database" / "knowledge_taxonomy_mapping.v1.json"


def load_active_taxonomy_mapping() -> dict[str, Any]:
    try:
        source = json.loads(TAXONOMY_MAPPING_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise SystemExit(f"cannot load knowledge taxonomy mapping: {exc}") from exc
    if not isinstance(source, dict):
        raise SystemExit("knowledge taxonomy mapping source must be an object")
    active_version = source.get("active_mapping_version")
    versions = source.get("versions")
    if not isinstance(versions, list):
        raise SystemExit("knowledge taxonomy mapping versions must be a list")
    active_mappings = [
        version for version in versions
        if isinstance(version, dict)
        and version.get("status") == "active"
    ]
    if (source.get("schema_version") != "knowledge-taxonomy-mapping.v1"
            or not active_version
            or len(active_mappings) != 1
            or active_mappings[0].get("mapping_version") != active_version):
        raise SystemExit("knowledge taxonomy must define one valid active mapping version")
    mapping = active_mappings[0]
    if not isinstance(mapping.get("domain_mappings"), dict):
        raise SystemExit("active knowledge taxonomy mapping has no domain mappings")
    return mapping


ACTIVE_TAXONOMY_MAPPING = load_active_taxonomy_mapping()
TAXONOMY_MAPPING_VERSION = str(ACTIVE_TAXONOMY_MAPPING["mapping_version"])
DOMAIN_MAPPINGS = ACTIVE_TAXONOMY_MAPPING["domain_mappings"]
DOMAIN_CODES = tuple(DOMAIN_MAPPINGS)
DOMAIN_RULES = (
    ("safety_first_aid", ("安全", "禁忌", "急救", "损伤", "疼痛", "风险")),
    ("sensory_integration", ("感统", "感觉统合", "前庭", "本体觉", "触觉")),
    ("child_development", ("儿童发展", "发展阶段", "认知", "情绪", "社交", "注意力")),
    ("physical_qualities", ("力量", "柔韧", "灵敏", "协调", "平衡", "速度", "耐力", "体能")),
    ("assessment", ("测评", "评估", "筛查", "测试", "体测")),
    ("teaching_practice", ("教学组织", "课堂组织", "队列", "分组", "教案", "课程流程")),
    ("ace_teaching", ("ACE", "目标", "教学法", "教练", "训练计划")),
    ("course_skills", ("动作", "游戏", "训练", "技术", "练习")),
)
REQUIRED_FIELDS = ("card_id", "card_type", "status", "risk_level")
DEFAULT_AGE_GUIDANCE = "全年龄段，需教练现场评估"
CARD_ID_RE = re.compile(r"^(?P<prefix>[A-Z]+)-(?P<number>\d{4})$")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-root", required=True, type=Path)
    parser.add_argument("--report", required=True, type=Path)
    parser.add_argument("--expected-record-count", type=int, default=1417)
    parser.add_argument("--strict", action="store_true", help="fail on contract errors")
    return parser.parse_args()


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def normalize(value: Any) -> Any:
    if isinstance(value, dict):
        return {str(k): normalize(value[k]) for k in sorted(value, key=str)}
    if isinstance(value, list):
        return [normalize(item) for item in value]
    return value


def repair_legacy_yaml_backslashes(value: str) -> str:
    """Escape Windows-style backslashes inside YAML double-quoted scalars."""
    output: list[str] = []
    in_double_quote = False
    escaped = False
    for char in value:
        if char == '"' and not escaped:
            in_double_quote = not in_double_quote
        if char == "\\" and in_double_quote and not escaped:
            output.append("\\\\")
            escaped = True
            continue
        output.append(char)
        escaped = char == "\\" and not escaped
        if char != "\\":
            escaped = False
    return "".join(output)


def parse_card(path: Path, root: Path) -> tuple[dict[str, Any] | None, list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    raw = path.read_bytes()
    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError:
        return None, ["not_utf8"], []
    lines = text.splitlines()
    if len(lines) < 3 or lines[0].strip() != "---":
        return None, ["missing_frontmatter_start"], []
    try:
        end = lines.index("---", 1)
    except ValueError:
        return None, ["missing_frontmatter_end"], []
    frontmatter = "\n".join(lines[1:end])
    try:
        metadata = yaml.safe_load(frontmatter)
    except yaml.YAMLError as exc:
        repaired = repair_legacy_yaml_backslashes(frontmatter)
        try:
            metadata = yaml.safe_load(repaired)
            warnings.append("legacy_yaml_backslashes_normalized")
        except yaml.YAMLError:
            return None, [f"invalid_yaml:{exc.__class__.__name__}"], []
    if not isinstance(metadata, dict):
        return None, ["frontmatter_not_mapping"], []
    relative = path.relative_to(root).as_posix()
    directory = path.parent.name
    card_id = metadata.get("card_id")
    card_type = metadata.get("card_type")
    if directory not in CARD_DIRS:
        errors.append("unexpected_card_directory")
    else:
        expected_type_label = directory[:-1]
        if card_type != expected_type_label:
            errors.append(f"card_type_mismatch:{card_type!r}")
    for field in REQUIRED_FIELDS:
        if field not in metadata or metadata[field] in (None, ""):
            errors.append(f"missing:{field}")
    if not isinstance(card_id, str) or not CARD_ID_RE.fullmatch(card_id):
        errors.append("invalid_card_id")
        prefix = None
    else:
        prefix = CARD_ID_RE.fullmatch(card_id).group("prefix")
        if directory in CARD_PREFIXES and prefix not in CARD_PREFIXES[directory]:
            errors.append(f"card_id_prefix_mismatch:{prefix}")
    if metadata.get("risk_level") not in {"低", "中", "高"}:
        errors.append("invalid_risk_level")
    if metadata.get("status") not in {"待整理", "待审核", "已审核", "已纳入课程", "不采用"}:
        errors.append("invalid_status")
    if "source_articles" not in metadata:
        errors.append("missing:source_articles")
    if "source_images" not in metadata:
        errors.append("missing:source_images")
    if end + 1 >= len(lines) or not any(line.strip() and not line.lstrip().startswith("<!--") for line in lines[end + 1:]):
        errors.append("empty_body")
    if not metadata.get("applicable_ages"):
        metadata["applicable_ages"] = [DEFAULT_AGE_GUIDANCE]
    if not metadata.get("primary_age"):
        metadata["primary_age"] = DEFAULT_AGE_GUIDANCE
    if not metadata.get("age_adaptation"):
        metadata["age_adaptation"] = DEFAULT_AGE_GUIDANCE
    if not metadata.get("target_roles"):
        metadata["target_roles"] = ["coach"]
    if not metadata.get("target_stages"):
        phases = metadata.get("setting", {}).get("lesson_phase", []) if isinstance(metadata.get("setting"), dict) else []
        metadata["target_stages"] = [phase for phase in phases if phase and phase != "原文未说明"] or ["通用"]
    if not metadata.get("difficulty"):
        metadata["difficulty"] = {"低": 2, "中": 3, "高": 4}.get(str(metadata.get("risk_level")), 3)
    if "related_content" not in metadata:
        metadata["related_content"] = []
    metadata_normalized = normalize(metadata)
    normalized_body = "\n".join(lines[end + 1:]).strip() + "\n"
    searchable_text = " ".join([
        str(metadata.get("card_type", "")),
        str(metadata.get("subjects", "")),
        str(metadata.get("source_articles", "")),
        normalized_body,
    ]).lower()
    domain_code = None
    domain_reason = "unmapped"
    for candidate, keywords in DOMAIN_RULES:
        hits = [keyword for keyword in keywords if keyword.lower() in searchable_text]
        if hits:
            domain_code = candidate
            domain_reason = "keyword:" + ",".join(hits[:3])
            break
    if domain_code is None:
        defaults = {
            "安全与禁忌": "safety_first_aid",
            "测评": "assessment",
            "教学组织": "teaching_practice",
            "教学知识": "ace_teaching",
            "训练计划": "ace_teaching",
            "动作": "course_skills",
            "游戏": "course_skills",
        }
        domain_code = defaults.get(str(card_type))
        if domain_code:
            domain_reason = "content_type_default"
    record = {
        "source_path": relative,
        "source_sha256": sha256_bytes(raw),
        "normalized_hash": sha256_bytes(normalized_body.encode("utf-8")),
        "source_card_id": card_id,
        "card_type": CARD_DIRS.get(directory),
        "card_type_label": card_type,
        "domain_code": domain_code,
        "domain_mapping_status": "mapped" if domain_code in DOMAIN_MAPPINGS else "unmapped",
        "domain_mapping_reason": domain_reason,
        "risk_level": metadata.get("risk_level"),
        "status": metadata.get("status"),
        "metadata": metadata_normalized,
        "quality_flags": warnings,
    }
    return record, errors, warnings


def inspect(root: Path, expected_record_count: int = 1417) -> dict[str, Any]:
    errors: list[dict[str, Any]] = []
    records: list[dict[str, Any]] = []
    if not root.is_dir():
        raise SystemExit(f"source root does not exist: {root}")
    paths = sorted(path for path in root.rglob("*.md") if path.name.lower() != "readme.md")
    for path in paths:
        record, card_errors, _ = parse_card(path, root)
        if record is not None:
            records.append(record)
        if card_errors:
            errors.append({"source_path": path.relative_to(root).as_posix(), "errors": card_errors})
    ids = [r["source_card_id"] for r in records if r["source_card_id"]]
    duplicate_ids = sorted(card_id for card_id, count in Counter(ids).items() if count > 1)
    if duplicate_ids:
        errors.append({"source_path": "", "errors": ["duplicate_card_ids:" + ",".join(duplicate_ids)]})
    type_counts = Counter(r["card_type"] for r in records)
    risk_counts = Counter(r["risk_level"] for r in records)
    status_counts = Counter(r["status"] for r in records)
    directory_counts = Counter(Path(r["source_path"]).parts[0] for r in records)
    report = {
        "schema_version": "knowledge-card-source-report.v1",
        "parser_version": "1.0.0",
        "taxonomy_mapping_version": TAXONOMY_MAPPING_VERSION,
        "source_root_name": root.name,
        "source_file_count": len(paths),
        "record_count": len(records),
        "expected_record_count": expected_record_count,
        "card_types": list(CARD_TYPES),
        "domain_codes": list(DOMAIN_CODES),
        "directory_counts": dict(sorted(directory_counts.items())),
        "type_counts": dict(sorted(type_counts.items())),
        "risk_counts": dict(sorted(risk_counts.items())),
        "status_counts": dict(sorted(status_counts.items())),
        "domain_mapping": {
            "mapped": sum(1 for record in records if record["domain_mapping_status"] == "mapped"),
            "unmapped": sum(1 for record in records if record["domain_mapping_status"] != "mapped"),
        },
        "quality_flag_counts": dict(sorted(Counter(flag for record in records for flag in record["quality_flags"]).items())),
        "duplicate_card_ids": duplicate_ids,
        "errors": errors,
        "error_count": len(errors),
        "valid": len(paths) == expected_record_count and len(records) == expected_record_count and not errors and not duplicate_ids,
        "records": sorted(records, key=lambda item: (item["source_card_id"] or "", item["source_path"])),
    }
    return report


def main() -> int:
    args = parse_args()
    report = inspect(args.source_root.resolve(), args.expected_record_count)
    args.report.parent.mkdir(parents=True, exist_ok=True)
    payload = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    args.report.write_text(payload, encoding="utf-8", newline="\n")
    print(json.dumps({k: report[k] for k in ("record_count", "type_counts", "risk_counts", "status_counts", "valid", "error_count") if k in report}, ensure_ascii=False))
    print(f"errors={len(report['errors'])}")
    if args.strict and not report["valid"]:
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
