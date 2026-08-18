# Redesign build spec — 2026-08-09

Binding contract for the 1:1 build. The exported frames in this folder
are the visual truth; §13 of 01-REQUIREMENTS.md is the behavioural
truth. Where a frame contradicts §13, §13 wins (known cases: teacher
add-student frame not built, FR-13.23; grammar-guide's 5-tab nav is
stale — the app has FOUR tabs: Main, Vocabulary, Ranking, Profile).

## Tokens (sampled from the exported pixels)

| Token | Value | Notes |
|---|---|---|
| brand | #7C0C3E | unchanged |
| surface | #FAF8F9 | unchanged |
| card | #FFFFFF | |
| ok / success | #10B981 | ring, % chips ≥ threshold, correct states |
| progressAmber | #FFC301 | exercise progress bar fill (NEW — replaces #F59E0B there) |
| accent | #F59E0B | vocabulary tile icon, podium gold — still used |
| danger | #EF4444 | wrong states, red % chips |
| chip backgrounds | 10% tints of their colour | e.g. green chip = #10B981 @10% bg, solid text |
| logo | logo.png (this folder) | "mydana*" — asterisk amber; replaces text-logo everywhere |

Typography stays Inter with the -2%-tracking rule. Corner radius 40 on
frames, 12/16 on cards as before; measure anything else off the PNG.

## Interface languages

tk / ru / en — three, per FR-13.22. `L` gains 'en' for every key.
Design copy IS the English string set.

## Screen → frame map (student)

| Screen | Frames |
|---|---|
| Splash | splash-screen.png (mydana* logo on brand) |
| Login | login-screen.png |
| Home | home-screen-3.png (base), home-screen-completed / -not completed / -free / -full-completed (state variants of the unit card + practice modules) |
| Lessons sheet ("View All") | home-screen-lessons.png — child units "Unit 1-A" + % chips (green ≥80, amber mid, red low) and content-state captions |
| Section history modal | home-screen-section-history.png — overall average + dated attempts + Start |
| Unit detail | unit-detail-screen.png |
| Unit grammar | unit-grammar-detail.png |
| Unit vocabulary | unit-vocabulary-screen*.png |
| Vocabulary tab | vocabulary-screen.png + vocabulary-screen-modal.png (word card: pronunciation + MEANING + source-unit chip) |
| Grammar guide | grammar-guide.png + grammar-guide-detail.png (topic index with search; NOTE: nav in-app is 4 tabs) |
| Ranking | leaderboard-screen.png — averaged big scores, YOU badge |
| Profile | profile-settings.png, profile-language.png (3 languages), logout confirm dialog |

## Exercise screens (owner: agent C)

Common chrome: brand header, back arrow → **End session dialog**
("Do you really want to end this session. Your progress won't be
saved." Cancel / End session — red), "N of M" counter, #FFC301
progress bar. Verdict banners per state frames.

| Type | Frames |
|---|---|
| Test (multiple choice) | Test*.png — flat option rows, red ✗ wrong state, bottom sheet Wrong/Continue |
| Match Pairs | Match Type*.png — two columns, selected pink, correct green |
| Fill in the blanks | Fill in the blanks*.png |
| Fill letter space | Fill letter space*.png — per-letter boxes, system keyboard |
| Reorder | reorder-words-exercise*.png |
| End screens | exercise-end-good/normal/bad.png, exam-end-good/normal/bad.png — memoji illustration + verdict + % (thresholds: good ≥80, normal ≥50, bad <50) + actions |

Memoji illustrations: crop from the end-screen PNGs into
app/assets/illustrations/ (they are part of the client's design file).

## fill_letter_space payload (FR-13.21)

Authored: `{"text": "I just {want} to go Hawaii.", "reveal": 2}`
— words in {} are targets; `reveal` = leading letters shown.
`payloadForStudent`: parts array, each `{"t": "I just "}` or
`{"blank": {"given": "wa", "count": 2}}`. Answer: list of
missing-letter strings per blank, graded case-insensitive, trimmed.

## API contract (owner: agent A; B/C build against these shapes)

All under /api/v1, auth as today. No unlock filtering anywhere.
Students read published sections only.

GET /me/outline →
{ level: {id,name}, level_progress: int(0-100),        // coverage: % of published sections attempted ≥1
  leaderboard_score: int(0-10000),
  parent_units: [ { id, number, title,
    child_units: [ { id, label:"1-A", title, success_rate: float|null,   // FR-13.8 average of section averages
      state: 'completed'|'in_progress'|'not_started'|'empty',
      sections: [ { id, type:'grammar'|'vocabulary'|'listening'|'quiz',
                    title_tk, title_ru, title_en:null,      // titles optional
                    question_count:int,                     // quiz: eligible count
                    attempts:int, average: float|null } ] } ] } ] }

GET /sections/{id} → { section:{id,type,...}, questions:[ payloadForStudent ] }
  — quiz sections: questions = ALL quiz_eligible questions of sibling
    sections, source order (FR-13.4). Non-quiz: own active questions.

POST /sections/{id}/check { question_id, answer } → { correct: bool }
  — STATELESS. Grades one answer, stores NOTHING (FR-13.5). Used for
    the per-question verdict UI.

POST /sections/{id}/attempts { answers: [ {question_id, answer} ] } →
{ attempt_no, percent, correct, total, average, history:[{attempt_no,percent,correct,total,completed_at}] }
  — requires an answer for EVERY question of the section; server
    re-grades everything (client verdicts are advisory), writes
    section_attempts + attempt_answers + student_section_stats in one
    transaction. This is the ONLY write.

GET /sections/{id}/attempts → { average, attempts:[...] }  // history modal

GET /me/leaderboard → { entries:[{rank, display_name, score:int, is_me}], me:{...} }
  — score = level_progress-correctness % × 100 (FR-13.9), classroom
    pool, ties by earlier last_completed_at (FR-13.19).

Teacher (read-only): GET /teacher/classrooms, /teacher/classrooms/{id}/students
(per-student average %), /teacher/students/{id}/attempts. All
credential routes are ADMIN-scoped now (FR-13.17): reveal+reset move to
/manage/students/{id}/credential|password; teacher variants deleted.

## File ownership (no agent touches another's files)

- **A (backend)**: api/** — services, controllers, routes, tests.
  Deletes UnlockService, old ExerciseService/StatsService points code.
- **B (app core)**: app/lib/core/**, app/lib/main.dart, shell,
  splash/login/home/lessons/history/unit/vocab/grammar/ranking/profile
  screens, app/assets/** (logo, illustrations), pubspec assets.
  Owns l10n (adds 'en'), theme (adds progressAmber #FFC301), icons.
- **C (app exercises)**: app/lib/screens/exercise_screen.dart,
  app/lib/screens/exercise_end_screen.dart (new). May import core
  tokens by name; must NOT edit core files — if a token/string is
  missing, use the literal and leave a `// SPEC:` comment for merge.
