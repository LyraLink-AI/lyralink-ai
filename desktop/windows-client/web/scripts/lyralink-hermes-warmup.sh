#!/usr/bin/env bash
set -euo pipefail

MODEL_NAME="hermes3:3b"
OLLAMA_URL="http://127.0.0.1:11434"

wait_for_ollama() {
  for _ in $(seq 1 30); do
    if curl -fsS "$OLLAMA_URL/api/tags" >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  echo "Ollama did not become ready in time" >&2
  exit 1
}

wait_for_ollama

if ! ollama list 2>/dev/null | awk '{print $1}' | grep -Fxq "$MODEL_NAME"; then
  ollama pull "$MODEL_NAME"
fi

curl -fsS "$OLLAMA_URL/v1/chat/completions" \
  -H 'Content-Type: application/json' \
  -d '{"model":"'"$MODEL_NAME"'","messages":[{"role":"user","content":"Warmup"}],"max_tokens":8,"temperature":0}' >/dev/null

echo "Warmup complete for $MODEL_NAME"
