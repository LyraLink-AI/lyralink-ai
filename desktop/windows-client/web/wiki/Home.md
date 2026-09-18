# LyraLink AI Wiki

LyraLink AI is a full-stack AI chat platform with account auth, plan-aware model routing, dataset context search, and integrated support/admin tooling.

Repository: https://github.com/LyraLink-AI/lyralink-ai

## Start Here

- New setup: [[Getting Started]]
- Environment and providers: [[Configuration]]
- Endpoints and examples: [[API Reference]]
- Common production fixes: [[Troubleshooting]]
- Frequent questions: [[FAQ]]

## Core Features

- Chat interface with markdown rendering, code blocks, and mobile-first behavior
- Multi-provider LLM routing with plan-level model allowlists
- Account, verification, and optional 2FA support
- Dataset ingestion and retrieval-augmented context
- Billing and plan flows
- Admin and support tooling

## Architecture At A Glance

- Frontend pages: PHP-rendered UI pages under pages and chat entrypoint at chat.php
- API layer: action-oriented handlers under /api
- Data layer: MySQL-backed account and app data
- Integrations: SMTP, Discord, PayPal, and Plesk

Detailed view: [[Architecture]]

## Security Quick Notes

- Keep .env out of version control
- Rotate secrets immediately if exposed
- Restrict CORS origins in production
- Disable debug defaults in production deployments

## Screenshots

Use this section in GitHub Wiki to add product screenshots:

- Chat desktop view
- Chat mobile view
- Account settings
- Admin dashboard
