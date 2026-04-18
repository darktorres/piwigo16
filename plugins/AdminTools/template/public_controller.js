/* global TomSelect, Mousetrap */
let urlWS = '';
let urlSelf = '';
let multiView = {};

const $ato = document.getElementById("ato_header");
const $ato_closed = document.getElementById("ato_header_closed");
let ato_height = 28;

function moveBody(dir, anim) {
    const operator = dir === "show" ? 1 : -1;
    const body = document.body;
    const currentMT = parseInt(body.style.marginTop) || 0;
    const newMT = currentMT + operator * ato_height;

    if (anim) {
        body.style.transition = "margin-top 0.3s";
        body.style.marginTop = newMT + "px";
        setTimeout(function() { body.style.transition = ""; }, 300);

        const pages = document.querySelectorAll('#the_page, [data-role="page"]');
        pages.forEach(function(page) {
            if (window.getComputedStyle(page).position === "absolute") {
                const currentTop = parseInt(page.style.top) || 0;
                page.style.transition = "top 0.3s";
                page.style.top = (currentTop + operator * ato_height) + "px";
                setTimeout(function() { page.style.transition = ""; }, 300);
            }
        });
    } else {
        body.style.marginTop = newMT + "px";
        const pages = document.querySelectorAll('#the_page, [data-role="page"]');
        pages.forEach(function(page) {
            if (window.getComputedStyle(page).position === "absolute") {
                const currentTop = parseInt(page.style.top) || 0;
                page.style.top = (currentTop + operator * ato_height) + "px";
            }
        });
    }
}

function populateMultiView() {
    const multiviewEl = $ato && $ato.querySelector(".multiview");
    if (!multiviewEl) return;

    if (multiviewEl.dataset.init) return;

    const render = function (data) {
        let html = "";
        data.users.forEach(function(user) {
            html += '<option value="' + user.id + '">' + user.username + "</option>";
        });
        const viewAs = multiviewEl.querySelector('select[data-type="view_as"]');
        if (viewAs) { viewAs.innerHTML = html; viewAs.value = multiView.view_as; }

        html = "";
        data.themes.forEach(function(theme) {
            html += '<option value="' + theme + '">' + theme + "</option>";
        });
        const themeEl = multiviewEl.querySelector('select[data-type="theme"]');
        if (themeEl) { themeEl.innerHTML = html; themeEl.value = multiView.theme; }

        html = "";
        data.languages.forEach(function(language) {
            html += '<option value="' + language.id + '">' + language.name + "</option>";
        });
        const langEl = multiviewEl.querySelector('select[data-type="lang"]');
        if (langEl) { langEl.innerHTML = html; langEl.value = multiView.lang; }

        multiviewEl.dataset.init = "1";
        multiviewEl.querySelectorAll(".switcher").forEach(function(el) { el.style.display = 'block'; });
    };

    if (
        "sessionStorage" in window &&
        window.sessionStorage.multiView !== undefined
    ) {
        render(JSON.parse(window.sessionStorage.multiView));
    } else {
        fetch(urlWS + "multiView.getData", { method: "POST" })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                render(data.result);
                if ("sessionStorage" in window) {
                    window.sessionStorage.multiView = JSON.stringify(data.result);
                }
            })
            .catch(function(_err) { alert(_err); });
    }
}

function _slideUp(el, cb) {
    if (!el) return;
    el.style.transition = 'opacity 0.2s';
    el.style.opacity = '0';
    setTimeout(function() {
        el.style.display = 'none';
        el.style.transition = '';
        el.style.opacity = '';
        if (cb) cb();
    }, 200);
}

function _slideDown(el) {
    if (!el) return;
    el.style.opacity = '0';
    el.style.display = 'block';
    el.style.transition = 'opacity 0.2s';
    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            el.style.opacity = '1';
            setTimeout(function() { el.style.transition = ''; el.style.opacity = ''; }, 200);
        });
    });
}

function _fadeInOut(el) {
    if (!el) return;
    el.style.transition = 'opacity 0.2s';
    el.style.opacity = '1';
    el.style.display = 'block';
    setTimeout(function() {
        el.style.opacity = '0';
        setTimeout(function() {
            el.style.display = 'none';
            el.style.transition = '';
            el.style.opacity = '';
        }, 200);
    }, 1600);
}

export const AdminTools = {
    deleteCache: function () {
        if ("sessionStorage" in window) {
            window.sessionStorage.removeItem("multiView");
        }
    },

    initMobile: function () {
        const headerbar = document.querySelector('div[data-role="header"] .title');
        if (headerbar && $ato_closed) {
            $ato_closed.classList.add("smartpocket");
            const a = $ato_closed.querySelector("a");
            if (a) {
                a.setAttribute("data-iconpos", "notext");
                a.setAttribute("data-role", "button");
            }
            headerbar.insertAdjacentElement('afterbegin', $ato_closed);
        }
    },

    init: function (open) {
        if (!$ato) return;
        document.body.insertAdjacentElement('afterbegin', $ato);

        $ato.style.display = 'block';
        ato_height = $ato.offsetHeight;

        if ("localStorage" in window) {
            if (window.localStorage.ato_panel_open == null) {
                window.localStorage.ato_panel_open = open;
            }
            if (window.localStorage.ato_panel_open == 1) {
                moveBody("show", false);
            } else {
                $ato.style.display = 'none';
                if ($ato_closed) $ato_closed.style.display = 'block';
            }
        } else {
            $ato.style.display = 'block';
            moveBody("show", false);
        }

        $ato.querySelectorAll(".parent").forEach(function(parent) {
            parent.addEventListener("click", function() {
                if (parent.classList.contains("multiview")) populateMultiView();
                const ul = parent.querySelector("ul");
                if (ul) ul.style.display = window.getComputedStyle(ul).display === "none" ? "block" : "none";
            });
            parent.addEventListener("mouseleave", function(_e) {
                if (_e.target.tagName.toLowerCase() !== "select") {
                    const ul = parent.querySelector("ul");
                    if (ul) ul.style.display = "none";
                }
            });
        });
        $ato.querySelectorAll(".parent>a").forEach(function(a) {
            a.addEventListener("click", function(e) { e.preventDefault(); });
        });
        $ato.querySelectorAll(".parent ul").forEach(function(ul) {
            ul.addEventListener("mouseleave", function(_e) {
                if (_e.target.tagName.toLowerCase() !== "select") {
                    ul.style.display = "none";
                }
            });
        });

        $ato.querySelectorAll(".switcher").forEach(function(sel) {
            sel.addEventListener("change", function() {
                window.location.href =
                    urlSelf + "ato_" + sel.dataset.type + "=" + sel.value;
            });
            sel.addEventListener("click", function(e) { e.stopPropagation(); });
        });

        const closePanel = $ato.querySelector(".close-panel");
        if (closePanel) {
            closePanel.addEventListener("click", function(e) {
                _slideUp($ato, null);
                _slideDown($ato_closed);
                moveBody("hide", true);
                if ("localStorage" in window) window.localStorage.ato_panel_open = 0;
                e.preventDefault();
            });
        }
        if ($ato_closed) {
            $ato_closed.addEventListener("click", function(e) {
                _slideDown($ato);
                _slideUp($ato_closed, null);
                moveBody("show", true);
                if ("localStorage" in window) window.localStorage.ato_panel_open = 1;
                e.preventDefault();
            });
        }
    },

    initRepresentative: function (image_id, category_id) {
        if (!$ato) return;
        $ato.querySelectorAll(".set-representative").forEach(function(btn) {
            btn.addEventListener("click", function(e) {
                const li = btn.parentElement;
                if (!li.classList.contains("disabled")) {
                    li.classList.add("disabled");
                    const body = new URLSearchParams({
                        image_id: image_id, category_id: category_id
                    });
                    fetch(urlWS + "pwg.categories.setRepresentative", {
                        method: "POST", body: body
                    })
                    .then(function() {
                        const saved = $ato.querySelector(".saved");
                        if (saved) _fadeInOut(saved);
                    })
                    .catch(function(_err) { alert(_err); });
                }
                e.preventDefault();
            });
        });
    },

    initCaddie: function (image_id) {
        if (!$ato) return;
        $ato.querySelectorAll(".add-caddie").forEach(function(btn) {
            btn.addEventListener("click", function(e) {
                const li = btn.parentElement;
                if (!li.classList.contains("disabled")) {
                    li.classList.add("disabled");
                    const body = new URLSearchParams({ image_id: image_id });
                    fetch(urlWS + "pwg.caddie.add", {
                        method: "POST", body: body
                    })
                    .then(function() {
                        const saved = $ato.querySelector(".saved");
                        if (saved) _fadeInOut(saved);
                    })
                    .catch(function(_err) { alert(_err); });
                }
                e.preventDefault();
            });
        });
    },

    initQuickEdit: function (is_picture) {
        const dlg = document.getElementById('ato_quick_edit_dlg');
        const ato_edit = document.getElementById('ato_quick_edit');
        if (!dlg || !ato_edit) return;

        ato_edit.querySelector('.close-edit').addEventListener('click', function(e) {
            dlg.close();
            e.preventDefault();
        });

        document.querySelectorAll('.edit-quick').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.preventDefault();

                let bg = 'white';
                ['#the_page #content', '[data-role="page"]', 'body'].some(function(sel) {
                    const node = document.querySelector(sel);
                    const c = node && window.getComputedStyle(node).backgroundColor;
                    if (c && c !== 'transparent' && c !== 'rgba(0, 0, 0, 0)') { bg = c; return true; }
                });
                ato_edit.style.backgroundColor = bg;

                if (is_picture && !ato_edit.dataset.tagsInit) {
                    ato_edit.dataset.tagsInit = '1';
                    fetch(urlWS + 'pwg.tags.getList')
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            const existingIds = Array.from(ato_edit.querySelectorAll('.tags option')).map(function(o) { return String(o.value); });
                            const options = data.result.tags.map(function(t) { return {value: String(t.id), text: t.name}; });
                            new TomSelect(ato_edit.querySelector('.tags'), {
                                valueField: 'value', labelField: 'text', searchField: 'text',
                                options: options, items: existingIds,
                                create: true, plugins: ['remove_button']
                            });
                        });
                }

                dlg.showModal();
                setTimeout(function() { const n = document.getElementById('quick_edit_name'); if (n) n.focus(); }, 0);
            });
        });

        if (typeof Mousetrap !== 'undefined') {
            Mousetrap.bind('mod+e', function(e) {
                e.preventDefault();
                const btn = document.querySelector('.edit-quick');
                if (btn) btn.click();
            });
        }
    },

    setConfig: function(config) {
        urlWS = config.urlWS || '';
        urlSelf = config.urlSelf || '';
        multiView = config.multiView || {};
    }
};
