// Native port of jQuery UI's sortable widget (P49-C), real source read
// from the vendored `jquery-ui@1.10.4` bundle's own `ui/sortable.js`.
// Narrowed hard to what the 2 real call sites actually use:
// `menubar.ts`'s own vertical-only menu-block reordering
// (`axis: "y"`, `opacity: 0.8`, no `handle` -- the whole `<li>` drags)
// and `element_set_ranks.ts`'s own free (both-axis) thumbnail-grid
// reordering (`opacity: 0.7`, `handle: ".rank-of-image, .rank-of-image
// img"` -- real, but every real `<li>` here is 100% covered by those 2
// selectors already, so it has no actual restricting effect on the one
// real template; implemented for real rather than assumed unreachable).
// `revert`'s own snap-into-place animation isn't ported -- a real,
// purely cosmetic nicety neither real call site's own tests (nor a
// user) can tell apart from an instant drop, unlike every other real
// option here. `connectWith`/multiple-list drag, `containment`, and
// `tolerance` are never set by either real call site and aren't
// ported. `cancel` (the shared `mouse` widget mixin's own real
// default, `"input,textarea,button,select,option"`, not sortable's
// own) isn't ported either, despite being genuinely real elsewhere in
// this app: confirmed dead on both of these 2 specific real call
// sites -- `menubar.ts`'s own real `hide_` checkbox is `display:
// none` behind a font-icon label (the actual pointerdown target is
// the label's own child text, which `cancel`'s own ancestor-only
// `.closest()` check would never have matched anyway), and its own
// real manual-position `<input>` is unconditionally force-hidden
// (`hide(".menuPos")`) the moment this file's own `ready()` runs;
// `element_set_ranks.ts`'s own `<input>` is `class="u-hidden"`. No
// real, currently-visible click target inside either real sortable
// item is a bare `input`/`textarea`/`button`/`select`/`option` at all.
//
// `distance` (that same shared mixin's own real default of 1px) IS
// ported, for a more surprising reason than `cancel` ever needed to
// be: the original's own `_mouseDown()` calls a real, native
// `mousedown` event's own `preventDefault()` unconditionally, but a
// `mousedown`'s own `preventDefault()` never suppresses the "click"
// event that normally follows it -- only a `pointerdown`'s own
// `preventDefault()` does that (it cancels the browser's own
// synthesized "compatibility mouse events", including the click, for
// that whole interaction). Calling `preventDefault()` immediately on
// `pointerdown` broke `menubar.ts`'s own checkbox's native label-click
// forwarding regardless of `cancel` (confirmed live, the same real
// bug either way) -- gating `preventDefault()` (and every other real
// drag side effect) behind the same real distance check the original
// already has fixes it structurally: a plain click never moves the
// pointer, so it never crosses the threshold, and the click reaches
// the checkbox exactly as before. Mouse and touch are unified through
// native Pointer Events (the same deliberate simplification
// `vendor/jcrop.ts`'s own leading comment already documents), not the
// original's own separate mouse/touch listener pairs.
//
// Reordering itself uses a plain midpoint-crossing swap (compare the
// pointer's own position against each sibling's own bounding-rect
// midpoint) rather than porting jQuery UI's own considerably more
// elaborate `_rearrange()`/`_contactContainers()`/floating-detection
// machinery, which exists to support connected multi-container drags
// neither real call site ever reaches. The single-axis case (below)
// doesn't require literally hovering over a sibling's own box either
// -- real CSS margins between consecutive items leave real gaps a
// literal-overlap check can land in and match nothing (confirmed
// live).

const DISTANCE_THRESHOLD_PX = 1;

export interface SortableOptions {
  axis?: "x" | "y";
  opacity?: number;
  handle?: string;
  update?: (container: HTMLElement) => void;
}

function siblings(
  container: HTMLElement,
  ...exclude: HTMLElement[]
): HTMLElement[] {
  return Array.from(container.children).filter(
    (el): el is HTMLElement =>
      el instanceof HTMLElement && !exclude.includes(el),
  );
}

function bindItem(
  container: HTMLElement,
  item: HTMLElement,
  options: SortableOptions,
): void {
  item.addEventListener("pointerdown", (downEvent) => {
    if (downEvent.button !== 0) {
      return;
    }
    if (
      options.handle !== undefined &&
      (downEvent.target as Element).closest(options.handle) === null
    ) {
      return;
    }

    const startX = downEvent.clientX;
    const startY = downEvent.clientY;
    let started = false;
    let offsetX = 0;
    let offsetY = 0;
    let placeholder: HTMLElement | undefined;
    let originalStyle: string | null = null;

    const beginDrag = (): void => {
      started = true;
      const rect = item.getBoundingClientRect();
      offsetX = startX - rect.left;
      offsetY = startY - rect.top;
      originalStyle = item.getAttribute("style");

      // A same-size placeholder keeps the container's own layout from
      // collapsing into the dragged item's old spot while it's
      // `position: fixed` (real, visible behavior -- jQuery UI's own
      // sortable does this too, via its own `helper`/placeholder
      // pair). Copies the real item's own class list (the same
      // class-copying approach `vendor/selectize.ts`'s own
      // `$wrapper.addClass(classes)` already uses) rather than just
      // its size: `element_set_ranks.ts`'s own real thumbnail grid
      // floats its items (`float: left`), and a bare, unclassed
      // placeholder doesn't inherit that, so it never actually held a
      // slot in the row -- confirmed live, every sibling shifted left
      // into the dragged item's old spot instead.
      placeholder = document.createElement(item.tagName);
      placeholder.className = item.className;
      placeholder.style.width = rect.width + "px";
      placeholder.style.height = rect.height + "px";
      placeholder.style.visibility = "hidden";
      item.before(placeholder);

      item.setPointerCapture(downEvent.pointerId);
      item.style.position = "fixed";
      item.style.zIndex = "10000";
      item.style.width = rect.width + "px";
      item.style.left = rect.left + "px";
      item.style.top = rect.top + "px";
      item.style.pointerEvents = "none";
      if (options.opacity !== undefined) {
        item.style.opacity = String(options.opacity);
      }
    };

    const onMove = (moveEvent: PointerEvent): void => {
      if (!started) {
        const distance = Math.hypot(
          moveEvent.clientX - startX,
          moveEvent.clientY - startY,
        );
        if (distance < DISTANCE_THRESHOLD_PX) {
          return;
        }
        moveEvent.preventDefault();
        beginDrag();
      }

      const x = moveEvent.clientX - offsetX;
      const y = moveEvent.clientY - offsetY;
      if (options.axis !== "y") {
        item.style.left = x + "px";
      }
      if (options.axis !== "x") {
        item.style.top = y + "px";
      }

      reorder(
        container,
        item,
        placeholder!,
        moveEvent.clientX,
        moveEvent.clientY,
        options.axis,
      );
    };

    const onUp = (upEvent: PointerEvent): void => {
      document.removeEventListener("pointermove", onMove);
      document.removeEventListener("pointerup", onUp);
      if (!started) {
        return;
      }
      item.releasePointerCapture(upEvent.pointerId);
      placeholder!.replaceWith(item);
      if (originalStyle === null) {
        item.removeAttribute("style");
      } else {
        item.setAttribute("style", originalStyle);
      }
      options.update?.(container);
    };

    document.addEventListener("pointermove", onMove);
    document.addEventListener("pointerup", onUp);
  });
}

function reorder(
  container: HTMLElement,
  item: HTMLElement,
  placeholder: HTMLElement,
  pointerX: number,
  pointerY: number,
  axis: "x" | "y" | undefined,
): void {
  const candidates = siblings(container, item, placeholder);

  // Single-axis (menubar.ts's own real vertical list): no literal
  // "hovering over a box" requirement -- a few px of real margin
  // between consecutive `<li>`s (confirmed live: dropping exactly on
  // that gap silently matched nothing) would otherwise make some
  // pointer positions match no sibling at all. Insert before the
  // first sibling whose own midpoint the pointer has not yet reached;
  // past every sibling means append at the end.
  if (axis !== undefined) {
    for (const sibling of candidates) {
      const rect = sibling.getBoundingClientRect();
      const past =
        axis === "y"
          ? pointerY < rect.top + rect.height / 2
          : pointerX < rect.left + rect.width / 2;
      if (past) {
        sibling.before(placeholder);
        return;
      }
    }
    container.append(placeholder);
    return;
  }

  // Free/grid mode (element_set_ranks.ts's own real thumbnail grid):
  // still requires literally hovering over a candidate cell, since a
  // 2D grid can't collapse to a single "past/not past" ordering.
  for (const sibling of candidates) {
    const rect = sibling.getBoundingClientRect();
    const midX = rect.left + rect.width / 2;
    const midY = rect.top + rect.height / 2;
    const overlaps =
      pointerX >= rect.left &&
      pointerX <= rect.right &&
      pointerY >= rect.top &&
      pointerY <= rect.bottom;
    if (!overlaps) {
      continue;
    }
    const before =
      pointerY < midY || (pointerY < rect.bottom && pointerX < midX);
    if (before) {
      sibling.before(placeholder);
    } else {
      sibling.after(placeholder);
    }
    return;
  }
}

export function sortable(
  containers: Element | ArrayLike<Element>,
  options: SortableOptions = {},
): void {
  const list =
    containers instanceof Element ? [containers] : Array.from(containers);
  for (const container of list) {
    if (!(container instanceof HTMLElement)) {
      continue;
    }
    for (const item of siblings(container)) {
      bindItem(container, item, options);
    }
  }
}

/** `.sortable("toArray")` -- the container's own real ids, in DOM order. */
export function sortableToArray(container: Element): string[] {
  return Array.from(container.children)
    .filter((el): el is HTMLElement => el instanceof HTMLElement)
    .map((el) => el.id);
}
