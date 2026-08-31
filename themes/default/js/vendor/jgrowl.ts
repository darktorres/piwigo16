import { fadeIn, fadeOut, hover, on } from "./dom";

/**
 * Port of jgrowl@1.3.0's own `$.jGrowl(message, options)` wrapper form
 * (real source read from the CDN, `jgrowl@1.3.0/jquery.jgrowl.js`) --
 * the only form either real call site (`updates_ext.ts`) uses, never the
 * per-container `$.fn.jGrowl()`/instance-method forms. Ported options:
 * `theme`, `header`, `life`, `sticky` -- the only ones any real call
 * site sets; `pool`, `group`, `position`, `glue`, `themeState`,
 * `corners`, `closer`/`closeTemplate`/`closerTemplate` customization,
 * and every callback (`log`/`beforeOpen`/`afterOpen`/`open`/
 * `beforeClose`/`close`) stay at the original's own defaults, so
 * nothing here makes them configurable.
 *
 * Two behaviors of the original are faithfully preserved, not
 * simplified away, because real usage exercises them: notifications
 * are queued and drained one per 250ms tick (not rendered
 * synchronously on call) -- real for a bulk `ignoreAll()`/`updateAll()`
 * loop that can fire several of these in a row -- and hovering *any*
 * notification pauses the auto-expiry countdown for *every*
 * notification in the container, not just the hovered one (the
 * original's own `mouseover.jGrowl` handler sets a shared pause flag
 * across the whole container).
 *
 * Not replicated: the original's permanent empty `<div
 * class="jGrowl-notification">` anchor node (`startup()`'s own
 * `.append('<div class="jGrowl-notification"></div>')`), which existed
 * only as a jQuery `:last`/`:first` selector target for glue-based
 * insertion. This port inserts directly (before the close-all button
 * when one exists, so it stays last; appended otherwise), reaching the
 * identical end DOM order without needing an inert placeholder node --
 * confirmed harmless against `updates_ext.ts`'s own `#jGrowl > *`
 * MutationObserver, which only ever looks for `.success`/`.error`
 * classes no anchor node would carry anyway.
 */
export interface JGrowlOptions {
  theme?: string;
  header?: string;
  life?: number;
  sticky?: boolean;
}

interface PendingNotification {
  message: string;
  theme: string;
  header: string;
  life: number;
  sticky: boolean;
}

interface RenderedNotification {
  el: HTMLElement;
  life: number;
  sticky: boolean;
  createdAt: number | null;
}

let container: HTMLElement | null = null;
const pending: PendingNotification[] = [];
const rendered: RenderedNotification[] = [];
let paused = false;

function ensureContainer(): HTMLElement {
  if (container !== null) {
    return container;
  }

  container = document.createElement("div");
  container.id = "jGrowl";
  container.className = "jGrowl top-right";
  document.body.appendChild(container);
  setInterval(tick, 250);

  return container;
}

function tick(): void {
  if (!paused) {
    const now = Date.now();
    for (const notification of rendered.slice()) {
      if (
        !notification.sticky &&
        notification.createdAt !== null &&
        notification.createdAt + notification.life < now
      ) {
        closeNotification(notification);
      }
    }
  }

  const next = pending.shift();
  if (next !== undefined) {
    render(next);
  }
}

function closeNotification(notification: RenderedNotification): void {
  const index = rendered.indexOf(notification);
  if (index === -1) {
    return;
  }
  rendered.splice(index, 1);
  updateCloser();
  fadeOut(notification.el, "normal", () => {
    notification.el.remove();
  });
}

function updateCloser(): void {
  const c = ensureContainer();
  const existing = c.querySelector<HTMLElement>(".jGrowl-closer");

  if (rendered.length > 1) {
    if (existing !== null) {
      return;
    }
    const closer = document.createElement("div");
    closer.className = "jGrowl-closer ui-state-highlight ui-corner-all default";
    closer.innerHTML = "[ close all ]";
    on(closer, "click", () => {
      for (const notification of rendered.slice()) {
        closeNotification(notification);
      }
    });
    c.appendChild(closer);
    fadeIn(closer, "normal");
  } else if (existing !== null) {
    fadeOut(existing, "normal", () => {
      existing.remove();
    });
  }
}

function render(p: PendingNotification): void {
  const c = ensureContainer();

  const el = document.createElement("div");
  el.className = `jGrowl-notification ui-state-highlight ui-corner-all ${p.theme}`;

  const closeEl = document.createElement("div");
  closeEl.className = "jGrowl-close";
  closeEl.innerHTML = "&times;";
  el.appendChild(closeEl);

  const headerEl = document.createElement("div");
  headerEl.className = "jGrowl-header";
  headerEl.innerHTML = p.header;
  el.appendChild(headerEl);

  const messageEl = document.createElement("div");
  messageEl.className = "jGrowl-message";
  messageEl.innerHTML = p.message;
  el.appendChild(messageEl);

  const notification: RenderedNotification = {
    el,
    life: p.life,
    sticky: p.sticky,
    createdAt: null,
  };

  hover(
    el,
    () => {
      paused = true;
    },
    () => {
      paused = false;
    }
  );
  on(closeEl, "click", () => {
    closeNotification(notification);
  });

  const closer = c.querySelector(".jGrowl-closer");
  if (closer !== null) {
    c.insertBefore(el, closer);
  } else {
    c.appendChild(el);
  }

  rendered.push(notification);
  updateCloser();

  fadeIn(el, "normal", () => {
    notification.createdAt = Date.now();
  });
}

export function jGrowl(message: string, options: JGrowlOptions = {}): void {
  ensureContainer();
  pending.push({
    message,
    theme: options.theme ?? "default",
    header: options.header ?? "",
    life: options.life ?? 3000,
    sticky: options.sticky ?? false,
  });
}
