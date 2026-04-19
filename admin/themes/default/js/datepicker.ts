import flatpickr from 'flatpickr';
import type { Options } from 'flatpickr/dist/types/options';

interface DatepickerOptions {
    showTimepicker?: boolean;
    cancelButton?: string | false;
}

export function pwgDatepicker(
    target: NodeList | Element[] | Element,
    options?: DatepickerOptions,
): void {
    const opts: Required<DatepickerOptions> = Object.assign({ showTimepicker: false, cancelButton: false }, options ?? {});

    let els: Element[];
    if (target instanceof NodeList) {
        els = Array.from(target) as Element[];
    } else if (Array.isArray(target)) {
        els = target;
    } else {
        els = [target];
    }

    const instances: Record<string, ReturnType<typeof flatpickr>> = {};

    els.forEach(function (el) {
        const inputEl = el as HTMLInputElement;
        const name = inputEl.dataset.datepicker;
        const hiddenEl = name ? document.querySelector<HTMLInputElement>('[name="' + name + '"]') : null;
        const initialValue = hiddenEl ? hiddenEl.value.trim() : '';
        let originalDate: Date | null = null;

        const fpConfig: Options = {
            enableTime: !!opts.showTimepicker,
            dateFormat: opts.showTimepicker ? 'D, j M Y H:i' : 'D, j M Y',
            time_24hr: true,
            allowInput: false,
            onOpen: function (selectedDates) {
                originalDate = selectedDates[0] ? new Date(selectedDates[0].getTime()) : null;
            },
            onChange: function (selectedDates) {
                const d = selectedDates[0] ?? null;
                if (hiddenEl) {
                    hiddenEl.value = d ? formatMachine(d, !!opts.showTimepicker) : '';
                }
                const endName = inputEl.dataset.datepickerEnd;
                if (endName && instances[endName]) {
                    (instances[endName] as { set(k: string, v: Date | null): void }).set('minDate', d ?? null);
                }
                const startName = inputEl.dataset.datepickerStart;
                if (startName && instances[startName]) {
                    (instances[startName] as { set(k: string, v: Date | null): void }).set('maxDate', d ?? null);
                }
            },
        };

        if (opts.cancelButton) {
            fpConfig.onReady = function (_selectedDates, _dateStr, fp) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = opts.cancelButton as string;
                btn.className = 'pwg-flatpickr-cancel';
                btn.style.cssText = 'display:block;width:100%;margin-top:4px;padding:2px 8px;cursor:pointer;';
                btn.addEventListener('click', function () {
                    fp.setDate(originalDate as Date, true);
                    fp.close();
                });
                fp.calendarContainer.appendChild(btn);
            };
        }

        const fpInstance = flatpickr(inputEl, fpConfig);

        if (initialValue) {
            fpInstance.setDate(initialValue, false, opts.showTimepicker ? 'Y-m-d H:i:S' : 'Y-m-d');
        }

        if (name) instances[name] = fpInstance;

        const unsetId = inputEl.dataset.datepickerUnset;
        if (unsetId) {
            const unsetBtn = document.getElementById(unsetId);
            if (unsetBtn) {
                unsetBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    fpInstance.clear();
                    if (hiddenEl) hiddenEl.value = '';
                });
            }
        }
    });

    els.forEach(function (el) {
        const inputEl = el as HTMLInputElement;
        const name = inputEl.dataset.datepicker;
        const fp = name ? instances[name] : null;
        if (!fp) return;
        const startName = inputEl.dataset.datepickerStart;
        if (startName && instances[startName]) {
            const fpTyped = instances[startName] as { selectedDates: Date[] };
            const startDate = fpTyped.selectedDates[0];
            if (startDate) (fp as { set(k: string, v: Date): void }).set('minDate', startDate);
        }
    });
}

function pad2(n: number): string { return n < 10 ? '0' + String(n) : String(n); }

function formatMachine(d: Date, withTime: boolean): string {
    const date = String(d.getFullYear()) + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    if (!withTime) return date;
    return date + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
}
