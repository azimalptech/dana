import { useMemo, useState } from 'react';

import { ApiError, api } from '../api';
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

  async function setStatus(sectionId: number, status: 'draft' | 'published') {
    setError(null);
    try {
      await api.post(`/manage/content/sections/${sectionId}/status`, { status });
      content.reload();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось изменить статус.');
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

        {unit.childUnits.map((child) => (
          <div className="card" key={child.id}>
            <div className="card-head">
              <h2>
                {child.label}{' '}
                {child.title && <span className="muted">— {child.title}</span>}
              </h2>
              <button className="btn btn-sm" onClick={() => setOpenChildUnit(child.id)}>
                Открыть и редактировать
              </button>
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
                    <th>Раздел</th>
                    <th>Тип</th>
                    <th>Наполнение</th>
                    <th>Попыток</th>
                    <th>Статус</th>
                  </tr>
                </thead>
                <tbody>
                  {child.sections.map((section) => (
                    <tr key={section.id}>
                      <td>{section.title_ru || TYPE_LABEL[section.type]}</td>
                      <td className="muted">{TYPE_LABEL[section.type] ?? section.type}</td>
                      <td className="muted">{sectionSummary(section, child)}</td>
                      <td>{section.attempts === 0 ? <span className="muted">—</span> : section.attempts}</td>
                      <td>
                        <StatusPill
                          status={section.status}
                          onPublish={() => setStatus(section.id, 'published')}
                          onUnpublish={() => setStatus(section.id, 'draft')}
                        />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        ))}
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
  onPublish,
  onUnpublish,
}: {
  status: 'draft' | 'published';
  onPublish: () => void;
  onUnpublish: () => void;
}) {
  if (status === 'published') {
    return (
      <span style={{ display: 'inline-flex', gap: 8, alignItems: 'center' }}>
        <span className="badge" style={{ background: 'rgba(30,127,79,0.1)', color: '#1e7f4f' }}>
          опубликовано
        </span>
        <button className="btn btn-ghost btn-sm" onClick={onUnpublish}>
          Снять
        </button>
      </span>
    );
  }

  return (
    <span style={{ display: 'inline-flex', gap: 8, alignItems: 'center' }}>
      <span className="badge">черновик</span>
      <button className="btn btn-sm" onClick={onPublish}>
        Опубликовать
      </button>
    </span>
  );
}
