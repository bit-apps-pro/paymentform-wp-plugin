import './admin.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';

const root = document.getElementById('paymentform-admin-root');
if (!root) {
  throw new Error('paymentform: admin root DOM element not found (#paymentform-admin-root)');
}

createRoot(root).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
