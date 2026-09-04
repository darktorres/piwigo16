import { off, on } from "../utils/dom";

/**
 * Port of jquery-confirm v3.3.4's own `$.confirm`/`$.alert` (real source
 * read from the CDN, `jquery-confirm@3.3.4/js/jquery-confirm.js`) --
 * `$.dialog` and the `$.fn.confirm` jQuery.fn form are never used by any
 * real call site, so neither is ported.
 *
 * Every real call site across this app -- confirmed by grepping every
 * `$.confirm`/`$.alert`/`jQuery.confirm`/`jQuery.alert` call and
 * `themes/admin/default/js/common.ts`'s own 4 `jConfirm*Options`
 * presets -- sets `draggable: false`, `theme: "modern"`,
 * `animation: "zoom"`, `useBootstrap: false`, `animateFromElement: false`,
 * `typeAnimated: false`, `backgroundDismiss: true` and never deviates,
 * so none of the drag-to-reposition, theme system, bootstrap grid,
 * open-from-clicked-element positioning, pulsing type-color animation,
 * or background-shake-instead-of-close machinery is ported -- only a
 * single fixed "modern"/"zoom" modal, background click and Escape
 * (`escapeKey`'s own default of `true`, never overridden) both always
 * closing it. `columnClass`/`bgOpacity`/`rtl`/`autoClose`/`container`/
 * `scrollToPreviousElement*`/`offsetTop`/`offsetBottom` and every
 * `onOpenBefore`/`onOpen`/`onDestroy`/`onAction`/`contentLoaded` callback
 * are real options of the original too, never set by any real call site
 * here either. `onClose` is the one callback option that IS real,
 * load-bearing usage: `plugins/installed.ts`'s own
 * `confirmIncompatibleActivation()` relies on it firing for every
 * dismissal path (a button, background click, or Escape alike) to revert
 * a toggle switch that visually flips before the confirm even opens.
 *
 * `content` as a function returning a thenable exposing `.always()`
 * (this app's own `ajax()` helper's real `AjaxThenable` shape) is real,
 * load-bearing usage (`categories/modify.ts`, `plugins/installed.ts`,
 * `users/groupList.ts`, `tags.ts`'s own delete-tag/merge-tags flows): while
 * that thenable is pending, the modal opens with a loading spinner and
 * whatever content the caller's own success/error callback pushes via
 * the instance's `setContent()`, not a value read off the settled
 * thenable itself -- faithfully ported, not simplified away.
 * `tags.ts`'s own bulk-delete flow returns a bare native
 * `Promise.all(...).then(...)` instead, which has no `.always()` --
 * the original library's own `typeof res.always === 'function'` check
 * genuinely fails to detect that as ajax-loading (falls through to a
 * blank `&nbsp;` placeholder that's never replaced), a real existing
 * quirk of that one call site preserved exactly, not "fixed" here.
 */
export interface JConfirmInstance {
  setContent(html: string): void;
  showLoading(): void;
  hideLoading(): void;
  close(): void;
}

interface JConfirmButtonOptions {
  text?: string;
  btnClass?: string;
  action?: (this: JConfirmInstance) => unknown;
}

export interface JConfirmOptions {
  title?: string;
  content?: string | ((this: JConfirmInstance) => unknown);
  buttons?: Record<string, JConfirmButtonOptions> | false;
  icon?: string;
  titleClass?: string;
  type?: string;
  boxWidth?: string;
  closeIcon?: boolean;
  onClose?: () => void;
}

interface Thenable {
  always(handler: () => void): unknown;
}

function isThenable(value: unknown): value is Thenable {
  return (
    typeof value === "object" &&
    value !== null &&
    typeof (value as { always?: unknown }).always === "function"
  );
}

// Real, visible body-scroll-lock behavior the original always has
// (`body[class*=jconfirm-no-scroll-]{overflow:hidden!important}` in the
// vendored CSS this app still loads) -- reference-counted rather than a
// plain toggle so a dialog opened from inside another button's own
// `action` callback (this app has no real such call site today, but
// nothing here assumes it won't) can't have the outer dialog's own
// close prematurely unlock scrolling.
let openCount = 0;

function buildModal(options: JConfirmOptions): void {
  const el = document.createElement("div");
  el.className = "jconfirm jconfirm-modern";
  el.innerHTML =
    '<div class="jconfirm-bg jconfirm-bg-h"></div>' +
    '<div class="jconfirm-scrollpane">' +
    '<div class="jconfirm-row"><div class="jconfirm-cell">' +
    '<div class="jconfirm-holder"><div class="jc-bs3-container"><div class="jc-bs3-row">' +
    '<div class="jconfirm-box-container jconfirm-animated">' +
    '<div class="jconfirm-box" role="dialog" tabindex="-1">' +
    '<div class="jconfirm-closeIcon">&times;</div>' +
    '<div class="jconfirm-title-c"><span class="jconfirm-icon-c"></span><span class="jconfirm-title"></span></div>' +
    '<div class="jconfirm-content-pane"><div class="jconfirm-content"></div></div>' +
    '<div class="jconfirm-buttons"></div>' +
    '<div class="jconfirm-clear"></div>' +
    "</div></div></div></div></div></div></div>";

  const box = el.querySelector<HTMLElement>(".jconfirm-box")!;
  const bg = el.querySelector<HTMLElement>(".jconfirm-bg")!;
  const scrollPane = el.querySelector<HTMLElement>(".jconfirm-scrollpane")!;
  const titleEl = el.querySelector<HTMLElement>(".jconfirm-title")!;
  const titleContainer = el.querySelector<HTMLElement>(".jconfirm-title-c")!;
  const iconEl = el.querySelector<HTMLElement>(".jconfirm-icon-c")!;
  const contentEl = el.querySelector<HTMLElement>(".jconfirm-content")!;
  const buttonsEl = el.querySelector<HTMLElement>(".jconfirm-buttons")!;
  const closeIconEl = el.querySelector<HTMLElement>(".jconfirm-closeIcon")!;

  // The real CSS ties the open/close animation to *removing*/*adding*
  // these classes, not the reverse: `.jconfirm-box.jconfirm-animation-zoom`
  // is the *hidden* state (`opacity:0`, `scale(1.2)`); `_open()` removes
  // it to trigger the transition back to the base `opacity:1` state, and
  // `close()` adds `jconfirm-animation-scale` (the real, always-default
  // `closeAnimation`, distinct from the always-`zoom` open one) to
  // transition back out before the element is actually removed.
  box.classList.add("jconfirm-animation-zoom", `jconfirm-type-${options.type ?? "default"}`);
  box.style.width = options.boxWidth ?? "50%";
  if (options.titleClass !== undefined && options.titleClass !== "") {
    titleContainer.classList.add(...options.titleClass.split(/\s+/));
  }

  titleEl.textContent = options.title ?? "";
  if (options.icon !== undefined && options.icon !== "") {
    iconEl.innerHTML = `<i class="${options.icon}"></i>`;
  }

  let boxClicked = false;
  let closed = false;
  const onEscape = (e: Event): void => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keydown"/"keyup" always dispatches a real KeyboardEvent; this handler's own listener registration is typed generically via the native EventListener interface.
    if ((e as KeyboardEvent).key === "Escape") {
      close();
    }
  };
  function close(): void {
    if (closed) {
      return;
    }
    closed = true;
    off(document, "keyup", onEscape);
    options.onClose?.();
    bg.classList.add("jconfirm-bg-h");
    box.classList.add("jconfirm-animation-scale");
    setTimeout(() => {
      el.remove();
      openCount -= 1;
      if (openCount === 0) {
        document.body.classList.remove("jconfirm-no-scroll-modal");
      }
    }, 160);
  }
  const instance: JConfirmInstance = {
    setContent(html: string): void {
      contentEl.innerHTML = html;
    },
    showLoading(): void {
      box.classList.add("loading");
    },
    hideLoading(): void {
      box.classList.remove("loading");
    },
    close,
  };

  const buttonSpecs: Record<string, JConfirmButtonOptions> =
    options.buttons === false ? {} : (options.buttons ?? { ok: {} });

  for (const [key, spec] of Object.entries(buttonSpecs)) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = `btn ${spec.btnClass ?? "btn-default"}`;
    btn.innerHTML = spec.text ?? key;
    on(btn, "click", (e) => {
      e.preventDefault();
      const result = spec.action?.call(instance);
      if (result === undefined || result) {
        close();
      }
    });
    buttonsEl.appendChild(btn);
  }
  const hasButtons = Object.keys(buttonSpecs).length > 0;
  if (!hasButtons) {
    buttonsEl.style.display = "none";
  }

  // Real default is `false` (hidden), like the original -- forced to
  // `true` only when there are literally no buttons, since otherwise
  // the dialog would have no way to close at all.
  const showCloseIcon = options.closeIcon === true || !hasButtons;
  if (showCloseIcon) {
    on(closeIconEl, "click", (e) => {
      e.preventDefault();
      close();
    });
  } else {
    closeIconEl.style.display = "none";
  }

  on(scrollPane, "click", () => {
    if (!boxClicked) {
      close();
    }
    boxClicked = false;
  });
  on(box, "click", () => {
    boxClicked = true;
  });
  on(document, "keyup", onEscape);

  openCount += 1;
  document.body.classList.add("jconfirm-no-scroll-modal");
  document.body.appendChild(el);
  requestAnimationFrame(() => {
    bg.classList.remove("jconfirm-bg-h");
    box.classList.remove("jconfirm-animation-zoom");
    el.classList.add("jconfirm-open");
  });

  // A falsy `content` (unset, or an empty string -- `pwg_jconfirm_follow_href`'s
  // own default when the caller passes no `alert_content`) becomes a
  // literal `&nbsp;` placeholder in the original (`if(!this.content) this.content
  // = e`), not a collapsed-empty pane -- faithfully matched, not "cleaned up".
  const {content} = options;
  if (typeof content === "function") {
    const result = content.call(instance);
    if (isThenable(result)) {
      instance.showLoading();
      result.always(() => {
        instance.hideLoading();
      });
    } else {
      instance.setContent(typeof result === "string" && result ? result : "&nbsp;");
    }
  } else {
    instance.setContent(
      content !== undefined && content !== "" ? content : "&nbsp;",
    );
  }
}

export function confirm(options: JConfirmOptions): void {
  buildModal({
    ...options,
    buttons:
      options.buttons === false
        ? false
        : (options.buttons ?? { ok: {}, close: {} }),
  });
}

export function alert(options: JConfirmOptions): void {
  buildModal({
    ...options,
    buttons:
      options.buttons === false ? false : (options.buttons ?? { ok: {} }),
  });
}
