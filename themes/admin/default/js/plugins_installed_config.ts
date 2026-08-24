// Declarer of the ~22 globals plugins_installated.ts reads bare
// (docs/PLAN.md P46-C's full sweep) -- stays a real global script (no
// `export {}`), matching the "config companion" pattern already
// established for search_filters.ts/mcs.js (P46-B). Every one of these
// real `var` declarations makes plugins_installated.ts's own earlier
// `declare const` ambient bindings in build/jquery-plugins.d.ts
// redundant for *type-checking* -- removed there in this same commit
// (matches the `derivatives` precedent: a real declaration in the
// shared type-checking program makes an ambient one both unnecessary
// and, for `let`/`const`, an outright conflict).
//
// That's the compile-time half only. At *runtime* this file's own
// declarations need the exact same explicit `window.X = X` exposure
// every other P46 declarer file already carries (common.ts,
// batchManagerGlobal.ts's own `derivatives`/etc.) -- real, found only
// by inspecting the real compiled `vite build` output: every P46 entry
// gets wrapped in its own IIFE (vite.config.ts's banner/footer), so a
// bare `var` here is scoped to THIS file's own wrapper, invisible to
// plugins_installated.ts's own separately-wrapped bare reads, unless
// explicitly attached to the one shared object both wrappers can see
// -- `window`. This was safe to skip before this file converted (the
// original, unwrapped `.js` really was a plain global script), and
// became a real latent bug the moment this file's own conversion
// wrapped it -- caught before landing, not after, by checking the
// compiled output directly rather than trusting typecheck/lint alone.

/* incompatible message */
const incompatible_msg = pwg_getPageString(
  "WARNING! This plugin does not seem to be compatible with this version of Piwigo.",
);
const activate_msg =
  "\n" + pwg_getPageString("Do you want to activate anyway?");
const deactivate_all_msg = pwg_getPageString("Deactivate all");

/* group action */
// `pwg_token`/`confirm_msg`/`cancel_msg` genuinely need `var`, not
// `const`: also independently declared per-page in other, unrelated
// config-companion files (e.g. albums.ts once converted) -- `var`
// allows unlimited redeclaration of the same name in the shared
// type-checking program, `const` would conflict with a sibling file's
// own `var`/`const` declaration of the same name outright (a real
// TS2451 error, confirmed while landing this file).
// eslint-disable-next-line no-var
var pwg_token = pwg_getPageData("csrf_token");
const count_types_plugins = pwg_getPageData("count_types_plugins");
const nb_plugin = {
  all:
    count_types_plugins.active +
    count_types_plugins.inactive +
    count_types_plugins.missing +
    count_types_plugins.merged,
  active: count_types_plugins.active,
  inactive: count_types_plugins.inactive,
  other: count_types_plugins.missing + count_types_plugins.merged,
};
// eslint-disable-next-line no-var
var confirm_msg = pwg_getPageString("Yes, I am sure");
// eslint-disable-next-line no-var
var cancel_msg = pwg_getPageString("No, I have changed my mind");
const delete_plugin_msg = pwg_getPageString(
  'Are you sure you want to delete the plugin "%s"?',
);
const deleted_plugin_msg = pwg_getPageString('Plugin "%s" deleted!');
const restore_plugin_msg = pwg_getPageString(
  'Are you sure you want to restore the plugin "%s"?',
);
const uninstall_plugin_msg = pwg_getPageString(
  'Are you sure you want to uninstall the plugin "%s"?',
);
const plugin_added_str = pwg_getPageString("Activated");
const plugin_deactivated_str = pwg_getPageString("Deactivated");
const plugin_restored_str = pwg_getPageString("Restored");
const plugin_action_error = pwg_getPageString("an error happened");
const not_webmaster = pwg_getPageString("Webmaster status required");
const nothing_found = pwg_getPageString("No plugins found");
const x_plugins_found = pwg_getPageString("%s plugins found");
const plugin_found = pwg_getPageString("%s plugin found");
const isWebmaster = pwg_getPageData("is_webmaster");
const str_restore_def = pwg_getPageString(
  "While restoring this plugin, it will be reset to its original parameters and associated data is going to be reset",
);

const show_details = pwg_getPageData("show_details");

const searchParams = new URLSearchParams(window.location.search);
const plugin_filter = searchParams.get("filter");

// Explicit `window.` exposure -- required at runtime, not decorative
// (see this file's own leading comment for the full explanation).
// plugins_installated.ts reads every one of these bare.
window.incompatible_msg = incompatible_msg;
window.activate_msg = activate_msg;
window.deactivate_all_msg = deactivate_all_msg;
window.pwg_token = pwg_token;
window.nb_plugin = nb_plugin;
window.confirm_msg = confirm_msg;
window.cancel_msg = cancel_msg;
window.delete_plugin_msg = delete_plugin_msg;
window.deleted_plugin_msg = deleted_plugin_msg;
window.restore_plugin_msg = restore_plugin_msg;
window.uninstall_plugin_msg = uninstall_plugin_msg;
window.plugin_added_str = plugin_added_str;
window.plugin_deactivated_str = plugin_deactivated_str;
window.plugin_restored_str = plugin_restored_str;
window.plugin_action_error = plugin_action_error;
window.not_webmaster = not_webmaster;
window.nothing_found = nothing_found;
window.x_plugins_found = x_plugins_found;
window.plugin_found = plugin_found;
window.isWebmaster = isWebmaster;
window.str_restore_def = str_restore_def;
window.show_details = show_details;
window.plugin_filter = plugin_filter;
