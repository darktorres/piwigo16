function SPTLine(margin, rowHeight) {
    this.elements = [];
    this.margin = margin;
    this.rowHeight = rowHeight;
    this.maxHeight = 0;
}

SPTLine.prototype = {
    width: 0,
    elementsWidth: 0,
    firstThumbIndex: 0,

    add: function (img, absIndex) {
        if (this.elements.length === 0) this.firstThumbIndex = absIndex;
        let w, h;
        if (!img.dataset.sptW) {
            w = img.offsetWidth;
            h = img.offsetHeight;
            if (h > this.rowHeight) {
                w = Math.round((w * this.rowHeight) / h);
                h = this.rowHeight;
            }
            img.dataset.sptW = w;
            img.dataset.sptH = h;
        } else {
            w = parseFloat(img.dataset.sptW);
            h = parseFloat(img.dataset.sptH);
        }

        const eltObj = { img: img, w: w, h: h };
        this.elements.push(eltObj);

        if (eltObj.h > this.maxHeight) this.maxHeight = eltObj.h;

        this.width += this.margin + eltObj.w;
        this.elementsWidth += eltObj.w;
    },

    clear: function () {
        if (!this.elements.length) return;
        this.width = this.elementsWidth = 0;
        this.maxHeight = 0;
        this.elements.length = 0;
    },
};

export function SPThumbs(options) {
    this.opts = options;

    this.thumbs = document.getElementById('thumbnails');
    if (!this.thumbs) return;
    this.thumbs.style.textAlign = "left";

    this.opts.extraRowHeight = 0;
    if (window.devicePixelRatio > 1) {
        const dpr = window.devicePixelRatio;
        this.opts.extraRowHeight = 6;
        this.opts.rowHeight =
            Math.round(this.opts.rowHeight / dpr) + this.opts.extraRowHeight;
    }
    this.process();

    const that = this;
    window.addEventListener("resize", function () {
        if (Math.abs(that.thumbs.offsetWidth - that.prevContainerWidth) > 1)
            that.process();
    });
    window.addEventListener("RVTS_loaded", function (evt) {
        const down = evt.detail && evt.detail.down;
        that.process(
            down && that.thumbs.offsetWidth == that.prevContainerWidth
                ? that.prevLastLineFirstThumbIndex
                : 0,
        );
    });
}

SPThumbs.prototype = {
    prevContainerWidth: 0,
    prevLastLineFirstThumbIndex: 0,

    process: function (startIndex) {
        startIndex = startIndex ? startIndex : 0;
        const containerWidth = this.thumbs.offsetWidth;
        const maxExtraMarginPerThumb = 1;
        this.prevContainerWidth = containerWidth;

        const elts = this.thumbs.querySelectorAll("li.liVisible>a>img");
        const line = new SPTLine(this.opts.hMargin, this.opts.rowHeight);

        for (let i = startIndex; i < elts.length; i++) {
            line.add(elts[i], i);
            if (
                line.width >=
                containerWidth - maxExtraMarginPerThumb * line.elements.length
            ) {
                this.processLine(line, containerWidth);
                line.clear();
            }
        }

        if (line.elements.length) this.processLine(line, containerWidth, true);
        this.prevLastLineFirstThumbIndex = line.firstThumbIndex;

        const that = this;
        window.requestAnimationFrame(function () {
            if (Math.abs(that.thumbs.offsetWidth - that.prevContainerWidth) > 1)
                that.process();
        });
    },

    processLine: function (line, containerWidth, lastLine) {
        let toRecover;
        let eltW;
        let eltH;
        let rowHeight = line.maxHeight ? line.maxHeight : line.elements[0].h;

        if (line.width / containerWidth > 1.01) {
            const ratio =
                line.elementsWidth /
                (line.elementsWidth + containerWidth - line.width);
            let adjustedRowHeight = rowHeight / (1 + (ratio - 1) * 0.95);
            adjustedRowHeight = 6 * Math.round(adjustedRowHeight / 6);
            if (adjustedRowHeight < rowHeight / ratio) {
                adjustedRowHeight = Math.ceil(rowHeight / ratio);
                const missing =
                    this.opts.rowHeight -
                    this.opts.extraRowHeight -
                    adjustedRowHeight;
                if (missing > 0 && missing < 6) adjustedRowHeight += missing;
            }
            if (adjustedRowHeight < rowHeight) rowHeight = adjustedRowHeight;
        } else if (lastLine)
            rowHeight = Math.min(
                rowHeight,
                this.opts.rowHeight - this.opts.extraRowHeight,
            );

        toRecover = line.width - containerWidth;
        if (lastLine) toRecover = 0;

        for (let i = 0; i < line.elements.length; i++) {
            const eltObj = line.elements[i];
            eltW = eltObj.w;
            eltH = eltObj.h;
            let eltToRecover;

            if (i == line.elements.length - 1) eltToRecover = toRecover;
            else
                eltToRecover = Math.round(
                    (toRecover * eltW) / line.elementsWidth,
                );

            toRecover -= eltToRecover;
            line.elementsWidth -= eltW;

            if (eltH > rowHeight) {
                eltW = Math.round((eltW * rowHeight) / eltObj.h);
                eltH = rowHeight;
                eltToRecover -= eltObj.w - eltW;
                if (lastLine) eltToRecover = 0;
            }

            this.reposition(
                eltObj.img,
                eltW,
                eltH,
                eltW - eltToRecover,
                rowHeight,
            );
        }
    },

    reposition: function (img, imgW, imgH, liW, liH) {
        img.setAttribute("width", imgW);
        img.setAttribute("height", imgH);

        const li = img.closest("li");
        if (li) {
            li.style.width = liW + "px";
            li.style.height = liH + "px";
        }

        const a = img.parentElement;
        if (a) {
            a.style.left = Math.round((liW - imgW) / 2) + "px";
            a.style.top = Math.round((liH - imgH) / 2) + "px";
        }
    },
};
