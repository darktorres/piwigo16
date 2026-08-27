// jQuery-compatible ajax over fetch, for P49-A (docs/PLAN.md).
//
// 97 first-party call sites, 51 of them the static `$.ajax` form. Ported from
// node_modules/jquery/src/ajax.js -- the pinned 1.11.3 this app loads -- so
// the option names, the callback signatures and the response-conversion rules
// behave as the call sites already expect.

/** What handlers receive in place of jQuery's jqXHR. */
interface AjaxResponse {
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

export interface AjaxOptions {
  url: string;
  /** jQuery accepts both spellings; `method` wins, as in 1.11.3. */
  type?: string;
  method?: string;
  data?: unknown;
  dataType?: "json" | "html" | "text";
  contentType?: string | false;
  headers?: Record<string, string>;
  timeout?: number;
  beforeSend?: (xhr: AjaxResponse) => void;
  success?: (data: unknown, statusText: string, xhr: AjaxResponse) => void;
  error?: (xhr: AjaxResponse, statusText: string, errorThrown: string) => void;
  complete?: (xhr: AjaxResponse, statusText: string) => void;
}

/**
 * `jQuery.param()` for the flat objects the call sites pass. Arrays repeat
 * the key with `[]`, matching jQuery's traditional-off default; booleans
 * serialise as "true"/"false" rather than 1/0.
 */
export function param(data: Record<string, unknown>): string {
  const parts: string[] = [];

  for (const [key, value] of Object.entries(data)) {
    if (Array.isArray(value)) {
      for (const item of value) {
        parts.push(
          encodeURIComponent(key + "[]") + "=" + encodeURIComponent(String(item))
        );
      }
      continue;
    }
    parts.push(encodeURIComponent(key) + "=" + encodeURIComponent(String(value)));
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
}

function decorate(promise: Promise<unknown>): AjaxThenable {
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

  return thenable;
}

/** `$.ajax(options)`. */
export function ajax(options: AjaxOptions): AjaxThenable {
  const method = (options.method ?? options.type ?? "GET").toUpperCase();
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
    if (!noContent && options.dataType === "json" && responseText !== "") {
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
    options.success?.(data, statusText, xhr);
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

  return decorate(pending);
}
