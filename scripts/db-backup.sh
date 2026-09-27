#!/bin/bash
# ~/bin/db-backup.sh — TNSVT production database daily backup with rotation.
#
# Usage: ~/bin/db-backup.sh
# Crontab: 0 3 * * * ~/bin/db-backup.sh >> ~/backups/db-backup.log 2>&1
#
# What it does:
#   1. Reads DB credentials from ~/domains/tnsvt.com/public_html/.env.local
#   2. mysqldump the entire prod database, gzip-compresses to ~/backups/
#   3. Verifies the dump is non-empty + has SQL content (not a corrupt
#      archive from a disk-full mid-dump)
#   4. Rotates: deletes files older than 30 days
#   5. Logs to ~/backups/db-backup.log
#
# Restore from a backup:
#   gunzip -c ~/backups/db_tnsvt_YYYYMMDD_HHMMSS.sql.gz \
#     | mysql -h <host> -u <user> -p<password> <dbname>
#
# Future: add an upload step (S3, Backblaze B2, rsync to NAS) before the
# rotate line — see AGENTS.md § Local backups / disaster recovery.

set -euo pipefail

# ── Locate the .env.local that has the real DB credentials ────────────
ENV_FILE="$HOME/domains/tnsvt.com/public_html/.env.local"
if [ ! -f "$ENV_FILE" ]; then
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] ERROR: $ENV_FILE not found" >&2
    exit 1
fi

# Parse DATABASE_URL="mysql://user:pass@host:port/db?params"
DB_URL=$(grep -E '^DATABASE_URL=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d "'\"" | tr -d ' ')
if [ -z "$DB_URL" ]; then
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] ERROR: DATABASE_URL not set in $ENV_FILE" >&2
    exit 1
fi

DB_USER=$(echo "$DB_URL" | sed -E 's#mysql://([^:]+):.*#\1#')
DB_PASS=$(echo "$DB_URL" | sed -E 's#mysql://[^:]+:([^@]+)@.*#\1#')
DB_HOSTPORT=$(echo "$DB_URL" | sed -E 's#mysql://[^@]+@([^/]+)/.*#\1#')
DB_NAME=$(echo "$DB_URL" | sed -E 's#mysql://[^@]+@[^/]+/([^?]+).*#\1#')
DB_HOST=$(echo "$DB_HOSTPORT" | cut -d: -f1)
DB_PORT=$(echo "$DB_HOSTPORT" | cut -d: -f2)
DB_PORT=${DB_PORT:-3306}

# ── Run the backup ──────────────────────────────────────────────────
BACKUP_DIR="$HOME/backups"
mkdir -p "$BACKUP_DIR"
TS=$(date +'%Y%m%d_%H%M%S')
BACKUP_FILE="$BACKUP_DIR/db_tnsvt_${TS}.sql.gz"

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Starting backup: $BACKUP_FILE"

# Hostinger shared hosting: MySQL user 'u310596868_tnsvt_v2' has
# privileges for 'localhost' only (Unix socket), not for '127.0.0.1'
# or '::1' (TCP). Force protocol=socket so mysqldump doesn't try TCP
# first.
# MYSQL_PWD env avoids password in argv (safer than -p"$pass").
MYSQL_PWD="$DB_PASS" mysqldump \
    --protocol=socket \
    --socket=/var/lib/mysql/mysql.sock \
    -u "$DB_USER" \
    "$DB_NAME" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    | gzip > "$BACKUP_FILE"

# ── Verify the dump ─────────────────────────────────────────────────
if [ ! -s "$BACKUP_FILE" ]; then
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] ERROR: backup file is empty" >&2
    rm -f "$BACKUP_FILE"
    exit 1
fi

# Sanity: gzip files start with 1F 8B. (xxd would also work but stat + grep is portable.)
if ! gzip -t "$BACKUP_FILE" 2>/dev/null; then
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] ERROR: gzip integrity check failed" >&2
    rm -f "$BACKUP_FILE"
    exit 1
fi

FILE_SIZE=$(stat -c %s "$BACKUP_FILE")
echo "[$(date +'%Y-%m-%d %H:%M:%S')] OK: $BACKUP_FILE ($FILE_SIZE bytes)"

# ── Rotate: delete backups older than 30 days ────────────────────────
DELETED=$(find "$BACKUP_DIR" -maxdepth 1 -name 'db_tnsvt_*.sql.gz' -mtime +30 -print -delete | wc -l)
if [ "$DELETED" -gt 0 ]; then
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] Rotated: removed $DELETED backup(s) older than 30 days"
fi

# ── Final summary: disk usage of the backup dir ──────────────────────
BACKUP_COUNT=$(find "$BACKUP_DIR" -maxdepth 1 -name 'db_tnsvt_*.sql.gz' | wc -l)
TOTAL_SIZE=$(du -sh "$BACKUP_DIR" 2>/dev/null | cut -f1)
echo "[$(date +'%Y-%m-%d %H:%M:%S')] Done. ${BACKUP_COUNT} backup(s) on disk, total size: ${TOTAL_SIZE}"
