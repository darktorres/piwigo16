// Real per-page bundle entry for batch_manager_global.php (docs/PLAN.md
// P48). The two real shared-library files this page ever registered
// separately (batchManagerGlobal.ts, batch_manager/global.ts) folded
// into this one Footer-mode bundle first (P48), then merged into a
// single real file (P51-I item 2, `batch_manager/global.ts`'s own
// leading comment has the full analysis of the ordering hazards that
// merge removed).
//
// scripts.ts also has several real registrant pages (
// Design §4); this import replaces the separate `core.scripts` script
// tag BatchManagerGlobalView used to register directly. This page
// doesn't read any of scripts.ts's own real exports, just needs its
// side effects, so its own ordering relative to the other imports here
// isn't load-bearing.
import "../../../../default/js/scripts";
import "../batch_manager/global";
