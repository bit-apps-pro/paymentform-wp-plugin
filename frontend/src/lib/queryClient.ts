import { QueryClient, QueryCache, MutationCache } from '@tanstack/react-query';
import type { ApiError } from './request/types';
import { handle401 } from './request/handle401';
import { __ } from './i18n';

let notificationInstance: any = null;

export function setNotificationInstance(instance: any) {
  notificationInstance = instance;
}

function getNotification() {
  return notificationInstance;
}

const queryCache = new QueryCache({
  onError: (error: unknown, query: any) => {
    const meta = query.meta as { silent?: boolean } | undefined;
    if (meta?.silent) return;

    const apiError = error as ApiError;
    if (apiError.status === 401 || apiError.errorCode === 'UNAUTHORIZED') {
      const notification = getNotification();
      if (notification) {
        notification.warning({
          message: __('Session expired', 'paymentform'),
          description: __('Your session has expired. Please reconnect.', 'paymentform'),
          duration: 4,
          btn: null,
        });
      }
      handle401();
      return;
    }

    const notification = getNotification();
    if (notification) {
      notification.error({
        message: apiError.errorCode || __('Error', 'paymentform'),
        description: apiError.message || __('An unexpected error occurred', 'paymentform'),
      });
    }
  },
});

const mutationCache = new MutationCache({
  onError: (error: unknown) => {
    const apiError = error as ApiError;
    if (apiError.status === 401) {
      const notification = getNotification();
      if (notification) {
        notification.warning({
          message: __('Session expired', 'paymentform'),
          description: __('Your session has expired. Please reconnect.', 'paymentform'),
          duration: 4,
        });
      }
      handle401();
      return;
    }

    const notification = getNotification();
    if (notification) {
      notification.error(apiError.message || __('Operation failed', 'paymentform'));
    }
  },
});

export const queryClient = new QueryClient({
  queryCache,
  mutationCache,
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5, // 5 minutes
      gcTime: 1000 * 60 * 10, // 10 minutes (formerly cacheTime)
      retry: 1,
    },
  },
});
