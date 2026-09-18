# Chat Microcache Setup

This project now supports edge microcaching for anonymous chat requests by accepting and validating `X-Chat-Cache-Key`.

## What Changed

- Client sends `X-Chat-Cache-Key` for guest JSON chat requests.
- API only marks guest-safe responses as cacheable.
- Suggested nginx config is in `scripts/nginx/chat_microcache.conf.example`.

## Enable Steps

1. Copy the `proxy_cache_path` and `map` blocks into your nginx `http` context.
2. Add the `location = /api/chat.php` block inside your site `server` block.
3. Replace `PHP_UPSTREAM` with your upstream target.
4. Reload nginx.

## Validate

Run two identical guest requests with the same `X-Chat-Cache-Key`:

```bash
curl -sS -D - https://lyralinkai.com/api/chat.php \
  -H 'Content-Type: application/json' \
  -H 'X-Chat-Cache-Key: 1111111111111111' \
  --data '{"messages":[{"role":"user","content":"Give 3 MySQL index tips"}]}' >/dev/null

curl -sS -D - https://lyralinkai.com/api/chat.php \
  -H 'Content-Type: application/json' \
  -H 'X-Chat-Cache-Key: 1111111111111111' \
  --data '{"messages":[{"role":"user","content":"Give 3 MySQL index tips"}]}' >/dev/null
```

Second response should show `X-Cache-Status: HIT` when nginx microcache is active.

## Notes

- Authenticated requests are bypassed automatically by cookie/auth header checks.
- Keep TTL short (20-45 seconds) to balance freshness and latency.
- Do not enable this for requests carrying user-specific context.
