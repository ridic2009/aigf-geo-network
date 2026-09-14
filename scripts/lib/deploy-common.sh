#!/usr/bin/env sh
# Shared helpers for deploy / deploy-all / rollback.
#
# Connection settings come from the environment (GitHub Secrets in CI,
# a local .env when deploying by hand):
#
#   DEPLOY_HOSTS  one or more VPS hosts, comma or space separated  (required)
#                 e.g. "203.0.113.10 198.51.100.7"
#   DEPLOY_HOST   alias for a single host (kept for convenience)
#   DEPLOY_USER   SSH user                      (default: deploy)
#   DEPLOY_PORT   SSH port                      (default: 22)
#   DEPLOY_ROOT   Web root on the server        (default: /srv/www)
#   KEEP_RELEASES Releases to keep per site     (default: 5)
#
# Every host receives the same release, with the same release id, so the servers
# stay byte-identical and a rollback means the same thing everywhere.

set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

DEPLOY_USER=${DEPLOY_USER:-deploy}
DEPLOY_PORT=${DEPLOY_PORT:-22}
DEPLOY_ROOT=${DEPLOY_ROOT:-/srv/www}
KEEP_RELEASES=${KEEP_RELEASES:-5}

if [ -f "$ROOT_DIR/.env" ]; then
    # shellcheck disable=SC1091
    . "$ROOT_DIR/.env"
fi

# DEPLOY_HOST stays valid as a single-host shorthand.
DEPLOY_HOSTS=${DEPLOY_HOSTS:-${DEPLOY_HOST:-}}

die() {
    printf '  [ERROR] %s\n' "$1" >&2
    exit 1
}

info() {
    printf '  %s\n' "$1"
}

ok() {
    printf '  [OK] %s\n' "$1"
}

upper() {
    printf '%s' "$1" | tr '[:lower:]' '[:upper:]'
}

require_hosts() {
    [ -n "$DEPLOY_HOSTS" ] || die "DEPLOY_HOSTS is not set (export it or put it in .env)."
}

# All target servers, whitespace separated.
host_list() {
    printf '%s' "$DEPLOY_HOSTS" | tr ',' ' '
}

host_count() {
    host_list | wc -w | tr -d ' '
}

geo_host() {
    php "$ROOT_DIR/scripts/geo.php" host "$1"
}

geo_list() {
    php "$ROOT_DIR/scripts/geo.php" list
}

ssh_run() {
    _host=$1
    shift
    ssh -p "$DEPLOY_PORT" -o StrictHostKeyChecking=accept-new "$DEPLOY_USER@$_host" "$@"
}
