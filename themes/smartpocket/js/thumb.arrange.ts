export interface SPThumbsOptions {
    hMargin: number;
    rowHeight: number;
    extraRowHeight: number;
}

interface ThumbElement {
    img: HTMLImageElement;
    w: number;
    h: number;
}

class SPTLine {
    elements: ThumbElement[] = [];
    width = 0;
    elementsWidth = 0;
    firstThumbIndex = 0;
    maxHeight = 0;

    constructor(public margin: number, public rowHeight: number) {}

    add(img: HTMLImageElement, absIndex: number): void {
        if (this.elements.length === 0) this.firstThumbIndex = absIndex;
        let w: number, h: number;
        if (!img.dataset['sptW']) {
            w = img.offsetWidth;
            h = img.offsetHeight;
            if (h > this.rowHeight) {
                w = Math.round((w * this.rowHeight) / h);
                h = this.rowHeight;
            }
            img.dataset['sptW'] = String(w);
            img.dataset['sptH'] = String(h);
        } else {
            w = parseFloat(img.dataset['sptW'] ?? '0');
            h = parseFloat(img.dataset['sptH'] ?? '0');
        }

        const eltObj: ThumbElement = { img, w, h };
        this.elements.push(eltObj);

        if (eltObj.h > this.maxHeight) this.maxHeight = eltObj.h;

        this.width += this.margin + eltObj.w;
        this.elementsWidth += eltObj.w;
    }

    clear(): void {
        if (!this.elements.length) return;
        this.width = this.elementsWidth = 0;
        this.maxHeight = 0;
        this.elements.length = 0;
    }
}

export class SPThumbs {
    opts: SPThumbsOptions;
    thumbs: HTMLElement | null;
    prevContainerWidth = 0;
    prevLastLineFirstThumbIndex = 0;

    constructor(options: SPThumbsOptions) {
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

        window.addEventListener("resize", () => {
            if (this.thumbs && Math.abs(this.thumbs.offsetWidth - this.prevContainerWidth) > 1)
                this.process();
        });
        window.addEventListener("RVTS_loaded", (evt) => {
            const customEvt = evt as CustomEvent<{ down?: boolean }>;
            const down = customEvt.detail.down;
            this.process(
                down && this.thumbs && this.thumbs.offsetWidth=== this.prevContainerWidth
                    ? this.prevLastLineFirstThumbIndex
                    : 0,
            );
        });
    }

    process(startIndex?: number): void {
        const si = startIndex ?? 0;
        if (!this.thumbs) return;
        const containerWidth = this.thumbs.offsetWidth;
        const maxExtraMarginPerThumb = 1;
        this.prevContainerWidth = containerWidth;

        const elts = this.thumbs.querySelectorAll<HTMLImageElement>("li.liVisible>a>img");
        const line = new SPTLine(this.opts.hMargin, this.opts.rowHeight);

        for (let i = si; i < elts.length; i++) {
            line.add(elts[i]!, i);
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

        window.requestAnimationFrame(() => {
            if (this.thumbs && Math.abs(this.thumbs.offsetWidth - this.prevContainerWidth) > 1)
                this.process();
        });
    }

    processLine(line: SPTLine, containerWidth: number, lastLine?: boolean): void {
        let toRecover: number;
        let eltW: number;
        let eltH: number;
        let rowHeight = line.maxHeight ? line.maxHeight : line.elements[0]!.h;

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
            const eltObj = line.elements[i]!;
            eltW = eltObj.w;
            eltH = eltObj.h;
            let eltToRecover: number;

            if (i=== line.elements.length - 1) eltToRecover = toRecover;
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
    }

    reposition(img: HTMLImageElement, imgW: number, imgH: number, liW: number, liH: number): void {
        img.setAttribute("width", String(imgW));
        img.setAttribute("height", String(imgH));

        const li = img.closest("li") as HTMLElement | null;
        if (li) {
            li.style.width = liW + "px";
            li.style.height = liH + "px";
        }

        const a = img.parentElement;
        if (a) {
            a.style.left = Math.round((liW - imgW) / 2) + "px";
            a.style.top = Math.round((liH - imgH) / 2) + "px";
        }
    }
}
