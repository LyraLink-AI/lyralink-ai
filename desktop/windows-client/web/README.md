# LyraLink AI

Open-source deployment of LyraLink AI by LyraLink-AI.

## Open Source Safety

This repository is configured to avoid leaking secrets:

- No production API keys or passwords are committed.
- `.env.example` provides required variables.
- `.env` and runtime secret files are ignored by git.

Before running locally, copy `.env.example` to `.env` and set your values.

## Fork Behavior

Fork/deployment preview mode is supported:

- If `FORK_MODE=1`, the root route redirects to `pages/admin.php`.
- Non-primary hosts also auto-enable fork preview behavior.
- In fork preview mode, admin page is read-only for sensitive controls.

## Quick Start

1. Install PHP dependencies:

```bash
composer install
```

2. Configure environment:

```bash
cp .env.example .env
# then edit .env
```

3. Run with your web server pointed to this directory.

## Mobile iPhone App

A starter Expo/React Native iPhone client now lives under `mobile/lyralink-ios`.

It is wired for:
- secure mobile token auth
- chat access against the existing PHP backend
- Apple IAP receipt verification hooks
- privacy/support URLs needed for App Store submission

## Security Notes

- Rotate any previously exposed secrets immediately.
- Use environment variables for all secrets.
- Keep `BOT_SECRET_KEY` set in production.
- Set the Apple IAP variables in `.env` before submitting the iPhone app.

## Intrusion Monitoring

This repository includes an intrusion monitor at `cron/security_intrusion_monitor.php`.

- Detects suspicious security patterns (login failure bursts, admin access probing, and active auth lockouts).
- Sends alerts to Discord channel `1475657872862875727` using your bot token from `BOT_SECRET_KEY`.
- Deduplicates repeated alerts for 30 minutes to reduce noise.

Install a 2-minute cron schedule:

```bash
bash scripts/setup_intrusion_monitor_cron.sh
```

Manual test run:

```bash
php cron/security_intrusion_monitor.php
```
