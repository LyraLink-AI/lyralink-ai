#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="/var/www/vhosts/lyralinkai.com/httpdocs"
PHP_BIN="/usr/bin/php"
DATASET_CRON_LINE="30 * * * * cd ${ROOT_DIR} && ${PHP_BIN} ${ROOT_DIR}/cron/dataset_auto_learn.php >> /tmp/lyralink-dataset-auto-learn.log 2>&1"
CONTINUOUS_CRON_LINE="30 * * * * cd ${ROOT_DIR} && ${PHP_BIN} ${ROOT_DIR}/cron/continuous_model_learning.php >> /tmp/lyralink-continuous-learning.log 2>&1"
PUBLIC_WEB_CRON_LINE="0 */6 * * * cd ${ROOT_DIR} && ${PHP_BIN} ${ROOT_DIR}/cron/public_web_seed.php --quiet >> /tmp/lyralink-public-web-seed.log 2>&1"

tmpfile="$(mktemp)"
trap 'rm -f "$tmpfile"' EXIT

crontab -l 2>/dev/null | grep -v 'cron/continuous_model_learning.php' | grep -v 'cron/dataset_auto_learn.php >> /tmp/lyralink-dataset-auto-learn.log' | grep -v 'cron/public_web_seed.php' > "$tmpfile" || true
printf '%s\n' "$DATASET_CRON_LINE" >> "$tmpfile"
printf '%s\n' "$CONTINUOUS_CRON_LINE" >> "$tmpfile"
printf '%s\n' "$PUBLIC_WEB_CRON_LINE" >> "$tmpfile"
crontab "$tmpfile"

echo "Installed continuous learning cron:"
echo "$DATASET_CRON_LINE"
echo "$CONTINUOUS_CRON_LINE"
echo "$PUBLIC_WEB_CRON_LINE"