# Deploying Dana to a server

A plain Ubuntu VPS: **nginx + PHP 8.2-FPM + MariaDB**, no Docker, no
separate worker process. This resolves **Q-36** (TLS) via Let's Encrypt.

> This guide was Apache until 2026-09-14. The production server has
> always answered `Server: nginx/1.24.0 (Ubuntu)`, so the configuration
> actually in use existed only on that machine and nowhere in version
> control. It is now [`split-subdomain.nginx.conf.example`](split-subdomain.nginx.conf.example).
> The Apache examples are kept for anyone deploying on Apache; they are
> not what mydana.app serves.

## Pick a layout first

Everything below branches on this one choice, so make it now.

**A — one origin** — **this is what production runs.** Simplest; no CORS
anywhere:

```
https://mydana.app/           ->  the admin panel (static build)
https://mydana.app/api/v1/*   ->  the PHP API
```

**B — split subdomains** — built (FR-15.24) but **cancelled before it
went live** (client, 2026-09-14: «we redirected APIs through
api.mydana.app and admin.mydana.app, we need to cancel this part only»).
Everything for it is still here and works; nothing is configured to use
it. Choose it only if you decide to split later:

```
https://admin.mydana.app/      ->  the admin panel
https://api.mydana.app/api/v1/ ->  the PHP API
```

B is not just two vhosts. The panel's requests become cross-origin, so
**three settings must agree** or the panel fails in the browser with an
opaque error and *nothing in the API log*:

| Where | Setting |
|---|---|
| `api/.env` | `CORS_ALLOWED_ORIGINS=https://admin.mydana.app` |
| `panel/.env.production` | `VITE_API_BASE=https://api.mydana.app/api/v1` — **this file does not exist**; create it to choose layout B. Baked in at **build** time, so it takes a rebuild, not a restart. |
| the mobile app | `flutter build apk --release --dart-define=API_BASE=https://api.mydana.app/api/v1` |

Already running layout A and moving to B? Skip to
[Moving a live install to subdomains](#moving-a-live-install-to-subdomains)
— the order matters and doing it wrong takes the panel down.

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
  `api/.env` and later removed. It still needs revoking at
  [aistudio.google.com](https://aistudio.google.com) — it is a credential
  that leaked, not just code that changed. Note that FR-15.18 now uses
  `GEMINI_API_KEY` for rendering a question's own note as audio or a
  picture, superadmin-only and dormant unless the operator sets it; the
  shipped product still carries no key.
- **Real Android release keystore.** `app/android` signs release builds
  with the debug keystore (a public, well-known password). Fine for
  sideloading, not for Play Store. Mobile task, separate from this guide.
- **Every password used during development is a placeholder** —
  superadmin `azim`, admin `adminpass1`, teacher `teacher123`, student
  `student`. §6 creates a fresh superadmin with a password only you know;
  nothing from development should reach this server.
- **Push notifications are inbox-only** until `FCM_SERVICE_ACCOUNT_PATH`
  is set (§5). Not a blocker — the in-app inbox works regardless
  (FR-10.3).

---

## 1. Server prerequisites

A fresh Ubuntu 22.04 or 24.04 VPS, 1 vCPU / 1 GB RAM is enough for a
single centre. Root or sudo access.

```bash
sudo apt update && sudo apt upgrade -y
```

```bash
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update && sudo apt install -y nginx php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-gd php8.2-opcache php8.2-cli mariadb-server composer git unzip certbot python3-certbot-nginx
```

PHP 8.2 exactly (Ubuntu 24.04 ships 8.3, which the code accepts, but
pinning to 8.2 keeps this identical to XAMPP dev). Note **`php8.2-fpm`**,
not `libapache2-mod-php` — under nginx, PHP runs as a separate service
and that is what holds the opcache (§9).

Node 20 LTS, for building the admin panel. Build-time only: the server
never runs Node afterwards, it only serves the static output.

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs
```

Point your DNS `A` record(s) at the server's IP before §8 — certbot needs
them resolvable. Layout B needs **two**: `api.` and `admin.`.

---

## 2. Clone the repository

```bash
sudo mkdir -p /var/www/dana && sudo chown "$USER":"$USER" /var/www/dana && git clone https://github.com/azimalptech/dana.git /var/www/dana
```

---

## 3. Database

```bash
sudo mysql_secure_installation
```

```bash
sudo mysql -u root -p <<'SQL'
CREATE DATABASE dana CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dana_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON dana.* TO 'dana_app'@'localhost';
FLUSH PRIVILEGES;
SQL
```

`dana_app` gets exactly the four verbs the app issues at runtime — no
`DROP`/`ALTER`/`CREATE TABLE`, so a bug or a stolen credential can corrupt
rows but cannot touch schema or drop the database. Migrations need more
than that once, so run them as root (§6) or temporarily grant `ALL`, run
`bin/migrate.php`, then revoke back down — never leave the app's
day-to-day credential able to alter schema.

Never use MySQL `root` as `DB_USERNAME`. That was the XAMPP-dev default
and is listed in §0 as a thing to change before production.

---

## 4. PHP dependencies

```bash
cd /var/www/dana/api && composer install --no-dev --optimize-autoloader
```

`--no-dev` skips PHPUnit — this server never runs the test suite.

You may see *"Warning: The lock file is not up to date"*. It is cosmetic:
`composer.json` gained two explicit extension requirements
(`ext-pdo_mysql`, `ext-zip`) after the lock was last generated. No package
version changed. Run `composer update --lock` once if you want it gone.

---

## 5. Configure the API

```bash
cp /var/www/dana/api/.env.example /var/www/dana/api/.env
```

Edit `api/.env`:

| Variable | Set to |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` — **a stack trace in a JSON error response is a live server, not dev** |
| `APP_URL` | `https://api.mydana.app` (layout B) or `https://mydana.app/api` (layout A) |
| `DB_HOST` | `127.0.0.1` |
| `DB_DATABASE` | `dana` |
| `DB_USERNAME` | `dana_app` |
| `DB_PASSWORD` | the password from §3 |
| `CORS_ALLOWED_ORIGINS` | **Layout B only:** `https://admin.mydana.app`. Comma-separated, exact match, scheme included, no trailing slash. **Leave EMPTY for layout A** — the API then sends no CORS headers at all. Never `*`: these replies carry one student's progress and, on the FR-1.10 reveal path, a decrypted credential. |
| `APP_CRED_KEY` | `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` — **generate once, then never rotate.** It decrypts the teacher password-reveal path (FR-1.10); rotating it locks out every existing student's stored credential. |
| `JWT_SECRET` | same command, a different 32 random bytes |
| `JWT_ACCESS_TTL` / `JWT_REFRESH_TTL` | leave at `900` / `2592000` — 15 minutes and 30 days. Sessions renew themselves; shortening these does not make anything safer, it just makes renewal run more often. |
| `JWT_REFRESH_GRACE` | leave at `60`. How long a just-rotated refresh token may be presented again before the server treats it as stolen — the window covering a retry after a lost response and a second browser tab (FR-15.15). `0` restores the old behaviour, where those two ordinary events signed the user out everywhere. |
| `LOG_PATH` | leave as `../storage/logs` |
| `STORAGE_PATH` | leave as `../storage` — **outside `api/public`**, so the web root can never serve question audio, images or logs |
| `MAX_UPLOAD_BYTES` | **Nothing reads this.** It looks like the upload ceiling and is not one — §9's `php.ini` values are. Left in place rather than silently deleted; do not tune it expecting an effect. |
| `LLM_PROVIDER` / `*_API_KEY` | leave blank unless you are deliberately enabling FR-15.18 media rendering, which is superadmin-only and needs `GEMINI_API_KEY`. No AI writes content (hard invariant, `CLAUDE.md`). |
| `FCM_SERVICE_ACCOUNT_PATH` | optional — see §0. If set, put the JSON file *outside* `api/public`, same as `STORAGE_PATH`. |

```bash
chmod 600 /var/www/dana/api/.env
```

---

## 6. Migrate and create the superadmin

```bash
cd /var/www/dana/api && php bin/migrate.php
```

Interactive — the prompt hides the password so it never lands in shell
history. (The script's header documents a non-interactive `--password=`
form; do **not** use it here.)

```bash
cd /var/www/dana/api && php bin/seed_superadmin.php
```

Do **not** run `bin/seed_demo.php`. It creates the exact placeholder
accounts §0 lists as things that must never reach production.

---

## 7. Build the admin panel

```bash
cd /var/www/dana/panel && npm ci && npm run build
```

That is the whole step. There is **no `.env.production.local` to write** —
an older version of this guide told you to put `API_ORIGIN` in one, which
does nothing for a production build: `API_ORIGIN` is read only by
`vite.config.ts`'s **dev-server proxy**.

What the build would read for layout B is `panel/.env.production`. It is
deliberately **not** in the repository, so a default build is
single-origin and cannot silently point a panel at a host that does not
exist. **For layout A** — the current one — there is nothing to do: with no
`.env.production` present, `VITE_API_BASE` is unset and `api.ts` falls
back to a relative `/api/v1`, which resolves against whatever host serves
the panel.

`npm run build` runs `tsc -b && vite build` — a type error fails the build
loudly rather than shipping broken JS. Output lands in `panel/dist/`,
which is what nginx serves; nothing else under `panel/` is web-facing.

---

## 8. nginx + TLS

**Layout B — split subdomains:**

```bash
sudo cp /var/www/dana/deploy/split-subdomain.nginx.conf.example /etc/nginx/sites-available/dana.conf
```

Edit the two `server_name`s and the certificate paths if your domains
differ, then:

```bash
sudo ln -sf /etc/nginx/sites-available/dana.conf /etc/nginx/sites-enabled/ && sudo rm -f /etc/nginx/sites-enabled/default && sudo nginx -t && sudo systemctl reload nginx
```

```bash
sudo certbot --nginx -d api.mydana.app -d admin.mydana.app
```

**Layout A — one origin:** use the same file but keep a single `server`
block, with `root /var/www/dana/panel/dist` and a `location /api/` that
sends requests to `/var/www/dana/api/public/index.php`. Certbot then only
needs `-d mydana.app`.

Certbot installs a renewal timer automatically
(`systemctl list-timers | grep certbot`) — nothing further for renewal.

Set ownership once, so the app can write logs and accept uploads without
the directory being world-writable:

```bash
sudo chown -R www-data:www-data /var/www/dana/storage && sudo find /var/www/dana/storage -type d -exec chmod 750 {} \; && sudo find /var/www/dana/storage -type f -exec chmod 640 {} \;
```

---

## 9. PHP-FPM production tuning

Under nginx the file is `/etc/php/8.2/**fpm**/php.ini` — *not* the
`apache2/` or `cli/` copy. Editing the wrong one is a silent no-op.

```ini
display_errors = Off
expose_php = Off
upload_max_filesize = 25M
post_max_size = 26M
memory_limit = 256M
opcache.enable = 1
opcache.validate_timestamps = 0
```

`upload_max_filesize` / `post_max_size` are the **real** ceiling on
question audio and image uploads (`MAX_UPLOAD_BYTES` in `api/.env` is not
read by anything). nginx's `client_max_body_size` must be at least
`post_max_size`, or nginx answers 413 before PHP is reached and nothing
appears in the application log — the shipped config sets `32M` to sit just
above the `26M` here. Raise all three together or none.

`opcache.validate_timestamps = 0` caches compiled PHP permanently. It is
faster, and it means **a `git pull` alone changes files on disk while
every request keeps running the old code**, silently, with nothing in any
log. `deploy/redeploy.sh` reloads PHP for you as its last step. Deploying
by hand, you must do it yourself:

```bash
sudo systemctl reload php8.2-fpm
```

---

## 10. Verify

```bash
curl -s https://api.mydana.app/api/v1/health
```

`{"ok":true,"env":"production",...}` confirms nginx → php-fpm → MariaDB
all work. (Layout A: `https://mydana.app/api/v1/health`.)

Layout B only — the CORS handshake the panel depends on:

```bash
curl -si -H "Origin: https://admin.mydana.app" https://api.mydana.app/api/v1/health | grep -iE "^HTTP|access-control-allow-origin"
```

You want `200` **and** `access-control-allow-origin: https://admin.mydana.app`.
A missing header means `CORS_ALLOWED_ORIGINS` is unset or misspelled —
the failure that shows as an opaque browser error with nothing in the API
log. Confirm a hostile origin is refused too:

```bash
curl -si -H "Origin: https://evil.example.com" https://api.mydana.app/api/v1/health | grep -ci "access-control-allow-origin"
```

That must print `0`.

Then open the panel in a browser and log in with the superadmin from §6.

For the mobile app, build against the real domain:

```bash
cd app && flutter build apk --release --dart-define=API_BASE=https://api.mydana.app/api/v1
```

---

## Redeploying after a change

```bash
cd /var/www/dana && ./deploy/redeploy.sh
```

The script: `git pull` → `composer install` → `npm ci` → `npm run build`
→ `php bin/migrate.php` → reload php-fpm and nginx. All are safe to
re-run — migrations track what is applied in a `migrations` table, and
nothing in the script touches `api/.env` or `storage/`.

**It reloads PHP for you.** Do not skip that if you ever deploy by hand;
see §9 for why a `git pull` alone appears to do nothing.

### It only redoes what changed

`npm ci` and `composer install` are nearly all of a deploy's wall time and
on a normal day have nothing to do. Each step runs only when a file it
depends on actually moved since the last successful deploy:

| Step | Runs when |
| --- | --- |
| `composer install` | `api/composer.json` or `api/composer.lock` changed, or `api/vendor/` is missing |
| `npm ci` | `panel/package.json` or `panel/package-lock.json` changed, or `panel/node_modules/` is missing |
| `npm run build` | anything under `panel/` changed, or `panel/dist/` is missing |
| migrations, reload | always — both take seconds, and skipping either is how a deploy silently does nothing |

An API-only change is `git pull` → migrate → reload: a couple of seconds.
Force the long version with `./deploy/redeploy.sh --full`.

The comparison is against the last commit that deployed **successfully**,
recorded in `.deploy-state` (gitignored) and written only after the
reload. A run that dies partway leaves it pointing at the old commit, so
the retry redoes the step that failed instead of deciding there is nothing
to do.

On failure the script names the step and says plainly that nothing was
reloaded. On success it prints the commit now live and the built bundle's
filename — if the browser's view-source names a different `index-*.js`,
the build did land and the browser is holding a cached page
(Ctrl+Shift+R).

---

## Moving a live install to subdomains

Going from layout A to layout B on a server that is already serving
students. **Order matters**: `panel/.env.production` bakes
`https://api.mydana.app/api/v1` into the bundle, so rebuilding before the
subdomain exists points the panel at a host with no DNS and the admin
panel goes dark until you finish.

1. **DNS** — `A` records for `api.` and `admin.` at the server's IP.
   Do not continue until both resolve:

   ```bash
   dig +short api.mydana.app admin.mydana.app
   ```

2. **Pull the config only** — no rebuild yet:

   ```bash
   cd /var/www/dana && git pull --ff-only origin main
   ```

3. **Certificates:**

   ```bash
   sudo certbot --nginx -d api.mydana.app -d admin.mydana.app
   ```

4. **CORS** — `redeploy.sh` never touches `.env`, so this is by hand,
   once:

   ```bash
   cd /var/www/dana && sed -i 's|^CORS_ALLOWED_ORIGINS=.*|CORS_ALLOWED_ORIGINS=https://admin.mydana.app|' api/.env
   ```

5. **vhosts** — §8's layout B block.

6. **Only now, deploy:**

   ```bash
   cd /var/www/dana && ./deploy/redeploy.sh
   ```

7. **Verify** with §10's CORS checks.

The old `https://mydana.app/` will still serve the panel files, but they
now call `api.mydana.app`, which allowlists only `admin.mydana.app` — so
that URL breaks. Either redirect it to the new one, or allow both:

```bash
cd /var/www/dana && sed -i 's|^CORS_ALLOWED_ORIGINS=.*|CORS_ALLOWED_ORIGINS=https://admin.mydana.app,https://mydana.app|' api/.env && sudo systemctl reload php8.2-fpm
```

Students on an older APK keep talking to whatever host that build was
compiled with, so leave the old API path answering until they have
updated.

---

## Backups

Two things actually need backing up — everything else regenerates from
the git repo.

```bash
mysqldump -u root -p dana | gzip > dana-db-$(date +%F).sql.gz
```

```bash
tar czf dana-storage-$(date +%F).tar.gz -C /var/www/dana storage
```

**Never back up `api/.env` into the same place a stolen SQL dump could
reach.** `APP_CRED_KEY` is what makes a leaked database dump useless on
its own (`CLAUDE.md`'s hard invariant on credential handling); keeping
the key beside the dump defeats that separation. Store `.env` — or just
`APP_CRED_KEY` and `JWT_SECRET` — in a password manager or a separate
secrets store.

Course closure purges a classroom's student progress server-side on
completion (FR-1.14) — **export any report you need before closing a
course**, since that is not reversible from a backup taken afterwards.

---

## What this deploy deliberately does not include

- **No Docker.** Superseded by this guide — see the git log for the
  containerised version.
- **No `bin/worker.php` as a running service.** It exists only for the
  removed AI content-generation feature — dead code, no route, nothing
  enqueues into it. Do not add a systemd unit for it.
- **No cron jobs.** Nothing currently needs a scheduled task —
  notifications send synchronously, and there is no token-cleanup job.
- **No CORS on layout A.** Same-origin requests need none, and with
  `CORS_ALLOWED_ORIGINS` empty the API sends no CORS headers at all.
  Layout B is the case that needs it, and the middleware for it now
  exists (FR-15.24) — which it did not when this guide first said a split
  layout was out of scope.
