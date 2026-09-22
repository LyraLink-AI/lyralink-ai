#!/usr/bin/env bash
# Smoke tests for the guard library, provenance exporter and Kaggle runner.
# These assert on real behaviour, not on the presence of code.
set -uo pipefail

R="${LYRALINK_WORKSPACE_ROOT:-/var/www/vhosts/lyralinkai.com/httpdocs}"
cd "$R" || exit 2

fails=0
check() { # label expected actual
  if [[ "$2" == "$3" ]]; then
    printf '  PASS %-28s %s\n' "$1" "$3"
  else
    printf '  FAIL %-28s expected=%s got=%s\n' "$1" "$2" "$3"
    fails=$((fails + 1))
  fi
}

# shellcheck source=/dev/null
source scripts/lib/train_guard.sh

echo "== parameter parsing =="
check "hermes3-Llama-3.2-3B" "3"   "$(lyra_param_billions 'NousResearch/Hermes-3-Llama-3.2-3B')"
check "distilgpt2 (no hint)" ""    "$(lyra_param_billions 'distilgpt2')"
check "Qwen2.5-7B"           "7"   "$(lyra_param_billions 'Qwen2.5-7B-Instruct')"
check "Qwen2.5-0.5B"         "0.5" "$(lyra_param_billions 'Qwen2.5-0.5B')"
check "Llama-3.2-1B"         "1"   "$(lyra_param_billions 'meta-llama/Llama-3.2-1B')"

echo "== gpu detection =="
check "gpus on this host" "0" "$(lyra_gpu_count)"

echo "== cpu guard refuses a 3B on cpu =="
out="$(lyra_cpu_train_guard 'NousResearch/Hermes-3-Llama-3.2-3B'; echo "rc=$?")"
echo "$out" | sed 's/^/    /'
check "refused" "rc=1" "$(echo "$out" | tail -1)"

echo "== cpu guard allows a small model =="
out="$(lyra_cpu_train_guard 'distilgpt2'; echo "rc=$?")"
check "allowed" "rc=0" "$(echo "$out" | tail -1)"

echo "== cpu guard explicit override =="
out="$(LYRA_ALLOW_CPU_TRAIN=1 lyra_cpu_train_guard 'NousResearch/Hermes-3-Llama-3.2-3B'; echo "rc=$?")"
check "overridden" "rc=0" "$(echo "$out" | tail -1)"

echo "== export refuses an unprovenanced corpus =="
python3 scripts/kaggle_bundle_export.py \
  --input-jsonl storage/model_training/latest_dataset.jsonl \
  --out /tmp/exp_none.jsonl --report /tmp/exp_none.json > /tmp/exp_none.log 2>&1
rc=$?
check "refused rc=1" "1" "$rc"
grep -E "REFUSED|carry no evidence_level" /tmp/exp_none.log | head -2 | sed 's/^/    /'

echo "== export passes when provenance is explicitly allowed =="
python3 scripts/kaggle_bundle_export.py \
  --input-jsonl storage/model_training/latest_dataset.jsonl \
  --keep-unprovenanced --max-rows 50 \
  --out /tmp/exp_keep.jsonl > /tmp/exp_keep.log 2>&1
check "row cap honoured" "50" "$(wc -l < /tmp/exp_keep.jsonl | tr -d ' ')"
grep -E '^\[export\] rows_in' /tmp/exp_keep.log | sed 's/^/    /'

echo "== export format is trainer-consumable =="
python3 - <<'PY'
import json
ok = 0
bad = 0
for line in open("/tmp/exp_keep.jsonl", encoding="utf-8"):
    line = line.strip()
    if not line:
        continue
    try:
        doc = json.loads(line)
    except ValueError:
        bad += 1
        continue
    msgs = doc.get("messages")
    if (isinstance(msgs, list) and len(msgs) == 2
            and msgs[0].get("role") == "user"
            and msgs[1].get("role") == "assistant"
            and msgs[0].get("content") and msgs[1].get("content")):
        ok += 1
    else:
        bad += 1
print(f"  PASS chat-format rows        ok={ok} bad={bad}")
raise SystemExit(1 if bad else 0)
PY
[[ $? -eq 0 ]] || fails=$((fails + 1))

echo "== runner readiness (expect: not configured yet) =="
python3 scripts/kaggle_train_runner.py --check | sed 's/^/    /'

echo "== runner dry-run builds a bundle without calling the api =="
python3 scripts/kaggle_train_runner.py --root "$R" \
  --dataset-file /tmp/exp_keep.jsonl --dry-run 2>&1 | sed 's/^/    /'
rc=${PIPESTATUS[0]}
check "dry-run rc=0" "0" "$rc"

echo "== kernel metadata is well formed =="
python3 - <<'PY'
import json, os
base = "/var/www/vhosts/lyralinkai.com/httpdocs/storage/model_training/kaggle"
km = os.path.join(base, "kernel", "kernel-metadata.json")
dm = os.path.join(base, "dataset", "dataset-metadata.json")
problems = []
for path, required in ((km, ("id", "code_file", "language", "kernel_type", "enable_gpu")),
                       (dm, ("id", "title"))):
    if not os.path.isfile(path):
        problems.append(f"missing {path}")
        continue
    doc = json.load(open(path, encoding="utf-8"))
    for key in required:
        if key not in doc:
            problems.append(f"{os.path.basename(path)} lacks {key}")
    if path == km:
        if doc.get("enable_gpu") is not True:
            problems.append("enable_gpu is not True")
        if doc.get("enable_internet") is not True:
            problems.append("enable_internet is not True (pip/HF download needs it)")
        code = os.path.join(os.path.dirname(path), doc.get("code_file", ""))
        if not os.path.isfile(code):
            problems.append(f"code_file not staged: {doc.get('code_file')}")
print("  PASS metadata valid" if not problems else "  FAIL " + "; ".join(problems))
raise SystemExit(1 if problems else 0)
PY
[[ $? -eq 0 ]] || fails=$((fails + 1))

echo
if [[ "$fails" -eq 0 ]]; then
  echo "ALL PASS"
else
  echo "$fails CHECK(S) FAILED"
fi
exit "$fails"
