import { initModule } from './moduleInit.js';
import tippy from 'tippy.js';
import { sprintf } from './common.js';
import { pwgDoubleSlider, type DoubleSliderOptions } from './doubleSlider.js';
import { pwgDatepicker } from './datepicker.js';
import { pwgAddAlbum } from './addAlbum.js';
import { CategoriesCache, TagsCache } from './LocalStorageCache.js';
import GLightbox from 'glightbox';

interface BatchManagerConfig {
    langCancel: string;
    deleteProgressMessage: string;
    syncProgressMessage: string;
    areYouSure: string;
    generateMsg: string;
    tagCreate: string;
    nbThumbsPage: number;
    nbThumbsSet: number;
    applyOnDetailsPattern: string;
    allElements: string[];
    selectedMessagePattern: string;
    selectedMessageNone: string;
    selectedMessageAll: string;
    associatedCategories: Record<string, boolean>;
    tagsServerKey: string;
    categoriesServerKey: string;
    cacheServerId: string;
    rootUrl: string;
    sliders: Record<string, unknown>;
}

interface DerivativesState {
    elements: string[] | null;
    done: number;
    total: number;
    finished: () => boolean;
}

let categoriesCache: CategoriesCache | undefined;

/* ********** Filters */
function filter_enable(filter: string): void {
    const el = document.getElementById(filter);
    if (el) el.style.display = '';

    const checkbox = document.querySelector<HTMLInputElement>("input[type=checkbox][name=" + filter + "_use]");
    if (checkbox) checkbox.checked = true;

    const addFilterLink = document.querySelector<HTMLElement>("#addFilter a[data-value=" + filter + "]");
    if (addFilterLink) addFilterLink.classList.add("disabled");

    document.querySelectorAll<HTMLElement>(".noFilter").forEach(function (el) {
        el.style.display = 'none';
    });
    document.querySelectorAll<HTMLElement>(".addFilter-button").forEach(function (el) {
        el.classList.remove("highlight");
    });
}

function filter_disable(filter: string): void {
    const el = document.getElementById(filter);
    if (el) el.style.display = 'none';

    const checkbox = document.querySelector<HTMLInputElement>("input[name=" + filter + "_use]");
    if (checkbox) checkbox.checked = false;

    const addFilterLink = document.querySelector<HTMLElement>("#addFilter a[data-value=" + filter + "]");
    if (addFilterLink) addFilterLink.classList.remove("disabled");

    const visibleFilters = document.querySelectorAll("#filterList li:not([style*='display: none'])").length;
    if (visibleFilters === 0) {
        document.querySelectorAll<HTMLElement>(".noFilter").forEach(function (el) {
            el.style.display = '';
        });
        document.querySelectorAll<HTMLElement>(".addFilter-button").forEach(function (el) {
            el.classList.add("highlight");
        });
    }
}

/* Called from the addFilter-button onclick attribute */
function toggleAddFilterDropdown(): void {
    const el = document.querySelector<HTMLElement>('.addFilter-dropdown');
    if (el) el.style.display = window.getComputedStyle(el).display === 'none' ? 'block' : 'none';
}

/* Called from javascript: hrefs in the generate/delete derivatives action panels */
function selectGenerateDerivAll(): void {
    document.querySelectorAll<HTMLInputElement>("#action_generate_derivatives input[type=checkbox]").forEach(function (el) {
        el.checked = true;
        el.dispatchEvent(new Event("change"));
    });
}

function selectGenerateDerivNone(): void {
    document.querySelectorAll<HTMLInputElement>("#action_generate_derivatives input[type=checkbox]").forEach(function (el) {
        el.checked = false;
        el.dispatchEvent(new Event("change"));
    });
}

function selectDelDerivAll(): void {
    document.querySelectorAll<HTMLInputElement>('#action_delete_derivatives input[name="del_derivatives_type[]"]').forEach(function (el) {
        el.checked = true;
        el.dispatchEvent(new Event("change"));
    });
}

function selectDelDerivNone(): void {
    document.querySelectorAll<HTMLInputElement>('#action_delete_derivatives input[name="del_derivatives_type[]"]').forEach(function (el) {
        el.checked = false;
        el.dispatchEvent(new Event("change"));
    });
}

window.toggleAddFilterDropdown = toggleAddFilterDropdown;
window.selectGenerateDerivAll = selectGenerateDerivAll;
window.selectGenerateDerivNone = selectGenerateDerivNone;
window.selectDelDerivAll = selectDelDerivAll;
window.selectDelDerivNone = selectDelDerivNone;

export function init(cfgRaw: Record<string, unknown>): void {
    const cfg = cfgRaw as unknown as BatchManagerConfig;
    const batchManagerConfig = {
        lang: {
            Cancel: cfg.langCancel,
            deleteProgressMessage: cfg.deleteProgressMessage,
            syncProgressMessage: cfg.syncProgressMessage,
            AreYouSure: cfg.areYouSure,
            generateMsg: cfg.generateMsg,
            tagCreate: cfg.tagCreate
        },
        nbThumbsPage: cfg.nbThumbsPage,
        nbThumbsSet: cfg.nbThumbsSet,
        applyOnDetailsPattern: cfg.applyOnDetailsPattern,
        allElements: cfg.allElements,
        selectedMessagePattern: cfg.selectedMessagePattern,
        selectedMessageNone: cfg.selectedMessageNone,
        selectedMessageAll: cfg.selectedMessageAll,
        associatedCategories: cfg.associatedCategories,
        tagsServerKey: cfg.tagsServerKey,
        categoriesServerKey: cfg.categoriesServerKey,
        cacheServerId: cfg.cacheServerId,
        rootUrl: cfg.rootUrl,
        sliders: cfg.sliders
    };

    document.querySelectorAll<HTMLElement>(".removeFilter").forEach(function (el) {
        el.classList.add("icon-cancel-circled");
    });

    document.querySelectorAll<HTMLElement>(".removeFilter").forEach(function (el) {
        el.addEventListener("click", function () {
            const filter = (this).closest("li")?.id;
            if (filter) filter_disable(filter);
            return false;
        });
    });

    document.querySelectorAll<HTMLElement>("#addFilter a").forEach(function (el) {
        el.addEventListener("click", function () {
            const filter = (this).getAttribute("data-value");
            if (filter) filter_enable(filter);
        });
    });

    const removeFiltersBtn = document.getElementById("removeFilters");
    if (removeFiltersBtn) {
        removeFiltersBtn.addEventListener("click", function () {
            document.querySelectorAll<HTMLElement>("#filterList li").forEach(function (el) {
                filter_disable(el.id);
            });
            return false;
        });
    }

    ["widths", "heights", "ratios", "filesizes"].forEach(function (key) {
        const el = document.querySelector<HTMLElement>("[data-slider=" + key + "]");
        if (el) pwgDoubleSlider(el, batchManagerConfig.sliders[key] as DoubleSliderOptions);
    });

    document.addEventListener("mouseup", function (e) {
        e.stopPropagation();
        if (!(e.target as HTMLElement).classList.contains("addFilter-button")) {
            document.querySelectorAll<HTMLElement>(".addFilter-dropdown").forEach(function (el) {
                el.style.display = 'none';
            });
        }
    });

    const config = batchManagerConfig;
    const lang = config.lang;

    /* ---- Tags ---- */
    const tagsCache = new TagsCache({
        serverKey: config.tagsServerKey,
        serverId: config.cacheServerId,
        rootUrl: config.rootUrl
    });

    tagsCache.selectize(document.querySelectorAll('[data-selectize=tags]'), {
        lang: { 'Add': lang.tagCreate }
    });

    /* ---- Categories ---- */
    categoriesCache = new CategoriesCache({
        serverKey: config.categoriesServerKey,
        serverId: config.cacheServerId,
        rootUrl: config.rootUrl
    });

    categoriesCache.selectize(document.querySelectorAll('[data-selectize=categories]'), {
        filter: function (categories, options) {
            if ((this as HTMLSelectElement).name === 'dissociate') {
                const filtered = categories.filter(function (cat) {
                    return !!config.associatedCategories[cat['id'] as string];
                });
                if (filtered.length > 0) options.default = filtered[0]?.['id'] as string | number | undefined;
                return filtered;
            }
            return categories;
        }
    });

    /* ---- Selection counters ---- */
    function checkPermitAction(): void {
        const setSelectedEl = document.querySelector<HTMLInputElement>("input[name=setSelected]");
        let nbSelected = 0;
        if (setSelectedEl?.checked) {
            nbSelected = config.nbThumbsSet;
        } else {
            nbSelected = document.querySelectorAll(".thumbnails input[type=checkbox]:checked").length;
        }

        const permitAction = document.getElementById("permitAction");
        const forbidAction = document.getElementById("forbidAction");
        if (nbSelected === 0) {
            if (permitAction) permitAction.style.display = 'none';
            if (forbidAction) forbidAction.style.display = '';
        } else {
            if (permitAction) permitAction.style.display = '';
            if (forbidAction) forbidAction.style.display = 'none';
        }

        const applyOnDetails = document.getElementById("applyOnDetails");
        if (applyOnDetails) applyOnDetails.textContent = sprintf(config.applyOnDetailsPattern, nbSelected);

        const selectedMessage = document.getElementById("selectedMessage");
        if (selectedMessage) {
            if (nbSelected === 0) {
                selectedMessage.textContent = sprintf(config.selectedMessageNone, config.nbThumbsSet);
            } else if (nbSelected === config.nbThumbsSet) {
                selectedMessage.textContent = sprintf(config.selectedMessageAll, config.nbThumbsSet);
            } else {
                selectedMessage.textContent = sprintf(config.selectedMessagePattern, nbSelected, config.nbThumbsSet);
            }
        }
    }

    /* ---- Action panel ---- */
    document.querySelectorAll<HTMLElement>("[id^=action_]").forEach(function (el) {
        el.style.display = 'none';
    });

    const selectActionEl = document.querySelector<HTMLSelectElement>("select[name=selectAction]");
    if (selectActionEl) {
        selectActionEl.addEventListener('change', function () {
            document.querySelectorAll<HTMLElement>("[id^=action_]").forEach(function (el) {
                el.style.display = 'none';
            });

            let action = this.value;
            if (action === 'move') action = 'associate';

            const actionEl = document.getElementById("action_" + action);
            if (actionEl) actionEl.style.display = '';

            const applyActionBlock = document.getElementById("applyActionBlock");
            if (this.value !== '-1') {
                if (applyActionBlock) applyActionBlock.style.display = '';
            } else {
                if (applyActionBlock) applyActionBlock.style.display = 'none';
            }

            const confirmDel = document.getElementById("confirmDel");
            if (this.value === "delete" || this.value === "delete_derivatives") {
                if (confirmDel) confirmDel.style.visibility = "visible";
            } else {
                if (confirmDel) confirmDel.style.visibility = "hidden";
            }
        });
    }

    /* ---- Thumbnail selection ---- */
    document.querySelectorAll<HTMLElement>(".wrap1 label").forEach(function (label) {
        label.addEventListener('click', function () {
            const setSelectedEl = document.querySelector<HTMLInputElement>("input[name=setSelected]");
            if (setSelectedEl) {
                setSelectedEl.checked = false;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }

            const li = (this).closest("li");
            const checkbox = (this).querySelector<HTMLInputElement>("input[type=checkbox]");
            setTimeout(function () {
                if (checkbox) {
                    if (checkbox.checked) {
                        if (li) li.classList.add("thumbSelected");
                    } else {
                        if (li) li.classList.remove('thumbSelected');
                    }
                }
                checkPermitAction();
            }, 0);
        });
    });

    function selectPageThumbnails(): void {
        document.querySelectorAll<HTMLElement>(".thumbnails label").forEach(function (label) {
            const checkbox = label.querySelector<HTMLInputElement>("input[type=checkbox]");
            if (checkbox) {
                checkbox.checked = true;
                checkbox.dispatchEvent(new Event("change", { bubbles: true }));
            }
            const li = label.closest("li");
            if (li) li.classList.add("thumbSelected");
        });
    }

    const selectAllEl = document.getElementById("selectAll");
    if (selectAllEl) {
        selectAllEl.addEventListener('click', function (event) {
            event.preventDefault();
            const setSelectedEl = document.querySelector<HTMLInputElement>("input[name=setSelected]");
            if (setSelectedEl) {
                setSelectedEl.checked = false;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            selectPageThumbnails();
            checkPermitAction();
        });
    }

    const selectNoneEl = document.getElementById("selectNone");
    if (selectNoneEl) {
        selectNoneEl.addEventListener('click', function (event) {
            event.preventDefault();
            const setSelectedEl = document.querySelector<HTMLInputElement>("input[name=setSelected]");
            if (setSelectedEl) {
                setSelectedEl.checked = false;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            document.querySelectorAll<HTMLElement>(".thumbnails label").forEach(function (label) {
                const checkbox = label.querySelector<HTMLInputElement>("input[type=checkbox]");
                if (checkbox?.checked) {
                    checkbox.checked = false;
                    checkbox.dispatchEvent(new Event("change", { bubbles: true }));
                }
                const li = label.closest("li");
                if (li) li.classList.remove("thumbSelected");
            });
            checkPermitAction();
        });
    }

    const selectInvertEl = document.getElementById("selectInvert");
    if (selectInvertEl) {
        selectInvertEl.addEventListener('click', function (event) {
            event.preventDefault();
            const setSelectedEl = document.querySelector<HTMLInputElement>("input[name=setSelected]");
            if (setSelectedEl) {
                setSelectedEl.checked = false;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            document.querySelectorAll<HTMLElement>(".thumbnails label").forEach(function (label) {
                const checkbox = label.querySelector<HTMLInputElement>("input[type=checkbox]");
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    checkbox.dispatchEvent(new Event("change", { bubbles: true }));
                    const li = label.closest("li");
                    if (li) li.classList.toggle("thumbSelected", checkbox.checked);
                }
            });
            checkPermitAction();
        });
    }

    const selectSetEl = document.getElementById("selectSet");
    if (selectSetEl) {
        selectSetEl.addEventListener('click', function (event) {
            event.preventDefault();
            selectPageThumbnails();
            const setSelectedEl = document.querySelector<HTMLInputElement>("input[name=setSelected]");
            if (setSelectedEl) {
                setSelectedEl.checked = true;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            checkPermitAction();
        });
    }

    const setSelectedEl = document.querySelector<HTMLInputElement>("input[name=setSelected]");
    if (setSelectedEl) {
        setSelectedEl.addEventListener('change', function () {
            const wholeSet = document.querySelector<HTMLInputElement>('input[name=whole_set]');
            if (wholeSet) wholeSet.value = this.checked ? config.allElements.join(',') : '';
        });
        if (setSelectedEl.checked) {
            setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    /* ---- Filter prefilter ---- */
    const filterPrefilterEl = document.querySelector<HTMLSelectElement>("select[name=filter_prefilter]");
    if (filterPrefilterEl) {
        filterPrefilterEl.addEventListener('change', function () {
            const emptyCaddie = document.getElementById("empty_caddie");
            const duplicatesOptions = document.getElementById("duplicates_options");
            const deleteOrphans = document.getElementById("delete_orphans");
            const syncMd5sum = document.getElementById("sync_md5sum");
            if (emptyCaddie) emptyCaddie.style.display = this.value === "caddie" ? '' : 'none';
            if (duplicatesOptions) duplicatesOptions.style.display = this.value === "duplicates" ? '' : 'none';
            if (deleteOrphans) deleteOrphans.style.display = this.value === "no_album" ? '' : 'none';
            if (syncMd5sum) syncMd5sum.style.display = this.value === "no_sync_md5sum" ? '' : 'none';
        });
    }

    checkPermitAction();

    /* ---- Shift-click range selection ---- */
    (function () {
        let last_clicked = 0;
        let last_clickedstatus = true;

        const thumbnails = document.querySelector("ul.thumbnails");
        if (!thumbnails) return;
        const checkboxes = Array.from(thumbnails.querySelectorAll<HTMLInputElement>("input[type=checkbox]"));
        checkboxes.forEach(function (cb, pos) {
            cb.addEventListener("click", function (event) {
                if ((event as MouseEvent).shiftKey) {
                    const first = last_clicked < pos ? last_clicked : pos;
                    const last = last_clicked < pos ? pos : last_clicked;
                    for (let i = first; i <= last; i++) {
                        const cbI = checkboxes[i];
                        if (cbI) {
                            cbI.checked = last_clickedstatus;
                            cbI.dispatchEvent(new Event("change"));
                            const li = cbI.closest("li");
                            if (li) li.classList.toggle("thumbSelected", last_clickedstatus);
                        }
                    }
                } else {
                    last_clicked = pos;
                    last_clickedstatus = this.checked;
                }
            });
        });
    })();

    GLightbox({ selector: 'a.preview-box' });

    tippy('.thumbnails img', { delay: 0, placement: 'top' });

    /* ---- Actions ---- */

    pwgDatepicker(document.querySelectorAll("[data-datepicker]"), {
        showTimepicker: true,
        cancelButton: lang.Cancel,
    });

    document.querySelectorAll<HTMLElement>("[data-add-album]").forEach(function (btn) { pwgAddAlbum(btn); });

    document.querySelectorAll<HTMLInputElement>("input[name=remove_author]").forEach(function (el) {
        el.addEventListener("click", function () {
            const display = this.checked ? 'none' : '';
            document.querySelectorAll<HTMLInputElement>("input[name=author]").forEach(function (input) {
                input.style.display = display;
            });
        });
    });

    document.querySelectorAll<HTMLInputElement>("input[name=remove_title]").forEach(function (el) {
        el.addEventListener("click", function () {
            const display = this.checked ? 'none' : '';
            document.querySelectorAll<HTMLInputElement>("input[name=title]").forEach(function (input) {
                input.style.display = display;
            });
        });
    });

    document.querySelectorAll<HTMLInputElement>("input[name=remove_date_creation]").forEach(function (el) {
        el.addEventListener("click", function () {
            const setDate = document.getElementById("set_date_creation");
            if (setDate) setDate.style.display = this.checked ? 'none' : '';
        });
    });

    /* ---- Derivative generation ---- */
    const derivatives: DerivativesState = {
        elements: null,
        done: 0,
        total: 0,
        finished: function () {
            return (
                derivatives.done === derivatives.total &&
                derivatives.elements !== null &&
                derivatives.elements.length === 0
            );
        },
    };

    function progress_start(): void {
        const el = document.getElementById("uploadingActions");
        if (el) {
            el.style.display = '';
            const progBar = el.querySelector<HTMLElement>(".progress-bar");
            if (progBar) progBar.style.width = "0%";
        }
    }

    function progress_end(): void {
        const el = document.getElementById("uploadingActions");
        if (el) el.style.display = 'none';
    }

    function progress(success?: boolean): void {
        const percent = Math.floor((derivatives.done / derivatives.total) * 100);
        const progBar = document.querySelector<HTMLElement>("#uploadingActions .progressbar");
        if (progBar) progBar.style.width = percent.toString() + "%";

        if (success !== undefined) {
            const type = success ? "regenerateSuccess" : "regenerateError";
            const input = document.querySelector<HTMLInputElement>('[name="' + type + '"]');
            if (input) input.value = (parseInt(input.value) + 1).toString();
        }

        if (derivatives.finished()) {
            progress_end();
            const applyActionBtn = document.getElementById("applyAction");
            if (applyActionBtn) applyActionBtn.click();
        }
    }

    function getDerivativeUrls(): void {
        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
        const ids = derivatives.elements!.splice(0, 500);
        const params = { max_urls: 100000, ids: ids, types: [] as string[] };

        document.querySelectorAll<HTMLInputElement>("#action_generate_derivatives input").forEach(function (t) {
            if (t.checked) params.types.push(t.value);
        });

        const applyActionBlock = document.getElementById("applyActionBlock");
        if (applyActionBlock) applyActionBlock.style.display = 'none';
        document.querySelectorAll<HTMLElement>(".permitActionListButton").forEach(function (el) {
            el.style.display = 'none';
        });
        const confirmDel = document.getElementById("confirmDel");
        if (confirmDel) confirmDel.style.display = 'none';
        const regenerationMsg = document.getElementById("regenerationMsg");
        if (regenerationMsg) regenerationMsg.style.display = '';
        const regenerationText = document.getElementById("regenerationText");
        if (regenerationText) regenerationText.innerHTML = lang.generateMsg;

        progress_start();

        const body = new URLSearchParams();
        body.append("max_urls", String(params.max_urls));
        params.ids.forEach(function (id) { body.append("ids[]", id); });
        params.types.forEach(function (t) { body.append("types[]", t); });
        void fetch("ws.php?format=json&method=pwg.getMissingDerivatives", { method: "POST", body: body })
            .then(function (r) { return r.json() as Promise<{ stat: string; result: { urls: string[] } }>; })
            .then(function (data) {
                if (!data.stat || data.stat !== "ok") return;
                derivatives.total += data.result.urls.length;
                const badge = document.querySelector("#regenerationStatus .badge-number");
                if (badge) badge.innerHTML = derivatives.done + "/" + derivatives.total;
                progress();
                const urls = data.result.urls;
                let idx = 0;
                function fetchNextDerivative(): void {
                    if (idx >= urls.length) return;
                    const url = urls[idx++];
                    if (!url) return;
                    fetch(url + "&ajaxload=true")
                        .then(function (r) { return r.json() as Promise<unknown>; })
                        .then(function () {
                            derivatives.done++;
                            const badge = document.querySelector("#regenerationStatus .badge-number");
                            if (badge) badge.innerHTML = derivatives.done + "/" + derivatives.total;
                            progress(true);
                            fetchNextDerivative();
                        })
                        .catch(function () {
                            derivatives.done++;
                            const badge = document.querySelector("#regenerationStatus .badge-number");
                            if (badge) badge.innerHTML = derivatives.done + "/" + derivatives.total;
                            progress(false);
                            fetchNextDerivative();
                        });
                }
                fetchNextDerivative();
                // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
                if (derivatives.elements!.length)
                    setTimeout(getDerivativeUrls, 25 * (derivatives.total - derivatives.done));
            });
    }

    /* ---- Apply action (all action types in one handler) ---- */
    const applyActionBtn = document.getElementById("applyAction");
    if (applyActionBtn) {
        applyActionBtn.addEventListener("click", function (e) {
            const action = document.querySelector<HTMLSelectElement>('[name="selectAction"]')?.value;
            if (!action) return;

            /* delete_derivatives: require confirmation */
            if (action === 'delete_derivatives') {
                const confirmInput = document.querySelector<HTMLInputElement>("#confirmDel input[name=confirm_deletion]");
                if (!confirmInput?.checked) {
                    const errorSpan = document.querySelector<HTMLElement>("#confirmDel span.errors");
                    if (errorSpan) errorSpan.style.visibility = "visible";
                    e.preventDefault();
                }
                return;
            }

            /* generate_derivatives: kick off async generation */
            if (action === 'generate_derivatives') {
                if (derivatives.finished()) return;
                e.preventDefault();
                document.querySelectorAll<HTMLElement>('.bulkAction').forEach(function (el) {
                    el.style.display = 'none';
                });
                derivatives.elements = [];
                if (document.querySelector<HTMLInputElement>('input[name="setSelected"]')?.checked) {
                    derivatives.elements = config.allElements.slice();
                } else {
                    document.querySelectorAll<HTMLInputElement>('.thumbnails input[type=checkbox]').forEach(function (cb) {
                        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
                        if (cb.checked) derivatives.elements!.push(cb.value);
                    });
                }
                const applyActionBlock2 = document.getElementById('applyActionBlock');
                if (applyActionBlock2) applyActionBlock2.style.display = 'none';
                const selectActionEl2 = document.querySelector<HTMLElement>('select[name="selectAction"]');
                if (selectActionEl2) selectActionEl2.style.display = 'none';
                const permitBtn = document.querySelector<HTMLElement>('.permitActionListButton div');
                if (permitBtn) permitBtn.classList.add('hidden');
                const regenMsg = document.getElementById('regenerationMsg');
                if (regenMsg) regenMsg.style.display = '';
                progress_start();
                progress();
                getDerivativeUrls();
                return;
            }

            /* metadata: sync by blocks with progress */
            if (action === 'metadata') {
                e.preventDefault();
                e.stopPropagation();
                document.querySelectorAll<HTMLElement>(".bulkAction").forEach(function (el) {
                    el.style.display = 'none';
                });
                const regenerationText2 = document.getElementById("regenerationText");
                if (regenerationText2) regenerationText2.innerHTML = lang.syncProgressMessage;
                let elements: string[] = [];

                if (document.querySelector<HTMLInputElement>("input[name=setSelected]")?.checked) {
                    elements = config.allElements.slice();
                } else {
                    document.querySelectorAll<HTMLInputElement>('input[name="selection[]"]:checked').forEach(function (el) {
                        elements.push(el.value);
                    });
                }

                const progressBar_max = elements.length;
                let todo = 0;
                const syncBlockSize = Math.min(Math.floor(elements.length / 2), 500);
                let image_ids: string[] = [];

                const applyBlock = document.getElementById("applyActionBlock");
                if (applyBlock) applyBlock.style.display = 'none';
                document.querySelectorAll<HTMLElement>(".permitActionListButton").forEach(function (el) {
                    el.style.display = 'none';
                });
                const confirmDel2 = document.getElementById("confirmDel");
                if (confirmDel2) confirmDel2.style.display = 'none';
                const regenMsg2 = document.getElementById("regenerationMsg");
                if (regenMsg2) regenMsg2.style.display = '';
                progress_bar_start();

                for (let i = 0; i < elements.length; i++) {
                    image_ids.push(elements[i] ?? '');
                    if (i % syncBlockSize !== syncBlockSize - 1 && i !== elements.length - 1) continue;

                    (function (ids: string[]) {
                        const thisBatchSize = ids.length;
                        const pwgTokenInput = document.querySelector<HTMLInputElement>("input[name=pwg_token]");
                        const body = new URLSearchParams({ pwg_token: pwgTokenInput?.value ?? '' });
                        ids.forEach(function (id) { body.append("image_id[]", id); });
                        fetch("ws.php?format=json&method=pwg.images.syncMetadata", { method: "POST", body: body })
                            .then(function (r) { return r.json() as Promise<unknown>; })
                            .then(function () {
                                todo += thisBatchSize;
                                const badge = document.querySelector("#regenerationStatus .badge-number");
                                if (badge) badge.innerHTML = todo + "/" + progressBar_max;
                                progress_bar(todo, progressBar_max, false);
                            })
                            .catch(function () {
                                todo += thisBatchSize;
                                const badge = document.querySelector("#regenerationStatus .badge-number");
                                if (badge) badge.innerHTML = todo + "/" + progressBar_max;
                                progress_bar(todo, progressBar_max, false);
                            });
                    })(image_ids);
                    image_ids = [];
                }
                return;
            }

            /* delete: require confirmation, then delete by blocks */
            if (action === 'delete') {
                const confirmInput2 = document.querySelector<HTMLInputElement>("#confirmDel input[name=confirm_deletion]");
                if (!confirmInput2?.checked) {
                    const errorSpan2 = document.querySelector<HTMLElement>("#confirmDel span.errors");
                    if (errorSpan2) errorSpan2.style.visibility = "visible";
                    e.preventDefault();
                    return;
                }
                e.preventDefault();
                e.stopPropagation();

                document.querySelectorAll<HTMLElement>(".bulkAction").forEach(function (el) {
                    el.style.display = 'none';
                });

                let elements2: string[] = [];
                if (document.querySelector<HTMLInputElement>("input[name=setSelected]")?.checked) {
                    elements2 = config.allElements.slice();
                } else {
                    document.querySelectorAll<HTMLInputElement>('input[name="selection[]"]:checked').forEach(function (el) {
                        elements2.push(el.value);
                    });
                }

                const progressBar_max2 = elements2.length;
                let todo2 = 0;
                const deleteBlockSize = Math.min(Math.floor(elements2.length / 2), 1000);
                let image_ids2: string[] = [];

                const applyBlock2 = document.getElementById("applyActionBlock");
                if (applyBlock2) applyBlock2.style.display = 'none';
                document.querySelectorAll<HTMLElement>(".permitActionListButton").forEach(function (el) {
                    el.style.display = 'none';
                });
                const confirmDelEl = document.getElementById("confirmDel");
                if (confirmDelEl) confirmDelEl.style.display = 'none';
                const regenText3 = document.getElementById("regenerationText");
                if (regenText3) regenText3.innerHTML = lang.deleteProgressMessage;
                const regenMsg3 = document.getElementById("regenerationMsg");
                if (regenMsg3) regenMsg3.style.display = '';
                progress_bar_start();

                let _deleteQueue = Promise.resolve();
                for (let i = 0; i < elements2.length; i++) {
                    image_ids2.push(elements2[i] ?? '');
                    if (i % deleteBlockSize !== deleteBlockSize - 1 && i !== elements2.length - 1) continue;

                    (function (ids: string[]) {
                        const thisBatchSize = ids.length;
                        _deleteQueue = _deleteQueue.then(function () {
                            const pwgTokenInput = document.querySelector<HTMLInputElement>("input[name=pwg_token]");
                            return fetch("ws.php?format=json", {
                                method: "POST",
                                body: new URLSearchParams({
                                    method: "pwg.images.delete",
                                    pwg_token: pwgTokenInput?.value ?? '',
                                    image_id: ids.join(","),
                                }),
                            })
                                .then(function (r) { return r.json() as Promise<unknown>; })
                                .then(function () {
                                    todo2 += thisBatchSize;
                                    const badge = document.querySelector("#regenerationStatus .badge-number");
                                    if (badge) badge.innerHTML = todo2 + "/" + progressBar_max2;
                                    progress_bar(todo2, progressBar_max2, false);
                                })
                                .catch(function () {
                                    todo2 += thisBatchSize;
                                    const badge = document.querySelector("#regenerationStatus .badge-number");
                                    if (badge) badge.innerHTML = todo2 + "/" + progressBar_max2;
                                    progress_bar(todo2, progressBar_max2, false);
                                });
                        });
                    })(image_ids2);

                    image_ids2 = [];
                }

                document.querySelector("form")?.insertAdjacentHTML(
                    "beforeend",
                    '<input type="hidden" name="nb_photos_deleted" value="' + elements2.length + '">',
                );
                return;
            }
        });
    }

    function progress_bar_start(): void {
        const el = document.getElementById("uploadingActions");
        if (el) {
            el.style.display = '';
            const progBar = el.querySelector<HTMLElement>(".progress-bar");
            if (progBar) progBar.style.width = "0%";
        }
    }

    function progress_bar(val: number, max: number, _done: boolean): void {
        const percent = Math.floor((val / max) * 100);
        const progBar = document.querySelector<HTMLElement>("#uploadingActions .progressbar");
        if (progBar) progBar.style.width = percent.toString() + "%";
        if (val === max) {
            const applyActionBtnInner = document.getElementById("applyAction");
            if (applyActionBtnInner) applyActionBtnInner.click();
        }
    }

    document.querySelectorAll<HTMLInputElement>("#confirmDel input[name=confirm_deletion]").forEach(function (el) {
        el.addEventListener("change", function () {
            const errorSpan = document.querySelector<HTMLElement>("#confirmDel span.errors");
            if (errorSpan) errorSpan.style.visibility = "hidden";
        });
    });

    document.querySelectorAll<HTMLElement>("#sync_md5sum").forEach(function (el) {
        el.addEventListener("click", function (e) {
            e.preventDefault();
            (this).style.display = 'none';
            const addMd5sum = document.getElementById("add_md5sum");
            if (addMd5sum) addMd5sum.style.display = '';

            const md5sumToAdd = document.getElementById("md5sum_to_add") as (HTMLElement & { dataset: DOMStringMap }) | null;
            const addBlockSize = Math.min(Math.floor(Number(md5sumToAdd?.dataset['origin']) / 2), 1000);
            add_md5sum_block(addBlockSize);
        });
    });

    function add_md5sum_block(blockSize?: number): void {
        const pwgTokenInput = document.querySelector<HTMLInputElement>("input[name=pwg_token]");
        const body = new URLSearchParams({ pwg_token: pwgTokenInput?.value ?? '' });
        if (blockSize !== undefined) body.append("block_size", String(blockSize));
        fetch("ws.php?format=json&method=pwg.images.setMd5sum", { method: "POST", body: body })
            .then(function (r) { return r.json() as Promise<{ result: { nb_no_md5sum: number } }>; })
            .then(function (data) {
                const md5sumToAdd = document.getElementById("md5sum_to_add") as (HTMLElement & { dataset: DOMStringMap }) | null;
                if (md5sumToAdd) md5sumToAdd.innerHTML = String(data.result.nb_no_md5sum);

                const origin = Number(md5sumToAdd?.dataset['origin']);
                const percent_remaining = Math.round((data.result.nb_no_md5sum * 100) / origin);
                const percent_done = 100 - percent_remaining;
                const md5sumAdded = document.getElementById("md5sum_added");
                if (md5sumAdded) md5sumAdded.innerHTML = String(percent_done);

                if (data.result.nb_no_md5sum > 0) {
                    add_md5sum_block();
                } else {
                    document.location.href = "admin.php?page=batch_manager&action=sync_md5sum&nb_md5sum_added=" +
                        (md5sumToAdd?.dataset['origin'] ?? '');
                }
            })
            .catch(function (err: unknown) {
                const addMd5sum = document.getElementById("add_md5sum");
                if (addMd5sum) addMd5sum.style.display = 'none';
                const addMd5sumError = document.getElementById("add_md5sum_error");
                if (addMd5sumError) {
                    addMd5sumError.style.display = '';
                    addMd5sumError.innerHTML = "error: " + String(err instanceof Error ? err.message : err);
                }
            });
    }

    document.querySelectorAll<HTMLElement>("#delete_orphans").forEach(function (el) {
        el.addEventListener("click", function (e) {
            e.preventDefault();
            (this).style.display = 'none';
            const orphansDeletion = document.getElementById("orphans_deletion");
            if (orphansDeletion) orphansDeletion.style.display = '';

            const orphansToDelete = document.getElementById("orphans_to_delete") as (HTMLElement & { dataset: DOMStringMap }) | null;
            const deleteBlockSize = Math.min(Math.floor(Number(orphansToDelete?.dataset['origin']) / 2), 1000);
            delete_orphans_block(deleteBlockSize);
        });
    });

    function delete_orphans_block(blockSize?: number): void {
        const pwgTokenInput = document.querySelector<HTMLInputElement>("input[name=pwg_token]");
        const body = new URLSearchParams({ pwg_token: pwgTokenInput?.value ?? '' });
        if (blockSize !== undefined) body.append("block_size", String(blockSize));
        fetch("ws.php?format=json&method=pwg.images.deleteOrphans", { method: "POST", body: body })
            .then(function (r) { return r.json() as Promise<{ result: { nb_orphans: number } }>; })
            .then(function (data) {
                const orphansToDelete = document.getElementById("orphans_to_delete") as (HTMLElement & { dataset: DOMStringMap }) | null;
                if (orphansToDelete) orphansToDelete.innerHTML = String(data.result.nb_orphans);

                const origin = Number(orphansToDelete?.dataset['origin']);
                const percent_remaining = Math.round((data.result.nb_orphans * 100) / origin);
                const percent_done = 100 - percent_remaining;
                const orphansDeleted = document.getElementById("orphans_deleted");
                if (orphansDeleted) orphansDeleted.innerHTML = String(percent_done);

                if (data.result.nb_orphans > 0) {
                    delete_orphans_block();
                } else {
                    document.location.href = "admin.php?page=batch_manager&action=delete_orphans&nb_orphans_deleted=" +
                        (orphansToDelete?.dataset['origin'] ?? '');
                }
            })
            .catch(function (err: unknown) {
                const orphansDeletion = document.getElementById("orphans_deletion");
                if (orphansDeletion) orphansDeletion.style.display = 'none';
                const orphansDeletionError = document.getElementById("orphans_deletion_error");
                if (orphansDeletionError) {
                    orphansDeletionError.style.display = '';
                    orphansDeletionError.innerHTML = "error: " + (err instanceof Error ? err.message : String(err));
                }
            });
    }
}

initModule(init as (cfg: Record<string, unknown>) => void);
