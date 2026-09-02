import "./common";

import { pwg_getPageData } from "../../../default/js/page-data";
import { colorbox } from "../../../default/js/vendor/colorbox";
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

    // No closure bug here, and no IIFE needed to avoid one --
    // configuration_comments.ts's own copy of this pattern already
    // established why: `for (const selector in ...)` gives each
    // iteration its own binding, so both `selector` and `target` are
    // already captured correctly without a wrapper.
    on(
      document.querySelectorAll(selector),
      "change",
      function (this: Element): void {
        toggle(document.querySelectorAll(target), is(this, ":checked"));
      },
    );
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
      if (parent?.matches("span.filter") === true) {
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
    function (this: Element): void {
      const previous = this.previousElementSibling;
      if (previous?.matches("span.filter") !== true) {
        return;
      }
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
      const clone = previous.cloneNode(true) as Element;
      this.parentElement?.insertBefore(clone, this);

      const clonedSelect = clone.querySelector("select");
      if (clonedSelect !== null) {
        setVal(clonedSelect, "");
      }

      updateFilters();
    },
  );

  updateFilters();
})();

colorbox(document.querySelectorAll(".themeBoxes a"));

on(
  document.querySelectorAll("input[name='mail_theme']"),
  "change",
  function (this: Element): void {
    document.querySelectorAll("input[name='mail_theme']").forEach((radio) => {
      const themeSelect = radio.closest(".themeSelect");
      if (themeSelect !== null) {
        removeClass(themeSelect, "themeDefault");
      }
    });

    const checked = this.closest(".themeSelect");
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
