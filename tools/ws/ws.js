// Piwigo Web-Services explorer.
// Vanilla ES module replacement for the original jQuery-based ws.js.
// Outside the Vite pipeline — loaded directly by tools/ws.htm.

import { renderJsonViewer } from './json-viewer.js';

// ─── tiny cookie helpers ──────────────────────────────────────────────────
function getCookie(name) {
    const match = document.cookie.match(
        new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)')
    );
    return match ? decodeURIComponent(match[1]) : null;
}
function setCookie(name, value) {
    document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=31536000`;
}
function removeCookie(name) {
    document.cookie = `${name}=; path=/; max-age=0`;
}

// ─── DOM helpers ──────────────────────────────────────────────────────────
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
const show = (el) => {
    if (el) el.style.display = '';
};
const hide = (el) => {
    if (el) el.style.display = 'none';
};

document.addEventListener('DOMContentLoaded', () => {
    const cachedMethods = Object.create(null);
    let ws_url = 'http://';

    const urlForm = $('#urlForm');
    const errorWrapper = $('#errorWrapper');
    const methodWrapper = $('#methodWrapper');
    const methodNameEl = $('#methodName');
    const methodDescription = $('#methodDescription');
    const methodDescriptionBQ = $('#methodDescription blockquote');
    const requestURLDisplay = $('#requestURLDisplay');
    const requestResultDisplay = $('#requestResultDisplay');
    const invokeFrame = $('#invokeFrame');
    const introMessage = $('#introMessage');
    const methodsList = $('#methodsList');
    const onlysEl = $('#onlys');
    const noParamsEl = $('.no-params');
    const methodParamsTable = $('#methodParams table');
    const methodParamsTbody = $('#methodParams tbody');
    const requestFormat = $('#requestFormat');
    const responseFormat = $('#responseFormat');
    const apiKey = $('#apiKey');
    const jsonViewer = $('#json-viewer');
    const invokeForm = $('#invokeForm');
    const resultWrapper = $('#resultWrapper');
    const searchInput = $('#search input');

    // ── auto-detect ws_url ──────────────────────────────────────────────
    const urlMatch = document.location.toString().match(/^(https?.*\/)tools\/ws\.html?/);
    if (urlMatch === null) {
        askForUrl();
    } else {
        ws_url = urlMatch[1] + 'ws.php';
        getMethodList();
    }

    // ── manual ws_url ───────────────────────────────────────────────────
    urlForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const input = urlForm.querySelector('input[name="ws_url"]');
        ws_url = input.value;
        getMethodList();
    });

    // ── invoke buttons ──────────────────────────────────────────────────
    $('#invokeMethod').addEventListener('click', (e) => {
        e.preventDefault();
        invokeMethod(methodNameEl.textContent, false);
    });
    $('#invokeMethodBlank').addEventListener('click', (e) => {
        e.preventDefault();
        invokeMethod(methodNameEl.textContent, true);
    });

    // ── resizable iframe ────────────────────────────────────────────────
    $('#increaseIframe').addEventListener('click', (e) => {
        e.preventDefault();
        resultWrapper.style.height = resultWrapper.offsetHeight + 100 + 'px';
    });
    $('#decreaseIframe').addEventListener('click', (e) => {
        e.preventDefault();
        if (resultWrapper.offsetHeight > 200) {
            resultWrapper.style.height = resultWrapper.offsetHeight - 100 + 'px';
        }
    });

    // ── search filter ───────────────────────────────────────────────────
    searchInput.value = '';
    searchInput.addEventListener('input', () => {
        const query = searchInput.value.toLowerCase();
        if (query) {
            $$('.method-node, .method-link').forEach(hide);
            if (!methodsList.classList.contains('onSearch')) {
                methodsList.classList.add('onSearch');
                $$('.method-node-content').forEach(show);
            }
            $$('.method-link').forEach((node) => {
                const m = node.dataset.method || '';
                if (m.toLowerCase().includes(query)) {
                    showBranch(node);
                }
            });
        } else {
            $$('.method-node, .method-link').forEach(show);
            methodsList.classList.remove('onSearch');
            setStateMenu();
        }
    });

    function showBranch(el) {
        show(el);
        let node = el;
        while (node.parentElement?.parentElement?.classList.contains('method-node')) {
            node = node.parentElement.parentElement;
            show(node);
        }
    }

    // ── dark mode ───────────────────────────────────────────────────────
    const darkBtn = $('.darkModeButton');
    if (getCookie('wse-dark-mode')) {
        $('#the_body').classList.add('dark-mode');
        darkBtn.classList.remove('icon-moon-inv');
        darkBtn.classList.add('icon-sun-inv');
    }
    darkBtn.addEventListener('click', () => {
        if (getCookie('wse-dark-mode')) {
            darkBtn.classList.remove('icon-sun-inv');
            darkBtn.classList.add('icon-moon-inv');
            removeCookie('wse-dark-mode');
            $('#the_body').classList.remove('dark-mode');
        } else {
            darkBtn.classList.remove('icon-moon-inv');
            darkBtn.classList.add('icon-sun-inv');
            setCookie('wse-dark-mode', 'true');
            $('#the_body').classList.add('dark-mode');
        }
    });

    // ── core functions ──────────────────────────────────────────────────
    function resetDisplay() {
        hide(errorWrapper);
        hide(methodWrapper);
        hide(methodNameEl);
        hide(urlForm);
        methodDescriptionBQ.innerHTML = '';
        hide(methodDescription);
        hide(requestURLDisplay);
        hide(requestResultDisplay);
        invokeFrame.setAttribute('src', '');
    }

    function displayError(message) {
        resetDisplay();
        errorWrapper.innerHTML = `<b>Error:</b> ${message}`;
        show(errorWrapper);
    }

    function askForUrl() {
        displayError("can't contact web-services, please give absolute url to 'ws.php'");
        const input = urlForm.querySelector('input[name="ws_url"]');
        if (input.value === '') input.value = ws_url;
        show(urlForm);
    }

    function parsePwgJSON(json) {
        try {
            const resp = JSON.parse(json);
            if (resp === null || resp.result === null || resp.stat === null || resp.stat !== 'ok') {
                throw new Error();
            }
            return resp.result;
        } catch {
            displayError('unable to parse JSON string');
            return null;
        }
    }

    async function getMethodList() {
        resetDisplay();

        const url = `${ws_url}?format=json&method=reflection.getMethodList`;
        let body;
        try {
            const res = await fetch(url, { method: 'GET' });
            body = await res.text();
        } catch {
            askForUrl();
            return;
        }

        const result = parsePwgJSON(body);
        if (result === null) return;

        const methods = result.methods;
        const methodTree = {};
        for (const m of methods) {
            addMethodToNode(methodTree, m.split('.'));
        }

        methodsList.innerHTML = displayMethodNode(methodTree, []);
        show(methodsList);

        $$('#methodsList .method-link').forEach((link) => {
            link.addEventListener('click', () => selectMethod(link.dataset.method));
        });

        const stored = getCookie('wse-menu-state');
        if (stored) {
            stored.split(',').forEach((id) => {
                const cb = document.getElementById(id);
                if (cb) cb.checked = true;
            });
        }

        setStateMenu();

        $$('.method-node-content').forEach((content) => {
            const checkbox = content.parentElement?.querySelector(':scope > input');
            if (!checkbox) return;
            checkbox.addEventListener('change', () => {
                const id = checkbox.id;
                let menustate = getCookie('wse-menu-state')?.split(',') ?? [];
                if (checkbox.checked) {
                    show(content);
                    menustate.push(id);
                } else {
                    hide(content);
                    menustate = menustate.filter((s) => s !== id);
                }
                setCookie('wse-menu-state', menustate.join(','));
            });
        });
    }

    function addMethodToNode(methodNode, methodRoute) {
        if (methodRoute.length > 1) {
            const node = methodRoute.shift();
            if (!methodNode[node]) methodNode[node] = {};
            addMethodToNode(methodNode[node], methodRoute);
        } else {
            methodNode[methodRoute[0]] = 1;
        }
    }

    function displayMethodNode(methodNode, route) {
        if (methodNode === 1) {
            const name = route[route.length - 1];
            return `<a class="method-link" data-method="${route.join('.')}" title="${name}">${name}</a>`;
        }
        if (route.length === 0) {
            let html = '';
            for (const k in methodNode) {
                html += displayMethodNode(methodNode[k], [...route, k]);
            }
            return html;
        }
        const name = route[route.length - 1];
        const id = `method-node-input-${route.join('.')}`;
        let html = `<div class="method-node">
            <input type="checkbox" id="${id}">
            <label for="${id}" title="${name}">
                <i class="icon-down-open"></i>
                <span>${name}</span>
            </label>
            <div class="method-node-content">`;
        for (const k in methodNode) {
            html += displayMethodNode(methodNode[k], [...route, k]);
        }
        return html + '</div></div>';
    }

    function setStateMenu() {
        $$('.method-node').forEach((node) => {
            const content = node.querySelector(':scope > .method-node-content');
            const checkbox = node.querySelector(':scope > input');
            if (!content || !checkbox) return;
            if (checkbox.checked) show(content);
            else hide(content);
        });
    }

    async function selectMethod(methodName) {
        hide(introMessage);

        if (cachedMethods[methodName]) {
            fillNewMethod(methodName);
            return;
        }

        const url = `${ws_url}?format=json&method=reflection.getMethodDetails&methodName=${encodeURIComponent(methodName)}`;
        let body;
        try {
            const res = await fetch(url, { method: 'GET' });
            body = await res.text();
        } catch {
            displayError('unknown error');
            return;
        }

        const result = parsePwgJSON(body);
        if (result === null) return;

        const onlys = [];
        if (result.options.post_only) onlys.push('POST only');
        if (result.options.admin_only) onlys.push('Admin only');
        result.onlys = onlys;
        cachedMethods[methodName] = result;
        fillNewMethod(methodName);
    }

    function fillNewMethod(methodName) {
        resetDisplay();

        const method = cachedMethods[methodName];

        methodNameEl.textContent = method.name;
        show(methodNameEl);

        onlysEl.innerHTML = '';
        method.onlys.forEach((text) => {
            const span = document.createElement('span');
            span.className = 'only';
            span.textContent = text;
            onlysEl.appendChild(span);
        });

        if (method.description !== '') {
            methodDescriptionBQ.innerHTML = method.description;
            show(methodDescription);
        }

        requestFormat.value = method.options.post_only ? 'post' : 'get';

        if (method.params && method.params.length > 0) {
            hide(noParamsEl);
            show(methodParamsTable);

            let rows = '';
            for (let i = 0; i < method.params.length; i++) {
                const param = method.params[i];
                const isOptional = param.optional;
                const acceptArray = param.acceptArray;
                let defaultValue = param.defaultValue === null ? '' : param.defaultValue;
                if (typeof defaultValue === 'object') defaultValue = defaultValue.join('|');
                const info =
                    param.info === null
                        ? ''
                        : `<i class="methodInfo icon-info-circled-1" title="${param.info.replace(/"/g, '&quot;')}"></i>`;
                const optionalMark =
                    '<span class="required" title="This parameter is required">*</span>';
                const arrayMark =
                    '<span class="type-badge icon-clone" title="Can be an array"></span>';
                let type = '';
                if (param.type.match(/bool/))
                    type += '<span class="type-badge" title="Boolean">B<span>';
                if (param.type.match(/int/))
                    type += '<span class="type-badge" title="Integer">I</span>';
                if (param.type.match(/float/))
                    type += '<span class="type-badge" title="Float">F</span>';
                let subtype = '';
                if (param.type.match(/positive/))
                    subtype += '<span class="type-badge icon-plus" title="Positive"></span>';
                if (param.type.match(/notnull/))
                    subtype +=
                        '<span class="type-badge" title="Not null"><span style="transform:translateY(-3px)">&oslash;</span></span>';

                rows += `<tr>
                    <td>${param.name + (isOptional ? '' : optionalMark) + info}</td>
                    <td class="mini">${(acceptArray ? arrayMark : '') + type + subtype}</td>
                    <td class="input"><input type="text" class="methodParameterValue" data-id="${i}" value="${defaultValue}"></td>
                    <td class="mini">
                        <input type="checkbox" id="parameter-send-${i}" class="methodParameterSend" data-id="${i}" ${isOptional ? '' : 'checked'}>
                        <label class="methodParameterSendCheckbox" for="parameter-send-${i}"><i class="icon-ok"></i></label>
                    </td>
                </tr>`;
            }
            methodParamsTbody.innerHTML = rows;
        } else {
            show(noParamsEl);
            hide(methodParamsTable);
        }

        show(methodWrapper);

        // checking the value field auto-checks the matching "send" checkbox
        $$('input.methodParameterValue').forEach((input) => {
            input.addEventListener('change', () => {
                const send = $(`input.methodParameterSend[data-id="${input.dataset.id}"]`);
                if (send) send.checked = true;
            });
        });

        // Tooltips: rely on native browser title attributes (already set above
        // on .methodInfo / .required / .type-badge). The previous version used
        // tipTip — dropped along with jQuery.
    }

    async function invokeMethod(methodName, newWindow) {
        renderJsonViewer(jsonViewer, {});
        show(requestURLDisplay);
        show(requestResultDisplay);

        const method = cachedMethods[methodName];
        let reqUrl = `${ws_url}?format=${responseFormat.value}`;

        const authorization = apiKey.value;
        const useCookie = authorization === '';

        const fetchOption = {};
        if (!useCookie) {
            fetchOption.credentials = 'omit';
            fetchOption.headers = { 'X-PIWIGO-API': authorization };
        }

        const sendChecked = (i) => $(`input.methodParameterSend[data-id="${i}"]`)?.checked;
        const paramValue = (i) => $(`input.methodParameterValue[data-id="${i}"]`)?.value ?? '';

        if (requestFormat.value === 'get') {
            reqUrl += `&method=${methodName}`;
            for (let i = 0; i < method.params.length; i++) {
                if (!sendChecked(i)) continue;
                const value = paramValue(i);
                const split = value.split('|');
                if (method.params[i].acceptArray && split.length > 1) {
                    for (const v of split) {
                        reqUrl += `&${method.params[i].name}[]=${v}`;
                    }
                } else {
                    reqUrl += `&${method.params[i].name}=${value}`;
                }
            }

            if (newWindow) {
                window.open(reqUrl);
            } else {
                fetch(reqUrl, fetchOption)
                    .then((r) => r.text())
                    .then(showResponseData);
            }

            $('.url', requestURLDisplay).innerHTML = reqUrl;
            hide($('.params', requestURLDisplay));
        } else {
            // POST
            const params = {};
            invokeForm.setAttribute('action', reqUrl);
            let hiddenFields = `<input type="hidden" name="method" value="${methodName}">`;

            for (let i = 0; i < method.params.length; i++) {
                if (!sendChecked(i)) continue;
                const value = paramValue(i);
                const name = method.params[i].name;
                const split = value.split('|');
                if (method.params[i].acceptArray && split.length > 1) {
                    params[name] = [];
                    for (const v of split) {
                        params[name].push(v);
                        hiddenFields += `<input type="hidden" name="${name}[]" value="${v}">`;
                    }
                } else {
                    params[name] = value;
                    hiddenFields += `<input type="hidden" name="${name}" value="${value}">`;
                }
            }

            if (!newWindow) {
                hide(invokeFrame);
                show(jsonViewer);

                const formData = new URLSearchParams();
                formData.append('method', methodName);
                for (const key in params) {
                    formData.append(key, params[key]);
                }
                fetchOption.headers ??= {};
                fetchOption.headers['Content-Type'] = 'application/x-www-form-urlencoded';

                fetch(reqUrl, { ...fetchOption, method: 'POST', body: formData })
                    .then((r) => r.text())
                    .then(showResponseData);
            } else {
                show(invokeFrame);
                hide(jsonViewer);

                invokeForm.innerHTML = hiddenFields;
                invokeForm.setAttribute('target', '_blank');
                invokeForm.submit();
            }

            $('.url', requestURLDisplay).innerHTML = reqUrl;
            const paramsEl = $('.params', requestURLDisplay);
            show(paramsEl);
            paramsEl.innerHTML = JSON.stringify(params, null, 4);
        }
    }

    function showResponseData(data) {
        const isJson = responseFormat.value === 'json';
        if (isJson) {
            try {
                const json = JSON.parse(data);
                renderJsonViewer(jsonViewer, json);
                hide(invokeFrame);
                show(jsonViewer);
                return;
            } catch {
                writeIframe(`<pre>${data}</pre>`);
                show(invokeFrame);
                hide(jsonViewer);
                return;
            }
        }
        writeIframe(data);
        show(invokeFrame);
        hide(jsonViewer);
    }

    function writeIframe(content) {
        const doc = invokeFrame.contentDocument || invokeFrame.contentWindow?.document;
        if (!doc) return;
        doc.open();
        doc.write(content);
        doc.close();
    }
});
