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

interface FormsListResponse {
  data: Form[];
}

export function useFormsList() {
  return useQuery({
    queryKey: ['forms'],
    queryFn: async () => {
      const response = await request<FormsListResponse>('/forms', { method: 'GET' });
      return response.data || [];
    },
  });
}
