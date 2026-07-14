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
  build: {
    outDir: "dist",
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        // Placeholder only — 68 real entries land in P24.
        noop: r("build/noop.ts"),
        // Real entry (docs/PLAN-REPLAY.md P1 gap, remediated post-P22) — web
        // Vitals RUM beacon, loaded on every page via footer.tpl.
        vitals: r("build/vitals.ts"),
      },
      output: {
        // P24's asset-manifest resolution (reading manifest.json for hashed
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
