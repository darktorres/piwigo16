// Real per-page bundle entry for batch_manager_global.php (docs/PLAN.md
// P48). Both real shared-library files this page ever registered
// separately (batchManagerGlobal.ts, batch_manager_global.ts) now fold
// into this one Footer-mode bundle -- see both files' own leading
// comments for why they can't stay 2 separate (page × LoadMode)
// entries the way most pages' shared-library files do. Import order is
// load-bearing: batchManagerGlobal.ts's own top-level, synchronous
// `lang.Cancel` read requires batch_manager_global.ts's module (which
// sets `lang`) to already be fully evaluated by then -- its own
// circular import of batch_manager_global.ts forces that, but only
// because this file enters via batchManagerGlobal.ts first. Do not
// reorder without re-verifying (see batchManagerGlobal.ts's own leading
// comment for the full analysis).
import "../addAlbum?dup";
import "../batchManagerGlobal";
import "../batch_manager_global";
