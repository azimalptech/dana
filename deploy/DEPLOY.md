# Deploying Dana to a server

A plain Ubuntu VPS: Apache + PHP 8.2 + MariaDB, no Docker, no separate
worker process. This resolves **Q-36** (TLS) via Let's Encrypt.

One origin serves everything, matching what the code already expects
(`panel/vite.config.ts`'s dev proxy, and the mobile app's
`--dart-define=API_BASE=.../api/v1`):

```
https://YOUR_DOMAIN/           ->  the admin panel (static build)
https://YOUR_DOMAIN/api/v1/*   ->  the PHP API
```

Estimated first-deploy time: 30–45 minutes on a fresh VPS.

---

## 0. Before you start

Read [`../CLAUDE.md`](../CLAUDE.md) and
[`../docs/02-OPEN-QUESTIONS.md`](../docs/02-OPEN-QUESTIONS.md) if you
haven't — this deploy does not decide any open `Q-*`, it only stands the
app up.

**Known items to close before real students use this** (kept here so
they aren't forgotten — none of them block getting the server running):

- **Rotate the Gemini API key.** An earlier key was committed to
  `api/.env` and later removed. The product ships with no AI generation
  (hard invariant — see `CLAUDE.md`), so `GEMINI_API_KEY` stays blank
  forever; that old key still needs revoking at
  [aistudio.google.com](https://aistudio.google.com) if you haven't
  already, since it's a credential that leaked, not just code that
  changed.
- **Real Android release keystore.** `app/android` currently signs
  release builds with the debug keystore (a public, well-known
  password). This is a mobile-app task, separate from this server
  deploy — do it before publishing the app, not before running this guide.
- **Every password used during development is a placeholder** —
  superadmin `azim`, admin `adminpass1`, teacher `teacher123`, student
  `student`, all of it. §6 below creates a fresh superadmin with a
  password only you know; nothing from development should reach this
  server.
- **Push notifications are inbox-only** until `FCM_SERVICE_ACCOUNT_PATH`
  is set (§5). Not a blocker — the in-app inbox works regardless
  (FR-10.3) — just don't expect a phone banner until you wire it up.

---

## 1. Server prerequisites

A fresh Ubuntu 22.04 or 24.04 VPS, 1 vCPU / 1 GB RAM is enough for a
single centre. Root or sudo access.

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.2 exactly (ondrej/php PPA — Ubuntu 24.04 ships 8.3, which the
# code accepts, but pinning to 8.2 keeps this identical to XAMPP dev)
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y \
  apache2 libapache2-mod-php8.2 \
  php8.2 php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl \
  php8.2-zip php8.2-gd php8.2-opcache php8.2-cli \
  mariadb-server \
  composer \
  git unzip \
  certbot python3-certbot-apache

# Node 20 LTS, for building the admin panel (build-time only — the
# server never runs Node afterwards, only serves the static output)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

Point your domain's DNS `A` record at the server's IP before §8
(certbot needs it resolvable).

---

## 2. Clone the repository

```bash
sudo mkdir -p /var/www/dana
sudo chown "$USER":"$USER" /var/www/dana
git clone https://github.com/azimalptech/dana.git /var/www/dana
cd /var/www/dana
```

---

## 3. Database

```bash
sudo mysql_secure_installation   # set a root password, remove test DB/anon users

sudo mysql -u root -p <<'SQL'
CREATE DATABASE dana CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dana_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON dana.* TO 'dana_app'@'localhost';
FLUSH PRIVILEGES;
SQL
```

`dana_app` gets exactly the four verbs the app issues at runtime — no
`DROP`/`ALTER`/`CREATE TABLE`, so a bug or a stolen API key can corrupt
rows but can't touch schema or drop the database. Migrations need more
than that once, so run them as root (§6) or temporarily grant `ALL` to
`dana_app`, run `bin/migrate.php`, then revoke back down to the four
verbs above — never leave the app's day-to-day credential able to alter
schema.

Never use MySQL `root` as `DB_USERNAME` in `api/.env` — that was the
XAMPP-dev default and is explicitly listed as a password to change
before production (§0).

---

## 4. PHP dependencies

```bash
cd /var/www/dana/api
composer install --no-dev --optimize-autoloader
```

`--no-dev` skips PHPUnit — this server never runs the test suite.

You may see *"Warning: The lock file is not up to date"* — this repo's
`composer.json` gained two explicit extension requirements
(`ext-pdo_mysql`, `ext-zip`) after the lock file was last generated with
composer unavailable in that session. It's cosmetic: no package version
changed, only the declared platform requirements did. `composer install`
still installs correctly; run `composer update --lock` once if you want
the warning gone.

---

## 5. Configure the API

```bash
cp api/.env.example api/.env
```

Edit `api/.env`:

| Variable | Set to |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` — **a stack trace in a JSON error response is a live server, not dev** |
| `APP_URL` | `https://YOUR_DOMAIN/api` |
| `DB_HOST` | `127.0.0.1` |
| `DB_DATABASE` | `dana` |
| `DB_USERNAME` | `dana_app` |
| `DB_PASSWORD` | the password from §3 |
| `APP_CRED_KEY` | `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` — **generate once, then never rotate.** It decrypts the teacher password-reveal path (FR-1.10); rotating it locks out every existing student's stored credential. |
| `JWT_SECRET` | same command, a different 32 random bytes |
| `LOG_PATH` | leave as `../storage/logs` |
| `STORAGE_PATH` | leave as `../storage` — **outside `api/public`, so `Alias /api` never exposes it** (question audio/images, logs — see the Apache config's comment) |
| `LLM_PROVIDER` / `*_API_KEY` | **leave every one of these blank.** No AI generation ships in this product (hard invariant, `CLAUDE.md`) — filling one in doesn't turn a feature on, since no route calls it, but a filled-in key is one more secret that can leak for nothing. |
| `FCM_SERVICE_ACCOUNT_PATH` | optional — see §0's push-notification note. If you set one, put the JSON file *outside* `api/public` too, same as `STORAGE_PATH`. |

```bash
chmod 600 api/.env
```

---

## 6. Migrate and create the superadmin

```bash
cd /var/www/dana/api
php bin/migrate.php

# Interactive — the prompt hides the password so it never lands in
# shell history (see the script's own header comment for the
# non-interactive --password= form, which you should NOT use here).
php bin/seed_superadmin.php
```

Do **not** run `bin/seed_demo.php` here — it creates the exact
placeholder accounts (`azim`, `adminpass1`, `teacher123`, `student`)
listed in §0 as things that must never reach production.

---

## 7. Build the admin panel

```bash
cd /var/www/dana/panel
echo "API_ORIGIN=https://YOUR_DOMAIN" > .env.production.local
npm ci
npm run build
```

`npm run build` runs `tsc -b && vite build` (see `package.json`) — a
type error fails the build loudly rather than shipping broken JS.
Output lands in `panel/dist/`, which is what the Apache vhost below
serves directly; nothing under `panel/` other than `dist/` is
web-facing.

---

## 8. Apache vhost + TLS

```bash
sudo cp deploy/apache-dana.conf.example /etc/apache2/sites-available/dana.conf
sudo sed -i 's/YOUR_DOMAIN/your.actual.domain/g' /etc/apache2/sites-available/dana.conf

sudo a2enmod rewrite
sudo a2ensite dana
sudo a2dissite 000-default
sudo systemctl reload apache2

# Requests storage of a cert for your.actual.domain, then edits the
# vhost above in place to add the SSL directives + an HTTP->HTTPS
# redirect — after this the SSLCertificateFile lines that config
# started with are the exact result, not just a placeholder anymore.
sudo certbot --apache -d your.actual.domain
```

Certbot installs a renewal timer automatically
(`systemctl list-timers | grep certbot`) — nothing further to do for
renewal.

Set correct ownership once, so the app can write logs and accept
uploads without the whole directory being world-writable:

```bash
sudo chown -R www-data:www-data /var/www/dana/storage
sudo find /var/www/dana/storage -type d -exec chmod 750 {} \;
sudo find /var/www/dana/storage -type f -exec chmod 640 {} \;
```

---

## 9. PHP production tuning

`/etc/php/8.2/apache2/php.ini`:

```ini
display_errors = Off
expose_php = Off
upload_max_filesize = 25M
post_max_size = 26M
memory_limit = 256M
opcache.enable = 1
opcache.validate_timestamps = 0
```

`opcache.validate_timestamps = 0` caches compiled PHP permanently —
faster, but it means **a redeploy is invisible until you reload PHP**
(`deploy/redeploy.sh` does not do this for you):

```bash
sudo systemctl reload apache2
```

`upload_max_filesize`/`post_max_size` here are the real ceiling on
question audio/image uploads. `MAX_UPLOAD_BYTES` in `api/.env` is
declared but not currently read by any code path — these two `php.ini`
values are what actually governs it today.

---

## 10. Verify

```bash
curl -s https://your.actual.domain/api/v1/health
# -> {"status":"ok"} or similar — confirms Apache -> PHP -> MariaDB all work

curl -s -o /dev/null -w '%{http_code}\n' https://your.actual.domain/
# -> 200 — the panel is being served
```

Open `https://your.actual.domain/` in a browser and log in with the
superadmin credentials from §6.

For the mobile app, rebuild pointing at the real domain instead of a
LAN IP — this is also what finally retires the LAN-scoped cleartext
exception in `network_security_config.xml` (§0):

```bash
cd app
flutter build apk --release --dart-define=API_BASE=https://your.actual.domain/api/v1
```

---

## Redeploying after a change

```bash
cd /var/www/dana
./deploy/redeploy.sh
sudo systemctl reload apache2   # only needed because of opcache.validate_timestamps=0 above
```

The script: `git pull` → `composer install` → `npm run build` →
`php bin/migrate.php`. All four are safe to re-run — migrations track
what's applied in a `migrations` table (`api/bin/migrate.php`), and
nothing in the script touches `api/.env` or `storage/`.

---

## Backups

Two things actually need backing up — everything else regenerates from
the git repo.

```bash
# Database — the real content and every student's progress
mysqldump -u root -p dana | gzip > dana-db-$(date +%F).sql.gz

# Uploaded question media (audio/images) — not in git, not in the DB
tar czf dana-storage-$(date +%F).tar.gz -C /var/www/dana storage
```

**Never back up `api/.env` alongside the database dump into the same
place a stolen SQL dump could reach.** `APP_CRED_KEY` is what makes a
leaked database dump useless on its own (`CLAUDE.md`'s hard invariant
on credential handling) — keeping the key next to the dump defeats
that separation. Store `.env` (or just `APP_CRED_KEY`/`JWT_SECRET`) in
a password manager or a separate secrets store instead.

Course closure already purges a classroom's student progress
server-side on completion (FR-1.14) — **export any report you need
before closing a course**, since that action is not reversible from a
backup taken after the fact.

---

## What this deploy deliberately does not include

- **No Docker.** Superseded by this guide — see the repo's git log if
  you want the containerised version for reference.
- **No `bin/worker.php` as a running service.** It exists only for the
  removed AI content-generation feature (`api/src/Content/**`,
  `GenerationQueue`) — dead code, not registered as a route, nothing
  enqueues into it. Don't add a systemd unit for it.
- **No cron jobs.** Nothing in the codebase currently needs a scheduled
  task — notifications send synchronously, and there's no token-cleanup
  job to run periodically.
- **No CORS configuration.** The single-origin layout in §8 keeps the
  panel's browser requests same-origin, so the API needs none — it
  currently defines none, and a split-domain layout would require
  writing that middleware first.
