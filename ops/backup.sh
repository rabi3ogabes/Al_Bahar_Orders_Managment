#!/usr/bin/env bash
# Logical PostgreSQL backup → encrypted → off-site object storage, then a restore verification into a scratch DB.
# Usage: ./ops/backup.sh [label]   Requires: BACKUP_BUCKET (s3://...), BACKUP_GPG_RECIPIENT, aws CLI configured.
# Physical PITR (WAL archiving) is handled by the managed PostgreSQL service in production — see docs/operations/BACKUP_RESTORE.md.
set -euo pipefail
LABEL="${1:-scheduled}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="albahar-${STAMP}-${LABEL}.dump"
cd "$(dirname "$0")/.."

docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc --no-owner' > "/tmp/${FILE}"

# Never consider a backup good until it restores: load into a throwaway database and count core tables.
docker compose exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" --if-exists restore_check && createdb -U "$POSTGRES_USER" restore_check'
docker compose exec -T postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d restore_check --no-owner' < "/tmp/${FILE}"
TABLES=$(docker compose exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d restore_check -tAc \"select count(*) from information_schema.tables where table_schema='public'\"")
docker compose exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" restore_check'
[ "${TABLES//[[:space:]]/}" -gt 100 ] || { echo "✗ Restore verification failed (${TABLES} tables)" >&2; exit 1; }

if [ -n "${BACKUP_BUCKET:-}" ]; then
  gpg --batch --yes --encrypt --recipient "${BACKUP_GPG_RECIPIENT:?}" --output "/tmp/${FILE}.gpg" "/tmp/${FILE}"
  aws s3 cp "/tmp/${FILE}.gpg" "${BACKUP_BUCKET}/postgres/${FILE}.gpg" --only-show-errors
  shred -u "/tmp/${FILE}" "/tmp/${FILE}.gpg" 2>/dev/null || true
  echo "✓ Backup verified and stored: ${BACKUP_BUCKET}/postgres/${FILE}.gpg"
else
  echo "✓ Backup verified (local only, BACKUP_BUCKET not set): /tmp/${FILE}"
fi
