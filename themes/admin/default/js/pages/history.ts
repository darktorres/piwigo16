// Real per-page bundle entry for history.php (docs/PLAN.md P48).
// datepicker.ts has 4 real registrant pages (docs/PLAN.md's own
// catalog), so its import must use the `?dup` suffix -- a plain import
// would make Rollup extract it into a shared chunk reachable from
// multiple entries, breaking every one of them (Design §4's mechanical
// addendum).
import "../datepicker";
