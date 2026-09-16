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

ACTIVE_LOCK_HOST=""
ACTIVE_LOCK_PATH=""
ACTIVE_LOCK_TOKEN=""

release_lock() {
    if [ -n "$ACTIVE_LOCK_HOST" ]; then
        ssh_run "$ACTIVE_LOCK_HOST" "if [ \"\$(cat '$ACTIVE_LOCK_PATH/token' 2>/dev/null)\" = '$ACTIVE_LOCK_TOKEN' ]; then rm -f '$ACTIVE_LOCK_PATH/token'; rmdir '$ACTIVE_LOCK_PATH'; fi" \
            || printf '  [ERROR] Unable to release deployment lock on %s; inspect it before retrying.\n' "$ACTIVE_LOCK_HOST" >&2
        ACTIVE_LOCK_HOST=""
    fi
}

acquire_lock() {
    release_lock
    lock_host=$1
    lock_path="$2/.deployment-lock"
    if [ -n "${DEPLOY_LOCK_TOKEN:-}" ]; then
        # Nested automatic rollback runs under the parent's server lock.
        ssh_run "$lock_host" "test \"\$(cat '$lock_path/token' 2>/dev/null)\" = '$DEPLOY_LOCK_TOKEN'"
        return
    fi
    lock_token="$(date -u +%Y%m%d%H%M%S)-$$"
    if ssh_run "$lock_host" "mkdir -p '$2'; mkdir '$lock_path' && printf '%s' '$lock_token' > '$lock_path/token'"; then
        ACTIVE_LOCK_HOST=$lock_host
        ACTIVE_LOCK_PATH=$lock_path
        ACTIVE_LOCK_TOKEN=$lock_token
        return 0
    fi
    printf '  [ERROR] %s: deployment lock unavailable. Another publish may be running.\n' "$lock_host" >&2
    return 1
}

trap 'release_lock' 0
trap 'exit 130' 1 2 15

CODE_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
ROOT_DIR=${MINICMS_ROOT:-$CODE_ROOT}

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
    php "$CODE_ROOT/scripts/geo.php" host "$1"
}

geo_list() {
    php "$CODE_ROOT/scripts/geo.php" list
}

ssh_run() {
    _host=$1
    shift
    ssh -p "$DEPLOY_PORT" -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=10 -o ServerAliveCountMax=3 -o StrictHostKeyChecking=accept-new "$DEPLOY_USER@$_host" "$@"
}
