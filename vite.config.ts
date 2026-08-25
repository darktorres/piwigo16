import { defineConfig } from "vite";
import { resolve } from "path";
import { fileURLToPath } from "url";
import { readFileSync } from "fs";

const __dirname = fileURLToPath(new URL(".", import.meta.url));
const r = (p: string) => resolve(__dirname, p);

export default defineConfig({
  root: ".",
  plugins: [
    // docs/PLAN.md P48's own real fix for a genuine Rollup constraint,
    // confirmed by a live `vite build` + direct dist/ inspection (not
    // assumed from docs): a module reachable from 2+ separate `input`
    // entries gets extracted into one shared chunk, and that chunk's
    // real `import` statement in each consuming entry's own compiled
    // output is a hard SyntaxError once wrapped in this file's own
    // `banner`/`footer` IIFE (`Cannot use import statement outside a
    // module`, confirmed directly). Since P48's own design (matching
    // this project's historical `FileCombiner` precedent, see
    // docs/PLAN.md's P48 plan) wants every page's own bundle fully
    // self-contained -- real duplication, not a shared chunk -- any
    // shared-library file importable from 2+ real page-bundle entries
    // needs `import "./real-path?dup";` (the literal, fixed `?dup`
    // suffix, no manual per-call tag -- this plugin derives the real
    // disambiguating key from the *importer's own resolved path*,
    // guaranteed unique per real consuming file already, rather than
    // trusting a hand-picked tag not to collide) instead of a plain
    // `import "./real-path";`. Resolves to the real file's own path
    // (so it's still real, checked TypeScript source, not a separate
    // copy on disk) but keeps a per-importer-unique id for Rollup, so
    // Rollup treats each importer's copy as a genuinely distinct
    // module -- confirmed via a live build that this produces zero
    // `imports` in the resulting manifest.json entries and fully
    // duplicates the real compiled code into each consuming entry
    // instead. The fixed (no wildcard-in-the-middle) `?dup` suffix is
    // also what makes `build/vite-modules.d.ts`'s own ambient
    // `declare module "*?dup"` valid -- TypeScript's wildcard module
    // patterns only support a single `*`, confirmed directly (a
    // `"*?dup=*"`-shaped pattern, tried first, left both real
    // importers as real `tsc` errors). `enforce: "pre"` is load-bearing,
    // confirmed directly -- without it, Vite's own core resolver
    // silently claims the `?dup`-suffixed specifier first (stripping
    // the query and resolving straight to the real shared file, no
    // error, just quietly wrong), and this plugin's `resolveId` never
    // runs at all.
    {
      name: "duplicate-shared-module",
      enforce: "pre",
      async resolveId(source, importer) {
        if (source !== "?dup" && !source.endsWith("?dup")) return null;
        const realSource = source.slice(0, -"?dup".length);
        const resolved = await this.resolve(realSource, importer, {
          skipSelf: true,
        });
        if (!resolved || !importer) return null;
        return resolved.id + "?dup&importer=" + encodeURIComponent(importer);
      },
      load(id) {
        if (!id.includes("?dup&importer=")) return null;
        const realId = id.split("?dup&importer=")[0]!;
        return readFileSync(realId, "utf-8");
      },
    },
  ],
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
        // album_selector.ts/intro.ts are new findings). intro.ts is no
        // longer its own entry (docs/PLAN.md P48) -- see the pages/
        // entries below, its one real registrant.
        adminCommon: r("themes/admin/default/js/common.ts"),
        localStorageCache: r("themes/admin/default/js/LocalStorageCache.ts"),
        albumSelector: r("themes/admin/default/js/album_selector.ts"),
        // P46-C part 2 — the genuinely bidirectional pair (see both
        // files' own leading comments for the real ordering-safety
        // analysis).
        batchManagerGlobalUnderscore: r(
          "themes/admin/default/js/batch_manager_global.ts",
        ),
        batchManagerGlobal: r("themes/admin/default/js/batchManagerGlobal.ts"),
        // P46-C part 3 -- the first consumer-only file (its declarer,
        // plugins_installed_config.js, hasn't converted yet).
        pluginsInstallated: r("themes/admin/default/js/plugins_installated.ts"),
        // P46-C part 4 -- consumer of the already-converted intro.ts.
        // intro_tooltips.ts is no longer its own entry either
        // (docs/PLAN.md P48) -- folded into the same pages/intro.ts
        // bundle as intro.ts itself.
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
        photosAddApplications: r(
          "themes/admin/default/js/photos_add_applications.ts",
        ),
        autosizeAdmin: r("themes/admin/default/js/autosize.ts"),
        languagesNew: r("themes/admin/default/js/languages_new.ts"),
        siteUpdate: r("themes/admin/default/js/site_update.ts"),
        languagesInstalled: r("themes/admin/default/js/languages_installed.ts"),
        updatesPwg: r("themes/admin/default/js/updates_pwg.ts"),
        permalinks: r("themes/admin/default/js/permalinks.ts"),
        notificationByMail: r(
          "themes/admin/default/js/notification_by_mail.ts",
        ),
        siteManager: r("themes/admin/default/js/site_manager.ts"),
        themesNew: r("themes/admin/default/js/themes_new.ts"),
        menubarAdmin: r("themes/admin/default/js/menubar.ts"),
        // P46-C part 12 -- plugins_installated.ts's own declarer, now
        // real (retires that file's earlier ambient declare-const
        // bindings).
        pluginsInstalledConfig: r(
          "themes/admin/default/js/plugins_installed_config.ts",
        ),
        // P46-C part 13 -- second batch of small, self-contained admin
        // files (14 files).
        footerAdmin: r("themes/admin/default/js/footer.ts"),
        checkIntegrity: r("themes/admin/default/js/check_integrity.ts"),
        configurationWatermark: r(
          "themes/admin/default/js/configuration_watermark.ts",
        ),
        elementSetRanks: r("themes/admin/default/js/element_set_ranks.ts"),
        albumNotification: r("themes/admin/default/js/album_notification.ts"),
        adminShell: r("themes/admin/default/js/admin.ts"),
        configurationSearch: r(
          "themes/admin/default/js/configuration_search.ts",
        ),
        themesInstalled: r("themes/admin/default/js/themes_installed.ts"),
        themesStandardPages: r(
          "themes/admin/default/js/themes_standard_pages.ts",
        ),
        maintenance: r("themes/admin/default/js/maintenance.ts"),
        pictureFormats: r("themes/admin/default/js/picture_formats.ts"),
        catPerm: r("themes/admin/default/js/cat_perm.ts"),
        ratingAdmin: r("themes/admin/default/js/rating.ts"),
        doubleSlider: r("themes/admin/default/js/doubleSlider.ts"),
        // P46-C part 14 -- 5 more small, self-contained admin files.
        configurationComments: r(
          "themes/admin/default/js/configuration_comments.ts",
        ),
        pictureCoi: r("themes/admin/default/js/picture_coi.ts"),
        configurationSizes: r("themes/admin/default/js/configuration_sizes.ts"),
        configurationMain: r("themes/admin/default/js/configuration_main.ts"),
        maintenanceActions: r("themes/admin/default/js/maintenance_actions.ts"),
        // P46-C part 15 -- datepicker.ts, the real declarer of
        // pwgDatepicker (already ambient-typed `any` and consumed by
        // several already-converted files). addAlbum.ts (its former
        // sibling here) is no longer its own entry (docs/PLAN.md P48)
        // -- see the pages/ entries below, its 2 real registrants.
        datepicker: r("themes/admin/default/js/datepicker.ts"),
        // P46-C part 16 -- migrating the remaining files in bulk;
        // validation (typecheck/lint/build/test) deferred to the end
        // per direct instruction, not per-file from here on.
        ratingUser: r("themes/admin/default/js/rating_user.ts"),
        updatesExt: r("themes/admin/default/js/updates_ext.ts"),
        install: r("themes/admin/default/js/install.ts"),
        stats: r("themes/admin/default/js/stats.ts"),
        pluginsNew: r("themes/admin/default/js/plugins_new.ts"),
        catList: r("themes/admin/default/js/cat_list.ts"),
        comments: r("themes/admin/default/js/comments.ts"),
        history: r("themes/admin/default/js/history.ts"),
        userActivity: r("themes/admin/default/js/user_activity.ts"),
        albums: r("themes/admin/default/js/albums.ts"),
        groupList: r("themes/admin/default/js/group_list.ts"),
        tags: r("themes/admin/default/js/tags.ts"),
        userList: r("themes/admin/default/js/user_list.ts"),
        // toaster.ts is no longer its own entry (docs/PLAN.md P48) --
        // it has exactly one real consumer (profile.ts, imported
        // below), so its code ships inside that entry's own compiled
        // output instead of a separate <script> tag.
        standardPages: r("themes/standard_pages/js/standard_pages.ts"),
        profile: r("themes/standard_pages/js/profile.ts"),
        mcs: r("themes/default/js/mcs.ts"),
        // docs/PLAN.md P48 -- real per-page bundle entries, one per
        // (page × LoadMode group), each extended by later batches as
        // more of that page's shared-library files get folded in.
        photosAddDirectPage: r(
          "themes/admin/default/js/pages/photos_add_direct.ts",
        ),
        batchManagerGlobalAsyncPage: r(
          "themes/admin/default/js/pages/batch_manager_global_async.ts",
        ),
        introPage: r("themes/admin/default/js/pages/intro.ts"),
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
