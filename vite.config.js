import { defineConfig } from 'vite';
import { resolve, relative } from 'path';
import { fileURLToPath } from 'url';
import { existsSync } from 'fs';

const __dirname = fileURLToPath(new URL('.', import.meta.url));

const adminModules = [
  'common',
  'pwgConfirm',
  'LocalStorageCache',
  'PwgTree',
  'addAlbum',
  'datepicker',
  'doubleSlider',
  'album_selector',
  'album_notification',
  'admin',
  'maintenance',
  'maintenance_env',
  'cat_list',
  'cat_perm',
  'cat_search',
  'comments',
  'element_set_ranks',
  'generate_video_thumbnails',
  'helpPopin',
  'history',
  'languages_installed',
  'plugins_installated',
  'plugins_new',
  'picture_formats',
  'stats',
  'group_list',
  'cat_modify',
  'picture_coi',
  'picture_modify',
  'tags',
  'themes_installed',
  'user_list',
  'albums',
  'batchManagerGlobal',
  'batch_manager_unit',
  'photos_add_applications',
  'photos_add_direct',
  'rating',
  'site_manager',
  'updates_ext',
  'user_activity',
  'menubar',
  'configuration',
  'configuration_comments',
  'configuration_sizes',
  'themes_new',
  'configuration_watermark',
  'permalinks',
  'configuration_main',
  'rating_user',
  'site_update',
  'intro',
];

// Gallery and plugin modules: [rollupKey, srcPath (no extension)]
const galleryAndPluginModules = [
  // gallery default theme
  ['gallery_mcs',            'themes/default/js/mcs'],
  ['gallery_rating',         'themes/default/js/rating'],
  ['gallery_switchbox',      'themes/default/js/switchbox'],
  ['gallery_scripts',        'themes/default/js/scripts'],
  // gallery smartpocket theme (thumb.arrange bundled into smartpocket)
  ['sp_smartpocket',         'themes/smartpocket/js/smartpocket'],
  // gallery bootstrap darkroom theme
  ['bd_header',              'themes/bootstrap_darkroom/js/header'],
  ['bd_rating',              'themes/bootstrap_darkroom/js/rating'],
  ['bd_theme',               'themes/bootstrap_darkroom/js/theme'],
  // plugins
  ['at_admin',               'plugins/AdminTools/js/admin'],
  ['at_admin_controller',    'plugins/AdminTools/js/admin_controller'],
  ['tat_tour',               'plugins/TakeATour/js/Tour'],
  ['rvts',                   'plugins/rv_tscroller/rv_tscroller'],
  ['gdthumb',                'plugins/GDThumb/js/gdthumb'],
  ['gdthumb_admin',          'plugins/GDThumb/js/gdthumb.admin'],
  ['gdthumb_image_loader',   'plugins/GDThumb/js/image.loader'],
  ['gdthumb_masonry',        'plugins/GDThumb/js/masonry'],
];

const input = {};

adminModules.forEach(module => {
  const ts = resolve(__dirname, `admin/themes/default/js/${module}.ts`);
  const js = resolve(__dirname, `admin/themes/default/js/${module}.js`);
  input[module] = existsSync(ts) ? ts : js;
});

galleryAndPluginModules.forEach(([key, srcPath]) => {
  const ts = resolve(__dirname, `${srcPath}.ts`);
  const js = resolve(__dirname, `${srcPath}.js`);
  input[key] = existsSync(ts) ? ts : js;
});

export default defineConfig({
  resolve: {
    extensions: ['.ts', '.js', '.mjs', '.jsx', '.tsx', '.json'],
  },
  build: {
    outDir: 'admin/themes/default/js/dist',
    rollupOptions: {
      output: {
        entryFileNames: '[name].[hash].js',
        chunkFileNames: '[name].[hash].js',
        assetFileNames: '[name].[hash][extname]',
      },
      input,
    },
  },
  plugins: [
    {
      name: 'generate-manifest',
      generateBundle(options, bundle) {
        const manifest = {};
        for (const [, file] of Object.entries(bundle)) {
          if (file.type === 'asset') continue;
          if (!file.isEntry) continue;

          let manifestKey;
          if (file.facadeModuleId) {
            manifestKey = relative(__dirname, file.facadeModuleId)
              .replace(/\\/g, '/')
              .replace(/\.ts$/, '.js');
          } else {
            manifestKey = `admin/themes/default/js/${file.name}.js`;
          }

          manifest[manifestKey] = {
            file: file.fileName,
            name: file.name,
          };
        }
        this.emitFile({
          type: 'asset',
          fileName: 'manifest.json',
          source: JSON.stringify(manifest, null, 2),
        });
      },
    },
  ],
});
