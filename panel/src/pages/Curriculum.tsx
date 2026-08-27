import { useMemo, useState } from 'react';

import { ApiError, api } from '../api';
import { useAsync } from '../hooks';

interface SectionRow {
  id: number;
  code: string;
  label: string | null;
  title: string | null;
  level_position: number;
}

interface UnitRow {
  id: number;
  number: number;
  name: string | null;
  title: string | null;
  sections: SectionRow[];
}

interface LevelRow {
  id: number;
  name: string;
  is_active: boolean;
  units: UnitRow[];
}

/** Display identity: the manual name verbatim, else the legacy number. */
function unitName(unit: UnitRow): string {
  return unit.name || `Юнит ${unit.number}`;
}

/** Child unit identity: the manual label verbatim, else {number}{code}. */
function sectionName(unitNumber: number, section: SectionRow): string {
  return section.label || `${unitNumber}${section.code}`;
}

/**
 * FR-3.3 / FR-3.4 / FR-3.12: levels, units and sections — the course
 * skeleton. Books, page ranges and uploads are GONE (FR-14.5, contract
 * 06 §4): content arrives only via manual authoring or the client's
 * xlsx files on the «База данных» page.
 *
 * The course is a tree, and rendering all of it at once put every
 * level's units and sections — with a creation form beside each — on one
 * page. It is presented one level at a time, with units opened
 * individually, so the page only ever shows the thing being edited.
 */
export default function Curriculum() {
  const tree = useAsync(() => api.get<{ levels: LevelRow[] }>('/manage/curriculum'));
  const [error, setError] = useState<string | null>(null);
  const [levelId, setLevelId] = useState<number | null>(null);
  const [openUnit, setOpenUnit] = useState<number | null>(null);
  const [addingLevel, setAddingLevel] = useState(false);
  const [newLevel, setNewLevel] = useState('');

  async function run(action: () => Promise<unknown>) {
    setError(null);
    try {
      await action();
      tree.reload();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось сохранить.');
    }
  }

  /**
   * Deletes with the attempts_exist handshake (same pattern as the
   * SectionEditor): the server refuses to cascade student attempts
   * without force=1, so the loss is confirmed twice. A plain validation
   * refusal (e.g. classrooms still use the level) is just shown.
   */
  async function remove(path: string, question: string, after?: () => void) {
    if (!confirm(question)) return;

    setError(null);
    try {
      await api.del(path);
      after?.();
      tree.reload();
    } catch (e: unknown) {
      if (e instanceof ApiError && e.code === 'attempts_exist') {
        if (confirm(`${e.message}\n\nУдалить вместе с попытками учеников?`)) {
          try {
            await api.del(`${path}?force=1`);
            after?.();
            tree.reload();
          } catch (e2: unknown) {
            setError(e2 instanceof ApiError ? e2.message : 'Не удалось удалить.');
          }
        }
      } else {
        setError(e instanceof ApiError ? e.message : 'Не удалось удалить.');
      }
    }
  }

  const levels = useMemo(() => tree.data?.levels ?? [], [tree.data]);
  const level = levels.find((l) => l.id === levelId) ?? levels[0] ?? null;
  const unit = level?.units.find((u) => u.id === openUnit) ?? null;

  /* ------------------------------------------------------ unit detail */

  if (level && unit) {
    return (
      <>
        <button className="btn btn-ghost btn-sm" onClick={() => setOpenUnit(null)}>
          ← {level.name}
        </button>

        <h1 style={{ marginTop: 12 }}>
          {unitName(unit)} {unit.title && <span className="muted">— {unit.title}</span>}
        </h1>
        <p className="sub">
          Подюниты (1-A, 1-B…). Их содержимое — слова, упражнения, квиз — редактируется на
          странице «Контент» или загружается файлами клиента на странице «База данных».
        </p>

        {error && <div className="alert alert-error">{error}</div>}

        {unit.sections.length === 0 && (
          <div className="card muted">
            У юнита нет разделов. Весь контент крепится к разделу, а не к юниту (FR-3.12).
          </div>
        )}

        {unit.sections.map((section) => (
          <SectionCard
            key={section.id}
            unitNumber={unit.number}
            section={section}
            run={run}
            remove={remove}
          />
        ))}

        <AddSection unitId={unit.id} run={run} />
      </>
    );
  }

  /* ----------------------------------------------------- level layout */

  return (
    <>
      <h1>Программа</h1>
      <p className="sub">
        Уровни, юниты и подюниты — скелет курса. Контент вносится на страницах «Контент» и
        «База данных».
      </p>

      {error && <div className="alert alert-error">{error}</div>}
      {tree.loading && <p className="muted">Загрузка…</p>}

      {!tree.loading && levels.length === 0 && (
        <div className="card muted">Уровней ещё нет. Создайте первый.</div>
      )}

      {levels.length > 0 && (
        <div className="card-head" style={{ marginBottom: 16 }}>
          <div className="segmented">
            {levels.map((row) => (
              <button
                key={row.id}
                type="button"
                className={row.id === level?.id ? 'on' : ''}
                onClick={() => {
                  setLevelId(row.id);
                  setOpenUnit(null);
                }}
              >
                {row.name}
              </button>
            ))}
          </div>

          <span style={{ whiteSpace: 'nowrap' }}>
            <button
              className={addingLevel ? 'btn btn-ghost btn-sm' : 'btn btn-sm'}
              onClick={() => setAddingLevel(!addingLevel)}
            >
              {addingLevel ? 'Отмена' : '+ Уровень'}
            </button>{' '}
            {level && (
              <button
                className="btn btn-danger btn-sm"
                onClick={() =>
                  void remove(
                    `/manage/levels/${level.id}`,
                    `Удалить уровень «${level.name}» со всеми юнитами и контентом?`,
                    () => {
                      setLevelId(null);
                      setOpenUnit(null);
                    },
                  )
                }
              >
                Удалить уровень
              </button>
            )}
          </span>
        </div>
      )}

      {(addingLevel || levels.length === 0) && (
        <div className="card">
          <h2>Новый уровень</h2>
          <div className="row">
            <div className="field">
              <label htmlFor="level-name">Название</label>
              <input
                id="level-name"
                value={newLevel}
                onChange={(e) => setNewLevel(e.target.value)}
                placeholder="Elementary"
              />
              <p className="hint">Только название, без кода CEFR (FR-3.13).</p>
            </div>
            <div className="field" style={{ display: 'flex', alignItems: 'flex-end' }}>
              <button
                className="btn"
                disabled={newLevel.trim() === ''}
                onClick={async () => {
                  await run(() => api.post('/manage/levels', { name: newLevel }));
                  setNewLevel('');
                  setAddingLevel(false);
                }}
              >
                Создать уровень
              </button>
            </div>
          </div>
        </div>
      )}

      {level && <UnitsCard level={level} run={run} remove={remove} onOpen={setOpenUnit} />}
    </>
  );
}

type Remove = (path: string, question: string, after?: () => void) => Promise<void>;

/* -------------------------------------------------------------- units */

function UnitsCard({
  level,
  run,
  remove,
  onOpen,
}: {
  level: LevelRow;
  run: (a: () => Promise<unknown>) => Promise<void>;
  remove: Remove;
  onOpen: (id: number) => void;
}) {
  const [adding, setAdding] = useState(false);
  const [name, setName] = useState('');
  const [title, setTitle] = useState('');

  return (
    <div className="card">
      <div className="card-head">
        <h2>
          Юниты {level.units.length > 0 && <span className="muted">· {level.units.length}</span>}
        </h2>
        <button
          className={adding ? 'btn btn-ghost btn-sm' : 'btn btn-sm'}
          onClick={() => setAdding(!adding)}
        >
          {adding ? 'Отмена' : '+ Юнит'}
        </button>
      </div>

      {adding && (
        <div className="inset">
          <div className="row">
            <div className="field" style={{ maxWidth: 220 }}>
              <label htmlFor="u-name">Название юнита</label>
              <input
                id="u-name"
                autoFocus
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Unit 1"
              />
              {/* Parent units are containers (FR-13.1): students see the
                  child-unit labels, so this name lives in the panel only. */}
              <p className="hint">Имя для панели. Ученики видят подюниты — их имя задаётся ниже.</p>
            </div>
            <div className="field">
              <label htmlFor="u-title">Подзаголовок (необязательно)</label>
              <input id="u-title" value={title}
                onChange={(e) => setTitle(e.target.value)} placeholder="A cappuccino, please" />
            </div>
            <div className="field" style={{ display: 'flex', alignItems: 'flex-end' }}>
              <button
                className="btn btn-sm"
                disabled={name.trim() === ''}
                onClick={async () => {
                  await run(() =>
                    api.post('/manage/units', {
                      level_id: level.id,
                      name: name.trim(),
                      title,
                    }),
                  );
                  setName('');
                  setTitle('');
                  setAdding(false);
                }}
              >
                Создать юнит
              </button>
            </div>
          </div>
        </div>
      )}

      {level.units.length === 0 && <p className="muted">Юнитов ещё нет.</p>}

      {level.units.length > 0 && (
        <table>
          <thead>
            <tr>
              <th>Юнит</th>
              <th>Подзаголовок</th>
              <th>Разделов</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {level.units.map((unit) => (
              <UnitRowView key={unit.id} unit={unit} run={run} remove={remove} onOpen={onOpen} />
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

/** One unit row: opens on click, edits in place (client, 2026-08-13). */
function UnitRowView({
  unit,
  run,
  remove,
  onOpen,
}: {
  unit: UnitRow;
  run: (a: () => Promise<unknown>) => Promise<void>;
  remove: Remove;
  onOpen: (id: number) => void;
}) {
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState(unit.name ?? '');
  const [title, setTitle] = useState(unit.title ?? '');

  if (editing) {
    return (
      <tr>
        <td>
          <input
            value={name}
            placeholder={`Юнит ${unit.number}`}
            onChange={(e) => setName(e.target.value)}
          />
        </td>
        <td>
          <input value={title} onChange={(e) => setTitle(e.target.value)} />
        </td>
        <td colSpan={2} style={{ whiteSpace: 'nowrap' }}>
          <button
            className="btn btn-sm"
            disabled={name.trim() === ''}
            onClick={async () => {
              await run(() =>
                api.post(`/manage/units/${unit.id}`, {
                  name: name.trim(),
                  title,
                }),
              );
              setEditing(false);
            }}
          >
            Сохранить
          </button>{' '}
          <button className="btn btn-ghost btn-sm" onClick={() => setEditing(false)}>
            Отмена
          </button>
        </td>
      </tr>
    );
  }

  return (
    <tr key={unit.id} className="row-link" onClick={() => onOpen(unit.id)}>
      <td>
        <button className="link" onClick={() => onOpen(unit.id)}>
          {unitName(unit)}
        </button>
      </td>
      <td className="muted">{unit.title ?? '—'}</td>
      <td>{unit.sections.length}</td>
      <td style={{ whiteSpace: 'nowrap', textAlign: 'right' }}>
        <button
          className="btn btn-ghost btn-sm"
          onClick={(e) => {
            e.stopPropagation();
            setName(unit.name ?? '');
            setTitle(unit.title ?? '');
            setEditing(true);
          }}
        >
          Изменить
        </button>{' '}
        <button
          className="btn btn-danger btn-sm"
          onClick={(e) => {
            e.stopPropagation();
            void remove(
              `/manage/units/${unit.id}`,
              `Удалить «${unitName(unit)}» со всеми подюнитами и контентом?`,
            );
          }}
        >
          Удалить
        </button>
      </td>
    </tr>
  );
}

/* ----------------------------------------------------------- sections */

/** Code, display name and title of one child unit. */
function SectionCard({
  unitNumber,
  section,
  run,
  remove,
}: {
  unitNumber: number;
  section: SectionRow;
  run: (a: () => Promise<unknown>) => Promise<void>;
  remove: Remove;
}) {
  const [editing, setEditing] = useState(false);
  const [code, setCode] = useState(section.code);
  const [label, setLabel] = useState(section.label ?? '');
  const [title, setTitle] = useState(section.title ?? '');

  return (
    <div className="card">
      <div className="card-head">
        <h2 style={{ margin: 0 }}>
          {sectionName(unitNumber, section)}{' '}
          {section.title && <span className="muted">— {section.title}</span>}
        </h2>
        <span style={{ whiteSpace: 'nowrap' }}>
          <button className="btn btn-ghost btn-sm" onClick={() => setEditing(!editing)}>
            {editing ? 'Отмена' : 'Изменить'}
          </button>{' '}
          <button
            className="btn btn-danger btn-sm"
            onClick={() =>
              void remove(
                `/manage/sections/${section.id}`,
                `Удалить подюнит «${sectionName(unitNumber, section)}» со всем содержимым?`,
              )
            }
          >
            Удалить подюнит
          </button>
        </span>
      </div>

      {editing && (
        <div className="row" style={{ marginBottom: 10 }}>
          <div className="field" style={{ maxWidth: 120 }}>
            <label>Код</label>
            <input value={code} onChange={(e) => setCode(e.target.value)} />
          </div>
          <div className="field" style={{ maxWidth: 200 }}>
            <label>Отображаемое имя</label>
            <input
              value={label}
              placeholder={`${unitNumber}${section.code}`}
              onChange={(e) => setLabel(e.target.value)}
            />
          </div>
          <div className="field">
            <label>Название</label>
            <input value={title} onChange={(e) => setTitle(e.target.value)} />
          </div>
          <div className="field" style={{ maxWidth: 160, display: 'flex', alignItems: 'flex-end' }}>
            <button
              className="btn btn-sm"
              disabled={code.trim() === ''}
              onClick={async () => {
                await run(() =>
                  api.post(`/manage/sections/${section.id}`, { code, label, title }),
                );
                setEditing(false);
              }}
            >
              Сохранить
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function AddSection({
  unitId,
  run,
}: {
  unitId: number;
  run: (a: () => Promise<unknown>) => Promise<void>;
}) {
  const [open, setOpen] = useState(false);
  const [code, setCode] = useState('');
  const [label, setLabel] = useState('');
  const [title, setTitle] = useState('');

  if (!open) {
    return (
      <button className="btn btn-sm" onClick={() => setOpen(true)}>
        + Раздел
      </button>
    );
  }

  return (
    <div className="card">
      <h2>Новый раздел</h2>

      <div className="row">
        <div className="field" style={{ maxWidth: 120 }}>
          <label htmlFor="s-code">Код</label>
          <input id="s-code" autoFocus value={code}
            onChange={(e) => setCode(e.target.value)} placeholder="A" />
        </div>
        <div className="field" style={{ maxWidth: 220 }}>
          <label htmlFor="s-label">Отображаемое имя (необязательно)</label>
          <input id="s-label" value={label}
            onChange={(e) => setLabel(e.target.value)} placeholder="1A" />
        </div>
        <div className="field">
          <label htmlFor="s-title">Название</label>
          <input id="s-title" value={title} onChange={(e) => setTitle(e.target.value)} />
        </div>
      </div>

      <button
        className="btn btn-sm"
        disabled={code.trim() === ''}
        onClick={async () => {
          await run(() => api.post('/manage/sections', { unit_id: unitId, code, label, title }));
          setCode('');
          setLabel('');
          setTitle('');
          setOpen(false);
        }}
      >
        Создать раздел
      </button>{' '}
      <button className="btn btn-ghost btn-sm" onClick={() => setOpen(false)}>
        Отмена
      </button>
    </div>
  );
}

// Books, book sets and page ranges removed entirely (FR-14.5,
// contract 06 §4) — no upload, no sources, no bindings.
