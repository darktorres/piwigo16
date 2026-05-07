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
                'core.scripts': r('themes/default/js/scripts.ts'),
                'core.switchbox': r('themes/default/js/switchbox.ts'),
                search: r('themes/default/js/search.ts'),
                popuphelp: r('themes/default/js/popuphelp.ts'),
                picture_nav_keys: r('themes/default/js/picture_nav_keys.ts'),
                mcs: r('themes/default/js/mcs.ts'),
                pngfix: r('themes/default/js/pngfix.ts'),
                rating: r('themes/default/js/rating.ts'),
                'thumbnails.loader': r('themes/default/js/thumbnails.loader.ts'),
                // ── Standard pages ────────────────────────────────────────
                toaster_js: r('themes/standard_pages/js/toaster.ts'),
                standard_pages_js: r('themes/standard_pages/js/standard_pages.ts'),
                standard_profile_js: r('themes/standard_pages/js/profile.ts'),

                // ── Admin (themes/admin/default) ──────────────────────────
                common: r('themes/admin/default/js/common.ts'),
                admin_footer: r('themes/admin/default/js/admin_footer.ts'),
                admin: r('themes/admin/default/js/admin.ts'),
                addAlbum: r('themes/admin/default/js/addAlbum.ts'),
                albums: r('themes/admin/default/js/albums.ts'),
                batchManagerGlobal: r('themes/admin/default/js/batchManagerGlobal.ts'),
                batchManagerUnit: r('themes/admin/default/js/batchManagerUnit.ts'),
                batchManagerFilter: r('themes/admin/default/js/batchManagerFilter.ts'),
                cat_list: r('themes/admin/default/js/cat_list.ts'),
                cat_modify: r('themes/admin/default/js/cat_modify.ts'),
                cat_perm: r('themes/admin/default/js/cat_perm.ts'),
                cat_search: r('themes/admin/default/js/cat_search.ts'),
                comments: r('themes/admin/default/js/comments.ts'),
                configuration_comments: r('themes/admin/default/js/configuration_comments.ts'),
                configuration_main: r('themes/admin/default/js/configuration_main.ts'),
                configuration_watermark: r('themes/admin/default/js/configuration_watermark.ts'),
                configuration_search: r('themes/admin/default/js/configuration_search.ts'),
                element_set_ranks: r('themes/admin/default/js/element_set_ranks.ts'),
                configuration_sizes: r('themes/admin/default/js/configuration_sizes.ts'),
                languages_installed: r('themes/admin/default/js/languages_installed.ts'),
                updates_pwg: r('themes/admin/default/js/updates_pwg.ts'),
                site_manager: r('themes/admin/default/js/site_manager.ts'),
                permalinks: r('themes/admin/default/js/permalinks.ts'),
                album_notification: r('themes/admin/default/js/album_notification.ts'),
                updates_ext: r('themes/admin/default/js/updates_ext.ts'),
                themes_installed: r('themes/admin/default/js/themes_installed.ts'),
                themes_new: r('themes/admin/default/js/themes_new.ts'),
                datepicker: r('themes/admin/default/js/datepicker.ts'),
                group_list: r('themes/admin/default/js/group_list.ts'),
                history: r('themes/admin/default/js/history.ts'),
                menubar: r('themes/admin/default/js/menubar.ts'),
                themes_standard_pages: r('themes/admin/default/js/themes_standard_pages.ts'),
                intro_tooltips: r('themes/admin/default/js/intro_tooltips.ts'),
                'glightbox-admin': r('themes/admin/default/js/glightbox-init.ts'),
                geoip: r('themes/admin/default/js/geoip.ts'),
                rating_user: r('themes/admin/default/js/rating_user.ts'),
                ajax: r('themes/admin/default/js/maintenance.ts'),
                activated_plugin_list: r('themes/admin/default/js/maintenance_env.ts'),
                sys: r('themes/admin/default/js/maintenance_sys.ts'),
                add_photo: r('themes/admin/default/js/photos_add_direct.ts'),
                picture_coi: r('themes/admin/default/js/picture_coi.ts'),
                picture_formats: r('themes/admin/default/js/picture_formats.ts'),
                picture_modify: r('themes/admin/default/js/picture_modify.ts'),
                pluginInstallated: r('themes/admin/default/js/plugins_installated.ts'),
                pluginsNew: r('themes/admin/default/js/plugins_new.ts'),
                notification_by_mail: r('themes/admin/default/js/notification_by_mail.ts'),
                site_update: r('themes/admin/default/js/site_update.ts'),
                photos_add_applications_glightbox: r(
                    'themes/admin/default/js/photos_add_applications_glightbox.ts'
                ),
                admin_help_glightbox: r('themes/admin/default/js/admin_help_glightbox.ts'),
                rating_admin: r('themes/admin/default/js/rating.ts'),
                stats: r('themes/admin/default/js/stats.ts'),
                tags: r('themes/admin/default/js/tags.ts'),
                user_activity: r('themes/admin/default/js/user_activity.ts'),
                user_list: r('themes/admin/default/js/user_list.ts'),
                install: r('themes/admin/default/js/install.ts'),
                check_integrity: r('themes/admin/default/js/check_integrity.ts'),
                popuphelp_window: r('themes/admin/default/js/popuphelp_window.ts'),
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
