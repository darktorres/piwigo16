// Consumer of themes/admin/default/js/plugins_installed_config.js's own
// shared globals (docs/PLAN.md P46-C's full sweep): activate_msg/
// cancel_msg/confirm_msg/deactivate_all_msg/delete_plugin_msg/
// deleted_plugin_msg/incompatible_msg/isWebmaster/nb_plugin/
// not_webmaster/nothing_found/plugin_action_error/plugin_added_str/
// plugin_deactivated_str/plugin_filter/plugin_found/plugin_restored_str/
// pwg_token/restore_plugin_msg/show_details/str_restore_def/
// uninstall_plugin_msg/x_plugins_found -- all read bare, declared with
// ambient `declare const` bindings in build/jquery-plugins.d.ts (same
// "consumer converts before its declarer" technique already used for
// pwg_token in album_selector.ts). jConfirm_alert_options/
// jConfirm_confirm_options need no such binding: common.ts's own real
// `const` declarations of those names already resolve this file's bare
// reads, since every themes/**/*.ts file shares one global
// type-checking program (same reasoning as batchManagerGlobal.ts's own
// `derivatives` case).
function setDisplayClassic() {
  $(".pluginContainer")
    .removeClass("line-form")
    .removeClass("compact-form")
    .addClass("classic-form");

  $(".pluginDesc").show();
  $(".pluginActions").show();
  $(".pluginActionsSmallIcons").hide();

  $(".pluginName").removeClass("pluginNameCompact");

  // normalTitle();
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

  // reduceTitle()
}

function setDisplayLine() {
  $(".pluginContainer")
    .addClass("line-form")
    .removeClass("compact-form")
    .removeClass("classic-form");

  $(".pluginDesc").show();
  $(".pluginActions").show();
  $(".pluginActionsSmallIcons").hide();
  // normalTitle();
}

function reduceTitle() {
  const x = document.getElementsByClassName(
    "pluginName",
  ) as HTMLCollectionOf<HTMLElement>;
  const length = 22;

  for (const div of x) {
    const text = div.innerHTML.trim();
    if (text.length > length) {
      let newText = text.substring(0, length);
      newText = newText + "...";

      div.innerHTML = newText;
      div.title = text;
    }
  }
}

function normalTitle() {
  const x = document.getElementsByClassName(
    "pluginName",
  ) as HTMLCollectionOf<HTMLElement>;

  for (const div of x) {
    div.innerHTML = div.dataset.title || "";
  }
}

function activatePlugin(id: string) {
  $("#" + id + " .switch").prop("disabled", true);

  $.ajax({
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
    error: function (e: JQuery.jqXHR) {
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

function disactivatePlugin(id: string) {
  $("#" + id + " .switch").prop("disabled", true);

  $.ajax({
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
    error: function (e: JQuery.jqXHR) {
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
      return $.ajax({
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
        error: function (e: JQuery.jqXHR) {
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
  $.ajax({
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
    error: function (e: JQuery.jqXHR) {
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
  $.ajax({
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
    error: function (e: JQuery.jqXHR) {
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

      if ($(this).find("#toggleSelectionMode").is(":checked")) {
        activatePlugin($(this).parent().parent().attr("id")!);

        $(this)
          .parent()
          .parent()
          .addClass("plugin-active")
          .removeClass("plugin-inactive");
        if (
          $(this)
            .parent()
            .parent()
            .find(".pluginUnavailableAction")
            .attr("href")
        ) {
          $(this)
            .parent()
            .parent()
            .find(".pluginUnavailableAction")
            .removeClass("pluginUnavailableAction")
            .addClass("pluginActionLevel1");
        }
      } else {
        disactivatePlugin($(this).parent().parent().attr("id")!);

        $(this)
          .parent()
          .parent()
          .removeClass("plugin-active")
          .addClass("plugin-inactive");
        $(this)
          .parent()
          .parent()
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
  $.ajax({
    url: "api/v1/session/preferences/plugin-manager-view",
    type: "PUT",
    contentType: "application/json",
    dataType: "JSON",
    data: JSON.stringify({
      value: view_type,
    }),
  });
}

// TPL part :

const queuedManager = jQuery.manageAjax.create("queued", {
  queue: true,
  maxRequests: 1,
});

const nb_plugins = jQuery("div.active").size();
// Was `const done = 0;` with `done++;` below -- a genuine pre-existing
// bug (reassigning a `const`), invisible at runtime only because nothing
// ever exercised this exact code path in a way that surfaced the
// TypeError, and undetectable by `eqeqeq`/`no-undef` (P45's own
// not-yet-CI-gated lint rules) the way this plan's other real bug finds
// were. `strict: true` refuses to compile a `const` reassignment
// outright (TS2588), so this genuinely can't be preserved byte-for-byte
// the way an ordering race condition can -- `let` is the fix, restoring
// the "reload after every active plugin is deactivated" feature this
// bug silently broke.
let done = 0;

function showInactivePlugins() {
  jQuery(".showInactivePlugins").fadeOut(function () {
    jQuery(".plugin-inactive").fadeIn();
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

function performPluginDeactivate(id: string) {
  queuedManager.add({
    type: "POST",
    dataType: "json",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    url: "api/v1/plugins/" + id + "/actions/perform",
    data: JSON.stringify({
      action: "deactivate",
    }),
    // 204 No Content -- pluginPerformAction's real response has no body.
    success: function (_data: unknown) {
      jQuery("#" + id)
        .removeClass("active")
        .addClass("inactive");
      done++;
      if (done == nb_plugins) location.reload();
    },
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

  jQuery("div.deactivate_all a").click(function () {
    $.confirm({
      title: deactivate_all_msg,
      buttons: {
        confirm: {
          text: confirm_msg,
          btnClass: "btn-red",
          action: function () {
            jQuery("div.active").each(function () {
              performPluginDeactivate(jQuery(this).attr("id")!);
            });
          },
        },
        cancel: {
          text: cancel_msg,
        },
      },
      ...jConfirm_confirm_options,
    });
  });

  /* incompatible plugins */
  jQuery.ajax({
    method: "GET",
    url: "admin.php",
    data: { page: "plugins_installed", incompatible_plugins: true },
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
        jQuery("#" + data[i]).addClass("incompatible");
        jQuery("#" + data[i] + " .activate").each(function () {
          $(this).pwg_jconfirm_follow_href({
            alert_title: incompatible_msg + activate_msg,
            alert_confirm: confirm_msg,
            alert_cancel: cancel_msg,
          });
        });
      }
      jQuery(".warning").tipTip({
        delay: 0,
        fadeIn: 200,
        fadeOut: 200,
        maxWidth: "250px",
      });
    },
  });

  jQuery(".fullInfo").tipTip({
    delay: 500,
    fadeIn: 200,
    fadeOut: 200,
    maxWidth: "300px",
    keepAlive: false,
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

  /* Show Inactive plugins or button to show them*/
  jQuery(".showInactivePlugins button").on("click", showInactivePlugins);

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
