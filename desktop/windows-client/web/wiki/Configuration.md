# Configuration

This project uses environment variables from .env.

## Setup order

1. Core app and database
2. LLM provider and model
3. Optional integrations (SMTP, billing, deploy)
4. Plan allowlists

## Core app

- APP_DEBUG: set to 1 for debugging in non-production.
- FORK_MODE: set to 1 for fork preview behavior.
- ADMIN_DEV_USERNAME: username treated as developer in UI.
- ALLOWED_ORIGINS: comma-separated CORS origins.

## Database

- DB_HOST
- DB_USER
- DB_PASS
- DB_NAME

## LLM provider

- LLM_PROVIDER: groq, openrouter, or openai
- LLM_MODEL: default model for current provider
- GROQ_API_KEY
- OPENROUTER_API_KEY
- OPENROUTER_MODEL
- OPENAI_API_KEY
- OPENAI_BASE_URL
- OPENAI_MODEL

## Provider sanity checklist

- Set only the provider key you actively use first
- Verify model exists for that provider
- Keep fallback model lists valid to avoid silent route confusion

## Model allowlists by plan

- LLM_ALLOWED_PROVIDERS_FREE
- LLM_ALLOWED_MODELS_FREE
- LLM_ALLOWED_PROVIDERS_BASIC
- LLM_ALLOWED_MODELS_BASIC
- LLM_ALLOWED_PROVIDERS_PRO
- LLM_ALLOWED_MODELS_PRO
- LLM_ALLOWED_PROVIDERS_ENTERPRISE
- LLM_ALLOWED_MODELS_ENTERPRISE

## Integrations

- SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM
- BOT_SECRET_KEY
- MOLTBOOK_API_KEY
- YUBICO_CLIENT_ID, YUBICO_SECRET

## Billing

- PAYPAL_MODE
- PAYPAL_CLIENT_ID
- PAYPAL_SECRET
- PAYPAL_PLAN_BASIC
- PAYPAL_PLAN_PRO
- PAYPAL_PLAN_ENTERPRISE

## Deploy integrations

- PELICAN_URL, PELICAN_API_KEY
- PLESK_URL, PLESK_API_KEY, PLESK_DOMAIN
- ENABLE_PLESK
- ENABLE_WEB_BOT_PANEL

## Recommended production defaults

- APP_DEBUG=0
- Strong DB_PASS and BOT_SECRET_KEY
- Restrictive ALLOWED_ORIGINS
- Only enabled integrations you actively use

## Common config mistakes

- Invalid model ID for selected provider
- Missing API key for active provider
- Plan allowlist excludes selected model
- PAYPAL plan IDs from wrong environment mode
