// Real shared bundle entry for the real pages that only ever needed
// scripts.ts's own side effects, with no other file of their own to
// fold it into (docs/PLAN.md P48): register.php/password.php/
// identification.php (default theme's own non-`standard_pages` skin
// branch), profile.php (ProfileView's own default-theme branch), and
// comment_list.inc.latte's own conditional `U_DELETE` branch
// (CommentListView). One shared entry, not 4-5 near-identical ones --
// each real registrant page's own `AssetContribution::script()` call
// points at this same file/id; Rollup compiles it once regardless of
// how many pages load it (the same "one entry, many pages" shape
// scripts.ts's own former standalone entry already had). `?dup` since
// scripts.ts has several real registrant pages beyond this shared one
// too (Design §4).
import "../scripts";
