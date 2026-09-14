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
| FR-15.15 | **A session lasts until it is ended, not until the next hiccup** (client, 2026-09-09: «session time of admin panels and app’s are very short, I need login again and again»). The TTLs were never the problem — 15-minute access token, 30-day rotating refresh token, both unchanged. Three things ended sessions early and all three are fixed. (a) **Rotation grace**: a refresh token is single-use, and re-presenting a spent one was read as theft, which revokes EVERY session that user holds. The two commonest replays are innocent — a retry after a lost response, and a second browser tab holding the copy it read at page load — and the dev database recorded one admin losing 18 live sessions in a single second. A token retired by ROTATION may now be presented again for `JWT_REFRESH_GRACE` seconds (default 60) and issues a fresh session instead; replay after the window, or of a token revoked by logout / the single-session rule / an earlier detection, still burns the family (migration 015 adds `revoked_reason`). (b) **A transient failure is not a rejection**: both clients used to delete their tokens whenever a refresh call threw, including on a dropped connection or a 502 during a redeploy. Only a 4xx now signs anyone out; the panel shows «Сервер недоступен» with a retry, and the app opens on its cached profile (FR-12.9 already caches the content). (c) **Renewal is proactive**: both clients read `expires_in` and renew a minute early, so the 401 path — and the chance of hitting it exactly while the network is down — is off the normal route. Single-session-per-student (FR-12.2) and immediate deactivation (FR-1.14) are unchanged. |
| FR-15.16 | **The order of a child unit’s sections is set by hand and the app follows it** (client, 2026-09-12: «add a function to reorder them as i want… i wanna change order of grammar with vocabulary… even after contents are uploaded, and in the app set order should appear»). Until now the order was whatever the importer happened to create — `sections.sort_order` existed and every read already sorted by it, but no UI ever sent one (`updateSection` accepted the field; nothing called it). `POST /manage/child-units/{id}/section-order` (superadmin) takes the WHOLE list of that child unit’s section ids and renumbers them 1..N in one transaction; a list that is missing, repeats, or borrows an id is refused outright, because a partial write would leave two sections sharing a position and the student’s order would then depend on which id was lower. Reordering is a MODE, off by default (client, 2026-09-12): «Изменить порядок» on a child unit’s card makes its rows draggable, the drag rearranges a local draft only, and «Сохранить порядок» is the single write — so a mis-aimed drag on a page whose main job is publishing cannot silently rearrange what students see, and «Отмена» discards. A checkbox in that footer, «Применить ко всем подюнитам юнита», copies the resulting TYPE order onto every sibling child unit of the same parent (a sibling missing a type skips it; a type the order does not name sorts after all the ones it does, keeping its own relative position). A child unit may legitimately hold two sections of one type — only the quiz is unique — and a rank keyed by type cannot address both, so the FIRST occurrence sets that type’s position and later ones are ignored for the copy; the source unit itself still stores exactly the order it was given. Reordering is available on the unit page only; the section editor renders the saved order but does not change it. The order survives re-uploading the same xlsx: the importer reuses the existing section row per type and never rewrites `sort_order`. **The Exam Quiz is outside the ordering** — the app gives it its own card below the practice modules (`home-screen-completed`), so it is pinned last in the panel and its row carries no grip, rather than offering a drag that would move nothing for the student. Reordering is display only: it changes no attempt, percentage or point (FR-8.4, FR-13.6). |
| FR-15.17 | **Units and child units are reordered the same way** (client, 2026-09-13, looking at a unit whose child units read 6D, 6C, 6A, 6B: «Create a reordering function for units also like you did before for lessons»). Same contract as FR-15.16 one level up: `POST /manage/levels/{id}/unit-order` and `POST /manage/units/{id}/child-order` (superadmin) each take the WHOLE list of that parent’s children and renumber `sort_order` 1..N in one transaction, refusing a list that is missing, repeats, or borrows an id. Same interaction too — «Изменить порядок» arms the mode, drag rearranges a local draft, «Сохранить порядок» is the only write — shared as the `useReorder` hook so the two lists cannot drift apart. **`units.number` and `unit_sections.code` are NOT touched**: they stopped being display values under FR-15.7 but remain the xlsx import’s join keys, and renumbering them would silently re-point every future upload. Any reorder also **rebuilds `unit_sections.level_position` for the whole level** — it is the level-wide teaching order, so it cannot follow from one unit alone, and until now `/manage/content` sorted child units by it while the curriculum page and the student’s outline sorted by `sort_order`, letting the two panel pages disagree with each other and with the app; that page now sorts by the same `(unit.sort_order, child.sort_order)` keys everything else uses. Reordering is display only: no attempt, percentage or point changes (FR-8.4, FR-13.6). |
| FR-15.18 | **A question’s own note can be rendered as its audio or its picture** (client, 2026-09-13: «for each audio - there is a given text, for example: brazil - and audio should be brazil, also text is provided for images»). The v2 payloads already carry the text: a listening stem is `{"audio_note": "seven"}` and a picture stem `{"image_note": "italy"}`, with the target `media_path` beside it — only the recording was missing. «Озвучить» / «Нарисовать» beside each media part sends **that note and nothing else** to Gemini and stores the result through the same path an upload takes (same filename, same stale-extension sweep, same servability answer). **This is not content generation and does not reverse invariant 6**: nothing invents a question, an option or an answer; the words stay the workbook’s, and deleting the feature leaves the content identical. **Notes are read, not guessed at.** An `audio_note` of two or more lines that are ALL `Name: text` is a SCENE and is performed by two voices (`Receptionist: Good evening… / Guest: I have a reservation.`); one line is always plain text, so `Time: half past four` is spoken whole rather than losing its first word; three or more speakers is refused, because Gemini voices at most two. Which role gets which voice is fixed by SORTING the two names — the same pair appears in both orders across the course, and assigning by who speaks first would give the teacher one voice in Unit 1 and another in Unit 5. Image notes: `FLAG_TURKEY` becomes *the national flag of Turkey* — the prefix is meaning, and stripping it naively yields “turkey”, the bird, in a question whose other options are countries — while `IMG_PEN` becomes *pen* and prose like `a red double-decker bus` is passed through untouched.

**The look was tuned against real output** (2026-09-13): cartoon, one single object, and three clauses each written against a picture the first draft actually produced — no face or eyes (a “friendly cartoon pen” came back smiling), no scene or montage (`italy` came back as a chef with a pizza, the Colosseum and a gondola, unreadable at the 96px an option tile gets), and writing rendered as illegible squiggles rather than merely forbidden (`IMG_DICTIONARY` came back with DOG, APPLE and CAT printed legibly across the page — and a printed English word inside the picture hands the student the answer). Images are **16:9 at 1K in JPEG**: landscape because the app draws a 160px-tall stem banner and 96px-tall option tiles with cover cropping, and JPEG because the live API rejects `image/png` on this model with a 400 whatever the docs example shows. Audio returns as raw 24 kHz PCM, wrapped as WAV or transcoded to MP3 when the host has ffmpeg — both already accepted by the media route, so installing ffmpeg later needs no code change.

**Generate in bulk, judge one by one** (client, 2026-09-13): an exercise set carries «Сгенерировать всё недостающее (N)», which fills every EMPTY slot in it and never overwrites a file that already exists, and each part carries «Перегенерировать» for taking another attempt at one that is not good enough. The bulk loop runs in the panel, one part at a time with a live count and a stop button — sixteen listening questions is about three minutes of calls, and a single request holding that open would hit PHP’s execution limit with a half-finished set and no way to tell a failure from a hang. One part failing is reported and the run continues. **Inert by default:** with no `GEMINI_API_KEY` in `api/.env` the route answers `generation_unconfigured` and the panel renders no buttons, so the product still ships with no LLM key anywhere (invariant 6’s actual requirement). Generation is superadmin-only and server-side; no student device ever contacts Google — see Q-52. An upload always wins: generation only ever fills a part that has no file. |
| FR-15.19 | **A child unit’s label is its whole name — nothing is prefixed to it** (client, 2026-09-13: «when i want to name a unit, for example "1-A" in app it appears as a "Unit 1-A". The word "Unit" is hardcoded. Remove it»). FR-15.7 already said an explicit label is the display identity *verbatim, everywhere*; the app was not honouring it, prefixing the localised word Unit / Юнит / Bölüm at eight places across the home screen, the vocabulary screens and the teacher screens. All eight now print the label alone, and the `unit` translation key is gone from `l10n.dart` so nothing can re-add it by habit. The server already composes `{number}-{code}` for a child unit with no explicit label, so a legacy unit still reads «1-A» and a named one reads exactly what was typed. `unit_quiz` is a different string and is unaffected. |
| FR-15.20 | **The app plays a sound for right, wrong and the result screen** (client, 2026-09-13, supplying the five clips). Right and wrong fire the moment `/check` answers, **in the Exam Quiz as well as in practice** (client decision, 2026-09-13) — the quiz already shows an immediate verdict per question, so staying silent only there would read as a fault. A re-queued retry (FR-15.13) sounds too: the clip is feedback on the answer just given, not on the score. The result screen has **three clips chosen by the same thresholds it uses for its artwork** — ≥80, ≥50, and below (client decision, 2026-09-13, supplying one per tier): a student who scores 30 hears the “not this time” clip, never the fanfare, because the screen already tells them they did not pass. **No mute toggle** (client decision, 2026-09-13): the phone’s own volume and silent switch are the control, which these respect — unlike listening-exercise audio, which must play regardless. **A clip never outlives its screen** (client, 2026-09-14: «sound should stop immediately after closing that page or moving to next page»): moving to the next question, leaving the exercise, and leaving the results screen each cut their own sound, and so does sending the app to the background — where question media now stops too. “Its own” is literal: every clip is started with a handle and a screen may only silence the handle it holds. Without that, one specific ordering breaks it — `pushReplacement` runs the results screen’s `initState` (starting the result clip) and disposes the exercise screen only after the transition, so an unconditional stop there would reach forward and cut off a sound belonging to the screen that replaced it. For the same reason the result clip is owned by `ExerciseEndScreen` (made stateful for it) rather than started before navigating.
| FR-15.21 | **Six glyphs move; nothing else does** (client, 2026-09-14: «everything is static and it is boring»). Chosen from a 41-item survey the client narrowed by hand — the four bottom-nav tabs, the vocabulary bookmark, and the audio tile. Everything else surveyed was declined and is deliberately untouched. The nav tabs and the bookmark each already ship TWO drawings (`nav_x.svg` / `nav_x_active.svg`, `bookmark_outline` / `bookmark_filled`), so `DanaIconSwap` keeps both mounted and crosses their opacity — the outline dissolves into the filled shape instead of being replaced by it, with the active tab rising 2px. The bookmark adds a scale overshoot **on save only**, never on un-save, where a flourish would celebrate the wrong thing; its two files are the same path (stroke-only versus stroke-plus-fill), so it reads as one bookmark filling in. The audio tile’s speaker → spinner → bars was two hard cuts on the biggest element of a listening question; the three states now crossfade in a shared `Stack` so the tile does not resize around them. The **waveform itself now moves** — it was six fixed-height bars drawn as a picture of sound while sound played — repainted in one `CustomPaint` (six animated `Container` heights would relayout the row every frame inside a scrolling list) with each bar a sixth of a cycle behind the last, modulating DOWN from the Figma ratios so the designed silhouette is the peak and never clips. Its controller lives and dies with the clip, so nothing ticks in silence. **Every animation honours `MediaQuery.disableAnimations`** and falls back to the previous instant behaviour — the first place in the product to read that flag. |
| FR-15.22 | **The result screen’s score arrives rather than appearing** (client, 2026-09-14, naming it as their first example). The ring sweeps from empty to the score and the percentage climbs with it, driven by ONE tween so the arc and the digits can never disagree, ending on exactly the number `POST /sections/{id}/attempts` returned — this screen still computes nothing (NFR-5). The duration scales with the score (450ms + 6ms per point), because a full ring given the same time as a quarter one has to race, and the climb is the reward. The **colour does not animate**: the tier is fixed by the final percent, so a 92% ring is green from the first frame rather than travelling red → amber → green and implying a verdict that was never in doubt. The tier illustration, copy and sound (FR-15.20) are unchanged. Honours `MediaQuery.disableAnimations`, where the score simply appears as before. |
| FR-15.23 | **The three result tiers are animated, and fitted to the phone** (client, 2026-09-14, supplying one LottieFiles animation per tier, then «optimize sizes of all of them, make bigger than current PNG and make sure that they arent oversized»). The 306×295 stills become Lottie compositions: ≥80 a businessman flying on a rocket, ≥50 a figure climbing a podium to a trophy, below 50 a woman with her head in her hand at a laptop. All three carry the **Lottie Simple License** — commercial use granted outright, attribution “strongly encouraged” but not required — and all three are pure vector with no embedded raster, so they scale to any screen. **What gets fitted is the artwork, not the canvas.** Lottie compositions are routinely authored with generous empty margins, and measuring these found the trophy spending 43% of its canvas on nothing, the woman 25% and the rocket 15%; fitting whole canvases would have spent that emptiness on screen, which is most of why an animation can look smaller than the still it replaced. Each ink rectangle was found by rasterising 45 points across the timeline and scanning for non-white pixels — `getBBox` was tried first and is wrong here, returning the clip rectangle at every frame on the trophy, which is built from precomps, and reporting a shape the artwork does not have. The ink is scaled to the slot with 4% held back as insurance against a frame the sampling stepped over, and the margin is scaled off the edge and clipped. **Sizing is measured, not fixed.** Trophy and woman are square once their margins are discounted (ink aspect 1.06 and 1.07), so they take 48% of the height the screen actually has, floored at **270** and capped at 320; a 375×812 phone lands at 295. The floor is 270 rather than the 240 the still was drawn at, and the difference is the point of it: a still fills its box, an animation fills 96% of one side and ~91% of the other, so a 240 box would put 230×217 of artwork on screen against the still’s 249×240 — smaller than what it replaced, the one outcome the brief ruled out. At 270 it is 259×245, at 295 it is 283×267, and the rocket runs 360×194 on a 375pt screen: every SQUARE tier, on every phone, larger in area than the still (+6% at the floor, +27% typical, +49% on a tall phone; measured on the device at +47% and +46%). The rocket is the exception and cannot be floored: it is bound by the screen width, not by the room, so its area equals the still’s at a screen width of 347dp and anything narrower — a 320dp hdpi phone, the bottom of this market — gets a rocket that is wider than the still but smaller in area. Scaling past the screen edge to fix that would clip the flame and the fist, the two things that make the pose read, so it is left as it is and recorded here rather than papered over. A fraction rather than “the room minus everything below it”, because the copy underneath is translated and Turkmen and Russian wrap to more lines than the English such a number would have been measured against. The rocket is the exception: ink aspect 1.86, a landscape flying pose with the jetpack flame at one end and an outstretched fist at the other, so no square crop of it spares both. It runs the **full width of the screen** instead, escaping the 24pt gutter the text keeps: wider than the still it replaces, and necessarily shorter. **The optimisation is verified, not asserted.** Authoring metadata (`nm`, `mn`, `cl`, `ln`) and property indices (`ix`) are stripped — no expression appears in any of the three, so nothing can look an index up, and `tool/optimize_lottie.mjs` refuses the file if one does — and every number is rounded to 3dp, 4dp below 10. That precision was chosen by pixel-diffing each candidate against its original over 40 sampled frames, with a same-file control first proving the rasteriser deterministic: at 2dp up to 0.28% of pixels shifted on shape edges, at 3dp two of the three diff to exactly zero and the third to one pixel in 67,600 at three parts in 255. 579 KB of JSON becomes 484 KB, and 70 KB becomes 65 KB gzipped — which is what actually ships, assets living inside the compressed APK — so squeezing further would have to be lossy for a saving the bundle cannot notice. **Only two of the three loop.** Whether a composition may loop was settled by rasterising its first and last frames and diffing them: the woman is a true cycle (0.00% of pixels differ), the rocket’s seam is 0.68% — twenty times smaller than its own first-to-middle difference, the speed-lines resetting, invisible in motion — but the TROPHY differs by 9.23%, two thirds as much as its own mid-point, because it is a build-in rather than a cycle: the figure climbs the podium over about three seconds and holds. Looping it teleported the figure 153 units back down the canvas and snapped three layers through 43-61° every six seconds, so it plays once and holds the pose it was drawn to end on. The composition is ALSO parsed on a background isolate (`backgroundLoading: true`, against the package default of false), because the first build of this widget happens during the `pushReplacement` transition, alongside the result clip and the FR-15.22 ring tween — tokenising 248 KB of JSON on the UI isolate there is the one stutter this screen can least afford, and it would land hardest on the cheapest phones. Honours `MediaQuery.disableAnimations`, where each holds its first frame. The stills stay in the bundle as the fallback for a composition that fails to parse — strictly better than the empty box the old builder left. |
| FR-15.24 | **The API and the panel move to their own subdomains, so the API learns CORS** (client, 2026-09-14: «Connect all APIs to api.mydana.app and admin panels to admin.mydana.app»). Until now one origin served both halves and the panel’s fetches were same-origin — `deploy/apache-dana.conf.example` said so, and said a split layout “would need backend changes this guide deliberately avoids”. Those changes are here. `CorsMiddleware` is an **allowlist, never `*`**: these replies carry one student’s progress and, on the FR-1.10 reveal path, a decrypted credential, so a wildcard would let any page on the internet read both from a browser holding a session. Origins come from `CORS_ALLOWED_ORIGINS` in `api/.env`, comma-separated and compared **exactly** — suffix matching would accept `evil-mydana.app` and `admin.mydana.app.evil.com`, both of which were tested and both of which are refused. **No `Access-Control-Allow-Credentials`**, because Dana authenticates with a bearer token in a header rather than a cookie: the browser never needs to attach ambient credentials, so the allowlist never becomes a CSRF surface. The middleware sits INSIDE the hardening closure but OUTSIDE the routing middleware — preflights therefore still carry the security headers, and an `OPTIONS` can be answered at all, which matters because no endpoint routes one and reaching the router would raise 405 and make the browser block the real call. `Vary: Origin` goes on every reply, allowed or not, so no shared cache can replay one origin’s answer to another. **Unset means off**: with no `CORS_ALLOWED_ORIGINS` the API sends no CORS headers whatsoever and a single-origin deployment is byte-for-byte what it was. On the panel side `BASE` was the relative `/api/v1`, which on `admin.mydana.app` would resolve against a host serving no API; it is now `import.meta.env.VITE_API_BASE ?? '/api/v1'`, set at build time in `panel/.env.production` and still relative for the Vite dev proxy and for anyone staying single-origin. The mobile app keeps its `--dart-define=API_BASE` (NFR-4) and release builds pass `https://api.mydana.app/api/v1`. Verified against a running API: no `Origin` → no headers; the allowed origin → echoed; preflight → 204 with methods, headers and a one-day max-age; and both hostile near-misses → no `Access-Control-Allow-Origin` at all. |
| FR-15.25 | **The panel works on a phone, and its menu can be put away on any device** (client, 2026-09-14: «admin panels arent responsive, i can use it on my phone or tablet… make side menu hidable and expandable»). The stylesheet contained **no media query at all**: the shell was a fixed `232px 1fr` grid, so on a 375pt phone the sidebar took most of the width and every table ran off the side. One control now serves every size — above 900px it collapses the sidebar’s grid track to zero and restores it, below 900px the same button slides the sidebar in over the page as a drawer with a dimming backdrop, dismissed by the backdrop, by Escape, or by choosing a page. The choice is remembered per browser, defaulting to open on a desktop and closed on a phone. The track collapses to `0 1fr` rather than the sidebar taking `display: none`, which would drop it out of the grid and leave the content sitting in column 2 of a one-column track. **The bug underneath the bug was `min-width`**: a grid item defaults to `min-width: auto` and so refuses to shrink below its contents, which made the content column 653px wide inside a 375px viewport — no `overflow-x` on a table could help, because the container was never the constraint. With `min-width: 0` on the shell’s children the page stops scrolling sideways (measured: 375 = 375) and each table becomes its own horizontal scroller instead, which is what the pages with seven columns need. Cards and form rows stack, dialogs stop being wider than the screen, and the slide is dropped under `prefers-reduced-motion`. Verified by rendering the real stylesheet at 375, 900 and 1280: drawer open and closed, column hidden and restored, and no sideways overflow in any of the six states. **The pages were then audited one by one** rather than assumed fixed by the shell, which turned up five more places where a page — not a table — dragged the viewport sideways: a classroom’s five-button action bar (~500px of unshrinkable content in the 311px a phone has inside the card, with «Удалить класс» off-screen), a card header whose nowrap actions and non-shrinking title totalled 392px once a child unit had a realistic title, a course-closing confirm row that hand-rolled `.with-action` instead of using it, inline-edit inputs that inherited a 66px column and showed about six characters of a +993 number, and inputs at 15px, which makes iOS Safari zoom on focus and never zoom back. All measured at 375px and re-measured after: the page scroll width is now exactly the viewport. **Reordering also had to stop being drag-only.** HTML5 drag fires no events on a touch screen, so «Изменить порядок» opened a mode that could not do its one job on the very devices this work is for — and on the Content page it disables the publish pills while open, so the page lost its main function too. Every reorderable list now carries ▲▼ buttons beside the drag handle, 40px on touch, going through the same path as a drop so the exam quiz stays pinned last; they are also the only way to reorder from a keyboard, since a native drag cannot be started with one. |

**Latency is a feature of the design** (client, 2026-09-14: «correct and incorrect sounds are kinda delayed… should be synced with the modal»). The first version called `play(AssetSource(...))` on one shared player, which per tap did a stop round-trip, resolved the asset out of the bundle and prepared a decoder before making any sound — audibly behind the verdict sheet. Now each clip has its OWN player whose source is set once at launch (`Sfx.warmUp()` from `main`), in `PlayerMode.lowLatency` (“ideal for short audio files”; it drops duration, position and completion events, none of which this uses), and firing is a bare `resume()` with nothing awaited in front of it.

Implementation: `core/sfx.dart` is SEPARATE from `AudioBus`, because that bus stops whatever is sounding on every new clip and drives the “tap to listen” tile state — sharing it would cut a listening clip short and leave the tile wrong. Every call is fire-and-forget and never awaited, so a slow decode cannot delay the verdict sheet, and a clip that fails to load is silence rather than an error over an exercise — but loud in `debugPrint` under `assert`, so a mistyped asset path cannot ship as “works, phone must be muted”. |

---

## Traceability

Every merged change should reference the `FR-*` it implements. Anything
with no `FR-*` and no `Q-*` answer behind it is out of scope.
