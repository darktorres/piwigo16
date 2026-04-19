// Ambient stubs for third-party libs that ship no @types package

declare module 'chart.js' {
  interface ChartPointDefaults { radius: number; hitRadius: number }
  interface ChartGlobalDefaults {
    elements: { point: ChartPointDefaults; [k: string]: Record<string, unknown> };
    defaultFontSize: number;
    defaultFontColor: string;
    tooltips: { intersect: boolean; [k: string]: unknown };
    legend: { onClick: null | ((e: Event, item: unknown) => void); display?: boolean; [k: string]: unknown };
    [k: string]: unknown;
  }
  interface ChartScaleAxis { time: { tooltipFormat?: string; unit?: string; displayFormats?: Record<string, string> }; [k: string]: unknown }
  interface ChartOptions {
    scales?: { xAxes?: ChartScaleAxis[]; yAxes?: ChartScaleAxis[]; [k: string]: unknown };
    legend?: { display?: boolean; [k: string]: unknown };
    tooltips?: { mode?: string; [k: string]: unknown };
    hover?: { intersect?: boolean; [k: string]: unknown };
    [k: string]: unknown;
  }
  interface ChartData { datasets?: unknown[]; [k: string]: unknown }
  interface ChartInstance { data: ChartData; options: ChartOptions; update(): void }
  interface ChartStatic {
    new(ctx: CanvasRenderingContext2D, config: { type: string; [k: string]: unknown }): ChartInstance;
    defaults: { global: ChartGlobalDefaults };
  }
  const Chart: ChartStatic;
  export default Chart;
}

declare module 'glightbox' {
  interface GLightboxOptions {
    selector?: string;
    loop?: boolean;
    autoplayVideos?: boolean;
    touchNavigation?: boolean;
    [key: string]: unknown;
  }
  interface GLightboxInstance {
    on(event: string, callback: (...args: unknown[]) => void): void;
    open(): void;
    close(): void;
    destroy(): void;
  }
  function GLightbox(options?: GLightboxOptions): GLightboxInstance;
  export default GLightbox;
}

declare module 'piecon' {
  interface PieconOptions {
    color?: string;
    background?: string;
    shadow?: string;
    fallback?: boolean | string;
  }
  const Piecon: {
    setOptions(options: PieconOptions): void;
    setProgress(progress: number): void;
    reset(): void;
  };
  export default Piecon;
}

declare module 'jgrowl' {
  interface JGrowlOptions {
    life?: number;
    speed?: string;
    position?: string;
    closerTemplate?: string;
    header?: string;
    group?: string;
    sticky?: boolean;
    [key: string]: unknown;
  }
  function jGrowl(message: string, options?: JGrowlOptions): void;
  export default jGrowl;
}
