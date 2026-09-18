# TURN Server Setup

This project uses `coturn` for WebRTC relay support.

## 1. DNS
Create an A record such as:
- `turn.lyralinkai.com` -> your server public IP

## 2. TLS certificate
Before using `turns:` on port `5349`, provision Let's Encrypt certs for the TURN hostname.

## 3. Install
Run as root:

```bash
sudo bash scripts/setup_coturn.sh turn.lyralinkai.com lyralinkai.com
```

The script installs `coturn`, writes `/etc/turnserver/turnserver.conf`, opens firewall ports, and prints the `.env` values to use.

## 4. Required ports
- `3478/tcp`
- `3478/udp`
- `5349/tcp`
- `49152-65535/udp`

## 5. App env
Add these values to `.env`:

```env
WEBRTC_STUN_URLS=stun:turn.lyralinkai.com:3478
WEBRTC_TURN_URLS=turn:turn.lyralinkai.com:3478?transport=udp,turn:turn.lyralinkai.com:3478?transport=tcp,turns:turn.lyralinkai.com:5349?transport=tcp
WEBRTC_TURN_USERNAME=replace_me
WEBRTC_TURN_CREDENTIAL=replace_me
WEBRTC_ICE_TRANSPORT_POLICY=all
```

Use `WEBRTC_ICE_TRANSPORT_POLICY=relay` temporarily if you want to force TURN-only testing.
