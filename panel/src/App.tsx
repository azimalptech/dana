import { useCallback, useEffect, useState } from 'react';

import NavIcon, { type NavIconName } from './NavIcon';
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
  /** Shown beside the label, and alone in the collapsed rail. */
  icon: NavIconName;
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
    { id: 'people', label: 'Центры и сотрудники', icon: 'people' },
    { id: 'curriculum', label: 'Программа', icon: 'curriculum' },
    { id: 'content', label: 'Контент', icon: 'content' },
    { id: 'data', label: 'База данных', icon: 'data' },
  ],
  admin: [
    { id: 'progress', label: 'Прогресс', icon: 'progress' },
    { id: 'people', label: 'Преподаватели', icon: 'people' },
    { id: 'classrooms', label: 'Классы', icon: 'classrooms' },
    { id: 'notify', label: 'Уведомления', icon: 'notify' },
  ],
};

/** Below this width the sidebar stops being a column and becomes a drawer. */
const DRAWER_BELOW = 900;

const NAV_KEY = 'panel_nav_open';

/**
 * True while the sidebar is an off-canvas drawer rather than a column.
 *
 * Needed because "closed" means two different things now. On a desktop
 * it is a 72px rail that is still on screen and still usable, so hiding
 * it from assistive technology would be wrong; below the breakpoint it
 * is genuinely off-canvas and must be hidden. A media query cannot say
 * which, so the component has to know.
 */
function useIsDrawer(): boolean {
  const query = `(max-width: ${DRAWER_BELOW - 1}px)`;
  const [isDrawer, setIsDrawer] = useState(
    () => typeof window !== 'undefined' && window.matchMedia(query).matches,
  );

  useEffect(() => {
    const mql = window.matchMedia(query);
    const onChange = (e: MediaQueryListEvent) => setIsDrawer(e.matches);

    setIsDrawer(mql.matches);
    mql.addEventListener('change', onChange);
    return () => mql.removeEventListener('change', onChange);
  }, [query]);

  return isDrawer;
}

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
  const isDrawer = useIsDrawer();

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
      {/* Below the drawer breakpoint the sidebar is off-canvas, so there
          is no edge to hang the chevron on and this burger opens it.
          Hidden on a desktop, where the chevron on the sidebar's own
          edge does the job (FR-15.25). */}
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

      {/* Hidden from assistive technology only when it is actually off
          the screen. Collapsed on a desktop is a visible rail. */}
      <aside className="sidebar" aria-hidden={isDrawer && !navOpen}>
        {/* Wordmark and role, both gone in the collapsed rail — there is
            no width for them, and the nav icons below say where you
            are. */}
        <div className="brand-row">
          <div className="brand-text">
            <div className="brand-name">dana</div>
            <div className="brand-who">
              {isSuperadmin ? 'Суперадмин' : 'Администратор центра'}
            </div>
          </div>
        </div>

        {/* The chevron rides the sidebar's right edge, half over the
            boundary, so it belongs to the menu rather than to the page —
            and it points the way the menu will move. */}
        <button
          type="button"
          className="nav-edge"
          aria-label={navOpen ? 'Свернуть меню' : 'Развернуть меню'}
          aria-expanded={navOpen}
          onClick={() => setNavOpen((open) => !open)}
        >
          <svg
            viewBox="0 0 24 24"
            width="14"
            height="14"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
          >
            <path d={navOpen ? 'M15 18l-6-6 6-6' : 'M9 18l6-6-6-6'} />
          </svg>
        </button>

        {visible.map((entry) => (
          <button
            key={entry.id}
            className={`nav-item${current === entry.id ? ' active' : ''}`}
            // The rail shows no label, so the tooltip and the accessible
            // name have to carry it there.
            title={entry.label}
            aria-label={entry.label}
            onClick={() => {
              setPage(entry.id);
              // On a phone the drawer covers what was just chosen, so
              // choosing dismisses it. On a desktop it is a column and
              // must stay put.
              if (window.innerWidth < DRAWER_BELOW) setNavOpen(false);
            }}
          >
            <NavIcon name={entry.icon} />
            <span className="nav-label">{entry.label}</span>
          </button>
        ))}

        <button
          className="nav-item nav-signout"
          title="Выйти"
          aria-label="Выйти"
          onClick={async () => {
            await api.logout();
            setUser(null);
          }}
        >
          <NavIcon name="signout" />
          <span className="nav-label">Выйти</span>
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
