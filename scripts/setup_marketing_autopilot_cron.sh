#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="/var/www/vhosts/lyralinkai.com/httpdocs"
PHP_BIN="$(command -v php || true)"

if [[ -z "$PHP_BIN" ]]; then
  echo "php not found in PATH"
  exit 1
fi

# Runs every 6 hours.
CRON_LINE="0 */6 * * * cd ${ROOT_DIR} && ${PHP_BIN} ${ROOT_DIR}/cron/marketing_autopilot_runner.php >> /tmp/lyralink-marketing-autopilot.log 2>&1"

TMP_CRON="$(mktemp)"
crontab -l 2>/dev/null | grep -v 'marketing_autopilot_runner.php' > "$TMP_CRON" || true
echo "$CRON_LINE" >> "$TMP_CRON"
crontab "$TMP_CRON"
rm -f "$TMP_CRON"

echo "Installed marketing-autopilot cron job:"
echo "$CRON_LINE"
