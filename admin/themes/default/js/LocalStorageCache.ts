export interface CacheItem {
    id: number | string;
    [key: string]: unknown;
}

export interface CacheOptions<T = unknown> {
    key: string;
    serverId: string | number;
    serverKey: string;
    lifetime?: number;
    loader: (callback: (data: T) => void) => void;
}

interface CacheEnvelope<T> {
    timestamp: number;
    key: string;
    data: T;
}

export interface SelectizerOptions {
    value?: Array<number | string | CacheItem>;
    default?: number | string | 'first';
    create?: boolean;
    filter?: (this: HTMLSelectElement, data: CacheItem[], options: SelectizerOptions) => CacheItem[];
    lang?: { Add: string };
}

interface SelectizedElement extends HTMLSelectElement {
    selectize: {
        settings: { maxOptions: number; create: boolean };
        load(cb: (callback: (items: CacheItem[]) => void) => void): void;
        options: Record<string, unknown>;
        addItem(id: number | string): void;
        getItem(id: number | string): JQuery;
        getValue(): string;
        on(event: 'item_remove', cb: (id: number | string) => void): void;
        on(event: 'dropdown_close', cb: () => void): void;
    };
    multiple: boolean;
}

export interface AdminListCacheOptions {
    serverId: string | number;
    serverKey: string;
    rootUrl: string;
}

export class LocalStorageCache<T = CacheItem[]> {
    protected key: string;
    protected serverKey: string;
    protected lifetime: number;
    protected loader: CacheOptions<T>['loader'];
    protected storage: Storage;
    protected ready: boolean;

    constructor(options: CacheOptions<T>) {
        this.key = `${options.key}_${options.serverId}`;
        this.serverKey = options.serverKey;
        this.lifetime = options.lifetime ? options.lifetime * 1000 : 3600 * 1000;
        this.loader = options.loader;
        this.storage = window.localStorage;
        this.ready = !!this.storage;
    }

    get(callback: (data: T) => void): void {
        const now = new Date().getTime();
        if (this.ready && this.storage[this.key] !== undefined) {
            const cache = JSON.parse(this.storage[this.key]) as CacheEnvelope<T>;
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

    set(data: T): void {
        try {
            if (this.ready) {
                const envelope: CacheEnvelope<T> = {
                    timestamp: new Date().getTime(),
                    key: this.serverKey,
                    data,
                };
                this.storage[this.key] = JSON.stringify(envelope);
            }
        } catch (e) {
            console.log('Local storage error:', e);
            console.log('Use of direct result from Piwigo API.');
        }
    }

    clear(): void {
        if (this.ready) this.storage.removeItem(this.key);
    }
}

export abstract class AbstractSelectizer extends LocalStorageCache<CacheItem[]> {
    protected _selectize($target: JQuery<SelectizedElement>, globalOptions: SelectizerOptions): void {
        $target.data('cache', this);
        this.get((data) => {
            $target.each(function () {
                const el = this as SelectizedElement;
                const options: SelectizerOptions = $.extend({}, globalOptions);
                const filtered = options.filter ? options.filter.call(el, data, options) : data;

                el.selectize.settings.maxOptions = filtered.length + 100;
                if (el.hasAttribute('data-create')) options.create = true;
                el.selectize.settings.create = !!options.create;

                el.selectize.load((cb) => {
                    if ($.isEmptyObject(el.selectize.options)) cb(filtered);
                });

                const dataValue = $(el).data('value') as typeof options.value;
                if (dataValue) options.value = dataValue;
                if (options.value !== undefined) {
                    $.each(options.value, (_i, cat) => {
                        if ($.isNumeric(cat as string | number)) el.selectize.addItem(cat as number);
                        else el.selectize.addItem((cat as CacheItem).id);
                    });
                }

                const dataDefault = $(el).data('default') as typeof options.default;
                if (dataDefault) options.default = dataDefault;
                if (options.default === 'first') options.default = filtered[0] ? filtered[0].id : undefined;

                if (options.default !== undefined) {
                    if (el.selectize.getValue() === '') el.selectize.addItem(options.default as string | number);
                    if (el.multiple) {
                        el.selectize.getItem(options.default as string | number).find('.remove').hide();
                        el.selectize.on('item_remove', (id) => {
                            if (id === options.default) {
                                el.selectize.addItem(id);
                                el.selectize.getItem(id).find('.remove').hide();
                            }
                        });
                    } else {
                        el.selectize.on('dropdown_close', () => {
                            if (el.selectize.getValue() === '') el.selectize.addItem(options.default!);
                        });
                    }
                }
            });
        });
    }

    static getRender(fieldLabel: string, lang: { Add: string } = { Add: 'Add' }) {
        return {
            option: (data: Record<string, string>) => `<div class="option">${data[fieldLabel]}</div>`,
            item: (data: Record<string, string>) => `<div class="item">${data[fieldLabel]}</div>`,
            option_create: (data: { input: string }) =>
                `<div class="create">${lang.Add} <strong>${data.input}</strong>&hellip;</div>`,
        };
    }
}

export class CategoriesCache extends AbstractSelectizer {
    constructor(options: AdminListCacheOptions) {
        super({
            ...options,
            key: 'categoriesAdminList',
            loader: (callback) => {
                $.getJSON(`${options.rootUrl}ws.php?format=json&method=pwg.categories.getAdminList`,
                    (data: { result: { categories: CacheItem[] } }) => {
                        const cats = data.result.categories.map((c, i) => {
                            (c as any).pos = i;
                            delete (c as any)['comment'];
                            delete (c as any)['uppercats'];
                            return c;
                        });
                        callback(cats);
                    }
                );
            },
        });
    }

    selectize($target: JQuery<SelectizedElement>, options: SelectizerOptions = {}): void {
        $target.selectize({
            valueField: 'id', labelField: 'fullname', sortField: 'pos', searchField: ['fullname'],
            plugins: ['remove_button'],
            render: AbstractSelectizer.getRender('fullname', options.lang),
        });
        this._selectize($target, options);
    }
}

export class TagsCache extends AbstractSelectizer {
    constructor(options: AdminListCacheOptions) {
        super({
            ...options,
            key: 'tagsAdminList',
            loader: (callback) => {
                $.getJSON(`${options.rootUrl}ws.php?format=json&method=pwg.tags.getAdminList`,
                    (data: { result: { tags: CacheItem[] } }) => {
                        const tags = data.result.tags.map((t) => {
                            t.id = `~~${t.id}~~`;
                            delete (t as any)['url_name'];
                            delete (t as any)['lastmodified'];
                            return t;
                        });
                        callback(tags);
                    }
                );
            },
        });
    }

    selectize($target: JQuery<SelectizedElement>, options: SelectizerOptions = {}): void {
        $target.selectize({
            valueField: 'id', labelField: 'name', sortField: 'name', searchField: ['name'],
            plugins: ['remove_button'],
            render: AbstractSelectizer.getRender('name', options.lang),
        });
        this._selectize($target, options);
    }
}

export class GroupsCache extends AbstractSelectizer {
    constructor(options: AdminListCacheOptions) {
        super({
            ...options,
            key: 'groupsAdminList',
            loader: (callback) => {
                $.getJSON(`${options.rootUrl}ws.php?format=json&method=pwg.groups.getList&per_page=9999`,
                    (data: { result: { groups: CacheItem[] } }) => {
                        const groups = data.result.groups.map((g) => {
                            delete (g as any)['lastmodified'];
                            return g;
                        });
                        callback(groups);
                    }
                );
            },
        });
    }

    selectize($target: JQuery<SelectizedElement>, options: SelectizerOptions = {}): void {
        $target.selectize({
            valueField: 'id', labelField: 'name', sortField: 'name', searchField: ['name'],
            plugins: ['remove_button'],
            render: AbstractSelectizer.getRender('name', options.lang),
        });
        this._selectize($target, options);
    }
}

export class UsersCache extends AbstractSelectizer {
    constructor(options: AdminListCacheOptions) {
        super({
            ...options,
            key: 'usersAdminList',
            loader: (callback) => {
                let users: CacheItem[] = [];
                const load = (page: number): void => {
                    $.getJSON(
                        `${options.rootUrl}ws.php?format=json&method=pwg.users.getList&display=username&per_page=9999&page=${page}`,
                        (data: { result: { users: CacheItem[]; paging: { count: number; per_page: number } } }) => {
                            users = users.concat(data.result.users);
                            if (data.result.paging.count === data.result.paging.per_page) load(page + 1);
                            else callback(users);
                        }
                    );
                };
                load(0);
            },
        });
    }

    selectize($target: JQuery<SelectizedElement>, options: SelectizerOptions = {}): void {
        $target.selectize({
            valueField: 'id', labelField: 'username', sortField: 'username', searchField: ['username'],
            plugins: ['remove_button'],
            render: AbstractSelectizer.getRender('username', options.lang),
        });
        this._selectize($target, options);
    }
}

window.LocalStorageCache = LocalStorageCache as any;
window.CategoriesCache = CategoriesCache as any;
window.TagsCache = TagsCache as any;
window.GroupsCache = GroupsCache as any;
window.UsersCache = UsersCache as any;
