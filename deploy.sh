#!/usr/bin/env bash
#
# deploy.sh — pull the latest Monster app into the Caddy doc root.
#
# The web server (FrankenPHP) runs as user `frankenphp` and serves:
#     /opt/caddy/monster.kawasu.wtf/web
# That directory is owned by `frankenphp`, not your deploy user, so this script
# writes files *as* that user. Run as root or via sudo with NOPASSWD for the
# frankenphp user (or just `sudo ./deploy.sh`).
#
# What it does:
#   1. get the repo into DEST — handles three cases without failing if the
#      path already exists:
#        - no DEST dir yet          -> git clone
#        - DEST/.git already there   -> git pull --ff-only
#        - DEST exists but no .git   -> git init + remote + force-checkout
#          (untracked dirs like logs/ are left alone)
#   2. install composer deps (vendor/)
#   3. ensure the data/ dir exists and is writable by frankenphp
#   4. set ownership so FrankenPHP can read/write the app + its data
#
# Env overrides (handy for testing):
#   MONSTER_DEST   default /opt/caddy/monster.kawasu.wtf
#   MONSTER_REMOTE default https://github.com/KawasuTanku/monster.git
#   MONSTER_BRANCH default main
#   COMPOSER       default: COMPOSER env, then /opt/caddy/bin/composer.phar, then `composer` on PATH
#

# Resolve the composer executable. Honors an explicit $COMPOSER, then falls back
# to /opt/caddy/bin/composer.phar (deployed alongside the app), then `composer`.
if [[ -n "${COMPOSER:-}" ]]; then
    COMPOSER_BIN="$COMPOSER"
elif [[ -x /opt/caddy/bin/composer.phar ]]; then
    COMPOSER_BIN="/opt/caddy/bin/composer.phar"
else
    COMPOSER_BIN="composer"
fi

REMOTE="${MONSTER_REMOTE:-https://github.com/KawasuTanku/monster.git}"
DEST="${MONSTER_DEST:-/opt/caddy/monster.kawasu.wtf}"
OWNER="frankenphp:frankenphp"
BRANCH="${MONSTER_BRANCH:-main}"

# Run the heavy lifting as the frankenphp user when we are root.
run_as() { if [[ "$(id -u)" -eq 0 ]]; then sudo -u frankenphp bash -c "$1"; else bash -c "$1"; fi; }

echo "==> Deploying monster to $DEST"

if [[ -d "$DEST/.git" ]]; then
    echo "==> Existing repo found — force-syncing to $REMOTE ($BRANCH)"
    echo "    (tracked files are reset to match origin; untracked dirs like logs/ are kept)"
    run_as "cd '$DEST' && (git remote set-url origin '$REMOTE' 2>/dev/null || git remote add origin '$REMOTE') && git fetch -q origin '$BRANCH' && git checkout -q -f -B '$BRANCH' origin/'$BRANCH'"
elif [[ -d "$DEST" ]]; then
    echo "==> Path exists without a repo — initialising git and checking out $BRANCH"
    echo "    (existing untracked files such as logs/ are preserved)"
    run_as "cd '$DEST' && git init -q && git remote add -f origin '$REMOTE' && git fetch -q origin '$BRANCH' && git checkout -q -f -B '$BRANCH' origin/'$BRANCH'"
else
    echo "==> Fresh deploy — cloning repo"
    run_as "git clone --branch '$BRANCH' '$REMOTE' '$DEST'"
fi

echo "==> Installing composer dependencies"
run_as "cd '$DEST' && '$COMPOSER_BIN' install --no-dev --no-interaction --optimize-autoloader"

echo "==> Preparing data directory"
run_as "mkdir -p '$DEST/data' && chmod 750 '$DEST/data'"

echo "==> Fixing ownership"
chown -R "$OWNER" "$DEST"

# Restart FrankenPHP so it picks up the freshly-synced PHP (opcache/worker
# otherwise keeps serving stale bytecode for edited files, which makes pages
# that call newly-added helpers fatal and render un-themed).
if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files frankenphp.service >/dev/null 2>&1; then
    echo "==> Restarting frankenphp to clear opcode cache"
    systemctl restart frankenphp.service || echo "  (warn: frankenphp restart failed; restart manually)"
fi

echo "==> Done. Visit https://monster.kawasu.wtf/setup and complete first-run setup."
