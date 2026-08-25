export {};

(function ($: JQueryStatic) {
  // eslint-disable-next-line @typescript-eslint/unbound-method -- jQuery.noop is a real no-op, never reads `this`; safe to assign bare.
  jQuery.timepicker.log = jQuery.noop; // that's ugly, but the timepicker is acting weird and throws parsing errors

  // modify DatePicker internal methods to replace year select by a numeric input
  // eslint-disable-next-line @typescript-eslint/unbound-method -- saved only to `.call(this, ...)` explicitly below, every time; never invoked detached from a receiver.
  const origGenerateMonthYearHeader = $.datepicker._generateMonthYearHeader,
    // eslint-disable-next-line @typescript-eslint/unbound-method -- same as above.
    origSelectMonthYear = $.datepicker._selectMonthYear;

  $.datepicker._generateMonthYearHeader = function (
    // The internal per-instance state bag jQuery UI's datepicker engine
    // keeps -- undocumented, no real type source; genuinely irreducible
    // (this file never reads a property off it directly, only forwards
    // it to the original implementation).
    inst: any,
    drawMonth: number,
    drawYear: number,
    minDate: Date | null,
    maxDate: Date | null,
    secondary: boolean,
    monthNames: string[],
    monthNamesShort: string[],
  ) {
    const html = origGenerateMonthYearHeader.call(
      this,
      inst,
      drawMonth,
      drawYear,
      minDate,
      maxDate,
      secondary,
      monthNames,
      monthNamesShort,
    );

    const yearshtml =
      "<input type='number' class='ui-datepicker-year' data-handler='selectYear' data-event='change keyup' value='" +
      drawYear +
      "' style='width:4em;margin-left:2px;'>";

    return html.replace(
      new RegExp("<select class='ui-datepicker-year'.*</select>", "gm"),
      yearshtml,
    );
  };

  // The datepicker widget's own internal `this` context -- undocumented,
  // no real npm type; only these 3 methods are actually used here.
  interface DatepickerWidgetContext {
    _getInst(target: Element | undefined): any;
    _notifyChange(inst: any): void;
    _adjustDate(target: JQuery): void;
  }

  $.datepicker._selectMonthYear = debounce(function (
    this: DatepickerWidgetContext,
    id: string,
    select: HTMLInputElement | HTMLSelectElement,
    period: "M" | "Y",
  ) {
    if (period === "M") {
      origSelectMonthYear.call(this, id, select, period);
    } else {
      const target = $(id),
        inst = this._getInst(target[0]),
        val = parseInt(select.value, 10);

      if (isNaN(val)) {
        inst["drawYear"] = "";
      } else {
        inst["selectedYear"] = inst["drawYear"] = val;

        this._notifyChange(inst);
        this._adjustDate(target);

        $(".ui-datepicker-year").focus();
      }
    }
  }, 500);

  interface PwgDatepickerResolvedOptions extends PwgDatepickerSettings {
    showTimepicker: boolean;
    cancelButton: string | false;
    beforeShow?: () => void;
    onChangeMonthYear?: () => void;
  }

  // plugin definition
  jQuery.fn.pwgDatepicker = function (
    this: JQuery,
    settings?: PwgDatepickerSettings,
  ) {
    // jQuery.extend's own typed overloads can't statically express a
    // 3-object deep merge -- real shape confirmed by tracing every
    // pwgDatepicker() call site (batchManagerGlobal.ts/
    // batchManagerUnit.ts/picture_modify.ts/history.ts).
    const options = jQuery.extend(
      true,
      {
        showTimepicker: false,
        cancelButton: false,
      },
      settings || {},
    ) as PwgDatepickerResolvedOptions;

    return this.each(function () {
      const $this = jQuery(this);
      let originalValue = $this.val();
      // eslint-disable-next-line prefer-const -- declared here (not at its own real assignment further down) so the `options.beforeShow` closure above can close over the same binding; assigned exactly once, but genuinely can't be `const` given where it's read.
      let originalDate: Date | null;
      const $target = jQuery('[name="' + $this.data("datepicker") + '"]');
      const linked = !!$target.length;
      let $start: JQuery | undefined, $end: JQuery | undefined;

      if (linked) {
        originalValue = $target.val();
      }

      // custom setter
      function set(date: Date | string | null, init: boolean) {
        if (date === "") date = null;
        $this.datetimepicker("setDate", date);

        if ($this.data("datepicker-start") && $start) {
          $start.datetimepicker("option", "maxDate", date);
        } else if ($this.data("datepicker-end") && $end) {
          if (!init) {
            // on init, "end" is not initialized yet (assuming "start" is before "end" in the DOM)
            $end.datetimepicker("option", "minDate", date);
          }
        }

        if (!date && linked) {
          $target.val("");
        }
      }

      // and custom cancel button
      if (options.cancelButton) {
        options.beforeShow = options.onChangeMonthYear = function () {
          setTimeout(function () {
            const buttonPane = $this
              .datepicker("widget")
              .find(".ui-datepicker-buttonpane");

            if (buttonPane.find(".pwg-datepicker-cancel").length == 0) {
              $('<button type="button">' + options.cancelButton + "</button>")
                .on("click", function () {
                  set(originalDate, false);
                  $this.datepicker("hide").blur();
                })
                .addClass("pwg-datepicker-cancel ui-state-error ui-corner-all")
                .appendTo(buttonPane);
            }
          }, 1);
        };
      }

      // init picker
      $this.datetimepicker(
        jQuery.extend(
          {
            dateFormat: linked ? "DD d MM yy" : "yy-mm-dd",
            timeFormat: "HH:mm",
            separator: options.showTimepicker ? " " : "",

            altField: linked ? $target : null,
            altFormat: "yy-mm-dd",
            altTimeFormat: options.showTimepicker ? "HH:mm:ss" : "",

            autoSize: true,
            changeMonth: true,
            changeYear: true,
            altFieldTimeOnly: false,
            showSecond: false,
            alwaysSetTime: false,
          },
          options,
        ),
      );

      // attach range pickers
      if ($this.data("datepicker-start")) {
        $start = jQuery(
          '[data-datepicker="' + $this.data("datepicker-start") + '"]',
        );

        $this.datetimepicker("option", "onClose", function (date: Date | null) {
          $start!.datetimepicker("option", "maxDate", date);
        });

        $this.datetimepicker(
          "option",
          "minDate",
          $start.datetimepicker("getDate"),
        );
      } else if ($this.data("datepicker-end")) {
        $end = jQuery(
          '[data-datepicker="' + $this.data("datepicker-end") + '"]',
        );

        $this.datetimepicker("option", "onClose", function (date: Date | null) {
          $end!.datetimepicker("option", "minDate", date);
        });
      }

      // attach unset button
      if ($this.data("datepicker-unset")) {
        jQuery("#" + $this.data("datepicker-unset")).on("click", function (e) {
          e.preventDefault();
          set(null, false);
        });
      }

      // set value from linked input
      if (linked) {
        const splitted = String(originalValue).split(" ");
        if (splitted.length == 2 && options.showTimepicker) {
          set(
            jQuery.datepicker.parseDateTime(
              "yy-mm-dd",
              "HH:mm:ss",
              String(originalValue),
            ),
            true,
          );
        } else if (splitted[0]!.length == 10) {
          set(jQuery.datepicker.parseDate("yy-mm-dd", splitted[0]!), true);
        } else {
          set(null, true);
        }
      }

      originalDate = $this.datetimepicker("getDate");

      // autoSize not handled by timepicker
      if (options.showTimepicker) {
        $this.attr("size", parseInt($this.attr("size")!) + 6);
      }
    });
  };

  // Generic pass-through utility -- wraps any function signature (here,
  // `_selectMonthYear`'s own 3-arg one), so `args`/`this` genuinely stay
  // unconstrained by design, not narrowed to a specific call site's shape.
  function debounce(
    func: (...args: any[]) => void,
    wait: number,
    immediate?: boolean,
  ) {
    let timeout: ReturnType<typeof setTimeout> | undefined;
    return function (this: unknown, ...args: any[]) {
      // eslint-disable-next-line @typescript-eslint/no-this-alias -- the classic callback-closure idiom: `this` needs to stay reachable inside later(), which has its own `this`.
      const context = this;
      const later = function () {
        timeout = undefined;
        if (!immediate) func.apply(context, args);
      };
      const callNow = immediate && !timeout;
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
      if (callNow) func.apply(context, args);
    };
  }
})(jQuery);
