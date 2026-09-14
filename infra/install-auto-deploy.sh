#!/usr/bin/env bash
#
# One-off setup of pull-based deployment on the VPS, for when GitHub Actions is
# not available. Run as root, after infra/server-setup.sh:
#
#     REPO_SSH_URL=git@github.com:owner/repo.git bash install-auto-deploy.sh
#
# Expects a read-only GitHub deploy key already present at
# /home/deploy/.ssh/repo_key (its public half registered in the repository's
# Deploy keys). See docs/deployment.md.
#
# What it installs:
#   - the PHP CLI toolchain needed to build (no PHP-FPM, nothing web-facing)
#   - a clone of the repository in /srv/aigf-network, owned by `deploy`
#   - an ssh key for deploy -> localhost, so the normal deploy scripts work here
#   - a systemd timer that checks the repository every two minutes

set -euo pipefail

DEPLOY_USER=${DEPLOY_USER:-deploy}
REPO_DIR=${REPO_DIR:-/srv/aigf-network}
BRANCH=${BRANCH:-main}
INTERVAL=${INTERVAL:-2min}
REPO_SSH_URL=${REPO_SSH_URL:-}

[ -n "$REPO_SSH_URL" ] || { echo "REPO_SSH_URL is required, e.g. git@github.com:owner/repo.git" >&2; exit 1; }
id -u "$DEPLOY_USER" >/dev/null 2>&1 || { echo "User $DEPLOY_USER does not exist — run infra/server-setup.sh first" >&2; exit 1; }

KEY="/home/$DEPLOY_USER/.ssh/repo_key"
[ -f "$KEY" ] || { echo "Missing $KEY (the repository deploy key)" >&2; exit 1; }
chown "$DEPLOY_USER:$DEPLOY_USER" "$KEY"
chmod 600 "$KEY"

echo "==> Build toolchain"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq git unzip \
    php-cli php-mbstring php-intl php-gd php-xml php-curl php-zip

if ! command -v composer >/dev/null 2>&1; then
    php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi
echo "    php $(php -r 'echo PHP_VERSION;'), composer $(composer --version --no-ansi | cut -d' ' -f3)"

echo "==> SSH for the deploy user"
sudo -u "$DEPLOY_USER" install -d -m 700 "/home/$DEPLOY_USER/.ssh"

# github.com host key, so the first clone is not interactive
sudo -u "$DEPLOY_USER" bash -c "ssh-keyscan -H github.com >> /home/$DEPLOY_USER/.ssh/known_hosts 2>/dev/null"

sudo -u "$DEPLOY_USER" bash -c "cat > /home/$DEPLOY_USER/.ssh/config" <<EOF
Host github.com
    IdentityFile ~/.ssh/repo_key
    IdentitiesOnly yes
EOF
chmod 600 "/home/$DEPLOY_USER/.ssh/config"

# The deploy scripts always go through ssh; pointing them at localhost keeps one
# tested code path instead of a second, subtly different local one.
if [ ! -f "/home/$DEPLOY_USER/.ssh/id_ed25519" ]; then
    sudo -u "$DEPLOY_USER" ssh-keygen -t ed25519 -N "" -C "aigf-localhost" -f "/home/$DEPLOY_USER/.ssh/id_ed25519" -q
fi
sudo -u "$DEPLOY_USER" bash -c "
    touch /home/$DEPLOY_USER/.ssh/authorized_keys
    grep -qF \"\$(cat /home/$DEPLOY_USER/.ssh/id_ed25519.pub)\" /home/$DEPLOY_USER/.ssh/authorized_keys \
        || cat /home/$DEPLOY_USER/.ssh/id_ed25519.pub >> /home/$DEPLOY_USER/.ssh/authorized_keys
    chmod 600 /home/$DEPLOY_USER/.ssh/authorized_keys
    ssh-keyscan -H localhost >> /home/$DEPLOY_USER/.ssh/known_hosts 2>/dev/null
"
sudo -u "$DEPLOY_USER" ssh -o BatchMode=yes -o StrictHostKeyChecking=no localhost true \
    && echo "    deploy@localhost ok" \
    || { echo "    deploy@localhost FAILED — sshd may forbid it; check /etc/ssh/sshd_config" >&2; exit 1; }

echo "==> Repository"
if [ ! -d "$REPO_DIR/.git" ]; then
    install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 755 "$REPO_DIR"
    sudo -u "$DEPLOY_USER" git clone --quiet --branch "$BRANCH" "$REPO_SSH_URL" "$REPO_DIR"
fi
sudo -u "$DEPLOY_USER" git -C "$REPO_DIR" config pull.ff only
sudo -u "$DEPLOY_USER" install -d -m 755 "$REPO_DIR/var"
sudo -u "$DEPLOY_USER" composer install -d "$REPO_DIR" --no-interaction --no-progress --prefer-dist --no-dev --quiet
chmod +x "$REPO_DIR/infra/auto-deploy.sh"
echo "    $REPO_DIR at $(sudo -u "$DEPLOY_USER" git -C "$REPO_DIR" rev-parse --short HEAD)"

echo "==> systemd timer"
cat >/etc/systemd/system/aigf-auto-deploy.service <<EOF
[Unit]
Description=AIGF network: pull, build and publish if the repository changed
After=network-online.target

[Service]
Type=oneshot
User=$DEPLOY_USER
WorkingDirectory=$REPO_DIR
Environment=REPO_DIR=$REPO_DIR
Environment=BRANCH=$BRANCH
ExecStart=$REPO_DIR/infra/auto-deploy.sh
TimeoutStartSec=900
EOF

cat >/etc/systemd/system/aigf-auto-deploy.timer <<EOF
[Unit]
Description=Check the AIGF repository every $INTERVAL

[Timer]
OnBootSec=2min
OnUnitActiveSec=$INTERVAL
AccuracySec=15s
Unit=aigf-auto-deploy.service

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now aigf-auto-deploy.timer

echo
echo "Done. The server now publishes itself whenever $BRANCH changes."
echo
echo "  status:   systemctl status aigf-auto-deploy.timer"
echo "  log:      tail -f $REPO_DIR/var/auto-deploy.log"
echo "  run now:  sudo -u $DEPLOY_USER $REPO_DIR/infra/auto-deploy.sh --force"
