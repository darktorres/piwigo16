import { pwgConfirm } from './pwgConfirm.js';
import GLightbox from 'glightbox';

const _docReady = function(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); };
/* ********** Filters */
function filter_enable(filter) {
    /* show the filter*/
    var el = document.getElementById(filter);
    if (el) el.style.display = '';

    /* check the checkbox to declare we use this filter */
    var checkbox = document.querySelector("input[type=checkbox][name=" + filter + "_use]");
    if (checkbox) checkbox.checked = true;

    /* forbid to select this filter in the addFilter list */
    var addFilterLink = document.querySelector("#addFilter a[data-value=" + filter + "]");
    if (addFilterLink) addFilterLink.classList.add("disabled");

    /* hide the no filter message */
    document.querySelectorAll(".noFilter").forEach(function (el) {
        el.style.display = 'none';
    });
    document.querySelectorAll(".addFilter-button").forEach(function (el) {
        el.classList.remove("highlight");
    });
}

function filter_disable(filter) {
    /* hide the filter line */
    var el = document.getElementById(filter);
    if (el) el.style.display = 'none';

    /* uncheck the checkbox to declare we do not use this filter */
    var checkbox = document.querySelector("input[name=" + filter + "_use]");
    if (checkbox) checkbox.checked = false;

    /* give the possibility to show it again */
    var addFilterLink = document.querySelector("#addFilter a[data-value=" + filter + "]");
    if (addFilterLink) addFilterLink.classList.remove("disabled");

    /* show the no filter message if no filter selected */
    var visibleFilters = document.querySelectorAll("#filterList li:not([style*='display: none'])").length;
    if (visibleFilters == 0) {
        document.querySelectorAll(".noFilter").forEach(function (el) {
            el.style.display = '';
        });
        document.querySelectorAll(".addFilter-button").forEach(function (el) {
            el.classList.add("highlight");
        });
    }
}

/* Called from the addFilter-button onclick attribute */
function toggleAddFilterDropdown() {
    var el = document.querySelector('.addFilter-dropdown');
    if (el) el.style.display = window.getComputedStyle(el).display === 'none' ? 'block' : 'none';
}

/* Called from javascript: hrefs in the generate/delete derivatives action panels */
function selectGenerateDerivAll() {
    document.querySelectorAll("#action_generate_derivatives input[type=checkbox]").forEach(function (el) {
        el.checked = true;
        el.dispatchEvent(new Event("change"));
    });
}

function selectGenerateDerivNone() {
    document.querySelectorAll("#action_generate_derivatives input[type=checkbox]").forEach(function (el) {
        el.checked = false;
        el.dispatchEvent(new Event("change"));
    });
}

function selectDelDerivAll() {
    document.querySelectorAll('#action_delete_derivatives input[name="del_derivatives_type[]"]').forEach(function (el) {
        el.checked = true;
        el.dispatchEvent(new Event("change"));
    });
}

function selectDelDerivNone() {
    document.querySelectorAll('#action_delete_derivatives input[name="del_derivatives_type[]"]').forEach(function (el) {
        el.checked = false;
        el.dispatchEvent(new Event("change"));
    });
}

document.querySelectorAll(".removeFilter").forEach(function (el) {
    el.classList.add("icon-cancel-circled");
});

document.querySelectorAll(".removeFilter").forEach(function (el) {
    el.addEventListener("click", function () {
        var filter = this.closest("li").id;
        filter_disable(filter);
        return false;
    });
});

document.querySelectorAll("#addFilter a").forEach(function (el) {
    el.addEventListener("click", function () {
        var filter = this.getAttribute("data-value");
        filter_enable(filter);
    });
});

var removeFiltersBtn = document.getElementById("removeFilters");
if (removeFiltersBtn) {
    removeFiltersBtn.addEventListener("click", function () {
        document.querySelectorAll("#filterList li").forEach(function (el) {
            var filter = el.id;
            filter_disable(filter);
        });
        return false;
    });
}

["widths", "heights", "ratios", "filesizes"].forEach(function (key) {
    var el = document.querySelector("[data-slider=" + key + "]");
    if (el) pwgDoubleSlider(el, batchManagerConfig.sliders[key]);
});

document.addEventListener("mouseup", function (e) {
    e.stopPropagation();
    if (!e.target.classList.contains("addFilter-button")) {
        document.querySelectorAll(".addFilter-dropdown").forEach(function (el) {
            el.style.display = 'none';
        });
    }
});

/* ********** Thumbs */

_docReady( function () {
    var cfg = batchManagerConfig;
    var lang = cfg.lang;

    /* ---- Tags ---- */
    var tagsCache = new TagsCache({
        serverKey: cfg.tagsServerKey,
        serverId: cfg.cacheServerId,
        rootUrl: cfg.rootUrl
    });

    tagsCache.selectize(document.querySelectorAll('[data-selectize=tags]'), {
        lang: { 'Add': lang.tagCreate }
    });

    /* ---- Categories ---- */
    window.categoriesCache = new CategoriesCache({
        serverKey: cfg.categoriesServerKey,
        serverId: cfg.cacheServerId,
        rootUrl: cfg.rootUrl
    });

    categoriesCache.selectize(document.querySelectorAll('[data-selectize=categories]'), {
        filter: function (categories, options) {
            if (this.name == 'dissociate') {
                var filtered = categories.filter(function (cat) {
                    return !!cfg.associatedCategories[cat.id];
                });
                if (filtered.length > 0) options.default = filtered[0].id;
                return filtered;
            }
            return categories;
        }
    });

    /* ---- Selection counters ---- */
    function checkPermitAction() {
        var setSelectedEl = document.querySelector("input[name=setSelected]");
        var nbSelected = 0;
        if (setSelectedEl && setSelectedEl.checked) {
            nbSelected = cfg.nbThumbsSet;
        } else {
            nbSelected = document.querySelectorAll(".thumbnails input[type=checkbox]:checked").length;
        }

        var permitAction = document.getElementById("permitAction");
        var forbidAction = document.getElementById("forbidAction");
        if (nbSelected == 0) {
            if (permitAction) permitAction.style.display = 'none';
            if (forbidAction) forbidAction.style.display = '';
        } else {
            if (permitAction) permitAction.style.display = '';
            if (forbidAction) forbidAction.style.display = 'none';
        }

        var applyOnDetails = document.getElementById("applyOnDetails");
        if (applyOnDetails) applyOnDetails.textContent = sprintf(cfg.applyOnDetailsPattern, nbSelected);

        var selectedMessage = document.getElementById("selectedMessage");
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
    document.querySelectorAll("[id^=action_]").forEach(function (el) {
        el.style.display = 'none';
    });

    var selectActionEl = document.querySelector("select[name=selectAction]");
    if (selectActionEl) {
        selectActionEl.addEventListener('change', function () {
            document.querySelectorAll("[id^=action_]").forEach(function (el) {
                el.style.display = 'none';
            });

            var action = this.value;
            if (action == 'move') action = 'associate';

            var actionEl = document.getElementById("action_" + action);
            if (actionEl) actionEl.style.display = '';

            var applyActionBlock = document.getElementById("applyActionBlock");
            if (this.value != -1) {
                if (applyActionBlock) applyActionBlock.style.display = '';
            } else {
                if (applyActionBlock) applyActionBlock.style.display = 'none';
            }

            var confirmDel = document.getElementById("confirmDel");
            if (this.value == "delete" || this.value == "delete_derivatives") {
                if (confirmDel) confirmDel.style.visibility = "visible";
            } else {
                if (confirmDel) confirmDel.style.visibility = "hidden";
            }
        });
    }

    /* ---- Thumbnail selection ---- */
    document.querySelectorAll(".wrap1 label").forEach(function (label) {
        label.addEventListener('click', function () {
            var setSelectedEl = document.querySelector("input[name=setSelected]");
            if (setSelectedEl) {
                setSelectedEl.checked = false;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }

            var li = this.closest("li");
            var checkbox = this.querySelector("input[type=checkbox]");
            if (checkbox) {
                if (checkbox.checked) {
                    if (li) li.classList.add("thumbSelected");
                } else {
                    if (li) li.classList.remove('thumbSelected');
                }
            }

            checkPermitAction();
        });
    });

    function selectPageThumbnails() {
        document.querySelectorAll(".thumbnails label").forEach(function (label) {
            var checkbox = label.querySelector("input[type=checkbox]");
            if (checkbox) {
                checkbox.checked = true;
                checkbox.dispatchEvent(new Event("change", { bubbles: true }));
            }
            var li = label.closest("li");
            if (li) li.classList.add("thumbSelected");
        });
    }

    var selectAllEl = document.getElementById("selectAll");
    if (selectAllEl) {
        selectAllEl.addEventListener('click', function (event) {
            event.preventDefault();
            var setSelectedEl = document.querySelector("input[name=setSelected]");
            if (setSelectedEl) {
                setSelectedEl.checked = false;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            selectPageThumbnails();
            checkPermitAction();
        });
    }

    var selectNoneEl = document.getElementById("selectNone");
    if (selectNoneEl) {
        selectNoneEl.addEventListener('click', function (event) {
            event.preventDefault();
            var setSelectedEl = document.querySelector("input[name=setSelected]");
            if (setSelectedEl) {
                setSelectedEl.checked = false;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            document.querySelectorAll(".thumbnails label").forEach(function (label) {
                var checkbox = label.querySelector("input[type=checkbox]");
                if (checkbox && checkbox.checked) {
                    checkbox.checked = false;
                    checkbox.dispatchEvent(new Event("change", { bubbles: true }));
                }
                var li = label.closest("li");
                if (li) li.classList.remove("thumbSelected");
            });
            checkPermitAction();
        });
    }

    var selectInvertEl = document.getElementById("selectInvert");
    if (selectInvertEl) {
        selectInvertEl.addEventListener('click', function (event) {
            event.preventDefault();
            var setSelectedEl = document.querySelector("input[name=setSelected]");
            if (setSelectedEl) {
                setSelectedEl.checked = false;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            document.querySelectorAll(".thumbnails label").forEach(function (label) {
                var checkbox = label.querySelector("input[type=checkbox]");
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    checkbox.dispatchEvent(new Event("change", { bubbles: true }));
                    var li = label.closest("li");
                    if (li) li.classList.toggle("thumbSelected", checkbox.checked);
                }
            });
            checkPermitAction();
        });
    }

    var selectSetEl = document.getElementById("selectSet");
    if (selectSetEl) {
        selectSetEl.addEventListener('click', function (event) {
            event.preventDefault();
            selectPageThumbnails();
            var setSelectedEl = document.querySelector("input[name=setSelected]");
            if (setSelectedEl) {
                setSelectedEl.checked = true;
                setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            checkPermitAction();
        });
    }

    var setSelectedEl = document.querySelector("input[name=setSelected]");
    if (setSelectedEl) {
        setSelectedEl.addEventListener('change', function () {
            var wholeSet = document.querySelector('input[name=whole_set]');
            if (wholeSet) wholeSet.value = this.checked ? cfg.allElements.join(',') : '';
        });
        /* Trigger on load when whole set is already selected (e.g. after an action) */
        if (setSelectedEl.checked) {
            setSelectedEl.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    /* ---- Filter prefilter ---- */
    var filterPrefilterEl = document.querySelector("select[name=filter_prefilter]");
    if (filterPrefilterEl) {
        filterPrefilterEl.addEventListener('change', function () {
            var emptyCaddie = document.getElementById("empty_caddie");
            var duplicatesOptions = document.getElementById("duplicates_options");
            var deleteOrphans = document.getElementById("delete_orphans");
            var syncMd5sum = document.getElementById("sync_md5sum");
            if (emptyCaddie) emptyCaddie.style.display = this.value == "caddie" ? '' : 'none';
            if (duplicatesOptions) duplicatesOptions.style.display = this.value == "duplicates" ? '' : 'none';
            if (deleteOrphans) deleteOrphans.style.display = this.value == "no_album" ? '' : 'none';
            if (syncMd5sum) syncMd5sum.style.display = this.value == "no_sync_md5sum" ? '' : 'none';
        });
    }

    checkPermitAction();

    /* ---- Shift-click range selection ---- */
    (function () {
        var last_clicked = 0;
        var last_clickedstatus = true;

        var thumbnails = document.querySelector("ul.thumbnails");
        if (!thumbnails) return;
        var checkboxes = Array.from(thumbnails.querySelectorAll("input[type=checkbox]"));
        checkboxes.forEach(function (cb, pos) {
            cb.addEventListener("click", function (event) {
                if (event.shiftKey) {
                    var first = last_clicked < pos ? last_clicked : pos;
                    var last = last_clicked < pos ? pos : last_clicked;
                    for (var i = first; i <= last; i++) {
                        checkboxes[i].checked = last_clickedstatus;
                        checkboxes[i].dispatchEvent(new Event("change"));
                        var li = checkboxes[i].closest("li");
                        if (li) li.classList.toggle("thumbSelected", last_clickedstatus);
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

    document.querySelectorAll("[data-add-album]").forEach(function (btn) { pwgAddAlbum(btn); });

    document.querySelectorAll("input[name=remove_author]").forEach(function (el) {
        el.addEventListener("click", function () {
            var display = this.checked ? 'none' : '';
            document.querySelectorAll("input[name=author]").forEach(function (input) {
                input.style.display = display;
            });
        });
    });

    document.querySelectorAll("input[name=remove_title]").forEach(function (el) {
        el.addEventListener("click", function () {
            var display = this.checked ? 'none' : '';
            document.querySelectorAll("input[name=title]").forEach(function (input) {
                input.style.display = display;
            });
        });
    });

    document.querySelectorAll("input[name=remove_date_creation]").forEach(function (el) {
        el.addEventListener("click", function () {
            var setDate = document.getElementById("set_date_creation");
            if (setDate) setDate.style.display = this.checked ? 'none' : '';
        });
    });

    /* ---- Derivative generation ---- */
    var derivatives = {
        elements: null,
        done: 0,
        total: 0,

        finished: function () {
            return (
                derivatives.done == derivatives.total &&
                derivatives.elements &&
                derivatives.elements.length == 0
            );
        },
    };

    function progress_start() {
        var el = document.getElementById("uploadingActions");
        if (el) {
            el.style.display = '';
            var progBar = el.querySelector(".progress-bar");
            if (progBar) progBar.style.width = "0%";
        }
    }

    function progress_end() {
        var el = document.getElementById("uploadingActions");
        if (el) el.style.display = 'none';
    }

    function progress(success) {
        var percent = parseInt((derivatives.done / derivatives.total) * 100);
        var progBar = document.querySelector("#uploadingActions .progressbar");
        if (progBar) progBar.style.width = percent.toString() + "%";

        if (success !== undefined) {
            var type = success ? "regenerateSuccess" : "regenerateError";
            var input = document.querySelector('[name="' + type + '"]');
            if (input) input.value = (parseInt(input.value) + 1).toString();
        }

        if (derivatives.finished()) {
            progress_end();
            var applyActionBtn = document.getElementById("applyAction");
            if (applyActionBtn) applyActionBtn.click();
        }
    }

    function getDerivativeUrls() {
        var ids = derivatives.elements.splice(0, 500);
        var params = { max_urls: 100000, ids: ids, types: [] };

        document.querySelectorAll("#action_generate_derivatives input").forEach(function (t) {
            if (t.checked) params.types.push(t.value);
        });

        document.getElementById("applyActionBlock").style.display = 'none';
        document.querySelectorAll(".permitActionListButton").forEach(function (el) {
            el.style.display = 'none';
        });
        document.getElementById("confirmDel").style.display = 'none';
        document.getElementById("regenerationMsg").style.display = '';
        var regenerationText = document.getElementById("regenerationText");
        if (regenerationText) regenerationText.innerHTML = lang.generateMsg;

        progress_start();

        var body = new URLSearchParams();
        body.append("max_urls", params.max_urls);
        params.ids.forEach(function (id) { body.append("ids[]", id); });
        params.types.forEach(function (t) { body.append("types[]", t); });
        fetch("ws.php?format=json&method=pwg.getMissingDerivatives", { method: "POST", body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.stat || data.stat != "ok") return;
                derivatives.total += data.result.urls.length;
                var badge = document.querySelector("#regenerationStatus .badge-number");
                if (badge) badge.innerHTML = derivatives.done + "/" + derivatives.total;
                progress();
                var urls = data.result.urls;
                var idx = 0;
                function fetchNextDerivative() {
                    if (idx >= urls.length) return;
                    var url = urls[idx++];
                    fetch(url + "&ajaxload=true")
                        .then(function (r) { return r.json(); })
                        .then(function () {
                            derivatives.done++;
                            var badge = document.querySelector("#regenerationStatus .badge-number");
                            if (badge) badge.innerHTML = derivatives.done + "/" + derivatives.total;
                            progress(true);
                            fetchNextDerivative();
                        })
                        .catch(function () {
                            derivatives.done++;
                            var badge = document.querySelector("#regenerationStatus .badge-number");
                            if (badge) badge.innerHTML = derivatives.done + "/" + derivatives.total;
                            progress(false);
                            fetchNextDerivative();
                        });
                }
                fetchNextDerivative();
                if (derivatives.elements.length)
                    setTimeout(getDerivativeUrls, 25 * (derivatives.total - derivatives.done));
            });
    }

    /* ---- Apply action (all action types in one handler) ---- */
    var applyActionBtn = document.getElementById("applyAction");
    if (applyActionBtn) {
        applyActionBtn.addEventListener("click", function (e) {
            var action = document.querySelector('[name="selectAction"]').value;

            /* delete_derivatives: require confirmation */
            if (action == 'delete_derivatives') {
                if (!document.querySelector("#confirmDel input[name=confirm_deletion]").checked) {
                    document.querySelector("#confirmDel span.errors").style.visibility = "visible";
                    e.preventDefault();
                }
                return;
            }

            /* generate_derivatives: kick off async generation */
            if (action == 'generate_derivatives') {
                if (derivatives.finished()) return;
                e.preventDefault();
                document.querySelectorAll('.bulkAction').forEach(function (el) {
                    el.style.display = 'none';
                });
                derivatives.elements = [];
                if (document.querySelector('input[name="setSelected"]').checked) {
                    derivatives.elements = cfg.allElements.slice();
                } else {
                    document.querySelectorAll('.thumbnails input[type=checkbox]').forEach(function (cb) {
                        if (cb.checked) derivatives.elements.push(cb.value);
                    });
                }
                document.getElementById('applyActionBlock').style.display = 'none';
                document.querySelector('select[name="selectAction"]').style.display = 'none';
                document.querySelector('.permitActionListButton div').classList.add('hidden');
                document.getElementById('regenerationMsg').style.display = '';
                progress_start();
                progress();
                getDerivativeUrls();
                return;
            }

            /* metadata: sync by blocks with progress */
            if (action == 'metadata') {
                e.preventDefault();
                e.stopPropagation();
                document.querySelectorAll(".bulkAction").forEach(function (el) {
                    el.style.display = 'none';
                });
                var regenerationText = document.getElementById("regenerationText");
                if (regenerationText) regenerationText.innerHTML = lang.syncProgressMessage;
                var elements = [];

                if (document.querySelector("input[name=setSelected]").checked) {
                    elements = cfg.allElements.slice();
                } else {
                    document.querySelectorAll('input[name="selection[]"]:checked').forEach(function (el) {
                        elements.push(el.value);
                    });
                }

                var progressBar_max = elements.length;
                var todo = 0;
                var syncBlockSize = Math.min(Number((elements.length / 2).toFixed()), 500);
                var image_ids = [];

                document.getElementById("applyActionBlock").style.display = 'none';
                document.querySelectorAll(".permitActionListButton").forEach(function (el) {
                    el.style.display = 'none';
                });
                document.getElementById("confirmDel").style.display = 'none';
                document.getElementById("regenerationMsg").style.display = '';
                progress_bar_start();

                for (var i = 0; i < elements.length; i++) {
                    image_ids.push(elements[i]);
                    if (i % syncBlockSize != syncBlockSize - 1 && i != elements.length - 1) continue;

                    (function (ids) {
                        var thisBatchSize = ids.length;
                        var body = new URLSearchParams({
                            pwg_token: document.querySelector("input[name=pwg_token]").value,
                        });
                        ids.forEach(function (id) { body.append("image_id[]", id); });
                        fetch("ws.php?format=json&method=pwg.images.syncMetadata", { method: "POST", body: body })
                            .then(function (r) { return r.json(); })
                            .then(function () {
                                todo += thisBatchSize;
                                var badge = document.querySelector("#regenerationStatus .badge-number");
                                if (badge) badge.innerHTML = todo + "/" + progressBar_max;
                                progress_bar(todo, progressBar_max, false);
                            })
                            .catch(function () {
                                todo += thisBatchSize;
                                var badge = document.querySelector("#regenerationStatus .badge-number");
                                if (badge) badge.innerHTML = todo + "/" + progressBar_max;
                                progress_bar(todo, progressBar_max, false);
                            });
                    })(image_ids);
                    image_ids = [];
                }
                return;
            }

            /* delete: require confirmation, then delete by blocks */
            if (action == 'delete') {
                if (!document.querySelector("#confirmDel input[name=confirm_deletion]").checked) {
                    var errorSpan = document.querySelector("#confirmDel span.errors");
                    if (errorSpan) errorSpan.style.visibility = "visible";
                    e.preventDefault();
                    return;
                }
                e.preventDefault();
                e.stopPropagation();

                document.querySelectorAll(".bulkAction").forEach(function (el) {
                    el.style.display = 'none';
                });

                var elements = [];
                if (document.querySelector("input[name=setSelected]").checked) {
                    elements = cfg.allElements.slice();
                } else {
                    document.querySelectorAll('input[name="selection[]"]:checked').forEach(function (el) {
                        elements.push(el.value);
                    });
                }

                var progressBar_max = elements.length;
                var todo = 0;
                var deleteBlockSize = Math.min(Number((elements.length / 2).toFixed()), 1000);
                var image_ids = [];

                document.getElementById("applyActionBlock").style.display = 'none';
                document.querySelectorAll(".permitActionListButton").forEach(function (el) {
                    el.style.display = 'none';
                });
                document.getElementById("confirmDel").style.display = 'none';
                var regenerationText = document.getElementById("regenerationText");
                if (regenerationText) regenerationText.innerHTML = lang.deleteProgressMessage;
                document.getElementById("regenerationMsg").style.display = '';
                progress_bar_start();

                var _deleteQueue = Promise.resolve();
                for (var i = 0; i < elements.length; i++) {
                    image_ids.push(elements[i]);
                    if (i % deleteBlockSize != deleteBlockSize - 1 && i != elements.length - 1) continue;

                    (function (ids) {
                        var thisBatchSize = ids.length;
                        _deleteQueue = _deleteQueue.then(function () {
                            return fetch("ws.php?format=json", {
                                method: "POST",
                                body: new URLSearchParams({
                                    method: "pwg.images.delete",
                                    pwg_token: document.querySelector("input[name=pwg_token]").value,
                                    image_id: ids.join(","),
                                }),
                            })
                                .then(function (r) { return r.json(); })
                                .then(function () {
                                    todo += thisBatchSize;
                                    var badge = document.querySelector("#regenerationStatus .badge-number");
                                    if (badge) badge.innerHTML = todo + "/" + progressBar_max;
                                    progress_bar(todo, progressBar_max, false);
                                })
                                .catch(function () {
                                    todo += thisBatchSize;
                                    var badge = document.querySelector("#regenerationStatus .badge-number");
                                    if (badge) badge.innerHTML = todo + "/" + progressBar_max;
                                    progress_bar(todo, progressBar_max, false);
                                });
                        });
                    })(image_ids);

                    image_ids = [];
                }

                document.querySelector("form").insertAdjacentHTML(
                    "beforeend",
                    '<input type="hidden" name="nb_photos_deleted" value="' + elements.length + '">',
                );
                return;
            }
        });
    }

    function progress_bar_start() {
        var el = document.getElementById("uploadingActions");
        if (el) {
            el.style.display = '';
            var progBar = el.querySelector(".progress-bar");
            if (progBar) progBar.style.width = "0%";
        }
    }

    function progress_bar_end() {
        var el = document.getElementById("uploadingActions");
        if (el) el.style.display = 'none';
    }

    function progress_bar(val, max) {
        var percent = parseInt((val / max) * 100);
        var progBar = document.querySelector("#uploadingActions .progressbar");
        if (progBar) progBar.style.width = percent.toString() + "%";
        if (val == max) {
            var applyActionBtn = document.getElementById("applyAction");
            if (applyActionBtn) applyActionBtn.click();
        }
    }

    document.querySelectorAll("#confirmDel input[name=confirm_deletion]").forEach(function (el) {
        el.addEventListener("change", function () {
            var errorSpan = document.querySelector("#confirmDel span.errors");
            if (errorSpan) errorSpan.style.visibility = "hidden";
        });
    });

    document.querySelectorAll("#sync_md5sum").forEach(function (el) {
        el.addEventListener("click", function (e) {
            e.preventDefault();
            this.style.display = 'none';
            var addMd5sum = document.getElementById("add_md5sum");
            if (addMd5sum) addMd5sum.style.display = '';

            var md5sumToAdd = document.getElementById("md5sum_to_add");
            var addBlockSize = Math.min(Number((md5sumToAdd.dataset.origin / 2).toFixed()), 1000);
            add_md5sum_block(addBlockSize);
        });
    });

    function add_md5sum_block(blockSize) {
        var body = new URLSearchParams({ pwg_token: document.querySelector("input[name=pwg_token]").value });
        if (blockSize !== undefined) body.append("block_size", blockSize);
        fetch("ws.php?format=json&method=pwg.images.setMd5sum", { method: "POST", body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var md5sumToAdd = document.getElementById("md5sum_to_add");
                if (md5sumToAdd) md5sumToAdd.innerHTML = data.result.nb_no_md5sum;

                var percent_remaining = Number(((data.result.nb_no_md5sum * 100) / md5sumToAdd.dataset.origin).toFixed());
                var percent_done = 100 - percent_remaining;
                var md5sumAdded = document.getElementById("md5sum_added");
                if (md5sumAdded) md5sumAdded.innerHTML = percent_done;

                if (data.result.nb_no_md5sum > 0) {
                    add_md5sum_block();
                } else {
                    document.location = "admin.php?page=batch_manager&action=sync_md5sum&nb_md5sum_added=" +
                        md5sumToAdd.dataset.origin;
                }
            })
            .catch(function (err) {
                var addMd5sum = document.getElementById("add_md5sum");
                if (addMd5sum) addMd5sum.style.display = 'none';
                var addMd5sumError = document.getElementById("add_md5sum_error");
                if (addMd5sumError) {
                    addMd5sumError.style.display = '';
                    addMd5sumError.innerHTML = "error: " + err.message;
                }
            });
    }

    document.querySelectorAll("#delete_orphans").forEach(function (el) {
        el.addEventListener("click", function (e) {
            e.preventDefault();
            this.style.display = 'none';
            var orphansDeletion = document.getElementById("orphans_deletion");
            if (orphansDeletion) orphansDeletion.style.display = '';

            var orphansToDelete = document.getElementById("orphans_to_delete");
            var deleteBlockSize = Math.min(Number((orphansToDelete.dataset.origin / 2).toFixed()), 1000);
            delete_orphans_block(deleteBlockSize);
        });
    });

    function delete_orphans_block(blockSize) {
        var body = new URLSearchParams({ pwg_token: document.querySelector("input[name=pwg_token]").value });
        if (blockSize !== undefined) body.append("block_size", blockSize);
        fetch("ws.php?format=json&method=pwg.images.deleteOrphans", { method: "POST", body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var orphansToDelete = document.getElementById("orphans_to_delete");
                if (orphansToDelete) orphansToDelete.innerHTML = data.result.nb_orphans;

                var percent_remaining = Number(((data.result.nb_orphans * 100) / orphansToDelete.dataset.origin).toFixed());
                var percent_done = 100 - percent_remaining;
                var orphansDeleted = document.getElementById("orphans_deleted");
                if (orphansDeleted) orphansDeleted.innerHTML = percent_done;

                if (data.result.nb_orphans > 0) {
                    delete_orphans_block();
                } else {
                    document.location = "admin.php?page=batch_manager&action=delete_orphans&nb_orphans_deleted=" +
                        orphansToDelete.dataset.origin;
                }
            })
            .catch(function (err) {
                var orphansDeletion = document.getElementById("orphans_deletion");
                if (orphansDeletion) orphansDeletion.style.display = 'none';
                var orphansDeletionError = document.getElementById("orphans_deletion_error");
                if (orphansDeletionError) {
                    orphansDeletionError.style.display = '';
                    orphansDeletionError.innerHTML = "error: " + err.message;
                }
            });
    }
});
