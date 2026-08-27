jQuery.fn.fontCheckbox = function (this: JQuery): JQuery {
  /* checkbox */
  this.find("input[type=checkbox]").each(function () {
    if (!jQuery(this).is(":checked")) {
      jQuery(this).prev().toggleClass("icon-check icon-check-empty");
    }
  });
  this.find("input[type=checkbox]").on("change", function () {
    jQuery(this).prev().removeClass();
    if (!jQuery(this).is(":checked")) {
      jQuery(this).prev().addClass("icon-check-empty");
    } else {
      jQuery(this).prev().addClass("icon-check");
    }
  });

  /* radio */
  this.find("input[type=radio]").each(function () {
    if (!jQuery(this).is(":checked")) {
      jQuery(this).prev().toggleClass("icon-dot-circled icon-circle-empty");
    } else {
      jQuery(this).closest("label").addClass("selected");
    }
  });
  this.find("input[type=radio]").on("change", function () {
    jQuery(
      '.font-checkbox input[type=radio][name="' +
        jQuery(this).attr("name") +
        '"]',
    ).each(function () {
      jQuery(this).prev().removeClass();
      jQuery(this).closest("label").removeClass("selected");
      if (!jQuery(this).is(":checked")) {
        jQuery(this).prev().addClass("icon-circle-empty");
      } else {
        jQuery(this).prev().addClass("icon-dot-circled");
        jQuery(this).closest("label").addClass("selected");
      }
    });
  });
  return this;
};

// init fontChecbox everywhere
jQuery(".font-checkbox").fontCheckbox();

// str_repeat stays module-private (P48) -- sprintf() below is its only
// real caller anywhere in this codebase; array_delete (the same
// original comment's other established shared-global) had zero real
// callers anywhere, `.ts` or `.latte`, and was removed outright rather
// than exported to nothing (Legacy porting: no permanent facades).
function str_repeat(i: string, m: number): string {
  const o: string[] = [];
  for (; m > 0; o[--m] = i);
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
  min = Math.ceil(min);
  max = Math.floor(max);
  return Math.floor(Math.random() * (max - min)) + min;
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
      if (/[^s]/.test(m[7]!) && typeof a != "number") {
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

$(".search-cancel").on("click", function () {
  $(".search-input").val("");
  $(".search-input").trigger("input");
});

$(".search-input").on("input", function () {
  if ($(".search-input").val() == "") {
    $(".search-cancel").hide();
  } else {
    $(".search-cancel").show();
  }
});

interface TemporaryStateAttrChange {
  object: JQuery;
  attribute: string;
  value: string | undefined;
}

interface TemporaryStateClassChange {
  object: JQuery;
  state: boolean;
  class: string;
}

interface TemporaryStateHtmlChange {
  object: JQuery;
  html: string;
}

// Class to implement a temporary state and reverse it
export class TemporaryState {
  attrChanges: TemporaryStateAttrChange[];
  classChanges: TemporaryStateClassChange[];
  htmlChanges: TemporaryStateHtmlChange[];

  constructor() {
    //Arrays to reverse changes
    this.attrChanges = []; //Attribute changes : {object(s), attribute, value}
    this.classChanges = []; //Class changes : {object(s), state(add:true/remove:false), class}
    this.htmlChanges = []; //Html changes : {object(s), html}
  }

  /**
   * Change temporaly an attribute of an object
   * @param {Jquery Object(s)} obj HTML Object(s)
   * @param {String} attr Attribute
   * @param {String} tempVal Temporary value of the attribute
   */
  changeAttribute(obj: JQuery, attr: string, tempVal: string): void {
    for (let i = 0; i < obj.length; i++) {
      this.attrChanges.push({
        object: $(obj[i]!),
        attribute: attr,
        value: $(obj[i]!).attr(attr),
      });
    }
    obj.attr(attr, tempVal);
  }

  /**
   * Add/remove a class temporarily
   * @param {Jquery Object(s)} obj HTML Object
   * @param {Boolean} st Add (true) or Remove (false) the class
   * @param {String} loadclass Class Name
   */
  changeClass(obj: JQuery, st: boolean, tempclass: string): void {
    for (let i = 0; i < obj.length; i++) {
      if (!($(obj[i]!).hasClass(tempclass) && st)) {
        this.classChanges.push({
          object: $(obj[i]!),
          state: !st,
          class: tempclass,
        });
        if (st) $(obj[i]!).addClass(tempclass);
        else $(obj[i]!).removeClass(tempclass);
      }
    }
  }

  /**
   * Add temporarily a class to the object
   * @param {Jquery Object(s)} obj
   * @param {string} tempclass
   */
  addClass(obj: JQuery, tempclass: string): void {
    this.changeClass(obj, true, tempclass);
  }

  /**
   * Remove temporarily a class to the object
   * @param {Jquery Object(s)} obj
   * @param {string} tempclass
   */
  removeClass(obj: JQuery, tempclass: string): void {
    this.changeClass(obj, false, tempclass);
  }

  /**
   * Change temporaly the html of objects (remove event handlers on the actual content)
   * @param {Jquery Object(s)} obj
   * @param {string} temphtml
   */
  changeHTML(obj: JQuery, temphtml: string): void {
    for (let i = 0; i < obj.length; i++) {
      this.htmlChanges.push({
        object: $(obj[i]!),
        html: $(obj[i]!).html(),
      });
    }
    obj.html(temphtml);
  }

  /**
   * Reverse all the changes and clear the history
   */
  reverse(): void {
    this.attrChanges.forEach(function (change) {
      if (change.value == undefined) {
        change.object.removeAttr(change.attribute);
      } else {
        change.object.attr(change.attribute, change.value);
      }
    });
    this.classChanges.forEach(function (change) {
      if (change.state) change.object.addClass(change.class);
      else change.object.removeClass(change.class);
    });
    this.htmlChanges.forEach(function (change) {
      change.object.html(change.html);
    });
    this.attrChanges = [];
    this.classChanges = [];
    this.htmlChanges = [];
  }
}

export const jConfirm_alert_options = {
  icon: "icon-ok",
  titleClass: "jconfirmAlert",
  theme: "modern",
  closeIcon: true,
  draggable: false,
  animation: "zoom",
  boxWidth: "20%",
  useBootstrap: false,
  backgroundDismiss: true,
  animateFromElement: false,
  typeAnimated: false,
};

export const jConfirm_confirm_options = {
  draggable: false,
  titleClass: "jconfirmDeleteConfirm",
  theme: "modern",
  animation: "zoom",
  boxWidth: "40%",
  useBootstrap: false,
  type: "red",
  animateFromElement: false,
  backgroundDismiss: true,
  typeAnimated: false,
};

export const jConfirm_warning_options = {
  icon: "icon-attention",
  draggable: false,
  titleClass: "jconfirmWarning jconfirmAlert",
  theme: "modern",
  type: "orange",
  closeIcon: true,
  animation: "zoom",
  boxWidth: "20%",
  useBootstrap: false,
  backgroundDismiss: true,
  animateFromElement: false,
  typeAnimated: false,
};

export const jConfirm_confirm_with_content_options = {
  draggable: false,
  theme: "modern",
  animation: "zoom",
  boxWidth: "40%",
  useBootstrap: false,
  type: "red",
  animateFromElement: false,
  backgroundDismiss: true,
  typeAnimated: false,
};

jQuery.fn.pwg_jconfirm_follow_href = function (
  this: JQuery,
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
  const button_href = $(this).attr("href");
  const options =
    alert_content === ""
      ? jConfirm_confirm_options
      : jConfirm_confirm_with_content_options;
  $(this).click(function () {
    $.confirm({
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
    return false;
  });
};

// getRandomInt/sprintf/jConfirm_alert_options/jConfirm_confirm_options/
// jConfirm_warning_options/TemporaryState are real exports now
// (docs/PLAN.md P48) -- every real consumer imports them directly, no
// more `window.` latching. array_delete had zero real callers anywhere
// and was deleted outright. str_repeat/jConfirm_confirm_with_content_options
// stay module-private -- each has exactly one real caller, both inside
// this same file (sprintf() and pwg_jconfirm_follow_href() above).
