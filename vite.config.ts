import { defineConfig } from 'vite';
import { resolve } from 'path';
import { fileURLToPath } from 'url';
import { piwigoManifestPlugin } from './build/piwigo-manifest-plugin';

const __dirname = fileURLToPath(new URL('.', import.meta.url));
const r = (p: string) => resolve(__dirname, p);

export default defineConfig({
    root: '.',
    // Resolve dynamic chunk URLs via import.meta.url so they work under any
    // Apache document root prefix (Piwigo can be served at /, /piwigo16/, etc.).
    base: './',
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: {
                // ── Frontend (themes/default) ─────────────────────────────
                'core.scripts':       r('themes/default/js/scripts.ts'),
                'core.switchbox':     r('themes/default/js/switchbox.ts'),
                'mcs':                r('themes/default/js/mcs.ts'),
                'pngfix':             r('themes/default/js/pngfix.ts'),
                'rating':             r('themes/default/js/rating.ts'),
                'thumbnails.loader':  r('themes/default/js/thumbnails.loader.ts'),
                // ── Standard pages ────────────────────────────────────────
                'toaster_js':         r('themes/standard_pages/js/toaster.ts'),
                'standard_pages_js':  r('themes/standard_pages/js/standard_pages.ts'),
                'standard_profile_js': r('themes/standard_pages/js/profile.ts'),

                // ── Admin (admin/themes/default) ──────────────────────────
                'common':             r('admin/themes/default/js/common.ts'),
                'addAlbum':           r('admin/themes/default/js/addAlbum.ts'),
                'albums':             r('admin/themes/default/js/albums.ts'),
                'batchManagerGlobal': r('admin/themes/default/js/batchManagerGlobal.ts'),
                'batchManagerUnit':   r('admin/themes/default/js/batchManagerUnit.ts'),
                'batchManagerFilter': r('admin/themes/default/js/batchManagerFilter.ts'),
                'cat_list':           r('admin/themes/default/js/cat_list.ts'),
                'cat_modify':         r('admin/themes/default/js/cat_modify.ts'),
                'cat_perm':           r('admin/themes/default/js/cat_perm.ts'),
                'cat_search':         r('admin/themes/default/js/cat_search.ts'),
                'comments':           r('admin/themes/default/js/comments.ts'),
                'datepicker':         r('admin/themes/default/js/datepicker.ts'),
                'group_list':         r('admin/themes/default/js/group_list.ts'),
                'history':            r('admin/themes/default/js/history.ts'),
                'menubar':            r('admin/themes/default/js/menubar.ts'),
                'intro_tooltips':     r('admin/themes/default/js/intro_tooltips.ts'),
                'glightbox-admin':    r('admin/themes/default/js/glightbox-init.ts'),
                'geoip':              r('admin/themes/default/js/geoip.ts'),
                'rating_user':        r('admin/themes/default/js/rating_user.ts'),
                'ajax':               r('admin/themes/default/js/maintenance.ts'),
                'activated_plugin_list': r('admin/themes/default/js/maintenance_env.ts'),
                'sys':                r('admin/themes/default/js/maintenance_sys.ts'),
                'add_photo':          r('admin/themes/default/js/photos_add_direct.ts'),
                'picture_coi':        r('admin/themes/default/js/picture_coi.ts'),
                'picture_formats':    r('admin/themes/default/js/picture_formats.ts'),
                'picture_modify':     r('admin/themes/default/js/picture_modify.ts'),
                'pluginInstallated':  r('admin/themes/default/js/plugins_installated.ts'),
                'pluginsNew':         r('admin/themes/default/js/plugins_new.ts'),
                'rating_admin':       r('admin/themes/default/js/rating.ts'),
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
    ],
    server: {
        port: 5173,
        strictPort: true,
        cors: true,
    },
});
