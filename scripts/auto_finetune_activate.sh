#!/usr/bin/env bash
set -euo pipefail

now_utc() {
  date -u +"%Y-%m-%dT%H:%M:%SZ"
}

log() {
  echo "[$(now_utc)] auto_finetune_activate $*"
}

ROOT_DIR="${LYRALINK_WORKSPACE_ROOT:-/var/www/vhosts/lyralinkai.com/httpdocs}"
ENV_FILE="${ROOT_DIR}/.env"
ARTIFACT_DIR="${LYRALINK_OUTPUT_DIR:-${ROOT_DIR}/storage/model_training/artifacts}"
BACKUP_FILE="${ARTIFACT_DIR}/active_model_backup.env"
CANDIDATE_FILE="${ARTIFACT_DIR}/candidate_model.txt"
CANDIDATE_MODELS_FILE="${ARTIFACT_DIR}/candidate_models.env"

mkdir -p "${ARTIFACT_DIR}"

if [[ ! -f "${ENV_FILE}" ]]; then
  log "status=failed reason=env_file_missing path=${ENV_FILE}"
  exit 1
fi

if [[ -f "${CANDIDATE_FILE}" ]]; then
  CANDIDATE_MODEL="$(tr -d '\r\n' < "${CANDIDATE_FILE}")"
else
  CANDIDATE_MODEL="${CONTINUOUS_FINETUNE_OLLAMA_CANARY_MODEL:-lyralink-auto-canary:latest}"
fi

if [[ "${CANDIDATE_MODEL}" == "" ]]; then
  log "status=failed reason=empty_candidate_model"
  exit 1
fi

if command -v ollama >/dev/null 2>&1; then
  if ! ollama show "${CANDIDATE_MODEL}" >/dev/null 2>&1; then
    log "status=failed reason=candidate_model_missing model=${CANDIDATE_MODEL}"
    exit 1
  fi
fi

current_local="$(grep -E '^LOCAL_LLM_MODEL=' "${ENV_FILE}" | head -n 1 | cut -d= -f2- || true)"
current_llm="$(grep -E '^LLM_MODEL=' "${ENV_FILE}" | head -n 1 | cut -d= -f2- || true)"
current_router_default="$(grep -E '^MODEL_ROUTER_DEFAULT=' "${ENV_FILE}" | head -n 1 | cut -d= -f2- || true)"
current_router_fast="$(grep -E '^MODEL_ROUTER_FAST=' "${ENV_FILE}" | head -n 1 | cut -d= -f2- || true)"
current_router_code="$(grep -E '^MODEL_ROUTER_CODE=' "${ENV_FILE}" | head -n 1 | cut -d= -f2- || true)"
current_router_reasoning="$(grep -E '^MODEL_ROUTER_REASONING=' "${ENV_FILE}" | head -n 1 | cut -d= -f2- || true)"
current_router_creative="$(grep -E '^MODEL_ROUTER_CREATIVE=' "${ENV_FILE}" | head -n 1 | cut -d= -f2- || true)"
current_router_fallback="$(grep -E '^MODEL_ROUTER_FALLBACK=' "${ENV_FILE}" | head -n 1 | cut -d= -f2- || true)"
current_llm_local_models="$(grep -E '^LLM_LOCAL_MODELS=' "${ENV_FILE}" | head -n 1 | cut -d= -f2- || true)"

cat > "${BACKUP_FILE}" <<EOF
LOCAL_LLM_MODEL=${current_local}
LLM_MODEL=${current_llm}
MODEL_ROUTER_DEFAULT=${current_router_default}
MODEL_ROUTER_FAST=${current_router_fast}
MODEL_ROUTER_CODE=${current_router_code}
MODEL_ROUTER_REASONING=${current_router_reasoning}
MODEL_ROUTER_CREATIVE=${current_router_creative}
MODEL_ROUTER_FALLBACK=${current_router_fallback}
LLM_LOCAL_MODELS=${current_llm_local_models}
EOF

router_default="${CANDIDATE_MODEL}"
router_fast="${CANDIDATE_MODEL}"
router_code="${CANDIDATE_MODEL}"
router_reasoning="${CANDIDATE_MODEL}"
router_creative="${CANDIDATE_MODEL}"
router_fallback="${CONTINUOUS_FINETUNE_HERMES_FALLBACK_MODEL:-hermes3:3b}"
if [[ -f "${CANDIDATE_MODELS_FILE}" ]]; then
  # shellcheck disable=SC1090
  source "${CANDIDATE_MODELS_FILE}"
  router_default="${MODEL_ROUTER_DEFAULT:-$router_default}"
  router_fast="${MODEL_ROUTER_FAST:-$router_fast}"
  router_code="${MODEL_ROUTER_CODE:-$router_code}"
  router_reasoning="${MODEL_ROUTER_REASONING:-$router_reasoning}"
  router_creative="${MODEL_ROUTER_CREATIVE:-$router_creative}"
  router_fallback="${MODEL_ROUTER_FALLBACK:-$router_fallback}"
fi

if grep -q '^LOCAL_LLM_MODEL=' "${ENV_FILE}"; then
  sed -i "s|^LOCAL_LLM_MODEL=.*|LOCAL_LLM_MODEL=${CANDIDATE_MODEL}|" "${ENV_FILE}"
else
  echo "LOCAL_LLM_MODEL=${CANDIDATE_MODEL}" >> "${ENV_FILE}"
fi

if grep -q '^LLM_MODEL=' "${ENV_FILE}"; then
  sed -i "s|^LLM_MODEL=.*|LLM_MODEL=${CANDIDATE_MODEL}|" "${ENV_FILE}"
else
  echo "LLM_MODEL=${CANDIDATE_MODEL}" >> "${ENV_FILE}"
fi

if grep -q '^LLM_PROVIDER=' "${ENV_FILE}"; then
  sed -i "s|^LLM_PROVIDER=.*|LLM_PROVIDER=local|" "${ENV_FILE}"
else
  echo "LLM_PROVIDER=local" >> "${ENV_FILE}"
fi

set_env_key() {
  local key="$1"
  local value="$2"
  if grep -q "^${key}=" "${ENV_FILE}"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "${ENV_FILE}"
  else
    echo "${key}=${value}" >> "${ENV_FILE}"
  fi
}

set_env_key "MODEL_ROUTER_DEFAULT" "${router_default}"
set_env_key "MODEL_ROUTER_FAST" "${router_fast}"
set_env_key "MODEL_ROUTER_CODE" "${router_code}"
set_env_key "MODEL_ROUTER_REASONING" "${router_reasoning}"
set_env_key "MODEL_ROUTER_CREATIVE" "${router_creative}"
set_env_key "MODEL_ROUTER_FALLBACK" "${router_fallback}"
set_env_key "MODEL_ROUTER_ENABLED" "1"

merged_models="${router_default},${router_fast},${router_code},${router_reasoning},${router_creative},${router_fallback},hermes3:3b,hermes3:8b"
merged_models="$(echo "${merged_models}" | tr ',' '\n' | sed 's/^ *//;s/ *$//' | awk 'NF>0 && !seen[$0]++' | paste -sd ',' -)"
set_env_key "LLM_LOCAL_MODELS" "${merged_models}"

log "status=complete activated_model=${CANDIDATE_MODEL}"
