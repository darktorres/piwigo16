import Cropper from 'cropperjs';

interface CoiData { l: number; t: number; r: number; b: number }

function from_coi(f: number, total: number): number { return f * total; }
function to_coi(v: number, total: number): number { return v / total; }

function jOnChange(detail: { x: number; y: number; width: number; height: number }): void {
    const img = document.getElementById('jcrop') as HTMLImageElement | null;
    if (!img) return;
    const w = img.naturalWidth;
    const h = img.naturalHeight;
    const fields: Record<string, number> = {
        l: detail.x, t: detail.y, r: detail.x + detail.width, b: detail.y + detail.height,
    };
    Object.keys(fields).forEach(function (id) {
        const el = document.getElementById(id) as HTMLInputElement | null;
        if (el) el.value = String(to_coi(fields[id]!, (id === 'l' || id === 'r') ? w : h));
    });
}

function jOnRelease(): void {
    document.querySelectorAll<HTMLInputElement>('#l,#t,#r,#b').forEach(el => { el.value = ''; });
}

const img = document.getElementById('jcrop') as HTMLImageElement | null;
if (img) {
    const coiRaw = img.dataset['coi'];
    const coiData: CoiData | null = coiRaw ? JSON.parse(coiRaw) as CoiData : null;

    const cropper = new Cropper(img);
    const cropperImage = cropper.getCropperImage();
    const cropperSelection = cropper.getCropperSelection();

    cropperSelection?.addEventListener('change', function (event) {
        const sel = event as CustomEvent<{ x: number; y: number; width: number; height: number }>;
        jOnChange(sel.detail);
    });

    if (coiData && cropperImage) {
        void cropperImage.$ready(function () {
            cropperSelection?.$change(
                from_coi(coiData.l, img.naturalWidth),
                from_coi(coiData.t, img.naturalHeight),
                from_coi(coiData.r, img.naturalWidth) - from_coi(coiData.l, img.naturalWidth),
                from_coi(coiData.b, img.naturalHeight) - from_coi(coiData.t, img.naturalHeight),
            );
        });
    }

    document.getElementById('jcrop-clear')?.addEventListener('click', function () {
        cropperSelection?.$clear();
        jOnRelease();
    });
}
