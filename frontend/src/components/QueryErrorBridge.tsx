import { useEffect } from 'react';
import { App } from 'antd';
import { setNotificationInstance } from '../lib/queryClient';

export function QueryErrorBridge() {
  const { notification } = App.useApp();

  useEffect(() => {
    setNotificationInstance(notification);
  }, [notification]);

  return null;
}
