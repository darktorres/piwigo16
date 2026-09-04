import {
  addClass,
  attrOf,
  hide,
  is,
  on,
  removeClass,
  setVal,
  show,
  trigger,
  val,
} from "../../../default/js/vendor/dom";

// Real declarer of `fontCheckbox` -- wraps no library (docs/PLAN.md's own
// P49 plugin table lists it "wraps: --"), so it converts fully now rather
// than waiting for P49-B. Every selector below is prefixed `.font-checkbox`
// and run once against `document` -- NOT scoped to one container per call,
// and NOT a loop over each matched `.font-checkbox` element either. Both
// of those were tried and both broke a real page:
//
// - A per-matched-element loop (`document.querySelectorAll(".font-checkbox")
//   .forEach(fontCheckbox)`, each call scoped to `container.querySelectorAll(
//   "input[type=radio]")`) double-processes any input under a NESTED match --
//   `configuration_main.latte`'s mail-theme picker wraps
//   `<label class="font-checkbox">` radios inside an outer
//   `<div class="themeBoxes font-checkbox">`, so the unchecked branch's
//   `classList.toggle()` ran twice and cancelled itself out (confirmed on
//   `admin-config`'s VR baseline: "Dark"'s icon stuck on `icon-dot-circled`).
// - A single call scoped to `document` itself (`container.querySelectorAll(
//   "input[type=radio]")` with `container = document`) fixes that, but drops
//   the `.font-checkbox` scoping entirely -- it then also touches
//   `cat_list.latte`'s unrelated `.AlbumViewSelector` radios (plain
//   `input[type=radio]`, no `.font-checkbox` ancestor at all), corrupting
//   their icons too (confirmed on `admin-cat-list`'s VR baseline: an extra,
//   wrongly-added `icon-dot-circled` in the view-mode switcher).
//
// `document.querySelectorAll(".font-checkbox input[type=...]")` gets both
// right at once: real CSS selector matching already unions and dedupes
// (a radio under two nested `.font-checkbox` ancestors still matches once),
// same as jQuery's own multi-element `.find()` did.
function fontCheckbox(): void {
  /* checkbox */
  document
    .querySelectorAll(".font-checkbox input[type=checkbox]")
    .forEach((checkbox) => {
      if (!is(checkbox, ":checked")) {
        // jQuery's `.toggleClass("a b")` toggles each name independently
        // (not a mutually-exclusive swap) -- the markup always starts with
        // "icon-check", so toggling both here is how it becomes
        // "icon-check-empty" for an unchecked box.
        const icon = checkbox.previousElementSibling;
        if (icon !== null) {
          icon.classList.toggle("icon-check");
          icon.classList.toggle("icon-check-empty");
        }
      }
    });
  on(
    document.querySelectorAll(".font-checkbox input[type=checkbox]"),
    "change",
    function (this: Element): void {
      const icon = this.previousElementSibling;
      if (icon !== null) {
        // jQuery's `.removeClass()` with no argument clears every class.
        icon.className = "";
        addClass(
          icon,
          is(this, ":checked") ? "icon-check" : "icon-check-empty",
        );
      }
    },
  );

  /* radio */
  document
    .querySelectorAll(".font-checkbox input[type=radio]")
    .forEach((radio) => {
      if (!is(radio, ":checked")) {
        const icon = radio.previousElementSibling;
        if (icon !== null) {
          icon.classList.toggle("icon-dot-circled");
          icon.classList.toggle("icon-circle-empty");
        }
      } else {
        const label = radio.closest("label");
        if (label !== null) addClass(label, "selected");
      }
    });
  on(
    document.querySelectorAll(".font-checkbox input[type=radio]"),
    "change",
    function (this: Element): void {
      // Non-null: every real .font-checkbox radio in the template has a
      // `name` attribute.
      const name = attrOf(this, "name")!;
      document
        .querySelectorAll(`.font-checkbox input[type=radio][name="${name}"]`)
        .forEach((el) => {
          const icon = el.previousElementSibling;
          if (icon !== null) icon.className = "";
          const label = el.closest("label");
          if (label !== null) removeClass(label, "selected");
          if (!is(el, ":checked")) {
            if (icon !== null) addClass(icon, "icon-circle-empty");
          } else {
            if (icon !== null) addClass(icon, "icon-dot-circled");
            if (label !== null) addClass(label, "selected");
          }
        });
    },
  );
}

// init fontChecbox everywhere
fontCheckbox();

on(document.querySelectorAll(".search-cancel"), "click", function (): void {
  setVal(document.querySelectorAll(".search-input"), "");
  trigger(document.querySelectorAll(".search-input"), "input");
});

on(document.querySelectorAll(".search-input"), "input", function (): void {
  if (val(document.querySelectorAll(".search-input")) === "") {
    hide(document.querySelectorAll(".search-cancel"));
  } else {
    show(document.querySelectorAll(".search-cancel"));
  }
});
