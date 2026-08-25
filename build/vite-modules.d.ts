// Ambient module declarations for `vite.config.ts`'s own `?dup` query
// suffix (docs/PLAN.md P48) -- a real Vite plugin, not a genuine file
// on disk, so TypeScript has nothing to resolve otherwise. One pattern
// per real shared-library file, not one shared generic pattern -- a
// single generic `declare module "*?dup" {}` covering every real
// importer worked fine while every consumer only ever needed a bare,
// side-effect-only import (`import "./real-path?dup";`), but broke the
// instant album_selector.ts's own consumers needed real NAMED bindings
// (`import { AlbumSelector, ... } from "./album_selector?dup";`): with
// 2 wildcard patterns both matching the same specifier, `tsc` itself
// picks the first-declared one regardless of specificity (confirmed
// directly -- a `paths` remap in tsconfig.json, tried first, was
// rejected too: `paths` doesn't apply to relative specifiers at all in
// TS's own resolver), and typescript-eslint's own `projectService`
// picks the *generic* one regardless of declaration order (confirmed
// directly, a real, different-from-`tsc` inconsistency -- `eslint`
// reported every real `?dup` consumer's own `AlbumSelector` methods as
// "unsafe call/member access on an error type" even with the specific
// pattern declared first, which satisfies `tsc` alone). One
// non-overlapping pattern per real file sidesteps needing either tool
// to pick a "winner" between 2 patterns that could both match.
declare module "*addAlbum?dup" {}
declare module "*autosize?dup" {}
declare module "*datepicker?dup" {}
// Explicit named re-exports, not `export * from "...album_selector"`
// -- confirmed directly that a wildcard re-export silently exports
// nothing at all from inside an ambient `declare module` block (a real
// TS quirk/limitation, not a typo), while naming each real export here
// works. Keep this list in sync with album_selector.ts's own real
// exports. A non-wildcard, exact-literal-string declaration (one per
// real relative-path spelling actually used -- `./album_selector?dup`,
// `../../admin/default/js/album_selector?dup`) was tried first and
// rejected: `tsc` doesn't apply an ambient module declaration to a
// relative-looking specifier at all unless it's a wildcard pattern
// (`TS2307` even with an exact matching literal), confirmed directly.
declare module "*album_selector?dup" {
  export {
    AlbumSelector,
    str_albums_found,
    str_result_limit,
    str_album_found,
  } from "../themes/admin/default/js/album_selector";
}
declare module "*scripts?dup" {
  export {
    phpWGOpenWindow,
    pwgAddEventListener,
  } from "../themes/default/js/scripts";
}
declare module "*doubleSlider?dup" {}
declare module "*page-data?dup" {
  export {
    pwg_getPageData,
    pwg_getPageString,
  } from "../themes/default/js/page-data";
}
declare module "*switchbox?dup" {}
declare module "*albums?dup" {
  export { data } from "../themes/admin/default/js/albums";
}
declare module "*LocalStorageCache?dup" {
  export {
    CategoriesCache,
    TagsCache,
    GroupsCache,
    UsersCache,
  } from "../themes/admin/default/js/LocalStorageCache";
}
declare module "*common?dup" {
  export {
    getRandomInt,
    sprintf,
    jConfirm_alert_options,
    jConfirm_confirm_options,
    jConfirm_warning_options,
    TemporaryState,
  } from "../themes/admin/default/js/common";
}
