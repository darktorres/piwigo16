import { sprintf } from "./sprintf";
import { data, find, html, off, on, setVal } from "./vendor/dom";
import {
  slider,
  type SliderOptions,
  type SliderUIParams,
} from "./vendor/slider";

/**
 * Real first-party wrapper around `themes/default/js/vendor/slider.ts`'s
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
    const minIdx = ui.values![0]!;
    const maxIdx = ui.values![1]!;
    setVal(
      find(container, "[data-input=min]"),
      String(options.values[minIdx]!),
    );
    setVal(
      find(container, "[data-input=max]"),
      String(options.values[maxIdx]!),
    );
    html(
      find(container, ".slider-info"),
      sprintf(options.text, options.values[minIdx]!, options.values[maxIdx]!),
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

  const sliderEl = find(container, ".slider-slider")[0]!;
  const sliderOptions: SliderOptions = {
    range: true,
    min: 0,
    max: options.values.length - 1,
    values,
    slide: onChange,
    change: onChange,
  };
  if (options.stop) {
    sliderOptions.stop = () => {
      options.stop!();
    };
  }
  slider(sliderEl, sliderOptions);

  const choices = find(container, ".slider-choice");
  off(choices, "click");
  on(choices, "click", function (this: Element): void {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
    const min = data(this, "min") as number;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
    const max = data(this, "max") as number;
    slider(sliderEl, "values", 0, options.values.indexOf(min));
    slider(sliderEl, "values", 1, options.values.indexOf(max));
  });
}
