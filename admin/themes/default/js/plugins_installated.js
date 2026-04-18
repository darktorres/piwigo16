import { initModule } from './moduleInit.js';
import tippy from 'tippy.js';
import { pwgConfirm, pwgConfirmFollowHref } from './pwgConfirm.js';

var _deactivateQueue = Promise.resolve();
var _deactivateTotal = 0;
var _deactivateDone = 0;

export function init(cfg) {
    const {
        pwgToken, incompatibleMsg, activateMsg, deactivateAllMsg,
        nbPlugin, areYouSureMsg, confirmMsg, cancelMsg,
        deletePluginMsg, deletedPluginMsg, restorePluginMsg, uninstallPluginMsg,
        restoreTipMsg, pluginAddedStr, pluginDeactivatedStr, pluginRestoredStr,
        pluginActionError, notWebmaster, nothingFound, xPluginsFound, pluginFound,
        isWebmaster, viewSelector, strRestoreDef, showDetails, pluginFilter = ''
    } = cfg;

    const nb_plugin = nbPlugin;
    const pwg_token = pwgToken;

function showInactivePlugins() {
    var showEls = document.querySelectorAll(".showInactivePlugins");
    if (!showEls.length) {
        document.querySelectorAll(".plugin-inactive").forEach(function(el) { el.style.display = ''; });
        return;
    }
    var pending = showEls.length;
    showEls.forEach(function(el) {
        el.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 300, fill: 'forwards' }).finished.then(function() {
            el.style.display = 'none';
            if (--pending === 0) {
                document.querySelectorAll(".plugin-inactive").forEach(function(inEl) {
                    inEl.style.display = '';
                    inEl.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 300 });
                });
            }
        });
    });
}

function actualizeFilter() {
    var seeAllLabel = document.querySelector("label[for='seeAll'] .filter-badge");
    if (seeAllLabel) seeAllLabel.innerHTML = nb_plugin.all;
    var seeActiveLabel = document.querySelector("label[for='seeActive'] .filter-badge");
    if (seeActiveLabel) seeActiveLabel.innerHTML = nb_plugin.active;
    var seeInactiveLabel = document.querySelector("label[for='seeInactive'] .filter-badge");
    if (seeInactiveLabel) seeInactiveLabel.innerHTML = nb_plugin.inactive;
    var seeOtherLabel = document.querySelector("label[for='seeOther'] .filter-badge");
    if (seeOtherLabel) seeOtherLabel.innerHTML = nb_plugin.other;

    document.querySelectorAll(".filterLabel").forEach(function (el) {
        el.style.display = '';
    });

    document.querySelectorAll(".pluginMiniBox").forEach(function (el) {
        if (nb_plugin.active == 0) {
            var activeLabel = document.querySelector("label[for='seeActive']");
            if (activeLabel) activeLabel.style.display = 'none';
            if (document.getElementById("seeActive").checked) {
                document.getElementById("seeAll").click();
            }
        }
        if (nb_plugin.inactive == 0) {
            var inactiveLabel = document.querySelector("label[for='seeInactive']");
            if (inactiveLabel) inactiveLabel.style.display = 'none';
            if (document.getElementById("seeInactive").checked) {
                document.getElementById("seeAll").click();
            }
        }
        if (nb_plugin.other == 0) {
            var otherLabel = document.querySelector("label[for='seeOther']");
            if (otherLabel) otherLabel.style.display = 'none';
            if (document.getElementById("seeOther").checked) {
                document.getElementById("seeAll").click();
            }
        }
    });
}

function setDisplayClassic() {
    document.querySelectorAll(".pluginContainer").forEach(function (el) {
        el.classList.remove("line-form", "compact-form");
        el.classList.add("classic-form");
    });

    document.querySelectorAll(".pluginDesc").forEach(function (el) {
        el.style.display = '';
    });
    document.querySelectorAll(".pluginActions").forEach(function (el) {
        el.style.display = '';
    });
    document.querySelectorAll(".pluginActionsSmallIcons").forEach(function (el) {
        el.style.display = 'none';
    });

    document.querySelectorAll(".pluginName").forEach(function (el) {
        el.classList.remove("pluginNameCompact");
    });
}

function setDisplayCompact() {
    document.querySelectorAll(".pluginContainer").forEach(function (el) {
        el.classList.remove("line-form");
        el.classList.add("compact-form");
        el.classList.remove("classic-form");
    });

    document.querySelectorAll(".pluginDesc").forEach(function (el) {
        el.style.display = 'none';
    });
    document.querySelectorAll(".pluginActions").forEach(function (el) {
        el.style.display = 'none';
    });
    document.querySelectorAll(".pluginActionsSmallIcons").forEach(function (el) {
        el.style.display = '';
    });

    document.querySelectorAll(".pluginName").forEach(function (el) {
        el.classList.add("pluginNameCompact");
    });
}

function setDisplayLine() {
    document.querySelectorAll(".pluginContainer").forEach(function (el) {
        el.classList.add("line-form");
        el.classList.remove("compact-form", "classic-form");
    });

    document.querySelectorAll(".pluginDesc").forEach(function (el) {
        el.style.display = '';
    });
    document.querySelectorAll(".pluginActions").forEach(function (el) {
        el.style.display = '';
    });
    document.querySelectorAll(".pluginActionsSmallIcons").forEach(function (el) {
        el.style.display = 'none';
    });
}

function reduceTitle() {
    var x = document.getElementsByClassName("pluginName");
    var length = 22;

    for (const div of x) {
        var text = div.innerHTML.trim();
        if (text.length > length) {
            var newText = text.substring(0, length);
            newText = newText + "...";

            div.innerHTML = newText;
            div.title = text;
        }
    }
}

function normalTitle() {
    var x = document.getElementsByClassName("pluginName");

    for (const div of x) {
        div.innerHTML = div.dataset.title;
    }
}

function activatePlugin(id) {
    var switchEl = document.querySelector("#" + id + " .switch");
    if (switchEl) switchEl.disabled = true;

    fetch("ws.php?" + new URLSearchParams({
        method: "pwg.plugins.performAction",
        action: "activate",
        plugin: id,
        pwg_token: pwg_token,
        format: "json",
    }))
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var switchEl = document.querySelector("#" + id + " .switch");
        if (switchEl) switchEl.disabled = false;
        if (data.stat == "ok") {
            var successLabel = document.querySelector("#" + id + " .AddPluginSuccess label span");
            if (successLabel) successLabel.innerHTML = pluginAddedStr;
            var successEl = document.querySelector("#" + id + " .AddPluginSuccess");
            if (successEl) {
                successEl.style.display = "flex";
                setTimeout(function() { successEl.style.display = 'none'; }, 3000);
            }
            nb_plugin.active += 1;
            nb_plugin.inactive -= 1;
            actualizeFilter();
        }
    })
    .catch(function(e) {
        console.log(e);
        var errorLabel = document.querySelector("#" + id + " .PluginActionError label span");
        if (errorLabel) errorLabel.innerHTML = pluginActionError;
        var errorEl = document.querySelector("#" + id + " .PluginActionError");
        if (errorEl) {
            errorEl.style.display = "flex";
            setTimeout(function() { errorEl.style.display = 'none'; }, 4000);
        }
    });
}

function deactivatePlugin(id) {
    var switchEl = document.querySelector("#" + id + " .switch");
    if (switchEl) switchEl.disabled = true;

    fetch("ws.php?" + new URLSearchParams({
        method: "pwg.plugins.performAction",
        action: "deactivate",
        plugin: id,
        pwg_token: pwg_token,
        format: "json",
    }))
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var switchEl = document.querySelector("#" + id + " .switch");
        if (switchEl) switchEl.disabled = false;
        if (data.stat == "ok") {
            var deactivateLabel = document.querySelector("#" + id + " .DeactivatePluginSuccess label span");
            if (deactivateLabel) deactivateLabel.innerHTML = pluginDeactivatedStr;
            var deactivateEl = document.querySelector("#" + id + " .DeactivatePluginSuccess");
            if (deactivateEl) {
                deactivateEl.style.display = "flex";
                setTimeout(function() { deactivateEl.style.display = 'none'; }, 3000);
            }
            nb_plugin.inactive += 1;
            nb_plugin.active -= 1;
            actualizeFilter();
        }
    })
    .catch(function(e) {
        console.log(e);
        var errorLabel = document.querySelector("#" + id + " .PluginActionError label span");
        if (errorLabel) errorLabel.innerHTML = pluginActionError;
        var errorEl = document.querySelector("#" + id + " .PluginActionError");
        if (errorEl) {
            errorEl.style.display = "flex";
            setTimeout(function() { errorEl.style.display = 'none'; }, 4000);
        }
    });
}

function deletePlugin(id, name) {
    fetch("ws.php?" + new URLSearchParams({
        method: "pwg.plugins.performAction",
        action: "delete",
        plugin: id,
        pwg_token: pwg_token,
        format: "json",
    }))
    .then(response => response.json())
    .then(function (data) {
        if (data.stat === "ok") {
            var el = document.getElementById(id);
            if (el) el.remove();
            nb_plugin.inactive -= 1;
            nb_plugin.all -= 1;
            actualizeFilter();
        }
    })
    .catch(function (e) {
        console.log(e);
        var errorEl = document.querySelector("#" + id + " .PluginActionError");
        if (errorEl) {
            errorEl.style.display = "flex";
            setTimeout(function() { errorEl.style.display = 'none'; }, 4000);
        }
    });
}

function restorePlugin(id) {
    fetch("ws.php?" + new URLSearchParams({
        method: "pwg.plugins.performAction",
        action: "restore",
        plugin: id,
        pwg_token: pwg_token,
        format: "json",
    }))
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.stat == "ok") {
            var restoreLabel = document.querySelector("#" + id + " .RestorePluginSuccess label span");
            if (restoreLabel) restoreLabel.innerHTML = pluginRestoredStr;
            var restoreEl = document.querySelector("#" + id + " .RestorePluginSuccess");
            if (restoreEl) {
                restoreEl.style.display = "flex";
                setTimeout(function() { restoreEl.style.display = 'none'; }, 3000);
            }
        }
    })
    .catch(function(e) {
        console.log(e);
        var errorLabel = document.querySelector("#" + id + " .PluginActionError label span");
        if (errorLabel) errorLabel.innerHTML = pluginActionError;
        var errorEl = document.querySelector("#" + id + " .PluginActionError");
        if (errorEl) {
            errorEl.style.display = "flex";
            setTimeout(function() { errorEl.style.display = 'none'; }, 4000);
        }
    });
}

function uninstallPlugin(id) {
    fetch("ws.php?" + new URLSearchParams({
        method: "pwg.plugins.performAction",
        action: "uninstall",
        plugin: id,
        pwg_token: pwg_token,
        format: "json",
    }))
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var el = document.getElementById(id);
        if (el) el.remove();
        nb_plugin.other -= 1;
        nb_plugin.all -= 1;
        actualizeFilter();
    })
    .catch(function(e) {
        var errorLabel = document.querySelector("#" + id + " .PluginActionError label span");
        if (errorLabel) errorLabel.innerHTML = pluginActionError;
        var errorEl = document.querySelector("#" + id + " .PluginActionError");
        if (errorEl) {
            errorEl.style.display = "flex";
            setTimeout(function() { errorEl.style.display = 'none'; }, 4000);
        }
        console.log(e.message);
    });
}

    // Initialize
    actualizeFilter();

    if (document.getElementById("displayClassic") && document.getElementById("displayClassic").checked) {
        setDisplayClassic();
    }

    if (document.getElementById("displayCompact") && document.getElementById("displayCompact").checked) {
        setDisplayCompact();
    }

    if (document.getElementById("displayLine") && document.getElementById("displayLine").checked) {
        setDisplayLine();
    }

    document.querySelectorAll("#displayClassic, #displayCompact, #displayLine").forEach(function (el) {
        el.addEventListener("change", function () {
            if (this.id === "displayClassic") {
                setDisplayClassic();
                set_view_selector("classic");
            } else if (this.id === "displayCompact") {
                setDisplayCompact();
                set_view_selector("compact");
            } else if (this.id === "displayLine") {
                setDisplayLine();
                set_view_selector("line");
            }
        });
    });

    /* Plugin Filters */

    // Set filter on Active on load
    if (nb_plugin.active > 0) {
        document.querySelectorAll(".pluginMiniBox").forEach(function (el) {
            if (!el.classList.contains("plugin-active")) {
                el.style.display = 'none';
            }
        });
        var seeActiveEl = document.getElementById("seeActive");
        if (seeActiveEl) seeActiveEl.click();
    } else {
        document.querySelectorAll(".pluginMiniBox").forEach(function (el) {
            el.style.display = '';
        });
    }

    document.querySelectorAll("#seeAll, #seeActive, #seeInactive, #seeOther").forEach(function (el) {
        el.addEventListener("change", function () {
            document.querySelectorAll(".pluginBox").forEach(function (box) {
                box.style.display = '';
            });

            if (this.id === "seeActive") {
                document.querySelectorAll(".pluginBox").forEach(function (box) {
                    if (!box.classList.contains("plugin-active")) {
                        box.style.display = 'none';
                    }
                });
            } else if (this.id === "seeInactive") {
                document.querySelectorAll(".pluginBox").forEach(function (box) {
                    if (!box.classList.contains("plugin-inactive")) {
                        box.style.display = 'none';
                    }
                });
            } else if (this.id === "seeOther") {
                document.querySelectorAll(".pluginBox").forEach(function (box) {
                    if (box.classList.contains("plugin-active") || box.classList.contains("plugin-inactive")) {
                        box.style.display = 'none';
                    }
                });
            }

            var searchInput = document.querySelector(".search-input");
            if (searchInput) searchInput.dispatchEvent(new Event("input"));
        });
    });

    /* Plugin Actions */
    /**
     * Activate / Deactivate
     */
    if (isWebmaster != 0) {
        document.querySelectorAll(".switch").forEach(function (switchEl) {
            switchEl.addEventListener("change", function () {
                document.querySelectorAll(".pluginMiniBox").forEach(function (el) {
                    el.classList.add("usable");
                });

                var pluginBox = this.closest(".pluginBox");
                if (!pluginBox) pluginBox = this.closest(".pluginMiniBox");

                if (this.querySelector("#toggleSelectionMode") && this.querySelector("#toggleSelectionMode").checked) {
                    activatePlugin(pluginBox.id);
                    pluginBox.classList.add("plugin-active");
                    pluginBox.classList.remove("plugin-inactive");
                    var unavail = pluginBox.querySelector(".pluginUnavailableAction");
                    if (unavail && unavail.getAttribute("href")) {
                        unavail.classList.remove("pluginUnavailableAction");
                        unavail.classList.add("pluginActionLevel1");
                    }
                } else {
                    deactivatePlugin(pluginBox.id);
                    pluginBox.classList.remove("plugin-active");
                    pluginBox.classList.add("plugin-inactive");
                    var level1 = pluginBox.querySelector(".pluginActionLevel1");
                    if (level1) {
                        level1.classList.remove("pluginActionLevel1");
                        level1.classList.add("pluginUnavailableAction");
                    }
                }

                actualizeFilter();
            });
        });
    } else {
        document.querySelectorAll(".pluginMiniBox").forEach(function (el) {
            el.classList.add("notUsable");
        });
        document.querySelectorAll(".plugin-active .slider").forEach(function (el) {
            el.classList.add("deactivate_disabled");
        });
        document.querySelectorAll(".plugin-inactive .slider").forEach(function (el) {
            el.classList.add("activate_disabled");
        });
        document.querySelectorAll(".switch input").forEach(function (el) {
            el.addEventListener("click", function (event) {
                this.classList.add("disabled");
                event.preventDefault();
                event.stopPropagation();

                var id = this.closest(".pluginBox").id || this.closest(".pluginMiniBox").id;
                var errorLabel = document.querySelector("#" + id + " .PluginActionError label span");
                if (errorLabel) errorLabel.innerHTML = notWebmaster;
                var errorEl = document.querySelector("#" + id + " .PluginActionError");
                if (errorEl) errorEl.style.display = "flex";
                if (errorEl) {
                    setTimeout(function() {
                        errorEl.style.display = 'none';
                    }, 4000);
                }

                setTimeout(function () {
                    document.querySelectorAll(".switch input").forEach(function (inp) {
                        inp.classList.remove("disabled");
                    });
                }, 400);
            });
        });
    }

    /**
     * Delete
     */
    document.querySelectorAll(".pluginContent .dropdown-option.delete-plugin-button").forEach(function (el) {
        el.addEventListener("click", function () {
            let plugin_name = this.closest(".pluginContent").querySelector(".pluginName").innerHTML.trim();
            let plugin_id = this.closest(".pluginBox").id;
            pwgConfirm({
                title: deletePluginMsg.replace("%s", plugin_name),
                buttons: {
                    confirm: {
                        text: confirmMsg,
                        btnClass: "btn-red",
                        action: function () {
                            deletePlugin(plugin_id, plugin_name);
                        },
                    },
                    cancel: {
                        text: cancelMsg,
                    },
                },
            });
        });
    });

    /**
     * Restore
     */
    document.querySelectorAll(".pluginContent .dropdown-option.plugin-restore").forEach(function (el) {
        el.addEventListener("click", function () {
            let plugin_name = this.closest(".pluginContent").querySelector(".pluginName").innerHTML.trim();
            let plugin_id = this.closest(".pluginBox").id;
            pwgConfirm({
                title: restorePluginMsg.replace("%s", plugin_name),
                content: strRestoreDef,
                buttons: {
                    confirm: {
                        text: confirmMsg,
                        btnClass: "btn-red",
                        action: function () {
                            restorePlugin(plugin_id);
                        },
                    },
                    cancel: {
                        text: cancelMsg,
                    },
                },
            });
        });
    });

    /**
     * Uninstall
     */
    document.querySelectorAll(".pluginContent .uninstall-plugin-button").forEach(function (el) {
        el.addEventListener("click", function () {
            let plugin_name = this.closest(".pluginContent").querySelector(".pluginName").innerHTML.trim();
            let plugin_id = this.closest(".pluginBox").id;
            pwgConfirm({
                title: uninstallPluginMsg.replace("%s", plugin_name),
                buttons: {
                    confirm: {
                        text: confirmMsg,
                        btnClass: "btn-red",
                        action: function () {
                            uninstallPlugin(plugin_id);
                        },
                    },
                    cancel: {
                        text: cancelMsg,
                    },
                },
            });
        });
    });

    /**
     * Show Inactive plugins or button to show them
     */
    document.querySelectorAll(".showInactivePlugins button").forEach(function (el) {
        el.addEventListener("click", showInactivePlugins);
    });

    if (pluginFilter == "deactivated") {
        var filterLabel = document.querySelector(".filterLabel[for='seeInactive']");
        if (filterLabel) filterLabel.click();
    }

    /**
     * Add the filter research
     */
    document.addEventListener("keydown", function (e) {
        if (e.keyCode == 58) {
            var searchInput = document.querySelector(".pluginFilter input.search-input");
            if (searchInput) searchInput.focus();
            return false;
        }
    });

    document.querySelectorAll(".pluginFilter input").forEach(function (inputEl) {
        inputEl.addEventListener("input", function () {
            let text = this.value.toLowerCase();
            var searchNumber = 0;
            var searchActive = 0;
            var searchInactive = 0;
            var searchOther = 0;

            document.querySelectorAll(".pluginBox").forEach(function (box) {
                if (text == "") {
                    document.querySelectorAll(".nbPluginsSearch").forEach(function (el) {
                        el.style.display = 'none';
                    });
                    if (document.getElementById("seeAll").checked) {
                        box.style.display = '';
                    }
                    if (document.getElementById("seeActive").checked && box.classList.contains("plugin-active")) {
                        box.style.display = '';
                    }
                    if (document.getElementById("seeInactive").checked && box.classList.contains("plugin-inactive")) {
                        box.style.display = '';
                    }
                    if (document.getElementById("seeOther").checked && (box.classList.contains("plugin-merged") || box.classList.contains("plugin-missing"))) {
                        box.style.display = '';
                    }

                    if (box.classList.contains("plugin-active")) searchActive++;
                    if (box.classList.contains("plugin-inactive")) searchInactive++;
                    if (box.classList.contains("plugin-merged") || box.classList.contains("plugin-missing")) searchOther++;
                    searchNumber++;

                    nb_plugin.all = searchNumber;
                    nb_plugin.active = searchActive;
                    nb_plugin.inactive = searchInactive;
                    nb_plugin.other = searchOther;
                } else {
                    let name = box.querySelector(".pluginName").textContent.toLowerCase();
                    document.querySelectorAll(".nbPluginsSearch").forEach(function (el) {
                        el.style.display = '';
                    });
                    let description = box.querySelector(".pluginDesc").textContent.toLowerCase();

                    if (name.search(text) != -1 || description.search(text) != -1) {
                        searchNumber++;

                        if (document.getElementById("seeAll").checked) {
                            box.style.display = '';
                        }
                        if (document.getElementById("seeActive").checked && box.classList.contains("plugin-active")) {
                            box.style.display = '';
                        }
                        if (document.getElementById("seeInactive").checked && box.classList.contains("plugin-inactive")) {
                            box.style.display = '';
                        }
                        if (document.getElementById("seeOther").checked && (box.classList.contains("plugin-merged") || box.classList.contains("plugin-missing"))) {
                            box.style.display = '';
                        }

                        if (box.classList.contains("plugin-active")) searchActive++;
                        if (box.classList.contains("plugin-inactive")) searchInactive++;
                        if (box.classList.contains("plugin-merged") || box.classList.contains("plugin-missing")) searchOther++;

                        nb_plugin.all = searchNumber;
                        nb_plugin.active = searchActive;
                        nb_plugin.inactive = searchInactive;
                        nb_plugin.other = searchOther;
                    } else {
                        box.style.display = 'none';

                        nb_plugin.all = searchNumber;
                        nb_plugin.active = searchActive;
                        nb_plugin.inactive = searchInactive;
                        nb_plugin.other = searchOther;
                    }
                }
            });

            actualizeFilter();

            var nbSearchEl = document.querySelector(".nbPluginsSearch");
            if (searchNumber == 0) {
                if (nbSearchEl) nbSearchEl.innerHTML = nothingFound;
            } else if (searchNumber == 1) {
                if (nbSearchEl) nbSearchEl.innerHTML = pluginFound.replace("%s", searchNumber);
            } else {
                if (nbSearchEl) nbSearchEl.innerHTML = xPluginsFound.replace("%s", searchNumber);
            }
        });
    });

    // Second initialization - plugin options and deactivate
    function set_view_selector(view_type) {
        fetch("ws.php?format=json&method=pwg.users.preferences.set", {
            method: "POST",
            body: new URLSearchParams({ param: "plugin-manager-view", value: view_type }),
        });
    }

    /* Sequential fetch queue for deactivating all plugins */
    function performPluginDeactivate(id) {
    _deactivateTotal++;
    _deactivateQueue = _deactivateQueue.then(function() {
        return fetch("ws.php?" + new URLSearchParams({
            method: "pwg.plugins.performAction",
            action: "deactivate",
            plugin: id,
            pwg_token: pwg_token,
            format: "json",
        }))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.stat == "ok") {
                var el = document.getElementById(id);
                if (el) {
                    el.classList.remove("active");
                    el.classList.add("inactive");
                }
            }
            _deactivateDone++;
            if (_deactivateDone == _deactivateTotal) location.reload();
        });
    });
}

    actualizeFilter();

    document.querySelectorAll(".pluginBox").forEach(function (el) {
        let myplugin = el;
        var showOptions = el.querySelector(".showOptions");
        if (showOptions) {
            showOptions.addEventListener("click", function () {
                var optionsBlock = myplugin.querySelector(".PluginOptionsBlock");
                if (optionsBlock) {
                    optionsBlock.style.display = optionsBlock.style.display === 'none' ? 'block' : 'none';
                }
            });
        }
    });

    document.querySelectorAll("div.deactivate_all a").forEach(function (el) {
        el.addEventListener("click", function () {
            pwgConfirm({
                title: deactivateAllMsg,
                buttons: {
                    confirm: {
                        text: confirmMsg,
                        btnClass: "btn-red",
                        action: function () {
                            _deactivateTotal = 0;
                            _deactivateDone = 0;
                            _deactivateQueue = Promise.resolve();
                            document.querySelectorAll("div.active").forEach(function (el) {
                                performPluginDeactivate(el.id);
                            });
                        },
                    },
                    cancel: {
                        text: cancelMsg,
                    },
                },
            });
        });
    });

    /* incompatible plugins */
    fetch("admin.php?" + new URLSearchParams({ page: "plugins_installed", incompatible_plugins: true }))
    .then(function(r) { return r.json(); })
    .then(function(data) {
        for (var i = 0; i < data.length; i++) {
            if (show_details) {
                var pluginName = document.querySelector("#" + data[i] + " .pluginName");
                if (pluginName) {
                    pluginName.insertAdjacentHTML('afterbegin', '<a class="warning" title="' + incompatibleMsg + '"></a>');
                }
            } else {
                var pluginName = document.querySelector("#" + data[i] + " .pluginName");
                if (pluginName) {
                    pluginName.insertAdjacentHTML('afterbegin', '<span class="warning" title="' + incompatibleMsg + '"></span>');
                }
            }
            var el = document.getElementById(data[i]);
            if (el) {
                el.classList.add("incompatible");
                el.querySelectorAll(".activate").forEach(function (activateBtn) {
                    pwgConfirmFollowHref(activateBtn, {
                        alert_title: incompatibleMsg + activateMsg,
                        alert_confirm: confirmMsg,
                        alert_cancel: cancelMsg,
                    });
                });
            }
        }
        tippy('.warning', { delay: 0, maxWidth: 250, placement: 'top' });
    });

    tippy('.fullInfo', { delay: 500, maxWidth: 300, placement: 'top' });

    // Global mouseup listener for closing plugin options
    document.addEventListener("mouseup", function (e) {
        e.stopPropagation();
        document.querySelectorAll(".pluginBox").forEach(function (el) {
            var showOptions = el.querySelector(".showOptions");
            if (showOptions && !showOptions.contains(e.target)) {
                var optionsBlock = el.querySelector(".PluginOptionsBlock");
                if (optionsBlock) optionsBlock.style.display = 'none';
            }
        });
    });
}

initModule(init);
