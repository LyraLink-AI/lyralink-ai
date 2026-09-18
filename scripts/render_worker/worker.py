#!/usr/bin/env python3
import base64
import os
import subprocess
        import io
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
        
        class WorkerError(Exception):
            pass
        
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
            with video_path.open("rb") as fp:
                files = {"video": (video_path.name, fp, "video/mp4")}
                resp = requests.post(WORKER_ENDPOINT, headers=headers, data=data, files=files, timeout=REQUEST_TIMEOUT)
            resp.raise_for_status()
            payload = resp.json()
            if not payload.get("success"):
                raise WorkerError(str(payload.get("error") or "result submission failed"))
        
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
        
        def sd_scene_image(prompt: str, width: int, height: int) -> Image.Image:
            payload = {
                "prompt": prompt,
                "negative_prompt": SD_NEGATIVE_PROMPT,
                "steps": SD_STEPS,
                "cfg_scale": SD_CFG,
                "sampler_name": SD_SAMPLER,
                "width": width,
                "height": height,
            }
            resp = requests.post(SD_WEBUI_URL + "/sdapi/v1/txt2img", json=payload, timeout=REQUEST_TIMEOUT)
            resp.raise_for_status()
            data = resp.json()
            images = data.get("images") or []
            if not images:
                raise WorkerError("Stable Diffusion API returned no images")
            return decode_image_from_b64(images[0])
        
        def add_scene_text(image: Image.Image, eyebrow: str, headline: str, body: str) -> Image.Image:
            overlay = image.copy()
            draw = ImageDraw.Draw(overlay, "RGBA")
            w, h = overlay.size
        
            draw.rectangle([(0, int(h * 0.65)), (w, h)], fill=(10, 10, 16, 168))
            draw.rectangle([(0, int(h * 0.62)), (w, int(h * 0.64))], fill=(255, 180, 70, 220))
        
            font_small = load_font(32, bold=True)
            font_head = load_font(56, bold=True)
            font_body = load_font(34, bold=False)
        
            draw.text((48, int(h * 0.69)), eyebrow.upper(), font=font_small, fill=(255, 194, 84, 255))
            draw.text((48, int(h * 0.75)), headline, font=font_head, fill=(255, 255, 255, 255))
            draw.text((48, int(h * 0.85)), body, font=font_body, fill=(226, 232, 240, 255))
        
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
        
        def run_ffmpeg(scene_paths: list[Path], audio_path: Path, output_path: Path, fps: int) -> None:
            d1, d2, d3 = 6.0, 6.0, 6.0
            transition = 0.55
            offset_2 = d1 - transition
            offset_3 = d1 + d2 - (transition * 2)
        
            filter_graph = (
                "[0:v]scale=1280:720,zoompan=z='min(zoom+0.0012,1.10)':d=180:s=1280x720:fps={fps},format=yuv420p[v0];"
                "[1:v]scale=1280:720,zoompan=z='min(zoom+0.0015,1.12)':d=180:s=1280x720:fps={fps},format=yuv420p[v1];"
                "[2:v]scale=1280:720,zoompan=z='min(zoom+0.0011,1.09)':d=180:s=1280x720:fps={fps},format=yuv420p[v2];"
                "[v0][v1]xfade=transition=fade:duration={tr}:offset={o2}[v01];"
                "[v01][v2]xfade=transition=fade:duration={tr}:offset={o3}[v]"
            ).format(fps=fps, tr=transition, o2=offset_2, o3=offset_3)
        
            cmd = [
                "ffmpeg",
                "-y",
                "-loop",
                "1",
                "-t",
                str(d1),
                "-i",
                str(scene_paths[0]),
                "-loop",
                "1",
                "-t",
                str(d2),
                "-i",
                str(scene_paths[1]),
                "-loop",
                "1",
                "-t",
                str(d3),
                "-i",
                str(scene_paths[2]),
                "-i",
                str(audio_path),
                "-filter_complex",
                filter_graph,
                "-map",
                "[v]",
                "-map",
                "3:a",
                "-c:v",
                "libx264",
                "-preset",
                "slow",
                "-crf",
                "18",
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
            ]
        
            proc = subprocess.run(cmd, capture_output=True, text=True)
            if proc.returncode != 0:
                raise WorkerError(f"ffmpeg failed: {proc.stderr[-700:]}")
        
        def build_scene_prompt(base: str, scene: Dict[str, Any], style: str) -> str:
            headline = str(scene.get("headline") or "")
            body = str(scene.get("body") or "")
            cinematic = (
                "ultra cinematic advertising frame, premium brand commercial, dynamic lighting, "
                "depth of field, realistic textures, tasteful color grading, sharp details"
            )
            return f"{base}. {cinematic}. style preset: {style}. subject: {headline}. context: {body}. no text in image"
        
        def render_job(job: Dict[str, Any]) -> Path:
            job_id = str(job.get("job_id") or "")
            payload = job.get("payload") or {}
            if not isinstance(payload, dict):
                raise WorkerError("job payload malformed")
        
            storyboard = payload.get("storyboard") or {}
            if not isinstance(storyboard, dict):
                storyboard = {}
        
            scene1 = storyboard.get("scene1") or {}
            scene2 = storyboard.get("scene2") or {}
            scene3 = storyboard.get("scene3") or {}
        
            style = str(payload.get("style_preset") or "cinematic_marketing")
            fps = int(payload.get("fps") or 30)
            resolution = str(payload.get("resolution") or "1280x720")
            width, height = split_resolution(resolution)
        
            title = str(payload.get("title") or "")
            description = str(payload.get("description") or "")
            base_context = f"Product marketing video for AI software. title: {title}. description: {description}"
        
            with tempfile.TemporaryDirectory(prefix=f"lyra_worker_{job_id}_") as tmp:
                tmpdir = Path(tmp)
                scene_paths = []
                for i, scene in enumerate([scene1, scene2, scene3], start=1):
                    prompt = build_scene_prompt(base_context, scene, style)
                    image = sd_scene_image(prompt, width, height)
                    overlay = add_scene_text(
                        image,
                        str(scene.get("eyebrow") or ""),
                        str(scene.get("headline") or ""),
                        str(scene.get("body") or ""),
                    )
                    path = tmpdir / f"scene{i}.png"
                    overlay.save(path, format="PNG")
                    scene_paths.append(path)
        
                audio_path = tmpdir / "narration.mp3"
                narration = str(storyboard.get("narration") or "")
                if not create_narration(narration, audio_path):
                    raise WorkerError("Narration synthesis failed (edge-tts)")
        
                output_path = tmpdir / "render.mp4"
                run_ffmpeg(scene_paths, audio_path, output_path, fps)
        
                if not output_path.exists() or output_path.stat().st_size <= 0:
                    raise WorkerError("Render output missing")
        
                final_path = Path(tempfile.gettempdir()) / f"lyra_result_{job_id}.mp4"
                output_path.replace(final_path)
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
        
            while True:
                try:
                    job = claim_job()
                    if not job:
                        time.sleep(POLL_INTERVAL)
                        continue
        
                    job_id = str(job.get("job_id") or "")
                    if not job_id:
                        time.sleep(POLL_INTERVAL)
                        continue
        
                    print(f"[worker] claimed {job_id}")
                    try:
                        result_path = render_job(job)
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


class WorkerError(Exception):
    pass


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
    with video_path.open("rb") as fp:
        files = {"video": (video_path.name, fp, "video/mp4")}
        resp = requests.post(WORKER_ENDPOINT, headers=headers, data=data, files=files, timeout=REQUEST_TIMEOUT)
    resp.raise_for_status()
    payload = resp.json()
    if not payload.get("success"):
        raise WorkerError(str(payload.get("error") or "result submission failed"))


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
    with tempfile.NamedTemporaryFile(delete=False, suffix=".png") as tmp:
        tmp.write(image_bytes)
        tmp_path = Path(tmp.name)
    try:
        image = Image.open(tmp_path).convert("RGB")
    finally:
        try:
            tmp_path.unlink(missing_ok=True)
        except Exception:
            pass
    return image


def sd_scene_image(prompt: str, width: int, height: int) -> Image.Image:
    payload = {
        "prompt": prompt,
        "negative_prompt": SD_NEGATIVE_PROMPT,
        "steps": SD_STEPS,
        "cfg_scale": SD_CFG,
        "sampler_name": SD_SAMPLER,
        "width": width,
        "height": height,
    }
    resp = requests.post(SD_WEBUI_URL + "/sdapi/v1/txt2img", json=payload, timeout=REQUEST_TIMEOUT)
    resp.raise_for_status()
    data = resp.json()
    images = data.get("images") or []
    if not images:
        raise WorkerError("Stable Diffusion API returned no images")
    return decode_image_from_b64(images[0])


def add_scene_text(image: Image.Image, eyebrow: str, headline: str, body: str) -> Image.Image:
    overlay = image.copy()
    draw = ImageDraw.Draw(overlay, "RGBA")
    w, h = overlay.size

    draw.rectangle([(0, int(h * 0.65)), (w, h)], fill=(10, 10, 16, 168))
    draw.rectangle([(0, int(h * 0.62)), (w, int(h * 0.64))], fill=(255, 180, 70, 220))

    font_small = load_font(32, bold=True)
    font_head = load_font(56, bold=True)
    font_body = load_font(34, bold=False)

    draw.text((48, int(h * 0.69)), eyebrow.upper(), font=font_small, fill=(255, 194, 84, 255))
    draw.text((48, int(h * 0.75)), headline, font=font_head, fill=(255, 255, 255, 255))
    draw.text((48, int(h * 0.85)), body, font=font_body, fill=(226, 232, 240, 255))

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


def run_ffmpeg(scene_paths: list[Path], audio_path: Path, output_path: Path, fps: int) -> None:
    d1, d2, d3 = 6.0, 6.0, 6.0
    transition = 0.55
    offset_2 = d1 - transition
    offset_3 = d1 + d2 - (transition * 2)

    filter_graph = (
        "[0:v]scale=1280:720,zoompan=z='min(zoom+0.0012,1.10)':d=180:s=1280x720:fps={fps},format=yuv420p[v0];"
        "[1:v]scale=1280:720,zoompan=z='min(zoom+0.0015,1.12)':d=180:s=1280x720:fps={fps},format=yuv420p[v1];"
        "[2:v]scale=1280:720,zoompan=z='min(zoom+0.0011,1.09)':d=180:s=1280x720:fps={fps},format=yuv420p[v2];"
        "[v0][v1]xfade=transition=fade:duration={tr}:offset={o2}[v01];"
        "[v01][v2]xfade=transition=fade:duration={tr}:offset={o3}[v]"
    ).format(fps=fps, tr=transition, o2=offset_2, o3=offset_3)

    cmd = [
        "ffmpeg",
        "-y",
        "-loop",
        "1",
        "-t",
        str(d1),
        "-i",
        str(scene_paths[0]),
        "-loop",
        "1",
        "-t",
        str(d2),
        "-i",
        str(scene_paths[1]),
        "-loop",
        "1",
        "-t",
        str(d3),
        "-i",
        str(scene_paths[2]),
        "-i",
        str(audio_path),
        "-filter_complex",
        filter_graph,
        "-map",
        "[v]",
        "-map",
        "3:a",
        "-c:v",
        "libx264",
        "-preset",
        "slow",
        "-crf",
        "18",
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
    ]

    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        raise WorkerError(f"ffmpeg failed: {proc.stderr[-700:]}")



def build_scene_prompt(base: str, scene: Dict[str, Any], style: str) -> str:
    headline = str(scene.get("headline") or "")
    body = str(scene.get("body") or "")
    cinematic = (
        "ultra cinematic advertising frame, premium brand commercial, dynamic lighting, "
        "depth of field, realistic textures, tasteful color grading, sharp details"
    )
    return f"{base}. {cinematic}. style preset: {style}. subject: {headline}. context: {body}. no text in image"


def render_job(job: Dict[str, Any]) -> Path:
    job_id = str(job.get("job_id") or "")
    payload = job.get("payload") or {}
    if not isinstance(payload, dict):
        raise WorkerError("job payload malformed")

    storyboard = payload.get("storyboard") or {}
    if not isinstance(storyboard, dict):
        storyboard = {}

    scene1 = storyboard.get("scene1") or {}
    scene2 = storyboard.get("scene2") or {}
    scene3 = storyboard.get("scene3") or {}

    style = str(payload.get("style_preset") or "cinematic_marketing")
    fps = int(payload.get("fps") or 30)
    resolution = str(payload.get("resolution") or "1280x720")
    width, height = split_resolution(resolution)

    title = str(payload.get("title") or "")
    description = str(payload.get("description") or "")
    base_context = f"Product marketing video for AI software. title: {title}. description: {description}"

    with tempfile.TemporaryDirectory(prefix=f"lyra_worker_{job_id}_") as tmp:
        tmpdir = Path(tmp)
        scene_paths = []
        for i, scene in enumerate([scene1, scene2, scene3], start=1):
            prompt = build_scene_prompt(base_context, scene, style)
            image = sd_scene_image(prompt, width, height)
            overlay = add_scene_text(
                image,
                str(scene.get("eyebrow") or ""),
                str(scene.get("headline") or ""),
                str(scene.get("body") or ""),
            )
            path = tmpdir / f"scene{i}.png"
            overlay.save(path, format="PNG")
            scene_paths.append(path)

        audio_path = tmpdir / "narration.mp3"
        narration = str(storyboard.get("narration") or "")
        if not create_narration(narration, audio_path):
            raise WorkerError("Narration synthesis failed (edge-tts)")

        output_path = tmpdir / "render.mp4"
        run_ffmpeg(scene_paths, audio_path, output_path, fps)

        if not output_path.exists() or output_path.stat().st_size <= 0:
            raise WorkerError("Render output missing")

        final_path = Path(tempfile.gettempdir()) / f"lyra_result_{job_id}.mp4"
        output_path.replace(final_path)
        return final_path


def main() -> None:
    if not RENDER_KEY:
        raise SystemExit("RENDER_WORKER_SHARED_KEY is required")

    print(f"[worker] starting {WORKER_NAME}")
    print(f"[worker] endpoint: {WORKER_ENDPOINT}")
    print(f"[worker] sd api: {SD_WEBUI_URL}")

    while True:
        try:
            job = claim_job()
            if not job:
                time.sleep(POLL_INTERVAL)
                continue

            job_id = str(job.get("job_id") or "")
            if not job_id:
                time.sleep(POLL_INTERVAL)
                continue

            print(f"[worker] claimed {job_id}")
            try:
                result_path = render_job(job)
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
