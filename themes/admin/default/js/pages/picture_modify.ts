// Real per-page bundle entry for picture_modify.php (docs/PLAN.md
// P48). autosize.ts/datepicker.ts each have several real registrant
// pages (docs/PLAN.md's own catalog), so their imports must use the
// `?dup` suffix -- a plain import would make Rollup extract them into
// a shared chunk reachable from multiple entries, breaking every one
// of them (Design §4's mechanical addendum).
import "../autosize?dup";
import "../datepicker?dup";
