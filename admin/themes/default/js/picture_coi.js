import Cropper from 'cropperjs';

function from_coi(f, total) {
    return f * total;
}

function to_coi(v, total) {
    return v / total;
}

function jOnChange(detail) {
    var img = document.getElementById("jcrop");
    if (!img) return;
    var w = img.naturalWidth;
    var h = img.naturalHeight;
    var fields = { l: detail.x, t: detail.y, r: detail.x + detail.width, b: detail.y + detail.height };
    Object.keys(fields).forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.value = to_coi(fields[id], (id === 'l' || id === 'r') ? w : h);
    });
}

function jOnRelease() {
    document.querySelectorAll("#l,#t,#r,#b").forEach(function (el) { el.value = ""; });
}

var img = document.getElementById("jcrop");
if (img) {
    var coiRaw = img.dataset.coi;
    var coiData = coiRaw ? JSON.parse(coiRaw) : null;

    var cropperConfig = {
        viewMode: 1,
        autoCrop: false,
        movable: true,
        rotatable: false,
        scalable: false,
        zoomable: false,
        zoomOnTouch: false,
        zoomOnWheel: false,
        dragMode: 'crop',
        crop: function (event) {
            jOnChange(event.detail);
        },
    };

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

    var cropper = new Cropper(img, cropperConfig);

    document.getElementById("jcrop-clear").addEventListener("click", function () {
        cropper.clear();
        jOnRelease();
    });
}
