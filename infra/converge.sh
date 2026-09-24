#!/usr/bin/env bash
#
# Brings the server in line with the repository. Runs as root from the deploy
# timer, before every publication, and is safe to run by hand at any time.
#
#     sudo /srv/aigf-network/infra/converge.sh          # fix what drifted
#     sudo /srv/aigf-network/infra/converge.sh --check  # report only, change nothing
#
# Why this exists: every outage in this network so far was drift, not a bug.
# A vhost that was never replaced after a domain move, a helper the older
# provisioning script did not install, a sudoers rule missing so every release
# failed to activate, a releases.log left owned by root by one manual deploy,
# /tmp losing its sticky bit so tmpfile() started returning false. None of it
# was visible until a site answered 404. All of it is written down in this
# repository, so the server can simply be made to match — every two minutes,
# by itself, instead of by someone remembering a command.
#
# Everything here is idempotent and narrow: it installs what the repository
# says, never deletes a vhost it does not know about, and touches nginx only
# after `nginx -t` accepts the result.

set -uo pipefail

REPO_DIR=${REPO_DIR:-/srv/aigf-network}
WEB_ROOT=${WEB_ROOT:-/srv/www}
DEPLOY_USER=${DEPLOY_USER:-deploy}
LOG=${LOG:-$REPO_DIR/var/converge.log}
CHECK_ONLY=0
CHANGED=0
PROBLEMS=()

[ "${1:-}" = '--check' ] && CHECK_ONLY=1

mkdir -p "$(dirname "$LOG")"

say() { printf '%s  %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" | tee -a "$LOG"; }
changed() { CHANGED=1; say "  fixed: $1"; }
problem() { PROBLEMS+=("$1"); say "  PROBLEM: $1"; }

if [ "$(id -u)" -ne 0 ]; then
    echo "converge.sh must run as root" >&2
    exit 2
fi

# --- 1. /tmp -----------------------------------------------------------------
# A /tmp that is not world-writable breaks tmpfile(), and with it every build.
TMP_MODE=$(stat -c '%a' /tmp 2>/dev/null || echo '')
TMP_OWNER=$(stat -c '%U' /tmp 2>/dev/null || echo '')
if [ "$TMP_MODE" != '1777' ] || [ "$TMP_OWNER" != 'root' ]; then
    if [ "$CHECK_ONLY" -eq 1 ]; then
        problem "/tmp is $TMP_OWNER $TMP_MODE, expected root 1777"
    else
        chown root:root /tmp && chmod 1777 /tmp && changed "/tmp -> root:root 1777 (was $TMP_OWNER $TMP_MODE)"
    fi
fi

# --- 1b. notification state --------------------------------------------------
# notify.sh keeps the last state per check in var/state. On a fresh server this
# script is the first to call it, as root, so the directory came out root-owned
# and the health check (run as the deploy user) failed on every run.
STATE_DIR="$REPO_DIR/var/state"
STATE_OWNER=$(stat -c '%U' "$STATE_DIR" 2>/dev/null || echo '')
if [ "$STATE_OWNER" != "$DEPLOY_USER" ]; then
    if [ "$CHECK_ONLY" -eq 1 ]; then
        problem "$STATE_DIR is owned by '${STATE_OWNER:-nobody}', expected $DEPLOY_USER"
    else
        install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 755 "$STATE_DIR" \
            && chown -R "$DEPLOY_USER:$DEPLOY_USER" "$STATE_DIR" \
            && changed "$STATE_DIR -> $DEPLOY_USER (was ${STATE_OWNER:-missing})"
    fi
fi

# --- 2. the redirect helper --------------------------------------------------
HELPER_SRC="$REPO_DIR/infra/apply-redirects.py"
HELPER_DST=/usr/local/sbin/minicms-redirects
if [ -f "$HELPER_SRC" ] && ! cmp -s "$HELPER_SRC" "$HELPER_DST"; then
    if [ "$CHECK_ONLY" -eq 1 ]; then
        problem "$HELPER_DST is missing or out of date"
    else
        install -o root -g root -m 755 "$HELPER_SRC" "$HELPER_DST" && changed "$HELPER_DST installed"
    fi
fi

# --- 3. sudoers --------------------------------------------------------------
# The deploy user may reload nginx and regenerate the redirect map, nothing else.
SUDOERS=/etc/sudoers.d/aigf-deploy
SUDOERS_LINE="$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload nginx, /usr/sbin/nginx -t, $HELPER_DST *"
if [ "$(cat "$SUDOERS" 2>/dev/null)" != "$SUDOERS_LINE" ]; then
    if [ "$CHECK_ONLY" -eq 1 ]; then
        problem "$SUDOERS does not grant the deploy user what a release needs"
    else
        TMP_SUDOERS=$(mktemp)
        printf '%s\n' "$SUDOERS_LINE" > "$TMP_SUDOERS"
        # Never install a sudoers file that visudo rejects: a broken one can
        # lock every sudo on the machine.
        if visudo -c -f "$TMP_SUDOERS" >/dev/null 2>&1; then
            install -o root -g root -m 440 "$TMP_SUDOERS" "$SUDOERS" && changed "$SUDOERS updated"
        else
            problem "generated sudoers file was rejected by visudo, left untouched"
        fi
        rm -f "$TMP_SUDOERS"
    fi
fi

# --- 4. nginx snippets and vhosts -------------------------------------------
NGINX_CHANGED=0
BACKUP_DIR=$(mktemp -d)

for src in "$REPO_DIR"/infra/nginx/snippets/*.conf; do
    [ -f "$src" ] || continue
    dst="/etc/nginx/snippets/aigf-$(basename "$src")"
    cmp -s "$src" "$dst" && continue
    if [ "$CHECK_ONLY" -eq 1 ]; then problem "$dst differs from the repository"; continue; fi
    [ -f "$dst" ] && cp -a "$dst" "$BACKUP_DIR/"
    install -o root -g root -m 644 "$src" "$dst" && NGINX_CHANGED=1 && changed "$dst"
done

for src in "$REPO_DIR"/infra/nginx/sites/*.conf; do
    [ -f "$src" ] || continue
    name=$(basename "$src")
    dst="/etc/nginx/sites-available/$name"
    if ! cmp -s "$src" "$dst"; then
        if [ "$CHECK_ONLY" -eq 1 ]; then problem "$dst differs from the repository"; continue; fi
        [ -f "$dst" ] && cp -a "$dst" "$BACKUP_DIR/"
        install -o root -g root -m 644 "$src" "$dst" && NGINX_CHANGED=1 && changed "$dst"
    fi
    # A vhost the repository knows about is a vhost that should be serving.
    if [ ! -L "/etc/nginx/sites-enabled/$name" ]; then
        if [ "$CHECK_ONLY" -eq 1 ]; then problem "$name is not enabled"; continue; fi
        ln -sfn "$dst" "/etc/nginx/sites-enabled/$name" && NGINX_CHANGED=1 && changed "$name enabled"
    fi
done

if [ "$NGINX_CHANGED" -eq 1 ]; then
    if nginx -t >/dev/null 2>&1; then
        systemctl reload nginx && say "  nginx reloaded"
    else
        # Put back exactly what was there and keep the running config alive.
        for f in "$BACKUP_DIR"/*; do
            [ -f "$f" ] || continue
            case "$(basename "$f")" in
                aigf-*) cp -a "$f" "/etc/nginx/snippets/$(basename "$f")" ;;
                *)      cp -a "$f" "/etc/nginx/sites-available/$(basename "$f")" ;;
            esac
        done
        problem "nginx rejected the configuration from the repository; previous files restored"
        nginx -t 2>&1 | tail -3 | tee -a "$LOG"
    fi
fi
rm -rf "$BACKUP_DIR"

# --- 5. the automation itself ------------------------------------------------
# The units are part of what the repository says the server should be, so a
# change to them is a commit like any other rather than another root command.
UNITS_CHANGED=0
for src in "$REPO_DIR"/infra/aigf-*.service "$REPO_DIR"/infra/aigf-*.timer; do
    [ -f "$src" ] || continue
    dst="/etc/systemd/system/$(basename "$src")"
    cmp -s "$src" "$dst" && continue
    if [ "$CHECK_ONLY" -eq 1 ]; then problem "$dst differs from the repository"; continue; fi
    if install -o root -g root -m 644 "$src" "$dst"; then
        UNITS_CHANGED=1
        changed "$dst"
    fi
done
if [ "$UNITS_CHANGED" -eq 1 ]; then
    systemctl daemon-reload && say "  systemd reloaded"
fi

# --- 6. what a release writes to --------------------------------------------
# One manual deploy as root leaves releases.log owned by root, and every later
# release fails at the last step — after the symlink has already moved.
# The site directories belong to whoever publishes: the deploy user, or the
# ConvertStudio service once /etc/aigf.env says PUBLISHER=convertstudio. A
# domain that is a link to a ConvertStudio site directory is left alone.
PUBLISHER=$(sed -n 's/^PUBLISHER=//p' /etc/aigf.env 2>/dev/null | tail -1 | tr -d "\"' ")
PUBLISH_USER=$DEPLOY_USER
[ "$PUBLISHER" = convertstudio ] && PUBLISH_USER=convertstudio
for dir in "$WEB_ROOT"/*/; do
    [ -d "$dir" ] || continue
    [ -L "${dir%/}" ] && continue
    for path in "$dir" "$dir/releases.log"; do
        [ -e "$path" ] || continue
        [ "$(stat -c '%U' "$path")" = "$PUBLISH_USER" ] && continue
        if [ "$CHECK_ONLY" -eq 1 ]; then
            problem "$path is owned by $(stat -c '%U' "$path"), not $PUBLISH_USER"
        else
            chown "$PUBLISH_USER:$PUBLISH_USER" "$path" && changed "$path -> $PUBLISH_USER"
        fi
    done
done

# --- 6b. redirects of the live releases --------------------------------------
# A publisher that is not the deploy scripts (ConvertStudio) has no root to
# apply its release's redirects.json. The root-owned helper validates the map
# and reloads nginx only when the rules change, so running it every time is cheap.
if [ "$CHECK_ONLY" -eq 0 ] && [ -x /usr/local/sbin/minicms-redirects ]; then
    for site in "$WEB_ROOT"/*/; do
        domain=$(basename "$site")
        case "$domain" in *.*) ;; *) continue ;; esac
        [ -e "$site/current/redirects.json" ] || continue
        /usr/local/sbin/minicms-redirects "$domain" >/dev/null 2>&1 \
            || problem "redirects of $domain were rejected; the previous rules stay"
    done
fi

# --- 7. the clone itself -----------------------------------------------------
if [ -d "$REPO_DIR/.git" ] && [ "$(stat -c '%U' "$REPO_DIR/.git")" != "$DEPLOY_USER" ]; then
    if [ "$CHECK_ONLY" -eq 1 ]; then
        problem "$REPO_DIR is not owned by $DEPLOY_USER; the publisher cannot update it"
    else
        chown -R "$DEPLOY_USER:$DEPLOY_USER" "$REPO_DIR" && changed "$REPO_DIR -> $DEPLOY_USER"
    fi
fi

# --- report ------------------------------------------------------------------
if [ ${#PROBLEMS[@]} -gt 0 ]; then
    say "converge: ${#PROBLEMS[@]} problem(s) left"
    [ "$CHECK_ONLY" -eq 1 ] || bash "$REPO_DIR/infra/notify.sh" converge fail "$(printf '%s; ' "${PROBLEMS[@]}")"
    exit 1
fi

if [ "$CHANGED" -eq 1 ]; then
    say "converge: server brought back in line with the repository"
    bash "$REPO_DIR/infra/notify.sh" converge ok "Сервер приведён в соответствие с репозиторием, подробности в var/converge.log"
else
    bash "$REPO_DIR/infra/notify.sh" converge ok "" >/dev/null 2>&1
fi
exit 0
