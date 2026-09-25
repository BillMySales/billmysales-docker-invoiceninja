Invoice Ninja Docker stack
==========================

Docker Compose stack for [Invoice Ninja](https://invoiceninja.com) v5 (open
source invoicing: clients, quotes, invoices, recurring invoices, payments,
expenses, a client portal, REST API and webhooks), usable for local
development and for simple production deployments (a single server).
Maintained by [BillMySales](https://www.billmysales.com).

| Component     | Image                                    | Default version |
|---------------|------------------------------------------|-----------------|
| Web server    | `caddy:<ver>-alpine`                     | 2.11            |
| Invoice Ninja | `invoiceninja/invoiceninja-debian`       | 5.13.43         |
| Database      | `mysql:<ver>`                            | 8.4 (LTS)       |
| Queue/cache   | `redis:<ver>-alpine`                     | 7.4             |
| Mailpit       | `axllent/mailpit` (optional, dev)        | v1.31           |

Invoice Ninja's official Debian image (amd64 and arm64; tested on arm64;
~1.1 GB) has PHP-FPM 8.5 with the extensions it needs and Chromium, which
renders the PDFs. It runs PHP-FPM, two queue workers and the scheduler under
supervisord and initializes the app from its entrypoint; this stack runs one
process per container instead (`app`, `worker`, `scheduler`) and does the
initialization in a `setup` job. The image's entrypoint is kept (it sets the
Chromium path for PDFs). The deprecated Alpine image and the Octane variant
aren't used. MySQL 8 and Redis are what Invoice Ninja's own compose file
uses.

License: Invoice Ninja is under the Elastic License 2.0 (free to self-host;
not to be offered as a hosted service; "Created by Invoice Ninja" in emails
and PDFs is removed with a paid white-label license).

Requirements
------------

- Docker Engine 24+ with the Compose v2 plugin (`docker compose`, 2.24+).
- About 2 GB of disk for the images; 1 GB of RAM for the stack.
- Development: ports 8116, 8416 and 8025 free on the host.
- Production: a server with ports 80 and 443 reachable, and a DNS record for
  the site's domain pointing to it.

Quick start (development)
-------------------------

```shell
cp .env.dev.example .env
docker compose up -d
docker compose logs -f setup   # wait for "==> Done" (under a minute)
```

- App: http://localhost:8116 (user `admin@example.com`, password
  `admin12345`).
- Client portal: http://localhost:8116/client (links in the emails).
- API: http://localhost:8116/api/v1 (an API token created in the app's
  settings, header `X-API-TOKEN`).
- Mailpit (every email Invoice Ninja sends): http://localhost:8025

Production
----------

```shell
cp .env.prod.example .env
# Fill in NINJA_URL, SITE_ADDRESS, APP_KEY, DB_PASSWORD, DB_ROOT_PASSWORD,
# NINJA_ADMIN_EMAIL, NINJA_ADMIN_PASSWORD and the SMTP_* values.
docker compose up -d
```

- Keep `APP_KEY` outside the server too: it encrypts stored secrets (payment
  gateway credentials...), and a restored database needs it.
- With `SITE_ADDRESS` set to the domain, Caddy gets a Let's Encrypt certificate
  and renews it automatically (certificates live in the `caddy_data` volume).
- Behind another TLS-terminating proxy, use `SITE_ADDRESS=:80`.
- Compose refuses to start while a required value is missing.
- The `backup` profile is enabled by default in the production template.
- Behind an existing Traefik (no host ports), use `overrides/traefik.yaml`
  (see [Overrides](#overrides)).

Services
--------

| Service     | Profile   | Role                                                         |
|-------------|-----------|--------------------------------------------------------------|
| `db`        |           | MySQL, data in the `db_data` volume.                         |
| `redis`     |           | Queues, cache, sessions.                                     |
| `setup`     |           | One-shot job (`scripts/setup.sh`), runs on every `up`.       |
| `app`       |           | PHP-FPM (internal port 9000): app, client portal, API.       |
| `worker`    |           | Queue worker: emails, PDFs, webhooks, recurring invoices.    |
| `scheduler` |           | Laravel scheduler (`schedule:work`): recurring invoices, reminders. |
| `caddy`     |           | TLS, static files, the only published ports (80, 443).       |
| `console`   | `tools`   | Artisan, as `www-data`.                                      |
| `backup`    | `backup`  | Database dump + storage on a schedule.                       |
| `mailpit`   | `mailpit` | Development SMTP server that catches all mail.               |

The code lives in the image. Caddy serves the static files from the `public`
volume (the image's public files, copied by `setup` when the image changes)
and uploads through `public/storage`, a link into the `storage` volume
(logos, documents); everything else goes to PHP-FPM. Each service caches
Laravel's config and routes from its environment when it starts
(`scripts/run.sh`).

### What `setup` does

- Copies the image's public files to the `public` volume when the image
  changes (without `index.html`, a link into the image that Laravel serves)
  and fixes the volumes' owners.
- `artisan migrate`, cache clear, `ninja:design-update` (invoice designs).
- First run (no account): `db:seed` and `ninja:create-account` with
  `NINJA_ADMIN_EMAIL` / `NINJA_ADMIN_PASSWORD`.
- `scripts/configure.php`:
  - every run: the client portal address (`NINJA_PORTAL_URL`, or
    `NINJA_URL`) in the companies' `portal_domain`, which links in emails
    use (Invoice Ninja stores the URL of the install there);
  - once, while the company still has `ninja:create-account`'s defaults:
    company name, CLP, Chile, Spanish (`es_ES`), America/Santiago, `d/m/Y`
    dates, 24-hour time, and an IVA 19% tax rate as the default tax of new
    invoices. Later changes in the app are kept.

Common commands
---------------

```shell
docker compose ps                        # status: every service "healthy", setup "Exited (0)"
docker compose logs -f app worker        # logs
docker compose exec db mysql -uninja -p ninja   # SQL shell
docker compose run --rm console          # artisan commands (profile "tools")
docker compose run --rm console ninja:check-data
docker compose down                      # stop, keep data
docker compose down -v                   # stop and DELETE all data
```

Company settings
----------------

- Amounts in CLP have no decimals and Spanish separators (`$238.000`); the
  IVA is added on top of line prices (`NINJA_PRICES_INCLUDE_TAX=false`, as
  in B2B invoices): 2 × $100.000 + $38.000 IVA = $238.000.
- **Language `es_ES`**: Invoice Ninja's `es` translation has broken
  placeholders in several texts, among them the invoice and quote email
  subjects ("Nueva factura :invoice de Empresa"); `es_ES` (Spain) has them
  right. It says "presupuesto" for quotes; each client can have its own
  language.
- The invoices are Invoice Ninja's documents, not Chilean tax documents
  (DTE).

Emails
------

The worker sends them through SMTP (`SMTP_*`; `SMTP_SECURE`: `tls` =
STARTTLS, `ssl` = SMTPS, `none`): invoices and quotes with a link to the
client portal (and the PDF attached if enabled in the email settings),
payment receipts, reminders, and notifications to the admin. Without
`SMTP_HOST` emails are written to Laravel's log. The sender is `SMTP_FROM` /
`SMTP_FROM_NAME`.

Integrations (API and webhooks)
-------------------------------

- REST API at `<url>/api/v1` with a token (`X-API-TOKEN`).
- Webhooks (in the app's settings, or the API) for events
  such as invoice/quote/payment/client created or updated, with the entity
  as JSON. A BillMySales integration would be a webhook receiver.
- **Invoice Ninja refuses webhook URLs that resolve to private or loopback
  addresses** (SSRF protection, not configurable): a receiver on the host or
  a private network can't be registered through the app or the API; use a
  public address (or a tunnel in development). The check is only done when
  a webhook is saved, not when it's sent: a webhook created directly in the
  database is delivered to a private address (that's how this stack was
  tested, with a receiver on the host):

  ```shell
  docker compose exec -u www-data app php -r '
      require "vendor/autoload.php";
      $app = require "bootstrap/app.php";
      $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
      $company = App\Models\Company::first();
      $webhook = new App\Models\Webhook();
      $webhook->company_id = $company->id;
      $webhook->user_id = $company->owner()->id;
      $webhook->event_id = 2; // invoice created (App\Models\Webhook::EVENT_*)
      $webhook->target_url = "http://host.docker.internal:8999/ninja";
      $webhook->format = "JSON";
      $webhook->rest_method = "post";
      $webhook->save();'
  ```

Backups
-------

With the `backup` profile, the `backup` service writes `<timestamp>-db.sql.gz`
and `<timestamp>-files.tar.gz` (storage) to the `backups` volume (or
`./data/backups` with `overrides/local-dirs.yaml`) at start and then every
`BACKUP_INTERVAL_HOURS`, and deletes files older than `BACKUP_KEEP_DAYS`.
Files are readable by their owner only. `APP_KEY` is in `.env`, not in the
backups: keep it.

```shell
docker compose run --rm --no-deps backup now                  # back up now
docker compose run --rm --no-deps backup list                 # list timestamps
docker compose stop app worker scheduler                      # stop the app first
docker compose run --rm --no-deps backup restore <timestamp>  # database and storage
docker compose exec redis redis-cli FLUSHALL                  # queues and cache of the old data
docker compose up -d
```

`--no-deps` keeps the commands from starting `setup` first (with damaged
data `setup` fails and the restore would never run); the database must be
running (`docker compose up -d db` if the stack is down). A restore drops
every table first, so nothing created after the backup remains.

Upgrades
--------

Invoice Ninja releases several times a week. Back up first, then change
`NINJA_VERSION` in `.env` and run `docker compose up -d`: the new image is
pulled, and `setup` copies its public files and runs the migrations before
the app starts. The app's own self-update doesn't apply (the code is in the
image).

Overrides
---------

Optional compose files in `overrides/`, enabled with `COMPOSE_FILE` in `.env`
(several are combined with `:`). Each file documents its variables.

```shell
COMPOSE_FILE=compose.yaml:overrides/traefik.yaml:overrides/local-dirs.yaml
```

| File                        | Purpose                                                            |
|-----------------------------|--------------------------------------------------------------------|
| `overrides/traefik.yaml`    | Publish through an existing Traefik on a shared external network:  |
|                             | no host ports, Traefik terminates TLS (`TRAEFIK_HOST`, ...).       |
| `overrides/local-dirs.yaml` | Database, Redis, public files, storage, Caddy and backups in       |
|                             | local directories (`DATA_DIR`, default `./data`).                  |

A local `compose.override.yaml` (gitignored) is also loaded automatically by
Docker Compose, for changes specific to one machine.

Configuration
-------------

Every variable is documented in `.env.prod.example`. Main groups:

- **Site and network**: `NINJA_URL`, `NINJA_PORTAL_URL`, `SITE_ADDRESS`,
  `HTTP_BIND`, `HTTP_PORT`, `HTTPS_PORT`, `TIMEZONE`.
- **Credentials**: `APP_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`,
  `NINJA_ADMIN_EMAIL`, `NINJA_ADMIN_PASSWORD` (required).
- **Company** (first install only): `NINJA_COMPANY_NAME`, `NINJA_CURRENCY`,
  `NINJA_COUNTRY`, `NINJA_LANGUAGE`, `NINJA_DATE_FORMAT`, `NINJA_TAX_RATE`,
  `NINJA_TAX_NAME`, `NINJA_PRICES_INCLUDE_TAX`.
- **Versions**: `NINJA_VERSION`, `MYSQL_VERSION`, `REDIS_VERSION`,
  `CADDY_VERSION`, ...
- **Mail**: `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`,
  `SMTP_PASSWORD`, `SMTP_FROM`, `SMTP_FROM_NAME`.
- **Resources and logs**: `*_MEMORY_LIMIT` per service, `UPLOAD_MAX_SIZE`,
  `LOG_MAX_SIZE`, `LOG_MAX_FILE`.

Notes:

- Production settings: `APP_ENV=production`, debug off.
- Behind Caddy (and Traefik), Laravel trusts the proxy headers
  (`TRUSTED_PROXIES`: only Caddy reaches PHP-FPM), so links follow the
  public `https://` address. Invoice Ninja records the client IP from
  `Cf-Connecting-Ip` or else `X-Forwarded-For` as is: Caddy removes the
  first and sends only the real client IP in the second (it would otherwise
  be the whole, forgeable chain). It also drops a client's
  `X-Forwarded-Port` (Laravel trusts it, and Caddy doesn't reset it).
- From inside the containers, the host machine is reachable as
  `host.docker.internal` (not usable for webhooks, see above).

Security
--------

- No default secrets: compose fails if the required passwords and keys are
  missing. The development template uses public values; never use it on a
  server.
- Only Caddy (and Mailpit in development) publishes ports; PHP-FPM, MySQL
  and Redis are internal. `HTTP_BIND` defaults to `127.0.0.1`.
- Caddy serves only the public files and `index.php`; dotfiles and other PHP
  files are never served; unknown paths get the app's page.
- Not included: a web application firewall or off-site backup copies.

Validation
----------

What was checked for this stack (2026-09-25):

- Clean start (`down -v` + `up -d`, images pulled) in about 45 s: every
  service `healthy`, `setup` `Exited (0)`; a second run makes no changes.
- The app and its 135 assets, client portal pages and assets, API login;
  company settings (CLP, Chile, es_ES, IVA 19%); client, invoice with IVA
  (2 × $100.000 + $38.000 = $238.000) and its PDF in Spanish (`25/09/2026`,
  `$238.000`), rendered by Chromium on arm64.
- Invoice email to the client through SMTP to Mailpit in Spanish ("Nueva
  factura Nº 0001 de Empresa") with the portal link, admin notifications.
- A webhook (invoice created) delivered to a receiver on the host (created
  in the database: the app refuses private addresses).
- Backup and restore (an invoice created after the backup is gone; storage
  back, served through `public/storage`).
- Upgrade 5.12.70 → 5.13.43 with data: migrations, public files refreshed,
  invoices kept, invoicing afterwards; the upgraded schema (columns,
  indexes, foreign keys) is identical to a fresh 5.13.43 install's.
- HTTPS with `SITE_ADDRESS=localhost` (links, secure cookies, portal links in
  emails on `https://localhost:8416`); URL change and back (portal links
  follow); overrides: Traefik v3.6 routing with no host ports (only the real
  client IP recorded), local directories (fresh install).
- Not tested: issuing a real Let's Encrypt certificate (needs a public
  domain), payment gateways, a real SMTP provider.

Resource usage
--------------

Idle, after a few requests: MySQL ~495 MiB, worker ~155 MiB, scheduler
~135 MiB, PHP-FPM ~105 MiB, Caddy ~18 MiB, Redis ~7 MiB (about 910 MiB in
total). Image ~1.1 GB.

License
-------

[MIT](LICENSE) (the stack; Invoice Ninja itself is under the Elastic
License 2.0).
