#!/bin/sh
# shellcheck disable=SC2153 # variables set by compose
# Entrypoint of the Invoice Ninja services (app, worker, scheduler, console):
# mail settings from the stack's SMTP_* variables, Laravel's caches for this
# container, then the image's own entrypoint (it sets the Chromium path for
# PDFs and runs the command).
set -eu
cd /var/www/html

# shellcheck source=scripts/mail-env.sh
. /usr/local/share/stack/scripts/mail-env.sh

# Config, routes and views cached from this container's environment.
if [ "$(id -u)" = 0 ]; then
    runuser -u www-data -- php artisan optimize > /dev/null
else
    php artisan optimize > /dev/null
fi

exec /usr/local/bin/init.sh "$@"
