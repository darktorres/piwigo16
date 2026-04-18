export const AdminTools = (function () {
    const state = {
        urlWS: null,
        urlSelf: null,
        multiView: null
    };

    var $ato = document.getElementById("ato_container");

    // fill multiview selects
    // data came from AJAX request or sessionStorage
    function populateMultiView() {
        var multiview = $ato && $ato.querySelector(".multiview");
        if (!multiview) return;

        if (multiview.dataset.init) return;

        var render = function (data) {
            var html = "";
            data.users.forEach(function(user) {
                if (user.status == "webmaster" || user.status == "admin") {
                    html += '<option value="' + user.id + '">' + user.username + "</option>";
                }
            });
            var viewAs = multiview.querySelector('select[data-type="view_as"]');
            if (viewAs) { viewAs.innerHTML = html; viewAs.value = state.multiView.view_as; }

            html = "";
            ["clear", "roma"].forEach(function(theme) {
                html += '<option value="' + theme + '">' + theme + "</option>";
            });
            var themeEl = multiview.querySelector('select[data-type="theme"]');
            if (themeEl) { themeEl.innerHTML = html; themeEl.value = state.multiView.theme; }

            html = "";
            data.languages.forEach(function(language) {
                html += '<option value="' + language.id + '">' + language.name + "</option>";
            });
            var langEl = multiview.querySelector('select[data-type="lang"]');
            if (langEl) { langEl.innerHTML = html; langEl.value = state.multiView.lang; }

            multiview.dataset.init = "1";

            multiview.querySelectorAll(".switcher").forEach(function(el) {
                el.style.display = '';
            });
        };

        if (
            "sessionStorage" in window &&
            window.sessionStorage.multiView != undefined
        ) {
            render(JSON.parse(window.sessionStorage.multiView));
        } else {
            fetch(state.urlWS + "multiView.getData", { method: "POST" })
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
    state.deleteCache = function () {
        if ("sessionStorage" in window) {
            window.sessionStorage.removeItem("multiView");
        }
    };

    // attach event handlers
    state.init = function (open) {
        if (!$ato) return;

        var multiview = document.querySelector(".multiview");
        if (multiview) $ato.appendChild(multiview);

        /* sub menus */
        $ato.addEventListener("click", function(e) {
            populateMultiView();
            var ul = e.currentTarget.querySelector("ul");
            if (ul) ul.style.display = ul.style.display === "none" ? "" : "none";
        });
        $ato.addEventListener("mouseleave", function(e) {
            if (e.target.tagName.toLowerCase() !== "select") {
                $ato.querySelectorAll("ul").forEach(function(ul) { ul.style.display = "none"; });
            }
        });
        $ato.querySelectorAll(":scope > a").forEach(function(a) {
            a.addEventListener("click", function(e) { e.preventDefault(); });
        });
        $ato.querySelectorAll("ul").forEach(function(ul) {
            ul.addEventListener("mouseleave", function(e) {
                if (e.target.tagName.toLowerCase() !== "select") {
                    ul.style.display = "none";
                }
            });
        });

        /* select boxes */
        $ato.querySelectorAll(".switcher").forEach(function(sel) {
            sel.addEventListener("change", function() {
                if (sel.dataset.type == "theme") {
                    if (sel.value != state.multiView.theme) {
                        window.location.href = state.urlSelf + "change_theme=1";
                    }
                } else {
                    window.location.href =
                        state.urlSelf + "ato_" + sel.dataset.type + "=" + sel.value;
                }
            });
            sel.addEventListener("click", function(e) { e.stopPropagation(); });
        });
    };

    state.setConfig = function(config) {
        if (config.urlWS) state.urlWS = config.urlWS;
        if (config.urlSelf) state.urlSelf = config.urlSelf;
        if (config.multiView) state.multiView = config.multiView;
        if (config.deleteCache) state.deleteCache();
    };

    return state;
})();
