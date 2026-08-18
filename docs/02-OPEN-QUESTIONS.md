# Dana — Open Questions

> **Nothing here may be implemented by assumption.** Each item has a
> *recommended default*. You can answer individually, or say
> **"use recommendations except Q-x, Q-y"** and I'll lock the rest in.
>
> **2026-08-04 — every 🟡 item's recommendation was approved as written.**
> The resulting rules are in §12 of [01-REQUIREMENTS.md](01-REQUIREMENTS.md).
> The remaining 🟢 items are proceeding on their documented recommendations
> too; all are low-impact and reversible, and any can still be changed.
>
> **Legend:** 🔴 blocks build start · 🟡 blocks a specific feature · 🟢 can be decided later
> **Last updated:** 2026-08-04

---

## A. Accounts & authentication

**✅ Q-1 — Login format & uniqueness.** *Settled by Q-45:* the login is a **phone number**, `+993` + 8 digits, stored E.164 and **unique system-wide** (not per centre). The panel validates format and rejects duplicates at creation time. See FR-1.16.

**✅ Q-2 — Password visibility.** *Answered 2026-08-04:* teachers and centre admins **can view** student passwords. Implemented as dual storage — bcrypt hash for auth, AES-256-GCM ciphertext for display, key outside the DB, every reveal audit-logged. See FR-1.10–1.12 and [04-DATA-MODEL.md](04-DATA-MODEL.md#credential-storage).

**✅ Q-3 — Student in multiple classrooms.** *Answered 2026-08-04:* **one classroom per account.** When the course finishes the account is **disabled** and its progress data is **not retained**. A student starting a new course gets a new account. See FR-1.13, FR-1.14 and [04-DATA-MODEL.md](04-DATA-MODEL.md#data-retention).

**✅ Q-4 — Progress on level change.** *Settled by Q-3:* nothing carries over. Each course is a fresh account with a fresh score, so the leaderboard is inherently classroom-scoped.

**🟡 Q-5 — Teacher scope.** *Partly answered 2026-08-04:* a teacher **can own several classrooms** — confirmed (FR-1.15). **Still open:** can one teacher work at more than one *centre*?
→ *Recommend:* **no** — one teacher belongs to exactly one centre. Proceeding on this unless corrected.

**🟡 Q-6 — Concurrent sessions.** Should a student account be limited to **one active device** (to stop credential sharing between friends)?
→ *Recommend:* **yes**, one active session; logging in elsewhere kicks the previous device. Teachers unrestricted.

**🟢 Q-7 — Superadmin bootstrap.** How is the very first superadmin created?
→ *Recommend:* one-time CLI seed script, credentials set by you at install; no UI path to create another superadmin unless you want one.

**🟡 Q-8 — Delete vs deactivate.** When an admin/teacher/student is removed, is the record deleted or deactivated?
→ *Impacts:* Historical progress and leaderboard integrity.
→ *Recommend:* **soft-delete (deactivate)** everywhere; progress preserved, account can no longer log in.

## B. Centres & classrooms

**✅ Q-9 — "Teaching book" dropdown.** *Answered 2026-08-04:* the dropdown picks a **book set** — a Student's Book + Workbook pair. New `book_sets` table; `classrooms.book_set_id` targets it. See FR-3.10.

**🟢 Q-10 — Classroom attributes.** Beyond teacher + level + book, does a classroom need a name, start/end date, schedule (days/times), or capacity?
→ *Recommend:* name + start date + optional capacity. No timetable in v1.

**🟢 Q-11 — Reassignment.** Can an admin move a classroom to a different teacher, or move a student between classrooms?
→ *Recommend:* yes to both, admin-only, with an audit log entry.

## C. Books & source material

**✅ Q-12 — Book file format.** *Answered 2026-08-04:* **digital PDFs with selectable text.** No OCR stage; no per-unit proofing gate. See FR-3.8.

**✅ Q-13 — Which book series?** *Answered 2026-08-04:* **none hardcoded.** The superadmin uploads whichever books they choose, and the AI works only from what has been uploaded. The system is therefore **book-agnostic**: no series-specific parsing, no assumed unit count, no assumed section scheme. This also settles Q-18. See FR-3.9.

**✅ Q-14 — Unit → page mapping.** *Answered 2026-08-04:* AI proposes page ranges from the contents page; **the superadmin confirms each one manually**. `section_sources.confirmed_by` must be non-null before a generation run can start. See FR-4.15.

**🟡 Q-15 — SB vs WB in one run.** Does one generation run read SB + WB pages together into one pool, or produce separate exercise sets per book?
→ *Recommend:* **one pooled run** per section; the source book is recorded per question for traceability.

**✅ Q-16 — Rights to the source books.** *Answered 2026-08-04:* confirmed by the client — they hold the necessary rights to upload the textbooks and derive exercises from them.

**✅ Q-17 — Storage.** *Answered 2026-08-04:* **500 MB cap per file**, stored on the filesystem outside the web root with the path in the database. Requires raised `upload_max_filesize` / `post_max_size` in PHP and chunked upload from the panel — a single 500 MB POST is not reliable. See FR-3.11.

## D. Units & sections

**✅ Q-18 — Section scheme.** *Settled by Q-13:* **fully superadmin-defined.** Arbitrary section codes (`A`, `B`, `C`, `D`, `Review`, …), arbitrary unit counts, drag-to-reorder. Nothing in the code assumes A/B/C or a fixed number of units. See FR-3.9.

**✅ Q-19 — Where content attaches.** *Answered 2026-08-04:* units are `1, 2, 3…`; sections are `1A, 1B, 1C, 2A, 2B, 3A, 3B…` with a **varying number of sections per unit**. **Grammar, vocabulary and exercises all attach to the section**, and all unlock together when the teacher begins that lesson. The unit is a container only. See FR-3.12.

## E. Exercises & questions

**✅ Q-20 — What exactly is bilingual?** *Answered 2026-08-04:* **instructions only.** Task instructions, hints and feedback are authored in TM and RU; the English content being practised stays English. See FR-4.14.

**✅ Q-21 — `match pairs` — what matches what?** *Answered 2026-08-04:* **all of them.** `match_pairs` sets carry a `pair_mode`: `translation` (English ↔ TM/RU), `definition` (English word ↔ English definition), `sentence_halves`, and `question_answer`. The superadmin picks the mode per set; the generator produces whichever modes the source pages can support. See FR-4.16.

**🟡 Q-22 — `reorder` — reorder what?** Scrambled **words into a sentence**, or scrambled **sentences into a dialogue/paragraph**?
→ *Recommend:* **words into a sentence**, 5–12 words, exactly one valid ordering.

**✅ Q-23 — `fill in the blank` — input method.** *Answered 2026-08-04:* **tap a word from the bank to fill a blank, tap the filled word to remove it** if tapped by mistake. Word bank at every level — no typing in v1. A question is only submitted once every blank is filled. See FR-4.19.

**✅ Q-24 — Type coverage.** *Answered 2026-08-04:* **no — a type must never be forced.** The generator attempts all four; any type the source pages cannot properly support is **reported to the superadmin, not fabricated**. A section with three solid exercise types is correct behaviour, not a failure. See FR-4.20.

**🟢 Q-25 — Content scope.** Is content global across all centres, or can a centre have its own variants?
→ *Recommend:* **global** — superadmin owns all content, as stated in the brief.

**🟡 Q-26 — Question order.** Fixed as authored, or shuffled per attempt? Should multiple-choice options shuffle?
→ *Recommend:* questions in authored order; **options shuffled** each attempt.

**✅ Q-27 — Review before publish.** *Answered 2026-08-04:* **yes, mandatory.** `draft → in_review → published`. Students only ever read `published` content, filtered at the repository layer. See FR-4.17.

## F. Learning flow & gamification

**✅ Q-28 — How does unlocking actually happen?** *Answered 2026-08-04:* **explicit teacher action.** The teacher opens the section list for a classroom and taps "Start teaching" on a section, which unlocks it for that classroom and writes an audit entry. See FR-7.4.

**🟡 Q-29 — Where do wrong questions reappear?** Immediately, re-queued at the end of the **same** exercise session until correct? Or collected into a separate "My mistakes" area to redo later?
→ *Impacts:* whether an exercise set can be "completed" with wrong answers outstanding (FR-8.7 vs FR-8.8).
→ *Recommend:* **re-queued within the same session** — the set is not complete, and points are not committed, until every question has been answered correctly at least once. Points still reflect the *first* attempt (+5/+3).

**🟡 Q-30 — Leaderboard definition.** Total points all-time within the classroom, or per unit / per week? How are ties broken? Do students see each other's real names?
→ *Recommend:* all-time classroom total; ties broken by who reached the score first; real first name + last initial; top 3 highlighted; the student's own row always pinned visible.

**🟢 Q-31 — Extra gamification.** Streaks, badges, daily goals, or points only?
→ *Recommend:* points + classroom leaderboard only in v1.

**🟡 Q-32 — Offline mode.** Must students be able to do exercises with no internet and sync later? Given connectivity conditions this may matter a lot.
→ *Impacts:* significant client complexity (local DB, sync/conflict logic).
→ *Recommend:* **v1 = online-only with graceful errors**; cache unlocked content for reading (grammar/vocabulary) offline. Full offline sync as a v2 milestone.

## G. Teacher & admin features

**🟡 Q-33 — Teacher tools beyond progress.** Does the teacher need attendance, homework assignment, per-question answer review (seeing *which* questions a student got wrong), or private notes on a student?
→ *Recommend:* include **per-question answer review** (high value, low cost). Attendance and homework deferred to v2 unless you need them.

**🟢 Q-34 — Reports export.** Do admins need Excel/PDF export of progress?
→ *Recommend:* CSV export in v1, PDF later.

**🟢 Q-35 — Teacher notifications.** Your brief grants push to superadmin and admin only. Should a teacher be able to notify their own classroom?
→ *Recommend:* yes — it's the most-used case in practice.

**🟡 Q-55 — Feedback and Contact Us.** The Figma profile screen has a **Feedback** row and a **Contact Us** row. Neither has anything behind it: there is no feedback table, and `centers` stores name, city and address but no phone or email. Both rows are built and visually identical to the design, and for now both open a sheet telling the student to ask their teacher. What should they actually do?
→ *Recommend:* **Contact Us** shows the centre's phone — add `phone` and `email` to `centers`, editable by the centre admin. **Feedback** writes a row a centre admin can read in the panel, which is a small table plus one endpoint. Say the word and I will write the FR and build both; until then the rows stay honest rather than inventing an address.

## H. Platform, hosting & delivery

**🟡 Q-36 — Production hosting.** *Partly answered 2026-08-04, deployment method decided 2026-08-18:* development and testing run on the **local XAMPP machine**. The full deploy — a plain Ubuntu VPS, Apache + PHP 8.2 + MariaDB, no Docker, TLS via Let's Encrypt — is now written up end to end in [deploy/DEPLOY.md](../deploy/DEPLOY.md); the API's plain-LAMP conventions (everything environment-specific in `.env`) meant this was configuration, not a rewrite, as anticipated. **Still needed before launch:** an actual VPS provisioned and a real domain pointed at it — `DEPLOY.md` uses `YOUR_DOMAIN` as a placeholder throughout.

**✅ Q-37 — Push notification channel.** *Answered 2026-08-04:* **FCM + APNs are the primary channel.** The in-app notification inbox is still built as a secondary path — it costs almost nothing (the `notifications` and `notification_receipts` tables already exist for delivery tracking) and it guarantees a message is readable even if push is throttled or the user denied the OS permission. See FR-10.3.

**✅ Q-38 — Scale.** *Answered 2026-08-04:* **up to 100,000 students.** MySQL handles this comfortably, but it is large enough that two things become design constraints rather than afterthoughts: classroom leaderboards must not be computed by scanning `question_results` unbounded, and centre-wide admin views must aggregate incrementally. Q-3's purge-on-course-close keeps the hot table bounded. See §11.

**🟢 Q-39 — Store accounts.** Do you have an Apple Developer account ($99/yr) and a Google Play Console account ($25 one-off)? Will I be publishing, or handing you builds?

**✅ Q-40 — Admin panel technology.** *Answered 2026-08-04:* **React + Vite + TypeScript SPA**, built to static files and served by Apache alongside the PHP API. One build, role-gated navigation for admin vs superadmin. See FR-2.7.

**✅ Q-41 — AI provider & key.** *Answered 2026-08-04:* **Claude now, with Gemini and DeepSeek to be added later.** Generation goes through an `LlmProvider` interface so a provider is a config value, not a code change. Claude (`claude-opus-5` for generation, `claude-sonnet-5` for bulk/judge passes) is the only implementation wired up initially; Gemini and DeepSeek adapters are written against the same interface and activate when their keys are added to `.env`. All keys are server-side only. See FR-4.18.

**🟢 Q-42 — Device support.** Minimum iOS/Android versions? Tablet layouts? Dark mode?
→ *Recommend:* iOS 14+, Android 8+ (API 26), phone-first with tablets scaling, light mode only in v1 unless the Figma defines dark.

**🟢 Q-43 — Branding.** App display name, logo files, brand colours and fonts — I can pull most from Figma once access is granted, but I need the logo assets and the store listing name.

**🟡 Q-44 — Interface languages.** *Settled by Q-47:* **Turkmen and Russian only.** **Still open:** which is shown on first launch?
→ *Recommend:* a language picker on first launch rather than a guessed default. Proceeding on this unless corrected.

## I. Conflicts between the Figma design and the written brief

*Raised 2026-08-04 from a screenshot of the Figma file (splash, login,
home, grammar-guide, leaderboard, vocabulary, vocabulary-modal,
profile-settings, and three exercise-type screens). Each item is a place
where the design shows something the brief does not, or vice versa.
**None of these may be resolved by picking whichever seems more likely.***

**✅ Q-45 — Login identifier.** *Answered 2026-08-04:* **the teacher creates the credential using a phone number and a password.** The number is entered by the teacher at account creation, is the login identifier, must be unique, and is **never verified by SMS**. See FR-1.16.
→ *Sub-item, not blocking:* the number is not verified, so a teacher can assign one to a student who has no phone. Format is validated (`+993` + 8 digits) and uniqueness enforced; reachability is not.

**✅ Q-46 — Level naming.** *Answered 2026-08-04:* **names only** — Beginner, Elementary, Pre-Intermediate, Intermediate, Upper-Intermediate, Advanced. The "A1" in the design is placeholder; there is no CEFR code field.

**✅ Q-47 — Interface language.** *Answered 2026-08-04:* **no English.** Turkmen and Russian only. The "English" shown in the Figma profile screen is placeholder text. `users.interface_lang` stays `ENUM('tk','ru')`.

**✅ Q-48 — Questions per exercise.** *Answered 2026-08-04:* **7–12 governs** (4–5 for match pairs). The "3 of 5" in the design is placeholder. The exercise header and progress bar must therefore render counts up to 12, not a fixed 5.

**✅ Q-49 — Match pairs by synonym.** *Answered 2026-08-04:* **add both `synonym` and `antonym`**, giving six modes: `translation`, `definition`, `sentence_halves`, `question_answer`, `synonym`, `antonym`. See FR-4.21.
→ *Constraint carried into the pipeline:* both new modes need words inside the vocabulary ceiling on **both** sides of every pair, so they will legitimately be unavailable in early units where too few words have been taught. Per FR-4.20 they are then skipped and reported, never padded.

**✅ Q-50 — Study Time and Daily Streak.** *Answered 2026-08-04:* **both ship in v1**, overriding the points-only note in Q-31. Definitions I am proceeding with — correct me if either is wrong:
→ **Daily streak** = consecutive calendar days with at least one **completed** exercise set. The day boundary is **Asia/Ashgabat (UTC+5)**, not the device clock, so travelling or changing the phone's time cannot inflate it.
→ **Study time** = accumulated **active foreground seconds** on exercise, grammar and vocabulary screens. Time with the app backgrounded or idle does not count.

**✅ Q-51 — Bookmarked vocabulary.** *Answered 2026-08-04:* **ships in v1.** New `student_bookmarks` table; the vocabulary screen gets the All Words / Bookmarked tabs and a bookmark toggle in the word modal. See FR-6.3.

**🟡 Q-54 — Do non-students also log in by phone number?** Q-45 settled the student credential. Teachers share the same app and the same login screen, and admins/superadmin use the web panels. Is the phone-number scheme used for **every** role, or do staff accounts use a username?
→ *Recommend:* **same scheme for all roles** — one login form, one credential type, no branching. Proceeding on this unless corrected.

**✅ Q-52 — No `reorder` screen exists.** *Answered 2026-08-04:* **reorder stays in scope.** I design the missing screen to match the visual language of the three existing exercise screens (same header, `n of m` counter, instruction caption, primary action button). See FR-2.8.

**🟡 Q-53 — Progress counters.** Home shows **"13 / 40 Units completed"** and unit progress as **"7/12 exercises completed"**. Given FR-3.12 (content lives on sections, not units), is "40 Units" really 40 *sections*? And is an exercise counted complete per exercise set?

**✅ Confirmed by the design** — no action needed: vocabulary carries IPA (`/ˈfæm.ɪ.li/`) and audio playback, matching `vocabulary_items`; fill-in-the-blank uses a tapped word pool, matching FR-4.19; the leaderboard is a distinct screen; profile has no password-change control, matching FR-1.6.

---

## Answered

*(Answers get moved here with the date, and the corresponding `FR-*` gets written into [01-REQUIREMENTS.md](01-REQUIREMENTS.md).)*

| ID | Answer | Date | Requirement |
|---|---|---|---|
| — | Backend stack: **PHP + MySQL**, XAMPP for local dev | 2026-08-04 | — |
| Q-2 | Teachers/admins **can view** student passwords → dual storage (bcrypt for auth + AES-256-GCM for display), key outside DB, every reveal audit-logged, students only | 2026-08-04 | FR-1.10–1.12 |
| Q-12 | Books are **digital PDFs with selectable text** — no OCR stage | 2026-08-04 | FR-3.8 |
| Q-20 | **Instructions only** are bilingual (TM/RU); English content stays English | 2026-08-04 | FR-4.14 |
| Q-40 | Panels are a **React + Vite + TS SPA** on the PHP API | 2026-08-04 | FR-2.7 |
| Q-9 | Classroom targets a **book set** (Student's Book + Workbook pair) | 2026-08-04 | FR-3.10 |
| Q-13 | **No hardcoded series** — system is book-agnostic, AI uses only uploaded books | 2026-08-04 | FR-3.9 |
| Q-14 | AI proposes page ranges, **superadmin confirms manually** before any run | 2026-08-04 | FR-4.15 |
| Q-16 | Client **confirms** they hold rights to the source books | 2026-08-04 | — |
| Q-18 | Section scheme **fully superadmin-defined** (follows from Q-13) | 2026-08-04 | FR-3.9 |
| Q-21 | `match_pairs` supports **all modes** via `pair_mode` | 2026-08-04 | FR-4.16 |
| Q-27 | **Mandatory review gate**: `draft → in_review → published` | 2026-08-04 | FR-4.17 |
| Q-28 | Unlocking is an **explicit teacher action** per classroom | 2026-08-04 | FR-7.4 |
| Q-36 | **Local XAMPP** for dev/testing; server later. Host/domain still TBD | 2026-08-04 | — |
| Q-37 | **FCM + APNs primary**; in-app inbox retained as secondary | 2026-08-04 | FR-10.3 |
| Q-41 | **Claude now**, pluggable `LlmProvider` for Gemini + DeepSeek later | 2026-08-04 | FR-4.18 |
| Q-3 | **One classroom per student account**; disabled and purged when the course ends | 2026-08-04 | FR-1.13, FR-1.14 |
| Q-4 | Nothing carries over between courses (follows from Q-3) | 2026-08-04 | FR-1.14 |
| Q-5 | Teacher **can own several classrooms**. Multi-*centre* still open | 2026-08-04 | FR-1.15 |
| Q-17 | **500 MB** per uploaded file, filesystem storage, chunked upload | 2026-08-04 | FR-3.11 |
| Q-19 | Units `1,2,3`; sections `1A,1B,1C,2A…` varying per unit. **All content attaches to sections** | 2026-08-04 | FR-3.12 |
| Q-23 | Fill-blank is **tap-to-fill / tap-to-remove** word bank at every level | 2026-08-04 | FR-4.19 |
| Q-24 | **Never force an exercise type** — report unsupported types instead | 2026-08-04 | FR-4.20 |
| Q-38 | Scale target **up to 100,000 students** | 2026-08-04 | §11 |
| Q-47 | **No English interface** — Turkmen and Russian only; Figma's "English" is placeholder | 2026-08-04 | FR-2.6 |
| Q-48 | **7–12 questions** governs (4–5 match pairs); Figma's "3 of 5" is placeholder | 2026-08-04 | FR-4.11 |
| Q-52 | **`reorder` stays in scope**; missing screen to be designed to match | 2026-08-04 | FR-2.8 |
| Q-45 | Login is a **teacher-entered phone number** + password; no SMS verification | 2026-08-04 | FR-1.16 |
| Q-46 | Levels are **names only** (Beginner…Advanced); no CEFR code | 2026-08-04 | FR-3.13 |
| Q-49 | Six `pair_mode` values — **synonym and antonym added** | 2026-08-04 | FR-4.21 |
| Q-50 | **Daily streak and study time both ship**; Ashgabat day boundary, active foreground seconds | 2026-08-04 | FR-8.10, FR-8.11 |
| Q-51 | **Bookmarked vocabulary ships** | 2026-08-04 | FR-6.3 |
| — | Multi-classroom person → **one account per classroom, distinct login each**; duplicates rejected at creation | 2026-08-04 | FR-1.17 |
| — | **Gemini free-tier key supplied and active** (`LLM_PROVIDER=gemini`, `gemini-3.5-flash` / `gemini-3.5-flash-lite`). Verified live: constrained JSON + all 3 test rewrites on target. Claude and DeepSeek adapters still unwritten — a Claude.ai subscription is not usable as a server credential | 2026-08-04 | FR-4.18 |
| — | **Book PDFs to be supplied later.** Ingestion built and tested against a sample; real books drop in unchanged | 2026-08-04 | FR-3.8 |
| — | **AI content generation REMOVED.** Content is authored manually by the superadmin, 1:1 from the workbook, through the panel's editor. All four exercise types stay available manually; the workbook leans on fill-blank and match-pairs. Generation endpoints deleted; pipeline doc retired | 2026-08-07 | §4 note |
| — | **Student creation moved to the centre admin.** Teachers keep reveal/reset only. Enforced at the route and controller, not just hidden | 2026-08-07 | FR-1.4 |
| — | **2026-08-08 redesign decisions** (client, in answer to direct questions): **(a)** a child unit's 100% divides **equally between its sections** (4 sections → 25% each), and a section's 100% divides **equally between its questions**; **(b)** a section's shown result and everything derived from it use the **average of all attempts**; **(c)** the Exam Quiz contains **all** quiz-eligible questions of the child unit, **in source order, no shuffle**; **(d)** existing exercises are **dropped** in the migration — content re-enters under the new structure (editor or xlsx) | 2026-08-08 | §13 |
| — | **2026-08-09 «1:1 as designed» rulings** (client instruction "Do 1:1 design as designed in Figma"): **(j)** **Fill letter space** ships as the fifth exercise type — type missing letters into per-letter boxes; **(k)** **English becomes the third interface language** (the design's language modal lists and selects it, superseding Q-47's TK/RU-only); **(l)** the teacher **add-student frame is NOT implemented** — the client's newer written rule (FR-1.4/FR-13.10, admin creates students) overrides the stale frame; **(m)** exercise progress amber is `#FFC301` per the exported pixels | 2026-08-09 | §13 |
| — | **2026-08-08 second batch:** **(e)** the teacher's password **reveal is removed** — credentials are the centre admin's business only; **(f)** the admin can **view** current teacher passwords, not just reset them — staff passwords become recoverable like students' (client chose this knowing a DB leak would then expose teacher accounts; superadmin→admin passwords stay reset-only until said otherwise); **(g)** leaderboard pool is the **classroom**, exact ties ordered by **who reached the score first**; **(h)** xlsx imports land as **draft** behind the publish gate; **(i)** **vocabulary is editable and uploadable** like all content — full column set in the xlsx | 2026-08-08 | §13 |
