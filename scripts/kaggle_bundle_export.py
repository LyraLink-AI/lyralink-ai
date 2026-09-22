#!/usr/bin/env python3
"""Build a provenance-filtered training bundle for remote (Kaggle) training.

Why this exists
---------------
`dataset_auto_learn.php` records an E0-E5 evidence level for every row it
ingests. The existing JSONL export (`hf_dataset_to_training_jsonl.py`) drops
that field, so the trainer cannot tell a tool-verified answer apart from the
model's own unverified prose. Training on the latter is self-reinforcement:
the model learns to reproduce its own confident mistakes.

This exporter reads the database directly, keeps provenance, filters by
evidence level, and refuses to silently produce an empty or legacy-only bundle.

Evidence vocabulary (as used by the runtime):
    E0 NONE                      no evidence
    E1 USER_PROVIDED             asserted by the user
    E2 MODEL_KNOWLEDGE           model's own knowledge  <- NOT a training signal
    E3 WEB_RETRIEVED             grounded in a retrieved page
    E4 TOOL_VERIFIED             a tool ran and its result was verified
    E5 DIRECT_EXECUTION_ARTIFACT output of an executed action

Default keep-list is E3,E4,E5: material grounded in a retrieved source or an
actual verified execution. E2 is deliberately excluded, because E2 is the
model's own knowledge restated - training on it rewards confidence rather than
correctness, which is exactly the failure the benchmark measures. Widen it
deliberately with --min-evidence / --evidence-set.

Exit codes: 0 ok, 1 refused (no usable rows), 2 error.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sys
from datetime import datetime, timezone
from typing import Any, Iterable

EVIDENCE_ORDER = ["E0", "E1", "E2", "E3", "E4", "E5"]

# Phrases that indicate the assistant turn is not a good training target.
BAD_TARGETS = [
    re.compile(p, re.IGNORECASE)
    for p in (
        r"\bsorry,? something went wrong\b",
        r"\bai returned empty\b",
        r"\btry sending your message again\b",
        r"\bas an ai language model\b",
        r"\bi (?:cannot|can't) (?:actually|really) (?:access|browse|execute)\b",
        r"\bi (?:can |will )?(?:simulate|pretend to) ",
        r"\bhere(?:'s| is) (?:a|an) (?:example|hypothetical) (?:of )?how i would\b",
    )
]

# Claimed-capability tells: the model asserting it did or can do something.
CAPABILITY_CLAIMS = [
    re.compile(p, re.IGNORECASE)
    for p in (
        r"\bi (?:have|already) (?:executed|run|created|written|deployed)\b",
        r"\bi (?:executed|ran) (?:the )?(?:command|script|query)\b",
        r"\bi (?:searched|browsed|checked) the (?:web|internet|site)\b",
    )
]


def read_env(path: str) -> dict[str, str]:
    out: dict[str, str] = {}
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as handle:
            for line in handle:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, _, value = line.partition("=")
                out[key.strip()] = value.strip().strip('"').strip("'")
    except OSError:
        pass
    return out


def connect(env: dict[str, str]):
    import pymysql  # noqa: F401  (optional dependency, imported lazily)

    return pymysql.connect(
        host=env.get("DB_HOST", "127.0.0.1"),
        user=env.get("DB_USER", ""),
        password=env.get("DB_PASS", ""),
        database=env.get("DB_NAME", ""),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )


def fetch_rows(cur) -> list[dict[str, Any]]:
    """Only columns we know exist; missing columns are tolerated."""
    cur.execute("SHOW COLUMNS FROM dataset")
    columns = {row["Field"] for row in cur.fetchall()}

    wanted = [c for c in ("question", "answer", "evidence_level", "source_kind", "approved") if c in columns]
    if "question" not in wanted or "answer" not in wanted:
        raise SystemExit("dataset table lacks question/answer columns")

    where = []
    if "approved" in columns:
        where.append("approved = 1")
    sql = "SELECT " + ", ".join(wanted) + " FROM dataset"
    if where:
        sql += " WHERE " + " AND ".join(where)
    cur.execute(sql)
    return list(cur.fetchall())


def normalise_evidence(raw: Any) -> str:
    value = str(raw or "").strip().upper()
    if not value:
        return ""
    if value in EVIDENCE_ORDER:
        return value
    # Tolerate long-form values such as "TOOL_VERIFIED".
    for level in EVIDENCE_ORDER:
        if value.startswith(level):
            return level
    return value


def is_usable_target(text: str) -> bool:
    if len(text.strip()) < 20:
        return False
    for pattern in BAD_TARGETS:
        if pattern.search(text):
            return False
    return True


def claimed_capability(text: str) -> bool:
    return any(p.search(text) for p in CAPABILITY_CLAIMS)


def build_records(rows: Iterable[dict[str, Any]], keep: set[str], keep_unknown: bool,
                  drop_capability_claims: bool) -> tuple[list[dict[str, Any]], dict[str, int]]:
    stats = {
        "input_rows": 0,
        "kept": 0,
        "dup_question": 0,
        "bad_target": 0,
        "evidence_filtered": 0,
        "capability_claim": 0,
        "no_provenance": 0,
    }
    seen: set[str] = set()
    records: list[dict[str, Any]] = []

    for row in rows:
        stats["input_rows"] += 1
        question = str(row.get("question") or "").strip()
        answer = str(row.get("answer") or "").strip()
        if not question or not answer:
            stats["bad_target"] += 1
            continue

        level = normalise_evidence(row.get("evidence_level"))
        if not level:
            stats["no_provenance"] += 1
            if not keep_unknown:
                stats["evidence_filtered"] += 1
                continue
        elif level not in keep:
            stats["evidence_filtered"] += 1
            continue

        if not is_usable_target(answer):
            stats["bad_target"] += 1
            continue

        if drop_capability_claims and claimed_capability(answer):
            stats["capability_claim"] += 1
            continue

        fingerprint = hashlib.sha256(question.lower().encode("utf-8")).hexdigest()
        if fingerprint in seen:
            stats["dup_question"] += 1
            continue
        seen.add(fingerprint)

        records.append({
            "messages": [
                {"role": "user", "content": question},
                {"role": "assistant", "content": answer},
            ],
            "metadata": {
                "evidence_level": level or None,
                "source_kind": row.get("source_kind"),
                "question_sha256": fingerprint,
            },
        })
        stats["kept"] += 1

    return records, stats


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Export a provenance-filtered training bundle")
    parser.add_argument("--out", required=True, help="Output JSONL path")
    parser.add_argument("--root", default=os.environ.get("LYRALINK_WORKSPACE_ROOT",
                                                         "/var/www/vhosts/lyralinkai.com/httpdocs"))
    parser.add_argument("--min-evidence", default=os.environ.get("LYRALINK_TRAIN_MIN_EVIDENCE", "E3"),
                        help="Lowest acceptable evidence level (default E3 = web_retrieved)")
    parser.add_argument("--evidence-set", default=None,
                        help="Explicit comma list, e.g. E3,E4,E5. Overrides --min-evidence")
    parser.add_argument("--keep-unprovenanced", action="store_true",
                        help="Include rows with no evidence level (legacy data). Logged as a risk.")
    parser.add_argument("--allow-empty", action="store_true",
                        help="Exit 0 even when zero rows are kept")
    parser.add_argument("--max-rows", type=int, default=0, help="Cap rows (0 = no cap)")
    parser.add_argument("--report", default=None, help="Where to write the export report JSON")
    parser.add_argument("--normalise-only", action="store_true",
                        help="Re-emit an existing JSONL with the same filters (still requires provenance)")
    parser.add_argument("--input-jsonl", default=None,
                        help="Read records from a JSONL instead of the database")
    return parser.parse_args(argv)


def emit(records: list[dict[str, Any]], path: str) -> None:
    os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False) + "\n")


def main(argv: list[str] | None = None) -> int:
    args = parse_args(list(sys.argv[1:] if argv is None else argv))

    if args.evidence_set:
        keep = {x.strip().upper() for x in args.evidence_set.split(",") if x.strip()}
    else:
        try:
            threshold = EVIDENCE_ORDER.index(args.min_evidence.strip().upper())
        except ValueError:
            print(f"[export] invalid --min-evidence {args.min_evidence!r}", file=sys.stderr)
            return 2
        keep = set(EVIDENCE_ORDER[threshold:])

    rows: list[dict[str, Any]] = []
    if args.input_jsonl:
        with open(args.input_jsonl, "r", encoding="utf-8", errors="replace") as handle:
            for line in handle:
                line = line.strip()
                if not line:
                    continue
                try:
                    doc = json.loads(line)
                except ValueError:
                    continue
                meta = doc.get("metadata") or {}
                messages = doc.get("messages") or []
                question = answer = ""
                if isinstance(messages, list) and len(messages) >= 2:
                    question = str(messages[0].get("content") or "")
                    answer = str(messages[-1].get("content") or "")
                rows.append({
                    "question": question,
                    "answer": answer,
                    "evidence_level": meta.get("evidence_level"),
                    "source_kind": meta.get("source_kind"),
                })
    else:
        env = read_env(os.path.join(args.root, ".env"))
        try:
            connection = connect(env)
        except Exception as exc:  # pragma: no cover - environment dependent
            print(f"[export] database connection failed: {exc}", file=sys.stderr)
            print("[export] hint: pip install pymysql, or pass --input-jsonl", file=sys.stderr)
            return 2
        try:
            with connection.cursor() as cur:
                rows = fetch_rows(cur)
        finally:
            connection.close()

    records, stats = build_records(rows, keep, args.keep_unprovenanced, True)

    if args.max_rows and len(records) > args.max_rows:
        records = records[: args.max_rows]
        stats["capped_to"] = args.max_rows

    emit(records, args.out)

    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "output": os.path.abspath(args.out),
        "keep_set": sorted(keep),
        "keep_unprovenanced": bool(args.keep_unprovenanced),
        "kept_rows": len(records),
        "stats": stats,
    }
    if args.report:
        with open(args.report, "w", encoding="utf-8") as handle:
            json.dump(report, handle, indent=2, sort_keys=True)

    print(f"[export] rows_in={stats['input_rows']} kept={len(records)} "
          f"evidence_filtered={stats['evidence_filtered']} "
          f"no_provenance={stats['no_provenance']} "
          f"dupes={stats['dup_question']} bad_target={stats['bad_target']} "
          f"capability_claims={stats['capability_claim']}")
    print(f"[export] keep_set={sorted(keep)} output={args.out}")

    if not records:
        print("[export] REFUSED: zero usable rows after filtering.", file=sys.stderr)
        if stats["no_provenance"]:
            print(f"[export] {stats['no_provenance']} rows carry no evidence_level. "
                  "Those are legacy rows written before provenance was persisted; "
                  "training on them is self-reinforcement, not learning.", file=sys.stderr)
        if not args.allow_empty:
            return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
