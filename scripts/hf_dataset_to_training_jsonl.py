#!/usr/bin/env python3
import argparse
import json
import os
import sys
from typing import Any, Dict, Iterable, List, Optional, Tuple


def _str(value: Any) -> str:
    if value is None:
        return ""
    if isinstance(value, str):
        return value.strip()
    if isinstance(value, (int, float, bool)):
        return str(value).strip()
    return ""


def _text_list(value: Any) -> List[str]:
    if isinstance(value, str):
        t = value.strip()
        return [t] if t else []
    if isinstance(value, list):
        out: List[str] = []
        for item in value:
            if isinstance(item, str):
                t = item.strip()
                if t:
                    out.append(t)
        return out
    return []


def _dict_get_ci(data: Any, key: str) -> Any:
    if not isinstance(data, dict):
        return None
    if key in data:
        return data[key]
    target = key.lower()
    for raw_key, raw_value in data.items():
        if isinstance(raw_key, str) and raw_key.lower() == target:
            return raw_value
    return None


def _row_json_fallback(row: Dict[str, Any]) -> str:
    try:
        return json.dumps(row, ensure_ascii=False, default=str)
    except Exception:
        return ""


def _first_scalar_field(row: Dict[str, Any]) -> Tuple[str, str]:
    for raw_key, raw_value in row.items():
        key = _str(raw_key)
        if not key:
            continue
        value = _str(raw_value)
        if value:
            return key, value
    return "", ""


def _pick_question(row: Dict[str, Any]) -> str:
    keys = ["question", "instruction", "prompt", "user", "query", "task", "input"]
    for k in keys:
        q = _str(_dict_get_ci(row, k))
        if q:
            return q

    label_text = _str(_dict_get_ci(row, "label_text"))
    text = _str(_dict_get_ci(row, "text"))
    sentence = _str(_dict_get_ci(row, "sentence"))

    if sentence:
        if label_text:
            return f"Classify this sentence: {sentence}"
        return f"Hugging Face sample: {sentence}"

    if text:
        if label_text:
            return f"Classify this text: {text}"
        return "Hugging Face sample"

    sentence = _str(_dict_get_ci(row, "sentence"))
    return ""


def _pick_answer(row: Dict[str, Any], label_names: Optional[List[str]]) -> str:
    answers_blob = _dict_get_ci(row, "answers")
    if isinstance(answers_blob, dict):
        texts = _text_list(_dict_get_ci(answers_blob, "text") or [])
        if texts:
            return texts[0]

    keys = ["answer", "response", "output", "completion", "assistant", "target"]
    for k in keys:
        a = _str(_dict_get_ci(row, k))
        if a:
            return a

    label_text = _str(_dict_get_ci(row, "label_text"))
    if label_text:
        return label_text

    label = _dict_get_ci(row, "label")
    if isinstance(label, int) and isinstance(label_names, list) and 0 <= label < len(label_names):
        return label_names[label]
    if label is not None:
        label_txt = _str(label)
        if label_txt:
            return label_txt

    text = _str(_dict_get_ci(row, "text"))
    if text:
        return text

    return ""


def _to_training_row(
    row: Dict[str, Any],
    repo_id: str,
    config: str,
    split: str,
    row_idx: int,
    label_names: Optional[List[str]],
) -> Optional[Dict[str, Any]]:
    question = _pick_question(row)
    answer = _pick_answer(row, label_names)

    if not question or not answer:
        first_key, first_value = _first_scalar_field(row)
        row_json = _row_json_fallback(row)
        if not question:
            question = f"Hugging Face row from {repo_id}"
            if first_key and first_value:
                question = f"{first_key}: {first_value[:260]}"
        if not answer and row_json:
            answer = row_json

    context = _str(_dict_get_ci(row, "context"))
    if context and answer and len(answer) < 1200:
        answer = f"{answer}\nContext: {context[:600]}"

    question = question.strip()[:400]
    answer = answer.strip()[:2200]
    if not question or not answer:
        return None

    return {
        "messages": [
            {"role": "user", "content": question},
            {"role": "assistant", "content": answer},
        ],
        "metadata": {
            "training_source": "hf_load_dataset",
            "repo_id": repo_id,
            "config": config,
            "split": split,
            "row_idx": row_idx,
        },
    }


def _iter_split_dataset(ds: Any) -> Iterable[Dict[str, Any]]:
    for row in ds:
        if isinstance(row, dict):
            yield row


def _label_names_for_split(ds: Any) -> Optional[List[str]]:
    try:
        feature = ds.features.get("label")
        names = getattr(feature, "names", None)
        if isinstance(names, list) and names:
            return [str(n) for n in names]
    except Exception:
        return None
    return None


def main() -> int:
    parser = argparse.ArgumentParser(description="Prepare HF dataset into training JSONL")
    parser.add_argument("--repo-id", required=True)
    parser.add_argument("--config", default="")
    parser.add_argument("--split", default="")
    parser.add_argument("--output", required=True)
    parser.add_argument("--max-rows", type=int, default=20000)
    args = parser.parse_args()

    repo_id = args.repo_id.strip()
    config = args.config.strip()
    split = args.split.strip()
    output_path = args.output
    max_rows = max(1, min(int(args.max_rows), 250000))

    token = os.getenv("HF_TOKEN", "").strip() or os.getenv("HUGGINGFACE_TOKEN", "").strip()

    try:
        from datasets import load_dataset
    except Exception as exc:
        print(json.dumps({"ok": False, "error": f"datasets_import_failed: {exc}"}))
        return 2

    load_kwargs: Dict[str, Any] = {"token": token if token else None}
    if split:
        load_kwargs["split"] = split

    try:
        if config:
            ds_obj = load_dataset(repo_id, config, **load_kwargs)
        else:
            ds_obj = load_dataset(repo_id, **load_kwargs)
    except Exception as exc:
        print(json.dumps({
            "ok": False,
            "error": "load_dataset_failed",
            "repo_id": repo_id,
            "config": config,
            "split": split,
            "details": str(exc),
        }))
        return 3

    os.makedirs(os.path.dirname(output_path), exist_ok=True)

    rows_seen = 0
    rows_written = 0
    rows_skipped = 0
    splits_used: List[str] = []

    with open(output_path, "w", encoding="utf-8") as handle:
        if hasattr(ds_obj, "items"):
            for split_name, split_ds in ds_obj.items():
                split_label = str(split_name)
                splits_used.append(split_label)
                label_names = _label_names_for_split(split_ds)
                for row in _iter_split_dataset(split_ds):
                    rows_seen += 1
                    training_row = _to_training_row(row, repo_id, config, split_label, rows_seen - 1, label_names)
                    if training_row is None:
                        rows_skipped += 1
                        continue
                    handle.write(json.dumps(training_row, ensure_ascii=False) + "\n")
                    rows_written += 1
                    if rows_written >= max_rows:
                        break
                if rows_written >= max_rows:
                    break
        else:
            split_label = split if split else "default"
            splits_used.append(split_label)
            label_names = _label_names_for_split(ds_obj)
            for row in _iter_split_dataset(ds_obj):
                rows_seen += 1
                training_row = _to_training_row(row, repo_id, config, split_label, rows_seen - 1, label_names)
                if training_row is None:
                    rows_skipped += 1
                    continue
                handle.write(json.dumps(training_row, ensure_ascii=False) + "\n")
                rows_written += 1
                if rows_written >= max_rows:
                    break

    print(json.dumps({
        "ok": True,
        "repo_id": repo_id,
        "config": config,
        "split": split,
        "output": output_path,
        "rows_seen": rows_seen,
        "rows_written": rows_written,
        "rows_skipped": rows_skipped,
        "splits": splits_used,
    }))
    return 0 if rows_written > 0 else 4


if __name__ == "__main__":
    sys.exit(main())
