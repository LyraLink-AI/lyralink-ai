# Lyralink Tauri Desktop

Tauri-based desktop wrapper for Lyralink with macOS build targets.

## Requirements

- Rust toolchain (`rustup`, `cargo`)
- Tauri system deps (WebKitGTK on Linux, Xcode on macOS)
- Node.js 18+

## Install

```bash
npm install
```

## Run (remote mode)

```bash
npm run tauri:dev
```

The app loads `https://lyralinkai.com/chat` by default.

## Run (local mode)

Start your local app server first, then run:

```bash
LYRALINK_MODE=local npm run tauri:dev
```

Optional env overrides:

- `LYRALINK_APP_URL`
- `LYRALINK_LOCAL_HOST`
- `LYRALINK_LOCAL_PORT`

## Build macOS

```bash
npm run build:mac
```

Architecture-specific builds:

```bash
npm run build:mac:aarch64
npm run build:mac:x64
```

### Signed Builds (for Distribution)

For distribution, sign with your Apple Developer certificate:

```bash
npm run build:mac:signed
```

See [SIGNING.md](./SIGNING.md) for full setup instructions (certificate, notarization, Gatekeeper).

## Output

Build artifacts are generated under:

- `src-tauri/target/release/bundle`
