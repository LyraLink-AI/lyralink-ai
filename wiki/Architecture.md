# Architecture

## High-level layout

- UI entrypoints: chat.php, index.php, and page templates under /pages
- API handlers: action-based scripts under /api
- Jobs and automation: /cron scripts
- Static assets: /assets and /images
- Dependencies: Composer packages under /vendor

## Chat flow

1. User sends message from chat UI.
2. Frontend posts payload to /api/chat.php.
3. Backend resolves plan constraints and provider/model routing.
4. Optional dataset context and optional code-test validation are applied.
5. Response returns with reply and optional debug/trace metadata.

## Auth and account flow

- Account actions are handled through /api/auth.php.
- Session state controls protected actions and UI visibility.
- Optional verification and 2FA methods can be enabled.

## Configuration model

- Environment variables in .env control providers, integrations, and feature flags.
- Plan-specific allowlists enforce provider/model constraints.

## Integrations

- SMTP for account and messaging workflows
- PayPal for billing and plan lifecycle
- Discord and bot sync features
- Plesk integration toggles

## Mobile behavior

- Chat uses dedicated mobile layout logic and navigation states.
- Some admin pages use compact menu patterns on small screens.

## Operational notes

- Keep debug disabled in production.
- Restrict CORS origins.
- Validate model IDs when changing provider defaults.
