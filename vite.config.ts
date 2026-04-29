import { defineConfig } from 'vite';
import { resolve } from 'path';
import { fileURLToPath } from 'url';
import { piwigoManifestPlugin } from './build/piwigo-manifest-plugin';

const __dirname = fileURLToPath(new URL('.', import.meta.url));
const r = (p: string) => resolve(__dirname, p);

export default defineConfig({
    root: '.',
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: {
                // ── Frontend (themes/default) ─────────────────────────────
                'core.scripts':       r('themes/default/js/scripts.ts'),
                'switchbox':          r('themes/default/js/switchbox.ts'),
                'pngfix':             r('themes/default/js/pngfix.ts'),
                'rating':             r('themes/default/js/rating.ts'),
                'thumbnails.loader':  r('themes/default/js/thumbnails.loader.ts'),

                // ── Standard pages ────────────────────────────────────────
                'toaster':            r('themes/standard_pages/js/toaster.ts'),
                'standard_pages':     r('themes/standard_pages/js/standard_pages.ts'),
                'profile':            r('themes/standard_pages/js/profile.ts'),

                // ── Admin (admin/themes/default) ──────────────────────────
                'common':             r('admin/themes/default/js/common.ts'),
                'LocalStorageCache':  r('admin/themes/default/js/LocalStorageCache.ts'),
                'album_selector':     r('admin/themes/default/js/album_selector.ts'),
                'addAlbum':           r('admin/themes/default/js/addAlbum.ts'),
                'albums':             r('admin/themes/default/js/albums.ts'),
                'batchManagerGlobal': r('admin/themes/default/js/batchManagerGlobal.ts'),
                'batchManagerUnit':   r('admin/themes/default/js/batchManagerUnit.ts'),
                'batchManagerFilter': r('admin/themes/default/js/batchManagerFilter.ts'),
                'cat_list':           r('admin/themes/default/js/cat_list.ts'),
                'cat_modify':         r('admin/themes/default/js/cat_modify.ts'),
                'cat_search':         r('admin/themes/default/js/cat_search.ts'),
                'comments':           r('admin/themes/default/js/comments.ts'),
                'datepicker':         r('admin/themes/default/js/datepicker.ts'),
                'doubleSlider':       r('admin/themes/default/js/doubleSlider.ts'),
                'group_list':         r('admin/themes/default/js/group_list.ts'),
                'history':            r('admin/themes/default/js/history.ts'),
                'intro_tooltips':     r('admin/themes/default/js/intro_tooltips.ts'),
                'jquery.geoip':       r('admin/themes/default/js/jquery.geoip.ts'),
                'maintenance':        r('admin/themes/default/js/maintenance.ts'),
                'maintenance_env':    r('admin/themes/default/js/maintenance_env.ts'),
                'maintenance_sys':    r('admin/themes/default/js/maintenance_sys.ts'),
                'photos_add_direct':  r('admin/themes/default/js/photos_add_direct.ts'),
                'picture_formats':    r('admin/themes/default/js/picture_formats.ts'),
                'picture_modify':     r('admin/themes/default/js/picture_modify.ts'),
                'plugins_installated': r('admin/themes/default/js/plugins_installated.ts'),
                'plugins_new':        r('admin/themes/default/js/plugins_new.ts'),
                'stats':              r('admin/themes/default/js/stats.ts'),
                'tags':               r('admin/themes/default/js/tags.ts'),
                'user_activity':      r('admin/themes/default/js/user_activity.ts'),
                'user_list':          r('admin/themes/default/js/user_list.ts'),
            },
            output: {
                entryFileNames: 'assets/[name]-[hash].js',
                chunkFileNames: 'assets/chunks/[name]-[hash].js',
            },
        },
    },
    plugins: [
        piwigoManifestPlugin(),
        // Wrap every output chunk (including Rollup-generated helpers) in an IIFE.
        // Rollup places helpers (var G=..., var i=...) before user code; the banner/
        // footer approach only wraps user code. This plugin wraps the full chunk so
        // helpers are function-scoped and cannot conflict across combined bundles.
        {
            name: 'iife-wrap',
            generateBundle(_opts, bundle) {
                for (const chunk of Object.values(bundle)) {
                    if (chunk.type === 'chunk') {
                        chunk.code = `(function(){\n${chunk.code}})();\n`;
                    }
                }
            },
        },
    ],
    server: {
        port: 5173,
        strictPort: true,
        cors: true,
    },
});
