// Ambient globals injected by Smarty templates via inline <script> tags
// or exposed to window by other bundles loaded earlier on the same page.
// Classes/consts defined in our own .ts files are NOT redeclared here to
// avoid duplicate-identifier errors. Instead they appear on Window interface.

declare global {
    // ── PHP/Smarty-emitted constants ─────────────────────────────────────────
    const pwg_token: string;
    const pwg_root_url: string;
    const cookie_path: string;
    const cookie_domain: string;

    // ── From themes/_base/js/scripts.ts (core.scripts bundle) ─────────────
    function phpWGOpenWindow(theURL: string, winName: string, features: string): void;
    function pwgBind(
        object: object,
        method: (...args: unknown[]) => unknown,
        ...args: unknown[]
    ): (...more: unknown[]) => unknown;
    function pwgAddEventListener(elem: Element | Window, evt: string, fn: EventListener): void;
    function popuphelp(url: string): void;

    // ── From themes/admin/_base/js/common.ts ───────────────────────────────
    function array_delete<T>(arr: T[], item: T): void;
    function str_repeat(s: string, n: number): string;
    function getRandomInt(min: number, max: number): number;
    function sprintf(format: string, ...args: unknown[]): string;

    // jConfirm option sets — declared as var to avoid conflict with const in common.ts module
    var jConfirm_alert_options: Record<string, unknown>;
    var jConfirm_confirm_options: Record<string, unknown>;
    var jConfirm_warning_options: Record<string, unknown>;
    var jConfirm_confirm_with_content_options: Record<string, unknown>;

    // TemporaryState — constructor-type var to avoid conflict with class in common.ts module
    var TemporaryState: new () => {
        changeAttribute(el: HTMLElement, attr: string, tempVal: string): void;
        changeClass(el: HTMLElement, st: boolean, tempclass: string): void;
        addClass(el: HTMLElement, tempclass: string): void;
        removeClass(el: HTMLElement, tempclass: string): void;
        changeHTML(el: HTMLElement, html: string): void;
        reverse(): void;
    };

    // ── From themes/standard_pages/js/toaster.ts ─────────────────────────────
    function pwgToaster(info: { text: string; icon: 'success' | 'error'; time?: number }): void;

    // ── Template-set vars (set by inline <script> before bundles load) ────────
    // switchbox.ts
    var SwitchBox: Array<string> | { push(link: string, box: string): void };

    // rating.ts
    var _pwgRatingAutoQueue:
        | Array<Record<string, unknown>>
        | { push(opts: Record<string, unknown>): void };

    // thumbnails.loader.ts
    var max_requests: number | undefined;
    var error_icon: string | undefined;

    // ── Window interface extensions ───────────────────────────────────────────
    interface Window {
        // PwgWS class exposed by scripts.ts
        PwgWS: new (urlRoot: string) => {
            callService(
                method: string,
                parameters: Record<string, unknown> | null,
                options?: {
                    method?: string;
                    async?: boolean;
                    onFailure?: (code: number, text: string) => void;
                    onSuccess?: (result: unknown) => void;
                }
            ): void;
        };

        // LocalStorageCache classes exposed by LocalStorageCache.ts
        LocalStorageCache: new (...args: unknown[]) => unknown;
        CategoriesCache: new (...args: unknown[]) => unknown;
        TagsCache: new (...args: unknown[]) => unknown;
        GroupsCache: new (...args: unknown[]) => unknown;
        UsersCache: new (...args: unknown[]) => unknown;
    }

    // ── standard_pages.ts ───────────────────────────────────────────────────
    var selected_language: string;
    var url_logo_dark: string;
    var url_logo_light: string;

    // ── profile.ts ──────────────────────────────────────────────────────────
    var canUpdatePreferences: boolean;
    var canUpdatePassword: boolean;
    var can_manage_api: boolean;
    var user: Record<string, unknown>;
    var preferencesDefaultValues: Record<string, unknown>;
    var standardSaveSelector: string[];
    var selected_date: string;
    var no_time_elapsed: string;
    var str_handle_error: string;
    var str_copy_key_secret: string;
    var str_copy_key_id: string;
    var str_cant_copy: string;
    var str_api_added: string;
    var str_api_edited: string;
    var str_api_revoked: string;
    var str_revoke_key: string;
    var str_show_expired: string;
    var str_hide_expired: string;
}

export {};
