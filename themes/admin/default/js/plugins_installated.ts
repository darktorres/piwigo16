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
import { ajax, type AjaxResponse } from "../../../default/js/vendor/ajax";
import { alert, confirm } from "../../../default/js/vendor/jconfirm";
import {
  addClass,
  css,
  delay,
  fadeOut,
  find,
  hasClass,
  hide,
  html,
  is,
  on,
  prepend,
  ready,
  removeClass,
  setChecked,
  setDisabled,
  show,
  stop,
  textOf,
  toggle,
  trigger,
  val,
} from "../../../default/js/vendor/dom";
import { tipTip } from "../../../default/js/vendor/tiptip";

function setDisplayClassic(): void {
  removeClass(
    document.querySelectorAll(".pluginContainer"),
    "line-form compact-form",
  );
  addClass(document.querySelectorAll(".pluginContainer"), "classic-form");

  show(document.querySelectorAll(".pluginDesc"));
  show(document.querySelectorAll(".pluginActions"));
  hide(document.querySelectorAll(".pluginActionsSmallIcons"));

  removeClass(document.querySelectorAll(".pluginName"), "pluginNameCompact");
}

function setDisplayCompact(): void {
  removeClass(
    document.querySelectorAll(".pluginContainer"),
    "line-form classic-form",
  );
  addClass(document.querySelectorAll(".pluginContainer"), "compact-form");

  hide(document.querySelectorAll(".pluginDesc"));
  hide(document.querySelectorAll(".pluginActions"));
  show(document.querySelectorAll(".pluginActionsSmallIcons"));

  addClass(document.querySelectorAll(".pluginName"), "pluginNameCompact");
}

function setDisplayLine(): void {
  removeClass(
    document.querySelectorAll(".pluginContainer"),
    "compact-form classic-form",
  );
  addClass(document.querySelectorAll(".pluginContainer"), "line-form");

  show(document.querySelectorAll(".pluginDesc"));
  show(document.querySelectorAll(".pluginActions"));
  hide(document.querySelectorAll(".pluginActionsSmallIcons"));
}

function activatePlugin(id: string): void {
  setDisabled(document.querySelectorAll("#" + id + " .switch"), true);

  void ajax({
    type: "POST",
    dataType: "json",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    url: "api/v1/plugins/" + id + "/actions/perform",
    data: JSON.stringify({ action: "activate" }),
    // 204 No Content -- pluginPerformAction's real response has no body.
    success: function (_data: unknown) {
      stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
      html(
        find(
          document.querySelectorAll("#" + id + " .AddPluginSuccess"),
          "label span:first-child",
        ),
        plugin_added_str,
      );
      css(
        document.querySelectorAll("#" + id + " .AddPluginSuccess"),
        "display",
        "flex",
      );

      nb_plugin.active += 1;
      nb_plugin.inactive -= 1;
      actualizeFilter();
    },
    error: function (e: AjaxResponse) {
      console.error(e.responseText);
      stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
      html(
        find(
          document.querySelectorAll("#" + id + " .PluginActionError"),
          "label span:first-child",
        ),
        plugin_action_error,
      );
      css(
        document.querySelectorAll("#" + id + " .PluginActionError"),
        "display",
        "flex",
      );
      const errorEl = document.querySelectorAll(
        "#" + id + " .PluginActionError",
      );
      delay(errorEl, 1500);
      fadeOut(errorEl, 2500);
    },
  }).done(function (_data: unknown) {
    setDisabled(document.querySelectorAll("#" + id + " .switch"), false);
    fadeOut(document.querySelectorAll("#" + id + " .AddPluginSuccess"), 3000);
  });
}

/**
 * The DOM half of activating a plugin, split out of the switch handler so
 * the confirm-first path below can reuse it verbatim rather than duplicate
 * the class bookkeeping.
 */
function applyActivation(row: Element): void {
  activatePlugin(row.id);

  addClass(row, "plugin-active");
  removeClass(row, "plugin-inactive");
  if (find(row, ".pluginUnavailableAction")[0]?.getAttribute("href")) {
    const unavailable = find(row, ".pluginUnavailableAction");
    removeClass(unavailable, "pluginUnavailableAction");
    addClass(unavailable, "pluginActionLevel1");
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
function confirmIncompatibleActivation(toggleEl: Element, row: Element): void {
  let confirmed = false;

  confirm({
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
        setChecked(toggleEl, false);
      }
    },
    ...jConfirm_confirm_with_content_options,
  });
}

function disactivatePlugin(id: string): void {
  setDisabled(document.querySelectorAll("#" + id + " .switch"), true);

  void ajax({
    type: "POST",
    dataType: "json",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    url: "api/v1/plugins/" + id + "/actions/perform",
    data: JSON.stringify({ action: "deactivate" }),
    // 204 No Content -- pluginPerformAction's real response has no body.
    success: function (_data: unknown) {
      stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
      html(
        find(
          document.querySelectorAll("#" + id + " .DeactivatePluginSuccess"),
          "label span:first-child",
        ),
        plugin_deactivated_str,
      );
      css(
        document.querySelectorAll("#" + id + " .DeactivatePluginSuccess"),
        "display",
        "flex",
      );

      nb_plugin.inactive += 1;
      nb_plugin.active -= 1;
      actualizeFilter();
    },
    error: function (e: AjaxResponse) {
      console.error(e);
      stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
      html(
        find(
          document.querySelectorAll("#" + id + " .PluginActionError"),
          "label span:first-child",
        ),
        plugin_action_error,
      );
      css(
        document.querySelectorAll("#" + id + " .PluginActionError"),
        "display",
        "flex",
      );
      const errorEl = document.querySelectorAll(
        "#" + id + " .PluginActionError",
      );
      delay(errorEl, 1500);
      fadeOut(errorEl, 2500);
    },
  }).done(function (_data: unknown) {
    setDisabled(document.querySelectorAll("#" + id + " .switch"), false);
    fadeOut(
      document.querySelectorAll("#" + id + " .DeactivatePluginSuccess"),
      3000,
    );
  });
}

function deletePlugin(id: string, name: string): void {
  alert({
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
          document.getElementById(id)?.remove();
          nb_plugin.inactive -= 1;
          nb_plugin.all -= 1;
          actualizeFilter();
        },
        error: function (e: AjaxResponse) {
          console.error(e);
          stop(
            document.querySelectorAll("#" + id + " .pluginNotif"),
            false,
            true,
          );
          html(
            find(
              document.querySelectorAll("#" + id + " .PluginActionError"),
              "label span:first-child",
            ),
            plugin_action_error,
          );
          css(
            document.querySelectorAll("#" + id + " .PluginActionError"),
            "display",
            "flex",
          );
          const errorEl = document.querySelectorAll(
            "#" + id + " .PluginActionError",
          );
          delay(errorEl, 1500);
          fadeOut(errorEl, 2500);
        },
      });
    },
    ...jConfirm_alert_options,
  });
}

function restorePlugin(id: string): void {
  void ajax({
    type: "POST",
    dataType: "json",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    url: "api/v1/plugins/" + id + "/actions/perform",
    data: JSON.stringify({ action: "restore" }),
    // 204 No Content -- pluginPerformAction's real response has no body.
    success: function (_data: unknown) {
      stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
      html(
        find(
          document.querySelectorAll("#" + id + " .RestorePluginSuccess"),
          "label span:first-child",
        ),
        plugin_restored_str,
      );
      css(
        document.querySelectorAll("#" + id + " .RestorePluginSuccess"),
        "display",
        "flex",
      );
    },
    error: function (e: AjaxResponse) {
      console.error(e);
      stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
      html(
        find(
          document.querySelectorAll("#" + id + " .PluginActionError"),
          "label span:first-child",
        ),
        plugin_action_error,
      );
      css(
        document.querySelectorAll("#" + id + " .PluginActionError"),
        "display",
        "flex",
      );
      const errorEl = document.querySelectorAll(
        "#" + id + " .PluginActionError",
      );
      delay(errorEl, 1500);
      fadeOut(errorEl, 2500);
    },
  }).done(function (_data: unknown) {
    fadeOut(
      document.querySelectorAll("#" + id + " .RestorePluginSuccess"),
      3000,
    );
  });
}

function uninstallPlugin(id: string): void {
  void ajax({
    type: "POST",
    dataType: "json",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    url: "api/v1/plugins/" + id + "/actions/perform",
    data: JSON.stringify({ action: "uninstall" }),
    // 204 No Content -- pluginPerformAction's real response has no body.
    success: function (_data: unknown) {
      document.getElementById(id)?.remove();
      nb_plugin.other -= 1;
      nb_plugin.all -= 1;
      actualizeFilter();
    },
    error: function (e: AjaxResponse) {
      stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
      html(
        find(
          document.querySelectorAll("#" + id + " .PluginActionError"),
          "label span:first-child",
        ),
        plugin_action_error,
      );
      css(
        document.querySelectorAll("#" + id + " .PluginActionError"),
        "display",
        "flex",
      );
      const errorEl = document.querySelectorAll(
        "#" + id + " .PluginActionError",
      );
      delay(errorEl, 1500);
      fadeOut(errorEl, 2500);
      // Was `e.message` -- jqXHR has no such property (confirmed via
      // @types/jquery's own jqXHR interface); the real server error body
      // is JSON on `.responseText`, matching activatePlugin's own
      // sibling error handler above.
      console.error(e.responseText);
    },
  });
}

ready(function () {
  actualizeFilter();

  if (is(document.querySelectorAll("#displayClassic"), ":checked")) {
    setDisplayClassic();
  }

  if (is(document.querySelectorAll("#displayCompact"), ":checked")) {
    setDisplayCompact();
  }

  if (is(document.querySelectorAll("#displayLine"), ":checked")) {
    setDisplayLine();
  }

  on(document.querySelectorAll("#displayClassic"), "change", function () {
    setDisplayClassic();
    set_view_selector("classic");
  });

  on(document.querySelectorAll("#displayCompact"), "change", function () {
    setDisplayCompact();
    set_view_selector("compact");
  });

  on(document.querySelectorAll("#displayLine"), "change", function () {
    setDisplayLine();
    set_view_selector("line");
  });

  /* Plugin Filters */

  // Set filter on Active on load
  if (nb_plugin.active > 0) {
    document.querySelectorAll(".pluginMiniBox").forEach((el) => {
      if (!hasClass(el, "plugin-active")) {
        hide(el);
      }
    });
    // `.trigger("click")` on a real radio input: jQuery's own trigger()
    // special-cases click/focus/blur/select/submit/reset by calling the
    // element's real native method instead of dispatching a synthetic
    // event -- only that real `.click()` actually flips the radio's own
    // checked state and fires the native "change" that follows. A
    // dispatched CustomEvent (dom.ts's own `trigger()`) does neither, so
    // this one call site needs the real DOM method, not the helper.
    document.getElementById("seeActive")?.click();
  } else {
    show(document.querySelectorAll(".pluginMiniBox"));
  }

  on(document.querySelectorAll("#seeAll"), "change", function () {
    show(document.querySelectorAll(".pluginBox"));
    trigger(document.querySelectorAll(".search-input"), "input");
  });

  on(document.querySelectorAll("#seeActive"), "change", function () {
    show(document.querySelectorAll(".pluginBox"));
    document.querySelectorAll(".pluginBox").forEach((el) => {
      if (!hasClass(el, "plugin-active")) {
        hide(el);
      }
    });
    trigger(document.querySelectorAll(".search-input"), "input");
  });

  on(document.querySelectorAll("#seeInactive"), "change", function () {
    show(document.querySelectorAll(".pluginBox"));
    document.querySelectorAll(".pluginBox").forEach((el) => {
      if (!hasClass(el, "plugin-inactive")) {
        hide(el);
      }
    });
    trigger(document.querySelectorAll(".search-input"), "input");
  });

  on(document.querySelectorAll("#seeOther"), "change", function () {
    show(document.querySelectorAll(".pluginBox"));
    document.querySelectorAll(".pluginBox").forEach((el) => {
      if (hasClass(el, "plugin-active") || hasClass(el, "plugin-inactive")) {
        hide(el);
      }
    });
    trigger(document.querySelectorAll(".search-input"), "input");
  });

  /* Plugin Actions */
  /**
   * Activate / Deactivate
   */
  if (isWebmaster != 0) {
    on(document.querySelectorAll(".switch"), "change", function (event: Event) {
      const switchEl = event.currentTarget as Element;
      addClass(document.querySelectorAll(".pluginMiniBox"), "usable");

      const toggleEl = find(switchEl, "#toggleSelectionMode")[0]!;
      const row = switchEl.parentElement!.parentElement!;

      if (is(toggleEl, ":checked")) {
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
        if (hasClass(row, "incompatible")) {
          confirmIncompatibleActivation(toggleEl, row);

          return;
        }

        applyActivation(row);
      } else {
        disactivatePlugin(row.id);

        removeClass(row, "plugin-active");
        addClass(row, "plugin-inactive");
        const levelAction = find(row, ".pluginActionLevel1");
        removeClass(levelAction, "pluginActionLevel1");
        addClass(levelAction, "pluginUnavailableAction");
      }

      actualizeFilter();
    });
  } else {
    addClass(document.querySelectorAll(".pluginMiniBox"), "notUsable");
    addClass(
      find(document.querySelectorAll(".plugin-active"), ".slider"),
      "desactivate_disabled",
    );
    addClass(
      find(document.querySelectorAll(".plugin-inactive"), ".slider"),
      "activate_disabled",
    );
    on(
      document.querySelectorAll(".switch input"),
      "click",
      function (event: Event) {
        const el = event.currentTarget as Element;
        addClass(el, "disabled");
        event.preventDefault();
        event.stopPropagation();

        const id = el.parentElement!.parentElement!.parentElement!.id;
        stop(
          document.querySelectorAll("#" + id + " .pluginNotif"),
          false,
          true,
        );
        html(
          find(
            document.querySelectorAll("#" + id + " .PluginActionError"),
            "label span:first-child",
          ),
          not_webmaster,
        );
        css(
          document.querySelectorAll("#" + id + " .PluginActionError"),
          "display",
          "flex",
        );
        const errorEl = document.querySelectorAll(
          "#" + id + " .PluginActionError",
        );
        delay(errorEl, 1500);
        fadeOut(errorEl, 2500);

        setTimeout(function () {
          removeClass(document.querySelectorAll(".switch input"), "disabled");
        }, 400); //Same duration as the animation "desactivate_disabled" in css
      },
    );
  }

  /**
   * Delete
   */
  on(
    find(
      document.querySelectorAll(".pluginContent"),
      ".dropdown-option.delete-plugin-button",
    ),
    "click",
    function (event: Event) {
      const el = event.currentTarget as Element;
      const pluginContent = el.closest(".pluginContent")!;
      const plugin_name = textOf(find(pluginContent, ".pluginName")).trim();
      const plugin_id = pluginContent.parentElement!.id;
      confirm({
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
    },
  );

  /**
   * Restore
   */
  on(
    find(
      document.querySelectorAll(".pluginContent"),
      ".dropdown-option.plugin-restore",
    ),
    "click",
    function (event: Event) {
      const el = event.currentTarget as Element;
      const pluginContent = el.closest(".pluginContent")!;
      const plugin_name = textOf(find(pluginContent, ".pluginName")).trim();
      const plugin_id = pluginContent.parentElement!.id;
      confirm({
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
    },
  );

  /**
   * Uninstall
   */
  on(
    find(
      document.querySelectorAll(".pluginContent"),
      ".uninstall-plugin-button",
    ),
    "click",
    function (event: Event) {
      const el = event.currentTarget as Element;
      const pluginContent = el.closest(".pluginContent")!;
      const plugin_name = textOf(find(pluginContent, ".pluginName")).trim();
      const plugin_id = pluginContent.parentElement!.id;
      confirm({
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
    },
  );
});

function set_view_selector(view_type: string): void {
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

function actualizeFilter(): void {
  html(
    find(document.querySelectorAll("label[for='seeAll']"), ".filter-badge"),
    String(nb_plugin.all),
  );
  html(
    find(document.querySelectorAll("label[for='seeActive']"), ".filter-badge"),
    String(nb_plugin.active),
  );
  html(
    find(
      document.querySelectorAll("label[for='seeInactive']"),
      ".filter-badge",
    ),
    String(nb_plugin.inactive),
  );
  html(
    find(document.querySelectorAll("label[for='seeOther']"), ".filter-badge"),
    String(nb_plugin.other),
  );
  show(document.querySelectorAll(".filterLabel"));

  document.querySelectorAll(".pluginMiniBox").forEach(() => {
    if (nb_plugin.active == 0) {
      hide(document.querySelectorAll("label[for='seeActive']"));
      if (is(document.querySelectorAll("#seeActive"), ":checked")) {
        document.getElementById("seeAll")?.click();
      }
    }
    if (nb_plugin.inactive == 0) {
      hide(document.querySelectorAll("label[for='seeInactive']"));
      if (is(document.querySelectorAll("#seeInactive"), ":checked")) {
        document.getElementById("seeAll")?.click();
      }
    }
    if (nb_plugin.other == 0) {
      hide(document.querySelectorAll("label[for='seeOther']"));
      if (is(document.querySelectorAll("#seeOther"), ":checked")) {
        document.getElementById("seeAll")?.click();
      }
    }
  });
}

/* group action */

ready(function () {
  html(
    find(document.querySelectorAll("label[for='seeActive']"), ".filter-badge"),
    String(nb_plugin.active),
  );
  html(
    find(
      document.querySelectorAll("label[for='seeInactive']"),
      ".filter-badge",
    ),
    String(nb_plugin.inactive),
  );
  html(
    find(document.querySelectorAll("label[for='seeOther']"), ".filter-badge"),
    String(nb_plugin.other),
  );
  show(document.querySelectorAll(".filterLabel"));

  document.querySelectorAll(".pluginBox").forEach((box) => {
    if (nb_plugin.active == 0) {
      hide(document.querySelectorAll("label[for='seeActive']"));
      if (is(document.querySelectorAll("#seeActive"), ":checked")) {
        document.getElementById("seeAll")?.click();
      }
    }
    if (nb_plugin.inactive == 0) {
      hide(document.querySelectorAll("label[for='seeInactive']"));
      if (is(document.querySelectorAll("#seeInactive"), ":checked")) {
        document.getElementById("seeAll")?.click();
      }
    }
    if (nb_plugin.other == 0) {
      hide(document.querySelectorAll("label[for='seeOther']"));
      if (is(document.querySelectorAll("#seeOther"), ":checked")) {
        document.getElementById("seeAll")?.click();
      }
    }

    on(find(box, ".showOptions"), "click", function () {
      toggle(find(box, ".PluginOptionsBlock"));
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
          prepend(
            document.querySelectorAll("#" + data[i]! + " .pluginName"),
            '<a class="warning" title="' + incompatible_msg + '"></a>',
          );
        else
          prepend(
            document.querySelectorAll("#" + data[i]! + " .pluginName"),
            '<span class="warning" title="' + incompatible_msg + '"></span>',
          );
        // The `incompatible` class is what the activation guard in the
        // switch handler above keys off -- this marker is the whole
        // mechanism, not just styling.
        addClass(document.querySelectorAll("#" + data[i]!), "incompatible");
      }
      tipTip(document.querySelectorAll(".warning"), {
        delay: 0,
        fadeIn: 200,
        fadeOut: 200,
        maxWidth: "250px",
      });
    },
  });

  /*Add the filter research*/
  document.onkeydown = function (e) {
    if (e.key === ":") {
      document
        .querySelector<HTMLElement>(".pluginFilter input.search-input")
        ?.focus();
      return false;
    }
  };

  on(
    document.querySelectorAll(".pluginFilter input"),
    "input",
    function (event: Event) {
      const text = String(val(event.currentTarget as Element)).toLowerCase();
      let searchNumber = 0;

      let searchActive = 0;
      let searchInactive = 0;
      let searchOther = 0;

      document.querySelectorAll(".pluginBox").forEach((box) => {
        if (text == "") {
          hide(document.querySelectorAll(".nbPluginsSearch"));
          if (is(document.querySelectorAll("#seeAll"), ":checked")) {
            show(box);
          }
          if (
            is(document.querySelectorAll("#seeActive"), ":checked") &&
            hasClass(box, "plugin-active")
          ) {
            show(box);
          }
          if (
            is(document.querySelectorAll("#seeInactive"), ":checked") &&
            hasClass(box, "plugin-inactive")
          ) {
            show(box);
          }
          if (
            is(document.querySelectorAll("#seeOther"), ":checked") &&
            (hasClass(box, "plugin-merged") || hasClass(box, "plugin-missing"))
          ) {
            show(box);
          }

          if (hasClass(box, "plugin-active")) {
            searchActive++;
          }
          if (hasClass(box, "plugin-inactive")) {
            searchInactive++;
          }
          if (
            hasClass(box, "plugin-merged") ||
            hasClass(box, "plugin-missing")
          ) {
            searchOther++;
          }
          searchNumber++;

          nb_plugin.all = searchNumber;
          nb_plugin.active = searchActive;
          nb_plugin.inactive = searchInactive;
          nb_plugin.other = searchOther;
        } else {
          const name = textOf(find(box, ".pluginName")).toLowerCase();
          show(document.querySelectorAll(".nbPluginsSearch"));
          const description = textOf(find(box, ".pluginDesc")).toLowerCase();
          if (name.search(text) != -1 || description.search(text) != -1) {
            searchNumber++;

            if (is(document.querySelectorAll("#seeAll"), ":checked")) {
              show(box);
            }
            if (
              is(document.querySelectorAll("#seeActive"), ":checked") &&
              hasClass(box, "plugin-active")
            ) {
              show(box);
            }
            if (
              is(document.querySelectorAll("#seeInactive"), ":checked") &&
              hasClass(box, "plugin-inactive")
            ) {
              show(box);
            }
            if (
              is(document.querySelectorAll("#seeOther"), ":checked") &&
              (hasClass(box, "plugin-merged") ||
                hasClass(box, "plugin-missing"))
            ) {
              show(box);
            }

            if (hasClass(box, "plugin-active")) {
              searchActive++;
            }
            if (hasClass(box, "plugin-inactive")) {
              searchInactive++;
            }
            if (
              hasClass(box, "plugin-merged") ||
              hasClass(box, "plugin-missing")
            ) {
              searchOther++;
            }

            nb_plugin.all = searchNumber;
            nb_plugin.active = searchActive;
            nb_plugin.inactive = searchInactive;
            nb_plugin.other = searchOther;
          } else {
            hide(box);

            nb_plugin.all = searchNumber;
            nb_plugin.active = searchActive;
            nb_plugin.inactive = searchInactive;
            nb_plugin.other = searchOther;
          }
        }
      });

      actualizeFilter();

      if (searchNumber == 0) {
        html(document.querySelectorAll(".nbPluginsSearch"), nothing_found);
      } else if (searchNumber == 1) {
        html(
          document.querySelectorAll(".nbPluginsSearch"),
          plugin_found.replace("%s", String(searchNumber)),
        );
      } else {
        html(
          document.querySelectorAll(".nbPluginsSearch"),
          x_plugins_found.replace("%s", String(searchNumber)),
        );
      }
    },
  );

  if (plugin_filter == "deactivated") {
    // `.trigger("click")` on a real <label> -- same real-native-method
    // reasoning as the #seeActive call above, though a label's own
    // `.click()` additionally activates the checkbox/radio it's `for`,
    // which is the whole point here.
    document
      .querySelector<HTMLElement>(".filterLabel[for='seeInactive']")
      ?.click();
  }
});

on(document, "mouseup", function (e: Event) {
  e.stopPropagation();
  document.querySelectorAll(".pluginBox").forEach((box) => {
    const showOptions = find(box, ".showOptions")[0];
    if (
      showOptions === undefined ||
      (showOptions !== e.target &&
        !showOptions.contains(e.target as Node | null))
    ) {
      hide(find(box, ".PluginOptionsBlock"));
    }
  });
});
