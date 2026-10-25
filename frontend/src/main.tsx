import './admin.css';

// WP enqueues this entry as `admin.js?ver=…`; keeping it a thin loader means lazy
// chunks import shared code from hashed chunks, never a second (query-less) admin.js instance.
void import('./bootstrap');
