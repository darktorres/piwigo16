(function (exports) {
    "use strict";

    /**
     * Base LocalStorage cache
     *
     * @param options {object}
     *    - key (required) identifier of the collection
     *    - serverId (recommended) identifier of the Piwigo instance
     *    - serverKey (required) state of collection server-side
     *    - lifetime (optional) cache lifetime in seconds
     *    - loader (required) function called to fetch data, takes a callback as first argument
     *        which must be called with the loaded date
     */
    var LocalStorageCache = function (options) {
        this._init(options);
    };

    /*
     * Constructor (deported for easy inheritance)
     */
    LocalStorageCache.prototype._init = function (options) {
        this.key = options.key + "_" + options.serverId;
        this.serverKey = options.serverKey;
        this.lifetime = options.lifetime
            ? options.lifetime * 1000
            : 3600 * 1000;
        this.loader = options.loader;

        this.storage = window.localStorage;
        this.ready = !!this.storage;
    };

    /*
     * Get the cache content
     * @param callback {function} called with the data as first parameter
     */
    LocalStorageCache.prototype.get = function (callback) {
        var now = new Date().getTime(),
            that = this;

        if (this.ready && this.storage[this.key] != undefined) {
            var cache = JSON.parse(this.storage[this.key]);

            if (
                now - cache.timestamp <= this.lifetime &&
                cache.key == this.serverKey
            ) {
                callback(cache.data);
                return;
            }
        }

        this.loader(function (data) {
            that.set.call(that, data);
            callback(data);
        });
    };

    /*
     * Manually set the cache content
     * @param data {mixed}
     */
    LocalStorageCache.prototype.set = function (data) {
        try {
            if (this.ready) {
                this.storage[this.key] = JSON.stringify({
                    timestamp: new Date().getTime(),
                    key: this.serverKey,
                    data: data,
                });
            }
        } catch (e) {
            console.log("Local storage error:");
            console.log(e);
            console.log("Use of direct result from Piwigo API.");
        }
    };

    /*
     * Manually clear the cache
     */
    LocalStorageCache.prototype.clear = function () {
        if (this.ready) {
            this.storage.removeItem(this.key);
        }
    };

    /**
     * Abstract class containing common initialization code for TomSelect
     */
    var AbstractSelectizer = function () {};
    AbstractSelectizer.prototype = new LocalStorageCache({});

    /*
     * Load TomSelect with cache content
     * @param els {Element|NodeList|Array} native DOM element(s)
     * @param options {object}
     *    - value (optional) list of preselected items (ids, or objects with "id" attribute")
     *    - default (optional) default value which will be forced if the select is emptied
     *    - create (optional) allow item user creation
     *    - filter (optional) function called for each select before applying the data
     *      takes two parameters: cache data, options
     *      must return new data
     */
    AbstractSelectizer.prototype._selectize = function (
        els,
        globalOptions,
    ) {
        if (!Array.isArray(els)) {
            els = els instanceof NodeList ? Array.from(els) : [els];
        }

        var that = this;
        els.forEach(function (el) { el._pwgCache = that; });

        this.get(function (data) {
            els.forEach(function (el) {
                var ts = el.tomselect;
                if (!ts) return;

                var filtered;
                var value;
                var defaultValue;
                var options = Object.assign({}, globalOptions);

                // apply filter function
                if (options.filter != undefined) {
                    filtered = options.filter.call(el, data, options);
                } else {
                    filtered = data;
                }

                ts.settings.maxOptions = filtered.length + 100;

                // active creation mode
                if (el.hasAttribute("data-create")) {
                    options.create = true;
                }
                ts.settings.create = !!options.create;

                // load options
                if (Object.keys(ts.options).length === 0) {
                    ts.addOptions(filtered);
                }

                // load items
                if ((value = el.dataset.value)) {
                    value = JSON.parse(value);
                    options.value = value;
                }
                if (options.value != undefined) {
                    (Array.isArray(options.value) ? options.value : [options.value]).forEach(function (cat) {
                        var n = parseFloat(cat);
                        if (!isNaN(n) && isFinite(cat)) ts.addItem(cat);
                        else ts.addItem(cat.id);
                    });
                }

                // set default
                if ((defaultValue = el.dataset['default'])) {
                    options.default = defaultValue;
                }
                if (options.default == "first") {
                    options.default = filtered[0] ? filtered[0].id : undefined;
                }

                if (options.default != undefined) {
                    // add default item
                    if (ts.getValue() == "") {
                        ts.addItem(options.default);
                    }

                    // if multiple: prevent item deletion
                    if (el.multiple) {
                        var removeBtn = ts.getItem(options.default).querySelector(".remove");
                        if (removeBtn) removeBtn.style.display = 'none';

                        ts.on("item_remove", function (id) {
                            if (id == options.default) {
                                this.addItem(id);
                                var btn = this.getItem(id).querySelector(".remove");
                                if (btn) btn.style.display = 'none';
                            }
                        });
                    }
                    // if single: restore default on blur
                    else {
                        ts.on("dropdown_close", function () {
                            if (this.getValue() == "") {
                                this.addItem(options.default);
                            }
                        });
                    }
                }
            });
        });
    };

    // redefine TomSelect render templates without escape
    AbstractSelectizer.getRender = function (field_label, lang) {
        lang = lang || { Add: "Add" };

        return {
            option: function (data, escape) {
                return '<div class="option">' + data[field_label] + "</div>";
            },
            item: function (data, escape) {
                return '<div class="item">' + data[field_label] + "</div>";
            },
            option_create: function (data, escape) {
                return (
                    '<div class="create">' +
                    lang["Add"] +
                    " <strong>" +
                    data.input +
                    "</strong>&hellip;</div>"
                );
            },
        };
    };

    /**
     * Special LocalStorage for admin categories list
     *
     * @param options {object}
     *    - serverId (recommended) identifier of the Piwigo instance
     *    - serverKey (required) state of collection server-side
     *    - rootUrl (required) used for WS call
     */
    var CategoriesCache = function (options) {
        options.key = "categoriesAdminList";

        options.loader = function (callback) {
            fetch(options.rootUrl + "ws.php?format=json&method=pwg.categories.getAdminList")
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var cats = data.result.categories.map(function (c, i) {
                        c.pos = i;
                        delete c["comment"];
                        delete c["uppercats"];
                        return c;
                    });
                    callback(cats);
                });
        };

        this._init(options);
    };

    CategoriesCache.prototype = new AbstractSelectizer();

    /*
     * Init TomSelect with cache content
     * @param target {Element|NodeList|Array} native DOM element(s)
     * @see AbstractSelectizer._selectize
     */
    CategoriesCache.prototype.selectize = function (target, options) {
        options = options || {};
        var els = target instanceof NodeList ? Array.from(target) : (Array.isArray(target) ? target : [target]);

        els.forEach(function (el) {
            new TomSelect(el, {
                valueField: "id",
                labelField: "fullname",
                sortField: "pos",
                searchField: ["fullname"],
                plugins: ["remove_button"],
                render: AbstractSelectizer.getRender("fullname", options.lang),
            });
        });

        this._selectize(els, options);
    };

    /**
     * Special LocalStorage for admin tags list
     *
     * @param options {object}
     *    - serverId (recommended) identifier of the Piwigo instance
     *    - serverKey (required) state of collection server-side
     *    - rootUrl (required) used for WS call
     */
    var TagsCache = function (options) {
        options.key = "tagsAdminList";

        options.loader = function (callback) {
            fetch(options.rootUrl + "ws.php?format=json&method=pwg.tags.getAdminList")
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var tags = data.result.tags.map(function (t) {
                        t.id = "~~" + t.id + "~~";
                        delete t["url_name"];
                        delete t["lastmodified"];
                        return t;
                    });
                    callback(tags);
                });
        };

        this._init(options);
    };

    TagsCache.prototype = new AbstractSelectizer();

    /*
     * Init TomSelect with cache content
     * @param target {Element|NodeList|Array} native DOM element(s)
     * @see AbstractSelectizer._selectize
     */
    TagsCache.prototype.selectize = function (target, options) {
        options = options || {};
        var els = target instanceof NodeList ? Array.from(target) : (Array.isArray(target) ? target : [target]);

        els.forEach(function (el) {
            new TomSelect(el, {
                valueField: "id",
                labelField: "name",
                sortField: "name",
                searchField: ["name"],
                plugins: ["remove_button"],
                render: AbstractSelectizer.getRender("name", options.lang),
            });
        });

        this._selectize(els, options);
    };

    /**
     * Special LocalStorage for admin groups list
     *
     * @param options {object}
     *    - serverId (recommended) identifier of the Piwigo instance
     *    - serverKey (required) state of collection server-side
     *    - rootUrl (required) used for WS call
     */
    var GroupsCache = function (options) {
        options.key = "groupsAdminList";

        options.loader = function (callback) {
            fetch(options.rootUrl + "ws.php?format=json&method=pwg.groups.getList&per_page=9999")
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var groups = data.result.groups.map(function (g) {
                        delete g["lastmodified"];
                        return g;
                    });
                    callback(groups);
                });
        };

        this._init(options);
    };

    GroupsCache.prototype = new AbstractSelectizer();

    /*
     * Init TomSelect with cache content
     * @param target {Element|NodeList|Array} native DOM element(s)
     * @see AbstractSelectizer._selectize
     */
    GroupsCache.prototype.selectize = function (target, options) {
        options = options || {};
        var els = target instanceof NodeList ? Array.from(target) : (Array.isArray(target) ? target : [target]);

        els.forEach(function (el) {
            new TomSelect(el, {
                valueField: "id",
                labelField: "name",
                sortField: "name",
                searchField: ["name"],
                plugins: ["remove_button"],
                render: AbstractSelectizer.getRender("name", options.lang),
            });
        });

        this._selectize(els, options);
    };

    /**
     * Special LocalStorage for admin users list
     *
     * @param options {object}
     *    - serverId (recommended) identifier of the Piwigo instance
     *    - serverKey (required) state of collection server-side
     *    - rootUrl (required) used for WS call
     */
    var UsersCache = function (options) {
        options.key = "usersAdminList";

        options.loader = function (callback) {
            var users = [];

            // recursive loader
            (function load(page) {
                fetch(
                    options.rootUrl +
                        "ws.php?format=json&method=pwg.users.getList&display=username&per_page=9999&page=" +
                        page,
                )
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        users = users.concat(data.result.users);

                        if (
                            data.result.paging.count ==
                            data.result.paging.per_page
                        ) {
                            load(++page);
                        } else {
                            callback(users);
                        }
                    });
            })(0);
        };

        this._init(options);
    };

    UsersCache.prototype = new AbstractSelectizer();

    /*
     * Init TomSelect with cache content
     * @param target {Element|NodeList|Array} native DOM element(s)
     * @see AbstractSelectizer._selectize
     */
    UsersCache.prototype.selectize = function (target, options) {
        options = options || {};
        var els = target instanceof NodeList ? Array.from(target) : (Array.isArray(target) ? target : [target]);

        els.forEach(function (el) {
            new TomSelect(el, {
                valueField: "id",
                labelField: "username",
                sortField: "username",
                searchField: ["username"],
                plugins: ["remove_button"],
                render: AbstractSelectizer.getRender("username", options.lang),
            });
        });

        this._selectize(els, options);
    };

    /**
     * Expose classes in global scope
     */
    exports.LocalStorageCache = LocalStorageCache;
    exports.CategoriesCache = CategoriesCache;
    exports.TagsCache = TagsCache;
    exports.GroupsCache = GroupsCache;
    exports.UsersCache = UsersCache;
})(window);
