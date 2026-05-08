import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';
import '../css/components/flatpickr.css';

interface PwgDatepickerOptions {
    showTimepicker?: boolean;
    cancelButton?: string | false;
    [key: string]: unknown;
}

function pwgDatepicker(el: HTMLElement, settings: PwgDatepickerOptions = {}): void {
    const options = { showTimepicker: false, cancelButton: false, ...settings };
    const targetName = el.dataset['datepicker'];
    const targetEl =
        targetName !== undefined && targetName !== ''
            ? document.querySelector<HTMLInputElement>(`[name="${targetName}"]`)
            : null;
    const linked = targetEl !== null;

    let originalValue = String(linked ? targetEl.value : (el as HTMLInputElement).value);
    // Normalize date values to match the configured format
    if (/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/.test(originalValue)) {
        if (options.showTimepicker === true) {
            // Ensure 'YYYY-MM-DD HH:MM' — append 00:00 if only date present
            originalValue =
                originalValue.length === 10 ? originalValue + ' 00:00' : originalValue.slice(0, 16);
        } else {
            originalValue = originalValue.slice(0, 10); // 'YYYY-MM-DD'
        }
        if (!linked) (el as HTMLInputElement).value = originalValue;
    }

    const wantsTime = options.showTimepicker === true;
    const fpOptions: flatpickr.Options.Options = {
        enableTime: wantsTime,
        dateFormat: linked
            ? 'l, j F Y' + (wantsTime ? ' H:i' : '')
            : 'Y-m-d' + (wantsTime ? ' H:i' : ''),
        altInput: linked,
        altFormat: 'Y-m-d' + (wantsTime ? ' H:i:S' : ''),
        altInputClass: linked ? (el as HTMLInputElement).className : '',
        allowInput: true,
        onChange: linked
            ? [
                  (_selectedDates, dateStr) => {
                      if (targetEl !== null)
                          targetEl.value = dateStr !== '' ? dateStr.split(' ')[0] : '';
                  },
              ]
            : [],
    };

    // Link start/end date pickers
    const startName = el.dataset['datepickerStart'];
    const endName = el.dataset['datepickerEnd'];

    type FpHost = HTMLElement & { _flatpickr?: flatpickr.Instance };

    if (startName !== undefined && startName !== '') {
        const startEl = document.querySelector<HTMLElement>(`[data-datepicker="${startName}"]`);
        if (startEl !== null) {
            const startFp = (startEl as FpHost)._flatpickr;
            if (startFp !== undefined) fpOptions.minDate = startFp.selectedDates[0] ?? undefined;
            fpOptions.onClose = [
                (_dates, dateStr) => {
                    if (startFp !== undefined)
                        startFp.set('maxDate', dateStr !== '' ? dateStr : undefined);
                },
            ];
        }
    } else if (endName !== undefined && endName !== '') {
        const endEl = document.querySelector<HTMLElement>(`[data-datepicker="${endName}"]`);
        if (endEl !== null) {
            const endFp = (endEl as FpHost)._flatpickr;
            fpOptions.onClose = [
                (_dates, dateStr) => {
                    if (endFp !== undefined)
                        endFp.set('minDate', dateStr !== '' ? dateStr : undefined);
                },
            ];
        }
    }

    const fp = flatpickr(el, fpOptions);
    (el as FpHost)._flatpickr = fp;

    // Fix SVG arrow sizing - set width/height attributes on navigation SVGs
    const calendar = fp.calendarContainer;
    if (calendar) {
        calendar.querySelectorAll('svg').forEach((svg) => {
            svg.setAttribute('width', '14');
            svg.setAttribute('height', '14');
        });
    }

    // Set initial value — pass ISO format explicitly so Flatpickr doesn't try to parse
    // the ISO string with the human-readable display format
    if (linked && originalValue !== '') {
        const parts = originalValue.split(' ');
        if (parts[0].length === 10) {
            const isoFormat = 'Y-m-d' + (wantsTime ? ' H:i' : '');
            fp.setDate(originalValue, false, isoFormat);
        }
    }

    // Cancel button (unset button)
    const unsetId = el.dataset['datepickerUnset'];
    if (unsetId !== undefined && unsetId !== '') {
        document.getElementById(unsetId)?.addEventListener('click', (e) => {
            e.preventDefault();
            fp.clear();
            if (linked && targetEl !== null) targetEl.value = '';
        });
    }

    if (wantsTime) {
        const sizeAttr = (el as HTMLInputElement).size;
        if (sizeAttr > 0) (el as HTMLInputElement).size = sizeAttr + 6;
    }
}

(window as Window & { pwgDatepicker: typeof pwgDatepicker }).pwgDatepicker = pwgDatepicker;

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll<HTMLElement>('[data-datepicker]').forEach((el) => {
        pwgDatepicker(el);
    });
});

export {};
