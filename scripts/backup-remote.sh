#!/usr/bin/env bash
#
# backup-remote.sh — push Monster's local JSON snapshots to a remote server.
#
# Strategy: the app already writes portable JSON dumps to data/backups/
# (daily-*.json + manual monster-*.json, see src/Backup.php). This script:
#   1. forces a fresh dump (as the app user, so it can read data/),
#   2. rsyncs the whole backups/ dir to the remote over SSH (incremental,
#      encrypted, resumable),
#   3. prunes remote snapshots older than REMOTE_KEEP days.
#
# Run it from cron as the app user, e.g. (daily at 03:07):
#   7 3 * * * frankenphp /opt/caddy/monster.kawasu.wtf/scripts/backup-remote.sh >> /var/log/monster-backup.log 2>&1
#
# NOTE: this script must run as the app user (frankenphp) so it can read
# data/ and use that user's SSH key. Set up passwordless SSH from frankenphp
# to REMOTE_USER@REMOTE_HOST first (ssh-keygen + install pub key).

set -euo pipefail

# ---- CONFIG (edit these) ---------------------------------------------------
DEPLOY_DIR="/opt/caddy/monster.kawasu.wtf"   # project root (parent of web/)
APP_USER="frankenphp"                         # user that owns data/
REMOTE_USER="sysbackup"                       # SSH user on the other server
REMOTE_HOST="zen.kawasu.wtf"                   # hostname / IP / ssh-config alias
REMOTE_DIR="/home/sysbackup/backups/monster"   # target dir on the remote
REMOTE_KEEP_DAYS=30                          # prune remote snapshots older than this
# ---------------------------------------------------------------------------

BACKUP_DIR="$DEPLOY_DIR/data/backups"
SSH_DEST="$REMOTE_USER@$REMOTE_HOST"
LOG_TS="$(date '+%Y-%m-%d %H:%M:%S')"

log() { echo "[$LOG_TS] $*"; }

if [ ! -d "$BACKUP_DIR" ]; then
    log "ERROR: backup dir not found: $BACKUP_DIR"
    exit 1
fi

# Run a command as the app user only if we are not already that user
# (cron invokes this script directly as APP_USER, so no sudo is needed there).
as_app() {
    if [ "$(id -un)" = "$APP_USER" ]; then
        "$@"
    else
        sudo -u "$APP_USER" "$@"
    fi
}

# 1. Force a fresh local snapshot (as the app user).
log "Creating local snapshot..."
as_app php "$DEPLOY_DIR/bin/backup.php" remote-cron \
    || { log "ERROR: local snapshot failed"; exit 1; }

# 2. Push the whole backups dir to the remote (incremental, perms preserved).
log "Rsyncing to $SSH_DEST:$REMOTE_DIR ..."
rsync -az --delete \
    -e "ssh -o StrictHostKeyChecking=accept-new" \
    "$BACKUP_DIR/" "$SSH_DEST:$REMOTE_DIR/" \
    || { log "ERROR: rsync failed"; exit 1; }

# 3. Prune old remote snapshots (both daily-*.json and monster-*.json).
log "Pruning remote snapshots older than $REMOTE_KEEP_DAYS days..."
ssh "$SSH_DEST" \
    "find '$REMOTE_DIR' -type f \( -name 'daily-*.json' -o -name 'monster-*.json' \) -mtime +$REMOTE_KEEP_DAYS -delete" \
    || log "WARN: remote prune failed (non-fatal)"

log "Done. Latest local: $(as_app ls -t "$BACKUP_DIR"/*.json 2>/dev/null | head -1)"
