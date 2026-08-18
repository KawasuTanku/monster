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
#   1. git pull (or git clone) the repo into /opt/caddy/monster.kawasu.wtf
#   2. install composer deps (vendor/)
#   3. ensure the data/ dir exists and is writable by frankenphp
#   4. set ownership so FrankenPHP can read/write the app + its data
#
set -euo pipefail

REMOTE="${MONSTER_REMOTE:-git@github.com:KawasuTanku/monster.git}"
DEST="/opt/caddy/monster.kawasu.wtf"
OWNER="frankenphp:frankenphp"
BRANCH="${MONSTER_BRANCH:-main}"

# Run the heavy lifting as the frankenphp user when we are root.
run_as() { if [[ "$(id -u)" -eq 0 ]]; then sudo -u frankenphp bash -c "$1"; else bash -c "$1"; fi; }

echo "==> Deploying monster to $DEST"

if [[ ! -d "$DEST/.git" ]]; then
    echo "==> Cloning repo (first deploy)"
    run_as "git clone --branch '$BRANCH' '$REMOTE' '$DEST'"
else
    echo "==> Pulling latest"
    run_as "cd '$DEST' && git pull --ff-only"
fi

echo "==> Installing composer dependencies"
run_as "cd '$DEST' && php ${COMPOSER:-composer} install --no-dev --no-interaction --optimize-autoloader"

echo "==> Preparing data directory"
run_as "mkdir -p '$DEST/data' && chmod 750 '$DEST/data'"

echo "==> Fixing ownership"
chown -R "$OWNER" "$DEST"

echo "==> Done. Visit https://monster.kawasu.wtf and complete first-run setup."
