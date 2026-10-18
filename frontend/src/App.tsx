import { lazy, Suspense, useEffect, type ReactNode } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { ConfigProvider, App as AntApp, Flex, Spin } from 'antd';
import { StyleProvider } from '@ant-design/cssinjs';
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import { ErrorBoundary } from './components/ErrorBoundary';
import { ErrorFallback } from './components/ErrorFallback';
import { AppShell } from './components/AppShell';
import { QueryErrorBridge } from './components/QueryErrorBridge';
import { queryClient } from './lib/queryClient';
import { getThemeConfig } from './theme/antdTheme';
import { useThemeMode } from './theme/themeMode';
import { wpModuleData } from './utils/wpData';
import { adminBarHeight } from './lib/adminBar';

// Pages load on demand so heavy views (e.g. the Forms table) stay out of the initial bundle.
const Welcome = lazy(() => import('./pages/Welcome').then((m) => ({ default: m.Welcome })));
const Callback = lazy(() => import('./pages/Callback').then((m) => ({ default: m.Callback })));
const Forms = lazy(() => import('./pages/Forms').then((m) => ({ default: m.Forms })));
const FormPreview = lazy(() => import('./pages/FormPreview').then((m) => ({ default: m.FormPreview })));
const Settings = lazy(() => import('./pages/Settings').then((m) => ({ default: m.Settings })));

const TOAST_GAP_PX = 8;
const NOTIFICATION_GAP_PX = 16;

/** Isolates a routed page's errors and shows a spinner while its chunk loads. */
function PageBoundary({ children }: { children: ReactNode }) {
  return (
    <ErrorBoundary fallback={(p) => <ErrorFallback {...p} />}>
      <Suspense
        fallback={
          <Flex justify="center" style={{ paddingBlock: 48 }}>
            <Spin />
          </Flex>
        }
      >
        {children}
      </Suspense>
    </ErrorBoundary>
  );
}

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
                  <Route path="welcome" element={<PageBoundary><Welcome /></PageBoundary>} />
                  <Route path="forms" element={<PageBoundary><Forms /></PageBoundary>} />
                  <Route path="forms/:formId/preview" element={<PageBoundary><FormPreview /></PageBoundary>} />
                  <Route path="settings" element={<PageBoundary><Settings /></PageBoundary>} />
                  <Route path="callback" element={<PageBoundary><Callback /></PageBoundary>} />
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
