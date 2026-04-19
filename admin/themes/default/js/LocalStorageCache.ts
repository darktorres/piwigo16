import TomSelect from 'tom-select';

interface LocalStorageCacheOptions {
    key?: string;
    serverId?: string | number;
    serverKey?: string;
    lifetime?: number;
    loader?: (callback: (data: CacheItem[]) => void) => void;
    rootUrl?: string;
}

interface SelectizeOptions {
    value?: (string | number) | Array<string | number | { id: string | number }>;
    default?: string | number;
    create?: boolean;
    filter?: (this: Element, data: CacheItem[], options: SelectizeOptions) => CacheItem[];
    lang?: Record<string, string>;
}

export type CacheItem = Record<string, unknown>;

class LocalStorageCache {
    protected key: string = '';
    protected serverKey: string = '';
    protected lifetime: number = 3600 * 1000;
    protected loader: ((callback: (data: CacheItem[]) => void) => void) = () => { /* no-op until _init */ };
    protected storage: Storage = window.localStorage;
    protected ready: boolean = false;

    protected _init(options: LocalStorageCacheOptions): void {
        this.key = (options.key ?? '') + '_' + (options.serverId ?? '');
        this.serverKey = options.serverKey ?? '';
        this.lifetime = options.lifetime ? options.lifetime * 1000 : 3600 * 1000;
        this.loader = options.loader!;
        this.storage = window.localStorage;
        this.ready = !!this.storage;
    }

    get(callback: (data: CacheItem[]) => void): void {
        const now = new Date().getTime();
        if (this.ready && this.storage[this.key] !== undefined) {
            const cache = JSON.parse(this.storage[this.key]) as { timestamp: number; key: string; data: CacheItem[] };
            if (now - cache.timestamp <= this.lifetime && cache.key === this.serverKey) {
                callback(cache.data);
                return;
            }
        }
        this.loader((data) => {
            this.set(data);
            callback(data);
        });
    }

    set(data: CacheItem[]): void {
        try {
            if (this.ready) {
                this.storage[this.key] = JSON.stringify({
                    timestamp: new Date().getTime(),
                    key: this.serverKey,
                    data,
                });
            }
        } catch (e) {
            console.log("Local storage error:");
            console.log(e);
            console.log("Use of direct result from Piwigo API.");
        }
    }

    clear(): void {
        if (this.ready) this.storage.removeItem(this.key);
    }
}

type TomSelectRenderTemplates = {
    option: (data: CacheItem, escape: (s: string) => string) => string;
    item: (data: CacheItem, escape: (s: string) => string) => string;
    option_create: (data: { input: string }, escape: (s: string) => string) => string;
};

abstract class AbstractSelectizer extends LocalStorageCache {
    protected _selectize(els: HTMLSelectElement[], globalOptions: SelectizeOptions): void {
        els.forEach((el) => { (el as HTMLSelectElement & { _pwgCache?: AbstractSelectizer })._pwgCache = this; });

        this.get((data) => {
            els.forEach((el) => {
                const ts = (el as HTMLSelectElement & { tomselect?: TomSelect }).tomselect;
                if (!ts) return;

                const options: SelectizeOptions = Object.assign({}, globalOptions);
                const filtered = options.filter ? options.filter.call(el, data, options) : data;

                ts.settings.maxOptions = filtered.length + 100;
                if (el.hasAttribute("data-create")) options.create = true;
                ts.settings.create = !!options.create;

                if (Object.keys(ts.options).length === 0) ts.addOptions(filtered);

                const valueAttr = (el as HTMLElement).dataset.value;
                if (valueAttr) options.value = JSON.parse(valueAttr) as SelectizeOptions['value'];

                if (options.value !== undefined) {
                    const vals = Array.isArray(options.value) ? options.value : [options.value];
                    for (const cat of vals) {
                        const n = parseFloat(String(cat));
                        if (!isNaN(n) && isFinite(Number(cat))) ts.addItem(String(cat));
                        else ts.addItem(String((cat as { id: string | number }).id));
                    }
                }

                const defaultAttr = (el as HTMLElement).dataset['default'];
                if (defaultAttr) options.default = defaultAttr;
                if (options.default === "first") options.default = filtered[0] ? String(filtered[0].id) : undefined;

                if (options.default !== undefined) {
                    if (ts.getValue() === "") ts.addItem(String(options.default));
                    if ((el).multiple) {
                        const defaultItem = ts.getItem(String(options.default));
                        if (defaultItem) {
                            const removeBtn = defaultItem.querySelector<HTMLElement>(".remove");
                            if (removeBtn) removeBtn.style.display = 'none';
                        }
                        ts.on("item_remove", function (this: TomSelect, id: string) {
                            if (id === String(options.default)) {
                                this.addItem(id);
                                const itemEl = this.getItem(id);
                                const btn = itemEl?.querySelector<HTMLElement>(".remove");
                                if (btn) btn.style.display = 'none';
                            }
                        });
                    } else {
                        ts.on("dropdown_close", function (this: TomSelect) {
                            if (this.getValue() === "") this.addItem(String(options.default));
                        });
                    }
                }
            });
        });
    }

    static getRender(field_label: string, lang: Record<string, string> = { Add: "Add" }): TomSelectRenderTemplates {
        return {
            option: (data) => '<div class="option">' + String(data[field_label]) + "</div>",
            item: (data) => '<div class="item">' + String(data[field_label]) + "</div>",
            option_create: (data) =>
                '<div class="create">' + lang["Add"] + " <strong>" + data.input + "</strong>&hellip;</div>",
        };
    }

    abstract selectize(target: Element | NodeList | Element[], options?: SelectizeOptions): void;
}

export class CategoriesCache extends AbstractSelectizer {
    constructor(options: LocalStorageCacheOptions) {
        super();
        options.key = "categoriesAdminList";
        options.loader = (callback) => {
            fetch((options.rootUrl ?? '') + "ws.php?format=json&method=pwg.categories.getAdminList")
                .then((r) => r.json())
                .then((data: { result: { categories: CacheItem[] } }) => {
                    const cats = data.result.categories.map((c, i) => {
                        c.pos = i;
                        delete c["comment"];
                        delete c["uppercats"];
                        return c;
                    });
                    callback(cats);
                });
        };
        this._init(options);
    }

    selectize(target: Element | NodeList | Element[], options: SelectizeOptions = {}): void {
        const els: HTMLSelectElement[] = target instanceof NodeList
            ? Array.from(target) as HTMLSelectElement[]
            : (Array.isArray(target) ? target as HTMLSelectElement[] : [target as HTMLSelectElement]);
        els.forEach((el) => {
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
    }
}

export class TagsCache extends AbstractSelectizer {
    constructor(options: LocalStorageCacheOptions) {
        super();
        options.key = "tagsAdminList";
        options.loader = (callback) => {
            fetch((options.rootUrl ?? '') + "ws.php?format=json&method=pwg.tags.getAdminList")
                .then((r) => r.json())
                .then((data: { result: { tags: CacheItem[] } }) => {
                    const tags = data.result.tags.map((t) => {
                        t.id = "~~" + String(t.id) + "~~";
                        delete t["url_name"];
                        delete t["lastmodified"];
                        return t;
                    });
                    callback(tags);
                });
        };
        this._init(options);
    }

    selectize(target: Element | NodeList | Element[], options: SelectizeOptions = {}): void {
        const els: HTMLSelectElement[] = target instanceof NodeList
            ? Array.from(target) as HTMLSelectElement[]
            : (Array.isArray(target) ? target as HTMLSelectElement[] : [target as HTMLSelectElement]);
        els.forEach((el) => {
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
    }
}

export class GroupsCache extends AbstractSelectizer {
    constructor(options: LocalStorageCacheOptions) {
        super();
        options.key = "groupsAdminList";
        options.loader = (callback) => {
            fetch((options.rootUrl ?? '') + "ws.php?format=json&method=pwg.groups.getList&per_page=9999")
                .then((r) => r.json())
                .then((data: { result: { groups: CacheItem[] } }) => {
                    const groups = data.result.groups.map((g) => {
                        delete g["lastmodified"];
                        return g;
                    });
                    callback(groups);
                });
        };
        this._init(options);
    }

    selectize(target: Element | NodeList | Element[], options: SelectizeOptions = {}): void {
        const els: HTMLSelectElement[] = target instanceof NodeList
            ? Array.from(target) as HTMLSelectElement[]
            : (Array.isArray(target) ? target as HTMLSelectElement[] : [target as HTMLSelectElement]);
        els.forEach((el) => {
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
    }
}

export class UsersCache extends AbstractSelectizer {
    constructor(options: LocalStorageCacheOptions) {
        super();
        options.key = "usersAdminList";
        options.loader = (callback) => {
            const users: CacheItem[] = [];
            const load = (page: number) => {
                fetch(
                    (options.rootUrl ?? '') +
                    "ws.php?format=json&method=pwg.users.getList&display=username&per_page=9999&page=" + page,
                )
                    .then((r) => r.json())
                    .then((data: { result: { users: CacheItem[]; paging: { count: number; per_page: number } } }) => {
                        users.push(...data.result.users);
                        if (data.result.paging.count === data.result.paging.per_page) load(page + 1);
                        else callback(users);
                    });
            };
            load(0);
        };
        this._init(options);
    }

    selectize(target: Element | NodeList | Element[], options: SelectizeOptions = {}): void {
        const els: HTMLSelectElement[] = target instanceof NodeList
            ? Array.from(target) as HTMLSelectElement[]
            : (Array.isArray(target) ? target as HTMLSelectElement[] : [target as HTMLSelectElement]);
        els.forEach((el) => {
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
    }
}
