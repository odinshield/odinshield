#!/bin/sh

# SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
#
# SPDX-License-Identifier: AGPL-3.0-or-later

set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
    if [ -n "$TZ" ]; then
        echo "date.timezone = $TZ" >/usr/local/etc/php/app.conf.d/99-timezone.ini
        if [ "$(id -u)" = "0" ] && [ -f "/usr/share/zoneinfo/$TZ" ]; then
            ln -snf "/usr/share/zoneinfo/$TZ" /etc/localtime
            echo "$TZ" >/etc/timezone
        fi
    fi

    if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
        composer install --prefer-dist --no-progress --no-interaction
    fi

    php bin/console -V

    if [ -n "$DATABASE_URL" ]; then
        echo 'Waiting for database to be ready...'
        ATTEMPTS_LEFT_TO_REACH_DATABASE=60
        until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php -r "
\$url = getenv('DATABASE_URL');
\$p   = parse_url(\$url);
try {
    new PDO('pgsql:host='.(\$p['host']??'localhost').';port='.(\$p['port']??5432).';dbname='.ltrim(\$p['path']??'/','/'), \$p['user']??'', \$p['pass']??'');
} catch (\\Exception \$e) { fwrite(STDERR, \$e->getMessage().PHP_EOL); exit(1); }
" 2>&1); do
            sleep 1
            ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
            echo "Still waiting for database to be ready... $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
        done

        if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
            echo 'The database is not up or not reachable:'
            echo "$DATABASE_ERROR"
            exit 1
        fi
        echo 'The database is now ready and reachable'

        if [ -z "$SKIP_MIGRATIONS" ] && [ "$(find ./migrations -iname '*.php' -print -quit)" ]; then
            php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
        fi

        php bin/console messenger:setup-transports --no-interaction || true
    fi

    echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
