import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ajax, AjaxError, param } from "../../../themes/default/js/vendor/ajax";

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
    vi.fn((url: string, init: RequestInit) => {
      calls.push({ url, init });

      return Promise.resolve({
        status,
        statusText,
        ok: status >= 200 && status < 300,
        headers: new Headers(),
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
    expect((init?.headers as Record<string, string>)["Content-Type"]).toBe(
      "application/json"
    );
    expect((init?.headers as Record<string, string>)["X-CSRF-Token"]).toBe(
      "tok"
    );
  });

  it("defaults to jQuery's form-urlencoded content type", async () => {
    stubFetch(200, "");
    await ajax({ url: "x", type: "POST", data: { a: 1 } });

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
    const error = vi.fn();

    await expect(ajax({ url: "x", error })).rejects.toBeDefined();

    const captured = error.mock.calls[0]?.[0] as AjaxError | undefined;
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
