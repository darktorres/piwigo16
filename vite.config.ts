import { defineConfig } from "vite";
import { resolve } from "path";
import { fileURLToPath } from "url";

const __dirname = fileURLToPath(new URL(".", import.meta.url));
const r = (p: string) => resolve(__dirname, p);

export default defineConfig({
  root: ".",
  plugins: [],
  resolve: {
    alias: {
      // tus-js-client ships separate node and browser builds and points
      // `main`/`module` at the NODE one (lib.esm/node/index.js). It
      // redirects them via a `browser` field, but that field is an
      // object *map* rather than a plain string and the package has no
      // `exports` block, so Vite's mainFields resolution reaches
      // `module` first and bundles the node build.
      //
      // Found live, not by any gate: typecheck, lint, the unit suite and
      // golden-html all passed, and the page threw `TypeError: Super
      // expression must either be null or a function` in the browser --
      // the node build extending APIs that do not exist there. Pinning
      // the browser entry explicitly makes the resolution deterministic
      // instead of dependent on mainFields ordering.
      "tus-js-client": r("node_modules/tus-js-client/lib.esm/browser/index.js"),
    },
  },
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
        // Placeholder only. 72 real entries total as of P48-Z's own
        // final count (docs/PLAN.md) -- down from the 79 P46-C left
        // this file with (2 build/*, 77 themes/**/*.ts, one file per
        // page/shared-library file, no consolidation): P48's own real
        // `import`/`export` conversion + bundle consolidation removed
        // every shared-library file's own standalone entry (common.ts/
        // page-data.ts/scripts.ts/album_selector.ts/LocalStorageCache.ts/
        // doubleSlider.ts/switchbox.ts/search_filters.ts/intro.ts/
        // intro_tooltips.ts/plugins_installed_config.ts/
        // plugins_installated.ts/batchManagerGlobal.ts/
        // batch_manager_global.ts/addAlbum.ts/datepicker.ts/autosize.ts/
        // toaster.ts, 18 entries gone) and added 11 new real per-page
        // bundle entries under themes/**/pages/: 9 where 2+ real files
        // needed merging into one LoadMode group, plus 2
        // (configurationDefaultPage/configurationDisplayPage) whose only
        // first-party JS need was a single shared-library file's own
        // side effects, with no other page-specific file of their own.
        noop: r("build/noop.ts"),
        // Real entry (docs/PLAN.md P1 gap, remediated post-P22) — web
        // Vitals RUM beacon, loaded on every page via footer.tpl.
        vitals: r("build/vitals.ts"),
        // P46-B — themes/default/js/*.js's first 12 real entries (2 more,
        // autosize.js/search.js, turned out to be dead code and were
        // deleted rather than converted).
        // page-data.ts is no longer its own entry either (docs/PLAN.md
        // P48, its own later, heaviest batch -- 48 real consumer files)
        // -- every real consumer imports it directly instead of the
        // separate centralized script tag `Template::finalizeHtml()`
        // used to register, and Rollup emits it once as a shared chunk.
        // scripts.ts is no longer its own entry (docs/PLAN.md P48, its
        // own later batch) -- it has several real registrant pages,
        // each of whose bundles imports it directly (see the pages/
        // entries below, and picture.ts's/
        // rating.ts's own direct imports for its 2 real symbol
        // consumers).
        picture: r("themes/default/js/picture.ts"),
        // search_filters.ts is no longer its own entry (docs/PLAN.md
        // P48) -- mcs.ts is its one real consumer file anywhere, so its
        // code folds directly into mcs.ts's own bundle via a plain
        // import instead.
        rating: r("themes/default/js/rating.ts"),
        index: r("themes/default/js/index.ts"),
        menubarLinks: r("themes/default/js/menubar-links.ts"),
        menubarQuicksearch: r("themes/default/js/menubar-quicksearch.ts"),
        pictureNavButtons: r("themes/default/js/picture_nav_buttons.ts"),
        popuphelp: r("themes/default/js/popuphelp.ts"),
        // switchbox.ts is no longer its own entry (docs/PLAN.md P48)
        // -- its 2 real registrant pages (IndexView/PictureView) fold
        // its code in via their own direct import instead (their own
        // index.ts/picture.ts, its 2 real "pusher" files).
        thumbnailsLoader: r("themes/default/js/thumbnails.loader.ts"),
        // P46-C — themes/admin/default/js/*.js's first 4 real entries
        // (the shared-global foundation files the P46-C full sweep
        // found: common.ts/LocalStorageCache.ts were already known;
        // album_selector.ts/intro.ts are new findings). intro.ts is no
        // longer its own entry (docs/PLAN.md P48) -- see the pages/
        // entries below, its one real registrant. album_selector.ts is
        // no longer its own entry either (docs/PLAN.md P48, its own
        // later batch) -- it has 8 real consumer files, each importing
        // its code directly via a direct import instead.
        // common.ts is no longer its own entry either (docs/PLAN.md
        // P48, its own later batch) -- 30+ real registrant pages, each
        // folding its code in via a direct import instead (its own real
        // consumer files, or a new minimal pages/ entry for the 2 pages
        // whose only first-party JS need was common.ts's own side
        // effects).
        // LocalStorageCache.ts is no longer its own entry either (docs/
        // PLAN.md P48, its own later batch) -- its 4 real exported
        // classes fold into their own 7 real consumer files' bundles
        // via a direct import instead.
        // P46-C part 2 — the genuinely bidirectional pair (see both
        // files' own leading comments for the real ordering-safety
        // analysis). Neither is its own entry any more (docs/PLAN.md
        // P48) -- both fold into the same pages/batch_manager_global.ts
        // bundle below, the one page that registers either.
        // P46-C part 3 -- the first consumer-only file. plugins_installated.ts
        // is no longer its own entry (docs/PLAN.md P48) -- see the pages/
        // entries below, its one real registrant.
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
        // autosize.ts is no longer its own entry (docs/PLAN.md P48) --
        // it has 3 real registrant pages, each with their own pages/
        // entry below folding its code in via a direct import.
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
        // P46-C part 12 -- plugins_installated.ts's own declarer.
        // Also no longer its own entry (docs/PLAN.md P48) -- see the
        // pages/ entries below.
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
        // doubleSlider.ts is no longer its own entry (docs/PLAN.md P48)
        // -- its 2 real file-level consumers (batchManagerFilter.ts/
        // mcs.ts, each its own real registrant-page entry) fold its
        // code in via their own direct import instead.
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
        // datepicker.ts itself is no longer its own entry either
        // (docs/PLAN.md P48, its own later batch) -- it has 4 real
        // registrant pages, each with their own pages/ entry folding
        // its code in via a direct import.
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
        // Merged Footer-mode bundle (docs/PLAN.md P48) -- was 2 separate
        // (page × LoadMode) entries (Async batchManagerGlobal.ts +
        // Footer batch_manager_global.ts) until this pair's own batch
        // found the 2 files must share one real evaluation (see both
        // files' own leading comments): batchManagerGlobal.ts has real,
        // unconditional page-load side effects (event-handler
        // registration), so it can never be duplicated across 2 script
        // tags the way addAlbum.ts's direct import safely is.
        batchManagerGlobalPage: r(
          "themes/admin/default/js/pages/batch_manager_global.ts",
        ),
        introPage: r("themes/admin/default/js/pages/intro.ts"),
        pluginsInstalledPage: r(
          "themes/admin/default/js/pages/plugins_installed.ts",
        ),
        // autosize.ts's own batch (docs/PLAN.md P48) -- each of its 3
        // real registrant pages gets its own bundle, extended by later
        // batches as more of that page's shared-library files convert.
        batchManagerUnitPage: r(
          "themes/admin/default/js/pages/batch_manager_unit.ts",
        ),
        pictureModifyPage: r("themes/admin/default/js/pages/picture_modify.ts"),
        notificationByMailPage: r(
          "themes/admin/default/js/pages/notification_by_mail.ts",
        ),
        // scripts.ts's own batch (docs/PLAN.md P48) -- extends
        // batchManagerUnitPage/batchManagerGlobalPage above with a
        // direct import each; coreScriptsPage is the shared bundle for
        // every other real registrant page with no other file of its
        // own to fold scripts.ts into (register.php/password.php/
        // identification.php/profile.php's default-theme branch,
        // comment_list.inc.latte's own conditional branch).
        coreScriptsPage: r("themes/default/js/pages/core_scripts.ts"),
        // common.ts's own batch (docs/PLAN.md P48) -- the 2 real pages
        // whose only first-party JS need was common.ts's own side
        // effects, with no other page-specific file to fold that import
        // into instead.
        configurationDefaultPage: r(
          "themes/admin/default/js/pages/configuration_default.ts",
        ),
        configurationDisplayPage: r(
          "themes/admin/default/js/pages/configuration_display.ts",
        ),
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
        // No `banner`/`footer` IIFE wrapper any more. It existed to fix a
        // real, concretely-diagnosed bug (P46-B): entries used to load as
        // separate non-module `<script>` tags sharing ONE global scope, so
        // two entries' MINIFIED-INTERNAL top-level names could collide --
        // page-data.ts's cache variable and menubar-quicksearch.ts's own
        // top-level function both minified to `e`, and the later-loaded
        // declaration overwrote the other, silently corrupting
        // `pwg_getPageString()` on every page loading both.
        //
        // Module scope replaces that protection outright: every entry now
        // ships as `<script type="module">`, and a module's top-level
        // bindings are private to it by definition -- there is no shared
        // global scope left for minified names to collide in. The wrapper
        // and the module switch are therefore a single atomic change;
        // removing either alone would reintroduce that corruption bug.
        // (The wrapper was also incompatible with sharing: a shared
        // chunk's `import` statement inside an IIFE-wrapped classic
        // script is a hard `Cannot use import statement outside a module`
        // SyntaxError, which is exactly why entries had to be fully
        // self-contained before.)
      },
    },
  },
});
