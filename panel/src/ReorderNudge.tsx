/**
 * Move-up / move-down buttons for a row being reordered (FR-15.25).
 *
 * The panel reorders by dragging (FR-15.16, FR-15.17), and HTML5
 * drag-and-drop fires NO events on a touch screen — so on the phone and
 * tablet the client asked to work on, dragging is not clumsy, it is
 * inert. These buttons are the whole feature there. On a desktop they
 * are also the only way to reorder from the keyboard, since a native
 * drag cannot be started with one.
 *
 * They appear only while reorder mode is open, next to the drag handle,
 * so the two ways of doing it sit together rather than competing.
 */
export default function ReorderNudge({
  index,
  count,
  move,
}: {
  index: number;
  count: number;
  /** Shift this row by delta places within the draft order. */
  move: (index: number, delta: number) => void;
}) {
  return (
    <span className="nudge">
      <button
        type="button"
        className="nudge-btn"
        // The ends of the list have nowhere to go. Disabled rather than
        // hidden, so the control does not jump around as rows move.
        disabled={index === 0}
        aria-label="Переместить выше"
        title="Переместить выше"
        // The row itself is a drag source; without this a press on the
        // button starts a drag instead of clicking it.
        draggable={false}
        onDragStart={(e) => e.preventDefault()}
        onClick={() => move(index, -1)}
      >
        ▲
      </button>
      <button
        type="button"
        className="nudge-btn"
        disabled={index >= count - 1}
        aria-label="Переместить ниже"
        title="Переместить ниже"
        draggable={false}
        onDragStart={(e) => e.preventDefault()}
        onClick={() => move(index, 1)}
      >
        ▼
      </button>
    </span>
  );
}
