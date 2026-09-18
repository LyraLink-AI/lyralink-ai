# Troubleshooting

## Quick triage checklist

1. Confirm .env values are loaded on the running host.
2. Confirm provider API key is valid.
3. Confirm selected model is valid for selected provider.
4. Check /api/status.php and web server error logs.

## Chat requests fail

1. Check selected LLM provider and model in .env.
2. Confirm corresponding API key is present.
3. Verify model is valid for that provider.
4. Check server logs and /api/status.php.

## Model selected but usage not shown where expected

- Confirm the requested model exists at provider.
- Fallback can route to a different provider if primary fails.
- Use developer diagnostics in chat (desktop) to see requested vs actual route.

## Chat appears different on mobile than desktop

- Hard refresh after CSS or template updates.
- Verify chat page is not loading conflicting global mobile overrides.
- Check viewport and safe-area behavior on iOS devices.

## Mobile layout issues

- Hard refresh on device after CSS updates.
- Confirm page-specific styles are not overridden by global mobile CSS.
- Validate touch targets and safe-area spacing on iOS/Android.

## Login/registration problems

- Verify DB credentials and table schema.
- Check SMTP settings for verification flows.
- Confirm 2FA provider values if using TOTP/YubiKey.

## Wiki push/auth problems

- Deploy keys are repository-scoped.
- Wiki publishing uses a special wiki git endpoint under the same repo wiki feature.
- If SSH push fails repeatedly, use an account-level SSH key or HTTPS PAT method.

## Billing callbacks not updating plans

- Confirm PAYPAL_MODE and plan IDs match your PayPal app.
- Verify callback URLs and return endpoint access.
- Check webhook/callback request logs.

## Deploy integration errors

- Confirm PELICAN_* and PLESK_* variables.
- Validate API permissions on remote services.
- Enable integration flags only when fully configured.
