# Security Policy

## Supported Versions

This project is maintained on the `main` branch.
Security fixes are applied to `main` and included in future releases.

## Reporting a Vulnerability

Please do not open public issues for security vulnerabilities.

Report vulnerabilities privately to:

- security@cloudhavenx.com

If email is unavailable, open a private advisory in GitHub Security Advisories.

Please include:

- A clear description of the issue
- Reproduction steps or proof of concept
- Potential impact
- Suggested mitigation (if known)

## Response Targets

- Initial acknowledgment: within 72 hours
- Triage decision: within 7 days
- Remediation timeline: based on severity and exploitability

## Secret Handling

- Never commit real credentials, API keys, or tokens.
- Use environment variables via `.env` (see `.env.example`).
- Rotate any leaked secrets immediately.
