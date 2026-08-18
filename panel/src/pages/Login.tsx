import { useState, type FormEvent } from 'react';

import { ApiError, api, type PanelUser } from '../api';

export default function Login({ onSignedIn }: { onSignedIn: (user: PanelUser) => void }) {
  const [login, setLogin] = useState('+993');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      onSignedIn(await api.login(login.trim(), password));
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Не удалось войти.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="login-wrap">
      <form className="login-box" onSubmit={submit}>
        <div className="login-head">
          <div className="logo">dana</div>
          <div style={{ opacity: 0.8, fontSize: 13 }}>Панель управления</div>
        </div>

        <div className="login-body">
          {error && <div className="alert alert-error">{error}</div>}

          <div className="field">
            <label htmlFor="login">Номер телефона</label>
            <input
              id="login"
              value={login}
              onChange={(e) => setLogin(e.target.value)}
              placeholder="+99365002233"
              autoComplete="username"
            />
          </div>

          <div className="field">
            <label htmlFor="password">Пароль</label>
            <input
              id="password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="current-password"
            />
          </div>

          <button className="btn" style={{ width: '100%' }} disabled={busy}>
            {busy ? 'Вход…' : 'Войти'}
          </button>

          <p className="muted" style={{ fontSize: 12, marginTop: 14, marginBottom: 0 }}>
            Преподаватели и ученики используют мобильное приложение.
          </p>
        </div>
      </form>
    </div>
  );
}
