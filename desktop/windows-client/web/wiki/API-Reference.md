# API Reference

Base path: /api

## Conventions

- Most endpoints are action-based handlers.
- Some endpoints accept form-data, others accept JSON.
- Auth-bound operations require active session cookies.

## Authentication and account

- POST /api/auth.php
  - Actions include login, register, check session, logout, model options, and account security flows.

Example login (form-data):

```bash
curl -X POST https://your-domain.com/api/auth.php \
  -F "action=login" \
  -F "email=user@example.com" \
  -F "password=your_password"
```

## Chat and AI

- POST /api/chat.php
  - Main chat generation endpoint.
  - Supports provider/model routing, plan limits, optional live trace, and optional code-test validation.

Example chat request (JSON):

```bash
curl -X POST https://your-domain.com/api/chat.php \
  -H "Content-Type: application/json" \
  -d '{
    "messages": [{"role": "user", "content": "Write a hello world in Python"}],
    "user_plan": "free",
    "run_code_tests": true,
    "live_trace": false
  }'
```

Typical response fields:

- reply
- debug (developer-visible diagnostics)
- trace (when live trace is enabled)
- usage/tokens (when provider returns them)

## Dataset

- POST /api/dataset.php
- POST /api/dataset_search.php

Example dataset search:

```bash
curl -X POST https://your-domain.com/api/dataset_search.php \
  -F "query=pricing limits for pro plan"
```

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

- POST /api/chat.php (with moltbook/context options)

## Notes

- Most API scripts are action-driven via form fields or JSON body.
- Keep auth/session cookies enabled for account-bound operations.
- Some endpoints are role-gated and require admin/developer user context.

## Error handling

- 400-range errors often indicate invalid input or model selection.
- 500-range errors usually indicate provider/integration failures.
- For chat routing issues, inspect requested vs actual provider/model in debug data.
