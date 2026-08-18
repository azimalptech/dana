# Dana

English learning app for Dana language centres — Flutter mobile app
(students + teachers), PHP/MySQL API, React admin panels.

**The specification lives in [`docs/`](docs/) and is the source of
truth.** Read [docs/01-REQUIREMENTS.md](docs/01-REQUIREMENTS.md) before
changing behaviour, and [docs/02-OPEN-QUESTIONS.md](docs/02-OPEN-QUESTIONS.md)
before assuming anything.

## Run it on your phone

The API runs on this PC; the phone reaches it over USB. No Wi-Fi
dependency, no firewall rule.

**1 — start the API** (leave running):

```bash
php -S 127.0.0.1:8080 -t api/public api/public/index.php
```

The trailing `api/public/index.php` is the **router script and is not
optional**. Without it PHP's built-in server treats any URL that ends in
a file extension as a static asset: it looks for that file under the doc
root, does not find it, and answers 404 *itself* — the request never
reaches the app. Every media URL ends in one (`/api/v1/media/q12-stem.mp3`,
`…-stem.png`), so audio and images silently 404 while the rest of the API
works, which reads in the app as "the uploaded mp3 does not play".

**2 — plug the phone in** (USB debugging on), then forward the port:

```bash
adb reverse tcp:8080 tcp:8080
```

**3 — install the build:**

```bash
adb install -r app/build/app/outputs/flutter-apk/app-release.apk
```

To rebuild after changing the app:

```bash
cd app && flutter build apk --release --dart-define=API_BASE=http://127.0.0.1:8080/api/v1
```

`adb reverse` must be re-run after every replug — it does not survive a
disconnect.

### Opening the admin panel from other devices on the Wi-Fi

The panel dev server binds all interfaces (`server.host: true`), so any
device on the same network opens it at `http://<PC-IP>:5173` — no
per-device setup. `/api` calls flow through the dev proxy on the PC, so
other devices need no route to the API port at all. Allow the port once
(admin shell):

```bash
netsh advfirewall firewall add rule name=DanaPanel5173 dir=in action=allow protocol=TCP localport=5173
```

### Using the phone without the USB cable (Wi-Fi)

The default setup tunnels through USB. To go cordless, point the app at
the PC's LAN address instead — phone and PC must be on the same Wi-Fi:

1. Find the PC's address (`ipconfig` → the Wi-Fi adapter's IPv4).
2. Start the API listening on all interfaces:

```bash
php -S 0.0.0.0:8081 -t api/public api/public/index.php
```

3. Allow it through the firewall once (admin shell):

```bash
netsh advfirewall firewall add rule name="Dana API 8081" dir=in action=allow protocol=TCP localport=8081
```

4. Build the app against that address and install:

```bash
cd app && flutter build apk --debug --dart-define=API_BASE=http://192.168.100.232:8081/api/v1
```

Constraints: the PC must be on with the API running; the address is
DHCP-assigned and can change after a router restart — rebuild if it
does. Debug builds allow plain HTTP for this (scoped to
`android/app/src/debug/AndroidManifest.xml`); a release build will not,
which is deliberate — production needs the hosted HTTPS API (Q-36).

### If port 8080 is already taken

Plenty of things want 8080, and a stale one is easy to miss: every API
call returns `404 NOT_FOUND` because the request reaches whatever else is
listening, not this API. Check who owns it before assuming the code
broke:

```bash
powershell "Get-NetTCPConnection -LocalPort 8080 -State Listen | ForEach-Object { (Get-Process -Id $_.OwningProcess).ProcessName }"
```

Either free the port, or run the API somewhere else and point the two
clients at it — no rebuild needed for either:

```bash
php -S 127.0.0.1:8081 -t api/public api/public/index.php
```

- **Panel** — put `API_ORIGIN=http://127.0.0.1:8081` in
  `panel/.env.local`. The dev proxy reads it and falls back to 8080.
- **Phone** — bridge the device's 8080 to the new host port, so the
  installed APK keeps working unchanged:

```bash
adb reverse tcp:8080 tcp:8081
```

## Run the admin panel

```bash
npm run dev --prefix panel
```

Opens on `http://localhost:5173` and proxies `/api` to the PHP server, so
both must be running. Sign in with the superadmin or an admin account —
teachers and students are refused, since the panel has no endpoints for
them.

For production it builds to static files that Apache serves alongside the
API (FR-2.7):

```bash
npm run build --prefix panel
```

**Pages:** Progress (centre-wide, CSV export) · Centres and staff ·
Classrooms (create, close a course) · Notifications (send to all / centre
/ classroom) · Content (superadmin only — the review→publish gate).

## Demo accounts

| Role | Login | Password |
|---|---|---|
| Superadmin | `+99363538839` | `azim` |
| Teacher | `+99312000001` | `teacher` |
| Student | `+99365123456` | `student` |

These are development credentials. **Change them before this reaches a
server** — a 4-character password is fine on localhost and nowhere else.

## Status

| Piece | State |
|---|---|
| Specification — 7 docs, 80+ requirements | ✅ |
| MySQL schema — 27 tables, 45 FKs, 7 CHECKs | ✅ verified |
| API — auth, role scoping, content, exercises, progress, teacher tools | ✅ verified |
| Points engine — the brief's 46-point example | ✅ 17/17 tests |
| Credential layer — bcrypt + AES-256-GCM reveal | ✅ 12/12 tests |
| Security — access control, brute-force throttle | ✅ verified |
| Logging — auth / app / worker channels | ✅ |
| Content pipeline — OCR, generation, 9 validation gates | ✅ working |
| Flutter app — 4 exercise types, TM/RU, teacher side | ✅ builds, 0 analyzer issues |
| App: study-time tracking, offline cache, notification inbox, create-student | ✅ verified |
| Notifications API — send + inbox, scoped | ✅ verified |
| Management API — centres, staff, classrooms, progress, CSV, course closure | ✅ verified |
| Beginner curriculum — units 1–6, 12 sections mapped | ✅ |
| Generated content | ⚠️ Unit 1 only — quota (see below) |
| Admin / superadmin panel (React) | ✅ builds, verified in browser |
| Superadmin content editing — vocabulary, grammar, questions | ✅ verified |
| Curriculum + chunked book upload in the panel | ✅ verified |
| Generate buttons — per unit and per exercise type | ✅ verified |
| Teacher progress + per-question review (FR-12.10) | ✅ verified |
| Push delivery — FCM HTTP v1 server side, token registry, dead-token pruning | ✅ built; needs a Firebase key to go live |
| Push — Firebase in the Flutter app | ⬜ needs your Firebase project (see below) |

## Content — read this first

**Units 1–6 are structured; only Unit 1 has content, and its exercises
are missing.** Current state:

| | 1A | 1B | 2A–6B |
|---|---|---|---|
| Page range mapped | ✅ | ✅ | ✅ |
| Pages transcribed | ✅ | partial | ⬜ |
| Vocabulary (TM/RU + IPA) | ✅ 13 | ✅ 12 | ⬜ |
| Grammar (TM/RU) | ✅ | ✅ | ⬜ |
| Exercise sets | ⬜ | ⬜ | ⬜ |

So the app will show the course outline, vocabulary and grammar for 1A
and 1B, but **the exercise list will be empty** until the sets are
regenerated. The free-tier Gemini quota ran out; nothing is wrong with
the pipeline, which produced all four types cleanly earlier.

Generation is queued from the panel — press **«Сгенерировать контент
юнита»** on a unit, or **«Сгенерировать ещё упражнение»** inside a
section. A worker must be running to pick the jobs up:

```bash
php api/bin/worker.php
```

The CLI equivalent, if you prefer:

```bash
php api/bin/fill_unit.php --unit=1 --publish
```

Both are safe to re-run and pick up where they stopped. A free-tier rate
limit requeues the job rather than failing it.

**The Unit 1 content currently in the database predates the pool fixes.**
It was generated before speaker labels and textbook apparatus were
filtered out, so some questions read like `"4 a ___ good."` or
`"B Is ___ nice?"`. Regenerating a unit now produces clean output —
fewer questions per set, because the pool is honestly smaller, but every
one of them from a real sentence. Either regenerate from the panel or
edit the odd ones by hand in the section editor.

**Map the Workbook pages.** Only the Student's Book is mapped to sections
so far. After the apparatus filters, a Beginner spread yields about five
usable sentences — enough, but thin. The Workbook is ingested (75 pages)
and exists to supply exactly this extra practice material; bind its page
ranges on the «Программа и учебники» page to roughly double the pool.

It is safe to re-run: transcribed pages are skipped, existing exercise
sets are left alone, and a quota error stops it cleanly rather than
corrupting anything.

**The books are scans.** They have no text layer, so `pdftotext` returns
nothing and pages are transcribed by vision model instead. This
contradicted Q-12 and was found only when the real files arrived.

## Setup from scratch

```bash
php api/composer.phar install --working-dir=api
```

Copy `api/.env.example` to `api/.env` and generate the two keys:

```bash
php -r "echo 'APP_CRED_KEY=', base64_encode(random_bytes(32)), PHP_EOL, 'JWT_SECRET=', base64_encode(random_bytes(48)), PHP_EOL;"
```

`APP_CRED_KEY` decrypts student passwords for the teacher reveal
(FR-1.10). **Keep it out of git and out of database backups** — that
separation is what makes a stolen SQL dump useless.

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS dana CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

```bash
php api/bin/migrate.php
```

```bash
php api/bin/seed_superadmin.php --login=+99363538839 --password=CHANGE_ME --name=Azim
```

## Command reference

| Command | Purpose |
|---|---|
| `php api/bin/migrate.php` | Apply pending migrations |
| `php api/bin/seed_superadmin.php` | Create the first superadmin (only way in) |
| `php api/bin/seed_curriculum.php` | Units and page ranges for Beginner |
| `php api/bin/seed_demo.php` | Demo centre, teacher, classroom, student |
| `php api/bin/ingest_book.php` | Register a book and extract its text |
| `php api/bin/ocr_pages.php` | Transcribe scanned pages |
| `php api/bin/generate_section.php` | Generate one section's content |
| `php api/bin/fill_unit.php` | OCR + generate a whole unit |
| `php api/bin/llm_smoke.php` | One request; checks the provider works |
| `php api/bin/worker.php` | Processes the generation queue (`--once` to drain and exit) |

## Tests

```bash
php api/tests/points_test.php
```

```bash
php api/tests/pool_test.php
```

The first proves the brief's worked example — 8 correct + 2 wrong = 46
points — plus the once-per-question guarantee and that locked content is
unreachable. It creates and removes its own fixtures, so it is safe
against a live database.

The second locks down what reaches the model: speaker labels and exercise
numbering stripped, textbook apparatus excluded, and — the cases most
likely to regress — that `"A cappuccino"` keeps its "A" while
`"A Yes, she is…"` loses its speaker mark. Needs no database or API key.

## Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/api/v1/health` | — | Status, whether an LLM key is configured |
| POST | `/api/v1/auth/login` | — | `{login, password}` → tokens |
| POST | `/api/v1/auth/refresh` | — | Rotates the refresh token |
| POST | `/api/v1/auth/logout` | — | Revokes a refresh token |
| GET | `/api/v1/auth/me` | any | Current user |
| GET | `/api/v1/me/outline` | student | Units and unlocked sections |
| GET | `/api/v1/me/leaderboard` | student | Classroom ranking |
| GET | `/api/v1/me/stats` | student | Points, streak, study time |
| POST | `/api/v1/me/heartbeat` | student | Study-time accrual |
| GET | `/api/v1/sections/{id}/vocabulary` | student | Word list + bookmarks |
| GET | `/api/v1/sections/{id}/grammar` | student | Explanation in TM/RU |
| GET | `/api/v1/sections/{id}/exercises` | student | Sets with progress |
| POST | `/api/v1/vocabulary/{id}/bookmark` | student | Toggle bookmark |
| POST | `/api/v1/exercises/{id}/start` | student | Begin/resume an attempt |
| POST | `/api/v1/exercises/{id}/answer` | student | Grade one answer |
| POST | `/api/v1/exercises/{id}/complete` | student | Commit points |
| GET | `/api/v1/teacher/classrooms` | teacher | Own classrooms |
| GET | `/api/v1/teacher/classrooms/{id}/sections` | teacher | Unlock state |
| POST | `/api/v1/teacher/classrooms/{id}/unlock` | teacher | Start teaching a section |
| GET | `/api/v1/teacher/classrooms/{id}/students` | teacher | Roster |
| POST | `/api/v1/teacher/classrooms/{id}/students` | teacher | Create a student |
| GET | `/api/v1/teacher/classrooms/{id}/progress` | teacher | Per-student totals |
| GET | `/api/v1/teacher/students/{id}/credential` | teacher | Reveal password (audited) |
| POST | `/api/v1/teacher/students/{id}/password` | teacher | Reset password |
| GET | `/api/v1/me/notifications` | any | Inbox with unread count |
| POST | `/api/v1/me/notifications/{id}/read` | any | Mark read |
| POST | `/api/v1/notifications` | staff | Send (all / centre / classroom) |
| GET | `/api/v1/manage/centers` | admin+ | Centres, scoped |
| POST | `/api/v1/manage/centers` | superadmin | Create a centre |
| GET | `/api/v1/manage/staff` | admin+ | Admins and teachers, scoped |
| POST | `/api/v1/manage/admins` | superadmin | Create an admin |
| POST | `/api/v1/manage/teachers` | admin+ | Create a teacher |
| POST | `/api/v1/manage/staff/{id}/password` | admin+ | Reset staff password |
| GET | `/api/v1/manage/options` | admin+ | Levels + book sets for forms |
| POST | `/api/v1/manage/classrooms` | admin+ | Create a classroom |
| GET | `/api/v1/manage/progress` | admin+ | Centre-wide progress |
| GET | `/api/v1/manage/classrooms/{id}/export` | admin+ | CSV export |
| POST | `/api/v1/manage/classrooms/{id}/close` | admin+ | **Irreversible** close + purge |

Errors are bilingual, from the server:

```json
{"error":{"code":"invalid_credentials","message_tk":"...","message_ru":"..."}}
```

## Security notes

Enforced and regression-tested:

- **Tokens prove identity, nothing else** — role, centre and classroom
  are read from the database on every request. Deactivating an account
  (or closing a course) locks it out on the next request, not at token
  expiry, and a moved student never acts in their old classroom.
- **Credential reveals are rate-limited** — 20 per staff account per
  hour, counted from the same audit rows that record them (FR-1.12), so
  the throttle can never disagree with the trail.
- **Login throttling is two-layer** — 10 failures per (login, IP) and 40
  per login across all IPs per 15 minutes, so neither a lockout attack
  nor IP rotation works.
- **Grading is order-independent** — multiple-choice options shuffle per
  attempt (FR-12.6) and the client submits the option text; word banks
  shuffle so the answer isn't always first; a reorder puzzle never
  arrives already solved; bilingual match pairs accept the language the
  student was shown.
- **Uploads are strictly sequenced** — every chunk states its offset and
  a retry cannot append twice; abandoned uploads are swept after a day.
- **CSV exports neutralise formula injection** — a student named
  `=HYPERLINK(...)` cannot run anything on the admin's machine.
- **Duplicate-key races answer like validations** — concurrent student
  creation, staff creation and section unlocks resolve to the proper
  message, never a 500.
- **Stalled generation jobs self-heal** — a run left `running` by a
  crashed worker is reclaimed after 45 minutes instead of wedging its
  section forever.
- **Responses are `no-store`** with `nosniff`/`DENY` headers — replies
  carry per-student data and revealed credentials and must never sit in
  a shared cache.

- **Points cannot be double-awarded** — `UNIQUE (student_id, question_id)`
  rejects it at the database, not in application code.
- **Scores cannot disagree with correctness** — a CHECK constraint allows
  only 5-with-correct or 3-with-incorrect.
- **A student cannot reach locked content** — exercise ids are sequential
  and guessable, so every start/answer/complete re-checks that the
  section is unlocked for that student's classroom. Returns 404, not 403,
  so locked sections are not discoverable.
- **Login is throttled** — 10 failures per (login, IP) per 15 minutes.
  Phone-number logins plus short passwords are a small space to guess.
- **No user enumeration** — a nonexistent login and a wrong password
  produce byte-identical responses and comparable timing.
- **Refresh tokens rotate** — a spent token is rejected.
- **Grading is server-side** — the client submits answers, never scores,
  and never receives the answer key.
- **Credentials never reach a response** except through the audited
  reveal endpoint.

## Enabling push notifications

The server side is complete: FCM HTTP v1 with OAuth, a device-token
registry (`POST /api/v1/me/device`), shared-device token handover, and
pruning of tokens FCM reports dead. Without a key configured, sending
still works — the message lands in every recipient's in-app inbox and the
response reports `push.configured: false`.

To go live, three steps that need your accounts:

1. Create a Firebase project (free) at console.firebase.google.com and
   add an Android app with package name `com.dana.dana_app`.
2. Project settings → Service accounts → **Generate new private key**.
   Save the JSON outside the web root and set
   `FCM_SERVICE_ACCOUNT_PATH=` in `api/.env` to its path.
3. Download `google-services.json` into `app/android/app/`, add the
   `firebase_messaging` package to the Flutter app, and post the token it
   issues to `/api/v1/me/device`. (iOS additionally needs an Apple
   Developer account with an APNs key uploaded to Firebase.)

## Known gaps

- **Admin and superadmin panels are not built.** Content is generated by
  CLI, and `--publish` bypasses the review gate (FR-4.17) because there
  is no review UI yet. That flag should not survive into production.
- **Vocabulary is AI-proposed, not authored.** FR-6.2 says the superadmin
  writes it; what is in the database is a proposal awaiting review.
- **Turkmen and Russian strings are a first pass** — grammatical but
  written by a non-native speaker. Worth a review before release.
- **`reorder` is missing from Unit 1** — the run hit quota. Re-run
  `fill_unit.php --unit=1` to add it.
- **Workbook is not ingested.** Only the Student's Book is mapped.
- **No HTTPS.** `network_security_config.xml` permits cleartext to
  localhost and one LAN address for development; delete it before
  release.

## Deploying to a server

Hosting is chosen: a plain Ubuntu VPS (Apache + PHP 8.2 + MariaDB, no
Docker). Full step-by-step instructions, including TLS (resolves
**Q-36**), a redeploy script, and a backup procedure, are in
[deploy/DEPLOY.md](deploy/DEPLOY.md) — start there, not here.
