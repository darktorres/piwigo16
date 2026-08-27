// Real per-page bundle entry for batch_manager_unit.php (docs/PLAN.md
// P48). autosize.ts/datepicker.ts/scripts.ts each have several real
// registrant pages (docs/PLAN.md's own catalog), so their imports must
// use the `?dup` suffix -- a plain import would make Rollup extract
// them into a shared chunk reachable from multiple entries, breaking
// every one of them (Design §4's mechanical addendum). scripts.ts's
// import replaces the separate `core.scripts` script tag
// BatchManagerUnitView used to register directly -- this page doesn't
// read any of scripts.ts's own real exports, just needs its side
// effects.
import "../autosize";
import "../datepicker";
import "../../../../default/js/scripts";
