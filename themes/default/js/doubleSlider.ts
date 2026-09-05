import { sprintf } from "./sprintf";
import { data, find, html, off, on, setVal, valueAt } from "./vendor/utils/dom";
import {
  slider,
  type SliderOptions,
  type SliderUIParams,
} from "./vendor/widgets/slider";

/**
 * Real first-party wrapper around `themes/default/js/vendor/widgets/slider.ts`'s
 * dual-handle (`range: true`) mode -- `container` is the
 * `[data-slider=...]` element both real consumers
 * (`batch_manager/filter.ts`, `mcs.ts`) already select; the actual slider
 * track builds inside its `.slider-slider` child, and `.slider-choice`
 * preset buttons (each carrying real `data-min`/`data-max` attributes)
 * jump the slider to a specific index pair.
 *
 * `stop` is new: the original relied on jQuery UI's own custom
 * `slidestop` DOM event (`widgetEventPrefix: "slide"` + `"stop"`),
 * invisible to a native `addEventListener` -- `mcs.ts`'s own filesize
 * filter was the one real listener (`jQuery(...).on("slidestop", ...)`,
 * kept jQuery for exactly that reason during P49-B group 4's own
 * investigation). Threading a real `stop` callback through here instead
 * is the direct native equivalent, not a new capability.
 */
export interface PwgDoubleSliderOptions {
  values: number[];
  selected: { min: number; max: number };
  text: string;
  stop?: () => void;
}

export function pwgDoubleSlider(
  container: Element,
  options: PwgDoubleSliderOptions,
): void {
  function onChange(_event: Event, ui: SliderUIParams): void {
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- this dual-handle (range: true) slider's own change/slide callbacks always carry both values.
    const values = ui.values!;
    const minValue = valueAt(options.values, valueAt(values, 0));
    const maxValue = valueAt(options.values, valueAt(values, 1));
    setVal(find(container, "[data-input=min]"), String(minValue));
    setVal(find(container, "[data-input=max]"), String(maxValue));
    html(
      find(container, ".slider-info"),
      sprintf(options.text, minValue, maxValue),
    );
  }

  function findClosest(array: number[], value: number): number {
    let closest: number | null = null;
    let index = -1;
    array.forEach((v, i) => {
      if (closest === null || Math.abs(v - value) < Math.abs(closest - value)) {
        closest = v;
        index = i;
      }
    });

    return index;
  }

  const values: [number, number] = [
    options.values.indexOf(options.selected.min),
    options.values.indexOf(options.selected.max),
  ];
  if (values[0] === -1) {
    values[0] = findClosest(options.values, options.selected.min);
  }
  if (values[1] === -1) {
    values[1] = findClosest(options.values, options.selected.max);
  }

  const sliderEl = valueAt(find(container, ".slider-slider"), 0);
  const sliderOptions: SliderOptions = {
    range: true,
    min: 0,
    max: options.values.length - 1,
    values,
    slide: onChange,
    change: onChange,
  };
  const { stop } = options;
  if (stop) {
    sliderOptions.stop = () => {
      stop();
    };
  }
  slider(sliderEl, sliderOptions);

  const choices = find(container, ".slider-choice");
  off(choices, "click");
  on(choices, "click", function (this: Element): void {
    const min = data<number>(this, "min");
    const max = data<number>(this, "max");
    slider(sliderEl, "values", 0, options.values.indexOf(min));
    slider(sliderEl, "values", 1, options.values.indexOf(max));
  });
}
