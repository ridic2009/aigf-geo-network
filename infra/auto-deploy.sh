#!/usr/bin/env bash
#
# Pull-based deployment: this is what publishes the network.
#
# Pages CMS commits to `main`; this runs on the VPS from a systemd timer and
# does the rest, without depending on GitHub Actions:
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

# Once the sites are published by ConvertStudio, this repository is the old
# content and must not be released over them: two publishers would take turns
# overwriting each other. The switch lives in /etc/aigf.env (PUBLISHER=convertstudio).
# The checkout still follows main, because convergence installs the server
# configuration from it; the health check keeps running too.
[ -r /etc/aigf.env ] && . /etc/aigf.env
if [ "${PUBLISHER:-network}" = "convertstudio" ]; then
    git fetch --quiet origin "$BRANCH" && git reset --hard --quiet "origin/$BRANCH"
    exit 0
fi

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
# Says something only when the answer changes: a failure that repeats every two
# minutes must not become thirty messages an hour.
notify() { bash "$REPO_DIR/infra/notify.sh" deploy "$1" "$2" || true; }

log "building"
if ! php scripts/build-all.php --optimize >>"$LOG" 2>&1; then
    log "BUILD FAILED — production untouched, still serving the previous release"
    notify fail "Сборка не прошла на коммите ${REMOTE:0:8}. Сайты продолжают отдавать прошлый релиз. Причина — в конце var/auto-deploy.log:
$(tail -5 "$LOG")"
    exit 1
fi

# The release dance is the same code used from a workstation; pointing it at
# localhost keeps one tested path instead of a second, subtly different one.
log "deploying"
if ! GIT_COMMIT="$REMOTE" DEPLOY_HOSTS=localhost sh scripts/deploy-all >>"$LOG" 2>&1; then
    log "DEPLOY FAILED — check the log above; failed sites were rolled back"
    notify fail "Выкладка коммита ${REMOTE:0:8} не удалась, релиз откатан. Причина — в конце var/auto-deploy.log:
$(tail -8 "$LOG")"
    exit 1
fi

log "deployed ${REMOTE:0:8}"
notify ok "Выложен коммит ${REMOTE:0:8}."
printf '%s\n' "$REMOTE" > "$LAST_SUCCESS_FILE.tmp"
mv "$LAST_SUCCESS_FILE.tmp" "$LAST_SUCCESS_FILE"
