// Native replacement for Chart.js 2.9.3 + moment 2.26.0 (P49-C). A
// repo-wide grep confirmed `stats.ts` is the only real consumer of either
// library, for exactly one chart: a canvas line chart with two real axis
// modes -- a single time-scaled series with a gradient fill (the default
// hours/days/months/years view) and several category-scaled series with a
// legend ("compare mode", either by year or by day-of-month). This is a
// purpose-built engine for that one chart, not a generic Chart.js
// workalike -- most of both libraries' own surface (every other chart
// type, animation, the adapter/plugin systems, moment's parsing) was never
// exercised here.
//
// Two real, confirmed pre-existing behaviours are preserved rather than
// "fixed", since a rewrite is not a licence to change what the page
// already renders:
//
// - `changeData()` in the original `stats.ts` reassigned `chart.options`
//   wholesale on every call, which drops the `maintainAspectRatio: false`
//   passed only at construction time (Chart.js's own `updateConfig()`
//   re-merges the *current* `options` against its defaults, not the
//   original config, and the global default is `true`). Real production
//   behaviour was therefore never "fill the container's height" -- it was
//   always locked to the `<canvas width="400" height="150">` attributes'
//   own 400:150 aspect ratio (`me.aspectRatio` is set once, from those
//   attributes, at construction). `ASPECT_RATIO` below reproduces that
//   ratio directly against the container's real width, rather than
//   reintroducing a `maintainAspectRatio` concept this app never actually
//   got to use.
// - The gradient fill's own `ctx.createLinearGradient(0, 400, 0, 0)` used
//   a hardcoded 400px span regardless of the canvas's real rendered
//   height (also confirmed above to be ~241px) -- so the fill was never
//   fully transparent at the plot's own bottom edge. Kept as-is in
//   `drawFill()`.
//
// `moment.locale(lang_code)` never actually applied in production either:
// no `moment/locale/*` file is imported anywhere in this app (a separate
// grep), so every real deployment silently formatted every date in
// English regardless of the admin's own language. That is not a
// behaviour worth preserving -- it is a packaging gap, not a real
// visible feature -- so date formatting here takes a real
// `Intl.DateTimeFormat` locale from its caller instead (`stats.ts` builds
// one from the same `lang_code` the old code already read).

export type LineChartUnit = "day" | "month" | "year";

export interface LineChartPoint {
  x: number;
  y: number;
}

export interface LineChartSeries {
  label: string;
  color: string;
  /**
   * RGB triplet for the gradient fill under the line (the plain,
   * single-series view only -- no real compare-mode series fills).
   */
  fillColor?: [number, number, number];
  points: LineChartPoint[];
}

export interface LineChartTimeAxis {
  kind: "time";
  unit: LineChartUnit;
  tickFormat(date: Date): string;
  tooltipFormat(date: Date): string;
}

export interface LineChartCategoryAxis {
  kind: "category";
  labels: string[];
}

export interface LineChartConfig {
  xAxis: LineChartTimeAxis | LineChartCategoryAxis;
  series: LineChartSeries[];
  legend: boolean;
  yAxisLabel?: string;
}

const FONT_SIZE = 14;
const FONT_COLOR = "#888";
const FONT_FAMILY =
  '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
const GRID_COLOR = "#e5e5e5";

/** The real `<canvas width="400" height="150">` ratio -- see this file's own header comment. */
const ASPECT_RATIO = 400 / 150;

const PADDING_TOP = 10;
const PADDING_RIGHT = 12;
const PADDING_BOTTOM = 6;
const TICK_GAP = 6;
const LEGEND_ROW_HEIGHT = 22;
const LEGEND_SWATCH = 12;

/**
 * Heckbert's "nice numbers" -- the same family of algorithm Chart.js's own
 * linear-scale tick generator uses, picked fresh here rather than ported
 * line-for-line, since the goal is "looks like a normal axis", not a byte
 * match with `Chart.Ticks.generators.linear`.
 */
function roundedNiceFraction(fraction: number): number {
  if (fraction < 1.5) return 1;
  if (fraction < 3) return 2;
  if (fraction < 7) return 5;
  return 10;
}

function ceiledNiceFraction(fraction: number): number {
  if (fraction <= 1) return 1;
  if (fraction <= 2) return 2;
  if (fraction <= 5) return 5;
  return 10;
}

function niceNum(range: number, round: boolean): number {
  const exponent = Math.floor(Math.log10(range));
  const fraction = range / Math.pow(10, exponent);
  const niceFraction = round
    ? roundedNiceFraction(fraction)
    : ceiledNiceFraction(fraction);
  return niceFraction * Math.pow(10, exponent);
}

interface NiceScale {
  max: number;
  ticks: number[];
}

/** Always anchored at 0 -- every real caller sets `ticks.min: 0` on the y-axis. */
function niceTicks(max: number, targetCount = 5): NiceScale {
  const safeMax = max <= 0 ? 1 : max;
  const range = niceNum(safeMax, false);
  const step = niceNum(range / (targetCount - 1), true);
  const niceMax = Math.ceil(safeMax / step) * step;
  const ticks: number[] = [];
  for (let v = 0; v <= niceMax + step / 2; v += step) {
    ticks.push(Math.round(v * 1e6) / 1e6);
  }
  return { max: niceMax, ticks };
}

function startOfUnit(date: Date, unit: LineChartUnit): Date {
  const d = new Date(date);
  d.setHours(0, 0, 0, 0);
  if (unit === "month") {
    d.setDate(1);
  } else if (unit === "year") {
    d.setMonth(0, 1);
  }
  return d;
}

function addUnit(date: Date, unit: LineChartUnit): Date {
  const d = new Date(date);
  if (unit === "day") {
    d.setDate(d.getDate() + 1);
  } else if (unit === "month") {
    d.setMonth(d.getMonth() + 1);
  } else {
    d.setFullYear(d.getFullYear() + 1);
  }
  return d;
}

/** Every real unit boundary spanning the domain -- thinned to fit in `fitTimeTicks()`. */
function timeTickCandidates(minMs: number, maxMs: number, unit: LineChartUnit): Date[] {
  const ticks: Date[] = [];
  let cur = startOfUnit(new Date(minMs), unit);
  const endMs = Math.max(maxMs, minMs);
  do {
    ticks.push(new Date(cur));
    cur = addUnit(cur, unit);
  } while (cur.getTime() <= endMs);
  return ticks;
}

interface LegendItem {
  series: LineChartSeries;
  x: number;
  row: number;
}

function layoutLegend(
  ctx: CanvasRenderingContext2D,
  series: LineChartSeries[],
  startX: number,
  maxWidth: number,
): { items: LegendItem[]; rowCount: number } {
  const ITEM_GAP = 18;
  const items: LegendItem[] = [];
  let x = startX;
  let row = 0;
  for (const s of series) {
    const itemWidth = LEGEND_SWATCH + TICK_GAP + ctx.measureText(s.label).width;
    if (x > startX && x + itemWidth > startX + maxWidth) {
      row += 1;
      x = startX;
    }
    items.push({ series: s, x, row });
    x += itemWidth + ITEM_GAP;
  }
  return { items, rowCount: row + 1 };
}

interface PlotRect {
  left: number;
  top: number;
  width: number;
  height: number;
}

/**
 * `stats.ts`'s own canvas line chart -- construction mirrors `new
 * Chart(ctx, {...})`, and `setData()` mirrors its own
 * `chart.data = ...; chart.options = ...; chart.update()` sequence (one
 * call instead of three, since there is no longer a real reason to keep
 * them separate).
 */
export class LineChart {
  readonly #canvas: HTMLCanvasElement;

  readonly #ctx: CanvasRenderingContext2D;

  readonly #numberFormat: Intl.NumberFormat;

  readonly #resizeObserver: ResizeObserver;

  #config: LineChartConfig | null = null;

  #plot: PlotRect = { left: 0, top: 0, width: 0, height: 0 };

  #timeDomain = { min: 0, max: 1 };

  #categoryCount = 0;

  #yMax = 1;

  #hoverIndex: number | null = null;

  #mouseX = 0;

  #mouseY = 0;

  public constructor(canvas: HTMLCanvasElement, locale?: string) {
    this.#canvas = canvas;
    const ctx = canvas.getContext("2d");
    if (ctx === null) {
      throw new Error("LineChart requires a 2D canvas context");
    }
    this.#ctx = ctx;
    this.#numberFormat = new Intl.NumberFormat(locale);

    this.#resizeObserver = new ResizeObserver(() => {
      this.#resize();
      this.#draw();
    });
    const container = canvas.parentElement;
    if (container !== null) {
      this.#resizeObserver.observe(container);
    }
    this.#resize();

    canvas.addEventListener("mousemove", (event) => {
      this.#onMouseMove(event);
    });
    canvas.addEventListener("mouseleave", () => {
      if (this.#hoverIndex !== null) {
        this.#hoverIndex = null;
        this.#draw();
      }
    });
  }

  public setData(config: LineChartConfig): void {
    this.#config = config;
    this.#hoverIndex = null;
    this.#draw();
  }

  #resize(): void {
    const container = this.#canvas.parentElement;
    const width = container === null ? this.#canvas.clientWidth : container.clientWidth;
    const height = Math.round(width / ASPECT_RATIO);
    const dpr = window.devicePixelRatio || 1;

    this.#canvas.style.width = `${String(width)}px`;
    this.#canvas.style.height = `${String(height)}px`;
    this.#canvas.width = Math.round(width * dpr);
    this.#canvas.height = Math.round(height * dpr);
    this.#ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  #draw(): void {
    const ctx = this.#ctx;
    const canvas = this.#canvas;
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    ctx.clearRect(0, 0, width, height);

    const config = this.#config;
    if (config === null || config.series.length === 0 || width === 0) {
      return;
    }

    ctx.font = `${String(FONT_SIZE)}px ${FONT_FAMILY}`;
    ctx.textBaseline = "middle";

    const maxY = Math.max(1, ...config.series.flatMap((s) => s.points.map((p) => p.y)));
    const yTicks = niceTicks(maxY);
    const yLabelWidth = Math.max(
      ...yTicks.ticks.map((t) => ctx.measureText(this.#numberFormat.format(t)).width),
    );
    const yAxisTitleWidth = config.yAxisLabel === undefined ? 0 : FONT_SIZE + TICK_GAP;
    const plotLeft = yAxisTitleWidth + yLabelWidth + TICK_GAP;
    const plotRightEdge = width - PADDING_RIGHT;

    let legend: { items: LegendItem[]; rowCount: number } | null = null;
    if (config.legend) {
      legend = layoutLegend(ctx, config.series, plotLeft, plotRightEdge - plotLeft);
    }
    const legendHeight = legend === null ? 0 : legend.rowCount * LEGEND_ROW_HEIGHT;
    const plotTop = PADDING_TOP + legendHeight;

    const xTicks =
      config.xAxis.kind === "time"
        ? this.#fitTimeTicks(config.xAxis, config.series[0]?.points ?? [], plotRightEdge - plotLeft)
        : this.#fitCategoryTicks(config.xAxis.labels, plotRightEdge - plotLeft);
    const plotBottom = height - PADDING_BOTTOM - FONT_SIZE - TICK_GAP;

    this.#plot = {
      left: plotLeft,
      top: plotTop,
      width: plotRightEdge - plotLeft,
      height: Math.max(1, plotBottom - plotTop),
    };
    this.#yMax = yTicks.max;

    if (config.xAxis.kind === "time") {
      const xs = (config.series[0]?.points ?? []).map((p) => p.x);
      this.#timeDomain = { min: Math.min(...xs, 0), max: Math.max(...xs, 1) };
      this.#categoryCount = 0;
    } else {
      this.#categoryCount = config.xAxis.labels.length;
    }

    this.#drawYAxis(yTicks, config.yAxisLabel);
    this.#drawXTicks(xTicks);

    for (const series of config.series) {
      this.#drawSeries(config.xAxis, series);
    }

    if (legend !== null) {
      this.#drawLegend(legend, plotTop - legendHeight);
    }

    if (this.#hoverIndex !== null) {
      this.#drawTooltip(config);
    }
  }

  #xScaleTime(x: number): number {
    const { min, max } = this.#timeDomain;
    const span = max - min || 1;
    return this.#plot.left + ((x - min) / span) * this.#plot.width;
  }

  #xScaleCategory(index: number): number {
    const count = Math.max(1, this.#categoryCount);
    return this.#plot.left + ((index + 0.5) / count) * this.#plot.width;
  }

  #yScale(y: number): number {
    return this.#plot.top + this.#plot.height - (y / this.#yMax) * this.#plot.height;
  }

  #drawYAxis(yTicks: NiceScale, axisLabel: string | undefined): void {
    const ctx = this.#ctx;
    const plot = this.#plot;
    ctx.strokeStyle = GRID_COLOR;
    ctx.fillStyle = FONT_COLOR;
    ctx.textAlign = "right";
    ctx.lineWidth = 1;

    for (const tick of yTicks.ticks) {
      const y = this.#yScale(tick);
      ctx.beginPath();
      ctx.moveTo(plot.left, y);
      ctx.lineTo(plot.left + plot.width, y);
      ctx.stroke();
      ctx.fillText(this.#numberFormat.format(tick), plot.left - TICK_GAP, y);
    }

    if (axisLabel !== undefined) {
      ctx.save();
      ctx.textAlign = "center";
      ctx.translate(FONT_SIZE, plot.top + plot.height / 2);
      ctx.rotate(-Math.PI / 2);
      ctx.fillText(axisLabel, 0, 0);
      ctx.restore();
    }
  }

  #fitTimeTicks(
    axis: LineChartTimeAxis,
    points: LineChartPoint[],
    availableWidth: number,
  ): { pixelX: number; label: string }[] {
    if (points.length === 0) {
      return [];
    }
    const xs = points.map((p) => p.x);
    const min = Math.min(...xs);
    const max = Math.max(...xs);
    // Unit boundaries can fall before the domain's own real start (e.g. the
    // 1st of a month whose data begins on the 15th) -- excluded rather than
    // drawn at a negative pixel offset, which pushed a label out past the
    // canvas's own left edge, confirmed live via a VR screenshot.
    const candidates = timeTickCandidates(min, max, axis.unit).filter(
      (d) => d.getTime() >= min && d.getTime() <= max,
    );
    const labeled = candidates.map((d) => ({ date: d, label: axis.tickFormat(d) }));
    const maxLabelWidth = Math.max(20, ...labeled.map((t) => this.#ctx.measureText(t.label).width));
    const maxFit = Math.max(1, Math.floor(availableWidth / (maxLabelWidth + 12)));
    const stride = Math.max(1, Math.ceil(labeled.length / maxFit));

    return labeled
      .filter((_, i) => i % stride === 0)
      .map((t) => ({ pixelX: this.#xScaleTimeForDomain(t.date.getTime(), min, max), label: t.label }));
  }

  /** Ticks are positioned before `this.#timeDomain` is set for the draw in progress. */
  #xScaleTimeForDomain(x: number, min: number, max: number): number {
    const span = max - min || 1;
    return this.#plot.left + ((x - min) / span) * this.#plot.width;
  }

  #fitCategoryTicks(
    labels: string[],
    availableWidth: number,
  ): { pixelX: number; label: string }[] {
    if (labels.length === 0) {
      return [];
    }
    const maxLabelWidth = Math.max(10, ...labels.map((l) => this.#ctx.measureText(l).width));
    const maxFit = Math.max(1, Math.floor(availableWidth / (maxLabelWidth + 8)));
    const stride = Math.max(1, Math.ceil(labels.length / maxFit));

    return labels
      .map((label, i) => ({ label, i }))
      .filter((_, i) => i % stride === 0)
      .map(({ label, i }) => ({
        pixelX: this.#plot.left + ((i + 0.5) / labels.length) * availableWidth,
        label,
      }));
  }

  /**
   * Center-aligned around `pixelX`, except where that would spill the label
   * past the canvas's own edges (the first/last tick, near either end of
   * the plot area) -- shifted just enough to stay on-canvas instead,
   * confirmed live via a VR screenshot showing the real overflow.
   */
  #drawXTicks(ticks: { pixelX: number; label: string }[]): void {
    const ctx = this.#ctx;
    const plot = this.#plot;
    const canvas = this.#canvas;
    ctx.fillStyle = FONT_COLOR;
    ctx.textAlign = "left";
    const y = plot.top + plot.height + TICK_GAP + FONT_SIZE / 2;
    const canvasWidth = canvas.clientWidth;
    for (const tick of ticks) {
      const {width} = ctx.measureText(tick.label);
      const left = Math.min(Math.max(tick.pixelX - width / 2, 0), canvasWidth - width);
      ctx.fillText(tick.label, left, y);
    }
  }

  #drawSeries(
    axis: LineChartTimeAxis | LineChartCategoryAxis,
    series: LineChartSeries,
  ): void {
    if (series.points.length === 0) {
      return;
    }
    const ctx = this.#ctx;
    const toPixel = (p: LineChartPoint): [number, number] => [
      axis.kind === "time" ? this.#xScaleTime(p.x) : this.#xScaleCategory(p.x),
      this.#yScale(p.y),
    ];

    if (series.fillColor !== undefined) {
      this.#drawFill(series, toPixel);
    }

    ctx.strokeStyle = series.color;
    ctx.lineWidth = 2;
    ctx.beginPath();
    series.points.forEach((p, i) => {
      const [x, y] = toPixel(p);
      if (i === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    });
    ctx.stroke();
  }

  /**
   * `ctx.createLinearGradient(0, 400, 0, 0)` in the original -- a hardcoded
   * 400px span kept verbatim, see this file's own header comment.
   */
  #drawFill(
    series: LineChartSeries,
    toPixel: (p: LineChartPoint) => [number, number],
  ): void {
    const ctx = this.#ctx;
    const plot = this.#plot;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- this method's own caller only invokes it after checking `series.fillColor !== undefined`; that narrowing doesn't cross the function boundary.
    const [r, g, b] = series.fillColor as [number, number, number];
    const gradient = ctx.createLinearGradient(0, 400, 0, 0);
    gradient.addColorStop(0, `rgba(${String(r)},${String(g)},${String(b)},0)`);
    gradient.addColorStop(1, `rgba(${String(r)},${String(g)},${String(b)},1)`);

    ctx.fillStyle = gradient;
    ctx.beginPath();
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- this method's own caller only invokes it after checking `series.points.length !== 0`; that narrowing doesn't cross the function boundary.
    const first = toPixel(series.points[0] as LineChartPoint);
    ctx.moveTo(first[0], plot.top + plot.height);
    ctx.lineTo(first[0], first[1]);
    for (const p of series.points.slice(1)) {
      const [x, y] = toPixel(p);
      ctx.lineTo(x, y);
    }
    const last = toPixel(
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- this method's own caller only invokes it after checking `series.points.length !== 0`; that narrowing doesn't cross the function boundary.
      series.points[series.points.length - 1] as LineChartPoint,
    );
    ctx.lineTo(last[0], plot.top + plot.height);
    ctx.closePath();
    ctx.fill();
  }

  #drawLegend(legend: { items: LegendItem[]; rowCount: number }, top: number): void {
    const ctx = this.#ctx;
    ctx.textAlign = "left";
    for (const item of legend.items) {
      const y = top + item.row * LEGEND_ROW_HEIGHT + LEGEND_ROW_HEIGHT / 2;
      ctx.fillStyle = item.series.color;
      ctx.fillRect(item.x, y - LEGEND_SWATCH / 2, LEGEND_SWATCH, LEGEND_SWATCH);
      ctx.fillStyle = FONT_COLOR;
      ctx.fillText(item.series.label, item.x + LEGEND_SWATCH + TICK_GAP, y);
    }
  }

  #onMouseMove(event: MouseEvent): void {
    if (this.#config === null) {
      return;
    }
    const x = event.offsetX;
    const y = event.offsetY;
    const plot = this.#plot;
    if (x < plot.left || x > plot.left + plot.width || y < plot.top || y > plot.top + plot.height) {
      if (this.#hoverIndex !== null) {
        this.#hoverIndex = null;
        this.#draw();
      }
      return;
    }

    const index = this.#nearestIndex(x);
    if (index !== this.#hoverIndex || x !== this.#mouseX || y !== this.#mouseY) {
      this.#hoverIndex = index;
      this.#mouseX = x;
      this.#mouseY = y;
      this.#draw();
    }
  }

  #nearestIndex(mouseX: number): number | null {
    const config = this.#config;
    if (config === null) {
      return null;
    }

    if (config.xAxis.kind === "time") {
      const points = config.series[0]?.points ?? [];
      if (points.length === 0) {
        return null;
      }
      let best = 0;
      let bestDist = Infinity;
      points.forEach((p, i) => {
        const dist = Math.abs(this.#xScaleTime(p.x) - mouseX);
        if (dist < bestDist) {
          bestDist = dist;
          best = i;
        }
      });
      return best;
    }

    const count = this.#categoryCount;
    if (count === 0) {
      return null;
    }
    let best = 0;
    let bestDist = Infinity;
    for (let i = 0; i < count; i += 1) {
      const dist = Math.abs(this.#xScaleCategory(i) - mouseX);
      if (dist < bestDist) {
        bestDist = dist;
        best = i;
      }
    }
    return best;
  }

  /**
   * `tooltips: {mode: "index"}` for the time axis (one real series, so
   * "the point at the nearest index" and "the nearest point" always
   * agree) and `{mode: "nearest"}` for compare mode. Both real configs
   * left `tooltips.intersect` at its own global-default override
   * (`Chart.defaults.global.tooltips.intersect = false`, set once and
   * never touched by `changeData()`'s later wholesale `options`
   * reassignment, unlike `maintainAspectRatio` above -- a *default*
   * mutation survives `updateConfig()`'s re-merge, an instance-level
   * construction option doesn't), so the real tooltip was never gated on
   * the cursor actually intersecting a point -- it always showed
   * *something* while hovering the plot area. One consistent
   * "nearest index" hit-test below reproduces that for both axis kinds,
   * rather than two separate real interaction-mode algorithms for a
   * single already-small chart.
   */
  #drawTooltip(config: LineChartConfig): void {
    const index = this.#hoverIndex;
    if (index === null) {
      return;
    }

    let title: string;
    const lines: { color: string; text: string }[] = [];

    if (config.xAxis.kind === "time") {
      const [series] = config.series;
      const point = series?.points[index];
      if (series === undefined || point === undefined) {
        return;
      }
      title = config.xAxis.tooltipFormat(new Date(point.x));
      lines.push({ color: series.color, text: `${series.label}: ${this.#numberFormat.format(point.y)}` });
    } else {
      title = config.xAxis.labels[index] ?? "";
      for (const series of config.series) {
        const point = series.points.find((p) => p.x === index);
        if (point !== undefined) {
          lines.push({
            color: series.color,
            text: `${series.label}: ${this.#numberFormat.format(point.y)}`,
          });
        }
      }
      if (lines.length === 0) {
        return;
      }
    }

    this.#paintTooltip(title, lines);
  }

  #paintTooltip(title: string, lines: { color: string; text: string }[]): void {
    const ctx = this.#ctx;
    const canvas = this.#canvas;
    const PADDING = 8;
    const LINE_HEIGHT = FONT_SIZE + 4;

    ctx.font = `bold ${String(FONT_SIZE)}px ${FONT_FAMILY}`;
    const titleWidth = ctx.measureText(title).width;
    ctx.font = `${String(FONT_SIZE)}px ${FONT_FAMILY}`;
    const bodyWidth = Math.max(0, ...lines.map((l) => ctx.measureText(l.text).width + 16));
    const boxWidth = Math.max(titleWidth, bodyWidth) + PADDING * 2;
    const boxHeight = PADDING * 2 + LINE_HEIGHT * (lines.length + 1);

    let x = this.#mouseX + 12;
    let y = this.#mouseY - boxHeight - 8;
    if (x + boxWidth > canvas.clientWidth) {
      x = this.#mouseX - boxWidth - 12;
    }
    if (y < 0) {
      y = this.#mouseY + 12;
    }

    ctx.fillStyle = "rgba(0,0,0,0.8)";
    ctx.fillRect(x, y, boxWidth, boxHeight);

    ctx.textAlign = "left";
    ctx.textBaseline = "top";
    ctx.fillStyle = "#fff";
    ctx.font = `bold ${String(FONT_SIZE)}px ${FONT_FAMILY}`;
    ctx.fillText(title, x + PADDING, y + PADDING);

    ctx.font = `${String(FONT_SIZE)}px ${FONT_FAMILY}`;
    lines.forEach((line, i) => {
      const lineY = y + PADDING + LINE_HEIGHT * (i + 1);
      ctx.fillStyle = line.color;
      ctx.fillRect(x + PADDING, lineY + 4, 8, 8);
      ctx.fillStyle = "#fff";
      ctx.fillText(line.text, x + PADDING + 14, lineY);
    });

    ctx.textBaseline = "middle";
  }
}
