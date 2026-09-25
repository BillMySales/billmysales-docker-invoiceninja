#!/bin/sh
# shellcheck disable=SC2153 # variables set by compose
# Installs or upgrades Invoice Ninja on every `docker compose up`; safe to
# repeat. What the image's init.sh does for its supervisord, as a one-shot
# job; runs as root (volumes' owners), artisan as www-data:
# - public/: the image's files (/tmp/public) copied to the `public` volume
#   (shared with Caddy) when the image changes.
# - `artisan migrate`, cache clear, `ninja:design-update` (invoice designs).
# - First run (no account): `db:seed` and `ninja:create-account`
#   (NINJA_ADMIN_EMAIL / NINJA_ADMIN_PASSWORD).
# - scripts/configure.php: company settings (currency, country, language...).
set -eu
cd /var/www/html

artisan() { runuser -u www-data -- php artisan "$@"; }

echo "==> Public files"
version="${NINJA_VERSION} $(stat -c %Y /tmp/public)"
if [ "$(cat public/.stack-version 2>/dev/null || true)" != "${version}" ]; then
    echo "Copying the image's public files (${NINJA_VERSION})"
    # index.html is a symlink into the image (resources/): Laravel serves it.
    rsync -a --delete --exclude=/.stack-version --exclude=/index.html /tmp/public/ public/
    # Version last: an interrupted copy is repeated.
    echo "${version}" > public/.stack-version
fi
for dir in public storage; do
    find "${dir}" ! -user www-data -exec chown -h www-data:www-data {} +
done
mkdir -p storage/app/public storage/framework/sessions storage/framework/views storage/framework/cache storage/logs
chown www-data:www-data storage/app/public storage/framework/* storage/logs

# MAIL_* for the setup's own artisan calls (like the services).
# shellcheck source=scripts/mail-env.sh
. /usr/local/share/stack/scripts/mail-env.sh

echo "==> Migrations"
artisan migrate --force
artisan cache:clear
artisan ninja:design-update

# Separate assignment: with `set -e`, a failing check stops setup here.
accounts="$(runuser -u www-data -- php /usr/local/share/stack/scripts/configure.php accounts)"
if [ "${accounts}" = 0 ]; then
    echo "==> First account (${NINJA_ADMIN_EMAIL})"
    artisan db:seed --force
    artisan ninja:create-account --email "${NINJA_ADMIN_EMAIL}" --password "${NINJA_ADMIN_PASSWORD}"
fi

echo "==> Company settings"
runuser -u www-data -- php /usr/local/share/stack/scripts/configure.php settings

echo "==> Done: Invoice Ninja ${NINJA_VERSION}"
echo "    App: ${APP_URL} (${NINJA_ADMIN_EMAIL})"
