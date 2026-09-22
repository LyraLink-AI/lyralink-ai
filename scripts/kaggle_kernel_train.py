#!/usr/bin/env python3
"""Lyralink LoRA fine-tune, designed to run as a Kaggle script kernel.

Runs the whole heavy half of the pipeline on a free GPU host:
    train LoRA -> merge adapter into base -> GGUF f16 -> quantise Q4_K_M

Merging and conversion happen here on purpose. Doing them on the web box would
require loading a 3B base in fp16 (~6 GiB) alongside live Ollama traffic.

Inputs (Kaggle mounts attached datasets under /kaggle/input):
    <dataset>/dataset.jsonl   chat-format rows: {"messages":[user,assistant],...}

Outputs (written to /kaggle/working, downloadable via the Kaggle API):
    lyralink-ft-Q4_K_M.gguf   quantised model, ready for `ollama create`
    training_manifest.json    config, metrics and provenance of the run

Environment knobs (set by the runner via kernel metadata env, or defaults):
    LYRA_BASE_MODEL      HF base model id
    LYRA_DATASET_GLOB    glob for the training jsonl under /kaggle/input
    LYRA_MAX_STEPS       optimiser steps
    LYRA_MAX_ROWS        row cap
    LYRA_MAX_LENGTH      sequence length
    LYRA_QUANT           llama-quantize target type
    LYRA_LORA_R / ALPHA / DROPOUT / LR / BATCH / GRAD_ACCUM
"""

from __future__ import annotations

import glob
import json
import os
import shutil
import subprocess
import sys
import time
import traceback

WORK = "/kaggle/working"
LLAMA_CPP_DIR = "/kaggle/tmp/llama.cpp"

DEFAULTS = {
    "LYRA_BASE_MODEL": "NousResearch/Hermes-3-Llama-3.2-3B",
    "LYRA_DATASET_GLOB": "/kaggle/input/**/dataset.jsonl",
    "LYRA_MAX_STEPS": "120",
    "LYRA_MAX_ROWS": "6000",
    "LYRA_MAX_LENGTH": "1024",
    "LYRA_QUANT": "Q4_K_M",
    "LYRA_LORA_R": "16",
    "LYRA_LORA_ALPHA": "32",
    "LYRA_LORA_DROPOUT": "0.05",
    "LYRA_LR": "0.0002",
    "LYRA_BATCH": "1",
    "LYRA_GRAD_ACCUM": "16",
    "LYRA_SEED": "1337",
}

for key, value in DEFAULTS.items():
    os.environ.setdefault(key, value)


def cfg(key: str) -> str:
    return os.environ[key]


def ci(key: str) -> int:
    return int(os.environ[key])


def cf(key: str) -> float:
    return float(os.environ[key])


def log(message: str) -> None:
    print(f"[lyra-train] {message}", flush=True)


def run(cmd: list[str], cwd: str | None = None, check: bool = True) -> subprocess.CompletedProcess:
    log("exec: " + " ".join(cmd))
    result = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True)
    if result.returncode != 0:
        log(f"command failed rc={result.returncode}")
        if result.stdout:
            log("stdout tail:\n" + result.stdout[-4000:])
        if result.stderr:
            log("stderr tail:\n" + result.stderr[-4000:])
        if check:
            raise RuntimeError(f"command failed: {cmd[0]}")
    return result


def gpu_report() -> dict:
    info = {"gpus": 0, "names": [], "torch_cuda": False}
    try:
        result = subprocess.run(["nvidia-smi", "--query-gpu=name,memory.total",
                                 "--format=csv,noheader"], capture_output=True, text=True)
        if result.returncode == 0:
            lines = [l.strip() for l in result.stdout.strip().splitlines() if l.strip()]
            info["gpus"] = len(lines)
            info["names"] = lines
    except FileNotFoundError:
        pass
    try:
        import torch
        info["torch_cuda"] = bool(torch.cuda.is_available())
        if info["torch_cuda"]:
            info["torch_device"] = torch.cuda.get_device_name(0)
    except Exception:
        pass
    return info


# --------------------------------------------------------------- dependencies
def install_deps() -> None:
    packages = [
        "transformers>=4.44", "peft>=0.13", "accelerate>=1.0",
        "datasets>=3.0", "gguf", "sentencepiece", "protobuf",
    ]
    run([sys.executable, "-m", "pip", "install", "-q", "--upgrade", *packages])
    # torch is preinstalled on Kaggle with CUDA; do not upgrade it.


# ------------------------------------------------------------------- dataset
def locate_dataset() -> str:
    pattern = cfg("LYRA_DATASET_GLOB")
    matches = sorted(glob.glob(pattern, recursive=True))
    if not matches:
        raise RuntimeError(f"no training file matched {pattern}")
    path = max(matches, key=os.path.getsize)
    size = os.path.getsize(path)
    log(f"dataset: {path} ({size} bytes)")
    if size == 0:
        raise RuntimeError("training file is empty")
    return path


def load_rows(path: str) -> list[dict]:
    rows: list[dict] = []
    with open(path, "r", encoding="utf-8", errors="replace") as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            try:
                doc = json.loads(line)
            except ValueError:
                continue
            messages = doc.get("messages")
            if not isinstance(messages, list) or len(messages) < 2:
                continue
            if any(not str(m.get("content") or "").strip() for m in messages):
                continue
            rows.append(doc)
    cap = ci("LYRA_MAX_ROWS")
    if cap and len(rows) > cap:
        rows = rows[:cap]
    log(f"dataset rows usable: {len(rows)}")
    if not rows:
        raise RuntimeError("zero usable rows; refusing to train (an adapter over no "
                           "data is random noise that looks like success)")
    return rows


def render_examples(rows: list[dict], tokenizer, max_length: int):
    from datasets import Dataset

    texts = []
    for doc in rows:
        try:
            rendered = tokenizer.apply_chat_template(
                doc["messages"], tokenize=False, add_generation_prompt=False)
        except Exception:
            rendered = "\n".join(
                f"<|{m.get('role')}|>\n{m.get('content')}" for m in doc["messages"])
        if rendered and len(rendered) > 32:
            texts.append({"text": rendered})

    if not texts:
        raise RuntimeError("no rows survived chat-template rendering")

    dataset = Dataset.from_list(texts)

    def tokenise(batch):
        out = tokenizer(batch["text"], truncation=True, max_length=max_length,
                        padding=False)
        out["labels"] = [ids[:] for ids in out["input_ids"]]
        return out

    return dataset.map(tokenise, batched=True, remove_columns=["text"])


# --------------------------------------------------------------------- train
def train(rows: list[dict]) -> dict:
    import torch
    from peft import LoraConfig, get_peft_model, prepare_model_for_kbit_training
    from transformers import (AutoModelForCausalLM, AutoTokenizer,
                              DataCollatorForLanguageModeling, Trainer,
                              TrainingArguments)

    base_id = cfg("LYRA_BASE_MODEL")
    log(f"loading tokenizer/base: {base_id}")
    tokenizer = AutoTokenizer.from_pretrained(base_id, trust_remote_code=False)
    if tokenizer.pad_token is None:
        tokenizer.pad_token = tokenizer.eos_token

    device_map = "auto" if torch.cuda.is_available() else "cpu"
    dtype = torch.float16 if torch.cuda.is_available() else torch.float32
    model = AutoModelForCausalLM.from_pretrained(
        base_id, torch_dtype=dtype, device_map=device_map, trust_remote_code=False)

    if torch.cuda.is_available():
        model = prepare_model_for_kbit_training(model, use_gradient_checkpointing=True)

    targets = detect_target_modules(model)
    log(f"lora target modules: {targets}")

    lora = LoraConfig(
        r=ci("LYRA_LORA_R"),
        lora_alpha=ci("LYRA_LORA_ALPHA"),
        lora_dropout=cf("LYRA_LORA_DROPOUT"),
        bias="none",
        task_type="CAUSAL_LM",
        target_modules=targets,
    )
    model = get_peft_model(model, lora)
    model.print_trainable_parameters()

    dataset = render_examples(rows, tokenizer, ci("LYRA_MAX_LENGTH"))
    log(f"tokenised rows: {len(dataset)}")

    adapter_dir = os.path.join(WORK, "lora_adapter")
    args = TrainingArguments(
        output_dir=os.path.join(WORK, "trainer_state"),
        per_device_train_batch_size=ci("LYRA_BATCH"),
        gradient_accumulation_steps=ci("LYRA_GRAD_ACCUM"),
        max_steps=ci("LYRA_MAX_STEPS"),
        learning_rate=cf("LYRA_LR"),
        lr_scheduler_type="cosine",
        warmup_ratio=0.03,
        logging_steps=10,
        save_strategy="no",
        report_to=[],
        fp16=torch.cuda.is_available(),
        bf16=False,
        optim="adamw_torch",
        gradient_checkpointing=torch.cuda.is_available(),
        seed=ci("LYRA_SEED"),
        data_seed=ci("LYRA_SEED"),
    )

    trainer = Trainer(
        model=model,
        args=args,
        train_dataset=dataset,
        data_collator=DataCollatorForLanguageModeling(tokenizer=tokenizer, mlm=False),
    )

    started = time.time()
    outcome = trainer.train()
    elapsed = time.time() - started

    trainer.save_model(adapter_dir)
    tokenizer.save_pretrained(adapter_dir)

    metrics = {
        "train_seconds": round(elapsed, 1),
        "steps": ci("LYRA_MAX_STEPS"),
        "train_loss": getattr(outcome, "training_loss", None),
        "trainable_params": int(sum(p.numel() for p in model.parameters() if p.requires_grad)),
        "target_modules": targets,
        "rows_used": len(rows),
    }
    log(f"training done in {elapsed:.0f}s loss={metrics['train_loss']}")
    return metrics


def detect_target_modules(model) -> list[str]:
    """Pick LoRA targets from the real module tree instead of a hardcoded list."""
    preferred = ["q_proj", "k_proj", "v_proj", "o_proj", "gate_proj", "up_proj", "down_proj"]
    present = set()
    for name, module in model.named_modules():
        leaf = name.rsplit(".", 1)[-1]
        if leaf in preferred and hasattr(module, "weight"):
            present.add(leaf)
    selected = [name for name in preferred if name in present]
    if not selected:
        raise RuntimeError("no known attention projection modules found; refusing to "
                           "guess LoRA targets")
    return selected


# --------------------------------------------------------------------- merge
def merge_adapter(base_id: str, adapter_dir: str) -> str:
    import torch
    from peft import PeftModel
    from transformers import AutoModelForCausalLM, AutoTokenizer

    merged_dir = os.path.join(WORK, "merged")
    log("merging adapter (cpu, fp16) to keep GPU free")
    base = AutoModelForCausalLM.from_pretrained(
        base_id, torch_dtype=torch.float16, device_map="cpu", trust_remote_code=False)
    model = PeftModel.from_pretrained(base, adapter_dir)
    merged = model.merge_and_unload()
    merged.save_pretrained(merged_dir, safe_serialization=True)
    AutoTokenizer.from_pretrained(base_id).save_pretrained(merged_dir)
    log(f"merged saved: {merged_dir}")
    return merged_dir


# ---------------------------------------------------------------------- gguf
def build_llama_cpp() -> str:
    if os.path.isdir(LLAMA_CPP_DIR):
        shutil.rmtree(LLAMA_CPP_DIR, ignore_errors=True)
    os.makedirs(os.path.dirname(LLAMA_CPP_DIR), exist_ok=True)
    run(["git", "clone", "--depth", "1",
         "https://github.com/ggml-org/llama.cpp.git", LLAMA_CPP_DIR])
    run([sys.executable, "-m", "pip", "install", "-q", "-r",
         os.path.join(LLAMA_CPP_DIR, "requirements", "requirements-convert_hf_to_gguf.txt")])
    build_dir = os.path.join(LLAMA_CPP_DIR, "build")
    run(["cmake", "-B", build_dir, "-DLLAMA_CURL=OFF", "-DGGML_NATIVE=OFF"],
        cwd=LLAMA_CPP_DIR)
    run(["cmake", "--build", build_dir, "--target", "llama-quantize", "-j", "4"],
        cwd=LLAMA_CPP_DIR)
    quantize = os.path.join(build_dir, "bin", "llama-quantize")
    if not os.path.isfile(quantize):
        raise RuntimeError("llama-quantize was not produced by the build")
    return quantize


def to_gguf(merged_dir: str, quant_type: str) -> str:
    converter = os.path.join(LLAMA_CPP_DIR, "convert_hf_to_gguf.py")
    if not os.path.isfile(converter):
        raise RuntimeError(f"converter missing at {converter}")

    f16_path = os.path.join(WORK, "lyralink-ft-f16.gguf")
    run([sys.executable, converter, merged_dir, "--outfile", f16_path, "--outtype", "f16"])

    quantize = build_llama_cpp()
    final_path = os.path.join(WORK, f"lyralink-ft-{quant_type}.gguf")
    run([quantize, f16_path, final_path, quant_type])

    if os.path.isfile(f16_path):
        os.remove(f16_path)
    if not os.path.isfile(final_path):
        raise RuntimeError("quantised gguf missing")
    log(f"gguf ready: {final_path} ({os.path.getsize(final_path)} bytes)")
    return final_path


# ---------------------------------------------------------------------- main
def main() -> int:
    manifest: dict = {
        "started_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "config": {k: cfg(k) for k in DEFAULTS},
        "gpu": gpu_report(),
        "status": "FAILED",
    }
    manifest_path = os.path.join(WORK, "training_manifest.json")

    def persist() -> None:
        manifest["finished_at"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        os.makedirs(WORK, exist_ok=True)
        with open(manifest_path, "w", encoding="utf-8") as handle:
            json.dump(manifest, handle, indent=2, sort_keys=True)

    try:
        if not manifest["gpu"]["torch_cuda"]:
            raise RuntimeError("no CUDA device visible; this kernel exists to use a GPU "
                               "(enable_gpu must be true and the accelerator selected)")
        log(f"gpu: {manifest['gpu']}")

        install_deps()
        dataset_path = locate_dataset()
        rows = load_rows(dataset_path)
        manifest["dataset"] = {"path": dataset_path, "rows": len(rows)}

        manifest["training"] = train(rows)
        dataset_path_out = os.path.join(WORK, "dataset_used.jsonl")
        with open(dataset_path_out, "w", encoding="utf-8") as handle:
            for row in rows:
                handle.write(json.dumps(row, ensure_ascii=False) + "\n")

        merged_dir = merge_adapter(cfg("LYRA_BASE_MODEL"), os.path.join(WORK, "lora_adapter"))
        gguf = to_gguf(merged_dir, cfg("LYRA_QUANT"))
        manifest["artifacts"] = {
            "gguf": os.path.basename(gguf),
            "gguf_bytes": os.path.getsize(gguf),
            "adapter_dir": os.path.join(WORK, "lora_adapter"),
        }
        manifest["status"] = "OK"
        log("SUCCESS")
        return 0
    except Exception as exc:
        manifest["error"] = f"{type(exc).__name__}: {exc}"
        manifest["traceback"] = traceback.format_exc()[-4000:]
        log(f"FAILURE: {manifest['error']}")
        return 1
    finally:
        persist()


if __name__ == "__main__":
    raise SystemExit(main())
