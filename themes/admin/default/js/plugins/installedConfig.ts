// Declarer of the 22 real symbols plugins/installed.ts imports (real
// export/import now, docs/PLAN.md P48 -- was window-global latching,
// see this file's own history for the pre-P48 shape). Both files fold
// into one real page bundle (themes/admin/default/js/pages/*.ts) as
// part of this same batch.
import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../../default/js/pageData";

/* incompatible message */
export const incompatibleMsg = pwg_getPageString(
  "WARNING! This plugin does not seem to be compatible with this version of Piwigo.",
);
// Dialog *content* now, paired with incompatibleMsg as the title (see
// plugins/installed.ts's own incompatible-plugin activation guard). The
// leading "\n" it used to carry only made sense while this was concatenated
// onto a title string, where it rendered as nothing anyway.
export const activateMsg = pwg_getPageString("Do you want to activate anyway?");

/* group action */
export const pwgToken = pwg_getPageData<string>("csrf_token");
interface CountTypesPlugins {
  active: number;
  inactive: number;
  missing: number;
  merged: number;
}
const countTypesPlugins = pwg_getPageData<CountTypesPlugins>(
  "count_types_plugins",
);
export const nbPlugin = {
  all:
    countTypesPlugins.active +
    countTypesPlugins.inactive +
    countTypesPlugins.missing +
    countTypesPlugins.merged,
  active: countTypesPlugins.active,
  inactive: countTypesPlugins.inactive,
  other: countTypesPlugins.missing + countTypesPlugins.merged,
};
export const confirmMsg = pwg_getPageString("Yes, I am sure");
export const cancelMsg = pwg_getPageString("No, I have changed my mind");
export const deletePluginMsg = pwg_getPageString(
  'Are you sure you want to delete the plugin "%s"?',
);
export const deletedPluginMsg = pwg_getPageString('Plugin "%s" deleted!');
export const restorePluginMsg = pwg_getPageString(
  'Are you sure you want to restore the plugin "%s"?',
);
export const uninstallPluginMsg = pwg_getPageString(
  'Are you sure you want to uninstall the plugin "%s"?',
);
export const pluginAddedStr = pwg_getPageString("Activated");
export const pluginDeactivatedStr = pwg_getPageString("Deactivated");
export const pluginRestoredStr = pwg_getPageString("Restored");
export const pluginActionError = pwg_getPageString("an error happened");
export const notWebmaster = pwg_getPageString("Webmaster status required");
export const nothingFound = pwg_getPageString("No plugins found");
export const xPluginsFound = pwg_getPageString("%s plugins found");
export const pluginFound = pwg_getPageString("%s plugin found");
export const isWebmaster = pwg_getPageData<number>("is_webmaster");
export const strRestoreDef = pwg_getPageString(
  "While restoring this plugin, it will be reset to its original parameters and associated data is going to be reset",
);

export const showDetails = pwg_getPageData<boolean>("show_details");

const searchParams = new URLSearchParams(window.location.search);
export const pluginFilter = searchParams.get("filter");
