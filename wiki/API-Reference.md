# API Reference

Base path: /api

## Authentication and account

- POST /api/auth.php
  - Actions include login, register, check session, logout, model options, and account security flows.

## Chat and AI

- POST /api/chat.php
  - Main chat generation endpoint.
  - Supports provider/model routing, plan limits, optional live trace, and optional code-test validation.

## Dataset

- POST /api/dataset.php
- POST /api/dataset_search.php

## Billing

- POST /api/billing.php
- GET/POST /api/billing_return.php

## Admin and support

- POST /api/admin.php
- POST /api/support.php
- POST /api/careers.php

## Public and status

- GET /api/public_api.php
- GET /api/status.php

## Other integrations

- POST /api/pelican.php
- POST /api/chat.php (with moltbook/context options)

## Notes

- Most API scripts are action-driven via form fields or JSON body.
- Keep auth/session cookies enabled for account-bound operations.
- Some endpoints are role-gated and require admin/developer user context.
