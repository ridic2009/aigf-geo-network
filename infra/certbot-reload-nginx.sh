#!/bin/sh
#
# Installed by converge.sh as /etc/letsencrypt/renewal-hooks/deploy/aigf-reload-nginx;
# certbot runs it once after it has renewed any certificate on the server.
#
# The certificates are renewed with the webroot method, which never touches
# nginx: without a reload the new certificate sits on disk while nginx keeps
# serving the old one until it expires.

nginx -t -q && systemctl reload nginx
