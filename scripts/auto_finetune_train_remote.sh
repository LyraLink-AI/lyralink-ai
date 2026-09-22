#!/usr/bin/env bash
# Remote fine-tune backend: train on a Kaggle GPU, import the GGUF locally.
#
# Used as CONTINUOUS_FINETUNE_TRAIN_COMMAND when
# CONTINUOUS_FINETUNE_TRAIN_BACKEND=kaggle, replacing the local CPU trainer.
#
#   auto_finetune_train_remote.sh [--export-only] [--dry-run]
#
# Exit codes
#   0  ok, or legitimately skipped (no provenanced rows / backend unavailable)
#   1  training ran and failed
#   2  misconfiguration
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/lib/train_guard.sh"

ROOT_DIR="${LYRALINK_WORKSPACE_ROOT:-/var/www/vhosts/lyralinkai.com/httpdocs}"
MT_DIR="${ROOT_DIR}/storage/model_training"
EXPORT_FILE="${MT_DIR}/train_export.jsonl"
EXPORT_REPORT="${MT_DIR}/train_export.report.json"
KAGGLE_WORK="${MT_DIR}/kaggle"

VENV="${ROOT_DIR}/.venv-kaggle"
PYTHON="${LYRA_KAGGLE_PYTHON:-${VENV}/bin/python}"
EXPORT_TOOL="${ROOT_DIR}/scripts/kaggle_bundle_export.py"
RUNNER="${ROOT_DIR}/scripts/kaggle_train_runner.py"
IMPORT_TOOL="${ROOT_DIR}/scripts/import_gguf_candidate.sh"

MIN_ROWS="${LYRA_TRAIN_MIN_ROWS:-256}"
MIN_EVIDENCE="${LYRALINK_TRAIN_MIN_EVIDENCE:-E3}"

MODE="run"
for arg in "$@"; do
  case "$arg" in
    --export-only) MODE="export" ;;
    --dry-run)     MODE="dry" ;;
    -h|--help)     sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) lyra_log "ERROR: unknown argument: $arg"; exit 2 ;;
  esac
done

mkdir -p "$MT_DIR" "$KAGGLE_WORK"

# ---------------------------------------------------------------- preflight
if [[ ! -x "$PYTHON" ]]; then
  lyra_log "status=skipped reason=kaggle_venv_missing path=${VENV}"
  lyra_log "hint: python3 -m venv ${VENV} && ${VENV}/bin/pip install kaggle pymysql"
  exit 0
fi
[[ -f "$EXPORT_TOOL" ]] || { lyra_log "ERROR: missing ${EXPORT_TOOL}"; exit 2; }
[[ -f "$RUNNER" ]]     || { lyra_log "ERROR: missing ${RUNNER}"; exit 2; }

# ------------------------------------------------------- 1. export corpus
lyra_log "step=export status=start min_evidence=${MIN_EVIDENCE} out=${EXPORT_FILE}"
set +e
"$PYTHON" "$EXPORT_TOOL" \
  --out "$EXPORT_FILE" \
  --report "$EXPORT_REPORT" \
  --root "$ROOT_DIR" \
  --min-evidence "$MIN_EVIDENCE"
export_rc=$?
set -e

case "$export_rc" in
  0) : ;;
  1)
    lyra_log "status=skipped reason=no_provenanced_training_rows min_evidence=${MIN_EVIDENCE}"
    lyra_log "hint: rows only qualify once conversations persist evidence_level; see report ${EXPORT_REPORT}"
    exit 0
    ;;
  *) lyra_log "ERROR: export failed rc=${export_rc}"; exit 2 ;;
esac

lyra_require_rows "$EXPORT_FILE" "$MIN_ROWS" || {
  lyra_log "status=skipped reason=below_min_rows min=${MIN_ROWS}"
  exit 0
}

if [[ "$MODE" == "export" ]]; then
  lyra_log "status=complete mode=export rows=$(lyra_count_jsonl "$EXPORT_FILE")"
  exit 0
fi

# -------------------------------------------------------- 2. guard + train
GUARD_DRY=""
[[ "$MODE" == "dry" ]] && GUARD_DRY="--dry-run"

if [[ "$MODE" != "dry" ]]; then
  lyra_log "step=remote_train status=start"
fi

set +e
if [[ "$MODE" == "dry" ]]; then
  "$PYTHON" "$RUNNER" --root "$ROOT_DIR" \
    --dataset-file "$EXPORT_FILE" --work-dir "$KAGGLE_WORK" --dry-run
  runner_rc=$?
else
  "$PYTHON" "$RUNNER" --root "$ROOT_DIR" \
    --dataset-file "$EXPORT_FILE" --work-dir "$KAGGLE_WORK"
  runner_rc=$?
fi
set -e

case "$runner_rc" in
  0) : ;;
  2) lyra_log "status=skipped reason=runner_not_configured rc=2"; exit 0 ;;
  3) lyra_log "ERROR: remote training timed out"; exit 1 ;;
  *) lyra_log "ERROR: remote training failed rc=${runner_rc}"; exit 1 ;;
esac

if [[ "$MODE" == "dry" ]]; then
  lyra_log "status=complete mode=dry"
  exit 0
fi

# ------------------------------------------------------------- 3. import
GGUF="$(find "${KAGGLE_WORK}/output" -maxdepth 1 -name '*.gguf' -type f -printf '%s %p\n' 2>/dev/null \
        | sort -rn | head -1 | cut -d' ' -f2-)"
if [[ -z "${GGUF:-}" || ! -f "$GGUF" ]]; then
  lyra_log "ERROR: no gguf found in ${KAGGLE_WORK}/output"
  exit 1
fi

lyra_log "step=import status=start gguf=$(basename "$GGUF")"
if ! bash "$IMPORT_TOOL" --gguf "$GGUF" --base "${CONTINUOUS_FINETUNE_OLLAMA_BASE_MODEL:-hermes3:3b}"; then
  lyra_log "ERROR: import failed"
  exit 1
fi

lyra_log "status=complete backend=kaggle candidate=${CONTINUOUS_FINETUNE_OLLAMA_CANARY_MODEL:-lyralink-auto-canary:latest}"
exit 0
