//
// piecon.js
//
// https://github.com/lipka/piecon
//
// Copyright (c) 2015 Lukas Lipka <lukaslipka@gmail.com>. All rights reserved.
//

const Piecon = (() => {
    const obj = {};

    let currentFavicon = null;
    let originalFavicon = null;
    let originalTitle = null;
    let canvas = null;
    let options = {};
    const defaults = {
        color: '#ff0084',
        background: '#bbb',
        shadow: '#fff',
        fallback: false
    };

    const isRetina = window.devicePixelRatio > 1;

    const ua = (() => {
        const agent = navigator.userAgent.toLowerCase();
        return (browser) => agent.indexOf(browser) !== -1;
    })();

    const browser = {
        ie: ua('msie'),
        chrome: ua('chrome'),
        webkit: ua('chrome') || ua('safari'),
        safari: ua('safari') && !ua('chrome'),
        mozilla: ua('mozilla') && !ua('chrome') && !ua('safari')
    };

    const getFaviconTag = () => {
        const links = document.getElementsByTagName('link');

        for (let i = 0, l = links.length; i < l; i++) {
            if (links[i].getAttribute('rel') === 'icon' || links[i].getAttribute('rel') === 'shortcut icon') {
                return links[i];
            }
        }

        return false;
    };

    const removeFaviconTag = () => {
        const links = Array.prototype.slice.call(document.getElementsByTagName('link'), 0);
        const head = document.getElementsByTagName('head')[0];

        for (let i = 0, l = links.length; i < l; i++) {
            if (links[i].getAttribute('rel') === 'icon' || links[i].getAttribute('rel') === 'shortcut icon') {
                head.removeChild(links[i]);
            }
        }
    };

    const setFaviconTag = (url) => {
        removeFaviconTag();

        const link = document.createElement('link');
        link.type = 'image/x-icon';
        link.rel = 'icon';
        link.href = url;

        document.getElementsByTagName('head')[0].appendChild(link);
    };

    const getCanvas = () => {
        if (!canvas) {
            canvas = document.createElement("canvas");
            if (isRetina) {
                canvas.width = 32;
                canvas.height = 32;
            } else {
                canvas.width = 16;
                canvas.height = 16;
            }
        }

        return canvas;
    };

    const drawFavicon = (percentage) => {
        const canvas = getCanvas();
        const context = canvas.getContext("2d");

        percentage = percentage || 0;

        if (context) {
            context.clearRect(0, 0, canvas.width, canvas.height);

            context.beginPath();
            context.moveTo(canvas.width / 2, canvas.height / 2);
            context.arc(canvas.width / 2, canvas.height / 2, Math.min(canvas.width / 2, canvas.height / 2), 0, Math.PI * 2, false);
            context.fillStyle = options.shadow;
            context.fill();

            context.beginPath();
            context.moveTo(canvas.width / 2, canvas.height / 2);
            context.arc(canvas.width / 2, canvas.height / 2, Math.min(canvas.width / 2, canvas.height / 2) - 2, 0, Math.PI * 2, false);
            context.fillStyle = options.background;
            context.fill();

            if (percentage > 0) {
                context.beginPath();
                context.moveTo(canvas.width / 2, canvas.height / 2);
                context.arc(canvas.width / 2, canvas.height / 2, Math.min(canvas.width / 2, canvas.height / 2) - 2, (-0.5) * Math.PI, (-0.5 + 2 * percentage / 100) * Math.PI, false);
                context.lineTo(canvas.width / 2, canvas.height / 2);
                context.fillStyle = options.color;
                context.fill();
            }

            setFaviconTag(canvas.toDataURL());
        }
    };

    const updateTitle = (percentage) => {
        if (percentage > 0) {
            document.title = '(' + percentage + '%) ' + originalTitle;
        } else {
            document.title = originalTitle;
        }
    };

    obj.setOptions = function(custom) {
        options = {};

        for (const key in defaults){
            options[key] = custom.hasOwnProperty(key) ? custom[key] : defaults[key];
        }

        return this;
    };

    obj.setProgress = function(percentage) {
        if (!originalTitle) {
            originalTitle = document.title;
        }

        if (!originalFavicon || !currentFavicon) {
            const tag = getFaviconTag();
            originalFavicon = currentFavicon = tag ? tag.getAttribute('href') : '/favicon.ico';
        }

        if (!isNaN(parseFloat(percentage)) && isFinite(percentage)) {
            if (!getCanvas().getContext || browser.ie || browser.safari || options.fallback === true) {
                return updateTitle(percentage);
            } else if (options.fallback === 'force') {
                updateTitle(percentage);
            }

            return drawFavicon(percentage);
        }

        return false;
    };

    obj.reset = function() {
        if (originalTitle) {
            document.title = originalTitle;
        }

        if (originalFavicon) {
            currentFavicon = originalFavicon;
            setFaviconTag(currentFavicon);
        }
    };

    obj.setOptions(defaults);

    return obj;
})();

export default Piecon;
