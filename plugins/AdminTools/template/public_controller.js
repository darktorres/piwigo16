var AdminTools = (function () {
    var __this = this;

    this.urlWS;
    this.urlSelf;
    this.multiView;

    var $ato        = document.getElementById("ato_header");
    var $ato_closed = document.getElementById("ato_header_closed");
    var ato_height  = 28; // normal height, real height computed on init()

    // move the whole page down or up
    function moveBody(dir, anim) {
        var operator = dir == "show" ? 1 : -1;
        var body = document.body;
        var currentMT = parseInt(body.style.marginTop) || 0;
        var newMT = currentMT + operator * ato_height;

        if (anim) {
            body.style.transition = "margin-top 0.3s";
            body.style.marginTop = newMT + "px";
            setTimeout(function() { body.style.transition = ""; }, 300);

            var pages = document.querySelectorAll('#the_page, [data-role="page"]');
            pages.forEach(function(page) {
                if (window.getComputedStyle(page).position === "absolute") {
                    var currentTop = parseInt(page.style.top) || 0;
                    page.style.transition = "top 0.3s";
                    page.style.top = (currentTop + operator * ato_height) + "px";
                    setTimeout(function() { page.style.transition = ""; }, 300);
                }
            });
        } else {
            body.style.marginTop = newMT + "px";
            var pages = document.querySelectorAll('#the_page, [data-role="page"]');
            pages.forEach(function(page) {
                if (window.getComputedStyle(page).position === "absolute") {
                    var currentTop = parseInt(page.style.top) || 0;
                    page.style.top = (currentTop + operator * ato_height) + "px";
                }
            });
        }
    }

    // fill multiview selects
    // data came from AJAX request or sessionStorage
    function populateMultiView() {
        var multiview = $ato && $ato.querySelector(".multiview");
        if (!multiview) return;

        if (multiview.dataset.init) return;

        var render = function (data) {
            var html = "";
            data.users.forEach(function(user) {
                html += '<option value="' + user.id + '">' + user.username + "</option>";
            });
            var viewAs = multiview.querySelector('select[data-type="view_as"]');
            if (viewAs) { viewAs.innerHTML = html; viewAs.value = __this.multiView.view_as; }

            html = "";
            data.themes.forEach(function(theme) {
                html += '<option value="' + theme + '">' + theme + "</option>";
            });
            var themeEl = multiview.querySelector('select[data-type="theme"]');
            if (themeEl) { themeEl.innerHTML = html; themeEl.value = __this.multiView.theme; }

            html = "";
            data.languages.forEach(function(language) {
                html += '<option value="' + language.id + '">' + language.name + "</option>";
            });
            var langEl = multiview.querySelector('select[data-type="lang"]');
            if (langEl) { langEl.innerHTML = html; langEl.value = __this.multiView.lang; }

            multiview.dataset.init = "1";
            multiview.querySelectorAll(".switcher").forEach(function(el) { el.style.display = ''; });
        };

        if (
            "sessionStorage" in window &&
            window.sessionStorage.multiView != undefined
        ) {
            render(JSON.parse(window.sessionStorage.multiView));
        } else {
            fetch(__this.urlWS + "multiView.getData", { method: "POST" })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    render(data.result);
                    if ("sessionStorage" in window) {
                        window.sessionStorage.multiView = JSON.stringify(data.result);
                    }
                })
                .catch(function(err) { alert(err); });
        }
    }

    // delete session cache
    this.deleteCache = function () {
        if ("sessionStorage" in window) {
            window.sessionStorage.removeItem("multiView");
        }
    };

    // move close button to smartpocket toolbar
    this.initMobile = function () {
        var headerbar = document.querySelector('div[data-role="header"] .title');
        if (headerbar && $ato_closed) {
            $ato_closed.classList.add("smartpocket");
            var a = $ato_closed.querySelector("a");
            if (a) {
                a.setAttribute("data-iconpos", "notext");
                a.setAttribute("data-role", "button");
            }
            headerbar.insertAdjacentElement('afterbegin', $ato_closed);
        }
    };

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
        el.style.display = '';
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
        el.style.display = '';
        setTimeout(function() {
            el.style.opacity = '0';
            setTimeout(function() {
                el.style.display = 'none';
                el.style.transition = '';
                el.style.opacity = '';
            }, 200);
        }, 1600);
    }

    // attach event handlers
    this.init = function (open) {
        if (!$ato) return;
        document.body.insertAdjacentElement('afterbegin', $ato); // ensure bar is at beginning

        $ato.style.display = '';
        ato_height = $ato.offsetHeight;

        if ("localStorage" in window) {
            if (window.localStorage.ato_panel_open == null) {
                window.localStorage.ato_panel_open = open;
            }
            if (window.localStorage.ato_panel_open == 1) {
                moveBody("show", false);
            } else {
                $ato.style.display = 'none';
                if ($ato_closed) $ato_closed.style.display = '';
            }
        } else {
            $ato.style.display = '';
            moveBody("show", false);
        }

        /* sub menus */
        $ato.querySelectorAll(".parent").forEach(function(parent) {
            parent.addEventListener("click", function() {
                if (parent.classList.contains("multiview")) populateMultiView();
                var ul = parent.querySelector("ul");
                if (ul) ul.style.display = ul.style.display === "none" ? "" : "none";
            });
            parent.addEventListener("mouseleave", function(e) {
                if (e.target.tagName.toLowerCase() !== "select") {
                    var ul = parent.querySelector("ul");
                    if (ul) ul.style.display = "none";
                }
            });
        });
        $ato.querySelectorAll(".parent>a").forEach(function(a) {
            a.addEventListener("click", function(e) { e.preventDefault(); });
        });
        $ato.querySelectorAll(".parent ul").forEach(function(ul) {
            ul.addEventListener("mouseleave", function(e) {
                if (e.target.tagName.toLowerCase() !== "select") {
                    ul.style.display = "none";
                }
            });
        });

        /* select boxes */
        $ato.querySelectorAll(".switcher").forEach(function(sel) {
            sel.addEventListener("change", function() {
                window.location.href =
                    __this.urlSelf + "ato_" + sel.dataset.type + "=" + sel.value;
            });
            sel.addEventListener("click", function(e) { e.stopPropagation(); });
        });

        /* toggle toolbar */
        var closePanel = $ato.querySelector(".close-panel");
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
    };

    // init "set as representative" button
    this.initRepresentative = function (image_id, category_id) {
        if (!$ato) return;
        $ato.querySelectorAll(".set-representative").forEach(function(btn) {
            btn.addEventListener("click", function(e) {
                var li = btn.parentElement;
                if (!li.classList.contains("disabled")) {
                    li.classList.add("disabled");
                    var body = new URLSearchParams({
                        image_id: image_id, category_id: category_id
                    });
                    fetch(__this.urlWS + "pwg.categories.setRepresentative", {
                        method: "POST", body: body
                    })
                    .then(function() {
                        var saved = $ato.querySelector(".saved");
                        if (saved) _fadeInOut(saved);
                    })
                    .catch(function(err) { alert(err); });
                }
                e.preventDefault();
            });
        });
    };

    // init "add to caddie" button
    this.initCaddie = function (image_id) {
        if (!$ato) return;
        $ato.querySelectorAll(".add-caddie").forEach(function(btn) {
            btn.addEventListener("click", function(e) {
                var li = btn.parentElement;
                if (!li.classList.contains("disabled")) {
                    li.classList.add("disabled");
                    var body = new URLSearchParams({ image_id: image_id });
                    fetch(__this.urlWS + "pwg.caddie.add", {
                        method: "POST", body: body
                    })
                    .then(function() {
                        var saved = $ato.querySelector(".saved");
                        if (saved) _fadeInOut(saved);
                    })
                    .catch(function(err) { alert(err); });
                }
                e.preventDefault();
            });
        });
    };

    // init "quick edit" popup — native <dialog> + Tom Select
    this.initQuickEdit = function (is_picture) {
        var dlg = document.getElementById('ato_quick_edit_dlg');
        var ato_edit = document.getElementById('ato_quick_edit');
        if (!dlg || !ato_edit) return;

        ato_edit.querySelector('.close-edit').addEventListener('click', function(e) {
            dlg.close();
            e.preventDefault();
        });

        document.querySelectorAll('.edit-quick').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.preventDefault();

                // Set background color from the page theme
                var bg = 'white';
                ['#the_page #content', '[data-role="page"]', 'body'].some(function(sel) {
                    var node = document.querySelector(sel);
                    var c = node && window.getComputedStyle(node).backgroundColor;
                    if (c && c !== 'transparent' && c !== 'rgba(0, 0, 0, 0)') { bg = c; return true; }
                });
                ato_edit.style.backgroundColor = bg;

                if (is_picture && !ato_edit.dataset.tagsInit) {
                    ato_edit.dataset.tagsInit = '1';
                    fetch(__this.urlWS + 'pwg.tags.getList')
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            var existingIds = Array.from(ato_edit.querySelectorAll('.tags option')).map(function(o) { return String(o.value); });
                            var options = data.result.tags.map(function(t) { return {value: String(t.id), text: t.name}; });
                            new TomSelect(ato_edit.querySelector('.tags'), {
                                valueField: 'value', labelField: 'text', searchField: 'text',
                                options: options, items: existingIds,
                                create: true, plugins: ['remove_button']
                            });
                        });
                }

                dlg.showModal();
                setTimeout(function() { var n = document.getElementById('quick_edit_name'); if (n) n.focus(); }, 0);
            });
        });

        if (typeof Mousetrap !== 'undefined') {
            Mousetrap.bind('mod+e', function(e) {
                e.preventDefault();
                var btn = document.querySelector('.edit-quick');
                if (btn) btn.click();
            });
        }
    };

    return this;
})();
