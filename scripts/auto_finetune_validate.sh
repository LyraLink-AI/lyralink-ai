#!/usr/bin/env bash
set -euo pipefail

now_utc() {
  date -u +"%Y-%m-%dT%H:%M:%SZ"
}

log() {
  echo "[$(now_utc)] auto_finetune_validate $*"
}

ROOT_DIR="${LYRALINK_WORKSPACE_ROOT:-/var/www/vhosts/lyralinkai.com/httpdocs}"
ARTIFACT_DIR="${LYRALINK_OUTPUT_DIR:-${ROOT_DIR}/storage/model_training/artifacts}"
CANDIDATE_FILE="${ARTIFACT_DIR}/candidate_model.txt"
CANDIDATE_MODEL="${CONTINUOUS_FINETUNE_OLLAMA_CANARY_MODEL:-lyralink-auto-canary:latest}"

if [[ -f "${CANDIDATE_FILE}" ]]; then
  CANDIDATE_MODEL="$(tr -d '\r\n' < "${CANDIDATE_FILE}")"
fi

if ! command -v ollama >/dev/null 2>&1; then
  log "status=skipped reason=ollama_not_installed"
  exit 0
fi

if ! ollama show "${CANDIDATE_MODEL}" >/dev/null 2>&1; then
  log "status=failed reason=candidate_model_missing model=${CANDIDATE_MODEL}"
  exit 1
fi

log "status=complete model=${CANDIDATE_MODEL}"
