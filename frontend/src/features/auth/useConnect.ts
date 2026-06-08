import { useMutation } from '@tanstack/react-query';
import { request } from '../../lib/request/request';

interface ConnectUrlResponse {
  url: string;
}

export function useConnect() {
  return useMutation({
    mutationFn: async () => {
      const data = await request<ConnectUrlResponse>('/connect-url', { method: 'GET' });
      return data;
    },
    onSuccess: (data) => {
      if (data.url) {
        window.location.assign(data.url);
      }
    },
  });
}
