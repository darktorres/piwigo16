import { defineConfig } from 'vite';
import { resolve } from 'path';
import { fileURLToPath } from 'url';

const __dirname = fileURLToPath(new URL('.', import.meta.url));

// Admin utilities - converted to ES modules first
// Page-specific modules will be added as they're converted
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
  'intro',
];

const input = {};
adminModules.forEach(module => {
  input[module] = resolve(__dirname, `admin/themes/default/js/${module}.js`);
});

export default defineConfig({
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
        for (const [key, file] of Object.entries(bundle)) {
          if (file.type === 'asset') continue;
          const moduleName = file.name;
          const fullPath = `admin/themes/default/js/${moduleName}.js`;
          manifest[fullPath] = {
            file: file.fileName,
            name: moduleName,
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
