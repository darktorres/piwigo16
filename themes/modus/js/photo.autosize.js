function rvas_get_scaled_size(d, available) {
    const ratio_w = d.w / available.w;
    const ratio_h = d.h / available.h;
    if (ratio_w > 1 || ratio_h > 1) {
        if (ratio_w > ratio_h)
            return {
                w: available.w / available.dpr,
                h: Math.floor(d.h / ratio_w / available.dpr),
            };
        else
            return {
                w: Math.floor(d.w / ratio_h / available.dpr),
                h: available.h / available.dpr,
            };
    }
    return {
        w: Math.round(d.w / available.dpr),
        h: Math.round(d.h / available.dpr),
    };
}

function rvas_get_available_size() {
    const theImage = document.getElementById("theImage");
    let width = theImage ? theImage.offsetWidth : 0;
    let zoom = 1;
    let docHeight;

    if ("innerHeight" in window) {
        docHeight = window.innerHeight;
        if (
            document.documentElement.clientWidth > window.innerWidth &&
            window.innerWidth
        )
            zoom = document.documentElement.clientWidth / window.innerWidth;
        docHeight = Math.floor(docHeight * zoom);
    } else docHeight = document.documentElement.offsetHeight;

    const imageTop = theImage ? theImage.getBoundingClientRect().top + window.scrollY : 0;
    const height = docHeight - Math.ceil(imageTop);

    const dpr =
        window.devicePixelRatio && window.devicePixelRatio > 1
            ? window.devicePixelRatio
            : 1;
    width = Math.floor(width * dpr);
    return { w: Math.floor(width), h: Math.floor(height), dpr: dpr, zoom: zoom };
}

export function rvas_choose(relaxed) {
    let best;
    const available = rvas_get_available_size();
    const img = document.getElementById("theMainImage");
    let changed = true;
    for (let i = 0; i < window.RVAS.derivatives.length; i++) {
        const d = window.RVAS.derivatives[i];
        if (
            d.w > available.w * available.zoom ||
            d.h > available.h * available.zoom
        ) {
            if (available.dpr > 1 || !best) best = d;
            break;
        } else best = d;
    }
    if (best) {
        if (available.dpr > 1) {
            const rescaled = rvas_get_scaled_size(best, available);
            if (img.getAttribute("width") && available.zoom == 1) {
                const changeRatio = rescaled.h / img.offsetHeight;
                const limit = relaxed ? 1.25 : 1.15;
                if (
                    (changeRatio >= 1 && changeRatio < limit) ||
                    (changeRatio < 1 &&
                        changeRatio > 1 / limit &&
                        img.offsetWidth < available.w / available.dpr)
                )
                    return;
            }
            const naturalW = img.dataset.rvasNaturalW ? parseInt(img.dataset.rvasNaturalW) : 0;
            if (!naturalW || naturalW < best.w) {
                img.setAttribute("width", rescaled.w);
                img.setAttribute("height", rescaled.h);
                img.setAttribute("src", best.url);
                img.removeAttribute("usemap");
                img.dataset.rvasNaturalW = best.w;
            } else {
                img.setAttribute("width", rescaled.w);
                img.setAttribute("height", rescaled.h);
                changed = false;
            }
        } else {
            if (img.getAttribute("width")) {
                const changeRatio = best.h / img.offsetHeight;
                const limit = relaxed ? 2 : 1.15;
                if (
                    (changeRatio >= 1 && changeRatio < limit) ||
                    (changeRatio < 1 &&
                        changeRatio > 1 / limit &&
                        img.offsetWidth < available.w)
                )
                    return;
            }
            img.setAttribute("width", best.w);
            img.setAttribute("height", best.h);
            img.setAttribute("src", best.url);
            img.setAttribute("usemap", "#map" + best.type);
        }
        if (changed) {
            document.querySelectorAll("#derivativeSwitchBox .switchCheck").forEach(function (el) {
                el.style.visibility = "hidden";
            });
            const checked = document.getElementById("derivativeChecked" + best.type);
            if (checked) checked.style.visibility = "visible";
        }
    }
    img.removeEventListener("load", img._rvasLoadHandler);
    img._rvasLoadHandler = function () {
        const attrW = this.getAttribute("width");
        const attrH = this.getAttribute("height");
        this.style.width = attrW ? attrW + "px" : "auto";
        this.style.height = attrH ? attrH + "px" : "auto";
    };
    img.addEventListener("load", img._rvasLoadHandler);
}

document.addEventListener('DOMContentLoaded', function () {
    const img = document.getElementById("theMainImage");
    if (window.changeImgSrc && img) {
        window.RVAS.changeImgSrcOrig = window.changeImgSrc;
        window.changeImgSrc = function () {
            window.RVAS.disable = 1;
            window.RVAS.changeImgSrcOrig.apply(undefined, arguments);
        };
    }

    window.addEventListener("resize", function () {
        const w = document.body.offsetWidth;
        const de = document.documentElement;
        if (document.location.search.indexOf("slideshow") == -1) {
            if (w < 1262) de.classList.remove("wide");
            else de.classList.add("wide");
        }

        if (window.RVAS.disable) rvas_get_available_size();
        else rvas_choose();
    });

    if (img) {
        img.addEventListener("click", function (e) {
            if (!this.getAttribute("usemap") && e.clientY) {
                const rect = this.getBoundingClientRect();
                const pct = (e.pageX - (rect.left + window.scrollX)) / this.offsetWidth;
                const clientY = e.pageY - (rect.top + window.scrollY);
                if (pct < 0.3) {
                    const linkPrev = document.getElementById("linkPrev");
                    if (linkPrev && clientY > 15)
                        window.location = linkPrev.getAttribute("href");
                } else if (pct > 0.7) {
                    const linkNext = document.getElementById("linkNext");
                    if (linkNext && clientY > 15)
                        window.location = linkNext.getAttribute("href");
                } else if (clientY / this.offsetHeight < 0.5 && clientY > 15) {
                    const upIcon = document.querySelector(".pwg-icon-arrow-n");
                    const upLink = upIcon ? upIcon.closest("a") : null;
                    if (upLink) window.location = upLink.getAttribute("href");
                }
            }
        });
    }
});
