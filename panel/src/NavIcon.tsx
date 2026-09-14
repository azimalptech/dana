/**
 * The sidebar's glyphs (FR-15.25).
 *
 * Inline SVG rather than an icon package: there are eight of them, they
 * never change, and a dependency would ship a few hundred for the eight.
 * Every path is stroke-only on `currentColor`, so one rule colours the
 * icon and its label together and the active/hover states need no
 * icon-specific CSS.
 *
 * Drawn on a 24-unit grid with round caps and joins, matching the
 * reference the client supplied — thin outline, no fills, no two-tone.
 * They carry `aria-hidden` because every one sits beside a text label,
 * or, in the collapsed rail, beside a `title` and an `aria-label` on the
 * button itself.
 */
export type NavIconName =
  | 'progress'
  | 'people'
  | 'classrooms'
  | 'notify'
  | 'content'
  | 'curriculum'
  | 'data'
  | 'signout';

const PATHS: Record<NavIconName, JSX.Element> = {
  // Bar chart — metrics.
  progress: (
    <>
      <path d="M4 20V10" />
      <path d="M10 20V4" />
      <path d="M16 20v-6" />
      <path d="M22 20H2" />
    </>
  ),
  // Two figures — centres and their staff.
  people: (
    <>
      <path d="M16 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
      <circle cx="9" cy="7" r="3.2" />
      <path d="M22 20v-2a4 4 0 0 0-3-3.87" />
      <path d="M16 4.13a4 4 0 0 1 0 7.75" />
    </>
  ),
  // A board — classrooms.
  classrooms: (
    <>
      <rect x="3" y="4" width="18" height="13" rx="2" />
      <path d="M8 21h8" />
      <path d="M12 17v4" />
    </>
  ),
  // Bell — announcements.
  notify: (
    <>
      <path d="M18 8a6 6 0 1 0-12 0c0 6-2 7-2 7h16s-2-1-2-7" />
      <path d="M13.7 20a2 2 0 0 1-3.4 0" />
    </>
  ),
  // Stacked layers — the content that fills a section.
  content: (
    <>
      <path d="M12 2.5 3 7l9 4.5L21 7z" />
      <path d="M3 12l9 4.5L21 12" />
      <path d="M3 17l9 4.5L21 17" />
    </>
  ),
  // An open book — the course programme.
  curriculum: (
    <>
      <path d="M2 4.5h6a3 3 0 0 1 3 3V20a2.5 2.5 0 0 0-2.5-2.5H2z" />
      <path d="M22 4.5h-6a3 3 0 0 0-3 3V20a2.5 2.5 0 0 1 2.5-2.5H22z" />
    </>
  ),
  // Cylinder — the database.
  data: (
    <>
      <ellipse cx="12" cy="5.5" rx="8" ry="3" />
      <path d="M4 5.5v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6" />
      <path d="M4 11.5v7c0 1.66 3.58 3 8 3s8-1.34 8-3v-7" />
    </>
  ),
  // Door with an arrow leaving it.
  signout: (
    <>
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
      <path d="M16 17l5-5-5-5" />
      <path d="M21 12H9" />
    </>
  ),
};

export default function NavIcon({ name }: { name: NavIconName }) {
  return (
    <svg
      className="nav-icon"
      viewBox="0 0 24 24"
      width="20"
      height="20"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.7"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      {PATHS[name]}
    </svg>
  );
}
