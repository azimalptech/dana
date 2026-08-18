# Dana — project instructions

English learning app for Dana language centres (Turkmenistan).
Flutter mobile app (students + teachers) + PHP/MySQL API + React admin panels.

## Read first

`docs/` is the source of truth. Before changing anything:

1. [docs/01-REQUIREMENTS.md](docs/01-REQUIREMENTS.md) — the contract (`FR-*`)
2. [docs/02-OPEN-QUESTIONS.md](docs/02-OPEN-QUESTIONS.md) — **unresolved decisions (`Q-*`)**
3. [docs/03-ARCHITECTURE.md](docs/03-ARCHITECTURE.md), [docs/04-DATA-MODEL.md](docs/04-DATA-MODEL.md), [docs/05-CONTENT-PIPELINE.md](docs/05-CONTENT-PIPELINE.md)

## Rules

- **Never implement anything still marked `Q-*`.** Ask instead. The
  client stated explicitly: no assumptions accepted.
- When a `Q-*` is answered: move it to the "Answered" table in
  `02-OPEN-QUESTIONS.md`, write the resulting rule into
  `01-REQUIREMENTS.md` as a new `FR-*`, then implement.
- Changing behaviour means changing the docs in the same commit.
- Reference the `FR-*` in commit messages.

## Hard invariants

1. Points are computed **server-side**. The client submits answers, never scores.
2. `question_results` has a unique key on `(student_id, question_id)` — a
   question can score exactly once, ever (FR-8.4).
3. Points are written **only** in the transaction that completes an
   exercise attempt (FR-8.7).
4. Students only ever read `status = 'published'` content, filtered at
   the repository layer.
5. Role scoping (`superadmin` / `admin` → centre / `teacher` → own
   classrooms / `student` → self) is applied in the repository layer,
   never per-controller.
6. **There is no AI content generation** (removed 2026-08-07). Content
   is authored manually by the superadmin, 1:1 from the workbook, and
   ships through the draft → published gate. No LLM key ships anywhere
   in the product.
7. Student accounts are created by the **centre admin** (FR-1.4,
   2026-08-07). Teachers read their classes and reveal/reset passwords;
   they never create accounts.
8. A student account belongs to **exactly one classroom** (`users.classroom_id`).
   Closing a course disables those accounts and **purges** their progress
   (FR-1.14) — so any report must be exported before closure.
9. Grammar, vocabulary and exercises all attach to a **section**, never a
   unit. Units are containers only (FR-3.12).
10. An exercise type is **never padded or forced** — an unsupported type
    is reported, not fabricated (FR-4.20).
11. **Authentication never decrypts a credential.** Login compares the
   bcrypt hash only. The reveal path (FR-1.10) is separate, students-only,
   scoped to the student's own teacher or centre admin, rate-limited, and
   audit-logged on every call. `APP_CRED_KEY` lives in `api/.env` — never
   in the database, a backup, or version control.

## Environment

- Local stack: **XAMPP** (Apache + MySQL), PHP 8.2
- Flutter: `C:\src\flutter`
- Interface languages: Turkmen (`tk`) and Russian (`ru`). No English UI
  unless Q-44 says otherwise.
- Content language is English; instructions and explanations are bilingual.
