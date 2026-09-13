// Native port of PhotoSwipe (P61 Phase 6), real source read from the
// vendored `photoswipe`/`photoswipe-ui-default` package
// (`bootstrap_darkroom_16.d/node_modules/photoswipe/dist/*.js`,
// v4.1.3 -- the legacy theme's own real vendored version, NOT v5 as
// P61's own plan first assumed before this file was written; corrected
// there). Legacy's real `_photoswipe_js.tpl`/`_photoswipe_div.tpl` are
// the two real call sites this is narrowed against.
//
// This engine is deliberately theme-agnostic and lives here (not in the
// sibling `bootstrap_darkroom` package) -- same convention as
// `colorbox.ts`: a real, reusable ported widget any theme can adopt.
// Darkroom's own multi-resolution-by-viewport image switching, inline
// video playback, autoplay, and social-share wiring (all real legacy
// features, but genuinely theme-specific integration logic, not part of
// the core library) are NOT ported here -- they belong in the theme's
// own TS, built against this module's real `PhotoSwipeItem`/options
// surface once Phase 6's own picture/index wiring lands (grounded
// against real callers, same "no speculative surface" discipline as
// `ImageReadFacade`'s own docblock states).
//
// Dropped entirely as dead code in every currently-supported browser:
// IE8/old-Android/old-iOS/old-Opera-Mobile detection and every branch
// gated on it, vendor-prefixed CSS property probing, the separate
// touch/mouse/MSPointer event-name branching (native `PointerEvent`
// unifies all three natively, including real multi-touch via multiple
// concurrent `pointerId`s -- a real modernization, not a fidelity cut).
// URL-hash deep-linking (`history.js`, `options.history`) is a
// self-contained routing feature with no bearing on the gesture/zoom
// experience and is not ported -- legacy's own real call site
// (`_photoswipe_js.tpl`) already disables it on IE Mobile 11 as a
// special case, confirming it was never treated as load-bearing.
//
// Real gesture/zoom math kept faithfully: the 3-holder rotating DOM
// window (only 3 slide containers ever exist, `NUM_HOLDERS`), pan
// bounds computed from fit-ratio/zoom level, pinch-zoom via 2-pointer
// center/distance tracking with edge friction and background-fade
// pinch-to-close, horizontal swipe-to-navigate blended with
// pan-when-zoomed (`_panOrMoveMainScroll`'s own real blending logic),
// momentum/flick deceleration on release (`slowDownRatio` decay loop),
// vertical drag-to-close with opacity fade, double-tap/double-click
// zoom toggle, mouse-wheel pan-when-zoomed or close-when-not, and the
// "grow from thumbnail" open/close transition.
import { valueAt } from "../utils/dom";

let gallery: PhotoSwipeGallery | undefined;

export interface PhotoSwipeItem {
  src: string;
  w: number;
  h: number;
  /** Caption HTML, matching legacy's own `item.title`. */
  title?: string;
}

export interface ShareButton {
  id: string;
  label: string;
  /** `{{url}}`/`{{image_url}}`/`{{raw_image_url}}`/`{{text}}` placeholders. */
  url: string;
  download?: boolean;
}

export interface PhotoSwipeOptions {
  /** Real per-element index override; defaults to this element's position among its `rel` group. */
  index?: number;
  rel?: string;
  /** Resolves the real gallery items for this element's group, lazily -- called once, on open. */
  getItems?: () => PhotoSwipeItem[];
  /** Thumbnail rect to grow the open/close animation from -- omit for a plain fade. */
  getThumbBounds?: (index: number) => DOMRect | undefined;
  shareButtons?: ShareButton[];
  getShareText?: (index: number) => string;
  autoplayIntervalMs?: number;
  /** Fired every time the current slide changes, real index. Darkroom's own multi-res/video wiring hooks in here. */
  onChange?: (index: number, item: PhotoSwipeItem) => void;
  onClose?: () => void;
}

const NUM_HOLDERS = 3;
const MIN_SWIPE_DISTANCE = 30;
const DIRECTION_CHECK_OFFSET = 10;
const DOUBLE_TAP_RADIUS = 25;
const DOUBLE_TAP_DELAY_MS = 300;
const MAX_SPREAD_ZOOM = 1.33;
const PAN_END_FRICTION = 0.35;
const SPACING = 0.12;
const VERTICAL_DRAG_RANGE = 0.75;
const SHOW_HIDE_DURATION = 333;

function sineOut(k: number): number {
  return Math.sin(k * (Math.PI / 2));
}

function cubicOut(k: number): number {
  const k1 = k - 1;
  return k1 * k1 * k1 + 1;
}

function sineInOut(k: number): number {
  return -(Math.cos(Math.PI * k) - 1) / 2;
}

interface Point {
  x: number;
  y: number;
}

function point(x = 0, y = 0): Point {
  return { x, y };
}

function makeButton(name: string, title: string): HTMLButtonElement {
  const btn = document.createElement("button");
  btn.type = "button";
  btn.className = `pswp__button pswp__button--${name}`;
  btn.title = title;
  return btn;
}

function applyItemTransform(item: SlideItem, zoomWrap: HTMLDivElement): void {
  const zoom = item.initialZoomLevel / item.fitRatio;
  zoomWrap.style.transform = `translate3d(${String(item.initialPosition.x)}px, ${String(item.initialPosition.y)}px, 0) scale(${String(zoom)})`;
}

function zoomWrapOf(holder: Holder): HTMLDivElement | null {
  return holder.el.querySelector<HTMLDivElement>(".pswp__zoom-wrap");
}

/** Small requestAnimationFrame tween -- matches `_animateProp()`'s own shape, kept as a plain function since every call site drives gesture-specific mutable state directly, the same reason `colorbox.ts`'s own `animateBox()` isn't factored into the shared `dom.ts` helpers. */
function animate(duration: number, easing: (k: number) => number, onUpdate: (now: number) => void, onComplete?: () => void): void {
  const start = performance.now();
  const tick = (): void => {
    const elapsed = performance.now() - start;
    const fraction = Math.min(1, elapsed / duration);
    onUpdate(easing(fraction));
    if (fraction < 1) {
      requestAnimationFrame(tick);
    } else {
      onComplete?.();
    }
  };
  requestAnimationFrame(tick);
}

// ── Registration (matches colorbox.ts's own WeakMap pattern) ──────────────

const optionsByElement = new WeakMap<Element, PhotoSwipeOptions>();
const registeredElements: Element[] = [];

function getRel(el: Element, opts: PhotoSwipeOptions): string {
  return opts.rel ?? el.getAttribute("rel") ?? "";
}

function computeRelated(el: Element, opts: PhotoSwipeOptions): { list: Element[]; index: number } {
  const rel = getRel(el, opts);
  let list: Element[];
  if (rel) {
    list = registeredElements.filter(
      (candidate) => getRel(candidate, optionsByElement.get(candidate) ?? {}) === rel
    );
    if (!list.includes(el)) {
      list = [...list, el];
    }
  } else {
    list = [el];
  }
  return { list, index: list.indexOf(el) };
}

export function photoswipe(elements: Element | ArrayLike<Element>, options: PhotoSwipeOptions = {}): void {
  const list = elements instanceof Element ? [elements] : Array.from(elements);
  for (const el of list) {
    if (!optionsByElement.has(el)) {
      registeredElements.push(el);
      el.addEventListener("click", (event) => {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "click" always dispatches a real MouseEvent.
        clickHandler(el, event as MouseEvent);
      });
    }
    optionsByElement.set(el, options);
  }
}

/** Opens the lightbox directly against a real items array -- darkroom's own thumbnail-grid/picture-page wiring calls this, not the click-registration path, since it already has the full gallery set assembled. */
export function openPhotoSwipe(items: PhotoSwipeItem[], index: number, options: PhotoSwipeOptions = {}): void {
  openGallery(items, index, options);
}

export function closePhotoSwipe(): void {
  gallery?.close();
}

// ── Gallery ─────────────────────────────────────────────────────────────

interface SlideItem extends PhotoSwipeItem {
  fitRatio: number;
  initialZoomLevel: number;
  initialPosition: Point;
  boundsCenter: Point;
  boundsMin: Point;
  boundsMax: Point;
  vGapTop: number;
  img?: HTMLImageElement;
  loaded: boolean;
  loading: boolean;
  loadError: boolean;
}

interface Holder {
  el: HTMLDivElement;
  index: number;
}

interface PanBounds {
  center: Point;
  min: Point;
  max: Point;
}

function makeSlideItem(item: PhotoSwipeItem): SlideItem {
  return {
    ...item,
    fitRatio: 1,
    initialZoomLevel: 1,
    initialPosition: point(),
    boundsCenter: point(),
    boundsMin: point(),
    boundsMax: point(),
    vGapTop: 0,
    loaded: false,
    loading: false,
    loadError: false,
  };
}

class PhotoSwipeGallery {
  private readonly items: SlideItem[];
  private readonly options: PhotoSwipeOptions;
  private currentIndex: number;
  // -1, not 0: open()'s initial setContent() loop puts the CURRENT slide
  // in holders[1] (holders[0]/[2] get currentIndex-1/+1), and
  // updateSize() positions holder i at (i + containerShiftIndex) *
  // slideSize.x with containerShiftIndex starting at 0 -- so holders[1]
  // sits at +1 slide-width until the container itself is shifted back by
  // -1 slide-width (mainScrollPos = slideSize.x * positionIndex) to
  // bring it to the visible x=0 position. A 0 default shows holders[0]
  // (currentIndex - 1, wrapped to the LAST item when currentIndex is 0)
  // instead of the real current slide -- a real bug this port's own
  // first live picture-page verification caught (P61 Phase 7-B).
  private positionIndex = -1;
  private containerShiftIndex = 0;
  private indexDiff = 0;

  private readonly root: HTMLDivElement;
  private readonly bg: HTMLDivElement;
  private readonly scrollWrap: HTMLDivElement;
  private readonly container: HTMLDivElement;
  private readonly holders: [Holder, Holder, Holder];
  private readonly ui: {
    root: HTMLDivElement;
    caption: HTMLDivElement;
    counter: HTMLDivElement;
    closeBtn: HTMLButtonElement;
    prevBtn: HTMLButtonElement;
    nextBtn: HTMLButtonElement;
    zoomBtn: HTMLButtonElement;
    fsBtn: HTMLButtonElement;
    autoplayBtn: HTMLButtonElement;
    shareBtn: HTMLButtonElement;
    shareModal: HTMLDivElement;
    preloader: HTMLDivElement;
  };

  private viewportSize: Point = point();
  private slideSize: Point = point();
  private mainScrollPos = 0;
  private zoomLevel = 1;
  private startZoomLevel = 1;
  private panOffset: Point = point();
  private startPanOffset: Point = point();
  private currPanDist: Point = point();
  private bgOpacity = 1;

  private isOpen = false;
  private isDragging = false;
  private isZooming = false;
  private moved = false;
  private zoomStarted = false;
  private opacityChanged = false;
  private verticalDragInitiated = false;
  private wasOverInitialZoom = false;
  private direction: "h" | "v" | null = null;
  private isFirstMove = false;
  private mainScrollShifted = false;
  private mainScrollAnimating = false;

  private readonly pointers = new Map<number, Point>();
  private startPoint: Point = point();
  private currPoint: Point = point();
  private startMainScrollPos = 0;
  private midZoomPoint: Point = point();
  private currCenterPoint: Point = point();
  private startPointsDistance = 0;
  private posPoints: { t: number; x: number; y: number }[] = [];
  private gestureStartTime = 0;
  private gestureCheckSpeedTime = 0;

  private rafId: number | undefined;
  private momentumRafId: number | undefined;
  private controlsVisible = true;
  private idleTimer: ReturnType<typeof setTimeout> | undefined;
  private autoplayId: ReturnType<typeof setInterval> | undefined;
  private lastFocusedEl: HTMLElement | null = null;
  private lastTapTime = 0;
  private lastTapPoint: Point = point();

  // stopImmediatePropagation() on every handled key, registered on the
  // CAPTURE phase (see open()'s own addEventListener(..., true)) -- a
  // host page's own document-level keyboard shortcuts (e.g. darkroom's
  // real picture-page previous/next-photo arrow-key navigation,
  // `pictureNavButtons.ts`, registered on the same "keydown"/document
  // target, at PAGE LOAD -- before the lightbox's own listener, which
  // only attaches when a gallery actually opens) must not ALSO react to
  // the same event while the lightbox is open: preventDefault() alone
  // only suppresses the browser's own default action, and a later-
  // registered bubble-phase listener can never preempt an
  // earlier-registered one -- only a capture-phase listener fires
  // before ANY bubble-phase listener, registration order notwithstanding.
  // A real bug this port's own first live keyboard-navigation test
  // caught (P61 Phase 7-B): ArrowRight navigated the underlying page
  // away entirely instead of just advancing the lightbox.
  private readonly onKeyDown = (e: KeyboardEvent): void => {
    if (e.key === "Escape") {
      e.preventDefault();
      e.stopImmediatePropagation();
      this.close();
    } else if (e.key === "ArrowLeft" && !e.altKey && !e.ctrlKey && !e.metaKey) {
      e.preventDefault();
      e.stopImmediatePropagation();
      this.prev();
    } else if (e.key === "ArrowRight" && !e.altKey && !e.ctrlKey && !e.metaKey) {
      e.preventDefault();
      e.stopImmediatePropagation();
      this.next();
    }
  };

  private readonly onResize = (): void => {
    this.updateSize();
  };

  private readonly onWheel = (e: WheelEvent): void => {
    this.handleWheel(e);
  };

  constructor(items: SlideItem[], index: number, options: PhotoSwipeOptions) {
    this.items = items;
    this.options = options;
    this.currentIndex = Math.min(Math.max(index, 0), items.length - 1);

    this.root = document.createElement("div");
    this.root.className = "pswp";
    this.root.tabIndex = -1;
    this.root.setAttribute("role", "dialog");
    this.root.setAttribute("aria-hidden", "true");

    this.bg = document.createElement("div");
    this.bg.className = "pswp__bg";

    this.scrollWrap = document.createElement("div");
    this.scrollWrap.className = "pswp__scroll-wrap";

    this.container = document.createElement("div");
    this.container.className = "pswp__container";
    const makeHolder = (): Holder => {
      const el = document.createElement("div");
      el.className = "pswp__item";
      this.container.append(el);
      return { el, index: -1 };
    };
    this.holders = [makeHolder(), makeHolder(), makeHolder()];

    this.ui = this.buildUi();

    this.scrollWrap.append(this.container, this.ui.root);
    this.root.append(this.bg, this.scrollWrap);
  }

  private buildUi(): PhotoSwipeGallery["ui"] {
    const root = document.createElement("div");
    // No pswp__ui--hidden here: controlsVisible (this.controlsVisible)
    // defaults to true, and that class is only ever added/removed by
    // toggleControls() -- hardcoding it here left the UI permanently
    // invisible (though still clickable, opacity alone doesn't disable
    // pointer events) until a tap toggled it off then on again. A real
    // bug this port's own first live open-animation use caught (P61
    // Phase 7-B).
    root.className = "pswp__ui";

    const topBar = document.createElement("div");
    topBar.className = "pswp__top-bar";

    const counter = document.createElement("div");
    counter.className = "pswp__counter";

    const closeBtn = makeButton("close", "Close (Esc)");
    const shareBtn = makeButton("share", "Share");
    const fsBtn = makeButton("fs", "Toggle fullscreen");
    const zoomBtn = makeButton("zoom", "Zoom in/out");
    const autoplayBtn = makeButton("autoplay", "Slideshow");

    const preloader = document.createElement("div");
    preloader.className = "pswp__preloader";

    topBar.append(counter, shareBtn, fsBtn, zoomBtn, autoplayBtn, closeBtn, preloader);

    const shareModal = document.createElement("div");
    shareModal.className = "pswp__share-modal pswp__share-modal--hidden";
    const shareTooltip = document.createElement("div");
    shareTooltip.className = "pswp__share-tooltip";
    shareModal.append(shareTooltip);

    const prevBtn = makeButton("arrow--left", "Previous (arrow left)");
    const nextBtn = makeButton("arrow--right", "Next (arrow right)");

    const caption = document.createElement("div");
    caption.className = "pswp__caption";
    const captionCenter = document.createElement("div");
    captionCenter.className = "pswp__caption__center";
    caption.append(captionCenter);

    root.append(topBar, shareModal, prevBtn, nextBtn, caption);

    closeBtn.addEventListener("click", () => {
      this.close();
    });
    prevBtn.addEventListener("click", () => {
      this.prev();
    });
    nextBtn.addEventListener("click", () => {
      this.next();
    });
    zoomBtn.addEventListener("click", () => {
      this.toggleZoom();
    });
    fsBtn.addEventListener("click", () => {
      this.toggleFullscreen();
    });
    shareBtn.addEventListener("click", () => {
      this.toggleShareModal();
    });
    autoplayBtn.addEventListener("click", () => {
      this.toggleAutoplay();
    });

    return { root, caption, counter, closeBtn, prevBtn, nextBtn, zoomBtn, fsBtn, autoplayBtn, shareBtn, shareModal, preloader };
  }


  private get currItem(): SlideItem {
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- currentIndex is always kept in [0, items.length).
    return this.items[this.currentIndex]!;
  }

  private getLoopedIndex(index: number): number {
    const n = this.items.length;
    if (index > n - 1) {
      return index - n;
    }
    if (index < 0) {
      return n + index;
    }
    return index;
  }

  open(): void {
    document.body.append(this.root);
    document.body.style.overflow = "hidden";
    this.lastFocusedEl = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    this.updateSize();
    for (let i = 0; i < NUM_HOLDERS; i++) {
      const index = this.currentIndex + i - 1;
      this.setContent(valueAt(this.holders, i), index);
    }
    // calculateItemSize() only needs item.w/item.h (already known from
    // construction, not the actual loaded <img>) -- called eagerly here
    // so updateCurrZoomItem()/applyPan()/playOpenAnimation() below read
    // this item's real fitRatio/initialZoomLevel/initialPosition rather
    // than makeSlideItem()'s placeholder defaults (zoomLevel 1, position
    // {0,0}), which loadItem()'s own async completion callback wouldn't
    // fix until well after the "grow from thumbnail" animation already
    // captured its (wrong) destination. A real bug this port's own first
    // live use of `getThumbBounds` caught (P61 Phase 7-B).
    this.calculateItemSize(this.currItem);
    this.updateCurrZoomItem();
    this.applyPan();

    this.isOpen = true;
    this.root.setAttribute("aria-hidden", "false");

    document.addEventListener("keydown", this.onKeyDown, true);
    window.addEventListener("resize", this.onResize);
    this.root.addEventListener("wheel", this.onWheel, { passive: false });
    this.scrollWrap.addEventListener("pointerdown", this.onPointerDown);

    const thumbBounds = this.options.getThumbBounds?.(this.currentIndex);
    this.playOpenAnimation(thumbBounds);

    this.updateUi();
    this.root.focus();
  }

  close(): void {
    if (!this.isOpen) {
      return;
    }
    this.isOpen = false;
    this.removeListeners();
    this.stopAutoplay();
    this.stopRenderLoop();
    if (this.momentumRafId !== undefined) {
      cancelAnimationFrame(this.momentumRafId);
    }

    const thumbBounds = this.options.getThumbBounds?.(this.currentIndex);
    this.playCloseAnimation(thumbBounds, () => {
      this.destroy();
      this.options.onClose?.();
    });
  }

  /**
   * Also called directly by `openGallery()` (via `gallery?.destroy()`)
   * when one gallery replaces another still open one, bypassing
   * `close()`'s own animation/cleanup entirely -- `removeListeners()`
   * must run here too, or the replaced instance's `keydown`/`resize`/
   * `wheel`/`pointerdown` listeners leak on `document`/`window` forever.
   * A real bug this port's own first live use of `stopImmediatePropagation()`
   * surfaced (P61 Phase 7-B): a leaked, stale instance's own `onKeyDown`
   * fired first (registration order) and stopped the real, current
   * instance's own handler from ever running. `removeEventListener()` on
   * an already-detached listener is a safe no-op, so calling this
   * unconditionally from both paths is correct either way.
   */
  destroy(): void {
    this.removeListeners();
    document.body.style.overflow = "";
    this.root.remove();
    this.lastFocusedEl?.focus();
    if (gallery === this) {
      gallery = undefined;
    }
  }

  private removeListeners(): void {
    document.removeEventListener("keydown", this.onKeyDown, true);
    window.removeEventListener("resize", this.onResize);
    this.root.removeEventListener("wheel", this.onWheel);
    this.scrollWrap.removeEventListener("pointerdown", this.onPointerDown);
  }

  // ── slide content / sizing ───────────────────────────────────────────

  private setContent(holder: Holder, rawIndex: number): void {
    const index = this.getLoopedIndex(rawIndex);
    const item = this.items[index];
    holder.el.innerHTML = "";
    holder.index = index;
    if (!item) {
      return;
    }

    const zoomWrap = document.createElement("div");
    zoomWrap.className = "pswp__zoom-wrap";
    holder.el.append(zoomWrap);

    if (item.loaded && item.img) {
      zoomWrap.append(item.img);
      this.calculateItemSize(item);
      applyItemTransform(item, zoomWrap);
      return;
    }

    const preloaderIcon = document.createElement("div");
    preloaderIcon.className = "pswp__img-placeholder";
    zoomWrap.append(preloaderIcon);

    this.loadItem(index, item, (loaded) => {
      if (holder.index !== index) {
        return;
      }
      preloaderIcon.remove();
      if (loaded.loadError || !loaded.img) {
        return;
      }
      this.calculateItemSize(loaded);
      zoomWrap.append(loaded.img);
      applyItemTransform(loaded, zoomWrap);
      if (index === this.currentIndex) {
        this.updateCurrZoomItem();
        this.applyPan();
      }
    });
  }

  private loadItem(index: number, item: SlideItem, onDone: (item: SlideItem) => void): void {
    if (item.loaded || item.loading) {
      if (item.loaded) {
        onDone(item);
      }
      return;
    }
    item.loading = true;
    const img = new Image();
    img.className = "pswp__img";
    img.alt = item.title ?? "";
    img.addEventListener("load", () => {
      item.loading = false;
      item.loaded = true;
      item.img = img;
      this.options.onChange?.(index, item);
      onDone(item);
    });
    img.addEventListener("error", () => {
      item.loading = false;
      item.loadError = true;
      onDone(item);
    });
    img.src = item.src;
  }

  private calculateItemSize(item: SlideItem): void {
    const areaW = this.viewportSize.x;
    const areaH = this.viewportSize.y - item.vGapTop;
    const hRatio = areaW / item.w;
    const vRatio = areaH / item.h;
    item.fitRatio = Math.min(hRatio, vRatio, 1);
    item.initialZoomLevel = item.fitRatio;

    const w = item.w * item.fitRatio;
    const h = item.h * item.fitRatio;
    item.boundsCenter = point(Math.round((areaW - w) / 2), Math.round((areaH - h) / 2) + item.vGapTop);
    item.boundsMin = { ...item.boundsCenter };
    item.boundsMax = { ...item.boundsCenter };
    item.initialPosition = { ...item.boundsCenter };
  }

  private panBoundsFor(item: SlideItem, zoomLevel: number): PanBounds {
    const w = item.w * zoomLevel;
    const h = item.h * zoomLevel;
    const areaW = this.viewportSize.x;
    const areaH = this.viewportSize.y - item.vGapTop;
    const center = point(Math.round((areaW - w) / 2), Math.round((areaH - h) / 2) + item.vGapTop);
    const min = point(
      w > areaW ? 0 : center.x,
      h > areaH ? item.vGapTop : center.y
    );
    const max = point(
      w > areaW ? Math.round(areaW - w) : center.x,
      h > areaH ? Math.round(areaH - h) + item.vGapTop : center.y
    );
    return { center, min, max };
  }

  private applyPan(): void {
    const zoomWrap = zoomWrapOf(this.holders[1]);
    if (!zoomWrap) {
      return;
    }
    const zoom = this.zoomLevel / this.currItem.fitRatio;
    zoomWrap.style.transform = `translate3d(${String(this.panOffset.x)}px, ${String(this.panOffset.y)}px, 0) scale(${String(zoom)})`;
  }

  private setMainScroll(x: number): void {
    this.mainScrollPos = x;
    this.container.style.transform = `translate3d(${String(x)}px, 0, 0)`;
  }

  updateSize(): void {
    this.viewportSize = point(this.scrollWrap.clientWidth, this.scrollWrap.clientHeight);
    this.slideSize = point(this.viewportSize.x + Math.round(this.viewportSize.x * SPACING), this.viewportSize.y);
    this.setMainScroll(this.slideSize.x * this.positionIndex);

    for (let i = 0; i < NUM_HOLDERS; i++) {
      const holder = valueAt(this.holders, i);
      holder.el.style.transform = `translate3d(${String((i + this.containerShiftIndex) * this.slideSize.x)}px, 0, 0)`;
      const item = this.items[holder.index];
      if (item?.loaded === true) {
        this.calculateItemSize(item);
        const zoomWrap = zoomWrapOf(holder);
        if (zoomWrap) {
          applyItemTransform(item, zoomWrap);
        }
      }
    }
    this.updateCurrZoomItem();
    this.applyPan();
  }

  private updateCurrZoomItem(): void {
    const bounds = this.panBoundsFor(this.currItem, this.currItem.initialZoomLevel);
    this.startZoomLevel = this.zoomLevel = this.currItem.initialZoomLevel;
    this.panOffset = { ...bounds.center };
  }

  // ── navigation ────────────────────────────────────────────────────────

  goTo(index: number): void {
    const looped = this.getLoopedIndex(index);
    const diff = looped - this.currentIndex;
    this.indexDiff = diff;
    this.currentIndex = looped;
    this.positionIndex -= diff;
    this.setMainScroll(this.slideSize.x * this.positionIndex);
    this.updateCurrItem();
  }

  next(): void {
    this.goTo(this.currentIndex + 1);
  }

  prev(): void {
    this.goTo(this.currentIndex - 1);
  }

  private updateCurrItem(): void {
    if (this.indexDiff === 0) {
      return;
    }
    let diffAbs = Math.abs(this.indexDiff);
    if (diffAbs >= NUM_HOLDERS) {
      this.containerShiftIndex += this.indexDiff + (this.indexDiff > 0 ? -NUM_HOLDERS : NUM_HOLDERS);
      diffAbs = NUM_HOLDERS;
    }
    for (let i = 0; i < diffAbs; i++) {
      if (this.indexDiff > 0) {
        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- this.holders is a fixed 3-element tuple; shift() never actually empties it.
        const holder = this.holders.shift()!;
        this.holders.push(holder);
        this.containerShiftIndex++;
        holder.el.style.transform = `translate3d(${String((this.containerShiftIndex + 2) * this.slideSize.x)}px, 0, 0)`;
        this.setContent(holder, this.currentIndex - diffAbs + i + 2);
      } else {
        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- this.holders is a fixed 3-element tuple; pop() never actually empties it.
        const holder = this.holders.pop()!;
        this.holders.unshift(holder);
        this.containerShiftIndex--;
        holder.el.style.transform = `translate3d(${String(this.containerShiftIndex * this.slideSize.x)}px, 0, 0)`;
        this.setContent(holder, this.currentIndex + diffAbs - i - 2);
      }
    }
    this.indexDiff = 0;
    // Same real reason as open()'s own eager call: the new current item
    // may not have finished loading yet, and updateCurrZoomItem()/
    // applyPan() below need its real fitRatio/initialZoomLevel/
    // initialPosition, not makeSlideItem()'s placeholder defaults.
    this.calculateItemSize(this.currItem);
    this.updateCurrZoomItem();
    this.applyPan();
    this.updateUi();
    this.options.onChange?.(this.currentIndex, this.currItem);
  }

  // ── gestures: pointer down/move/up, unified via native PointerEvent ─────
  //
  // Real math kept from `_onDragStart`/`_onDragMove`/`_renderMovement`/
  // `_onDragRelease`: direction-lock after DIRECTION_CHECK_OFFSET px,
  // horizontal drag blends "pan the zoomed image" and "move the main
  // scroll toward next/prev slide" (`panOrMoveMainScroll`), 2-pointer
  // pinch drives both zoom level and pan (center-point tracking), edge
  // friction when panning/zooming past bounds, vertical drag fades
  // opacity toward close.

  private readonly onPointerDown = (e: PointerEvent): void => {
    if (e.button > 0) {
      return;
    }
    const target = e.target instanceof Element ? e.target : null;
    // Real click-through allowance: legacy's own `isClickableElement`
    // only ever matches `<a>` (share/caption links) -- buttons (close/
    // share/fs/zoom/autoplay/prev/next) are real UI controls with their
    // own click handlers already, and must never start a pan/pinch
    // gesture or take pointer capture, or their own click never fires.
    // A real bug this port's own first live click-through-a-button test
    // caught (P61 Phase 7-B): the condition below only ever checked
    // `.pswp__caption a` -- the `<button>` half of its own docblock's
    // stated intent was never actually implemented.
    if (target?.closest("a, button") && this.pointers.size === 0) {
      return;
    }
    e.preventDefault();
    this.pointers.set(e.pointerId, point(e.pageX, e.pageY));
    this.scrollWrap.setPointerCapture(e.pointerId);
    this.scrollWrap.addEventListener("pointermove", this.onPointerMove);
    this.scrollWrap.addEventListener("pointerup", this.onPointerUp);
    this.scrollWrap.addEventListener("pointercancel", this.onPointerUp);

    const numPoints = this.pointers.size;
    if (!this.isDragging || numPoints === 1) {
      this.isDragging = this.isFirstMove = true;
      this.zoomStarted = this.opacityChanged = this.verticalDragInitiated = this.mainScrollShifted = this.moved = this.wasOverInitialZoom = false;
      this.direction = null;
      this.startPanOffset = { ...this.panOffset };
      this.currPanDist = point();
      this.startPoint = this.currPoint = point(e.pageX, e.pageY);
      this.startMainScrollPos = this.slideSize.x * this.positionIndex;
      this.posPoints = [{ t: performance.now(), x: e.pageX, y: e.pageY }];
      this.gestureCheckSpeedTime = this.gestureStartTime = performance.now();
      this.startRenderLoop();
    }

    if (!this.isZooming && numPoints > 1) {
      const pts = [...this.pointers.values()];
      this.startZoomLevel = this.zoomLevel;
      this.isZooming = true;
      this.currPanDist = point();
      this.startPanOffset = { ...this.panOffset };
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- numPoints > 1 guarantees at least 2 entries.
      const [p1, p2] = [pts[0]!, pts[1]!];
      this.currCenterPoint = point((p1.x + p2.x) / 2, (p1.y + p2.y) / 2);
      this.midZoomPoint = point(Math.abs(this.currCenterPoint.x) - this.panOffset.x, Math.abs(this.currCenterPoint.y) - this.panOffset.y);
      this.startPointsDistance = Math.hypot(p1.x - p2.x, p1.y - p2.y);
    }
  };

  private pendingMove: PointerEvent | undefined;

  private readonly onPointerMove = (e: PointerEvent): void => {
    e.preventDefault();
    const p = this.pointers.get(e.pointerId);
    if (p) {
      p.x = e.pageX;
      p.y = e.pageY;
    }
    if (!this.isDragging) {
      return;
    }
    if (!this.direction && !this.moved && !this.isZooming) {
      if (this.mainScrollPos !== this.slideSize.x * this.positionIndex) {
        this.direction = "h";
      } else {
        const diff = Math.abs(e.pageX - this.currPoint.x) - Math.abs(e.pageY - this.currPoint.y);
        if (Math.abs(diff) >= DIRECTION_CHECK_OFFSET) {
          this.direction = diff > 0 ? "h" : "v";
        }
      }
    }
    this.pendingMove = e;
  };

  private startRenderLoop(): void {
    this.stopRenderLoop();
    const loop = (): void => {
      if (!this.isDragging) {
        return;
      }
      this.rafId = requestAnimationFrame(loop);
      this.renderMovement();
    };
    this.rafId = requestAnimationFrame(loop);
  }

  private stopRenderLoop(): void {
    if (this.rafId !== undefined) {
      cancelAnimationFrame(this.rafId);
      this.rafId = undefined;
    }
  }

  private renderMovement(): void {
    if (!this.pendingMove) {
      return;
    }
    // Real points always come from the live pointer map (both slots),
    // matching the original's own `_getTouchPoints()` -- during a pinch,
    // slot 0/1 are read fresh regardless of which pointer fired the
    // latest move event, not derived from the single triggering event.
    const pts = [...this.pointers.values()];
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- renderMovement only ever runs while isDragging, which requires at least one tracked pointer.
    const p1 = pts[0]!;

    if (this.isZooming && pts.length > 1) {
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- pts.length > 1 checked above.
      this.renderPinchZoom(p1, pts[1]!);
    } else {
      this.renderPanOrScroll(p1);
    }
    this.pendingMove = undefined;
  }

  private renderPinchZoom(p1: Point, p2: Point): void {
    this.currPoint = p1;
    if (!this.zoomStarted) {
      this.zoomStarted = true;
    }
    const distance = Math.hypot(p1.x - p2.x, p1.y - p2.y);
    let zoomLevel = (1 / this.startPointsDistance) * distance * this.startZoomLevel;

    if (zoomLevel > this.currItem.initialZoomLevel + this.currItem.initialZoomLevel / 15) {
      this.wasOverInitialZoom = true;
    }

    const minZoom = this.currItem.initialZoomLevel;
    const maxZoom = MAX_SPREAD_ZOOM;
    if (zoomLevel < minZoom) {
      if (!this.wasOverInitialZoom && this.startZoomLevel <= this.currItem.initialZoomLevel) {
        const percent = 1 - (minZoom - zoomLevel) / (minZoom / 1.2);
        this.setBgOpacity(percent);
        this.opacityChanged = true;
      } else {
        const friction = Math.min((minZoom - zoomLevel) / minZoom, 1);
        zoomLevel = minZoom - friction * (minZoom / 3);
      }
    } else if (zoomLevel > maxZoom) {
      const friction = Math.min((zoomLevel - maxZoom) / (minZoom * 6), 1);
      zoomLevel = maxZoom + friction * minZoom;
    }

    const center = point((p1.x + p2.x) / 2, (p1.y + p2.y) / 2);
    this.currPanDist.x += center.x - this.currCenterPoint.x;
    this.currPanDist.y += center.y - this.currCenterPoint.y;
    this.currCenterPoint = center;

    this.panOffset = point(this.calculatePanOffset("x", zoomLevel), this.calculatePanOffset("y", zoomLevel));
    this.zoomLevel = zoomLevel;
    this.applyPan();
  }

  private renderPanOrScroll(p1: Point): void {
    const delta = point(p1.x - this.currPoint.x, p1.y - this.currPoint.y);
    if (!this.direction) {
      return;
    }
    if (this.isFirstMove) {
      this.isFirstMove = false;
      if (Math.abs(delta.x) >= DIRECTION_CHECK_OFFSET) {
        delta.x -= p1.x - this.startPoint.x;
      }
      if (Math.abs(delta.y) >= DIRECTION_CHECK_OFFSET) {
        delta.y -= p1.y - this.startPoint.y;
      }
    }
    this.currPoint = p1;
    if (delta.x === 0 && delta.y === 0) {
      return;
    }

    if (this.direction === "v" && !this.canPan()) {
      this.currPanDist.y += delta.y;
      this.panOffset.y += delta.y;
      const ratio = this.verticalDragOpacityRatio();
      this.verticalDragInitiated = true;
      this.setBgOpacity(ratio);
      this.applyPan();
      return;
    }

    if (performance.now() - this.gestureCheckSpeedTime > 50) {
      const pt = { t: performance.now(), x: p1.x, y: p1.y };
      this.posPoints = this.posPoints.length > 2 ? [...this.posPoints.slice(1), pt] : [...this.posPoints, pt];
      this.gestureCheckSpeedTime = performance.now();
    }

    this.moved = true;
    const mainScrollChanged = this.panOrMoveMainScroll("x", delta);
    if (!mainScrollChanged) {
      this.panOrMoveMainScroll("y", delta);
      this.panOffset.x = Math.round(this.panOffset.x);
      this.panOffset.y = Math.round(this.panOffset.y);
      this.applyPan();
    }
  }

  private canPan(): boolean {
    return this.zoomLevel !== this.currItem.initialZoomLevel;
  }

  private verticalDragOpacityRatio(): number {
    const yOffset = this.panOffset.y - this.currItem.initialPosition.y;
    return 1 - Math.abs(yOffset / (this.viewportSize.y / 2));
  }

  private setBgOpacity(opacity: number): void {
    this.bgOpacity = opacity;
    this.bg.style.opacity = String(opacity);
  }

  private calculatePanOffset(axis: "x" | "y", zoomLevel: number): number {
    const m = this.midZoomPoint[axis];
    return this.startPanOffset[axis] + this.currPanDist[axis] + m - m * (zoomLevel / this.startZoomLevel);
  }

  private panOrMoveMainScroll(axis: "x" | "y", delta: Point): boolean {
    const bounds = this.panBoundsFor(this.currItem, this.zoomLevel);
    const newOffset = this.panOffset[axis] + delta[axis];
    const panFriction = newOffset > bounds.min[axis] || newOffset < bounds.max[axis] ? PAN_END_FRICTION : 1;

    if (axis === "x" && this.direction === "h" && !this.zoomStarted) {
      return this.resolveHorizontalScrollOrPan(delta, bounds, newOffset, panFriction);
    }

    if (!this.mainScrollAnimating && !this.mainScrollShifted && this.zoomLevel > this.currItem.fitRatio) {
      this.panOffset[axis] += delta[axis] * panFriction;
    }
    return false;
  }

  /** The real "drag right/left" blend between panning the zoomed image and moving the main scroll toward the next/prev slide -- `_panOrMoveMainScroll`'s own horizontal-axis branch, factored out for its own real complexity. */
  private resolveHorizontalScrollOrPan(delta: Point, bounds: PanBounds, newOffset: number, panFriction: number): boolean {
    const newMainScrollPosition = this.mainScrollPos + delta.x;
    const mainScrollDiff = this.mainScrollPos - this.startMainScrollPos;
    const { newMainScrollPos, newPanPos } = delta.x > 0
      ? this.scrollTargetDraggingRight(bounds, newOffset, newMainScrollPosition, mainScrollDiff)
      : this.scrollTargetDraggingLeft(bounds, newOffset, newMainScrollPosition, mainScrollDiff);

    if (newMainScrollPos !== undefined) {
      this.setMainScroll(newMainScrollPos);
      this.mainScrollShifted = newMainScrollPos !== this.startMainScrollPos;
    }
    if (bounds.min.x !== bounds.max.x) {
      if (newPanPos !== undefined) {
        this.panOffset.x = newPanPos;
      } else if (!this.mainScrollShifted) {
        this.panOffset.x += delta.x * panFriction;
      }
    }
    return newMainScrollPos !== undefined;
  }

  private scrollTargetDraggingRight(bounds: PanBounds, newOffset: number, newMainScrollPosition: number, mainScrollDiff: number): { newMainScrollPos?: number; newPanPos?: number } {
    const startOverDiff = newOffset > bounds.min.x ? bounds.min.x - this.startPanOffset.x : 0;
    if ((startOverDiff <= 0 || mainScrollDiff < 0) && this.items.length > 1) {
      const newMainScrollPos = mainScrollDiff < 0 && newMainScrollPosition > this.startMainScrollPos ? this.startMainScrollPos : newMainScrollPosition;
      return { newMainScrollPos };
    }
    if (bounds.min.x !== bounds.max.x) {
      return { newPanPos: newOffset };
    }
    return {};
  }

  private scrollTargetDraggingLeft(bounds: PanBounds, newOffset: number, newMainScrollPosition: number, mainScrollDiff: number): { newMainScrollPos?: number; newPanPos?: number } {
    const startOverDiff = newOffset < bounds.max.x ? this.startPanOffset.x - bounds.max.x : 0;
    if ((startOverDiff <= 0 || mainScrollDiff > 0) && this.items.length > 1) {
      const newMainScrollPos = mainScrollDiff > 0 && newMainScrollPosition < this.startMainScrollPos ? this.startMainScrollPos : newMainScrollPosition;
      return { newMainScrollPos };
    }
    if (bounds.min.x !== bounds.max.x) {
      return { newPanPos: newOffset };
    }
    return {};
  }

  /** Pointer bookkeeping + tap detection on release; returns the number of pointers still down afterward. */
  private releasePointer(e: PointerEvent): number {
    this.pointers.delete(e.pointerId);
    this.scrollWrap.removeEventListener("pointermove", this.onPointerMove);
    this.scrollWrap.removeEventListener("pointerup", this.onPointerUp);
    this.scrollWrap.removeEventListener("pointercancel", this.onPointerUp);

    const numPoints = this.pointers.size;
    if (numPoints === 1) {
      this.startPoint = valueAt([...this.pointers.values()], 0);
    }

    if (numPoints === 0 && !this.direction && !this.mainScrollAnimating && !this.moved && !this.zoomStarted) {
      this.handleTap(e);
    }
    if (numPoints === 0) {
      this.isDragging = false;
      this.stopRenderLoop();
    }
    if (this.isZooming && numPoints < 2) {
      this.isZooming = false;
    }
    this.pendingMove = undefined;
    return numPoints;
  }

  private readonly onPointerUp = (e: PointerEvent): void => {
    const numPoints = this.releasePointer(e);

    if (!this.moved && !this.zoomStarted && !this.mainScrollAnimating && !this.verticalDragInitiated) {
      return;
    }
    if (numPoints > 0) {
      return;
    }

    if (this.verticalDragInitiated) {
      this.finishVerticalDrag();
      return;
    }

    const swipeSpeed = this.calculateSwipeSpeed("x");
    if (this.mainScrollShifted || this.mainScrollAnimating) {
      const itemChanged = this.finishSwipeMainScroll(swipeSpeed);
      if (itemChanged) {
        return;
      }
    }
    if (this.mainScrollAnimating) {
      return;
    }
    if (this.zoomStarted) {
      this.completeZoomGesture();
      return;
    }
    if (!this.mainScrollShifted && this.zoomLevel > this.currItem.fitRatio) {
      this.completePanGesture();
    }
  };

  private handleTap(e: PointerEvent): void {
    const now = performance.now();
    const p = point(e.pageX, e.pageY);
    const nearby = Math.abs(p.x - this.lastTapPoint.x) < DOUBLE_TAP_RADIUS && Math.abs(p.y - this.lastTapPoint.y) < DOUBLE_TAP_RADIUS;
    if (now - this.lastTapTime < DOUBLE_TAP_DELAY_MS && nearby) {
      this.lastTapTime = 0;
      this.zoomToward(p);
      return;
    }
    this.lastTapTime = now;
    this.lastTapPoint = p;

    const target = e.target instanceof Element ? e.target : null;
    if (target?.closest(".pswp__caption, .pswp__top-bar, button")) {
      return;
    }
    // Single tap (after the double-tap window) toggles chrome visibility.
    setTimeout(() => {
      if (this.lastTapTime === now) {
        this.toggleControls();
      }
    }, DOUBLE_TAP_DELAY_MS);
  }

  private zoomToward(centerPoint: Point): void {
    const initial = this.currItem.initialZoomLevel;
    if (this.zoomLevel !== initial) {
      this.zoomTo(initial, centerPoint, SHOW_HIDE_DURATION);
    } else {
      this.zoomTo(initial < 0.7 ? 1 : MAX_SPREAD_ZOOM, centerPoint, SHOW_HIDE_DURATION);
    }
  }

  private toggleZoom(): void {
    this.zoomToward(point(this.viewportSize.x / 2, this.viewportSize.y / 2));
  }

  private calculateSwipeSpeed(axis: "x" | "y"): number {
    let duration: number;
    let releasePos: number;
    if (this.posPoints.length > 1) {
      const last = valueAt(this.posPoints, this.posPoints.length - 2);
      duration = performance.now() - this.gestureCheckSpeedTime + 50;
      releasePos = last[axis];
    } else {
      duration = performance.now() - this.gestureStartTime;
      releasePos = this.startPoint[axis];
    }
    const offset = this.currPoint[axis] - releasePos;
    const dist = Math.abs(offset);
    let speed = dist > 20 ? offset / duration : 0;
    if (Math.abs(speed) < 0.1) {
      speed = 0;
    }
    return speed;
  }

  private finishVerticalDrag(): void {
    const ratio = this.verticalDragOpacityRatio();
    if (ratio < VERTICAL_DRAG_RANGE) {
      this.close();
      return;
    }
    const initialY = this.panOffset.y;
    const initialOpacity = this.bgOpacity;
    animate(300, cubicOut, (now) => {
      this.panOffset.y = (this.currItem.initialPosition.y - initialY) * now + initialY;
      this.setBgOpacity((1 - initialOpacity) * now + initialOpacity);
      this.applyPan();
    });
  }

  private finishSwipeMainScroll(speedX: number): boolean {
    const totalShift = this.currPoint.x - this.startPoint.x;
    let itemsDiff = 0;
    if (totalShift > MIN_SWIPE_DISTANCE && speedX >= 0) {
      itemsDiff = -1;
    } else if (totalShift < -MIN_SWIPE_DISTANCE && speedX <= 0) {
      itemsDiff = 1;
    }

    let itemChanged = false;
    if (itemsDiff !== 0) {
      this.currentIndex = this.getLoopedIndex(this.currentIndex + itemsDiff);
      this.indexDiff += itemsDiff;
      this.positionIndex -= itemsDiff;
      itemChanged = true;
    }

    const animateToX = this.slideSize.x * this.positionIndex;
    const animateDist = Math.abs(animateToX - this.mainScrollPos);
    let duration = Math.abs(speedX) > 0 ? animateDist / Math.abs(speedX) : 333;
    duration = Math.min(Math.max(duration, 250), 400);

    this.mainScrollAnimating = true;
    const fromX = this.mainScrollPos;
    animate(duration, cubicOut, (now) => {
      this.setMainScroll(fromX + (animateToX - fromX) * now);
    }, () => {
      this.mainScrollAnimating = false;
      this.mainScrollShifted = false;
      if (itemChanged) {
        this.updateCurrItem();
      }
    });

    return itemChanged;
  }

  private completeZoomGesture(): void {
    let dest = this.zoomLevel;
    const min = this.currItem.initialZoomLevel;
    const max = MAX_SPREAD_ZOOM;
    if (this.zoomLevel < min) {
      dest = min;
    } else if (this.zoomLevel > max) {
      dest = max;
    }

    if (this.opacityChanged && !this.wasOverInitialZoom && this.zoomLevel < min) {
      this.close();
      return;
    }

    if (this.opacityChanged) {
      const initialOpacity = this.bgOpacity;
      this.zoomTo(dest, undefined, 200, cubicOut, (now) => {
        this.setBgOpacity((1 - initialOpacity) * now + initialOpacity);
      });
    } else {
      this.zoomTo(dest, undefined, 200, cubicOut);
    }
  }

  private completePanGesture(): void {
    const speed = point(this.calculateSwipeSpeed("x"), this.calculateSwipeSpeed("y"));
    if (Math.abs(speed.x) <= 0.05 && Math.abs(speed.y) <= 0.05) {
      this.bounceBackIfNeeded();
      return;
    }
    this.runMomentum(speed);
  }

  private bounceBackIfNeeded(): void {
    const bounds = this.panBoundsFor(this.currItem, this.zoomLevel);
    (["x", "y"] as const).forEach((axis) => {
      let dest: number | undefined;
      if (this.panOffset[axis] > bounds.min[axis]) {
        dest = bounds.min[axis];
      } else if (this.panOffset[axis] < bounds.max[axis]) {
        dest = bounds.max[axis];
      }
      if (dest !== undefined) {
        const from = this.panOffset[axis];
        animate(300, sineOut, (now) => {
          this.panOffset[axis] = from + (dest - from) * now;
          this.applyPan();
        });
      }
    });
  }

  private runMomentum(speed: Point): void {
    let speedX = speed.x;
    let speedY = speed.y;
    let decelX = 1;
    let decelY = 1;
    let lastNow = performance.now();
    const bounds = this.panBoundsFor(this.currItem, this.zoomLevel);
    const slowDown = 0.95;

    const loop = (): void => {
      const now = performance.now();
      const dt = now - lastNow;
      lastNow = now;

      decelX = decelX * (slowDown + (1 - slowDown) - (1 - slowDown) * (dt / 10));
      decelY = decelY * (slowDown + (1 - slowDown) - (1 - slowDown) * (dt / 10));
      this.panOffset.x += speedX * decelX * dt;
      this.panOffset.y += speedY * decelY * dt;
      this.applyPan();

      const absX = Math.abs(speedX * decelX);
      const absY = Math.abs(speedY * decelY);
      if (absX < 0.05 && absY < 0.05) {
        this.panOffset.x = Math.round(this.panOffset.x);
        this.panOffset.y = Math.round(this.panOffset.y);
        this.applyPan();
        this.bounceBackIfNeeded();
        return;
      }
      // Out-of-bounds edge kill (real behavior: once past bounds, snap into the bounce-back animation instead of drifting further).
      if (this.panOffset.x > bounds.min.x || this.panOffset.x < bounds.max.x || this.panOffset.y > bounds.min.y || this.panOffset.y < bounds.max.y) {
        speedX = 0;
        speedY = 0;
      }
      this.momentumRafId = requestAnimationFrame(loop);
    };
    this.momentumRafId = requestAnimationFrame(loop);
  }

  // ── zoom / wheel ──────────────────────────────────────────────────────

  private zoomTo(destZoom: number, centerPoint: Point | undefined, speed: number, easing: (k: number) => number = sineInOut, onUpdate?: (now: number) => void): void {
    if (centerPoint) {
      this.startZoomLevel = this.zoomLevel;
      this.midZoomPoint = point(Math.abs(centerPoint.x) - this.panOffset.x, Math.abs(centerPoint.y) - this.panOffset.y);
      this.startPanOffset = { ...this.panOffset };
      this.currPanDist = point();
    }
    const bounds = this.panBoundsFor(this.currItem, destZoom);
    const destPan = point(
      destZoom === this.currItem.initialZoomLevel ? this.currItem.initialPosition.x : Math.min(Math.max(this.calculatePanOffset("x", destZoom), bounds.max.x), bounds.min.x),
      destZoom === this.currItem.initialZoomLevel ? this.currItem.initialPosition.y : Math.min(Math.max(this.calculatePanOffset("y", destZoom), bounds.max.y), bounds.min.y)
    );
    const initialZoom = this.zoomLevel;
    const initialPan = { ...this.panOffset };

    if (speed <= 0) {
      this.zoomLevel = destZoom;
      this.panOffset = destPan;
      this.applyPan();
      onUpdate?.(1);
      return;
    }
    animate(speed, easing, (now) => {
      if (now === 1) {
        this.zoomLevel = destZoom;
        this.panOffset = destPan;
      } else {
        this.zoomLevel = (destZoom - initialZoom) * now + initialZoom;
        this.panOffset = point((destPan.x - initialPan.x) * now + initialPan.x, (destPan.y - initialPan.y) * now + initialPan.y);
      }
      onUpdate?.(now);
      this.applyPan();
    });
  }

  private handleWheel(e: WheelEvent): void {
    if (this.zoomLevel <= this.currItem.fitRatio) {
      if (Math.abs(e.deltaY) > 2) {
        this.close();
      } else {
        e.preventDefault();
      }
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    const bounds = this.panBoundsFor(this.currItem, this.zoomLevel);
    const newX = this.panOffset.x - e.deltaX;
    const newY = this.panOffset.y - e.deltaY;
    this.panOffset.x = Math.min(Math.max(newX, bounds.max.x), bounds.min.x);
    this.panOffset.y = Math.min(Math.max(newY, bounds.max.y), bounds.min.y);
    this.applyPan();
  }

  // ── open/close "grow from thumbnail" transition ──────────────────────

  private playOpenAnimation(thumbBounds: DOMRect | undefined): void {
    this.root.style.opacity = "1";
    if (!thumbBounds) {
      this.setBgOpacity(1);
      return;
    }
    const item = this.currItem;
    const destZoom = item.initialZoomLevel;
    const destPan = { ...item.initialPosition };
    this.zoomLevel = thumbBounds.width / item.w;
    this.panOffset = point(thumbBounds.left, thumbBounds.top);
    this.applyPan();
    this.setBgOpacity(0.001);

    requestAnimationFrame(() => {
      animate(SHOW_HIDE_DURATION, cubicOut, (now) => {
        this.zoomLevel = (destZoom - thumbBounds.width / item.w) * now + thumbBounds.width / item.w;
        this.panOffset = point(
          (destPan.x - thumbBounds.left) * now + thumbBounds.left,
          (destPan.y - thumbBounds.top) * now + thumbBounds.top
        );
        this.setBgOpacity(now);
        this.applyPan();
      });
    });
  }

  private playCloseAnimation(thumbBounds: DOMRect | undefined, onComplete: () => void): void {
    if (!thumbBounds) {
      animate(SHOW_HIDE_DURATION, cubicOut, (now) => {
        this.setBgOpacity(1 - now);
        this.root.style.opacity = String(1 - now);
      }, onComplete);
      return;
    }
    const item = this.currItem;
    const destZoom = thumbBounds.width / item.w;
    const initialZoom = this.zoomLevel;
    const initialPan = { ...this.panOffset };
    const initialOpacity = this.bgOpacity;
    animate(SHOW_HIDE_DURATION, cubicOut, (now) => {
      this.zoomLevel = (destZoom - initialZoom) * now + initialZoom;
      this.panOffset = point(
        (thumbBounds.left - initialPan.x) * now + initialPan.x,
        (thumbBounds.top - initialPan.y) * now + initialPan.y
      );
      this.setBgOpacity(initialOpacity - now * initialOpacity);
      this.applyPan();
    }, onComplete);
  }

  // ── UI chrome: caption, counter, idle-hide, fullscreen, share, autoplay ──

  private updateUi(): void {
    if (!this.controlsVisible) {
      return;
    }
    this.ui.counter.textContent = `${String(this.currentIndex + 1)} / ${String(this.items.length)}`;
    const caption = this.ui.caption.querySelector(".pswp__caption__center");
    if (caption) {
      caption.innerHTML = this.currItem.title ?? "";
    }
    this.ui.caption.classList.toggle("pswp__caption--empty", this.currItem.title === undefined || this.currItem.title === "");
    this.ui.root.classList.toggle("pswp__ui--one-slide", this.items.length === 1);
    this.resetIdleTimer();
  }

  private toggleControls(): void {
    this.controlsVisible = !this.controlsVisible;
    this.ui.root.classList.toggle("pswp__ui--hidden", !this.controlsVisible);
    if (this.controlsVisible) {
      this.updateUi();
    }
  }

  private resetIdleTimer(): void {
    if (this.idleTimer !== undefined) {
      clearTimeout(this.idleTimer);
    }
    this.ui.root.classList.remove("pswp__ui--idle");
    this.idleTimer = setTimeout(() => {
      this.ui.root.classList.add("pswp__ui--idle");
    }, 4000);
  }

  private toggleFullscreen(): void {
    if (document.fullscreenElement) {
      void document.exitFullscreen();
    } else {
      void this.root.requestFullscreen();
    }
  }

  private toggleShareModal(): void {
    const hidden = this.ui.shareModal.classList.toggle("pswp__share-modal--hidden");
    if (!hidden) {
      this.renderShareLinks();
    }
  }

  private renderShareLinks(): void {
    const buttons = this.options.shareButtons ?? [];
    const tooltip = this.ui.shareModal.querySelector(".pswp__share-tooltip");
    if (!tooltip) {
      return;
    }
    const item = this.currItem;
    const pageUrl = window.location.href;
    const text = this.options.getShareText?.(this.currentIndex) ?? item.title ?? "";
    tooltip.innerHTML = buttons
      .map((btn) => {
        const url = btn.url
          .replace("{{url}}", encodeURIComponent(pageUrl))
          .replace("{{image_url}}", encodeURIComponent(item.src))
          .replace("{{raw_image_url}}", item.src)
          .replace("{{text}}", encodeURIComponent(text));
        const downloadAttr = btn.download === true ? " download" : "";
        return `<a href="${url}" target="_blank" rel="noopener" class="pswp__share--${btn.id}"${downloadAttr}>${btn.label}</a>`;
      })
      .join("");
  }

  private toggleAutoplay(): void {
    if (this.autoplayId !== undefined) {
      this.stopAutoplay();
      return;
    }
    const intervalMs = this.options.autoplayIntervalMs ?? 3500;
    this.ui.autoplayBtn.classList.add("pswp__button--autoplay-active");
    this.autoplayId = setInterval(() => {
      this.next();
    }, intervalMs);
  }

  private stopAutoplay(): void {
    if (this.autoplayId !== undefined) {
      clearInterval(this.autoplayId);
      this.autoplayId = undefined;
      this.ui.autoplayBtn.classList.remove("pswp__button--autoplay-active");
    }
  }
}

// ── Orchestration (module-level singleton, one lightbox open at a time) ──

function openGallery(items: PhotoSwipeItem[], index: number, options: PhotoSwipeOptions): void {
  if (items.length === 0) {
    return;
  }
  gallery?.destroy();
  gallery = new PhotoSwipeGallery(items.map(makeSlideItem), index, options);
  gallery.open();
}

function clickHandler(el: Element, event: MouseEvent): void {
  if (event.button > 0 || event.shiftKey || event.altKey || event.metaKey || event.ctrlKey) {
    return;
  }
  event.preventDefault();
  const opts = optionsByElement.get(el) ?? {};
  // The clicked element's own `getItems()` is the real, shared resolver
  // for its whole `rel` group -- darkroom's own wiring registers every
  // thumbnail in one grid with the same closure returning the full
  // gallery's items, matching colorbox.ts's own `rel`-grouping
  // convention but resolving the item list once, lazily, on open rather
  // than per-element.
  const items = opts.getItems?.() ?? [];
  const { index } = computeRelated(el, opts);
  openGallery(items, opts.index ?? index, opts);
}
