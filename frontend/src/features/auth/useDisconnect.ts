import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { request } from '../../lib/request/request';

interface DisconnectResponse {
  status: boolean;
}

export function useDisconnect() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();

  return useMutation({
    mutationFn: async () => {
      const response = await request<DisconnectResponse>('/disconnect', { method: 'POST' });
      return response;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['connection'] });
      navigate('/welcome');
    },
  });
}
