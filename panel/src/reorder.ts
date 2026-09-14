import { useEffect, useRef, useState } from 'react';

import { ApiError } from './api';

/**
 * Drag-to-reorder as a MODE, off by default (FR-15.16, FR-15.17).
 *
 * The client asked for this shape explicitly: a toggle, then drag, then
 * an explicit save. Rows are inert until the mode is on, so a mis-aimed
 * drag on a page whose main job is something else cannot silently
 * rearrange what students see, and nothing reaches the server until the
 * save button.
 *
 * Two lists use it — a level's units and a unit's child units — and both
 * post the WHOLE id list to an endpoint that refuses anything but a
 * complete one. Keeping the interaction here means the two cannot drift
 * into behaving differently.
 *
 * (The typed-section list on the Content page predates this hook and
 * still carries its own copy, because it also has to pin the exam quiz
 * out of the ordering. Worth folding in the next time that file is
 * opened for another reason.)
 */

export interface Orderable {
  id: number;
}

interface Options<T extends Orderable> {
  /** The live children of a parent, or null if that parent is gone. */
  itemsOf: (parentId: number) => T[] | null;
  save: (parentId: number, order: number[]) => Promise<unknown>;
  onSaved: () => void;
  onError: (message: string) => void;
  /** Re-run the reconcile check when the underlying data changes. */
  deps: unknown[];
}

export function useReorder<T extends Orderable>(options: Options<T>) {
  const [openFor, setOpenFor] = useState<number | null>(null);
  const [draftIds, setDraftIds] = useState<number[]>([]);
  const [baseline, setBaseline] = useState<number[]>([]);
  const [saving, setSaving] = useState(false);

  /**
   * The row being dragged. A ref, not state, because `drop` must read
   * what `dragstart` set even when no render happened in between — a
   * state variable is captured by the handler's closure at render time.
   */
  const dragFrom = useRef<number | null>(null);
  const [dragOver, setDragOver] = useState<number | null>(null);

  // Options are re-created every render; the effect below must not
  // re-run for that reason alone.
  const latest = useRef(options);
  latest.current = options;

  // A child created or deleted elsewhere while the mode is open would
  // leave the draft describing a list that no longer exists, and the
  // server refuses an incomplete list. Close the mode rather than let
  // someone arrange rows into a save that cannot succeed.
  useEffect(() => {
    if (openFor === null) return;

    const live = latest.current.itemsOf(openFor);
    const ids = new Set(live?.map((x) => x.id) ?? []);
    const same = live !== null && ids.size === draftIds.length && draftIds.every((id) => ids.has(id));

    if (!same) {
      setOpenFor(null);
      setDraftIds([]);
      setBaseline([]);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [openFor, draftIds, ...options.deps]);

  function cancel(): void {
    setOpenFor(null);
    setDraftIds([]);
    setBaseline([]);
    dragFrom.current = null;
    setDragOver(null);
  }

  function start(parentId: number, items: T[]): void {
    const ids = items.map((x) => x.id);
    dragFrom.current = null;
    setDragOver(null);
    setOpenFor(parentId);
    setDraftIds(ids);
    setBaseline(ids);
  }

  /** The items in the order the drag has them, live objects throughout. */
  function apply(items: T[]): T[] {
    if (openFor === null || draftIds.length === 0) return items;

    const byId = new Map(items.map((x) => [x.id, x]));

    return draftIds.map((id) => byId.get(id)).filter((x): x is T => x !== undefined);
  }

  /** True once a drag has actually moved something. */
  function changed(): boolean {
    return draftIds.join() !== baseline.join();
  }

  function drop(to: number): void {
    const from = dragFrom.current;

    if (from !== null && to >= 0 && to < draftIds.length && to !== from) {
      const next = [...draftIds];
      const [lifted] = next.splice(from, 1);
      next.splice(to, 0, lifted);
      setDraftIds(next);
    }

    dragFrom.current = null;
    setDragOver(null);
  }

  /**
   * Move a row one place, without dragging (FR-15.25).
   *
   * HTML5 drag-and-drop fires NO events on a touch screen — not
   * dragstart, not drop, nothing — so on the phone and tablet the client
   * asked to work on, the drag below is not merely awkward, it is inert.
   * These two buttons are the whole feature on those devices, and on a
   * desktop they are also the only way to reorder from the keyboard.
   *
   * Deliberately not a touch reimplementation of dragging: a list this
   * short is faster to nudge than to drag, and pointer-event dragging
   * fights the page's own scrolling on a phone.
   */
  function move(index: number, delta: number): void {
    const to = index + delta;

    if (index < 0 || index >= draftIds.length) return;
    if (to < 0 || to >= draftIds.length) return;

    const next = [...draftIds];
    const [lifted] = next.splice(index, 1);
    next.splice(to, 0, lifted);
    setDraftIds(next);
  }

  /**
   * Everything a row needs to be a drag handle and a drop target. The
   * inset shadow marks which side of the row the drop lands on —
   * dragging down inserts below, up inserts above.
   */
  function rowProps(index: number) {
    return {
      draggable: true,
      onDragStart: () => {
        dragFrom.current = index;
      },
      onDragEnd: () => {
        dragFrom.current = null;
        setDragOver(null);
      },
      onDragOver: (e: { preventDefault: () => void }) => {
        // Without preventDefault the browser treats the row as an
        // invalid drop target and the drag ends in a bounce-back.
        e.preventDefault();
        if (dragOver !== index) setDragOver(index);
      },
      onDrop: (e: { preventDefault: () => void }) => {
        e.preventDefault();
        drop(index);
      },
      style: {
        cursor: 'grab',
        boxShadow:
          dragOver === index && dragFrom.current !== null && dragFrom.current !== index
            ? `inset 0 ${dragFrom.current < index ? '-2px' : '2px'} 0 0 currentColor`
            : undefined,
      } as const,
    };
  }

  async function commit(): Promise<void> {
    if (openFor === null) return;

    setSaving(true);

    try {
      await latest.current.save(openFor, draftIds);
      cancel();
      latest.current.onSaved();
    } catch (e: unknown) {
      latest.current.onError(
        e instanceof ApiError ? e.message : 'Не удалось сохранить порядок.',
      );
    } finally {
      setSaving(false);
    }
  }

  return {
    /** Is this parent the one being reordered? */
    isOpen: (parentId: number) => openFor === parentId,
    /** Is some OTHER parent mid-reorder? Its toggle should be disabled. */
    busyElsewhere: (parentId: number) => openFor !== null && openFor !== parentId,
    saving,
    start,
    cancel,
    apply,
    changed,
    rowProps,
    /** How many rows the open draft has, for bounding the move buttons. */
    count: () => draftIds.length,
    move,
    commit,
  };
}
