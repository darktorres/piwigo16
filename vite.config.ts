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
                'admin_footer':       r('admin/themes/default/js/admin_footer.ts'),
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
                'configuration_comments': r('admin/themes/default/js/configuration_comments.ts'),
                'configuration_main': r('admin/themes/default/js/configuration_main.ts'),
                'languages_installed': r('admin/themes/default/js/languages_installed.ts'),
                'updates_ext':        r('admin/themes/default/js/updates_ext.ts'),
                'themes_installed':   r('admin/themes/default/js/themes_installed.ts'),
                'themes_new':         r('admin/themes/default/js/themes_new.ts'),
                'datepicker':         r('admin/themes/default/js/datepicker.ts'),
                'group_list':         r('admin/themes/default/js/group_list.ts'),
                'history':            r('admin/themes/default/js/history.ts'),
                'menubar':            r('admin/themes/default/js/menubar.ts'),
                'themes_standard_pages': r('admin/themes/default/js/themes_standard_pages.ts'),
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
                'notification_by_mail': r('admin/themes/default/js/notification_by_mail.ts'),
                'site_update':        r('admin/themes/default/js/site_update.ts'),
                'photos_add_applications_glightbox': r('admin/themes/default/js/photos_add_applications_glightbox.ts'),
                'admin_help_glightbox': r('admin/themes/default/js/admin_help_glightbox.ts'),
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
