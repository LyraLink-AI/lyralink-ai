# Lyralink Security Audit Report

Date: 2026-07-18
Auditor: GitHub Copilot (GPT-5.3-Codex)
Scope: Full workspace review, live request abuse simulation, targeted remediation

## Executive Summary

A complete security pass was performed across API, admin, auth, desktop/local router, and support surfaces.

A critical unauthorized data exposure in the admin API was reproduced and fixed during this audit.
Additional hardening was implemented for API key transport, redirect safety, local router traversal, and dependency vulnerabilities.

Intrusion monitoring is now automated to check every 2 minutes and alert Discord channel 1475657872862875727.

## Findings and Status

### 1) Critical: Unauthenticated admin data exposure
- Severity: Critical
- Endpoint: /api/admin.php (and desktop mirror)
- Repro (pre-fix): POST action=status returned billing and customer telemetry without authentication under fork-host logic.
- Root cause: Implicit auth bypass in fork-mode behavior.
- Fix: Require developer session by default; only allow bypass when ALLOW_UNAUTH_FORK_ADMIN=1.
- Status: Fixed and revalidated.

### 2) High: Query-string API key transport leakage
- Severity: High
- Endpoint: /api/public_api.php (and desktop mirror)
- Risk: API keys leak through URLs, logs, referrers, browser history.
- Fix: Query-key auth disabled by default. Header/Bearer required. Optional override: PUBLIC_API_ALLOW_QUERY_KEY=1.
- Status: Fixed and revalidated.

### 3) High: Desktop local router traversal/LFI risk
- Severity: High
- Endpoint: desktop local router copies
- Risk: Crafted path traversal to include unintended files.
- Fix: Realpath + docroot containment validation before requiring direct PHP path.
- Status: Fixed and revalidated with traversal payloads.

### 4) Medium: Redirect/input handling hardening
- Severity: Medium
- Endpoints: billing return, mail SSO redirect handling, reseller impersonation redirect
- Fixes: input sanitization, redirect host lock, malformed/protocol-relative redirect blocking.
- Status: Fixed and revalidated.

### 5) Medium: Dependency advisories
- Severity: Medium
- Packages: guzzlehttp/psr7, symfony/http-foundation, symfony/routing (plus lockstep updates)
- Fix: Composer update and lock refresh.
- Status: Fixed; composer audit now reports zero advisories.

## Live Abuse Simulation Evidence (post-fix)

### Access control / sensitive endpoint checks
- /api/security_log_api.php (unauth): 403 Forbidden
- /pages/security_log.php (unauth): 302 redirect to /
- /api/mail_admin.php create_mailbox (unauth): 403 Forbidden
- /api/plesk_admin.php snapshot (unauth): 403 Forbidden
- /api/support.php privileged write action (unauth): denied (not authenticated as agent)
- /api/admin.php status (unauth): Unauthorized (fixed)

### Transport and input hardening checks
- /api/public_api.php with ?key=: rejected with INSECURE_KEY_TRANSPORT
- /api/public_api.php with fake X-API-Key: INVALID_KEY (auth gate active)
- /api/billing_return.php with CRLF-style plan payload: safe redirect/error path

### Local router traversal probes
- ../ and encoded traversal requests against local router returned 404/Not Found.
- No out-of-root include observed.

## New Security Operations Additions

### Security logs page availability
- Existing security log page is now linked from admin tools for easier operator access.
- Route: /pages/security_log.php

### Automated intrusion monitor
- New worker: cron/security_intrusion_monitor.php
- Schedule: every 2 minutes
- Alert channel: Discord channel ID 1475657872862875727
- Uses BOT_SECRET_KEY for Discord bot API authentication
- Detection patterns:
  - Login failure burst (>=5 in 15 min per IP)
  - Admin access denied burst (>=3 in 20 min per IP)
  - Active auth rate-limit lockouts with high attempts
- Alert dedupe:
  - Same fingerprint suppressed for 30 minutes

### Cron setup
- Helper script: scripts/setup_intrusion_monitor_cron.sh
- Installed cron entry:
  - */2 * * * * cd /var/www/vhosts/lyralinkai.com/httpdocs && /usr/bin/php /var/www/vhosts/lyralinkai.com/httpdocs/cron/security_intrusion_monitor.php >/dev/null 2>&1

## Residual Risks / Recommendations

1. Run authenticated abuse tests with dedicated staging credentials:
   - privilege escalation attempts
   - CSRF token/origin bypass under real browser flows
   - account takeover paths (reset and 2FA fallback)

2. Add edge-level controls:
   - WAF rules for repeated auth abuse patterns
   - rate limits at reverse proxy/CDN by IP + path

3. Add SIEM export:
   - forward security_log and intrusion alerts to centralized log pipeline

4. Secret hygiene:
   - ensure BOT_SECRET_KEY remains in environment only
   - rotate if ever exposed

## Modified Files (this phase)

- api/admin.php
- desktop/windows-client/web/api/admin.php
- cron/security_intrusion_monitor.php
- scripts/setup_intrusion_monitor_cron.sh
- pages/admin.php
- desktop/windows-client/web/pages/admin.php
- .env.example
- README.md
- SECURITY_AUDIT_REPORT_2026-07-18.md
