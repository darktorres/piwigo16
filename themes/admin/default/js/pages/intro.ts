// Real per-page bundle entry for admin.php?page=intro (docs/PLAN.md
// P48) -- Footer-mode script group. Both intro.ts and
// intro_tooltips.ts have exactly this one real registrant page, so no
// `?dup` suffix is needed (Design §4's mechanical addendum) -- plain
// imports, in the same relative order the original 2 separate script
// tags executed in.
import "../intro";
import "../intro_tooltips";
