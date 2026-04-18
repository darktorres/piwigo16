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
        if (el) el.value = String(to_coi(fields[id], (id === 'l' || id === 'r') ? w : h));
    });
}

function jOnRelease(): void {
    document.querySelectorAll<HTMLInputElement>('#l,#t,#r,#b').forEach(el => { el.value = ''; });
}

const img = document.getElementById('jcrop') as HTMLImageElement | null;
if (img) {
    const coiRaw = img.dataset.coi;
    const coiData: CoiData | null = coiRaw ? JSON.parse(coiRaw) as CoiData : null;

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const cropperConfig: any = {
        viewMode: 1,
        autoCrop: false,
        movable: true,
        rotatable: false,
        scalable: false,
        zoomable: false,
        zoomOnTouch: false,
        zoomOnWheel: false,
        dragMode: 'crop',
        crop: function (event: { detail: { x: number; y: number; width: number; height: number } }) {
            jOnChange(event.detail);
        },
    };

    let cropper: Cropper;

    if (coiData) {
        cropperConfig.ready = function () {
            cropper.setData({
                x: from_coi(coiData.l, img.naturalWidth),
                y: from_coi(coiData.t, img.naturalHeight),
                width: from_coi(coiData.r, img.naturalWidth) - from_coi(coiData.l, img.naturalWidth),
                height: from_coi(coiData.b, img.naturalHeight) - from_coi(coiData.t, img.naturalHeight),
            });
        };
    }

    // eslint-disable-next-line prefer-const
    cropper = new Cropper(img, cropperConfig);

    document.getElementById('jcrop-clear')?.addEventListener('click', function () {
        cropper.clear();
        jOnRelease();
    });
}
