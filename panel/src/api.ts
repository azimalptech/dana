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

// In dev, Vite proxies /api to the PHP server. In production the panel is
// served by the same Apache, so a relative path is correct either way.
const BASE = '/api/v1';

let accessToken: string | null = localStorage.getItem('panel_access') ?? null;
let refreshToken: string | null = localStorage.getItem('panel_refresh') ?? null;

function persist(): void {
  if (accessToken) {
    localStorage.setItem('panel_access', accessToken);
    localStorage.setItem('panel_refresh', refreshToken ?? '');
  } else {
    localStorage.removeItem('panel_access');
    localStorage.removeItem('panel_refresh');
  }
}

async function send<T>(
  method: 'GET' | 'POST' | 'DELETE',
  path: string,
  body?: unknown,
  retry = true,
): Promise<T> {
  let response: Response;

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
  try {
    const body = await send<{ access_token: string; refresh_token: string }>(
      'POST',
      '/auth/refresh',
      { refresh_token: refreshToken },
      false,
    );
    accessToken = body.access_token;
    refreshToken = body.refresh_token;
    persist();
    return true;
  } catch {
    accessToken = null;
    refreshToken = null;
    persist();
    return false;
  }
}

export const api = {
  get: <T>(path: string) => send<T>('GET', path),
  post: <T>(path: string, body?: unknown) => send<T>('POST', path, body),
  del: <T>(path: string) => send<T>('DELETE', path),

  isSignedIn: () => accessToken !== null,

  async login(login: string, password: string): Promise<PanelUser> {
    const body = await send<{ access_token: string; refresh_token: string; user: PanelUser }>(
      'POST',
      '/auth/login',
      { login, password },
      false,
    );

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

    accessToken = body.access_token;
    refreshToken = body.refresh_token;
    persist();
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
