#!/usr/bin/env bash
#
# Pull-based deployment. Runs on the VPS from a systemd timer and does what
# GitHub Actions would do, without depending on it:
#
#   git fetch -> is there anything new? -> validate -> build -> release switch
#
# Install it with infra/install-auto-deploy.sh. It is safe to run by hand:
#
#     sudo -u deploy /srv/aigf-network/infra/auto-deploy.sh
#     sudo -u deploy /srv/aigf-network/infra/auto-deploy.sh --force   # rebuild even if nothing changed
#
# Every guarantee of the normal pipeline still holds: validation failures and
# build failures abort before anything is published, the release switch is
# atomic, and a release that fails its smoke test is rolled back automatically.

set -euo pipefail

REPO_DIR=${REPO_DIR:-/srv/aigf-network}
BRANCH=${BRANCH:-main}
LOG=${LOG:-$REPO_DIR/var/auto-deploy.log}
LOCK=${LOCK:-$REPO_DIR/var/auto-deploy.lock}
FORCE=0

# Never reset a working Studio checkout or compete with its publication queue.
if [ "${PUBLISHER:-git}" = studio ] || [ -f "$REPO_DIR/.studio/state.json" ]; then
    echo 'Studio publishing is enabled; Git pull deployment is disabled.'
    exit 0
fi

for arg in "$@"; do
    case "$arg" in
        --force) FORCE=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

mkdir -p "$(dirname "$LOG")"

log() {
    printf '%s  %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" | tee -a "$LOG"
}

# Only one deployment at a time: a build takes seconds, the timer fires often.
exec 9>"$LOCK"
if ! flock -n 9; then
    echo "another deployment is running, skipping" >>"$LOG"
    exit 0
fi

cd "$REPO_DIR"

git fetch --quiet origin "$BRANCH"
LOCAL=$(git rev-parse HEAD)
LAST_SUCCESS_FILE="$REPO_DIR/var/last-successful-commit"
LAST_SUCCESS=$(cat "$LAST_SUCCESS_FILE" 2>/dev/null || true)
REMOTE=$(git rev-parse "origin/$BRANCH")

if [ "$LAST_SUCCESS" = "$REMOTE" ] && [ "$FORCE" -eq 0 ]; then
    exit 0   # nothing new — stay quiet, the timer runs often
fi

log "----------------------------------------------------------------"
log "new commit: ${LOCAL:0:8} -> ${REMOTE:0:8}"
git log --oneline "${LOCAL}..${REMOTE}" 2>/dev/null | head -5 | sed 's/^/                      /' | tee -a "$LOG" || true

git reset --hard --quiet "origin/$BRANCH"

if [ "$LAST_SUCCESS" != "$REMOTE" ] || ! git diff --quiet "${LOCAL}" HEAD -- composer.json composer.lock 2>/dev/null; then
    log "dependencies changed, running composer install"
    composer install --no-interaction --no-progress --prefer-dist --no-dev --quiet
fi

# build-all runs data, CMS-schema and content validation first, and verifies the
# generated HTML afterwards. A non-zero exit means nothing gets published.
log "building"
if ! php scripts/build-all.php --optimize >>"$LOG" 2>&1; then
    log "BUILD FAILED — production untouched, still serving the previous release"
    exit 1
fi

# The release dance is the same code used from a workstation; pointing it at
# localhost keeps one tested path instead of a second, subtly different one.
log "deploying"
if ! GIT_COMMIT="$REMOTE" DEPLOY_HOSTS=localhost sh scripts/deploy-all >>"$LOG" 2>&1; then
    log "DEPLOY FAILED — check the log above; failed sites were rolled back"
    exit 1
fi

log "deployed ${REMOTE:0:8}"
printf '%s\n' "$REMOTE" > "$LAST_SUCCESS_FILE.tmp"
mv "$LAST_SUCCESS_FILE.tmp" "$LAST_SUCCESS_FILE"
