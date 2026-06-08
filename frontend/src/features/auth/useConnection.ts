import { useQuery } from '@tanstack/react-query';
import { request } from '../../lib/request/request';

export interface ConnectionStatus {
  connected: boolean;
  user?: {
    id: string;
    email: string;
    name: string;
  };
  tenant?: {
    id: string;
    name: string;
  };
  expiresAt?: number;
  expiresIn?: number;
}

export function useConnection() {
  return useQuery({
    queryKey: ['connection'],
    queryFn: async () => {
      const response = await request<ConnectionStatus>('/status', { method: 'GET' });
      return response;
    },
  });
}
