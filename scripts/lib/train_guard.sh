#!/usr/bin/env bash
# Shared guards for the Lyralink fine-tune pipeline.
#
# Sourced by the training scripts. Deliberately dependency-free: no jq, no python.

# --------------------------------------------------------------------- logging
lyra_log() { echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*"; }

# ------------------------------------------------------------------------- gpu
lyra_gpu_count() {
  if command -v nvidia-smi >/dev/null 2>&1; then
    nvidia-smi -L 2>/dev/null | grep -c '^GPU ' || echo 0
    return
  fi
  # No nvidia-smi: fall back to device nodes / rocm.
  if compgen -G "/dev/nvidia[0-9]*" >/dev/null 2>&1; then
    compgen -G "/dev/nvidia[0-9]*" | wc -l
    return
  fi
  if [[ -d /dev/dri ]] && command -v rocminfo >/dev/null 2>&1; then
    echo 1
    return
  fi
  echo 0
}

lyra_have_gpu() { [[ "$(lyra_gpu_count)" -gt 0 ]]; }

# ------------------------------------------------------------------ model size
# Extract a parameter count in billions from a model id such as
# "NousResearch/Hermes-3-Llama-3.2-3B" -> 3.0
# Returns empty string when the name carries no size hint (e.g. "distilgpt2").
lyra_param_billions() {
  local name="$1" best="" candidate
  # Only accept a number immediately followed by b/B at a word boundary, so
  # "Hermes-3" and "Llama-3.2" do not register as sizes but "3B" does.
  while IFS= read -r candidate; do
    [[ -z "$candidate" ]] && continue
    if [[ -z "$best" ]] || awk -v a="$candidate" -v b="$best" 'BEGIN{exit !(a>b)}'; then
      best="$candidate"
    fi
  done < <(printf '%s' "$name" | grep -oE '[0-9]+(\.[0-9]+)?[bB]([^a-zA-Z0-9]|$)' | grep -oE '^[0-9]+(\.[0-9]+)?')
  printf '%s' "$best"
}

# Available RAM in MiB.
lyra_avail_ram_mib() {
  awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0
}

# ---------------------------------------------------------------- cpu guard
# Refuse to start a local training run that cannot possibly finish.
#
# Rationale: weights are ~2 bytes/param in bf16, and a training step needs
# several multiples of that again for activations and gradients. A 3B LoRA
# needs roughly 8 GiB of resident memory before it does any useful work, which
# this host does not have while Ollama is serving live traffic.
#
# Honours:
#   LYRA_ALLOW_CPU_TRAIN=1          explicit override (dangerous, logged loudly)
#   LYRA_CPU_TRAIN_MAX_PARAMS_B     default 0.5
#   LYRA_CPU_TRAIN_MIN_RAM_MIB      default 4096
#
# Returns 0 when training may proceed, 1 when it must be refused.
lyra_cpu_train_guard() {
  local model="$1"
  local allow="${LYRA_ALLOW_CPU_TRAIN:-0}"
  local max_params="${LYRA_CPU_TRAIN_MAX_PARAMS_B:-0.5}"
  local min_ram="${LYRA_CPU_TRAIN_MIN_RAM_MIB:-4096}"
  local params ram

  if lyra_have_gpu; then
    lyra_log "guard=cpu_train status=pass reason=gpu_present gpus=$(lyra_gpu_count)"
    return 0
  fi

  params="$(lyra_param_billions "$model")"
  ram="$(lyra_avail_ram_mib)"

  if [[ "$allow" == "1" ]]; then
    lyra_log "guard=cpu_train status=OVERRIDDEN model=${model} params=${params:-unknown}B ram_mib=${ram} (LYRA_ALLOW_CPU_TRAIN=1)"
    return 0
  fi

  if [[ -n "$params" ]]; then
    if awk -v p="$params" -v m="$max_params" 'BEGIN{exit !(p>m)}'; then
      lyra_log "guard=cpu_train status=refused reason=model_too_large_for_cpu model=${model} params=${params}B max_params=${max_params}B"
      lyra_log "guard=cpu_train hint=set CONTINUOUS_FINETUNE_TRAIN_BACKEND=kaggle to train on free GPU, or LYRA_ALLOW_CPU_TRAIN=1 to force"
      return 1
    fi
  else
    lyra_log "guard=cpu_train status=warn reason=unknown_param_count model=${model}"
  fi

  if [[ "$ram" -gt 0 ]] && [[ "$ram" -lt "$min_ram" ]]; then
    lyra_log "guard=cpu_train status=refused reason=insufficient_ram ram_mib=${ram} required_mib=${min_ram}"
    return 1
  fi

  lyra_log "guard=cpu_train status=pass model=${model} params=${params:-unknown}B ram_mib=${ram}"
  return 0
}

# ------------------------------------------------------------- dataset guard
# Count usable rows in a training JSONL and refuse an empty run.
# A run over zero rows still writes an adapter (randomly initialised deltas),
# which is worse than no adapter because it looks like success.
lyra_count_jsonl() {
  local file="$1"
  [[ -f "$file" ]] || { echo 0; return; }
  wc -l < "$file" | tr -d ' '
}

lyra_require_rows() {
  local file="$1" min="${2:-1}" n
  n="$(lyra_count_jsonl "$file")"
  if [[ "$n" -lt "$min" ]]; then
    lyra_log "guard=dataset status=refused reason=too_few_rows file=${file} rows=${n} min=${min}"
    return 1
  fi
  lyra_log "guard=dataset status=pass file=${file} rows=${n}"
  return 0
}
