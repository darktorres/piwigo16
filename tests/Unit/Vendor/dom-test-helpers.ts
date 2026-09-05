/**
 * Test-only DOM query helpers. Every real call site across this directory's
 * fixtures queries an element it just created (`document.body.innerHTML =
 * ...` on the immediately preceding line), so the element really is always
 * there -- but that's not provable to the type checker the way a bare
 * `document.getElementById(id)!`/`document.querySelector(sel)!` assumed.
 * These turn the assumption into a real runtime check instead, the same
 * "prove it, don't assert it" shape as `vendor/utils/dom.ts`'s own
 * `valueAt()` (P51-O).
 */
export function byId(id: string): HTMLElement {
  const el = document.getElementById(id);
  if (el === null) {
    throw new Error(`test fixture is missing #${id}`);
  }
  return el;
}

export function qs(selector: string): HTMLElement {
  const el = document.querySelector<HTMLElement>(selector);
  if (el === null) {
    throw new Error(`test fixture matches no element for "${selector}"`);
  }
  return el;
}
