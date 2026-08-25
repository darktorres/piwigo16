export {};

// Real declarer of `pwgDoubleSlider` (docs/PLAN.md P46-C's own sweep --
// batchManagerFilter.ts was converted first, as a consumer, using the
// ambient type already declared in build/jquery-plugins.d.ts). A
// `jQuery.fn` assignment needs no `window.X = X` exposure the way a
// bare top-level var/function would: `jQuery`/`$` itself is a real,
// unwrapped global regardless of this file's own IIFE wrapping, and
// `.fn` is its one shared prototype object every entry mutates in
// place -- same reasoning as common.ts's own `jQuery.fn.fontCheckbox`/
// `pwg_jconfirm_follow_href` and admin.ts's own `lightAccordion`.
(function ($: JQueryStatic) {
  /**
   * OPTIONS:
   * values {mixed[]}
   * selected {object} min and max
   * text {string}
   */
  $.fn.pwgDoubleSlider = function (
    this: JQuery,
    options: PwgDoubleSliderOptions,
  ) {
    // eslint-disable-next-line @typescript-eslint/no-this-alias -- the classic callback-closure idiom: `this` needs to stay reachable inside onChange(), which has its own `this`.
    const that = this;

    function onChange(_e: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
      that.find("[data-input=min]").val(options.values[ui.values![0]!]!);
      that.find("[data-input=max]").val(options.values[ui.values![1]!]!);

      that
        .find(".slider-info")
        .html(
          sprintf(
            options.text,
            options.values[ui.values![0]!]!,
            options.values[ui.values![1]!]!,
          ),
        );
    }

    function findClosest(array: number[], value: number) {
      let closest: number | null = null,
        index = -1;
      $.each(array, function (i, v) {
        if (
          closest == null ||
          Math.abs(v - value) < Math.abs(closest - value)
        ) {
          closest = v;
          index = i;
        }
      });
      return index;
    }

    const values = [
      options.values.indexOf(options.selected.min),
      options.values.indexOf(options.selected.max),
    ];
    if (values[0] == -1) {
      values[0] = findClosest(options.values, options.selected.min);
    }
    if (values[1] == -1) {
      values[1] = findClosest(options.values, options.selected.max);
    }

    const slider = this.find(".slider-slider").slider({
      range: true,
      min: 0,
      max: options.values.length - 1,
      values: values,
      slide: onChange,
      change: onChange,
    });

    this.find(".slider-choice").on("click", function () {
      slider.slider(
        "values",
        0,
        options.values.indexOf($(this).data("min") as number),
      );
      slider.slider(
        "values",
        1,
        options.values.indexOf($(this).data("max") as number),
      );
    });

    return this;
  };
})(jQuery);
