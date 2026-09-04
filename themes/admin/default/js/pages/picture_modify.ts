// Real per-page bundle entry for picture_modify.php (docs/PLAN.md
// P48). autosize.ts has several real registrant pages, so Rollup emits
// it as a shared chunk that every consuming entry imports.
// `vendor/widgets/datepicker.ts` (P49-B, native port) is imported directly by
// `pictureModify.ts` itself now, not registered as a separate
// side-effect import here.
import "../autosize";
