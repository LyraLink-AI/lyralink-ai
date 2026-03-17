# Troubleshooting

## Chat requests fail

1. Check selected LLM provider and model in .env.
2. Confirm corresponding API key is present.
3. Verify model is valid for that provider.
4. Check server logs and /api/status.php.

## Model selected but usage not shown where expected

- Confirm the requested model exists at provider.
- Fallback can route to a different provider if primary fails.
- Use developer diagnostics in chat (desktop) to see requested vs actual route.

## Mobile layout issues

- Hard refresh on device after CSS updates.
- Confirm page-specific styles are not overridden by global mobile CSS.
- Validate touch targets and safe-area spacing on iOS/Android.

## Login/registration problems

- Verify DB credentials and table schema.
- Check SMTP settings for verification flows.
- Confirm 2FA provider values if using TOTP/YubiKey.

## Billing callbacks not updating plans

- Confirm PAYPAL_MODE and plan IDs match your PayPal app.
- Verify callback URLs and return endpoint access.
- Check webhook/callback request logs.

## Deploy integration errors

- Confirm PELICAN_* and PLESK_* variables.
- Validate API permissions on remote services.
- Enable integration flags only when fully configured.
