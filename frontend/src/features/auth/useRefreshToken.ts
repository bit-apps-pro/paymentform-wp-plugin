import { useMutation, useQueryClient } from '@tanstack/react-query';
import { request } from '../../lib/request/request';

interface RefreshTokenResponse {
  success: boolean;
  expiresAt?: number;
}

export function useRefreshToken() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      const response = await request<RefreshTokenResponse>('/refresh', { method: 'POST' });
      return response;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['connection'] });
    },
  });
}
