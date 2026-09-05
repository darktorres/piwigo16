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
import { ajax, AjaxError } from "../../../default/js/vendor/utils/ajax";
import { data, setData } from "../../../default/js/vendor/utils/dom";
import {
  getSelectizeInstance,
  selectize as createSelectize,
  type SelectizeInstance,
  type SelectizeRenderers,
} from "../../../default/js/vendor/widgets/selectize";
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

// The real common structural contract `_selectize` (below) relies on --
// every one of the 4 real entity shapes (Category/Tag/Group/User) has
// a real `.id`, plus whatever other fields the render/filter functions
// (each *Cache's own `.selectize()`, `AbstractSelectizer.getRender()`)
// dynamically look up by field name.
interface SelectizeEntity extends Record<string, unknown> {
  id: string | number;
}

interface LocalStorageCacheOptions<
  T extends SelectizeEntity = SelectizeEntity,
> {
  key?: string;
  serverId?: string;
  serverKey?: string;
  rootUrl?: string;
  lifetime?: number;
  loader?: (callback: (data: T[]) => void) => void;
}

// The options a real call site passes to a Cache's own `.selectize()` --
// distinct from `vendor/widgets/selectize.ts`'s own `SelectizeOptions` (the
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
class LocalStorageCache<T extends SelectizeEntity = SelectizeEntity> {
  key: string;
  serverKey: string | undefined;
  lifetime: number;
  loader: (callback: (data: T[]) => void) => void;
  storage: Storage;
  ready: boolean;

  constructor(options: LocalStorageCacheOptions<T>) {
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real concrete subclass constructor below sets options.key before calling super(options).
    this.key = options.key! + "_" + options.serverId!;
    this.serverKey = options.serverKey;
    this.lifetime =
      options.lifetime !== undefined ? options.lifetime * 1000 : 3600 * 1000;
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real concrete subclass constructor below sets options.loader before calling super(options).
    this.loader = options.loader!;

    this.storage = window.localStorage;
    this.ready = Boolean(this.storage);
  }

  /*
   * Get the cache content
   * @param callback {function} called with the data as first parameter
   */
  get(callback: (data: T[]) => void): void {
    const now = new Date().getTime();

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
              data: T[];
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

    this.loader((entities) => {
      this.set(entities);
      callback(entities);
    });
  }

  /*
   * Manually set the cache content
   * @param entities {mixed}
   */
  set(entities: T[]): void {
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
  }

  /*
   * Manually clear the cache
   */
  clear(): void {
    if (this.ready) {
      this.storage.removeItem(this.key);
    }
  }
}

/**
 * Abstract class containing common initialization code for selectize.
 * Never instantiated directly -- only its 4 concrete subclasses below
 * are ever constructed with real options.
 */
abstract class AbstractSelectizer<
  T extends SelectizeEntity,
> extends LocalStorageCache<T> {
  /*
   * Load Selectize with cache content
   * @param targets elements already initialized via `vendor/widgets/selectize.ts`'s
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
  protected _selectize(
    targets: Element | ArrayLike<Element>,
    globalOptions: EntitySelectizeCallOptions,
  ): void {
    const elements = toElements(targets);
    elements.forEach((el) => {
      setData(el, "cache", this);
    });

    this.get((cacheData) => {
      elements.forEach((el) => {
        let filtered: SelectizeEntity[];
        const options = { ...globalOptions };
        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every el here was already selectize()-initialized by the calling subclass's own .selectize() before _selectize() runs.
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

        AbstractSelectizer._applySelectedValues(instance, el);
        AbstractSelectizer._applyDefaultValue(instance, el, filtered, options);
      });
    });
  }

  /** The "load items" step of `_selectize()` above -- preselects whatever `data-value` names. */
  private static _applySelectedValues(
    instance: SelectizeInstance<string | number, SelectizeEntity>,
    el: HTMLSelectElement,
  ): void {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
    const value = data(el, "value") as
      (string | number)[] | { id: string | number }[] | undefined;
    if (!value) {
      return;
    }

    value.forEach((cat: string | number | { id: string | number }) => {
      if (typeof cat === "string" || typeof cat === "number") {
        instance.addItem(cat);
      } else {
        instance.addItem(cat.id);
      }
    });
  }

  /** The "set default" step of `_selectize()` above. */
  private static _applyDefaultValue(
    instance: SelectizeInstance<string | number, SelectizeEntity>,
    el: HTMLSelectElement,
    filtered: SelectizeEntity[],
    options: EntitySelectizeCallOptions,
  ): void {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
    const rawDefault = data(el, "default") as string | number | undefined;
    if (rawDefault !== undefined && rawDefault !== "") {
      options.default = rawDefault;
    }
    if (options.default === "first") {
      options.default = filtered[0] ? filtered[0].id : undefined;
    }

    if (options.default === undefined) {
      return;
    }

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

  /**
   * Shared body of every real subclass's own `selectize()` (sonarjs/
   * no-identical-functions: TagsCache/GroupsCache's own copies were
   * byte-identical, and CategoriesCache/UsersCache differed only in
   * which field name they used throughout) -- `labelField` doubles as
   * `searchField`/the render field; `sortField` defaults to it too,
   * except CategoriesCache's own real "pos" sort field.
   */
  protected _selectizeWithField(
    targets: Element | ArrayLike<Element>,
    rawOptions: EntitySelectizeCallOptions | undefined,
    labelField: string,
    sortField: string = labelField,
  ): void {
    const options = rawOptions ?? {};

    toElements(targets).forEach((el) => {
      createSelectize(el, {
        valueField: "id",
        labelField,
        sortField,
        searchField: [labelField],
        plugins: ["remove_button"],
        render: AbstractSelectizer.getRender(labelField, options.lang),
      });
    });

    this._selectize(targets, options);
  }

  // redefine Selectize templates without escape
  static getRender<U extends Record<string, unknown>>(
    field_label: string,
    rawLang: { Add?: string } | undefined,
  ): Required<SelectizeRenderers<U>> {
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
          (lang.Add ?? "") +
          " <strong>" +
          entry.input +
          "</strong>&hellip;</div>"
        );
      },
    };
  }
}

/**
 * Special LocalStorage for admin categories list
 *
 * @param options {object}
 *    - serverId (recommended) identifier of the Piwigo instance
 *    - serverKey (required) state of collection server-side
 *    - rootUrl (required) used for the /api/v1 call
 */
class CategoriesCache extends AbstractSelectizer<ProcessedCategory> {
  constructor(options: LocalStorageCacheOptions<ProcessedCategory>) {
    options.key = "categoriesAdminList";

    options.loader = function (callback) {
      void (async () => {
        try {
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
          const response = (await ajax({
            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real caller of this cache always passes rootUrl.
          url: options.rootUrl! + "api/v1/categories",
            dataType: "json",
          })) as operations["categoryList"]["responses"][200]["content"]["application/json"];

          const cats: ProcessedCategory[] = response.categories.map(
            function (c, i) {
              const { comment: _comment, uppercats: _uppercats, ...rest } = c;
              return { ...rest, pos: i };
            },
          );

          callback(cats);
        } catch (e) {
          console.error(e instanceof AjaxError ? e.responseText : e);
        }
      })();
    };

    super(options);
  }

  /*
   * Init Selectize with cache content
   * @see AbstractSelectizer._selectize
   */
  selectize(
    targets: Element | ArrayLike<Element>,
    rawOptions?: EntitySelectizeCallOptions,
  ): void {
    this._selectizeWithField(targets, rawOptions, "fullname", "pos");
  }
}

/**
 * Special LocalStorage for admin tags list
 *
 * @param options {object}
 *    - serverId (recommended) identifier of the Piwigo instance
 *    - serverKey (required) state of collection server-side
 *    - rootUrl (required) used for the /api/v1 call
 */
class TagsCache extends AbstractSelectizer<ProcessedTag> {
  constructor(options: LocalStorageCacheOptions<ProcessedTag>) {
    options.key = "tagsAdminList";

    options.loader = function (callback) {
      void (async () => {
        try {
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
          const response = (await ajax({
            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real caller of this cache always passes rootUrl.
          url: options.rootUrl! + "api/v1/tags",
            dataType: "json",
          })) as operations["tagList"]["responses"][200]["content"]["application/json"];

          const tags: ProcessedTag[] = response.tags.map(function (t) {
            const {
              urlName: _urlName,
              lastmodified: _lastmodified,
              ...rest
            } = t;
            return { ...rest, id: "~~" + String(t.id) + "~~" };
          });

          callback(tags);
        } catch (e) {
          console.error(e instanceof AjaxError ? e.responseText : e);
        }
      })();
    };

    super(options);
  }

  /*
   * Init Selectize with cache content
   * @see AbstractSelectizer._selectize
   */
  selectize(
    targets: Element | ArrayLike<Element>,
    rawOptions?: EntitySelectizeCallOptions,
  ): void {
    this._selectizeWithField(targets, rawOptions, "name");
  }
}

/**
 * Special LocalStorage for admin groups list
 *
 * @param options {object}
 *    - serverId (recommended) identifier of the Piwigo instance
 *    - serverKey (required) state of collection server-side
 *    - rootUrl (required) used for the /api/v1 call
 */
class GroupsCache extends AbstractSelectizer<ProcessedGroup> {
  constructor(options: LocalStorageCacheOptions<ProcessedGroup>) {
    options.key = "groupsAdminList";

    options.loader = function (callback) {
      void (async () => {
        try {
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
          const response = (await ajax({
            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real caller of this cache always passes rootUrl.
          url: options.rootUrl! + "api/v1/groups",
            dataType: "json",
          })) as operations["groupList"]["responses"][200]["content"]["application/json"];

          const groups: ProcessedGroup[] = response.groups.map(function (g) {
            const { lastmodified: _lastmodified, ...rest } = g;
            return rest;
          });

          callback(groups);
        } catch (e) {
          console.error(e instanceof AjaxError ? e.responseText : e);
        }
      })();
    };

    super(options);
  }

  /*
   * Init Selectize with cache content
   * @see AbstractSelectizer._selectize
   */
  selectize(
    targets: Element | ArrayLike<Element>,
    rawOptions?: EntitySelectizeCallOptions,
  ): void {
    this._selectizeWithField(targets, rawOptions, "name");
  }
}

/**
 * Special LocalStorage for admin users list
 *
 * @param options {object}
 *    - serverId (recommended) identifier of the Piwigo instance
 *    - serverKey (required) state of collection server-side
 *    - rootUrl (required) used for the /api/v1 call
 */
class UsersCache extends AbstractSelectizer<UserEntity> {
  constructor(options: LocalStorageCacheOptions<UserEntity>) {
    options.key = "usersAdminList";

    options.loader = function (callback) {
      let users: UserEntity[] = [];

      // recursive loader
      void (async function load(page: number) {
        try {
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
          const response = (await ajax({
            url:
              // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real caller of this cache always passes rootUrl.
              options.rootUrl! +
              "api/v1/users?perPage=9999&page=" +
              String(page),
            dataType: "json",
          })) as operations["userList"]["responses"][200]["content"]["application/json"];

          users = users.concat(response.users);

          if (response.users.length === response.perPage) {
            await load(page + 1);
          } else {
            callback(users);
          }
        } catch (e) {
          console.error(e instanceof AjaxError ? e.responseText : e);
        }
      })(0);
    };

    super(options);
  }

  /*
   * Init Selectize with cache content
   * @see AbstractSelectizer._selectize
   */
  selectize(
    targets: Element | ArrayLike<Element>,
    rawOptions?: EntitySelectizeCallOptions,
  ): void {
    this._selectizeWithField(targets, rawOptions, "username");
  }
}

// LocalStorageCache itself (the 4 real exports' own shared base class)
// stays module-private -- zero real consumers read it bare anywhere in
// this codebase, only these 4 real subclasses.
export { CategoriesCache, TagsCache, GroupsCache, UsersCache };
