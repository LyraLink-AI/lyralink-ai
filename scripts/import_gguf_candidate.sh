#!/usr/bin/env bash
# Import a GGUF produced on Kaggle as a local Ollama candidate model.
#
# The chat template is taken from the model that is currently being served, not
# hardcoded. A fine-tune that keeps the weights but loses the prompt format is
# silently worse than the base it replaces, and that failure is invisible in a
# benchmark score until it is severe.
#
#   import_gguf_candidate.sh --gguf <path> [--name <model>] [--base <model>]
set -euo pipefail

now_utc() { date -u +"%Y-%m-%dT%H:%M:%SZ"; }
log() { echo "[$(now_utc)] import_gguf $*"; }
die() { log "ERROR: $*"; exit 2; }

ROOT_DIR="${LYRALINK_WORKSPACE_ROOT:-/var/www/vhosts/lyralinkai.com/httpdocs}"
ARTIFACT_DIR="${LYRALINK_OUTPUT_DIR:-${ROOT_DIR}/storage/model_training/artifacts}"

GGUF=""
DERIVE_ONLY=""
CANDIDATE_MODEL="${CONTINUOUS_FINETUNE_OLLAMA_CANARY_MODEL:-lyralink-auto-canary:latest}"
BASE_MODEL="${CONTINUOUS_FINETUNE_OLLAMA_BASE_MODEL:-hermes3:3b}"
MIN_FREE_MIB="${LYRA_IMPORT_MIN_FREE_MIB:-4096}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --gguf) GGUF="${2:-}"; shift 2 ;;
    --name) CANDIDATE_MODEL="${2:-}"; shift 2 ;;
    --base) BASE_MODEL="${2:-}"; shift 2 ;;
    --derive-only) DERIVE_ONLY="${2:-}"; shift 2 ;;
    -h|--help) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

command -v ollama >/dev/null 2>&1 || die "ollama not installed"

if [[ -n "$DERIVE_ONLY" ]]; then
  # Derivation check: build the Modelfile for an arbitrary FROM target without
  # needing the weights present. Used by the pipeline's own tests.
  [[ -n "$GGUF" ]] || GGUF="/placeholder/model.gguf"
elif [[ -z "$GGUF" ]]; then
  die "--gguf is required (or use --derive-only <outfile>)"
fi

if [[ -z "$DERIVE_ONLY" ]]; then
  [[ -f "$GGUF" ]] || die "gguf not found: $GGUF"
  gguf_bytes=$(stat -c %s "$GGUF" 2>/dev/null || echo 0)
  [[ "$gguf_bytes" -gt 1048576 ]] || die "gguf suspiciously small (${gguf_bytes} bytes)"

  free_mib=$(df -Pm "$(dirname "$GGUF")" 2>/dev/null | awk 'NR==2{print $4}')
  if [[ -n "${free_mib:-}" ]] && [[ "$free_mib" -lt "$MIN_FREE_MIB" ]]; then
    die "insufficient disk: ${free_mib} MiB free, need ${MIN_FREE_MIB} MiB"
  fi
fi

mkdir -p "$ARTIFACT_DIR"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

MODELFILE="${work}/Modelfile"
base_modelfile="${work}/base.modelfile"

# --- derive the template from the currently served model -------------------
rest="${work}/rest.modelfile"
: > "$rest"
if ollama show "$BASE_MODEL" --modelfile > "$base_modelfile" 2>/dev/null \
   && grep -qE '^TEMPLATE' "$base_modelfile"; then
  log "template source: ${BASE_MODEL}"
  # Keep everything except the weight pointer and any adapter binding.
  awk '!/^FROM[ \t]/ && !/^ADAPTER[ \t]/ && !/^#/' "$base_modelfile" > "$rest"
  template_source="base_model:${BASE_MODEL}"
else
  log "WARN: no modelfile available from ${BASE_MODEL}; using ChatML fallback"
  cat > "$rest" <<'EOF'
TEMPLATE """{{ if .System }}<|im_start|>system
{{ .System }}<|im_end|>
{{ end }}{{ if .Prompt }}<|im_start|>user
{{ .Prompt }}<|im_end|>
{{ end }}<|im_start|>assistant
{{ .Response }}<|im_end|>
"""
PARAMETER stop "<|im_start|>"
PARAMETER stop "<|im_end|>"
EOF
  template_source="chatml_fallback"
fi

{
  echo "FROM ${GGUF}"
  cat "$rest"
} > "$MODELFILE"

if ! grep -qE '^TEMPLATE' "$MODELFILE"; then
  log "WARN: derived modelfile has no TEMPLATE; candidate will use Ollama defaults"
fi

if [[ -n "$DERIVE_ONLY" ]]; then
  mkdir -p "$(dirname "$DERIVE_ONLY")" 2>/dev/null || true
  cp -f "$MODELFILE" "$DERIVE_ONLY"
  log "status=complete mode=derive_only out=${DERIVE_ONLY} template_source=${template_source}"
  exit 0
fi

# --- build -----------------------------------------------------------------
log "creating model=${CANDIDATE_MODEL} from $(basename "$GGUF") (${gguf_bytes} bytes)"
if ! ollama create "$CANDIDATE_MODEL" -f "$MODELFILE"; then
  die "ollama create failed for ${CANDIDATE_MODEL}"
fi

# --- verify ----------------------------------------------------------------
if ! ollama show "$CANDIDATE_MODEL" >/dev/null 2>&1; then
  die "candidate ${CANDIDATE_MODEL} not visible after create"
fi

# A candidate must actually generate. `ollama show` only proves registration.
probe_ok=0
if command -v curl >/dev/null 2>&1; then
  if curl -fsS -m 90 http://127.0.0.1:11434/api/generate \
      -d "{\"model\":\"${CANDIDATE_MODEL}\",\"prompt\":\"Reply with the single word: ready\",\"stream\":false,\"options\":{\"num_predict\":8}}" \
      >/dev/null 2>&1; then
    probe_ok=1
  fi
fi

printf '%s\n' "$CANDIDATE_MODEL" > "${ARTIFACT_DIR}/candidate_model.txt"
log "status=complete model=${CANDIDATE_MODEL} template_source=${template_source} generation_probe=${probe_ok}"

if [[ "$probe_ok" != "1" ]]; then
  log "WARN: generation probe failed; candidate registered but did not answer"
fi
exit 0
