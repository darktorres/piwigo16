import TomSelect from 'tom-select';
import noUiSlider from 'nouislider';
import 'nouislider/dist/nouislider.css';
import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import { getPageData } from './page-data';
import { config } from './config';

interface UserListPageData {
    pwg_token: string;
    connected_user: number;
    connected_user_status: string;
    owner_id: number;
    owner_username: string;
    guest_id: number;
    has_group: string;
    view_selector: string;
    pagination: number;
    history_base_url: string;
    register_dates: string[];
    groups_arr: [number, string][];
    months: string[];
    status_to_str: Record<string, string>;
    // translation strings
    cancel_msg: string;
    cannotSendMail: string;
    cantCopy: string;
    confirm_msg: string;
    copyLinkStr: string;
    dates_infos: string;
    errorMailSent: string;
    errorMailSentMsg: string;
    errorStr: string;
    fieldNotEmpty: string;
    filtered_user: string;
    filtered_users: string;
    last_visit_str: string;
    mailSentAt: string;
    mainAskWebmaster: string;
    mainUserContinue: string;
    mainUserRewrite: string;
    mainUserSet: string;
    mainUserStr: string;
    mainUserSuccess: string;
    mainUserUpgradeWebmaster: string;
    mainUserValidate: string;
    missingConfirm: string;
    missingConfPassword: string;
    missingField: string;
    missingPassword: string;
    missingUsername: string;
    noMatchPassword: string;
    nb_days: string;
    nb_photos: string;
    passwordCopied: string;
    registered_str: string;
    str_and_others_tags: string;
    title_msg: string;
    user_added_str: string;
    validLinkMail: string;
    validLinkWithoutMail: string;
}

const {
    pwg_token: _pwg_token,
    connected_user: _connected_user,
    connected_user_status: _connected_user_status,
    owner_id: _owner_id,
    owner_username: _owner_username,
    guest_id: _guest_id,
    has_group: _has_group,
    view_selector: _view_selector,
    pagination: _pagination,
    history_base_url,
    register_dates: _register_dates,
    groups_arr: _groups_arr,
    months: _months,
    status_to_str,
    cancel_msg: _cancel_msg,
    cannotSendMail,
    cantCopy,
    confirm_msg: _confirm_msg,
    copyLinkStr: _copyLinkStr,
    dates_infos,
    errorMailSent,
    errorMailSentMsg,
    errorStr: _errorStr,
    fieldNotEmpty,
    filtered_user,
    filtered_users,
    last_visit_str,
    mailSentAt,
    mainAskWebmaster,
    mainUserContinue,
    mainUserRewrite,
    mainUserSet,
    mainUserStr,
    mainUserSuccess,
    mainUserUpgradeWebmaster,
    mainUserValidate,
    missingConfirm,
    missingConfPassword,
    missingField,
    missingPassword,
    missingUsername,
    noMatchPassword,
    nb_days,
    nb_photos: _nb_photos,
    passwordCopied,
    registered_str,
    str_and_others_tags,
    title_msg,
    user_added_str,
    validLinkMail,
    validLinkWithoutMail,
} = getPageData<UserListPageData>();

declare function sprintf(fmt: string, ...args: unknown[]): string;
declare function getRandomInt(min: number, max: number): number;

// User shape returned by pwg.users.getList / pwg.users.getInfo (subset we read).
interface PwgUser {
    id: number;
    username: string;
    status: string;
    level: number;
    email?: string;
    groups?: Array<number | string>;
    last_visit?: string;
    last_visit_string?: string;
    last_visit_since?: string;
    last_visit_what?: string;
    registration_date?: string;
    registration_date_string?: string;
    registration_date_since?: string;
    nb_image_page?: number;
    enabled_high?: string | boolean;
    expand?: string | boolean;
    show_nb_comments?: string | boolean;
    show_nb_hits?: string | boolean;
    recent_period?: number;
    theme?: string;
    language?: string;
    nb_total_photos?: number | string;
    nb_added_photos?: number | string;
    main_user?: boolean;
    has_bookmark?: boolean;
    [key: string]: unknown;
}

// mutable state
let _number: number | undefined;

const color_icons = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'];
const status_arr = ['webmaster', 'admin', 'normal', 'generic', 'guest'];
const level_arr = ['0', '1', '2', '4', '8'];
const king_template = '<p class="icon-king" id="the_king"></p>';
let current_users: PwgUser[] = [];
const guest_id = _guest_id;
let guest_user: PwgUser | Record<string, never> = {};
const connected_user = _connected_user;
const groups_arr: [number, string][] = _groups_arr;
let last_user_index: number = -1;
let last_user_id = -1;
const pwg_token = _pwg_token;
interface SelectionItem {
    id: number;
    username?: string;
}
let selection: SelectionItem[] = [];
let first_update = true;
let total_users = 0;
let filter_by = 'id DESC';
const plugins_set_functions: Record<string, (popIn: HTMLElement, user: PwgUser) => void> = {};
const plugins_get_functions: Record<string, () => void> = {};
const plugins_load: Array<() => void> = [];
interface UserInfoTableEntry {
    content_id: string;
    users_table: string;
}
const plugins_users_infos_table: UserInfoTableEntry[] = [];
let owner_username = _owner_username;
let owner_id = _owner_id;
const connected_user_status = _connected_user_status;
const has_group = _has_group;
const view_selector = _view_selector;
const register_dates: string[] = _register_dates;
const months: string[] = _months;
const groupOptions = _groups_arr.map((x) => ({ value: x[0], label: x[1], isSelected: 0 }));
let _nb_filtered_users: number;
let per_page = _pagination;

const qs = <T extends HTMLElement = HTMLElement>(sel: string, ctx: Element | Document = document) =>
    ctx.querySelector<T>(sel);
const qsa = <T extends HTMLElement = HTMLElement>(
    sel: string,
    ctx: Element | Document = document
) => Array.from(ctx.querySelectorAll<T>(sel));
const show = (el: HTMLElement | null | undefined) => {
    if (el) el.style.display = '';
};
const hide = (el: HTMLElement | null | undefined) => {
    if (el) el.style.display = 'none';
};
function _fadeInEl(el: HTMLElement | null | undefined) {
    if (el) el.style.display = '';
}
function _fadeOutEl(el: HTMLElement | null | undefined, delay = 0) {
    if (!el) return;
    setTimeout(() => {
        el.style.transition = 'opacity 0.4s';
        el.style.opacity = '0';
        setTimeout(() => {
            el.style.display = 'none';
            el.style.opacity = '';
            el.style.transition = '';
        }, 400);
    }, delay);
}
function fadeInThenOut(el: HTMLElement | null | undefined, showMs = 0, fadeDuration = 400) {
    if (!el) return;
    el.style.display = '';
    el.style.opacity = '1';
    setTimeout(() => {
        el.style.transition = `opacity ${fadeDuration / 1000}s`;
        el.style.opacity = '0';
        setTimeout(() => {
            el.style.display = 'none';
            el.style.opacity = '';
            el.style.transition = '';
        }, fadeDuration);
    }, showMs);
}

interface WsResponse<T = unknown> {
    stat?: string;
    result?: T;
    err?: string;
    message?: string;
}

function pwgPost<T = unknown>(
    method: string,
    params: Record<string, unknown>
): Promise<WsResponse<T>> {
    const stringify = (val: unknown): string => {
        if (val === null || val === undefined) return '';
        if (typeof val === 'string') return val;
        if (typeof val === 'number' || typeof val === 'boolean') return String(val);
        return JSON.stringify(val);
    };
    const body = new URLSearchParams();
    for (const [k, v] of Object.entries(params)) {
        if (Array.isArray(v))
            (v as unknown[]).forEach((item) => body.append(k + '[]', stringify(item)));
        else if (v !== undefined && v !== null) body.append(k, stringify(v));
    }
    return fetch(`${config.wsUrl}format=json&method=${method}`, { method: 'POST', body }).then(
        (r) => r.json() as Promise<WsResponse<T>>
    );
}

type SliderEl = HTMLElement & {
    noUiSlider?: { get: () => string | string[]; set: (v: number | string) => void };
};

function getSliderValue(el: HTMLElement | null): number {
    if (el === null) return 0;
    const val = (el as SliderEl).noUiSlider?.get();
    return Array.isArray(val) ? parseInt(String(val[0])) : parseInt(String(val ?? 0));
}
function getSliderValues(el: HTMLElement | null): [number, number] {
    if (el === null) return [0, 0];
    const vals = (el as SliderEl).noUiSlider?.get();
    if (!Array.isArray(vals)) return [0, 0];
    return [parseInt(vals[0] ?? '0'), parseInt(vals[1] ?? '0')];
}
function setSliderValue(el: HTMLElement | null | undefined, val: number) {
    if (el && (el as SliderEl).noUiSlider) (el as SliderEl).noUiSlider!.set(val);
}

// Tom Select instances for groups
const groupSelectEls = Array.from(
    document.querySelectorAll<HTMLSelectElement>('[data-selectize=groups]')
);
const tsOptions = {
    valueField: 'value',
    labelField: 'label',
    searchField: ['label'],
    plugins: { remove_button: {} },
};
function makeGroupTs(idx: number): TomSelect | null {
    const el = groupSelectEls[idx];
    return el !== undefined ? new TomSelect(el, tsOptions) : null;
}
const groupTs = makeGroupTs(0);
const groupGuestTs = makeGroupTs(1);
const groupAddUserTs = makeGroupTs(2);

type TsSelect = HTMLElement & { tomselect?: TomSelect };
function getPopInTs(popIn: HTMLElement): TomSelect | null {
    const sel = popIn.querySelector<HTMLElement>('.user-property-group select');
    return sel !== null ? ((sel as TsSelect).tomselect ?? null) : null;
}

/*---- Escape popup ----*/
document.addEventListener('keydown', (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        hide_modals();
        close_user_list();
    }
});

/*---- Open/Close functions ----*/
function open_user_list() {
    hide_temporary_messages();
    show(document.getElementById('UserList'));
}
function close_user_list() {
    hide_temporary_messages();
    qs<HTMLInputElement>('#result_send_mail_copy_input')!.value = '';
    hide(document.getElementById('UserList'));
}
function open_guest_user_list() {
    hide_temporary_messages();
    show(document.getElementById('GuestUserList'));
}
function close_guest_user_list() {
    hide_temporary_messages();
    hide(document.getElementById('GuestUserList'));
}
function isSelectionMode(): boolean {
    return (
        (document.getElementById('toggleSelectionMode') as HTMLInputElement | null)?.checked ===
        true
    );
}

/*---- Sliders ----*/
const nb_image_page_values = [
    1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26,
    27, 28, 29, 30, 35, 40, 45, 50, 60, 70, 80, 90, 100, 200, 300, 500, 999,
];
const recent_period_values = [
    0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 25, 30, 40, 50, 60,
    80, 99,
];
const recent_period_init = 0;
const nb_image_page_init = 0;

function getSliderKeyFromValue(value: number, values: number[]): number {
    for (let key = 0; key < values.length; key++) {
        if (values[key]! >= value) return key;
    }
    return 0;
}
function getNbImagePageInfoFromIdx(idx: number): string {
    return String(nb_image_page_values[idx]);
}
function getRecentPeriodInfoFromIdx(idx: number): string {
    return sprintf(nb_days, recent_period_values[idx]);
}

function makeSlider(
    sel: string,
    maxIdx: number,
    initVal: number,
    onUpdate: (idx: number) => void,
    onStop?: (idx: number) => void
) {
    const el = qs<HTMLElement>(sel);
    if (!el) return;
    const slider = noUiSlider.create(el, {
        range: { min: 0, max: maxIdx },
        start: initVal,
        step: 1,
        connect: [true, false],
    });
    slider.on('update', ([val]) => onUpdate(parseInt(String(val))));
    if (onStop) slider.on('change', ([val]) => onStop(parseInt(String(val))));
}

function initSliders() {
    // Photos sliders
    [
        {
            sel: '#UserList .photos-select-bar .slider-bar-container',
            infoSel: '#UserList .photos-select-bar .nb-img-page-infos',
            inputSel: '#UserList .photos-select-bar input[name=nb_image_page]',
        },
        {
            sel: '#GuestUserList .photos-select-bar .slider-bar-container',
            infoSel: '#GuestUserList .photos-select-bar .nb-img-page-infos',
            inputSel: '#GuestUserList .photos-select-bar input[name=nb_image_page]',
        },
        {
            sel: '#permitActionUserList .photos-select-bar .slider-bar-container',
            infoSel: '#permitActionUserList .photos-select-bar .nb-img-page-infos',
            inputSel: '#permitActionUserList .photos-select-bar input[name=nb_image_page]',
        },
    ].forEach(({ sel, infoSel, inputSel }) => {
        makeSlider(
            sel,
            nb_image_page_values.length - 1,
            nb_image_page_init,
            (idx) => {
                const el = qs<HTMLElement>(infoSel);
                if (el) el.innerHTML = getNbImagePageInfoFromIdx(idx);
            },
            (idx) => {
                const inp = qs<HTMLInputElement>(inputSel);
                if (inp) {
                    inp.value = String(nb_image_page_values[idx]);
                    inp.dispatchEvent(new Event('change'));
                }
            }
        );
    });
    // Reset permitAction photos slider to 0
    setSliderValue(qs('#permitActionUserList .photos-select-bar .slider-bar-container'), 0);
    const periodInfo = getRecentPeriodInfoFromIdx(0);
    const piEl = qs<HTMLElement>('#permitActionUserList .period-select-bar .recent_period_infos');
    if (piEl) piEl.innerHTML = periodInfo;

    // Period sliders
    [
        {
            sel: '#UserList .period-select-bar .slider-bar-container',
            infoSel: '#UserList .period-select-bar .recent_period_infos',
            inputSel: '#UserList .period-select-bar input[name=recent_period]',
        },
        {
            sel: '#GuestUserList .period-select-bar .slider-bar-container',
            infoSel: '#GuestUserList .period-select-bar .recent_period_infos',
            inputSel: '#GuestUserList .period-select-bar input[name=recent_period]',
        },
        {
            sel: '#permitActionUserList .period-select-bar .slider-bar-container',
            infoSel: '#permitActionUserList .period-select-bar .recent_period_infos',
            inputSel: '#permitActionUserList .period-select-bar input[name=recent_period]',
        },
    ].forEach(({ sel, infoSel, inputSel }) => {
        makeSlider(
            sel,
            recent_period_values.length - 1,
            recent_period_init,
            (idx) => {
                const el = qs<HTMLElement>(infoSel);
                if (el) el.innerHTML = getRecentPeriodInfoFromIdx(idx);
            },
            (idx) => {
                const inp = qs<HTMLInputElement>(inputSel);
                if (inp) {
                    inp.value = String(recent_period_values[idx]);
                    inp.dispatchEvent(new Event('change'));
                }
            }
        );
    });
}

/*---- Pagination ----*/
const actual_page = 1;

function update_pagination_menu() {
    const container = qs<HTMLElement>('.pagination-item-container');
    if (container) container.innerHTML = '';
    // pages are rendered server-side; just update arrows
    qs('.pagination-arrow.left')?.classList.toggle('unavailable', true);
}

/*---- Advanced filter ----*/
function advanced_filter_button_click() {
    if (qs('.advanced-filter')?.classList.contains('advanced-filter-open') !== true)
        advanced_filter_show();
    else advanced_filter_hide();
}
function advanced_filter_show() {
    qsa('.advanced-filter-btn, .advanced-filter').forEach((el) =>
        el.classList.add('advanced-filter-open')
    );
}
function advanced_filter_hide() {
    qsa('.advanced-filter-btn, .advanced-filter').forEach((el) =>
        el.classList.remove('advanced-filter-open')
    );
}

function getDateStr(date: string): string {
    const parts = date.split('-');
    return months[parseInt(parts[1]!) - 1]! + ' ' + parts[0]!;
}

function setupRegisterDates(register_dates: string[]) {
    const el = qs<HTMLElement>('.advanced-filter .dates-select-bar .slider-bar-container');
    if (!el || register_dates.length === 0) return;
    const slider = noUiSlider.create(el, {
        range: { min: 0, max: register_dates.length - 1 },
        start: [0, register_dates.length - 1],
        step: 1,
        connect: true,
    });
    const updateDates = (vals: (string | number)[]) => {
        const min = parseInt(String(vals[0])),
            max = parseInt(String(vals[1]));
        const infoEl = qs<HTMLElement>('.advanced-filter .dates-infos');
        if (infoEl)
            infoEl.innerHTML = sprintf(
                dates_infos,
                getDateStr(register_dates[min]!),
                getDateStr(register_dates[max]!)
            );
    };
    slider.on('update', updateDates);
    slider.on('change', (vals) => {
        updateDates(vals);
        update_user_list();
    });
    const infoEl = qs<HTMLElement>('.advanced-filter .dates-infos');
    if (infoEl)
        infoEl.innerHTML = sprintf(
            dates_infos,
            getDateStr(register_dates[0]!),
            getDateStr(register_dates[register_dates.length - 1]!)
        );
}

/*---- Add User ----*/
function gen_password() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    const length = getRandomInt(8, 15);
    return Array.from({ length }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
}

function add_user_close() {
    const addUser = document.getElementById('AddUser')!;
    addUser.style.display = 'none';
    const errors = qs<HTMLElement>('#AddUser .AddUserErrors');
    if (errors) errors.style.visibility = 'hidden';
}

function add_user_open() {
    hide(document.getElementById('AddUserSuccessContainer'));
    show(document.getElementById('AddUserFieldContainer'));
    qsa<HTMLInputElement>('#AddUser :is(input, textarea, select)').forEach((el) => {
        el.value = '';
    });
    hide(document.getElementById('add_user_password'));
    fill_new_user();
    show(document.getElementById('AddUser'));
    qs<HTMLInputElement>('.AddUserLabelUsername input')?.focus();

    const statusSel = qs<HTMLSelectElement>('#AddUser .user-property-status .user-property-select');
    if (statusSel === null) return;
    const newSel = statusSel.cloneNode(true) as HTMLSelectElement;
    statusSel.replaceWith(newSel);
    newSel.addEventListener('change', function (this: HTMLSelectElement) {
        const status = this.value;
        qs<HTMLInputElement>('#add_user_pass')!.value = '';
        qs<HTMLInputElement>('#add_user_confpass')!.value = '';
        if ('generic' === status) show(document.getElementById('add_user_password'));
        else hide(document.getElementById('add_user_password'));
    });
}

/*---- Checkboxes ----*/
function checkbox_change(this: HTMLElement) {
    const i = this.querySelector<HTMLElement>('i');
    if (this.dataset['selected'] === '1') hide(i);
    else show(i);
}
function checkbox_click(this: HTMLElement) {
    const i = this.querySelector<HTMLElement>('i');
    if (this.dataset['selected'] === '1') {
        this.dataset['selected'] = '0';
        hide(i);
    } else {
        this.dataset['selected'] = '1';
        show(i);
    }
}

qsa('.user-list-checkbox').forEach((el) => {
    el.addEventListener('change', checkbox_change.bind(el));
    el.addEventListener('click', checkbox_click.bind(el));
});

/*---- Selection mode ----*/
function _checkbox_container_change(this: HTMLElement) {
    const i = this.querySelector<HTMLElement>('i');
    if (this.dataset['selected'] === '1') hide(i);
    else show(i);
}
function _checkbox_container_click(this: HTMLElement) {
    const container = this.closest<HTMLElement>('.user-container');
    const currUser: { id: number; username?: string } =
        container !== null
            ? (current_users[parseInt(container.getAttribute('key') ?? '0')] ?? { id: -1 })
            : { id: -1 };
    if (this.dataset['selected'] === '1') {
        this.dataset['selected'] = '0';
        this.querySelector<HTMLElement>('i')!.style.display = 'none';
        if (container !== null) {
            container.classList.remove('container-selected');
            selection = selection.filter((e) => e.id !== currUser.id);
        }
    } else {
        this.dataset['selected'] = '1';
        this.querySelector<HTMLElement>('i')!.style.display = '';
        if (container !== null) {
            container.classList.add('container-selected');
            selection.push({ id: currUser.id, username: currUser.username });
        }
    }
    if (container !== null) update_selection_content();
}

function create_user_selected_item(user: SelectionItem): HTMLElement {
    const node = document
        .getElementById('template')
        ?.querySelector<HTMLElement>('.user-selected-item')
        ?.cloneNode(true);
    if (node === undefined) return document.createElement('div');
    const tmpl = node as HTMLElement;
    tmpl.dataset['id'] = String(user.id);
    tmpl.querySelector('p')!.innerHTML = user.username ?? '';
    tmpl.querySelector('a')?.addEventListener('click', () => {
        selection.splice(
            selection.findIndex((i) => i.id === user.id),
            1
        );
        update_selection_content();
    });
    return tmpl;
}

function generate_user_selected_items() {
    qsa('.user-selected-list .user-selected-item').forEach((el) => el.remove());
    let created = 0;
    const others = selection.length - 5;
    for (let i = 0; i < selection.length && created < 5; i++) {
        if (typeof selection[i]!.username !== 'undefined') {
            qs('.user-selected-list')?.appendChild(create_user_selected_item(selection[i]!));
            created++;
        }
    }
    const othersEl = qs<HTMLElement>('.selection-other-users');
    if (others >= 1) {
        if (othersEl) {
            othersEl.innerHTML = str_and_others_tags.replace('%s', String(others));
            show(othersEl);
        }
    } else hide(othersEl);
}

function fill_user_selected_list() {
    let withUsername = 0;
    for (let i = 0; i < selection.length && withUsername < 5; i++) {
        if (typeof selection[i]!.username !== 'undefined') withUsername++;
    }
    if (withUsername < 5 && withUsername !== selection.length)
        get_first_selection_usernames(generate_user_selected_items);
    else generate_user_selected_items();
}

function update_selection_content() {
    _number = selection.length;
    fill_user_selected_list();
    if (selection.length === 0) {
        show(document.getElementById('forbidAction'));
        qsa('.selection-mode-ul').forEach(hide);
        hide(document.getElementById('permitActionUserList'));
    } else {
        hide(document.getElementById('forbidAction'));
        show(document.getElementById('permitActionUserList'));
        qsa('.selection-mode-ul').forEach(show);
    }
    set_selected_to_selection();
    qsa<HTMLElement>('#applyActionBlock .infos').forEach(hide);
}

function set_selected_to_selection() {
    if (!isSelectionMode()) return;
    qsa('.user-container-wrapper .user-container').forEach((container, index) => {
        const selIds = selection.map((x) => x.id);
        const cu = current_users.at(index);
        if (cu !== undefined && selIds.includes(cu.id)) {
            container.classList.add('container-selected');
            container.querySelector<HTMLElement>('.user-list-checkbox')!.dataset['selected'] = '1';
            show(container.querySelector<HTMLElement>('.user-list-checkbox i'));
        } else {
            container.classList.remove('container-selected');
            container.querySelector<HTMLElement>('.user-list-checkbox')!.dataset['selected'] = '0';
            hide(container.querySelector<HTMLElement>('.user-list-checkbox i'));
        }
    });
}

function selectionMode(isSelection: boolean) {
    qs<HTMLSelectElement>('#permitActionUserList select[name=selectAction]')!.value = '-1';
    qs('#permitActionUserList select[name=selectAction]')?.dispatchEvent(new Event('change'));
    if (isSelection) {
        set_selected_to_selection();
        qsa('.in-selection-mode').forEach((el) => {
            el.style.display = '';
        });
        qsa('.not-in-selection-mode').forEach((el) => {
            el.style.display = 'none';
        });
        if (view_selector === 'tile')
            qsa<HTMLElement>('.user-container-email').forEach((el) => {
                el.style.display = '';
            });
        if (view_selector === 'compact')
            qsa<HTMLElement>('.user-container-email').forEach((el) => {
                el.style.display = 'none';
            });
        qsa('.user-container').forEach((el) => el.classList.add('selectable'));
    } else {
        qsa('.container-selected').forEach((el) => el.classList.remove('container-selected'));
        qsa('.in-selection-mode').forEach((el) => {
            el.style.display = 'none';
        });
        qsa('.not-in-selection-mode').forEach((el) => {
            el.style.display = '';
        });
        if (view_selector === 'tile' || view_selector === 'line')
            qsa<HTMLElement>('.user-container-email').forEach((el) => {
                el.style.display = 'flex';
            });
        qsa('.user-container').forEach((el) => el.classList.remove('selectable'));
    }
}

/*---- General helpers ----*/
function hide_temporary_messages() {
    qsa('.update-user-success,.error-msg,#AddUserSuccess').forEach(hide);
}

function get_group_name_from_id(id: number): string {
    const g = groups_arr.find((g) => g[0] === id);
    return g !== undefined ? g[1] : 'group_id error';
}
function get_container_index_from_uid(uid: number): number {
    return current_users.findIndex((u) => u.id === uid);
}
function hide_error_edit_user() {
    const el = qs<HTMLElement>('#UserList .EditUserErrors');
    if (el) {
        el.style.transition = 'opacity 0.1s';
        el.style.opacity = '0';
    }
}
function show_error_edit_user() {
    const el = qs<HTMLElement>('#UserList .EditUserErrors');
    if (el) {
        el.style.transition = 'opacity 0.3s';
        el.style.opacity = '1';
    }
}
function reset_input_password() {
    qsa<HTMLInputElement>('#edit_user_password, #edit_user_conf_password').forEach((el) => {
        el.value = '';
    });
}

function editTabsBind() {
    qsa('.edit-user-tabsheet').forEach((el) => {
        const newEl = el.cloneNode(true) as HTMLElement;
        el.replaceWith(newEl);
        newEl.addEventListener('click', () => {
            const parts = newEl.id.split('_');
            const tabId = parts[1] + '_' + parts[2];
            document.getElementById(tabId)?.scrollIntoView({ behavior: 'smooth' });
        });
    });
}

function check_tabs(title_tab_name_id: string) {
    if (plugins_load.length <= 2) {
        const el = document.getElementById(title_tab_name_id);
        if (el) tippy(el, { delay: [0, 0], duration: [200, 200] });
    }
}

function reset_password_modals() {
    qsa(
        '.user-property-password-change,.user-property-password-change-inputs,#edit_password_success_change,#edit_password_result_mail,#edit_password_result_mail_copy'
    ).forEach(hide);
    show(qs('.user-property-password-choice'));
}
function reset_username_modals() {
    hide(qs('.edit-username-success'));
    show(qs('.user-property-username-change-input'));
}
function reset_main_user_modals() {
    qs<HTMLInputElement>('#main_user_rewrite')!.value = '';
    qs('#main_user_rewrite_icon')?.classList.remove(
        'icon-ok',
        'icon-green',
        'icon-cancel',
        'icon-red'
    );
    qsa('.main-user-rewrite,.main-user-validate,.main-user-success').forEach(hide);
    show(qs('.main-user-proceed'));
}
function hide_modals() {
    qsa(
        '.user-property-username-change,.user-property-password-change,.user-property-main-user-change'
    ).forEach(hide);
    reset_password_modals();
    reset_username_modals();
    reset_main_user_modals();
}

function display_long_string(username: string) {
    return username.length > 20 ? username.slice(0, 17) + '...' : username;
}
function generate_random_string() {
    const s = 'abcdefghijkmlnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    return Array.from({ length: 5 }, () => s[Math.floor(Math.random() * s.length)]).join('');
}
function get_initials(username: string) {
    const words = username.toUpperCase().split(' ');
    return (
        (words[0]?.[0] ?? '') +
        (words.length > 1 && (words[1]?.[0] ?? '') !== '' ? words[1]![0]! : '')
    );
}
function get_status_index(status: string) {
    const i = status_arr.indexOf(status);
    return i >= 0 ? i : 0;
}
function get_level_index(level: string) {
    const i = level_arr.indexOf(level);
    return i >= 0 ? i : 0;
}
function is_owner(user_id: number): boolean {
    return user_id === owner_id;
}
function copyToClipboard(text: string) {
    void navigator.clipboard.writeText(text);
}

function set_selected_groups(groups: Array<number | string>) {
    groupOptions.forEach((g) => (g.isSelected = groups.includes(g.value) ? 1 : 0));
}

/*---- Pop-in fill functions ----*/
function fill_user_edit_summary(user_to_edit: PwgUser, popIn: HTMLElement, isGuest: boolean) {
    const q = <T extends HTMLElement = HTMLElement>(sel: string) => popIn.querySelector<T>(sel);
    if (!isGuest) {
        const initFill = get_initials(user_to_edit.username);
        const span = q('.user-property-initials span');
        if (span) {
            span.innerHTML = initFill;
            span.classList.remove(...color_icons);
            span.classList.add(color_icons[user_to_edit.id % 5]!);
            span.classList.toggle('small', initFill.length > 1);
        }
    } else {
        const span = q('.user-property-initials span');
        if (span) {
            span.classList.remove(...color_icons);
            span.classList.add(color_icons[user_to_edit.id % 5]!);
        }
    }
    const usernameSpan = q('.user-property-username span:first-child');
    if (usernameSpan) {
        usernameSpan.innerHTML = user_to_edit.username;
        tippy(usernameSpan, { content: user_to_edit.username });
    }
    const editSpec = q('.user-property-username .edit-username-specifier');
    if (user_to_edit.id === connected_user || user_to_edit.id === 1) show(editSpec);
    else hide(editSpec);
    const unameInput = q<HTMLInputElement>('.user-property-username-change input');
    if (unameInput) unameInput.value = user_to_edit.username;
    const pwdInput = q<HTMLInputElement>('.user-property-password-change input');
    if (pwdInput) pwdInput.value = '';
    const permsLink = q<HTMLAnchorElement>('.user-property-permissions a');
    if (permsLink) permsLink.href = `${config.adminUrl}page=user_perm&user_id=${user_to_edit.id}`;
    const regEl = q('.user-property-register');
    if (regEl) {
        regEl.innerHTML = user_to_edit.registration_date_string ?? '';
        tippy(regEl, {
            content: `${registered_str}<br />${user_to_edit.registration_date_since ?? ''}`,
            allowHTML: true,
        });
    }
    const lastEl = q('.user-property-last-visit');
    if (lastEl) {
        lastEl.innerHTML = user_to_edit.last_visit_string ?? '';
        tippy(lastEl, {
            content: `${last_visit_str}<br />${user_to_edit.last_visit_since ?? ''}`,
            allowHTML: true,
        });
    }
    const histLink = q<HTMLAnchorElement>('.user-property-history a');
    if (histLink) histLink.href = history_base_url + user_to_edit.id;

    if (!window.isSecureContext) {
        hide(qs('#copy_password'));
        const copyBtn = q('#result_send_mail_copy_btn');
        if (copyBtn) {
            copyBtn.title = cantCopy;
            tippy(copyBtn, { content: cantCopy });
        }
    }
}

function loadGroupOptions(ts: TomSelect | null) {
    if (ts === null) return;
    ts.clear();
    ts.clearOptions();
    groupOptions.forEach((g) => ts.addOption({ value: String(g.value), text: g.label }));
    groupOptions.filter((g) => g.isSelected !== 0).forEach((g) => ts.addItem(String(g.value)));
}

function fill_user_edit_properties(user_to_edit: PwgUser, popIn: HTMLElement) {
    const q = <T extends HTMLElement = HTMLElement>(sel: string) => popIn.querySelector<T>(sel);
    const currentGroupTs = user_to_edit.id === guest_id ? groupGuestTs : groupTs;
    const emailInput = q<HTMLInputElement>('.user-property-email input');
    if (emailInput) emailInput.value = user_to_edit.email ?? '';
    const statusSelect = q<HTMLSelectElement>('.user-property-status select');
    if (statusSelect) {
        const o = Array.from(statusSelect.options).at(get_status_index(user_to_edit.status));
        if (o !== undefined) o.selected = true;
    }
    const levelSelect = q<HTMLSelectElement>('.user-property-level select');
    if (levelSelect) {
        const o = Array.from(levelSelect.options).at(get_level_index(String(user_to_edit.level)));
        if (o !== undefined) o.selected = true;
    }
    set_selected_groups(user_to_edit.groups ?? []);
    loadGroupOptions(currentGroupTs);
    const hdCb = q('.user-list-checkbox[name="hd_enabled"]');
    if (hdCb) hdCb.dataset['selected'] = user_to_edit.enabled_high === 'true' ? '1' : '0';
}

function fill_user_edit_preferences(user_to_edit: PwgUser, popIn: HTMLElement) {
    const q = <T extends HTMLElement = HTMLElement>(sel: string) => popIn.querySelector<T>(sel);
    const photosKey = getSliderKeyFromValue(
        parseInt(String(user_to_edit.nb_image_page ?? 0)),
        nb_image_page_values
    );
    const periodKey = getSliderKeyFromValue(
        parseInt(String(user_to_edit.recent_period ?? 0)),
        recent_period_values
    );
    setSliderValue(q('.photos-select-bar .slider-bar-container'), photosKey);
    const themeSelect = q<HTMLSelectElement>('.user-property-theme select');
    if (themeSelect)
        Array.from(themeSelect.options).forEach((o) => {
            o.selected = o.value === user_to_edit.theme;
        });
    const langSelect = q<HTMLSelectElement>('.user-property-lang select');
    if (langSelect)
        Array.from(langSelect.options).forEach((o) => {
            o.selected = o.value === user_to_edit.language;
        });
    setSliderValue(q('.period-select-bar .slider-bar-container'), periodKey);
    const expandCb = q('.user-list-checkbox[name="expand_all_albums"]');
    if (expandCb) expandCb.dataset['selected'] = user_to_edit.expand === 'true' ? '1' : '0';
    const commentsCb = q('.user-list-checkbox[name="show_nb_comments"]');
    if (commentsCb)
        commentsCb.dataset['selected'] = user_to_edit.show_nb_comments === 'true' ? '1' : '0';
    const hitsCb = q('.user-list-checkbox[name="show_nb_hits"]');
    if (hitsCb) hitsCb.dataset['selected'] = user_to_edit.show_nb_hits === 'true' ? '1' : '0';
}

function fill_user_edit_update(user_to_edit: PwgUser, popIn: HTMLElement) {
    const q = <T extends HTMLElement = HTMLElement>(sel: string) => popIn.querySelector<T>(sel);
    const updateBtn = q('.update-user-button');
    if (updateBtn) {
        const newBtn = updateBtn.cloneNode(true) as HTMLElement;
        updateBtn.replaceWith(newBtn);
        newBtn.addEventListener(
            'click',
            user_to_edit.id === guest_id ? update_guest_info : update_user_info
        );
    }
    const validateUsernameBtn = q('.edit-username-validate');
    if (validateUsernameBtn) {
        const nb = validateUsernameBtn.cloneNode(true) as HTMLElement;
        validateUsernameBtn.replaceWith(nb);
        nb.addEventListener('click', update_user_username);
    }
    const editPwdBtn = q('#edit_modal_password');
    if (editPwdBtn) {
        const nb = editPwdBtn.cloneNode(true) as HTMLElement;
        editPwdBtn.replaceWith(nb);
        nb.addEventListener('click', () => {
            hide(qs('.user-property-password-choice'));
            show(qs('.user-property-password-change-inputs'));
        });
    }
    const sendPwdLink = q('#send_password_link');
    if (sendPwdLink) {
        const nb = sendPwdLink.cloneNode(true) as HTMLElement;
        sendPwdLink.replaceWith(nb);
        if (user_to_edit.email !== undefined && user_to_edit.email !== '') {
            nb.classList.remove('unavailable', 'tiptip');
            nb.title = '';
            nb.addEventListener('click', () =>
                send_link_password(
                    user_to_edit.email ?? '',
                    user_to_edit.username,
                    user_to_edit.id,
                    true
                )
            );
        } else {
            nb.classList.add('unavailable', 'tiptip');
            nb.title = cannotSendMail;
            tippy(nb, { delay: [0, 0], duration: [200, 200] });
        }
    }
    const copyPwdLink = q('#copy_password_link');
    if (copyPwdLink) {
        const nb = copyPwdLink.cloneNode(true) as HTMLElement;
        copyPwdLink.replaceWith(nb);
        nb.addEventListener('click', () => {
            const inputValue = qs<HTMLInputElement>('#result_send_mail_copy_input')?.value ?? '';
            if (inputValue === '')
                send_link_password(
                    user_to_edit.email ?? '',
                    user_to_edit.username,
                    user_to_edit.id,
                    false
                );
            else if (window.isSecureContext) copyToClipboard(inputValue);
            hide(qs('.user-property-password-choice'));
            show(qs('#edit_password_result_mail_copy'));
            qs<HTMLInputElement>('#result_send_mail_copy_input')?.focus();
        });
    }
    const validatePwdBtn = q('.edit-password-validate');
    if (validatePwdBtn) {
        const nb = validatePwdBtn.cloneNode(true) as HTMLElement;
        validatePwdBtn.replaceWith(nb);
        nb.addEventListener('click', () => {
            const errDiv = qs<HTMLElement>('#UserList .EditUserErrors');
            const pwd = qs<HTMLInputElement>('#edit_user_password')?.value ?? '';
            const conf = qs<HTMLInputElement>('#edit_user_conf_password')?.value ?? '';
            if (pwd === '' || conf === '') {
                if (errDiv) errDiv.innerHTML = missingField;
                show_error_edit_user();
            } else if (pwd !== conf) {
                if (errDiv) errDiv.innerHTML = noMatchPassword;
                show_error_edit_user();
            } else update_user_password();
        });
    }
    const deleteBtn = q('.delete-user-button');
    if (deleteBtn) {
        const nb = deleteBtn.cloneNode(true) as HTMLElement;
        deleteBtn.replaceWith(nb);
        nb.addEventListener('click', () => {
            if (window.confirm(title_msg.replace('%s', user_to_edit.username)))
                delete_user(user_to_edit.id);
        });
    }
}

function fill_user_edit_permissions(user_to_edit: PwgUser, popIn: HTMLElement) {
    const q = <T extends HTMLElement = HTMLElement>(sel: string) => popIn.querySelector<T>(sel);
    const setPerms = (
        canDelete: boolean,
        canEditPwd: boolean,
        canEditEmail: boolean,
        canEditStatus: boolean,
        canEditUsername: boolean
    ) => {
        if (canDelete) show(q('.delete-user-button'));
        else hide(q('.delete-user-button'));
        if (canEditPwd) show(q('.user-property-password.edit-password'));
        else hide(q('.user-property-password.edit-password'));
        const emailInput = q<HTMLInputElement>('.user-property-email .user-property-input');
        if (emailInput) {
            if (canEditEmail) emailInput.removeAttribute('disabled');
            else emailInput.setAttribute('disabled', 'disabled');
        }
        const statusSel = q('.user-property-status .user-property-select');
        if (statusSel) {
            if (canEditStatus) statusSel.classList.remove('notClickable');
            else statusSel.classList.add('notClickable');
        }
        const editUsername = q('.user-property-username .edit-username');
        if (editUsername) {
            if (canEditUsername) show(editUsername);
            else hide(editUsername);
        }
    };

    if (user_to_edit.id !== connected_user) {
        if (!is_owner(connected_user)) {
            if (is_owner(user_to_edit.id)) {
                setPerms(false, false, false, false, false);
            } else {
                setPerms(true, true, true, true, true);
                if (
                    user_to_edit.status === connected_user_status &&
                    connected_user_status === 'webmaster' &&
                    !is_owner(user_to_edit.id)
                )
                    setPerms(true, true, true, true, true);
                else if (
                    user_to_edit.status === connected_user_status &&
                    connected_user_status === 'admin'
                )
                    setPerms(false, true, true, false, true);
                else if (user_to_edit.status === 'webmaster' && connected_user_status === 'admin')
                    setPerms(false, false, false, false, false);
                else if (user_to_edit.status === 'admin' && connected_user_status === 'webmaster')
                    setPerms(true, true, true, true, true);
            }
        } else {
            setPerms(true, true, true, true, true);
        }
    } else {
        setPerms(false, true, true, false, true);
    }

    qsa('.notClickableBefore').forEach((el) => el.classList.remove('notClickableBefore'));
    qsa('.notClickable').forEach((el) => el.parentElement?.classList.add('notClickableBefore'));
}

function fill_who_is_the_king(user_to_edit: PwgUser, popIn: HTMLElement) {
    const king = popIn.querySelector<HTMLElement>('#who_is_the_king');
    if (!king) return;
    const setKingClass = (cls: string, title: string, clickable: boolean) => {
        king.classList.remove(
            'princes-of-this-piwigo',
            'king-of-this-piwigo',
            'royal-court-of-this-piwigo',
            'can-change',
            'cannot-change'
        );
        king.classList.add(cls, clickable ? 'can-change' : 'cannot-change');
        king.title = title;
        tippy(king, { content: title });
        const nb = king.cloneNode(true) as HTMLElement;
        king.replaceWith(nb);
    };

    if (is_owner(user_to_edit.id)) {
        setKingClass('king-of-this-piwigo', mainUserStr, false);
    } else if (connected_user_status === 'webmaster') {
        if (user_to_edit.status === 'webmaster') {
            setKingClass('princes-of-this-piwigo', mainUserSet, true);
            const nb = popIn.querySelector<HTMLElement>('#who_is_the_king')!;
            if (!is_owner(user_to_edit.id))
                nb.addEventListener('click', () => open_main_user_modal(user_to_edit));
        } else {
            setKingClass('royal-court-of-this-piwigo', mainUserUpgradeWebmaster, false);
        }
    } else {
        setKingClass('royal-court-of-this-piwigo', mainAskWebmaster, false);
    }
}

function fill_user_edit(user_to_edit: PwgUser) {
    const popIn = document.getElementById('UserList')!;
    fill_user_edit_summary(user_to_edit, popIn, false);
    fill_user_edit_properties(user_to_edit, popIn);
    fill_user_edit_preferences(user_to_edit, popIn);
    fill_user_edit_update(user_to_edit, popIn);
    fill_user_edit_permissions(user_to_edit, popIn);
    fill_who_is_the_king(user_to_edit, popIn);
    if (Object.keys(plugins_get_functions).length > 0) {
        Object.values(plugins_get_functions).forEach((f) => f());
    }
    const keyUser = Object.keys(user_to_edit);
    plugins_users_infos_table.forEach((i) => {
        const el = qs<HTMLInputElement>('#' + i.content_id);
        if (el === null) return;
        if (!keyUser.includes(i.users_table)) {
            el.value = '';
            return;
        }
        const raw = user_to_edit[i.users_table];
        el.value =
            raw === undefined || raw === null
                ? ''
                : typeof raw === 'string'
                  ? raw
                  : typeof raw === 'number' || typeof raw === 'boolean'
                    ? String(raw)
                    : JSON.stringify(raw);
    });
}

function fill_guest_edit() {
    const user_to_edit = guest_user as PwgUser;
    const popIn = qs<HTMLElement>('.GuestUserListPopInContainer')!;
    fill_user_edit_summary(user_to_edit, popIn, true);
    fill_user_edit_properties(user_to_edit, popIn);
    fill_user_edit_preferences(user_to_edit, popIn);
    fill_user_edit_update(user_to_edit, popIn);
}

function fill_new_user() {
    const popIn = document.getElementById('AddUser')!;
    const statusIdx = get_status_index('normal');
    const guest = guest_user as Partial<PwgUser>;
    const levelIdx = get_level_index(String(guest.level ?? '0'));
    set_selected_groups(guest.groups ?? []);
    loadGroupOptions(groupAddUserTs);
    const statusSelect = qs<HTMLSelectElement>(
        `.AddUserInputContainer .user-property-status select`,
        popIn
    );
    if (statusSelect) {
        const o = Array.from(statusSelect.options).at(statusIdx);
        if (o !== undefined) o.selected = true;
    }
    const levelSelect = qs<HTMLSelectElement>(
        `.AddUserInputContainer .user-property-level select`,
        popIn
    );
    if (levelSelect) {
        const o = Array.from(levelSelect.options).at(levelIdx);
        if (o !== undefined) o.selected = true;
    }
    const hdCb = qs('.AddUserInputContainer .user-list-checkbox[name="hd_enabled"]', popIn);
    if (hdCb) hdCb.dataset['selected'] = guest.enabled_high === 'true' ? '1' : '0';
}

/*---- Ajax data helpers ----*/
type AjaxData = Record<string, unknown>;

function fill_ajax_data_from_properties(ajax_data: AjaxData, popIn: HTMLElement): AjaxData {
    const ts = getPopInTs(popIn);
    const groups_selected = ts !== null ? ts.items.map((id) => parseInt(id)) : [];
    const emailInput = qs<HTMLInputElement>('.user-property-email input', popIn);
    ajax_data['email'] = emailInput?.value;
    const statusSelect = qs<HTMLSelectElement>('.user-property-status select', popIn);
    const statusVal = statusSelect?.value;
    if (connected_user_status === 'admin' && statusVal !== 'webmaster' && statusVal !== 'admin')
        ajax_data['status'] = statusVal;
    else if (connected_user_status === 'webmaster') ajax_data['status'] = statusVal;
    ajax_data['level'] = qs<HTMLSelectElement>('.user-property-level select', popIn)?.value;
    ajax_data['group_id'] = groups_selected.length === 0 ? -1 : groups_selected;
    ajax_data['enabled_high'] =
        qs<HTMLElement>('.user-list-checkbox[name="hd_enabled"]', popIn)?.dataset['selected'] ===
        '1';
    return ajax_data;
}

function fill_ajax_data_from_preferences(ajax_data: AjaxData, popIn: HTMLElement): AjaxData {
    ajax_data['theme'] = qs<HTMLSelectElement>('.user-property-theme select', popIn)?.value;
    ajax_data['language'] = qs<HTMLSelectElement>('.user-property-lang select', popIn)?.value;
    const photosIdx = getSliderValue(qs('.photos-select-bar .slider-bar-container', popIn));
    ajax_data['nb_image_page'] = nb_image_page_values[photosIdx];
    const periodIdx = getSliderValue(qs('.period-select-bar .slider-bar-container', popIn));
    ajax_data['recent_period'] = recent_period_values[periodIdx];
    ajax_data['expand'] =
        qs<HTMLElement>('.user-list-checkbox[name="expand_all_albums"]', popIn)?.dataset[
            'selected'
        ] === '1';
    ajax_data['show_nb_comments'] =
        qs<HTMLElement>('.user-list-checkbox[name="show_nb_comments"]', popIn)?.dataset[
            'selected'
        ] === '1';
    ajax_data['show_nb_hits'] =
        qs<HTMLElement>('.user-list-checkbox[name="show_nb_hits"]', popIn)?.dataset['selected'] ===
        '1';
    return ajax_data;
}

function fill_ajax_data_from_container(ajax_data: AjaxData, popIn: HTMLElement): AjaxData {
    let data = fill_ajax_data_from_properties(ajax_data, popIn);
    data = fill_ajax_data_from_preferences(data, popIn);
    return data;
}

/*---- Ajax requests ----*/
function get_first_selection_usernames(callback: () => void) {
    const ids = selection.slice(0, 50).map((x) => x.id);
    const body = new URLSearchParams({ display: 'username', order: 'id' });
    ids.forEach((id) => body.append('user_id[]', String(id)));
    body.append('exclude[]', String(guest_id));
    void fetch(config.wsUrl + 'format=json&method=pwg.users.getList', { method: 'POST', body })
        .then((r) => r.json() as Promise<{ result: { users: PwgUser[] } }>)
        .then((d) => {
            d.result.users.forEach((u) => {
                const idx = selection.findIndex((x) => x.id === u.id);
                if (idx !== -1) selection[idx]!.username = u.username;
            });
            callback();
        });
}

function select_whole_set() {
    const datesSliderEl = qs<HTMLElement>('.dates-select-bar .slider-bar-container');
    const [minIdx, maxIdx] = getSliderValues(datesSliderEl);
    const body = new URLSearchParams({
        display: 'only_id',
        order: 'id',
        page: String(actual_page - 1),
        per_page: '0',
        status: qs<HTMLSelectElement>('.advanced-filter-select[name=filter_status]')?.value ?? '',
        group_id: qs<HTMLSelectElement>('.advanced-filter-select[name=filter_group]')?.value ?? '',
        min_level: qs<HTMLSelectElement>('.advanced-filter-select[name=filter_level]')?.value ?? '',
        max_level: qs<HTMLSelectElement>('.advanced-filter-select[name=filter_level]')?.value ?? '',
        min_register: register_dates[minIdx] ?? '',
        max_register: register_dates[maxIdx] ?? '',
    });
    body.append('exclude[]', String(guest_id));
    show(qs('#checkActions .loading'));
    fetch(config.wsUrl + 'format=json&method=pwg.users.getList', { method: 'POST', body })
        .then((r) => r.json() as Promise<{ result: number[] }>)
        .then((d) => {
            selection = d.result.map((x) => ({ id: x }));
            hide(qs('#checkActions .loading'));
            update_selection_content();
        })
        .catch(() => hide(qs('#checkActions .loading')));
}

function update_user_username() {
    const popIn = document.getElementById('UserList')!;
    const username = qs<HTMLInputElement>('.user-property-input-username', popIn)?.value ?? '';
    if (username.replace(/\s/g, '') === '') {
        const failEl = qs<HTMLElement>('.update-user-fail');
        if (failEl) {
            failEl.innerHTML = fieldNotEmpty;
            fadeInThenOut(failEl, 0, 2500);
        }
        return;
    }
    void pwgPost<{ users: PwgUser[] }>('pwg.users.setInfo', {
        pwg_token,
        user_id: last_user_id,
        username,
    }).then((d) => {
        if (d.stat === 'ok' && last_user_index !== -1 && d.result !== undefined) {
            current_users[last_user_index]!.username = d.result.users[0]!.username;
            qs<HTMLElement>('#UserList .user-property-username .edit-username-title')!.innerHTML =
                current_users[last_user_index]!.username;
            qs<HTMLElement>('#UserList .user-property-initials span')!.innerHTML = get_initials(
                current_users[last_user_index]!.username
            );
            fill_container_user_info(
                qsa('#user-table-content .user-container')[last_user_index]!,
                last_user_index
            );
            hide(qs('.user-property-username-change-input'));
            show(qs('.edit-username-success'));
        }
    });
}

function update_user_password() {
    const popIn = document.getElementById('UserList')!;
    const password = qs<HTMLInputElement>('.user-property-input-password', popIn)?.value ?? '';
    void pwgPost('pwg.users.setInfo', { pwg_token, user_id: last_user_id, password }).then((d) => {
        if (d.stat === 'ok') {
            hide(qs('.user-property-password-change-inputs'));
            show(document.getElementById('edit_password_success_change'));
            if (window.isSecureContext) {
                qs('#copy_password')?.addEventListener('click', () => {
                    copyToClipboard(password);
                    qs<HTMLElement>('#password_msg_success')!.innerHTML = passwordCopied;
                });
            }
        }
    });
}

function update_user_info() {
    qsa('.update-user-button i').forEach((el) => {
        el.classList.remove('icon-floppy');
        el.classList.add('icon-spin6', 'animate-spin');
    });
    qsa('.update-user-button').forEach((el) => el.classList.add('unclickable'));
    const popIn = qs<HTMLElement>('.UserListPopInContainer')!;
    let ajax_data: AjaxData = { pwg_token, user_id: last_user_id };
    plugins_users_infos_table.forEach((i) => {
        const el = qs<HTMLInputElement>('#' + i.content_id);
        if (el && Object.keys(current_users[last_user_index] ?? {}).includes(i.users_table))
            ajax_data[i.users_table] = el.value;
    });
    ajax_data = fill_ajax_data_from_container(ajax_data, popIn);
    qsa<HTMLElement>('#UserList .update-user-fail, #UserList .update-user-success').forEach(
        (el) => {
            el.style.display = 'none';
        }
    );
    void pwgPost<{ users: PwgUser[] }>('pwg.users.setInfo', ajax_data).then((d) => {
        if (d.stat === 'ok' && d.result !== undefined) {
            const result = d.result.users[0]!;
            if (last_user_index !== -1) {
                current_users[last_user_index] = { ...current_users[last_user_index]!, ...result };
                fill_container_user_info(
                    qsa('#user-table-content .user-container')[last_user_index]!,
                    last_user_index
                );
            }
            fadeInThenOut(qs('#UserList .update-user-success'), 0, 2500);
            qsa('.update-user-button i').forEach((el) => {
                el.classList.remove('icon-spin6', 'animate-spin');
                el.classList.add('icon-floppy');
            });
            qsa('.update-user-button').forEach((el) => el.classList.remove('unclickable'));
            if (Object.keys(plugins_set_functions).length > 0)
                Object.values(plugins_set_functions).forEach((f) => f(popIn, result));
            fill_who_is_the_king(result, document.getElementById('UserList')!);
        } else if (d.stat === 'fail') {
            const failEl = qs<HTMLElement>('#UserList .update-user-fail');
            if (failEl) {
                failEl.innerHTML = d.message ?? '';
                failEl.style.display = '';
            }
            qsa('.update-user-button i').forEach((el) => {
                el.classList.add('icon-floppy');
                el.classList.remove('icon-spin6', 'animate-spin');
            });
            qsa('.update-user-button').forEach((el) => el.classList.remove('unclickable'));
            setTimeout(() => hide(qs<HTMLElement>('#UserList .update-user-fail')), 5000);
        }
    });
}

function get_guest_info() {
    void pwgPost<{ users: PwgUser[] }>('pwg.users.getList', {
        display: 'all',
        user_id: guest_id,
    }).then((d) => {
        if (d.stat === 'ok' && d.result !== undefined) {
            guest_user = d.result.users[0]!;
            fill_guest_edit();
        }
    });
}

function get_user_info(uid: number, callback: (() => void) | null = null) {
    void pwgPost<{ users: PwgUser[] }>('pwg.users.getList', { display: 'all', user_id: uid }).then(
        (d) => {
            if (d.stat === 'ok' && d.result !== undefined) {
                fill_user_edit(d.result.users[0]!);
                if (callback !== null) callback();
            }
        }
    );
}

function update_guest_info() {
    qsa('.update-user-button i').forEach((el) => {
        el.classList.remove('icon-floppy');
        el.classList.add('icon-spin6', 'animate-spin');
    });
    qsa('.update-user-button').forEach((el) => el.classList.add('unclickable'));
    const popIn = qs<HTMLElement>('.GuestUserListPopInContainer')!;
    let ajax_data: AjaxData = { pwg_token, user_id: guest_id };
    ajax_data = fill_ajax_data_from_container(ajax_data, popIn);
    delete ajax_data['email'];
    delete ajax_data['status'];
    void pwgPost('pwg.users.setInfo', ajax_data).then((d) => {
        if (d.stat === 'ok') fadeInThenOut(qs('#GuestUserList .update-user-success'), 0, 2500);
        qsa('.update-user-button i').forEach((el) => {
            el.classList.remove('icon-spin6', 'animate-spin');
            el.classList.add('icon-floppy');
        });
        qsa('.update-user-button').forEach((el) => el.classList.remove('unclickable'));
    });
}

function update_user_list() {
    const update_data: AjaxData = {
        display: 'all',
        order: filter_by,
        page: actual_page - 1,
        per_page,
        exclude: [guest_id],
    };
    const searchVal = qs<HTMLInputElement>('#user_search')?.value ?? '';
    if (searchVal !== '') {
        const m = /^id:(\d+)$/.exec(searchVal);
        update_data[m !== null ? 'user_id' : 'filter'] = m !== null ? m[1] : searchVal;
    }
    if (qs('.advanced-filter')?.classList.contains('advanced-filter-open') === true) {
        update_data['status'] = qs<HTMLSelectElement>(
            '.advanced-filter-select[name=filter_status]'
        )?.value;
        update_data['group_id'] = qs<HTMLSelectElement>(
            '.advanced-filter-select[name=filter_group]'
        )?.value;
        update_data['min_level'] = qs<HTMLSelectElement>(
            '.advanced-filter-select[name=filter_level]'
        )?.value;
        update_data['max_level'] = update_data['min_level'];
        const [minIdx, maxIdx] = getSliderValues(qs('.dates-select-bar .slider-bar-container'));
        update_data['min_register'] = register_dates[minIdx] ?? '';
        update_data['max_register'] = register_dates[maxIdx] ?? '';
    }
    show(qs('.user-update-spinner'));
    void pwgPost<{ users: PwgUser[]; paging: { total_count: number } }>(
        'pwg.users.getList',
        update_data
    )
        .then((d) => {
            if (d.stat === 'fail' || d.result === undefined) return;
            total_users = d.result.paging.total_count;
            if (first_update) {
                document
                    .querySelector('h1')
                    ?.insertAdjacentHTML(
                        'beforeend',
                        `<span class='badge-number'>${total_users}</span>`
                    );
                first_update = false;
            }
            _nb_filtered_users = d.result.paging.total_count;
            update_pagination_menu();
            current_users = d.result.users;
            generate_user_list();
            qsa('.user-col.user-first-col.user-container-edit').forEach((el) => {
                el.addEventListener('click', () => {
                    const key = parseInt(
                        el.closest<HTMLElement>('.user-container')?.getAttribute('key') ?? '0'
                    );
                    last_user_id = current_users[key]!.id;
                    last_user_index = key;
                    fill_user_edit(current_users[key]!);
                    show(document.getElementById('UserList'));
                    document
                        .getElementById('tab_properties')
                        ?.scrollIntoView({ behavior: 'instant' as ScrollBehavior });
                });
            });
            set_selected_to_selection();
            hide(qs('.user-update-spinner'));

            let nb_filters = 0;
            const filterStatus = qs<HTMLSelectElement>(
                '.advanced-filter-select[name=filter_status]'
            )?.value;
            const filterGroup = qs<HTMLSelectElement>(
                '.advanced-filter-select[name=filter_group]'
            )?.value;
            const filterLevel = qs<HTMLSelectElement>(
                '.advanced-filter-select[name=filter_level]'
            )?.value;
            if (filterStatus !== undefined && filterStatus !== '') nb_filters++;
            if (filterGroup !== undefined && filterGroup !== '') nb_filters++;
            if (filterLevel !== undefined && filterLevel !== '') nb_filters++;
            const [minIdx, maxIdx] = getSliderValues(qs('.dates-select-bar .slider-bar-container'));
            if (minIdx !== 0) nb_filters++;
            if (register_dates.length > 0 && maxIdx !== register_dates.length - 1) nb_filters++;
            show_filter_infos(nb_filters);
        })
        .catch(() => hide(qs('.user-update-spinner')));
}

function add_user() {
    const addUserTs = groupAddUserTs;
    const groups_selected = addUserTs !== null ? addUserTs.items.map((id) => parseInt(id)) : [];
    const username =
        qs<HTMLInputElement>('.AddUserLabelUsername .user-property-input')?.value ?? '';
    const email = qs<HTMLInputElement>('.AddUserLabelEmail .user-property-input')?.value ?? '';
    const status =
        qs<HTMLSelectElement>('.AddUserInputContainer .user-property-status select')?.value ?? '';
    const level =
        qs<HTMLSelectElement>('.AddUserInputContainer .user-property-level select')?.value ?? '';
    const enabled_high =
        qs<HTMLElement>('.AddUserInputContainer .user-list-checkbox[name="hd_enabled"]')?.dataset[
            'selected'
        ] === '1';

    const errEl = qs<HTMLElement>('#AddUser .AddUserErrors');
    const showErr = (msg: string) => {
        if (errEl) {
            errEl.innerHTML = msg;
            errEl.style.visibility = 'visible';
        }
    };
    if (errEl) errEl.style.visibility = 'hidden';
    if (username === '') {
        showErr(missingUsername);
        return;
    }
    if (status === 'generic') {
        const pass = qs<HTMLInputElement>('#add_user_pass')?.value ?? '';
        const conf = qs<HTMLInputElement>('#add_user_confpass')?.value ?? '';
        if (pass === '') {
            showErr(missingPassword);
            return;
        }
        if (conf === '') {
            showErr(missingConfPassword);
            return;
        }
        if (pass !== conf) {
            showErr(noMatchPassword);
            return;
        }
    }

    const params: AjaxData = { username, email, pwg_token };
    if (status === 'generic') {
        params['password'] = qs<HTMLInputElement>('#add_user_pass')?.value;
        params['password_confirm'] = qs<HTMLInputElement>('#add_user_confpass')?.value;
    } else {
        params['auto_password'] = true;
    }

    void pwgPost<{ users: PwgUser[] }>('pwg.users.add', params).then((d) => {
        if (d.stat === 'ok' && d.result !== undefined) {
            const new_user_id = d.result.users[0]!.id;
            const default_group = d.result.users[0]!.groups ?? [];
            add_infos_to_new_user(new_user_id, {
                username,
                email,
                status,
                level,
                group_id: groups_selected.concat(default_group.map(Number)),
                enabled_high,
            });
        } else {
            showErr(d.message ?? '');
        }
    });
}

interface AddUserInfo {
    username: string;
    email: string;
    status: string;
    level: string;
    group_id: number[];
    enabled_high: boolean;
}

function add_infos_to_new_user(user_id: number, ajax_data: AddUserInfo) {
    void pwgPost<{ users: PwgUser[] }>('pwg.users.setInfo', {
        user_id,
        status: ajax_data.status,
        level: ajax_data.level,
        group_id: ajax_data.group_id,
        enabled_high: ajax_data.enabled_high,
        pwg_token,
    }).then((d) => {
        if (d.stat === 'ok' && d.result !== undefined) {
            const new_user_id = d.result.users[0]!.id;
            update_user_list();
            qs('#AddUserUpdated')?.classList.replace('icon-red', 'icon-green');
            qs('#AddUserUpdated')?.classList.replace('icon-cancel', 'icon-ok');
            qs('#AddUserUpdated')?.classList.add('border-green');
            const textEl = qs<HTMLElement>('#AddUserUpdatedText');
            if (textEl) textEl.innerHTML = user_added_str.replace('%s', ajax_data.username);
            if (['webmaster', 'admin', 'normal'].includes(ajax_data.status))
                send_new_user_password(new_user_id, ajax_data.email);
            else add_user_close();
            qsa<HTMLInputElement>('#AddUser .user-property-input').forEach((el) => {
                el.value = '';
            });
            const editNow = qs('#AddUserSuccess .edit-now');
            if (editNow) {
                const nb = editNow.cloneNode(true) as HTMLElement;
                editNow.replaceWith(nb);
                nb.addEventListener('click', () => {
                    last_user_id = new_user_id;
                    last_user_index = get_container_index_from_uid(new_user_id);
                    if (last_user_index !== -1) {
                        fill_user_edit(current_users[last_user_index]!);
                        open_user_list();
                    } else get_user_info(new_user_id, open_user_list);
                });
            }
            const labelSpan = qs<HTMLElement>('#AddUserSuccess label span:first-child');
            if (labelSpan) labelSpan.innerHTML = user_added_str.replace('%s', ajax_data.username);
            const successEl = qs<HTMLElement>('#AddUserSuccess');
            if (successEl) successEl.style.display = 'flex';
            const badge = qs('.badge-number');
            if (badge) badge.innerHTML = String(parseInt(badge.innerHTML) + 1);
        } else {
            qs<HTMLElement>('#AddUser .AddUserErrors')!.style.visibility = 'visible';
            qs<HTMLElement>('#AddUser .AddUserErrors')!.innerHTML = d.message ?? '';
        }
    });
}

interface PasswordLinkResult {
    generated_link: string;
    time_validation: string;
    send_by_mail: boolean;
}

function send_new_user_password(user_id: number, mail: string) {
    const send_by_mail = mail !== '';
    void pwgPost<PasswordLinkResult>('pwg.users.generatePasswordLink', {
        user_id,
        send_by_mail,
        pwg_token,
    }).then((res) => {
        if (res.stat === 'ok' && res.result !== undefined) {
            const password_container = document.getElementById('AddUserPasswordInputContainer');
            show(password_container);
            hide(document.getElementById('AddUserFieldContainer'));
            show(document.getElementById('AddUserSuccessContainer'));
            const pwdLink = qs<HTMLInputElement>('#AddUserPasswordLink');
            if (pwdLink) {
                pwdLink.value = res.result.generated_link;
                pwdLink.focus();
            }
            const textEl = qs<HTMLElement>('#AddUserTextField');
            if (textEl)
                textEl.innerHTML = send_by_mail
                    ? sprintf(validLinkMail, res.result.time_validation, `<b>${mail}</b>`)
                    : sprintf(validLinkWithoutMail, res.result.time_validation);
            if (send_by_mail && !res.result.send_by_mail) {
                qs('#AddUserUpdated')?.classList.add('icon-red-error', 'icon-cancel');
                const ut = qs<HTMLElement>('#AddUserUpdatedText');
                if (ut) ut.innerHTML = errorMailSent;
                if (textEl)
                    textEl.innerHTML = sprintf(errorMailSentMsg, res.result.time_validation);
            } else if (send_by_mail && res.result.send_by_mail) {
                hide(password_container);
            }
        }
    });
}

function delete_user(uid: number) {
    void pwgPost('pwg.users.delete', { user_id: uid, pwg_token }).then(() => {
        close_user_list();
        update_user_list();
        const badge = qs('.badge-number');
        if (badge) badge.innerHTML = String(parseInt(badge.innerHTML) - 1);
    });
}

function show_filter_infos(nb_filters: number) {
    const filteredEl = qs('.filtered-users');
    const searchVal = qs<HTMLInputElement>('#user_search')?.value ?? '';
    if (filteredEl) {
        if (searchVal.length !== 0 || nb_filters !== 0)
            filteredEl.innerHTML =
                total_users !== 1
                    ? filtered_users.replace(/%d/g, String(total_users))
                    : filtered_user.replace(/%d/g, String(total_users));
        else filteredEl.innerHTML = '';
    }
    const btnEl = qs<HTMLElement>('.advanced-filter-btn');
    const counterEl = qs<HTMLElement>('.filter-counter');
    if (nb_filters !== 0) {
        if (btnEl) btnEl.style.width = '80px';
        if (counterEl) {
            counterEl.innerHTML = String(nb_filters);
            counterEl.style.display = 'flex';
        }
    } else {
        if (btnEl) btnEl.style.width = '70px';
        if (counterEl) {
            counterEl.style.display = 'none';
            counterEl.innerHTML = '0';
        }
    }
}

function send_link_password(
    email: string,
    username: string,
    user_id: number,
    send_by_mail: boolean
) {
    void pwgPost<PasswordLinkResult>('pwg.users.generatePasswordLink', {
        user_id,
        send_by_mail: String(send_by_mail),
        pwg_token,
    })
        .then((res) => {
            if (res.stat === 'ok' && res.result !== undefined) {
                const copyInput = qs<HTMLInputElement>('#result_send_mail_copy_input');
                if (copyInput) copyInput.value = res.result.generated_link;
                if (send_by_mail) {
                    const resultMail = qs('#result_send_mail');
                    if (res.result.send_by_mail) {
                        resultMail?.classList.remove('update-password-fail', 'icon-red');
                        resultMail?.classList.add('update-password-success', 'icon-green');
                        qs('#icon_password_msg_result_mail')?.classList.replace(
                            'icon-cancel',
                            'icon-ok'
                        );
                        const fallbackMail = qs<HTMLInputElement>(
                            '.user-property-email .user-property-input'
                        )?.value;
                        const curr_mail =
                            fallbackMail !== undefined && fallbackMail !== ''
                                ? fallbackMail
                                : email;
                        qs<HTMLElement>('#password_msg_result_mail')!.innerHTML = sprintf(
                            mailSentAt,
                            username,
                            curr_mail
                        );
                    } else {
                        resultMail?.classList.remove('update-password-success', 'icon-green');
                        resultMail?.classList.add('update-password-fail', 'icon-red');
                        qs('#icon_password_msg_result_mail')?.classList.replace(
                            'icon-ok',
                            'icon-cancel'
                        );
                        qs<HTMLElement>('#password_msg_result_mail')!.innerHTML = errorMailSent;
                    }
                    hide(qs('.user-property-password-choice'));
                    show(qs('#edit_password_result_mail'));
                } else {
                    if (window.isSecureContext) copyToClipboard(res.result.generated_link);
                }
            }
        })
        .catch((err: unknown) => console.error('Error send_link_password:', err));
}

function set_main_user(user_id: number, new_username: string) {
    void pwgPost('pwg.users.setMainUser', { user_id, pwg_token }).then((res) => {
        if (res.stat === 'ok') {
            const king = qs('#who_is_the_king');
            if (king) {
                king.classList.remove(
                    'princes-of-this-piwigo',
                    'royal-court-of-this-piwigo',
                    'can-change'
                );
                king.classList.add('king-of-this-piwigo', 'cannot-change');
                king.title = mainUserStr;
                tippy(king, { content: mainUserStr });
            }
            owner_id = user_id;
            owner_username = new_username;
            set_main_user_success();
        }
    });
}

/*---- Main user (king) ----*/
function open_main_user_modal(user_to_edit: PwgUser) {
    const modal = qs<HTMLElement>('.user-property-main-user-change')!;
    reset_main_user_modals();
    qs<HTMLElement>('.main-user-proceed-desc')!.innerHTML = sprintf(
        mainUserContinue,
        `<b>${display_long_string(user_to_edit.username)}</b>`,
        `<b>${display_long_string(owner_username)}</b>`
    );
    show(modal);
    const proceedBtn = qs('.main-user-btn-proceed')!;
    const newProceed = proceedBtn.cloneNode(true) as HTMLElement;
    proceedBtn.replaceWith(newProceed);
    newProceed.addEventListener('click', () => {
        const gen = generate_random_string();
        qs<HTMLElement>('.main-user-rewrite-desc')!.innerHTML =
            sprintf(mainUserRewrite, `<b>${gen}</b>`) + ' :';
        hide(qs('.main-user-proceed'));
        show(qs('.main-user-rewrite'));
        event_check_string_main_user(user_to_edit.username, user_to_edit.id, gen);
    });
    qsa('.user-property-main-user-cancel, #main_user_success_close').forEach((el) => {
        const nb = el.cloneNode(true) as HTMLElement;
        el.replaceWith(nb);
        nb.addEventListener('click', () => hide(modal));
    });
}

function set_main_user_success() {
    const indexKey = current_users.findIndex((u) => u.id === owner_id);
    const new_main = qs<HTMLElement>(`.user-container[key="${indexKey}"] .user-container-username`);
    let king = document.getElementById('the_king');
    if (!king) {
        const div = document.createElement('div');
        div.innerHTML = king_template;
        king = div.firstElementChild as HTMLElement;
        king.title = mainUserStr;
        tippy(king, { content: mainUserStr });
    }
    new_main?.appendChild(king);
    qsa('.delete-user-button').forEach(hide);
    hide(qs('.main-user-validate'));
    show(qs('.main-user-success'));
}

function event_check_string_main_user(
    new_main_username: string,
    new_main_id: number,
    stringToCheck: string
) {
    const input = qs<HTMLInputElement>('#main_user_rewrite')!;
    const icon = qs<HTMLElement>('#main_user_rewrite_icon')!;
    const newInput = input.cloneNode(true) as HTMLInputElement;
    input.replaceWith(newInput);
    newInput.addEventListener('keyup', () => {
        icon.classList.remove('icon-ok', 'icon-green');
        icon.classList.add('icon-cancel', 'icon-red');
        if (stringToCheck === newInput.value) {
            icon.classList.remove('icon-cancel', 'icon-red');
            icon.classList.add('icon-ok', 'icon-green');
            qs<HTMLElement>('.main-user-validate-desc')!.innerHTML = sprintf(
                mainUserValidate,
                `<b>${display_long_string(owner_username)}</b>`,
                `<b>${display_long_string(new_main_username)}</b>`
            );
            qs<HTMLElement>('.main-user-success-desc')!.innerHTML = sprintf(
                mainUserSuccess,
                display_long_string(new_main_username)
            );
            hide(qs('.main-user-rewrite'));
            show(qs('.main-user-validate'));
            event_validate_main_user(new_main_username, new_main_id);
        }
    });
}

function event_validate_main_user(new_main_username: string, user_id: number) {
    const btn = qs('.main-user-btn-validate')!;
    const nb = btn.cloneNode(true) as HTMLElement;
    btn.replaceWith(nb);
    nb.addEventListener('click', () => set_main_user(user_id, new_main_username));
}

function set_view_selector(view_type: string) {
    void fetch(config.wsUrl + 'format=json&method=pwg.users.preferences.set', {
        method: 'POST',
        body: new URLSearchParams({ param: 'user-manager-view', value: view_type }),
    });
}
function setDisplayTile() {
    qsa('.user-container-wrapper').forEach((el) => {
        el.classList.remove('compactView', 'lineView');
        el.classList.add('tileView');
    });
    qsa('.user-header-col').forEach((el) => el.classList.add('hide'));
}
function setDisplayLine() {
    qsa('.user-container-wrapper').forEach((el) => {
        el.classList.remove('tileView', 'compactView');
        el.classList.add('lineView');
    });
    qsa('.user-header-col').forEach((el) => el.classList.remove('hide'));
}
function setDisplayCompact() {
    qsa('.user-container-wrapper').forEach((el) => {
        el.classList.remove('tileView', 'lineView');
        el.classList.add('compactView');
    });
    qsa('.user-header-col').forEach((el) => el.classList.add('hide'));
    if (per_page < 10) {
        per_page = 10;
        update_pagination_menu();
        update_user_list();
        qsa('[id^=pagination-per-page-]').forEach((el) =>
            el.classList.remove('selected-pagination')
        );
        qs('#pagination-per-page-10')?.classList.add('selected-pagination');
    }
}

/*---- Generate user containers ----*/
function user_container_click(this: HTMLElement) {
    if (!isSelectionMode()) return;
    const key = parseInt(this.getAttribute('key') ?? '0');
    const currUser = current_users[key];
    if (currUser === undefined) return;
    const cb = this.querySelector<HTMLElement>('.user-list-checkbox');
    if (!cb) return;
    if (cb.dataset['selected'] === '1') {
        cb.dataset['selected'] = '0';
        hide(cb.querySelector('i'));
        this.classList.remove('container-selected');
        selection = selection.filter((e) => e.id !== currUser.id);
    } else {
        cb.dataset['selected'] = '1';
        show(cb.querySelector('i'));
        this.classList.add('container-selected');
        selection.push({ id: currUser.id, username: currUser.username });
    }
    update_selection_content();
}

function generate_groups(container: HTMLElement, groups: Array<number | string>) {
    const groupsContainer = container.querySelector<HTMLElement>('.user-container-groups');
    if (!groupsContainer) return;
    groupsContainer.innerHTML = '';
    const tmpl = document.getElementById('template');
    const cloneByClass = (cls: string): HTMLElement | null => {
        const node = tmpl?.querySelector<HTMLElement>(cls)?.cloneNode(true);
        return node === undefined ? null : (node as HTMLElement);
    };
    if (groups.length >= 1) {
        const pg = cloneByClass('.group-primary');
        if (pg !== null) {
            pg.innerHTML = get_group_name_from_id(Number(groups[0]!));
            pg.classList.add(color_icons[Number(groups[0]!) % 5]!);
            groupsContainer.appendChild(pg);
        }
    }
    if (groups.length >= 2) {
        const pg2 = cloneByClass('.group-primary');
        if (pg2 !== null) {
            pg2.innerHTML = get_group_name_from_id(Number(groups[1]!));
            pg2.classList.add(color_icons[Number(groups[1]!) % 5]!);
            groupsContainer.appendChild(pg2);
        }
    }
    if (groups.length >= 3) {
        const bonus = cloneByClass('.group-bonus');
        if (bonus !== null) {
            bonus.innerHTML = '...';
            bonus.classList.add(color_icons[Number(groups[2]!) % 5]!, 'tiptip');
            const title = groups
                .slice(2)
                .map((id) => get_group_name_from_id(Number(id)))
                .join(', ');
            bonus.title = title;
            tippy(bonus, { content: title });
            groupsContainer.appendChild(bonus);
        }
    }
}

function fill_container_user_info(container: HTMLElement, user_index: number) {
    const user = current_users.at(user_index);
    if (user === undefined) return;
    const regParts = user.registration_date?.split(' ') ?? [];
    container.setAttribute('key', String(user_index));
    const usernameSpan = container.querySelector<HTMLElement>('.user-container-username span');
    if (usernameSpan) usernameSpan.innerHTML = user.username;
    if (user.id === owner_id && document.getElementById('the_king') === null) {
        const div = document.createElement('div');
        div.innerHTML = king_template;
        const king = div.firstElementChild as HTMLElement;
        king.title = mainUserStr;
        tippy(king, { content: mainUserStr });
        container.querySelector('.user-container-username')?.appendChild(king);
    }
    const initFill = get_initials(user.username);
    const initSpan = container.querySelector<HTMLElement>('.user-container-initials span');
    if (initSpan) {
        initSpan.innerHTML = initFill;
        initSpan.classList.remove(...color_icons);
        initSpan.classList.add(color_icons[user.id % 5]!);
        initSpan.classList.toggle('small', initFill.length > 1);
    }
    const statusSpan = container.querySelector<HTMLElement>('.user-container-status span');
    if (statusSpan) statusSpan.innerHTML = status_to_str[user.status] ?? '';
    const emailSpan = container.querySelector<HTMLElement>('.user-container-email span');
    if (emailSpan) emailSpan.innerHTML = user.email ?? '';
    generate_groups(container, user.groups ?? []);
    const regDate = container.querySelector<HTMLElement>('.user-container-registration-date');
    if (regDate) regDate.innerHTML = regParts[0] ?? '';
    const regTime = container.querySelector<HTMLElement>('.user-container-registration-time');
    if (regTime) regTime.innerHTML = regParts[1] ?? '';
    const regSince = container.querySelector<HTMLElement>(
        '.user-container-registration-date-since'
    );
    if (regSince) regSince.innerHTML = user.registration_date_since ?? '';
}

function generate_user_list() {
    qsa('#user-table-content .user-container').forEach((el) => el.remove());
    const tmplContainer = document
        .getElementById('template')
        ?.querySelector<HTMLElement>('.user-container');
    const wrapper = qs('#user-table-content .user-container-wrapper');
    for (let i = 0; i < current_users.length; i++) {
        const node = tmplContainer?.cloneNode(true);
        if (node === undefined || wrapper === null) continue;
        const container = node as HTMLElement;
        fill_container_user_info(container, i);
        wrapper.appendChild(container);
    }
    qsa('.user-container .user-list-checkbox').forEach((el) => {
        el.addEventListener('change', checkbox_change.bind(el));
    });
    qsa('.user-container').forEach((el) => {
        el.addEventListener('click', user_container_click.bind(el));
    });
}

/*---- Plugin API ----*/
type SetDataFn = (popIn: HTMLElement, user: PwgUser) => void;
type GetDataFn = () => void;

function plugin_add_tab_in_user_modal(
    tab_name: string,
    content_id: string,
    users_table: string | null = null,
    set_data_function: SetDataFn | null = null,
    get_data_function: GetDataFn | null = null
) {
    const name = tab_name.replace(/ /g, '').toLowerCase();
    if (document.getElementById('name_tab_' + name) !== null)
        throw new TypeError('An element with this tab_name already exists.');
    if (document.getElementById(content_id) === null)
        throw new TypeError('HTML element "' + content_id + '" not found.');
    if (document.querySelector('.edit-user-slides #' + content_id) !== null)
        throw new TypeError('An element with this content_id already exists.');
    if (users_table !== null && (set_data_function !== null || get_data_function !== null))
        throw new TypeError(
            'users_table must be null if set_data_function or get_data_function is used.'
        );
    if (set_data_function !== null)
        plugins_set_functions[name + '_set_function'] = set_data_function;
    if (get_data_function !== null)
        plugins_get_functions[name + '_get_function'] = get_data_function;
    if (users_table !== null) plugins_users_infos_table.push({ content_id, users_table });

    qs('.edit-user-tab-title')?.insertAdjacentHTML(
        'beforeend',
        `<p class="edit-user-tabsheet" id="name_tab_${name}" title="${tab_name}">${tab_name}</p>`
    );
    qs('.edit-user-slides')?.insertAdjacentHTML(
        'beforeend',
        `<div class="edit-user-tabsheet-plugins ${name}-container" id="tab_${name}"></div>`
    );
    const content = document.getElementById(content_id);
    document.getElementById('tab_' + name)?.appendChild(content!);
    show(content);
    editTabsBind();
    qsa('.edit-user-tabsheet').forEach((el) => el.classList.add('tab-with-plugin'));
    plugins_load.push(() => undefined);
    check_tabs('name_tab_' + name);
}

(
    window as unknown as Window & {
        plugin_add_tab_in_user_modal: typeof plugin_add_tab_in_user_modal;
    }
).plugin_add_tab_in_user_modal = plugin_add_tab_in_user_modal;

/*---- DOMContentLoaded ----*/
document.addEventListener('DOMContentLoaded', () => {
    tippy('.user-property-register, .user-property-last-visit', {
        delay: [0, 0],
        duration: [200, 200],
        maxWidth: 300,
        allowHTML: true,
    });
    qs('.advanced-filter-level select option:nth-child(2)')?.remove();

    qs('.button-edit-password-icon')?.addEventListener('click', () => {
        reset_password_modals();
        show(qs('.user-property-password-change'));
    });
    qs('.edit-password-cancel')?.addEventListener('click', () => {
        hide(qs('.user-property-password-change'));
        reset_input_password();
    });

    qsa('.icon-show-password').forEach((icon) => {
        icon.addEventListener('click', () => {
            const input =
                (icon.nextElementSibling as HTMLInputElement | null) ??
                (icon.previousElementSibling as HTMLInputElement | null);
            if (input?.type === 'password') {
                input.type = 'text';
                icon.classList.replace('icon-eye', 'icon-eye-off');
            } else if (input) {
                input.type = 'password';
                icon.classList.replace('icon-eye-off', 'icon-eye');
            }
        });
    });

    qsa('#edit_user_password, #edit_user_conf_password').forEach((el) =>
        el.addEventListener('keyup', hide_error_edit_user)
    );
    qs('.edit-username')?.addEventListener('click', () =>
        show(qs('.user-property-username-change'))
    );
    qs('.edit-username-cancel')?.addEventListener('click', () =>
        hide(qs('.user-property-username-change'))
    );
    qs('#UserList .close-update-button')?.addEventListener('click', close_user_list);
    qsa('.CloseUserList').forEach((el) => el.addEventListener('click', close_user_list));

    const toggleSel = document.getElementById('toggleSelectionMode') as HTMLInputElement | null;
    if (toggleSel !== null) {
        toggleSel.checked = false;
        toggleSel.addEventListener('click', () => selectionMode(toggleSel.checked));
    }
    qs('.edit-guest-user-button')?.addEventListener('click', open_guest_user_list);
    qsa('.CloseGuestUserList').forEach((el) => el.addEventListener('click', close_guest_user_list));
    qs('#GuestUserList .close-update-button')?.addEventListener('click', close_guest_user_list);

    qsa('[id^=action_]').forEach(hide);
    qs<HTMLSelectElement>('select[name=selectAction]')?.addEventListener(
        'change',
        function (this: HTMLSelectElement) {
            qsa('#applyActionBlock .infos').forEach(hide);
            qsa('[id^=action_]').forEach(hide);
            show(document.getElementById('action_' + this.value));
            if (this.value !== '-1') show(document.getElementById('applyActionBlock'));
            else hide(document.getElementById('applyActionBlock'));
        }
    );

    qsa('.yes_no_radio .user-list-checkbox').forEach((el) => {
        el.addEventListener('click', () => {
            if (el.dataset['selected'] !== '1') {
                el.dataset['selected'] = '1';
                Array.from(el.parentElement?.children ?? []).forEach((s) => {
                    if (s !== el) (s as HTMLElement).dataset['selected'] = '0';
                });
            }
        });
    });

    qs('.EditUserGenPassword')?.addEventListener('click', () => {
        const p = gen_password();
        qs<HTMLInputElement>('#edit_user_password')!.value = p;
        qs<HTMLInputElement>('#edit_user_conf_password')!.value = p;
        hide_error_edit_user();
    });
    qs('.AddUserGenPassword span')?.addEventListener('click', () => {
        const p = gen_password();
        qs<HTMLInputElement>('#add_user_pass')!.value = p;
        qs<HTMLInputElement>('#add_user_confpass')!.value = p;
    });
    qs('.AddUserSubmit')?.addEventListener('click', add_user);
    qs('.AddUserCancel')?.addEventListener('click', add_user_close);
    qs('.CloseAddUser')?.addEventListener('click', add_user_close);
    qs('.add-user-button')?.addEventListener('click', add_user_open);

    qs('#selectSet')?.addEventListener('click', (e) => {
        e.preventDefault();
        select_whole_set();
    });
    qs('#selectAllPage')?.addEventListener('click', (e) => {
        e.preventDefault();
        const ids = selection.map((x) => x.id);
        current_users.forEach((u) => {
            if (!ids.includes(u.id)) selection.push({ id: u.id, username: u.username });
        });
        update_selection_content();
    });
    qs('#selectNone')?.addEventListener('click', (e) => {
        e.preventDefault();
        selection = [];
        update_selection_content();
    });
    qs('#selectInvert')?.addEventListener('click', (e) => {
        e.preventDefault();
        const ids = selection.map((x) => x.id);
        current_users.forEach((u) => {
            if (ids.includes(u.id))
                selection.splice(
                    selection.findIndex((x) => x.id === u.id),
                    1
                );
            else selection.push({ id: u.id, username: u.username });
        });
        update_selection_content();
    });

    qs<HTMLSelectElement>('#permitActionUserList select[name=selectAction]')!.value = '-1';

    qs('.advanced-filter-btn')?.addEventListener('click', advanced_filter_button_click);
    qs('.advanced-filter span.icon-cancel')?.addEventListener('click', advanced_filter_hide);
    qsa('.advanced-filter-select').forEach((el) => el.addEventListener('change', update_user_list));
    qs('#user_search')?.addEventListener('input', update_user_list);

    if (qs<HTMLInputElement>('#displayCompact')?.checked === true) setDisplayCompact();
    if (qs<HTMLInputElement>('#displayLine')?.checked === true) setDisplayLine();
    if (qs<HTMLInputElement>('#displayTile')?.checked === true) setDisplayTile();
    qs('#displayCompact')?.addEventListener('change', () => {
        setDisplayCompact();
        set_view_selector('compact');
    });
    qs('#displayLine')?.addEventListener('change', () => {
        setDisplayLine();
        set_view_selector('line');
    });
    qs('#displayTile')?.addEventListener('change', () => {
        setDisplayTile();
        set_view_selector('tile');
    });

    // Pagination init
    qsa('[id^=pagination-per-page-]').forEach((el) => el.classList.remove('selected-pagination'));
    qs(`#pagination-per-page-${per_page}`)?.classList.add('selected-pagination');

    if (has_group !== '') {
        advanced_filter_button_click();
        qs<HTMLSelectElement>("select[name='filter_group']")!.value = has_group;
        update_user_list();
    }

    qs('.search-cancel')?.addEventListener('click', () => {
        qs<HTMLInputElement>('.search-input')!.value = '';
        qs('.search-input')?.dispatchEvent(new Event('input'));
    });
    qs('.search-input')?.addEventListener('input', function (this: HTMLInputElement) {
        if (this.value === '') hide(qs('.search-cancel'));
        else show(qs('.search-cancel'));
    });

    const iconUser = document.getElementById('icon-usr-list-user');
    const iconReg = document.getElementById('icon-usr-list-registered');
    qs('#usr-list-registered')?.addEventListener('click', () => {
        hide(iconUser);
        show(iconReg);
        if (iconUser) iconUser.className = 'icon-down';
        if (filter_by === 'id DESC') {
            if (iconReg) iconReg.className = 'icon-down';
            filter_by = 'id ASC';
        } else if (filter_by === 'id ASC') {
            if (iconReg) iconReg.className = 'icon-up';
            filter_by = 'id DESC';
        } else {
            if (iconReg) iconReg.className = 'icon-up';
            filter_by = 'id DESC';
        }
        update_user_list();
    });
    qs('#usr-list-user')?.addEventListener('click', () => {
        hide(iconReg);
        show(iconUser);
        if (iconReg) iconReg.className = 'icon-up';
        if (filter_by === 'username DESC') {
            if (iconUser) iconUser.className = 'icon-down';
            filter_by = 'username ASC';
        } else if (filter_by === 'username ASC') {
            if (iconUser) iconUser.className = 'icon-up';
            filter_by = 'username DESC';
        } else {
            if (iconUser) iconUser.className = 'icon-down';
            filter_by = 'username ASC';
        }
        update_user_list();
    });

    editTabsBind();

    qs('.edit-user-slides')?.addEventListener('scroll', function (this: HTMLElement) {
        const slides = Array.from(this.children) as HTMLElement[];
        const rectView = this.getBoundingClientRect();
        qsa('.edit-user-tabsheet').forEach((el) => el.classList.remove('selected'));
        slides.forEach((slide) => {
            const rect = slide.getBoundingClientRect();
            if (
                Number(rect.left.toFixed(0)) >= Number(rectView.left.toFixed(0)) - 1 &&
                Number(rect.right.toFixed(0)) <= Number(rectView.right.toFixed(0)) + 1
            ) {
                qs('#name_' + slide.id)?.classList.add('selected');
            }
        });
    });

    qsa('.guest-edit-user-tabsheet').forEach((el) => {
        el.addEventListener('click', () => {
            const parts = el.id.split('_');
            const tabId = parts[1] + '_' + parts[2] + '_' + parts[3];
            document.getElementById(tabId)?.scrollIntoView({ behavior: 'smooth' });
        });
    });

    qs('.guest-edit-user-slides')?.addEventListener('scroll', function (this: HTMLElement) {
        const slides = Array.from(this.children) as HTMLElement[];
        const rectView = this.getBoundingClientRect();
        qsa('.guest-edit-user-tabsheet').forEach((el) => el.classList.remove('selected'));
        slides.forEach((slide) => {
            const rect = slide.getBoundingClientRect();
            if (
                Number(rect.left.toFixed(0)) >= Number(rectView.left.toFixed(0)) - 1 &&
                Number(rect.right.toFixed(0)) <= Number(rectView.right.toFixed(0)) + 1
            ) {
                qs('#name_' + slide.id)?.classList.add('selected');
            }
        });
    });

    initSliders();
    setupRegisterDates(register_dates);
    selectionMode(false);
    get_guest_info();
    update_user_list();
    update_selection_content();

    // applyAction bulk handler (plugin-extensible — plugins may call
    // window.pwg_apply_action_handlers to prepend additional cases)
    const applyActionBtn = document.getElementById('applyAction');
    if (applyActionBtn) {
        applyActionBtn.addEventListener('click', function (e) {
            e.preventDefault();
            const action =
                document.querySelector<HTMLSelectElement>('select[name=selectAction]')?.value ?? '';
            let method = 'pwg.users.setInfo';
            const data: AjaxData = {
                pwg_token,
                user_id: selection.map((x) => x.id),
            };
            const sel = <T extends Element>(s: string): T | null => document.querySelector<T>(s);
            const inputVal = (s: string): string => sel<HTMLInputElement>(s)?.value ?? '';
            const selectVal = (s: string): string => sel<HTMLSelectElement>(s)?.value ?? '';
            const dataSelected = (s: string): boolean =>
                sel<HTMLElement>(s)?.getAttribute('data-selected') === '1';
            switch (action) {
                case 'delete':
                    if (
                        !dataSelected(
                            '#permitActionUserList .user-list-checkbox[name=confirm_deletion]'
                        )
                    ) {
                        alert(missingConfirm);
                        return false;
                    }
                    method = 'pwg.users.delete';
                    break;
                case 'group_associate':
                    method = 'pwg.groups.addUser';
                    data['group_id'] = selectVal('#permitActionUserList select[name=associate]');
                    break;
                case 'group_dissociate':
                    method = 'pwg.groups.deleteUser';
                    data['group_id'] = selectVal('#permitActionUserList select[name=dissociate]');
                    break;
                case 'status':
                    data['status'] = selectVal('#permitActionUserList select[name=status]');
                    break;
                case 'enabled_high':
                    data['enabled_high'] = dataSelected(
                        '#permitActionUserList .user-list-checkbox[name=enabled_high_yes]'
                    );
                    break;
                case 'level':
                    data['level'] = selectVal('#permitActionUserList select[name=level]');
                    break;
                case 'nb_image_page':
                    data['nb_image_page'] = inputVal(
                        '#permitActionUserList input[name=nb_image_page]'
                    );
                    break;
                case 'theme':
                    data['theme'] = selectVal('#permitActionUserList select[name=theme]');
                    break;
                case 'language':
                    data['language'] = selectVal('#permitActionUserList select[name=language]');
                    break;
                case 'recent_period': {
                    const sliderEl = sel<HTMLElement>(
                        '#permitActionUserList .period-select-bar .slider-bar-container'
                    );
                    const sliderIdx = parseInt(sliderEl?.dataset['sliderValue'] ?? '0');
                    data['recent_period'] = recent_period_values[sliderIdx];
                    break;
                }
                case 'expand':
                    data['expand'] = dataSelected(
                        '#permitActionUserList .user-list-checkbox[name=expand_yes]'
                    );
                    break;
                case 'show_nb_comments':
                    data['show_nb_comments'] = dataSelected(
                        '#permitActionUserList .user-list-checkbox[name=show_nb_comments_yes]'
                    );
                    break;
                case 'show_nb_hits':
                    data['show_nb_hits'] = dataSelected(
                        '#permitActionUserList .user-list-checkbox[name=show_nb_hits_yes]'
                    );
                    break;
                default:
                    alert('Unexpected action');
                    return false;
            }
            const applyActionLoading = document.getElementById('applyActionLoading');
            const applyActionInfos = document.querySelector<HTMLElement>(
                '#applyActionBlock .infos'
            );
            if (applyActionLoading) applyActionLoading.style.display = '';
            if (applyActionInfos) applyActionInfos.style.display = 'none';

            const formData = new FormData();
            const stringifyVal = (val: unknown): string => {
                if (val === null || val === undefined) return '';
                if (typeof val === 'string') return val;
                if (typeof val === 'number' || typeof val === 'boolean') return String(val);
                return JSON.stringify(val);
            };
            Object.keys(data).forEach((key) => {
                const val = data[key];
                if (Array.isArray(val))
                    (val as unknown[]).forEach((v) => formData.append(key + '[]', stringifyVal(v)));
                else formData.append(key, stringifyVal(val));
            });

            fetch(config.wsUrl + 'format=json&method=' + method, { method: 'POST', body: formData })
                .then(() => {
                    if (applyActionLoading) applyActionLoading.style.display = 'none';
                    if (applyActionInfos) applyActionInfos.style.display = 'inline-block';
                    update_user_list();
                    if (action === 'delete') {
                        selection = [];
                        update_selection_content();
                    }
                })
                .catch(() => {
                    if (applyActionLoading) applyActionLoading.style.display = 'none';
                });
            return false;
        });
    }
});

document.querySelectorAll<HTMLFormElement>('form[data-prevent-submit]').forEach((form) => {
    form.addEventListener('submit', (e) => e.preventDefault());
});

export {};
