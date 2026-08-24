// Shared ambient types for vendored jQuery-plugin methods that
// @types/jquery doesn't cover (docs/PLAN.md P46). `JQueryStatic`/`JQuery`
// are already global interfaces (see node_modules/@types/jquery), so
// merging into them here needs no `declare global` wrapper -- this file
// has no top-level import/export of its own.
//
// Also the home for genuinely first-party (non-jQuery-plugin) shared
// ambient globals, when a real one turns up during conversion (P46-B
// found window.SwitchBox needs exactly this).
//
// Starts empty -- grows one method/global at a time as each conversion
// batch's real call sites need it, rather than guessing the full list
// up front.
