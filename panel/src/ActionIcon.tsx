/**
 * Glyphs for row actions (FR-15.26).
 *
 * Same drawing rules as NavIcon — 24-unit grid, stroke-only on
 * `currentColor`, round caps — so an icon button inherits the colour of
 * whichever `.btn-*` variant it sits in and needs no per-icon CSS.
 *
 * WHICH buttons lose their words is a judgement, not a sweep. These are
 * for actions that repeat on every row, where the label is the same
 * fifteen times down a page and the context already says what the row
 * is. Anything rare, or whose consequence is not obvious from the glyph
 * — Опубликовать, Снять, Завершить курс, Сбросить пароль — keeps its
 * text, because a button you press once a month should say what it
 * does.
 *
 * Every icon button still carries `title` and `aria-label`, so the word
 * is a hover away and a screen reader never sees a nameless button.
 */
export type ActionIconName =
  | 'trash'
  | 'pencil'
  | 'upload'
  | 'download'
  | 'refresh'
  | 'eye'
  | 'eyeOff';

const PATHS: Record<ActionIconName, JSX.Element> = {
  trash: (
    <>
      <path d="M3 6h18" />
      <path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2" />
      <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
      <path d="M10 11v6" />
      <path d="M14 11v6" />
    </>
  ),
  pencil: (
    <>
      <path d="M12 20h9" />
      <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z" />
    </>
  ),
  upload: (
    <>
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
      <path d="M17 8l-5-5-5 5" />
      <path d="M12 3v13" />
    </>
  ),
  download: (
    <>
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
      <path d="M7 10l5 5 5-5" />
      <path d="M12 15V3" />
    </>
  ),
  refresh: (
    <>
      <path d="M21 12a9 9 0 1 1-2.64-6.36" />
      <path d="M21 3v6h-6" />
    </>
  ),
  eye: (
    <>
      <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" />
      <circle cx="12" cy="12" r="3" />
    </>
  ),
  eyeOff: (
    <>
      <path d="M9.9 5.2A9.5 9.5 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.1" />
      <path d="M6.6 6.6A17 17 0 0 0 2 12s3.5 7 10 7a9.6 9.6 0 0 0 4.4-1" />
      <path d="M3 3l18 18" />
      <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
    </>
  ),
};

export default function ActionIcon({ name }: { name: ActionIconName }) {
  return (
    <svg
      className="action-icon"
      viewBox="0 0 24 24"
      width="16"
      height="16"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      {PATHS[name]}
    </svg>
  );
}
