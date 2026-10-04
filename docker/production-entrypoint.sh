#!/bin/sh
set -eu
: "${APP_SECRET:?APP_SECRET must be set}"
: "${DATABASE_URL:?DATABASE_URL must be set}"
# Release Machines have no volume; their only job is the migration command.
if [ "${1:-}" = "php" ]; then
    exec docker-php-entrypoint "$@"
fi
for directory in /data/invoice-drafts /data/sessions /data/cache-app; do
    install -d -m 0700 -o www-data -g www-data "$directory"
done
su -s /bin/sh www-data -c 'php bin/console cache:clear --no-warmup && php bin/console cache:warmup'
exec docker-php-entrypoint "$@"
