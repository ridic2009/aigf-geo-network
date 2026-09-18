#!/usr/bin/env bash
#
# Installs the automation, once. After this the server keeps itself in line with
# the repository and says something in Telegram when it cannot.
#
#     sudo bash /srv/aigf-network/infra/install-automation.sh
#
# What it sets up:
#   aigf-deploy.timer   every 2 min: converge as root, then build and release as deploy
#   aigf-health.timer   every 10 min: check the live sites, report a change of state
#   /etc/aigf.env       Telegram credentials, mode 600, never in git
#
# The units call scripts from the checkout, so updating the automation itself is
# a normal commit — this installer is not needed again. It replaces the older
# aigf-auto-deploy.timer if that is still present.

set -euo pipefail

REPO_DIR=${REPO_DIR:-/srv/aigf-network}
DEPLOY_USER=${DEPLOY_USER:-deploy}
ENV_FILE=/etc/aigf.env

[ "$(id -u)" -eq 0 ] || { echo "run as root" >&2; exit 2; }
[ -d "$REPO_DIR/infra" ] || { echo "no checkout at $REPO_DIR" >&2; exit 2; }

echo "==> Telegram credentials"
if [ ! -f "$ENV_FILE" ]; then
    cat > "$ENV_FILE" <<'EOF'
# Notifications for the publisher and the health check.
# Get a token from @BotFather, then write to the bot once and read the chat id
# from https://api.telegram.org/bot<TOKEN>/getUpdates
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
EOF
    echo "    created $ENV_FILE — fill in the two values to switch notifications on"
else
    echo "    $ENV_FILE already exists, left alone"
fi
# The publisher and the health check send the messages, and they run as the
# deploy user: root-only credentials would disable notifications silently.
chown "root:$DEPLOY_USER" "$ENV_FILE"
chmod 640 "$ENV_FILE"

echo "==> systemd units"
for unit in aigf-deploy.service aigf-deploy.timer aigf-health.service aigf-health.timer; do
    install -o root -g root -m 644 "$REPO_DIR/infra/$unit" "/etc/systemd/system/$unit"
    echo "    $unit"
done

# The previous timer did the same job without convergence; two of them would
# fight over the same lock file.
#
# Checking the file rather than `systemctl list-unit-files | grep -q`: under
# `set -o pipefail` grep closes the pipe on its first match, systemctl dies of
# SIGPIPE, and the whole condition reads as false — so the old timer survived
# the first run of this installer.
if [ -f /etc/systemd/system/aigf-auto-deploy.timer ]; then
    systemctl disable --now aigf-auto-deploy.timer >/dev/null 2>&1 || true
    rm -f /etc/systemd/system/aigf-auto-deploy.timer /etc/systemd/system/aigf-auto-deploy.service
    echo "    removed the old aigf-auto-deploy timer"
fi

systemctl daemon-reload
systemctl enable --now aigf-deploy.timer aigf-health.timer
echo "    timers enabled"

echo "==> First convergence"
bash "$REPO_DIR/infra/converge.sh" || echo "    convergence reported problems, see $REPO_DIR/var/converge.log"

echo
echo "Done. From here on:"
echo "  systemctl list-timers 'aigf-*'                 когда сработает в следующий раз"
echo "  tail -f $REPO_DIR/var/auto-deploy.log          что выкладывается"
echo "  tail -f $REPO_DIR/var/converge.log             что чинилось на сервере"
echo "  bash $REPO_DIR/infra/converge.sh --check       что разошлось, ничего не меняя"
