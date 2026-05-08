//
// piecon.js → piecon.ts
//
// https://github.com/lipka/piecon
//
// Copyright (c) 2015 Lukas Lipka <lukaslipka@gmail.com>. All rights reserved.
//

interface PieconOptions {
    color: string;
    background: string;
    shadow: string;
    fallback: boolean | 'force';
}

interface PieconInstance {
    setOptions(custom: Partial<PieconOptions>): PieconInstance;
    setProgress(percentage: number): void;
    reset(): void;
}

const Piecon: PieconInstance = (() => {
    const obj = {} as PieconInstance;

    let currentFavicon: string | null = null;
    let originalFavicon: string | null = null;
    let originalTitle: string | null = null;
    let canvas: HTMLCanvasElement | null = null;
    let options: PieconOptions = {} as PieconOptions;
    const defaults: PieconOptions = {
        color: '#ff0084',
        background: '#bbb',
        shadow: '#fff',
        fallback: false,
    };

    const isRetina = window.devicePixelRatio > 1;

    const ua = (() => {
        const agent = navigator.userAgent.toLowerCase();
        return (browser: string) => agent.indexOf(browser) !== -1;
    })();

    const browser = {
        ie: ua('msie'),
        chrome: ua('chrome'),
        webkit: ua('chrome') || ua('safari'),
        safari: ua('safari') && !ua('chrome'),
        mozilla: ua('mozilla') && !ua('chrome') && !ua('safari'),
    };

    const getFaviconTag = (): HTMLLinkElement | false => {
        const links = document.getElementsByTagName('link');
        for (let i = 0, l = links.length; i < l; i++) {
            const rel = links[i]!.getAttribute('rel');
            if (rel === 'icon' || rel === 'shortcut icon') return links[i]!;
        }
        return false;
    };

    const removeFaviconTag = (): void => {
        const links = Array.prototype.slice.call(
            document.getElementsByTagName('link'),
            0
        ) as HTMLLinkElement[];
        const head = document.getElementsByTagName('head')[0]!;
        for (let i = 0, l = links.length; i < l; i++) {
            const rel = links[i]!.getAttribute('rel');
            if (rel === 'icon' || rel === 'shortcut icon') head.removeChild(links[i]!);
        }
    };

    const setFaviconTag = (url: string): void => {
        removeFaviconTag();
        const link = document.createElement('link');
        link.type = 'image/x-icon';
        link.rel = 'icon';
        link.href = url;
        document.getElementsByTagName('head')[0]!.appendChild(link);
    };

    const getCanvas = (): HTMLCanvasElement => {
        if (!canvas) {
            canvas = document.createElement('canvas');
            canvas.width = isRetina ? 32 : 16;
            canvas.height = isRetina ? 32 : 16;
        }
        return canvas;
    };

    const drawFavicon = (percentageIn: number): void => {
        const percentage = percentageIn || 0;
        const cvs = getCanvas();
        const context = cvs.getContext('2d');
        if (!context) return;

        const cx = cvs.width / 2;
        const cy = cvs.height / 2;
        const r = Math.min(cx, cy);

        context.clearRect(0, 0, cvs.width, cvs.height);

        context.beginPath();
        context.moveTo(cx, cy);
        context.arc(cx, cy, r, 0, Math.PI * 2, false);
        context.fillStyle = options.shadow;
        context.fill();

        context.beginPath();
        context.moveTo(cx, cy);
        context.arc(cx, cy, r - 2, 0, Math.PI * 2, false);
        context.fillStyle = options.background;
        context.fill();

        if (percentage > 0) {
            context.beginPath();
            context.moveTo(cx, cy);
            context.arc(
                cx,
                cy,
                r - 2,
                -0.5 * Math.PI,
                (-0.5 + (2 * percentage) / 100) * Math.PI,
                false
            );
            context.lineTo(cx, cy);
            context.fillStyle = options.color;
            context.fill();
        }

        setFaviconTag(cvs.toDataURL());
    };

    const updateTitle = (percentage: number): void => {
        document.title =
            percentage > 0 ? '(' + percentage + '%) ' + originalTitle : (originalTitle ?? '');
    };

    obj.setOptions = function (custom: Partial<PieconOptions>): PieconInstance {
        options = { ...defaults, ...custom };
        return this;
    };

    obj.setProgress = function (percentage: number): void {
        originalTitle ??= document.title;
        if (
            originalFavicon === null ||
            originalFavicon === '' ||
            currentFavicon === null ||
            currentFavicon === ''
        ) {
            const tag = getFaviconTag();
            originalFavicon = currentFavicon =
                tag !== false ? tag.getAttribute('href') : '/favicon.ico';
        }
        if (!isNaN(parseFloat(String(percentage))) && isFinite(percentage)) {
            if (browser.ie || browser.safari || options.fallback === true) {
                updateTitle(percentage);
            } else {
                if (options.fallback === 'force') updateTitle(percentage);
                drawFavicon(percentage);
            }
        }
    };

    obj.reset = function (): void {
        if (originalTitle !== null && originalTitle !== '') document.title = originalTitle;
        if (originalFavicon !== null && originalFavicon !== '') {
            currentFavicon = originalFavicon;
            setFaviconTag(currentFavicon);
        }
    };

    obj.setOptions(defaults);
    return obj;
})();

export default Piecon;
