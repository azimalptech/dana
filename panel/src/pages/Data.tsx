import { useRef, useState } from 'react';

import { ApiError, api } from '../api';
import { useAsync } from '../hooks';

interface UnitRow {
  id: number;
  number: number;
  /** Manual naming (FR-15.7): explicit name wins, null composes the legacy id. */
  name: string | null;
  title: string | null;
  child_units: number;
}

interface LevelRow {
  id: number;
  name: string;
  units: UnitRow[];
}

interface Summary {
  counts: {
    levels: number;
    units: number;
    child_units: number;
    sections_published: number;
    sections_draft: number;
    words: number;
    exercise_sets: number;
    questions: number;
  };
  levels: LevelRow[];
}

interface ImportRowError {
  sheet: string;
  row: number;
  message_ru: string;
}

/**
 * Contract 06: one row per audio/image part still waiting for its file —
 * the superadmin's upload worklist after an import.
 */
interface MediaPendingRow {
  question: string;
  part: string;
  note: string;
}

interface ImportResult {
  created: Record<string, number>;
  updated: Record<string, number>;
  errors: ImportRowError[];
  media_pending: MediaPendingRow[];
}

/** Import counters, in the order the workbook creates things. */
const ENTITY_LABEL: Record<string, string> = {
  levels: 'Уровни',
  units: 'Юниты',
  child_units: 'Подюниты',
  sections: 'Разделы',
  vocabulary_items: 'Слова',
  exercise_sets: 'Упражнения',
  questions: 'Вопросы',
  quiz_targets: 'Цели квизов',
};

/** stem / opt0..opt3 → readable part names for the media worklist. */
function partLabel(part: string): string {
  if (part === 'stem') return 'вопрос';
  const i = Number(part.slice(3));
  return `вариант ${'ABCD'[i] ?? part}`;
}

/**
 * «База данных» — the superadmin's spreadsheet door (FR-13.15, contract
 * 06): the course content in the client's five-sheet workbook shapes,
 * out and back in. Import accepts one or several .xlsx files in a single
 * request; `Question ID` / `Word ID` is the upsert key. Everything an
 * import creates or touches lands as a DRAFT behind the publish gate
 * (FR-13.20). Student data never travels in either direction.
 */
export default function Data() {
  const summary = useAsync(() => api.get<Summary>('/manage/data-summary'));

  const [error, setError] = useState<string | null>(null);
  const [openLevel, setOpenLevel] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<ImportResult | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const counts = summary.data?.counts ?? null;
  const levels = summary.data?.levels ?? [];

  // Same name the server would put in Content-Disposition.
  const stamp = new Date().toISOString().slice(0, 10).replace(/-/g, '');

  async function exportScope(query: string, filename: string) {
    setError(null);
    try {
      await api.download(`/manage/export-xlsx?${query}`, filename);
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось скачать.');
    }
  }

  async function importFiles() {
    const files = [...(fileRef.current?.files ?? [])];

    if (files.length === 0) {
      setError('Сначала выберите файлы .xlsx.');
      return;
    }

    setBusy(true);
    setError(null);
    setResult(null);

    try {
      // All selected workbooks travel in ONE multipart request — the
      // importer sees the client's five files as one delivery.
      const form = new FormData();
      for (const file of files) {
        form.append('files[]', file);
      }

      // `api` speaks JSON only, so the one multipart request goes
      // through fetch directly — same base path and Authorization
      // header as api.ts sends.
      const response = await fetch('/api/v1/manage/import-xlsx', {
        method: 'POST',
        headers: { Authorization: `Bearer ${localStorage.getItem('panel_access') ?? ''}` },
        body: form,
      });

      const body = (await response.json().catch(() => null)) as
        | ImportResult
        | { error?: { message_ru?: string } }
        | null;

      if (!response.ok || body === null || !('created' in body)) {
        const shape = body as { error?: { message_ru?: string } } | null;
        setError(shape?.error?.message_ru ?? 'Не удалось импортировать файлы.');
        return;
      }

      setResult(body);

      if (body.errors.length === 0) {
        if (fileRef.current) fileRef.current.value = '';
        summary.reload();
      }
    } catch {
      setError('Нет соединения с сервером.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <h1>База данных</h1>
      <p className="sub">
        Все материалы курса в формате рабочих файлов клиента: экспорт для правки в Excel и
        обратный импорт. Данные учеников не выгружаются и не затрагиваются.
      </p>

      {error && <div className="alert alert-error">{error}</div>}
      {summary.error && <div className="alert alert-error">{summary.error}</div>}
      {summary.loading && <p className="muted">Загрузка…</p>}

      {/* ------------------------------------------------------ summary */}

      {counts && (
        <div className="card">
          <h2>Сводка</h2>
          <table>
            <thead>
              <tr>
                <th>Уровни</th>
                <th>Юниты</th>
                <th>Подюниты</th>
                <th>Разделы · опубл.</th>
                <th>Разделы · черн.</th>
                <th>Слова</th>
                <th>Упражнения</th>
                <th>Вопросы</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>{counts.levels}</td>
                <td>{counts.units}</td>
                <td>{counts.child_units}</td>
                <td>{counts.sections_published}</td>
                <td>
                  {counts.sections_draft === 0 ? (
                    <span className="muted">—</span>
                  ) : (
                    <span className="badge">{counts.sections_draft}</span>
                  )}
                </td>
                <td>{counts.words}</td>
                <td>{counts.exercise_sets}</td>
                <td>{counts.questions}</td>
              </tr>
            </tbody>
          </table>
        </div>
      )}

      {/* ------------------------------------------------------- export */}

      {summary.data && (
        <div className="card">
          <div className="card-head">
            <h2>Экспорт</h2>
            <button
              className="btn"
              onClick={() => exportScope('scope=all', `dana-content-all-${stamp}.xlsx`)}
            >
              Вся база
            </button>
          </div>

          {levels.length === 0 ? (
            <p className="muted" style={{ fontSize: 13, margin: 0 }}>
              Уровней ещё нет — создайте программу в разделе «Программа».
            </p>
          ) : (
            <table>
              <thead>
                <tr>
                  <th>Уровень</th>
                  <th>Юнитов</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {levels.map((level) => (
                  <LevelExportRows
                    key={level.id}
                    level={level}
                    open={openLevel === level.id}
                    onToggle={() => setOpenLevel(openLevel === level.id ? null : level.id)}
                    onExport={exportScope}
                    stamp={stamp}
                  />
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {/* ------------------------------------------------------- import */}

      <div className="card">
        <h2>Импорт</h2>

        <div className="alert alert-error">
          Всё, что создаёт или меняет импорт, попадает в <strong>ЧЕРНОВИКИ</strong> — ученики
          этого не увидят, пока каждый раздел не будет опубликован на странице «Контент»
          (FR-13.20).
        </div>

        <div className="with-action">
          <input type="file" accept=".xlsx" multiple ref={fileRef} disabled={busy} />
          <button className="btn" disabled={busy} onClick={importFiles}>
            {busy ? 'Загрузка…' : 'Импортировать'}
          </button>
        </div>
        <p className="hint">
          Пять рабочих файлов клиента — <code>Listening.xlsx</code>, <code>Grammar.xlsx</code>,{' '}
          <code>Vocabulary.xlsx</code>, <code>UnitQuiz.xlsx</code>, <code>Wordlist.xlsx</code> —
          можно выбрать все сразу или загружать по одному. <code>Question ID</code> /{' '}
          <code>Word ID</code> обновляет существующую запись, новый код — создаёт. При любой
          ошибке всё отклоняется целиком и база остаётся без изменений.
        </p>

        {result && result.errors.length > 0 && (
          <>
            <div className="alert alert-error" style={{ marginTop: 16 }}>
              Ошибок: <strong>{result.errors.length}</strong>. Ничего не записано — исправьте
              строки и загрузите файлы ещё раз.
            </div>
            <table>
              <thead>
                <tr>
                  <th>Лист</th>
                  <th>Строка</th>
                  <th>Ошибка</th>
                </tr>
              </thead>
              <tbody>
                {result.errors.map((row, i) => (
                  <tr key={i}>
                    <td>{row.sheet}</td>
                    <td>{row.row}</td>
                    <td>{row.message_ru}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </>
        )}

        {result && result.errors.length === 0 && (
          <>
            <div className="alert alert-ok" style={{ marginTop: 16 }}>
              Импорт выполнен. Новые и изменённые разделы — в черновиках.
            </div>
            <table>
              <thead>
                <tr>
                  <th />
                  <th>Создано</th>
                  <th>Обновлено</th>
                </tr>
              </thead>
              <tbody>
                {[
                  ...new Set([
                    ...Object.keys(ENTITY_LABEL),
                    ...Object.keys(result.created),
                    ...Object.keys(result.updated),
                  ]),
                ].map((key) => (
                  <tr key={key}>
                    <td>{ENTITY_LABEL[key] ?? key}</td>
                    <td>{cell(result.created[key])}</td>
                    <td>{cell(result.updated[key])}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </>
        )}

        {result && (result.media_pending ?? []).length > 0 && (
          <>
            <div className="alert alert-warn" style={{ marginTop: 16 }}>
              Файлов не хватает: <strong>{result.media_pending.length}</strong>. Эти вопросы
              скрыты от учеников, пока аудио и картинки не будут загружены — по заметке из
              таблицы найдите вопрос на странице «Контент» и прикрепите файл.
            </div>
            <table>
              <thead>
                <tr>
                  <th>Вопрос</th>
                  <th>Часть</th>
                  <th>Заметка</th>
                </tr>
              </thead>
              <tbody>
                {result.media_pending.map((row, i) => (
                  <tr key={i}>
                    <td>
                      <code>{row.question}</code>
                    </td>
                    <td>{partLabel(row.part)}</td>
                    <td>{row.note}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </>
        )}
      </div>
    </>
  );
}

/** A zero reads as "nothing happened", so it renders as a dash. */
function cell(count: number | undefined) {
  return !count ? <span className="muted">—</span> : count;
}

/**
 * One level in the export table: the level row itself, plus — when
 * opened — a row per parent unit, matching the three export scopes.
 */
function LevelExportRows({
  level,
  open,
  onToggle,
  onExport,
  stamp,
}: {
  level: LevelRow;
  open: boolean;
  onToggle: () => void;
  onExport: (query: string, filename: string) => void;
  stamp: string;
}) {
  return (
    <>
      <tr>
        <td>
          <button className="link" onClick={onToggle}>
            {level.name}
          </button>
        </td>
        <td className="muted">{level.units.length}</td>
        <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
          <button className="btn btn-ghost btn-sm" style={{ marginRight: 8 }} onClick={onToggle}>
            {open ? 'Свернуть юниты' : 'Юниты…'}
          </button>
          <button
            className="btn btn-sm"
            onClick={() =>
              onExport(`scope=level&id=${level.id}`, `dana-content-level-${level.id}-${stamp}.xlsx`)
            }
          >
            Скачать .xlsx
          </button>
        </td>
      </tr>

      {open && level.units.length === 0 && (
        <tr>
          <td colSpan={3} className="muted">
            В уровне ещё нет юнитов.
          </td>
        </tr>
      )}

      {open &&
        level.units.map((unit) => (
          <tr key={unit.id}>
            <td style={{ paddingLeft: 28 }}>
              {unit.name || `Юнит ${unit.number}`}
              {unit.title && <span className="muted"> — {unit.title}</span>}
            </td>
            <td className="muted">{unit.child_units} подюнитов</td>
            <td style={{ textAlign: 'right' }}>
              <button
                className="btn btn-ghost btn-sm"
                onClick={() =>
                  onExport(`scope=unit&id=${unit.id}`, `dana-content-unit-${unit.id}-${stamp}.xlsx`)
                }
              >
                Скачать .xlsx
              </button>
            </td>
          </tr>
        ))}
    </>
  );
}
