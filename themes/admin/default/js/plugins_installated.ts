// Consumer of themes/admin/default/js/plugins_installed_config.ts's own
// real exports now (docs/PLAN.md P48 -- was ambient window-global
// latching). jConfirm_alert_options/jConfirm_confirm_options now import
// from common.ts too (its own P48 batch landed).
import {
  jConfirm_alert_options,
  jConfirm_confirm_options,
  jConfirm_confirm_with_content_options,
} from "./common";
import {
  activate_msg,
  cancel_msg,
  confirm_msg,
  delete_plugin_msg,
  deleted_plugin_msg,
  incompatible_msg,
  isWebmaster,
  nb_plugin,
  not_webmaster,
  nothing_found,
  plugin_action_error,
  plugin_added_str,
  plugin_deactivated_str,
  plugin_filter,
  plugin_found,
  plugin_restored_str,
  pwg_token,
  restore_plugin_msg,
  show_details,
  str_restore_def,
  uninstall_plugin_msg,
  x_plugins_found,
} from "./plugins_installed_config";
import { ajax } from "../../../default/js/vendor/ajax";
function setDisplayClassic() {
  $(".pluginContainer")
    .removeClass("line-form")
    .removeClass("compact-form")
    .addClass("classic-form");

  $(".pluginDesc").show();
  $(".pluginActions").show();
  $(".pluginActionsSmallIcons").hide();

  $(".pluginName").removeClass("pluginNameCompact");
}

function setDisplayCompact() {
  $(".pluginContainer")
    .removeClass("line-form")
    .addClass("compact-form")
    .removeClass("classic-form");

  $(".pluginDesc").hide();
  $(".pluginActions").hide();
  $(".pluginActionsSmallIcons").show();

  $(".pluginName").addClass("pluginNameCompact");
}

function setDisplayLine() {
  $(".pluginContainer")
    .addClass("line-form")
    .removeClass("compact-form")
    .removeClass("classic-form");

  $(".pluginDesc").show();
  $(".pluginActions").show();
  $(".pluginActionsSmallIcons").hide();
}

function activatePlugin(id: string) {
  $("#" + id + " .switch").prop("disabled", true);

  void ajax({
    type: "POST",
    dataType: "json",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    url: "api/v1/plugins/" + id + "/actions/perform",
    data: JSON.stringify({ action: "activate" }),
    // 204 No Content -- pluginPerformAction's real response has no body.
    success: function (_data: unknown) {
      $("#" + id + " .pluginNotif").stop(false, true);
      $("#" + id + " .AddPluginSuccess label span:first").html(
        plugin_added_str,
      );
      $("#" + id + " .AddPluginSuccess").css("display", "flex");

      nb_plugin.active += 1;
      nb_plugin.inactive -= 1;
      actualizeFilter();
    },
    error: function (e) {
      console.log(e.responseText);
      $("#" + id + " .pluginNotif").stop(false, true);
      $("#" + id + " .PluginActionError label span:first").html(
        plugin_action_error,
      );
      $("#" + id + " .PluginActionError").css("display", "flex");
      $("#" + id + " .PluginActionError")
        .delay(1500)
        .fadeOut(2500);
    },
  }).done(function (_data: unknown) {
    $("#" + id + " .switch").prop("disabled", false);
    $("#" + id + " .AddPluginSuccess").fadeOut(3000);
  });
}

/**
 * The DOM half of activating a plugin, split out of the switch handler so
 * the confirm-first path below can reuse it verbatim rather than duplicate
 * the class bookkeeping.
 */
function applyActivation(row: JQuery) {
  activatePlugin(row.attr("id")!);

  row.addClass("plugin-active").removeClass("plugin-inactive");
  if (row.find(".pluginUnavailableAction").attr("href")) {
    row
      .find(".pluginUnavailableAction")
      .removeClass("pluginUnavailableAction")
      .addClass("pluginActionLevel1");
  }
}

/**
 * The switch has already flipped by the time `change` fires, so anything
 * short of confirming has to put it back.
 *
 * That revert hangs off `onClose`, not the cancel button's own action:
 * jConfirm_confirm_with_content_options sets `backgroundDismiss: true`, and
 * dismissing by backdrop click or Esc never runs the cancel action -- the
 * switch would have been left reading "active" for a plugin that was never
 * activated. `onClose` fires for every dismissal path, so the `confirmed`
 * flag is what distinguishes them.
 */
function confirmIncompatibleActivation(toggle: JQuery, row: JQuery) {
  let confirmed = false;

  $.confirm({
    title: incompatible_msg,
    content: activate_msg,
    buttons: {
      confirm: {
        text: confirm_msg,
        btnClass: "btn-red",
        action: function () {
          confirmed = true;
          applyActivation(row);
          actualizeFilter();
        },
      },
      cancel: {
        text: cancel_msg,
      },
    },
    onClose: function () {
      if (!confirmed) {
        toggle.prop("checked", false);
      }
    },
    ...jConfirm_confirm_with_content_options,
  });
}

function disactivatePlugin(id: string) {
  $("#" + id + " .switch").prop("disabled", true);

  void ajax({
    type: "POST",
    dataType: "json",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    url: "api/v1/plugins/" + id + "/actions/perform",
    data: JSON.stringify({ action: "deactivate" }),
    // 204 No Content -- pluginPerformAction's real response has no body.
    success: function (_data: unknown) {
      $("#" + id + " .pluginNotif").stop(false, true);
      $("#" + id + " .DeactivatePluginSuccess label span:first").html(
        plugin_deactivated_str,
      );
      $("#" + id + " .DeactivatePluginSuccess").css("display", "flex");

      nb_plugin.inactive += 1;
      nb_plugin.active -= 1;
      actualizeFilter();
    },
    error: function (e) {
      console.log(e);
      $("#" + id + " .pluginNotif").stop(false, true);
      $("#" + id + " .PluginActionError label span:first").html(
        plugin_action_error,
      );
      $("#" + id + " .PluginActionError").css("display", "flex");
      $("#" + id + " .PluginActionError")
        .delay(1500)
        .fadeOut(2500);
    },
  }).done(function (_data: unknown) {
    $("#" + id + " .switch").prop("disabled", false);
    $("#" + id + " .DeactivatePluginSuccess").fadeOut(3000);
  });
}

function deletePlugin(id: string, name: string) {
  $.alert({
    title: deleted_plugin_msg.replace("%s", name),
    content: function () {
      return ajax({
        type: "POST",
        dataType: "json",
        contentType: "application/json",
        headers: { "X-CSRF-Token": pwg_token },
        url: "api/v1/plugins/" + id + "/actions/perform",
        data: JSON.stringify({ action: "delete" }),
        // 204 No Content -- pluginPerformAction's real response has no body.
        success: function (_data: unknown) {
          $("#" + id).remove();
          nb_plugin.inactive -= 1;
          nb_plugin.all -= 1;
          actualizeFilter();
        },
        error: function (e) {
          console.log(e);
          $("#" + id + " .pluginNotif").stop(false, true);
          $("#" + id + " .PluginActionError label span:first").html(
            plugin_action_error,
          );
          $("#" + id + " .PluginActionError").css("display", "flex");
          $("#" + id + " .PluginActionError")
            .delay(1500)
            .fadeOut(2500);
        },
      });
    },
    ...jConfirm_alert_options,
  });
}

function restorePlugin(id: string) {
  void ajax({
    type: "POST",
    dataType: "json",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    url: "api/v1/plugins/" + id + "/actions/perform",
    data: JSON.stringify({ action: "restore" }),
    // 204 No Content -- pluginPerformAction's real response has no body.
    success: function (_data: unknown) {
      $("#" + id + " .pluginNotif").stop(false, true);
      $("#" + id + " .RestorePluginSuccess label span:first").html(
        plugin_restored_str,
      );
      $("#" + id + " .RestorePluginSuccess").css("display", "flex");
    },
    error: function (e) {
      console.log(e);
      $("#" + id + " .pluginNotif").stop(false, true);
      $("#" + id + " .PluginActionError label span:first").html(
        plugin_action_error,
      );
      $("#" + id + " .PluginActionError").css("display", "flex");
      $("#" + id + " .PluginActionError")
        .delay(1500)
        .fadeOut(2500);
    },
  }).done(function (_data: unknown) {
    $("#" + id + " .RestorePluginSuccess").fadeOut(3000);
  });
}

function uninstallPlugin(id: string) {
  void ajax({
    type: "POST",
    dataType: "json",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    url: "api/v1/plugins/" + id + "/actions/perform",
    data: JSON.stringify({ action: "uninstall" }),
    // 204 No Content -- pluginPerformAction's real response has no body.
    success: function (_data: unknown) {
      $("#" + id).remove();
      nb_plugin.other -= 1;
      nb_plugin.all -= 1;
      actualizeFilter();
    },
    error: function (e) {
      $("#" + id + " .pluginNotif").stop(false, true);
      $("#" + id + " .PluginActionError label span:first").html(
        plugin_action_error,
      );
      $("#" + id + " .PluginActionError").css("display", "flex");
      $("#" + id + " .PluginActionError")
        .delay(1500)
        .fadeOut(2500);
      // Was `e.message` -- jqXHR has no such property (confirmed via
      // @types/jquery's own jqXHR interface); the real server error body
      // is JSON on `.responseText`, matching activatePlugin's own
      // sibling error handler above.
      console.log(e.responseText);
    },
  });
}

$(document).ready(function () {
  actualizeFilter();

  if ($("#displayClassic").is(":checked")) {
    setDisplayClassic();
  }

  if ($("#displayCompact").is(":checked")) {
    setDisplayCompact();
  }

  if ($("#displayLine").is(":checked")) {
    setDisplayLine();
  }

  $("#displayClassic").change(function () {
    setDisplayClassic();
    set_view_selector("classic");
  });

  $("#displayCompact").change(function () {
    setDisplayCompact();
    set_view_selector("compact");
  });

  $("#displayLine").change(function () {
    setDisplayLine();
    set_view_selector("line");
  });

  /* Plugin Filters */

  // Set filter on Active on load
  if (nb_plugin.active > 0) {
    $(".pluginMiniBox").each(function () {
      if (!$(this).hasClass("plugin-active")) {
        $(this).hide();
      }
    });
    $("#seeActive").trigger("click");
  } else {
    $(".pluginMiniBox").show();
  }

  $("#seeAll").on("change", function () {
    $(".pluginBox").show();
    $(".search-input").trigger("input");
  });

  $("#seeActive").on("change", function () {
    $(".pluginBox").show();
    $(".pluginBox").each(function () {
      if (!$(this).hasClass("plugin-active")) {
        $(this).hide();
      }
    });
    $(".search-input").trigger("input");
  });

  $("#seeInactive").on("change", function () {
    $(".pluginBox").show();
    $(".pluginBox").each(function () {
      if (!$(this).hasClass("plugin-inactive")) {
        $(this).hide();
      }
    });
    $(".search-input").trigger("input");
  });

  $("#seeOther").on("change", function () {
    $(".pluginBox").show();
    $(".pluginBox").each(function () {
      if (
        $(this).hasClass("plugin-active") ||
        $(this).hasClass("plugin-inactive")
      ) {
        $(this).hide();
      }
    });
    $(".search-input").trigger("input");
  });

  /* Plugin Actions */
  /**
   * Activate / Deactivate
   */
  if (isWebmaster != 0) {
    $(".switch").change(function () {
      $(".pluginMiniBox").addClass("usable");

      const toggle = $(this).find("#toggleSelectionMode");
      const row = $(this).parent().parent();

      if (toggle.is(":checked")) {
        // Activating a plugin the PEM catalog reports as incompatible with
        // this Piwigo version asks first. This guard used to hang off
        // `#<id> .activate` inside the incompatible-plugins ajax handler
        // below -- upstream Piwigo's markup, an <a class="activate"> link,
        // which this fork replaced with the toggle switch this handler
        // binds. No `class="activate"` element exists in any template any
        // more, so that .each() matched nothing and the confirmation was
        // never shown: the warning marker rendered, and activation went
        // through silently regardless. It lives here now, on the control
        // that actually performs the activation.
        if (row.hasClass("incompatible")) {
          confirmIncompatibleActivation(toggle, row);

          return;
        }

        applyActivation(row);
      } else {
        disactivatePlugin(row.attr("id")!);

        row.removeClass("plugin-active").addClass("plugin-inactive");
        row
          .find(".pluginActionLevel1")
          .removeClass("pluginActionLevel1")
          .addClass("pluginUnavailableAction");
      }

      actualizeFilter();
    });
  } else {
    $(".pluginMiniBox").addClass("notUsable");
    $(".plugin-active").find(".slider").addClass("desactivate_disabled");
    $(".plugin-inactive").find(".slider").addClass("activate_disabled");
    $(".switch input").on("click", function (event) {
      $(this).addClass("disabled");
      event.preventDefault();
      event.stopPropagation();

      const id = $(this).parent().parent().parent().attr("id")!;
      $("#" + id + " .pluginNotif").stop(false, true);
      $("#" + id + " .PluginActionError label span:first").html(not_webmaster);
      $("#" + id + " .PluginActionError").css("display", "flex");
      $("#" + id + " .PluginActionError")
        .delay(1500)
        .fadeOut(2500);

      setTimeout(function () {
        $(".switch input").removeClass("disabled");
      }, 400); //Same duration as the animation "desactivate_disabled" in css
    });
  }

  /**
   * Delete
   */
  $(".pluginContent")
    .find(".dropdown-option.delete-plugin-button")
    .on("click", function () {
      const plugin_name = $(this)
        .closest(".pluginContent")
        .find(".pluginName")
        .html()
        .trim();
      const plugin_id = $(this).closest(".pluginContent").parent().attr("id")!;
      $.confirm({
        title: delete_plugin_msg.replace("%s", plugin_name),
        buttons: {
          confirm: {
            text: confirm_msg,
            btnClass: "btn-red",
            action: function () {
              deletePlugin(plugin_id, plugin_name);
            },
          },
          cancel: {
            text: cancel_msg,
          },
        },
        ...jConfirm_confirm_options,
      });
    });

  /**
   * Restore
   */
  $(".pluginContent")
    .find(".dropdown-option.plugin-restore")
    .on("click", function () {
      const plugin_name = $(this)
        .closest(".pluginContent")
        .find(".pluginName")
        .html()
        .trim();
      const plugin_id = $(this).closest(".pluginContent").parent().attr("id")!;
      $.confirm({
        title: restore_plugin_msg.replace("%s", plugin_name),
        content: str_restore_def,
        buttons: {
          confirm: {
            text: confirm_msg,
            btnClass: "btn-red",
            action: function () {
              restorePlugin(plugin_id);
            },
          },
          cancel: {
            text: cancel_msg,
          },
        },
        ...jConfirm_confirm_options,
      });
    });

  /**
   * Uninstall
   */
  $(".pluginContent")
    .find(".uninstall-plugin-button")
    .on("click", function () {
      const plugin_name = $(this)
        .closest(".pluginContent")
        .find(".pluginName")
        .html()
        .trim();
      const plugin_id = $(this).closest(".pluginContent").parent().attr("id")!;
      $.confirm({
        title: uninstall_plugin_msg.replace("%s", plugin_name),
        buttons: {
          confirm: {
            text: confirm_msg,
            btnClass: "btn-red",
            action: function () {
              uninstallPlugin(plugin_id);
            },
          },
          cancel: {
            text: cancel_msg,
          },
        },
        ...jConfirm_confirm_options,
      });
    });
});

function set_view_selector(view_type: string) {
  void ajax({
    url: "api/v1/session/preferences/plugin-manager-view",
    type: "PUT",
    contentType: "application/json",
    dataType: "JSON",
    data: JSON.stringify({
      value: view_type,
    }),
  });
}

function actualizeFilter() {
  $("label[for='seeAll'] .filter-badge").html(String(nb_plugin.all));
  $("label[for='seeActive'] .filter-badge").html(String(nb_plugin.active));
  $("label[for='seeInactive'] .filter-badge").html(String(nb_plugin.inactive));
  $("label[for='seeOther'] .filter-badge").html(String(nb_plugin.other));
  $(".filterLabel").show();

  $(".pluginMiniBox").each(function () {
    if (nb_plugin.active == 0) {
      $("label[for='seeActive']").hide();
      if ($("#seeActive").is(":checked")) {
        $("#seeAll").trigger("click");
      }
    }
    if (nb_plugin.inactive == 0) {
      $("label[for='seeInactive']").hide();
      if ($("#seeInactive").is(":checked")) {
        $("#seeAll").trigger("click");
      }
    }
    if (nb_plugin.other == 0) {
      $("label[for='seeOther']").hide();
      if ($("#seeOther").is(":checked")) {
        $("#seeAll").trigger("click");
      }
    }
  });
}

/* group action */

jQuery(document).ready(function () {
  $("label[for='seeActive'] .filter-badge").html(String(nb_plugin.active));
  $("label[for='seeInactive'] .filter-badge").html(String(nb_plugin.inactive));
  $("label[for='seeOther'] .filter-badge").html(String(nb_plugin.other));
  $(".filterLabel").show();

  $(".pluginBox").each(function () {
    if (nb_plugin.active == 0) {
      $("label[for='seeActive']").hide();
      if ($("#seeActive").is(":checked")) {
        $("#seeAll").trigger("click");
      }
    }
    if (nb_plugin.inactive == 0) {
      $("label[for='seeInactive']").hide();
      if ($("#seeInactive").is(":checked")) {
        $("#seeAll").trigger("click");
      }
    }
    if (nb_plugin.other == 0) {
      $("label[for='seeOther']").hide();
      if ($("#seeOther").is(":checked")) {
        $("#seeAll").trigger("click");
      }
    }

    const myplugin = jQuery(this);
    myplugin.find(".showOptions").click(function () {
      myplugin.find(".PluginOptionsBlock").toggle();
    });
  });

  /* incompatible plugins */
  void ajax({
    method: "GET",
    url: "admin.php",
    // `page=plugins_installed` is upstream Piwigo's slug. This fork
    // consolidated the per-tab slugs into `page=plugins` + `tab`
    // (CoreTabs.php's own 'plugins' case), and an unrecognised slug
    // still returns 200 with the default admin page's HTML -- so the
    // old value made `dataType: "json"` fail to parse on every view,
    // silently killing this whole handler.
    data: { page: "plugins", tab: "installed", incompatible_plugins: true },
    dataType: "json",
    // Real shape confirmed via PluginsInstalledPageRenderer.php's own
    // `echo json_encode($incompatible_plugins);` -- a plain array of
    // plugin id strings, no OpenAPI coverage (legacy admin.php endpoint,
    // not api/v1).
    success: function (data: string[]) {
      for (let i = 0; i < data.length; i++) {
        if (show_details)
          jQuery("#" + data[i] + " .pluginName").prepend(
            '<a class="warning" title="' + incompatible_msg + '"></a>',
          );
        else
          jQuery("#" + data[i] + " .pluginName").prepend(
            '<span class="warning" title="' + incompatible_msg + '"></span>',
          );
        // The `incompatible` class is what the activation guard in the
        // switch handler above keys off -- this marker is the whole
        // mechanism, not just styling.
        jQuery("#" + data[i]).addClass("incompatible");
      }
      jQuery(".warning").tipTip({
        delay: 0,
        fadeIn: 200,
        fadeOut: 200,
        maxWidth: "250px",
      });
    },
  });

  /*Add the filter research*/
  document.onkeydown = function (e) {
    if (e.keyCode == 58) {
      jQuery(".pluginFilter input.search-input").focus();
      return false;
    }
  };

  jQuery(".pluginFilter input").on("input", function () {
    const text = String(jQuery(this).val()).toLowerCase();
    let searchNumber = 0;

    let searchActive = 0;
    let searchInactive = 0;
    let searchOther = 0;

    $(".pluginBox").each(function () {
      if (text == "") {
        jQuery(".nbPluginsSearch").hide();
        if ($("#seeAll").is(":checked")) {
          jQuery(this).show();
        }
        if (
          $("#seeActive").is(":checked") &&
          jQuery(this).hasClass("plugin-active")
        ) {
          jQuery(this).show();
        }
        if (
          $("#seeInactive").is(":checked") &&
          jQuery(this).hasClass("plugin-inactive")
        ) {
          jQuery(this).show();
        }
        if (
          $("#seeOther").is(":checked") &&
          (jQuery(this).hasClass("plugin-merged") ||
            jQuery(this).hasClass("plugin-missing"))
        ) {
          jQuery(this).show();
        }

        if ($(this).hasClass("plugin-active")) {
          searchActive++;
        }
        if ($(this).hasClass("plugin-inactive")) {
          searchInactive++;
        }
        if (
          $(this).hasClass("plugin-merged") ||
          $(this).hasClass("plugin-missing")
        ) {
          searchOther++;
        }
        searchNumber++;

        nb_plugin.all = searchNumber;
        nb_plugin.active = searchActive;
        nb_plugin.inactive = searchInactive;
        nb_plugin.other = searchOther;
      } else {
        const name = jQuery(this).find(".pluginName").text().toLowerCase();
        jQuery(".nbPluginsSearch").show();
        const description = jQuery(this)
          .find(".pluginDesc")
          .text()
          .toLowerCase();
        if (name.search(text) != -1 || description.search(text) != -1) {
          searchNumber++;

          if ($("#seeAll").is(":checked")) {
            jQuery(this).show();
          }
          if (
            $("#seeActive").is(":checked") &&
            jQuery(this).hasClass("plugin-active")
          ) {
            jQuery(this).show();
          }
          if (
            $("#seeInactive").is(":checked") &&
            jQuery(this).hasClass("plugin-inactive")
          ) {
            jQuery(this).show();
          }
          if (
            $("#seeOther").is(":checked") &&
            (jQuery(this).hasClass("plugin-merged") ||
              jQuery(this).hasClass("plugin-missing"))
          ) {
            jQuery(this).show();
          }

          if ($(this).hasClass("plugin-active")) {
            searchActive++;
          }
          if ($(this).hasClass("plugin-inactive")) {
            searchInactive++;
          }
          if (
            $(this).hasClass("plugin-merged") ||
            $(this).hasClass("plugin-missing")
          ) {
            searchOther++;
          }

          nb_plugin.all = searchNumber;
          nb_plugin.active = searchActive;
          nb_plugin.inactive = searchInactive;
          nb_plugin.other = searchOther;
        } else {
          jQuery(this).hide();

          nb_plugin.all = searchNumber;
          nb_plugin.active = searchActive;
          nb_plugin.inactive = searchInactive;
          nb_plugin.other = searchOther;
        }
      }
    });

    actualizeFilter();

    if (searchNumber == 0) {
      jQuery(".nbPluginsSearch").html(nothing_found);
    } else if (searchNumber == 1) {
      jQuery(".nbPluginsSearch").html(
        plugin_found.replace("%s", String(searchNumber)),
      );
    } else {
      jQuery(".nbPluginsSearch").html(
        x_plugins_found.replace("%s", String(searchNumber)),
      );
    }
  });

  if (plugin_filter == "deactivated") {
    jQuery(".filterLabel[for='seeInactive']").trigger("click");
  }
});

$(document).mouseup(function (e) {
  e.stopPropagation();
  $(".pluginBox").each(function () {
    if (
      $(this)
        .find(".showOptions")
        .has(e.target as unknown as Element).length === 0
    ) {
      $(this).find(".PluginOptionsBlock").hide();
    }
  });
});
