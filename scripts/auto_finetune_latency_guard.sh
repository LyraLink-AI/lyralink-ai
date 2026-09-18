#!/usr/bin/env bash
set -euo pipefail

now_utc() {
  date -u +"%Y-%m-%dT%H:%M:%SZ"
}

log() {
  echo "[$(now_utc)] auto_finetune_latency_guard $*"
}

ROOT_DIR="${LYRALINK_WORKSPACE_ROOT:-/var/www/vhosts/lyralinkai.com/httpdocs}"
ARTIFACT_DIR="${LYRALINK_OUTPUT_DIR:-${ROOT_DIR}/storage/model_training/artifacts}"
HEALTH_URL="${CONTINUOUS_FINETUNE_HEALTH_URL:-https://lyralinkai.com/api/chat.php?health=1}"
HEALTH_TIMEOUT="${CONTINUOUS_FINETUNE_HEALTH_TIMEOUT:-12}"
MAX_LATENCY_MS="${CONTINUOUS_FINETUNE_MAX_ALLOWED_LATENCY_MS:-1800}"
ROLLBACK_STREAK_REQUIRED="${CONTINUOUS_FINETUNE_LATENCY_ROLLBACK_STREAK:-2}"
STATE_FILE="${ARTIFACT_DIR}/latency_guard_state.env"
ROLLBACK_COMMAND="${CONTINUOUS_FINETUNE_ROLLBACK_COMMAND:-bash /var/www/vhosts/lyralinkai.com/httpdocs/scripts/auto_finetune_rollback.sh}"

mkdir -p "${ARTIFACT_DIR}"

current_streak=0
if [[ -f "${STATE_FILE}" ]]; then
  current_streak="$(grep -E '^STREAK=' "${STATE_FILE}" | head -n 1 | cut -d= -f2- || true)"
  if [[ ! "${current_streak}" =~ ^[0-9]+$ ]]; then
    current_streak=0
  fi
fi

if ! curl -fsS -m "${HEALTH_TIMEOUT}" "${HEALTH_URL}" >/tmp/lyralink-latency-health.json; then
  log "status=skipped reason=health_unreachable"
  echo "STREAK=${current_streak}" > "${STATE_FILE}"
  exit 0
fi

latency_ms="$(php -r '$d=json_decode(file_get_contents("/tmp/lyralink-latency-health.json"),true);$v=$d["status"]["last_latency_ms"]??0;echo (int)$v;' 2>/dev/null || echo 0)"
ok_flag="$(php -r '$d=json_decode(file_get_contents("/tmp/lyralink-latency-health.json"),true);echo (($d["ok"]??false)?"1":"0");' 2>/dev/null || echo 0)"

if [[ "${ok_flag}" == "1" && "${latency_ms}" =~ ^[0-9]+$ && "${latency_ms}" -gt "${MAX_LATENCY_MS}" ]]; then
  current_streak=$((current_streak + 1))
  log "probe=slow latency_ms=${latency_ms} threshold_ms=${MAX_LATENCY_MS} streak=${current_streak}"
else
  if [[ "${current_streak}" -ne 0 ]]; then
    log "probe=recovered latency_ms=${latency_ms} streak_reset=1"
  fi
  current_streak=0
fi

echo "STREAK=${current_streak}" > "${STATE_FILE}"

action_taken=0
if [[ "${current_streak}" -ge "${ROLLBACK_STREAK_REQUIRED}" ]]; then
  log "rollback=trigger reason=latency_regression streak=${current_streak}"
  if bash -lc "${ROLLBACK_COMMAND}"; then
    log "rollback=ok"
  else
    log "rollback=failed"
    exit 1
  fi
  current_streak=0
  echo "STREAK=0" > "${STATE_FILE}"
  action_taken=1
fi

log "status=complete latency_ms=${latency_ms} streak=${current_streak} action_taken=${action_taken}"
