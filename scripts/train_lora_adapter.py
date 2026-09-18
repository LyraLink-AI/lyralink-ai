#!/usr/bin/env python3
import argparse
import inspect
import json
import os
import sys
from typing import Any


def load_jsonl(path: str, max_rows: int) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    with open(path, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            try:
                rows.append(json.loads(line))
            except Exception:
                continue
            if len(rows) >= max_rows:
                break
    return rows


def to_prompt_rows(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    for r in rows:
        msgs = r.get("messages")
        if not isinstance(msgs, list):
            continue
        metadata = r.get("metadata") if isinstance(r.get("metadata"), dict) else {}
        sample_weight_raw = metadata.get("sample_weight", 1.0)
        sample_weight = 1.0
        if isinstance(sample_weight_raw, (int, float)):
            sample_weight = float(sample_weight_raw)
        elif isinstance(sample_weight_raw, str):
            try:
                sample_weight = float(sample_weight_raw)
            except Exception:
                sample_weight = 1.0
        sample_weight = max(0.1, min(3.0, sample_weight))

        user_parts: list[str] = []
        assistant_parts: list[str] = []
        for m in msgs:
            if not isinstance(m, dict):
                continue
            role = str(m.get("role", "")).strip().lower()
            content = str(m.get("content", "")).strip()
            if not content:
                continue
            if role == "user":
                user_parts.append(content)
            elif role == "assistant":
                assistant_parts.append(content)
        if not user_parts or not assistant_parts:
            continue
        prompt = "User: " + "\n".join(user_parts) + "\nAssistant:"
        completion = "\n".join(assistant_parts)
        out.append({"text": prompt + " " + completion, "sample_weight": sample_weight})
    return out


def detect_lora_target_modules(model: Any) -> list[str]:
    names = [name for name, _ in model.named_modules()]
    endings = [n.split(".")[-1] for n in names]

    candidate_sets = [
        ["q_proj", "k_proj", "v_proj", "o_proj", "gate_proj", "up_proj", "down_proj"],
        ["q_proj", "k_proj", "v_proj", "o_proj"],
        ["c_attn", "c_proj", "c_fc"],
        ["query_key_value", "dense", "dense_h_to_4h", "dense_4h_to_h"],
    ]

    for candidate in candidate_sets:
        if any(mod in endings for mod in candidate):
            selected = [mod for mod in candidate if mod in endings]
            if selected:
                return selected

    # Last-resort fallback for unknown architectures.
    linear_like = sorted({
        end for end in endings
        if end in {"q_proj", "k_proj", "v_proj", "o_proj", "c_attn", "c_proj", "c_fc", "fc1", "fc2"}
    })
    return linear_like or ["c_attn", "c_proj"]


def main() -> int:
    parser = argparse.ArgumentParser(description="Train a LoRA adapter from Lyralink JSONL")
    parser.add_argument("--train-file", required=True)
    parser.add_argument("--base-model", required=True)
    parser.add_argument("--output-dir", required=True)
    parser.add_argument("--max-rows", type=int, default=2500)
    parser.add_argument("--max-steps", type=int, default=30)
    parser.add_argument("--lr", type=float, default=2e-4)
    parser.add_argument("--batch-size", type=int, default=1)
    parser.add_argument("--grad-accum", type=int, default=8)
    parser.add_argument("--max-length", type=int, default=256)
    args = parser.parse_args()

    # Heavy imports are delayed so callers can detect missing dependencies cleanly.
    try:
        import torch
        from datasets import Dataset
        from peft import LoraConfig, get_peft_model
        from transformers import AutoModelForCausalLM, AutoTokenizer, Trainer, TrainingArguments
    except Exception as exc:
        print(f"lora_train missing_deps error={exc}")
        return 2

    if not os.path.isfile(args.train_file):
        print(f"lora_train missing_train_file path={args.train_file}")
        return 1

    rows = load_jsonl(args.train_file, max(32, args.max_rows))
    train_rows = to_prompt_rows(rows)
    if len(train_rows) < 16:
        print(f"lora_train too_few_rows count={len(train_rows)}")
        return 3

    os.makedirs(args.output_dir, exist_ok=True)

    tokenizer = AutoTokenizer.from_pretrained(args.base_model, use_fast=True)
    if tokenizer.pad_token is None:
        tokenizer.pad_token = tokenizer.eos_token

    dtype = torch.float16 if torch.cuda.is_available() else torch.float32
    model = AutoModelForCausalLM.from_pretrained(
        args.base_model,
        torch_dtype=dtype,
        low_cpu_mem_usage=True,
        device_map="auto" if torch.cuda.is_available() else None,
    )
    model.config.use_cache = False

    target_modules = detect_lora_target_modules(model)

    lora_cfg = LoraConfig(
        r=8,
        lora_alpha=16,
        lora_dropout=0.05,
        bias="none",
        task_type="CAUSAL_LM",
        target_modules=target_modules,
    )
    model = get_peft_model(model, lora_cfg)

    ds = Dataset.from_list(train_rows)

    def tok(ex: dict[str, Any]) -> dict[str, Any]:
        t = tokenizer(ex["text"], truncation=True, max_length=max(64, args.max_length), padding="max_length")
        t["labels"] = t["input_ids"].copy()
        t["sample_weight"] = float(ex.get("sample_weight", 1.0))
        return t

    tokenized = ds.map(tok, remove_columns=ds.column_names)

    train_arg_kwargs: dict[str, Any] = {
        "output_dir": args.output_dir,
        "per_device_train_batch_size": max(1, args.batch_size),
        "gradient_accumulation_steps": max(1, args.grad_accum),
        "num_train_epochs": 1,
        "max_steps": max(1, args.max_steps),
        "learning_rate": args.lr,
        "logging_steps": 5,
        "save_steps": max(10, args.max_steps),
        "save_total_limit": 2,
        "report_to": [],
        "dataloader_pin_memory": False,
        "fp16": torch.cuda.is_available(),
        "bf16": False,
    }
    train_arg_kwargs["warmup_steps"] = max(1, int(args.max_steps * 0.03))

    signature_params = inspect.signature(TrainingArguments.__init__).parameters
    has_var_kwargs = any(p.kind == inspect.Parameter.VAR_KEYWORD for p in signature_params.values())
    if not has_var_kwargs:
        train_arg_kwargs = {k: v for k, v in train_arg_kwargs.items() if k in signature_params}

    try:
        train_args = TrainingArguments(**train_arg_kwargs)
    except TypeError:
        # Some releases remove optional fields; retry with a conservative subset.
        for optional_key in ["warmup_ratio", "warmup_steps", "report_to", "dataloader_pin_memory", "bf16", "fp16"]:
            train_arg_kwargs.pop(optional_key, None)
        train_args = TrainingArguments(**train_arg_kwargs)

    class WeightedTrainer(Trainer):
        def compute_loss(self, model, inputs, return_outputs=False, num_items_in_batch=None):
            sample_weight = inputs.pop("sample_weight", None)
            outputs = model(**inputs)
            logits = outputs.logits
            labels = inputs["labels"]

            shift_logits = logits[..., :-1, :].contiguous()
            shift_labels = labels[..., 1:].contiguous()

            import torch.nn.functional as F

            token_loss = F.cross_entropy(
                shift_logits.view(-1, shift_logits.size(-1)),
                shift_labels.view(-1),
                ignore_index=-100,
                reduction="none",
            ).view(shift_labels.size())

            token_mask = shift_labels.ne(-100).float()
            per_example_denom = token_mask.sum(dim=1).clamp_min(1.0)
            per_example_loss = (token_loss * token_mask).sum(dim=1) / per_example_denom

            if sample_weight is None:
                loss = per_example_loss.mean()
            else:
                sample_weight = sample_weight.to(per_example_loss.device).float().clamp(min=0.1, max=3.0)
                loss = (per_example_loss * sample_weight).sum() / sample_weight.sum().clamp_min(1e-6)

            return (loss, outputs) if return_outputs else loss

    trainer = WeightedTrainer(model=model, args=train_args, train_dataset=tokenized)
    trainer.train()
    model.save_pretrained(args.output_dir)
    tokenizer.save_pretrained(args.output_dir)

    print(f"lora_train ok output_dir={args.output_dir} rows={len(train_rows)} steps={args.max_steps}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
