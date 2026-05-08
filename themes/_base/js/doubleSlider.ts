import noUiSlider from 'nouislider';
import 'nouislider/dist/nouislider.css';

declare function sprintf(fmt: string, ...args: unknown[]): string;

interface DoubleSliderOptions {
    values: Array<number | string>;
    selected: { min: number | string; max: number | string };
    text: string;
}

function findClosest(array: Array<number | string>, value: number | string): number {
    let closest: number | null = null,
        index = -1;
    array.forEach((v, i) => {
        const diff = Math.abs(Number(v) - Number(value));
        if (closest === null || diff < Math.abs(Number(closest) - Number(value))) {
            closest = Number(v);
            index = i;
        }
    });
    return index;
}

export function pwgDoubleSlider(container: HTMLElement, options: DoubleSliderOptions): void {
    const values = [
        options.values.indexOf(options.selected.min),
        options.values.indexOf(options.selected.max),
    ];
    if (values[0] === -1) values[0] = findClosest(options.values, options.selected.min);
    if (values[1] === -1) values[1] = findClosest(options.values, options.selected.max);

    const sliderEl = container.querySelector<HTMLElement>('.slider-slider')!;
    const slider = noUiSlider.create(sliderEl, {
        range: { min: 0, max: options.values.length - 1 },
        start: [values[0]!, values[1]!],
        step: 1,
        connect: true,
    });

    const update = (vals: (string | number)[]) => {
        const minIdx = parseInt(String(vals[0]));
        const maxIdx = parseInt(String(vals[1]));
        const minInput = container.querySelector<HTMLInputElement>('[data-input=min]');
        const maxInput = container.querySelector<HTMLInputElement>('[data-input=max]');
        const info = container.querySelector<HTMLElement>('.slider-info');
        if (minInput) minInput.value = String(options.values[minIdx]);
        if (maxInput) maxInput.value = String(options.values[maxIdx]);
        if (info)
            info.innerHTML = sprintf(options.text, options.values[minIdx], options.values[maxIdx]);
    };

    slider.on('update', update);

    container.querySelectorAll<HTMLElement>('.slider-choice').forEach((btn) => {
        btn.addEventListener('click', () => {
            const minIdx = options.values.indexOf(btn.dataset['min'] ?? '');
            const maxIdx = options.values.indexOf(btn.dataset['max'] ?? '');
            slider.set([minIdx, maxIdx]);
        });
    });
}
