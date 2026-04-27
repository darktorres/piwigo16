// Module-augmentation for vendored jQuery plugins loaded as page globals.

import 'jquery';

declare global {
    interface JQuery {
        // ── admin/common.ts ──────────────────────────────────────────────────
        fontCheckbox(): JQuery;
        pwg_jconfirm_follow_href(options?: {
            alert_title?: string;
            alert_confirm?: string;
            alert_cancel?: string;
            alert_content?: string;
        }): void;

        // ── doubleSlider.ts ──────────────────────────────────────────────────
        pwgDoubleSlider(options: {
            values: Array<number | string>;
            selected: { min: number | string; max: number | string };
            text: string;
        }): JQuery;

        // ── addAlbum.ts ──────────────────────────────────────────────────────
        pwgAddAlbum(options?: Record<string, unknown>): JQuery;

        // ── datepicker.ts ────────────────────────────────────────────────────
        pwgDatepicker(settings?: Record<string, unknown>): JQuery;
        datetimepicker(...args: unknown[]): unknown;

        // ── albums.ts: jqtree plugin ─────────────────────────────────────────
        // tree() is variadic: tree('methodName', args...) or tree({options})
        tree(...args: unknown[]): any;
        // jqtree stores node data as properties on the jQuery-wrapped node element
        name?: any;
        id?: any;
        open_nodes?: any;
        haveChildren?: any;
        load_on_demand?: any;
        nb_sub_photos?: any;

        // ── batchManager: shift-click selection ──────────────────────────────
        enableShiftClick(options?: Record<string, unknown>): JQuery;

        // ── Vendored plugins ─────────────────────────────────────────────────
        colorbox(opts?: Record<string, unknown>): JQuery;
        coloris(opts?: Record<string, unknown>): JQuery;
        tipTip(opts?: Record<string, unknown>): JQuery;
        cluetip(opts?: Record<string, unknown>): JQuery;
        selectize(opts?: Record<string, unknown>): JQuery;
        Jcrop(opts?: Record<string, unknown>, cb?: () => void): JQuery;
        autogrow(opts?: { onInitialize?: boolean }): JQuery;
        plupload(opts: Record<string, unknown>): JQuery;
        progressBar(value: number): JQuery;
        fileupload(opts: Record<string, unknown>): JQuery;
        tokenInput(data: unknown, opts?: Record<string, unknown>): JQuery;
        sortElements(comparator: (a: HTMLElement, b: HTMLElement) => number, getSortable?: () => HTMLElement): JQuery;
    }

    // jqtree augments jQuery events with node and move_info properties
    namespace JQuery {
        interface TriggeredEvent<
            TDelegateTarget = any,
            TData = any,
            TCurrentTarget = any,
            TTarget = any
        > {
            node?: any;
            move_info?: any;
        }
    }

    interface JQueryStatic {
        cookie(name: string, value?: string | null, options?: Record<string, unknown>): string | null;
        manageAjax: {
            create(name: string, opts?: {
                queue?: boolean;
                cacheResponse?: boolean;
                maxRequests?: number;
                preventDoubleRequests?: boolean;
            }): {
                add(req: JQueryAjaxSettings & Record<string, unknown>): void;
                abort(name?: string): void;
            };
            add(name: string, req: JQueryAjaxSettings & Record<string, unknown>): void;
        };
        timepicker: Record<string, unknown>;
        confirm(opts: Record<string, unknown>): void;
        alert(opts: Record<string, unknown>): void;
    }
}
