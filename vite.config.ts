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
                // ── Frontend (themes/_base) ─────────────────────────────
                'core.scripts': r('themes/_base/js/scripts.ts'),
                'core.switchbox': r('themes/_base/js/switchbox.ts'),
                search: r('themes/_base/js/search.ts'),
                popuphelp: r('themes/_base/js/popuphelp.ts'),
                picture_nav_keys: r('themes/_base/js/picture_nav_keys.ts'),
                mcs: r('themes/_base/js/mcs.ts'),
                pngfix: r('themes/_base/js/pngfix.ts'),
                rating: r('themes/_base/js/rating.ts'),
                'thumbnails.loader': r('themes/_base/js/thumbnails.loader.ts'),
                // ── Standard pages ────────────────────────────────────────
                toaster_js: r('themes/standard_pages/js/toaster.ts'),
                standard_pages_js: r('themes/standard_pages/js/standard_pages.ts'),
                standard_profile_js: r('themes/standard_pages/js/profile.ts'),

                // ── Admin (themes/admin/_base) ──────────────────────────
                common: r('themes/admin/_base/js/common.ts'),
                admin_footer: r('themes/admin/_base/js/admin_footer.ts'),
                admin: r('themes/admin/_base/js/admin.ts'),
                addAlbum: r('themes/admin/_base/js/addAlbum.ts'),
                albums: r('themes/admin/_base/js/albums.ts'),
                batchManagerGlobal: r('themes/admin/_base/js/batchManagerGlobal.ts'),
                batchManagerUnit: r('themes/admin/_base/js/batchManagerUnit.ts'),
                batchManagerFilter: r('themes/admin/_base/js/batchManagerFilter.ts'),
                cat_list: r('themes/admin/_base/js/cat_list.ts'),
                cat_modify: r('themes/admin/_base/js/cat_modify.ts'),
                cat_perm: r('themes/admin/_base/js/cat_perm.ts'),
                cat_search: r('themes/admin/_base/js/cat_search.ts'),
                comments: r('themes/admin/_base/js/comments.ts'),
                configuration_comments: r('themes/admin/_base/js/configuration_comments.ts'),
                configuration_main: r('themes/admin/_base/js/configuration_main.ts'),
                configuration_watermark: r('themes/admin/_base/js/configuration_watermark.ts'),
                configuration_search: r('themes/admin/_base/js/configuration_search.ts'),
                element_set_ranks: r('themes/admin/_base/js/element_set_ranks.ts'),
                configuration_sizes: r('themes/admin/_base/js/configuration_sizes.ts'),
                languages_installed: r('themes/admin/_base/js/languages_installed.ts'),
                updates_pwg: r('themes/admin/_base/js/updates_pwg.ts'),
                site_manager: r('themes/admin/_base/js/site_manager.ts'),
                permalinks: r('themes/admin/_base/js/permalinks.ts'),
                album_notification: r('themes/admin/_base/js/album_notification.ts'),
                updates_ext: r('themes/admin/_base/js/updates_ext.ts'),
                themes_installed: r('themes/admin/_base/js/themes_installed.ts'),
                themes_new: r('themes/admin/_base/js/themes_new.ts'),
                datepicker: r('themes/admin/_base/js/datepicker.ts'),
                group_list: r('themes/admin/_base/js/group_list.ts'),
                history: r('themes/admin/_base/js/history.ts'),
                menubar: r('themes/admin/_base/js/menubar.ts'),
                themes_standard_pages: r('themes/admin/_base/js/themes_standard_pages.ts'),
                intro_tooltips: r('themes/admin/_base/js/intro_tooltips.ts'),
                'glightbox-admin': r('themes/admin/_base/js/glightbox-init.ts'),
                geoip: r('themes/admin/_base/js/geoip.ts'),
                rating_user: r('themes/admin/_base/js/rating_user.ts'),
                ajax: r('themes/admin/_base/js/maintenance.ts'),
                activated_plugin_list: r('themes/admin/_base/js/maintenance_env.ts'),
                sys: r('themes/admin/_base/js/maintenance_sys.ts'),
                add_photo: r('themes/admin/_base/js/photos_add_direct.ts'),
                picture_coi: r('themes/admin/_base/js/picture_coi.ts'),
                picture_formats: r('themes/admin/_base/js/picture_formats.ts'),
                picture_modify: r('themes/admin/_base/js/picture_modify.ts'),
                pluginInstallated: r('themes/admin/_base/js/plugins_installated.ts'),
                pluginsNew: r('themes/admin/_base/js/plugins_new.ts'),
                notification_by_mail: r('themes/admin/_base/js/notification_by_mail.ts'),
                site_update: r('themes/admin/_base/js/site_update.ts'),
                photos_add_applications_glightbox: r(
                    'themes/admin/_base/js/photos_add_applications_glightbox.ts'
                ),
                admin_help_glightbox: r('themes/admin/_base/js/admin_help_glightbox.ts'),
                rating_admin: r('themes/admin/_base/js/rating.ts'),
                stats: r('themes/admin/_base/js/stats.ts'),
                tags: r('themes/admin/_base/js/tags.ts'),
                user_activity: r('themes/admin/_base/js/user_activity.ts'),
                user_list: r('themes/admin/_base/js/user_list.ts'),
                install: r('themes/admin/_base/js/install.ts'),
                check_integrity: r('themes/admin/_base/js/check_integrity.ts'),
                popuphelp_window: r('themes/admin/_base/js/popuphelp_window.ts'),
            },
            output: {
                entryFileNames: 'assets/[name]-[hash].js',
                chunkFileNames: 'assets/chunks/[name]-[hash].js',
            },
        },
    },
    plugins: [piwigoManifestPlugin()],
    server: {
        port: 5173,
        strictPort: true,
        cors: true,
    },
});
