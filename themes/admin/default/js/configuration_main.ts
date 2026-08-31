import "./common";

import { pwg_getPageData } from "../../../default/js/page-data";
import {
  addClass,
  attr,
  css,
  delegate,
  is,
  on,
  removeAttr,
  removeClass,
  setVal,
  toggle,
  val,
} from "../../../default/js/vendor/dom";
import { tipTip } from "../../../default/js/vendor/tiptip";
export {};

(function () {
  const targets: Record<string, string> = {
    'input[name="rate"]': "#rate_anonymous",
    'input[name="allow_user_registration"]': "#email_admin_on_new_user",
    'input[name="email_admin_on_new_user"]': "#email_admin_on_new_user_filter",
  };

  for (const selector in targets) {
    const target = targets[selector]!;

    toggle(
      document.querySelectorAll(target),
      is(document.querySelectorAll(selector), ":checked"),
    );

    // Same pre-existing closure bug as configuration_comments.ts's own
    // copy of this pattern -- `selector` read from the outer loop's
    // `var`, not passed into the IIFE the way `target` is. Preserved
    // exactly.
    (function (target) {
      on(
        document.querySelectorAll(selector),
        "change",
        function (event: Event): void {
          toggle(
            document.querySelectorAll(target),
            is(event.currentTarget as Element, ":checked"),
          );
        },
      );
    })(target);
  }

  tipTip(document.querySelectorAll(".tiptip-with-img"), {
    maxWidth: "300px",
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
  });
})();

(function () {
  const max_fields = Math.ceil(
    pwg_getPageData<number>("order_by_options_count") / 2,
  );

  function updateFilters() {
    const selects = document.querySelectorAll<HTMLSelectElement>(
      "#order_filters select",
    );

    toggle(
      document.querySelectorAll("#order_filters .addFilter"),
      selects.length <= max_fields,
    );
    // jQuery's `.filter(":first")` here -- a jQuery-only pseudo-selector,
    // not real CSS -- is just "the first of the set already queried";
    // native indexing says the same thing directly.
    const removeFilters = document.querySelectorAll(
      "#order_filters .removeFilter",
    );
    css(removeFilters, "display", "");
    if (removeFilters[0] !== undefined) {
      css(removeFilters[0], "display", "none");
    }

    selects.forEach((select) => {
      select.querySelectorAll("option").forEach((option) => {
        removeAttr(option, "disabled");
      });
    });
    // Disables, in every OTHER select, whichever option matches this
    // select's own chosen value -- so the same order-by rule can't be
    // picked twice across the filter rows.
    selects.forEach((select) => {
      const value = String(val(select));
      selects.forEach((other) => {
        if (other === select) {
          return;
        }
        attr(
          other.querySelectorAll('option[value="' + value + '"]'),
          "disabled",
          "disabled",
        );
      });
    });
  }

  delegate(
    document.querySelectorAll("#order_filters"),
    "click",
    ".removeFilter",
    function (this: Element): void {
      const parent = this.parentElement;
      if (parent !== null && parent.matches("span.filter")) {
        parent.remove();
      }
      updateFilters();
    },
  );

  delegate(
    document.querySelectorAll("#order_filters"),
    "change",
    "select",
    updateFilters,
  );

  on(
    document.querySelectorAll("#order_filters .addFilter"),
    "click",
    function (event: Event): void {
      const addFilter = event.currentTarget as Element;
      const previous = addFilter.previousElementSibling;
      if (previous === null || !previous.matches("span.filter")) {
        return;
      }
      const clone = previous.cloneNode(true) as Element;
      addFilter.parentElement?.insertBefore(clone, addFilter);

      const clonedSelect = clone.querySelector("select");
      if (clonedSelect !== null) {
        setVal(clonedSelect, "");
      }

      updateFilters();
    },
  );

  updateFilters();
})();

// Still jQuery: colorbox is a library, ported in P49-B group 3.
jQuery(".themeBoxes a").colorbox();

on(
  document.querySelectorAll("input[name='mail_theme']"),
  "change",
  function (event: Event): void {
    document.querySelectorAll("input[name='mail_theme']").forEach((radio) => {
      const themeSelect = radio.closest(".themeSelect");
      if (themeSelect !== null) {
        removeClass(themeSelect, "themeDefault");
      }
    });

    const checked = (event.currentTarget as Element).closest(".themeSelect");
    if (checked !== null) {
      addClass(checked, "themeDefault");
    }
  },
);

on(
  document.querySelectorAll("input[name='email_admin_on_new_user_filter']"),
  "change",
  function (): void {
    const value = val(
      document.querySelectorAll(
        "input[name='email_admin_on_new_user_filter']:checked",
      ),
    );

    toggle(
      document.querySelectorAll(
        "#email_admin_on_new_user_filter_group_options",
      ),
      value === "group",
    );
  },
);
