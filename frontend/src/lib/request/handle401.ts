import { queryClient } from '../queryClient';

export function handle401() {
  // Mark connection query as stale so it refreshes
  queryClient.invalidateQueries({ queryKey: ['connection'] });

  // Redirect to welcome page
  window.location.hash = '#/welcome';
}
