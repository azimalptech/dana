import { useCallback, useEffect, useState } from 'react';

import { ApiError, api, type PanelUser } from './api';
import Classrooms from './pages/Classrooms';
import Content from './pages/Content';
import Curriculum from './pages/Curriculum';
import Data from './pages/Data';
import Login from './pages/Login';
import Notify from './pages/Notify';
import People from './pages/People';
import Progress from './pages/Progress';

type Page = 'progress' | 'people' | 'classrooms' | 'notify' | 'content' | 'curriculum' | 'data';

interface NavEntry {
  id: Page;
  label: string;
}

/**
 * The two roles run different jobs, so they get different menus rather
 * than one menu with half the items greyed out.
 *
 * A superadmin runs the chain: they see progress across every centre,
 * open a centre to manage it, and own the course material. A centre admin
 * runs one centre: teachers, classes, announcements and their own numbers.
 * Announcements are deliberately absent from the superadmin menu — there
 * is no all-users broadcast (FR-10.1).
 */
const NAV: Record<'superadmin' | 'admin', NavEntry[]> = {
  // Прогресс is deliberately absent for the superadmin (client decision,
  // 2026-08-13) — their job here is centres and content, not metrics.
  superadmin: [
    { id: 'people', label: 'Центры и сотрудники' },
    { id: 'curriculum', label: 'Программа' },
    { id: 'content', label: 'Контент' },
    { id: 'data', label: 'База данных' },
  ],
  admin: [
    { id: 'progress', label: 'Прогресс' },
    { id: 'people', label: 'Преподаватели' },
    { id: 'classrooms', label: 'Классы' },
    { id: 'notify', label: 'Уведомления' },
  ],
};

/** Below this width the sidebar stops being a column and becomes a drawer. */
const DRAWER_BELOW = 900;

const NAV_KEY = 'panel_nav_open';

/**
 * Whether the menu starts open (FR-15.25).
 *
 * Remembered per browser, because on a desktop it is a layout preference
 * and re-hiding it on every load would be a chore. With nothing
 * remembered the width decides: open on a desktop, where 232px costs
 * nothing, closed on a phone, where it would cover the page.
 */
function initialNavOpen(): boolean {
  try {
    const saved = localStorage.getItem(NAV_KEY);
    if (saved !== null) return saved === '1';
  } catch {
    // Private mode, or site data blocked. Fall through to the width.
  }

  return typeof window === 'undefined' || window.innerWidth >= DRAWER_BELOW;
}

export default function App() {
  const [user, setUser] = useState<PanelUser | null>(null);
  const [booting, setBooting] = useState(true);
  const [unreachable, setUnreachable] = useState(false);
  const [page, setPage] = useState<Page>('progress');
  const [navOpen, setNavOpen] = useState(initialNavOpen);

  useEffect(() => {
    try {
      localStorage.setItem(NAV_KEY, navOpen ? '1' : '0');
    } catch {
      // Nothing to do — the menu still works, it just will not be
      // remembered next time.
    }
  }, [navOpen]);

  // Escape closes the drawer. On a phone it covers the page, so there
  // has to be a way out that is not the backdrop.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && window.innerWidth < DRAWER_BELOW) setNavOpen(false);
    };

    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  const boot = useCallback(() => {
    if (!api.isSignedIn()) {
      setBooting(false);
      return;
    }

    setBooting(true);
    setUnreachable(false);

    api
      .get<{ user: PanelUser }>('/auth/me')
      .then((body) => setUser(body.user))
      .catch((e: unknown) => {
        if (!(e instanceof ApiError)) return;

        // Only 401/403 mean the session was REJECTED. Everything else —
        // no connection, a 5xx, or the 404 a proxy returns when its
        // backend is down — means the server could not answer, which is
        // not a reason to make an admin type their password again
        // (FR-15.15).
        if (e.status !== 401 && e.status !== 403) {
          setUnreachable(true);
          return;
        }

        setUser(null);
      })
      .finally(() => setBooting(false));
  }, []);

  useEffect(boot, [boot]);

  if (booting) {
    return <div className="login-wrap muted">Загрузка…</div>;
  }

  if (unreachable && !user) {
    return (
      <div className="login-wrap">
        <p className="muted">Сервер недоступен. Сессия сохранена.</p>
        <button type="button" className="btn" onClick={boot}>
          Повторить
        </button>
      </div>
    );
  }

  if (!user) {
    return <Login onSignedIn={setUser} />;
  }

  const isSuperadmin = user.role === 'superadmin';
  const visible = NAV[isSuperadmin ? 'superadmin' : 'admin'];

  // A page the current role has no entry for is not rendered, so a stale
  // `page` from a previous session cannot show something it should not.
  const current = visible.some((entry) => entry.id === page) ? page : visible[0].id;

  const currentLabel = visible.find((entry) => entry.id === current)?.label ?? '';

  return (
    <div className={`shell${navOpen ? '' : ' nav-closed'}`}>
      {/* One control for every screen (FR-15.25). On a desktop it hides
          and restores the column; below 900px the same button opens the
          sidebar over the page as a drawer. */}
      <header className="topbar">
        <button
          type="button"
          className="nav-toggle"
          aria-label={navOpen ? 'Скрыть меню' : 'Показать меню'}
          aria-expanded={navOpen}
          onClick={() => setNavOpen((open) => !open)}
        >
          {/* Always the burger, never a ✕ (client, 2026-09-14). The
              glyph names the menu rather than reporting its state, so it
              stays one recognisable control instead of turning into a
              close button that reads as "dismiss this page". State is
              still carried for screen readers by aria-expanded and the
              label above. */}
          <span aria-hidden="true">☰</span>
        </button>
        <span className="topbar-title">{currentLabel}</span>
      </header>

      {/* Tapping away closes the drawer. Rendered only when open, and
          only visible under the drawer breakpoint — on a desktop the
          sidebar takes its own column and dims nothing. */}
      {navOpen && (
        <button
          type="button"
          className="nav-backdrop"
          aria-label="Закрыть меню"
          tabIndex={-1}
          onClick={() => setNavOpen(false)}
        />
      )}

      <aside className="sidebar" aria-hidden={!navOpen}>
        <div className="brand">dana</div>
        <div className="who">
          {user.full_name}
          <br />
          {isSuperadmin ? 'Суперадмин' : 'Администратор центра'}
        </div>

        {visible.map((entry) => (
          <button
            key={entry.id}
            className={`nav-item${current === entry.id ? ' active' : ''}`}
            onClick={() => {
              setPage(entry.id);
              // On a phone the drawer covers what was just chosen, so
              // choosing dismisses it. On a desktop it is a column and
              // must stay put.
              if (window.innerWidth < DRAWER_BELOW) setNavOpen(false);
            }}
          >
            {entry.label}
          </button>
        ))}

        <button
          className="nav-item nav-signout"
          onClick={async () => {
            await api.logout();
            setUser(null);
          }}
        >
          Выйти
        </button>
      </aside>

      <main className="content">
        {current === 'progress' && <Progress user={user} />}
        {current === 'people' && <People user={user} />}
        {current === 'classrooms' && <Classrooms />}
        {current === 'notify' && <Notify />}
        {current === 'content' && isSuperadmin && <Content />}
        {current === 'curriculum' && isSuperadmin && <Curriculum />}
        {current === 'data' && isSuperadmin && <Data />}
      </main>
    </div>
  );
}
