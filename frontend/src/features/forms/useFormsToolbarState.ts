import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

export type StatusFilter = 'all' | 'enabled' | 'disabled' | 'draft';

export interface FormsToolbarValue {
  q: string;
  status: StatusFilter;
}

const URL_DEBOUNCE_MS = 300;

export function useFormsToolbarState(): {
  value: FormsToolbarValue;
  setValue: (next: FormsToolbarValue) => void;
} {
  const [params, setParams] = useSearchParams();
  const initial: FormsToolbarValue = {
    q: params.get('q') ?? '',
    status: (params.get('status') as StatusFilter) ?? 'all',
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
