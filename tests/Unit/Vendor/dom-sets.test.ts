import { beforeEach, describe, expect, it } from "vitest";

import {
  addClass,
  after,
  append,
  attr,
  attrOf,
  empty,
  escapeId,
  find,
  hasClass,
  html,
  htmlOf,
  is,
  remove,
  removeClass,
  setVal,
  val,
} from "../../../themes/default/js/vendor/dom";

// The one rule under test throughout: a setter writes to every element of
// the set, a getter reads the first, and both are silent on an empty set.
// The naive `querySelector(...)` translation breaks all three.

function mount(markup: string): NodeListOf<HTMLElement> {
  document.body.innerHTML = markup;

  return document.body.querySelectorAll<HTMLElement>(".t");
}

beforeEach(() => {
  document.body.innerHTML = "";
});

describe("html", () => {
  it("writes to every element", () => {
    const set = mount('<p class="t"></p><p class="t"></p>');

    html(set, "<b>x</b>");

    expect(Array.from(set).map((el) => el.innerHTML)).toEqual([
      "<b>x</b>",
      "<b>x</b>",
    ]);
  });

  it("reads only the first", () => {
    const set = mount('<p class="t">one</p><p class="t">two</p>');

    expect(htmlOf(set)).toBe("one");
  });

  it("is silent on an empty set", () => {
    const set = mount("<p></p>");

    expect(() => {
      html(set, "x");
    }).not.toThrow();
    expect(htmlOf(set)).toBeUndefined();
  });
});

describe("val", () => {
  it("writes to every field and reads the first", () => {
    const set = mount(
      '<input class="t" value="a"><input class="t" value="b">'
    );

    expect(val(set)).toBe("a");

    setVal(set, "z");

    expect(Array.from(set).map((el) => (el as HTMLInputElement).value)).toEqual(
      ["z", "z"]
    );
  });

  it("returns undefined for an element with no value", () => {
    const set = mount('<p class="t"></p>');

    expect(val(set)).toBeUndefined();
  });

  it("covers select and textarea, not just input", () => {
    document.body.innerHTML =
      '<select class="t"><option value="s" selected></option></select>';
    const select = document.body.querySelectorAll<HTMLElement>(".t");

    expect(val(select)).toBe("s");

    document.body.innerHTML = '<textarea class="t">area</textarea>';
    const area = document.body.querySelectorAll<HTMLElement>(".t");

    expect(val(area)).toBe("area");
  });
});

describe("classes", () => {
  it("adds and removes several names at once", () => {
    const set = mount('<p class="t"></p><p class="t"></p>');

    // jQuery splits on whitespace; classList.add would throw on a string
    // containing a space.
    addClass(set, "one two");

    expect(Array.from(set).every((el) => el.classList.contains("two"))).toBe(
      true
    );

    removeClass(set, "one two");

    expect(Array.from(set).some((el) => el.classList.contains("one"))).toBe(
      false
    );
  });

  it("reports hasClass when any element carries it", () => {
    const set = mount('<p class="t"></p><p class="t marked"></p>');

    expect(hasClass(set, "marked")).toBe(true);
    expect(hasClass(set, "absent")).toBe(false);
  });
});

describe("attributes", () => {
  it("writes to every element and reads the first", () => {
    const set = mount('<a class="t" href="one"></a><a class="t"></a>');

    expect(attrOf(set, "href")).toBe("one");

    attr(set, "href", "two");

    expect(Array.from(set).map((el) => el.getAttribute("href"))).toEqual([
      "two",
      "two",
    ]);
  });

  it("returns null for a missing attribute and undefined for an empty set", () => {
    const set = mount('<a class="t"></a>');

    expect(attrOf(set, "href")).toBeNull();
    expect(attrOf(document.querySelectorAll(".absent"), "href")).toBeUndefined();
  });
});

describe("empty and remove", () => {
  it("clears children without detaching the elements", () => {
    const set = mount('<p class="t"><b>x</b></p><p class="t"><i>y</i></p>');

    empty(set);

    expect(document.body.querySelectorAll(".t")).toHaveLength(2);
    expect(document.body.querySelector(".t")?.children).toHaveLength(0);
  });

  it("detaches every element", () => {
    const set = mount('<p class="t"></p><p class="t"></p>');

    remove(set);

    expect(document.body.querySelectorAll(".t")).toHaveLength(0);
  });
});

describe("is and find", () => {
  it("matches when any element matches", () => {
    const set = mount('<p class="t"></p><p class="t open"></p>');

    expect(is(set, ".open")).toBe(true);
    expect(is(set, ".closed")).toBe(false);
  });

  it("gathers descendants across the whole set", () => {
    const set = mount(
      '<div class="t"><b class="leaf"></b></div>' +
        '<div class="t"><b class="leaf"></b><b class="leaf"></b></div>'
    );

    expect(find(set, ".leaf")).toHaveLength(3);
  });
});

describe("append and after", () => {
  it("gives every element its own parsed copy", () => {
    const set = mount('<div class="t"></div><div class="t"></div>');

    append(set, "<b>x</b>");

    // A single parsed node could only live in one parent, so each element
    // must get its own -- jQuery clones for exactly this reason.
    expect(document.body.querySelectorAll("b")).toHaveLength(2);
  });

  it("inserts after the element, in source order", () => {
    document.body.innerHTML = '<div id="w"><p class="t"></p></div>';
    const set = document.body.querySelectorAll<HTMLElement>(".t");

    after(set, '<i id="one"></i><i id="two"></i>');

    const ids = Array.from(
      document.getElementById("w")?.children ?? []
    ).map((el) => el.id);

    expect(ids).toEqual(["", "one", "two"]);
  });

  it("appends at the end, after existing children", () => {
    document.body.innerHTML = '<div class="t"><b id="first"></b></div>';
    const set = document.body.querySelectorAll<HTMLElement>(".t");

    append(set, '<b id="last"></b>');

    const ids = Array.from(set[0]?.children ?? []).map((el) => el.id);

    expect(ids).toEqual(["first", "last"]);
  });
});

describe("escapeId", () => {
  // happy-dom's selector parser rejects `#1` (as a real browser does) but
  // also does not understand the escaped form, so the query half of this is
  // verified in the Browser suite instead. What is checked here is that the
  // escape itself is produced.
  it("escapes an id that starts with a digit", () => {
    // The reason this exists: `#1` is a valid Sizzle selector and an
    // invalid CSS one, and every id built from a database row id is a
    // number.
    document.body.innerHTML = '<div id="1"></div>';

    expect(() => document.querySelectorAll("#1")).toThrow();
    expect(escapeId(1)).not.toBe("1");
    expect(escapeId(1)).toMatch(/^\\3/);
  });

  it("accepts a number as readily as a string", () => {
    expect(escapeId(42)).toBe(escapeId("42"));
  });

  it("leaves an already-conforming id alone", () => {
    expect(escapeId("plain-id")).toBe("plain-id");
  });
});
