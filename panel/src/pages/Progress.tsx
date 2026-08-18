import { useMemo, useState } from 'react';

import { api, type PanelUser } from '../api';
import { useAsync } from '../hooks';

interface ClassroomProgress {
  id: number;
  name: string;
  center_id: number;
  center: string | null;
  level: string | null;
  teacher: string | null;
  closed: boolean;
  students: number;
  /** Students with at least one completed section attempt. */
  active: number;
  attempts: number;
  /** Mean per-section average across the class, 0–100 (FR-13.8). */
  average: number | null;
}

interface CenterRollup {
  id: number;
  name: string;
  classrooms: number;
  students: number;
  attempts: number;
  weighted: number;
  average: number | null;
}

/**
 * FR-9.3, on the §13 numbers: no points any more (FR-13.7) — a class is
 * described by how many students tried, how many attempts they made and
 * their average correctness.
 *
 * A centre admin sees their classes — one flat table is the whole story.
 * A superadmin sees several centres at once, and a flat list of every
 * class in the chain answers nothing; the question they actually have is
 * "which centre is behind?". So they get a centre roll-up first and drill
 * into one centre for its classes.
 */
export default function Progress({ user }: { user: PanelUser }) {
  const { data, error, loading } = useAsync(() =>
    api.get<{ classrooms: ClassroomProgress[] }>('/manage/progress'),
  );

  const [openCenter, setOpenCenter] = useState<number | null>(null);

  const rows = useMemo(() => data?.classrooms ?? [], [data]);
  const isSuperadmin = user.role === 'superadmin';

  const centers = useMemo(() => {
    const map = new Map<number, CenterRollup>();

    for (const row of rows) {
      const found = map.get(row.center_id) ?? {
        id: row.center_id,
        name: row.center ?? 'Без центра',
        classrooms: 0,
        students: 0,
        attempts: 0,
        // The average cannot be averaged across classes as-is — a class
        // with two attempts would weigh the same as one with two
        // thousand, so each class weighs in by its attempt count.
        weighted: 0,
        average: null,
      };

      found.classrooms += 1;
      found.students += row.students;
      found.attempts += row.attempts;
      found.weighted += row.average === null ? 0 : row.average * row.attempts;

      map.set(row.center_id, found);
    }

    for (const centre of map.values()) {
      centre.average =
        centre.attempts === 0 ? null : Math.round(centre.weighted / centre.attempts);
    }

    return [...map.values()].sort((a, b) => a.name.localeCompare(b.name));
  }, [rows]);

  const totals = rows.reduce(
    (acc, row) => ({
      students: acc.students + row.students,
      attempts: acc.attempts + row.attempts,
      weighted: acc.weighted + (row.average === null ? 0 : row.average * row.attempts),
    }),
    { students: 0, attempts: 0, weighted: 0 },
  );
  const totalAverage =
    totals.attempts === 0 ? null : Math.round(totals.weighted / totals.attempts);

  const open = openCenter === null ? null : centers.find((c) => c.id === openCenter);
  const classesShown = open ? rows.filter((row) => row.center_id === open.id) : rows;

  return (
    <>
      {open ? (
        <>
          <button className="btn btn-ghost btn-sm" onClick={() => setOpenCenter(null)}>
            ← Все центры
          </button>
          <h1 style={{ marginTop: 12 }}>{open.name}</h1>
          <p className="sub">Классы центра и их результаты.</p>
        </>
      ) : (
        <>
          <h1>Прогресс</h1>
          <p className="sub">
            {isSuperadmin
              ? 'Сводка по центрам. Откройте центр, чтобы увидеть его классы.'
              : 'Сводка по классам вашего центра.'}
          </p>
        </>
      )}

      {error && <div className="alert alert-error">{error}</div>}

      <div className="row" style={{ marginBottom: 20 }}>
        {isSuperadmin && !open && <Stat label="Центров" value={`${centers.length}`} />}
        <Stat label="Классов" value={`${classesShown.length}`} />
        <Stat label="Учеников" value={`${open ? open.students : totals.students}`} />
        <Stat label="Попыток" value={`${open ? open.attempts : totals.attempts}`} />
        <Stat
          label="Средний %"
          value={
            (open ? open.average : totalAverage) === null
              ? '—'
              : `${open ? open.average : totalAverage}%`
          }
        />
      </div>

      {loading && <p className="muted">Загрузка…</p>}
      {!loading && rows.length === 0 && (
        <div className="card">
          <p className="muted">Пока нет классов.</p>
        </div>
      )}

      {isSuperadmin && !open && centers.length > 0 && (
        <div className="card">
          <h2>Центры</h2>

          <table>
            <thead>
              <tr>
                <th>Центр</th>
                <th>Классов</th>
                <th>Учеников</th>
                <th>Попыток</th>
                <th>Средний %</th>
              </tr>
            </thead>
            <tbody>
              {centers.map((centre) => (
                <tr key={centre.id} className="row-link" onClick={() => setOpenCenter(centre.id)}>
                  <td>
                    <button className="link" onClick={() => setOpenCenter(centre.id)}>
                      {centre.name}
                    </button>
                  </td>
                  <td>{centre.classrooms}</td>
                  <td>{centre.students}</td>
                  <td>{centre.attempts}</td>
                  <td>{centre.average === null ? '—' : `${centre.average}%`}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {(!isSuperadmin || open) && classesShown.length > 0 && (
        <div className="card">
          <h2>Классы</h2>

          <table>
            <thead>
              <tr>
                <th>Класс</th>
                <th>Уровень</th>
                <th>Преподаватель</th>
                <th>Учеников</th>
                <th>Активных</th>
                <th>Попыток</th>
                <th>Средний %</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {classesShown.map((row) => (
                <tr key={row.id}>
                  <td>
                    {row.name} {row.closed && <span className="badge">завершён</span>}
                  </td>
                  <td className="muted">{row.level ?? '—'}</td>
                  <td className="muted">{row.teacher ?? '—'}</td>
                  <td>{row.students}</td>
                  <td>{row.active}</td>
                  <td>{row.attempts}</td>
                  <td>{row.average === null ? '—' : `${row.average}%`}</td>
                  <td style={{ textAlign: 'right' }}>
                    <button
                      className="btn btn-ghost btn-sm"
                      onClick={() =>
                        api.download(`/manage/classrooms/${row.id}/export`, `${row.name}.csv`)
                      }
                    >
                      CSV
                    </button>
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

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="card" style={{ marginBottom: 0 }}>
      <div className="muted" style={{ fontSize: 11, textTransform: 'uppercase' }}>
        {label}
      </div>
      <div style={{ fontSize: 26, fontWeight: 700, marginTop: 4 }}>{value}</div>
    </div>
  );
}
