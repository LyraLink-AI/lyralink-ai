# iPhone / App Store Checklist

## Required before submission
- Apple Developer account active
- Bundle identifier finalized
- App icon and screenshots prepared
- Privacy Policy URL live
- Terms of Service URL live
- Support URL live
- Account deletion available in app/backend
- Apple in-app purchase configured for digital upgrades
- Receipt verification secret configured on the server
- TestFlight testing completed

## Security review items
- HTTPS only
- No hardcoded secrets in mobile app
- Server-side session or bearer-token validation
- Rate limiting for auth and billing flows
- Clear moderation and abuse-reporting path
