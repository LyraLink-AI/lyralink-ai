#!/usr/bin/env python3
import base64
import hashlib
import io
import os
import random
import shutil
import subprocess
import tempfile
import textwrap
import time
from pathlib import Path
from typing import Any, Dict, Optional

import requests
from PIL import Image, ImageDraw, ImageFont
from dotenv import load_dotenv

load_dotenv()

SERVER_URL = os.getenv("LYRALINK_SERVER_URL", "https://lyralinkai.com").rstrip("/")
WORKER_ENDPOINT = SERVER_URL + "/api/render_worker.php"
RENDER_KEY = os.getenv("RENDER_WORKER_SHARED_KEY", "").strip()
WORKER_NAME = os.getenv("RENDER_WORKER_NAME", "gtx1660-worker").strip() or "gtx1660-worker"
POLL_INTERVAL = max(3, int(os.getenv("RENDER_WORKER_POLL_INTERVAL", "8")))
REQUEST_TIMEOUT = max(15, int(os.getenv("RENDER_WORKER_REQUEST_TIMEOUT", "120")))
FFMPEG_TIMEOUT = max(30, int(os.getenv("RENDER_WORKER_FFMPEG_TIMEOUT", "240")))
SD_WEBUI_URL = os.getenv("SD_WEBUI_URL", "http://127.0.0.1:7860").rstrip("/")
SD_NEGATIVE_PROMPT = os.getenv(
    "SD_NEGATIVE_PROMPT",
    "blurry, low quality, artifact, jpeg artifacts, distorted face, watermark, text, logo, oversaturated",
)
SD_STEPS = max(16, int(os.getenv("SD_STEPS", "30")))
SD_CFG = float(os.getenv("SD_CFG", "7.0"))
SD_SAMPLER = os.getenv("SD_SAMPLER", "DPM++ 2M Karras")
SD_WIDTH = max(512, int(os.getenv("SD_WIDTH", "1024")))
SD_HEIGHT = max(288, int(os.getenv("SD_HEIGHT", "576")))
ENABLE_CAPTIONS = os.getenv("ENABLE_CAPTIONS", "1").strip().lower() not in {"0", "false", "no", "off"}
ENABLE_BACKGROUND_MUSIC = os.getenv("ENABLE_BACKGROUND_MUSIC", "1").strip().lower() not in {"0", "false", "no", "off"}
BACKGROUND_MUSIC_DIR = os.getenv("BACKGROUND_MUSIC_DIR", "").strip()
BACKGROUND_MUSIC_MOOD = os.getenv("BACKGROUND_MUSIC_MOOD", "tech_cinematic").strip() or "tech_cinematic"
BACKGROUND_MUSIC_VOLUME = max(0.03, min(float(os.getenv("BACKGROUND_MUSIC_VOLUME", "0.18")), 0.8))
MOTION_PROVIDER = (os.getenv("MOTION_PROVIDER", "auto").strip() or "auto").lower()
MOTION_API_URL = os.getenv("MOTION_API_URL", "").strip()
MOTION_STRENGTH = max(0.05, min(float(os.getenv("MOTION_STRENGTH", "0.58")), 1.0))
DEFAULT_FPS = max(24, int(os.getenv("DEFAULT_FPS", "30")))
REMOTE_MUSIC_PROVIDER = (os.getenv("REMOTE_MUSIC_PROVIDER", "internet_archive").strip() or "internet_archive").lower()
REMOTE_MUSIC_CACHE_DIR = os.getenv("REMOTE_MUSIC_CACHE_DIR", "").strip()
REMOTE_MUSIC_QUERY_OVERRIDE = os.getenv("REMOTE_MUSIC_QUERY", "").strip()
REMOTE_MUSIC_BLOCK_TERMS = {
    "librivox",
    "audiobook",
    "podcast",
    "episode",
    "speech",
    "sermon",
    "lecture",
    "lesson",
    "interview",
    "radio",
    "ost",
    "soundtrack",
}

WORK_DIR = Path(os.getenv("WORK_DIR", str(Path(__file__).resolve().parent / "work"))).expanduser().resolve()
WORK_DIR.mkdir(parents=True, exist_ok=True)


def ensure_free_space(target_dir: Path, min_mb: int = 4096) -> None:
    try:
        usage = shutil.disk_usage(target_dir)
    except OSError as exc:
        raise WorkerError(f"Could not inspect disk usage for {target_dir}: {exc}")
    free_mb = usage.free / (1024 * 1024)
    if free_mb < min_mb:
        raise WorkerError(f"Not enough free space in {target_dir}: {free_mb:.0f}MB free, need at least {min_mb}MB")


STYLE_BUNDLES: dict[str, dict[str, Any]] = {
    "cinematic_marketing": {
        "look": [
            "premium technology commercial",
            "cinematic startup launch campaign",
            "luxury SaaS advertisement",
        ],
        "palette": [
            "electric cyan and amber",
            "gunmetal, gold, and cool white",
            "deep navy with warm accent lighting",
        ],
        "camera": [
            "35mm anamorphic lens",
            "low-angle hero framing",
            "dynamic close-mid product composition",
        ],
        "scene_roles": {
            "hook": [
                "unexpected visual hook, magnetic composition, scroll-stopping energy",
                "bold opening reveal with atmospheric depth and premium brand tension",
            ],
            "proof": [
                "show workflow intelligence, operator dashboard feel, active systems, real momentum",
                "show automation proving itself with motion-design clarity and tactical detail",
            ],
            "cta": [
                "authoritative payoff frame, clean call-to-action energy, confident finish",
                "brand-signature closing shot with momentum and executive polish",
            ],
        },
    },
    "founder_story": {
        "look": [
            "documentary-style founder campaign",
            "gritty but premium startup story",
        ],
        "palette": [
            "charcoal, steel blue, and tungsten highlights",
            "warm practical lights against dark workspace tones",
        ],
        "camera": [
            "handheld-inspired cinematic framing",
            "close character-first composition",
        ],
        "scene_roles": {
            "hook": [
                "late-night breakthrough moment, intensity, ambition, momentum",
                "emotional curiosity with human stakes and product obsession",
            ],
            "proof": [
                "team-level execution, dashboards, shipping velocity, real systems at work",
                "proof through outcomes, not hype, with grounded premium realism",
            ],
            "cta": [
                "resolved confident ending, clear next move, high trust",
                "aspirational but concrete closing image with forward motion",
            ],
        },
    },
    "neon_operator": {
        "look": [
            "futuristic operator ad campaign",
            "cyber-noir product commercial",
        ],
        "palette": [
            "neon teal, graphite, and copper glow",
            "magenta accents with dark glass reflections",
        ],
        "camera": [
            "sharp wide-to-mid cinematic framing",
            "sleek editorial ad composition",
        ],
        "scene_roles": {
            "hook": [
                "mysterious high-energy opening visual with premium sci-fi restraint",
                "bold reveal shot with luminous UI reflections and atmospheric tension",
            ],
            "proof": [
                "operator command center, layered screens, intelligent orchestration",
                "visualize active systems, autonomous workflow, elegant control",
            ],
            "cta": [
                "refined closing frame, elite product confidence, memorable finish",
                "high-end closing signature with sleek motion and polished authority",
            ],
        },
    },
}


class WorkerError(Exception):
    pass


def coerce_bool(value: Any, default: bool) -> bool:
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return value != 0
    raw = str(value).strip().lower()
    if raw == "":
        return default
    return raw not in {"0", "false", "no", "off"}


def payload_bool(payload: Dict[str, Any], key: str, default: bool) -> bool:
    return coerce_bool(payload.get(key), default)


def payload_str(payload: Dict[str, Any], key: str, default: str) -> str:
    value = payload.get(key)
    if value is None:
        return default
    text = str(value).strip()
    return text if text else default


def load_font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    candidates = []
    if bold:
        candidates.extend([
            "/usr/share/fonts/google-noto/NotoSans-Bold.ttf",
            "/usr/share/fonts/noto/NotoSans-Bold.ttf",
            "/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf",
            "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
            "C:/Windows/Fonts/arialbd.ttf",
        ])
    else:
        candidates.extend([
            "/usr/share/fonts/google-noto/NotoSans-Regular.ttf",
            "/usr/share/fonts/noto/NotoSans-Regular.ttf",
            "/usr/share/fonts/dejavu/DejaVuSans.ttf",
            "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
            "C:/Windows/Fonts/arial.ttf",
        ])

    for candidate in candidates:
        try:
            return ImageFont.truetype(candidate, size)
        except OSError:
            continue

    return ImageFont.load_default()


def post_json(payload: Dict[str, Any]) -> Dict[str, Any]:
    headers = {
        "Content-Type": "application/json",
        "X-Render-Worker-Key": RENDER_KEY,
    }
    resp = requests.post(WORKER_ENDPOINT, headers=headers, json=payload, timeout=REQUEST_TIMEOUT)
    resp.raise_for_status()
    data = resp.json()
    if not data.get("success"):
        raise WorkerError(str(data.get("error") or "unknown server error"))
    return data


def sd_api_available() -> tuple[bool, str]:
    try:
        resp = requests.get(SD_WEBUI_URL + "/sdapi/v1/sd-models", timeout=15)
        resp.raise_for_status()
        data = resp.json()
        if isinstance(data, list):
            return True, f"reachable, models={len(data)}"
        return True, "reachable"
    except Exception as exc:
        return False, str(exc)


def claim_job() -> Optional[Dict[str, Any]]:
    data = post_json({"action": "claim_job", "worker_name": WORKER_NAME})
    return data.get("job")


def submit_failure(job_id: str, detail: str) -> None:
    payload = {
        "action": "submit_result",
        "job_id": job_id,
        "status": "failed",
        "worker_name": WORKER_NAME,
        "error_detail": detail[:7000],
    }
    try:
        post_json(payload)
    except Exception as exc:
        print(f"[worker] failed to submit failure for {job_id}: {exc}")


def submit_result(job_id: str, video_path: Path) -> None:
    headers = {"X-Render-Worker-Key": RENDER_KEY}
    data = {
        "action": "submit_result",
        "job_id": job_id,
        "status": "completed",
        "worker_name": WORKER_NAME,
    }
    print(f"[worker] final submit payload job_id={job_id} worker={WORKER_NAME} endpoint={WORKER_ENDPOINT}")
    print(f"[worker] submitting {job_id} to {WORKER_ENDPOINT} with {video_path.name} ({video_path.stat().st_size} bytes)")
    with video_path.open("rb") as fp:
        files = {"video": (video_path.name, fp, "video/mp4")}
        resp = requests.post(WORKER_ENDPOINT, headers=headers, data=data, files=files, timeout=max(REQUEST_TIMEOUT, 600))
    print(f"[worker] submit http status: {resp.status_code} for {job_id}")
    print(f"[worker] submit response body: {resp.text[:1200]}")
    try:
        payload = resp.json()
    except ValueError:
        raise WorkerError(f"result submission returned invalid JSON: {resp.text[:400]}")
    if not payload.get("success"):
        raise WorkerError(str(payload.get("error") or "result submission failed"))
    print(f"[worker] server accepted submission for {job_id}: {payload}")


def split_resolution(text: str) -> tuple[int, int]:
    parts = text.lower().split("x")
    if len(parts) != 2:
        return SD_WIDTH, SD_HEIGHT
    try:
        w = int(parts[0])
        h = int(parts[1])
        if w < 512 or h < 288:
            return SD_WIDTH, SD_HEIGHT
        return w, h
    except ValueError:
        return SD_WIDTH, SD_HEIGHT


def decode_image_from_b64(raw: str) -> Image.Image:
    if "," in raw:
        raw = raw.split(",", 1)[1]
    image_bytes = base64.b64decode(raw)
    return Image.open(io.BytesIO(image_bytes)).convert("RGB")


def encode_file_b64(path: Path) -> str:
    return base64.b64encode(path.read_bytes()).decode("ascii")


def probe_media_duration(path: Path) -> float:
    cmd = [
        "ffprobe",
        "-v",
        "error",
        "-show_entries",
        "format=duration",
        "-of",
        "default=noprint_wrappers=1:nokey=1",
        str(path),
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        return 0.0
    try:
        return max(0.0, float(proc.stdout.strip() or "0"))
    except ValueError:
        return 0.0


def escape_filter_value(value: str) -> str:
    return (
        value.replace("\\", "\\\\")
        .replace(":", "\\:")
        .replace("'", "\\'")
        .replace(",", "\\,")
        .replace("[", "\\[")
        .replace("]", "\\]")
    )


def resolve_music_cache_dir() -> Path:
    if REMOTE_MUSIC_CACHE_DIR:
        target = Path(REMOTE_MUSIC_CACHE_DIR).expanduser()
    else:
        target = Path(__file__).resolve().parent / "music_cache"
    target.mkdir(parents=True, exist_ok=True)
    return target


def wrap_overlay_text(text: str, width: int) -> str:
    text = " ".join(text.split())
    if not text:
        return ""
    return "\n".join(textwrap.wrap(text, width=width))


_FFMPEG_ENCODERS_CACHE: set[str] | None = None


def ffmpeg_available_encoders() -> set[str]:
    global _FFMPEG_ENCODERS_CACHE
    if _FFMPEG_ENCODERS_CACHE is not None:
        return _FFMPEG_ENCODERS_CACHE
    try:
        proc = subprocess.run(["ffmpeg", "-hide_banner", "-encoders"], capture_output=True, text=True)
        output = (proc.stdout or "") + "\n" + (proc.stderr or "")
    except Exception:
        _FFMPEG_ENCODERS_CACHE = set()
        return _FFMPEG_ENCODERS_CACHE

    encoders: set[str] = set()
    for line in output.splitlines():
        line = line.strip()
        if not line or line.startswith("------"):
            continue
        parts = line.split()
        if len(parts) >= 2 and parts[0].startswith("V"):
            encoders.add(parts[1].strip())
    _FFMPEG_ENCODERS_CACHE = encoders
    return encoders


def pick_video_encoder() -> tuple[str, list[str]]:
    encoders = ffmpeg_available_encoders()
    if "libx264" in encoders:
        return "libx264", ["-preset", "faster", "-crf", "18"]
    if "h264" in encoders:
        return "h264", ["-q:v", "3"]
    if "mpeg4" in encoders:
        return "mpeg4", ["-q:v", "3"]
    raise WorkerError("ffmpeg has no usable H.264/MPEG-4 video encoder (missing libx264/h264/mpeg4)")


def sd_scene_image(prompt: str, width: int, height: int, seed: int) -> Image.Image:
    payload = {
        "prompt": prompt,
        "negative_prompt": SD_NEGATIVE_PROMPT,
        "steps": SD_STEPS,
        "cfg_scale": SD_CFG,
        "sampler_name": SD_SAMPLER,
        "width": width,
        "height": height,
        "seed": seed,
    }
    resp = requests.post(SD_WEBUI_URL + "/sdapi/v1/txt2img", json=payload, timeout=max(REQUEST_TIMEOUT, 300))
    resp.raise_for_status()
    data = resp.json()
    images = data.get("images") or []
    if not images:
        raise WorkerError("Stable Diffusion API returned no images")
    return decode_image_from_b64(images[0])


def add_scene_text(image: Image.Image, eyebrow: str, headline: str, body: str, footer: str) -> Image.Image:
    overlay = image.copy()
    draw = ImageDraw.Draw(overlay, "RGBA")
    w, h = overlay.size

    draw.rectangle([(0, int(h * 0.58)), (w, h)], fill=(8, 10, 16, 172))
    draw.rectangle([(42, int(h * 0.60)), (w - 42, int(h * 0.605))], fill=(255, 180, 70, 220))

    font_small = load_font(28, bold=True)
    font_head = load_font(52, bold=True)
    font_body = load_font(30, bold=False)
    font_footer = load_font(22, bold=True)

    draw.multiline_text((48, int(h * 0.64)), wrap_overlay_text(eyebrow.upper(), 28), font=font_small, fill=(255, 194, 84, 255), spacing=6)
    draw.multiline_text((48, int(h * 0.71)), wrap_overlay_text(headline, 24), font=font_head, fill=(255, 255, 255, 255), spacing=8)
    draw.multiline_text((48, int(h * 0.82)), wrap_overlay_text(body, 36), font=font_body, fill=(226, 232, 240, 255), spacing=6)
    draw.multiline_text((48, int(h * 0.93)), wrap_overlay_text(footer, 48), font=font_footer, fill=(255, 194, 84, 240), spacing=4)

    return overlay


def create_narration(narration_text: str, output_mp3: Path) -> bool:
    narration_text = narration_text.strip()
    if not narration_text:
        return False

    cmd = [
        "edge-tts",
        "--voice",
        os.getenv("EDGE_TTS_VOICE", "en-US-JennyNeural"),
        "--text",
        narration_text,
        "--write-media",
        str(output_mp3),
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        print(f"[worker] edge-tts failed: {proc.stderr[:400]}")
        return False
    return output_mp3.exists() and output_mp3.stat().st_size > 0


def scene_word_weight(scene: Dict[str, Any]) -> float:
    headline = str(scene.get("headline") or "")
    body = str(scene.get("body") or "")
    return max(1.0, len((headline + " " + body).split()) / 5.0)


def build_scene_durations(scenes: list[Dict[str, Any]], audio_duration: float, max_duration: int) -> list[float]:
    transition = 0.55
    final_target = max(13.5, audio_duration + 1.0)
    if max_duration > 0:
        final_target = min(final_target, float(max_duration))

    clip_target = final_target + transition * (len(scenes) - 1)
    base = 4.2
    durations = [base for _ in scenes]
    remaining = max(0.0, clip_target - (base * len(scenes)))
    weights = [scene_word_weight(scene) for scene in scenes]
    weight_total = sum(weights) or 1.0
    for index, weight in enumerate(weights):
        durations[index] += remaining * (weight / weight_total)
    return [round(value, 2) for value in durations]


def format_srt_time(seconds: float) -> str:
    total_ms = max(0, int(round(seconds * 1000)))
    hours = total_ms // 3_600_000
    minutes = (total_ms % 3_600_000) // 60_000
    secs = (total_ms % 60_000) // 1000
    millis = total_ms % 1000
    return f"{hours:02d}:{minutes:02d}:{secs:02d},{millis:03d}"


def build_caption_segments(storyboard: Dict[str, Any], final_duration: float) -> list[dict[str, Any]]:
    raw_segments = storyboard.get("caption_segments") or []
    usable: list[dict[str, Any]] = []

    if isinstance(raw_segments, list):
        for segment in raw_segments:
            if not isinstance(segment, dict):
                continue
            text = " ".join(str(segment.get("text") or "").split())
            if not text:
                continue
            try:
                weight = float(segment.get("weight"))
            except (TypeError, ValueError):
                weight = max(1.0, len(text.split()) / 4.0)
            usable.append({"text": text, "weight": max(0.6, weight)})

    if not usable:
        narration = " ".join(str(storyboard.get("narration") or "").split())
        for chunk in [part.strip() for part in narration.replace("?", ".").replace("!", ".").split(".") if part.strip()][:5]:
            usable.append({"text": chunk, "weight": max(0.8, len(chunk.split()) / 4.0)})

    if not usable:
        return []

    start_pad = 0.25
    end_pad = 0.30
    available = max(2.0, final_duration - start_pad - end_pad)
    total_weight = sum(item["weight"] for item in usable) or 1.0

    segments: list[dict[str, Any]] = []
    cursor = start_pad
    for item in usable:
        duration = max(1.15, available * (item["weight"] / total_weight))
        end_at = min(final_duration - end_pad, cursor + duration)
        segments.append({"text": item["text"], "start": cursor, "end": end_at})
        cursor = end_at

    if segments:
        segments[-1]["end"] = max(segments[-1]["start"] + 1.15, final_duration - end_pad)

    return segments


def write_srt(captions: list[dict[str, Any]], output_path: Path) -> None:
    lines: list[str] = []
    for index, segment in enumerate(captions, start=1):
        lines.append(str(index))
        lines.append(f"{format_srt_time(float(segment['start']))} --> {format_srt_time(float(segment['end']))}")
        lines.append(wrap_overlay_text(str(segment['text']), 34))
        lines.append("")
    output_path.write_text("\n".join(lines), encoding="utf-8")


def resolve_style_bundle(style_name: str, job_id: str, allow_variation: bool) -> tuple[str, dict[str, Any], random.Random]:
    available = list(STYLE_BUNDLES.keys())
    chooser = random.Random(hashlib.sha1(job_id.encode("utf-8")).hexdigest())
    requested = style_name if style_name in STYLE_BUNDLES else "cinematic_marketing"
    if allow_variation and style_name in {"auto", "dynamic", "varied"}:
        requested = chooser.choice(available)
    return requested, STYLE_BUNDLES[requested], chooser


def deterministic_seed(job_id: str, scene_index: int) -> int:
    digest = hashlib.sha1(f"{job_id}:{scene_index}".encode("utf-8")).hexdigest()[:8]
    return int(digest, 16)


def build_scene_prompt(base: str, scene: Dict[str, Any], style_name: str, style_bundle: Dict[str, Any], chooser: random.Random) -> str:
    scene_role = str(scene.get("scene_role") or "hook")
    role_options = list(style_bundle.get("scene_roles", {}).get(scene_role, [])) or ["premium cinematic marketing frame"]
    look = chooser.choice(list(style_bundle.get("look", ["premium technology commercial"])))
    palette = chooser.choice(list(style_bundle.get("palette", ["electric cyan and amber"])))
    camera = chooser.choice(list(style_bundle.get("camera", ["35mm cinematic lens"])))
    role = chooser.choice(role_options)
    focus = str(scene.get("prompt_focus") or "")
    headline = str(scene.get("headline") or "")
    body = str(scene.get("body") or "")
    return (
        f"{base}. style preset {style_name}. {look}. {palette}. {camera}. {role}. "
        f"focus: {focus}. subject: {headline}. context: {body}. no text, no watermark, no logo, no UI typography."
    )


def local_motion_filter(duration: float, fps: int, scene_index: int) -> str:
    frames = max(1, int(round(duration * fps)))
    profiles = [
        f"scale=1280:720,zoompan=z='min(zoom+0.0015,1.12)':x='iw/2-(iw/zoom/2)+sin(on/18)*14':y='ih/2-(ih/zoom/2)+cos(on/21)*8':d={frames}:s=1280x720:fps={fps}",
        f"scale=1280:720,zoompan=z='min(zoom+0.0012,1.09)':x='iw/2-(iw/zoom/2)-sin(on/16)*18':y='ih/2-(ih/zoom/2)+sin(on/24)*10':d={frames}:s=1280x720:fps={fps}",
        f"scale=1280:720,zoompan=z='min(zoom+0.0017,1.13)':x='iw/2-(iw/zoom/2)+cos(on/20)*16':y='ih/2-(ih/zoom/2)-sin(on/18)*12':d={frames}:s=1280x720:fps={fps}",
    ]
    return profiles[(scene_index - 1) % len(profiles)] + ",format=yuv420p"


def render_local_motion_clip(image_path: Path, output_path: Path, duration: float, fps: int, scene_index: int) -> None:
    encoder, encoder_opts = pick_video_encoder()
    primary_cmd = [
        "ffmpeg",
        "-y",
        "-nostdin",
        "-loop",
        "1",
        "-t",
        str(duration),
        "-i",
        str(image_path),
        "-vf",
        local_motion_filter(duration, fps, scene_index),
        "-c:v",
        encoder,
        *encoder_opts,
        "-pix_fmt",
        "yuv420p",
        str(output_path),
    ]
    try:
        proc = subprocess.run(primary_cmd, capture_output=True, text=True, timeout=FFMPEG_TIMEOUT)
    except subprocess.TimeoutExpired:
        print(f"[worker] local motion fallback: zoompan timed out for scene {scene_index}, falling back to static frame clip")
        fallback_cmd = [
            "ffmpeg",
            "-y",
            "-nostdin",
            "-loop",
            "1",
            "-t",
            str(duration),
            "-i",
            str(image_path),
            "-vf",
            "scale=1280:720,format=yuv420p",
            "-c:v",
            encoder,
            *encoder_opts,
            "-pix_fmt",
            "yuv420p",
            str(output_path),
        ]
        try:
            proc = subprocess.run(fallback_cmd, capture_output=True, text=True, timeout=min(FFMPEG_TIMEOUT, 90))
        except subprocess.TimeoutExpired:
            raise WorkerError(f"local motion clip timed out after {FFMPEG_TIMEOUT}s")
    if proc.returncode != 0:
        raise WorkerError(f"local motion clip failed: {proc.stderr[-700:]}")


def archive_mood_query(mood: str) -> str:
    if REMOTE_MUSIC_QUERY_OVERRIDE:
        return REMOTE_MUSIC_QUERY_OVERRIDE

    mood_terms = {
        "tech_cinematic": 'electronic OR ambient OR cinematic OR instrumental',
        "founder_story": 'ambient OR piano OR inspirational OR instrumental',
        "neon_operator": 'synthwave OR electronic OR cyberpunk OR instrumental',
    }
    selected_terms = mood_terms.get(mood.lower(), 'ambient OR cinematic OR instrumental')
    return (
        'mediatype:(audio) AND '
        '(licenseurl:("https://creativecommons.org/publicdomain/zero/1.0/") OR '
        'licenseurl:("https://creativecommons.org/publicdomain/mark/1.0/")) AND '
        f'(subject:({selected_terms}) OR title:({selected_terms}))'
    )


def is_safe_archive_candidate(item: dict[str, Any]) -> bool:
    combined = " ".join([
        str(item.get("identifier") or ""),
        str(item.get("title") or ""),
        str(item.get("subject") or ""),
    ]).lower()
    if combined == "":
        return False
    return not any(term in combined for term in REMOTE_MUSIC_BLOCK_TERMS)


def archive_search_candidates(mood: str) -> list[dict[str, Any]]:
    if REMOTE_MUSIC_PROVIDER not in {"internet_archive", "auto"}:
        return []

    params = {
        "q": archive_mood_query(mood),
        "fl[]": ["identifier", "title", "subject", "downloads"],
        "sort[]": ["downloads desc"],
        "rows": "8",
        "page": "1",
        "output": "json",
    }
    try:
        resp = requests.get("https://archive.org/advancedsearch.php", params=params, timeout=max(REQUEST_TIMEOUT, 45))
        resp.raise_for_status()
        data = resp.json()
    except Exception as exc:
        print(f"[worker] archive search failed: {exc}")
        return []

    docs = data.get("response", {}).get("docs", [])
    if not isinstance(docs, list):
        return []
    return [item for item in docs if isinstance(item, dict) and is_safe_archive_candidate(item)]


def archive_metadata_download_url(identifier: str) -> Optional[str]:
    try:
        resp = requests.get(f"https://archive.org/metadata/{identifier}", timeout=max(REQUEST_TIMEOUT, 45))
        resp.raise_for_status()
        data = resp.json()
    except Exception as exc:
        print(f"[worker] archive metadata failed for {identifier}: {exc}")
        return None

    files = data.get("files", [])
    if not isinstance(files, list):
        return None

    preferred_exts = (".mp3", ".ogg", ".flac", ".wav", ".m4a", ".aac")
    for ext in preferred_exts:
        for entry in files:
            if not isinstance(entry, dict):
                continue
            name = str(entry.get("name") or "")
            source = str(entry.get("source") or "")
            if not name.lower().endswith(ext):
                continue
            if source and source.lower() not in {"original", "derivative"}:
                continue
            return f"https://archive.org/download/{identifier}/{name}"
    return None


def download_to_path(url: str, output_path: Path) -> bool:
    try:
        with requests.get(url, stream=True, timeout=max(REQUEST_TIMEOUT, 120)) as resp:
            resp.raise_for_status()
            with output_path.open("wb") as handle:
                for chunk in resp.iter_content(chunk_size=65536):
                    if chunk:
                        handle.write(chunk)
    except Exception as exc:
        print(f"[worker] download failed: {exc}")
        try:
            output_path.unlink(missing_ok=True)
        except Exception:
            pass
        return False

    return output_path.exists() and output_path.stat().st_size > 0


def fetch_remote_music(job_id: str, mood: str) -> Optional[Path]:
    cache_dir = resolve_music_cache_dir()
    candidates = archive_search_candidates(mood)
    if not candidates:
        return None

    chooser = random.Random(hashlib.sha1((job_id + mood + "remote-music").encode("utf-8")).hexdigest())
    chooser.shuffle(candidates)
    for item in candidates:
        if not isinstance(item, dict):
            continue
        identifier = str(item.get("identifier") or "").strip()
        if not identifier:
            continue
        cached_matches = list(cache_dir.glob(identifier + ".*"))
        if cached_matches:
            return cached_matches[0]
        download_url = archive_metadata_download_url(identifier)
        if not download_url:
            continue
        suffix = Path(download_url).suffix or ".mp3"
        target = cache_dir / f"{identifier}{suffix}"
        if download_to_path(download_url, target):
            return target

    return None


def generate_procedural_music(job_id: str, mood: str, duration: float) -> Optional[Path]:
    cache_dir = resolve_music_cache_dir()
    safe_mood = "".join(ch if ch.isalnum() or ch in {"_", "-"} else "_" for ch in mood.lower()) or "default"
    target = cache_dir / f"generated_{safe_mood}_{job_id[:12]}.mp3"
    if target.exists() and target.stat().st_size > 0:
        return target

    frequencies = {
        "tech_cinematic": (110, 220, 330),
        "founder_story": (196, 247, 294),
        "neon_operator": (92, 184, 276),
    }
    base_a, base_b, base_c = frequencies.get(mood.lower(), (110, 220, 330))
    duration = max(10.0, duration + 1.0)
    filter_graph = (
        f"sine=f={base_a}:sample_rate=44100:duration={duration},volume=0.06[a0];"
        f"sine=f={base_b}:sample_rate=44100:duration={duration},volume=0.04[a1];"
        f"sine=f={base_c}:sample_rate=44100:duration={duration},volume=0.025[a2];"
        f"anoisesrc=color=pink:sample_rate=44100:duration={duration},volume=0.008,lowpass=f=700[an];"
        "[a0][a1][a2][an]amix=inputs=4:normalize=0,alimiter=limit=0.7,afade=t=in:st=0:d=1.8,afade=t=out:st=" + str(max(1.0, duration - 2.2)) + ":d=2.0"
    )
    cmd = [
        "ffmpeg",
        "-y",
        "-filter_complex",
        filter_graph,
        "-t",
        str(duration),
        "-c:a",
        "libmp3lame",
        "-q:a",
        "4",
        str(target),
    ]
    try:
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=FFMPEG_TIMEOUT)
    except subprocess.TimeoutExpired:
        print(f"[worker] procedural music timed out after {FFMPEG_TIMEOUT}s")
        try:
            target.unlink(missing_ok=True)
        except Exception:
            pass
        return None
    if proc.returncode != 0:
        print(f"[worker] procedural music failed: {proc.stderr[-400:]}")
        try:
            target.unlink(missing_ok=True)
        except Exception:
            pass
        return None
    return target if target.exists() and target.stat().st_size > 0 else None


def save_motion_api_response(data: Dict[str, Any], output_path: Path) -> bool:
    if isinstance(data.get("video_base64"), str) and data["video_base64"].strip():
        output_path.write_bytes(base64.b64decode(data["video_base64"]))
        return output_path.exists() and output_path.stat().st_size > 0

    candidate = str(data.get("video_path") or data.get("video_url") or "").strip()
    if not candidate:
        return False

    if candidate.startswith("file://"):
        candidate = candidate[7:]
    source = Path(candidate)
    if source.exists() and source.is_file():
        output_path.write_bytes(source.read_bytes())
        return output_path.exists() and output_path.stat().st_size > 0
    return False


def try_motion_api(image_path: Path, prompt: str, output_path: Path, duration: float, fps: int, style_name: str) -> bool:
    if not MOTION_API_URL:
        return False

    payload = {
        "prompt": prompt,
        "style_preset": style_name,
        "seconds": duration,
        "fps": fps,
        "motion_strength": MOTION_STRENGTH,
        "image_base64": encode_file_b64(image_path),
    }
    try:
        resp = requests.post(MOTION_API_URL, json=payload, timeout=max(REQUEST_TIMEOUT, 600))
        resp.raise_for_status()
        data = resp.json()
        if isinstance(data, dict) and data.get("success") is False:
            return False
        return save_motion_api_response(data if isinstance(data, dict) else {}, output_path)
    except Exception as exc:
        print(f"[worker] motion api fallback: {exc}")
        return False


def render_scene_clip(image_path: Path, prompt: str, output_path: Path, duration: float, fps: int, scene_index: int, style_name: str, motion_provider: str) -> None:
    wants_api = motion_provider in {"api", "external_api", "auto", "hybrid"}
    if wants_api and try_motion_api(image_path, prompt, output_path, duration, fps, style_name):
        return
    render_local_motion_clip(image_path, output_path, duration, fps, scene_index)


def pick_background_music(job_id: str, mood: str) -> Optional[Path]:
    if not BACKGROUND_MUSIC_DIR:
        remote_track = fetch_remote_music(job_id, mood)
        if remote_track is not None:
            return remote_track
        return None

    root = Path(BACKGROUND_MUSIC_DIR).expanduser()
    if not root.exists() or not root.is_dir():
        remote_track = fetch_remote_music(job_id, mood)
        if remote_track is not None:
            return remote_track
        return None

    exts = {".mp3", ".wav", ".m4a", ".aac", ".flac", ".ogg"}
    candidates = [path for path in root.rglob("*") if path.is_file() and path.suffix.lower() in exts]
    if not candidates:
        remote_track = fetch_remote_music(job_id, mood)
        if remote_track is not None:
            return remote_track
        return None

    mood_lower = mood.lower()
    mood_hits = [path for path in candidates if mood_lower in str(path.parent).lower() or mood_lower in path.name.lower()]
    chooser = random.Random(hashlib.sha1((job_id + mood).encode("utf-8")).hexdigest())
    return chooser.choice(mood_hits or candidates)


def concat_scene_clips(scene_clips: list[Path], output_path: Path, fps: int) -> None:
    list_path = output_path.with_suffix(".txt")
    list_path.write_text("\n".join(f"file '{clip.as_posix()}'" for clip in scene_clips) + "\n", encoding="utf-8")

    copy_cmd = [
        "ffmpeg",
        "-y",
        "-nostdin",
        "-f",
        "concat",
        "-safe",
        "0",
        "-i",
        str(list_path),
        "-c",
        "copy",
        str(output_path),
    ]
    try:
        print(f"[worker] concat scenes: {len(scene_clips)} clips -> {output_path.name}")
        proc = subprocess.run(copy_cmd, capture_output=True, text=True, timeout=FFMPEG_TIMEOUT)
    except subprocess.TimeoutExpired:
        raise WorkerError(f"scene concat timed out after {FFMPEG_TIMEOUT}s")
    if proc.returncode == 0:
        print("[worker] concat scenes: stream copy complete")
        try:
            list_path.unlink(missing_ok=True)
        except Exception:
            pass
        return

    print(f"[worker] concat scenes: copy path failed, re-encoding ({proc.stderr[-300:]})")

    encoder, encoder_opts = pick_video_encoder()
    cmd = [
        "ffmpeg",
        "-y",
        "-nostdin",
        "-f",
        "concat",
        "-safe",
        "0",
        "-i",
        str(list_path),
        "-an",
        "-vf",
        f"fps={fps},scale=1280:720,format=yuv420p,setsar=1",
        "-c:v",
        encoder,
        *encoder_opts,
        str(output_path),
    ]
    try:
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=FFMPEG_TIMEOUT)
    except subprocess.TimeoutExpired:
        raise WorkerError(f"scene concat timed out after {FFMPEG_TIMEOUT}s")
    finally:
        try:
            list_path.unlink(missing_ok=True)
        except Exception:
            pass

    if proc.returncode != 0:
        raise WorkerError(f"scene concat failed: {proc.stderr[-900:]}")


def run_ffmpeg(video_path: Path, audio_path: Path, output_path: Path, fps: int, captions_path: Optional[Path] = None, music_path: Optional[Path] = None) -> None:
    encoder, encoder_opts = pick_video_encoder()
    final_duration = max(1.0, probe_media_duration(video_path) or probe_media_duration(audio_path) or 1.0)

    cmd = ["ffmpeg", "-y", "-nostdin", "-i", str(video_path), "-i", str(audio_path)]
    if music_path is not None:
        cmd.extend(["-stream_loop", "-1", "-i", str(music_path)])

    if captions_path is not None and captions_path.exists():
        video_chain = (
            f"[0:v]subtitles='{escape_filter_value(str(captions_path))}':"
            "force_style='FontName=DejaVu Sans,FontSize=18,PrimaryColour=&H00FFFFFF,OutlineColour=&H00000000,BorderStyle=1,Outline=2,Shadow=0,Alignment=2,MarginV=34'[v]"
        )
    else:
        video_chain = "[0:v]fps=" + str(fps) + ",format=yuv420p[v]"

    audio_chain = "[1:a]aresample=44100,volume=1.0[aout]"
    map_audio = "[aout]"
    if music_path is not None:
        music_input_index = 2
        audio_chain += (
            ";[aout]asplit=2[narr_sc][narr_mix];"
            f"[{music_input_index}:a]atrim=0:{final_duration},asetpts=N/SR/TB,volume={BACKGROUND_MUSIC_VOLUME}[bg];"
            "[bg][narr_sc]sidechaincompress=threshold=0.015:ratio=10:attack=15:release=350[bgduck];"
            "[bgduck][narr_mix]amix=inputs=2:weights='1 1':normalize=0[mix];"
            "[mix]loudnorm=I=-16:TP=-1.5:LRA=11[aout]"
        )

    filter_complex = video_chain + ";" + audio_chain
    cmd.extend([
        "-filter_complex",
        filter_complex,
        "-map",
        "[v]",
        "-map",
        map_audio,
        "-c:v",
        encoder,
        *encoder_opts,
        "-c:a",
        "aac",
        "-b:a",
        "192k",
        "-pix_fmt",
        "yuv420p",
        "-shortest",
        "-movflags",
        "+faststart",
        str(output_path),
    ])

    try:
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=FFMPEG_TIMEOUT)
    except subprocess.TimeoutExpired:
        raise WorkerError(f"ffmpeg compose timed out after {FFMPEG_TIMEOUT}s")
    if proc.returncode != 0:
        raise WorkerError(f"ffmpeg failed: {proc.stderr[-900:]}")


def compose_video_with_fallbacks(
    video_path: Path,
    audio_path: Path,
    output_path: Path,
    fps: int,
    captions_path: Optional[Path],
    music_path: Optional[Path],
) -> None:
    attempts = [
        (captions_path, music_path, None),
        (None, music_path, "caption fallback: disabling subtitle burn-in after ffmpeg compose failure"),
        (None, None, "audio fallback: disabling background music after ffmpeg compose failure"),
    ]
    last_error: Optional[Exception] = None
    for attempt_captions, attempt_music, notice in attempts:
        try:
            if notice:
                print(f"[worker] {notice}")
            run_ffmpeg(video_path, audio_path, output_path, fps, attempt_captions, attempt_music)
            return
        except WorkerError as exc:
            last_error = exc
    if last_error is not None:
        raise last_error
    raise WorkerError("ffmpeg compose failed")


def render_job(job: Dict[str, Any]) -> Path:
    job_id = str(job.get("job_id") or "")
    payload = job.get("payload") or {}
    if not isinstance(payload, dict):
        raise WorkerError("job payload malformed")

    storyboard = payload.get("storyboard") or {}
    if not isinstance(storyboard, dict):
        storyboard = {}

    scenes = [storyboard.get("scene1") or {}, storyboard.get("scene2") or {}, storyboard.get("scene3") or {}]

    style_name = payload_str(payload, "style_preset", "cinematic_marketing")
    motion_provider = payload_str(payload, "motion_provider", MOTION_PROVIDER).lower()
    enable_captions = payload_bool(payload, "enable_captions", ENABLE_CAPTIONS)
    enable_background_music = payload_bool(payload, "enable_background_music", ENABLE_BACKGROUND_MUSIC)
    allow_variation = payload_bool(payload, "style_variation", True)
    music_mood = payload_str(payload, "music_mood", payload_str(storyboard, "music_mood", BACKGROUND_MUSIC_MOOD))
    fps = max(24, int(payload.get("fps") or DEFAULT_FPS))
    max_duration = max(12, int(payload.get("max_duration") or 30))
    resolution = payload_str(payload, "resolution", "1280x720")
    width, height = split_resolution(resolution)
    resolved_style, style_bundle, chooser = resolve_style_bundle(style_name, job_id, allow_variation)

    title = str(payload.get("title") or "")
    description = str(payload.get("description") or "")
    base_context = f"Product marketing video for AI software. title: {title}. description: {description}"

    ensure_free_space(WORK_DIR, min_mb=8192)
    with tempfile.TemporaryDirectory(prefix=f"lyra_worker_{job_id}_", dir=str(WORK_DIR)) as tmp:
        tmpdir = Path(tmp)
        ensure_free_space(tmpdir, min_mb=2048)
        audio_path = tmpdir / "narration.mp3"
        narration = str(storyboard.get("narration") or "")
        if not create_narration(narration, audio_path):
            raise WorkerError("Narration synthesis failed (edge-tts)")

        audio_duration = probe_media_duration(audio_path)
        scene_durations = build_scene_durations(scenes, audio_duration, max_duration)
        final_duration = max(1.0, sum(scene_durations) - (0.55 * 2))

        captions_path: Optional[Path] = None
        captions = build_caption_segments(storyboard, final_duration)
        if enable_captions and captions:
            captions_path = tmpdir / "captions.srt"
            write_srt(captions, captions_path)
            print("[worker] captions ready")

        scene_clips: list[Path] = []
        for index, scene in enumerate(scenes, start=1):
            print(f"[worker] rendering scene {index}/{len(scenes)}")
            prompt = build_scene_prompt(base_context, scene, resolved_style, style_bundle, chooser)
            image = sd_scene_image(prompt, width, height, deterministic_seed(job_id, index))
            overlay = add_scene_text(
                image,
                str(scene.get("eyebrow") or ""),
                str(scene.get("headline") or ""),
                str(scene.get("body") or ""),
                str(scene.get("footer") or ""),
            )
            image_path = tmpdir / f"scene{index}.png"
            overlay.save(image_path, format="PNG")

            clip_path = tmpdir / f"scene{index}.mp4"
            render_scene_clip(image_path, prompt, clip_path, scene_durations[index - 1], fps, index, resolved_style, motion_provider)
            scene_clips.append(clip_path)

        music_path = None
        if enable_background_music:
            music_path = pick_background_music(job_id, music_mood)
            if music_path is None:
                music_path = generate_procedural_music(job_id, music_mood, final_duration)

        print("[worker] assembling scene clips")

        concat_path = tmpdir / "scenes.mp4"
        concat_scene_clips(scene_clips, concat_path, fps)

        output_path = tmpdir / "render.mp4"
        print("[worker] composing final video")
        compose_video_with_fallbacks(concat_path, audio_path, output_path, fps, captions_path, music_path)

        if not output_path.exists() or output_path.stat().st_size <= 0:
            raise WorkerError("Render output missing")

        final_path = WORK_DIR / f"lyra_result_{job_id}.mp4"
        try:
            if final_path.exists():
                final_path.unlink()
        except OSError:
            pass

        output_path.replace(final_path)
        print(f"[worker] final output staged at {final_path}")
        return final_path


def main() -> None:
    if not RENDER_KEY:
        raise SystemExit("RENDER_WORKER_SHARED_KEY is required")

    print(f"[worker] starting {WORKER_NAME}")
    print(f"[worker] endpoint: {WORKER_ENDPOINT}")
    print(f"[worker] sd api: {SD_WEBUI_URL}")
    if BACKGROUND_MUSIC_DIR:
        print(f"[worker] bgm dir: {BACKGROUND_MUSIC_DIR}")
    if MOTION_API_URL:
        print(f"[worker] motion api: {MOTION_API_URL}")

    ok, detail = sd_api_available()
    if ok:
        print(f"[worker] sd status: {detail}")
    else:
        print(f"[worker] sd status: unavailable ({detail})")
        print("[worker] hint: start A1111/Forge separately with --api --xformers --medvram, or update SD_WEBUI_URL in .env")

    while True:
        try:
            job = claim_job()
            if not job:
                print(f"[worker] idle: no queued jobs, sleeping {POLL_INTERVAL}s")
                time.sleep(POLL_INTERVAL)
                continue

            job_id = str(job.get("job_id") or "")
            if not job_id:
                print("[worker] claimed empty job id; retrying")
                time.sleep(POLL_INTERVAL)
                continue

            print(f"[worker] claimed {job_id}")
            try:
                result_path = render_job(job)
                print(f"[worker] render complete for {job_id}: {result_path}")
                submit_result(job_id, result_path)
                print(f"[worker] submitted {job_id}")
                try:
                    result_path.unlink(missing_ok=True)
                except Exception:
                    pass
            except Exception as render_exc:
                detail = str(render_exc)
                print(f"[worker] failed {job_id}: {detail}")
                submit_failure(job_id, detail)

        except requests.HTTPError as http_exc:
            print(f"[worker] http error: {http_exc}")
            time.sleep(POLL_INTERVAL)
        except Exception as exc:
            print(f"[worker] loop error: {exc}")
            time.sleep(POLL_INTERVAL)


if __name__ == "__main__":
    main()
