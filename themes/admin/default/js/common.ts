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
import { confirm } from "../../../default/js/vendor/jconfirm";

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
    function (event: Event): void {
      const checkbox = event.currentTarget as Element;
      const icon = checkbox.previousElementSibling;
      if (icon !== null) {
        // jQuery's `.removeClass()` with no argument clears every class.
        icon.className = "";
        addClass(
          icon,
          is(checkbox, ":checked") ? "icon-check" : "icon-check-empty",
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
    function (event: Event): void {
      const radio = event.currentTarget as Element;
      // Non-null: every real .font-checkbox radio in the template has a
      // `name` attribute.
      const name = attrOf(radio, "name")!;
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

// str_repeat stays module-private (P48) -- sprintf() below is its only
// real caller anywhere in this codebase; array_delete (the same
// original comment's other established shared-global) had zero real
// callers anywhere, `.ts` or `.latte`, and was removed outright rather
// than exported to nothing (Legacy porting: no permanent facades).
function str_repeat(i: string, m: number): string {
  const o: string[] = [];
  for (let count = m; count > 0; o[--count] = i);
  return o.join("");
}

if (!Array.prototype.indexOf) {
  // Genuinely irreducible `any`: this assigns to the shared
  // `Array.prototype` object itself, not one array instance -- lib.es5's
  // own ambient `indexOf` signature for that shared prototype has no
  // real element type to narrow to. The guard itself is realistically
  // dead in any evergreen browser this project's own P35 browserslist
  // floor targets (indexOf has been standard since ES5), but left
  // as-is -- removing a guarded fallback isn't this phase's job.
  Array.prototype.indexOf = function (elt: any, fromArg?: number): number {
    const len = this.length;

    let from = Number(fromArg) || 0;
    from = from < 0 ? Math.ceil(from) : Math.floor(from);
    if (from < 0) from += len;

    for (; from < len; from++) {
      if (from in this && this[from] === elt) return from;
    }
    return -1;
  };
}

export function getRandomInt(min: number, max: number): number {
  const lo = Math.ceil(min);
  const hi = Math.floor(max);
  return Math.floor(Math.random() * (hi - lo)) + lo;
}

export function sprintf(...args: (string | number)[]): string {
  let i = 0,
    // Genuinely polymorphic per format specifier (%b/%d/%x reinterpret
    // as number, %s coerces to string, %c reinterprets as a char code)
    // -- irreducible without a much larger rewrite of this well-known
    // sprintf implementation, not this phase's job.
    a: any,
    // The first argument is always the format-pattern string, never one
    // of the `%s`/`%d`-substituted values `args`'s own looser type
    // covers -- every real call site passes a literal string here.
    f = args[i++] as string,
    m: RegExpExecArray | null,
    p: string,
    c: string,
    x: number;
  const o: string[] = [],
    s = "";
  while (f) {
    if ((m = /^[^\x25]+/.exec(f))) {
      o.push(m[0]);
    } else if ((m = /^\x25{2}/.exec(f))) {
      o.push("%");
    } else if (
      (m =
        /^\x25(?:(\d+)\$)?(\+)?(0|'[^$])?(-)?(\d+)?(?:\.(\d+))?([b-fosuxX])/.exec(
          f,
        ))
    ) {
      if ((a = args[m[1] ? Number(m[1]) : i++]) == null || a == undefined) {
        throw new Error("Too few arguments.");
      }
      if (/[^s]/.test(m[7]!) && typeof a !== "number") {
        throw new Error("Expecting number but found " + typeof a);
      }

      switch (m[7]) {
        case "b":
          a = a.toString(2);
          break;
        case "c":
          a = String.fromCharCode(a);
          break;
        case "d":
          a = parseInt(a);
          break;
        case "e":
          a = m[6] ? a.toExponential(Number(m[6])) : a.toExponential();
          break;
        case "f":
          a = m[6] ? parseFloat(a).toFixed(Number(m[6])) : parseFloat(a);
          break;
        case "o":
          a = a.toString(8);
          break;
        case "s":
          a = (a = String(a)) && m[6] ? a.substring(0, Number(m[6])) : a;
          break;
        case "u":
          a = Math.abs(a);
          break;
        case "x":
          a = a.toString(16);
          break;
        case "X":
          a = a.toString(16).toUpperCase();
          break;
      }

      a = /[def]/.test(m[7]!) && m[2] && a >= 0 ? "+" + a : a;
      c = m[3] ? (m[3] == "0" ? "0" : m[3].charAt(1)) : " ";
      x = Number(m[5]) - String(a).length - s.length;
      p = m[5] ? str_repeat(c, x) : "";
      o.push(s + (m[4] ? a + p : p + a));
    } else {
      throw new Error("Huh ?!");
    }

    f = f.substring(m[0].length);
  }

  return o.join("");
}

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

interface TemporaryStateAttrChange {
  el: Element;
  attribute: string;
  value: string | null;
}

interface TemporaryStateClassChange {
  el: Element;
  state: boolean;
  class: string;
}

interface TemporaryStateHtmlChange {
  el: Element;
  html: string;
}

function toElements(target: Element | ArrayLike<Element>): Element[] {
  return target instanceof Element ? [target] : Array.from(target);
}

// Class to implement a temporary state and reverse it
//
// Converted off jQuery with tags.ts (P49-A) -- its other real caller,
// group_list.ts, is still unconverted, so its own 8 call sites pass
// `document.querySelectorAll(...)` in place of their prior `$(...)`
// without the rest of that file changing.
export class TemporaryState {
  attrChanges: TemporaryStateAttrChange[];
  classChanges: TemporaryStateClassChange[];
  htmlChanges: TemporaryStateHtmlChange[];

  constructor() {
    //Arrays to reverse changes
    this.attrChanges = []; //Attribute changes : {el, attribute, value}
    this.classChanges = []; //Class changes : {el, state(add:true/remove:false), class}
    this.htmlChanges = []; //Html changes : {el, html}
  }

  /** Change temporarily an attribute of every element in the set. */
  changeAttribute(
    target: Element | ArrayLike<Element>,
    attrName: string,
    tempVal: string,
  ): void {
    for (const el of toElements(target)) {
      this.attrChanges.push({
        el,
        attribute: attrName,
        value: el.getAttribute(attrName),
      });
      el.setAttribute(attrName, tempVal);
    }
  }

  /** Add/remove a class temporarily on every element in the set. */
  changeClass(
    target: Element | ArrayLike<Element>,
    st: boolean,
    tempclass: string,
  ): void {
    for (const el of toElements(target)) {
      if (!(el.classList.contains(tempclass) && st)) {
        this.classChanges.push({ el, state: !st, class: tempclass });
        if (st) el.classList.add(tempclass);
        else el.classList.remove(tempclass);
      }
    }
  }

  /** Add a class temporarily to every element in the set. */
  addClass(target: Element | ArrayLike<Element>, tempclass: string): void {
    this.changeClass(target, true, tempclass);
  }

  /** Remove a class temporarily from every element in the set. */
  removeClass(target: Element | ArrayLike<Element>, tempclass: string): void {
    this.changeClass(target, false, tempclass);
  }

  /**
   * Change temporarily the HTML of every element in the set (removes
   * event handlers on the actual content).
   */
  changeHTML(target: Element | ArrayLike<Element>, temphtml: string): void {
    for (const el of toElements(target)) {
      this.htmlChanges.push({ el, html: el.innerHTML });
      el.innerHTML = temphtml;
    }
  }

  /** Reverse all the changes and clear the history. */
  reverse(): void {
    this.attrChanges.forEach(function (change) {
      if (change.value === null) {
        change.el.removeAttribute(change.attribute);
      } else {
        change.el.setAttribute(change.attribute, change.value);
      }
    });
    this.classChanges.forEach(function (change) {
      if (change.state) change.el.classList.add(change.class);
      else change.el.classList.remove(change.class);
    });
    this.htmlChanges.forEach(function (change) {
      change.el.innerHTML = change.html;
    });
    this.attrChanges = [];
    this.classChanges = [];
    this.htmlChanges = [];
  }
}

// `draggable`/`theme`/`animation`/`useBootstrap`/`animateFromElement`/
// `typeAnimated`/`backgroundDismiss` all dropped from these 4 presets:
// every real call site across the whole app set them to the exact same
// values (confirmed via a full grep, not assumed), so `themes/default/js/
// vendor/jconfirm.ts`'s own port of `$.confirm`/`$.alert` (P49-B group 5)
// hardcodes them instead of taking them as options at all.
export const jConfirm_alert_options = {
  icon: "icon-ok",
  titleClass: "jconfirmAlert",
  closeIcon: true,
  boxWidth: "20%",
};

export const jConfirm_confirm_options = {
  titleClass: "jconfirmDeleteConfirm",
  boxWidth: "40%",
  type: "red",
};

export const jConfirm_warning_options = {
  icon: "icon-attention",
  titleClass: "jconfirmWarning jconfirmAlert",
  type: "orange",
  closeIcon: true,
  boxWidth: "20%",
};

export const jConfirm_confirm_with_content_options = {
  boxWidth: "40%",
  type: "red",
};

export function pwg_jconfirm_follow_href(
  el: Element,
  {
    alert_title = "TITLE",
    alert_confirm = "CONFIRM",
    alert_cancel = "CANCEL",
    alert_content = "",
  }: {
    alert_title?: string;
    alert_confirm?: string;
    alert_cancel?: string;
    alert_content?: string;
  } = {},
): void {
  const button_href = attrOf(el, "href");
  const options =
    alert_content === ""
      ? jConfirm_confirm_options
      : jConfirm_confirm_with_content_options;
  on(el, "click", (e) => {
    e.preventDefault();
    confirm({
      content: alert_content,
      title: alert_title,
      buttons: {
        confirm: {
          text: alert_confirm,
          btnClass: "btn-red",
          action: function () {
            window.location.href = button_href!;
          },
        },
        cancel: {
          text: alert_cancel,
        },
      },
      ...options,
    });
  });
}

// getRandomInt/sprintf/jConfirm_alert_options/jConfirm_confirm_options/
// jConfirm_warning_options/TemporaryState are real exports now
// (docs/PLAN.md P48) -- every real consumer imports them directly, no
// more `window.` latching. array_delete had zero real callers anywhere
// and was deleted outright. str_repeat/jConfirm_confirm_with_content_options
// stay module-private -- each has exactly one real caller, both inside
// this same file (sprintf() and pwg_jconfirm_follow_href() above).
