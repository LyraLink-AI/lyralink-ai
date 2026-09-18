# Operator Scale Blueprint

## Objective
Build a single operator platform that works for:
- First-time AI users
- Agencies and resellers
- Enterprise operators managed directly by Lyralink

## North-Star Metrics
- Time to first live deployment: under 15 minutes
- Time to first paying client: under 7 days
- Invite-attributed signup share: above 70%
- 30-day client retention: above 85%
- Gross margin stability: tracked weekly

## Product Pillars
1. Guided onboarding
2. Unified operator workspace
3. Margin-safe usage billing
4. Scalable distribution (embed + API)
5. Team and governance controls

## Phase 1: Foundation
- Unified Operator Workspace model over reseller + org primitives
- Operator Strategy dashboard with growth and payout telemetry
- Beginner Launchpad checklist and reusable templates
- Branding + acquisition + API channels available in one place

## Phase 2: Growth Engine
- Campaign-level invite analytics and UTM tracking
- Conversion funnel views from lead to paid client
- Growth playbook recommendations from real account metrics
- Operator client lifecycle workflows (trial, convert, retention)

## Phase 3: Enterprise Readiness
- Team roles and delegated administration
- Audit visibility and compliance controls
- Webhook/event feed for billing and account lifecycle
- Integration hub (CRM, billing, messaging, support)

## Integration Roadmap
1. Stripe metered usage and invoice sync
2. HubSpot/Salesforce lead pipeline sync
3. Slack/Teams notifications for billing and growth events
4. Zapier/Make automation templates
5. Accounting exports (QuickBooks/Xero)

## Operator Dashboard Modules
- Strategy Cockpit: client growth, MRR estimate, payout backlog
- Revenue: earned, pending, payout cadence
- Accounts: managed clients with plan and contribution mix
- Acquisition: invite and channel attribution
- Distribution: widget + API + docs
- Launchpad: beginner-to-enterprise operating checklist

## UX Guidelines
- Keep non-technical language as default
- Hide advanced controls behind explicit toggles
- Provide opinionated defaults for pricing and routing
- Turn every critical workflow into a 3-step guided path

## Operational Guardrails
- Enforce secure defaults for API key handling
- Keep usage pricing env-driven and transparent
- Surface warning states early (suspension, payout backlog, low attribution)
- Require explicit confirmation for destructive operator actions

## Next Build Queue
1. Add invite UTM capture and reporting table
2. Add weekly cohort snapshot job for retention trends
3. Add operator event/webhook API for external automations
4. Add one-click vertical templates (support, sales, booking)
5. Add in-dashboard margin simulator tied to model multipliers
