# Content model v2 — the client's xlsx files ARE the structure

Decided 2026-08-13. The five files the client supplied (`Listening.xlsx`,
`Grammar.xlsx`, `Vocabulary.xlsx`, `UnitQuiz.xlsx`, `Wordlist.xlsx`)
define the content database structure **exactly**. This document is the
binding contract for the importer, exporter, serving, grading, panel and
app. Where it contradicts §13, THIS supersedes (recorded as FR-14.*).

## 1. File formats (import AND export use these exact shapes)

### 1.1 Question files — Listening / Grammar / Vocabulary
One sheet, header row:

```
Question ID | Level | Unit | Lesson | Topic | Subtopic | Question Type | Rule | Question | Option A | Option B | Option C | Option D | Answer Description | Eligble
```

- The importer tolerates the header typos seen in real files
  (`Answer Desription`, `Eligble`) and trailing empty columns.
- **Which skill** (= which typed section) comes from the sheet/file name:
  `Listening` → listening section, `Grammar` → grammar, `Vocabulary` →
  vocabulary section of the row's child unit.
- `Question ID` (e.g. `U1-G-001`) → `questions.external_code`, the upsert
  key. Same ID = update, new ID = insert.
- `Level` = `"Beginner / A1"` → match a level whose name equals either
  half (trimmed); error if no match.
- `Unit` (int) + `Lesson` (`"1A"`) → parent unit by number, child unit by
  code (the `Lesson` minus the unit number). Created if missing.
- `Topic`, `Subtopic`, `Rule`, `Answer Description` → stored verbatim,
  **English only** (client decision) in `topic`, `subtopic`, `rule_en`,
  `answer_description_en`.
- `Eligble` = `Yes` → `quiz_eligible = 1`, anything else → 0.

#### Question Type values
| File value | Stored set type | Notes |
|---|---|---|
| `Test` | `multiple_choice` | 2–4 options; **Option A is always the correct one** in the file |
| `Practical Dialogue → Detail` | `multiple_choice` | listening variant; behaves as Test |
| `Practical Dialogue → Meaning` | `multiple_choice` | listening variant; behaves as Test |
| `ReOrder` | `reorder` | Question holds the CORRECT sentence as `{{tok}} {{tok}} …` |
| `LetterSpace` | `fill_letter_space` | `{{S<e>ve<n>}}` — letters inside `<>` are HIDDEN |
| `FillBlank` | `fill_blank` | `…{{correct~wrong~wrong~wrong}}…` — first option correct |

Within a section, questions keep FILE ORDER via a global `sort_order`
(row number), regardless of which set (type) they land in.

#### Media parts — `[audio: X, image: Y, text: Z]`
The `Question` cell and each `Option` cell of Test rows is a triple.
Exactly one member is non-`"0"`; `"0"` (or empty) = unused.
- `text` → literal display text.
- `audio` / `image` → a **NOTE** for the superadmin ("seven",
  "IMG_PEN", a two-line dialogue). The panel shows the note beside an
  upload control; the real file is uploaded later.
- Parser must tolerate multi-line notes inside the quotes.

### 1.2 Wordlist.xlsx
```
Word ID | Level | Unit | Lesson | Category | Type | English | Turkmen | Russian
```
→ `vocabulary_items` of the child unit's **vocabulary** section:
`external_code`, `category`, `word_type` (`Word`→`word`,
`Phrase`→`phrase`), `term_en`, `translation_tk`, `translation_ru`.
No IPA / part-of-speech / example in v2 — the app hides those when null.

### 1.3 UnitQuiz.xlsx
Sheet "Content Inventory": a title row `… UNIT <n><code> …`, then
`Skill | Item Count | Unit Quiz Target` rows for Vocabulary / Grammar /
Listening. → `unit_sections.quiz_target_vocabulary/grammar/listening`.
The TOTAL row and "Quiz scoring" block are informational (20 × 5 = 100
is the percent framing; points stay dead per FR-13.7).

## 2. Storage (migration 012)

- `questions`: `external_code` (unique), `topic`, `subtopic`, `rule_en`,
  `answer_description_en`. Type still lives on `exercise_sets`; the
  importer groups rows into one set per (section, type) and keeps file
  order through `questions.sort_order`.
- **Payload v2 for multiple_choice**:
  ```json
  {"stem": {"text": "..."} | {"audio_note": "seven", "media_path": null}
          | {"image_note": "IMG_PEN", "media_path": null},
   "options": [ {"text": "7"} | {"audio_note": "...", "media_path": null}
              | {"image_note": "...", "media_path": null}, ... ],
   "answer": 2}
  ```
  **The file has the correct option first (Option A), but the importer
  SHUFFLES the options and sets `answer` to the correct option's new
  index** (a random 0..n-1, NOT always 0). This is what makes the
  served index safe: because the correct answer sits at a random stored
  index, sending each option its stored index `i` (and naming its media
  `q{id}-opt{i}`) reveals nothing. Re-import of the same `external_code`
  keeps the existing stored order/answer stable (shuffle only on first
  insert) so media files and answer don't churn. Legacy payloads (`stem`
  as string, `answer` 0-based) stay valid; the grader accepts both.
- **reorder v2**: `{"tokens": ["Are","you","from","Germany","?"]}` —
  token order IS the answer; no separate answer array.
- **fill_letter_space v2**: `{"mask": "S<e>ve<n>"}` — `<>` marks HIDDEN
  letters at arbitrary positions (supersedes prefix-`reveal`). Legacy
  `{text, reveal}` payloads stay gradable.
- **fill_blank v2**: from `before{{a~b~c~d}}after` →
  `{"before": "...", "after": "...", "answer": ["a"], "word_bank":
  ["a","b","c","d"]}` (existing shape; first bank entry correct at
  authoring, bank shuffled at serve).
- `vocabulary_items`: `external_code` (unique), `category`, `word_type`.
- `unit_sections`: `quiz_target_vocabulary/grammar/listening`.
- `quiz_draws`: the FIXED quiz set per child unit.
- Media files: `STORAGE_PATH/media/q{questionId}-{part}.{ext}` where
  part ∈ `stem`, `opt0`..`opt3`. Served via authenticated
  `GET /media/{name}` streaming route.

## 3. Serving & grading rules

- **Servable question** = active + its section published + **every
  audio/image part has an uploaded file** (client decision: auto-hide
  until media uploaded). Non-servable questions are excluded from
  section play, quiz pools, quiz draws and all percent denominators
  (levelMap question counts).
- **MC shuffle**: the app/server never shows file order — options are
  shuffled per serve (FR-12.6). Payload-for-student sends options with
  their ORIGINAL indices (`[{i: 2, text: …}, …]` shuffled); the client
  submits the chosen original index; grader: `i === answer`. Text
  submissions remain accepted for legacy content.
- **ReOrder shuffle**: payload-for-student sends tokens shuffled (never
  in correct order — reshuffle while `shuffled == original` and length
  > 1); client submits the arranged token list; grader compares to
  `tokens` verbatim (normalised).
- **LetterSpace**: student receives visible letters + box positions
  (never the hidden letters); grader compares typed letters to the
  hidden ones case-insensitively.
- **Quiz composition (FR-14.3)**: per child unit, ONE fixed random draw
  of `quiz_target_vocabulary` + `quiz_target_grammar` +
  `quiz_target_listening` servable eligible questions from that unit's
  sections of each type, stored in `quiz_draws` (order = draw order,
  grouped vocab→grammar→listening). Every student gets the same set.
  The draw happens lazily on first serve and self-heals: a drawn
  question that stops being servable is replaced from its pool. The
  superadmin can REDRAW from the panel. If a pool is smaller than its
  target, the whole pool is used (and the panel warns). Units with no
  targets keep the old all-eligible behaviour.
- **Rule / Answer Description**: served verbatim (English) in every UI
  language. `rule_en` is the instruction line above the question;
  `answer_description_en` shows on the verdict sheet after answering.

## 4. Books removed (FR-14.5)

No book upload, no book sets, no page ranges, anywhere: routes
(`/manage/uploads*`, `/manage/sections/{id}/sources`,
`/manage/sources/{id}`, `/manage/levels/{id}/book-sets`), the panel's
page-range UI, and classroom book attachment (column now nullable,
nothing writes it). Content arrives ONLY via manual authoring or xlsx.
`book_sets`/`books`/`book_page_texts`/`section_sources` tables stay
dormant; the auto-create of a book set on level creation is removed.

## 5. App UI for media questions (frames in figma-export/)

- Audio stem → `Test-audio.png` / `Test-audio-playing.png`: brand
  speaker tile "TAP TO LISTEN", waveform state while playing.
- Image stem → `Test-image.png`: image inside the question card.
- Image options → cards in the option list with the image (letter chip
  kept), per `Match Type-image.png`'s card style.
- Audio options → option rows with a leading speaker glyph that plays on
  tap (`Match Type-audio.png`), selection still by tapping the row.
- Answer Description → the verdict sheet's description line (the frames
  already show it: "You heard seven.").

## 6. Export

`GET /manage/export-xlsx?scope=…` now emits ONE workbook whose sheets
are exactly the client's five shapes: `Listening`, `Grammar`,
`Vocabulary` (question sheets, §1.1 headers, media parts re-serialised
as `[audio: …, image: …, text: …]`), `Wordlist` (§1.2), `UnitQuiz`
(§1.3, one block per child unit in scope). Round-trip through import is
lossless. The old 4-sheet format is retired; the importer accepts ONLY
the v2 shapes (plus tolerated typos).
