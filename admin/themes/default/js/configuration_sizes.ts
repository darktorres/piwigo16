import { initModule } from './moduleInit.js';
import { pwgConfirmFollowHref } from './pwgConfirm.js';

interface ConfigSizesConfig {
    titleMsg?: string;
    confirmMsg?: string;
    cancelMsg?: string;
    labelMaxWidth?: string;
    labelWidth?: string;
    labelMaxHeight?: string;
    labelHeight?: string;
}

export function init(cfg: ConfigSizesConfig): void {
    const { titleMsg = '', confirmMsg = 'Yes', cancelMsg = 'No', labelMaxWidth = '', labelWidth = '', labelMaxHeight = '', labelHeight = '' } = cfg;

    document.querySelectorAll<HTMLAnchorElement>('.restore-settings-button').forEach(function (el) {
        pwgConfirmFollowHref(el, {
            alert_title: titleMsg,
            alert_confirm: confirmMsg,
            alert_cancel: cancelMsg,
        });
    });

    function toggleResizeFields(): void {
        const checkbox = document.querySelector<HTMLInputElement>('[name=original_resize]');
        const needToggle = document.getElementById('sizeEdit-original');
        if (!checkbox || !needToggle) return;
        needToggle.style.display = checkbox.checked ? '' : 'none';
    }

    toggleResizeFields();
    const originalResizeCheckbox = document.querySelector<HTMLInputElement>('[name=original_resize]');
    if (originalResizeCheckbox) {
        originalResizeCheckbox.addEventListener('click', toggleResizeFields);
    }

    document.querySelectorAll<HTMLAnchorElement>("a[id^='sizeEditOpen-']").forEach(function (el) {
        el.addEventListener('click', function (event) {
            event.preventDefault();
            const sizeName = el.id.split('-')[1];
            const sizeEdit = document.getElementById('sizeEdit-' + sizeName);
            if (sizeEdit) sizeEdit.style.display = sizeEdit.style.display === 'none' || sizeEdit.style.display === '' ? 'block' : 'none';
            el.style.display = 'none';
        });
    });

    document.querySelectorAll<HTMLInputElement>('.cropToggle').forEach(function (el) {
        el.addEventListener('click', function () {
            const form = el.closest<HTMLElement>('table.sizeEditForm');
            if (!form) return;
            const labelBoxWidth = form.querySelector<HTMLElement>('td.sizeEditWidth');
            const labelBoxHeight = form.querySelector<HTMLElement>('td.sizeEditHeight');
            if (el.checked) {
                if (labelBoxWidth) labelBoxWidth.innerHTML = labelWidth;
                if (labelBoxHeight) labelBoxHeight.innerHTML = labelHeight;
            } else {
                if (labelBoxWidth) labelBoxWidth.innerHTML = labelMaxWidth;
                if (labelBoxHeight) labelBoxHeight.innerHTML = labelMaxHeight;
            }
        });
    });

    const showDetailsBtn = document.getElementById('showDetails');
    if (showDetailsBtn) {
        showDetailsBtn.addEventListener('click', function (event) {
            event.preventDefault();
            document.querySelectorAll<HTMLElement>('.sizeDetails').forEach(el => { el.style.display = ''; });
            showDetailsBtn.style.visibility = 'hidden';
        });
    }
}

initModule(init as (cfg: Record<string, unknown>) => void);
