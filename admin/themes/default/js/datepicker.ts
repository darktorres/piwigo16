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
    const targetName = (el as HTMLElement).dataset['datepicker'];
    const targetEl = targetName
        ? document.querySelector<HTMLInputElement>(`[name="${targetName}"]`)
        : null;
    const linked = !!targetEl;

    let originalValue = String(
        linked ? (targetEl!.value ?? '') : ((el as HTMLInputElement).value ?? '')
    );
    // Normalize date values to match the configured format
    if (/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/.test(originalValue)) {
        if (options.showTimepicker) {
            // Ensure 'YYYY-MM-DD HH:MM' — append 00:00 if only date present
            originalValue =
                originalValue.length === 10 ? originalValue + ' 00:00' : originalValue.slice(0, 16);
        } else {
            originalValue = originalValue.slice(0, 10); // 'YYYY-MM-DD'
        }
        if (!linked) (el as HTMLInputElement).value = originalValue;
    }

    const fpOptions: flatpickr.Options.Options = {
        enableTime: !!options.showTimepicker,
        dateFormat: linked
            ? 'l, j F Y' + (options.showTimepicker ? ' H:i' : '')
            : 'Y-m-d' + (options.showTimepicker ? ' H:i' : ''),
        altInput: linked,
        altFormat: 'Y-m-d' + (options.showTimepicker ? ' H:i:S' : ''),
        altInputClass: linked ? (el as HTMLInputElement).className : '',
        allowInput: true,
        onChange: linked
            ? [
                  (selectedDates, dateStr) => {
                      if (targetEl) targetEl.value = dateStr ? dateStr.split(' ')[0] : '';
                  },
              ]
            : [],
    };

    // Link start/end date pickers
    const startName = (el as HTMLElement).dataset['datepickerStart'];
    const endName = (el as HTMLElement).dataset['datepickerEnd'];

    if (startName) {
        const startEl = document.querySelector<HTMLElement>(`[data-datepicker="${startName}"]`);
        if (startEl) {
            const startFp = (startEl as any)._flatpickr as flatpickr.Instance | undefined;
            if (startFp) fpOptions.minDate = startFp.selectedDates[0] ?? undefined;
            fpOptions.onClose = [
                (_dates, dateStr) => {
                    if (startFp) startFp.set('maxDate', dateStr || undefined);
                },
            ];
        }
    } else if (endName) {
        const endEl = document.querySelector<HTMLElement>(`[data-datepicker="${endName}"]`);
        if (endEl) {
            const endFp = (endEl as any)._flatpickr as flatpickr.Instance | undefined;
            fpOptions.onClose = [
                (_dates, dateStr) => {
                    if (endFp) endFp.set('minDate', dateStr || undefined);
                },
            ];
        }
    }

    const fp = flatpickr(el, fpOptions);
    (el as any)._flatpickr = fp;

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
    if (linked && originalValue) {
        const parts = originalValue.split(' ');
        if (parts[0].length === 10) {
            const isoFormat = 'Y-m-d' + (options.showTimepicker ? ' H:i' : '');
            fp.setDate(originalValue, false, isoFormat);
        }
    }

    // Cancel button (unset button)
    const unsetId = (el as HTMLElement).dataset['datepickerUnset'];
    if (unsetId) {
        document.getElementById(unsetId)?.addEventListener('click', (e) => {
            e.preventDefault();
            fp.clear();
            if (linked && targetEl) targetEl.value = '';
        });
    }

    if (options.showTimepicker) {
        const sizeAttr = (el as HTMLInputElement).size;
        if (sizeAttr) (el as HTMLInputElement).size = sizeAttr + 6;
    }
}

(window as any).pwgDatepicker = pwgDatepicker;

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll<HTMLElement>('[data-datepicker]').forEach((el) => {
        pwgDatepicker(el);
    });
});

export {};
