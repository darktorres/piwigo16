// Ambient module declaration for `vite.config.ts`'s own `?dup` query
// suffix (docs/PLAN.md P48) -- a real Vite plugin, not a genuine file
// on disk, so TypeScript has nothing to resolve otherwise. Always a
// bare, side-effect-only import (`import "./real-path?dup";`), never
// a value import, so an empty module body is correct. Fixed suffix,
// no wildcard-in-the-middle tag -- TypeScript's own wildcard module
// patterns only support a single `*`, confirmed directly (the
// originally-tried `"*?dup=*"` shape left every real usage as a real
// `tsc` error).
declare module "*?dup" {}
