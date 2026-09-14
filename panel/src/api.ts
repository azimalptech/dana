/**
 * API client for the panel.
 *
 * Same server-side rules apply as for the app: the panel never decides
 * what an admin may see — every list comes back already scoped.
 */

export interface ApiErrorShape {
  code: string;
  message_tk: string;
  message_ru: string;
}

export class ApiError extends Error {
  constructor(
    public code: string,
    public messageTk: string,
    public messageRu: string,
    public status: number,
  ) {
    // The panel is used by staff, who read Russian more often than not.
    super(messageRu || messageTk || code);
  }
}

export interface PanelUser {
  id: number;
  role: 'superadmin' | 'admin' | 'teacher' | 'student';
  login: string;
  full_name: string;
  center_id: number | null;
}

// Where the API lives.
//
// The default is RELATIVE, which is right for the two layouts that need
// no configuration: the Vite dev server, which proxies /api to the PHP
// server, and a single-origin deployment where the same host serves both
// the panel and the API.
//
// FR-15.24 added a third: the panel on its own subdomain. There a
// relative path resolves against admin.mydana.app, which serves no API,
// so the origin has to be named. Set VITE_API_BASE at BUILD time — Vite
// inlines it, so this is baked into the bundle, not read at runtime:
//
//   VITE_API_BASE=https://api.mydana.app/api/v1
//
// That request is then cross-origin, which only works because the API
// allowlists this panel's origin in CORS_ALLOWED_ORIGINS. Changing one
// without the other breaks the panel with an opaque browser error, so
// they are documented together in deploy/DEPLOY.md.
const BASE = import.meta.env.VITE_API_BASE ?? '/api/v1';

const ACCESS_KEY = 'panel_access';
const REFRESH_KEY = 'panel_refresh';
const EXPIRY_KEY = 'panel_access_expires';

// '' is what an older build wrote when it had no refresh token, and
// `?? null` lets it through — an empty string is not null. The session
// then had an access token, no way to renew it, and died at 15 minutes.
function stored(key: string): string | null {
  return localStorage.getItem(key) || null;
}

let accessToken: string | null = stored(ACCESS_KEY);
let refreshToken: string | null = stored(REFRESH_KEY);

/** Epoch ms at which the access token stops being accepted. */
let accessExpiresAt = Number(stored(EXPIRY_KEY) ?? 0);

function persist(): void {
  if (accessToken && refreshToken) {
    localStorage.setItem(ACCESS_KEY, accessToken);
    localStorage.setItem(REFRESH_KEY, refreshToken);
    localStorage.setItem(EXPIRY_KEY, String(accessExpiresAt));
  } else {
    accessToken = null;
    refreshToken = null;
    accessExpiresAt = 0;
    localStorage.removeItem(ACCESS_KEY);
    localStorage.removeItem(REFRESH_KEY);
    localStorage.removeItem(EXPIRY_KEY);
  }
}

function adopt(access: string, refreshTok: string, expiresIn: number | undefined): void {
  accessToken = access;
  refreshToken = refreshTok;
  // A minute of slack for clock skew between this machine and the
  // server; the proactive refresh below leans on this number, so it is
  // better a little early than a little late.
  accessExpiresAt = Date.now() + Math.max(0, (expiresIn ?? 900) - 60) * 1000;
  persist();
}

// Rotation is single-use, and every tab of the panel is a separate copy
// of these variables over ONE localStorage. Without this listener the
// second tab keeps using the token the first one already spent: it gets
// a 401, refreshes with a dead token, and the server reads that as a
// stolen token and ends every session the admin has. Adopting a
// sibling's rotation keeps all the tabs on one live chain.
window.addEventListener('storage', (event) => {
  if (event.key !== ACCESS_KEY && event.key !== REFRESH_KEY && event.key !== EXPIRY_KEY) {
    return;
  }

  accessToken = stored(ACCESS_KEY);
  refreshToken = stored(REFRESH_KEY);
  accessExpiresAt = Number(stored(EXPIRY_KEY) ?? 0);
});

async function send<T>(
  method: 'GET' | 'POST' | 'DELETE',
  path: string,
  body?: unknown,
  retry = true,
): Promise<T> {
  let response: Response;

  // Renew BEFORE the token dies rather than after. Waiting for the 401
  // means every fifteenth minute of work starts with a failed request,
  // and each of those is a chance to hit the network exactly when the
  // connection is down — which is when a session used to be lost.
  if (retry && accessToken && refreshToken && accessExpiresAt > 0 && !path.startsWith('/auth/')) {
    if (Date.now() >= accessExpiresAt) {
      await refresh();
    }
  }

  try {
    response = await fetch(`${BASE}${path}`, {
      method,
      headers: {
        'Content-Type': 'application/json; charset=utf-8',
        ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
      },
      body: method === 'GET' || method === 'DELETE' ? undefined : JSON.stringify(body ?? {}),
    });
  } catch {
    throw new ApiError('network', 'Birikme ýok.', 'Нет соединения с сервером.', 0);
  }

  const text = await response.text();
  const isJson = response.headers.get('content-type')?.includes('application/json');
  const data = text && isJson ? JSON.parse(text) : text;

  if (response.ok) {
    return data as T;
  }

  // Access tokens last 15 minutes; refresh once and replay so the user
  // never sees an expiry.
  if (response.status === 401 && retry && refreshToken) {
    if (await refresh()) {
      return send<T>(method, path, body, false);
    }
  }

  const error = (data as { error?: ApiErrorShape })?.error;
  throw new ApiError(
    error?.code ?? 'error',
    error?.message_tk ?? 'Ýalňyşlyk.',
    error?.message_ru ?? 'Ошибка.',
    response.status,
  );
}

// In flight while a refresh is running. Two requests that 401 at once
// (common after an idle spell) must share ONE rotation: the refresh
// token is single-use, and the server now treats a replayed rotated
// token as theft and kills the whole session. Without this shared
// promise the loser would wipe the winner's freshly stored tokens — or
// worse, trip that revocation — dumping the admin to the login screen.
let refreshing: Promise<boolean> | null = null;

function refresh(): Promise<boolean> {
  return (refreshing ??= doRefresh().finally(() => {
    refreshing = null;
  }));
}

async function doRefresh(): Promise<boolean> {
  // A sibling tab may have rotated while this one was queued. Its result
  // is already in localStorage, so take that instead of spending our own
  // (now stale) token on a second rotation.
  const shared = stored(REFRESH_KEY);

  if (shared && shared !== refreshToken) {
    accessToken = stored(ACCESS_KEY);
    refreshToken = shared;
    accessExpiresAt = Number(stored(EXPIRY_KEY) ?? 0);
    return accessToken !== null;
  }

  try {
    const body = await send<{ access_token: string; refresh_token: string; expires_in?: number }>(
      'POST',
      '/auth/refresh',
      { refresh_token: refreshToken },
      false,
    );
    adopt(body.access_token, body.refresh_token, body.expires_in);
    return true;
  } catch (e) {
    // Only the server SAYING NO ends the session, and only 401 and 403
    // say that. A dropped connection (status 0), a 502 mid-redeploy, a
    // 5xx, or a 404 from a proxy whose backend is down all say nothing
    // about whether the refresh token is good — throwing it away for one
    // of those is what signed admins out "again and again". 404 is not
    // hypothetical: with the API stopped, the dev proxy answers 404, and
    // a "4xx means rejected" rule read that as a dead session. Keep the
    // token and let the caller surface the error; the next request
    // tries again.
    const definitive = e instanceof ApiError && (e.status === 401 || e.status === 403);

    if (definitive) {
      accessToken = null;
      refreshToken = null;
      persist();
    }

    return false;
  }
}

export const api = {
  get: <T>(path: string) => send<T>('GET', path),
  post: <T>(path: string, body?: unknown) => send<T>('POST', path, body),
  del: <T>(path: string) => send<T>('DELETE', path),

  isSignedIn: () => accessToken !== null,

  async login(login: string, password: string): Promise<PanelUser> {
    const body = await send<{
      access_token: string;
      refresh_token: string;
      expires_in?: number;
      user: PanelUser;
    }>('POST', '/auth/login', { login, password }, false);

    // Students and teachers use the mobile app; letting them in here
    // would show a UI with no endpoints they can call.
    if (body.user.role !== 'superadmin' && body.user.role !== 'admin') {
      throw new ApiError(
        'forbidden',
        'Bu panel diňe dolandyryjylar üçin.',
        'Эта панель только для администраторов.',
        403,
      );
    }

    adopt(body.access_token, body.refresh_token, body.expires_in);
    return body.user;
  },

  async logout(): Promise<void> {
    try {
      await send('POST', '/auth/logout', { refresh_token: refreshToken });
    } catch {
      // Signing out locally matters more than telling the server.
    }
    accessToken = null;
    refreshToken = null;
    persist();
  },

  /**
   * For the requests `send` cannot carry: multipart uploads and media
   * blobs, which are not JSON.
   *
   * They used to build `Authorization` from localStorage by hand, which
   * meant they had no renewal at all — after fifteen minutes of editing
   * a section with no other API traffic, the import answered «Сессия
   * истекла. Войдите снова.» and every uploaded audio preview rendered
   * as missing, on a session that was in fact perfectly alive. Retrying
   * failed the same way, because nothing on those paths ever refreshed.
   * Routing them through here gives them the renewal, the shared
   * single-flight rotation and the one-shot replay that `send` has
   * (FR-15.15).
   */
  async authedFetch(path: string, init: RequestInit = {}): Promise<Response> {
    if (accessToken && refreshToken && accessExpiresAt > 0 && Date.now() >= accessExpiresAt) {
      await refresh();
    }

    const fetchOnce = () =>
      fetch(`${BASE}${path}`, {
        ...init,
        headers: {
          ...(init.headers ?? {}),
          ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
        },
      });

    const response = await fetchOnce();

    if (response.status === 401 && refreshToken && (await refresh())) {
      return fetchOnce();
    }

    return response;
  },

  /** A file (CSV / xlsx), not JSON. Refreshes once on a stale token so an
   *  idle admin's export doesn't fail on an otherwise-recoverable session. */
  async download(path: string, filename: string): Promise<void> {
    const fetchOnce = () =>
      fetch(`${BASE}${path}`, {
        headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : {},
      });

    let response = await fetchOnce();

    if (response.status === 401 && refreshToken && (await refresh())) {
      response = await fetchOnce();
    }

    if (!response.ok) {
      throw new ApiError('download_failed', 'Ýüklenmedi.', 'Не удалось скачать.', response.status);
    }

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
  },
};
