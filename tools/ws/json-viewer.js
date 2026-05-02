// Vanilla replacement for jquery.json-viewer.
// Renders a JS value into collapsible HTML inside `target`. CSS lives in
// json-viewer.css and uses .json-document, .json-dict, .json-array,
// .json-string, .json-literal, .json-toggle, .json-placeholder.
//
// Originally derived from https://github.com/abodelot/jquery.json-viewer
// (MIT, Alexandre Bodelot).

const URL_RE =
    /^(https?:\/\/|ftps?:\/\/)?([a-z0-9%-]+\.){1,}([a-z0-9-]+)?(:(\d{1,5}))?(\/([a-z0-9\-._~:/?#[\]@!$&'()*+,;=%]+)?)?$/i;

function isCollapsable(arg) {
    return arg instanceof Object && Object.keys(arg).length > 0;
}

function escapeHtml(s) {
    return s
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/'/g, '&apos;')
        .replace(/"/g, '&quot;');
}

function json2html(json, options) {
    if (typeof json === 'string') {
        const escaped = escapeHtml(json);
        if (options.withLinks && URL_RE.test(json)) {
            return `<a href="${escaped}" class="json-string" target="_blank">${escaped}</a>`;
        }
        return `<span class="json-string">"${escaped.replace(/&quot;/g, '\\&quot;')}"</span>`;
    }
    if (typeof json === 'number' || typeof json === 'boolean') {
        return `<span class="json-literal">${json}</span>`;
    }
    if (json === null) {
        return '<span class="json-literal">null</span>';
    }
    if (Array.isArray(json)) {
        if (json.length === 0) return '[]';
        let html = '[<ol class="json-array">';
        for (let i = 0; i < json.length; i++) {
            html += '<li>';
            if (isCollapsable(json[i])) html += '<a href class="json-toggle"></a>';
            html += json2html(json[i], options);
            if (i < json.length - 1) html += ',';
            html += '</li>';
        }
        return html + '</ol>]';
    }
    if (typeof json === 'object') {
        const keys = Object.keys(json);
        if (keys.length === 0) return '{}';
        let html = '{<ul class="json-dict">';
        for (let i = 0; i < keys.length; i++) {
            const key = keys[i];
            const value = json[key];
            const keyRepr = options.withQuotes ? `<span class="json-string">"${key}"</span>` : key;
            html += '<li>';
            if (isCollapsable(value)) {
                html += `<a href class="json-toggle">${keyRepr}</a>`;
            } else {
                html += keyRepr;
            }
            html += `: ${json2html(value, options)}`;
            if (i < keys.length - 1) html += ',';
            html += '</li>';
        }
        return html + '</ul>}';
    }
    return '';
}

export function renderJsonViewer(target, json, options = {}) {
    const opts = {
        collapsed: false,
        rootCollapsable: true,
        withQuotes: false,
        withLinks: true,
        ...options,
    };

    let html = json2html(json, opts);
    if (opts.rootCollapsable && isCollapsable(json)) {
        html = '<a href class="json-toggle"></a>' + html;
    }

    target.innerHTML = html;
    target.classList.add('json-document');

    target.addEventListener('click', (e) => {
        const link =
            e.target instanceof Element
                ? e.target.closest('a.json-toggle, a.json-placeholder')
                : null;
        if (!link || !target.contains(link)) return;
        e.preventDefault();

        if (link.classList.contains('json-placeholder')) {
            const sibling = link.parentElement?.querySelector(':scope > a.json-toggle');
            sibling?.click();
            return;
        }

        link.classList.toggle('collapsed');
        const collection = link.parentElement?.querySelector(
            ':scope > ul.json-dict, :scope > ol.json-array'
        );
        if (!collection) return;

        const willHide = collection.style.display !== 'none' && !collection.hidden;
        if (willHide) {
            collection.style.display = 'none';
            const count = collection.children.length;
            const placeholder = document.createElement('a');
            placeholder.href = '';
            placeholder.className = 'json-placeholder';
            placeholder.textContent = `${count} ${count > 1 ? 'items' : 'item'}`;
            collection.after(placeholder);
        } else {
            collection.style.display = '';
            const placeholder = collection.parentElement?.querySelector(
                ':scope > a.json-placeholder'
            );
            placeholder?.remove();
        }
    });

    if (opts.collapsed) {
        target.querySelectorAll('a.json-toggle').forEach((a) => a.click());
    }
}
