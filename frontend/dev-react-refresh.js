// React Fast Refresh preamble, served by the Vite dev server at
// `<devServerUrl>/dev-react-refresh.js` and loaded as a module before the SPA
// entry (see PluginServiceProvider::enqueueDevAdminAssets). The `/@react-refresh`
// import is resolved against the dev origin this module is served from. This
// file is dev-only and intentionally excluded from the production bundle.
import RefreshRuntime from "/@react-refresh";

RefreshRuntime.injectIntoGlobalHook(window);
window.$RefreshReg$ = () => {};
window.$RefreshSig$ = () => (type) => type;
window.__vite_plugin_react_preamble_installed__ = true;
