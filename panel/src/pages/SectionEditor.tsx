import { useEffect, useMemo, useRef, useState } from 'react';

import { ApiError, api } from '../api';
import { useAsync } from '../hooks';

interface Vocab {
  id: number;
  term_en: string;
  part_of_speech: string | null;
  ipa: string | null;
  translation_tk: string;
  translation_ru: string;
  example_en: string | null;
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Payload = Record<string, any>;

/** One member of a v2 multiple_choice payload: stem or an option. */
interface McPart {
  text?: string;
  audio_note?: string;
  image_note?: string;
  media_path?: string | null;
}

interface Question {
  id: number;
  sort_order: number;
  question_type: 'text' | 'audio' | 'picture';
  media_path: string | null;
  quiz_eligible: boolean;
  external_code?: string | null;
  topic?: string | null;
  subtopic?: string | null;
  rule_en?: string | null;
  answer_description_en?: string | null;
  prompt_tk: string | null;
  prompt_ru: string | null;
  payload: Payload;
}

interface SetRow {
  id: number;
  type: string;
  title_tk: string;
  title_ru: string;
  instructions_tk: string | null;
  instructions_ru: string | null;
  status: string;
  questions: Question[];
}

interface QuizItem {
  id: number;
  question_type: string;
  set_type: string;
  payload: Payload;
  section_id: number;
  section_type: string;
  section_title_ru: string | null;
  section_status: 'draft' | 'published';
}

/** vocabulary / grammar / listening — the three quiz skills (FR-14.3). */
interface QuizSkillNumbers {
  vocabulary: number;
  grammar: number;
  listening: number;
}

interface SectionRow {
  id: number;
  type: 'grammar' | 'vocabulary' | 'listening' | 'quiz';
  status: 'draft' | 'published';
  title_tk: string | null;
  title_ru: string | null;
  sort_order: number;
  attempts: number;
  vocabulary?: Vocab[];
  sets?: SetRow[];
  quiz_preview?: QuizItem[];
  /** Quiz sections only — contract 06 §1.3 / FR-14.3. */
  quiz_targets?: QuizSkillNumbers | null;
  /** Servable eligible questions available per skill in this child unit. */
  quiz_pools?: QuizSkillNumbers | null;
  /** The current FIXED draw, as question external codes, in draw order. */
  quiz_draw?: string[] | null;
}

interface ChildUnitData {
  child_unit: { id: number; label: string; title: string | null; level: string };
  sections: SectionRow[];
}

const SECTION_TYPE_LABEL: Record<string, string> = {
  grammar: 'Грамматика',
  vocabulary: 'Словарь',
  listening: 'Аудирование',
  quiz: 'Экзамен-квиз',
};

const SET_TYPE_LABEL: Record<string, string> = {
  multiple_choice: 'Выбор ответа',
  fill_blank: 'Пропуски',
  reorder: 'Порядок слов',
  match_pairs: 'Пары',
  fill_letter_space: 'Пропущенные буквы',
};

const QUESTION_TYPE_LABEL: Record<string, string> = {
  text: 'Текст',
  audio: 'Аудио',
  picture: 'Картинка',
};

const QUIZ_SKILL_LABEL: Record<keyof QuizSkillNumbers, string> = {
  vocabulary: 'Словарь',
  grammar: 'Грамматика',
  listening: 'Аудирование',
};

/** stem / opt0..opt3 → what the superadmin reads. */
function partLabel(key: string): string {
  if (key === 'stem') return 'Вопрос';
  const i = Number(key.slice(3));
  return `Вариант ${'ABCD'[i] ?? i + 1}`;
}

/** New questions are authored in the v2 payload shapes (contract 06 §2). */
const EMPTY_PAYLOAD: Record<string, Payload> = {
  multiple_choice: {
    stem: { text: '' },
    options: [{ text: '' }, { text: '' }, { text: '' }, { text: '' }],
    answer: 0,
  },
  fill_blank: { before: '', after: '', answer: [''], word_bank: ['', '', '', ''] },
  reorder: { tokens: [] },
  match_pairs: { pairs: Array.from({ length: 4 }, () => ({ left: '', right: '' })) },
  fill_letter_space: { mask: '' },
};

/* ----------------------------------------------------- payload helpers */

/** v2 mc payload: stem is an object, not a string. */
function isMcV2(p: Payload): boolean {
  return typeof p.stem === 'object' && p.stem !== null;
}

function mcPartList(p: Payload): { key: string; part: McPart }[] {
  if (!isMcV2(p)) return [];
  const parts: { key: string; part: McPart }[] = [{ key: 'stem', part: p.stem as McPart }];
  (p.options ?? []).forEach((opt: unknown, i: number) => {
    if (typeof opt === 'object' && opt !== null) parts.push({ key: `opt${i}`, part: opt as McPart });
  });
  return parts;
}

function partIsMedia(part: McPart): boolean {
  return part.audio_note !== undefined || part.image_note !== undefined;
}

function partAwaitsFile(part: McPart): boolean {
  return partIsMedia(part) && !part.media_path;
}

/** Contract 06 §3: a question missing ANY media file is hidden from students. */
function missingMedia(setType: string, p: Payload): boolean {
  if (setType !== 'multiple_choice') return false;
  return mcPartList(p).some(({ part }) => partAwaitsFile(part));
}

/** A part (or legacy string) rendered as one line of text. */
function partText(part: McPart | string | undefined): string {
  if (part === undefined) return '';
  if (typeof part === 'string') return part;
  if (part.text !== undefined) return part.text;
  if (part.audio_note !== undefined) return `аудио: «${part.audio_note}»`;
  return `картинка: ${part.image_note ?? ''}`;
}

/**
 * FR-13.21 (legacy payloads): same derivation as the server so the live
 * preview shows exactly what the student will get.
 */
function letterBlanks(text: string, reveal: number): { word: string; given: string; missing: string }[] {
  const out: { word: string; given: string; missing: string }[] = [];
  const shown = Math.max(0, reveal);

  for (const match of text.matchAll(/\{([^{}]+)\}/g)) {
    const word = match[1];
    const n = Math.min(shown, word.length);
    out.push({ word, given: word.slice(0, n), missing: word.slice(n) });
  }

  return out;
}

/** Legacy letterspace: the authored sentence with blanks masked. */
function maskedLetterText(payload: Payload): string {
  const reveal = Math.max(0, Number(payload.reveal ?? 0));

  return String(payload.text ?? '').replace(/\{([^{}]+)\}/g, (_, word: string) => {
    const n = Math.min(reveal, word.length);
    return word.slice(0, n) + '_'.repeat(word.length - n);
  });
}

/** v2 letterspace mask `S<e>ve<n>`: hidden letters become boxes. */
function maskStudentView(mask: string): string {
  return mask.replace(/<([^<>]+)>/g, (_, hidden: string) => '□'.repeat(hidden.length));
}

/** v2 letterspace mask: the letters the student must type, in order. */
function maskHiddenLetters(mask: string): string {
  return [...mask.matchAll(/<([^<>]+)>/g)].map((m) => m[1]).join('');
}

function questionSummary(setType: string, p: Payload): string {
  switch (setType) {
    case 'multiple_choice':
      return `${partText(p.stem)} → ${partText((p.options ?? [])[p.answer ?? 0])}`;
    case 'fill_blank':
      return `${p.before} [${(p.answer ?? [])[0]}] ${p.after}`;
    case 'reorder':
      return (p.tokens ?? []).join(' ');
    case 'fill_letter_space':
      return p.mask !== undefined ? maskStudentView(String(p.mask)) : maskedLetterText(p);
    default:
      return (p.pairs ?? []).map((x: Payload) => `${x.left} = ${x.right ?? x.right_ru}`).join(' · ');
  }
}

/* -------------------------------------------------------- media plumbing */

function panelToken(): string {
  return localStorage.getItem('panel_access') ?? '';
}

/** `STORAGE_PATH/media/q12-stem.mp3` → the name /api/v1/media/{name} serves. */
function mediaFileName(path: string): string {
  return path.split(/[\\/]/).pop() ?? path;
}

/**
 * Uploads ONE part's file: multipart POST to
 * /manage/media/{questionId}/{part} (part = stem|opt0..3 in the path) —
 * same Authorization pattern as the Data page import (api.ts speaks JSON
 * only).
 */
async function uploadPartMedia(questionId: number, part: string, file: File): Promise<string | null> {
  try {
    const form = new FormData();
    form.append('file', file);

    const response = await fetch(`/api/v1/manage/media/${questionId}/${part}`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${panelToken()}` },
      body: form,
    });

    if (response.ok) return null;

    const body = (await response.json().catch(() => null)) as
      | { error?: { message_ru?: string } }
      | null;
    return body?.error?.message_ru ?? 'Не удалось загрузить файл.';
  } catch {
    return 'Нет соединения с сервером.';
  }
}

/**
 * /api/v1/media/{name} requires the Bearer header, so a plain src= would
 * 401 — the blob is fetched with Authorization and object-URL'd instead.
 */
function MediaPreview({ path, kind }: { path: string; kind: 'audio' | 'image' }) {
  const [url, setUrl] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let cancelled = false;
    let objectUrl: string | null = null;
    setUrl(null);
    setFailed(false);

    fetch(`/api/v1/media/${encodeURIComponent(mediaFileName(path))}`, {
      headers: { Authorization: `Bearer ${panelToken()}` },
    })
      .then((response) => (response.ok ? response.blob() : Promise.reject(new Error())))
      .then((blob) => {
        objectUrl = URL.createObjectURL(blob);
        if (!cancelled) setUrl(objectUrl);
      })
      .catch(() => {
        if (!cancelled) setFailed(true);
      });

    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [path]);

  if (failed) return <span className="badge badge-warn">файл не загрузился</span>;
  if (!url) return <span className="muted" style={{ fontSize: 12 }}>Загрузка…</span>;

  return kind === 'audio' ? (
    <audio controls src={url} style={{ height: 32, maxWidth: 260, verticalAlign: 'middle' }} />
  ) : (
    <img
      src={url}
      alt=""
      style={{ maxWidth: 160, maxHeight: 120, borderRadius: 6, display: 'block' }}
    />
  );
}

/**
 * The per-part media strip of a v2 question: the NOTE from the xlsx
 * («что записать/нарисовать»), upload control, preview and delete.
 */
function PartMedia({
  questionId,
  partKey,
  part,
  run,
}: {
  questionId: number;
  partKey: string;
  part: McPart;
  run: (a: () => Promise<unknown>) => Promise<void>;
}) {
  const fileRef = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const kind: 'audio' | 'image' = part.audio_note !== undefined ? 'audio' : 'image';
  const note = part.audio_note ?? part.image_note ?? '';

  async function upload() {
    const file = fileRef.current?.files?.[0];
    if (!file) {
      setError('Сначала выберите файл.');
      return;
    }

    setBusy(true);
    setError(null);
    const failure = await uploadPartMedia(questionId, partKey, file);
    setBusy(false);

    if (failure) {
      setError(failure);
    } else {
      await run(() => Promise.resolve());
    }
  }

  return (
    <div
      style={{
        display: 'flex',
        gap: 10,
        alignItems: 'center',
        flexWrap: 'wrap',
        padding: '6px 0 6px 22px',
        fontSize: 13,
      }}
    >
      <span className="muted" style={{ minWidth: 84 }}>{partLabel(partKey)}</span>
      <strong>{kind === 'audio' ? 'аудио' : 'картинка'}: «{note}»</strong>

      {part.media_path ? (
        <>
          <MediaPreview path={part.media_path} kind={kind} />
          <button
            className="btn btn-danger btn-sm"
            disabled={busy}
            onClick={() => {
              if (confirm(`Удалить файл для «${partLabel(partKey)}»? Вопрос снова скроется от учеников.`)) {
                void run(() =>
                  api.del(`/manage/media/${questionId}/${encodeURIComponent(partKey)}`),
                );
              }
            }}
          >
            Удалить файл
          </button>
        </>
      ) : (
        <>
          <span className="badge badge-warn">ждёт файла</span>
          <input
            type="file"
            ref={fileRef}
            accept={kind === 'audio' ? 'audio/*' : 'image/*'}
            disabled={busy}
            style={{ maxWidth: 220 }}
          />
          <button className="btn btn-sm" disabled={busy} onClick={() => void upload()}>
            {busy ? 'Загрузка…' : 'Загрузить'}
          </button>
        </>
      )}

      {error && <span className="badge badge-warn">{error}</span>}
    </div>
  );
}

/**
 * The editor for ONE CHILD UNIT (1A, 1B…): its typed sections as cards
 * (FR-13.2), each with the editor its type calls for. The section's
 * publish pill is the visibility gate (FR-13.20).
 */
export default function SectionEditor({
  childUnitId,
  onBack,
}: {
  childUnitId: number;
  onBack: () => void;
}) {
  const state = useAsync(
    () => api.get<ChildUnitData>(`/manage/child-units/${childUnitId}/sections`),
    [childUnitId],
  );
  const [error, setError] = useState<string | null>(null);

  async function run(action: () => Promise<unknown>) {
    setError(null);
    try {
      await action();
      state.reload();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось сохранить.');
    }
  }

  if (state.loading) return <p className="muted">Загрузка…</p>;
  if (!state.data) return <div className="alert alert-error">{state.error}</div>;

  const { child_unit: childUnit, sections } = state.data;
  const hasQuiz = sections.some((s) => s.type === 'quiz');

  return (
    <>
      <button className="btn btn-ghost btn-sm" onClick={onBack} style={{ marginBottom: 16 }}>
        ← Ко всем юнитам
      </button>

      <h1>
        {childUnit.label} {childUnit.title && <span className="muted">— {childUnit.title}</span>}
      </h1>
      <p className="sub">
        {childUnit.level} · Ученики видят только опубликованные разделы.
      </p>

      {error && <div className="alert alert-error">{error}</div>}

      {sections.length === 0 && (
        <div className="card muted">
          В юните пока нет разделов — добавьте первый. Разделы необязательны: ученик увидит
          ровно те, что созданы здесь.
        </div>
      )}

      {sections.map((section) => (
        <SectionCard
          key={section.id}
          section={section}
          childUnitId={childUnitId}
          run={run}
          setError={setError}
        />
      ))}

      <AddSection childUnitId={childUnitId} hasQuiz={hasQuiz} run={run} />
    </>
  );
}

/* ---------------------------------------------------------- section card */

function SectionCard({
  section,
  childUnitId,
  run,
  setError,
}: {
  section: SectionRow;
  childUnitId: number;
  run: (a: () => Promise<unknown>) => Promise<void>;
  setError: (e: string | null) => void;
}) {
  const [renaming, setRenaming] = useState(false);
  const [titles, setTitles] = useState({
    title_tk: section.title_tk ?? '',
    title_ru: section.title_ru ?? '',
  });

  // Accordion state lives HERE, not in SetSection/VocabularyEditor:
  // this card survives a data reload (stable key on section.id), so an
  // open lesson stays open while the list refreshes underneath it.
  const [openSets, setOpenSets] = useState<Set<number>>(new Set());
  const [vocabOpen, setVocabOpen] = useState(false);

  function toggleSet(id: number) {
    setOpenSets((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  const name = section.title_ru || SECTION_TYPE_LABEL[section.type] || section.type;

  // The server refuses to delete an attempted section without force=1 —
  // student attempts cascade with it, so the loss is confirmed twice.
  async function removeHandlingAttempts() {
    if (!confirm(`Удалить раздел «${name}» со всем содержимым?`)) return;

    setError(null);
    try {
      await api.del(`/manage/typed-sections/${section.id}`);
      await run(() => Promise.resolve());
    } catch (e: unknown) {
      if (e instanceof ApiError && e.code === 'attempts_exist') {
        if (confirm(`${e.message}\n\nУдалить раздел вместе с попытками учеников?`)) {
          void run(() => api.del(`/manage/typed-sections/${section.id}?force=1`));
        }
      } else {
        setError(e instanceof ApiError ? e.message : 'Не удалось удалить раздел.');
      }
    }
  }

  return (
    <div className="card">
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <h2 style={{ margin: 0, flex: 1 }}>
          {name} <span className="badge">{SECTION_TYPE_LABEL[section.type] ?? section.type}</span>{' '}
          <SectionStatusPill section={section} run={run} />
        </h2>
        <button className="btn btn-ghost btn-sm" onClick={() => setRenaming(!renaming)}>
          {renaming ? 'Отмена' : 'Переименовать'}
        </button>
        <button className="btn btn-danger btn-sm" onClick={() => void removeHandlingAttempts()}>
          Удалить раздел
        </button>
      </div>

      {section.attempts > 0 && (
        <p className="muted" style={{ fontSize: 13, marginBottom: 0 }}>
          Попыток учеников: {section.attempts}
        </p>
      )}

      {renaming && (
        <div style={{ marginTop: 12 }}>
          <div className="row">
            <Input
              label="Название — türkmençe"
              value={titles.title_tk}
              onChange={(x) => setTitles({ ...titles, title_tk: x })}
            />
            <Input
              label="Название — по-русски"
              value={titles.title_ru}
              onChange={(x) => setTitles({ ...titles, title_ru: x })}
            />
          </div>
          <button
            className="btn btn-sm"
            onClick={async () => {
              await run(() => api.post(`/manage/typed-sections/${section.id}`, titles));
              setRenaming(false);
            }}
          >
            Сохранить
          </button>
        </div>
      )}

      {section.type === 'vocabulary' && (
        <VocabularyEditor
          items={section.vocabulary ?? []}
          sectionId={section.id}
          run={run}
          open={vocabOpen}
          onToggle={() => setVocabOpen((v) => !v)}
        />
      )}

      {/* Грамматических объяснений больше нет (FR-13.26): раздел
          «Грамматика» — это только упражнения. */}

      {section.type === 'quiz' ? (
        <>
          <QuizTargetsEditor section={section} childUnitId={childUnitId} run={run} />
          <QuizPreview items={section.quiz_preview ?? []} attempts={section.attempts} />
        </>
      ) : (
        <>
          {(section.sets ?? []).map((set) => (
            <SetSection
              key={set.id}
              set={set}
              run={run}
              open={openSets.has(set.id)}
              onToggle={() => toggleSet(set.id)}
            />
          ))}
          <AddSet sectionId={section.id} run={run} />
        </>
      )}
    </div>
  );
}

function SectionStatusPill({
  section,
  run,
}: {
  section: SectionRow;
  run: (a: () => Promise<unknown>) => Promise<void>;
}) {
  const next = section.status === 'published' ? 'draft' : 'published';

  return (
    <span style={{ display: 'inline-flex', gap: 8, alignItems: 'center' }}>
      {section.status === 'published' ? (
        <span className="badge" style={{ background: 'rgba(30,127,79,0.1)', color: '#1e7f4f' }}>
          опубликовано
        </span>
      ) : (
        <span className="badge">черновик</span>
      )}
      <button
        className={section.status === 'published' ? 'btn btn-ghost btn-sm' : 'btn btn-sm'}
        onClick={() =>
          void run(() => api.post(`/manage/typed-sections/${section.id}/status`, { status: next }))
        }
      >
        {section.status === 'published' ? 'Снять' : 'Опубликовать'}
      </button>
    </span>
  );
}

function AddSection({
  childUnitId,
  hasQuiz,
  run,
}: {
  childUnitId: number;
  hasQuiz: boolean;
  run: (a: () => Promise<unknown>) => Promise<void>;
}) {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ type: 'grammar', title_tk: '', title_ru: '' });

  if (!open) {
    return (
      <button className="btn" onClick={() => setOpen(true)}>
        + Раздел
      </button>
    );
  }

  return (
    <div className="card">
      <h2>Новый раздел</h2>
      <div className="row">
        <div className="field">
          <label htmlFor="section-type">Тип</label>
          <select
            id="section-type"
            value={form.type}
            onChange={(e) => setForm({ ...form, type: e.target.value })}
          >
            {Object.entries(SECTION_TYPE_LABEL).map(([value, label]) => (
              <option key={value} value={value} disabled={value === 'quiz' && hasQuiz}>
                {label}
                {value === 'quiz' && hasQuiz ? ' — уже есть' : ''}
              </option>
            ))}
          </select>
        </div>
        <Input
          label="Название — türkmençe (необязательно)"
          value={form.title_tk}
          onChange={(x) => setForm({ ...form, title_tk: x })}
        />
        <Input
          label="Название — по-русски (необязательно)"
          value={form.title_ru}
          onChange={(x) => setForm({ ...form, title_ru: x })}
        />
      </div>

      {form.type === 'quiz' && (
        <p className="hint">
          Экзамен-квиз не имеет своих вопросов: он собирается случайным розыгрышем из вопросов
          остальных разделов юнита с отметкой «в квизе», по целям «словарь / грамматика /
          аудирование».
        </p>
      )}

      <button
        className="btn btn-sm"
        onClick={async () => {
          await run(() => api.post(`/manage/child-units/${childUnitId}/sections`, form));
          setOpen(false);
          setForm({ type: 'grammar', title_tk: '', title_ru: '' });
        }}
      >
        Создать
      </button>{' '}
      <button className="btn btn-ghost btn-sm" onClick={() => setOpen(false)}>
        Отмена
      </button>
    </div>
  );
}

/* ------------------------------------------------------------ vocabulary */

/**
 * Word list as a collapsed-by-default accordion (same reason as
 * SetSection: 1A's endless page). Open flag lives in SectionCard.
 */
function VocabularyEditor({
  items,
  sectionId,
  run,
  open,
  onToggle,
}: {
  items: Vocab[];
  sectionId: number;
  run: (a: () => Promise<unknown>) => Promise<void>;
  open: boolean;
  onToggle: () => void;
}) {
  const [editing, setEditing] = useState<number | 'new' | null>(null);

  return (
    <div style={{ marginTop: 14 }}>
      <div
        style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}
        onClick={onToggle}
      >
        <span className="muted" aria-hidden style={{ width: 14 }}>
          {open ? '▾' : '▸'}
        </span>
        <h3 style={{ margin: 0, flex: 1 }}>Слова · {items.length}</h3>
      </div>

      {open && (
        <>
      <table>
        <thead>
          <tr>
            <th>Слово</th>
            <th>Транскрипция</th>
            <th>Türkmençe</th>
            <th>По-русски</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {items.map((item) =>
            editing === item.id ? (
              <tr key={item.id}>
                <td colSpan={5}>
                  <VocabForm
                    initial={item}
                    onCancel={() => setEditing(null)}
                    onSave={async (values) => {
                      await run(() => api.post(`/manage/vocabulary/${item.id}`, values));
                      setEditing(null);
                    }}
                  />
                </td>
              </tr>
            ) : (
              <tr key={item.id}>
                <td>
                  <strong>{item.term_en}</strong>
                </td>
                <td className="muted">{item.ipa ?? '—'}</td>
                <td>{item.translation_tk}</td>
                <td>{item.translation_ru}</td>
                <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                  <button className="btn btn-ghost btn-sm" onClick={() => setEditing(item.id)}>
                    Изменить
                  </button>{' '}
                  <button
                    className="btn btn-danger btn-sm"
                    onClick={() => {
                      if (confirm(`Удалить «${item.term_en}»?`)) {
                        void run(() => api.del(`/manage/vocabulary/${item.id}`));
                      }
                    }}
                  >
                    Удалить
                  </button>
                </td>
              </tr>
            ),
          )}
        </tbody>
      </table>

      {editing === 'new' ? (
        <VocabForm
          initial={null}
          onCancel={() => setEditing(null)}
          onSave={async (values) => {
            await run(() => api.post(`/manage/typed-sections/${sectionId}/vocabulary`, values));
            setEditing(null);
          }}
        />
      ) : (
        <button className="btn btn-ghost btn-sm" onClick={() => setEditing('new')}>
          + Добавить слово
        </button>
      )}
        </>
      )}
    </div>
  );
}

function VocabForm({
  initial,
  onSave,
  onCancel,
}: {
  initial: Vocab | null;
  onSave: (values: Record<string, string>) => Promise<void>;
  onCancel: () => void;
}) {
  const [v, setV] = useState({
    term_en: initial?.term_en ?? '',
    ipa: initial?.ipa ?? '',
    part_of_speech: initial?.part_of_speech ?? '',
    translation_tk: initial?.translation_tk ?? '',
    translation_ru: initial?.translation_ru ?? '',
    example_en: initial?.example_en ?? '',
  });

  return (
    <div style={{ padding: '12px 0' }}>
      <div className="row">
        <Input label="Слово (English)" value={v.term_en} onChange={(x) => setV({ ...v, term_en: x })} />
        <Input label="Транскрипция" value={v.ipa} onChange={(x) => setV({ ...v, ipa: x })} />
        <Input
          label="Часть речи"
          value={v.part_of_speech}
          onChange={(x) => setV({ ...v, part_of_speech: x })}
        />
      </div>
      <div className="row">
        <Input
          label="Türkmençe"
          value={v.translation_tk}
          onChange={(x) => setV({ ...v, translation_tk: x })}
        />
        <Input
          label="По-русски"
          value={v.translation_ru}
          onChange={(x) => setV({ ...v, translation_ru: x })}
        />
      </div>
      <Input label="Пример (English)" value={v.example_en} onChange={(x) => setV({ ...v, example_en: x })} />
      <div style={{ marginTop: 10 }}>
        <button className="btn btn-sm" onClick={() => void onSave(v)}>
          Сохранить
        </button>{' '}
        <button className="btn btn-ghost btn-sm" onClick={onCancel}>
          Отмена
        </button>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------- quiz card */

/**
 * FR-14.3: the quiz is ONE fixed random draw per child unit —
 * quiz_target_vocabulary + quiz_target_grammar + quiz_target_listening
 * servable eligible questions. Targets are edited here; «Пересобрать»
 * replaces the whole draw (same for every student).
 */
function QuizTargetsEditor({
  section,
  childUnitId,
  run,
}: {
  section: SectionRow;
  childUnitId: number;
  run: (a: () => Promise<unknown>) => Promise<void>;
}) {
  const [targets, setTargets] = useState<QuizSkillNumbers>({
    vocabulary: section.quiz_targets?.vocabulary ?? 0,
    grammar: section.quiz_targets?.grammar ?? 0,
    listening: section.quiz_targets?.listening ?? 0,
  });
  const pools = section.quiz_pools ?? null;
  const draw = section.quiz_draw ?? [];

  const skills = Object.keys(QUIZ_SKILL_LABEL) as (keyof QuizSkillNumbers)[];
  const totalTargets = skills.reduce((n, k) => n + targets[k], 0);

  return (
    <div className="inset" style={{ marginTop: 14 }}>
      <h3 style={{ margin: '0 0 8px' }}>Состав квиза</h3>

      <div className="row">
        {skills.map((skill) => (
          <div className="field" key={skill} style={{ maxWidth: 140 }}>
            <label htmlFor={`quiz-target-${section.id}-${skill}`}>
              {QUIZ_SKILL_LABEL[skill]}
            </label>
            <input
              id={`quiz-target-${section.id}-${skill}`}
              type="number"
              min={0}
              value={targets[skill]}
              onChange={(e) =>
                setTargets({ ...targets, [skill]: Math.max(0, Number(e.target.value) || 0) })
              }
            />
            {pools && (
              <p className="hint" style={{ margin: '4px 0 0' }}>
                в наличии: {pools[skill]}
              </p>
            )}
          </div>
        ))}
        <div className="field" style={{ display: 'flex', alignItems: 'flex-end', maxWidth: 160 }}>
          <button
            className="btn btn-sm"
            onClick={() =>
              void run(() =>
                api.post(`/manage/typed-sections/${section.id}`, {
                  quiz_target_vocabulary: targets.vocabulary,
                  quiz_target_grammar: targets.grammar,
                  quiz_target_listening: targets.listening,
                }),
              )
            }
          >
            Сохранить цели
          </button>
        </div>
      </div>

      {pools &&
        skills
          .filter((skill) => targets[skill] > pools[skill])
          .map((skill) => (
            <div className="alert alert-warn" key={skill} style={{ marginTop: 8 }}>
              {QUIZ_SKILL_LABEL[skill]}: цель {targets[skill]}, а годных вопросов только{' '}
              {pools[skill]} — в квиз попадут все {pools[skill]}. Годный вопрос = «в квизе» +
              раздел опубликован + все аудио/картинки загружены.
            </div>
          ))}

      {totalTargets === 0 && (
        <p className="muted" style={{ fontSize: 13 }}>
          Цели не заданы — квиз работает по-старому: все вопросы с отметкой «в квизе».
        </p>
      )}

      <div style={{ marginTop: 12 }}>
        <h3 style={{ margin: '0 0 6px' }}>Текущий набор ({draw.length})</h3>
        {draw.length === 0 ? (
          <p className="muted" style={{ fontSize: 13, margin: 0 }}>
            Набор ещё не разыгран — он соберётся сам при первом открытии квиза учеником.
          </p>
        ) : (
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
            {draw.map((code, i) => (
              <span className="badge" key={`${code}-${i}`}>
                {code}
              </span>
            ))}
          </div>
        )}

        <button
          className="btn btn-danger btn-sm"
          style={{ marginTop: 10 }}
          onClick={() => {
            if (
              confirm(
                'Пересобрать квиз? Текущий набор будет заменён новым случайным розыгрышем — ' +
                  'одинаковым для всех учеников юнита.',
              )
            ) {
              void run(() => api.post(`/manage/quiz/${childUnitId}/redraw`));
            }
          }}
        >
          Пересобрать квиз
        </button>
      </div>
    </div>
  );
}

/**
 * The drawn/eligible questions as the student meets them — read-only;
 * content is edited by flagging questions in the sibling sections.
 */
function QuizPreview({ items, attempts }: { items: QuizItem[]; attempts: number }) {
  const publishedCount = useMemo(
    () => items.filter((q) => q.section_status === 'published').length,
    [items],
  );

  return (
    <div style={{ marginTop: 14 }}>
      <p className="muted" style={{ fontSize: 13 }}>
        Сейчас ученики получат <strong>{publishedCount}</strong> вопросов
        {items.length !== publishedCount &&
          ` (ещё ${items.length - publishedCount} — из неопубликованных разделов)`}
        . Попыток учеников: {attempts}.
      </p>

      {items.length === 0 && (
        <p className="muted">
          Пока ни один вопрос юнита не отмечен «в квизе» — отметьте нужные в редакторе вопросов.
        </p>
      )}

      {items.map((q, i) => (
        <div key={q.id} style={{ borderTop: '1px solid var(--border)', padding: '10px 0' }}>
          <span className="muted" style={{ minWidth: 22, display: 'inline-block' }}>
            {i + 1}.
          </span>
          {questionSummary(q.set_type, q.payload)}
          <div className="muted" style={{ fontSize: 12, marginTop: 2, marginLeft: 22 }}>
            {q.section_title_ru || SECTION_TYPE_LABEL[q.section_type]} ·{' '}
            {SET_TYPE_LABEL[q.set_type] ?? q.set_type}
            {q.section_status !== 'published' && (
              <span className="badge" style={{ marginLeft: 6 }}>
                раздел не опубликован — не попадёт в квиз
              </span>
            )}
          </div>
        </div>
      ))}
    </div>
  );
}

/* --------------------------------------------------------- exercise sets */

/**
 * One exercise set as a collapsed-by-default accordion — «контент 1A —
 * один бесконечный список» (client): the questions and editors render
 * only when the lesson is opened. The open flag lives in the parent
 * SectionCard so a data reload does not collapse an open set.
 */
function SetSection({
  set,
  run,
  open,
  onToggle,
}: {
  set: SetRow;
  run: (a: () => Promise<unknown>) => Promise<void>;
  open: boolean;
  onToggle: () => void;
}) {
  const [adding, setAdding] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);

  return (
    <div className="inset" style={{ marginTop: 14 }}>
      <div
        style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', cursor: 'pointer' }}
        onClick={onToggle}
      >
        <span className="muted" aria-hidden style={{ width: 14 }}>
          {open ? '▾' : '▸'}
        </span>
        <h3 style={{ margin: 0, flex: 1 }}>
          {set.title_ru} <span className="badge">{SET_TYPE_LABEL[set.type] ?? set.type}</span>{' '}
          <span className="badge">{set.status === 'published' ? 'опубликовано' : 'черновик'}</span>{' '}
          <span className="muted" style={{ fontWeight: 400, fontSize: 13 }}>
            · вопросов: {set.questions.length}
          </span>
        </h3>
        <button
          className="btn btn-ghost btn-sm"
          onClick={(e) => {
            e.stopPropagation();
            void run(() =>
              api.post(`/manage/content/sets/${set.id}/status`, {
                status: set.status === 'published' ? 'draft' : 'published',
              }),
            );
          }}
        >
          {set.status === 'published' ? 'Снять' : 'Опубликовать'}
        </button>
        <button
          className="btn btn-danger btn-sm"
          onClick={(e) => {
            e.stopPropagation();
            if (confirm(`Удалить упражнение «${set.title_ru}»?`)) {
              void run(() => api.del(`/manage/sets/${set.id}`));
            }
          }}
        >
          Удалить упражнение
        </button>
      </div>

      {open && (
        <>
      <p className="muted" style={{ fontSize: 13, display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <span>
          Вопросов: {set.questions.length} · В квизе:{' '}
          {set.questions.filter((q) => q.quiz_eligible).length}
        </span>
        {/* FR-15.11: one click instead of an edit-save cycle per question —
            unticked hand-authored questions quietly starve the quiz pools. */}
        {set.questions.length > 0 &&
          (set.questions.every((q) => q.quiz_eligible) ? (
            <button
              className="btn btn-ghost btn-sm"
              onClick={() =>
                void run(() => api.post(`/manage/sets/${set.id}/quiz-eligible`, { eligible: false }))
              }
            >
              Убрать все из квиза
            </button>
          ) : (
            <button
              className="btn btn-sm"
              onClick={() =>
                void run(() => api.post(`/manage/sets/${set.id}/quiz-eligible`, { eligible: true }))
              }
            >
              Все — в квиз
            </button>
          ))}
      </p>

      {set.questions.map((q, i) =>
        editingId === q.id ? (
          <QuestionForm
            key={q.id}
            type={set.type}
            initial={q}
            onCancel={() => setEditingId(null)}
            onSave={async (values) => {
              await run(() => api.post(`/manage/questions/${q.id}`, values));
              setEditingId(null);
            }}
          />
        ) : (
          <QuestionRow
            key={q.id}
            index={i + 1}
            type={set.type}
            question={q}
            run={run}
            onEdit={() => setEditingId(q.id)}
            onDelete={() => {
              if (confirm('Удалить вопрос?')) {
                void run(() => api.del(`/manage/questions/${q.id}`));
              }
            }}
          />
        ),
      )}

      {adding ? (
        <QuestionForm
          type={set.type}
          initial={null}
          onCancel={() => setAdding(false)}
          onSave={async (values) => {
            await run(() => api.post(`/manage/sets/${set.id}/questions`, values));
            setAdding(false);
          }}
        />
      ) : (
        <button className="btn btn-ghost btn-sm" onClick={() => setAdding(true)}>
          + Добавить вопрос
        </button>
      )}
        </>
      )}
    </div>
  );
}

function QuestionRow({
  index,
  type,
  question,
  run,
  onEdit,
  onDelete,
}: {
  index: number;
  type: string;
  question: Question;
  run: (a: () => Promise<unknown>) => Promise<void>;
  onEdit: () => void;
  onDelete: () => void;
}) {
  const payload = question.payload ?? {};
  const mediaParts = type === 'multiple_choice'
    ? mcPartList(payload).filter(({ part }) => partIsMedia(part))
    : [];
  const hidden = missingMedia(type, payload);

  return (
    <div style={{ borderTop: '1px solid var(--border)', padding: '12px 0' }}>
      <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
        <span className="muted" style={{ minWidth: 22 }}>
          {index}.
        </span>
        <div style={{ flex: 1 }}>
          <div>
            {question.external_code && (
              <span className="badge" style={{ marginRight: 8 }}>
                {question.external_code}
              </span>
            )}
            {questionSummary(type, payload)}
            {hidden && (
              <span className="badge badge-warn" style={{ marginLeft: 8 }}>
                скрыт от учеников — нет файла
              </span>
            )}
          </div>
          <div className="muted" style={{ fontSize: 12, marginTop: 4 }}>
            {QUESTION_TYPE_LABEL[question.question_type] ?? question.question_type}
            {(question.topic || question.subtopic) && (
              <> · {question.topic ?? '—'}{question.subtopic && ` / ${question.subtopic}`}</>
            )}
            {question.media_path && <> · {question.media_path}</>}
            {question.quiz_eligible && ' · в квизе'}
          </div>
          {question.rule_en && (
            <div className="muted" style={{ fontSize: 12, marginTop: 2 }}>
              Rule: {question.rule_en}
            </div>
          )}
          {question.answer_description_en && (
            <div className="muted" style={{ fontSize: 12, marginTop: 2 }}>
              Пояснение: {question.answer_description_en}
            </div>
          )}
        </div>
        <button className="btn btn-ghost btn-sm" onClick={onEdit}>
          Изменить
        </button>
        <button className="btn btn-danger btn-sm" onClick={onDelete}>
          Удалить
        </button>
      </div>

      {mediaParts.map(({ key, part }) => (
        <PartMedia key={key} questionId={question.id} partKey={key} part={part} run={run} />
      ))}
    </div>
  );
}

/* -------------------------------------------------------- question form */

/** One stem/option of a v2 mc payload: text OR audio note OR image note. */
function McPartEditor({
  label,
  part,
  onChange,
}: {
  label: string;
  part: McPart;
  onChange: (part: McPart) => void;
}) {
  const kind = part.audio_note !== undefined ? 'audio' : part.image_note !== undefined ? 'image' : 'text';
  const value = part.text ?? part.audio_note ?? part.image_note ?? '';

  function rebuild(nextKind: string, nextValue: string): McPart {
    if (nextKind === 'audio') {
      return { audio_note: nextValue, media_path: kind === 'audio' ? (part.media_path ?? null) : null };
    }
    if (nextKind === 'image') {
      return { image_note: nextValue, media_path: kind === 'image' ? (part.media_path ?? null) : null };
    }
    return { text: nextValue };
  }

  return (
    <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginBottom: 8, flexWrap: 'wrap' }}>
      <span className="muted" style={{ minWidth: 84, fontSize: 13 }}>{label}</span>
      <select
        value={kind}
        style={{ maxWidth: 130 }}
        onChange={(e) => onChange(rebuild(e.target.value, value))}
      >
        <option value="text">текст</option>
        <option value="audio">аудио</option>
        <option value="image">картинка</option>
      </select>
      <input
        style={{ flex: 1, minWidth: 180 }}
        value={value}
        placeholder={
          kind === 'text'
            ? 'Текст'
            : kind === 'audio'
              ? 'Заметка: что должно звучать (файл загружается после сохранения)'
              : 'Заметка: что на картинке (файл загружается после сохранения)'
        }
        onChange={(e) => onChange(rebuild(kind, e.target.value))}
      />
      {kind !== 'text' && part.media_path && <span className="badge">файл загружен</span>}
      {kind !== 'text' && !part.media_path && (
        <span className="badge badge-warn">ждёт файла</span>
      )}
    </div>
  );
}

function QuestionForm({
  type,
  initial,
  onSave,
  onCancel,
}: {
  type: string;
  initial: Question | null;
  onSave: (values: Payload) => Promise<void>;
  onCancel: () => void;
}) {
  const [promptTk, setPromptTk] = useState(initial?.prompt_tk ?? '');
  const [promptRu, setPromptRu] = useState(initial?.prompt_ru ?? '');
  const [topic, setTopic] = useState(initial?.topic ?? '');
  const [subtopic, setSubtopic] = useState(initial?.subtopic ?? '');
  const [ruleEn, setRuleEn] = useState(initial?.rule_en ?? '');
  const [answerDescriptionEn, setAnswerDescriptionEn] = useState(
    initial?.answer_description_en ?? '',
  );
  const [questionType, setQuestionType] = useState<string>(initial?.question_type ?? 'text');
  const [mediaPath, setMediaPath] = useState(initial?.media_path ?? '');
  // New questions default INTO the quiz (FR-15.11): authors forgot the
  // opt-in checkbox on every hand-authored question, quietly starving
  // the draw pools — «в наличии: 0» over a full section. Existing
  // questions keep their stored flag.
  const [quizEligible, setQuizEligible] = useState(initial?.quiz_eligible ?? true);
  const [payload, setPayload] = useState<Payload>(
    initial?.payload ?? structuredClone(EMPTY_PAYLOAD[type] ?? {}),
  );

  const set = (patch: Payload) => setPayload({ ...payload, ...patch });

  const mcV2 = type === 'multiple_choice' && isMcV2(payload);
  const letterV2 = type === 'fill_letter_space' && payload.mask !== undefined;

  const legacyBlanks =
    type === 'fill_letter_space' && !letterV2
      ? letterBlanks(String(payload.text ?? ''), Number(payload.reveal ?? 0))
      : [];

  const mask = letterV2 ? String(payload.mask ?? '') : '';
  const tokens: string[] = type === 'reorder' ? (payload.tokens ?? []) : [];

  return (
    <div style={{ borderTop: '1px solid var(--border)', padding: '14px 0' }}>
      {initial?.external_code && (
        <p style={{ margin: '0 0 10px' }}>
          <span className="badge">{initial.external_code}</span>{' '}
          <span className="muted" style={{ fontSize: 12 }}>
            код из xlsx — по нему импорт обновляет этот вопрос
          </span>
        </p>
      )}

      <div className="row">
        <Input label="Задание — türkmençe" value={promptTk} onChange={setPromptTk} />
        <Input label="Задание — по-русски" value={promptRu} onChange={setPromptRu} />
      </div>

      {/* Contract 06 §1.1: Topic / Subtopic / Rule / Answer Description —
          stored verbatim, English only (client decision). */}
      <div className="row">
        <Input label="Topic (EN)" value={topic} onChange={setTopic} />
        <Input label="Subtopic (EN)" value={subtopic} onChange={setSubtopic} />
      </div>
      <Input
        label="Rule (EN) — строка-инструкция над вопросом"
        value={ruleEn}
        onChange={setRuleEn}
      />
      <Input
        label="Answer Description (EN) — пояснение после ответа"
        value={answerDescriptionEn}
        onChange={setAnswerDescriptionEn}
      />

      <div className="row">
        <div className="field">
          <label>Тип вопроса</label>
          <select value={questionType} onChange={(e) => setQuestionType(e.target.value)}>
            {Object.entries(QUESTION_TYPE_LABEL).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </div>

        {questionType !== 'text' && (
          <Input
            label={questionType === 'audio' ? 'Путь к аудиофайлу' : 'Путь к картинке'}
            value={mediaPath}
            onChange={setMediaPath}
          />
        )}

        <div className="field">
          <label htmlFor={`quiz-eligible-${initial?.id ?? 'new'}`}>Экзамен-квиз</label>
          {/* FR-13.14: the flag that feeds the unit's Exam Quiz. */}
          <label
            htmlFor={`quiz-eligible-${initial?.id ?? 'new'}`}
            style={{ display: 'flex', gap: 8, alignItems: 'center', fontWeight: 400 }}
          >
            <input
              id={`quiz-eligible-${initial?.id ?? 'new'}`}
              type="checkbox"
              style={{ width: 18 }}
              checked={quizEligible}
              onChange={(e) => setQuizEligible(e.target.checked)}
            />
            Входит в экзамен-квиз
          </label>
        </div>
      </div>

      {mcV2 && (
        <>
          <McPartEditor
            label="Вопрос"
            part={payload.stem as McPart}
            onChange={(part) => set({ stem: part })}
          />
          {(payload.options ?? []).map((opt: McPart, i: number) => (
            <div key={i} style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
              {/* The stored option order is the import's shuffle — the
                  correct answer sits at payload.answer, NOT at A. Saving
                  used to force answer=0, so merely re-saving a question
                  (to upload audio, to tick «в квизе») silently made
                  option A "correct" and scrambled the key. The radio
                  makes the true answer visible and editable, and the
                  save keeps it. */}
              <input
                type="radio"
                name={`mc-answer-${initial?.id ?? 'new'}`}
                style={{ width: 18, marginTop: 30 }}
                title="Правильный ответ"
                checked={(payload.answer ?? 0) === i}
                onChange={() => set({ answer: i })}
              />
              <div style={{ flex: 1 }}>
                <McPartEditor
                  label={`Вариант ${'ABCD'[i] ?? i + 1}${
                    (payload.answer ?? 0) === i ? ' — правильный' : ''
                  }`}
                  part={opt}
                  onChange={(part) => {
                    const options = [...payload.options];
                    options[i] = part;
                    set({ options });
                  }}
                />
              </div>
            </div>
          ))}
          <div style={{ display: 'flex', gap: 8 }}>
            {(payload.options ?? []).length < 4 && (
              <button
                className="btn btn-ghost btn-sm"
                onClick={() => set({ options: [...(payload.options ?? []), { text: '' }] })}
              >
                + Вариант
              </button>
            )}
            {(payload.options ?? []).length > 2 && (
              <button
                className="btn btn-ghost btn-sm"
                onClick={() => set({ options: (payload.options ?? []).slice(0, -1) })}
              >
                − Вариант
              </button>
            )}
          </div>
          <p className="muted" style={{ fontSize: 12 }}>
            Вариант A всегда правильный — в приложении варианты перемешиваются. От 2 до 4
            вариантов; для аудио/картинок файлы загружаются в списке вопросов после сохранения.
          </p>
        </>
      )}

      {type === 'multiple_choice' && !mcV2 && (
        <>
          <Input
            label="Предложение (___ на месте пропуска)"
            value={payload.stem ?? ''}
            onChange={(x) => set({ stem: x })}
          />
          {(payload.options ?? []).map((opt: string, i: number) => (
            <div key={i} style={{ display: 'flex', gap: 10, alignItems: 'center', marginBottom: 8 }}>
              <input
                type="radio"
                style={{ width: 18 }}
                checked={payload.answer === i}
                onChange={() => set({ answer: i })}
              />
              <input
                value={opt}
                placeholder={`Вариант ${i + 1}`}
                onChange={(e) => {
                  const options = [...payload.options];
                  options[i] = e.target.value;
                  set({ options });
                }}
              />
            </div>
          ))}
          <p className="muted" style={{ fontSize: 12 }}>
            Старый формат вопроса. Отметьте правильный вариант.
          </p>
        </>
      )}

      {type === 'fill_blank' && (
        <>
          <div className="row">
            <Input label="До пропуска" value={payload.before ?? ''} onChange={(x) => set({ before: x })} />
            <Input label="После пропуска" value={payload.after ?? ''} onChange={(x) => set({ after: x })} />
          </div>
          <Input
            label="Набор слов (через запятую) — ПЕРВОЕ слово и есть правильный ответ"
            value={(payload.word_bank ?? []).join(', ')}
            onChange={(x) => {
              const word_bank = x.split(',').map((s) => s.trim()).filter(Boolean);
              set({ word_bank, answer: [word_bank[0] ?? ''] });
            }}
          />
          <p className="muted" style={{ fontSize: 12 }}>
            Правильный ответ сейчас: <code>{(payload.answer ?? [''])[0] || '—'}</code>. Ученику
            набор показывается перемешанным.
          </p>
        </>
      )}

      {type === 'reorder' && (
        <>
          <Input
            label="ПРАВИЛЬНОЕ предложение — слова через пробел"
            value={tokens.join(' ')}
            onChange={(x) => set({ tokens: x.split(/\s+/).filter(Boolean) })}
          />
          {tokens.length > 0 && (
            <p className="muted" style={{ fontSize: 12 }}>
              Токены: <code>{tokens.map((t) => `{{${t}}}`).join(' ')}</code> — порядок и есть
              ответ; ученик получит их перемешанными.
            </p>
          )}
        </>
      )}

      {type === 'match_pairs' && (
        <>
          {(payload.pairs ?? []).map((pair: Payload, i: number) => (
            <div className="row" key={i}>
              <Input
                label={`Пара ${i + 1} — слева`}
                value={pair.left ?? ''}
                onChange={(x) => {
                  const pairs = [...payload.pairs];
                  pairs[i] = { ...pairs[i], left: x };
                  set({ pairs });
                }}
              />
              <Input
                label="справа"
                value={pair.right ?? pair.right_ru ?? ''}
                onChange={(x) => {
                  const pairs = [...payload.pairs];
                  pairs[i] = { ...pairs[i], right: x };
                  set({ pairs });
                }}
              />
            </div>
          ))}
          <button
            className="btn btn-ghost btn-sm"
            onClick={() => set({ pairs: [...(payload.pairs ?? []), { left: '', right: '' }] })}
          >
            + Пара
          </button>
          <p className="muted" style={{ fontSize: 12 }}>От 4 до 5 пар.</p>
        </>
      )}

      {type === 'fill_letter_space' && letterV2 && (
        <>
          {/* Contract 06 §2: {"mask": "S<e>ve<n>"} — letters inside <>
              are HIDDEN, at arbitrary positions. */}
          <Input
            label={'Маска — скрытые буквы в угловых скобках, напр. S<e>ve<n>'}
            value={mask}
            onChange={(x) => set({ mask: x })}
          />
          {mask.trim() === '' ? (
            <p className="muted" style={{ fontSize: 12 }}>
              Введите слово или фразу и заключите скрываемые буквы в {'<>'}.
            </p>
          ) : (
            <div className="inset" style={{ fontSize: 13 }}>
              <div>
                Ученик увидит: <code>{maskStudentView(mask)}</code>
              </div>
              <div className="muted" style={{ marginTop: 4 }}>
                {maskHiddenLetters(mask).length > 0 ? (
                  <>
                    Ввести {maskHiddenLetters(mask).length} букв:{' '}
                    <code>{maskHiddenLetters(mask)}</code>
                  </>
                ) : (
                  'Нет скрытых букв — отметьте хотя бы одну: <б>.'
                )}
              </div>
            </div>
          )}
        </>
      )}

      {type === 'fill_letter_space' && !letterV2 && (
        <>
          {/* FR-13.21 legacy payloads: plain text with {word} marks. */}
          <div className="row">
            <Input
              label="Предложение — скрываемые слова в фигурных скобках, напр. I just {want} to go."
              value={payload.text ?? ''}
              onChange={(x) => set({ text: x })}
            />
            <div className="field" style={{ maxWidth: 180 }}>
              <label>Открытых букв в начале слова</label>
              <input
                type="number"
                min={0}
                value={Number(payload.reveal ?? 0)}
                onChange={(e) => set({ reveal: Math.max(0, Number(e.target.value) || 0) })}
              />
            </div>
          </div>

          {legacyBlanks.length === 0 ? (
            <p className="muted" style={{ fontSize: 12 }}>
              Отметьте хотя бы одно слово фигурными скобками: {'{слово}'}.
            </p>
          ) : (
            <div className="inset" style={{ fontSize: 13 }}>
              <div style={{ marginBottom: 6 }}>
                Ученик увидит: <code>{maskedLetterText(payload)}</code>
              </div>
              {legacyBlanks.map((b, i) => (
                <div key={i} className="muted">
                  {i + 1}. {b.word} → показано «{b.given || '—'}», ввести{' '}
                  {b.missing.length > 0 ? (
                    <>
                      {b.missing.length} букв: <code>{b.missing}</code>
                    </>
                  ) : (
                    'нечего — слово открыто целиком'
                  )}
                </div>
              ))}
            </div>
          )}
        </>
      )}

      <div style={{ marginTop: 12 }}>
        <button
          className="btn btn-sm"
          onClick={() =>
            void onSave({
              prompt_tk: promptTk,
              prompt_ru: promptRu,
              topic: topic || null,
              subtopic: subtopic || null,
              rule_en: ruleEn || null,
              answer_description_en: answerDescriptionEn || null,
              question_type: questionType,
              media_path: questionType === 'text' ? null : mediaPath || null,
              quiz_eligible: quizEligible,
              // The payload's answer index is the radio's choice — sent
              // as-is. The old `answer: 0` here assumed the FILE
              // convention (option A = correct) against a DB that stores
              // the import's SHUFFLED order, so every re-save moved the
              // correct answer to whichever option was displayed first.
              payload,
            })
          }
        >
          Сохранить
        </button>{' '}
        <button className="btn btn-ghost btn-sm" onClick={onCancel}>
          Отмена
        </button>
      </div>
    </div>
  );
}

function AddSet({
  sectionId,
  run,
}: {
  sectionId: number;
  run: (a: () => Promise<unknown>) => Promise<void>;
}) {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ type: 'multiple_choice', title_tk: '', title_ru: '' });

  if (!open) {
    return (
      <button className="btn btn-ghost" style={{ marginTop: 12 }} onClick={() => setOpen(true)}>
        + Новое упражнение
      </button>
    );
  }

  return (
    <div className="inset" style={{ marginTop: 12 }}>
      <h3>Новое упражнение</h3>
      <div className="row">
        <div className="field">
          <label htmlFor={`set-type-${sectionId}`}>Тип</label>
          <select
            id={`set-type-${sectionId}`}
            value={form.type}
            onChange={(e) => setForm({ ...form, type: e.target.value })}
          >
            {Object.entries(SET_TYPE_LABEL).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </div>
        <Input label="Название — türkmençe" value={form.title_tk} onChange={(x) => setForm({ ...form, title_tk: x })} />
        <Input label="Название — по-русски" value={form.title_ru} onChange={(x) => setForm({ ...form, title_ru: x })} />
      </div>
      <button
        className="btn btn-sm"
        onClick={async () => {
          await run(() => api.post(`/manage/typed-sections/${sectionId}/sets`, form));
          setOpen(false);
          setForm({ type: 'multiple_choice', title_tk: '', title_ru: '' });
        }}
      >
        Создать
      </button>{' '}
      <button className="btn btn-ghost btn-sm" onClick={() => setOpen(false)}>
        Отмена
      </button>
    </div>
  );
}

/* ----------------------------------------------------------------- inputs */

function Input({
  label,
  value,
  onChange,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
}) {
  return (
    <div className="field">
      <label>{label}</label>
      <input value={value} onChange={(e) => onChange(e.target.value)} />
    </div>
  );
}
