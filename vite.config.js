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
  'maintenance',
  'maintenance_env',
  'cat_list',
  'helpPopin',
  'history',
  'plugins_installated',
  'plugins_new',
  'picture_formats',
];

const input = {};
adminModules.forEach(module => {
  input[module] = resolve(__dirname, `admin/themes/default/js/${module}.js`);
});

export default defineConfig({
  build: {
    manifest: true,
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
});
