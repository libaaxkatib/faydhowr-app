#!/bin/sh
# Daily PostgreSQL backup -> Cloudflare R2.
#
# Runs as a Railway cron service (schedule "0 17 * * *" = 20:00 Somalia time,
# Railway cron is UTC). One run: dump, verify, upload, verify the uploaded
# object, clean up, EXIT. Any failure exits non-zero.
#
# Reads the database (pg_dump takes a single consistent snapshot; it does not
# lock out writers and never modifies data). Secrets are only ever read from
# environment variables; nothing secret is written to a file, argv, or the log.
#
# Required environment (set in Railway Variables, never in Git):
#   PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE   libpq connection (reference the Postgres service)
#   R2_ACCOUNT_ID R2_ACCESS_KEY_ID R2_SECRET_ACCESS_KEY R2_BUCKET
# Optional:
#   R2_ENDPOINT        override (e.g. EU jurisdiction); default https://<R2_ACCOUNT_ID>.r2.cloudflarestorage.com
#   BACKUP_PREFIX      object key prefix, default "postgres/daily"
#   BACKUP_LABEL       file label, default "prod" (use "rehearsal" for tests)
#   EXPECT_TABLE       table that must be present in the dump, default "employees"
#   PGSSLMODE          default "require" (encrypted; Railway's Postgres image has SSL)
#   RCLONE_BIN         uploader binary, default "rclone" (tests substitute a local stub)
set -eu
umask 077

START_EPOCH="$(date +%s)"
STEP="starting"
WORKDIR=""

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }

cleanup() {
  code=$?
  if [ -n "$WORKDIR" ] && [ -d "$WORKDIR" ]; then rm -rf "$WORKDIR"; fi
  if [ "$code" -ne 0 ]; then
    log "BACKUP FAILED during step: ${STEP} (exit ${code})"
  fi
  exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

fail() { log "ERROR: $*"; exit 1; }

STEP="checking configuration"
missing=""
for v in PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE R2_ACCOUNT_ID R2_ACCESS_KEY_ID R2_SECRET_ACCESS_KEY R2_BUCKET; do
  eval "val=\${$v:-}"
  [ -n "$val" ] || missing="$missing $v"
done
[ -z "$missing" ] || fail "missing required variables:${missing}"

export PGSSLMODE="${PGSSLMODE:-require}"
export PGCONNECT_TIMEOUT=20
PREFIX="${BACKUP_PREFIX:-postgres/daily}"
LABEL="${BACKUP_LABEL:-prod}"
EXPECT_TABLE="${EXPECT_TABLE:-employees}"
RCLONE="${RCLONE_BIN:-rclone}"

# rclone remote "r2" configured purely from environment (no config file on disk).
# no_check_bucket is required: the API token is scoped to object read/write on
# one bucket and cannot create or list buckets.
export RCLONE_CONFIG_R2_TYPE=s3
export RCLONE_CONFIG_R2_PROVIDER=Cloudflare
export RCLONE_CONFIG_R2_ACCESS_KEY_ID="$R2_ACCESS_KEY_ID"
export RCLONE_CONFIG_R2_SECRET_ACCESS_KEY="$R2_SECRET_ACCESS_KEY"
export RCLONE_CONFIG_R2_ENDPOINT="${R2_ENDPOINT:-https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com}"
export RCLONE_CONFIG_R2_NO_CHECK_BUCKET=true

STEP="creating private work directory"
WORKDIR="$(mktemp -d /tmp/fayadhowr_backup.XXXXXX)"
chmod 700 "$WORKDIR"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
NAME="fayadhowr_${LABEL}_${STAMP}.dump"
DUMP="$WORKDIR/$NAME"
KEY_DIR="$PREFIX/$(date -u +%Y)/$(date -u +%m)"

STEP="checking database connection"
log "client: $(pg_dump --version)"
SERVER_VERSION="$(psql -X -At -c 'show server_version')" || fail "cannot connect to the database"
log "server: PostgreSQL ${SERVER_VERSION}"

STEP="pg_dump"
log "dumping database (custom format, single consistent snapshot)"
pg_dump --format=custom --no-owner --no-privileges --lock-wait-timeout=30s --file="$DUMP"

STEP="verifying the dump"
SIZE="$(wc -c < "$DUMP" | tr -d ' ')"
[ "$SIZE" -ge 10240 ] || fail "dump is suspiciously small (${SIZE} bytes)"
[ "$(head -c 5 "$DUMP")" = "PGDMP" ] || fail "file is not a pg_dump custom-format archive"
pg_restore --list "$DUMP" > "$WORKDIR/toc.txt" || fail "archive table of contents is unreadable"
grep -q " TABLE DATA public ${EXPECT_TABLE} " "$WORKDIR/toc.txt" || fail "expected table '${EXPECT_TABLE}' is missing from the dump"
TABLES="$(grep -c ' TABLE DATA ' "$WORKDIR/toc.txt" || true)"
SHA="$(sha256sum "$DUMP" | cut -d' ' -f1)"
printf '%s  %s\n' "$SHA" "$NAME" > "$DUMP.sha256"
log "dump ok: ${NAME} size_bytes=${SIZE} tables_with_data=${TABLES} sha256=${SHA}"

STEP="uploading to R2"
DEST="r2:${R2_BUCKET}/${KEY_DIR}"
"$RCLONE" copyto "$DUMP" "$DEST/$NAME" --s3-no-check-bucket --retries 3 --low-level-retries 5
"$RCLONE" copyto "$DUMP.sha256" "$DEST/$NAME.sha256" --s3-no-check-bucket --retries 3 --low-level-retries 5

STEP="verifying the uploaded object"
REMOTE_SHA="$("$RCLONE" cat "$DEST/$NAME" | sha256sum | cut -d' ' -f1)"
[ "$REMOTE_SHA" = "$SHA" ] || fail "uploaded object checksum differs from the local dump"
log "upload verified: ${KEY_DIR}/${NAME} (re-downloaded checksum matches)"

STEP="finished"
log "BACKUP OK in $(( $(date +%s) - START_EPOCH ))s"
