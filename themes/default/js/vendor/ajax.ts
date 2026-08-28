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

  public constructor(
    public readonly status: number,
    public readonly statusText: string,
    public readonly responseText: string,
    private readonly headers: Headers,
    message: string
  ) {
    super(message);
    this.name = "AjaxError";
  }

  public getResponseHeader(name: string): string | null {
    return this.headers.get(name);
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
function paramValue(value: unknown): string {
  const resolved =
    typeof value === "function" ? (value as () => unknown)() : value;

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

export function param(data: Record<string, unknown>): string {
  const parts: string[] = [];

  for (const [key, value] of Object.entries(data)) {
    if (Array.isArray(value)) {
      for (const item of value) {
        parts.push(
          encodeURIComponent(key + "[]") + "=" + encodeURIComponent(paramValue(item))
        );
      }
      continue;
    }
    parts.push(encodeURIComponent(key) + "=" + encodeURIComponent(paramValue(value)));
  }

  return parts.join("&");
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return (
    typeof value === "object" && value !== null && !Array.isArray(value)
  );
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

function decorate(promise: Promise<unknown>, abort: () => void): AjaxThenable {
  // jQuery's jqXHR is not a native promise, so a failing request never
  // produced an unhandled-rejection event. This one would, on every request
  // whose failure is handled by the `error` callback rather than by a
  // .catch() -- which is how nearly every call site is written, and which
  // Browser tests would see through assertNoJavaScriptErrors(). Attaching a
  // silent handler to a *derived* promise suppresses that without changing
  // the original: `await ajax(...)` still rejects, and .fail() still fires.
  void promise.catch(() => undefined);

  const thenable = promise as AjaxThenable;

  thenable.done = (handler) => {
    void promise.then(handler, () => undefined);

    return thenable;
  };
  thenable.fail = (handler) => {
    void promise.catch((reason: unknown) => {
      handler(reason as AjaxError);
    });

    return thenable;
  };
  thenable.always = (handler) => {
    void promise.then(
      () => handler(),
      () => handler()
    );

    return thenable;
  };
  thenable.abort = abort;

  return thenable;
}

/** `$.ajax(options)`. */
export function ajax<T = unknown>(options: AjaxOptions<T>): AjaxThenable {
  const method = (options.method ?? options.type ?? "GET").toUpperCase();
  const dataType = options.dataType?.toLowerCase();
  const isBodyless = method === "GET" || method === "HEAD";

  let url = options.url;
  let body: BodyInit | undefined;

  // jQuery's default contentType. `contentType: false` suppresses the header
  // entirely, which is what a FormData upload needs.
  const contentType =
    options.contentType === undefined
      ? "application/x-www-form-urlencoded; charset=UTF-8"
      : options.contentType;

  if (options.data !== undefined && options.data !== null) {
    const serialized = isPlainObject(options.data)
      ? param(options.data)
      : typeof options.data === "string"
        ? options.data
        : JSON.stringify(options.data);

    if (isBodyless) {
      url += (url.includes("?") ? "&" : "?") + serialized;
    } else {
      body =
        options.data instanceof FormData ? options.data : serialized;
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
      body,
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
      (/json/.test(response.headers.get("Content-Type") ?? "")
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
