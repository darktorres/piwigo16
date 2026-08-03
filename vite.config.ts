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
  // confirmed live: a real `vite build` run filled the disk
  // (dist/dist/dist/... nested ~100 levels deep, ENOSPC) before this was
  // set. Nothing here needs Vite's copy step anyway: outDir already IS what
  // public/dist symlinks to, so the built assets are already reachable at
  // /dist/... with zero copying.
  publicDir: false,
  build: {
    outDir: "dist",
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        // Placeholder only — 68 real entries land in P30.
        noop: r("build/noop.ts"),
        // Real entry (docs/PLAN.md P1 gap, remediated post-P22) — web
        // Vitals RUM beacon, loaded on every page via footer.tpl.
        vitals: r("build/vitals.ts"),
      },
      output: {
        // P30's asset-manifest resolution (reading manifest.json for hashed
        // filenames) doesn't exist yet — everything else here still uses
        // Piwigo's legacy ScriptLoader/CssLoader combiner. `vitals` is
        // referenced directly from footer.tpl by a fixed path, so it needs a
        // stable, non-hashed filename rather than the default `[name]-
        // [hash].js`; every other entry keeps the default.
        entryFileNames: (chunk) =>
          chunk.name === "vitals" ? "vitals.js" : "assets/[name]-[hash].js",
      },
    },
  },
});
