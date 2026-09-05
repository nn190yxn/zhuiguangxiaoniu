#!/usr/bin/env python3
"""Build a deterministic classification review report for an isolated card package."""
from __future__ import annotations

import argparse
import hashlib
import json
from collections import Counter
from pathlib import Path
from typing import Any


REPORT_SCHEMA_VERSION = "knowledge-card-classification-review-report.v1"
TAXONOMY_SCHEMA_VERSION = "knowledge-taxonomy-mapping.v1"
PLACEHOLDER_VALUES = {"原文未说明", "全年龄段，需教练现场评估"}
REQUIRED_RECORD_FIELDS = (
    "item_code",
    "source_card_id",
    "source_path",
    "title",
    "content_type",
    "domain_code",
    "domain_mapping_status",
    "publication_status",
    "metadata",
)


def parse_args() -> argparse.Namespace:
    root = Path(__file__).resolve().parent.parent
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--package",
        type=Path,
        default=root / "database" / "import_data" / "knowledge-cards-phase2.isolated-package.json",
    )
    parser.add_argument(
        "--taxonomy",
        type=Path,
        default=root / "database" / "knowledge_taxonomy_mapping.v1.json",
    )
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--expected-record-count", type=int, default=1417)
    return parser.parse_args()


def stable_json(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n").encode("utf-8")


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def load_json(path: Path, label: str) -> tuple[dict[str, Any], bytes]:
    try:
        raw = path.read_bytes()
        value = json.loads(raw)
    except (OSError, json.JSONDecodeError) as exc:
        raise SystemExit(f"cannot load {label}: {exc}") from exc
    if not isinstance(value, dict):
        raise SystemExit(f"{label} must be a JSON object")
    return value, raw


def load_active_taxonomy(source: dict[str, Any]) -> dict[str, Any]:
    versions = source.get("versions")
    active_version = source.get("active_mapping_version")
    if source.get("schema_version") != TAXONOMY_SCHEMA_VERSION or not isinstance(versions, list):
        raise SystemExit("knowledge taxonomy source has an invalid schema")
    active = [version for version in versions if isinstance(version, dict) and version.get("status") == "active"]
    if len(active) != 1 or active[0].get("mapping_version") != active_version:
        raise SystemExit("knowledge taxonomy must define one valid active mapping version")
    for field in ("primary_categories", "domain_mappings", "content_type_review_baselines"):
        if not isinstance(active[0].get(field), dict):
            raise SystemExit(f"active knowledge taxonomy has no {field}")
    validate_taxonomy_targets(active[0])
    return active[0]


def validate_taxonomy_targets(taxonomy: dict[str, Any]) -> None:
    categories = taxonomy["primary_categories"]
    for collection_name in ("domain_mappings", "content_type_review_baselines"):
        for source_code, target in taxonomy[collection_name].items():
            if not isinstance(target, dict):
                raise SystemExit(f"{collection_name}.{source_code} must be an object")
            primary = target.get("primary_category")
            subcategory = target.get("subcategory_code")
            primary_definition = categories.get(primary)
            if not isinstance(primary_definition, dict):
                raise SystemExit(f"{collection_name}.{source_code} has an unknown primary category")
            subcategories = primary_definition.get("subcategories")
            if not isinstance(subcategories, dict) or subcategory not in subcategories:
                raise SystemExit(f"{collection_name}.{source_code} has an unknown subcategory")


def contains_placeholder(value: Any) -> bool:
    if isinstance(value, str):
        normalized = value.strip()
        return normalized in PLACEHOLDER_VALUES or normalized.startswith("原文未说明")
    if isinstance(value, list):
        return any(contains_placeholder(item) for item in value)
    if isinstance(value, dict):
        return any(contains_placeholder(item) for item in value.values())
    return False


def target_value(target: dict[str, Any] | None) -> dict[str, str] | None:
    if target is None:
        return None
    return {
        "primary_category": str(target["primary_category"]),
        "subcategory_code": str(target["subcategory_code"]),
    }


def counter_rows(counter: Counter[Any], field_names: tuple[str, ...]) -> list[dict[str, Any]]:
    rows = []
    for key, count in sorted(counter.items(), key=lambda item: item[0] if isinstance(item[0], tuple) else (item[0],)):
        values = key if isinstance(key, tuple) else (key,)
        rows.append({**dict(zip(field_names, values)), "count": count})
    return rows


def build_report(
    package: dict[str, Any],
    package_raw: bytes,
    taxonomy_source: dict[str, Any],
    taxonomy_raw: bytes,
    expected_record_count: int,
    package_path: str,
    taxonomy_path: str,
) -> dict[str, Any]:
    taxonomy = load_active_taxonomy(taxonomy_source)
    records = package.get("records")
    record_count = package.get("record_count")
    if not isinstance(records, list) or not isinstance(record_count, int):
        raise SystemExit("knowledge package must define records and record_count")
    if record_count != len(records) or record_count != expected_record_count:
        raise SystemExit("knowledge package record count does not match the expected count")

    item_codes: set[str] = set()
    review_items: list[dict[str, Any]] = []
    mapping_gaps: list[dict[str, str]] = []
    source_domain_counts: Counter[str] = Counter()
    content_type_counts: Counter[str] = Counter()
    taxonomy_target_counts: Counter[tuple[str, str]] = Counter()
    difference_counts: Counter[tuple[str, str, str]] = Counter()
    reason_counts: Counter[str] = Counter()
    mapped_count = 0
    classification_match_count = 0
    classification_difference_count = 0
    transitional_count = 0

    domain_mappings = taxonomy["domain_mappings"]
    review_baselines = taxonomy["content_type_review_baselines"]
    transitional_category = str(package.get("default_category_code") or "")

    for index, record in enumerate(records):
        if not isinstance(record, dict):
            raise SystemExit(f"knowledge package record {index} must be an object")
        missing = [field for field in REQUIRED_RECORD_FIELDS if field not in record]
        if missing:
            raise SystemExit(f"knowledge package record {index} is missing: {','.join(missing)}")
        item_code = str(record["item_code"])
        if not item_code or item_code in item_codes:
            raise SystemExit(f"knowledge package contains an empty or duplicate item_code: {item_code}")
        item_codes.add(item_code)

        content_type = str(record["content_type"])
        domain_code = str(record["domain_code"])
        metadata = record["metadata"]
        if not isinstance(metadata, dict):
            raise SystemExit(f"knowledge package metadata must be an object: {item_code}")

        mapped_target = domain_mappings.get(domain_code)
        baseline_target = review_baselines.get(content_type)
        source_domain_counts[domain_code] += 1
        content_type_counts[content_type] += 1
        reasons = ["transitional_category", "classification_review_missing"]
        transitional_count += 1

        if mapped_target is None or mapped_target.get("status") != "active":
            reasons.append("taxonomy_mapping_missing")
            mapping_gaps.append({
                "item_code": item_code,
                "source_domain_code": domain_code,
                "source_path": str(record["source_path"]),
            })
        else:
            mapped_count += 1
            mapped_key = (str(mapped_target["primary_category"]), str(mapped_target["subcategory_code"]))
            taxonomy_target_counts[mapped_key] += 1
            if baseline_target is None:
                reasons.append("content_type_review_baseline_missing")
            else:
                baseline_key = (str(baseline_target["primary_category"]), str(baseline_target["subcategory_code"]))
                if baseline_key == mapped_key:
                    classification_match_count += 1
                else:
                    classification_difference_count += 1
                    reasons.append("content_type_taxonomy_difference")
                    difference_counts[(content_type, domain_code, mapped_key[1])] += 1

        age_values = {
            "primary_age": metadata.get("primary_age"),
            "applicable_ages": metadata.get("applicable_ages"),
            "age_adaptation": metadata.get("age_adaptation"),
        }
        if contains_placeholder(age_values):
            reasons.append("applicable_age_confirmation_required")
        if contains_placeholder(metadata.get("setting")):
            reasons.append("setting_confirmation_required")
        if metadata.get("related_content") == []:
            reasons.append("related_content_confirmation_required")

        for reason in reasons:
            reason_counts[reason] += 1
        review_items.append({
            "assigned_category_code": transitional_category,
            "classification_difference": "content_type_taxonomy_difference" in reasons,
            "content_type": content_type,
            "content_type_review_baseline": target_value(baseline_target),
            "item_code": item_code,
            "mapped_taxonomy_target": target_value(mapped_target),
            "review_reasons": sorted(reasons),
            "review_status": "pending",
            "source_card_id": str(record["source_card_id"]),
            "source_domain_code": domain_code,
            "source_path": str(record["source_path"]),
            "title": str(record["title"]),
        })

    review_items.sort(key=lambda item: (item["item_code"], item["source_path"]))
    mapping_gaps.sort(key=lambda item: (item["source_domain_code"], item["item_code"], item["source_path"]))
    report: dict[str, Any] = {
        "inputs": {
            "package_file_sha256": sha256_bytes(package_raw),
            "package_identity_sha256": str(package.get("package_sha256") or ""),
            "package_path": package_path,
            "source_report_sha256": str(package.get("source_report_sha256") or ""),
            "taxonomy_file_sha256": sha256_bytes(taxonomy_raw),
            "taxonomy_mapping_version": str(taxonomy["mapping_version"]),
            "taxonomy_path": taxonomy_path,
            "taxonomy_schema_version": str(taxonomy_source["schema_version"]),
        },
        "mapping_gaps": mapping_gaps,
        "review_items": review_items,
        "schema_version": REPORT_SCHEMA_VERSION,
        "scope": {
            "production_database": {
                "reason": "repository classification report does not connect to a database",
                "status": "not_evaluated",
            },
            "repository_package": {
                "publication_state": str(package.get("publication_default") or "unknown"),
                "status": "evaluated",
            },
        },
        "statistics": {
            "classification_differences": counter_rows(
                difference_counts,
                ("content_type", "source_domain_code", "mapped_subcategory_code"),
            ),
            "content_types": counter_rows(content_type_counts, ("content_type",)),
            "manual_review_reasons": counter_rows(reason_counts, ("reason",)),
            "source_domains": counter_rows(source_domain_counts, ("source_domain_code",)),
            "taxonomy_targets": counter_rows(
                taxonomy_target_counts,
                ("primary_category", "subcategory_code"),
            ),
        },
        "summary": {
            "classification_difference_count": classification_difference_count,
            "classification_match_count": classification_match_count,
            "manual_review_count": len(review_items),
            "mapped_count": mapped_count,
            "mapping_gap_count": len(mapping_gaps),
            "record_count": record_count,
            "review_status_counts": {"confirmed": 0, "pending": len(review_items)},
            "transitional_category_code": transitional_category,
            "transitional_count": transitional_count,
        },
    }
    report["report_sha256"] = sha256_bytes(stable_json(report))
    return report


def main() -> int:
    args = parse_args()
    package_path = args.package.resolve()
    taxonomy_path = args.taxonomy.resolve()
    package, package_raw = load_json(package_path, "knowledge package")
    taxonomy, taxonomy_raw = load_json(taxonomy_path, "knowledge taxonomy")
    report = build_report(
        package,
        package_raw,
        taxonomy,
        taxonomy_raw,
        args.expected_record_count,
        package_path.name,
        taxonomy_path.name,
    )
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_bytes(stable_json(report))
    print(json.dumps(report["summary"], ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
