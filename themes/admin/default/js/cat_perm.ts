import "./common?dup";
import { GroupsCache, UsersCache } from "./LocalStorageCache?dup";

import { pwg_getPageData } from "../../../default/js/page-data?dup";
export {};
(function () {
  // <!-- GROUPS -->
  const groupsCache = new GroupsCache({
    serverKey: pwg_getPageData<string>("cache_key_groups"),
    serverId: pwg_getPageData<string>("cache_key_hash"),
    rootUrl: pwg_getPageData<string>("root_url"),
  });

  groupsCache.selectize(jQuery("[data-selectize=groups]"));

  // <!-- USERS -->
  const usersCache = new UsersCache({
    serverKey: pwg_getPageData<string>("cache_key_users"),
    serverId: pwg_getPageData<string>("cache_key_hash"),
    rootUrl: pwg_getPageData<string>("root_url"),
  });

  usersCache.selectize(jQuery("[data-selectize=users]"));

  // <!-- TOGGLES -->
  function checkStatusOptions() {
    if (jQuery("input[name=status]:checked").val() === "private") {
      jQuery("#privateOptions").show();
    } else {
      jQuery("#privateOptions").hide();
    }
  }

  checkStatusOptions();
  jQuery("#selectStatus").change(function () {
    checkStatusOptions();
  });

  jQuery(".toggle-indirectPermissions").click(function (e) {
    jQuery(".toggle-indirectPermissions").toggle();
    jQuery("#indirectPermissionsDetails").toggle();
    e.preventDefault();
  });
})();
