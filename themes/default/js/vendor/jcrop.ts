// Native port of Jcrop (P49-B group 6, the last "group 6"-labeled
// library -- jqtree, group 6B, is already ported; this closes the
// group out entirely), real source read from `github:tapmodo/
// Jcrop#v0.9.12`'s own `js/jquery.Jcrop.js`. One real consumer,
// `picture_coi.ts`'s center-of-interest cropper (a single `Jcrop(el,
// {boxWidth, boxHeight, onChange, onRelease}, initCallback?)` call on a
// real `<img id="jcrop">`), which narrows the real surface hard:
//
// - `#jcrop` is always a real `<img>` (`picture_coi.latte`), never the
//   library's own "crop an arbitrary `<div>`" mode -- that whole branch
//   (`img_mode = false`), and the `shade` (darkened-background) option
//   it forces on for that mode, is real, unreachable dead weight here
//   and isn't ported.
// - `aspectRatio`/`maxSize`/`minSize`/`minSelect` are never set (all
//   default to 0/`[0, 0]`), which collapses `Coords.getFixed()`'s own
//   aspect-ratio branch and every one of `getRect()`'s min/max-size
//   clamps to dead code -- not ported. The bounds-clamping that *is*
//   reachable (dragging past the image edges) is, including one exact,
//   pre-existing quirk in the original's own `getRect()` (its `x1 >
//   boundx` branch computes `delta` from `boundy`, not `boundx`) --
//   ported literally, not "fixed", same policy `vendor/jqtree.ts`'s own
//   header already documents for its hit-area arithmetic.
// - `trueSize`/`setSelect`/`setOptions`/`outerImage`/`addClass` are
//   never used (no second `.setOptions()`/`.Jcrop("api")` call anywhere
//   real) -- the whole "reconfigure a live instance" surface is dead
//   weight and isn't ported; this module only ever initializes once.
// - The returned instance's only real consumer use is `animateTo()`
//   (`picture_coi.ts`'s own init callback, when a stored `coi` exists) --
//   `setImage`/`setSelect`/`tellSelect`/`tellScaled`/`setClass`/
//   `disable`/`enable`/`cancel`/`release`/`destroy`/`focus`/
//   `getBounds`/`getWidgetSize`/`getScaleFactor`/`getOptions`/`ui` are
//   real API surface on the original's own instance object, but none of
//   it is ever read back here, so none of it is exposed.
// - `allowSelect`/`allowMove`/`allowResize`/`keySupport`/`drawBorders`/
//   `dragEdges` all default `true` and are never overridden -- real,
//   always-on behavior (drawing a new selection, moving it, resizing it
//   via 8 handles, arrow-key nudging, Escape-to-release, and the
//   border/dragbar decorations) and are ported, not dropped.
// - Mouse and touch are unified through native Pointer Events (one
//   `pointerdown`/`pointermove`/`pointerup` path) rather than the
//   original's own separate mouse/touch listener pairs plus its own
//   touch-capability-detection shim -- a deliberate simplification,
//   not a literal translation, since every real target browser here
//   already dispatches pointer events for both input kinds.
import { height, offset, width } from "./dom";

interface JcropSelection {
  x: number;
  y: number;
  x2: number;
  y2: number;
  w: number;
  h: number;
}

export interface JcropOptions {
  boxWidth?: number;
  boxHeight?: number;
  onChange?: (sel: JcropSelection) => void;
  onRelease?: () => void;
}

export interface JcropApi {
  animateTo(coords: [number, number, number, number], callback?: (this: JcropApi) => void): void;
}

type Ord = "n" | "s" | "e" | "w" | "nw" | "ne" | "se" | "sw";
type Corner = "nw" | "ne" | "se" | "sw";

const HANDLE_ORDS: Ord[] = ["n", "s", "e", "w", "nw", "ne", "se", "sw"];
const DRAGBAR_ORDS: Ord[] = ["n", "s", "e", "w"];
const BOUNDARY = 2;
const HANDLE_OPACITY = 0.5;
const BORDER_OPACITY = 0.4;
const BG_COLOR = "black";
const BG_OPACITY = 0.6;
const ANIMATION_DELAY = 20;
const SWING_SPEED = 3;

function px(n: number): string {
  return `${Math.round(n)}px`;
}

// Only ever called with a real Ord -- both call sites narrow away "move"
// before reaching here (startDragMode's own early return, and this
// function's own Corner-only return feeding back into itself).
function oppositeLockCorner(ord: Ord): Corner {
  switch (ord) {
    case "n":
      return "sw";
    case "s":
      return "nw";
    case "e":
      return "nw";
    case "w":
      return "ne";
    case "ne":
      return "sw";
    case "nw":
      return "se";
    case "se":
      return "nw";
    case "sw":
      return "ne";
  }
  throw new Error("unreachable: exhaustive switch over Ord");
}

/**
 * Real entry point (`$.fn.Jcrop(options, callback)`). The target `<img>`
 * is not necessarily loaded yet by the time this runs -- confirmed live,
 * not theoretical: cloning it below (`initJcrop`) re-triggers the
 * clone's own async decode regardless of the original's own state, and
 * even the original itself can still be mid-fetch this early in a real
 * page load. The real source guards this exact case with its own
 * `$.Jcrop.Loader` (poll `img.complete`, else wait for `load`/`error`) --
 * ported as a native `load` listener rather than a 50ms poll loop, since
 * that loop's only real job was working around ancient browsers'
 * unreliable `load` event, not a concern on any real target here.
 */
export function jcrop(
  origImg: HTMLImageElement,
  options: JcropOptions,
  initCallback?: (this: JcropApi) => void,
): void {
  const start = (): void => {
    const api = initJcrop(origImg, options);
    initCallback?.call(api);
  };
  if (origImg.complete) {
    start();
    return;
  }
  origImg.addEventListener("load", start, { once: true });
}

function initJcrop(origImg: HTMLImageElement, options: JcropOptions): JcropApi {
  const boxWidth = options.boxWidth ?? 0;
  const boxHeight = options.boxHeight ?? 0;
  // eslint-disable-next-line @typescript-eslint/no-empty-function -- intentional no-op default for an optional callback.
  const onChange = options.onChange ?? ((): void => {});
  // eslint-disable-next-line @typescript-eslint/no-empty-function -- intentional no-op default for an optional callback.
  const onRelease = options.onRelease ?? ((): void => {});

  // ── DOM construction ────────────────────────────────────────────────
  // Forced visible-but-hidden before measuring, matching the real
  // source's own `$(this).css({display:'block',visibility:'hidden'})`
  // right before its own real `$.Jcrop(this, options)` call.
  origImg.style.display = "block";
  origImg.style.visibility = "hidden";
  const rawWidth = origImg.width;
  const rawHeight = origImg.height;
  origImg.style.width = px(rawWidth);
  origImg.style.height = px(rawHeight);

  const img = origImg.cloneNode(true) as HTMLImageElement;
  img.removeAttribute("id");
  Object.assign(img.style, {
    border: "none",
    visibility: "visible",
    margin: "0",
    padding: "0",
    position: "absolute",
    top: "0",
    left: "0",
    display: "block",
  });
  // From the already-known original dimensions, not a fresh measurement
  // of the clone: cloning an `<img>` re-triggers its own independent
  // (and asynchronous, even on a cache hit) decode, so reading the
  // clone's own rendered size synchronously right after `cloneNode()`
  // is unreliable regardless of the original's own load state -- real
  // source's own `$img.width($origimg.width())` does the same, and
  // `presize()` below reads this explicit size back rather than
  // re-measuring.
  img.style.width = px(rawWidth);
  img.style.height = px(rawHeight);
  origImg.after(img);
  origImg.style.display = "none";

  let xscale = 1;
  let yscale = 1;

  function presize(el: HTMLElement, w: number, h: number): void {
    let nw = width(el);
    let nh = height(el);
    if (nw > w && w > 0) {
      nw = w;
      nh = (w / width(el)) * height(el);
    }
    if (nh > h && h > 0) {
      nh = h;
      nw = (h / height(el)) * width(el);
    }
    xscale = width(el) / nw;
    yscale = height(el) / nh;
    el.style.width = px(nw);
    el.style.height = px(nh);
  }

  presize(img, boxWidth, boxHeight);

  const boundx = width(img);
  const boundy = height(img);

  const holder = document.createElement("div");
  holder.className = "jcrop-holder";
  Object.assign(holder.style, {
    position: "relative",
    backgroundColor: BG_COLOR,
    width: px(boundx),
    height: px(boundy),
  });
  origImg.after(holder);
  holder.appendChild(img);

  const img2 = document.createElement("img");
  img2.src = img.src;
  Object.assign(img2.style, {
    border: "none",
    visibility: "visible",
    margin: "0",
    padding: "0",
    position: "absolute",
    top: "0",
    left: "0",
  });
  img2.width = boundx;
  img2.height = boundy;

  const imgHolder = document.createElement("div");
  Object.assign(imgHolder.style, {
    width: "100%",
    height: "100%",
    zIndex: "310",
    position: "absolute",
    overflow: "hidden",
  });
  imgHolder.appendChild(img2);

  const hdlHolder = document.createElement("div");
  Object.assign(hdlHolder.style, { width: "100%", height: "100%", zIndex: "320" });

  const sel = document.createElement("div");
  Object.assign(sel.style, { position: "absolute", zIndex: "600" });
  sel.appendChild(imgHolder);
  sel.appendChild(hdlHolder);
  img.before(sel);

  function insertBorder(cssClass: string): HTMLElement {
    const el = document.createElement("div");
    el.className = `jcrop-${cssClass}`;
    Object.assign(el.style, { position: "absolute", opacity: String(BORDER_OPACITY) });
    imgHolder.appendChild(el);
    return el;
  }
  for (const ord of DRAGBAR_ORDS) {
    insertBorder(ord === "n" ? "hline" : ord === "s" ? "hline bottom" : ord === "e" ? "vline right" : "vline");
  }

  function dragDiv(ord: Ord | "move", zIndex: number): HTMLElement {
    const el = document.createElement("div");
    el.className = `ord-${ord}`;
    Object.assign(el.style, { cursor: ord === "move" ? "move" : `${ord}-resize`, position: "absolute", zIndex: String(zIndex) });
    hdlHolder.appendChild(el);
    return el;
  }

  // Dragbars before handles, matching the real creation order -- an
  // edge handle (n/s/e/w) sits at the midpoint of its same-edge
  // dragbar, so this is what puts the handle on top there instead of
  // the other way around. Both trigger the identical axis-locked
  // resize when dragged either way, so this only affects which one is
  // visually on top at that overlap, never the drag outcome itself.
  let hdep = 370;
  for (const ord of DRAGBAR_ORDS) {
    const el = dragDiv(ord, hdep++);
    el.classList.add("jcrop-dragbar");
  }
  const handles: Partial<Record<Ord, HTMLElement>> = {};
  for (const ord of HANDLE_ORDS) {
    const el = dragDiv(ord, hdep++);
    el.classList.add("jcrop-handle");
    el.style.opacity = String(HANDLE_OPACITY);
    handles[ord] = el;
  }

  const outerTracker = document.createElement("div");
  outerTracker.className = "jcrop-tracker";
  Object.assign(outerTracker.style, {
    position: "absolute",
    top: px(-BOUNDARY),
    left: px(-BOUNDARY),
    width: px(boundx + BOUNDARY * 2),
    height: px(boundy + BOUNDARY * 2),
    zIndex: "290",
    cursor: "crosshair",
    touchAction: "none",
  });
  img.before(outerTracker);

  const moveTracker = document.createElement("div");
  moveTracker.className = "jcrop-tracker";
  Object.assign(moveTracker.style, { position: "absolute", zIndex: "360", cursor: "move", width: "100%", height: "100%", touchAction: "none" });
  imgHolder.appendChild(moveTracker);

  // A focusable, visually-hidden proxy: arrow-key nudging and Escape
  // (KeyManager's real mechanism) need *something* focused to receive
  // keydown, and this app has no other natural focus target mid-drag.
  const keyProxy = document.createElement("button");
  keyProxy.type = "button";
  keyProxy.setAttribute("aria-hidden", "true");
  keyProxy.tabIndex = -1;
  Object.assign(keyProxy.style, { position: "fixed", left: "-999px", width: "1px", height: "1px", opacity: "0" });
  document.body.appendChild(keyProxy);

  // ── Coordinate model (real source: Coords module) ────────────────────
  let x1 = 0;
  let y1 = 0;
  let x2 = 0;
  let y2 = 0;

  function rebound(pos: [number, number]): [number, number] {
    let [px1, py1] = pos;
    if (px1 < 0) px1 = 0;
    if (py1 < 0) py1 = 0;
    if (px1 > boundx) px1 = boundx;
    if (py1 > boundy) py1 = boundy;
    return [Math.round(px1), Math.round(py1)];
  }

  function setPressed(pos: [number, number]): void {
    [x1, y1] = rebound(pos);
    x2 = x1;
    y2 = y1;
  }

  function setCurrent(pos: [number, number]): void {
    [x2, y2] = rebound(pos);
  }

  function moveOffset(offsetPos: [number, number]): void {
    let [ox, oy] = offsetPos;
    if (0 > x1 + ox) ox -= ox + x1;
    if (0 > y1 + oy) oy -= oy + y1;
    if (boundy < y2 + oy) oy += boundy - (y2 + oy);
    if (boundx < x2 + ox) ox += boundx - (x2 + ox);
    x1 += ox;
    x2 += ox;
    y1 += oy;
    y2 += oy;
  }

  function flipCoords(a1: number, b1: number, a2: number, b2: number): [number, number, number, number] {
    let xa = a1;
    let xb = a2;
    let ya = b1;
    let yb = b2;
    if (a2 < a1) {
      xa = a2;
      xb = a1;
    }
    if (b2 < b1) {
      ya = b2;
      yb = b1;
    }
    return [xa, ya, xb, yb];
  }

  function makeSelection(a: [number, number, number, number]): JcropSelection {
    return { x: a[0], y: a[1], x2: a[2], y2: a[3], w: a[2] - a[0], h: a[3] - a[1] };
  }

  // Only ever called with the Corner oppositeLockCorner() returns -- a
  // straight edge (n/s/e/w) never reaches here.
  function getCorner(ord: Corner): [number, number] {
    const c = getFixed();
    switch (ord) {
      case "ne":
        return [c.x2, c.y];
      case "nw":
        return [c.x, c.y];
      case "se":
        return [c.x2, c.y2];
      case "sw":
        return [c.x, c.y2];
    }
    throw new Error("unreachable: exhaustive switch over Corner");
  }

  // Real source's own `x1 > boundx` branch computes `delta` from
  // `boundy`, not `boundx` -- ported literally, see this file's header.
  function getFixed(): JcropSelection {
    let rx1 = x1;
    let ry1 = y1;
    let rx2 = x2;
    let ry2 = y2;
    let delta: number;
    if (rx1 < 0) {
      rx2 -= rx1;
      rx1 -= rx1;
    }
    if (ry1 < 0) {
      ry2 -= ry1;
      ry1 -= ry1;
    }
    if (rx2 < 0) {
      rx1 -= rx2;
      rx2 -= rx2;
    }
    if (ry2 < 0) {
      ry1 -= ry2;
      ry2 -= ry2;
    }
    if (rx2 > boundx) {
      delta = rx2 - boundx;
      rx1 -= delta;
      rx2 -= delta;
    }
    if (ry2 > boundy) {
      delta = ry2 - boundy;
      ry1 -= delta;
      ry2 -= delta;
    }
    if (rx1 > boundx) {
      delta = rx1 - boundy;
      ry2 -= delta;
      ry1 -= delta;
    }
    if (ry1 > boundy) {
      delta = ry1 - boundy;
      ry2 -= delta;
      ry1 -= delta;
    }
    return makeSelection(flipCoords(rx1, ry1, rx2, ry2));
  }

  function unscale(c: JcropSelection): JcropSelection {
    return {
      x: c.x * xscale,
      y: c.y * yscale,
      x2: c.x2 * xscale,
      y2: c.y2 * yscale,
      w: c.w * xscale,
      h: c.h * yscale,
    };
  }

  // ── Selection visibility/geometry (real source: Selection module) ───
  let awake = false;
  let animating = false;

  function resize(w: number, h: number): void {
    sel.style.width = px(w);
    sel.style.height = px(h);
  }

  function moveTo(x: number, y: number): void {
    img2.style.top = px(-y);
    img2.style.left = px(-x);
    sel.style.top = px(y);
    sel.style.left = px(x);
  }

  function setBgOpacity(opacity: number): void {
    img.style.opacity = String(opacity);
  }

  function show(): void {
    sel.style.display = "block";
    setBgOpacity(BG_OPACITY);
    awake = true;
  }

  function enableHandles(): void {
    hdlHolder.style.display = "block";
  }

  function disableHandles(): void {
    hdlHolder.style.display = "none";
  }

  function update(isSelect: boolean): void {
    const c = getFixed();
    resize(c.w, c.h);
    moveTo(c.x, c.y);
    if (!awake) show();
    // Real source only fires `onSelect` (unused here, no real call site
    // ever sets it) at drag-end; every intermediate move fires
    // `onChange` -- both paths funnel through the one real callback
    // this consumer supplies.
    if (!isSelect) {
      onChange(unscale(c));
    }
  }

  function updateVisible(isSelect: boolean): void {
    if (awake) update(isSelect);
  }

  function release(): void {
    disableHandles();
    sel.style.display = "none";
    setBgOpacity(1);
    awake = false;
    onRelease();
  }

  function animMode(on: boolean): void {
    animating = on;
    if (on) {
      disableHandles();
    } else {
      enableHandles();
    }
  }

  function refresh(): void {
    const c = getFixed();
    setPressed([c.x, c.y]);
    setCurrent([c.x2, c.y2]);
    updateVisible(false);
  }

  function done(): void {
    animMode(false);
    refresh();
  }

  disableHandles();

  // ── Pointer-based dragging (real source: mouse/touch handlers +
  // Tracker module, unified through Pointer Events) ────────────────────
  let btndown = false;
  let dragMove: ((pos: [number, number]) => void) | null = null;
  let dragDone: ((pos: [number, number]) => void) | null = null;

  function mouseAbs(e: PointerEvent): [number, number] {
    const docOffset = offset(img);
    return [e.pageX - docOffset.left, e.pageY - docOffset.top];
  }

  function activateHandlers(
    move: (pos: [number, number]) => void,
    doneFn: (pos: [number, number]) => void,
  ): void {
    btndown = true;
    dragMove = move;
    dragDone = doneFn;
  }

  function onPointerMove(e: PointerEvent): void {
    if (!btndown || dragMove === null) return;
    dragMove(mouseAbs(e));
  }

  function onPointerUp(e: PointerEvent): void {
    if (!btndown) return;
    btndown = false;
    const fn = dragDone;
    dragMove = null;
    dragDone = null;
    fn?.(mouseAbs(e));
  }

  document.addEventListener("pointermove", onPointerMove);
  document.addEventListener("pointerup", onPointerUp);

  function watchKeys(): void {
    keyProxy.focus();
  }

  function doneSelect(): void {
    const c = getFixed();
    if (c.w > 0 && c.h > 0) {
      enableHandles();
      done();
    } else {
      release();
    }
  }

  function selectDrag(pos: [number, number]): void {
    setCurrent(pos);
    update(false);
  }

  function newSelection(e: PointerEvent): void {
    disableHandles();
    const pos = mouseAbs(e);
    setPressed(pos);
    update(false);
    activateHandlers(selectDrag, doneSelect);
    watchKeys();
    e.stopPropagation();
    e.preventDefault();
  }
  outerTracker.addEventListener("pointerdown", newSelection);

  function createMover(pos: [number, number]): (pos: [number, number]) => void {
    let lastPos = pos;
    watchKeys();
    return (nextPos) => {
      moveOffset([nextPos[0] - lastPos[0], nextPos[1] - lastPos[1]]);
      lastPos = nextPos;
      update(false);
    };
  }

  // `f` is the fixed rect captured once at drag-start (`startDragMode`):
  // an edge handle (n/s/e/w, as opposed to a corner) only ever moves
  // along its own axis, with the perpendicular one pinned to that
  // captured rect's own far edge for the whole drag -- not the live,
  // continuously-updating one, which is what makes the *opposite* edge
  // stay put while only the dragged edge follows the pointer.
  function dragModeHandler(ord: Ord, f: JcropSelection): (pos: [number, number]) => void {
    return (pos) => {
      switch (ord) {
        case "e":
        case "w":
          pos[1] = f.y2;
          break;
        case "n":
        case "s":
          pos[0] = f.x2;
          break;
        default:
          break;
      }
      setCurrent(pos);
      update(false);
    };
  }

  function startDragMode(ord: Ord | "move", pos: [number, number]): void {
    if (ord === "move") {
      activateHandlers(createMover(pos), doneSelect);
      return;
    }
    const fixed = getFixed();
    const opp = oppositeLockCorner(ord);
    // `opp` is the corner that stays anchored (pressed); the moving
    // point (current) starts at *its* opposite -- i.e. back at the
    // corner nearest the handle actually being dragged.
    setPressed(getCorner(opp));
    setCurrent(getCorner(oppositeLockCorner(opp)));
    activateHandlers(dragModeHandler(ord, fixed), doneSelect);
  }

  function createDragger(ord: Ord | "move"): (e: PointerEvent) => void {
    return (e) => {
      startDragMode(ord, mouseAbs(e));
      e.stopPropagation();
      e.preventDefault();
    };
  }

  moveTracker.addEventListener("pointerdown", createDragger("move"));
  for (const ord of HANDLE_ORDS) {
    handles[ord]?.addEventListener("pointerdown", createDragger(ord));
  }

  // ── Keyboard nudging (real source: KeyManager module) ────────────────
  function doNudge(e: KeyboardEvent, dx: number, dy: number): void {
    moveOffset([dx, dy]);
    updateVisible(true);
    e.preventDefault();
    e.stopPropagation();
  }

  keyProxy.addEventListener("keydown", (e) => {
    if (e.ctrlKey || e.metaKey) return;
    const nudge = e.shiftKey ? 10 : 1;
    switch (e.key) {
      case "ArrowLeft":
        doNudge(e, -nudge, 0);
        break;
      case "ArrowRight":
        doNudge(e, nudge, 0);
        break;
      case "ArrowUp":
        doNudge(e, 0, -nudge);
        break;
      case "ArrowDown":
        doNudge(e, 0, nudge);
        break;
      case "Escape":
        release();
        break;
      default:
        break;
    }
  });

  // ── Public API: only animateTo() is ever read by a real call site ───
  const api: JcropApi = {
    animateTo(coords, callback): void {
      if (animating) return;
      const target = flipCoords(
        coords[0] / xscale,
        coords[1] / yscale,
        coords[2] / xscale,
        coords[3] / yscale,
      );
      const c = getFixed();
      const start: [number, number, number, number] = [c.x, c.y, c.x2, c.y2];
      let percent = 0;
      const deltas = [
        target[0] - start[0],
        target[1] - start[1],
        target[2] - start[2],
        target[3] - start[3],
      ];
      const current: [number, number, number, number] = [...start];

      animMode(true);

      const step = (): void => {
        percent += (100 - percent) / SWING_SPEED;
        current[0] = Math.round(start[0] + (percent / 100) * deltas[0]!);
        current[1] = Math.round(start[1] + (percent / 100) * deltas[1]!);
        current[2] = Math.round(start[2] + (percent / 100) * deltas[2]!);
        current[3] = Math.round(start[3] + (percent / 100) * deltas[3]!);

        if (percent >= 99.8) percent = 100;

        if (percent < 100) {
          setPressed([current[0], current[1]]);
          setCurrent([current[2], current[3]]);
          update(false);
          window.setTimeout(step, ANIMATION_DELAY);
        } else {
          setPressed([current[0], current[1]]);
          setCurrent([current[2], current[3]]);
          update(false);
          done();
          animMode(false);
          callback?.call(api);
        }
      };
      step();
    },
  };

  return api;
}
