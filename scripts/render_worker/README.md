# Lyralink GPU Render Worker (Bazzite Linux + GTX 1660)

This worker turns your PC GPU into a remote video renderer for Lyralink marketing runs.

Pipeline:

1. Lyralink server queues a render job.
2. This worker claims the job over HTTPS.
3. Stable Diffusion WebUI API (local) generates cinematic scene images on your GPU.
4. An optional local motion API can turn stills into richer clips before final assembly.
5. `edge-tts` generates narration.
6. FFmpeg assembles motion, timed captions, transitions, music ducking, and narration.
7. Worker uploads final MP4 back to Lyralink.

## 1) Server env values

Set these in server `.env`:

- `VIDEO_GEN_PROVIDER=remote_worker`
- `RENDER_WORKER_SHARED_KEY=<long-random-secret>`
- `RENDER_WORKER_WAIT_TIMEOUT=1800`
- `RENDER_WORKER_JOB_TTL=7200`
- `RENDER_WORKER_STYLE_PRESET=cinematic_marketing`
- `RENDER_WORKER_RESOLUTION=1280x720`
- `RENDER_WORKER_FPS=30`
- `RENDER_WORKER_MAX_DURATION=30`

## 2) Host prerequisites (Bazzite Linux)

Install:

- Python 3.11+
- FFmpeg in PATH
- Stable Diffusion WebUI or Forge with API enabled on `http://127.0.0.1:7860`

Recommended launch args for GTX 1660 (6GB):

- `--api --xformers --medvram`

Use SD1.5/realistic models for best speed on 1660.

### Bazzite note

Bazzite is immutable, so the cleanest path is usually one of these:

1. Run the worker from a `toolbox` or `distrobox` container that has Python and FFmpeg installed.
2. Or layer the packages if you already manage your host that way.

If you want the least friction, use a toolbox and keep the worker folder in your home directory.

## 3) Worker setup

From this folder:

1. Copy `.env.example` to `.env`
2. Set `RENDER_WORKER_SHARED_KEY` to exactly match server `.env`
3. Run `chmod +x start_worker.sh`
4. Run `./start_worker.sh`

If successful, you will see:

- worker startup logs
- periodic polling
- `claimed mrj_...` when a render arrives
- `submitted mrj_...` after upload

## 4) Running continuously

Use a user-level systemd service:

1. Copy this folder to a stable path such as `~/lyralink-render-worker`
2. Copy `lyralink-render-worker.service` to `~/.config/systemd/user/lyralink-render-worker.service`
3. Run `systemctl --user daemon-reload`
4. Run `systemctl --user enable --now lyralink-render-worker.service`
5. Check logs with `journalctl --user -u lyralink-render-worker.service -f`

If you need it to survive reboots without an active desktop login session, enable lingering:

- `loginctl enable-linger $USER`

## 5) Quality tuning

For sharper cinematic output, tune `.env` on worker:

- Increase `SD_STEPS` to `34-40`
- Keep `SD_CFG` around `6.5-7.5`
- For speed fallback: `SD_WIDTH=896`, `SD_HEIGHT=504`
- Keep narration voice as `en-US-JennyNeural` or switch to `en-US-GuyNeural`
- Use a strong SD 1.5 cinematic checkpoint first; GTX 1660 will struggle with heavier SDXL pipelines for unattended batch rendering
- Keep Forge or A1111 on a lighter VAE/control stack to avoid VRAM stalls on 6 GB
- Put royalty-free music tracks in a local folder and set `BACKGROUND_MUSIC_DIR=/path/to/music`
- If `BACKGROUND_MUSIC_DIR` is empty or has no usable tracks, the worker will try to fetch public-domain audio from Internet Archive and cache it locally
- If remote music cannot be fetched, the worker will synthesize a simple ambient bed automatically so the render still has music
- Leave `ENABLE_CAPTIONS=1` for subtitle overlays
- If you expose a local motion service, set `MOTION_API_URL=http://127.0.0.1:PORT/...` and keep `MOTION_PROVIDER=auto`

## 6) Troubleshooting

- `Unauthorized render worker`: key mismatch between worker and server.
- `Stable Diffusion API returned no images`: local SD API not running or no model loaded.
- FFmpeg errors: verify `ffmpeg -version` from the worker shell.
- Slow renders: lower resolution/steps, or use a lighter SD model.
- If Python install is awkward on Bazzite host, run the worker inside `toolbox` and bind-mount or copy the folder into your home directory.
- If WebUI runs on another port or another box on your LAN, update `SD_WEBUI_URL` in `.env`.

## 7) Practical Bazzite setup example

Example flow using toolbox:

1. `toolbox create lyralink-worker`
2. `toolbox enter lyralink-worker`
3. `sudo dnf install -y python3 python3-pip python3-virtualenv ffmpeg`
4. Copy this worker folder into `~/lyralink-render-worker`
5. `cd ~/lyralink-render-worker`
6. `cp .env.example .env`
7. Set `RENDER_WORKER_SHARED_KEY` and any SD settings
8. `./start_worker.sh`

## 8) Current render style

Right now the worker does this:

1. Generates three cinematic scene images from the storyboard using your GPU.
2. Applies per-scene style variation and prompt-role direction.
3. Optionally asks a local motion API to animate scenes, with a local fallback if that API is unavailable.
4. Adds branded text overlays locally.
5. Generates narration with `edge-tts`.
6. Assembles motion, timed captions, fades, music ducking, and audio in FFmpeg.

That already gives you a major step up from server-side static slides. If you want something closer to high-end AI ad tools after this, the next upgrades should be image-to-video motion models, per-shot prompt packs, background music ducking, and timed subtitles.
