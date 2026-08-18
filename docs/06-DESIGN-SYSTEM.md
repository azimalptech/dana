# Dana — Design System

> Extracted from Figma `l8BCWiLVcCswpQOgThBLPo` on 2026-08-04.
> Source of truth for the Flutter theme. Values are measured from the
> file, not estimated.

## Tokens

| Token | Value | Used for |
|---|---|---|
| `brand` | `#7C0C3E` | Header banner, primary buttons, splash background, progress fill |
| `surface` | `#FAF8F9` | Screen background (warm off-white, slight pink cast) |
| `card` | `#FFFFFF` | Input fields, cards, list rows |
| `border` | `#F0E9EC` | Input borders, dividers — 1.5px |
| `textMuted` | `#7D7477` | Field labels, placeholder text, secondary copy |
| `onBrand` | `#FFFFFF` | Text on the brand colour |
| `onBrandMuted` | `rgba(255,255,255,0.8)` | Secondary text on the brand colour |
| `textPrimary` | `#F9FAFB` | The one declared Figma variable (`text/primary`) |
| `ink` | `#1F1A1C` | List and body text on the content screens |
| `navBorder` | `#E5E7EB` | Tab bar hairline — cooler than the pink-cast `border` |
| `accent` | `#F59E0B` | Vocabulary tile, exercise progress, podium first place |
| `silver` | `#BDBDBD` | Podium second place |
| `bronze` | `#D97706` | Podium third place |
| `danger` | `#EF4444` | Log Out row only — `error` stays for validation |
| `chipUsed` | `#B1B1B1` | A word chip already placed in a gap |

Only `text/primary` exists as a Figma variable; everything else is a
hardcoded fill. The Flutter theme therefore becomes the real token
source — if the design changes, update `app/lib/core/theme/` and this
table together.

## Typography

Family: **Inter** (Regular 400, Semi Bold 600, Bold 700).

| Role | Size | Weight | Line height | Tracking |
|---|---|---|---|---|
| Screen title (`Welcome Back!`) | 28 | Bold | 34 | −0.56 |
| Subtitle | 14 | Regular | normal | −0.28 |
| Section header (`Multiple Choice`) | 17 | Semi Bold | 22 | −0.34 |
| Field label (uppercase) | 11 | Semi Bold | normal | −0.22 |
| Input text | 15 | Regular | normal | −0.30 |
| Button label | 16 | Semi Bold | normal | −0.32 |

Tracking is consistently ≈ −2% of font size. In Flutter that is
`letterSpacing: size * -0.02`.

## Components

**Screen frame** — 402 × 874 (iPhone 16 Pro logical size). Corner radius
40, shadow `0 12px 30px rgba(0,0,0,0.05)`. Content padding 24 on the
login screen, 20 on content screens.

**Header banner** (login) — `brand` fill, bottom corners 24, status bar
42 tall, then 24 top / 36 bottom padding.

**Input field** — white fill, 1.5px `border`, radius 12, padding 16
horizontal / 12 vertical, 24×24 leading icon, 10 gap. Label sits 8 above.

**Primary button** — `brand` fill, height 52, radius 12, full width,
shadow `0 8px 8px rgba(124,12,62,0.15)`.

**Exercise header** — back button 36×36, centred title, `n of m` counter
right-aligned, then a progress bar 8 tall, full content width, radius
pill, `brand` fill on `border` track.

**Exercise option row** — 362 × 60, 12 gap between rows, 28×28 key
circle (A/B/C/D) at x=20, label at x=60, 20×20 check indicator right.

**Tab bar** — white, 1px `#E5E7EB` top hairline, 24 horizontal / 16
vertical padding, five 64-wide destinations, 24px glyph over a 10px
label with 4 between. Active is `brand`, resting is `textMuted`.

**Icons** — exported from Figma as SVG into `app/assets/icons/`. Each
glyph is nested at its *own* size inside a 24px frame, and that padding
differs per glyph: `angle-right-small` is 5.5 × 9.5 while `home-roof` is
19.5 × 19.5. `DanaIcon` therefore draws the SVG at its intrinsic size,
scaled only by `size / 24`. Fitting the glyph to the box instead
stretches the narrow ones — the chevron rendered at more than twice its
intended width.

Every tab destination has **two** drawings, not one shape recoloured:

| Destination | Resting | Active (filled) |
|---|---|---|
| Main | `nav_home.svg` 19.50 × 19.50 | `nav_home_active.svg` 19.50 × 19.25 |
| Grammar | `nav_grammar.svg` 19.50 × 17.50 | `nav_grammar_active.svg` 18.00 × 15.34 |
| Ranking | `nav_ranking.svg` 19.50 × 19.50 | `nav_ranking_active.svg` 18.00 × 18.47 |
| Dictionary | `nav_dictionary.svg` 17.50 × 19.50 | `nav_dictionary_active.svg` 17.50 × 19.50 |
| Profile | `nav_profile.svg` 15.50 × 19.50 | `nav_profile_active.svg` 15.00 × 19.00 |

Two glyphs keep the colour they were drawn in and are rendered with
`DanaIcon.original`: the clock (`#10B981`) and fire (`#F59E0B`) on the
profile stat tiles, the amber saved bookmark, and the exercise-row tick
(`#10B981`).

**Leaderboard podium** — second `#BDBDBD`, first `accent` `#F59E0B`,
third `#D97706`. Avatars 64 / 86 / 64 with a 2px ring, rank pill 24 × 18
straddling the avatar's bottom edge. Second place's *points* are drawn
in `brand`, not in its ring colour — that is what the file says.

## Screen inventory

| Screen | Node | Built to spec | Verified on device |
|---|---|---|---|
| `login-screen` | `1:722` | Yes | Yes |
| `home-screen` | `6:155` | Yes | Yes |
| `grammar-guide` | `1:194` | Yes | Yes |
| `vocabulary-screen` | `1:402` | Yes | Yes |
| `leaderboard-screen` | `1:294` | Yes | Yes |
| `profile-settings` | `1:607` | Yes | Yes |
| `ExTypes-Test` (multiple choice, new style `126:846/937`) | `1:753` | Yes | Yes |
| `ExTypes-Match Type` | `1:796` | Yes | Not yet — no content |
| `ExTypes-Fill in the blanks` | `1:865` | Yes | Not yet — no content |
| `reorder-words-exercise` | `127:213` | Yes | Not yet on device |
| Correct / Wrong verdict sheets | `126:846`, `126:937` | Yes | Not yet on device |
| `home-screen-units` (View All sheet) | `51:453` | Yes | Not yet on device |
| `unit-vocabulary-screen` | `51:235` | Yes | Not yet on device |
| `vocabulary-screen-modal` | `1:495` | Yes | Yes |
| `splash-screen` (wordmark, `154:245`) | `1:715` | Yes | Yes |
| `login-screen` (wordmark + pinned button, `154:252`) | `1:722` | Yes | Yes |
| Teacher home | `154:298` | Yes | Not yet on device |
| Teacher classroom (Students/Lessons/Ranking) | `155:533`, `156:830` | Yes | Not yet on device |
| Teacher unit detail | `159:1105` | Yes | Not yet on device |
| Notification inbox | — | Built without a spec | Yes — no design exists (FR-10.3) |

New-screen notes (2026-08-06):

- The **dana\*** wordmark ships as `assets/brand/logo.png` (512², brand
  fill baked in) — pulled from the Figma raw image, not redrawn.
- The verdict sheets replace the auto-advancing inline banner: answers
  now wait on the student's Continue, so a wrong answer can be read.
- The teacher unit detail's **Mark Completed** maps onto FR-7.4's
  sequential unlocks: completing the frontier section unlocks the next
  one in level order. Nothing re-locks, no new state was added.
- The teacher course card's schedule line required a new nullable
  `classrooms.schedule_note` (migration 007) — free text, exactly what
  the design shows. The teacher **Ranking** tab has no Figma frame; it
  reuses the student leaderboard's list rows.
- The mock's per-exercise captions ("Speaking section", "Listen and
  Repeat") stay unbuilt: exercises carry generated bilingual titles and
  a question count, and speaking/listening types don't exist (FR-4.3).

## Known mismatches with the requirements

Carried from Q-45–Q-53; the design is a mockup and the requirements win:

- Exercise counters read **"2 of 5"**. Real sets are **7–12** (4–5 for
  match pairs) — the header and progress bar must handle 12 (FR-2.9).
- Profile shows **Interface Language: English**. Only Turkmen and
  Russian ship (FR-2.6).
- Course card reads **"A1 Beginner"**. Levels are names only (FR-3.13).
- Copy in the mockups is English. All UI strings are TM/RU (FR-4.14).
- The leaderboard hint credits the **daily streak** for the ranking. The
  server ranks on points alone and breaks ties by who reached the score
  first (FR-12.8), so the shipped copy states that rule instead. Change
  the copy only if the ranking formula changes with it.
- The home lesson rows use **mic** and **headphones** for two of their
  exercises. Speaking and listening material is excluded from generation
  (FR-4.3), so the four real types take the four neutral glyphs from the
  same set — `book`, `book-open`, `message-text`, `bookmark-outline`.
- Profile's **Feedback** and **Contact Us** rows have no channel behind
  them in the data model — see Q-55.
- The word card's **Meaning** block is filled from `example_en`, labelled
  "Example", because the API stores an example sentence and not a
  definition. Its **speaker button** is drawn as designed but reads as
  disabled: `vocabulary_items.audio_path` exists and nothing fills it —
  there is no audio in the content pipeline and no player dependency in
  the app.
