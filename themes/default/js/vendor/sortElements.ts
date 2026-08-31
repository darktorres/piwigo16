/**
 * Port of jquery.sort.js's own `jQuery.fn.sortElements` -- the well-known,
 * widely copy-pasted snippet that file's own docblock confirms has no
 * single canonical source repo or package anywhere. jQuery's version also
 * accepts a `getSortable` second parameter (sort by one element but
 * reposition another, e.g. a parent row) -- this app's one real call site
 * (`plugins_new.ts`'s `.pluginBox` grid) never uses it, so it isn't
 * ported.
 *
 * Requires every element to share the same parentNode (true of that real
 * call site): `appendChild()` on an already-attached node moves it to the
 * end, so appending each element in sorted order reproduces that order
 * relative to every other matched element. Anything else the shared
 * parent contains is not repositioned, and ends up after every matched
 * element once this runs.
 */
export function sortElements(
  elements: Iterable<Element>,
  comparator: (a: Element, b: Element) => number
): void {
  const sorted = Array.from(elements).sort(comparator);
  for (const element of sorted) {
    element.parentNode?.appendChild(element);
  }
}
