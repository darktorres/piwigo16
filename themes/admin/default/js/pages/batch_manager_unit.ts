// Real per-page bundle entry for batch_manager_unit.php (docs/PLAN.md
// P48). autosize.ts/scripts.ts each have several real registrant
// pages, so Rollup emits each as a shared chunk that every consuming
// entry imports. scripts.ts's
// import replaces the separate `core.scripts` script tag
// BatchManagerUnitView used to register directly -- this page doesn't
// read any of scripts.ts's own real exports, just needs its side
// effects. `vendor/widgets/datepicker.ts` (P49-B, native port) is imported
// directly by `batch_manager/unit.ts` itself now, not registered as a
// separate side-effect import here.
import "../autosize";
import "../../../../default/js/scripts";
