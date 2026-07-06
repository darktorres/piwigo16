import { defineConfig } from "vitest/config";

export default defineConfig({
  test: {
    environment: "happy-dom",
    include: ["tests/Unit/**/*.test.ts"],
    coverage: {
      provider: "v8",
    },
  },
});
