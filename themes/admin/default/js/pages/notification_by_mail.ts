// Real per-page bundle entry for notification_by_mail.php (docs/
// PLAN.md P48). autosize.ts has 3 real registrant pages (docs/PLAN.md's
// own catalog), so its import must use the `?dup` suffix -- a plain
// import would make Rollup extract it into a shared chunk reachable
// from 3 entries, breaking every one of them (Design §4's mechanical
// addendum).
import "../autosize";
