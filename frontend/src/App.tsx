import { useEffect } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { ConfigProvider, App as AntApp } from 'antd';
import { StyleProvider } from '@ant-design/cssinjs';
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import { ErrorBoundary } from './components/ErrorBoundary';
import { ErrorFallback } from './components/ErrorFallback';
import { AppShell } from './components/AppShell';
import { QueryErrorBridge } from './components/QueryErrorBridge';
import { queryClient } from './lib/queryClient';
import { getThemeConfig } from './theme/antdTheme';
import { useThemeMode } from './theme/themeMode';
import { Welcome } from './pages/Welcome';
import { Callback } from './pages/Callback';
import { Forms } from './pages/Forms';
import { Settings } from './pages/Settings';
import { wpModuleData } from './utils/wpData';
import { adminBarHeight } from './lib/adminBar';

const TOAST_GAP_PX = 8;
const NOTIFICATION_GAP_PX = 16;

function RootRedirect() {
  const connected = wpModuleData.connected === '1' || wpModuleData.connected === true;
  return <Navigate to={connected ? '/forms' : '/welcome'} replace />;
}

export default function App() {
  const mode = useThemeMode((s) => s.mode);

  useEffect(() => {
    document.documentElement.dataset.theme = mode;
  }, [mode]);

  return (
    <QueryClientProvider client={queryClient}>
      {/* High hash priority lets antd's selectors outrank wp-admin's forms.css element rules. */}
      <StyleProvider hashPriority="high">
        <ConfigProvider theme={getThemeConfig(mode)}>
        <AntApp
          message={{ top: adminBarHeight() + TOAST_GAP_PX }}
          notification={{ top: adminBarHeight() + NOTIFICATION_GAP_PX }}
        >
          <ErrorBoundary fallback={(p) => <ErrorFallback {...p} />}>
            <QueryErrorBridge />
            <HashRouter>
              <Routes>
                <Route element={<AppShell />}>
                  <Route index element={<RootRedirect />} />
                  <Route
                    path="welcome"
                    element={
                      <ErrorBoundary fallback={(p) => <ErrorFallback {...p} />}>
                        <Welcome />
                      </ErrorBoundary>
                    }
                  />
                  <Route
                    path="forms"
                    element={
                      <ErrorBoundary fallback={(p) => <ErrorFallback {...p} />}>
                        <Forms />
                      </ErrorBoundary>
                    }
                  />
                  <Route
                    path="settings"
                    element={
                      <ErrorBoundary fallback={(p) => <ErrorFallback {...p} />}>
                        <Settings />
                      </ErrorBoundary>
                    }
                  />
                  <Route
                    path="callback"
                    element={
                      <ErrorBoundary fallback={(p) => <ErrorFallback {...p} />}>
                        <Callback />
                      </ErrorBoundary>
                    }
                  />
                </Route>
              </Routes>
            </HashRouter>
          </ErrorBoundary>
        </AntApp>
        </ConfigProvider>
      </StyleProvider>
    </QueryClientProvider>
  );
}
