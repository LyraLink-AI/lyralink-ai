#!/usr/bin/env bash
# Lyralink fine-tune EVAL GATE.
#
# Sits between training and activation. A candidate model is only allowed to be
# promoted if its benchmark result clears the baseline. This replaces the old
# behaviour where any completed training run was treated as a success and
# activated unconditionally.
#
# Wired in via CONTINUOUS_FINETUNE_VALIDATE_COMMAND.
#
#   auto_finetune_eval_gate.sh                     run benchmark, then gate
#   auto_finetune_eval_gate.sh --establish-baseline  freeze current scores as baseline
#   auto_finetune_eval_gate.sh --skip-run            gate against the newest existing summary
#
# Exit codes
#   0  PASS   promotion allowed
#   1  BLOCK  promotion denied
#   2  ERROR  gate could not run (missing tooling / baseline / summary)
set -euo pipefail

now_utc() { date -u +"%Y-%m-%dT%H:%M:%SZ"; }
log() { echo "[$(now_utc)] eval_gate $*"; }
die() { log "ERROR: $*"; exit 2; }

ROOT_DIR="${LYRALINK_WORKSPACE_ROOT:-/var/www/vhosts/lyralinkai.com/httpdocs}"
BENCH_DIR="${ROOT_DIR}/benchmark"
# Matches run_lyralink_benchmark.py / score_benchmark.py storage resolution.
STORAGE_DIR="${BENCHMARK_STORAGE_DIR:-$(cd "${BENCH_DIR}/../.." 2>/dev/null && pwd)/benchmark_private}"
SUMMARY_PATH="${EVAL_GATE_SUMMARY:-${STORAGE_DIR}/scoring/BENCHMARK_SUMMARY_V2.json}"

GATE_DIR="${ROOT_DIR}/storage/model_training/eval_gate"
REPORT_DIR="${GATE_DIR}/reports"
BASELINE_PATH="${EVAL_GATE_BASELINE:-${GATE_DIR}/baseline.json}"
LOG_DIR="${ROOT_DIR}/storage/logs"
LOCK_FILE="${GATE_DIR}/.lock"

PYTHON_BIN="${EVAL_GATE_PYTHON:-$(command -v python3 || true)}"
COMPARE_BIN="${ROOT_DIR}/scripts/eval_gate_compare.py"

TASK_LIMIT="${EVAL_GATE_TASK_LIMIT:-100}"
REQ_TIMEOUT="${EVAL_GATE_REQUEST_TIMEOUT:-180}"
PROVIDER="${BENCHMARK_PROVIDER:-local}"

MODE="gate"
for arg in "$@"; do
  case "$arg" in
    --establish-baseline) MODE="baseline" ;;
    --skip-run)           MODE="skip" ;;
    -h|--help)
      sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) die "unknown argument: $arg" ;;
  esac
done

mkdir -p "$GATE_DIR" "$REPORT_DIR" "$LOG_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
REPORT_PATH="${REPORT_DIR}/${STAMP}.json"
RUN_LOG="${LOG_DIR}/eval_gate_${STAMP}.log"

# ---------------------------------------------------------------- preflight
[[ -x "$PYTHON_BIN" ]] || die "python3 not found (set EVAL_GATE_PYTHON)"
[[ -f "$COMPARE_BIN" ]] || die "missing comparator: $COMPARE_BIN"
[[ -f "${BENCH_DIR}/run_lyralink_benchmark.py" ]] || die "missing benchmark runner"
[[ -f "${BENCH_DIR}/score_benchmark.py" ]] || die "missing benchmark scorer"

# Serialise gate runs: two concurrent benchmark runs would corrupt the manifest.
#
# A crashed run must not block promotions forever. Refusing outright on any
# existing lock would turn one interruption into a permanent manual-intervention
# requirement, which is the opposite of what an unattended pipeline needs. The
# lock records its owner and is reclaimed only when that owner is provably gone.
LOCK_STALE_SECONDS="${EVAL_GATE_LOCK_STALE_SECONDS:-1800}"
LOCK_YOUNG_SECONDS=30

acquire_lock() {
  if mkdir "${LOCK_FILE}" 2>/dev/null; then
    printf '%s' "$$" > "${LOCK_FILE}/pid"
    date -u +%s > "${LOCK_FILE}/at"
    return 0
  fi

  local owner="" age=999999 now started dir_mtime
  [[ -f "${LOCK_FILE}/pid" ]] && owner="$(cat "${LOCK_FILE}/pid" 2>/dev/null || true)"
  now="$(date -u +%s)"
  if [[ -f "${LOCK_FILE}/at" ]]; then
    started="$(cat "${LOCK_FILE}/at" 2>/dev/null || true)"
    if [[ "$started" =~ ^[0-9]+$ ]]; then age=$(( now - started )); fi
  else
    # No timestamp yet: fall back to the directory's own mtime, otherwise a lock
    # that was created microseconds ago would look infinitely old and get stolen.
    dir_mtime="$(stat -c %Y "${LOCK_FILE}" 2>/dev/null || true)"
    if [[ "$dir_mtime" =~ ^[0-9]+$ ]]; then age=$(( now - dir_mtime )); fi
  fi

  # A live owner always wins, however old the lock is.
  if [[ -n "$owner" ]] && kill -0 "$owner" 2>/dev/null; then
    log "lock held by live pid ${owner} (age ${age}s)"
    return 1
  fi

  # No owner recorded: the acquirer may be mid-write, so only reclaim once the
  # lock is clearly abandoned.
  if [[ -z "$owner" ]] && [[ "$age" -lt "$LOCK_YOUNG_SECONDS" ]]; then
    log "lock has no owner yet (age ${age}s); assuming acquisition in progress"
    return 1
  fi

  if [[ -n "$owner" ]] && [[ "$age" -lt "$LOCK_STALE_SECONDS" ]]; then
    log "lock pid ${owner} is gone but the lock is recent (age ${age}s); not stealing"
    return 1
  fi

  log "WARN: reclaiming abandoned lock owner='${owner}' age=${age}s"
  rm -rf "${LOCK_FILE}" 2>/dev/null || true
  if mkdir "${LOCK_FILE}" 2>/dev/null; then
    printf '%s' "$$" > "${LOCK_FILE}/pid"
    date -u +%s > "${LOCK_FILE}/at"
    return 0
  fi
  return 1
}

if ! acquire_lock; then
  die "another eval gate run holds ${LOCK_FILE}"
fi
cleanup() { rm -rf "${LOCK_FILE}" 2>/dev/null || true; }
trap cleanup EXIT

log "mode=${MODE} storage=${STORAGE_DIR} summary=${SUMMARY_PATH}"

# ------------------------------------------------------- establish baseline
if [[ "$MODE" == "baseline" ]]; then
  [[ -f "$SUMMARY_PATH" ]] || die "no summary at ${SUMMARY_PATH}; run the benchmark first"
  cp -f "$SUMMARY_PATH" "$BASELINE_PATH"
  cp -f "$SUMMARY_PATH" "${GATE_DIR}/baseline.${STAMP}.json"
  log "baseline established from $(basename "$SUMMARY_PATH") -> ${BASELINE_PATH}"
  "$PYTHON_BIN" - "$BASELINE_PATH" <<'PY'
import json, sys
d = json.load(open(sys.argv[1], encoding="utf-8"))
print(f"[{__import__('datetime').datetime.now(__import__('datetime').timezone.utc).isoformat()}] "
      f"eval_gate baseline run_id={d.get('run_id')} "
      f"weighted_total={d.get('weighted_total')} "
      f"critical_failures={d.get('critical_failures')}")
PY
  exit 0
fi

[[ -f "$BASELINE_PATH" ]] || die "no baseline at ${BASELINE_PATH}; run --establish-baseline first"

# ------------------------------------------------------------ run benchmark
if [[ "$MODE" == "gate" ]]; then
  log "running benchmark limit=${TASK_LIMIT} timeout=${REQ_TIMEOUT} provider=${PROVIDER}"
  if ! ( cd "$BENCH_DIR" && BENCHMARK_PROVIDER="$PROVIDER" \
         "$PYTHON_BIN" ./run_lyralink_benchmark.py \
           --limit "$TASK_LIMIT" --timeout "$REQ_TIMEOUT" ) >> "$RUN_LOG" 2>&1; then
    log "benchmark runner failed; see ${RUN_LOG}"
    "$PYTHON_BIN" - "$REPORT_PATH" <<'PY' || true
import json, sys
from datetime import datetime, timezone
json.dump({"verdict": "ERROR",
           "reasons": ["benchmark runner failed; see run log"],
           "checks": [],
           "evaluated_at": datetime.now(timezone.utc).isoformat()},
          open(sys.argv[1], "w", encoding="utf-8"), indent=2, sort_keys=True)
PY
    exit 2
  fi
  log "scoring"
  if ! ( cd "$BENCH_DIR" && "$PYTHON_BIN" ./score_benchmark.py ) >> "$RUN_LOG" 2>&1; then
    log "scorer failed; see ${RUN_LOG}"
    exit 2
  fi
fi

[[ -f "$SUMMARY_PATH" ]] || die "no candidate summary at ${SUMMARY_PATH}"

# ------------------------------------------------------------------- gate
log "comparing candidate vs baseline"
set +e
"$PYTHON_BIN" "$COMPARE_BIN" \
  --baseline "$BASELINE_PATH" \
  --candidate "$SUMMARY_PATH" \
  --report "$REPORT_PATH" 2>&1 | tee -a "$RUN_LOG"
rc=${PIPESTATUS[0]}
set -e

case "$rc" in
  0) log "verdict=PASS report=${REPORT_PATH}" ;;
  1) log "verdict=BLOCK (promotion denied) report=${REPORT_PATH}" ;;
  *) log "verdict=ERROR report=${REPORT_PATH}" ;;
esac
exit "$rc"
