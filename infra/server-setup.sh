#!/usr/bin/env bash
#
# One-off provisioning for the production VPS (Ubuntu 22.04/24.04).
# Run as root on a fresh server:
#
#     scp infra/server-setup.sh root@VPS:/tmp/ && ssh root@VPS "bash /tmp/server-setup.sh"
#
# What it does NOT install: PHP, Node, a database. Production only serves static
# files; everything is built in CI.

set -euo pipefail

DEPLOY_USER=${DEPLOY_USER:-deploy}
WEB_ROOT=${WEB_ROOT:-/srv/www}

echo "==> Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx rsync certbot python3 python3-certbot-nginx ufw fail2ban

echo "==> Deploy user"
if ! id -u "$DEPLOY_USER" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "" "$DEPLOY_USER"
fi
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 700 "/home/$DEPLOY_USER/.ssh"
touch "/home/$DEPLOY_USER/.ssh/authorized_keys"
chown "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh/authorized_keys"
chmod 600 "/home/$DEPLOY_USER/.ssh/authorized_keys"
echo "    Add the CI public key to /home/$DEPLOY_USER/.ssh/authorized_keys"

echo "==> Web root"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 755 "$WEB_ROOT"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 755 /var/www/certbot

echo "==> Nginx"
install -d -m 755 /etc/nginx/snippets
# nginx.conf gets includes for these below. Create placeholders first: an
# include pointing at a missing file makes every later `nginx -t` fail, which
# would leave the server unable to reload until the real files are copied.
for snippet in compression cloudflare-real-ip security-headers static-cache; do
    target="/etc/nginx/snippets/aigf-$snippet.conf"
    [ -f "$target" ] || echo "# placeholder, replaced by scripts/nginx-config output" > "$target"
done
# The affiliate map is only present once scripts/redirect-config has run.
[ -f /etc/nginx/snippets/aigf-affiliate-map.conf ] || echo 'map $uri $aigf_affiliate { default ""; }' > /etc/nginx/snippets/aigf-affiliate-map.conf
# The deploy user only needs to reload nginx, nothing else.
cat >/etc/sudoers.d/aigf-deploy <<EOF
$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload nginx, /usr/sbin/nginx -t, /usr/local/sbin/minicms-redirects *
EOF
chmod 440 /etc/sudoers.d/aigf-deploy
HELPER_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
if [ -f "$HELPER_DIR/apply-redirects.py" ]; then
    install -o root -g root -m 755 "$HELPER_DIR/apply-redirects.py" /usr/local/sbin/minicms-redirects
else
    echo "    REQUIRED before deploying: install infra/apply-redirects.py as /usr/local/sbin/minicms-redirects (root:root, 0755)"
fi
python3 - "$WEB_ROOT" <<'PY'
import json, sys
from pathlib import Path
Path('/etc/minicms-deploy.json').write_text(json.dumps({'web_root': sys.argv[1]}))
PY
chmod 644 /etc/minicms-deploy.json

# The distribution ships `gzip on;` in nginx.conf; our snippet sets the whole
# gzip block, and nginx rejects a duplicate directive.
cp -n /etc/nginx/nginx.conf /etc/nginx/nginx.conf.aigf-backup 2>/dev/null || true
sed -i 's|^[[:space:]]*gzip on;|	# gzip on;  # managed by snippets/aigf-compression.conf|' /etc/nginx/nginx.conf

if ! grep -q "aigf-compression" /etc/nginx/nginx.conf; then
    sed -i 's|include /etc/nginx/conf.d/\*.conf;|include /etc/nginx/snippets/aigf-compression.conf;\n\tinclude /etc/nginx/snippets/aigf-cloudflare-real-ip.conf;\n\tinclude /etc/nginx/snippets/aigf-affiliate-map.conf;\n\tinclude /etc/nginx/conf.d/*.conf;|' /etc/nginx/nginx.conf
fi
# Long domain names need a bigger hash bucket once the network grows.
if ! grep -q "server_names_hash_bucket_size" /etc/nginx/nginx.conf; then
    sed -i 's|http {|http {\n\tserver_names_hash_bucket_size 128;|' /etc/nginx/nginx.conf
fi
rm -f /etc/nginx/sites-enabled/default

echo "==> Nginx configuration check"
nginx -t && systemctl reload nginx

echo "==> Firewall"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

echo "==> Certificate renewal"
systemctl enable --now certbot.timer || true

echo
echo "Done. Next:"
echo "  1. Add the CI deploy key to /home/$DEPLOY_USER/.ssh/authorized_keys"
echo "  2. Locally: ./scripts/nginx-config && copy infra/nginx/ to the server (see docs/deployment.md)"
echo "  3. Request certificates:  certbot certonly --webroot -w /var/www/certbot -d DOMAIN -d www.DOMAIN"
echo "  4. nginx -t && systemctl reload nginx"
