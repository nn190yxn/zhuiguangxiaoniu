from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
from typing import Any

from inspect_knowledge_cards import inspect


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build an isolated, deterministic phase-two knowledge-card package")
    parser.add_argument("--source-root", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--expected-record-count", type=int, default=1417)
    parser.add_argument("--strict", action="store_true")
    return parser.parse_args()


def stable_json(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n").encode("utf-8")


def build_package(source_root: Path, expected_record_count: int = 1417) -> dict[str, Any]:
    source_root = source_root.resolve()
    report = inspect(source_root, expected_record_count)
    records = []
    for record in report["records"]:
        records.append(
            {
                "item_code": record["source_card_id"],
                "source_card_id": record["source_card_id"],
                "source_path": record["source_path"],
                "source_sha256": record["source_sha256"],
                "normalized_hash": record["normalized_hash"],
                "content_type": record["card_type"],
                "content_type_label": record["card_type_label"],
                "domain_code": record["domain_code"],
                "domain_mapping_status": record["domain_mapping_status"],
                "risk_level": record["risk_level"],
                "source_status": record["status"],
                "publication_status": "isolated",
                "metadata": record["metadata"],
            }
        )
    package = {
        "schema_version": "knowledge-card-isolated-package.v1",
        "parser_version": report["parser_version"],
        "source_root_name": report["source_root_name"],
        "source_file_count": report["source_file_count"],
        "record_count": report["record_count"],
        "source_report_valid": report["valid"],
        "source_report_error_count": report["error_count"],
        "source_report_sha256": hashlib.sha256(stable_json(report).replace(b'"records": [', b'"records": [', 1)).hexdigest(),
        "card_types": report["card_types"],
        "domain_codes": report["domain_codes"],
        "type_counts": report["type_counts"],
        "risk_counts": report["risk_counts"],
        "status_counts": report["status_counts"],
        "quality_flag_counts": report["quality_flag_counts"],
        "domain_mapping": report["domain_mapping"],
        "publication_default": "isolated",
        "records": records,
    }
    package["package_sha256"] = hashlib.sha256(stable_json(package)).hexdigest()
    return package


def main() -> int:
    args = parse_args()
    package = build_package(args.source_root, args.expected_record_count)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_bytes(stable_json(package))
    print(json.dumps({
        "record_count": package["record_count"],
        "source_report_valid": package["source_report_valid"],
        "source_report_error_count": package["source_report_error_count"],
        "package_sha256": package["package_sha256"],
    }, ensure_ascii=False))
    if args.strict and not package["source_report_valid"]:
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
