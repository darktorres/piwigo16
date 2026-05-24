import '../css/pages/watermark.css';
import { getPageData } from './page-data';

interface ConfigurationWatermarkPageData {
    root_url: string;
}

const { root_url } = getPageData<ConfigurationWatermarkPageData>();

function onWatermarkChange(): void {
    const wSelect = document.getElementById('wSelect') as HTMLSelectElement | null;
    const wImg = document.getElementById('wImg') as HTMLImageElement | null;
    if (!wSelect || !wImg) return;
    const val = wSelect.value;
    if (val.length) {
        wImg.setAttribute('src', root_url + val);
        wImg.style.display = '';
    } else {
        wImg.style.display = 'none';
    }
}

onWatermarkChange();
document.getElementById('wSelect')?.addEventListener('change', onWatermarkChange);

const checkedPosition = document.querySelector<HTMLInputElement>(
    "input[name='w[position]']:checked"
);
if (checkedPosition?.value === 'custom') {
    const posCustomDetails = document.getElementById('positionCustomDetails');
    if (posCustomDetails) posCustomDetails.style.display = '';
}

document.querySelectorAll<HTMLInputElement>("input[name='w[position]']").forEach((radio) => {
    radio.addEventListener('change', function (this: HTMLInputElement) {
        const posCustomDetails = document.getElementById('positionCustomDetails');
        if (posCustomDetails) {
            posCustomDetails.style.display = this.value === 'custom' ? '' : 'none';
        }
    });
});

document.querySelectorAll<HTMLElement>('.addWatermarkOpen').forEach((btn) => {
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        ['addWatermark', 'selectWatermark'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
        });
    });
});
