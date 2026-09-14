import { defineConfig } from "vite";
import { resolve } from "path";
import { fileURLToPath } from "url";
import { collectScriptEntries } from "./build/collectScriptEntries.ts";

const __dirname = fileURLToPath(new URL(".", import.meta.url));
const r = (p: string) => resolve(__dirname, p);

/**
 * Rollup's own default entry-chunk `[name]` token (a bare basename) is NOT
 * unique across this app's real entries -- several pairs of `.ts` files
 * share a basename across different subdirectories (`categories/list.ts`/
 * `users/list.ts`, `configuration/comments.ts`/the top-level
 * `comments.ts`, `languages/new.ts`/`plugins/new.ts`,
 * `categories/search.ts`/`configuration/search.ts`), confirmed live via
 * the manifest itself, not assumed. Two entries then emit as e.g.
 * `list-fR1n7d0Q.js`/`list-BcFNDY23.js` -- distinct files, but
 * indistinguishable by any glob pattern that has to tolerate the hash
 * changing on every build (`tools/size-budget.mjs`'s own `dist/assets/
 * list-*.js`-style budgets silently matched both, corrupting the byte
 * counts of both real pages' size budgets).
 *
 * Fixed at the root by disambiguating only the entries that actually
 * collide (computed once, from the same source-path list `input` uses,
 * before any chunking happens) rather than every entry uniformly --
 * every other entry keeps its plain existing basename, so this carries
 * zero rename churn (and zero golden-html/asset-URL diff) for the ~90%
 * of entries that were never ambiguous to begin with.
 */
const entryBasename = (path: string) =>
  path.slice(path.lastIndexOf("/") + 1).replace(/\.ts$/, "");
const collidingBasenames = (() => {
  const seen = new Set<string>();
  const dupes = new Set<string>();
  for (const path of collectScriptEntries()) {
    const base = entryBasename(path);
    (seen.has(base) ? dupes : seen).add(base);
  }
  return dupes;
})();

/**
 * The disambiguated name for a colliding entry: its source path relative
 * to its nearest `js/` directory, with `/` flattened to `-` and the `.ts`
 * extension stripped (e.g. `themes/admin/default/js/categories/list.ts`
 * -> `categories-list`).
 */
function disambiguatedName(facadeModuleId: string): string {
  const jsDir = facadeModuleId.lastIndexOf("/js/");
  const rel =
    jsDir === -1 ? facadeModuleId : facadeModuleId.slice(jsDir + "/js/".length);
  return (rel.endsWith(".ts") ? rel.slice(0, -".ts".length) : rel).replaceAll(
    "/",
    "-",
  );
}

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
    // (originally Chrome/Edge ≥94, Firefox ≥93, Safari ≥15, bumped by
    // P52-A to Chrome/Edge ≥123, Firefox ≥128, Safari ≥17.5): "the
    // evergreen floor that actually supports tsconfig.json's existing
    // target/lib" -- originally mirrored here as specific per-browser
    // version strings (esbuild targets and browserslist queries aren't
    // interchangeable), on the assumption the two framings were
    // equivalent. They weren't, for at least one real feature: esbuild's
    // own per-browser-version compat table treated real ES2022 private
    // class fields (`#foo`) as unsupported by every one of those 4
    // specific version strings, and downleveled them into a
    // `Object.defineProperty`-based helper -- extracted by Rollup into
    // its own chunk, imported via a real ES `import` statement no
    // `<script>` tag in this codebase ever loads (P46-B's own "every
    // entry loads as a separate non-module script tag" design), a real
    // dead-on-arrival ReferenceError. Found via `album_selector.ts`
    // (P46-C), the first converted entry to use `#foo` syntax.
    // `target: "es2022"` (the bare language target, not per-browser
    // version strings) fixed that specific gap. Bumped further to
    // `"esnext"` alongside tsconfig.json's own target/lib bump: rather
    // than trusting esbuild's own per-feature compat table (the exact
    // thing that got the private-fields case wrong above) to correctly
    // decide what's safe to leave undownleveled at whatever specific
    // target string is named here, "esnext" disables downleveling
    // entirely -- the real evergreen floor this repo already requires
    // (no IE, no legacy Edge, ES2022+ throughout) means every real
    // target browser already runs whatever syntax the source actually
    // contains natively, so there is nothing left for esbuild to get
    // wrong. Confirmed to produce byte-identical output to the prior
    // `"es2022"` target across every entry (this codebase's own source
    // doesn't yet use any newer syntax `"es2022"` would have downleveled
    // differently from `"esnext"`) via `bun run build`.
    target: "esnext",
    rollupOptions: {
      // Derived from real PHP AssetContribution::script() registrations
      // (build/collectScriptEntries.ts, P51-B docs/PLAN.md) rather than
      // hand-maintained -- a file move/rename/merge only needs its PHP
      // registration updated, never a matching edit here. Array form,
      // not a keyed Record: PageAssets/ViteManifest resolve a built
      // asset by its real source path (see ViteManifest.php's own doc
      // comment), never by whatever alias Rollup used internally, so a
      // hand-picked chunk name buys nothing functionally.
      input: collectScriptEntries().map(r),
      output: {
        // P36's Piwigo\Asset\ViteManifest (reading manifest.json for
        // hashed filenames) exists now, but no template has migrated onto
        // it yet — everything except `vitals` still uses Piwigo's legacy
        // ScriptLoader/CssLoader combiner (docs/PLAN.md's P36 section).
        // `vitals` is resolved through ViteManifest from a fixed,
        // non-hashed filename rather than the default `[name]-[hash].js`;
        // an entry whose own basename collides with another real entry's
        // (see collidingBasenames's own docblock above) disambiguates via
        // disambiguatedName(); every other entry keeps Rollup's own
        // default `[name]-[hash].js`.
        entryFileNames: (chunk) => {
          if (chunk.name === "vitals") return "vitals.js";
          if (!collidingBasenames.has(chunk.name)) {
            return "assets/[name]-[hash].js";
          }
          return `assets/${disambiguatedName(chunk.facadeModuleId ?? chunk.name)}-[hash].js`;
        },
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
