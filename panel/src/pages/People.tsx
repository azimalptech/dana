import { useMemo, useState, type FormEvent } from 'react';

import { ApiError, api, type PanelUser } from '../api';
import { useAsync } from '../hooks';

interface Center {
  id: number;
  name: string;
  city: string | null;
  address: string | null;
  is_active: boolean;
  admins: number;
  teachers: number;
  students: number;
  classrooms: number;
}

interface Staff {
  id: number;
  role: string;
  full_name: string;
  login: string;
  center_id: number | null;
  is_active: boolean;
  classrooms: number | null;
}

/**
 * A credential moment worth a persistent panel. Admin passwords are
 * hash-only — shown once, then never again. Teacher passwords are
 * recoverable since FR-13.18: the centre admin can show the current one
 * with «Показать пароль», so the note differs per role.
 */
interface Handover {
  name: string;
  login: string;
  password: string;
  what: string;
  note?: string;
}

/**
 * Centres and staff.
 *
 * The two roles want different things here, so they get different pages
 * rather than one page full of conditionals:
 *
 * - A superadmin runs a chain. They see a searchable list of centres and
 *   open one to see who works there. They create centres and centre
 *   admins — nothing else (FR-1.1). Teachers belong to the centre admin.
 * - A centre admin runs one centre. They see their own teachers and
 *   create more (FR-1.2).
 *
 * The server enforces both rules, so this page only has to make them
 * obvious.
 */
export default function People({ user }: { user: PanelUser }) {
  return user.role === 'superadmin' ? <CentersPage /> : <CenterStaffPage />;
}

/* ----------------------------------------------------- superadmin view */

function CentersPage() {
  const centers = useAsync(() => api.get<{ centers: Center[] }>('/manage/centers'));
  const staff = useAsync(() => api.get<{ staff: Staff[] }>('/manage/staff'));

  const [query, setQuery] = useState('');
  const [openId, setOpenId] = useState<number | null>(null);
  const [adding, setAdding] = useState(false);
  const [handover, setHandover] = useState<Handover | null>(null);

  const all = centers.data?.centers ?? [];
  const staffList = staff.data?.staff ?? [];

  const visible = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (needle === '') return all;

    return all.filter((c) =>
      `${c.name} ${c.city ?? ''} ${c.address ?? ''}`.toLowerCase().includes(needle),
    );
  }, [all, query]);

  const open = openId === null ? null : (all.find((c) => c.id === openId) ?? null);

  function reloadAll() {
    centers.reload();
    staff.reload();
  }

  if (open) {
    return (
      <CenterDetail
        center={open}
        staff={staffList.filter((s) => s.center_id === open.id)}
        loading={staff.loading}
        handover={handover}
        onHandover={setHandover}
        onChanged={reloadAll}
        onBack={() => {
          setOpenId(null);
          setHandover(null);
        }}
      />
    );
  }

  return (
    <>
      <h1>Центры и сотрудники</h1>
      <p className="sub">
        Откройте центр, чтобы увидеть его сотрудников. Вы создаёте центры и их
        администраторов — преподавателей набирает администратор центра.
      </p>

      {handover && <HandoverPanel handover={handover} onClose={() => setHandover(null)} />}

      <section className="card">
        <div className="card-head">
          <h2>Центры {all.length > 0 && <span className="muted">· {all.length}</span>}</h2>
          <button
            className={adding ? 'btn btn-ghost btn-sm' : 'btn btn-sm'}
            onClick={() => setAdding(!adding)}
          >
            {adding ? 'Отмена' : '+ Новый центр'}
          </button>
        </div>

        {adding && (
          <CenterForm
            onCancel={() => setAdding(false)}
            onCreated={() => {
              setAdding(false);
              reloadAll();
            }}
          />
        )}

        {all.length > 0 && (
          <input
            className="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Поиск по названию, городу или адресу"
          />
        )}

        {centers.error && <div className="alert alert-error">{centers.error}</div>}
        {centers.loading && <p className="muted">Загрузка…</p>}

        {!centers.loading && all.length === 0 && (
          <p className="muted">
            Пока нет центров. Создайте первый — без него нельзя добавить сотрудников.
          </p>
        )}

        {all.length > 0 && visible.length === 0 && (
          <p className="muted">Ничего не найдено по запросу «{query}».</p>
        )}

        {visible.length > 0 && (
          <table>
            <thead>
              <tr>
                <th>Центр</th>
                <th>Город</th>
                <th>Админов</th>
                <th>Преподавателей</th>
                <th>Классов</th>
                <th>Учеников</th>
              </tr>
            </thead>
            <tbody>
              {visible.map((c) => (
                <tr key={c.id} className="row-link" onClick={() => setOpenId(c.id)}>
                  <td>
                    <button className="link" onClick={() => setOpenId(c.id)}>
                      {c.name}
                    </button>
                    {!c.is_active && <span className="badge"> отключён</span>}
                  </td>
                  <td className="muted">{c.city ?? '—'}</td>
                  <td>
                    {c.admins === 0 ? (
                      <span className="badge badge-warn">нет</span>
                    ) : (
                      c.admins
                    )}
                  </td>
                  <td>{c.teachers}</td>
                  <td>{c.classrooms}</td>
                  <td>{c.students}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </>
  );
}

function CenterDetail({
  center,
  staff,
  loading,
  handover,
  onHandover,
  onChanged,
  onBack,
}: {
  center: Center;
  staff: Staff[];
  loading: boolean;
  handover: Handover | null;
  onHandover: (h: Handover | null) => void;
  onChanged: () => void;
  onBack: () => void;
}) {
  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const admins = staff.filter((s) => s.role === 'admin');
  const teachers = staff.filter((s) => s.role === 'teacher');

  // Superadmin edits anything (client, 2026-08-13). Deletion is refused
  // by the server while the centre still runs active courses (FR-1.14
  // stays the only way to end a course), so the error is surfaced as-is.
  async function removeCenter() {
    if (!confirm(`Удалить центр «${center.name}»? Оставшиеся сотрудники будут отключены.`)) {
      return;
    }

    setDeleteError(null);
    try {
      await api.del(`/manage/centers/${center.id}`);
      onChanged();
      onBack();
    } catch (e: unknown) {
      setDeleteError(e instanceof ApiError ? e.message : 'Не удалось удалить центр.');
    }
  }

  return (
    <>
      <button className="btn btn-ghost btn-sm" onClick={onBack}>
        ← Все центры
      </button>

      <div className="card-head" style={{ marginTop: 12 }}>
        <h1 style={{ margin: 0 }}>{center.name}</h1>
        <span>
          <button className="btn btn-ghost btn-sm" onClick={() => setEditing(!editing)}>
            {editing ? 'Отмена' : 'Изменить'}
          </button>{' '}
          <button className="btn btn-danger btn-sm" onClick={() => void removeCenter()}>
            Удалить центр
          </button>
        </span>
      </div>
      <p className="sub">
        {[center.city, center.address].filter(Boolean).join(' · ') || 'Адрес не указан'}
      </p>

      {deleteError && <div className="alert alert-error">{deleteError}</div>}

      {editing && (
        <CenterEditForm
          center={center}
          onDone={() => {
            setEditing(false);
            onChanged();
          }}
        />
      )}

      <div className="row" style={{ marginBottom: 20 }}>
        <Stat label="Администраторов" value={center.admins} />
        <Stat label="Преподавателей" value={center.teachers} />
        <Stat label="Классов" value={center.classrooms} />
        <Stat label="Учеников" value={center.students} />
      </div>

      {handover && <HandoverPanel handover={handover} onClose={() => onHandover(null)} />}

      <section className="card">
        <div className="card-head">
          <h2>Администраторы центра</h2>
          <button
            className={adding ? 'btn btn-ghost btn-sm' : 'btn btn-sm'}
            onClick={() => setAdding(!adding)}
          >
            {adding ? 'Отмена' : '+ Новый администратор'}
          </button>
        </div>

        {adding && (
          <AdminForm
            centerId={center.id}
            onCancel={() => setAdding(false)}
            onCreated={(created) => {
              setAdding(false);
              onHandover(created);
              onChanged();
            }}
          />
        )}

        {loading && <p className="muted">Загрузка…</p>}

        {!loading && admins.length === 0 && (
          <p className="muted">
            У центра нет администратора. Без него никто не сможет добавлять
            преподавателей и классы.
          </p>
        )}

        {admins.length > 0 && (
          <StaffTable people={admins} onReset={onHandover} onDeleted={onChanged} />
        )}
      </section>

      <section className="card">
        <div className="card-head">
          <h2>Преподаватели</h2>
        </div>

        <p className="hint" style={{ marginTop: 0 }}>
          Преподавателей создаёт администратор центра. Здесь можно сбросить
          пароль или удалить — создать нельзя.
        </p>

        {loading && <p className="muted">Загрузка…</p>}

        {!loading && teachers.length === 0 && (
          <p className="muted">Пока нет преподавателей.</p>
        )}

        {teachers.length > 0 && (
          <StaffTable people={teachers} onReset={onHandover} onDeleted={onChanged} />
        )}
      </section>
    </>
  );
}

/* ---------------------------------------------------- centre-admin view */

function CenterStaffPage() {
  const staff = useAsync(() => api.get<{ staff: Staff[] }>('/manage/staff'));

  const [adding, setAdding] = useState(false);
  const [handover, setHandover] = useState<Handover | null>(null);

  const teachers = staff.data?.staff ?? [];

  return (
    <>
      <h1>Преподаватели</h1>
      <p className="sub">Сотрудники вашего центра. Классы им назначаются в разделе «Классы».</p>

      {handover && <HandoverPanel handover={handover} onClose={() => setHandover(null)} />}

      <section className="card">
        <div className="card-head">
          <h2>
            Преподаватели {teachers.length > 0 && <span className="muted">· {teachers.length}</span>}
          </h2>
          <button
            className={adding ? 'btn btn-ghost btn-sm' : 'btn btn-sm'}
            onClick={() => setAdding(!adding)}
          >
            {adding ? 'Отмена' : '+ Новый преподаватель'}
          </button>
        </div>

        {adding && (
          <TeacherForm
            onCancel={() => setAdding(false)}
            onCreated={(created) => {
              setAdding(false);
              setHandover(created);
              staff.reload();
            }}
          />
        )}

        {staff.error && <div className="alert alert-error">{staff.error}</div>}
        {staff.loading && <p className="muted">Загрузка…</p>}

        {!staff.loading && teachers.length === 0 && (
          <p className="muted">Пока нет преподавателей. Создайте первого.</p>
        )}

        {/* FR-13.18: the centre admin may view teacher passwords. */}
        {teachers.length > 0 && (
          <StaffTable
            people={teachers}
            onReset={setHandover}
            canReveal
            onDeleted={() => staff.reload()}
          />
        )}
      </section>
    </>
  );
}

/* ------------------------------------------------------------- shared */

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="card" style={{ marginBottom: 0 }}>
      <div className="muted" style={{ fontSize: 11, textTransform: 'uppercase' }}>
        {label}
      </div>
      <div style={{ fontSize: 26, fontWeight: 700, marginTop: 4 }}>{value}</div>
    </div>
  );
}

function StaffTable({
  people,
  onReset,
  canReveal = false,
  onDeleted,
}: {
  people: Staff[];
  onReset: (handover: Handover) => void;
  /** FR-13.18: only the centre admin's own view offers the reveal. */
  canReveal?: boolean;
  /** When set, rows offer «Удалить» — admins and teachers alike. */
  onDeleted?: () => void;
}) {
  return (
    <table>
      <thead>
        <tr>
          <th>Имя</th>
          <th>Логин</th>
          <th>Классов</th>
          <th />
        </tr>
      </thead>
      <tbody>
        {[...people]
          .sort((a, b) => a.full_name.localeCompare(b.full_name))
          .map((person) => (
            <StaffRow
              key={person.id}
              person={person}
              onReset={onReset}
              canReveal={canReveal}
              onDeleted={onDeleted}
            />
          ))}
      </tbody>
    </table>
  );
}

/**
 * The only moment a staff password is visible. It is hashed on the
 * server and cannot be recovered (FR-1.11) — losing it means a reset —
 * so it gets a panel that stays until dismissed, not a toast.
 */
function HandoverPanel({ handover, onClose }: { handover: Handover; onClose: () => void }) {
  const [copied, setCopied] = useState(false);

  const text = `${handover.name}\nЛогин: ${handover.login}\nПароль: ${handover.password}`;

  return (
    <div className="alert alert-ok handover">
      <div className="handover-head">
        <strong>{handover.what}</strong>
        <button className="btn btn-ghost btn-sm" onClick={onClose}>Закрыть</button>
      </div>

      <p style={{ margin: '6px 0 12px', fontSize: 13 }}>
        {handover.note ??
          'Передайте эти данные сотруднику. Пароль больше не будет показан — его можно только сбросить.'}
      </p>

      <div className="handover-creds">
        <div><span className="muted">Имя</span><br />{handover.name}</div>
        <div><span className="muted">Логин</span><br /><code>{handover.login}</code></div>
        <div><span className="muted">Пароль</span><br /><code>{handover.password}</code></div>
      </div>

      <button
        className="btn btn-sm"
        style={{ marginTop: 12 }}
        onClick={async () => {
          try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
          } catch {
            setCopied(false);
          }
        }}
      >
        {copied ? 'Скопировано' : 'Скопировать'}
      </button>
    </div>
  );
}

function StaffRow({
  person,
  onReset,
  canReveal,
  onDeleted,
}: {
  person: Staff;
  onReset: (handover: Handover) => void;
  canReveal: boolean;
  onDeleted?: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function removeStaff() {
    setBusy(true);
    setError(null);

    try {
      await api.del(
        person.role === 'admin'
          ? `/manage/admins/${person.id}`
          : `/manage/teachers/${person.id}`,
      );
      onDeleted?.();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось удалить.');
    } finally {
      setBusy(false);
    }
  }

  /**
   * FR-13.18: shows the teacher's CURRENT password — audited and
   * rate-limited server-side. Teachers created before recoverable
   * storage answer 422 with the fix (set a new password).
   */
  async function revealPassword() {
    setBusy(true);
    setError(null);

    try {
      const body = await api.get<{ login: string; password: string }>(
        `/manage/staff/${person.id}/credential`,
      );
      onReset({
        name: person.full_name,
        login: body.login,
        password: body.password,
        what: 'Текущий пароль',
        note: 'Это действующий пароль преподавателя — его можно показать снова в любой момент.',
      });
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось показать пароль.');
    } finally {
      setBusy(false);
    }
  }

  async function resetPassword() {
    const password = suggestPassword();
    setBusy(true);
    setError(null);

    try {
      await api.post(`/manage/staff/${person.id}/password`, { password });
      onReset({
        name: person.full_name,
        login: person.login,
        password,
        what: 'Пароль сброшен',
        note:
          person.role === 'teacher'
            ? 'Передайте пароль преподавателю. Забытый пароль можно показать кнопкой «Показать пароль».'
            : undefined,
      });
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось сбросить пароль.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <tr>
      <td>
        <strong>{person.full_name}</strong>
        {!person.is_active && <span className="badge"> отключён</span>}
      </td>
      <td className="muted"><code>{person.login}</code></td>
      <td>{person.role === 'teacher' ? (person.classrooms ?? 0) : '—'}</td>
      <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
        {error && <span style={{ color: 'var(--error)', fontSize: 12, marginRight: 8 }}>{error}</span>}
        {canReveal && person.role === 'teacher' && (
          <>
            <button className="btn btn-ghost btn-sm" disabled={busy} onClick={() => void revealPassword()}>
              {busy ? '…' : 'Показать пароль'}
            </button>{' '}
          </>
        )}
        <button
          className="btn btn-ghost btn-sm"
          disabled={busy}
          onClick={() => {
            if (confirm(`Сбросить пароль для «${person.full_name}»? Текущий перестанет работать.`)) {
              void resetPassword();
            }
          }}
        >
          {busy ? '…' : 'Сбросить пароль'}
        </button>
        {onDeleted && (
          <>
            {' '}
            <button
              className="btn btn-danger btn-sm"
              disabled={busy}
              onClick={() => {
                const who = person.role === 'admin' ? 'администратора' : 'преподавателя';
                if (confirm(`Удалить ${who} «${person.full_name}»? Доступ пропадёт сразу.`)) {
                  void removeStaff();
                }
              }}
            >
              Удалить
            </button>
          </>
        )}
      </td>
    </tr>
  );
}

/* ------------------------------------------------------------ forms */

/** Superadmin edits anything (client, 2026-08-13): centre details. */
function CenterEditForm({ center, onDone }: { center: Center; onDone: () => void }) {
  const [name, setName] = useState(center.name);
  const [city, setCity] = useState(center.city ?? '');
  const [address, setAddress] = useState(center.address ?? '');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await api.post(`/manage/centers/${center.id}`, { name, city, address });
      onDone();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось сохранить.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="inset" onSubmit={submit} style={{ marginBottom: 16 }}>
      {error && <div className="alert alert-error">{error}</div>}

      <div className="row">
        <div className="field">
          <label htmlFor="ce-name">Название</label>
          <input id="ce-name" required autoFocus value={name} onChange={(e) => setName(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="ce-city">Город</label>
          <input id="ce-city" value={city} onChange={(e) => setCity(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="ce-address">Адрес</label>
          <input id="ce-address" value={address} onChange={(e) => setAddress(e.target.value)} />
        </div>
      </div>

      <button className="btn btn-sm" disabled={busy}>
        {busy ? 'Сохранение…' : 'Сохранить'}
      </button>
    </form>
  );
}

function CenterForm({ onCancel, onCreated }: { onCancel: () => void; onCreated: () => void }) {
  const [name, setName] = useState('');
  const [city, setCity] = useState('');
  const [address, setAddress] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await api.post('/manage/centers', { name, city, address });
      onCreated();
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось создать центр.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="inset" onSubmit={submit}>
      {error && <div className="alert alert-error">{error}</div>}

      <div className="row">
        <div className="field">
          <label htmlFor="c-name">Название</label>
          <input id="c-name" required autoFocus value={name}
            onChange={(e) => setName(e.target.value)} placeholder="Dana Aşgabat" />
        </div>
        <div className="field">
          <label htmlFor="c-city">Город</label>
          <input id="c-city" value={city}
            onChange={(e) => setCity(e.target.value)} placeholder="Aşgabat" />
        </div>
      </div>

      <div className="field">
        <label htmlFor="c-addr">Адрес</label>
        <input id="c-addr" value={address}
          onChange={(e) => setAddress(e.target.value)} placeholder="Görogly köçesi 45" />
      </div>

      <button className="btn btn-sm" disabled={busy}>{busy ? 'Создание…' : 'Создать центр'}</button>{' '}
      <button type="button" className="btn btn-ghost btn-sm" onClick={onCancel}>Отмена</button>
    </form>
  );
}

function AdminForm({
  centerId,
  onCancel,
  onCreated,
}: {
  centerId: number;
  onCancel: () => void;
  onCreated: (handover: Handover) => void;
}) {
  return (
    <PersonForm
      what="Администратор создан"
      submitLabel="Создать администратора"
      hint="Управляет своим центром: преподаватели, классы, уведомления, отчёты."
      onCancel={onCancel}
      onCreated={onCreated}
      send={(payload) => api.post('/manage/admins', { ...payload, center_id: centerId })}
    />
  );
}

function TeacherForm({
  onCancel,
  onCreated,
}: {
  onCancel: () => void;
  onCreated: (handover: Handover) => void;
}) {
  return (
    <PersonForm
      what="Преподаватель создан"
      submitLabel="Создать преподавателя"
      hint="Видит свои классы и результаты учеников. Только просмотр — учеников создаёте вы."
      passwordHint="Не менее 8 символов. Забытый пароль можно показать кнопкой «Показать пароль»."
      note="Передайте эти данные преподавателю. Пароль можно показать снова в любой момент."
      onCancel={onCancel}
      onCreated={onCreated}
      send={(payload) => api.post('/manage/teachers', payload)}
    />
  );
}

/** The fields are identical for both roles; only endpoint and copy differ. */
function PersonForm({
  what,
  submitLabel,
  hint,
  passwordHint,
  note,
  send,
  onCancel,
  onCreated,
}: {
  what: string;
  submitLabel: string;
  hint: string;
  passwordHint?: string;
  note?: string;
  send: (payload: Record<string, unknown>) => Promise<unknown>;
  onCancel: () => void;
  onCreated: (handover: Handover) => void;
}) {
  const [name, setName] = useState('');
  const [login, setLogin] = useState('+993');
  const [password, setPassword] = useState(suggestPassword);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await send({ full_name: name.trim(), login: login.trim(), password });
      onCreated({ name: name.trim(), login: login.trim(), password, what, note });
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось создать сотрудника.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="inset" onSubmit={submit}>
      {error && <div className="alert alert-error">{error}</div>}

      <p className="hint" style={{ marginTop: 0 }}>{hint}</p>

      <div className="row">
        <div className="field">
          <label htmlFor="s-name">Имя и фамилия</label>
          <input id="s-name" required autoFocus value={name}
            onChange={(e) => setName(e.target.value)} placeholder="Aýgül Nuryýewa" />
        </div>

        <div className="field">
          <label htmlFor="s-login">Номер телефона — это логин</label>
          <input id="s-login" required value={login}
            onChange={(e) => setLogin(e.target.value)} placeholder="+99361000001" />
          <p className="hint">Формат: +993 и 8 цифр.</p>
        </div>
      </div>

      <div className="field">
        <label htmlFor="s-pass">Пароль</label>
        <div className="with-action">
          <input id="s-pass" required value={password} onChange={(e) => setPassword(e.target.value)} />
          <button type="button" className="btn btn-ghost btn-sm"
            onClick={() => setPassword(suggestPassword())}>Сгенерировать</button>
        </div>
        <p className="hint">
          {passwordHint ??
            'Не менее 8 символов. Показывается один раз — сохранить его нельзя, только сбросить.'}
        </p>
      </div>

      <button className="btn" disabled={busy}>{busy ? 'Создание…' : submitLabel}</button>{' '}
      <button type="button" className="btn btn-ghost" onClick={onCancel}>Отмена</button>
    </form>
  );
}

/**
 * Readable and long enough for the 8-character staff minimum. Ambiguous
 * characters are left out because these get read aloud and copied by
 * hand — a password nobody can dictate is a password nobody uses.
 */
function suggestPassword(): string {
  const alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
  const bytes = crypto.getRandomValues(new Uint8Array(10));

  return [...bytes].map((b) => alphabet[b % alphabet.length]).join('');
}
