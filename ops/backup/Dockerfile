# Daily PostgreSQL -> Cloudflare R2 backup job (Railway cron service).
#
# Why alpine + packages instead of the official postgres image: that image's
# entrypoint starts a database server and it declares a Docker VOLUME; neither
# belongs in a short-lived cron job. postgresql18-client (18.6) matches the
# production server's major version, which pg_dump requires.
FROM alpine:3.23

RUN apk add --no-cache postgresql18-client rclone ca-certificates \
 && adduser -D -u 10001 backup

COPY backup.sh /usr/local/bin/backup.sh
RUN chmod 0755 /usr/local/bin/backup.sh

# Run unprivileged; the script only needs /tmp.
USER backup

# The job must run once and EXIT (Railway skips the next run while a previous
# one is still "Active"), so this is a one-shot command, not a server.
CMD ["/usr/local/bin/backup.sh"]
