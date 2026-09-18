#!/usr/bin/env bash
#
# Checks every live site the way a visitor would, and says something only when
# the answer changes. Runs from aigf-health.timer.
#
#     /srv/aigf-network/infra/healthcheck.sh
#
# This is the same scripts/smoke.php that guards a release switch — homepage,
# canonical, sitemap, robots, a real page — pointed at the public domain instead
# of at a fresh release. A deploy can succeed and the site still be wrong an
# hour later: an expired certificate, a DNS change, a full disk. Nobody watches
# a log, so the check has to speak up.

set -uo pipefail

REPO_DIR=${REPO_DIR:-/srv/aigf-network}
LOG=${LOG:-$REPO_DIR/var/health.log}

mkdir -p "$(dirname "$LOG")"
cd "$REPO_DIR" || exit 2

say() { printf '%s  %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" >> "$LOG"; }

GEOS=$(php scripts/geo.php list 2>/dev/null)
[ -n "$GEOS" ] || { say "no enabled GEOs"; exit 0; }

FAILED=""
for geo in $GEOS; do
    if OUTPUT=$(php scripts/smoke.php "$geo" --quiet 2>&1); then
        say "$geo ok"
        bash "$REPO_DIR/infra/notify.sh" "health:$geo" ok "Сайт снова отвечает нормально."
    else
        FAILED="$FAILED $geo"
        say "$geo FAILED: $(printf '%s' "$OUTPUT" | tail -3 | tr '\n' ' ')"
        bash "$REPO_DIR/infra/notify.sh" "health:$geo" fail "$(printf '%s' "$OUTPUT" | tail -5)"
    fi
done

[ -z "$FAILED" ] || exit 1
exit 0
