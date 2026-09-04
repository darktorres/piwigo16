import "../common";
import { GroupsCache, UsersCache } from "../LocalStorageCache";

import { pwg_getPageData } from "../../../../default/js/pageData";
import {
  hide,
  on,
  show,
  toggle,
  val,
} from "../../../../default/js/vendor/utils/dom";

(function () {
  // <!-- GROUPS -->
  const groupsCache = new GroupsCache({
    serverKey: pwg_getPageData<string>("cache_key_groups"),
    serverId: pwg_getPageData<string>("cache_key_hash"),
    rootUrl: pwg_getPageData<string>("root_url"),
  });

  groupsCache.selectize(document.querySelectorAll("[data-selectize=groups]"));

  // <!-- USERS -->
  const usersCache = new UsersCache({
    serverKey: pwg_getPageData<string>("cache_key_users"),
    serverId: pwg_getPageData<string>("cache_key_hash"),
    rootUrl: pwg_getPageData<string>("root_url"),
  });

  usersCache.selectize(document.querySelectorAll("[data-selectize=users]"));

  // <!-- TOGGLES -->
  function checkStatusOptions() {
    // `:checked` is a real CSS pseudo-class, unlike the jQuery-only ones
    // (`:visible`/`:input`/`:first`) querySelectorAll throws on, so this
    // selector carries over unchanged. With no radio checked the set is
    // empty and val() returns undefined, matching jQuery.
    if (
      val(document.querySelectorAll("input[name=status]:checked")) === "private"
    ) {
      show(document.querySelectorAll("#privateOptions"));
    } else {
      hide(document.querySelectorAll("#privateOptions"));
    }
  }

  checkStatusOptions();
  on(document.querySelectorAll("#selectStatus"), "change", function (): void {
    checkStatusOptions();
  });

  on(
    document.querySelectorAll(".toggle-indirectPermissions"),
    "click",
    function (e: Event): void {
      // Both links carry the class, and each is toggled against its OWN
      // current visibility -- the pair swaps rather than both hiding.
      toggle(document.querySelectorAll(".toggle-indirectPermissions"));
      toggle(document.querySelectorAll("#indirectPermissionsDetails"));
      e.preventDefault();
    },
  );
})();
