import TomSelect from 'tom-select';
import { config } from './config';

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
    filter?: (
        this: HTMLSelectElement,
        data: CacheItem[],
        options: SelectizerOptions
    ) => CacheItem[];
    lang?: { Add: string };
}

export interface AdminListCacheOptions {
    serverId: string | number;
    serverKey: string;
    rootUrl: string;
    wsUrl?: string;
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
        this.lifetime = options.lifetime !== undefined ? options.lifetime * 1000 : 3600 * 1000;
        this.loader = options.loader;
        this.storage = window.localStorage;
        this.ready = true;
    }

    get(callback: (data: T) => void): void {
        const now = new Date().getTime();
        const stored = this.storage.getItem(this.key);
        if (this.ready && stored !== null) {
            const cache = JSON.parse(stored) as CacheEnvelope<T>;
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
                this.storage.setItem(
                    this.key,
                    JSON.stringify({
                        timestamp: new Date().getTime(),
                        key: this.serverKey,
                        data,
                    })
                );
            }
        } catch (e) {
            console.error('Local storage error:', e);
        }
    }

    clear(): void {
        if (this.ready) this.storage.removeItem(this.key);
    }
}

type CachedSelect = HTMLSelectElement & {
    _cache?: AbstractSelectizer;
    _tomSelect?: TomSelect;
};

export abstract class AbstractSelectizer extends LocalStorageCache<CacheItem[]> {
    protected _selectize(target: HTMLSelectElement | null, globalOptions: SelectizerOptions): void {
        if (target === null) return;
        (target as CachedSelect)._cache = this;
        this.get((data) => {
            const options = { ...globalOptions };
            const filtered =
                options.filter !== undefined ? options.filter.call(target, data, options) : data;
            const ts = (target as CachedSelect)._tomSelect;
            if (ts === undefined) return;

            ts.settings.maxOptions = filtered.length + 100;
            if (target.hasAttribute('data-create')) options.create = true;
            ts.settings.create = Boolean(options.create);

            if (Object.keys(ts.options).length === 0) ts.addOptions(filtered);

            const rawValue = target.dataset['value'];
            const dataValue =
                rawValue !== undefined && rawValue !== ''
                    ? (JSON.parse(rawValue) as Array<number | string | CacheItem>)
                    : undefined;
            if (dataValue !== undefined) options.value = dataValue;
            if (options.value !== undefined) {
                (Array.isArray(options.value) ? options.value : [options.value]).forEach((cat) => {
                    const id =
                        typeof cat === 'object' && cat !== null && 'id' in cat ? cat.id : cat;
                    if (!isNaN(Number(id))) ts.addItem(String(id), true);
                    else ts.addItem(String(id), true);
                });
            }

            const dataDefault = target.dataset['default'];
            if (dataDefault !== undefined) options.default = dataDefault;
            if (options.default === 'first')
                options.default = filtered[0] !== undefined ? filtered[0].id : undefined;

            if (options.default !== undefined) {
                if (ts.getValue() === '') ts.addItem(String(options.default), true);
                if (target.multiple) {
                    const defaultItem = ts.getItem(String(options.default));
                    if (defaultItem)
                        defaultItem.querySelector<HTMLElement>('.remove')!.style.display = 'none';
                    ts.on('item_remove', (id: string) => {
                        if (id === String(options.default)) {
                            ts.addItem(id, true);
                            const item = ts.getItem(id);
                            if (item)
                                item.querySelector<HTMLElement>('.remove')!.style.display = 'none';
                        }
                    });
                } else {
                    ts.on('dropdown_close', () => {
                        if (ts.getValue() === '') ts.addItem(String(options.default!), true);
                    });
                }
            }
        });
    }

    static getRender(fieldLabel: string, lang: { Add: string } = { Add: 'Add' }) {
        return {
            option: (data: Record<string, string>) =>
                `<div class="option">${data[fieldLabel]}</div>`,
            item: (data: Record<string, string>) => `<div class="item">${data[fieldLabel]}</div>`,
            option_create: (data: { input: string }) =>
                `<div class="create">${lang.Add} <strong>${data.input}</strong>&hellip;</div>`,
        };
    }
}

function makeTomSelect(
    target: HTMLSelectElement | null,
    options: Record<string, unknown>
): TomSelect | null {
    if (target === null) return null;
    const ts = new TomSelect(target, options);
    (target as CachedSelect)._tomSelect = ts;
    return ts;
}

export class CategoriesCache extends AbstractSelectizer {
    constructor(options: AdminListCacheOptions) {
        super({
            ...options,
            key: 'categoriesAdminList',
            loader: (callback) => {
                void fetch(
                    `${options.wsUrl ?? config.wsUrl}format=json&method=pwg.categories.getAdminList`
                )
                    .then((r) => r.json())
                    .then((data: { result: { categories: CacheItem[] } }) => {
                        const cats = data.result.categories.map((c, i) => {
                            (c as CacheItem & { pos: number }).pos = i;
                            delete c['comment'];
                            delete c['uppercats'];
                            return c;
                        });
                        callback(cats);
                    });
            },
        });
    }

    selectize(target: HTMLSelectElement | null, options: SelectizerOptions = {}): void {
        if (target === null) return;
        makeTomSelect(target, {
            valueField: 'id',
            labelField: 'fullname',
            sortField: 'pos',
            searchField: ['fullname'],
            plugins: { remove_button: {} },
            render: AbstractSelectizer.getRender('fullname', options.lang),
        });
        this._selectize(target, options);
    }
}

export class TagsCache extends AbstractSelectizer {
    constructor(options: AdminListCacheOptions) {
        super({
            ...options,
            key: 'tagsAdminList',
            loader: (callback) => {
                void fetch(
                    `${options.wsUrl ?? config.wsUrl}format=json&method=pwg.tags.getAdminList`
                )
                    .then((r) => r.json())
                    .then((data: { result: { tags: CacheItem[] } }) => {
                        const tags = data.result.tags.map((t) => {
                            t.id = `~~${String(t.id)}~~`;
                            delete t['url_name'];
                            delete t['lastmodified'];
                            return t;
                        });
                        callback(tags);
                    });
            },
        });
    }

    selectize(target: HTMLSelectElement | null, options: SelectizerOptions = {}): void {
        if (target === null) return;
        makeTomSelect(target, {
            valueField: 'id',
            labelField: 'name',
            sortField: 'name',
            searchField: ['name'],
            plugins: { remove_button: {} },
            render: AbstractSelectizer.getRender('name', options.lang),
        });
        this._selectize(target, options);
    }
}

export class GroupsCache extends AbstractSelectizer {
    constructor(options: AdminListCacheOptions) {
        super({
            ...options,
            key: 'groupsAdminList',
            loader: (callback) => {
                void fetch(
                    `${options.wsUrl ?? config.wsUrl}format=json&method=pwg.groups.getList&per_page=9999`
                )
                    .then((r) => r.json())
                    .then((data: { result: { groups: CacheItem[] } }) => {
                        const groups = data.result.groups.map((g) => {
                            delete g['lastmodified'];
                            return g;
                        });
                        callback(groups);
                    });
            },
        });
    }

    selectize(target: HTMLSelectElement | null, options: SelectizerOptions = {}): void {
        if (target === null) return;
        makeTomSelect(target, {
            valueField: 'id',
            labelField: 'name',
            sortField: 'name',
            searchField: ['name'],
            plugins: { remove_button: {} },
            render: AbstractSelectizer.getRender('name', options.lang),
        });
        this._selectize(target, options);
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
                    void fetch(
                        `${options.wsUrl ?? config.wsUrl}format=json&method=pwg.users.getList&display=username&per_page=9999&page=${page}`
                    )
                        .then((r) => r.json())
                        .then(
                            (data: {
                                result: {
                                    users: CacheItem[];
                                    paging: { count: number; per_page: number };
                                };
                            }) => {
                                users = users.concat(data.result.users);
                                if (data.result.paging.count === data.result.paging.per_page)
                                    load(page + 1);
                                else callback(users);
                            }
                        );
                };
                load(0);
            },
        });
    }

    selectize(target: HTMLSelectElement | null, options: SelectizerOptions = {}): void {
        if (target === null) return;
        makeTomSelect(target, {
            valueField: 'id',
            labelField: 'username',
            sortField: 'username',
            searchField: ['username'],
            plugins: { remove_button: {} },
            render: AbstractSelectizer.getRender('username', options.lang),
        });
        this._selectize(target, options);
    }
}
