import { useEffect, useMemo, useRef, useState } from 'react';

import { ApiError, api } from '../api';
import ReorderNudge from '../ReorderNudge';
import { useAsync } from '../hooks';
import SectionEditor from './SectionEditor';

interface SectionRow {
  id: number;
  type: 'grammar' | 'vocabulary' | 'listening' | 'quiz';
  status: 'draft' | 'published';
  title_tk: string | null;
  title_ru: string | null;
  vocabulary: number;
  grammar: boolean;
  sets: number;
  questions: number;
  eligible: number;
  attempts: number;
}

interface ChildUnit {
  id: number;
  level: string;
  unit_id: number;
  unit_number: number;
  /** Manual naming (FR-15.7): the unit's own name, null on legacy rows. */
  unit_name: string | null;
  label: string;
  title: string | null;
  sections: SectionRow[];
}

interface Unit {
  id: number;
  number: number;
  name: string | null;
  level: string;
  childUnits: ChildUnit[];
  sections: number;
  words: number;
  questions: number;
  drafts: number;
}

const TYPE_LABEL: Record<string, string> = {
  grammar: 'Грамматика',
  vocabulary: 'Словарь',
  listening: 'Аудирование',
  quiz: 'Экзамен-квиз',
};

/**
 * The exam quiz does not take part in the ordering: the app gives it its
 * own card below the practice modules (`home-screen-completed`), so a
 * drag that moved it in the panel would promise the student something
 * the design does not do. It is pinned last here so the two agree.
 */
function splitOrderable(sections: SectionRow[]): [SectionRow[], SectionRow[]] {
  return [
    sections.filter((s) => s.type !== 'quiz'),
    sections.filter((s) => s.type === 'quiz'),
  ];
}

/** Lifts `from` out of the list and drops it back in at `to`. */
function moveInList<T>(list: T[], from: number, to: number): T[] {
  const next = [...list];
  const [lifted] = next.splice(from, 1);
  next.splice(to, 0, lifted);
  return next;
}

/**
 * The §13 course shape: level → parent unit → child unit (1A, 1B…) →
 * typed section. The SECTION's status is the visibility gate (FR-13.20)
 * — the pills here publish sections, and everything inside a section
 * ships or hides with it.
 */
export default function Content() {
  const content = useAsync(() => api.get<{ child_units: ChildUnit[] }>('/manage/content'));
  const [error, setError] = useState<string | null>(null);
  const [level, setLevel] = useState<string | null>(null);
  const [openUnit, setOpenUnit] = useState<number | null>(null);
  const [openChildUnit, setOpenChildUnit] = useState<number | null>(null);
  /**
   * Reordering is a MODE, off by default (FR-15.16). Rows only become
   * draggable once it is on, so a mis-aimed drag on a page whose main
   * job is publishing cannot silently rearrange what students see, and
   * nothing is written until «Сохранить порядок».
   *
   * One child unit at a time: the draft below belongs to `reorderFor`.
   */
  const [reorderFor, setReorderFor] = useState<number | null>(null);
  /**
   * IDS, not rows: the section objects themselves keep coming from the
   * live data, so a status published in another tab (or by the pill in
   * this very table) still shows correctly while the mode is open.
   */
  const [draftIds, setDraftIds] = useState<number[]>([]);
  const [baseline, setBaseline] = useState<number[]>([]);
  const [alsoSiblings, setAlsoSiblings] = useState(false);
  const [savingOrder, setSavingOrder] = useState(false);
  /**
   * The row being dragged. A ref, not state, because `drop` must read
   * the value `dragstart` set even when no render happened in between —
   * a state variable is captured by the handler's closure at render
   * time, so a drag that starts and ends inside one tick would drop
   * nowhere. `dragOver` below is state because it only paints.
   */
  const dragFrom = useRef<number | null>(null);
  const [dragOver, setDragOver] = useState<number | null>(null);

  const rows = useMemo(() => content.data?.child_units ?? [], [content.data]);

  const units = useMemo<Unit[]>(() => {
    const map = new Map<number, Unit>();

    for (const child of rows) {
      const unit = map.get(child.unit_id) ?? {
        id: child.unit_id,
        number: child.unit_number,
        name: child.unit_name,
        level: child.level,
        childUnits: [],
        sections: 0,
        words: 0,
        questions: 0,
        drafts: 0,
      };

      unit.childUnits.push(child);
      unit.sections += child.sections.length;
      unit.words += child.sections.reduce((n, s) => n + s.vocabulary, 0);
      unit.questions += child.sections.reduce((n, s) => n + s.questions, 0);
      unit.drafts += child.sections.filter((s) => s.status !== 'published').length;

      map.set(child.unit_id, unit);
    }

    return [...map.values()].sort((a, b) => a.number - b.number);
  }, [rows]);

  const levels = useMemo(() => [...new Set(units.map((u) => u.level))], [units]);
  const activeLevel = level ?? levels[0] ?? null;
  const shown = units.filter((unit) => unit.level === activeLevel);

  const totalDrafts = units.reduce((n, unit) => n + unit.drafts, 0);
  const unit = openUnit === null ? null : (units.find((u) => u.id === openUnit) ?? null);

  // A section deleted or created elsewhere while the mode is open would
  // make the draft describe a list that no longer exists, and the server
  // refuses an incomplete list. Close the mode rather than let the user
  // arrange rows into a save that cannot succeed.
  useEffect(() => {
    if (reorderFor === null) return;

    const child = rows.find((c) => c.id === reorderFor);
    const live = new Set(child?.sections.map((s) => s.id) ?? []);
    const same = live.size === draftIds.length && draftIds.every((id) => live.has(id));

    if (!same) {
      setReorderFor(null);
      setDraftIds([]);
      setBaseline([]);
    }
  }, [rows, reorderFor, draftIds]);

  async function setStatus(sectionId: number, status: 'draft' | 'published') {
    setError(null);
    try {
      await api.post(`/manage/content/sections/${sectionId}/status`, { status });
      content.reload();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось изменить статус.');
    }
  }

  function startReorder(child: ChildUnit) {
    setError(null);
    setAlsoSiblings(false);
    dragFrom.current = null;
    setDragOver(null);
    setReorderFor(child.id);

    const [movable, pinned] = splitOrderable(child.sections);
    const opened = [...movable, ...pinned].map((s) => s.id);

    // The baseline is the list AS OPENED, quiz already pinned last — not
    // the raw server order. Comparing against the raw order would light
    // «Сохранить порядок» up the instant the mode opens on a unit whose
    // stored quiz is not last, offering to save a change nobody made.
    setDraftIds(opened);
    setBaseline(opened);
  }

  function cancelReorder() {
    setReorderFor(null);
    setDraftIds([]);
    setBaseline([]);
    dragFrom.current = null;
    setDragOver(null);
  }

  /**
   * Moves a row one place without dragging.
   *
   * HTML5 drag fires no events on a touch screen, so on a phone or
   * tablet "Изменить порядок" opened a mode that could not do its one
   * job — and it disables the publish pills while it is open, so the
   * page lost its main function too (FR-15.25). Goes through the same
   * splitOrderable/moveInList path as dropAt, so the exam quiz stays
   * pinned last exactly as it does for a drag.
   */
  function nudgeBy(sections: SectionRow[], index: number, delta: number) {
    const [movable, pinned] = splitOrderable(sections);
    const to = index + delta;

    if (index < 0 || index >= movable.length) return;
    if (to < 0 || to >= movable.length) return;

    setDraftIds([...moveInList(movable, index, to), ...pinned].map((s) => s.id));
  }

  /** Drops the row being dragged at `to`, both counted among the movable rows. */
  function dropAt(sections: SectionRow[], to: number) {
    const [movable, pinned] = splitOrderable(sections);
    const from = dragFrom.current;

    if (from !== null && to >= 0 && to < movable.length && to !== from) {
      setDraftIds([...moveInList(movable, from, to), ...pinned].map((s) => s.id));
    }

    dragFrom.current = null;
    setDragOver(null);
  }

  /** FR-15.16: nothing reaches the server until this. */
  async function saveOrder(childId: number) {
    setError(null);
    setSavingOrder(true);

    try {
      await api.post(`/manage/child-units/${childId}/section-order`, {
        order: draftIds,
        also_siblings: alsoSiblings,
      });
      cancelReorder();
      content.reload();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось сохранить порядок.');
    } finally {
      setSavingOrder(false);
    }
  }

  if (openChildUnit !== null) {
    return (
      <SectionEditor
        childUnitId={openChildUnit}
        onBack={() => {
          setOpenChildUnit(null);
          content.reload();
        }}
      />
    );
  }

  /* ------------------------------------------------------ unit detail */

  if (unit) {
    return (
      <>
        <button className="btn btn-ghost btn-sm" onClick={() => setOpenUnit(null)}>
          ← {unit.level}
        </button>

        <h1 style={{ marginTop: 12 }}>{unit.name || `Юнит ${unit.number}`}</h1>
        <p className="sub">
          {unit.childUnits.length} подюнитов · {unit.sections} разделов · {unit.words} слов ·{' '}
          {unit.questions} вопросов
          {unit.drafts > 0 && <> · <strong>{unit.drafts}</strong> в черновиках</>}
        </p>

        {error && <div className="alert alert-error">{error}</div>}

        {unit.childUnits.map((child) => {
          const reordering = reorderFor === child.id;
          // The rows always come from the LIVE data; while the mode is
          // open the draft only decides their sequence.
          const byId = new Map(child.sections.map((s) => [s.id, s]));
          const shownSections = reordering
            ? draftIds.map((id) => byId.get(id)).filter((s): s is SectionRow => s !== undefined)
            : child.sections;
          const [movable] = splitOrderable(shownSections);
          const changed = reordering && shownSections.map((s) => s.id).join() !== baseline.join();

          return (
          <div className="card" key={child.id}>
            <div className="card-head">
              <h2>
                {child.label}{' '}
                {child.title && <span className="muted">— {child.title}</span>}
              </h2>
              <div style={{ display: 'flex', gap: 8 }}>
                {movable.length > 1 && (
                  <button
                    className={reordering ? 'btn btn-sm' : 'btn btn-ghost btn-sm'}
                    title="Изменить порядок разделов и сохранить его"
                    // Only one child unit at a time: switching cards
                    // would throw away an unsaved arrangement without
                    // saying so.
                    disabled={reorderFor !== null && !reordering}
                    onClick={() => (reordering ? cancelReorder() : startReorder(child))}
                  >
                    {reordering ? 'Отменить перестановку' : 'Изменить порядок'}
                  </button>
                )}
                <button
                  className="btn btn-sm"
                  disabled={reordering}
                  onClick={() => setOpenChildUnit(child.id)}
                >
                  Открыть и редактировать
                </button>
              </div>
            </div>

            {child.sections.length === 0 ? (
              <p className="muted" style={{ fontSize: 13, margin: 0 }}>
                Разделов ещё нет — добавьте их в редакторе. Ученик видит ровно те разделы,
                что созданы.
              </p>
            ) : (
              <table>
                <thead>
                  <tr>
                    {reordering && <th style={{ width: 34 }} />}
                    <th>Раздел</th>
                    <th>Тип</th>
                    <th>Наполнение</th>
                    <th>Попыток</th>
                    <th>Статус</th>
                  </tr>
                </thead>
                <tbody>
                  {shownSections.map((section, index) => {
                    const draggable = reordering && section.type !== 'quiz';

                    return (
                    <tr
                      key={section.id}
                      draggable={draggable}
                      onDragStart={draggable ? () => { dragFrom.current = index; } : undefined}
                      onDragEnd={draggable ? () => { dragFrom.current = null; setDragOver(null); } : undefined}
                      onDragOver={
                        draggable
                          ? (e) => {
                              // Without preventDefault the browser treats
                              // the row as an invalid drop target and the
                              // drag ends in a bounce-back.
                              e.preventDefault();
                              if (dragOver !== index) setDragOver(index);
                            }
                          : undefined
                      }
                      onDrop={
                        draggable
                          ? (e) => {
                              e.preventDefault();
                              dropAt(shownSections, index);
                            }
                          : undefined
                      }
                      style={
                        !reordering
                          ? undefined
                          : {
                              cursor: draggable ? 'grab' : 'default',
                              opacity: dragOver !== null && dragFrom.current === index ? 0.4 : 1,
                              // Which side of the row the drop lands on —
                              // dragging down inserts below, up inserts above.
                              boxShadow:
                                dragOver === index &&
                                dragFrom.current !== null &&
                                dragFrom.current !== index
                                  ? `inset 0 ${dragFrom.current < index ? '-2px' : '2px'} 0 0 currentColor`
                                  : undefined,
                            }
                      }
                    >
                      {reordering && (
                        <td className="muted" style={{ textAlign: 'center' }}>
                          {draggable ? (
                            <>
                              <ReorderNudge
                                index={index}
                                count={movable.length}
                                move={(i, d) => nudgeBy(shownSections, i, d)}
                              />
                              <span title="Перетащите строку">⠿</span>
                            </>
                          ) : (
                            <span title="Экзамен-квиз в приложении всегда идёт последним, отдельной карточкой">
                              —
                            </span>
                          )}
                        </td>
                      )}
                      <td>{section.title_ru || TYPE_LABEL[section.type]}</td>
                      <td className="muted">{TYPE_LABEL[section.type] ?? section.type}</td>
                      <td className="muted">{sectionSummary(section, child)}</td>
                      <td>{section.attempts === 0 ? <span className="muted">—</span> : section.attempts}</td>
                      <td>
                        {/* Publishing is disabled while the rows are
                            being dragged: the whole &lt;tr&gt; is the drag
                            handle, so a press on the pill is as likely
                            to start a drag as to click, and the two
                            actions should not compete. */}
                        <StatusPill
                          status={section.status}
                          disabled={reordering}
                          onPublish={() => setStatus(section.id, 'published')}
                          onUnpublish={() => setStatus(section.id, 'draft')}
                        />
                      </td>
                    </tr>
                    );
                  })}
                </tbody>
              </table>
            )}

            {reordering && (
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 12,
                  flexWrap: 'wrap',
                  marginTop: 12,
                }}
              >
                <button
                  className="btn btn-sm"
                  disabled={!changed || savingOrder}
                  onClick={() => void saveOrder(child.id)}
                >
                  {savingOrder ? 'Сохранение…' : 'Сохранить порядок'}
                </button>
                <button className="btn btn-ghost btn-sm" onClick={cancelReorder}>
                  Отмена
                </button>

                {unit.childUnits.length > 1 && (
                  <label className="muted" style={{ fontSize: 13, display: 'flex', gap: 6 }}>
                    <input
                      type="checkbox"
                      checked={alsoSiblings}
                      onChange={(e) => setAlsoSiblings(e.target.checked)}
                    />
                    Применить ко всем подюнитам юнита
                  </label>
                )}

                <span className="muted" style={{ fontSize: 13 }}>
                  Перетащите строку мышью или используйте ▲▼. Ученик увидит разделы в этом порядке.
                </span>
              </div>
            )}
          </div>
          );
        })}
      </>
    );
  }

  /* -------------------------------------------------------- unit list */

  return (
    <>
      <h1>Контент</h1>
      <p className="sub">
        Материалы вносятся вручную или импортом файлов клиента («База данных») и попадают к
        ученикам только после публикации раздела. Доступны все пять типов упражнений.
      </p>

      {error && <div className="alert alert-error">{error}</div>}

      {totalDrafts > 0 && (
        <div className="alert alert-warn">
          Разделов, ожидающих публикации: <strong>{totalDrafts}</strong>
        </div>
      )}

      {content.loading && <p className="muted">Загрузка…</p>}

      {!content.loading && rows.length === 0 && (
        <div className="card muted">
          Учебная программа ещё не создана — начните с раздела «Программа».
        </div>
      )}

      {levels.length > 1 && (
        <div className="segmented" style={{ marginBottom: 16 }}>
          {levels.map((name) => (
            <button
              key={name}
              type="button"
              className={name === activeLevel ? 'on' : ''}
              onClick={() => setLevel(name)}
            >
              {name}
            </button>
          ))}
        </div>
      )}

      {shown.length > 0 && (
        <div className="card">
          <h2>Юниты {activeLevel && <span className="muted">· {activeLevel}</span>}</h2>

          <table>
            <thead>
              <tr>
                <th>Юнит</th>
                <th>Подюнитов</th>
                <th>Разделов</th>
                <th>Слов</th>
                <th>Вопросов</th>
                <th>В черновиках</th>
              </tr>
            </thead>
            <tbody>
              {shown.map((row) => (
                <tr key={row.id} className="row-link" onClick={() => setOpenUnit(row.id)}>
                  <td>
                    <button className="link" onClick={() => setOpenUnit(row.id)}>
                      {row.name || `Юнит ${row.number}`}
                    </button>
                  </td>
                  <td>{row.childUnits.length}</td>
                  <td>{row.sections}</td>
                  <td>{row.words}</td>
                  <td>{row.questions}</td>
                  <td>
                    {row.drafts === 0 ? (
                      <span className="muted">—</span>
                    ) : (
                      <span className="badge">{row.drafts}</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}

/** One line saying what the section holds, by what its type holds. */
function sectionSummary(section: SectionRow, child: ChildUnit): string {
  switch (section.type) {
    case 'vocabulary':
      return `${section.vocabulary} слов · ${section.sets} упражнений · ${section.questions} вопросов`;
    case 'grammar':
      return `${section.sets} упражнений · ${section.questions} вопросов`;
    case 'listening':
      return `${section.sets} упражнений · ${section.questions} вопросов`;
    case 'quiz': {
      // FR-13.4: the quiz serves the SIBLINGS' eligible questions.
      const eligible = child.sections
        .filter((s) => s.type !== 'quiz' && s.status === 'published')
        .reduce((n, s) => n + s.eligible, 0);
      return `${eligible} вопросов из опубликованных разделов`;
    }
  }
}

function StatusPill({
  status,
  disabled = false,
  onPublish,
  onUnpublish,
}: {
  status: 'draft' | 'published';
  disabled?: boolean;
  onPublish: () => void;
  onUnpublish: () => void;
}) {
  if (status === 'published') {
    return (
      <span style={{ display: 'inline-flex', gap: 8, alignItems: 'center' }}>
        <span className="badge" style={{ background: 'rgba(30,127,79,0.1)', color: '#1e7f4f' }}>
          опубликовано
        </span>
        <button className="btn btn-ghost btn-sm" disabled={disabled} onClick={onUnpublish}>
          Снять
        </button>
      </span>
    );
  }

  return (
    <span style={{ display: 'inline-flex', gap: 8, alignItems: 'center' }}>
      <span className="badge">черновик</span>
      <button className="btn btn-sm" disabled={disabled} onClick={onPublish}>
        Опубликовать
      </button>
    </span>
  );
}
