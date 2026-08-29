# Dana — Functional Requirements

> **Status:** DRAFT. Every requirement below is stated in the client
> brief. Anything *not* stated is recorded in
> [02-OPEN-QUESTIONS.md](02-OPEN-QUESTIONS.md), never guessed here.
> **Last updated:** 2026-08-08
>
> **⚠ 2026-08-08 REDESIGN — §13 supersedes on conflict.** The client
> restructured the product: a new hierarchy (parent unit → child unit →
> typed sections), everything unlocked, the point system removed in
> favour of percentages with repeatable averaged attempts, an Exam Quiz
> per child unit, three question media types, and xlsx import/export.
> Where an older FR (notably §7 unlocking and §8 points) contradicts
> §13, **§13 wins**; the older sections are kept for history.

---

## 1. Accounts & roles

| ID | Requirement |
|---|---|
| FR-1.1 | Superadmin creates **centres** and their **admin** accounts, with a pre-set login and password — and nothing else. They cannot create teachers, classrooms or students. *(client decision 2026-08-04)* |
| FR-1.2 | Admin creates **teacher** accounts with a pre-set login and password, within their own centre only. The centre comes from the admin's token, so `center_id` in the request body is ignored. |
| FR-1.3 | Admin creates **classrooms**, assigning: a teacher, a level, and a teaching book (dropdown). Own centre only. |
| FR-1.4 | The **centre admin** creates student accounts with a pre-set login and password, enrolling each into one of their centre's classrooms. Teachers do not create students — they keep the reveal/reset paths (FR-1.7, FR-1.10). *(client decision 2026-08-07, replaces "teacher creates students")* |
| FR-1.5 | Students log in with credentials created by their centre admin. |
| FR-1.6 | Students **cannot** change their own login or password. |
| FR-1.7 | A teacher can change login/password for students in their own classrooms. |
| FR-1.8 | A centre admin can change login/password for any student in that centre. |
| FR-1.9 | There is no self-registration and no email-based signup. |
| FR-1.10 | A teacher can **view** the current password of students in their own classrooms. A centre admin can view it for any student in their centre. *(Q-2)* |
| FR-1.11 | Only **student** passwords are recoverable. Superadmin, admin and teacher passwords are hash-only and can only be reset, never viewed. *(Q-2)* |
| FR-1.12 | Every password reveal writes an `audit_log` entry recording who viewed whose credential and when. Reveal is an explicit action, never shown in list views. *(Q-2)* |
| FR-1.13 | A student account belongs to **exactly one classroom** for its entire life. There is no multi-classroom membership. *(Q-3)* |
| FR-1.14 | When a course finishes, its student accounts are **disabled** and their progress data is **not retained**. A student joining a new course receives a new account. *(Q-3, Q-4)* |
| FR-1.15 | A teacher may own **several classrooms**. *(Q-5)* |
| FR-1.17 | A person attending **more than one classroom** gets a **separate student account per classroom**, each created by the centre admin with its **own distinct login and password**. Logins are unique system-wide, so at most one of a person's accounts can carry their real phone number; the panel rejects a duplicate at creation and suggests a free variant. *(Q-3, Q-45)* |
| FR-1.16 | The login identifier is a **phone number**, entered by the teacher when creating the account, together with a password. It must be unique and format-valid (`+993` + 8 digits). It is **never verified by SMS**, so a teacher may assign a number to a student who has no phone. *(Q-45)* |

## 2. Applications

| ID | Requirement |
|---|---|
| FR-2.1 | One Flutter mobile app, iOS + Android, used by **students and teachers only**. |
| FR-2.2 | Admin uses a **separate web panel**. |
| FR-2.3 | Superadmin uses a **separate web panel** with full control over all app content. |
| FR-2.4 | Student UI follows the supplied Figma design. |
| FR-2.5 | Teacher UI is designed to match the student UI's visual style (no Figma supplied). |
| FR-2.6 | App interface language is Turkmen or Russian. |
| FR-2.7 | Both panels are one React + Vite + TypeScript SPA served as static files by Apache, using the same PHP API. Navigation and routes are gated by the JWT role claim. *(Q-40)* |
| FR-2.8 | The Figma file has no `reorder` exercise screen. It is designed to match the three existing exercise screens — same header bar, `n of m` counter, instruction caption and primary action button. *(Q-52)* |
| FR-2.9 | Exercise screens must render question counts up to **12**, not the fixed "3 of 5" shown in the mockups. *(Q-48)* |

## 3. Content structure (superadmin panel)

| ID | Requirement |
|---|---|
| FR-3.1 | Hierarchy is `English → Levels → Units → { Vocabulary, Grammar, Exercise types }`. |
| FR-3.2 | Default levels: Beginner, Elementary, Pre-Intermediate, Intermediate, Upper-Intermediate, Advanced. |
| FR-3.3 | Superadmin can add, rename, edit and delete **levels**. |
| FR-3.4 | Superadmin can add/upload, rename, edit and delete **books**. At least one book must exist. |
| FR-3.5 | Each level has a Student's Book and a Workbook. |
| FR-3.6 | Units are subdivided into sections (`1A`, `1B`, …). Content attaches to sections. |
| FR-3.7 | Superadmin uploads **vocabulary** for each unit manually, one entry at a time. |
| FR-3.8 | Books are supplied as **digital PDFs with selectable text**. No OCR stage is required. *(Q-12)* |
| FR-3.9 | The system is **book-agnostic**. No book series, unit count, or section naming scheme is hardcoded. The superadmin defines the structure for whatever they upload, and the AI works only from uploaded books. *(Q-13, Q-18)* |
| FR-3.10 | Books are grouped into **book sets** (a Student's Book + Workbook pair). A classroom targets a book set, not an individual book. *(Q-9)* |
| FR-3.11 | Maximum uploaded file size is **500 MB**. Files are stored on the filesystem outside the web root; uploads are chunked. *(Q-17)* |
| FR-3.13 | Levels are identified by **name only** — Beginner, Elementary, Pre-Intermediate, Intermediate, Upper-Intermediate, Advanced. There is no CEFR code field; the "A1" in the Figma mockup is placeholder. *(Q-46)* |
| FR-3.12 | Units are numbered `1, 2, 3…`; sections are `1A, 1B, 1C, 2A, 2B, 3A…`, and **the number of sections per unit varies**. **Grammar, vocabulary and exercises all attach to the section**, and all unlock together when the teacher begins that lesson. A unit is a container only and holds no content of its own. *(Q-19)* |

## 4. Content authoring

> **Superseded 2026-08-07 (client decision): AI generation is REMOVED.**
> Content is authored **manually by the superadmin, copied 1:1 from the
> workbook** — no transformation, no change ratio, no model. The
> generation requirements below (FR-4.1–FR-4.11, FR-4.15, FR-4.18–4.21)
> are retained for history only and no code path implements them.
> What remains in force:
>
> - **FR-4.12 / FR-4.13** — full manual add/edit/delete of vocabulary,
>   grammar and exercises, Turkmen left / Russian right.
> - **FR-4.17** — the review gate: draft → published, students see only
>   published content.
> - **All four exercise types stay available** for manual authoring. The
>   workbook leans on *fill the blank* and *match pairs*; *test* and
>   *reorder* remain usable whenever the superadmin wants them (FR-4.20's
>   spirit survives: nothing is padded or forced).

| ID | Requirement |
|---|---|
| FR-4.1 | Superadmin triggers generation for a specific level + unit section (e.g. Beginner 1A). |
| FR-4.2 | The AI reads **only** that level's books, **only** the pages belonging to that section. |
| FR-4.3 | The AI must **not** invent questions from scratch. It transforms existing book sentences. |
| FR-4.4 | Each source sentence is changed by **20–25%** — no more, no less. |
| FR-4.5 | Transformation must preserve the source's grammar, vocabulary flow and logic. |
| FR-4.6 | Only grammar taught **up to and including** the current unit may appear. Future grammar is forbidden. |
| FR-4.7 | The current section's vocabulary must be the focus. |
| FR-4.8 | Generated difficulty must match the level. |
| FR-4.9 | Exercises derived from **speaking**, **reading-topic**, or **listening** material are excluded. |
| FR-4.10 | Exercise types: `reorder`, `match pairs`, `multiple choice (1 correct + 3 incorrect)`, `fill in the blank`. |
| FR-4.11 | Question count per set: **7–12**, except `match pairs` which is **4–5**. |
| FR-4.12 | Superadmin can add, edit and delete individual exercises and whole exercise types. |
| FR-4.13 | Every question is editable, with the **Turkmen version on the left** and the **Russian version on the right**, with add / delete / edit / save actions. |
| FR-4.14 | What is bilingual is the **instruction, hint and feedback text** only. The English content being practised (sentences, options, word banks, blanks) stays in English. *(Q-20)* |
| FR-4.15 | The AI proposes each section's book page ranges from the contents page; the **superadmin must confirm them manually**. A generation run cannot start against unconfirmed ranges. *(Q-14)* |
| FR-4.16 | `match pairs` supports four `pair_mode` variants: `translation` (English ↔ TM/RU), `definition` (English ↔ English definition), `sentence_halves`, and `question_answer`. *(Q-21)* |
| FR-4.21 | Two further `pair_mode` variants: **`synonym`** and **`antonym`** — six in total. Both require every word on **both** sides of a pair to sit inside the vocabulary ceiling, so they are legitimately unavailable in early units and are then skipped under FR-4.20. *(Q-49)* |
| FR-4.17 | All generated content passes `draft → in_review → published`. Students can only ever read `published` content. *(Q-27)* |
| FR-4.18 | Generation runs behind an `LlmProvider` interface. **Claude** is the only provider enabled initially; **Gemini** and **DeepSeek** adapters are implemented against the same interface and activate once their keys are supplied. Switching provider is a config change, never a code change. *(Q-41)* |
| FR-4.19 | `fill in the blank` is answered by **tapping a word from the word bank** to fill a blank, and **tapping a filled word to remove it**. Word bank at every level; no typed input in v1. A question submits only once every blank is filled. *(Q-23)* |
| FR-4.20 | An exercise type is **never forced**. If the source pages cannot properly support a type, it is **reported to the superadmin, not fabricated**. A section with fewer than four types is valid. *(Q-24)* |

## 5. Grammar menu

| ID | Requirement |
|---|---|
| FR-5.1 | Each unit section has a grammar explanation in the grammar menu. |
| FR-5.2 | Explanations exist in Turkmen and Russian; the app shows the one matching the interface language. |
| FR-5.3 | The AI derives the explanation from the book's own grammar section for that unit. |
| FR-5.4 | The AI **simplifies** it and adds **more examples** than the book gives. |

## 6. Vocabulary menu

| ID | Requirement |
|---|---|
| FR-6.1 | Vocabulary is browsable per unit section. |
| FR-6.2 | Vocabulary is authored by the superadmin, not generated. |
| FR-6.3 | Students can **bookmark** vocabulary items. The vocabulary screen has *All Words* and *Bookmarked* tabs, and the word detail modal has a bookmark toggle. *(Q-51)* |

## 7. Progressive unlocking

| ID | Requirement |
|---|---|
| FR-7.1 | Grammar, vocabulary and exercises are visible only for sections already taught. |
| FR-7.2 | When the teacher begins teaching a section, that section unlocks for that classroom. |
| FR-7.3 | Unlocking is cumulative — previously unlocked sections stay open. |
| FR-7.4 | Unlocking is an **explicit teacher action**: the teacher taps "Start teaching" on a section in a classroom's section list. Each unlock is recorded with actor and timestamp. *(Q-28)* |

## 8. Exercises, points & progress

| ID | Requirement |
|---|---|
| FR-8.1 | Each exercise set shows a progress bar that fills as questions are answered. |
| FR-8.2 | Correct answer → **+5 points**. |
| FR-8.3 | Incorrect answer → **+3 points**. |
| FR-8.4 | Points are awarded **once per question only**, on first attempt. |
| FR-8.5 | Worked example: 10 questions, 8 correct + 2 incorrect = `8×5 + 2×3` = **46 points**. |
| FR-8.6 | Re-doing a completed exercise set awards **no** additional points. |
| FR-8.7 | Points are sent to the server **only when the exercise set is completed**. Incomplete sets award nothing. |
| FR-8.8 | Questions answered incorrectly reappear until answered correctly. |
| FR-8.9 | Repeat attempts on a question award **no** points. |
| FR-8.10 | **Daily streak** = consecutive calendar days on which the student completed at least one exercise set. The day boundary is **Asia/Ashgabat (UTC+5)**, evaluated server-side, so changing the device clock cannot inflate it. *(Q-50)* |
| FR-8.11 | **Study time** = accumulated **active foreground seconds** on exercise, grammar and vocabulary screens. Backgrounded and idle time is excluded. *(Q-50)* |
| FR-8.12 | Exercise sets are shown to the student as **"Exercise 1, Exercise 2 …" numbered across the whole unit**, continuing from one section into the next, and **restarting at each new unit**. Numbering spans every section of the unit — including not-yet-unlocked ones — so an exercise never renumbers when the teacher opens an earlier section. |
| FR-8.13 | A section may hold **several exercise sets of the same type**. A second batch of multiple choice is additional practice, not a conflict. |

## 9. Tracking & leaderboard

| ID | Requirement |
|---|---|
| FR-9.1 | A teacher tracks the progress of each student in each of their classrooms. |
| FR-9.2 | Each classroom has a leaderboard ranking its students. |
| FR-9.3 | A centre admin can see the progress of all students across the centre. |

## 10. Push notifications

| ID | Requirement |
|---|---|
| FR-10.1 | **The superadmin does not send notifications.** Announcements belong to the centre that runs the classes, so there is no all-users broadcast. Enforced server-side, not only hidden in the panel. *(client decision 2026-08-04, replaces the original "superadmin → all users")* |
| FR-10.2 | Admin can push a notification to **their own centre** — every member of it, students and teachers. The centre is taken from the token, never from the request, so an admin cannot address another centre. |
| FR-10.3 | **FCM (Android) and APNs (iOS) are the primary delivery channel.** An in-app notification inbox is also maintained, so a message stays readable if push is throttled or OS notification permission was denied. *(Q-37)* |

## 12. Approved defaults

*2026-08-04 — every 🟡 recommendation approved as written; 🟢 items
proceed on their documented recommendation and remain changeable.*

| ID | Requirement | From |
|---|---|---|
| FR-12.1 | A teacher belongs to **exactly one centre**. | Q-5 |
| FR-12.2 | A student may hold **one active session**; logging in elsewhere ends the previous one. Teachers are unrestricted. | Q-6 |
| FR-12.3 | Accounts are **deactivated, never hard-deleted**, except by the course-closure purge in FR-1.14. | Q-8 |
| FR-12.4 | Generation pools the Student's Book and Workbook pages into **one run** per section; the source book is recorded per question. | Q-15 |
| FR-12.5 | `reorder` scrambles **words into one sentence**, 5–12 words, exactly one valid ordering. | Q-22 |
| FR-12.6 | Questions appear in authored order; **multiple-choice options are shuffled** on each attempt. | Q-26 |
| FR-12.7 | A wrong answer **re-queues the question at the end of the current exercise**. The set is not complete — and no points are committed — until every question has been answered correctly at least once. Points still reflect the **first** attempt (+5/+3). | Q-29 |
| FR-12.8 | Leaderboard = all-time points within the classroom; ties broken by **who reached the score first**; first name + last initial; top 3 highlighted; the student's own row always pinned into view. | Q-30 |
| FR-12.9 | v1 is **online-only for exercises**, with grammar and vocabulary for unlocked sections **cached for offline reading**. Full offline sync is a v2 milestone. | Q-32 |
| FR-12.10 | Teachers get **per-question answer review** — which questions a student got wrong, not just totals. Attendance and homework are deferred. | Q-33 |
| FR-12.11 | Admins can **export progress as CSV**. PDF export deferred. | Q-34 |
| FR-12.12 | Teachers can send notifications to **their own classrooms**. | Q-35 |
| FR-12.13 | On first launch the app shows a **language picker** (Turkmen / Russian). No default is guessed. | Q-44 |
| FR-12.14 | The Home/Profile course-overview card shows the **student's own completion**: the ring is completed exercises over published exercises, the caption counts fully-completed units ("2 / 12 юнитов завершено"). It never reflects how far the teacher has unlocked — a student who has done nothing sees 0%. Exercise counters count exercise **sets**. *(client correction 2026-08-06 — replaces the earlier sections-unlocked counter)* | Q-53 |
| FR-12.15 | **All roles** log in with the phone-number scheme of FR-1.16. One login form, one credential type. | Q-54 |
| FR-12.16 | Admins may **reassign a classroom to another teacher** and **move a student between classrooms**, with an audit entry. | Q-11 |
| FR-12.17 | A classroom has a name, start date and optional capacity. No timetable in v1. | Q-10 |
| FR-12.18 | Content is **global** across all centres; there are no per-centre content variants. | Q-25 |
| FR-12.19 | The first superadmin is created by a **one-time CLI seed script**. There is no UI path to create another. | Q-7 |
| FR-12.20 | Target platforms: **iOS 14+, Android 8+ (API 26)**, phone-first with tablets scaling. Light mode only unless the design defines dark. | Q-42 |

## 11. Non-functional

| ID | Requirement |
|---|---|
| NFR-1 | The system supports **up to 100,000 student accounts**. *(Q-38)* |
| NFR-2 | Classroom leaderboards must not require an unbounded scan of `question_results`. Ranking is served from a per-classroom aggregate maintained on exercise completion. |
| NFR-3 | Centre-wide admin progress views aggregate incrementally, never by scanning every student's results on page load. |
| NFR-4 | No code may assume `localhost`. API base URL, database credentials, `APP_CRED_KEY`, LLM keys and storage paths all come from configuration. *(Q-36)* |
| NFR-5 | Points are computed server-side; the client submits answers, never scores. |

---

## 13. Redesign 2026-08-08 — hierarchy, progress, roles, data

Stated by the client on 2026-08-08; ambiguities resolved by direct
answer the same day (see 02-OPEN-QUESTIONS). Supersedes §7 and §8
entirely, and amends §1, §3, §4 and §9 where they conflict.

### Structure

| ID | Requirement |
|---|---|
| FR-13.1 | Hierarchy: **level → parent unit → child unit → section → exercise → question**. Parent units (Unit 1, Unit 2…) exist as containers only and have **no function for now**. Child units are 1A, 1B… |
| FR-13.2 | A section has a **type**: `grammar`, `vocabulary`, `listening` or `quiz` (Exam Quiz). Sections are **optional** — a child unit shows exactly the sections the superadmin added, no placeholders. |
| FR-13.3 | **Everything is unlocked.** Any student may open any unit and any section at any time. There is no unlock action, no gating, no lock state anywhere. |
| FR-13.4 | The **Exam Quiz** of a child unit contains **all questions flagged quiz-eligible** in that unit's other sections, **in source order, without shuffle**. It has no questions of its own. |

### Attempts & progress

| ID | Requirement |
|---|---|
| FR-13.5 | **Nothing is saved mid-section.** Answers persist only when the section is completed; quitting mid-way discards the attempt and the student restarts from question 1. |
| FR-13.6 | Sections (Exam Quiz included) are **repeatable without limit**. The shown result is the **average of all completed attempts**, and the full attempt history is visible. |
| FR-13.7 | **The point system is removed completely.** The only measure anywhere is the percentage of correctly answered questions. A wrong answer earns nothing. |
| FR-13.8 | Percentage weighting divides **equally at every layer**: a section's 100% splits equally across its questions; a child unit's 100% splits equally across its sections (4 sections → 25% each, 3 → 33.3%…); a level's 100% splits equally across its child units. |
| FR-13.9 | Displayed metrics: **Level completion progress** (how much of the level's content has been completed at least once), **child-unit success rate** (per FR-13.8 from section averages), and the **leaderboard number** = the level's correct-answer percentage **× 100** (0–10 000), so small gaps read as meaningful. Leaderboard remains per classroom. |

### Roles

| ID | Requirement |
|---|---|
| FR-13.10 | **Teacher** is read-only: sees their classes, their students and their students' results. No student creation, no unlocking (gone with FR-13.3), no password authority. |
| FR-13.11 | **Centre admin** creates classes, teachers and students, and **creates/edits every password in the centre** — teachers' and students' alike. |
| FR-13.12 | **Superadmin** creates/edits/deletes centres and centre admins, and owns all content: levels, units, sections (choosing the section type), exercises and questions. |

### Questions & data

| ID | Requirement |
|---|---|
| FR-13.13 | A question has a **media type**: `text`, `audio` or `picture`. Audio and picture UIs are not in Figma and are designed in-app to match the design system. |
| FR-13.14 | Every question carries an **`eligible for exam quiz`** flag (yes/no), settable in the editor and in xlsx. |
| FR-13.15 | The superadmin can **import and export content as .xlsx** — the whole database, one level, or one unit. The sheet is plainly editable and includes at minimum: exercise type, question type, and quiz eligibility. Import validates before writing and reports precise row-level errors. |
| FR-13.16 | Migration: existing exercises are **dropped**, per the client's explicit choice. Content re-enters through the editor or xlsx import. Entered **vocabulary and grammar are preserved**, repointed into auto-created Vocabulary/Grammar sections of their child units. |
| FR-13.17 | The teacher's **password reveal is removed**. Credentials are handled solely by the centre admin. The reveal path becomes admin-only, still audited and rate-limited. |
| FR-13.18 | The centre admin can **view** current teacher and student passwords, and set new ones. Staff passwords therefore become recoverable (client decision, security trade-off stated and accepted). Login still never decrypts — bcrypt comparison only. Superadmin→admin passwords remain reset-only. |
| FR-13.19 | Leaderboard pool is the **classroom**; equal scores rank by **who reached theirs first**. |
| FR-13.20 | xlsx imports land as **draft** behind the publish gate (FR-4.17); nothing reaches a student straight from a spreadsheet. Vocabulary is a first-class part of the sheet: term, transcription, both translations, example, section. |
| FR-13.21 | **Fill letter space** is the fifth exercise type (per the design, 2026-08-09): a sentence with partially hidden words; the student types the missing letters into one box per letter using the keyboard. Authored as plain text with `{word}` marks plus a reveal count; graded case-insensitively per blank. |
| FR-13.22 | **English is the third interface language** alongside Turkmen and Russian (design's language modal, 2026-08-09; supersedes Q-47/FR-2.6). Content language remains English; every UI string carries tk/ru/en. |
| FR-13.23 | The design file's teacher **add-student** frame is intentionally not implemented — FR-1.4/FR-13.10 (admin creates students) is the newer explicit rule and the server enforces it. |
| FR-13.24 | Vocabulary **pronunciation speaks through the device's system text-to-speech** (client request, 2026-08-13): the engine, voice and rate configured in Android settings are used as-is — no recordings, no network, no bundled engine. An English voice is requested when the system has one; otherwise the system default reads the word. |
| FR-13.25 | **Changing the phone's system language re-applies to the app** without a restart (client request, 2026-08-13), live and across launches. Only tk/ru/en switch it; other system languages leave the current choice. An explicit in-app language pick still sticks until the *system* language next changes, and the first-run picker still asks rather than guessing (FR-12.13). |
| FR-13.26 | **The grammar guide is removed from the product** (client decision, 2026-08-13): no guide screens or profile entry in the app, no `/me/grammar` endpoints, no explanation editor in the panel. A grammar *section* remains purely a practice module (exercise sets + questions). `grammar_explanations` data is retained dormant in the database; the per-section explanation endpoint stays for a possible future in-player display. |
| FR-13.27 | **The superadmin edits anything in their panel** (client, 2026-08-13): centres are editable and deletable (deletion refused while active courses exist — FR-1.14 stays the only way to end a course; an empty centre deactivates with its remaining accounts), centre admins are removable (deactivated + sessions revoked, audit-logged), units and child units are editable in place. |
| FR-13.28 | The superadmin panel has **no Прогресс page** (client, 2026-08-13) — metrics belong to centre admins. |
| FR-13.29 | **One book per level** (client, 2026-08-13): the «Учебники» management card and every book-set picker are gone; a classroom takes its level's book set automatically, and creating a level auto-creates its default book set. `book_sets` stays in the schema; page ranges bind to the level's book without a selector. |
| FR-13.31 | **The teacher app follows the Figma "Teacher" section 1:1** (full export 2026-08-13; the earlier partial export missed it): no bottom nav — the teacher home is the "My Classes" list (greeting header, hamburger), settings live in a **left drawer** (`home-screen-1`), the classroom detail keeps Students/Ranking tabs with the podium ranking (`unit-vocabulary-screen-2`), a unit row in Student Detail opens the **Unit progress** screen (`unit-vocabulary-screen-3`: unit overall + per-module tries/averages + Unit Quiz card, backed by `GET /teacher/students/{id}/units/{cu}/progress`, same FR-13.8 split as every other surface). Read-only as ever (FR-13.10); the stale `student-detail-more` features stay out. |
| FR-13.30 | ~~xlsx export/import, four generic sheets~~ **Superseded by §14** (2026-08-13, same day): the client supplied canonical content files and the import/export format became exactly those files. All-or-nothing transactions, draft forcing, and the no-student-data rule carry over unchanged. Route paths still carry no `.xlsx` extension. |

## 14. Content model v2 — the client's files are the structure (2026-08-13)

Full contract: [docs/06-CONTENT-V2.md](06-CONTENT-V2.md). Decisions taken with the client the same day.

| ID | Requirement |
| --- | --- |
| FR-14.1 | **The five client xlsx files define the content database structure exactly** (`Listening`/`Grammar`/`Vocabulary` question sheets, `Wordlist`, `UnitQuiz`). The superadmin uploads these files in the panel and every row lands losslessly: `Question ID`→external code (upsert key), Topic, Subtopic, English-only Rule and Answer Description (client decision — no tk/ru columns), `Eligble`→quiz eligibility. Export emits the same shapes back. |
| FR-14.2 | **Question/option media by note**: `[audio:/image:/text:]` parts where audio/image arrive as author NOTES; the superadmin panel shows the note beside an upload control and the real file is uploaded there. A question with any missing media file is **auto-hidden** from students, quiz pools and every percent denominator until its media is uploaded (client decision). Uploaded media is preserved across re-imports while the note is unchanged; a changed note clears the file for re-upload. |
| FR-14.3 | **Unit Quiz = one fixed random draw for everyone** (client decision): `Unit Quiz Target` per skill (e.g. 7 vocabulary + 8 grammar + 5 listening) drawn once from the eligible servable pools, stored, identical for every student and attempt; self-heals when a drawn question stops being servable; superadmin can redraw. Pools smaller than the target serve the whole pool. Supersedes FR-13.4's all-eligible/source-order rule for units that have targets. |
| FR-14.4 | **Authoring syntaxes**: Test rows store option A as correct and the app NEVER shows file order — options are shuffled per serve and answered by original index; `ReOrder` stores only the correct sentence `{{tok}} {{tok}}` and the app receives the tokens pre-shuffled; `LetterSpace` hides exactly the letters inside `<>` (`{{S<e>ve<n>}}`), anywhere in the word; `FillBlank` stores `{{correct~wrong~…}}` with the first entry correct and the bank shuffled at serve. Hidden letters, answer indices and correct orders never reach the client before grading. |
| FR-14.5 | **Books removed from the product entirely** (client decision): no book upload, no book sets, no page ranges — routes, panel UI and classroom attachment all gone; content arrives only via manual authoring or the FR-14.1 files. Replaces FR-3.10/FR-3.11/FR-4.15 and FR-13.29's book-set remnants; dormant tables stay in the schema. |
| FR-14.6 | `Wordlist` vocabulary is `Category` + `Type` (word/phrase) + English/Turkmen/Russian; IPA, part-of-speech and example are not part of v2 and the app hides them when absent. |

## 15. App polish — 2026-08-14 client requests

| ID | Requirement |
| --- | --- |
| FR-15.1 | **"Delete Account" is present but never deletes** (client, 2026-08-14): the profile settings list carries a red Delete-Account row and a confirm dialog (`profile-language` frame, "You can't restore your data progress"); confirming only signs the student out — it does not touch the account or its progress. Purging a student stays the centre admin's action on course closure (FR-1.14), so the app offers no self-serve deletion and no client route deletes an account. |
| FR-15.2 | **No notification bell on the home header** (client, 2026-08-14): the `home-screen-completed` greeting spans the full width. The in-app inbox (FR-10.3) is still reachable from Profile → Notifications; only the home shortcut is gone. |
| FR-15.3 | **Bookmarking a word is silent and instant** (client, 2026-08-14): the bookmark toggles optimistically in place with a brief "Bookmarked / Removed" toast — no list reload or screen flash. On the Bookmarked tab an un-saved word leaves the list immediately; a failed call reverts the glyph and shows the error. |
| FR-15.4 | **The vocabulary word sheet has no empty pronunciation band when there is no IPA** (client, 2026-08-14; refines FR-14.6 / FR-13.24): the PRONUNCIATION heading and its section are dropped entirely, and the tap-to-hear system-TTS button rides inline beside the translation instead — so the sheet stays compact with no gap. The full section (label + IPA + button) returns whenever a word carries written IPA. |
| FR-15.6 | **The word card shows the Wordlist Category and Type as two chips** (client, 2026-08-14; completes FR-14.6): design `vocabulary-screen-modal` — the category in blue (`status/info`) and the word/phrase type in amber (`accent`), uppercase, under the translation. The type is localised (Word/Слово/Söz, Phrase/Фраза/Söz düzümi); the category is the author's own string, shown verbatim. Either chip is omitted when its column was empty — never padded with a placeholder. The three vocabulary payloads (`/me/dictionary`, `/units/{id}/vocabulary`, `/teacher/units/{id}/vocabulary`) all carry `category` and `word_type`. **MEANING is not part of v2** — the Wordlist has no definition column, so the block stays hidden rather than being filled from another field. |
| FR-15.5 | **A live interface-language change re-resolves the mounted tab screens** (2026-08-14; makes FR-13.25 hold on screen, not only on next launch): the bottom-nav shell subscribes to the language state and hands its `IndexedStack` fresh children on change, so the already-built Main/Vocabulary/Ranking/Profile tabs switch language in place. Previously only freshly-pushed routes updated, leaving a mounted tab (e.g. the vocabulary list showing Turkmen while the sheet on top showed Russian) stale in the old language. The tab index and each screen's own state are preserved. |
| FR-15.7 | **Units are named manually — no auto-numbering in the panel** (client, 2026-08-20): the create-unit form is a single free-text name (`units.name`, ≤120 chars) with no number field and no prefill; the internal `number` survives only as a hidden ordering/uniqueness key (auto `max+1`). Child units gain an optional free display label (`unit_sections.label`, ≤32) beside their code. **Fallback contract:** an explicit name/label is the display identity *verbatim*, everywhere (panel pages, student outline, dictionary word-card chip, teacher screens); a null keeps the legacy composition (`Юнит {number}` / `{number}-{code}`) byte-identical, so pre-existing content renders unchanged. Parent-unit names are panel-facing only — students see child-unit labels (FR-13.1 containers). xlsx import/export compositions are untouched: there number+code are join keys, not display. The app hides the word-card's numeric unit tile when a label has no leading digits rather than padding a meaningless "00". |
| FR-15.8 | **Everything created in the panel can be deleted** (client, 2026-08-20): six new endpoints — parent units, child units, levels (superadmin); teachers, students, classrooms (admin, centre-scoped; superadmin). Semantics: unit/child-unit deletes cascade all content and progress beneath them in one transaction, refusing with `attempts_exist` (409) when student attempts would be lost until the panel re-confirms with `force=1` — the same handshake typed-section delete already used. Level delete refuses while any classroom references the level (FR-1.14 course closure stays the only sanctioned way to end a course; `force` does not override this). Teacher delete deactivates + revokes tokens (mirror of admin delete) and refuses while active classrooms are theirs, naming them. Student delete is a **hard purge** of the account and all its progress rows — an individual-scale sibling of FR-1.14, admin-initiated. Classroom delete refuses while active students are enrolled; closed-course leftovers are detached, not destroyed. Every delete writes an FR-1.12 audit row. |
| FR-15.9 | **Content editor lists are expandable lesson by lesson** (client, 2026-08-20): in a child unit's editor each exercise set is a collapsed accordion — header with name, type, status and question count; the question list renders only when opened. The vocabulary word list collapses the same way («Слова · N»). Open state survives saves and background reloads. |
| FR-15.11 | **Quiz eligibility is opt-out, not opt-in, and togglable in bulk** (client report, 2026-08-27: targets 5/5/5 drew nothing — «в наличии: 0» — because no hand-authored question had the «в квизе» checkbox ticked): new questions created in the panel default to eligible; each exercise set carries «Все — в квиз» / «Убрать все из квиза» (`POST /manage/sets/{id}/quiz-eligible`), flipping every active question at once and self-healing the child unit's stored draw. On import the eligibility column stays authoritative when PRESENT; a sheet without one imports everything eligible (the client's 2026-08-27 files dropped the column). Those files also renamed `Answer Description` to **`Feedback`** — accepted as a first-class header alias, same field (FR-14.1). |
| FR-15.12 | **The MC editor shows and preserves the true correct answer** (client report, 2026-08-27: uploaded audio for "7", the answer silently became "3"): the v2 multiple-choice form used to hardcode `answer: 0` on save — the FILE convention (option A = correct) applied against the DB's SHUFFLED stored order (FR-14.4) — so *any* re-save (uploading media, ticking «в квизе») moved the correct answer to whichever option was displayed first. The form now renders a radio on the stored correct option and saves the payload's real index; stored option order never changes on edit, keeping `q{id}-opt{i}` media bindings stable. Scrambled rows are repaired by re-importing the same xlsx: `reconcileMcOrder` remaps the file's correct option onto the stored order and uploaded media is carried (FR-14.2). |
| FR-15.13 | **Practice modules re-queue wrong answers until solved** (client, 2026-08-27): in Grammar / Listening / Vocabulary a wrongly answered question returns to the end of the run and keeps returning until answered correctly — the run finishes only when everything has been solved. **Scoring is first-try** (client decision, same day): only the first answer per question is buffered and submitted, so retries never change the percent, the leaderboard, or any server-side number — the server flow (FR-13.5 buffered submit, FR-8.4 single scoring) is untouched. The **Unit Quiz stays a one-pass exam** (client decision, same day). A re-served retry re-shuffles the option list / word bank locally — display only, safe because answers travel as the option's original index or text (FR-14.4), so reordering can never change which option is correct. |
| FR-15.14 | **Teacher delete is a hard delete, and admins edit teacher details** (client, 2026-08-27: «отключён» read as the teacher still existing): deleting a teacher removes the row — closed classrooms keep their history with `teacher_id` nulled, students' inbox copies keep their messages with the sender nulled (migration 014 makes those columns nullable). Active classrooms still block the delete until reassigned or closed. `POST /manage/teachers/{id}` lets the centre admin (and superadmin) edit a teacher's name and phone/login in place, with the same validation as hiring; password stays its own reveal/reset flow (FR-1.10/FR-13.18). |
| FR-15.10 | **Saving in the panel keeps the scroll position** (client, 2026-08-20): `useAsync` is stale-while-revalidate — a reload keeps the previous data on screen instead of swapping the page for a loading placeholder, so the DOM never unmounts and the browser keeps the scroll naturally. The full-page «Загрузка…» state appears only on first load, when there is nothing to keep. |

---

## Traceability

Every merged change should reference the `FR-*` it implements. Anything
with no `FR-*` and no `Q-*` answer behind it is out of scope.
