import { beforeEach, describe, expect, it } from "vitest";

import {
  coerceDataAttribute,
  data,
  dataId,
  parseHtml,
  removeData,
  setData,
} from "../../../themes/default/js/vendor/dom";

beforeEach(() => {
  document.body.innerHTML = "";
});

function el(attrs = ""): HTMLElement {
  document.body.innerHTML = `<div id="t" ${attrs}></div>`;
  return document.getElementById("t")!;
}

describe("coerceDataAttribute", () => {
  it("maps the three literals jQuery special-cases", () => {
    expect(coerceDataAttribute("true")).toBe(true);
    expect(coerceDataAttribute("false")).toBe(false);
    expect(coerceDataAttribute("null")).toBeNull();
  });

  it("converts a number only when it round-trips exactly", () => {
    expect(coerceDataAttribute("12")).toBe(12);
    expect(coerceDataAttribute("-3.5")).toBe(-3.5);
    // `+"007" + "" === "7"`, not "007", so jQuery leaves it a string --
    // this is the guard that keeps zero-padded ids from becoming numbers.
    expect(coerceDataAttribute("007")).toBe("007");
    expect(coerceDataAttribute("1e1000")).toBe("1e1000");
    expect(coerceDataAttribute("")).toBe("");
  });

  it("parses only brace- or bracket-wrapped JSON", () => {
    expect(coerceDataAttribute('{"a":1}')).toEqual({ a: 1 });
    expect(coerceDataAttribute("[1,2]")).toEqual([1, 2]);
    // Valid JSON, but not wrapped -- jQuery's rbrace does not match it.
    expect(coerceDataAttribute('"quoted"')).toBe('"quoted"');
  });

  it("falls back to the raw string on malformed JSON instead of throwing", () => {
    expect(coerceDataAttribute("{not json}")).toBe("{not json}");
  });
});

describe("data()", () => {
  it("reads and coerces a data-* attribute", () => {
    const node = el('data-count="42" data-flag="true"');
    expect(data(node, "count")).toBe(42);
    expect(data(node, "flag")).toBe(true);
  });

  it("maps camelCase keys onto dashed attribute names", () => {
    const node = el('data-foo-bar="9"');
    expect(data(node, "fooBar")).toBe(9);
  });

  it("returns undefined when the attribute is absent", () => {
    expect(data(el(), "missing")).toBeUndefined();
  });

  it("caches on first read, so a later attribute change is invisible", () => {
    // jQuery's documented behaviour: the attribute is consulted once, then
    // the store wins. Translating .data() to dataset would break this.
    const node = el('data-x="1"');
    expect(data(node, "x")).toBe(1);
    node.setAttribute("data-x", "2");
    expect(data(node, "x")).toBe(1);
  });

  it("does not write the attribute back when set", () => {
    const node = el('data-x="1"');
    setData(node, "x", 99);
    expect(data(node, "x")).toBe(99);
    expect(node.getAttribute("data-x")).toBe("1");
  });

  it("stores values the DOM cannot hold", () => {
    const node = el();
    const obj = { nested: [1, 2] };
    setData(node, "payload", obj);
    expect(data(node, "payload")).toBe(obj);
  });

  it("removeData drops the cached value and re-reads the attribute", () => {
    const node = el('data-x="1"');
    setData(node, "x", 99);
    removeData(node, "x");
    expect(data(node, "x")).toBe(1);
  });

  it("keeps each element's store separate", () => {
    document.body.innerHTML = `<div id="a"></div><div id="b"></div>`;
    const a = document.getElementById("a")!;
    const b = document.getElementById("b")!;
    setData(a, "k", "A");
    setData(b, "k", "B");
    expect(data(a, "k")).toBe("A");
    expect(data(b, "k")).toBe("B");
  });
});

describe("dataId", () => {
  it("returns the already-coerced number", () => {
    const node = el('data-cat-id="42"');
    expect(dataId(node, "catId")).toBe(42);
  });

  it("throws for a missing attribute instead of returning NaN", () => {
    expect(() => dataId(el(), "missing")).toThrow(/dataId/);
  });

  it("throws for a non-numeric attribute instead of silently keeping it a string", () => {
    expect(() => dataId(el('data-id="abc"'), "id")).toThrow(/dataId/);
  });

  it("throws for a literal \"null\" attribute rather than minting id 0", () => {
    expect(() => dataId(el('data-id="null"'), "id")).toThrow(/dataId/);
  });
});

describe("parseHtml", () => {
  it("returns the top-level elements of a markup string", () => {
    const nodes = parseHtml('<li class="a"></li><li class="b"></li>');

    expect(nodes.map((n) => n.className)).toEqual(["a", "b"]);
  });

  it("parses a table row, which innerHTML on a div would discard", () => {
    // This is the case jQuery keeps a `wrapMap` for; a <template> gets it
    // right without one.
    const nodes = parseHtml("<tr><td>x</td></tr>");

    expect(nodes).toHaveLength(1);
    expect(nodes[0]?.tagName).toBe("TR");
  });

  it("drops the whitespace between elements", () => {
    const nodes = parseHtml("\n  <div></div>\n  ");

    expect(nodes).toHaveLength(1);
  });

  it("returns nothing for markup with no elements", () => {
    expect(parseHtml("")).toEqual([]);
  });
});
