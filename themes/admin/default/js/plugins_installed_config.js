/* incompatible message */
var incompatible_msg = pwg_getPageString('WARNING! This plugin does not seem to be compatible with this version of Piwigo.');
var activate_msg = '\n' + pwg_getPageString('Do you want to activate anyway?');
var deactivate_all_msg = pwg_getPageString('Deactivate all');

/* group action */
var pwg_token = pwg_getPageData('csrf_token');
var count_types_plugins = pwg_getPageData('count_types_plugins');
var nb_plugin = {
  'all' : count_types_plugins.active + count_types_plugins.inactive + count_types_plugins.missing + count_types_plugins.merged,
  'active' : count_types_plugins.active,
  'inactive' : count_types_plugins.inactive,
  'other' : count_types_plugins.missing + count_types_plugins.merged,
};
var confirm_msg = pwg_getPageString('Yes, I am sure');
var cancel_msg = pwg_getPageString('No, I have changed my mind');
var delete_plugin_msg = pwg_getPageString('Are you sure you want to delete the plugin "%s"?');
var deleted_plugin_msg = pwg_getPageString('Plugin "%s" deleted!');
var restore_plugin_msg = pwg_getPageString('Are you sure you want to restore the plugin "%s"?');
var uninstall_plugin_msg = pwg_getPageString('Are you sure you want to uninstall the plugin "%s"?');
var plugin_added_str = pwg_getPageString('Activated');
var plugin_deactivated_str = pwg_getPageString('Deactivated');
var plugin_restored_str = pwg_getPageString('Restored');
var plugin_action_error = pwg_getPageString('an error happened');
var not_webmaster = pwg_getPageString('Webmaster status required');
var nothing_found = pwg_getPageString('No plugins found');
var x_plugins_found = pwg_getPageString('%s plugins found');
var plugin_found = pwg_getPageString('%s plugin found');
var isWebmaster = pwg_getPageData('is_webmaster');
var str_restore_def = pwg_getPageString('While restoring this plugin, it will be reset to its original parameters and associated data is going to be reset');

var show_details = pwg_getPageData('show_details');

var searchParams = new URLSearchParams(window.location.search);
var plugin_filter = searchParams.get('filter');
