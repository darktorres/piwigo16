import noUiSlider from 'nouislider';
import { sprintf } from './common.js';

interface DoubleSliderOptions {
    values: number[];
    selected: { min: number; max: number };
    text: string;
}

export function pwgDoubleSlider(containerEl: HTMLElement, options: DoubleSliderOptions): HTMLElement {
    const sliderEl = containerEl.querySelector<HTMLElement>('.slider-slider')!;

    function findClosest(array: number[], value: number): number {
        let closest: number | null = null;
        let index = -1;
        array.forEach(function (v, i) {
            if (closest === null || Math.abs(v - value) < Math.abs(closest - value)) {
                closest = v;
                index = i;
            }
        });
        return index;
    }

    let startLo = options.values.indexOf(options.selected.min);
    let startHi = options.values.indexOf(options.selected.max);
    if (startLo === -1) startLo = findClosest(options.values, options.selected.min);
    if (startHi === -1) startHi = findClosest(options.values, options.selected.max);

    const api = noUiSlider.create(sliderEl, {
        start: [startLo, startHi],
        range: { min: 0, max: options.values.length - 1 },
        step: 1,
        connect: true,
    });

    function onChange(values: (string | number)[]): void {
        const lo = Math.round(parseFloat(String(values[0])));
        const hi = Math.round(parseFloat(String(values[1])));
        const minEl = containerEl.querySelector<HTMLInputElement>('[data-input=min]');
        const maxEl = containerEl.querySelector<HTMLInputElement>('[data-input=max]');
        const infoEl = containerEl.querySelector<HTMLElement>('.slider-info');
        if (minEl) minEl.value = String(options.values[lo]!);
        if (maxEl) maxEl.value = String(options.values[hi]!);
        if (infoEl) infoEl.innerHTML = sprintf(options.text, options.values[lo]!, options.values[hi]!);
    }

    api.on('update', onChange);

    containerEl.querySelectorAll<HTMLElement>('.slider-choice').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const minIdx = options.values.indexOf(Number(btn.dataset['min']));
            const maxIdx = options.values.indexOf(Number(btn.dataset['max']));
            api.set([minIdx, maxIdx]);
        });
    });

    return containerEl;
}
