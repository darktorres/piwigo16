// Real entity shapes for the 4 real per-list caches below, via the
// existing OpenAPI schema.
//
// `CategoryAdmin` duplicates album_selector.ts's own identically-named,
// identically-defined type alias -- until that file's own P48 module
// conversion (docs/PLAN.md), its non-exported `type CategoryAdmin` was
// a real ambient global visible here too, so this file didn't need its
// own copy; a second declaration would have been a real
// duplicate-identifier conflict. Now that album_selector.ts is a real
// module, its own types are module-private, so this file needs its own
// local copy -- a pure type alias, safe to duplicate.
import { ajax } from "../../../default/js/vendor/ajax";
import { data, setData } from "../../../default/js/vendor/dom";
import {
  getSelectizeInstance,
  selectize as createSelectize,
  type SelectizeRenderers,
} from "../../../default/js/vendor/selectize";
import type { operations } from "../../../../openapi/client/schema";

type CategoryAdmin =
  operations["categoryList"]["responses"][200]["content"]["application/json"]["categories"][number];
type TagAdmin =
  operations["tagList"]["responses"][200]["content"]["application/json"]["tags"][number];
type GroupEntity =
  operations["groupList"]["responses"][200]["content"]["application/json"]["groups"][number];
export type UserEntity =
  operations["userList"]["responses"][200]["content"]["application/json"]["users"][number];

// This file's own `categoriesAdminList`/`tagsAdminList`/`groupsAdminList`/
// `usersAdminList` loaders each reshape the raw API row before caching
// it (see each *Cache's own `options.loader` below) -- these are the
// real post-reshape shapes, not the raw API ones.
type ProcessedCategory = Omit<CategoryAdmin, "comment" | "uppercats"> & {
  pos: number;
};
type ProcessedTag = Omit<TagAdmin, "id" | "urlName" | "lastmodified"> & {
  id: string;
};
type ProcessedGroup = Omit<GroupEntity, "lastmodified">;

interface LocalStorageCacheOptions {
  key?: string;
  serverId?: string;
  serverKey?: string;
  rootUrl?: string;
  lifetime?: number;
  loader?: (callback: (data: SelectizeEntity[]) => void) => void;
}

// The real common structural contract `_selectize` (below) relies on --
// every one of the 4 real entity shapes (Category/Tag/Group/User) has
// a real `.id`, plus whatever other fields the render/filter functions
// (each *Cache's own `.selectize()`, `AbstractSelectizer.getRender()`)
// dynamically look up by field name.
interface SelectizeEntity extends Record<string, unknown> {
  id: string | number;
}

interface LocalStorageCacheInstance {
  key: string;
  serverKey?: string | undefined;
  lifetime: number;
  loader: (callback: (data: SelectizeEntity[]) => void) => void;
  storage: Storage;
  ready: boolean;
  _init(options: LocalStorageCacheOptions): void;
  get(callback: (data: SelectizeEntity[]) => void): void;
  set(data: SelectizeEntity[]): void;
  clear(): void;
}

interface LocalStorageCacheCtor {
  new (options: LocalStorageCacheOptions): LocalStorageCacheInstance;
  prototype: LocalStorageCacheInstance;
}

// The options a real call site passes to a Cache's own `.selectize()` --
// distinct from `vendor/selectize.ts`'s own `SelectizeOptions` (the
// low-level widget-init options), imported below under its real name.
interface EntitySelectizeCallOptions {
  value?: (string | number)[] | { id: string | number }[];
  default?: string | number | undefined;
  create?: boolean;
  lang?: { Add?: string };
  filter?: (
    data: SelectizeEntity[],
    options: EntitySelectizeCallOptions,
  ) => SelectizeEntity[];
}

interface AbstractSelectizerInstance extends LocalStorageCacheInstance {
  _selectize(
    targets: Element | ArrayLike<Element>,
    globalOptions: EntitySelectizeCallOptions,
  ): void;
}

interface AbstractSelectizerCtor {
  new (): AbstractSelectizerInstance;
  prototype: AbstractSelectizerInstance;
  getRender<U extends Record<string, unknown>>(
    field_label: string,
    lang: { Add?: string } | undefined,
  ): Required<SelectizeRenderers<U>>;
}

export interface EntityCacheInstance<
  T extends SelectizeEntity,
> extends AbstractSelectizerInstance {
  loader: (callback: (data: T[]) => void) => void;
  get(callback: (data: T[]) => void): void;
  set(data: T[]): void;
  selectize(
    targets: Element | ArrayLike<Element>,
    options?: EntitySelectizeCallOptions,
  ): void;
}

interface EntityCacheCtor<T extends SelectizeEntity> {
  new (options: LocalStorageCacheOptions): EntityCacheInstance<T>;
  prototype: EntityCacheInstance<T>;
}

function toElements(target: Element | ArrayLike<Element>): HTMLSelectElement[] {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- every real call site in this file passes a real <select> (the whole file's own selectize-widget contract); no other Element is ever passed.
  return (
    target instanceof Element ? [target] : Array.from(target)
  ) as HTMLSelectElement[];
}

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
// Every `as unknown as *Ctor`/`as *Instance` cast in this file is the same
// prototype-based "class" emulation: a plain function assigned to a
// `const`, with methods hung off its `.prototype` below, cast to a real
// constructor-shaped interface TS has no structural way to verify from a
// bare function expression. Real fix is converting this file to actual
// ES6 `class`/`extends` syntax (a separate, already-scoped phase) --
// until then each cast is this same irreducible gap, not a new one.
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion
const LocalStorageCache = function (
  this: LocalStorageCacheInstance,
  options: LocalStorageCacheOptions,
) {
  this._init(options);
} as unknown as LocalStorageCacheCtor;

/*
 * Constructor (deported for easy inheritance)
 */
LocalStorageCache.prototype._init = function (
  this: LocalStorageCacheInstance,
  options: LocalStorageCacheOptions,
) {
  this.key = options.key! + "_" + options.serverId!;
  this.serverKey = options.serverKey;
  this.lifetime =
    options.lifetime !== undefined ? options.lifetime * 1000 : 3600 * 1000;
  this.loader = options.loader!;

  this.storage = window.localStorage;
  this.ready = Boolean(this.storage);
};

/*
 * Get the cache content
 * @param callback {function} called with the data as first parameter
 */
LocalStorageCache.prototype.get = function (
  this: LocalStorageCacheInstance,
  callback: (data: SelectizeEntity[]) => void,
) {
  const now = new Date().getTime(),
    // eslint-disable-next-line @typescript-eslint/no-this-alias -- pre-arrow-function idiom, needed so `this` survives into the loader's own callback below; a real behavior change (converting to a real arrow function here) is out of scope for a mechanical conversion.
    that = this;

  const stored = this.storage.getItem(this.key);
  if (this.ready && stored !== null) {
    const parsed: unknown = JSON.parse(stored);
    const cache =
      typeof parsed === "object" &&
      parsed !== null &&
      "timestamp" in parsed &&
      typeof parsed.timestamp === "number" &&
      "data" in parsed &&
      Array.isArray(parsed.data)
        ? // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- self-write/self-read cache (this same code wrote it via set() below); validating the shape/timestamp/array-ness above already rules out a foreign or corrupted entry, deep-validating every cached entity's own fields would be real over-engineering for a client-side invalidation check.
          (parsed as {
            timestamp: number;
            key?: string;
            data: SelectizeEntity[];
          })
        : { timestamp: 0, data: [] };

    if (
      now - cache.timestamp <= this.lifetime &&
      cache.key === this.serverKey
    ) {
      callback(cache.data);
      return;
    }
  }

  this.loader(function (entities) {
    that.set.call(that, entities);
    callback(entities);
  });
};

/*
 * Manually set the cache content
 * @param entities {mixed}
 */
LocalStorageCache.prototype.set = function (
  this: LocalStorageCacheInstance,
  entities: SelectizeEntity[],
) {
  try {
    if (this.ready) {
      this.storage.setItem(
        this.key,
        JSON.stringify({
          timestamp: new Date().getTime(),
          key: this.serverKey,
          data: entities,
        }),
      );
    }
  } catch (e) {
    console.error(
      "Local storage error:",
      e,
      "Use of direct result from Piwigo API.",
    );
  }
};

/*
 * Manually clear the cache
 */
LocalStorageCache.prototype.clear = function (this: LocalStorageCacheInstance) {
  if (this.ready) {
    this.storage.removeItem(this.key);
  }
};

/**
 * Abstract class containing common initialization code for selectize
 */
// Prototype-based base "class" with no constructor logic of its own --
// real init happens in _selectize()/_init().
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see LocalStorageCache's own leading comment.
const AbstractSelectizer = function (
  this: AbstractSelectizerInstance,
  // eslint-disable-next-line @typescript-eslint/no-empty-function -- see comment above.
) {} as unknown as AbstractSelectizerCtor;
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see LocalStorageCache's own leading comment.
AbstractSelectizer.prototype = new LocalStorageCache(
  {},
) as unknown as AbstractSelectizerInstance;

/*
 * Load Selectize with cache content
 * @param targets elements already initialized via `vendor/selectize.ts`'s
 *   own `selectize()` by the calling subclass's own `.selectize()` method
 *   (may have real `data-create`/`data-default`/`data-value` attributes)
 * @param options {object}
 *    - value (optional) list of preselected items (ids, or objects with "id" attribute")
 *    - default (optional) default value which will be forced if the select is emptyed
 *    - create (optional) allow item user creation
 *    - filter (optional) function called for each select before applying the data
 *      takes two parameters: cache data, options
 *      must return new data
 */
AbstractSelectizer.prototype._selectize = function (
  this: AbstractSelectizerInstance,
  targets: Element | ArrayLike<Element>,
  globalOptions: EntitySelectizeCallOptions,
) {
  const elements = toElements(targets);
  elements.forEach((el) => {
    setData(el, "cache", this);
  });

  this.get((cacheData) => {
    elements.forEach((el) => {
      let filtered: SelectizeEntity[];
      let value: (string | number)[] | { id: string | number }[] | undefined;
      const options = { ...globalOptions };
      const instance = getSelectizeInstance<string | number, SelectizeEntity>(
        el,
      )!;

      // apply filter function
      if (options.filter !== undefined) {
        filtered = options.filter.call(el, cacheData, options);
      } else {
        filtered = cacheData;
      }

      instance.settings.maxOptions = filtered.length + 100;

      // active creation mode
      if (el.hasAttribute("data-create")) {
        options.create = true;
      }
      instance.settings.create = Boolean(options.create);

      // load options
      instance.load(function (callback) {
        if (Object.keys(this.options).length === 0) {
          callback(filtered);
        }
      });

      // load items
      if (
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
        (value = data(el, "value") as
          (string | number)[] | { id: string | number }[] | undefined)
      ) {
        options.value = value;
      }
      if (options.value !== undefined) {
        options.value.forEach(
          (cat: string | number | { id: string | number }) => {
            if (typeof cat === "string" || typeof cat === "number") {
              instance.addItem(cat);
            } else {
              instance.addItem(cat.id);
            }
          },
        );
      }

      // set default
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      const rawDefault = data(el, "default") as string | number | undefined;
      if (rawDefault !== undefined && rawDefault !== "") {
        options.default = rawDefault;
      }
      if (options.default === "first") {
        options.default = filtered[0] ? filtered[0].id : undefined;
      }

      if (options.default !== undefined) {
        // Captured into its own const: `options.default` is a mutable
        // property TS can't narrow across the closures below.
        const capturedDefault = options.default;
        // add default item
        if (instance.getValue() === "") {
          instance.addItem(capturedDefault);
        }

        // if multiple: prevent item deletion
        if (el.multiple) {
          instance
            .getItem(capturedDefault)
            ?.querySelector<HTMLElement>(".remove")
            ?.style.setProperty("display", "none");

          instance.on("item_remove", (id) => {
            if (String(id) === String(capturedDefault)) {
              instance.addItem(id);
              instance
                .getItem(id)
                ?.querySelector<HTMLElement>(".remove")
                ?.style.setProperty("display", "none");
            }
          });
        }
        // if single: restore default on blur
        else {
          instance.on("dropdown_close", () => {
            if (instance.getValue() === "") {
              instance.addItem(capturedDefault);
            }
          });
        }
      }
    });
  });
};

// redefine Selectize templates without escape
AbstractSelectizer.getRender = function <U extends Record<string, unknown>>(
  field_label: string,
  rawLang: { Add?: string } | undefined,
) {
  const lang = rawLang ?? { Add: "Add" };

  return {
    option: function (entry: U, _escape: unknown) {
      return '<div class="option">' + String(entry[field_label]) + "</div>";
    },
    item: function (entry: U, _escape: unknown) {
      return '<div class="item">' + String(entry[field_label]) + "</div>";
    },
    option_create: function (entry: { input: string }, _escape: unknown) {
      return (
        '<div class="create">' +
        (lang["Add"] ?? "") +
        " <strong>" +
        entry.input +
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
 *    - rootUrl (required) used for the /api/v1 call
 */
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see LocalStorageCache's own leading comment.
const CategoriesCache = function (
  this: EntityCacheInstance<ProcessedCategory>,
  options: LocalStorageCacheOptions,
) {
  options.key = "categoriesAdminList";

  options.loader = function (callback) {
    void ajax({
      url: options.rootUrl! + "api/v1/categories",
      dataType: "json",
      success: function (
        response: operations["categoryList"]["responses"][200]["content"]["application/json"],
      ) {
        const cats: ProcessedCategory[] = response.categories.map(
          function (c, i) {
            const { comment: _comment, uppercats: _uppercats, ...rest } = c;
            return { ...rest, pos: i };
          },
        );

        callback(cats);
      },
    });
  };

  this._init(options);
} as unknown as EntityCacheCtor<ProcessedCategory>;

CategoriesCache.prototype =
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see LocalStorageCache's own leading comment.
  new AbstractSelectizer() as unknown as EntityCacheInstance<ProcessedCategory>;

/*
 * Init Selectize with cache content
 * @see AbstractSelectizer._selectize
 */
CategoriesCache.prototype.selectize = function (
  this: EntityCacheInstance<ProcessedCategory>,
  targets: Element | ArrayLike<Element>,
  rawOptions?: EntitySelectizeCallOptions,
) {
  const options = rawOptions ?? {};

  toElements(targets).forEach((el) => {
    createSelectize(el, {
      valueField: "id",
      labelField: "fullname",
      sortField: "pos",
      searchField: ["fullname"],
      plugins: ["remove_button"],
      render: AbstractSelectizer.getRender("fullname", options.lang),
    });
  });

  this._selectize(targets, options);
};

/**
 * Special LocalStorage for admin tags list
 *
 * @param options {object}
 *    - serverId (recommended) identifier of the Piwigo instance
 *    - serverKey (required) state of collection server-side
 *    - rootUrl (required) used for the /api/v1 call
 */
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see LocalStorageCache's own leading comment.
const TagsCache = function (
  this: EntityCacheInstance<ProcessedTag>,
  options: LocalStorageCacheOptions,
) {
  options.key = "tagsAdminList";

  options.loader = function (callback) {
    void ajax({
      url: options.rootUrl! + "api/v1/tags",
      dataType: "json",
      success: function (
        response: operations["tagList"]["responses"][200]["content"]["application/json"],
      ) {
        const tags: ProcessedTag[] = response.tags.map(function (t) {
          const { urlName: _urlName, lastmodified: _lastmodified, ...rest } = t;
          return { ...rest, id: "~~" + String(t.id) + "~~" };
        });

        callback(tags);
      },
    });
  };

  this._init(options);
} as unknown as EntityCacheCtor<ProcessedTag>;

TagsCache.prototype =
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see LocalStorageCache's own leading comment.
  new AbstractSelectizer() as unknown as EntityCacheInstance<ProcessedTag>;

/*
 * Init Selectize with cache content
 * @see AbstractSelectizer._selectize
 */
TagsCache.prototype.selectize = function (
  this: EntityCacheInstance<ProcessedTag>,
  targets: Element | ArrayLike<Element>,
  rawOptions?: EntitySelectizeCallOptions,
) {
  const options = rawOptions ?? {};

  toElements(targets).forEach((el) => {
    createSelectize(el, {
      valueField: "id",
      labelField: "name",
      sortField: "name",
      searchField: ["name"],
      plugins: ["remove_button"],
      render: AbstractSelectizer.getRender("name", options.lang),
    });
  });

  this._selectize(targets, options);
};

/**
 * Special LocalStorage for admin groups list
 *
 * @param options {object}
 *    - serverId (recommended) identifier of the Piwigo instance
 *    - serverKey (required) state of collection server-side
 *    - rootUrl (required) used for the /api/v1 call
 */
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see LocalStorageCache's own leading comment.
const GroupsCache = function (
  this: EntityCacheInstance<ProcessedGroup>,
  options: LocalStorageCacheOptions,
) {
  options.key = "groupsAdminList";

  options.loader = function (callback) {
    void ajax({
      url: options.rootUrl! + "api/v1/groups",
      dataType: "json",
      success: function (
        response: operations["groupList"]["responses"][200]["content"]["application/json"],
      ) {
        const groups: ProcessedGroup[] = response.groups.map(function (g) {
          const { lastmodified: _lastmodified, ...rest } = g;
          return rest;
        });

        callback(groups);
      },
    });
  };

  this._init(options);
} as unknown as EntityCacheCtor<ProcessedGroup>;

GroupsCache.prototype =
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see LocalStorageCache's own leading comment.
  new AbstractSelectizer() as unknown as EntityCacheInstance<ProcessedGroup>;

/*
 * Init Selectize with cache content
 * @see AbstractSelectizer._selectize
 */
GroupsCache.prototype.selectize = function (
  this: EntityCacheInstance<ProcessedGroup>,
  targets: Element | ArrayLike<Element>,
  rawOptions?: EntitySelectizeCallOptions,
) {
  const options = rawOptions ?? {};

  toElements(targets).forEach((el) => {
    createSelectize(el, {
      valueField: "id",
      labelField: "name",
      sortField: "name",
      searchField: ["name"],
      plugins: ["remove_button"],
      render: AbstractSelectizer.getRender("name", options.lang),
    });
  });

  this._selectize(targets, options);
};

/**
 * Special LocalStorage for admin users list
 *
 * @param options {object}
 *    - serverId (recommended) identifier of the Piwigo instance
 *    - serverKey (required) state of collection server-side
 *    - rootUrl (required) used for the /api/v1 call
 */
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see LocalStorageCache's own leading comment.
const UsersCache = function (
  this: EntityCacheInstance<UserEntity>,
  options: LocalStorageCacheOptions,
) {
  options.key = "usersAdminList";

  options.loader = function (callback) {
    let users: UserEntity[] = [];

    // recursive loader
    (function load(page: number) {
      void ajax({
        url:
          options.rootUrl! + "api/v1/users?perPage=9999&page=" + String(page),
        dataType: "json",
        success: function (
          response: operations["userList"]["responses"][200]["content"]["application/json"],
        ) {
          users = users.concat(response.users);

          if (response.users.length === response.perPage) {
            load(page + 1);
          } else {
            callback(users);
          }
        },
      });
    })(0);
  };

  this._init(options);
} as unknown as EntityCacheCtor<UserEntity>;

UsersCache.prototype =
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see LocalStorageCache's own leading comment.
  new AbstractSelectizer() as unknown as EntityCacheInstance<UserEntity>;

/*
 * Init Selectize with cache content
 * @see AbstractSelectizer._selectize
 */
UsersCache.prototype.selectize = function (
  this: EntityCacheInstance<UserEntity>,
  targets: Element | ArrayLike<Element>,
  rawOptions?: EntitySelectizeCallOptions,
) {
  const options = rawOptions ?? {};

  toElements(targets).forEach((el) => {
    createSelectize(el, {
      valueField: "id",
      labelField: "username",
      sortField: "username",
      searchField: ["username"],
      plugins: ["remove_button"],
      render: AbstractSelectizer.getRender("username", options.lang),
    });
  });

  this._selectize(targets, options);
};

// LocalStorageCache itself (the 4 real exports' own shared base class)
// stays module-private -- zero real consumers read it bare anywhere in
// this codebase, only these 4 real subclasses.
export { CategoriesCache, TagsCache, GroupsCache, UsersCache };
