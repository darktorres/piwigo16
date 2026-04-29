import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';

interface PwgDatepickerOptions {
    showTimepicker?: boolean;
    cancelButton?: string | false;
    [key: string]: unknown;
}

function pwgDatepicker(el: HTMLElement, settings: PwgDatepickerOptions = {}): void {
    const options = { showTimepicker: false, cancelButton: false, ...settings };
    const targetName = (el as HTMLElement).dataset['datepicker'];
    const targetEl = targetName ? document.querySelector<HTMLInputElement>(`[name="${targetName}"]`) : null;
    const linked = !!targetEl;

    let originalValue = String(linked ? (targetEl!.value ?? '') : (el as HTMLInputElement).value ?? '');

    const fpOptions: flatpickr.Options.Options = {
        enableTime: !!options.showTimepicker,
        dateFormat: linked ? 'l, j F Y' + (options.showTimepicker ? ' H:i' : '') : 'Y-m-d' + (options.showTimepicker ? ' H:i' : ''),
        altInput: linked,
        altFormat: 'Y-m-d' + (options.showTimepicker ? ' H:i:S' : ''),
        altInputClass: linked ? (el as HTMLInputElement).className : '',
        allowInput: true,
        onChange: linked ? [(selectedDates, dateStr) => {
            if (targetEl) targetEl.value = dateStr ? dateStr.split(' ')[0] : '';
        }] : [],
    };

    // Link start/end date pickers
    const startName = (el as HTMLElement).dataset['datepickerStart'];
    const endName = (el as HTMLElement).dataset['datepickerEnd'];

    if (startName) {
        const startEl = document.querySelector<HTMLElement>(`[data-datepicker="${startName}"]`);
        if (startEl) {
            const startFp = (startEl as any)._flatpickr as flatpickr.Instance | undefined;
            if (startFp) fpOptions.minDate = startFp.selectedDates[0] ?? undefined;
            fpOptions.onClose = [(_dates, dateStr) => {
                if (startFp) startFp.set('maxDate', dateStr || undefined);
            }];
        }
    } else if (endName) {
        const endEl = document.querySelector<HTMLElement>(`[data-datepicker="${endName}"]`);
        if (endEl) {
            const endFp = (endEl as any)._flatpickr as flatpickr.Instance | undefined;
            fpOptions.onClose = [(_dates, dateStr) => {
                if (endFp) endFp.set('minDate', dateStr || undefined);
            }];
        }
    }

    const fp = flatpickr(el, fpOptions);
    (el as any)._flatpickr = fp;

    // Set initial value
    if (linked && originalValue) {
        const parts = originalValue.split(' ');
        if (parts[0].length === 10) {
            fp.setDate(originalValue, false);
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
    document.querySelectorAll<HTMLElement>('[data-datepicker]').forEach(el => {
        pwgDatepicker(el);
    });
});

export {};
