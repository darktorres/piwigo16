// Real per-page bundle entry for configuration.php&page=default (docs/
// PLAN.md P48) -- this page's ONLY first-party JS need is common.ts's
// own side effects (font-checkbox init, search-cancel bindings); it has
// no page-specific .ts of its own to fold that import into instead.
import "../common?dup";
