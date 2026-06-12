import { useQuery } from '@tanstack/react-query';
import { request } from '../../lib/request/request';

export interface Form {
  id: string;
  name: string;
  slug: string | null;
  status: string;
  formType?: number;
  submissionsCount?: number;
  createdAt: string;
  updatedAt: string;
}

export function useFormsList() {
  return useQuery({
    queryKey: ['forms'],
    queryFn: async () => {
      // The controller returns the bare forms array; request() strips the
      // wp-kit envelope. Coerce any non-array payload (e.g. a degenerate
      // backend body flattened to an object) to [] so the table never
      // receives a non-array — `?? []` only guards null/undefined.
      const forms = await request<Form[]>('/forms', { method: 'GET' });
      return Array.isArray(forms) ? forms : [];
    },
  });
}
