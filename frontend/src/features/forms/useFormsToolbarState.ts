import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

const STATUS_FILTERS = ['all', 'enabled', 'disabled'] as const;

export type StatusFilter = (typeof STATUS_FILTERS)[number];

export interface FormsToolbarValue {
  q: string;
  status: StatusFilter;
}

const URL_DEBOUNCE_MS = 300;

/** Narrows a `?status=` value to a known filter so stale links fall back to all. */
function parseStatus(raw: string | null): StatusFilter {
  return STATUS_FILTERS.find((status) => status === raw) ?? 'all';
}

export function useFormsToolbarState(): {
  value: FormsToolbarValue;
  setValue: (next: FormsToolbarValue) => void;
} {
  const [params, setParams] = useSearchParams();
  const initial: FormsToolbarValue = {
    q: params.get('q') ?? '',
    status: parseStatus(params.get('status')),
  };
  const [value, setLocal] = useState<FormsToolbarValue>(initial);
  const timer = useRef<number | undefined>(undefined);

  const setValue = (next: FormsToolbarValue) => {
    setLocal(next);
    if (timer.current) window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => {
      const sp = new URLSearchParams();
      if (next.q) sp.set('q', next.q);
      if (next.status !== 'all') sp.set('status', next.status);
      setParams(sp, { replace: true });
    }, URL_DEBOUNCE_MS);
  };

  useEffect(() => () => {
    if (timer.current) window.clearTimeout(timer.current);
  }, []);

  return { value, setValue };
}
