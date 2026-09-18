#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
  echo "Run as root: sudo bash scripts/setup_coturn.sh"
  exit 1
fi

TURN_DOMAIN="${1:-turn.lyralinkai.com}"
TURN_REALM="${2:-lyralinkai.com}"
TURN_USER="${TURN_USER:-lyralinkturn}"
TURN_PASS="${TURN_PASS:-$(openssl rand -base64 24 | tr -d '=+/\n' | cut -c1-32)}"
TURN_EXTERNAL_IP="${TURN_EXTERNAL_IP:-$(curl -4 -fsS https://api.ipify.org || true)}"

if [[ -z "$TURN_EXTERNAL_IP" ]]; then
  echo "Unable to detect public IPv4 automatically. Set TURN_EXTERNAL_IP and rerun."
  exit 1
fi

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y coturn ufw

mkdir -p /etc/turnserver
cp "$(dirname "$0")/turnserver.conf.example" /etc/turnserver/turnserver.conf
sed -i "s|__TURN_DOMAIN__|${TURN_DOMAIN}|g" /etc/turnserver/turnserver.conf
sed -i "s|__TURN_REALM__|${TURN_REALM}|g" /etc/turnserver/turnserver.conf
sed -i "s|__TURN_USER__|${TURN_USER}|g" /etc/turnserver/turnserver.conf
sed -i "s|__TURN_PASS__|${TURN_PASS}|g" /etc/turnserver/turnserver.conf
sed -i "s|__TURN_EXTERNAL_IP__|${TURN_EXTERNAL_IP}|g" /etc/turnserver/turnserver.conf

TLS_CERT_DIR="/etc/letsencrypt/live/${TURN_DOMAIN}"
if [[ -f "${TLS_CERT_DIR}/fullchain.pem" && -f "${TLS_CERT_DIR}/privkey.pem" ]]; then
  cat >> /etc/turnserver/turnserver.conf <<EOF
cert=${TLS_CERT_DIR}/fullchain.pem
pkey=${TLS_CERT_DIR}/privkey.pem
EOF
  HAS_TLS=1
else
  HAS_TLS=0
fi

if [[ -f /etc/default/coturn ]]; then
  sed -i "s/^#\?TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/" /etc/default/coturn || true
  grep -q '^TURNSERVER_ENABLED=1$' /etc/default/coturn || echo 'TURNSERVER_ENABLED=1' >> /etc/default/coturn
fi

ufw allow 3478/tcp || true
ufw allow 3478/udp || true
if [[ ${HAS_TLS} -eq 1 ]]; then
  ufw allow 5349/tcp || true
  ufw allow 5349/udp || true
fi
ufw allow 49152:65535/udp || true

systemctl enable coturn
systemctl restart coturn
systemctl --no-pager --full status coturn | sed -n '1,12p'

echo
echo "TURN server configured. Add these to your .env:"
echo "WEBRTC_STUN_URLS=stun:${TURN_DOMAIN}:3478"
if [[ ${HAS_TLS} -eq 1 ]]; then
  echo "WEBRTC_TURN_URLS=turn:${TURN_DOMAIN}:3478?transport=udp,turn:${TURN_DOMAIN}:3478?transport=tcp,turns:${TURN_DOMAIN}:5349?transport=tcp"
else
  echo "WEBRTC_TURN_URLS=turn:${TURN_DOMAIN}:3478?transport=udp,turn:${TURN_DOMAIN}:3478?transport=tcp"
fi
echo "WEBRTC_TURN_USERNAME=${TURN_USER}"
echo "WEBRTC_TURN_CREDENTIAL=${TURN_PASS}"
echo "WEBRTC_ICE_TRANSPORT_POLICY=all"
