#!/usr/bin/env bash
#
# Telegram notifications for the publisher and the health check.
#
#     notify.sh <key> <state> <text...>
#
# `key` names the thing being reported ("deploy", "health:fr", "converge"), and
# `state` is ok|fail. A message is sent only when the state of that key CHANGES:
# a site that has been down for an hour must not produce thirty messages, and a
# recovery is worth exactly one.
#
# Configure in /etc/aigf.env:
#     TELEGRAM_BOT_TOKEN=123456:ABC...
#     TELEGRAM_CHAT_ID=-1001234567890
#
# Without those, this is a no-op that still records the state, so switching
# notifications on later does not replay old failures.

set -uo pipefail

STATE_DIR=${STATE_DIR:-/srv/aigf-network/var/state}
ENV_FILE=${AIGF_ENV:-/etc/aigf.env}

KEY=${1:?usage: notify.sh <key> <ok|fail> <text...>}
STATE=${2:?usage: notify.sh <key> <ok|fail> <text...>}
shift 2
TEXT="$*"

mkdir -p "$STATE_DIR"
SAFE_KEY=$(printf '%s' "$KEY" | tr -c 'a-zA-Z0-9_.-' '_')
STATE_FILE="$STATE_DIR/$SAFE_KEY"
PREVIOUS=$(cat "$STATE_FILE" 2>/dev/null || echo 'unknown')

printf '%s\n' "$STATE" > "$STATE_FILE"
[ "$PREVIOUS" = "$STATE" ] && exit 0   # nothing changed, stay quiet

# shellcheck disable=SC1090
[ -r "$ENV_FILE" ] && . "$ENV_FILE"
[ -n "${TELEGRAM_BOT_TOKEN:-}" ] && [ -n "${TELEGRAM_CHAT_ID:-}" ] || exit 0

case "$STATE" in
    ok)   ICON='✅' ;;
    fail) ICON='🔴' ;;
    *)    ICON='ℹ️' ;;
esac

HOST=$(hostname -s 2>/dev/null || echo server)
curl -sS -m 20 -o /dev/null \
    --data-urlencode "chat_id=${TELEGRAM_CHAT_ID}" \
    --data-urlencode "text=${ICON} ${KEY} на ${HOST}
${TEXT}" \
    --data "disable_web_page_preview=true" \
    "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
    || echo "notify: Telegram unreachable" >&2
