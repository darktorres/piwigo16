// jQuery-compatible ajax over fetch, for P49-A (docs/PLAN.md).
//
// 97 first-party call sites, 51 of them the static `$.ajax` form. Ported from
// node_modules/jquery/src/ajax.js -- the pinned 1.11.3 this app loads -- so
// the option names, the callback signatures and the response-conversion rules
// behave as the call sites already expect.

/** What handlers receive in place of jQuery's jqXHR. */
export interface AjaxResponse {
  status: number;
  statusText: string;
  responseText: string;
  responseJSON?: unknown;
  getResponseHeader(name: string): string | null;
}

/**
 * Rejections carry the response, because call sites read `e.responseText`
 * off it. It extends Error so it is a legitimate throw value -- jQuery
 * rejects with the bare jqXHR, which a plain object cannot model here.
 */
export class AjaxError extends Error implements AjaxResponse {
  public responseJSON?: unknown;

  readonly #headers: Headers;

  public constructor(
    public readonly status: number,
    public readonly statusText: string,
    public readonly responseText: string,
    headers: Headers,
    message: string
  ) {
    super(message);
    this.#headers = headers;
    this.name = "AjaxError";
  }

  public getResponseHeader(name: string): string | null {
    return this.#headers.get(name);
  }
}

/**
 * `T` is the response shape the caller asserts. Nothing validates it --
 * exactly as jQuery's own typings did (`success: (data: any)`), and the
 * reason the call sites can go on naming their OpenAPI response types
 * instead of casting inside every callback.
 */
export interface AjaxOptions<T = unknown> {
  url: string;
  /** jQuery accepts both spellings; `method` wins, as in 1.11.3. */
  type?: string;
  method?: string;
  data?: unknown;
  /**
   * Shorthand for the ~66 real call sites that otherwise hand-pair
   * `contentType: "application/json"` with `data: JSON.stringify({...})`.
   * Stringifies `json` and forces `contentType` to `"application/json"`,
   * same as writing both by hand. Ignored if `data` is also set --
   * no real call site needs both, so this doesn't need a precedence rule
   * beyond "don't pass both".
   */
  json?: unknown;
  /**
   * Matched case-insensitively. jQuery lowercases it
   * (`s.dataType.toLowerCase()` in `ajaxSettings`), and four call sites
   * spell it "JSON".
   */
  dataType?: string;
  contentType?: string | false;
  headers?: Record<string, string>;
  timeout?: number;
  beforeSend?: (xhr: AjaxResponse) => void;
  success?: (data: T, statusText: string, xhr: AjaxResponse) => void;
  error?: (xhr: AjaxResponse, statusText: string, errorThrown: string) => void;
  complete?: (xhr: AjaxResponse, statusText: string) => void;
}

/**
 * `jQuery.param()` for the flat objects the call sites pass. Arrays repeat
 * the key with `[]`, matching jQuery's traditional-off default; booleans
 * serialise as "true"/"false" rather than 1/0.
 */
/**
 * jQuery's own `add()` (`src/serialize.js`): a function value is invoked,
 * and `null`/`undefined` serialise as the **empty string**, not as the words
 * "null"/"undefined".
 *
 * That last rule is load-bearing rather than pedantic. Several filter
 * requests pass a bag of optional criteria and leave the unused ones
 * undefined; `String(undefined)` sends `minDate=undefined`, which the API
 * rejects with a 422.
 */
function isCallable(value: unknown): value is () => unknown {
  return typeof value === "function";
}

function paramValue(value: unknown): string {
  const resolved = isCallable(value) ? value() : value;

  if (resolved === null || resolved === undefined) {
    return "";
  }

  // Narrowed positively, one primitive at a time: excluding cases from
  // `unknown` does not narrow it, so a trailing `String(resolved)` would
  // still be stringifying `unknown`.
  switch (typeof resolved) {
    case "string":
      return resolved;
    case "number":
    case "boolean":
    case "bigint":
    case "symbol":
      return String(resolved);
    default:
      // jQuery passes the value straight to `encodeURIComponent`, which
      // stringifies it -- a plain object becomes "[object Object]". Kept as
      // jQuery has it rather than "improved" into JSON: no call site passes
      // one, and diverging would be a silent behaviour change, not a fix.
      return Object.prototype.toString.call(resolved);
  }
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return (
    typeof value === "object" && value !== null && !Array.isArray(value)
  );
}

/**
 * jQuery's own `buildParams()` (`src/serialize.js`), non-traditional mode
 * (this app never sets `ajaxSettings.traditional`): an array repeats the
 * key with empty brackets for a scalar item, or recurses with its numeric
 * index for an object/array item; a plain (non-array) object recurses with
 * each of its own keys bracketed on. Needed for real object-shaped values
 * like history.ts's own `current_param.types` (`{0: "high", 1: "other"}`,
 * a plain object, not a real array -- jQuery serialises it as
 * `types[0]=high&types[1]=other`) -- the previous version here only
 * special-cased `Array.isArray()` and fell through to `paramValue()`'s
 * `[object Object]` default for anything else, silently sending a garbage
 * query value for that shape.
 */
function buildParams(prefix: string, obj: unknown, parts: string[]): void {
  if (Array.isArray(obj)) {
    obj.forEach((item: unknown, i: number) => {
      const segment =
        typeof item === "object" && item !== null ? String(i) : "";
      buildParams(prefix + "[" + segment + "]", item, parts);
    });

    return;
  }

  if (isPlainObject(obj)) {
    for (const [name, value] of Object.entries(obj)) {
      buildParams(prefix + "[" + name + "]", value, parts);
    }

    return;
  }

  parts.push(encodeURIComponent(prefix) + "=" + encodeURIComponent(paramValue(obj)));
}

export function param(data: Record<string, unknown>): string {
  const parts: string[] = [];

  for (const [key, value] of Object.entries(data)) {
    buildParams(key, value, parts);
  }

  return parts.join("&");
}

/**
 * A thenable that also answers to jQuery's `.done()`/`.fail()`, which 10 call
 * sites use on the return value.
 */
export interface AjaxThenable extends Promise<unknown> {
  done(handler: (data: unknown) => void): AjaxThenable;
  fail(handler: (xhr: AjaxError) => void): AjaxThenable;
  always(handler: () => void): AjaxThenable;
  /**
   * `jqXHR.abort()`. The installer keeps a handle to its in-flight
   * database check and cancels it when the form changes again, so this is
   * a real call site rather than API completeness.
   */
  abort(): void;
}

// eslint-disable-next-line @typescript-eslint/promise-function-async -- must return this exact `promise` object (now carrying done()/fail()/always()/abort()), not a value; wrapping in `async` would re-resolve it through `Promise.resolve()` and strip those extra properties, breaking jconfirm.ts's own `isThenable()` check and 10 real `.done()`/`.fail()` call sites.
function decorate(promise: Promise<unknown>, abort: () => void): AjaxThenable {
  // jQuery's jqXHR is not a native promise, so a failing request never
  // produced an unhandled-rejection event. This one would, on every request
  // whose failure is handled by the `error` callback rather than by a
  // .catch() -- which is how nearly every call site is written, and which
  // Browser tests would see through assertNoJavaScriptErrors(). Attaching a
  // silent handler to a *derived* promise suppresses that without changing
  // the original: `await ajax(...)` still rejects, and .fail() still fires.
  void promise.catch(() => undefined);

  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- AjaxThenable's own extra done()/fail()/always()/abort() methods are assigned onto this same promise object immediately below, a real boundary between "plain Promise" and "decorated with those methods".
  const thenable = promise as AjaxThenable;

  // eslint-disable-next-line @typescript-eslint/promise-function-async -- returns `thenable` itself for chaining, not a value to await; `async` would re-wrap it through `Promise.resolve()` and drop the very done()/fail()/always()/abort() methods this method exists to expose.
  thenable.done = (handler) => {
    void promise.then(handler, () => undefined);

    return thenable;
  };
  // eslint-disable-next-line @typescript-eslint/promise-function-async -- see thenable.done above.
  thenable.fail = (handler) => {
    void promise.catch((reason: unknown) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- this same promise only ever rejects with a real AjaxError, constructed at this file's own 2 real rejection sites.
      handler(reason as AjaxError);
    });

    return thenable;
  };
  // eslint-disable-next-line @typescript-eslint/promise-function-async -- see thenable.done above.
  thenable.always = (handler) => {
    void promise.then(
      () => {
        handler();
      },
      () => {
        handler();
      }
    );

    return thenable;
  };
  thenable.abort = abort;

  return thenable;
}

/** `$.ajax(options)`. */
// eslint-disable-next-line @typescript-eslint/promise-function-async -- returns `decorate(pending, ...)` directly, the same real AjaxThenable object carrying done()/fail()/always()/abort(); `async` would re-wrap it through `Promise.resolve()` and lose them.
export function ajax<T = unknown>(options: AjaxOptions<T>): AjaxThenable {
  const method = (options.method ?? options.type ?? "GET").toUpperCase();
  const dataType = options.dataType?.toLowerCase();
  const isBodyless = method === "GET" || method === "HEAD";

  let {url} = options;
  let body: BodyInit | undefined;

  // jQuery's default contentType. `contentType: false` suppresses the header
  // entirely, which is what a FormData upload needs.
  const contentType =
    options.json !== undefined
      ? "application/json"
      : (options.contentType ?? "application/x-www-form-urlencoded; charset=UTF-8");

  const requestData =
    options.json !== undefined ? JSON.stringify(options.json) : options.data;

  if (requestData !== undefined && requestData !== null) {
    const serialized = isPlainObject(requestData)
      ? param(requestData)
      : typeof requestData === "string"
        ? requestData
        : JSON.stringify(requestData);

    if (isBodyless) {
      url += (url.includes("?") ? "&" : "?") + serialized;
    } else {
      body = requestData instanceof FormData ? requestData : serialized;
    }
  }

  const headers: Record<string, string> = {
    ...options.headers,
  };
  if (body !== undefined && contentType !== false && !(body instanceof FormData)) {
    headers["Content-Type"] = contentType;
  }

  const controller = new AbortController();
  if (options.timeout !== undefined && options.timeout > 0) {
    setTimeout(() => {
      controller.abort();
    }, options.timeout);
  }

  const run = async (): Promise<unknown> => {
    const response = await fetch(url, {
      method,
      ...(body !== undefined ? { body } : {}),
      headers,
      signal: controller.signal,
      credentials: "same-origin",
    });

    const responseText = await response.text();
    const xhr: AjaxResponse = {
      status: response.status,
      statusText: response.statusText,
      responseText,
      getResponseHeader: (name) => response.headers.get(name),
    };

    // jQuery skips conversion entirely for 204/HEAD ("nocontent") and 304
    // ("notmodified"), so `success` runs with an undefined body rather than
    // failing to parse an empty one. Several call sites depend on this: the
    // plugin action endpoints really do answer 204.
    const noContent =
      response.status === 204 || response.status === 304 || method === "HEAD";

    let data: unknown;
    // jQuery's default dataType is "intelligent guess": with none given it
    // maps the response Content-Type through `ajaxSettings.contents`
    // (`json: /json/`) and converts accordingly. Roughly a seventh of the
    // call sites omit `dataType` and rely on this -- without it their
    // `success` receives raw text and the first property access throws,
    // which is how the history page's spinner ended up never hiding.
    const sniffed =
      dataType ??
      ((response.headers.get("Content-Type") ?? "").includes("json")
        ? "json"
        : undefined);

    if (!noContent && sniffed === "json" && responseText !== "") {
      try {
        data = JSON.parse(responseText);
        xhr.responseJSON = data;
      } catch {
        const failure = new AjaxError(
          xhr.status,
          xhr.statusText,
          responseText,
          response.headers,
          "Invalid JSON"
        );
        options.error?.(failure, "parsererror", "Invalid JSON");
        options.complete?.(failure, "parsererror");

        throw failure;
      }
    } else if (!noContent) {
      data = responseText;
    }

    if (!response.ok) {
      const failure = new AjaxError(
        xhr.status,
        xhr.statusText,
        responseText,
        response.headers,
        response.statusText
      );
      failure.responseJSON = xhr.responseJSON;
      options.error?.(failure, "error", response.statusText);
      options.complete?.(failure, "error");

      throw failure;
    }

    const statusText = noContent ? "nocontent" : "success";
    // The cast is the whole of what `T` means: the caller asserted this
    // shape, and neither jQuery nor this checks it.
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see comment above.
    options.success?.(data as T, statusText, xhr);
    options.complete?.(xhr, statusText);

    return data;
  };

  const pending = (async () => {
    const preflight: AjaxResponse = {
      status: 0,
      statusText: "",
      responseText: "",
      getResponseHeader: () => null,
    };
    options.beforeSend?.(preflight);

    return run();
  })();

  return decorate(pending, () => {
    controller.abort();
  });
}
