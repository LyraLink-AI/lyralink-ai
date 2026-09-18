#!/usr/bin/env bash
set -euo pipefail

now_utc() {
  date -u +"%Y-%m-%dT%H:%M:%SZ"
}

log() {
  echo "[$(now_utc)] auto_finetune_rollback $*"
}

ROOT_DIR="${LYRALINK_WORKSPACE_ROOT:-/var/www/vhosts/lyralinkai.com/httpdocs}"
ENV_FILE="${ROOT_DIR}/.env"
ARTIFACT_DIR="${LYRALINK_OUTPUT_DIR:-${ROOT_DIR}/storage/model_training/artifacts}"
BACKUP_FILE="${ARTIFACT_DIR}/active_model_backup.env"

if [[ ! -f "${ENV_FILE}" ]]; then
  log "status=failed reason=env_file_missing"
  exit 1
fi

if [[ ! -f "${BACKUP_FILE}" ]]; then
  log "status=skipped reason=no_backup_file"
  exit 0
fi

backup_local="$(grep -E '^LOCAL_LLM_MODEL=' "${BACKUP_FILE}" | head -n 1 | cut -d= -f2- || true)"
backup_llm="$(grep -E '^LLM_MODEL=' "${BACKUP_FILE}" | head -n 1 | cut -d= -f2- || true)"
backup_router_default="$(grep -E '^MODEL_ROUTER_DEFAULT=' "${BACKUP_FILE}" | head -n 1 | cut -d= -f2- || true)"
backup_router_fast="$(grep -E '^MODEL_ROUTER_FAST=' "${BACKUP_FILE}" | head -n 1 | cut -d= -f2- || true)"
backup_router_code="$(grep -E '^MODEL_ROUTER_CODE=' "${BACKUP_FILE}" | head -n 1 | cut -d= -f2- || true)"
backup_router_reasoning="$(grep -E '^MODEL_ROUTER_REASONING=' "${BACKUP_FILE}" | head -n 1 | cut -d= -f2- || true)"
backup_router_creative="$(grep -E '^MODEL_ROUTER_CREATIVE=' "${BACKUP_FILE}" | head -n 1 | cut -d= -f2- || true)"
backup_router_fallback="$(grep -E '^MODEL_ROUTER_FALLBACK=' "${BACKUP_FILE}" | head -n 1 | cut -d= -f2- || true)"
backup_local_models="$(grep -E '^LLM_LOCAL_MODELS=' "${BACKUP_FILE}" | head -n 1 | cut -d= -f2- || true)"

set_env_key() {
  local key="$1"
  local value="$2"
  if grep -q "^${key}=" "${ENV_FILE}"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "${ENV_FILE}"
  else
    echo "${key}=${value}" >> "${ENV_FILE}"
  fi
}

if [[ "${backup_local}" != "" ]]; then
  if grep -q '^LOCAL_LLM_MODEL=' "${ENV_FILE}"; then
    sed -i "s|^LOCAL_LLM_MODEL=.*|LOCAL_LLM_MODEL=${backup_local}|" "${ENV_FILE}"
  else
    echo "LOCAL_LLM_MODEL=${backup_local}" >> "${ENV_FILE}"
  fi
fi

if [[ "${backup_llm}" != "" ]]; then
  set_env_key "LLM_MODEL" "${backup_llm}"
fi

if [[ "${backup_router_default}" != "" ]]; then
  set_env_key "MODEL_ROUTER_DEFAULT" "${backup_router_default}"
fi
if [[ "${backup_router_fast}" != "" ]]; then
  set_env_key "MODEL_ROUTER_FAST" "${backup_router_fast}"
fi
if [[ "${backup_router_code}" != "" ]]; then
  set_env_key "MODEL_ROUTER_CODE" "${backup_router_code}"
fi
if [[ "${backup_router_reasoning}" != "" ]]; then
  set_env_key "MODEL_ROUTER_REASONING" "${backup_router_reasoning}"
fi
if [[ "${backup_router_creative}" != "" ]]; then
  set_env_key "MODEL_ROUTER_CREATIVE" "${backup_router_creative}"
fi
if [[ "${backup_router_fallback}" != "" ]]; then
  set_env_key "MODEL_ROUTER_FALLBACK" "${backup_router_fallback}"
fi
if [[ "${backup_local_models}" != "" ]]; then
  set_env_key "LLM_LOCAL_MODELS" "${backup_local_models}"
fi

log "status=complete restored_local_model=${backup_local:-unknown} restored_llm_model=${backup_llm:-unknown}"
