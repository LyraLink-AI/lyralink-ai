#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

if [[ $# -gt 0 ]]; then
  echo "This script only starts the Lyralink render worker." >&2
  echo "Start your SD backend separately, for example: ./webui.sh --api --xformers --medvram" >&2
  echo "Then run: ./start_worker.sh" >&2
  exit 1
fi

if [[ ! -d .venv ]]; then
  python3 -m venv .venv
fi

source .venv/bin/activate
python -m pip install --upgrade pip
python -m pip install -r requirements.txt edge-tts
exec python gpu_worker.py
