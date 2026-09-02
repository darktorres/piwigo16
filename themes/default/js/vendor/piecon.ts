// Native port of Piecon (P49-C), real source read from the vendored
// package (`github:lipka/piecon#0.5.0`, an abandoned upstream fork
// pin -- last real upstream commit years ago). The one real call site
// (`photos_add_direct.ts`'s own upload-progress favicon indicator)
// only ever calls `Piecon.setProgress(percentage)`/`.reset()` --
// `.setOptions()` is never called, so the original's own configurable
// `color`/`background`/`shadow`/`fallback` options collapse to their
// own real defaults (`#ff0084`/`#bbb`/`#fff`/`false`), not ported as
// options at all.
//
// `browser.ie`/`browser.safari` UA-sniffed fallback (falls back to a
// `"(NN%) title"` document-title update instead of drawing a canvas
// favicon) is real, preserved literally even though it's unreachable
// in this app's own Chromium-based test matrix -- real Safari/IE
// users still hit it in production.

let currentFavicon: string | null = null;
let originalFavicon: string | null = null;
let originalTitle: string | null = null;
let canvas: HTMLCanvasElement | null = null;

const COLOR = "#ff0084";
const BACKGROUND = "#bbb";
const SHADOW = "#fff";

const isRetina = window.devicePixelRatio > 1;

const userAgent = navigator.userAgent.toLowerCase();
const isIE = userAgent.includes("msie");
const isChrome = userAgent.includes("chrome");
const isSafari = userAgent.includes("safari") && !isChrome;

function getFaviconTag(): HTMLLinkElement | null {
  for (const link of document.getElementsByTagName("link")) {
    const rel = link.getAttribute("rel");
    if (rel === "icon" || rel === "shortcut icon") {
      return link;
    }
  }
  return null;
}

function removeFaviconTag(): void {
  const links = Array.from(document.getElementsByTagName("link"));
  for (const link of links) {
    const rel = link.getAttribute("rel");
    if (rel === "icon" || rel === "shortcut icon") {
      link.remove();
    }
  }
}

function setFaviconTag(url: string): void {
  removeFaviconTag();
  const link = document.createElement("link");
  link.type = "image/x-icon";
  link.rel = "icon";
  link.href = url;
  document.head.append(link);
}

function getCanvas(): HTMLCanvasElement {
  if (canvas === null) {
    canvas = document.createElement("canvas");
    const size = isRetina ? 32 : 16;
    canvas.width = size;
    canvas.height = size;
  }
  return canvas;
}

function drawFavicon(percentage: number): void {
  const canvasEl = getCanvas();
  const context = canvasEl.getContext("2d");
  if (context === null) {
    return;
  }

  const width = canvasEl.width;
  const height = canvasEl.height;
  const radius = Math.min(width / 2, height / 2);

  context.clearRect(0, 0, width, height);

  context.beginPath();
  context.moveTo(width / 2, height / 2);
  context.arc(width / 2, height / 2, radius, 0, Math.PI * 2, false);
  context.fillStyle = SHADOW;
  context.fill();

  context.beginPath();
  context.moveTo(width / 2, height / 2);
  context.arc(width / 2, height / 2, radius - 2, 0, Math.PI * 2, false);
  context.fillStyle = BACKGROUND;
  context.fill();

  if (percentage > 0) {
    context.beginPath();
    context.moveTo(width / 2, height / 2);
    context.arc(
      width / 2,
      height / 2,
      radius - 2,
      -0.5 * Math.PI,
      (-0.5 + (2 * percentage) / 100) * Math.PI,
      false,
    );
    context.lineTo(width / 2, height / 2);
    context.fillStyle = COLOR;
    context.fill();
  }

  setFaviconTag(canvasEl.toDataURL());
}

function updateTitle(percentage: number): void {
  if (percentage > 0) {
    document.title = "(" + String(percentage) + "%) " + (originalTitle ?? "");
  } else {
    document.title = originalTitle ?? "";
  }
}

export function setProgress(percentage: number): void {
  originalTitle ??= document.title;

  if (originalFavicon === null || currentFavicon === null) {
    const tag = getFaviconTag();
    originalFavicon = currentFavicon = tag?.getAttribute("href") ?? "/favicon.ico";
  }

  if (!Number.isFinite(percentage)) {
    return;
  }

  if (isIE || isSafari) {
    updateTitle(percentage);
    return;
  }

  drawFavicon(percentage);
}

export function reset(): void {
  if (originalTitle !== null) {
    document.title = originalTitle;
  }
  if (originalFavicon !== null) {
    currentFavicon = originalFavicon;
    setFaviconTag(currentFavicon);
  }
}
