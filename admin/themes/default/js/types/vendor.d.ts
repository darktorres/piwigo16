// Ambient stubs for third-party libs that ship no @types package

declare module 'chart.js' {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const Chart: any;
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
