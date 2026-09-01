// Real per-page bundle entry for batch_manager_global.php (docs/PLAN.md
// P48). Both real shared-library files this page ever registered
// separately (batchManagerGlobal.ts, batch_manager_global.ts) now fold
// into this one Footer-mode bundle -- see both files' own leading
// comments for why they can't stay 2 separate (page × LoadMode)
// entries the way most pages' shared-library files do.
//
// Import order is load-bearing:
// - batchManagerGlobal.ts must be entered before batch_manager_global.ts
//   for its own top-level, synchronous `lang.Cancel` read to see
//   `lang` already set -- its own circular import of
//   batch_manager_global.ts forces that, but only because this file
//   enters via batchManagerGlobal.ts first (see that file's own
//   leading comment for the full analysis).
// Do not reorder without re-verifying both.
//
// No separate `addAlbum.ts` import here anymore (P49-B, colorbox):
// `batchManagerGlobal.ts` now imports its own `{ pwgAddAlbum }` binding
// directly, and standard ESM dependency evaluation already guarantees
// that runs before this file's own subsequent code -- the old bare
// side-effect import (needed only for `jQuery.fn.pwgAddAlbum`'s global
// registration) would just be a redundant second resolution of the
// same module. Same reasoning now applies to `vendor/datepicker.ts`'s
// own `{ pwgDatepicker }` (P49-B, datepicker): `batchManagerGlobal.ts`
// imports it directly and only calls it inside a `.ready()` callback,
// so no separate side-effect import or load-order constraint remains
// here either.
// scripts.ts also has several real registrant pages (
// Design §4); this import replaces the separate `core.scripts` script
// tag BatchManagerGlobalView used to register directly. This page
// doesn't read any of scripts.ts's own real exports, just needs its
// side effects, so its own ordering relative to the other imports here
// isn't load-bearing.
import "../../../../default/js/scripts";
import "../batchManagerGlobal";
import "../batch_manager_global";
