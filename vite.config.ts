import { defineConfig } from "vite";
import { resolve } from "path";
import { fileURLToPath } from "url";

const __dirname = fileURLToPath(new URL(".", import.meta.url));
const r = (p: string) => resolve(__dirname, p);

export default defineConfig({
  root: ".",
  // Resolve dynamic chunk URLs via import.meta.url so they work under any
  // Apache document root prefix (Piwigo can be served at /, /piwigo17/, etc.).
  base: "./",
  // Vite's own default publicDir ("<root>/public") collides with this
  // project's public/ -- the PHP app's web root (Legacy Coupling
  // Retirement's web-root isolation work), not a "static assets to copy
  // into the build output" directory in Vite's sense. Left at the default,
  // the build's own publicDir-copy step walks into public/dist (a symlink
  // back to this very outDir, bridging the built assets so the PHP app can
  // serve them) and recursively copies dist/ into itself without bound --
  // real bug: a `vite build` run filled the disk (dist/dist/dist/...
  // nested ~100 levels deep, ENOSPC) before this was set. Nothing here
  // needs Vite's copy step anyway: outDir already IS what
  // public/dist symlinks to, so the built assets are already reachable at
  // /dist/... with zero copying.
  publicDir: false,
  build: {
    outDir: "dist",
    emptyOutDir: true,
    manifest: true,
    // docs/PLAN.md P35's own stated intent for .browserslistrc's floor
    // (Chrome/Edge ≥94, Firefox ≥93, Safari ≥15): "the evergreen floor
    // that actually supports tsconfig.json's existing ES2022
    // target/lib" -- originally mirrored here as those same specific
    // browser-version strings (esbuild targets and browserslist queries
    // aren't interchangeable), on the assumption the two framings were
    // equivalent. They aren't, for at least one real feature: esbuild's
    // own per-browser-version compat table treats real ES2022 private
    // class fields (`#foo`) as unsupported by every one of those 4
    // specific version strings, and downlevels them into a
    // `Object.defineProperty`-based helper -- extracted by Rollup into
    // its own chunk, imported via a real ES `import` statement no
    // `<script>` tag in this codebase ever loads (P46-B's own "every
    // entry loads as a separate non-module script tag" design), a real
    // dead-on-arrival ReferenceError. Found via `album_selector.ts`
    // (P46-C), the first converted entry to use `#foo` syntax --
    // confirmed the exact same syntax was already being served raw,
    // untranspiled, straight to the browser pre-conversion (this file
    // was never bundled before), so requiring genuine ES2022 support
    // isn't a new compatibility requirement, just P35's own original
    // intent enforced accurately. `target: "es2022"` (the bare language
    // target, not per-browser version strings) is what actually
    // delivers that intent -- confirmed to produce byte-identical
    // output for every other already-converted entry (none use
    // private fields), so this isn't a broader compatibility change,
    // just a correction of a gap in esbuild's own compat table.
    target: "es2022",
    rollupOptions: {
      input: {
        // Placeholder only — 61 more real entries land across P46-C
        // onward.
        noop: r("build/noop.ts"),
        // Real entry (docs/PLAN.md P1 gap, remediated post-P22) — web
        // Vitals RUM beacon, loaded on every page via footer.tpl.
        vitals: r("build/vitals.ts"),
        // P46-B — themes/default/js/*.js's first 12 real entries (2 more,
        // autosize.js/search.js, turned out to be dead code and were
        // deleted rather than converted).
        pageData: r("themes/default/js/page-data.ts"),
        scripts: r("themes/default/js/scripts.ts"),
        picture: r("themes/default/js/picture.ts"),
        searchFilters: r("themes/default/js/search_filters.ts"),
        rating: r("themes/default/js/rating.ts"),
        index: r("themes/default/js/index.ts"),
        menubarLinks: r("themes/default/js/menubar-links.ts"),
        menubarQuicksearch: r("themes/default/js/menubar-quicksearch.ts"),
        pictureNavButtons: r("themes/default/js/picture_nav_buttons.ts"),
        popuphelp: r("themes/default/js/popuphelp.ts"),
        switchbox: r("themes/default/js/switchbox.ts"),
        thumbnailsLoader: r("themes/default/js/thumbnails.loader.ts"),
        // P46-C — themes/admin/default/js/*.js's first 4 real entries
        // (the shared-global foundation files the P46-C full sweep
        // found: common.ts/LocalStorageCache.ts were already known;
        // album_selector.ts/intro.ts are new findings).
        adminCommon: r("themes/admin/default/js/common.ts"),
        localStorageCache: r("themes/admin/default/js/LocalStorageCache.ts"),
        albumSelector: r("themes/admin/default/js/album_selector.ts"),
        intro: r("themes/admin/default/js/intro.ts"),
        // P46-C part 2 — the genuinely bidirectional pair (see both
        // files' own leading comments for the real ordering-safety
        // analysis).
        batchManagerGlobalUnderscore: r("themes/admin/default/js/batch_manager_global.ts"),
        batchManagerGlobal: r("themes/admin/default/js/batchManagerGlobal.ts"),
        // P46-C part 3 -- the first consumer-only file (its declarer,
        // plugins_installed_config.js, hasn't converted yet).
        pluginsInstallated: r("themes/admin/default/js/plugins_installated.ts"),
        // P46-C part 4 -- consumer of the already-converted intro.ts.
        introTooltips: r("themes/admin/default/js/intro_tooltips.ts"),
        // P46-C part 5 -- consumer of album_selector.ts, and (a new
        // finding beyond the plan's own sweep) of not-yet-converted
        // albums.js's own `data`/`str_album_found` globals.
        catSearch: r("themes/admin/default/js/cat_search.ts"),
        // P46-C part 6 -- one of the 5 remaining AlbumSelector consumers.
        pictureModify: r("themes/admin/default/js/picture_modify.ts"),
        // P46-C part 7 -- another of the 5 remaining AlbumSelector
        // consumers.
        batchManagerFilter: r("themes/admin/default/js/batchManagerFilter.ts"),
        // P46-C part 8 -- another of the 5 remaining AlbumSelector
        // consumers.
        catModify: r("themes/admin/default/js/cat_modify.ts"),
        // P46-C part 9 -- the 4th of the 5 remaining AlbumSelector
        // consumers.
        batchManagerUnit: r("themes/admin/default/js/batchManagerUnit.ts"),
        // P46-C part 10 -- the 5th and last of the AlbumSelector
        // consumers.
        photosAddDirect: r("themes/admin/default/js/photos_add_direct.ts"),
        // P46-C part 11 -- first batch of the remaining ~47
        // non-consumer, non-declarer admin files: 12 trivial ones.
        adminHelp: r("themes/admin/default/js/admin_help.ts"),
        photosAddApplications: r("themes/admin/default/js/photos_add_applications.ts"),
        autosizeAdmin: r("themes/admin/default/js/autosize.ts"),
        languagesNew: r("themes/admin/default/js/languages_new.ts"),
        siteUpdate: r("themes/admin/default/js/site_update.ts"),
        languagesInstalled: r("themes/admin/default/js/languages_installed.ts"),
        updatesPwg: r("themes/admin/default/js/updates_pwg.ts"),
        permalinks: r("themes/admin/default/js/permalinks.ts"),
        notificationByMail: r("themes/admin/default/js/notification_by_mail.ts"),
        siteManager: r("themes/admin/default/js/site_manager.ts"),
        themesNew: r("themes/admin/default/js/themes_new.ts"),
        menubarAdmin: r("themes/admin/default/js/menubar.ts"),
        // P46-C part 12 -- plugins_installated.ts's own declarer, now
        // real (retires that file's earlier ambient declare-const
        // bindings).
        pluginsInstalledConfig: r("themes/admin/default/js/plugins_installed_config.ts"),
        // P46-C part 13 -- second batch of small, self-contained admin
        // files (14 files).
        footerAdmin: r("themes/admin/default/js/footer.ts"),
        checkIntegrity: r("themes/admin/default/js/check_integrity.ts"),
        configurationWatermark: r("themes/admin/default/js/configuration_watermark.ts"),
        elementSetRanks: r("themes/admin/default/js/element_set_ranks.ts"),
        albumNotification: r("themes/admin/default/js/album_notification.ts"),
        adminShell: r("themes/admin/default/js/admin.ts"),
        configurationSearch: r("themes/admin/default/js/configuration_search.ts"),
        themesInstalled: r("themes/admin/default/js/themes_installed.ts"),
        themesStandardPages: r("themes/admin/default/js/themes_standard_pages.ts"),
        maintenance: r("themes/admin/default/js/maintenance.ts"),
        pictureFormats: r("themes/admin/default/js/picture_formats.ts"),
        catPerm: r("themes/admin/default/js/cat_perm.ts"),
        ratingAdmin: r("themes/admin/default/js/rating.ts"),
        doubleSlider: r("themes/admin/default/js/doubleSlider.ts"),
        // P46-C part 14 -- 5 more small, self-contained admin files.
        configurationComments: r("themes/admin/default/js/configuration_comments.ts"),
        pictureCoi: r("themes/admin/default/js/picture_coi.ts"),
        configurationSizes: r("themes/admin/default/js/configuration_sizes.ts"),
        configurationMain: r("themes/admin/default/js/configuration_main.ts"),
        maintenanceActions: r("themes/admin/default/js/maintenance_actions.ts"),
        // P46-C part 15 -- addAlbum.ts/datepicker.ts, the real
        // declarers of pwgAddAlbum/pwgDatepicker (already ambient-typed
        // `any` and consumed by several already-converted files).
        addAlbum: r("themes/admin/default/js/addAlbum.ts"),
        datepicker: r("themes/admin/default/js/datepicker.ts"),
      },
      output: {
        // P36's Piwigo\Asset\ViteManifest (reading manifest.json for
        // hashed filenames) exists now, but no template has migrated onto
        // it yet — everything except `vitals` still uses Piwigo's legacy
        // ScriptLoader/CssLoader combiner (docs/PLAN.md's P36 section).
        // `vitals` is resolved through ViteManifest from a fixed,
        // non-hashed filename rather than the default `[name]-[hash].js`;
        // every other entry keeps the default.
        entryFileNames: (chunk) =>
          chunk.name === "vitals" ? "vitals.js" : "assets/[name]-[hash].js",
        // Real bug found only via an actual `vite build` + real browser
        // run (P46-B): every themes/**/*.ts entry compiles as a flat,
        // unwrapped top-level script (Rollup's default 'es' output has
        // no reason to add a wrapper when a chunk has no real import/
        // export of its own -- confirmed against several real entries'
        // output). Since every one of these entries is loaded as a
        // separate, non-module `<script>` tag sharing ONE browser global
        // scope (docs/PLAN.md's own "not real ES modules, no import/
        // export between first-party files" P46 scope), each entry's own
        // MINIFIED-INTERNAL (not `window.`-exposed) top-level names can
        // silently collide with another entry's own same-named internal
        // binding -- confirmed concretely: page-data.ts's cache variable
        // and menubar-quicksearch.ts's own top-level function both
        // minified down to the identical single-letter name `e`, and
        // menubar-quicksearch's later-loaded declaration overwrote
        // page-data's cache variable, silently corrupting
        // `pwg_getPageString()` on every page that loads both. `format:
        // 'iife'` (Rollup's normal fix for this) can't be used
        // build-wide: `vitals` genuinely needs real ES-module semantics
        // (`import.meta.url`, confirmed in its own source) that `iife`
        // doesn't support. Wrapping only the *other* entries' own raw
        // output text in a self-invoking function -- via `banner`/
        // `footer`, not the `format` option -- gets the same private-
        // scope isolation for exactly the entries that need it, without
        // touching `vitals`'s own compiled format at all. Safe precisely
        // because these entries already compile with zero import/export
        // statements (confirmed) -- wrapping code that already assumes
        // "plain script, own scope" changes nothing else observable.
        banner: (chunk) => (chunk.name === "vitals" ? "" : "(function(){"),
        footer: (chunk) => (chunk.name === "vitals" ? "" : "})();"),
      },
    },
  },
});
