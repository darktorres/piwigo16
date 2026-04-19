import { initModule } from './moduleInit.js';

interface ConfigWatermarkConfig {
    rootUrl?: string;
}

export function init(cfg: ConfigWatermarkConfig): void {
    const { rootUrl = '' } = cfg;

    function onWatermarkChange(): void {
        const wSelect = document.getElementById('wSelect') as HTMLSelectElement | null;
        const wImg = document.getElementById('wImg') as HTMLImageElement | null;
        if (!wSelect || !wImg) return;
        const val = wSelect.value;
        if (val.length) {
            wImg.setAttribute('src', rootUrl + val);
            wImg.style.display = '';
        } else {
            wImg.style.display = 'none';
        }
    }

    onWatermarkChange();

    const wSelect = document.getElementById('wSelect');
    if (wSelect) wSelect.addEventListener('change', onWatermarkChange);

    const positionChecked = document.querySelector<HTMLInputElement>("input[name='w[position]']:checked");
    const positionCustomDetails = document.getElementById('positionCustomDetails');
    if (positionChecked && positionCustomDetails) {
        positionCustomDetails.style.display = positionChecked.value === 'custom' ? '' : 'none';
    }

    document.querySelectorAll<HTMLInputElement>("input[name='w[position]']").forEach(function (el) {
        el.addEventListener('change', function () {
            const pcd = document.getElementById('positionCustomDetails');
            if (pcd) pcd.style.display = (this).value === 'custom' ? '' : 'none';
        });
    });

    document.querySelectorAll<HTMLElement>('.addWatermarkOpen').forEach(function (el) {
        el.addEventListener('click', function (event) {
            event.preventDefault();
            const addWatermark = document.getElementById('addWatermark');
            const selectWatermark = document.getElementById('selectWatermark');
            if (addWatermark) addWatermark.style.display = addWatermark.style.display === 'none' ? '' : 'none';
            if (selectWatermark) selectWatermark.style.display = selectWatermark.style.display === 'none' ? '' : 'none';
        });
    });
}

initModule(init as (cfg: Record<string, unknown>) => void);
