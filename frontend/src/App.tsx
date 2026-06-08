import { QueryClientProvider } from '@tanstack/react-query';
import { ConfigProvider, App as AntApp } from 'antd';
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import { ErrorBoundary } from './components/ErrorBoundary';
import { ErrorFallback } from './components/ErrorFallback';
import { AppShell } from './components/AppShell';
import { QueryErrorBridge } from './components/QueryErrorBridge';
import { queryClient } from './lib/queryClient';
import { antdTheme } from './theme/antdTheme';
import { Welcome } from './pages/Welcome';
import { Callback } from './pages/Callback';
import { Forms } from './pages/Forms';

// Pages (will be created in subsequent tasks)
function Settings() {
  return <div>Settings</div>;
}

export default function App() {
  return (
    <ErrorBoundary fallback={<ErrorFallback />}>
      <QueryClientProvider client={queryClient}>
        <ConfigProvider theme={antdTheme}>
          <AntApp>
            <QueryErrorBridge />
            <HashRouter>
              <Routes>
                <Route element={<AppShell />}>
                  <Route index element={<Navigate to="/welcome" replace />} />
                  <Route path="welcome" element={<Welcome />} />
                  <Route path="forms" element={<Forms />} />
                  <Route path="settings" element={<Settings />} />
                  <Route path="callback" element={<Callback />} />
                </Route>
              </Routes>
            </HashRouter>
          </AntApp>
        </ConfigProvider>
      </QueryClientProvider>
    </ErrorBoundary>
  );
}
