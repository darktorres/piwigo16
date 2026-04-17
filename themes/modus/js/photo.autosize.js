function rvas_get_scaled_size(d, available) {
    var ratio_w = d.w / available.w;
    var ratio_h = d.h / available.h;
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
    var theImage = document.getElementById("theImage");
    var width = theImage ? theImage.offsetWidth : 0;
    var zoom = 1;
    var docHeight;

    if ("innerHeight" in window) {
        docHeight = window.innerHeight;
        if (
            document.documentElement.clientWidth > window.innerWidth &&
            window.innerWidth
        )
            zoom = document.documentElement.clientWidth / window.innerWidth;
        docHeight = Math.floor(docHeight * zoom);
    } else docHeight = document.documentElement.offsetHeight;

    var imageTop = theImage ? theImage.getBoundingClientRect().top + window.scrollY : 0;
    var height = docHeight - Math.ceil(imageTop);

    var dpr =
        window.devicePixelRatio && window.devicePixelRatio > 1
            ? window.devicePixelRatio
            : 1;
    width = Math.floor(width * dpr);
    height = Math.floor(height * dpr);

    document.cookie =
        "phavsz=" + width + "x" + height + "x" + dpr + ";path=" + RVAS.cp;
    return { w: width, h: height, dpr: dpr, zoom: zoom };
}

function rvas_choose(relaxed) {
    var best;
    var available = rvas_get_available_size();
    var img = document.getElementById("theMainImage");
    var changed = true;
    for (var i = 0; i < RVAS.derivatives.length; i++) {
        var d = RVAS.derivatives[i];
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
            var rescaled = rvas_get_scaled_size(best, available);
            if (img.getAttribute("width") && available.zoom == 1) {
                var changeRatio = rescaled.h / img.offsetHeight;
                var limit = relaxed ? 1.25 : 1.15;
                if (
                    (changeRatio >= 1 && changeRatio < limit) ||
                    (changeRatio < 1 &&
                        changeRatio > 1 / limit &&
                        img.offsetWidth < available.w / available.dpr)
                )
                    return;
            }
            var naturalW = img.dataset.rvasNaturalW ? parseInt(img.dataset.rvasNaturalW) : 0;
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
                var changeRatio = best.h / img.offsetHeight;
                var limit = relaxed ? 2 : 1.15;
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
            var checked = document.getElementById("derivativeChecked" + best.type);
            if (checked) checked.style.visibility = "visible";
        }
    }
    img.removeEventListener("load", img._rvasLoadHandler);
    img._rvasLoadHandler = function () {
        var attrW = this.getAttribute("width");
        var attrH = this.getAttribute("height");
        this.style.width = attrW ? attrW + "px" : "auto";
        this.style.height = attrH ? attrH + "px" : "auto";
    };
    img.addEventListener("load", img._rvasLoadHandler);
}

document.addEventListener('DOMContentLoaded', function () {
    var img = document.getElementById("theMainImage");
    if (window.changeImgSrc && img) {
        RVAS.changeImgSrcOrig = changeImgSrc;
        changeImgSrc = function () {
            RVAS.disable = 1;
            RVAS.changeImgSrcOrig.apply(undefined, arguments);
        };
    }

    window.addEventListener("resize", function () {
        var w = document.body.offsetWidth;
        var de = document.documentElement;
        if (document.location.search.indexOf("slideshow") == -1) {
            if (w < 1262) de.classList.remove("wide");
            else de.classList.add("wide");
        }

        if (RVAS.disable) rvas_get_available_size();
        else rvas_choose();
    });

    if (img) {
        img.addEventListener("click", function (e) {
            if (!this.getAttribute("usemap") && e.clientY) {
                var rect = this.getBoundingClientRect();
                var pct = (e.pageX - (rect.left + window.scrollX)) / this.offsetWidth;
                var clientY = e.pageY - (rect.top + window.scrollY);
                if (pct < 0.3) {
                    var linkPrev = document.getElementById("linkPrev");
                    if (linkPrev && clientY > 15)
                        window.location = linkPrev.getAttribute("href");
                } else if (pct > 0.7) {
                    var linkNext = document.getElementById("linkNext");
                    if (linkNext && clientY > 15)
                        window.location = linkNext.getAttribute("href");
                } else if (clientY / this.offsetHeight < 0.5 && clientY > 15) {
                    var upIcon = document.querySelector(".pwg-icon-arrow-n");
                    var upLink = upIcon ? upIcon.closest("a") : null;
                    if (upLink) window.location = upLink.getAttribute("href");
                }
            }
        });
    }
});
