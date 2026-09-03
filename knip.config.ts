import type { KnipConfig } from "knip";
import { collectScriptEntries } from "./build/collectScriptEntries";

// Derived from real PHP AssetContribution::script() registrations
// (build/collectScriptEntries.ts, P51-B docs/PLAN.md) rather than
// hand-maintained -- a file move/rename/merge only needs its PHP
// registration updated, never a matching edit here.
// `openapi/client/index.ts` isn't a Vite bundle entry at all (no
// `AssetContribution` registers it) -- it's the separately
// `openapi-typescript`-generated API client, kept here only so knip
// doesn't flag it as unused.
const config: KnipConfig = {
  entry: [...collectScriptEntries(), "openapi/client/index.ts"],
  project: [
    "themes/**/*.ts",
    "tests/Unit/*.test.ts",
    "*.config.ts",
    "openapi/client/*.ts",
  ],
  ignoreDependencies: ["@cyclonedx/cyclonedx-npm", "lefthook"],
};

export default config;
