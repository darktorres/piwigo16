export function phpWGOpenWindow(theURL, winName, features) {
    const img = new Image();
    let width, height, newWin;
    img.src = theURL;
    if (img.complete) {
        width = img.width + 40;
        height = img.height + 40;
    } else {
        width = 640;
        height = 480;
        img.onload = function () {
            newWin.resizeTo(img.width + 50, img.height + 100);
        };
    }
    newWin = window.open(
        theURL,
        winName,
        features + ",left=2,top=1,width=" + width + ",height=" + height,
    );
}

export function popuphelp(url) {
    window.open(
        url,
        "dc_popup",
        "alwaysRaised=yes,dependent=yes,toolbar=no,height=420,width=500,menubar=no,resizable=yes,scrollbars=yes,status=no",
    );
}

export function pwgBind(object, method) {
    const args = Array.prototype.slice.call(arguments, 2);
    return function () {
        return method.apply(
            object,
            args.concat(Array.prototype.slice.call(arguments, 0)),
        );
    };
}

export function PwgWS(urlRoot) {
    this.urlRoot = urlRoot;
    this.options = {
        method: "GET",
        async: true,
        onFailure: null,
        onSuccess: null,
    };
}

PwgWS.prototype = {
    callService: function (method, parameters, options) {
        if (options) {
            for (const prop in options) this.options[prop] = options[prop];
        }
        this.xhr = new XMLHttpRequest();
        this.xhr.onreadystatechange = pwgBind(this, this.onStateChange);

        let url = this.urlRoot + "ws.php?format=json&method=" + method;

        let body = "";
        if (parameters) {
            for (const prop in parameters) {
                if (typeof parameters[prop] == "object" && parameters[prop]) {
                    for (let i = 0; i < parameters[prop].length; i++)
                        body +=
                            prop +
                            "[]=" +
                            encodeURIComponent(parameters[prop][i]) +
                            "&";
                } else
                    body +=
                        prop + "=" + encodeURIComponent(parameters[prop]) + "&";
            }
        }

        if (this.options.method != "POST") {
            url += "&" + body;
            body = null;
        }
        this.xhr.open(this.options.method, url, this.options.async);
        if (this.options.method == "POST")
            this.xhr.setRequestHeader(
                "Content-Type",
                "application/x-www-form-urlencoded",
            );
        try {
            this.xhr.send(body);
        } catch (_e) {
            this.error(0, _e.message);
        }
    },

    onStateChange: function () {
        const readyState = this.xhr.readyState;
        if (readyState == 4) {
            try {
                this.respondToReadyState(readyState);
            } finally {
                this.cleanup();
            }
        }
    },

    error: function (httpCode, text) {
        !this.options.onFailure || this.options.onFailure(httpCode, text);
        this.cleanup();
    },

    respondToReadyState: function (readyState) {
        const xhr = this.xhr;
        if (readyState == 4 && xhr.status == 200) {
            let resp;
            try {
                resp =
                    window.JSON && window.JSON.parse
                        ? window.JSON.parse(xhr.responseText)
                        : new Function("return " + xhr.responseText)();
            } catch (_e) {
                this.error(
                    200,
                    _e.message + "\n" + xhr.responseText.substr(0, 512),
                );
            }
            if (resp != null) {
                if (resp.stat == null) this.error(200, "Invalid response");
                else if (resp.stat == "ok")
                    !this.options.onSuccess ||
                        this.options.onSuccess(resp.result);
                else this.error(200, resp.err + " " + resp.message);
            }
        }
        if (readyState == 4 && xhr.status != 200)
            this.error(xhr.status, xhr.statusText);
    },

    cleanup: function () {
        if (this.xhr) this.xhr.onreadystatechange = null;
        this.xhr = null;
        this.options.onFailure = this.options.onSuccess = null;
    },

    xhr: null,
};

export function pwgAddEventListener(elem, evt, fn) {
    if (window.addEventListener) elem.addEventListener(evt, fn, false);
    else elem.attachEvent("on" + evt, fn);
}

export function setupPwgOpenWindow() {
    document.addEventListener('click', function(e) {
        const link = e.target.closest('[data-action="pwgOpenWindow"]');
        if (link) {
            e.preventDefault();
            const url = link.dataset.url;
            const features = link.dataset.features;
            phpWGOpenWindow(url, 'xxx', features);
        }
    });
}

export function setupPopuphelp() {
    document.addEventListener('click', function(e) {
        const link = e.target.closest('[data-action="pwgPopupHelp"]');
        if (link) {
            e.preventDefault();
            popuphelp(link.href);
        }
    });
}
