import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  ajax,
  AjaxError,
  param,
  type AjaxResponse,
} from "../../../themes/default/js/vendor/utils/ajax";

interface FetchCall {
  url: string;
  init: RequestInit;
}

let calls: FetchCall[] = [];

function stubFetch(
  status: number,
  bodyText: string,
  statusText = "OK"
): void {
  vi.stubGlobal(
    "fetch",
    // eslint-disable-next-line @typescript-eslint/promise-function-async -- no internal `await` needed (a pure stub), and adding `async` here would only trip the already-enabled require-await instead; behaviorally identical either way.
    vi.fn((url: string, init: RequestInit) => {
      calls.push({ url, init });

      return Promise.resolve({
        status,
        statusText,
        ok: status >= 200 && status < 300,
        headers: new Headers(),
        // eslint-disable-next-line @typescript-eslint/promise-function-async -- see comment above.
        text: () => Promise.resolve(bodyText),
      });
    })
  );
}

beforeEach(() => {
  calls = [];
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("param()", () => {
  it("serialises a flat object and encodes values", () => {
    expect(param({ page: "plugins", tab: "installed" })).toBe(
      "page=plugins&tab=installed"
    );
    expect(param({ q: "a b&c" })).toBe("q=a%20b%26c");
  });

  it("serialises booleans as true/false, not 1/0", () => {
    expect(param({ incompatible_plugins: true })).toBe(
      "incompatible_plugins=true"
    );
  });

  it("repeats an array key with []", () => {
    expect(param({ ids: [1, 2] })).toBe("ids%5B%5D=1&ids%5B%5D=2");
  });

  it("recurses into a plain (non-array) object value, bracketing each key", () => {
    // history.ts's own current_param.types shape -- a plain object with
    // numeric-looking keys, not a real array, so jQuery's own
    // buildParams() takes its object branch (types[0]=high&types[1]=other),
    // not its array branch (types[]=high&types[]=other).
    expect(param({ types: { 0: "high", 1: "other" } })).toBe(
      "types%5B0%5D=high&types%5B1%5D=other"
    );
  });
});

describe("ajax() request shaping", () => {
  it("puts object data in the query string for GET", async () => {
    stubFetch(200, "[]");
    await ajax({
      url: "admin.php",
      method: "GET",
      data: { page: "plugins", tab: "installed" },
      dataType: "json",
    });

    expect(calls[0]?.url).toBe("admin.php?page=plugins&tab=installed");
    expect(calls[0]?.init.body).toBeUndefined();
  });

  it("appends with & when the url already has a query", async () => {
    stubFetch(200, "[]");
    await ajax({ url: "admin.php?x=1", method: "GET", data: { y: 2 } });

    expect(calls[0]?.url).toBe("admin.php?x=1&y=2");
  });

  it("sends a pre-stringified body untouched for POST", async () => {
    stubFetch(200, "{}");
    await ajax({
      url: "api/v1/plugins/x/actions/perform",
      type: "POST",
      contentType: "application/json",
      headers: { "X-CSRF-Token": "tok" },
      data: JSON.stringify({ action: "activate" }),
      dataType: "json",
    });

    const init = calls[0]?.init;
    expect(init?.body).toBe('{"action":"activate"}');
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own fetch() call always builds headers as a plain object, one of RequestInit's own several real HeadersInit shapes.
    expect((init?.headers as Record<string, string>)["Content-Type"]).toBe(
      "application/json"
    );
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own fetch() call always builds headers as a plain object, one of RequestInit's own several real HeadersInit shapes.
    expect((init?.headers as Record<string, string>)["X-CSRF-Token"]).toBe(
      "tok"
    );
  });

  it("defaults to jQuery's form-urlencoded content type", async () => {
    stubFetch(200, "");
    await ajax({ url: "x", type: "POST", data: { a: 1 } });

    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own fetch() call always builds headers as a plain object, one of RequestInit's own several real HeadersInit shapes.
    expect((calls[0]?.init.headers as Record<string, string>)["Content-Type"]).toBe(
      "application/x-www-form-urlencoded; charset=UTF-8"
    );
  });

  it("prefers method over type, as jQuery does", async () => {
    stubFetch(200, "");
    await ajax({ url: "x", type: "GET", method: "POST", data: { a: 1 } });

    expect(calls[0]?.init.method).toBe("POST");
  });
});

describe("json: option", () => {
  it("stringifies json and sets contentType, same as hand-pairing data/contentType", async () => {
    stubFetch(200, "{}");
    await ajax({
      url: "api/v1/tags",
      type: "POST",
      headers: { "X-CSRF-Token": "tok" },
      json: { name: "vacation" },
      dataType: "json",
    });

    const init = calls[0]?.init;
    expect(init?.body).toBe('{"name":"vacation"}');
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own fetch() call always builds headers as a plain object, one of RequestInit's own several real HeadersInit shapes.
    expect((init?.headers as Record<string, string>)["Content-Type"]).toBe(
      "application/json"
    );
  });

  it("ignores an explicit contentType, forcing application/json", async () => {
    stubFetch(200, "{}");
    await ajax({
      url: "x",
      type: "POST",
      contentType: "text/plain",
      json: { a: 1 },
    });

    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own fetch() call always builds headers as a plain object, one of RequestInit's own several real HeadersInit shapes.
    expect((calls[0]?.init.headers as Record<string, string>)["Content-Type"]).toBe(
      "application/json"
    );
  });
});

describe("ajax() response conversion", () => {
  it("parses JSON when dataType is json", async () => {
    stubFetch(200, '{"ok":true}');
    const success = vi.fn();
    await ajax({ url: "x", dataType: "json", success });

    expect(success).toHaveBeenCalledWith(
      { ok: true },
      "success",
      expect.anything()
    );
  });

  it("treats 204 as nocontent and does not try to parse", async () => {
    // The plugin action endpoints really answer 204. jQuery skips conversion
    // entirely rather than failing to parse an empty body, which is why those
    // call sites take an unused `_data` parameter.
    stubFetch(204, "", "No Content");
    const success = vi.fn();
    await ajax({ url: "x", type: "POST", dataType: "json", success });

    expect(success).toHaveBeenCalledWith(undefined, "nocontent", expect.anything());
  });

  it("reports a parse failure through error(), not a throw to the caller", async () => {
    stubFetch(200, "<html>not json</html>");
    const error = vi.fn();
    const success = vi.fn();

    await expect(
      ajax({ url: "x", dataType: "json", success, error })
    ).rejects.toBeDefined();

    expect(success).not.toHaveBeenCalled();
    expect(error).toHaveBeenCalledTimes(1);
    expect(error.mock.calls[0]?.[1]).toBe("parsererror");
  });

  it("gives error handlers responseText, which call sites log", async () => {
    stubFetch(500, "boom", "Server Error");
    const error =
      vi.fn<(xhr: AjaxResponse, statusText: string, errorThrown: string) => void>();

    await expect(ajax({ url: "x", error })).rejects.toBeDefined();

    const captured = error.mock.calls[0]?.[0];
    expect(captured?.responseText).toBe("boom");
    expect(captured?.status).toBe(500);
  });

  it("rejects with a real Error carrying the response", async () => {
    // jQuery rejects with the bare jqXHR. A plain object cannot model that
    // here without becoming an illegitimate throw value, so failures are an
    // Error subclass that still exposes responseText/status to call sites.
    stubFetch(500, "boom", "Server Error");

    await expect(ajax({ url: "x" })).rejects.toBeInstanceOf(AjaxError);
    await expect(ajax({ url: "x" })).rejects.toBeInstanceOf(Error);
    await expect(ajax({ url: "x" })).rejects.toMatchObject({
      status: 500,
      responseText: "boom",
    });
  });

  it("rejects a parse failure with the same shape", async () => {
    stubFetch(200, "<html>");

    await expect(
      ajax({ url: "x", dataType: "json" })
    ).rejects.toBeInstanceOf(AjaxError);
  });

  it("runs complete() on both success and failure", async () => {
    stubFetch(200, "ok");
    const complete = vi.fn();
    await ajax({ url: "x", complete });
    expect(complete).toHaveBeenCalledTimes(1);

    stubFetch(500, "no");
    const complete2 = vi.fn();
    await expect(ajax({ url: "x", complete: complete2 })).rejects.toBeDefined();
    expect(complete2).toHaveBeenCalledTimes(1);
  });

  it("calls beforeSend before the request goes out", async () => {
    stubFetch(200, "ok");
    const order: string[] = [];
    await ajax({
      url: "x",
      beforeSend: () => order.push("before"),
      success: () => order.push("success"),
    });

    expect(order).toEqual(["before", "success"]);
  });
});

describe("the returned thenable", () => {
  it("supports .done(), which call sites chain", async () => {
    stubFetch(200, '{"v":1}');
    const done = vi.fn();
    await ajax({ url: "x", dataType: "json" }).done(done);

    expect(done).toHaveBeenCalledWith({ v: 1 });
  });

  it("supports await", async () => {
    stubFetch(200, '{"v":2}');
    await expect(ajax({ url: "x", dataType: "json" })).resolves.toEqual({
      v: 2,
    });
  });
});

describe("param() null handling", () => {
  it("serialises null and undefined as empty, not as the words", () => {
    // jQuery: `value == null ? "" : value`. Getting this wrong sends
    // `minDate=undefined`, which the comments API answers with a 422 --
    // found by visual regression, not by any type check.
    expect(param({ a: undefined, b: null, c: "x" })).toBe("a=&b=&c=x");
  });

  it("invokes a function value, as jQuery does", () => {
    expect(param({ a: () => "called" })).toBe("a=called");
  });

  it("applies the same rule inside an array", () => {
    expect(param({ ids: [1, null, 3] })).toBe("ids%5B%5D=1&ids%5B%5D=&ids%5B%5D=3");
  });
});

describe("dataType omitted (jQuery's intelligent guess)", () => {
  it("parses JSON when the response says application/json", async () => {
    vi.stubGlobal(
      "fetch",
      // eslint-disable-next-line @typescript-eslint/promise-function-async -- see stubFetch's own comment above: no internal `await` needed, and `async` would only trip require-await instead.
      vi.fn(() =>
        Promise.resolve({
          status: 200,
          statusText: "OK",
          ok: true,
          headers: new Headers({ "Content-Type": "application/json" }),
          // eslint-disable-next-line @typescript-eslint/promise-function-async -- see comment above.
          text: () => Promise.resolve('{"lines":[]}'),
        })
      )
    );

    // No dataType given. jQuery sniffs the header; a call site that relies
    // on it would otherwise get a string and throw on first property
    // access.
    await expect(ajax({ url: "/x" })).resolves.toEqual({ lines: [] });
  });

  it("leaves the body as text when the response is not JSON", async () => {
    stubFetch(200, "<p>hi</p>");

    await expect(ajax({ url: "/x" })).resolves.toBe("<p>hi</p>");
  });

  it("does not parse when an explicit non-json dataType is given", async () => {
    vi.stubGlobal(
      "fetch",
      // eslint-disable-next-line @typescript-eslint/promise-function-async -- see stubFetch's own comment above: no internal `await` needed, and `async` would only trip require-await instead.
      vi.fn(() =>
        Promise.resolve({
          status: 200,
          statusText: "OK",
          ok: true,
          headers: new Headers({ "Content-Type": "application/json" }),
          // eslint-disable-next-line @typescript-eslint/promise-function-async -- see comment above.
          text: () => Promise.resolve('{"a":1}'),
        })
      )
    );

    await expect(ajax({ url: "/x", dataType: "text" })).resolves.toBe(
      '{"a":1}'
    );
  });
});

describe("dataType casing", () => {
  it('parses JSON for dataType "JSON" as well as "json"', async () => {
    // jQuery lowercases dataType; four call sites spell it "JSON", and a
    // case-sensitive check would hand them back an unparsed string.
    stubFetch(200, '{"ok":1}');

    await expect(ajax({ url: "/x", dataType: "JSON" })).resolves.toEqual({
      ok: 1,
    });
    await expect(ajax({ url: "/x", dataType: "json" })).resolves.toEqual({
      ok: 1,
    });
  });
});

describe("abort()", () => {
  it("cancels the in-flight request through its own signal", async () => {
    let seenSignal: AbortSignal | undefined;
    vi.stubGlobal(
      "fetch",
      vi.fn(async (_url: string, init: RequestInit) => {
        seenSignal = init.signal ?? undefined;

        return new Promise((_resolve, reject) => {
          init.signal?.addEventListener("abort", () => {
            reject(new DOMException("Aborted", "AbortError"));
          });
        });
      })
    );

    const request = ajax({ url: "/slow" });
    const settled = request.then(
      () => "resolved",
      () => "rejected"
    );

    request.abort();

    await expect(settled).resolves.toBe("rejected");
    expect(seenSignal?.aborted).toBe(true);
  });
});
