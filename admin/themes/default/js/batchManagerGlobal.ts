import { initModule } from './moduleInit.js';
import tippy from 'tippy.js';
import { sprintf } from './common.js';
import { pwgDoubleSlider } from './doubleSlider.js';
import { pwgDatepicker } from './datepicker.js';
import { pwgAddAlbum } from './addAlbum.js';
import { CategoriesCache, TagsCache } from './LocalStorageCache.js';
import GLightbox from 'glightbox';

type SliderOptions = Parameters<typeof pwgDoubleSlider>[1];

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
    associatedCategories: Record<string | number, unknown>;
    tagsServerKey: string;
    categoriesServerKey: string;
    cacheServerId: string | number;
    rootUrl: string;
    sliders: Record<string, SliderOptions>;
}

let categoriesCache: CategoriesCache | null = null;
void categoriesCache;

function filter_enable(filter: string): void {
    const el = document.getElementById(filter);
    if (el) el.style.display = '';

    const checkbox = document.querySelector<HTMLInputElement>('input[type=checkbox][name=' + filter + '_use]');
    if (checkbox) checkbox.checked = true;

    const addFilterLink = document.querySelector<HTMLElement>('#addFilter a[data-value=' + filter + ']');
    if (addFilterLink) addFilterLink.classList.add('disabled');

    document.querySelectorAll<HTMLElement>('.noFilter').forEach(function (el) {
        el.style.display = 'none';
    });
    document.querySelectorAll<HTMLElement>('.addFilter-button').forEach(function (el) {
        el.classList.remove('highlight');
    });
}

function filter_disable(filter: string): void {
    const el = document.getElementById(filter);
    if (el) el.style.display = 'none';

    const checkbox = document.querySelector<HTMLInputElement>('input[name=' + filter + '_use]');
    if (checkbox) checkbox.checked = false;

    const addFilterLink = document.querySelector<HTMLElement>('#addFilter a[data-value=' + filter + ']');
    if (addFilterLink) addFilterLink.classList.remove('disabled');

    const visibleFilters = document.querySelectorAll('#filterList li:not([style*="display: none"])').length;
    if (visibleFilters == 0) {
        document.querySelectorAll<HTMLElement>('.noFilter').forEach(function (el) {
            el.style.display = '';
        });
        document.querySelectorAll<HTMLElement>('.addFilter-button').forEach(function (el) {
            el.classList.add('highlight');
        });
    }
}

function toggleAddFilterDropdown(): void {
    const el = document.querySelector<HTMLElement>('.addFilter-dropdown');
    if (el) el.style.display = window.getComputedStyle(el).display === 'none' ? 'block' : 'none';
}

function selectGenerateDerivAll(): void {
    document.querySelectorAll<HTMLInputElement>('#action_generate_derivatives input[type=checkbox]').forEach(function (el) {
        el.checked = true;
        el.dispatchEvent(new Event('change'));
    });
}

function selectGenerateDerivNone(): void {
    document.querySelectorAll<HTMLInputElement>('#action_generate_derivatives input[type=checkbox]').forEach(function (el) {
        el.checked = false;
        el.dispatchEvent(new Event('change'));
    });
}

function selectDelDerivAll(): void {
    document.querySelectorAll<HTMLInputElement>('#action_delete_derivatives input[name="del_derivatives_type[]"]').forEach(function (el) {
        el.checked = true;
        el.dispatchEvent(new Event('change'));
    });
}

function selectDelDerivNone(): void {
    document.querySelectorAll<HTMLInputElement>('#action_delete_derivatives input[name="del_derivatives_type[]"]').forEach(function (el) {
        el.checked = false;
        el.dispatchEvent(new Event('change'));
    });
}

void toggleAddFilterDropdown; void selectGenerateDerivAll; void selectGenerateDerivNone;
void selectDelDerivAll; void selectDelDerivNone;

export function init(cfg: BatchManagerConfig): void {
    const lang = {
        Cancel: cfg.langCancel,
        deleteProgressMessage: cfg.deleteProgressMessage,
        syncProgressMessage: cfg.syncProgressMessage,
        AreYouSure: cfg.areYouSure,
        generateMsg: cfg.generateMsg,
        tagCreate: cfg.tagCreate,
    };

    document.querySelectorAll<HTMLElement>('.removeFilter').forEach(function (el) {
        el.classList.add('icon-cancel-circled');
    });

    document.querySelectorAll<HTMLElement>('.removeFilter').forEach(function (el) {
        el.addEventListener('click', function (this: HTMLElement) {
            const li = this.closest('li');
            const filter = li ? li.id : '';
            if (filter) filter_disable(filter);
            return false;
        });
    });

    document.querySelectorAll<HTMLElement>('#addFilter a').forEach(function (el) {
        el.addEventListener('click', function (this: HTMLElement) {
            const filter = this.getAttribute('data-value');
            if (filter) filter_enable(filter);
        });
    });

    const removeFiltersBtn = document.getElementById('removeFilters');
    if (removeFiltersBtn) {
        removeFiltersBtn.addEventListener('click', function () {
            document.querySelectorAll<HTMLElement>('#filterList li').forEach(function (el) {
                const filter = el.id;
                filter_disable(filter);
            });
            return false;
        });
    }

    (['widths', 'heights', 'ratios', 'filesizes'] as const).forEach(function (key) {
        const el = document.querySelector<HTMLElement>('[data-slider=' + key + ']');
        if (el) pwgDoubleSlider(el, cfg.sliders[key]);
    });

    document.addEventListener('mouseup', function (e) {
        e.stopPropagation();
        if (!(e.target as Element).classList.contains('addFilter-button')) {
            document.querySelectorAll<HTMLElement>('.addFilter-dropdown').forEach(function (el) {
                el.style.display = 'none';
            });
        }
    });

    /* ---- Tags ---- */
    const tagsCache = new TagsCache({
        serverKey: cfg.tagsServerKey,
        serverId: cfg.cacheServerId,
        rootUrl: cfg.rootUrl,
    });

    tagsCache.selectize(document.querySelectorAll('[data-selectize=tags]'), {
        lang: { 'Add': lang.tagCreate },
    });

    /* ---- Categories ---- */
    categoriesCache = new CategoriesCache({
        serverKey: cfg.categoriesServerKey,
        serverId: cfg.cacheServerId,
        rootUrl: cfg.rootUrl,
    });

    categoriesCache.selectize(document.querySelectorAll('[data-selectize=categories]'), {
        filter: function (this: Element, categories, options) {
            if ((this as HTMLSelectElement).name == 'dissociate') {
                const filtered = categories.filter(function (cat) {
                    return !!(cfg.associatedCategories)[(cat as { id: string | number }).id];
                });
                if (filtered.length > 0) options.default = (filtered[0] as { id: string | number }).id;
                return filtered;
            }
            return categories;
        },
    });

    /* ---- Selection counters ---- */
    function checkPermitAction(): void {
        const setSelectedEl = document.querySelector<HTMLInputElement>('input[name=setSelected]');
        let nbSelected = 0;
        if (setSelectedEl && setSelectedEl.checked) {
            nbSelected = cfg.nbThumbsSet;
        } else {
            nbSelected = document.querySelectorAll('.thumbnails input[type=checkbox]:checked').length;
        }

        const permitAction = document.getElementById('permitAction');
        const forbidAction = document.getElementById('forbidAction');
        if (nbSelected == 0) {
            if (permitAction) permitAction.style.display = 'none';
            if (forbidAction) forbidAction.style.display = '';
        } else {
            if (permitAction) permitAction.style.display = '';
            if (forbidAction) forbidAction.style.display = 'none';
        }

        const applyOnDetails = document.getElementById('applyOnDetails');
        if (applyOnDetails) applyOnDetails.textContent = sprintf(cfg.applyOnDetailsPattern, nbSelected);

        const selectedMessage = document.getElementById('selectedMessage');
        if (selectedMessage) {
            if (nbSelected == 0) {
                selectedMessage.textContent = sprintf(cfg.selectedMessageNone, cfg.nbThumbsSet);
            } else if (nbSelected == cfg.nbThumbsSet) {
                selectedMessage.textContent = sprintf(cfg.selectedMessageAll, cfg.nbThumbsSet);
            } else {
                selectedMessage.textContent = sprintf(cfg.selectedMessagePattern, nbSelected, cfg.nbThumbsSet);
            }
        }
    }

    /* ---- Action panel ---- */
    document.querySelectorAll<HTMLElement>('[id^=action_]').forEach(function (el) {
        el.style.display = 'none';
    });

    const selectActionEl = document.querySelector<HTMLSelectElement>('select[name=selectAction]');
    if (selectActionEl) {
        selectActionEl.addEventListener('change', function (this: HTMLSelectElement) {
            document.querySelectorAll<HTMLElement>('[id^=action_]').forEach(function (el) {
                el.style.display = 'none';
            });

            let action = this.value;
            if (action == 'move') action = 'associate';

            const actionEl = document.getElementById('action_' + action);
            if (actionEl) actionEl.style.display = '';

            const applyActionBlock = document.getElementById('applyActionBlock');
            if (this.value != '-1') {
                if (applyActionBlock) applyActionBlock.style.display = '';
            } else {
                if (applyActionBlock) applyActionBlock.style.display = 'none';
            }

            const confirmDel = document.getElementById('confirmDel');
            if (this.value == 'delete' || this.value == 'delete_derivatives') {
                if (confirmDel) confirmDel.style.visibility = 'visible';
            } else {
                if (confirmDel) confirmDel.style.visibility = 'hidden';
            }
        });
    }

    /* ---- Thumbnail selection ---- */
    document.querySelectorAll<HTMLElement>('.wrap1 label').forEach(function (label) {
        label.addEventListener('click', function (this: HTMLElement) {
            const setSelectedEl = document.querySelector<HTMLInputElement>('input[name=setSelected]');
            if (setSelectedEl) {
                setSelectedEl.checked = false;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }

            const li = this.closest('li');
            const checkbox = this.querySelector<HTMLInputElement>('input[type=checkbox]');
            if (checkbox) {
                if (checkbox.checked) {
                    if (li) li.classList.add('thumbSelected');
                } else {
                    if (li) li.classList.remove('thumbSelected');
                }
            }

            checkPermitAction();
        });
    });

    function selectPageThumbnails(): void {
        document.querySelectorAll<HTMLElement>('.thumbnails label').forEach(function (label) {
            const checkbox = label.querySelector<HTMLInputElement>('input[type=checkbox]');
            if (checkbox) {
                checkbox.checked = true;
                checkbox.dispatchEvent(new Event('change', { bubbles: true }));
            }
            const li = label.closest('li');
            if (li) li.classList.add('thumbSelected');
        });
    }

    const selectAllEl = document.getElementById('selectAll');
    if (selectAllEl) {
        selectAllEl.addEventListener('click', function (event) {
            event.preventDefault();
            const setSelectedEl = document.querySelector<HTMLInputElement>('input[name=setSelected]');
            if (setSelectedEl) {
                setSelectedEl.checked = false;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            selectPageThumbnails();
            checkPermitAction();
        });
    }

    const selectNoneEl = document.getElementById('selectNone');
    if (selectNoneEl) {
        selectNoneEl.addEventListener('click', function (event) {
            event.preventDefault();
            const setSelectedEl = document.querySelector<HTMLInputElement>('input[name=setSelected]');
            if (setSelectedEl) {
                setSelectedEl.checked = false;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            document.querySelectorAll<HTMLElement>('.thumbnails label').forEach(function (label) {
                const checkbox = label.querySelector<HTMLInputElement>('input[type=checkbox]');
                if (checkbox && checkbox.checked) {
                    checkbox.checked = false;
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                }
                const li = label.closest('li');
                if (li) li.classList.remove('thumbSelected');
            });
            checkPermitAction();
        });
    }

    const selectInvertEl = document.getElementById('selectInvert');
    if (selectInvertEl) {
        selectInvertEl.addEventListener('click', function (event) {
            event.preventDefault();
            const setSelectedEl = document.querySelector<HTMLInputElement>('input[name=setSelected]');
            if (setSelectedEl) {
                setSelectedEl.checked = false;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            document.querySelectorAll<HTMLElement>('.thumbnails label').forEach(function (label) {
                const checkbox = label.querySelector<HTMLInputElement>('input[type=checkbox]');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                    const li = label.closest('li');
                    if (li) li.classList.toggle('thumbSelected', checkbox.checked);
                }
            });
            checkPermitAction();
        });
    }

    const selectSetEl = document.getElementById('selectSet');
    if (selectSetEl) {
        selectSetEl.addEventListener('click', function (event) {
            event.preventDefault();
            selectPageThumbnails();
            const setSelectedEl = document.querySelector<HTMLInputElement>('input[name=setSelected]');
            if (setSelectedEl) {
                setSelectedEl.checked = true;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            checkPermitAction();
        });
    }

    const setSelectedEl = document.querySelector<HTMLInputElement>('input[name=setSelected]');
    if (setSelectedEl) {
        setSelectedEl.addEventListener('change', function (this: HTMLInputElement) {
            const wholeSet = document.querySelector<HTMLInputElement>('input[name=whole_set]');
            if (wholeSet) wholeSet.value = this.checked ? cfg.allElements.join(',') : '';
        });
        if (setSelectedEl.checked) {
            setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    /* ---- Filter prefilter ---- */
    const filterPrefilterEl = document.querySelector<HTMLSelectElement>('select[name=filter_prefilter]');
    if (filterPrefilterEl) {
        filterPrefilterEl.addEventListener('change', function (this: HTMLSelectElement) {
            const emptyCaddie = document.getElementById('empty_caddie');
            const duplicatesOptions = document.getElementById('duplicates_options');
            const deleteOrphans = document.getElementById('delete_orphans');
            const syncMd5sum = document.getElementById('sync_md5sum');
            if (emptyCaddie) emptyCaddie.style.display = this.value == 'caddie' ? '' : 'none';
            if (duplicatesOptions) duplicatesOptions.style.display = this.value == 'duplicates' ? '' : 'none';
            if (deleteOrphans) deleteOrphans.style.display = this.value == 'no_album' ? '' : 'none';
            if (syncMd5sum) syncMd5sum.style.display = this.value == 'no_sync_md5sum' ? '' : 'none';
        });
    }

    checkPermitAction();

    /* ---- Shift-click range selection ---- */
    (function () {
        let last_clicked = 0;
        let last_clickedstatus = true;

        const thumbnails = document.querySelector('ul.thumbnails');
        if (!thumbnails) return;
        const checkboxes = Array.from(thumbnails.querySelectorAll<HTMLInputElement>('input[type=checkbox]'));
        checkboxes.forEach(function (cb, pos) {
            cb.addEventListener('click', function (this: HTMLInputElement, event) {
                if ((event as MouseEvent).shiftKey) {
                    const first = last_clicked < pos ? last_clicked : pos;
                    const last = last_clicked < pos ? pos : last_clicked;
                    for (let i = first; i <= last; i++) {
                        checkboxes[i].checked = last_clickedstatus;
                        checkboxes[i].dispatchEvent(new Event('change'));
                        const li = checkboxes[i].closest('li');
                        if (li) li.classList.toggle('thumbSelected', last_clickedstatus);
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
    pwgDatepicker(document.querySelectorAll('[data-datepicker]'), {
        showTimepicker: true,
        cancelButton: lang.Cancel,
    });

    document.querySelectorAll<HTMLElement>('[data-add-album]').forEach(function (btn) { pwgAddAlbum(btn); });

    document.querySelectorAll<HTMLInputElement>('input[name=remove_author]').forEach(function (el) {
        el.addEventListener('click', function (this: HTMLInputElement) {
            const display = this.checked ? 'none' : '';
            document.querySelectorAll<HTMLElement>('input[name=author]').forEach(function (input) {
                input.style.display = display;
            });
        });
    });

    document.querySelectorAll<HTMLInputElement>('input[name=remove_title]').forEach(function (el) {
        el.addEventListener('click', function (this: HTMLInputElement) {
            const display = this.checked ? 'none' : '';
            document.querySelectorAll<HTMLElement>('input[name=title]').forEach(function (input) {
                input.style.display = display;
            });
        });
    });

    document.querySelectorAll<HTMLInputElement>('input[name=remove_date_creation]').forEach(function (el) {
        el.addEventListener('click', function (this: HTMLInputElement) {
            const setDate = document.getElementById('set_date_creation');
            if (setDate) setDate.style.display = this.checked ? 'none' : '';
        });
    });

    /* ---- Derivative generation ---- */
    const derivatives = {
        elements: null as string[] | null,
        done: 0,
        total: 0,

        finished: function (): boolean {
            return (
                derivatives.done == derivatives.total &&
                derivatives.elements !== null &&
                derivatives.elements.length == 0
            );
        },
    };

    function progress_start(): void {
        const el = document.getElementById('uploadingActions');
        if (el) {
            el.style.display = '';
            const progBar = el.querySelector<HTMLElement>('.progress-bar');
            if (progBar) progBar.style.width = '0%';
        }
    }

    function progress_end(): void {
        const el = document.getElementById('uploadingActions');
        if (el) el.style.display = 'none';
    }

    void progress_end;

    function progress(success?: boolean): void {
        const percent = Math.floor((derivatives.done / derivatives.total) * 100);
        const progBar = document.querySelector<HTMLElement>('#uploadingActions .progressbar');
        if (progBar) progBar.style.width = String(percent) + '%';

        if (success !== undefined) {
            const type = success ? 'regenerateSuccess' : 'regenerateError';
            const input = document.querySelector<HTMLInputElement>('[name="' + type + '"]');
            if (input) input.value = String(parseInt(input.value) + 1);
        }

        if (derivatives.finished()) {
            progress_end();
            const applyActionBtn = document.getElementById('applyAction');
            if (applyActionBtn) applyActionBtn.click();
        }
    }

    function getDerivativeUrls(): void {
        const ids = derivatives.elements!.splice(0, 500);
        const params: { max_urls: number; ids: string[]; types: string[] } = { max_urls: 100000, ids: ids, types: [] };

        document.querySelectorAll<HTMLInputElement>('#action_generate_derivatives input').forEach(function (t) {
            if (t.checked) params.types.push(t.value);
        });

        document.getElementById('applyActionBlock')!.style.display = 'none';
        document.querySelectorAll<HTMLElement>('.permitActionListButton').forEach(function (el) {
            el.style.display = 'none';
        });
        document.getElementById('confirmDel')!.style.display = 'none';
        document.getElementById('regenerationMsg')!.style.display = '';
        const regenerationText = document.getElementById('regenerationText');
        if (regenerationText) regenerationText.innerHTML = lang.generateMsg;

        progress_start();

        const body = new URLSearchParams();
        body.append('max_urls', String(params.max_urls));
        params.ids.forEach(function (id) { body.append('ids[]', id); });
        params.types.forEach(function (t) { body.append('types[]', t); });
        fetch('ws.php?format=json&method=pwg.getMissingDerivatives', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data: { stat?: string; result: { urls: string[] } }) {
                if (!data.stat || data.stat != 'ok') return;
                derivatives.total += data.result.urls.length;
                const badge = document.querySelector<HTMLElement>('#regenerationStatus .badge-number');
                if (badge) badge.innerHTML = String(derivatives.done) + '/' + String(derivatives.total);
                progress();
                const urls = data.result.urls;
                let idx = 0;
                function fetchNextDerivative(): void {
                    if (idx >= urls.length) return;
                    const url = urls[idx++];
                    fetch(url + '&ajaxload=true')
                        .then(function (r) { return r.json(); })
                        .then(function () {
                            derivatives.done++;
                            const badge = document.querySelector<HTMLElement>('#regenerationStatus .badge-number');
                            if (badge) badge.innerHTML = String(derivatives.done) + '/' + String(derivatives.total);
                            progress(true);
                            fetchNextDerivative();
                        })
                        .catch(function () {
                            derivatives.done++;
                            const badge = document.querySelector<HTMLElement>('#regenerationStatus .badge-number');
                            if (badge) badge.innerHTML = String(derivatives.done) + '/' + String(derivatives.total);
                            progress(false);
                            fetchNextDerivative();
                        });
                }
                fetchNextDerivative();
                if (derivatives.elements && derivatives.elements.length)
                    setTimeout(getDerivativeUrls, 25 * (derivatives.total - derivatives.done));
            });
    }

    /* ---- Apply action (all action types in one handler) ---- */
    const applyActionBtn = document.getElementById('applyAction');
    if (applyActionBtn) {
        applyActionBtn.addEventListener('click', function (e) {
            const action = document.querySelector<HTMLSelectElement>('[name="selectAction"]')!.value;

            if (action == 'delete_derivatives') {
                if (!(document.querySelector<HTMLInputElement>('#confirmDel input[name=confirm_deletion]'))!.checked) {
                    document.querySelector<HTMLElement>('#confirmDel span.errors')!.style.visibility = 'visible';
                    e.preventDefault();
                }
                return;
            }

            if (action == 'generate_derivatives') {
                if (derivatives.finished()) return;
                e.preventDefault();
                document.querySelectorAll<HTMLElement>('.bulkAction').forEach(function (el) {
                    el.style.display = 'none';
                });
                derivatives.elements = [];
                if (document.querySelector<HTMLInputElement>('input[name="setSelected"]')!.checked) {
                    derivatives.elements = cfg.allElements.slice();
                } else {
                    document.querySelectorAll<HTMLInputElement>('.thumbnails input[type=checkbox]').forEach(function (cb) {
                        if (cb.checked) derivatives.elements!.push(cb.value);
                    });
                }
                document.getElementById('applyActionBlock')!.style.display = 'none';
                document.querySelector<HTMLElement>('select[name="selectAction"]')!.style.display = 'none';
                document.querySelector<HTMLElement>('.permitActionListButton div')!.classList.add('hidden');
                document.getElementById('regenerationMsg')!.style.display = '';
                progress_start();
                progress();
                getDerivativeUrls();
                return;
            }

            if (action == 'metadata') {
                e.preventDefault();
                e.stopPropagation();
                document.querySelectorAll<HTMLElement>('.bulkAction').forEach(function (el) {
                    el.style.display = 'none';
                });
                const regenerationText = document.getElementById('regenerationText');
                if (regenerationText) regenerationText.innerHTML = lang.syncProgressMessage;
                let elements: string[] = [];

                if (document.querySelector<HTMLInputElement>('input[name=setSelected]')!.checked) {
                    elements = cfg.allElements.slice();
                } else {
                    document.querySelectorAll<HTMLInputElement>('input[name="selection[]"]:checked').forEach(function (el) {
                        elements.push(el.value);
                    });
                }

                const progressBar_max = elements.length;
                let todo = 0;
                const syncBlockSize = Math.min(Number((elements.length / 2).toFixed()), 500);
                let image_ids: string[] = [];

                document.getElementById('applyActionBlock')!.style.display = 'none';
                document.querySelectorAll<HTMLElement>('.permitActionListButton').forEach(function (el) {
                    el.style.display = 'none';
                });
                document.getElementById('confirmDel')!.style.display = 'none';
                document.getElementById('regenerationMsg')!.style.display = '';
                progress_bar_start();

                for (let i = 0; i < elements.length; i++) {
                    image_ids.push(elements[i]);
                    if (i % syncBlockSize != syncBlockSize - 1 && i != elements.length - 1) continue;

                    (function (ids: string[]) {
                        const thisBatchSize = ids.length;
                        const body = new URLSearchParams({
                            pwg_token: document.querySelector<HTMLInputElement>('input[name=pwg_token]')!.value,
                        });
                        ids.forEach(function (id) { body.append('image_id[]', id); });
                        fetch('ws.php?format=json&method=pwg.images.syncMetadata', { method: 'POST', body: body })
                            .then(function (r) { return r.json(); })
                            .then(function () {
                                todo += thisBatchSize;
                                const badge = document.querySelector<HTMLElement>('#regenerationStatus .badge-number');
                                if (badge) badge.innerHTML = String(todo) + '/' + String(progressBar_max);
                                progress_bar(todo, progressBar_max);
                            })
                            .catch(function () {
                                todo += thisBatchSize;
                                const badge = document.querySelector<HTMLElement>('#regenerationStatus .badge-number');
                                if (badge) badge.innerHTML = String(todo) + '/' + String(progressBar_max);
                                progress_bar(todo, progressBar_max);
                            });
                    })(image_ids);
                    image_ids = [];
                }
                return;
            }

            if (action == 'delete') {
                if (!(document.querySelector<HTMLInputElement>('#confirmDel input[name=confirm_deletion]'))!.checked) {
                    const errorSpan = document.querySelector<HTMLElement>('#confirmDel span.errors');
                    if (errorSpan) errorSpan.style.visibility = 'visible';
                    e.preventDefault();
                    return;
                }
                e.preventDefault();
                e.stopPropagation();

                document.querySelectorAll<HTMLElement>('.bulkAction').forEach(function (el) {
                    el.style.display = 'none';
                });

                let elements: string[] = [];
                if (document.querySelector<HTMLInputElement>('input[name=setSelected]')!.checked) {
                    elements = cfg.allElements.slice();
                } else {
                    document.querySelectorAll<HTMLInputElement>('input[name="selection[]"]:checked').forEach(function (el) {
                        elements.push(el.value);
                    });
                }

                const progressBar_max = elements.length;
                let todo = 0;
                const deleteBlockSize = Math.min(Number((elements.length / 2).toFixed()), 1000);
                let image_ids: string[] = [];

                document.getElementById('applyActionBlock')!.style.display = 'none';
                document.querySelectorAll<HTMLElement>('.permitActionListButton').forEach(function (el) {
                    el.style.display = 'none';
                });
                document.getElementById('confirmDel')!.style.display = 'none';
                const regenerationText = document.getElementById('regenerationText');
                if (regenerationText) regenerationText.innerHTML = lang.deleteProgressMessage;
                document.getElementById('regenerationMsg')!.style.display = '';
                progress_bar_start();

                let _deleteQueue: Promise<void> = Promise.resolve();
                for (let i = 0; i < elements.length; i++) {
                    image_ids.push(elements[i]);
                    if (i % deleteBlockSize != deleteBlockSize - 1 && i != elements.length - 1) continue;

                    (function (ids: string[]) {
                        const thisBatchSize = ids.length;
                        _deleteQueue = _deleteQueue.then(function () {
                            return fetch('ws.php?format=json', {
                                method: 'POST',
                                body: new URLSearchParams({
                                    method: 'pwg.images.delete',
                                    pwg_token: document.querySelector<HTMLInputElement>('input[name=pwg_token]')!.value,
                                    image_id: ids.join(','),
                                }),
                            })
                                .then(function (r) { return r.json(); })
                                .then(function () {
                                    todo += thisBatchSize;
                                    const badge = document.querySelector<HTMLElement>('#regenerationStatus .badge-number');
                                    if (badge) badge.innerHTML = String(todo) + '/' + String(progressBar_max);
                                    progress_bar(todo, progressBar_max);
                                })
                                .catch(function () {
                                    todo += thisBatchSize;
                                    const badge = document.querySelector<HTMLElement>('#regenerationStatus .badge-number');
                                    if (badge) badge.innerHTML = String(todo) + '/' + String(progressBar_max);
                                    progress_bar(todo, progressBar_max);
                                });
                        });
                    })(image_ids);

                    image_ids = [];
                }

                document.querySelector<HTMLElement>('form')!.insertAdjacentHTML(
                    'beforeend',
                    '<input type="hidden" name="nb_photos_deleted" value="' + String(elements.length) + '">',
                );
                return;
            }
        });
    }

    function progress_bar_start(): void {
        const el = document.getElementById('uploadingActions');
        if (el) {
            el.style.display = '';
            const progBar = el.querySelector<HTMLElement>('.progress-bar');
            if (progBar) progBar.style.width = '0%';
        }
    }

    function progress_bar(val: number, max: number): void {
        const percent = Math.floor((val / max) * 100);
        const progBar = document.querySelector<HTMLElement>('#uploadingActions .progressbar');
        if (progBar) progBar.style.width = String(percent) + '%';
        if (val == max) {
            const applyActionBtn = document.getElementById('applyAction');
            if (applyActionBtn) applyActionBtn.click();
        }
    }

    document.querySelectorAll<HTMLInputElement>('#confirmDel input[name=confirm_deletion]').forEach(function (el) {
        el.addEventListener('change', function () {
            const errorSpan = document.querySelector<HTMLElement>('#confirmDel span.errors');
            if (errorSpan) errorSpan.style.visibility = 'hidden';
        });
    });

    document.querySelectorAll<HTMLElement>('#sync_md5sum').forEach(function (el) {
        el.addEventListener('click', function (this: HTMLElement, e) {
            e.preventDefault();
            this.style.display = 'none';
            const addMd5sum = document.getElementById('add_md5sum');
            if (addMd5sum) addMd5sum.style.display = '';

            const md5sumToAdd = document.getElementById('md5sum_to_add')!;
            const addBlockSize = Math.min(Number((Number(md5sumToAdd.dataset.origin) / 2).toFixed()), 1000);
            add_md5sum_block(addBlockSize);
        });
    });

    function add_md5sum_block(blockSize?: number): void {
        const body = new URLSearchParams({ pwg_token: document.querySelector<HTMLInputElement>('input[name=pwg_token]')!.value });
        if (blockSize !== undefined) body.append('block_size', String(blockSize));
        fetch('ws.php?format=json&method=pwg.images.setMd5sum', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data: { result: { nb_no_md5sum: number } }) {
                const md5sumToAdd = document.getElementById('md5sum_to_add')!;
                md5sumToAdd.innerHTML = String(data.result.nb_no_md5sum);

                const percent_remaining = Number(((data.result.nb_no_md5sum * 100) / Number(md5sumToAdd.dataset.origin)).toFixed());
                const percent_done = 100 - percent_remaining;
                const md5sumAdded = document.getElementById('md5sum_added');
                if (md5sumAdded) md5sumAdded.innerHTML = String(percent_done);

                if (data.result.nb_no_md5sum > 0) {
                    add_md5sum_block();
                } else {
                    document.location.href = 'admin.php?page=batch_manager&action=sync_md5sum&nb_md5sum_added=' +
                        String(md5sumToAdd.dataset.origin);
                }
            })
            .catch(function (err: Error) {
                const addMd5sum = document.getElementById('add_md5sum');
                if (addMd5sum) addMd5sum.style.display = 'none';
                const addMd5sumError = document.getElementById('add_md5sum_error');
                if (addMd5sumError) {
                    addMd5sumError.style.display = '';
                    addMd5sumError.innerHTML = 'error: ' + err.message;
                }
            });
    }

    document.querySelectorAll<HTMLElement>('#delete_orphans').forEach(function (el) {
        el.addEventListener('click', function (this: HTMLElement, e) {
            e.preventDefault();
            this.style.display = 'none';
            const orphansDeletion = document.getElementById('orphans_deletion');
            if (orphansDeletion) orphansDeletion.style.display = '';

            const orphansToDelete = document.getElementById('orphans_to_delete')!;
            const deleteBlockSize = Math.min(Number((Number(orphansToDelete.dataset.origin) / 2).toFixed()), 1000);
            delete_orphans_block(deleteBlockSize);
        });
    });

    function delete_orphans_block(blockSize?: number): void {
        const body = new URLSearchParams({ pwg_token: document.querySelector<HTMLInputElement>('input[name=pwg_token]')!.value });
        if (blockSize !== undefined) body.append('block_size', String(blockSize));
        fetch('ws.php?format=json&method=pwg.images.deleteOrphans', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data: { result: { nb_orphans: number } }) {
                const orphansToDelete = document.getElementById('orphans_to_delete')!;
                orphansToDelete.innerHTML = String(data.result.nb_orphans);

                const percent_remaining = Number(((data.result.nb_orphans * 100) / Number(orphansToDelete.dataset.origin)).toFixed());
                const percent_done = 100 - percent_remaining;
                const orphansDeleted = document.getElementById('orphans_deleted');
                if (orphansDeleted) orphansDeleted.innerHTML = String(percent_done);

                if (data.result.nb_orphans > 0) {
                    delete_orphans_block();
                } else {
                    document.location.href = 'admin.php?page=batch_manager&action=delete_orphans&nb_orphans_deleted=' +
                        String(orphansToDelete.dataset.origin);
                }
            })
            .catch(function (err: Error) {
                const orphansDeletion = document.getElementById('orphans_deletion');
                if (orphansDeletion) orphansDeletion.style.display = 'none';
                const orphansDeletionError = document.getElementById('orphans_deletion_error');
                if (orphansDeletionError) {
                    orphansDeletionError.style.display = '';
                    orphansDeletionError.innerHTML = 'error: ' + err.message;
                }
            });
    }
}

initModule(init as unknown as (cfg: Record<string, unknown>) => void);
