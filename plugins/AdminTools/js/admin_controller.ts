interface MultiViewConfig {
    view_as: string;
    theme: string;
    lang: string;
}

interface MultiViewData {
    users: Array<{ id: string | number; status: string; username: string }>;
    languages: Array<{ id: string; name: string }>;
}

interface AdminToolsConfig {
    urlWS?: string;
    urlSelf?: string;
    multiView?: MultiViewConfig;
    deleteCache?: boolean;
}

export const AdminTools = (function () {
    const state = {
        urlWS: null as string | null,
        urlSelf: null as string | null,
        multiView: null as MultiViewConfig | null,

        deleteCache(): void {
            if ("sessionStorage" in window) {
                window.sessionStorage.removeItem("multiView");
            }
        },

        init(_open?: boolean): void {
            const $ato = document.getElementById("ato_container");
            if (!$ato) return;

            const multiview = document.querySelector<HTMLElement>(".multiview");
            if (multiview) $ato.appendChild(multiview);

            $ato.addEventListener("click", function (e) {
                populateMultiView($ato, state);
                const ul = (e.currentTarget as HTMLElement).querySelector<HTMLElement>("ul");
                if (ul) ul.style.display = ul.style.display === "none" ? "" : "none";
            });
            $ato.addEventListener("mouseleave", function (e) {
                if ((e.target as HTMLElement).tagName.toLowerCase() !== "select") {
                    $ato.querySelectorAll<HTMLElement>("ul").forEach(function (ul) { ul.style.display = "none"; });
                }
            });
            $ato.querySelectorAll<HTMLAnchorElement>(":scope > a").forEach(function (a) {
                a.addEventListener("click", function (e) { e.preventDefault(); });
            });
            $ato.querySelectorAll<HTMLElement>("ul").forEach(function (ul) {
                ul.addEventListener("mouseleave", function (e) {
                    if ((e.target as HTMLElement).tagName.toLowerCase() !== "select") {
                        ul.style.display = "none";
                    }
                });
            });

            $ato.querySelectorAll<HTMLSelectElement>(".switcher").forEach(function (sel) {
                sel.addEventListener("change", function () {
                    if (sel.dataset['type'] == "theme") {
                        if (sel.value != state.multiView?.theme) {
                            window.location.href = (state.urlSelf ?? '') + "change_theme=1";
                        }
                    } else {
                        window.location.href =
                            (state.urlSelf ?? '') + "ato_" + sel.dataset['type'] + "=" + sel.value;
                    }
                });
                sel.addEventListener("click", function (e) { e.stopPropagation(); });
            });
        },

        setConfig(config: AdminToolsConfig): void {
            if (config.urlWS) state.urlWS = config.urlWS;
            if (config.urlSelf) state.urlSelf = config.urlSelf;
            if (config.multiView) state.multiView = config.multiView;
            if (config.deleteCache) state.deleteCache();
        },
    };

    return state;
})();

function populateMultiView(
    $ato: HTMLElement,
    state: { urlWS: string | null; multiView: MultiViewConfig | null }
): void {
    const multiview = $ato.querySelector<HTMLElement>(".multiview");
    if (!multiview) return;
    if (multiview.dataset['init']) return;

    function render(data: MultiViewData): void {
        if (!multiview) return;
        let html = "";
        data.users.forEach(function (user) {
            if (user.status == "webmaster" || user.status == "admin") {
                html += '<option value="' + user.id + '">' + user.username + "</option>";
            }
        });
        const viewAs = multiview.querySelector<HTMLSelectElement>('select[data-type="view_as"]');
        if (viewAs) { viewAs.innerHTML = html; viewAs.value = state.multiView?.view_as ?? ''; }

        html = "";
        ["clear", "roma"].forEach(function (theme) {
            html += '<option value="' + theme + '">' + theme + "</option>";
        });
        const themeEl = multiview.querySelector<HTMLSelectElement>('select[data-type="theme"]');
        if (themeEl) { themeEl.innerHTML = html; themeEl.value = state.multiView?.theme ?? ''; }

        html = "";
        data.languages.forEach(function (language) {
            html += '<option value="' + language.id + '">' + language.name + "</option>";
        });
        const langEl = multiview.querySelector<HTMLSelectElement>('select[data-type="lang"]');
        if (langEl) { langEl.innerHTML = html; langEl.value = state.multiView?.lang ?? ''; }

        multiview.dataset['init'] = "1";
        multiview.querySelectorAll<HTMLElement>(".switcher").forEach(function (el) {
            el.style.display = '';
        });
    }

    if ("sessionStorage" in window && window.sessionStorage['multiView'] != undefined) {
        render(JSON.parse(window.sessionStorage['multiView'] as string) as MultiViewData);
    } else {
        fetch((state.urlWS ?? '') + "multiView.getData", { method: "POST" })
            .then(function (r) { return r.json(); })
            .then(function (data: { result: MultiViewData }) {
                render(data.result);
                if ("sessionStorage" in window) {
                    window.sessionStorage['multiView'] = JSON.stringify(data.result);
                }
            })
            .catch(function (err) { alert(err); });
    }
}
