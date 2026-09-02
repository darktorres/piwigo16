// Native port of jQuery UI's tooltip widget (P49-C), real source read from
// the vendored `jquery-ui@1.10.4` bundle's own `tooltip.js`. Narrowed hard
// to `rating_user.ts`'s own one real call site: a delegated
// `items: ".usr,[title]"` match against `#rateTable`'s own descendants
// (real, because `dataTable()`'s own redraws replace row elements on
// paging/sorting/filtering -- a listener bound directly to each row at
// init time would go stale) and a fully custom `content` callback (sync
// or async, via its own `callback` parameter). No other real option
// (`hide`/`show` animation, `position` collision-flipping, `track`,
// `tooltipClass`, `disabled`) is ever set by the one real call site, and
// no jQuery-UI theme CSS is even loaded on this page (`RatingUserView`'s
// own comment confirms it never was) -- so positioning here is plain
// below-the-target placement, flipped above only if it would overflow the
// viewport's own bottom edge, which is all a GeoIP city name (plus an
// optional static map image) or a raw title string ever needs.
//
// Delegation itself needs no `vendor/dom.ts`-style "mouseenter doesn't
// bubble" translation: jQuery UI's own real `_create()` binds plain,
// *bubbling* "mouseover"/"focusin" directly on `this.element` (the
// container) and resolves the actual matched descendant per event via
// `$(event.target).closest(items)` -- not a `mouseenter`/`.hover()`
// binding at all. Only the *close* side ("mouseleave") is bound directly
// on the one specific resolved target once it's found, which is an
// ordinary, non-delegated, one-shot listener. `focusin`/keyboard-close
// isn't ported: neither real matched element here (`.usr` table cells,
// `<a title=...>` rate links) is a focusable element a real user tabs to.
// "Kill parent tooltips" (jQuery UI's own handling for a `[title]`
// ancestor of the current match having a tooltip of its own already open)
// isn't ported either -- every real match here is a `<td>`/`<a>`, none
// ever nested inside another real match.

export interface TooltipOptions {
  items: string;
  content: (this: HTMLElement, callback: (content: string) => void) => string | undefined;
}

const pendingOrOpen = new WeakSet<HTMLElement>();
const tooltipElements = new WeakMap<HTMLElement, HTMLElement>();
const savedTitles = new WeakMap<HTMLElement, string>();

function positionTooltip(tooltipEl: HTMLElement, target: HTMLElement): void {
  const rect = target.getBoundingClientRect();
  tooltipEl.style.left = String(rect.left + window.scrollX) + "px";

  const tooltipHeight = tooltipEl.getBoundingClientRect().height;
  const below = rect.bottom + window.scrollY + 15;
  const overflowsViewport = rect.bottom + 15 + tooltipHeight > window.innerHeight;
  tooltipEl.style.top = overflowsViewport
    ? String(rect.top + window.scrollY - tooltipHeight - 15) + "px"
    : String(below) + "px";
}

function openTooltip(target: HTMLElement, content: string): void {
  const existing = tooltipElements.get(target);
  if (existing) {
    const contentEl = existing.querySelector(".ui-tooltip-content");
    if (contentEl) {
      contentEl.innerHTML = content;
    }
    return;
  }

  // Blank (not remove) the real title attribute while our own tooltip is
  // open, matching the original's own real mouseover-specific branch --
  // suppresses the browser's native tooltip without losing the value
  // `close()` restores below.
  const title = target.getAttribute("title");
  if (title !== null && title !== "") {
    savedTitles.set(target, title);
    target.setAttribute("title", "");
  }

  const tooltipEl = document.createElement("div");
  tooltipEl.className = "ui-tooltip";
  const contentEl = document.createElement("div");
  contentEl.className = "ui-tooltip-content";
  contentEl.innerHTML = content;
  tooltipEl.append(contentEl);
  document.body.append(tooltipEl);
  tooltipElements.set(target, tooltipEl);
  positionTooltip(tooltipEl, target);

  target.addEventListener(
    "mouseleave",
    () => {
      pendingOrOpen.delete(target);
      tooltipElements.delete(target);
      tooltipEl.remove();
      const savedTitle = savedTitles.get(target);
      if (savedTitle !== undefined) {
        target.setAttribute("title", savedTitle);
        savedTitles.delete(target);
      }
    },
    { once: true },
  );
}

export function tooltip(container: Element, options: TooltipOptions): void {
  container.addEventListener("mouseover", (event) => {
    const origin = event.target as Element | null;
    const target = origin?.closest(options.items);
    if (!(target instanceof HTMLElement) || pendingOrOpen.has(target)) {
      return;
    }
    pendingOrOpen.add(target);

    const show = (content: string | undefined): void => {
      if (
        content === undefined ||
        content === "" ||
        !pendingOrOpen.has(target)
      ) {
        return;
      }
      openTooltip(target, content);
    };

    show(options.content.call(target, show));
  });
}
