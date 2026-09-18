# macOS Code Signing & Notarization

This guide covers signing the Lyralink Tauri app for macOS so it passes Gatekeeper checks without "damaged" warnings on first run.

## Prerequisites

- Apple Developer account with valid signing certificate
- macOS with Xcode command line tools installed
- Valid team ID and developer identity

## Steps

### 1. Obtain Signing Certificate

1. Log into [Apple Developer portal](https://developer.apple.com)
2. Navigate to **Certificates, Identifiers & Profiles** → **Certificates**
3. Create or download an **Apple Distribution** certificate (`.cer`)
4. Download the private key (`.p8` or from Keychain)
5. Export to `.p12` format:
   ```bash
   security export -k ~/Library/Keychains/login.keychain-db \
     -t identities -f pkcs12 -o /tmp/lyralink-cert.p12 \
     -P "your-p12-password"
   ```

### 2. Encode Certificate for CI/Env Vars

If building in CI (GitHub Actions, etc.), base64-encode the certificate:

```bash
base64 -i /tmp/lyralink-cert.p12 > /tmp/lyralink-cert.b64
cat /tmp/lyralink-cert.b64
```

### 3. Set Environment Variables

#### For Local Development

Add to your shell profile (`.zshrc`, `.bash_profile`):

```bash
export APPLE_CERTIFICATE="<base64-encoded-cert>"
export APPLE_CERTIFICATE_PASSWORD="your-p12-password"
export APPLE_SIGNING_IDENTITY="Developer ID Application: Your Name (TEAM1D)"
export APPLE_TEAM_ID="TEAM1D"
```

Or create a `.env` file in this directory:

```bash
APPLE_CERTIFICATE=<your-base64-cert>
APPLE_CERTIFICATE_PASSWORD=<your-password>
APPLE_SIGNING_IDENTITY=Developer ID Application: Your Name (TEAM1D)
APPLE_TEAM_ID=TEAM1D
```

Then source it before building:

```bash
source .env && npm run build:mac:signed
```

#### For GitHub Actions

Add these as **Repository Secrets**:

- `APPLE_CERTIFICATE`
- `APPLE_CERTIFICATE_PASSWORD`
- `APPLE_SIGNING_IDENTITY`
- `APPLE_TEAM_ID`

Then reference in your workflow:

```yaml
- name: Build signed macOS app
  run: npm run build:mac:signed
  env:
    APPLE_CERTIFICATE: ${{ secrets.APPLE_CERTIFICATE }}
    APPLE_CERTIFICATE_PASSWORD: ${{ secrets.APPLE_CERTIFICATE_PASSWORD }}
    APPLE_SIGNING_IDENTITY: ${{ secrets.APPLE_SIGNING_IDENTITY }}
    APPLE_TEAM_ID: ${{ secrets.APPLE_TEAM_ID }}
```

### 4. Build Signed App

```bash
npm run build:mac:signed
```

Architecture-specific:

```bash
npm run build:mac:signed:aarch64    # Apple Silicon
npm run build:mac:signed:x64        # Intel x64
```

### 5. Verify Signing

After build completes, verify the app is signed:

```bash
codesign -vvv ./src-tauri/target/release/bundle/macos/Lyralink.app
```

Expected output:

```
./src-tauri/target/release/bundle/macos/Lyralink.app: valid on disk
```

## Notarization (Apple)

For distribution outside the App Store, Apple recommends notarizing your app. Tauri v2+ automates this; for v1, you may need to use `xcrun notarytool`.

### Quick Notarization (Tauri v1 Manual)

1. Upload:
   ```bash
   xcrun notarytool submit ./src-tauri/target/release/bundle/macos/Lyralink.dmg \
     --apple-id your-apple-id@example.com \
     --password "app-specific-password" \
     --team-id TEAM1D \
     --wait
   ```

2. Staple the ticket:
   ```bash
   xcrun stapler staple ./src-tauri/target/release/bundle/macos/Lyralink.dmg
   ```

## Troubleshooting

### `Certificate not found`

- Ensure certificate is in Keychain: `security find-identity`
- Verify `APPLE_SIGNING_IDENTITY` matches output exactly

### `Invalid certificate password`

- Test password: `security export-db -ki login.keychain`
- Regenerate P12 with correct password

### Build fails silently

- Check Tauri logs: `RUST_LOG=debug npm run build:mac:signed`

## Distribution

Once signed and notarized, users can safely run:

```bash
open ./Lyralink.dmg
```

without Gatekeeper warnings.

---

For more details, see [Tauri macOS Signing Docs](https://tauri.app/v1/guides/building/macos/).
