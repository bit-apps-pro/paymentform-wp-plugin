import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import detectPort from 'detect-port';
import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import type { AddressInfo } from 'node:net';
import { resolve } from 'node:path';
import { createHash } from 'node:crypto';
import type { Plugin } from 'vite';

/**
 * Externalize every `@wordpress/*` import to the editor's `wp.*` globals so
 * bundles don't ship duplicate copies of `@wordpress/blocks`,
 * `@wordpress/element`, etc. — and so the block lands in the editor's own
 * block registry (a bundled copy would register against a different registry
 * and silently never appear in the inserter).
 *
 * Also emits `<entry>.asset.php` next to every JS chunk so PHP can enqueue
 * the right `wp-blocks`/`wp-components`/... script handles via
 * `wp_enqueue_script('handle', url, $asset['dependencies'], $asset['version'])`.
 */
function wpExternals(): Plugin {
  // @wordpress/<package> → wp.<camelCase> + matching WP script handle.
  // react + react/jsx-runtime also map to wp.element so the bundle uses the
  // editor's React copy (a duplicate React would break hooks across the
  // block boundary).
  const mapping: Record<string, { global: string; handle: string }> = {
    '@wordpress/blocks': { global: 'wp.blocks', handle: 'wp-blocks' },
    '@wordpress/block-editor': { global: 'wp.blockEditor', handle: 'wp-block-editor' },
    '@wordpress/components': { global: 'wp.components', handle: 'wp-components' },
    '@wordpress/element': { global: 'wp.element', handle: 'wp-element' },
    '@wordpress/api-fetch': { global: 'wp.apiFetch', handle: 'wp-api-fetch' },
    '@wordpress/i18n': { global: 'wp.i18n', handle: 'wp-i18n' },
    '@wordpress/date': { global: 'wp.date', handle: 'wp-date' },
    // `react` / `react-dom` map to the WP-bundled React globals (window.React /
    // window.ReactDOM) which expose the full React API surface including
    // `version`, `useId`, etc. that wp.element doesn't re-export. Handle remains
    // `wp-element` because that's the script that boots `window.React`.
    react: { global: 'window.React', handle: 'wp-element' },
    'react-dom': { global: 'window.ReactDOM', handle: 'wp-element' },
    'react/jsx-runtime': { global: 'window.React', handle: 'wp-element' },
    'react/jsx-dev-runtime': { global: 'window.React', handle: 'wp-element' },
  };

  const handlesByChunk = new Map<string, Set<string>>();

  /**
   * Explicit allowlist of WP block entry directories (anchored under `/blocks/<name>/`).
   * Tightened from a plain substring match so a future `src/blocks/` util directory
   * doesn't false-positive into the block-bundle externalization path.
   */
  const BLOCK_DIR_PATTERN = /\/blocks\/(form-embed)\//;
  const inBlocksDir = (p: string | null | undefined): boolean => {
    if (!p) return false;
    return BLOCK_DIR_PATTERN.test(p.replace(/\\/g, '/'));
  };

  return {
    name: 'wp-externals',
    enforce: 'pre',
    // Build-only: in dev serve the SPA must resolve `@wordpress/*` from
    // node_modules (there is no editor page providing `wp.*` globals, and
    // externalized ids would 404 against the dev server).
    apply: 'build',
    resolveId(id, importer) {
      if (!(id in mapping)) return null;
      // `@wordpress/*` always externalize (same handle scheme everywhere).
      // `react` / `react-dom` only externalize for block bundles — admin SPA
      // bundles its own React 18 copy.
      const isReactish = id === 'react' || id === 'react-dom'
        || id.startsWith('react/') || id.startsWith('react-dom/');
      if (isReactish && importer && !inBlocksDir(importer)) {
        return null;
      }
      return { id, external: true };
    },
    renderChunk(code, chunk) {
      const used = new Set<string>();
      let out = code;

      // `import { a, b as c } from "@wordpress/x"` → `const { a, b: c } = wp.x;`
      out = out.replace(
        /import\s*\{\s*([^}]+?)\s*\}\s*from\s*['"]([^'"]+)['"]\s*;?/g,
        (match: string, names: string, mod: string) => {
          const m = mapping[mod];
          if (!m) return match;
          used.add(m.handle);
          const destructured = names
            .split(',')
            .map((n) => n.trim().replace(/\s+as\s+/, ': '))
            .join(', ');
          return `const { ${destructured} } = ${m.global};`;
        },
      );

      // `import Default from "@wordpress/x"` → `const Default = wp.x.default || wp.x;`
      out = out.replace(
        /import\s+([\w$]+)\s+from\s*['"]([^'"]+)['"]\s*;?/g,
        (match, name: string, mod: string) => {
          const m = mapping[mod];
          if (!m) return match;
          used.add(m.handle);
          return `const ${name} = ${m.global}.default || ${m.global};`;
        },
      );

      // `import * as ns from "@wordpress/x"` → `const ns = wp.x;`
      out = out.replace(
        /import\s+\*\s+as\s+([\w$]+)\s+from\s*['"]([^'"]+)['"]\s*;?/g,
        (match: string, name: string, mod: string) => {
          const m = mapping[mod];
          if (!m) return match;
          used.add(m.handle);
          return `const ${name} = ${m.global};`;
        },
      );

      // `import Default, { a, b as c } from "X"` →
      //  `const Default = wp.x.default || wp.x; const { a, b: c } = wp.x;`
      out = out.replace(
        /import\s+([\w$]+)\s*,\s*\{\s*([^}]+?)\s*\}\s*from\s*['"]([^'"]+)['"]\s*;?/g,
        (match: string, name: string, named: string, mod: string) => {
          const m = mapping[mod];
          if (!m) return match;
          used.add(m.handle);
          const destructured = named
            .split(',')
            .map((n) => n.trim().replace(/\s+as\s+/, ': '))
            .join(', ');
          return `const ${name} = ${m.global}.default || ${m.global}; const { ${destructured} } = ${m.global};`;
        },
      );

      // Rolldown emits `__require("react")` runtime calls for CJS-style requires
      // inside `react-is` etc.; rewrite to the WP-bundled globals so the bundle
      // doesn't hit the runtime `require is not defined` error.
      for (const [mod, m] of Object.entries(mapping)) {
        const re = new RegExp(`__require\\(['"]${mod.replace(/\//g, '\\/')}['"]\\)`, 'g');
        if (re.test(out)) used.add(m.handle);
        out = out.replace(re, m.global);
      }

      // Classic JSX runtime emits `React.createElement(...)` calls; for blocks
      // bind to the editor's React global so the block bundle and the editor
      // share one React instance (hooks work across the block boundary). Use
      // `chunk.moduleIds` (not `facadeModuleId`) so non-facade shared chunks
      // emitted from a block entry still match.
      const isBlockChunk = inBlocksDir(chunk.facadeModuleId)
        || chunk.moduleIds.some((id: string) => inBlocksDir(id));
      if (isBlockChunk && /\bReact\.createElement\b/.test(out)) {
        out = `const React = window.React;\n${out}`;
        used.add('wp-element');
      }

      handlesByChunk.set(chunk.fileName, used);
      return { code: out, map: null };
    },
    generateBundle(_, bundle) {
      for (const [fileName, output] of Object.entries(bundle)) {
        if (output.type !== 'chunk') continue;
        // Only emit asset.php beside ENTRY chunks (admin / block-editor / block-view).
        // Shared chunks (e.g. emotion-css splits) have no PHP consumer.
        if (!output.isEntry) continue;

        const deps = Array.from(handlesByChunk.get(fileName) ?? []);
        // Handle names are validated against `[a-z0-9-]+` defensively to keep the
        // emitted PHP literal safe even if a future mapping introduces a special char.
        const safeDeps = deps.filter((d) => /^[a-z0-9-]+$/.test(d));
        const version = createHash('sha1').update(output.code).digest('hex').slice(0, 12);
        const phpName = fileName.replace(/\.js$/, '.asset.php');
        const depsLiteral = `array(${safeDeps.map((d) => `'${d}'`).join(', ')})`;
        const php = `<?php return array('dependencies' => ${depsLiteral}, 'version' => '${version}');\n`;
        this.emitFile({ type: 'asset', fileName: phpName, source: php });
      }
    },
  };
}

/**
 * Copy `blocks/form-embed/block.json` into the build output at
 * `form-embed/block.json`, rewriting its `viewScriptModule` `file:` path to match
 * the flat `assets/` layout (block.json sits in `assets/form-embed/`, the JS sits
 * in `assets/`, so `../block-view.js` is the correct relative path). The editor
 * entry is not declared here — PHP enqueues `block-editor.js` as a Script Module
 * directly (WP has no `editorScriptModule` block.json field). Every other field is
 * preserved verbatim from the source so future block.json edits propagate.
 */
function copyBlockJson(): Plugin {
  return {
    name: 'copy-block-json',
    generateBundle() {
      const source = readFileSync(
        resolve(__dirname, 'frontend/blocks/form-embed/block.json'),
        'utf8',
      );
      const block = JSON.parse(source) as Record<string, unknown>;
      block.viewScriptModule = 'file:../block-view.js';
      this.emitFile({
        type: 'asset',
        fileName: 'form-embed/block.json',
        source: `${JSON.stringify(block, null, 2)}\n`,
      });
    },
  };
}

/**
 * Coordinate the dev-server port with PHP through a `frontend/port` file:
 * the plugin picks a free port (sticky across restarts via the stored file),
 * writes it on boot and removes it on shutdown. PHP treats the file's
 * presence as "dev mode" (Config::isDev()) and loads the admin SPA from
 * `http://localhost:<port>` with HMR instead of the built `assets/` bundle.
 *
 * Adapted from the file-manager plugin's port-file pattern.
 */
function setDevServerConfig(): Plugin {
  return {
    name: 'set-dev-server-config',
    async config(_, env) {
      if (env.mode !== 'development') {
        removeStoredPort();
        return;
      }
      registerPortFileCleanup();
      let port = getStoredPort();
      if (!port) {
        port = await detectPort(5173);
        updateStoredPort(port);
      }
      return { server: { port, origin: `http://localhost:${port}` } };
    },
    configureServer(server) {
      const { httpServer } = server;
      if (!httpServer) return;

      httpServer.once('listening', () => {
        // Vite bumps to the next free port when the requested one is taken;
        // persist the port it actually bound so PHP targets the right origin.
        const { port } = httpServer.address() as AddressInfo;
        if (port !== getStoredPort()) {
          updateStoredPort(port);
        }
      });

      // An out-of-band port-file change means `server.origin` no longer
      // matches reality — restart so the config hook re-reads the file.
      server.watcher.add(portFile);
      server.watcher.on('change', (file) => {
        if (resolve(file) === portFile) {
          server.config.logger.warnOnce('Port file changed; restarting dev server for origin mismatch', {
            timestamp: true,
          });
          void server.restart();
        }
      });

      httpServer.once('close', () => {
        removeStoredPort();
        void server.watcher.close();
      });
    },
  };
}

const portFile = resolve(__dirname, 'frontend/port');

let portFileCleanupRegistered = false;

/**
 * Vite only closes the http server gracefully on SIGTERM / stdin end; Ctrl+C
 * (SIGINT) terminates through Node's default handler without ever firing the
 * httpServer 'close' hook, which would leave a stale port file behind — and
 * PHP would keep pointing wp-admin at a dead dev server. Hook the process
 * directly so every exit path drops the file.
 */
function registerPortFileCleanup(): void {
  if (portFileCleanupRegistered) return;
  portFileCleanupRegistered = true;
  process.once('exit', removeStoredPort);
  process.once('SIGINT', () => {
    removeStoredPort();
    process.exit(130);
  });
}

function getStoredPort(): number {
  return existsSync(portFile) ? Number(readFileSync(portFile, 'utf8')) : 0;
}

function updateStoredPort(port: number): void {
  writeFileSync(portFile, String(port));
}

function removeStoredPort(): void {
  rmSync(portFile, { force: true });
}

export default defineConfig({
  // Classic JSX runtime so block bundles emit `createElement(...)` calls that
  // map cleanly to `wp.element.createElement` (the editor's React 18 instance).
  // The automatic runtime would emit `jsx`/`jsxs` calls which `wp.element` does
  // not expose. Admin SPA also gets classic — same emotion-css JSX behavior.
  plugins: [react({ jsxRuntime: 'classic' }), wpExternals(), copyBlockJson(), setDevServerConfig()],
  // The build config lives at the plugin root but the web app source stays under
  // `frontend/`, so point Vite's project root there. The dev server then serves
  // `/src/main.tsx`, `/dev-react-refresh.js` etc. from `frontend/` (PHP targets
  // those exact paths), `.env` loads from `frontend/`, and `outDir: '../assets'`
  // still resolves to the repo-root `assets/` dir.
  root: resolve(__dirname, 'frontend'),
  // Expose `APP_*` vars from `frontend/.env` to the client as dev-only
  // overrides (see src/lib/devEnv.ts); production reads window.paymentformAdmin.
  envPrefix: 'APP_',
  // Single copy of react / react-dom / react-is across the bundle. Admin SPA is on
  // React 18 (matches WP-bundled React + emotion ecosystem). `dedupe` alone is
  // enough — no pnpm-path alias (those slugs are brittle across pnpm versions
  // and break the build when the lockfile rewrites).
  resolve: {
    dedupe: ['react', 'react-dom', 'react-is'],
  },
  build: {
    target: 'ES2022',
    // Built assets land in the repo-root `assets/` dir (sibling of `frontend/`),
    // the only tree shipped to WordPress. `emptyOutDir` still wipes it on each
    // build — correct, since `assets/` is generated-only.
    outDir: '../assets',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        admin: resolve(__dirname, 'frontend/src/main.tsx'),
        'block-editor': resolve(__dirname, 'frontend/blocks/form-embed/index.ts'),
        'block-view': resolve(__dirname, 'frontend/blocks/form-embed/view.ts'),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: '[name]-[hash].js',
        assetFileNames: '[name][extname]',
        format: 'es',
      },
    },
    minify: false,
    sourcemap: false,
  },
  server: {
    // Port is managed by setDevServerConfig() (free-port detection + port
    // file). CORS + localhost HMR because the dev server is consumed from
    // inside wp-admin (different origin), not opened directly.
    cors: true,
    hmr: { host: 'localhost' },
  },
});
