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

## Security Notes

- Rotate any previously exposed secrets immediately.
- Use environment variables for all secrets.
- Keep `BOT_SECRET_KEY` set in production.
