from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path
from typing import Any

from inspect_knowledge_cards import inspect, sha256_bytes


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build an isolated, deterministic phase-two knowledge-card package")
    parser.add_argument("--source-root", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--expected-record-count", type=int, default=1417)
    parser.add_argument("--strict", action="store_true")
    return parser.parse_args()


def stable_json(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n").encode("utf-8")


def load_body(source_root: Path, record: dict[str, Any]) -> tuple[str, str, str]:
    """Return (title, normalized_body, raw_markdown) for one record.

    normalized_body must reproduce the exact bytes the inspector hashed, so the
    normalized_hash inside the package can be verified end-to-end.
    """
    path = (source_root / record["source_path"]).resolve()
    raw = path.read_bytes().decode("utf-8")
    lines = raw.splitlines()
    try:
        end = lines.index("---", 1)
    except ValueError:
        raise SystemExit(f"missing frontmatter end in {record['source_path']}")
    normalized_body = "\n".join(lines[end + 1:]).strip() + "\n"
    if sha256_bytes(normalized_body.encode("utf-8")) != record["normalized_hash"]:
        raise SystemExit(f"normalized_hash mismatch for {record['source_path']}")
    first = next((line for line in normalized_body.splitlines() if line.strip()), "")
    match = re.match(r"^#\s+(.+?)\s*$", first)
    if not match:
        raise SystemExit(f"missing H1 title in {record['source_path']}")
    return match.group(1), normalized_body, raw


def package_identity_sha256(package: dict[str, Any]) -> str:
    parts = [
        package["schema_version"],
        package["parser_version"],
        package["source_root_name"],
        str(package["record_count"]),
        package["source_report_sha256"],
    ]
    for record in package["records"]:
        parts.extend((record["item_code"], record["source_sha256"], record["normalized_hash"]))
    return hashlib.sha256("\0".join(parts).encode("utf-8")).hexdigest()


def build_package(source_root: Path, expected_record_count: int = 1417) -> dict[str, Any]:
    source_root = source_root.resolve()
    report = inspect(source_root, expected_record_count)
    records = []
    for record in report["records"]:
        title, normalized_body, raw_markdown = load_body(source_root, record)
        records.append(
            {
                "item_code": record["source_card_id"],
                "source_card_id": record["source_card_id"],
                "source_path": record["source_path"],
                "source_sha256": record["source_sha256"],
                "normalized_hash": record["normalized_hash"],
                "title": title,
                "content": normalized_body,
                "raw_markdown": raw_markdown,
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
        "schema_version": "knowledge-card-isolated-package.v2",
        "parser_version": report["parser_version"],
        "source_root_name": report["source_root_name"],
        "source_file_count": report["source_file_count"],
        "record_count": report["record_count"],
        "source_report_valid": report["valid"],
        "source_report_error_count": report["error_count"],
        "source_report_sha256": hashlib.sha256(stable_json(report)).hexdigest(),
        "card_types": report["card_types"],
        "domain_codes": report["domain_codes"],
        "type_counts": report["type_counts"],
        "risk_counts": report["risk_counts"],
        "status_counts": report["status_counts"],
        "quality_flag_counts": report["quality_flag_counts"],
        "domain_mapping": report["domain_mapping"],
        "default_category_code": "phase2_import",
        "publication_default": "isolated",
        "records": records,
    }
    package["package_sha256"] = package_identity_sha256(package)
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
        "schema_version": package["schema_version"],
        "default_category_code": package["default_category_code"],
        "package_sha256": package["package_sha256"],
    }, ensure_ascii=False))
    if args.strict and not package["source_report_valid"]:
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
