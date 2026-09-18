# Enterprise SaaS Roadmap (Implementation Baseline)

This document tracks what is now implemented and what remains to reach full company-grade SaaS readiness.

## Implemented in Code (This Phase)

### Multi-tenant architecture
- Added organizations and memberships:
  - `organizations`
  - `organization_members`
- Added auto-provisioned personal workspace per existing user.
- Added active organization context (`users.active_org_id`).
- Added tenant-scoped automation behavior (`org_id` enforced in automation CRUD/history/run paths).

### Identity and access control (core RBAC)
- Added Owner/Admin/Member roles in `organization_members.role`.
- Added org/team API endpoints in `/api/org.php`:
  - `context`
  - `list_orgs`
  - `create_org`
  - `switch_org`
  - `add_member`
  - `set_role`
  - `remove_member`
- Added stronger session cookie flags in auth API.

### Billing + subscriptions (foundation)
- Added `org_subscriptions` table for org-level plan state.
- Billing status now resolves effective plan from org subscription (with user-plan fallback).
- Added `org_usage_events` for future usage-based billing and invoices.

### Reliability baseline
- Added request idempotency store (`saas_idempotency_keys`).
- Added automation `Idempotency-Key` support for create/run_now.
- Added cron execution lock table (`automation_job_locks`) to prevent duplicate per-minute runs.
- Added shared SaaS rate-limit table and run-now throttling.

### Security hardening
- Added org audit log table (`org_audit_logs`) and audit writes for org and automation mutations.
- Added signed outgoing webhooks capability:
  - `org_webhook_secrets`
  - `X-Lyralink-Signature` header (`sha256=...`)
- Existing 2FA support remains in auth/billing flow.

## Remaining for Full Enterprise SaaS

### Identity (SSO/SCIM)
- OAuth/OIDC enterprise SSO providers (Google Workspace, Microsoft Entra).
- SAML 2.0 support for enterprise accounts.
- SCIM 2.0 provisioning endpoints + token management.
- Just-in-time org user provisioning and domain claim/verification.

### Billing operations
- Invoice generation and downloadable PDF receipts.
- Proration logic for seat changes and mid-cycle plan changes.
- Dunning (failed-payment retries, email workflows, account state transitions).
- Tax/VAT engine integration and tax metadata persistence.

### Reliability and operations
- Background queue for automation execution retries with backoff and dead-letter states.
- Backup + restore runbooks and scheduled restore drills.
- SLO/SLA dashboards and alerting.
- Incident management playbooks and postmortem template.

### Security/compliance
- Secrets rotation workflow and KMS-backed secret storage.
- SOC 2 evidence mapping, controls checklist, and audit procedures.
- Data retention and legal deletion policies at org level.
- Fine-grained API key scopes and key rotation policy.

## Suggested Next Build Order
1. SSO (OIDC first), then SAML.
2. SCIM provisioning endpoints.
3. Invoicing + proration + dunning flow.
4. Queue-based automation retries and DLQ.
5. Compliance controls and policy surfaces (DPA, retention, subprocessor disclosure).
