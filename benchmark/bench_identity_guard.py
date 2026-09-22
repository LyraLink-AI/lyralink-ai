#!/usr/bin/env python3
"""Benchmark identity guard - make model drift loud instead of silent.

Why
---
Five runs of the same 100 tasks produced means from 76.81 to 80.02. Two of the causes
are avoidable, and both are invisible unless something checks for them:

  1. The model is addressed as `lyralink-auto-canary:latest`. `:latest` is a MUTABLE tag.
     Nothing in a run record says which weights actually served the request, so if the
     tag is rebuilt, every comparison against an older run is silently invalid. The
     current digest happens to be stable (957f2afe059571e4), which is exactly why this
     needs a guard: it will be stable until the day it is not.

  2. Sampling is unseeded. There is no `seed` field anywhere in the request path, so
     identical prompts sample differently every run. That is irreducible variance that
     cannot be measured away, only reported as a floor.

This script does not change scoring. It records the identity that a run was performed
against, refuses to let a changed identity pass unnoticed, and warms the model so the
first tasks are not measuring a cold load.

Usage
-----
    python3 bench_identity_guard.py --storage /root/bench_replica_5 \
        --model lyralink-auto-canary:latest --url http://127.0.0.1:11434

Exit codes
----------
    0  identity unchanged (or first observation recorded), model warm
    2  identity CHANGED since the recorded run - comparisons are not valid
    1  could not reach the inference host
"""
from __future__ import annotations

import argparse
import datetime as _dt
import json
import os
import sys
import urllib.error
import urllib.request

WARM_PROMPT = "Reply with the single word: ready"


def _get(url: str, timeout: int = 20) -> dict:
    req = urllib.request.Request(url, method="GET")
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8"))


def _post(url: str, payload: dict, timeout: int = 120) -> dict:
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        url, data=data, method="POST",
        headers={"Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8"))


def resolve_digest(base: str, model: str) -> str | None:
    """Digest of the weights the tag currently points at, or None if unknown."""
    try:
        tags = _get(base.rstrip("/") + "/api/tags")
    except (urllib.error.URLError, TimeoutError, OSError, ValueError):
        return None
    wanted = model.split(":")[0]
    for entry in tags.get("models", []) or []:
        name = str(entry.get("name") or "")
        if name == model or name.split(":")[0] == wanted:
            return str(entry.get("digest") or "") or None
    return None


def warm(base: str, model: str) -> bool:
    """One throwaway inference so run T001 is not the first load."""
    try:
        _post(
            base.rstrip("/") + "/api/generate",
            {"model": model, "prompt": WARM_PROMPT, "stream": False,
             "options": {"num_predict": 1, "temperature": 0}},
        )
        return True
    except (urllib.error.URLError, TimeoutError, OSError, ValueError):
        return False


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--storage", required=True, help="benchmark storage root for this run")
    ap.add_argument("--model", default=None, help="model tag; defaults to LOCAL_LLM_MODEL")
    ap.add_argument("--url", default=None, help="inference base URL; defaults to env")
    ap.add_argument("--no-warm", action="store_true")
    args = ap.parse_args()

    base = args.url or os.environ.get("REMOTE_LLM_BASE_URL") or "http://127.0.0.1:11434"
    model = args.model or os.environ.get("LOCAL_LLM_MODEL") or "lyralink-auto-canary:latest"

    print("benchmark identity guard")
    print("  endpoint : %s" % base)
    print("  model tag: %s" % model)

    digest = resolve_digest(base, model)
    if digest is None:
        print("  ERROR: could not resolve the model digest; refusing to certify this run")
        return 1
    print("  digest   : %s" % digest)

    record_path = os.path.join(args.storage, "model_identity.json")
    record = {
        "model": model,
        "digest": digest,
        "endpoint": base,
        "observed_at": _dt.datetime.now(_dt.timezone.utc).isoformat(timespec="seconds"),
    }

    previous = None
    if os.path.isfile(record_path):
        try:
            previous = json.load(open(record_path, encoding="utf-8"))
        except (OSError, ValueError):
            previous = None

    status = 0
    if isinstance(previous, dict) and previous.get("digest"):
        if previous["digest"] == digest:
            print("  identity : UNCHANGED since %s" % previous.get("observed_at", "?"))
        else:
            print("  identity : *** CHANGED ***")
            print("    previous: %s (%s)" % (previous["digest"], previous.get("observed_at", "?")))
            print("    current : %s" % digest)
            print("    The tag is the same but the weights are not. Every score comparison")
            print("    against the earlier run is invalid. Pin the old digest for a like-for-like")
            print("    comparison, or start a new baseline.")
            status = 2
    else:
        print("  identity : no prior record; recording a new baseline")

    os.makedirs(args.storage, exist_ok=True)
    tmp = record_path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(record, fh, indent=2, sort_keys=True)
        fh.write("\n")
    os.replace(tmp, record_path)
    print("  recorded : %s" % record_path)

    if not args.no_warm:
        ok = warm(base, model)
        print("  warmup   : %s" % ("done (first task no longer measures a cold load)" if ok
                                  else "FAILED (scores may carry a cold-start penalty)"))
        if not ok and status == 0:
            status = 1

    print("  verdict  : %s" % {0: "OK", 2: "IDENTITY CHANGED", 1: "WARMUP/UNAVAILABLE"}[status])
    return status


if __name__ == "__main__":
    sys.exit(main())
