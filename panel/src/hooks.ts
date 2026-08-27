import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError } from './api';

interface AsyncState<T> {
  data: T | null;
  error: string | null;
  /** First load only — true while there is nothing to show yet. */
  loading: boolean;
  /** A reload is in flight while the previous data stays on screen. */
  refreshing: boolean;
  reload: () => void;
}

/**
 * Load once, expose loading/error, and allow an explicit reload.
 *
 * Stale-while-revalidate: reload() keeps the previous data — and with it
 * the rendered list and the browser's scroll position — visible while
 * the new response is in flight. `loading` is true only when there is no
 * data at all, so pages that gate on it never unmount their lists during
 * a background refresh. A deps change is a different query: it clears
 * the data and starts over as a first load.
 */
export function useAsync<T>(load: () => Promise<T>, deps: unknown[] = []): AsyncState<T> {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [nonce, setNonce] = useState(0);

  // eslint-disable-next-line react-hooks/exhaustive-deps
  const run = useCallback(load, deps);

  const lastRun = useRef<typeof run | null>(null);
  const hasData = useRef(false);

  useEffect(() => {
    let cancelled = false;
    const sameQuery = lastRun.current === run;
    lastRun.current = run;

    if (sameQuery && hasData.current) {
      setRefreshing(true);
    } else {
      // New query (deps changed) or nothing loaded yet — a real load.
      hasData.current = false;
      setData(null);
      setLoading(true);
    }
    setError(null);

    run()
      .then((result) => {
        if (!cancelled) {
          hasData.current = true;
          setData(result);
        }
      })
      .catch((e: unknown) => {
        if (!cancelled) {
          setError(e instanceof ApiError ? e.message : 'Ошибка загрузки.');
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
          setRefreshing(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [run, nonce]);

  return { data, error, loading, refreshing, reload: () => setNonce((n) => n + 1) };
}
