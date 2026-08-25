// Declarer of the 22 real symbols plugins_installated.ts imports (real
// export/import now, docs/PLAN.md P48 -- was window-global latching,
// see this file's own history for the pre-P48 shape). Both files fold
// into one real page bundle (themes/admin/default/js/pages/*.ts) as
// part of this same batch.

/* incompatible message */
export const incompatible_msg = pwg_getPageString(
  "WARNING! This plugin does not seem to be compatible with this version of Piwigo.",
);
export const activate_msg =
  "\n" + pwg_getPageString("Do you want to activate anyway?");
export const deactivate_all_msg = pwg_getPageString("Deactivate all");

/* group action */
export const pwg_token = pwg_getPageData<string>("csrf_token");
interface CountTypesPlugins {
  active: number;
  inactive: number;
  missing: number;
  merged: number;
}
const count_types_plugins = pwg_getPageData<CountTypesPlugins>(
  "count_types_plugins",
);
export const nb_plugin = {
  all:
    count_types_plugins.active +
    count_types_plugins.inactive +
    count_types_plugins.missing +
    count_types_plugins.merged,
  active: count_types_plugins.active,
  inactive: count_types_plugins.inactive,
  other: count_types_plugins.missing + count_types_plugins.merged,
};
export const confirm_msg = pwg_getPageString("Yes, I am sure");
export const cancel_msg = pwg_getPageString("No, I have changed my mind");
export const delete_plugin_msg = pwg_getPageString(
  'Are you sure you want to delete the plugin "%s"?',
);
export const deleted_plugin_msg = pwg_getPageString('Plugin "%s" deleted!');
export const restore_plugin_msg = pwg_getPageString(
  'Are you sure you want to restore the plugin "%s"?',
);
export const uninstall_plugin_msg = pwg_getPageString(
  'Are you sure you want to uninstall the plugin "%s"?',
);
export const plugin_added_str = pwg_getPageString("Activated");
export const plugin_deactivated_str = pwg_getPageString("Deactivated");
export const plugin_restored_str = pwg_getPageString("Restored");
export const plugin_action_error = pwg_getPageString("an error happened");
export const not_webmaster = pwg_getPageString("Webmaster status required");
export const nothing_found = pwg_getPageString("No plugins found");
export const x_plugins_found = pwg_getPageString("%s plugins found");
export const plugin_found = pwg_getPageString("%s plugin found");
export const isWebmaster = pwg_getPageData<number>("is_webmaster");
export const str_restore_def = pwg_getPageString(
  "While restoring this plugin, it will be reset to its original parameters and associated data is going to be reset",
);

export const show_details = pwg_getPageData<boolean>("show_details");

const searchParams = new URLSearchParams(window.location.search);
export const plugin_filter = searchParams.get("filter");
