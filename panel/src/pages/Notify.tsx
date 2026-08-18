import { useState, type FormEvent } from 'react';

import { ApiError, api } from '../api';
import { useAsync } from '../hooks';

interface ClassroomRow {
  id: number;
  name: string;
  closed: boolean;
}

/**
 * FR-10.2: a centre admin reaches their own centre, and only that one —
 * the centre is taken from the token, never from this form, so there is
 * no field here that could address someone else's students. There is no
 * all-users broadcast (FR-10.1).
 *
 * Both languages are required: a student whose interface is Russian must
 * not receive an empty message.
 */
export default function Notify() {
  const classrooms = useAsync(() =>
    api.get<{ classrooms: ClassroomRow[] }>('/manage/progress'),
  );

  const [scope, setScope] = useState('center');
  const [classroomId, setClassroomId] = useState('');
  const [form, setForm] = useState({ title_tk: '', title_ru: '', body_tk: '', body_ru: '' });
  const [result, setResult] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function send(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setResult(null);

    try {
      const body: Record<string, unknown> = { scope, ...form };
      if (scope === 'classroom') body.classroom_id = Number(classroomId);

      const sent = await api.post<{ recipients: number }>('/notifications', body);
      setResult(`Отправлено. Получателей: ${sent.recipients}.`);
      setForm({ title_tk: '', title_ru: '', body_tk: '', body_ru: '' });
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось отправить.');
    } finally {
      setBusy(false);
    }
  }

  const open = (classrooms.data?.classrooms ?? []).filter((c) => !c.closed);

  return (
    <>
      <h1>Уведомления</h1>
      <p className="sub">
        Сообщение уходит только вашему центру. Оно приходит push-уведомлением и остаётся
        во «Входящих» в приложении, даже если push не доставлен.
      </p>

      <form className="card" onSubmit={send}>
        <h2>Новое сообщение</h2>
        {error && <div className="alert alert-error">{error}</div>}
        {result && <div className="alert alert-ok">{result}</div>}

        <div className="field">
          <label htmlFor="scope">Кому</label>
          <select id="scope" value={scope} onChange={(e) => setScope(e.target.value)}>
            <option value="center">Всему центру</option>
            <option value="classroom">Одному классу</option>
          </select>
        </div>

        {scope === 'classroom' && (
          <div className="field">
            <label htmlFor="classroom">Класс</label>
            {/* If the class list failed to load, say so — an empty picker
                could otherwise nudge an admin into sending centre-wide. */}
            {classrooms.error && (
              <div className="alert alert-error">
                Не удалось загрузить классы.{' '}
                <button type="button" className="link" onClick={() => classrooms.reload()}>
                  Повторить
                </button>
              </div>
            )}
            <select
              id="classroom"
              required
              value={classroomId}
              onChange={(e) => setClassroomId(e.target.value)}
            >
              <option value="">—</option>
              {open.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
        )}

        <div className="row">
          <div className="field">
            <label htmlFor="title_tk">Заголовок — türkmençe</label>
            <input
              id="title_tk"
              required
              value={form.title_tk}
              onChange={(e) => setForm({ ...form, title_tk: e.target.value })}
            />
          </div>
          <div className="field">
            <label htmlFor="title_ru">Заголовок — по-русски</label>
            <input
              id="title_ru"
              required
              value={form.title_ru}
              onChange={(e) => setForm({ ...form, title_ru: e.target.value })}
            />
          </div>
        </div>

        <div className="row">
          <div className="field">
            <label htmlFor="body_tk">Текст — türkmençe</label>
            <textarea
              id="body_tk"
              required
              rows={4}
              value={form.body_tk}
              onChange={(e) => setForm({ ...form, body_tk: e.target.value })}
            />
          </div>
          <div className="field">
            <label htmlFor="body_ru">Текст — по-русски</label>
            <textarea
              id="body_ru"
              required
              rows={4}
              value={form.body_ru}
              onChange={(e) => setForm({ ...form, body_ru: e.target.value })}
            />
          </div>
        </div>

        <button className="btn" disabled={busy}>
          {busy ? 'Отправка…' : 'Отправить'}
        </button>
      </form>
    </>
  );
}
