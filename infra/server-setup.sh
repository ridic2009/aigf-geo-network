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
apt-get install -y -qq nginx rsync certbot python3-certbot-nginx ufw fail2ban

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
# The affiliate map is only present once scripts/redirect-config has run;
# create an empty one so the include in nginx.conf never breaks a reload.
[ -f /etc/nginx/snippets/aigf-affiliate-map.conf ] || echo 'map $uri $aigf_affiliate { default ""; }' > /etc/nginx/snippets/aigf-affiliate-map.conf
# The deploy user only needs to reload nginx, nothing else.
cat >/etc/sudoers.d/aigf-deploy <<EOF
$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload nginx, /usr/sbin/nginx -t
EOF
chmod 440 /etc/sudoers.d/aigf-deploy

if ! grep -q "aigf-compression" /etc/nginx/nginx.conf; then
    sed -i 's|include /etc/nginx/conf.d/\*.conf;|include /etc/nginx/snippets/aigf-compression.conf;\n\tinclude /etc/nginx/snippets/aigf-cloudflare-real-ip.conf;\n\tinclude /etc/nginx/snippets/aigf-affiliate-map.conf;\n\tinclude /etc/nginx/conf.d/*.conf;|' /etc/nginx/nginx.conf
fi
# Long domain names need a bigger hash bucket once the network grows.
if ! grep -q "server_names_hash_bucket_size" /etc/nginx/nginx.conf; then
    sed -i 's|http {|http {\n\tserver_names_hash_bucket_size 128;|' /etc/nginx/nginx.conf
fi
rm -f /etc/nginx/sites-enabled/default

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
