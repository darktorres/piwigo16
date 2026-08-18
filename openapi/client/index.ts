import createClient from "openapi-fetch";
import type { paths } from "./schema";

/**
 * Typed client for this project's own `/api/v1` REST surface, driven
 * entirely by `schema.d.ts` (generated from `openapi/openapi.yaml` via
 * `bun run generate:api-client`) -- regenerating the schema after a spec
 * change is the only sync step, no per-operation wrapper to hand-maintain.
 *
 * `baseUrl` matches the spec's own `servers[0].url` (`/api/v1`), so paths
 * are passed relative to it (e.g. `apiClient.GET("/version")`), not as
 * full `/api/v1/...` strings.
 */
export function createApiClient(baseUrl: string) {
  return createClient<paths>({ baseUrl });
}

export type { paths };
