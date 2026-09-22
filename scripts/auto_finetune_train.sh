#!/usr/bin/env bash
set -euo pipefail

now_utc() {
  date -u +"%Y-%m-%dT%H:%M:%SZ"
}

log() {
  echo "[$(now_utc)] auto_finetune_train $*"
}

ROOT_DIR="${LYRALINK_WORKSPACE_ROOT:-/var/www/vhosts/lyralinkai.com/httpdocs}"
ARTIFACT_DIR="${LYRALINK_OUTPUT_DIR:-${ROOT_DIR}/storage/model_training/artifacts}"
TRAIN_FILE="${LYRALINK_TRAIN_FILE:-${ROOT_DIR}/storage/model_training/latest_dataset.jsonl}"
INCREMENTAL_FILE="${LYRALINK_INCREMENTAL_FILE:-${ROOT_DIR}/storage/model_training/latest_incremental.jsonl}"
BASE_MODEL="${CONTINUOUS_FINETUNE_OLLAMA_BASE_MODEL:-${LOCAL_LLM_MODEL:-hermes3:3b}}"
CANARY_MODEL="${CONTINUOUS_FINETUNE_OLLAMA_CANARY_MODEL:-lyralink-auto-canary:latest}"
TRAIN_MODE="${CONTINUOUS_FINETUNE_TRAIN_MODE:-auto}"
ALLOW_FALLBACK="${CONTINUOUS_FINETUNE_ALLOW_FALLBACK_MODEL_BUILD:-1}"
HF_BASE_MODEL="${CONTINUOUS_FINETUNE_HF_BASE_MODEL:-}"
LORA_OUTPUT_DIR="${ARTIFACT_DIR}/lora_adapter"
LORA_MAX_ROWS="${CONTINUOUS_FINETUNE_LORA_MAX_ROWS:-2500}"
LORA_MAX_STEPS="${CONTINUOUS_FINETUNE_LORA_MAX_STEPS:-30}"
LORA_LEARNING_RATE="${CONTINUOUS_FINETUNE_LORA_LR:-0.0002}"
LORA_BATCH_SIZE="${CONTINUOUS_FINETUNE_LORA_BATCH_SIZE:-1}"
LORA_GRAD_ACCUM="${CONTINUOUS_FINETUNE_LORA_GRAD_ACCUM:-8}"
LORA_MAX_LENGTH="${CONTINUOUS_FINETUNE_LORA_MAX_LENGTH:-256}"
LORA_TIMEOUT="${CONTINUOUS_FINETUNE_LORA_TIMEOUT:-3600}"
LORA_ONLY="${CONTINUOUS_FINETUNE_LORA_ONLY:-0}"

# ---------------------------------------------------------------------------
# Guard library and backend selection.
# ---------------------------------------------------------------------------
if [[ -f "${ROOT_DIR}/scripts/lib/train_guard.sh" ]]; then
  # shellcheck source=/dev/null
  source "${ROOT_DIR}/scripts/lib/train_guard.sh"
fi

# "kaggle" delegates the entire training stage to a free GPU host. This box has
# no GPU and cannot fit a 3B LoRA into its available RAM alongside Ollama.
TRAIN_BACKEND="${CONTINUOUS_FINETUNE_TRAIN_BACKEND:-local}"
if [[ "${TRAIN_BACKEND}" == "kaggle" ]]; then
  log "step=backend status=kaggle script=auto_finetune_train_remote.sh"
  exec bash "${ROOT_DIR}/scripts/auto_finetune_train_remote.sh"
fi

LORA_SCRIPT="${ROOT_DIR}/scripts/train_lora_adapter.py"
LORA_PYTHON_BIN="${CONTINUOUS_FINETUNE_PYTHON_BIN:-}"
LORA_AUTO_BACKOFF="${CONTINUOUS_FINETUNE_LORA_AUTO_BACKOFF:-1}"
LORA_MIN_ROWS="${CONTINUOUS_FINETUNE_LORA_MIN_ROWS:-128}"
LORA_MIN_STEPS="${CONTINUOUS_FINETUNE_LORA_MIN_STEPS:-4}"
LORA_MIN_LENGTH="${CONTINUOUS_FINETUNE_LORA_MIN_LENGTH:-64}"
FAST_MODEL="${CONTINUOUS_FINETUNE_FAST_MODEL:-lyralink-fast:latest}"
CODE_MODEL="${CONTINUOUS_FINETUNE_CODE_MODEL:-lyralink-code:latest}"
REASONING_MODEL="${CONTINUOUS_FINETUNE_REASONING_MODEL:-lyralink-reasoning:latest}"
CREATIVE_MODEL="${CONTINUOUS_FINETUNE_CREATIVE_MODEL:-lyralink-creative:latest}"
ENABLE_CONTEXT_MODELS="${CONTINUOUS_FINETUNE_ENABLE_CONTEXT_MODELS:-1}"
HERMES_FALLBACK_MODEL="${CONTINUOUS_FINETUNE_HERMES_FALLBACK_MODEL:-hermes3:3b}"
REMOTE_SYNC_ENABLED="${CONTINUOUS_FINETUNE_REMOTE_SYNC:-1}"
REMOTE_SYNC_HOST="${CONTINUOUS_FINETUNE_REMOTE_SYNC_HOST:-${REMOTE_LLM_HOST:-}}"
REMOTE_SYNC_PORT="${CONTINUOUS_FINETUNE_REMOTE_SYNC_PORT:-${REMOTE_LLM_PORT:-11434}}"
REMOTE_SYNC_BASE_MODEL="${CONTINUOUS_FINETUNE_REMOTE_SYNC_BASE_MODEL:-${REMOTE_LLM_MODEL:-${BASE_MODEL:-hermes3:3b}}}"
REMOTE_SYNC_STRICT="${CONTINUOUS_FINETUNE_REMOTE_SYNC_STRICT:-0}"
LORA_PROFILE_STATE_FILE="${ARTIFACT_DIR}/lora_runtime_profile.env"

if [[ "${LORA_PYTHON_BIN}" == "" && -x "${ROOT_DIR}/.venv-finetune/bin/python" ]]; then
  LORA_PYTHON_BIN="${ROOT_DIR}/.venv-finetune/bin/python"
fi

if [[ "${LORA_PYTHON_BIN}" == "" ]]; then
  LORA_PYTHON_BIN="python3"
fi

mkdir -p "${ARTIFACT_DIR}"

clamp_min() {
  local value="$1"
  local min="$2"
  if [[ "${value}" -lt "${min}" ]]; then
    echo "${min}"
  else
    echo "${value}"
  fi
}

save_lora_profile_state() {
  local reason="$1"
  cat > "${LORA_PROFILE_STATE_FILE}" <<EOF
LORA_MAX_ROWS=${LORA_MAX_ROWS}
LORA_MAX_STEPS=${LORA_MAX_STEPS}
LORA_GRAD_ACCUM=${LORA_GRAD_ACCUM}
LORA_MAX_LENGTH=${LORA_MAX_LENGTH}
UPDATED_AT=$(now_utc)
REASON=${reason}
EOF
}

if [[ -f "${LORA_PROFILE_STATE_FILE}" ]]; then
  # shellcheck disable=SC1090
  source "${LORA_PROFILE_STATE_FILE}" || true
  LORA_MAX_ROWS="${LORA_MAX_ROWS:-${CONTINUOUS_FINETUNE_LORA_MAX_ROWS:-2500}}"
  LORA_MAX_STEPS="${LORA_MAX_STEPS:-${CONTINUOUS_FINETUNE_LORA_MAX_STEPS:-30}}"
  LORA_GRAD_ACCUM="${LORA_GRAD_ACCUM:-${CONTINUOUS_FINETUNE_LORA_GRAD_ACCUM:-8}}"
  LORA_MAX_LENGTH="${LORA_MAX_LENGTH:-${CONTINUOUS_FINETUNE_LORA_MAX_LENGTH:-256}}"
  log "step=lora_backoff_profile status=loaded rows=${LORA_MAX_ROWS} steps=${LORA_MAX_STEPS} grad_accum=${LORA_GRAD_ACCUM} max_length=${LORA_MAX_LENGTH}"
fi

if [[ ! -f "${TRAIN_FILE}" ]]; then
  log "status=skipped reason=train_file_missing path=${TRAIN_FILE}"
  exit 0
fi

if ! command -v ollama >/dev/null 2>&1; then
  log "status=skipped reason=ollama_not_installed"
  exit 0
fi

if ! ollama show "${BASE_MODEL}" >/dev/null 2>&1; then
  log "status=skipped reason=base_model_missing base_model=${BASE_MODEL}"
  exit 0
fi

sample_path="${ARTIFACT_DIR}/learning_sample.txt"
if [[ -f "${INCREMENTAL_FILE}" ]]; then
  head -n 12 "${INCREMENTAL_FILE}" > "${sample_path}" || true
else
  head -n 12 "${TRAIN_FILE}" > "${sample_path}" || true
fi

lora_attempted=0
lora_ok=0
# Refuse a local run this host cannot complete. A refused run is a skip, not a
# failure: the pipeline should stop cleanly rather than start doomed work.
if [[ -n "${HF_BASE_MODEL}" ]] && ! lyra_cpu_train_guard "${HF_BASE_MODEL}"; then
  log "step=lora_train status=skipped reason=cpu_guard_refused base_model=${HF_BASE_MODEL}"
  if [[ "${TRAIN_MODE}" == "lora" ]]; then
    exit 0
  fi
  HF_BASE_MODEL=""
fi

if [[ "${TRAIN_MODE}" == "lora" || "${TRAIN_MODE}" == "auto" ]]; then
  if [[ -n "${HF_BASE_MODEL}" ]] && command -v "${LORA_PYTHON_BIN}" >/dev/null 2>&1 && [[ -f "${LORA_SCRIPT}" ]]; then
    lora_attempted=1
    log "step=lora_train status=start base_model=${HF_BASE_MODEL} python=${LORA_PYTHON_BIN}"
    if timeout "${LORA_TIMEOUT}"s "${LORA_PYTHON_BIN}" "${LORA_SCRIPT}" \
      --train-file "${TRAIN_FILE}" \
      --base-model "${HF_BASE_MODEL}" \
      --output-dir "${LORA_OUTPUT_DIR}" \
      --max-rows "${LORA_MAX_ROWS}" \
      --max-steps "${LORA_MAX_STEPS}" \
      --lr "${LORA_LEARNING_RATE}" \
      --batch-size "${LORA_BATCH_SIZE}" \
      --grad-accum "${LORA_GRAD_ACCUM}" \
      --max-length "${LORA_MAX_LENGTH}" \
      >/tmp/lyralink-lora-train.log 2>&1; then
      lora_ok=1
      cp -f /tmp/lyralink-lora-train.log "${ARTIFACT_DIR}/last_lora_train.log" || true
      log "step=lora_train status=ok output=${LORA_OUTPUT_DIR}"
      echo "${LORA_OUTPUT_DIR}" > "${ARTIFACT_DIR}/candidate_lora_adapter.txt"
      if [[ -f "${LORA_PROFILE_STATE_FILE}" ]]; then
        rm -f "${LORA_PROFILE_STATE_FILE}" || true
        log "step=lora_backoff_profile status=cleared reason=successful_run"
      fi
    else
      lora_exit_code=$?
      cp -f /tmp/lyralink-lora-train.log "${ARTIFACT_DIR}/last_lora_train.log" || true
      log "step=lora_train status=failed exit_code=${lora_exit_code} log=${ARTIFACT_DIR}/last_lora_train.log"
      if [[ "${LORA_AUTO_BACKOFF}" == "1" ]]; then
        if [[ "${lora_exit_code}" -eq 137 || "${lora_exit_code}" -eq 9 || "${lora_exit_code}" -eq 124 ]] || grep -Eqi "killed|out of memory|oom|cuda out of memory" "${ARTIFACT_DIR}/last_lora_train.log"; then
          LORA_MAX_ROWS=$((LORA_MAX_ROWS / 2))
          LORA_MAX_STEPS=$((LORA_MAX_STEPS / 2))
          LORA_GRAD_ACCUM=$((LORA_GRAD_ACCUM + 1))
          LORA_MAX_LENGTH=$((LORA_MAX_LENGTH - 16))

          LORA_MAX_ROWS="$(clamp_min "${LORA_MAX_ROWS}" "${LORA_MIN_ROWS}")"
          LORA_MAX_STEPS="$(clamp_min "${LORA_MAX_STEPS}" "${LORA_MIN_STEPS}")"
          LORA_GRAD_ACCUM="$(clamp_min "${LORA_GRAD_ACCUM}" "1")"
          LORA_MAX_LENGTH="$(clamp_min "${LORA_MAX_LENGTH}" "${LORA_MIN_LENGTH}")"

          save_lora_profile_state "auto_backoff_after_failure"
          log "step=lora_backoff_profile status=updated rows=${LORA_MAX_ROWS} steps=${LORA_MAX_STEPS} grad_accum=${LORA_GRAD_ACCUM} max_length=${LORA_MAX_LENGTH}"
        fi
      fi
      if [[ "${TRAIN_MODE}" == "lora" ]]; then
        exit 1
      fi
    fi
  else
    log "step=lora_train status=skipped reason=missing_python_or_model python=${LORA_PYTHON_BIN}"
    if [[ "${TRAIN_MODE}" == "lora" ]]; then
      exit 1
    fi
  fi
fi

if [[ "${lora_ok}" -eq 1 && "${LORA_ONLY}" == "1" ]]; then
  log "status=complete mode=lora_only"
  exit 0
fi

if [[ "${lora_ok}" -eq 1 ]]; then
  log "step=fallback_model_build status=skipped reason=lora_completed"
elif [[ "${ALLOW_FALLBACK}" != "1" ]]; then
  log "status=failed reason=fallback_disabled"
  exit 1
fi

if [[ "${lora_ok}" -eq 1 ]]; then
  # Keep candidate model if LoRA training succeeded and lora-only was requested.
  # Otherwise continue building a canary model for immediate safe activation.
  log "step=canary_build status=continue reason=activation_requires_local_model"
fi

modelfile="${ARTIFACT_DIR}/Modelfile.auto"
cat > "${modelfile}" <<EOF
FROM ${BASE_MODEL}
PARAMETER temperature 0.7
SYSTEM """You are Lyralink.
Use concise responses first, expand only when asked.
Prioritize reliability, concrete actions, and low-latency behavior.
Adapt using retrieval context from the local dataset memory.
"""
EOF

log "step=create_canary_model status=start base_model=${BASE_MODEL} canary_model=${CANARY_MODEL}"
ollama create "${CANARY_MODEL}" -f "${modelfile}" >/tmp/lyralink-ollama-create.log 2>&1

if [[ ! -s /tmp/lyralink-ollama-create.log ]]; then
  log "step=create_canary_model status=ok"
else
  tail -n 40 /tmp/lyralink-ollama-create.log > "${ARTIFACT_DIR}/last_ollama_create.log" || true
  log "step=create_canary_model status=ok log=${ARTIFACT_DIR}/last_ollama_create.log"
fi

echo "${CANARY_MODEL}" > "${ARTIFACT_DIR}/candidate_model.txt"

build_context_model() {
  local target_model="$1"
  local system_prompt="$2"
  local source_model="$3"

  if [[ "${target_model}" == "" ]]; then
    return 0
  fi

  local modelfile_ctx="${ARTIFACT_DIR}/Modelfile.${target_model//[:\/]/_}"
  cat > "${modelfile_ctx}" <<EOF
FROM ${source_model}
PARAMETER temperature 0.7
SYSTEM """${system_prompt}"""
EOF

  if ollama create "${target_model}" -f "${modelfile_ctx}" >/tmp/lyralink-ollama-create-${target_model//[:\/]/_}.log 2>&1; then
    log "step=create_context_model status=ok model=${target_model} source=${source_model}"
    return 0
  fi

  log "step=create_context_model status=failed model=${target_model} source=${source_model}"
  return 1
}

sync_remote_model() {
  local target_model="$1"
  local source_model="$2"
  local system_prompt="$3"

  if [[ "${REMOTE_SYNC_ENABLED}" != "1" ]]; then
    return 0
  fi

  if [[ -z "${REMOTE_SYNC_HOST}" ]]; then
    log "step=remote_sync status=skipped reason=no_remote_host"
    return 0
  fi

  local payload
  payload=$(python3 - "$target_model" "$source_model" "$system_prompt" <<'PY'
import json, sys
name, source, prompt = sys.argv[1], sys.argv[2], sys.argv[3]
modelfile = f"FROM {source}\nPARAMETER temperature 0.7\nSYSTEM \"\"\"{prompt}\"\"\"\n"
print(json.dumps({"name": name, "modelfile": modelfile, "stream": False}))
PY
)

  local remote_url="http://${REMOTE_SYNC_HOST}:${REMOTE_SYNC_PORT}/api/create"
  if curl -fsS -X POST "${remote_url}" \
    -H 'Content-Type: application/json' \
    -d "${payload}" >/tmp/lyralink-remote-sync-${target_model//[:\/]/_}.log 2>&1; then
    log "step=remote_sync status=ok model=${target_model} host=${REMOTE_SYNC_HOST}:${REMOTE_SYNC_PORT}"
    return 0
  fi

  log "step=remote_sync status=failed model=${target_model} host=${REMOTE_SYNC_HOST}:${REMOTE_SYNC_PORT}"
  return 1
}

if [[ "${ENABLE_CONTEXT_MODELS}" == "1" ]]; then
  build_context_model "${FAST_MODEL}" "You are Lyralink Fast. Keep answers short, direct, and useful. Optimize for low latency and clear next actions." "${CANARY_MODEL}" || true
  build_context_model "${CODE_MODEL}" "You are Lyralink Code. Prioritize precise engineering answers, concrete code fixes, and testable implementation steps." "${CANARY_MODEL}" || true
  build_context_model "${REASONING_MODEL}" "You are Lyralink Reasoning. Prioritize stepwise analysis, tradeoff evaluation, and reliable decision-making." "${CANARY_MODEL}" || true
  build_context_model "${CREATIVE_MODEL}" "You are Lyralink Creative. Provide high-quality writing, ideas, and polished tone while staying practical." "${CANARY_MODEL}" || true
fi

if ! sync_remote_model "${CANARY_MODEL}" "${REMOTE_SYNC_BASE_MODEL}" "You are Lyralink. Use concise responses first, expand only when asked. Prioritize reliability, concrete actions, and low-latency behavior. Adapt using retrieval context from the local dataset memory."; then
  if [[ "${REMOTE_SYNC_STRICT}" == "1" ]]; then
    log "step=remote_sync status=failed_strict model=${CANARY_MODEL}"
    exit 1
  fi
  log "step=remote_sync status=soft_failed model=${CANARY_MODEL}"
fi
if [[ "${ENABLE_CONTEXT_MODELS}" == "1" ]]; then
  sync_remote_model "${FAST_MODEL}" "${CANARY_MODEL}" "You are Lyralink Fast. Keep answers short, direct, and useful. Optimize for low latency and clear next actions." || true
  sync_remote_model "${CODE_MODEL}" "${CANARY_MODEL}" "You are Lyralink Code. Prioritize precise engineering answers, concrete code fixes, and testable implementation steps." || true
  sync_remote_model "${REASONING_MODEL}" "${CANARY_MODEL}" "You are Lyralink Reasoning. Prioritize stepwise analysis, tradeoff evaluation, and reliable decision-making." || true
  sync_remote_model "${CREATIVE_MODEL}" "${CANARY_MODEL}" "You are Lyralink Creative. Provide high-quality writing, ideas, and polished tone while staying practical." || true
fi

cat > "${ARTIFACT_DIR}/candidate_models.env" <<EOF
MODEL_ROUTER_DEFAULT=${CANARY_MODEL}
MODEL_ROUTER_FAST=${FAST_MODEL}
MODEL_ROUTER_CODE=${CODE_MODEL}
MODEL_ROUTER_REASONING=${REASONING_MODEL}
MODEL_ROUTER_CREATIVE=${CREATIVE_MODEL}
MODEL_ROUTER_FALLBACK=${FAST_MODEL}
EOF

log "status=complete candidate_model=${CANARY_MODEL}"
