#!/usr/bin/env python3
"""Drive a Lyralink fine-tune on Kaggle from the web box.

Pipeline:
    1. write a provenance-filtered dataset bundle
    2. create or version a private Kaggle dataset
    3. push a script kernel that trains + merges + converts to GGUF
    4. poll until the kernel stops
    5. download the output artifacts

Shares (so the Kaggle host does the expensive half):
    - the Kaggle GPU does train / merge / quantise
    - this host only downloads a ~2 GiB GGUF and runs `ollama create`

Credentials are read from the Lyralink .env (KAGGLE_USERNAME / KAGGLE_KEY) or an
existing ~/.kaggle/kaggle.json. They are written to a private config dir with
0600 and handed to the kaggle CLI; they are never echoed and never placed on a
command line.

Usage:
    kaggle_train_runner.py --dataset-file storage/model_training/train_export.jsonl
    kaggle_train_runner.py --dry-run          # build everything, call nothing
    kaggle_train_runner.py --check            # report credential/config readiness
    kaggle_train_runner.py --download-only    # re-fetch the last kernel output

Exit codes
    0 success   1 training failed   2 configuration error   3 timeout
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import time
from datetime import datetime, timezone

DEFAULT_ROOT = os.environ.get("LYRALINK_WORKSPACE_ROOT",
                              "/var/www/vhosts/lyralinkai.com/httpdocs")

STATUS_COMPLETE = {"complete", "kernelworkerstatus.complete"}
STATUS_ERROR = {"error", "kernelworkerstatus.error", "cancelled",
                "kernelworkerstatus.cancelled"}


def log(message: str) -> None:
    print(f"[{datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')}] kaggle_runner {message}",
          flush=True)


def read_env(path: str) -> dict[str, str]:
    out: dict[str, str] = {}
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as handle:
            for line in handle:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, _, value = line.partition("=")
                out[key.strip()] = value.strip().strip('"').strip("'")
    except OSError:
        pass
    return out


def slugify(value: str) -> str:
    value = re.sub(r"[^a-z0-9]+", "-", value.lower()).strip("-")
    return value or "lyralink-finetune"


class KaggleCLI:
    """Thin wrapper around the official kaggle CLI, with isolated config.

    Authentication supports both credential styles:
      * scoped token (KGAT_*): passed as KAGGLE_API_TOKEN in the environment, so
        it never appears in an argv (visible via ps) and never on disk twice.
      * legacy username/key: written to a 0600 kaggle.json inside an isolated
        config dir.
    """

    def __init__(self, cli_path: str, config_dir: str, api_token: str = ""):
        self.cli = cli_path
        self.config_dir = config_dir
        self.env = dict(os.environ)
        self.env["KAGGLE_CONFIG_DIR"] = config_dir
        self.env["PYTHONWARNINGS"] = "ignore"
        if api_token:
            self.env["KAGGLE_API_TOKEN"] = api_token

    def version(self) -> str:
        result = self.run(["--version"], check=False, timeout=60)
        text = ((result.stdout or "") + (result.stderr or "")).strip()
        return text.splitlines()[-1].strip() if text else "unknown"

    def account_name(self) -> str:
        """Resolve the account behind a scoped token.

        A KGAT_ token carries no username, but dataset and kernel ids are
        owner/slug. With auth_method=ACCESS_TOKEN the client introspects the
        token and reports the account, so this is discovered, not configured.
        """
        result = self.run(["config", "view"], check=False, timeout=90)
        text = (result.stdout or "") + (result.stderr or "")
        match = re.search(r"^\s*-\s*username:\s*(\S+)\s*$", text, re.MULTILINE)
        return match.group(1).strip() if match else ""

    def run(self, args: list[str], check: bool = True, timeout: int = 1800) -> subprocess.CompletedProcess:
        cmd = [self.cli, *args]
        log("kaggle " + " ".join(args))
        result = subprocess.run(cmd, env=self.env, capture_output=True, text=True,
                                timeout=timeout)
        if check and result.returncode != 0:
            combined = (result.stdout or "") + (result.stderr or "")
            raise RuntimeError(f"kaggle {' '.join(args)} failed rc={result.returncode}: "
                               f"{combined.strip()[-1500:]}")
        return result


def find_cli(root: str) -> str | None:
    """Prefer the Python 3.12 venv: scoped KGAT_ tokens need kaggle >= 1.8, and
    that requires Python >= 3.11 which this host's system interpreter is not."""
    for candidate in (
        os.path.join(root, ".venv-kaggle312", "bin", "kaggle"),
        os.path.join(root, ".venv-kaggle", "bin", "kaggle"),
        shutil.which("kaggle") or "",
    ):
        if candidate and os.path.isfile(candidate) and os.access(candidate, os.X_OK):
            return candidate
    return None


def write_credentials(config_dir: str, username: str, key: str) -> str:
    os.makedirs(config_dir, exist_ok=True)
    os.chmod(config_dir, 0o700)
    path = os.path.join(config_dir, "kaggle.json")
    with open(path, "w", encoding="utf-8") as handle:
        json.dump({"username": username, "key": key}, handle)
    os.chmod(path, 0o600)
    return path


# ------------------------------------------------------------------ bundling
def build_kernel_dir(root: str, work_dir: str, owner: str, dataset_slug: str,
                     kernel_slug: str, config: dict) -> str:
    kernel_dir = os.path.join(work_dir, "kernel")
    os.makedirs(kernel_dir, exist_ok=True)

    source = os.path.join(root, "scripts", "kaggle_kernel_train.py")
    if not os.path.isfile(source):
        raise RuntimeError(f"kernel source missing: {source}")

    # Copy as the metadata's code_file so the pushed kernel matches the source.
    shutil.copy2(source, os.path.join(kernel_dir, "train.py"))

    metadata = {
        "id": f"{owner}/{kernel_slug}",
        "title": "Lyralink LoRA Fine-tune",
        "code_file": "train.py",
        "language": "python",
        "kernel_type": "script",
        "is_private": True,
        "enable_gpu": True,
        "enable_internet": True,
        "dataset_sources": [f"{owner}/{dataset_slug}"],
        "competition_sources": [],
        "kernel_sources": [],
        "model_sources": [],
    }
    with open(os.path.join(kernel_dir, "kernel-metadata.json"), "w", encoding="utf-8") as handle:
        json.dump(metadata, handle, indent=2, sort_keys=True)

    # Kernel reads its knobs from these; Kaggle has no separate env mechanism for
    # script kernels, so they are injected into a JSON file the kernel can read.
    with open(os.path.join(kernel_dir, "lyra_config.json"), "w", encoding="utf-8") as handle:
        json.dump(config, handle, indent=2, sort_keys=True)

    return kernel_dir


def build_dataset_dir(work_dir: str, owner: str, dataset_slug: str, dataset_file: str) -> str:
    dataset_dir = os.path.join(work_dir, "dataset")
    os.makedirs(dataset_dir, exist_ok=True)
    target = os.path.join(dataset_dir, "dataset.jsonl")
    shutil.copy2(dataset_file, target)

    rows = 0
    with open(target, "r", encoding="utf-8", errors="replace") as handle:
        for line in handle:
            if line.strip():
                rows += 1
    if rows == 0:
        raise RuntimeError("refusing to upload an empty dataset")

    metadata = {
        "title": "Lyralink Training Corpus",
        "id": f"{owner}/{dataset_slug}",
        "licenses": [{"name": "other"}],
    }
    with open(os.path.join(dataset_dir, "dataset-metadata.json"), "w", encoding="utf-8") as handle:
        json.dump(metadata, handle, indent=2, sort_keys=True)

    log(f"dataset bundle: {rows} rows -> {target}")
    return dataset_dir


# ------------------------------------------------------------------- polling
def poll_kernel(cli: KaggleCLI, kernel_id: str, timeout_s: int, interval_s: int = 45) -> str:
    deadline = time.time() + timeout_s
    last = ""
    while time.time() < deadline:
        result = cli.run(["kernels", "status", kernel_id], check=False, timeout=120)
        text = ((result.stdout or "") + (result.stderr or "")).strip()
        lowered = text.lower()
        if text and text != last:
            log(f"kernel status: {text}")
            last = text
        for complete in STATUS_COMPLETE:
            if complete in lowered:
                return "COMPLETE"
        for error in STATUS_ERROR:
            if error in lowered:
                return "ERROR"
        time.sleep(interval_s)
    return "TIMEOUT"


def read_manifest(out_dir: str) -> dict:
    path = os.path.join(out_dir, "training_manifest.json")
    if not os.path.isfile(path):
        return {}
    try:
        with open(path, "r", encoding="utf-8") as handle:
            return json.load(handle)
    except ValueError:
        return {}


# ---------------------------------------------------------------------- main
def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run a Lyralink fine-tune on Kaggle")
    parser.add_argument("--root", default=DEFAULT_ROOT)
    parser.add_argument("--dataset-file",
                        default=None,
                        help="Provenance-filtered training JSONL (required unless --check)")
    parser.add_argument("--work-dir", default=None)
    parser.add_argument("--dry-run", action="store_true", help="Build the bundle, call no API")
    parser.add_argument("--check", action="store_true", help="Report readiness only")
    parser.add_argument("--download-only", action="store_true")
    parser.add_argument("--timeout", type=int, default=10800, help="Max wait in seconds")
    parser.add_argument("--poll-interval", type=int, default=45)
    parser.add_argument("--max-steps", type=int, default=120)
    parser.add_argument("--max-rows", type=int, default=6000)
    parser.add_argument("--max-length", type=int, default=1024)
    parser.add_argument("--quant", default="Q4_K_M")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(list(sys.argv[1:] if argv is None else argv))

    work_dir = args.work_dir or os.path.join(args.root, "storage", "model_training", "kaggle")
    os.makedirs(work_dir, exist_ok=True)

    env = read_env(os.path.join(args.root, ".env"))
    api_token = env.get("KAGGLE_API_TOKEN", "").strip()
    username = env.get("KAGGLE_USERNAME", "").strip()
    key = env.get("KAGGLE_KEY", "").strip()

    cli_path = find_cli(args.root)
    config_dir = os.path.join(work_dir, "kaggle_config")

    probe = KaggleCLI(cli_path, config_dir, api_token) if cli_path else None
    if not username and api_token and probe is not None:
        discovered = probe.account_name()
        if discovered:
            username = discovered
            log(f"account resolved from scoped token: {username}")

    legacy_ready = bool(username and key)
    auth_ready = bool(api_token and username) or legacy_ready

    dataset_slug = slugify(env.get("LYRALINK_KAGGLE_DATASET_SLUG", "lyralink-training-corpus"))
    kernel_slug = slugify(env.get("LYRALINK_KAGGLE_KERNEL_SLUG", "lyralink-lora-finetune"))

    if args.check:
        print("kaggle runner readiness")
        print(f"  root           : {args.root}")
        print(f"  work dir       : {work_dir}")
        print(f"  kaggle cli     : {cli_path or 'NOT FOUND (pip install kaggle>=1.8)'}")
        if probe is not None:
            print(f"  cli version    : {probe.version()}")
        mode = "scoped token (KAGGLE_API_TOKEN)" if api_token else "legacy username/key"
        print(f"  auth mode      : {mode if auth_ready else 'none configured'}")
        print(f"  account        : {username or 'unknown'}")
        print(f"  dataset slug   : {dataset_slug}")
        print(f"  kernel slug    : {kernel_slug}")
        kernel_src = os.path.join(args.root, "scripts", "kaggle_kernel_train.py")
        print(f"  kernel source  : {'ok' if os.path.isfile(kernel_src) else 'MISSING'}")
        if args.dataset_file:
            print(f"  dataset file   : {args.dataset_file} "
                  f"({'ok' if os.path.isfile(args.dataset_file) else 'MISSING'})")
        if not username:
            print("  note           : account unknown; kernel ids need owner/slug")
        ready = bool(cli_path and auth_ready and os.path.isfile(kernel_src))
        print(f"  ready          : {'yes' if ready else 'no'}")
        return 0 if ready else 2

    if not args.download_only:
        if not args.dataset_file:
            log("ERROR: --dataset-file is required")
            return 2
        if not os.path.isfile(args.dataset_file):
            log(f"ERROR: dataset file not found: {args.dataset_file}")
            return 2

    config = {
        "LYRA_BASE_MODEL": env.get("CONTINUOUS_FINETUNE_HF_BASE_MODEL",
                                   "NousResearch/Hermes-3-Llama-3.2-3B"),
        "LYRA_MAX_STEPS": str(args.max_steps),
        "LYRA_MAX_ROWS": str(args.max_rows),
        "LYRA_MAX_LENGTH": str(args.max_length),
        "LYRA_QUANT": args.quant,
    }

    kernel_dir = build_kernel_dir(args.root, work_dir, username or "OWNER",
                                 dataset_slug, kernel_slug, config)
    dataset_dir = None
    if not args.download_only:
        dataset_dir = build_dataset_dir(work_dir, username or "OWNER", dataset_slug,
                                        args.dataset_file)

    kernel_id = f"{username or 'OWNER'}/{kernel_slug}"
    out_dir = os.path.join(work_dir, "output")
    os.makedirs(out_dir, exist_ok=True)

    if args.dry_run:
        log("DRY RUN - nothing was uploaded")
        log(f"would use kernel id : {kernel_id}")
        log(f"kernel dir          : {kernel_dir}")
        log(f"dataset dir         : {dataset_dir}")
        log(f"output dir          : {out_dir}")
        log(f"config              : {json.dumps(config, sort_keys=True)}")
        if not cli_path:
            log("NOTE: kaggle CLI not installed; would fail at push")
        if not (username and key):
            log("NOTE: credentials missing; would fail at push")
        return 0

    if not cli_path:
        log("ERROR: kaggle CLI not found. Install into .venv-kaggle312 "
            "(kaggle>=1.8 needs Python>=3.11): uv pip install kaggle")
        return 2
    if not auth_ready:
        log("ERROR: no usable Kaggle credentials.")
        log("Set KAGGLE_API_TOKEN (scoped KGAT_ token) in .env, or the legacy "
            "KAGGLE_USERNAME + KAGGLE_KEY pair.")
        return 2

    if not api_token:
        # Legacy mode only: the scoped token is passed through the environment
        # so it is never materialised on disk a second time.
        write_credentials(config_dir, username, key)
    cli = KaggleCLI(cli_path, config_dir, api_token)

    # 1. dataset: create first time, then publish a new version.
    status = cli.run(["datasets", "status", f"{username}/{dataset_slug}"],
                     check=False, timeout=120)
    exists = "ready" in ((status.stdout or "") + (status.stderr or "")).lower()
    if exists:
        log("dataset exists; publishing new version")
        result = cli.run(["datasets", "version", "-p", dataset_dir,
                          "-m", f"lyralink update {datetime.now(timezone.utc).isoformat()}"],
                         check=False, timeout=1800)
        if result.returncode != 0:
            combined = ((result.stdout or "") + (result.stderr or "")).lower()
            if "unchanged" not in combined and "already" not in combined:
                log("ERROR: dataset version failed")
                log(((result.stdout or "") + (result.stderr or ""))[-1200:])
                return 2
            log("dataset content unchanged; reusing existing version")
    else:
        cli.run(["datasets", "create", "-p", dataset_dir], timeout=1800)

    # 2. push the kernel.
    cli.run(["kernels", "push", "-p", kernel_dir], timeout=1800)

    # 3. wait.
    outcome = poll_kernel(cli, kernel_id, args.timeout, args.poll_interval)
    if outcome == "TIMEOUT":
        log(f"ERROR: kernel did not finish within {args.timeout}s")
        return 3

    # 4. always try to fetch output so a failed run still yields its manifest.
    fetch = cli.run(["kernels", "output", kernel_id, "-p", out_dir], check=False, timeout=1800)
    if fetch.returncode != 0:
        log("WARNING: could not download kernel output")

    manifest = read_manifest(out_dir)
    if outcome == "ERROR" or manifest.get("status") != "OK":
        log(f"ERROR: kernel did not succeed (status={outcome}, "
            f"manifest={manifest.get('status')!r})")
        if manifest.get("error"):
            log(f"remote error: {manifest['error']}")
        if manifest.get("traceback"):
            log("remote traceback tail:\n" + str(manifest["traceback"])[-1500:])
        return 1

    gguf = manifest.get("artifacts", {}).get("gguf")
    if not gguf or not os.path.isfile(os.path.join(out_dir, gguf)):
        log(f"ERROR: manifest reports success but gguf is absent ({gguf!r})")
        return 1

    log(f"SUCCESS gguf={os.path.join(out_dir, gguf)} "
        f"bytes={manifest['artifacts'].get('gguf_bytes')} "
        f"loss={manifest.get('training', {}).get('train_loss')}")
    print(json.dumps({"gguf": os.path.join(out_dir, gguf), "manifest": manifest}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
