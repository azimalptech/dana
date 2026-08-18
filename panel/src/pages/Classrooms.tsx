import { useState, type FormEvent } from 'react';

import { ApiError, api } from '../api';
import { useAsync } from '../hooks';

interface Option {
  id: number;
  name: string;
  level_id?: number;
}

interface Staff {
  id: number;
  role: string;
  full_name: string;
}

interface ClassroomRow {
  id: number;
  name: string;
  level: string | null;
  teacher: string | null;
  closed: boolean;
  students: number;
}

export default function Classrooms() {
  // book_sets are gone from the options payload (FR-14.5) — the class
  // needs only a level and a teacher now.
  const options = useAsync(() => api.get<{ levels: Option[] }>('/manage/options'));
  const staff = useAsync(() => api.get<{ staff: Staff[] }>('/manage/staff'));
  const classrooms = useAsync(() =>
    api.get<{ classrooms: ClassroomRow[] }>('/manage/progress'),
  );

  const [form, setForm] = useState({ name: '', teacher_id: '', level_id: '' });
  const [error, setError] = useState<string | null>(null);
  const [ok, setOk] = useState(false);
  const [busy, setBusy] = useState(false);

  const teachers = (staff.data?.staff ?? []).filter((s) => s.role === 'teacher');
  const levels = options.data?.levels ?? [];

  async function create(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setOk(false);

    try {
      // Books are gone (FR-14.5) — a class is a name, a teacher and a
      // level, nothing else.
      await api.post('/manage/classrooms', {
        name: form.name,
        teacher_id: Number(form.teacher_id),
        level_id: Number(form.level_id),
      });
      setForm({ name: '', teacher_id: '', level_id: '' });
      setOk(true);
      classrooms.reload();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось создать класс.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <h1>Классы</h1>
      <p className="sub">Класс — это преподаватель и уровень.</p>

      <form className="card" onSubmit={create}>
        <h2>Новый класс</h2>
        {error && <div className="alert alert-error">{error}</div>}
        {ok && <div className="alert alert-ok">Класс создан.</div>}

        <div className="row">
          <div className="field">
            <label htmlFor="name">Название</label>
            <input
              id="name"
              required
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              placeholder="Утренняя группа"
            />
          </div>

          <div className="field">
            <label htmlFor="teacher">Преподаватель</label>
            <select
              id="teacher"
              required
              value={form.teacher_id}
              onChange={(e) => setForm({ ...form, teacher_id: e.target.value })}
            >
              <option value="">—</option>
              {teachers.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.full_name}
                </option>
              ))}
            </select>
          </div>

          <div className="field">
            <label htmlFor="level">Уровень</label>
            <select
              id="level"
              required
              value={form.level_id}
              onChange={(e) => setForm({ ...form, level_id: e.target.value })}
            >
              <option value="">—</option>
              {levels.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.name}
                </option>
              ))}
            </select>
          </div>
        </div>

        {teachers.length === 0 && (
          <div className="alert alert-warn">
            Сначала создайте преподавателя на вкладке «Центры и сотрудники».
          </div>
        )}

        <button className="btn" disabled={busy || teachers.length === 0}>
          {busy ? 'Создание…' : 'Создать класс'}
        </button>
      </form>

      <div className="card">
        <h2>Существующие классы</h2>
        {classrooms.loading && <p className="muted">Загрузка…</p>}
        {(classrooms.data?.classrooms.length ?? 0) === 0 && !classrooms.loading && (
          <p className="muted">Пока нет классов.</p>
        )}

        {(classrooms.data?.classrooms ?? []).map((row) => (
          <ClassroomRowView key={row.id} row={row} onChanged={() => classrooms.reload()} />
        ))}
      </div>
    </>
  );
}

/** FR-1.14: closing a course disables its students and deletes their progress. */
function ClassroomRowView({ row, onChanged }: { row: ClassroomRow; onChanged: () => void }) {
  const [confirming, setConfirming] = useState(false);
  const [adding, setAdding] = useState(false);
  const [showStudents, setShowStudents] = useState(false);
  const [typed, setTyped] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function close() {
    setBusy(true);
    setError(null);

    try {
      await api.post(`/manage/classrooms/${row.id}/close`, { confirm_name: typed });
      setConfirming(false);
      onChanged();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось завершить курс.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div
      style={{
        borderBottom: '1px solid var(--border)',
        padding: '14px 0',
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        <div style={{ flex: 1 }}>
          <strong>{row.name}</strong>{' '}
          {row.closed && <span className="badge">завершён</span>}
          <div className="muted" style={{ fontSize: 13 }}>
            {row.level ?? '—'} · {row.teacher ?? '—'} · {row.students} учеников
          </div>
        </div>

        <button className="btn btn-ghost btn-sm" onClick={() => setShowStudents(!showStudents)}>
          {showStudents ? 'Скрыть учеников' : 'Ученики'}
        </button>

        {!row.closed && (
          <button
            className={adding ? 'btn btn-ghost btn-sm' : 'btn btn-sm'}
            onClick={() => setAdding(!adding)}
          >
            {adding ? 'Отмена' : '+ Ученик'}
          </button>
        )}

        <button
          className="btn btn-ghost btn-sm"
          onClick={() => api.download(`/manage/classrooms/${row.id}/export`, `${row.name}.csv`)}
        >
          Скачать CSV
        </button>

        {!row.closed && (
          <button className="btn btn-danger btn-sm" onClick={() => setConfirming(!confirming)}>
            Завершить курс
          </button>
        )}
      </div>

      {adding && <StudentForm classroomId={row.id} onCreated={onChanged} />}

      {showStudents && <StudentsPanel classroomId={row.id} />}

      {confirming && (
        <div className="alert alert-warn" style={{ marginTop: 12 }}>
          <p style={{ marginTop: 0 }}>
            <strong>Это необратимо.</strong> Аккаунты учеников будут отключены, а весь их
            прогресс — удалён навсегда. Сначала скачайте CSV: после завершения отчёт получить
            будет невозможно.
          </p>
          <p style={{ fontSize: 13 }}>
            Для подтверждения введите название класса: <code>{row.name}</code>
          </p>
          {error && <div className="alert alert-error">{error}</div>}
          <div style={{ display: 'flex', gap: 10 }}>
            <input value={typed} onChange={(e) => setTyped(e.target.value)} />
            <button
              className="btn btn-danger"
              disabled={busy || typed !== row.name}
              onClick={close}
            >
              {busy ? 'Завершение…' : 'Завершить'}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

interface StudentRow {
  id: number;
  full_name: string;
  login: string;
  is_active: boolean;
  can_reveal: boolean;
}

/**
 * FR-13.17/13.18: credentials are the centre admin's job now — the
 * teacher's reveal is gone. Show the current password (audited and
 * rate-limited server-side) or set a new one, per student.
 */
function StudentsPanel({ classroomId }: { classroomId: number }) {
  const students = useAsync(
    () => api.get<{ students: StudentRow[] }>(`/manage/classrooms/${classroomId}/students`),
    [classroomId],
  );
  const [shown, setShown] = useState<{ name: string; login: string; password: string; what: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  async function reveal(student: StudentRow) {
    setBusyId(student.id);
    setError(null);

    try {
      const body = await api.get<{ login: string; password: string }>(
        `/manage/students/${student.id}/credential`,
      );
      setShown({
        name: student.full_name,
        login: body.login,
        password: body.password,
        what: 'Текущий пароль',
      });
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось показать пароль.');
    } finally {
      setBusyId(null);
    }
  }

  async function reset(student: StudentRow) {
    if (!confirm(`Сбросить пароль для «${student.full_name}»? Текущий перестанет работать.`)) {
      return;
    }

    const password = suggestStudentPassword();
    setBusyId(student.id);
    setError(null);

    try {
      await api.post(`/manage/students/${student.id}/password`, { password });
      setShown({ name: student.full_name, login: student.login, password, what: 'Новый пароль' });
      students.reload();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось сбросить пароль.');
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="inset" style={{ marginTop: 12 }}>
      {error && <div className="alert alert-error">{error}</div>}

      {shown && (
        <div className="alert alert-ok">
          {shown.what} — <strong>{shown.name}</strong>: логин <code>{shown.login}</code> · пароль{' '}
          <code>{shown.password}</code>{' '}
          <button className="btn btn-ghost btn-sm" onClick={() => setShown(null)}>
            Скрыть
          </button>
        </div>
      )}

      {students.loading && <p className="muted">Загрузка…</p>}

      {!students.loading && (students.data?.students.length ?? 0) === 0 && (
        <p className="muted" style={{ margin: 0 }}>В классе пока нет учеников.</p>
      )}

      {(students.data?.students.length ?? 0) > 0 && (
        <table>
          <thead>
            <tr>
              <th>Ученик</th>
              <th>Логин</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {(students.data?.students ?? []).map((s) => (
              <tr key={s.id}>
                <td>
                  <strong>{s.full_name}</strong>
                  {!s.is_active && <span className="badge"> отключён</span>}
                </td>
                <td className="muted">
                  <code>{s.login}</code>
                </td>
                <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                  <button
                    className="btn btn-ghost btn-sm"
                    disabled={busyId === s.id || !s.can_reveal}
                    title={s.can_reveal ? undefined : 'Пароль нельзя показать — задайте новый.'}
                    onClick={() => void reveal(s)}
                  >
                    Показать пароль
                  </button>{' '}
                  <button
                    className="btn btn-ghost btn-sm"
                    disabled={busyId === s.id}
                    onClick={() => void reset(s)}
                  >
                    Сбросить пароль
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

/**
 * FR-1.4 (since 2026-08-07): the centre admin enrols students, not the
 * teacher. A student's password stays recoverable, so losing this panel
 * is not losing the credentials — the admin can show it again in the
 * class list («Ученики»), FR-13.17.
 */
function StudentForm({
  classroomId,
  onCreated,
}: {
  classroomId: number;
  // Refreshes the row's student count. The form itself stays open so a
  // whole class list can be typed in one sitting.
  onCreated: () => void;
}) {
  const [name, setName] = useState('');
  const [login, setLogin] = useState('+993');
  const [password, setPassword] = useState(suggestStudentPassword);
  const [created, setCreated] = useState<{ name: string; login: string; password: string } | null>(
    null,
  );
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await api.post(`/manage/classrooms/${classroomId}/students`, {
        full_name: name.trim(),
        login: normalisePhone(login),
        password,
      });
      setCreated({ name: name.trim(), login: normalisePhone(login), password });
      setName('');
      setLogin('+993');
      setPassword(suggestStudentPassword());
      onCreated();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось создать ученика.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="inset" style={{ marginTop: 12 }} onSubmit={submit}>
      {error && <div className="alert alert-error">{error}</div>}

      {created && (
        <div className="alert alert-ok">
          Ученик создан: <strong>{created.name}</strong> · логин <code>{created.login}</code> ·
          пароль <code>{created.password}</code>. Передайте данные ученику — забытый пароль
          можно показать в списке «Ученики».
        </div>
      )}

      <div className="row">
        <div className="field">
          <label htmlFor={`st-name-${classroomId}`}>Имя и фамилия</label>
          <input
            id={`st-name-${classroomId}`}
            required
            autoFocus
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Merdan Erkinow"
          />
        </div>
        <div className="field">
          <label htmlFor={`st-login-${classroomId}`}>Номер телефона — это логин</label>
          <input
            id={`st-login-${classroomId}`}
            required
            value={login}
            onChange={(e) => setLogin(e.target.value)}
            placeholder="+99365123456"
          />
          <p className="hint">Формат: +993 и 8 цифр.</p>
        </div>
        <div className="field">
          <label htmlFor={`st-pass-${classroomId}`}>Пароль</label>
          <div className="with-action">
            <input
              id={`st-pass-${classroomId}`}
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
            <button
              type="button"
              className="btn btn-ghost btn-sm"
              onClick={() => setPassword(suggestStudentPassword())}
            >
              Сгенерировать
            </button>
          </div>
          <p className="hint">Не менее 4 символов.</p>
        </div>
      </div>

      <button className="btn btn-sm" disabled={busy}>
        {busy ? 'Создание…' : 'Создать ученика'}
      </button>
    </form>
  );
}

/**
 * What people actually type — spaces, dashes, a doubled +993 over the
 * pre-filled prefix — normalised to the +993XXXXXXXX the server accepts.
 */
function normalisePhone(raw: string): string {
  let digits = raw.replace(/[^0-9]/g, '');

  while (digits.length > 11 && digits.startsWith('993993')) {
    digits = digits.slice(3);
  }

  if (digits.length === 8) return `+993${digits}`;

  return digits === '' ? '' : `+${digits}`;
}

/** Short and dictatable — students read these aloud in class. */
function suggestStudentPassword(): string {
  const alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
  const bytes = crypto.getRandomValues(new Uint8Array(6));

  return [...bytes].map((b) => alphabet[b % alphabet.length]).join('');
}
