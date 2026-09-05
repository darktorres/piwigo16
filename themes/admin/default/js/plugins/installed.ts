// Consumer of themes/admin/default/js/plugins/installedConfig.ts's own
// real exports now (docs/PLAN.md P48 -- was ambient window-global
// latching). jConfirmAlertOptions/jConfirmConfirmOptions now import
// from jconfirmPresets.ts too (its own P51-I split of what used to be
// common.ts).
// common.ts's own side effects (font-checkbox init, search-cancel
// bindings) -- plugins_installed.latte's own generic
// `.search-cancel`/`.search-input` pair needs the shared wiring; this
// page used to get it incidentally, as a side effect of importing from
// what was then the same file (common.ts); the P51-I split made that
// dependency explicit instead of leaving it accidental.
import "../common";
import {
  jConfirmAlertOptions,
  jConfirmConfirmOptions,
  jConfirmConfirmWithContentOptions,
} from "../jconfirmPresets";
import {
  activateMsg,
  cancelMsg,
  confirmMsg,
  deletePluginMsg,
  deletedPluginMsg,
  incompatibleMsg,
  isWebmaster,
  nbPlugin,
  notWebmaster,
  nothingFound,
  pluginActionError,
  pluginAddedStr,
  pluginDeactivatedStr,
  pluginFilter,
  pluginFound,
  pluginRestoredStr,
  pwgToken,
  restorePluginMsg,
  showDetails,
  strRestoreDef,
  uninstallPluginMsg,
  xPluginsFound,
} from "./installedConfig";
import {
  ajax,
  AjaxError,
  type AjaxResponse,
} from "../../../../default/js/vendor/utils/ajax";
import { alert, confirm } from "../../../../default/js/vendor/widgets/jconfirm";
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
} from "../../../../default/js/vendor/utils/dom";
import { tipTip } from "../../../../default/js/vendor/widgets/tiptip";

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

async function activatePlugin(id: string): Promise<void> {
  setDisabled(document.querySelectorAll("#" + id + " .switch"), true);

  try {
    // 204 No Content -- pluginPerformAction's real response has no body.
    await ajax({
      type: "POST",
      dataType: "json",
      json: { action: "activate" },
      headers: { "X-CSRF-Token": pwgToken },
      url: "api/v1/plugins/" + id + "/actions/perform",
    });

    stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
    html(
      find(
        document.querySelectorAll("#" + id + " .AddPluginSuccess"),
        "label span:first-child",
      ),
      pluginAddedStr,
    );
    css(
      document.querySelectorAll("#" + id + " .AddPluginSuccess"),
      "display",
      "flex",
    );

    nbPlugin.active += 1;
    nbPlugin.inactive -= 1;
    actualizeFilter();

    setDisabled(document.querySelectorAll("#" + id + " .switch"), false);
    fadeOut(document.querySelectorAll("#" + id + " .AddPluginSuccess"), 3000);
  } catch (e) {
    console.error(e instanceof AjaxError ? e.responseText : e);
    stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
    html(
      find(
        document.querySelectorAll("#" + id + " .PluginActionError"),
        "label span:first-child",
      ),
      pluginActionError,
    );
    css(
      document.querySelectorAll("#" + id + " .PluginActionError"),
      "display",
      "flex",
    );
    const errorEl = document.querySelectorAll("#" + id + " .PluginActionError");
    delay(errorEl, 1500);
    fadeOut(errorEl, 2500);
  }
}

/**
 * The DOM half of activating a plugin, split out of the switch handler so
 * the confirm-first path below can reuse it verbatim rather than duplicate
 * the class bookkeeping.
 */
function applyActivation(row: Element): void {
  void activatePlugin(row.id);

  addClass(row, "plugin-active");
  removeClass(row, "plugin-inactive");
  const unavailableHref = find(
    row,
    ".pluginUnavailableAction",
  )[0]?.getAttribute("href");
  if (
    unavailableHref !== undefined &&
    unavailableHref !== null &&
    unavailableHref !== ""
  ) {
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
 * jConfirmConfirmWithContentOptions sets `backgroundDismiss: true`, and
 * dismissing by backdrop click or Esc never runs the cancel action -- the
 * switch would have been left reading "active" for a plugin that was never
 * activated. `onClose` fires for every dismissal path, so the `confirmed`
 * flag is what distinguishes them.
 */
function confirmIncompatibleActivation(toggleEl: Element, row: Element): void {
  let confirmed = false;

  confirm({
    title: incompatibleMsg,
    content: activateMsg,
    buttons: {
      confirm: {
        text: confirmMsg,
        btnClass: "btn-red",
        action: function () {
          confirmed = true;
          applyActivation(row);
          actualizeFilter();
        },
      },
      cancel: {
        text: cancelMsg,
      },
    },
    onClose: function () {
      if (!confirmed) {
        setChecked(toggleEl, false);
      }
    },
    ...jConfirmConfirmWithContentOptions,
  });
}

async function disactivatePlugin(id: string): Promise<void> {
  setDisabled(document.querySelectorAll("#" + id + " .switch"), true);

  try {
    // 204 No Content -- pluginPerformAction's real response has no body.
    await ajax({
      type: "POST",
      dataType: "json",
      json: { action: "deactivate" },
      headers: { "X-CSRF-Token": pwgToken },
      url: "api/v1/plugins/" + id + "/actions/perform",
    });

    stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
    html(
      find(
        document.querySelectorAll("#" + id + " .DeactivatePluginSuccess"),
        "label span:first-child",
      ),
      pluginDeactivatedStr,
    );
    css(
      document.querySelectorAll("#" + id + " .DeactivatePluginSuccess"),
      "display",
      "flex",
    );

    nbPlugin.inactive += 1;
    nbPlugin.active -= 1;
    actualizeFilter();

    setDisabled(document.querySelectorAll("#" + id + " .switch"), false);
    fadeOut(
      document.querySelectorAll("#" + id + " .DeactivatePluginSuccess"),
      3000,
    );
  } catch (e) {
    console.error(e instanceof AjaxError ? e.responseText : e);
    stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
    html(
      find(
        document.querySelectorAll("#" + id + " .PluginActionError"),
        "label span:first-child",
      ),
      pluginActionError,
    );
    css(
      document.querySelectorAll("#" + id + " .PluginActionError"),
      "display",
      "flex",
    );
    const errorEl = document.querySelectorAll("#" + id + " .PluginActionError");
    delay(errorEl, 1500);
    fadeOut(errorEl, 2500);
  }
}

function deletePlugin(id: string, name: string): void {
  alert({
    title: deletedPluginMsg.replace("%s", name),
    // eslint-disable-next-line @typescript-eslint/promise-function-async -- must return ajax()'s own AjaxThenable (jconfirm.ts's `isThenable()` checks for its real `.always()`); `async` would re-wrap it through `Promise.resolve()` and lose that method.
    content: function () {
      return ajax({
        type: "POST",
        dataType: "json",
        contentType: "application/json",
        headers: { "X-CSRF-Token": pwgToken },
        url: "api/v1/plugins/" + id + "/actions/perform",
        data: JSON.stringify({ action: "delete" }),
        // 204 No Content -- pluginPerformAction's real response has no body.
        success: function (_data: unknown) {
          document.getElementById(id)?.remove();
          nbPlugin.inactive -= 1;
          nbPlugin.all -= 1;
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
            pluginActionError,
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
    ...jConfirmAlertOptions,
  });
}

async function restorePlugin(id: string): Promise<void> {
  try {
    // 204 No Content -- pluginPerformAction's real response has no body.
    await ajax({
      type: "POST",
      dataType: "json",
      json: { action: "restore" },
      headers: { "X-CSRF-Token": pwgToken },
      url: "api/v1/plugins/" + id + "/actions/perform",
    });

    stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
    html(
      find(
        document.querySelectorAll("#" + id + " .RestorePluginSuccess"),
        "label span:first-child",
      ),
      pluginRestoredStr,
    );
    css(
      document.querySelectorAll("#" + id + " .RestorePluginSuccess"),
      "display",
      "flex",
    );

    fadeOut(
      document.querySelectorAll("#" + id + " .RestorePluginSuccess"),
      3000,
    );
  } catch (e) {
    console.error(e instanceof AjaxError ? e.responseText : e);
    stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
    html(
      find(
        document.querySelectorAll("#" + id + " .PluginActionError"),
        "label span:first-child",
      ),
      pluginActionError,
    );
    css(
      document.querySelectorAll("#" + id + " .PluginActionError"),
      "display",
      "flex",
    );
    const errorEl = document.querySelectorAll("#" + id + " .PluginActionError");
    delay(errorEl, 1500);
    fadeOut(errorEl, 2500);
  }
}

async function uninstallPlugin(id: string): Promise<void> {
  try {
    // 204 No Content -- pluginPerformAction's real response has no body.
    await ajax({
      type: "POST",
      dataType: "json",
      json: { action: "uninstall" },
      headers: { "X-CSRF-Token": pwgToken },
      url: "api/v1/plugins/" + id + "/actions/perform",
    });

    document.getElementById(id)?.remove();
    nbPlugin.other -= 1;
    nbPlugin.all -= 1;
    actualizeFilter();
  } catch (e) {
    stop(document.querySelectorAll("#" + id + " .pluginNotif"), false, true);
    html(
      find(
        document.querySelectorAll("#" + id + " .PluginActionError"),
        "label span:first-child",
      ),
      pluginActionError,
    );
    css(
      document.querySelectorAll("#" + id + " .PluginActionError"),
      "display",
      "flex",
    );
    const errorEl = document.querySelectorAll("#" + id + " .PluginActionError");
    delay(errorEl, 1500);
    fadeOut(errorEl, 2500);
    // Was `e.message` -- jqXHR has no such property (confirmed via
    // @types/jquery's own jqXHR interface); the real server error body
    // is JSON on `.responseText`, matching activatePlugin's own
    // sibling error handler above.
    console.error(e instanceof AjaxError ? e.responseText : e);
  }
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
    setViewSelector("classic");
  });

  on(document.querySelectorAll("#displayCompact"), "change", function () {
    setDisplayCompact();
    setViewSelector("compact");
  });

  on(document.querySelectorAll("#displayLine"), "change", function () {
    setDisplayLine();
    setViewSelector("line");
  });

  /* Plugin Filters */

  // Set filter on Active on load
  if (nbPlugin.active > 0) {
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
  if (isWebmaster !== 0) {
    on(
      document.querySelectorAll(".switch"),
      "change",
      function (this: Element) {
        addClass(document.querySelectorAll(".pluginMiniBox"), "usable");

        const toggleEl = find(this, "#toggleSelectionMode")[0]!;
        const row = this.parentElement!.parentElement!;

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
          void disactivatePlugin(row.id);

          removeClass(row, "plugin-active");
          addClass(row, "plugin-inactive");
          const levelAction = find(row, ".pluginActionLevel1");
          removeClass(levelAction, "pluginActionLevel1");
          addClass(levelAction, "pluginUnavailableAction");
        }

        actualizeFilter();
      },
    );
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
      function (this: Element, event: Event) {
        addClass(this, "disabled");
        event.preventDefault();
        event.stopPropagation();

        const { id } = this.parentElement!.parentElement!.parentElement!;
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
          notWebmaster,
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
    function (this: Element) {
      const pluginContent = this.closest(".pluginContent")!;
      const pluginName = textOf(find(pluginContent, ".pluginName")).trim();
      const pluginId = pluginContent.parentElement!.id;
      confirm({
        title: deletePluginMsg.replace("%s", pluginName),
        buttons: {
          confirm: {
            text: confirmMsg,
            btnClass: "btn-red",
            action: function () {
              deletePlugin(pluginId, pluginName);
            },
          },
          cancel: {
            text: cancelMsg,
          },
        },
        ...jConfirmConfirmOptions,
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
    function (this: Element) {
      const pluginContent = this.closest(".pluginContent")!;
      const pluginName = textOf(find(pluginContent, ".pluginName")).trim();
      const pluginId = pluginContent.parentElement!.id;
      confirm({
        title: restorePluginMsg.replace("%s", pluginName),
        content: strRestoreDef,
        buttons: {
          confirm: {
            text: confirmMsg,
            btnClass: "btn-red",
            action: function () {
              void restorePlugin(pluginId);
            },
          },
          cancel: {
            text: cancelMsg,
          },
        },
        ...jConfirmConfirmOptions,
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
    function (this: Element) {
      const pluginContent = this.closest(".pluginContent")!;
      const pluginName = textOf(find(pluginContent, ".pluginName")).trim();
      const pluginId = pluginContent.parentElement!.id;
      confirm({
        title: uninstallPluginMsg.replace("%s", pluginName),
        buttons: {
          confirm: {
            text: confirmMsg,
            btnClass: "btn-red",
            action: function () {
              void uninstallPlugin(pluginId);
            },
          },
          cancel: {
            text: cancelMsg,
          },
        },
        ...jConfirmConfirmOptions,
      });
    },
  );
});

function setViewSelector(view_type: string): void {
  void (async () => {
    try {
      await ajax({
        url: "api/v1/session/preferences/plugin-manager-view",
        type: "PUT",
        dataType: "JSON",
        json: {
          value: view_type,
        },
      });
    } catch (e) {
      console.error(e instanceof AjaxError ? e.responseText : e);
    }
  })();
}

function actualizeFilter(): void {
  html(
    find(document.querySelectorAll("label[for='seeAll']"), ".filter-badge"),
    String(nbPlugin.all),
  );
  html(
    find(document.querySelectorAll("label[for='seeActive']"), ".filter-badge"),
    String(nbPlugin.active),
  );
  html(
    find(
      document.querySelectorAll("label[for='seeInactive']"),
      ".filter-badge",
    ),
    String(nbPlugin.inactive),
  );
  html(
    find(document.querySelectorAll("label[for='seeOther']"), ".filter-badge"),
    String(nbPlugin.other),
  );
  show(document.querySelectorAll(".filterLabel"));

  document.querySelectorAll(".pluginMiniBox").forEach(() => {
    if (nbPlugin.active === 0) {
      hide(document.querySelectorAll("label[for='seeActive']"));
      if (is(document.querySelectorAll("#seeActive"), ":checked")) {
        document.getElementById("seeAll")?.click();
      }
    }
    if (nbPlugin.inactive === 0) {
      hide(document.querySelectorAll("label[for='seeInactive']"));
      if (is(document.querySelectorAll("#seeInactive"), ":checked")) {
        document.getElementById("seeAll")?.click();
      }
    }
    if (nbPlugin.other === 0) {
      hide(document.querySelectorAll("label[for='seeOther']"));
      if (is(document.querySelectorAll("#seeOther"), ":checked")) {
        document.getElementById("seeAll")?.click();
      }
    }
  });
}

/**
 * Part of the plugin-search `input` handler's own extraction, below --
 * whether `box` should be visible under the currently-checked
 * seeAll/seeActive/seeInactive/seeOther radio, independent of the
 * text-search match itself.
 */
function shouldShowBoxForFilter(box: Element): boolean {
  if (is(document.querySelectorAll("#seeAll"), ":checked")) {
    return true;
  }
  if (
    is(document.querySelectorAll("#seeActive"), ":checked") &&
    hasClass(box, "plugin-active")
  ) {
    return true;
  }
  if (
    is(document.querySelectorAll("#seeInactive"), ":checked") &&
    hasClass(box, "plugin-inactive")
  ) {
    return true;
  }
  return (
    is(document.querySelectorAll("#seeOther"), ":checked") &&
    (hasClass(box, "plugin-merged") || hasClass(box, "plugin-missing"))
  );
}

interface PluginSearchCounts {
  active: number;
  inactive: number;
  other: number;
}

/** Part of the plugin-search `input` handler's own extraction, below. */
function tallyBoxCategory(box: Element, counts: PluginSearchCounts): void {
  if (hasClass(box, "plugin-active")) {
    counts.active++;
  }
  if (hasClass(box, "plugin-inactive")) {
    counts.inactive++;
  }
  if (hasClass(box, "plugin-merged") || hasClass(box, "plugin-missing")) {
    counts.other++;
  }
}

/**
 * Part of the plugin-search `input` handler's own extraction, below --
 * shows/hides every `.pluginBox` against the current search text and
 * the checked see-filter radio, updates `nbPlugin`'s live counts, and
 * returns how many boxes matched (0 = every box, when `text` is empty).
 */
function updatePluginBoxesForSearch(text: string): number {
  let searchNumber = 0;
  const counts: PluginSearchCounts = { active: 0, inactive: 0, other: 0 };

  document.querySelectorAll(".pluginBox").forEach((box) => {
    if (text === "") {
      hide(document.querySelectorAll(".nbPluginsSearch"));
      if (shouldShowBoxForFilter(box)) {
        show(box);
      }
      tallyBoxCategory(box, counts);
      searchNumber++;
    } else {
      const name = textOf(find(box, ".pluginName")).toLowerCase();
      show(document.querySelectorAll(".nbPluginsSearch"));
      const description = textOf(find(box, ".pluginDesc")).toLowerCase();
      if (name.search(text) !== -1 || description.search(text) !== -1) {
        searchNumber++;
        if (shouldShowBoxForFilter(box)) {
          show(box);
        }
        tallyBoxCategory(box, counts);
      } else {
        hide(box);
      }
    }

    nbPlugin.all = searchNumber;
    nbPlugin.active = counts.active;
    nbPlugin.inactive = counts.inactive;
    nbPlugin.other = counts.other;
  });

  return searchNumber;
}

/* group action */

ready(function () {
  html(
    find(document.querySelectorAll("label[for='seeActive']"), ".filter-badge"),
    String(nbPlugin.active),
  );
  html(
    find(
      document.querySelectorAll("label[for='seeInactive']"),
      ".filter-badge",
    ),
    String(nbPlugin.inactive),
  );
  html(
    find(document.querySelectorAll("label[for='seeOther']"), ".filter-badge"),
    String(nbPlugin.other),
  );
  show(document.querySelectorAll(".filterLabel"));

  document.querySelectorAll(".pluginBox").forEach((box) => {
    if (nbPlugin.active === 0) {
      hide(document.querySelectorAll("label[for='seeActive']"));
      if (is(document.querySelectorAll("#seeActive"), ":checked")) {
        document.getElementById("seeAll")?.click();
      }
    }
    if (nbPlugin.inactive === 0) {
      hide(document.querySelectorAll("label[for='seeInactive']"));
      if (is(document.querySelectorAll("#seeInactive"), ":checked")) {
        document.getElementById("seeAll")?.click();
      }
    }
    if (nbPlugin.other === 0) {
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
  void (async () => {
    try {
      // Real shape confirmed via PluginsInstalledPageRenderer.php's own
      // `echo json_encode($incompatible_plugins);` -- a plain array of
      // plugin id strings, no OpenAPI coverage (legacy admin.php endpoint,
      // not api/v1).
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
      const data = (await ajax({
        method: "GET",
        url: "admin.php",
        // `page=plugins_installed` is upstream Piwigo's slug. This fork
        // consolidated the per-tab slugs into `page=plugins` + `tab`
        // (CoreTabs.php's own 'plugins' case), and an unrecognised slug
        // still returns 200 with the default admin page's HTML -- so the
        // old value made `dataType: "json"` fail to parse on every view,
        // silently killing this whole handler.
        data: {
          page: "plugins",
          tab: "installed",
          incompatible_plugins: true,
        },
        dataType: "json",
      })) as string[];

      for (const pluginId of data) {
        if (showDetails)
          prepend(
            document.querySelectorAll("#" + pluginId + " .pluginName"),
            '<a class="warning" title="' + incompatibleMsg + '"></a>',
          );
        else
          prepend(
            document.querySelectorAll("#" + pluginId + " .pluginName"),
            '<span class="warning" title="' + incompatibleMsg + '"></span>',
          );
        // The `incompatible` class is what the activation guard in the
        // switch handler above keys off -- this marker is the whole
        // mechanism, not just styling.
        addClass(document.querySelectorAll("#" + pluginId), "incompatible");
      }
      tipTip(document.querySelectorAll(".warning"), {
        delay: 0,
        fadeIn: 200,
        fadeOut: 200,
        maxWidth: "250px",
      });
    } catch (e) {
      console.error(e instanceof AjaxError ? e.responseText : e);
    }
  })();

  /*Add the filter research*/
  on(document, "keydown", function (e: Event) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keydown" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
    const event = e as KeyboardEvent;
    if (event.key === ":") {
      document
        .querySelector<HTMLElement>(".pluginFilter input.search-input")
        ?.focus();
      // A DOM0 handler property's own `return false` suppresses only the
      // default action, not propagation -- this real listener form has
      // no return-value control at all, so the same suppression needs
      // preventDefault().
      e.preventDefault();
    }
  });

  on(
    document.querySelectorAll(".pluginFilter input"),
    "input",
    function (this: Element) {
      const text = String(val(this)).toLowerCase();
      const searchNumber = updatePluginBoxesForSearch(text);

      actualizeFilter();

      if (searchNumber === 0) {
        html(document.querySelectorAll(".nbPluginsSearch"), nothingFound);
      } else if (searchNumber === 1) {
        html(
          document.querySelectorAll(".nbPluginsSearch"),
          pluginFound.replace("%s", String(searchNumber)),
        );
      } else {
        html(
          document.querySelectorAll(".nbPluginsSearch"),
          xPluginsFound.replace("%s", String(searchNumber)),
        );
      }
    },
  );

  if (pluginFilter === "deactivated") {
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
    const [showOptions] = find(box, ".showOptions");
    if (
      showOptions === undefined ||
      (showOptions !== e.target &&
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real mouseup event's own target inside the document is always a Node (or null), never a bare EventTarget with no Node interface.
        !showOptions.contains(e.target as Node | null))
    ) {
      hide(find(box, ".PluginOptionsBlock"));
    }
  });
});
