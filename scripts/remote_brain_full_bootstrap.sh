#!/usr/bin/env bash
set -euo pipefail

TARGET_HOST="${1:-${REMOTE_LLM_HOST:-127.0.0.1}}"
TARGET_PORT="${2:-${REMOTE_LLM_PORT:-11434}}"
BASE_MODEL="${3:-${REMOTE_LLM_MODEL:-hermes3:3b}}"
VISION_MODEL="${4:-${REMOTE_LLM_IMAGE_MODEL:-llava:7b}}"
WORKDIR="${LYRALINK_BOOTSTRAP_DIR:-/tmp/lyralink-remote-bootstrap}"

mkdir -p "${WORKDIR}"

pull_if_missing() {
  local model="$1"
  if ! ollama list 2>/dev/null | awk '{print $1}' | grep -Fxq "${model}"; then
    echo "Pulling ${model}"
    ollama pull "${model}"
  fi
}

create_model() {
  local model_name="$1"
  local source_model="$2"
  local system_prompt="$3"
  local modelfile="${WORKDIR}/Modelfile.${model_name//[:\/]/_}"

  cat > "${modelfile}" <<EOF
FROM ${source_model}
PARAMETER temperature 0.7
SYSTEM """${system_prompt}"""
EOF

  if ! ollama show "${model_name}" >/dev/null 2>&1; then
    echo "Creating ${model_name} from ${source_model}"
    ollama create "${model_name}" -f "${modelfile}"
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

pull_if_missing "${BASE_MODEL}"
pull_if_missing "${VISION_MODEL}"

create_model "lyralink-auto-canary:latest" "${BASE_MODEL}" "You are Lyralink. Use concise responses first, expand only when asked. Prioritize reliability, concrete actions, and low-latency behavior."
create_model "lyralink-fast:latest" "lyralink-auto-canary:latest" "You are Lyralink Fast. Keep answers short, direct, and useful. Optimize for low latency and clear next actions."
create_model "lyralink-code:latest" "lyralink-auto-canary:latest" "You are Lyralink Code. Prioritize precise engineering answers, concrete code fixes, and testable implementation steps."
create_model "lyralink-reasoning:latest" "lyralink-auto-canary:latest" "You are Lyralink Reasoning. Prioritize stepwise analysis, tradeoff evaluation, and reliable decision-making."
create_model "lyralink-creative:latest" "lyralink-auto-canary:latest" "You are Lyralink Creative. Provide high-quality writing, ideas, and polished tone while staying practical."

curl -fsS "http://127.0.0.1:${TARGET_PORT}/v1/chat/completions" \
  -H 'Content-Type: application/json' \
  -d '{"model":"lyralink-auto-canary:latest","messages":[{"role":"user","content":"remote brain warmup"}],"max_tokens":8,"temperature":0}' >/dev/null

cat <<EOF
Remote brain models are ready.

Base URL: http://${TARGET_HOST}:${TARGET_PORT}/v1
Base model: ${BASE_MODEL}
Vision model: ${VISION_MODEL}
Created: lyralink-auto-canary:latest, lyralink-fast:latest, lyralink-code:latest, lyralink-reasoning:latest, lyralink-creative:latest

Run this on the remote host, then the app can route by load to the matching remote Lyralink model.
EOF