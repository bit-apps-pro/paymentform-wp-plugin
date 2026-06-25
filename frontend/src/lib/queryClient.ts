import { QueryClient, QueryCache, MutationCache, type Query } from '@tanstack/react-query';
import type { App } from 'antd';
import type { ApiError } from './request/types';
import { handle401 } from './request/handle401';
import { __ } from './i18n';

/** antd's imperative notification API, as handed out by `App.useApp()`. */
type NotificationInstance = ReturnType<typeof App.useApp>['notification'];

let notificationInstance: NotificationInstance | null = null;

export function setNotificationInstance(instance: NotificationInstance) {
  notificationInstance = instance;
}

function getNotification(): NotificationInstance | null {
  return notificationInstance;
}

function handleDisconnected(notification: NotificationInstance | null) {
  notification?.warning({
    message: __('Plugin disconnected', 'paymentform'),
    description: __('Your plugin has been disconnected. Please reconnect from the dashboard.', 'paymentform'),
    duration: 0,
  });
  handle401();
}

function handleSessionExpired(notification: NotificationInstance | null) {
  notification?.warning({
    message: __('Session expired', 'paymentform'),
    description: __('Your session has expired. Please reconnect.', 'paymentform'),
    duration: 4,
    btn: null,
  });
  handle401();
}

const queryCache = new QueryCache({
  onError: (error: unknown, query: Query<unknown, unknown>) => {
    const meta = query.meta as { silent?: boolean } | undefined;
    if (meta?.silent) return;

    const apiError = error as ApiError;
    const notification = getNotification();

    if (apiError.errorCode === 'DISCONNECTED') {
      handleDisconnected(notification);
      return;
    }

    if (apiError.status === 401 || apiError.errorCode === 'UNAUTHORIZED') {
      handleSessionExpired(notification);
      return;
    }

    if (notification) {
      notification.error({
        message: __('Something went wrong', 'paymentform'),
        description: apiError.message || __('An unexpected error occurred', 'paymentform'),
      });
    }
  },
});

const mutationCache = new MutationCache({
  onError: (error: unknown) => {
    const apiError = error as ApiError;
    const notification = getNotification();

    if (apiError.errorCode === 'DISCONNECTED') {
      handleDisconnected(notification);
      return;
    }

    if (apiError.status === 401 || apiError.errorCode === 'UNAUTHORIZED') {
      handleSessionExpired(notification);
      return;
    }

    if (notification) {
      notification.error({
        message: __('Something went wrong', 'paymentform'),
        description: apiError.message || __('Operation failed', 'paymentform'),
      });
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
