#!/usr/bin/env bash
set -euo pipefail

TARGET_HOST="${1:-${REMOTE_LLM_HOST:-127.0.0.1}}"
TARGET_PORT="${2:-${REMOTE_LLM_PORT:-11434}}"
TARGET_MODEL="${3:-${REMOTE_LLM_MODEL:-lyralink-auto-canary:latest}}"
APP_ROOT="${LYRALINK_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
ENV_FILE="${APP_ROOT}/.env"
BASE_URL="http://${TARGET_HOST}:${TARGET_PORT}/v1"

set_env_key() {
  local key="$1"
  local value="$2"
  if [[ -f "${ENV_FILE}" ]] && grep -Eq "^${key}=" "${ENV_FILE}"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "${ENV_FILE}"
  else
    printf '%s=%s\n' "${key}" "${value}" >> "${ENV_FILE}"
  fi
}

if ! command -v curl >/dev/null 2>&1; then
  echo "curl is required" >&2
  exit 1
fi

if ! command -v ollama >/dev/null 2>&1; then
  echo "Installing Ollama..."
  curl -fsSL https://ollama.com/install.sh | sh
fi

if command -v systemctl >/dev/null 2>&1; then
  systemctl enable ollama >/dev/null 2>&1 || true
  systemctl restart ollama >/dev/null 2>&1 || true
else
  nohup ollama serve >/tmp/lyralink-remote-ollama.log 2>&1 &
fi

for _ in $(seq 1 60); do
  if curl -fsS "http://127.0.0.1:${TARGET_PORT}/api/tags" >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

if ! curl -fsS "http://127.0.0.1:${TARGET_PORT}/api/tags" >/dev/null 2>&1; then
  echo "Remote Ollama did not become ready on port ${TARGET_PORT}" >&2
  exit 1
fi

if ! ollama list 2>/dev/null | awk '{print $1}' | grep -Fxq "${TARGET_MODEL}"; then
  echo "Pulling model: ${TARGET_MODEL}"
  ollama pull "${TARGET_MODEL}"
fi

curl -fsS "http://127.0.0.1:${TARGET_PORT}/v1/chat/completions" \
  -H 'Content-Type: application/json' \
  -d '{"model":"'"${TARGET_MODEL}"'","messages":[{"role":"user","content":"remote brain warmup"}],"max_tokens":8,"temperature":0}' >/dev/null

if [[ ! -f "${ENV_FILE}" ]]; then
  touch "${ENV_FILE}"
fi

set_env_key "REMOTE_LLM_BASE_URL" "${BASE_URL}"
set_env_key "REMOTE_LLM_BASE_URLS" "${BASE_URL}"
set_env_key "REMOTE_LLM_API_KEY" "local-ollama"
set_env_key "REMOTE_LLM_MODEL" "${TARGET_MODEL}"
set_env_key "REMOTE_LLM_MODELS" "${TARGET_MODEL},hermes3:3b,hermes3:8b"
set_env_key "REMOTE_LLM_TIMEOUT" "45"
set_env_key "REMOTE_LLM_CONNECT_TIMEOUT" "3"
set_env_key "REMOTE_LLM_KEEP_ALIVE" "2h"

cat <<EOF
Remote brain is ready.

Base URL: ${BASE_URL}
Model: ${TARGET_MODEL}
Env file: ${ENV_FILE}

This app will now use it as a second Ollama-compatible brain when the local node is slow or unavailable.
EOF
