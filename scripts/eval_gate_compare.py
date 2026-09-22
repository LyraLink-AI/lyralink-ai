#!/usr/bin/env python3
"""Lyralink fine-tune promotion gate (eval gate).

Compares a candidate benchmark summary against a stored baseline summary and
decides whether a fine-tuned model may be promoted.

Schema is pinned to BENCHMARK_SUMMARY_V2.json as produced by
benchmark/score_benchmark.py:

    benchmark_version, run_status, task_count, actual_task_count,
    successful_outputs, failed_outputs, timeout_outputs, empty_outputs,
    invalid_outputs, lyralink_scored, lyralink_average,
    lyralink_weighted_average, weighted_total, category_weights,
    category_breakdown, critical_failures, lyralink_critical_failures,
    evidence_paralysis_count

Exit codes
    0  PASS   - promotion allowed
    1  BLOCK  - promotion denied
    2  ERROR  - gate could not be evaluated (missing/malformed input)

Nothing here calls a model or the network. It is a deterministic arithmetic
comparison so a promotion decision can never be "improvised" by an LLM.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from datetime import datetime, timezone
from typing import Any

# --------------------------------------------------------------------------
# Defaults (all overridable by CLI flag or environment variable)
# --------------------------------------------------------------------------

DEFAULTS = {
    # Absolute floor. A candidate below this is rejected regardless of baseline.
    "min_weighted": 70.0,
    # Allowed score drop vs baseline, in weighted points.
    "max_regression": 2.0,
    # critical_failures may not increase by more than this.
    "max_critical_increase": 0,
    # Absolute critical-failure ceiling. Negative disables the check.
    "max_critical_absolute": -1,
    # evidence_paralysis_count may not increase by more than this.
    "max_paralysis_increase": 0,
    # (failed + timeout + empty + invalid) outputs may not increase by more than this.
    "max_output_failure_increase": 0,
    # A run must score at least this fraction of the baseline's scored tasks.
    "min_coverage_ratio": 0.90,
    # Per-category floors are advisory unless enforce_categories is set.
    "enforce_categories": 0,
    "max_category_regression": 5.0,
    # Categories below this weight are ignored for category checks.
    "min_category_weight": 10.0,
}


def env_default(key: str, fallback: Any) -> Any:
    raw = os.environ.get("EVAL_GATE_" + key.upper())
    if raw is None or raw == "":
        return fallback
    if isinstance(fallback, float):
        try:
            return float(raw)
        except ValueError:
            return fallback
    if isinstance(fallback, int):
        try:
            return int(raw)
        except ValueError:
            return fallback
    return raw


def load_json(path: str) -> dict[str, Any]:
    with open(path, "r", encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict):
        raise ValueError(f"{path}: expected a JSON object, got {type(data).__name__}")
    return data


def num(doc: dict[str, Any], key: str) -> float | None:
    value = doc.get(key)
    if isinstance(value, bool) or value is None:
        return None
    if isinstance(value, (int, float)):
        return float(value)
    return None


def output_failures(doc: dict[str, Any]) -> float | None:
    keys = ("failed_outputs", "timeout_outputs", "empty_outputs", "invalid_outputs")
    present = [num(doc, k) for k in keys]
    if all(v is None for v in present):
        return None
    return float(sum(v for v in present if v is not None))


def category_scores(doc: dict[str, Any]) -> dict[str, float]:
    """Extract {category: score} from category_breakdown.

    The exact inner shape of category_breakdown is not asserted here because it
    has varied; this reads the first usable numeric field per category and
    reports what it could not parse instead of guessing a value.
    """
    breakdown = doc.get("category_breakdown")
    if not isinstance(breakdown, dict):
        return {}
    # Verified against BENCHMARK_SUMMARY_V2.json, where each category payload is
    # {"weight": int, "lyralink_avg": float|None, "external_avg": ..., "lyralink_count": int}.
    preferred = ("lyralink_avg", "lyralink_weighted", "weighted", "weighted_score",
                 "score", "lyralink", "lyralink_score", "average", "percent")
    out: dict[str, float] = {}
    for category, payload in breakdown.items():
        if isinstance(payload, bool):
            continue
        if isinstance(payload, (int, float)):
            out[str(category)] = float(payload)
            continue
        if isinstance(payload, dict):
            for field in preferred:
                value = payload.get(field)
                if isinstance(value, (int, float)) and not isinstance(value, bool):
                    out[str(category)] = float(value)
                    break
    return out


def breakdown_weights(doc: dict[str, Any]) -> dict[str, float]:
    """Extract {category: weight} from category_breakdown payloads."""
    breakdown = doc.get("category_breakdown")
    if not isinstance(breakdown, dict):
        return {}
    out: dict[str, float] = {}
    for category, payload in breakdown.items():
        if isinstance(payload, dict):
            value = payload.get("weight")
            if isinstance(value, (int, float)) and not isinstance(value, bool):
                out[str(category)] = float(value)
    return out


def build_report(baseline: dict[str, Any], candidate: dict[str, Any],
                 cfg: dict[str, Any]) -> dict[str, Any]:
    reasons: list[str] = []
    warnings: list[str] = []
    checks: list[dict[str, Any]] = []

    cand_total = num(candidate, "weighted_total")
    if cand_total is None:
        cand_total = num(candidate, "lyralink_weighted_average")
    if cand_total is None:
        cand_total = num(candidate, "lyralink_average")

    base_total = num(baseline, "weighted_total")
    if base_total is None:
        base_total = num(baseline, "lyralink_weighted_average")
    if base_total is None:
        base_total = num(baseline, "lyralink_average")

    if cand_total is None:
        return {
            "verdict": "ERROR",
            "reasons": ["Candidate summary has no usable score field "
                        "(weighted_total / lyralink_weighted_average / lyralink_average)."],
            "checks": checks,
            "warnings": warnings,
        }

    # --- gate 1: run completed -------------------------------------------
    status = str(candidate.get("run_status") or "").upper()
    ok = status in {"COMPLETED", ""}
    checks.append({"name": "run_completed", "pass": ok,
                   "observed": status, "required": "COMPLETED"})
    if not ok:
        reasons.append(f"Candidate benchmark run did not complete (run_status={status or 'MISSING'}).")

    # --- gate 2: absolute floor ------------------------------------------
    floor = float(cfg["min_weighted"])
    ok = cand_total >= floor
    checks.append({"name": "absolute_floor", "pass": ok,
                   "observed": round(cand_total, 4), "required": f">={floor}"})
    if not ok:
        reasons.append(f"Candidate weighted total {cand_total:.2f} is below the "
                       f"absolute floor {floor:.2f}.")

    # --- gate 3: regression vs baseline ----------------------------------
    delta = None
    if base_total is not None:
        delta = round(cand_total - base_total, 4)
        allowed = float(cfg["max_regression"])
        ok = delta >= -allowed
        checks.append({"name": "no_score_regression", "pass": ok,
                       "observed": delta, "required": f">=-{allowed}"})
        if not ok:
            reasons.append(f"Candidate regressed {abs(delta):.2f} weighted points "
                           f"vs baseline (allowed drop {allowed:.2f}).")
    else:
        warnings.append("Baseline has no comparable score field; "
                        "regression check skipped (absolute floor still enforced).")

    # --- gate 4: critical failures ---------------------------------------
    cand_crit = num(candidate, "lyralink_critical_failures")
    if cand_crit is None:
        cand_crit = num(candidate, "critical_failures")
    base_crit = num(baseline, "lyralink_critical_failures")
    if base_crit is None:
        base_crit = num(baseline, "critical_failures")
    if cand_crit is not None and base_crit is not None:
        allowed = float(cfg["max_critical_increase"])
        ok = cand_crit <= base_crit + allowed
        checks.append({"name": "critical_failures", "pass": ok,
                       "observed": cand_crit, "baseline": base_crit,
                       "required": f"<={base_crit + allowed}"})
        if not ok:
            reasons.append(f"Candidate critical failures {cand_crit:.0f} exceed "
                           f"baseline {base_crit:.0f} (tolerance {allowed:.0f}).")
    elif cand_crit is not None:
        ok = cand_crit <= float(cfg["max_critical_increase"])
        checks.append({"name": "critical_failures", "pass": ok,
                       "observed": cand_crit,
                       "required": f"<={float(cfg['max_critical_increase']):.0f}"})
        if not ok:
            reasons.append(f"Candidate has {cand_crit:.0f} critical failures "
                           f"with no usable baseline.")

    # --- gate 4b: absolute critical-failure ceiling ----------------------
    absolute_ceiling = float(cfg["max_critical_absolute"])
    if cand_crit is not None and absolute_ceiling >= 0:
        ok = cand_crit <= absolute_ceiling
        checks.append({"name": "critical_failures_absolute", "pass": ok,
                       "observed": cand_crit,
                       "required": f"<={absolute_ceiling:.0f}"})
        if not ok:
            reasons.append(f"Candidate has {cand_crit:.0f} critical failures "
                           f"above the absolute ceiling {absolute_ceiling:.0f}.")

    # --- gate 5: evidence-paralysis regression ---------------------------
    cand_par = num(candidate, "evidence_paralysis_count")
    base_par = num(baseline, "evidence_paralysis_count")
    if cand_par is not None and base_par is not None:
        allowed = float(cfg["max_paralysis_increase"])
        ok = cand_par <= base_par + allowed
        checks.append({"name": "evidence_paralysis", "pass": ok,
                       "observed": cand_par, "baseline": base_par,
                       "required": f"<={base_par + allowed}"})
        if not ok:
            reasons.append(f"Evidence-paralysis count rose from {base_par:.0f} "
                           f"to {cand_par:.0f}.")

    # --- gate 6: output reliability --------------------------------------
    cand_out = output_failures(candidate)
    base_out = output_failures(baseline)
    if cand_out is not None and base_out is not None:
        allowed = float(cfg["max_output_failure_increase"])
        ok = cand_out <= base_out + allowed
        checks.append({"name": "output_reliability", "pass": ok,
                       "observed": cand_out, "baseline": base_out,
                       "required": f"<={base_out + allowed}"})
        if not ok:
            reasons.append(f"Non-successful outputs rose from {base_out:.0f} "
                           f"to {cand_out:.0f}.")
    elif cand_out is not None:
        ok = cand_out <= float(cfg["max_output_failure_increase"])
        checks.append({"name": "output_reliability", "pass": ok,
                       "observed": cand_out,
                       "required": f"<={float(cfg['max_output_failure_increase']):.0f}"})
        if not ok:
            reasons.append(f"Candidate produced {cand_out:.0f} non-successful outputs.")

    # --- gate 7: scoring coverage ----------------------------------------
    cand_scored = num(candidate, "lyralink_scored")
    if cand_scored is None:
        cand_scored = num(candidate, "successful_outputs")
    base_scored = num(baseline, "lyralink_scored")
    if base_scored is None:
        base_scored = num(baseline, "successful_outputs")
    if cand_scored is not None and base_scored:
        ratio = cand_scored / base_scored
        ok = ratio >= float(cfg["min_coverage_ratio"])
        checks.append({"name": "scoring_coverage", "pass": ok,
                       "observed": round(ratio, 4),
                       "baseline": base_scored,
                       "required": f">={float(cfg['min_coverage_ratio'])}"})
        if not ok:
            reasons.append(f"Candidate scored only {cand_scored:.0f} tasks vs "
                           f"baseline {base_scored:.0f} - not a comparable run.")

    # --- per-category (advisory by default) ------------------------------
    enforce = bool(int(cfg["enforce_categories"]))
    cat_base = category_scores(baseline)
    cat_cand = category_scores(candidate)
    weights = baseline.get("category_weights")
    if not isinstance(weights, dict) or not weights:
        weights = candidate.get("category_weights")
    if not isinstance(weights, dict) or not weights:
        weights = breakdown_weights(candidate) or breakdown_weights(baseline)
    if not isinstance(weights, dict):
        weights = {}

    if not cat_base or not cat_cand:
        warnings.append("category_breakdown not parseable on one side; "
                        "per-category floors skipped.")
    else:
        tol = float(cfg["max_category_regression"])
        min_w = float(cfg["min_category_weight"])
        for category, base_value in sorted(cat_base.items()):
            if category not in cat_cand:
                warnings.append(f"category '{category}' missing from candidate.")
                continue
            try:
                weight = float(weights.get(category, 0) or 0)
            except (TypeError, ValueError):
                weight = 0.0
            if weight and weight < min_w:
                continue
            cand_value = cat_cand[category]
            cat_delta = round(cand_value - base_value, 4)
            ok = cat_delta >= -tol
            checks.append({"name": f"category:{category}", "pass": ok,
                           "observed": cat_delta, "baseline": base_value,
                           "candidate": cand_value,
                           "weight": weight,
                           "enforced": enforce,
                           "required": f">=-{tol}"})
            if not ok:
                msg = (f"category '{category}' regressed {abs(cat_delta):.2f} "
                       f"(weight {weight:g}, allowed {tol:.2f}).")
                if enforce:
                    reasons.append(msg)
                else:
                    warnings.append(msg)

    verdict = "PASS" if not reasons else "BLOCK"
    return {
        "verdict": verdict,
        "reasons": reasons,
        "warnings": warnings,
        "checks": checks,
        "metrics": {
            "candidate_score": round(cand_total, 4),
            "baseline_score": round(base_total, 4) if base_total is not None else None,
            "delta": delta,
        },
    }


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Lyralink fine-tune eval gate")
    parser.add_argument("--baseline", required=True, help="Baseline summary JSON path")
    parser.add_argument("--candidate", required=True, help="Candidate summary JSON path")
    parser.add_argument("--report", default=None, help="Where to write the gate report JSON")
    parser.add_argument("--min-weighted", type=float,
                        default=env_default("min_weighted", DEFAULTS["min_weighted"]))
    parser.add_argument("--max-regression", type=float,
                        default=env_default("max_regression", DEFAULTS["max_regression"]))
    parser.add_argument("--max-critical-increase", type=int,
                        default=env_default("max_critical_increase",
                                            DEFAULTS["max_critical_increase"]))
    parser.add_argument("--max-critical-absolute", type=int,
                        default=env_default("max_critical_absolute",
                                            DEFAULTS["max_critical_absolute"]))
    parser.add_argument("--max-paralysis-increase", type=int,
                        default=env_default("max_paralysis_increase",
                                            DEFAULTS["max_paralysis_increase"]))
    parser.add_argument("--max-output-failure-increase", type=int,
                        default=env_default("max_output_failure_increase",
                                            DEFAULTS["max_output_failure_increase"]))
    parser.add_argument("--min-coverage-ratio", type=float,
                        default=env_default("min_coverage_ratio",
                                            DEFAULTS["min_coverage_ratio"]))
    parser.add_argument("--enforce-categories", type=int,
                        default=env_default("enforce_categories",
                                            DEFAULTS["enforce_categories"]))
    parser.add_argument("--max-category-regression", type=float,
                        default=env_default("max_category_regression",
                                            DEFAULTS["max_category_regression"]))
    parser.add_argument("--min-category-weight", type=float,
                        default=env_default("min_category_weight",
                                            DEFAULTS["min_category_weight"]))
    parser.add_argument("--quiet", action="store_true", help="Suppress human-readable output")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(list(sys.argv[1:] if argv is None else argv))

    cfg = {
        "min_weighted": args.min_weighted,
        "max_regression": args.max_regression,
        "max_critical_increase": args.max_critical_increase,
        "max_critical_absolute": args.max_critical_absolute,
        "max_paralysis_increase": args.max_paralysis_increase,
        "max_output_failure_increase": args.max_output_failure_increase,
        "min_coverage_ratio": args.min_coverage_ratio,
        "enforce_categories": args.enforce_categories,
        "max_category_regression": args.max_category_regression,
        "min_category_weight": args.min_category_weight,
    }

    try:
        baseline = load_json(args.baseline)
        candidate = load_json(args.candidate)
    except (OSError, ValueError) as exc:
        report = {"verdict": "ERROR", "reasons": [f"input error: {exc}"], "checks": []}
        report["evaluated_at"] = datetime.now(timezone.utc).isoformat()
        if args.report:
            with open(args.report, "w", encoding="utf-8") as handle:
                json.dump(report, handle, indent=2, sort_keys=True)
        if not args.quiet:
            print(f"[eval-gate] ERROR: {exc}", file=sys.stderr)
        return 2

    result = build_report(baseline, candidate, cfg)
    result["evaluated_at"] = datetime.now(timezone.utc).isoformat()
    result["baseline_path"] = os.path.abspath(args.baseline)
    result["candidate_path"] = os.path.abspath(args.candidate)
    result["baseline_run_id"] = baseline.get("run_id")
    result["candidate_run_id"] = candidate.get("run_id")
    result["config"] = cfg

    if args.report:
        try:
            with open(args.report, "w", encoding="utf-8") as handle:
                json.dump(result, handle, indent=2, sort_keys=True)
        except OSError as exc:
            print(f"[eval-gate] could not write report: {exc}", file=sys.stderr)

    if not args.quiet:
        metrics = result.get("metrics", {})
        for check in result.get("checks", []):
            mark = "pass" if check["pass"] else "FAIL"
            extra = ""
            if "observed" in check and "required" in check:
                extra = f" observed={check['observed']} required={check['required']}"
            print(f"[eval-gate] {mark:4} {check['name']}{extra}")
        for warning in result.get("warnings", []):
            print(f"[eval-gate] warn {warning}")
        for reason in result.get("reasons", []):
            print(f"[eval-gate] BLOCK {reason}", file=sys.stderr)
        print(f"[eval-gate] verdict={result['verdict']} "
              f"candidate={metrics.get('candidate_score')} "
              f"baseline={metrics.get('baseline_score')} "
              f"delta={metrics.get('delta')}")

    if result["verdict"] == "ERROR":
        return 2
    return 0 if result["verdict"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
