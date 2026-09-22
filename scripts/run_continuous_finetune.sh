#!/usr/bin/env bash
set -euo pipefail

now_utc() {
  date -u +"%Y-%m-%dT%H:%M:%SZ"
}

log() {
  echo "[$(now_utc)] finetune_orchestrator $*"
}

ROOT_DIR="${LYRALINK_WORKSPACE_ROOT:-/var/www/vhosts/lyralinkai.com/httpdocs}"
ARTIFACT_DIR="${LYRALINK_OUTPUT_DIR:-${ROOT_DIR}/storage/model_training/artifacts}"
TRAIN_FILE="${LYRALINK_TRAIN_FILE:-${ROOT_DIR}/storage/model_training/latest_dataset.jsonl}"
INCREMENTAL_FILE="${LYRALINK_INCREMENTAL_FILE:-${ROOT_DIR}/storage/model_training/latest_incremental.jsonl}"

TRAIN_COMMAND="${CONTINUOUS_FINETUNE_TRAIN_COMMAND:-}"
ACTIVATE_COMMAND="${CONTINUOUS_FINETUNE_ACTIVATE_COMMAND:-}"
ROLLBACK_COMMAND="${CONTINUOUS_FINETUNE_ROLLBACK_COMMAND:-}"
POST_TRAIN_VALIDATE_COMMAND="${CONTINUOUS_FINETUNE_VALIDATE_COMMAND:-}"
EVAL_GATE_COMMAND="${CONTINUOUS_FINETUNE_EVAL_GATE_COMMAND:-}"
HEALTH_URL="${CONTINUOUS_FINETUNE_HEALTH_URL:-https://lyralinkai.com/api/chat.php?health=1}"
HEALTH_TIMEOUT="${CONTINUOUS_FINETUNE_HEALTH_TIMEOUT:-12}"
CANARY_REQUIRED_SUCCESSES="${CONTINUOUS_FINETUNE_CANARY_REQUIRED_SUCCESSES:-2}"
CANARY_MAX_ATTEMPTS="${CONTINUOUS_FINETUNE_CANARY_MAX_ATTEMPTS:-4}"
LATENCY_GUARD_ENABLED="${CONTINUOUS_FINETUNE_LATENCY_GUARD_ENABLED:-1}"
LATENCY_GUARD_SCRIPT="${ROOT_DIR}/scripts/auto_finetune_latency_guard.sh"

if [[ "${CANARY_REQUIRED_SUCCESSES}" -lt 1 ]]; then
  CANARY_REQUIRED_SUCCESSES=1
fi
if [[ "${CANARY_MAX_ATTEMPTS}" -lt "${CANARY_REQUIRED_SUCCESSES}" ]]; then
  CANARY_MAX_ATTEMPTS="${CANARY_REQUIRED_SUCCESSES}"
fi

mkdir -p "${ARTIFACT_DIR}"

# Step 1: Always refresh retrieval corpus artifacts.
if [[ -f "${TRAIN_FILE}" ]]; then
  cp -f "${TRAIN_FILE}" "${ARTIFACT_DIR}/current_corpus.jsonl"
fi
if [[ -f "${INCREMENTAL_FILE}" ]]; then
  cp -f "${INCREMENTAL_FILE}" "${ARTIFACT_DIR}/current_incremental.jsonl"
fi

cat > "${ARTIFACT_DIR}/run_meta.env" <<EOF
RUN_AT=$(now_utc)
DATASET_ROWS=${LYRALINK_DATASET_ROWS:-0}
NEW_ROWS=${LYRALINK_NEW_ROWS:-0}
TRAIN_FILE=${TRAIN_FILE}
INCREMENTAL_FILE=${INCREMENTAL_FILE}
EOF

log "step=corpus_refresh status=ok"

# Step 2: Optional model training command.
if [[ -n "${TRAIN_COMMAND}" ]]; then
  log "step=train status=start"
  bash --noprofile --norc -lc "${TRAIN_COMMAND}"
  log "step=train status=ok"
else
  log "step=train status=skipped reason=no_train_command"
fi

# Optional post-train validation command.
if [[ -n "${POST_TRAIN_VALIDATE_COMMAND}" ]]; then
  log "step=validate status=start"
  bash --noprofile --norc -lc "${POST_TRAIN_VALIDATE_COMMAND}"
  log "step=validate status=ok"
fi

# Step 3: Optional activation + health gate + rollback.
if [[ -n "${ACTIVATE_COMMAND}" ]]; then
  log "step=activate status=start"
  if bash --noprofile --norc -lc "${ACTIVATE_COMMAND}"; then
    log "step=activate status=ok"
  else
    log "step=activate status=failed"
    if [[ -n "${ROLLBACK_COMMAND}" ]]; then
      log "step=rollback status=start reason=activation_failed"
      bash --noprofile --norc -lc "${ROLLBACK_COMMAND}" || true
      log "step=rollback status=done"
    fi
    exit 1
  fi

  log "step=health_gate status=start url=${HEALTH_URL} required=${CANARY_REQUIRED_SUCCESSES} max_attempts=${CANARY_MAX_ATTEMPTS}"
  success_count=0
  attempt=1
  while [[ "${attempt}" -le "${CANARY_MAX_ATTEMPTS}" ]]; do
    if curl -fsS -m "${HEALTH_TIMEOUT}" "${HEALTH_URL}" >/tmp/lyralink-finetune-health.json; then
      if grep -qi '"ok"[[:space:]]*:[[:space:]]*true' /tmp/lyralink-finetune-health.json; then
        success_count=$((success_count + 1))
        log "step=health_gate_probe attempt=${attempt} status=ok success_count=${success_count}"
      else
        log "step=health_gate_probe attempt=${attempt} status=failed reason=ok_not_true"
      fi
    else
      log "step=health_gate_probe attempt=${attempt} status=failed reason=endpoint_unreachable"
    fi

    if [[ "${success_count}" -ge "${CANARY_REQUIRED_SUCCESSES}" ]]; then
      log "step=health_gate status=ok"
      break
    fi

    attempt=$((attempt + 1))
  done

  if [[ "${success_count}" -lt "${CANARY_REQUIRED_SUCCESSES}" ]]; then
    log "step=health_gate status=failed reason=insufficient_successes got=${success_count} required=${CANARY_REQUIRED_SUCCESSES}"
    if [[ -n "${ROLLBACK_COMMAND}" ]]; then
      log "step=rollback status=start reason=canary_gate_failed"
      bash --noprofile --norc -lc "${ROLLBACK_COMMAND}" || true
      log "step=rollback status=done"
    fi
    exit 1
  fi

  # Step 3b: quality eval gate. The candidate is serving at this point, so a
  # regression here is measurable; blocking forces a rollback.
  if [[ -n "${EVAL_GATE_COMMAND}" ]]; then
    log "step=eval_gate status=start"
    if bash --noprofile --norc -lc "${EVAL_GATE_COMMAND}"; then
      log "step=eval_gate status=ok"
    else
      gate_rc=$?
      log "step=eval_gate status=blocked rc=${gate_rc}"
      if [[ -n "${ROLLBACK_COMMAND}" ]]; then
        log "step=rollback status=start reason=eval_gate_blocked"
        bash --noprofile --norc -lc "${ROLLBACK_COMMAND}" || true
        log "step=rollback status=done"
      fi
      exit 1
    fi
  fi
else
  log "step=activate status=skipped reason=no_activate_command"
fi

if [[ "${LATENCY_GUARD_ENABLED}" == "1" && -f "${LATENCY_GUARD_SCRIPT}" ]]; then
  log "step=latency_guard status=start"
  if bash "${LATENCY_GUARD_SCRIPT}"; then
    log "step=latency_guard status=ok"
  else
    log "step=latency_guard status=failed"
    exit 1
  fi
fi

log "status=complete"
