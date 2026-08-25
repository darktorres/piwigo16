// Real per-page bundle entry for batch_manager_global.php (docs/PLAN.md
// P48). Both real shared-library files this page ever registered
// separately (batchManagerGlobal.ts, batch_manager_global.ts) now fold
// into this one Footer-mode bundle -- see both files' own leading
// comments for why they can't stay 2 separate (page × LoadMode)
// entries the way most pages' shared-library files do.
//
// Import order is load-bearing, twice over:
// - datepicker.ts must be entered before batchManagerGlobal.ts: the
//   latter's own top-level, synchronous `jQuery("[data-datepicker]")
//   .pwgDatepicker(...)` call needs that plugin already registered,
//   replicating the real script-tag `dependsOn` ordering this page
//   used before this batch (datepicker.ts had 4 real registrant pages,
//   so its own import needs the `?dup` suffix -- Design §4).
// - batchManagerGlobal.ts must be entered before batch_manager_global.ts
//   for its own top-level, synchronous `lang.Cancel` read to see
//   `lang` already set -- its own circular import of
//   batch_manager_global.ts forces that, but only because this file
//   enters via batchManagerGlobal.ts first (see that file's own
//   leading comment for the full analysis).
// Do not reorder without re-verifying both.
import "../addAlbum?dup";
import "../datepicker?dup";
// scripts.ts also has several real registrant pages (`?dup` needed --
// Design §4); this import replaces the separate `core.scripts` script
// tag BatchManagerGlobalView used to register directly. This page
// doesn't read any of scripts.ts's own real exports, just needs its
// side effects, so its own ordering relative to the other imports here
// isn't load-bearing.
import "../../../../default/js/scripts?dup";
import "../batchManagerGlobal";
import "../batch_manager_global";
