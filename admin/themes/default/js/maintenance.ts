import { initModule } from './moduleInit.js';
import { pwgConfirmFollowHref } from './pwgConfirm.js';

interface MaintenanceConfig {
    confirm_msg?: string;
    cancel_msg?: string;
    str_gallery_tip?: string;
    str_lock_unlock_title?: string;
    str_purge_detail?: string;
    str_purge_summary?: string;
    str_purge_search?: string;
    str_delete_all_sizes?: string;
    unit_MB?: string;
    no_time_elapsed?: string;
    pwg_token?: string;
}

interface CacheSizeInfo { value: number | Record<string, number> }
interface CacheSizeResponse {
    stat: string;
    result: { infos: CacheSizeInfo[] };
}

export function init(cfg: MaintenanceConfig): void {
    const confirm_msg = cfg.confirm_msg ?? 'Yes, I am sure';
    const cancel_msg = cfg.cancel_msg ?? 'No, I have changed my mind';
    const str_gallery_tip = cfg.str_gallery_tip ?? 'A locked gallery is only visible to administrators';
    const str_lock_unlock_title = cfg.str_lock_unlock_title ?? 'Are you sure?';
    const str_purge_detail = cfg.str_purge_detail ?? 'Purge history detail';
    const str_purge_summary = cfg.str_purge_summary ?? 'Purge history summary';
    const str_purge_search = cfg.str_purge_search ?? 'Purge search history';
    const str_delete_all_sizes = cfg.str_delete_all_sizes ?? 'Are you sure you want to delete all sizes?';
    const unit_MB = cfg.unit_MB ?? '%s MB';
    const no_time_elapsed = cfg.no_time_elapsed ?? 'right now';
    const pwg_token = cfg.pwg_token ?? '';

    function displayResponse(
        domElem: (Element | null)[],
        values: string[],
        mDivs: NodeListOf<Element>,
        mValues: Record<string, string>,
    ): void {
        for (let index = 0; index < domElem.length; index++) {
            if (domElem[index]) domElem[index]!.innerHTML = unit_MB.replace("%s", values[index]);
        }
        mDivs.forEach(function (div) {
            const name = div.getAttribute("name");
            if (name) (div as HTMLElement).title = unit_MB.replace("%s", mValues[name]);
        });
        const cacheLastCalc = document.querySelector(".cache-lastCalculated-value");
        if (cacheLastCalc) cacheLastCalc.innerHTML = no_time_elapsed;
    }

    const confirmOpts = { alert_confirm: confirm_msg, alert_cancel: cancel_msg };
    document.querySelectorAll<HTMLAnchorElement>(".lock-gallery-button").forEach(el =>
        pwgConfirmFollowHref(el, { ...confirmOpts, alert_title: str_lock_unlock_title, alert_content: str_gallery_tip }));
    document.querySelectorAll<HTMLAnchorElement>(".purge-history-detail-button").forEach(el =>
        pwgConfirmFollowHref(el, { ...confirmOpts, alert_title: str_purge_detail }));
    document.querySelectorAll<HTMLAnchorElement>(".purge-history-summary-button").forEach(el =>
        pwgConfirmFollowHref(el, { ...confirmOpts, alert_title: str_purge_summary }));
    document.querySelectorAll<HTMLAnchorElement>(".purge-search-history-button").forEach(el =>
        pwgConfirmFollowHref(el, { ...confirmOpts, alert_title: str_purge_search }));
    document.querySelectorAll<HTMLAnchorElement>(".delete-all-sizes-button").forEach(el =>
        pwgConfirmFollowHref(el, { ...confirmOpts, alert_title: str_delete_all_sizes }));

    document.querySelectorAll<HTMLElement>(".delete-size-check").forEach(function (el) {
        el.addEventListener('click', function () {
            if (el.getAttribute('data-selected') === '1') {
                el.setAttribute('data-selected', '0');
                const icon = el.querySelector<HTMLElement>("i");
                if (icon) icon.style.display = 'none';
            } else {
                el.setAttribute('data-selected', '1');
                const icon = el.querySelector<HTMLElement>("i");
                if (icon) icon.style.display = '';
            }
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    const firstDeleteSizeCheck = document.querySelector<HTMLElement>(".delete-size-check");
    if (firstDeleteSizeCheck) {
        firstDeleteSizeCheck.addEventListener('change', function () {
            const allChecks = document.querySelectorAll<HTMLElement>(".delete-size-check");
            if (firstDeleteSizeCheck.getAttribute('data-selected') === '1') {
                allChecks.forEach(el => { el.style.display = 'none'; el.setAttribute("data-selected", "1"); });
                firstDeleteSizeCheck.style.display = '';
            } else {
                allChecks.forEach(el => { el.style.display = ''; el.setAttribute("data-selected", "0"); });
            }
        });
    }

    const delete_deriv_URL = "admin.php?page=maintenance&action=derivatives&";
    document.querySelectorAll<HTMLElement>(".delete-size-check").forEach(function (el) {
        el.addEventListener('change', function () {
            const delete_deriv_with_token = delete_deriv_URL + "pwg_token=" + pwg_token + "&";
            const selected: string[] = [];
            document.querySelectorAll<HTMLElement>(".delete-size-check").forEach(function (check) {
                if (check.getAttribute("data-selected") === '1') selected.push(check.getAttribute("name") ?? '');
            });
            const deleteLink = document.querySelector(".delete-sizes");
            if (selected.length === 0) {
                if (deleteLink) deleteLink.setAttribute("href", "");
            } else {
                const types_str = selected[0] === "all" ? "all" : selected.join("_");
                if (deleteLink) deleteLink.setAttribute("href", delete_deriv_with_token + "type=" + types_str);
            }
        });
    });

    const deleteSizesLink = document.querySelector<HTMLElement>(".delete-sizes");
    if (deleteSizesLink) deleteSizesLink.style.display = 'none';

    document.querySelectorAll<HTMLElement>(".delete-size-check").forEach(function (el) {
        el.addEventListener('click', function () {
            let displayDeleteSizes = false;
            document.querySelectorAll<HTMLElement>(".delete-size-check").forEach(function (check) {
                if (check.getAttribute("data-selected") === '1') displayDeleteSizes = true;
            });
            const deleteSizes = document.querySelector<HTMLElement>(".delete-sizes");
            if (deleteSizes) deleteSizes.style.display = displayDeleteSizes ? '' : 'none';
        });
    });

    document.querySelectorAll<HTMLElement>(".refresh-cache-size").forEach(function (btn) {
        btn.addEventListener("click", function () {
            const refreshIcon = btn.querySelector<HTMLElement>(".refresh-icon");
            if (refreshIcon) refreshIcon.classList.add("animate-spin");

            fetch("ws.php?format=json&method=pwg.getCacheSize", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ param: "test_param", service: "test_service" }),
            })
                .then(r => r.text())
                .then(function (raw) {
                    const data = JSON.parse(raw) as CacheSizeResponse;
                    if (data.stat === "ok") {
                        const domElemToRefresh = [
                            document.querySelector(".cache-size-value"),
                            document.querySelector(".multiple-pictures-sizes"),
                            document.querySelector(".multiple-compiledTemplate-sizes"),
                        ];
                        const domElemValues = [
                            String((data.result.infos[0].value as number) / 1024 / 1024),
                            String(((data.result.infos[1].value as Record<string, number>).all) / 1024 / 1024),
                            String((data.result.infos[2].value as number) / 1024 / 1024),
                        ].map(v => parseFloat(v).toFixed(2));

                        const deleteCheckContainer = document.querySelector(".delete-check-container");
                        const multipleSizes = deleteCheckContainer
                            ? deleteCheckContainer.querySelectorAll(".delete-size-check")
                            : document.querySelectorAll(".delete-size-check-none");
                        const multipleSizesValues = Object.fromEntries(
                            Object.entries(data.result.infos[1].value as Record<string, number>).map(
                                ([k, v]) => [k, parseFloat((v / 1024 / 1024).toFixed(2)).toString()]
                            )
                        );

                        displayResponse(domElemToRefresh, domElemValues, multipleSizes, multipleSizesValues);
                        document.querySelectorAll<HTMLElement>(".animate-spin").forEach(el => el.classList.remove("animate-spin"));
                    }
                })
                .catch(console.log);
        });
    });
}

initModule(init as (cfg: Record<string, unknown>) => void);
