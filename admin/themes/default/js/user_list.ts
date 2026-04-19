import { initModule } from './moduleInit.js';
import { sprintf } from './common.js';
import { pwgConfirm } from './pwgConfirm.js';
import TomSelect from 'tom-select';
import noUiSlider, { type API as NoUiSliderAPI } from 'nouislider';

type SliderEl = HTMLElement & { noUiSlider?: NoUiSliderAPI };
import tippy from 'tippy.js';

interface UserData {
    id: number;
    username: string;
    email: string;
    status: string;
    level: string;
    groups: number[];
    registration_date: string;
    registration_date_since: string;
    last_visit: string | null;
    last_visit_since: string;
    nb_image_page: number | string;
    recent_period: number | string;
    theme: string;
    language: string;
    enabled_high: string;
    expand: string;
    show_nb_comments: string;
    show_nb_hits: string;
}

interface SelectionEntry {
    id: number;
    username?: string;
}

interface GroupOption {
    value: string | number;
    label: string;
    isSelected: boolean | number;
}

type AjaxData = Record<string, string | number | boolean | number[] | string[]>;

interface UserListConfig {
    guestId: number;
    connectedUser: number;
    nbDays: string;
    nbPhotos: string;
    nbPhotosPerPage: string;
    pwgToken: string;
    groupsArrId: (string | number)[];
    groupsArrName: string[];
    registerDatesStr: string;
    titleMsg: string;
    areYouSureMsg: string;
    confirmMsg: string;
    cancelMsg: string;
    strAndOthersTags: string;
    missingConfirm: string;
    missingUsername: string;
    fieldNotEmpty: string;
    registeredStr: string;
    lastVisitStr: string;
    datesInfos: string;
    hideStr: string;
    showStr: string;
    userAddedStr: string;
    strPopinUpdateBtn: string;
    filteredUsers: string;
    filteredUser: string;
    historyBaseUrl: string;
    statusToStr: Record<string, string>;
    viewSelector: string;
    pagination: string;
    months: string[];
    connectedUserStatus: string;
    ownerId: number;
    hasGroup: string | false | null | undefined;
}

const color_icons = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'];
const status_arr = ['webmaster', 'admin', 'normal', 'generic', 'guest'];
const level_arr = ['0', '1', '2', '4', '8'];
let current_users: UserData[] = [];
let guest_id = 0;
let guest_user: UserData = {} as UserData;
let connected_user = 0;
let groups_arr: (string | number)[][] = [];
let nb_days = '';
let nb_photos = '';
let last_user_index = -1;
let last_user_id = -1;
let pwg_token = '';
let selection: SelectionEntry[] = [];
let first_update = true;
let total_users = 0;
let groupSelectize: TomSelect | null = null;
let groupGuestSelectize: TomSelect | null = null;

export function init(cfg: UserListConfig): void {
    guest_id = cfg.guestId;
    connected_user = cfg.connectedUser;
    nb_days = cfg.nbDays;
    nb_photos = cfg.nbPhotos;
    pwg_token = cfg.pwgToken;

    const groupsArrId = cfg.groupsArrId;
    const groupsArrName = cfg.groupsArrName;
    groups_arr = groupsArrId.map((elem, index) => [elem, groupsArrName[index]!]);

    const register_dates = cfg.registerDatesStr.split(',');

    const title_msg = cfg.titleMsg;
    const confirm_msg = cfg.confirmMsg;
    const cancel_msg = cfg.cancelMsg;
    const str_and_others_tags = cfg.strAndOthersTags;
    const missingConfirm = cfg.missingConfirm;
    const missingUsername = cfg.missingUsername;
    const fieldNotEmpty = cfg.fieldNotEmpty;
    const registered_str = cfg.registeredStr;
    const last_visit_str = cfg.lastVisitStr;
    const dates_infos = cfg.datesInfos;
    const user_added_str = cfg.userAddedStr;
    const filtered_users = cfg.filteredUsers;
    const filtered_user = cfg.filteredUser;
    const history_base_url = cfg.historyBaseUrl;
    const status_to_str = cfg.statusToStr;
    const view_selector = cfg.viewSelector;
    const pagination = cfg.pagination;
    const months = cfg.months;
    const connected_user_status = cfg.connectedUserStatus;
    const owner_id = cfg.ownerId;
    const has_group = cfg.hasGroup;
    const groupOptions: GroupOption[] = groups_arr.map(x => ({ value: x[0]!, label: String(x[1]), isSelected: 0 }));

    /*---------------- Escape of pop-in ----------------*/

    document.addEventListener('keydown', function (e) {
        if (e.keyCode === 27) {
            const userList = document.getElementById('UserList');
            if (userList) userList.style.display = 'none';
        }
    });

    /*---------------- Group Selectize ----------------*/

    document.querySelectorAll<HTMLElement>('[data-selectize=groups]').forEach(function (el) {
        new TomSelect(el as HTMLSelectElement, {
            valueField: 'value',
            labelField: 'label',
            searchField: ['label'],
            plugins: ['remove_button'],
        });
    });

    const _groupEls = document.querySelectorAll<HTMLElement>('[data-selectize=groups]');
    groupSelectize = _groupEls[0] ? (_groupEls[0] as HTMLElement & { tomselect?: TomSelect }).tomselect ?? null : null;
    groupGuestSelectize = _groupEls[1] ? (_groupEls[1] as HTMLElement & { tomselect?: TomSelect }).tomselect ?? null : null;

    /*----------------- OnClick functions -----------------*/

    function open_user_list(): void {
        hide_temporary_messages();
        const userList = document.getElementById('UserList');
        if (userList) userList.style.display = 'flex';
    }

    function close_user_list(): void {
        hide_temporary_messages();
        const userList = document.getElementById('UserList');
        if (userList) userList.style.display = 'none';
    }

    function open_guest_user_list(): void {
        hide_temporary_messages();
        const guestUserList = document.getElementById('GuestUserList');
        if (guestUserList) guestUserList.style.display = 'flex';
    }

    function close_guest_user_list(): void {
        hide_temporary_messages();
        const guestUserList = document.getElementById('GuestUserList');
        if (guestUserList) guestUserList.style.display = 'none';
    }

    function isSelectionMode(): boolean {
        const toggleSelectionMode = document.getElementById('toggleSelectionMode') as HTMLInputElement | null;
        return toggleSelectionMode ? toggleSelectionMode.checked : false;
    }

    tippy('.user-property-register', { maxWidth: 300, delay: 0, placement: 'top' });
    tippy('.user-property-last-visit', { maxWidth: 300, delay: 0, placement: 'top' });

    const advFilterLevelSelects = document.querySelectorAll('.advanced-filter-level select option');
    if (advFilterLevelSelects.length > 1) {
        advFilterLevelSelects[1]!.remove();
    }

    document.querySelectorAll<HTMLElement>('.edit-password').forEach(function (el) {
        el.addEventListener('click', function () {
            document.querySelectorAll<HTMLElement>('.user-property-password').forEach(e => { e.style.display = 'none'; });
            document.querySelectorAll<HTMLElement>('.user-property-password-change').forEach(e => { e.style.display = 'flex'; });
        });
    });

    document.querySelectorAll<HTMLElement>('.edit-password-cancel').forEach(function (el) {
        el.addEventListener('click', function () {
            document.querySelectorAll<HTMLElement>('.user-property-password').forEach(e => { e.style.display = ''; });
            document.querySelectorAll<HTMLElement>('.user-property-password-change').forEach(e => { e.style.display = 'none'; });
        });
    });

    document.querySelectorAll<HTMLElement>('.edit-username').forEach(function (el) {
        el.addEventListener('click', function () {
            document.querySelectorAll<HTMLElement>('.user-property-username').forEach(e => { e.style.display = 'none'; });
            document.querySelectorAll<HTMLElement>('.user-property-username-change').forEach(e => { e.style.display = 'flex'; });
        });
    });

    document.querySelectorAll<HTMLElement>('.edit-username-cancel').forEach(function (el) {
        el.addEventListener('click', function () {
            document.querySelectorAll<HTMLElement>('.user-property-username').forEach(e => { e.style.display = ''; });
            document.querySelectorAll<HTMLElement>('.user-property-username-change').forEach(e => { e.style.display = 'none'; });
        });
    });

    document.querySelectorAll<HTMLElement>('#UserList .close-update-button').forEach(el => { el.addEventListener('click', close_user_list); });
    document.querySelectorAll<HTMLElement>('.CloseUserList').forEach(el => { el.addEventListener('click', close_user_list); });

    const toggleSelectionMode = document.getElementById('toggleSelectionMode') as HTMLInputElement | null;
    if (toggleSelectionMode) {
        toggleSelectionMode.checked = false;
        toggleSelectionMode.addEventListener('click', function () {
            selectionMode(this.checked);
        });
    }

    document.querySelectorAll<HTMLElement>('.edit-guest-user-button').forEach(el => { el.addEventListener('click', open_guest_user_list); });
    document.querySelectorAll<HTMLElement>('.CloseGuestUserList').forEach(el => { el.addEventListener('click', close_guest_user_list); });
    document.querySelectorAll<HTMLElement>('#GuestUserList .close-update-button').forEach(el => { el.addEventListener('click', close_guest_user_list); });

    const showPassword = document.getElementById('show_password');
    if (showPassword) {
        showPassword.addEventListener('click', function (this: HTMLElement) {
            if (this.classList.contains('icon-eye')) {
                this.classList.remove('icon-eye');
                this.classList.add('icon-eye-off');
                const addUserPassword = document.getElementById('AddUserPassword') as HTMLInputElement | null;
                if (addUserPassword) addUserPassword.type = 'text';
            } else {
                this.classList.remove('icon-eye-off');
                this.classList.add('icon-eye');
                const addUserPassword = document.getElementById('AddUserPassword') as HTMLInputElement | null;
                if (addUserPassword) addUserPassword.type = 'password';
            }
        });
    }

    /* Action */
    document.querySelectorAll<HTMLElement>('[id^=action_]').forEach(el => { el.style.display = 'none'; });

    document.querySelectorAll<HTMLSelectElement>('select[name=selectAction]').forEach(function (el) {
        el.addEventListener('change', function (this: HTMLSelectElement) {
            document.querySelectorAll<HTMLElement>('#applyActionBlock .infos').forEach(e => { e.style.display = 'none'; });
            document.querySelectorAll<HTMLElement>('[id^=action_]').forEach(e => { e.style.display = 'none'; });
            const actionEl = document.getElementById('action_' + this.value);
            if (actionEl) actionEl.style.display = '';
            const applyActionBlock = document.getElementById('applyActionBlock');
            if (applyActionBlock) {
                applyActionBlock.style.display = this.value !== '-1' ? '' : 'none';
            }
        });
    });

    document.querySelectorAll<HTMLElement>('.yes_no_radio .user-list-checkbox').forEach(function (el) {
        el.addEventListener('click', function (this: HTMLElement) {
            if (this.getAttribute('data-selected') !== '1') {
                this.setAttribute('data-selected', '1');
                this.parentElement?.querySelectorAll<HTMLElement>('.user-list-checkbox').forEach(sibling => {
                    if (sibling !== this) sibling.setAttribute('data-selected', '0');
                });
            }
        });
    });

    document.querySelectorAll<HTMLElement>('.AddUserGenPassword').forEach(el => { el.addEventListener('click', gen_password); });
    document.querySelectorAll<HTMLElement>('.AddUserSubmit').forEach(el => { el.addEventListener('click', add_user); });
    document.querySelectorAll<HTMLElement>('.AddUserCancel').forEach(el => { el.addEventListener('click', add_user_close); });
    document.querySelectorAll<HTMLElement>('.CloseAddUser').forEach(el => { el.addEventListener('click', add_user_close); });
    document.querySelectorAll<HTMLElement>('.add-user-button').forEach(el => { el.addEventListener('click', add_user_open); });

    /* Select */

    const selectSetBtn = document.getElementById('selectSet');
    if (selectSetBtn) {
        selectSetBtn.addEventListener('click', function () { select_whole_set(); return false; });
    }

    const selectAllPageBtn = document.getElementById('selectAllPage');
    if (selectAllPageBtn) {
        selectAllPageBtn.addEventListener('click', function () {
            const selection_ids = selection.map((x) => x.id);
            for (let i = 0; i < current_users.length; i++) {
                if (!selection_ids.includes(current_users[i]!.id)) {
                    selection.push({ id: current_users[i]!.id, username: current_users[i]!.username });
                }
            }
            update_selection_content();
            return false;
        });
    }

    const selectNoneBtn = document.getElementById('selectNone');
    if (selectNoneBtn) {
        selectNoneBtn.addEventListener('click', function () {
            selection = [];
            update_selection_content();
            return false;
        });
    }

    const selectInvertBtn = document.getElementById('selectInvert');
    if (selectInvertBtn) {
        selectInvertBtn.addEventListener('click', function () {
            const selection_ids = selection.map((x) => x.id);
            for (let i = 0; i < current_users.length; i++) {
                if (selection_ids.includes(current_users[i]!.id)) {
                    selection.splice(selection.findIndex((x) => x.id === current_users[i]!.id), 1);
                } else {
                    selection.push({ id: current_users[i]!.id, username: current_users[i]!.username });
                }
            }
            update_selection_content();
            return false;
        });
    }

    const permitActionSelectAction = document.querySelector<HTMLSelectElement>('#permitActionUserList select[name=selectAction]');
    if (permitActionSelectAction) permitActionSelectAction.value = '-1';

    document.querySelectorAll<HTMLElement>('.advanced-filter-btn').forEach(el => { el.addEventListener('click', advanced_filter_button_click); });
    document.querySelectorAll<HTMLElement>('.advanced-filter span.icon-cancel').forEach(el => { el.addEventListener('click', advanced_filter_hide); });
    document.querySelectorAll<HTMLElement>('.advanced-filter-select').forEach(el => { el.addEventListener('change', update_user_list); });
    const userSearch = document.getElementById('user_search');
    if (userSearch) userSearch.addEventListener('input', update_user_list);

    /*View manager*/

    const displayCompact = document.getElementById('displayCompact') as HTMLInputElement | null;
    const displayLine = document.getElementById('displayLine') as HTMLInputElement | null;
    const displayTile = document.getElementById('displayTile') as HTMLInputElement | null;

    if (displayCompact?.checked) setDisplayCompact();
    if (displayLine?.checked) setDisplayLine();
    if (displayTile?.checked) setDisplayTile();

    if (displayCompact) {
        displayCompact.addEventListener('change', function () {
            setDisplayCompact();
            document.querySelectorAll<HTMLElement>('.addAlbum').forEach(el => {
                if (el.classList.contains('input-mode')) {
                    el.querySelectorAll<HTMLElement>('p').forEach(p => { p.style.display = 'none'; });
                }
            });
            set_view_selector('compact');
        });
    }
    if (displayLine) {
        displayLine.addEventListener('change', function () {
            setDisplayLine();
            document.querySelectorAll<HTMLElement>('.addAlbum').forEach(el => {
                if (el.classList.contains('input-mode')) {
                    el.querySelectorAll<HTMLElement>('p').forEach(p => { p.style.display = 'none'; });
                }
            });
            set_view_selector('line');
        });
    }
    if (displayTile) {
        displayTile.addEventListener('change', function () {
            setDisplayTile();
            document.querySelectorAll<HTMLElement>('.addAlbum').forEach(el => {
                if (el.classList.contains('input-mode')) {
                    el.querySelectorAll<HTMLElement>('p').forEach(p => { p.style.display = ''; });
                }
            });
            set_view_selector('tile');
        });
    }

    /* Pagination */

    const paginationPages = ['5', '10', '25', '50'];
    paginationPages.forEach(page => {
        const el = document.getElementById('pagination-per-page-' + page);
        if (el) {
            if (page === pagination) {
                el.classList.add('selected-pagination');
            } else {
                el.classList.remove('selected-pagination');
            }
        }
    });

    const paginationBtn = document.getElementById('pagination-per-page-' + pagination);
    if (paginationBtn) paginationBtn.dispatchEvent(new Event('click', { bubbles: true }));

    if (has_group) {
        advanced_filter_button_click();
        const filterGroup = document.querySelector<HTMLSelectElement>("select[name='filter_group']");
        if (filterGroup) filterGroup.value = has_group;
        update_user_list();
    }

    document.querySelectorAll<HTMLElement>('.search-cancel').forEach(el => {
        el.addEventListener('click', function () {
            document.querySelectorAll<HTMLInputElement>('.search-input').forEach(input => {
                input.value = '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    });

    document.querySelectorAll<HTMLInputElement>('.search-input').forEach(el => {
        el.addEventListener('input', function (this: HTMLInputElement) {
            document.querySelectorAll<HTMLElement>('.search-cancel').forEach(cancel => {
                cancel.style.display = this.value === '' ? 'none' : '';
            });
        });
    });

    function set_view_selector(view_type: string): void {
        const params = new URLSearchParams({ param: 'user-manager-view', value: view_type });
        fetch('ws.php?format=json&method=pwg.users.preferences.set', { method: 'POST', body: params })
            .catch(err => console.error('Error setting view selector:', err));
    }

    function setDisplayTile(): void {
        document.querySelectorAll<HTMLElement>('.user-container-wrapper').forEach(el => {
            el.classList.remove('compactView', 'lineView');
            el.classList.add('tileView');
        });
        document.querySelectorAll<HTMLElement>('.user-header-col').forEach(el => { el.classList.add('hide'); });
    }

    function setDisplayLine(): void {
        document.querySelectorAll<HTMLElement>('.user-container-wrapper').forEach(el => {
            el.classList.remove('tileView', 'compactView');
            el.classList.add('lineView');
        });
        document.querySelectorAll<HTMLElement>('.user-header-col').forEach(el => { el.classList.remove('hide'); });
    }

    function setDisplayCompact(): void {
        document.querySelectorAll<HTMLElement>('.user-container-wrapper').forEach(el => {
            el.classList.remove('tileView', 'lineView');
            el.classList.add('compactView');
        });
        document.querySelectorAll<HTMLElement>('.user-header-col').forEach(el => { el.classList.add('hide'); });
        if (per_page < 10) {
            per_page = 10;
            update_pagination_menu();
            update_user_list();
            const pages = ['5', '10', '25', '50'];
            pages.forEach(page => {
                const el = document.getElementById('pagination-per-page-' + page);
                if (el) {
                    if (page === '10') { el.classList.add('selected-pagination'); }
                    else { el.classList.remove('selected-pagination'); }
                }
            });
        }
    }

    /*---------------- Checkboxes ----------------*/

    function checkbox_change(this: HTMLElement): void {
        if (this.getAttribute('data-selected') === '1') {
            this.querySelectorAll<HTMLElement>('i').forEach(el => { el.style.display = 'none'; });
        } else {
            this.querySelectorAll<HTMLElement>('i').forEach(el => { el.style.display = ''; });
        }
    }

    function checkbox_click(this: HTMLElement): void {
        if (this.getAttribute('data-selected') === '1') {
            this.setAttribute('data-selected', '0');
            this.querySelectorAll<HTMLElement>('i').forEach(el => { el.style.display = 'none'; });
        } else {
            this.setAttribute('data-selected', '1');
            this.querySelectorAll<HTMLElement>('i').forEach(el => { el.style.display = ''; });
        }
    }

    document.querySelectorAll<HTMLElement>('.user-list-checkbox').forEach(el => {
        el.removeEventListener('change', checkbox_change);
        el.removeEventListener('click', checkbox_click);
        el.addEventListener('change', checkbox_change);
        el.addEventListener('click', checkbox_click);
    });

    /* --------------- User edit sliders ----------------*/
    const nb_image_page_values = [
        1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21,
        22, 23, 24, 25, 26, 27, 28, 29, 30, 35, 40, 45, 50, 60, 70, 80, 90, 100,
        200, 300, 500, 999,
    ];
    const recent_period_values = [
        0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
        25, 30, 40, 50, 60, 80, 99,
    ];
    const recent_period_init = 0;
    const nb_image_page_init = 0;

    function getSliderKeyFromValue(value: number, values: number[]): number {
        for (let i = 0; i < values.length; i++) {
            if ((values[i] ?? 0) >= value) return i;
        }
        return 0;
    }

    function getNbImagePageInfoFromIdx(idx: number): string {
        return sprintf(nb_photos, nb_image_page_values[idx]!);
    }

    function getRecentPeriodInfoFromIdx(idx: number): string {
        return sprintf(nb_days, recent_period_values[idx]!);
    }

    /* Photos bar slider */
    (function () {
        function makePhotosSlider(scope: string): void {
             
            const el = document.querySelector<SliderEl>(scope + ' .photos-select-bar .slider-bar-container');
            if (!el) return;
            noUiSlider.create(el, {
                start: [nb_image_page_init],
                range: { min: 0, max: nb_image_page_values.length - 1 },
                step: 1,
                connect: [true, false],
            });
            el.noUiSlider!.on('slide', function (values: (string | number)[]) {
                const val = Math.round(parseFloat(String(values[0])));
                const infos = document.querySelector<HTMLElement>(scope + ' .photos-select-bar .nb-img-page-infos');
                if (infos) infos.innerHTML = getNbImagePageInfoFromIdx(val);
            });
            el.noUiSlider!.on('change', function (values: (string | number)[]) {
                const val = Math.round(parseFloat(String(values[0])));
                const infos = document.querySelector<HTMLElement>(scope + ' .photos-select-bar .nb-img-page-infos');
                if (infos) infos.innerHTML = getNbImagePageInfoFromIdx(val);
                const input = document.querySelector<HTMLInputElement>(scope + ' .photos-select-bar input[name=nb_image_page]');
                if (input) {
                    input.value = String(nb_image_page_values[val]);
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        }

        makePhotosSlider('#UserList');
        makePhotosSlider('#GuestUserList');
        const permitActionPhotosInfo = document.querySelector<HTMLElement>('#permitActionUserList .photos-select-bar .nb-img-page-infos');
        if (permitActionPhotosInfo) permitActionPhotosInfo.innerHTML = getNbImagePageInfoFromIdx(0);
        makePhotosSlider('#permitActionUserList');
    }());

    /* recent_period slider */
    (function () {
        function makePeriodSlider(scope: string): void {
             
            const el = document.querySelector<SliderEl>(scope + ' .period-select-bar .slider-bar-container');
            if (!el) return;
            noUiSlider.create(el, {
                start: [recent_period_init],
                range: { min: 0, max: recent_period_values.length - 1 },
                step: 1,
                connect: [true, false],
            });
            el.noUiSlider!.on('slide', function (values: (string | number)[]) {
                const val = Math.round(parseFloat(String(values[0])));
                const infos = document.querySelector<HTMLElement>(scope + ' .period-select-bar .recent_period_infos');
                if (infos) infos.innerHTML = getRecentPeriodInfoFromIdx(val);
            });
            el.noUiSlider!.on('change', function (values: (string | number)[]) {
                const val = Math.round(parseFloat(String(values[0])));
                const infos = document.querySelector<HTMLElement>(scope + ' .period-select-bar .recent_period_infos');
                if (infos) infos.innerHTML = getRecentPeriodInfoFromIdx(val);
                const input = document.querySelector<HTMLInputElement>(scope + ' .period-select-bar input[name=recent_period]');
                if (input) {
                    input.value = String(recent_period_values[val]);
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        }

        makePeriodSlider('#UserList');
        makePeriodSlider('#GuestUserList');
        makePeriodSlider('#permitActionUserList');

         
        const permitPhotosSlider = document.querySelector<SliderEl>('#permitActionUserList .photos-select-bar .slider-bar-container');
        if (permitPhotosSlider?.noUiSlider) permitPhotosSlider.noUiSlider.set(0);

        const permitActionPeriodInfo = document.querySelector<HTMLElement>('#permitActionUserList .period-select-bar .recent_period_infos');
        if (permitActionPeriodInfo) permitActionPeriodInfo.innerHTML = getRecentPeriodInfoFromIdx(0);
    }());

    /* ----------- Pagination ------------*/

    let per_page = 5;
    let actual_page = 1;
    let max_page = 1;
    let nb_filtered_users = 0;
    const page_item = '<a data-page="%d">%d</a>';

    function move_to_page(page: number): void {
        if (page < 1 || page > max_page) return;
        actual_page = page;
        update_pagination_menu();
        update_user_list();
    }

    document.querySelectorAll<HTMLElement>('.pagination-arrow.right').forEach(el => {
        el.addEventListener('click', () => { move_to_page(actual_page + 1); });
    });
    document.querySelectorAll<HTMLElement>('.pagination-arrow.left').forEach(el => {
        el.addEventListener('click', () => { move_to_page(actual_page - 1); });
    });

    document.querySelectorAll<HTMLElement>('.pagination-per-page a').forEach(function (el) {
        el.addEventListener('click', function (this: HTMLElement) {
            const value = parseInt(this.innerHTML);
            const params = new URLSearchParams({
                param: 'user-manager-pagination',
                value: String(value),
                is_json: 'false',
            });
            fetch('ws.php?format=json&method=pwg.users.preferences.set', { method: 'POST', body: params })
                .catch(err => console.error('Error setting pagination:', err));
            per_page = value;
            actual_page = 1;
            update_pagination_menu();
            update_user_list();
            this.parentElement?.querySelectorAll<HTMLElement>('a').forEach(a => { a.classList.remove('selected-pagination'); });
            this.classList.add('selected-pagination');
        });
    });

    function append_pagination_item(page: number | null = null): void {
        const container = document.querySelector<HTMLElement>('.pagination-item-container');
        if (!container) return;
        if (page !== null) {
            const html = page_item.replace(/%d/g, String(page));
            const temp = document.createElement('div');
            temp.innerHTML = html;
            const new_tag = temp.firstElementChild as HTMLElement;
            container.appendChild(new_tag);
            if (actual_page === page) new_tag.classList.add('actual');
            new_tag.addEventListener('click', () => {
                move_to_page(parseInt(new_tag.getAttribute('data-page') ?? '1'));
            });
        } else {
            const temp = document.createElement('div');
            temp.innerHTML = '<span>...</span>';
            container.appendChild(temp.firstElementChild!);
        }
    }

    function update_pagination_items(): void {
        const container = document.querySelector<HTMLElement>('.pagination-item-container');
        if (!container) return;
        container.querySelectorAll('a').forEach(el => el.remove());
        container.querySelectorAll('span').forEach(el => el.remove());
        append_pagination_item(1);
        if (actual_page > 2) append_pagination_item();
        if (actual_page !== 1 && actual_page !== max_page) append_pagination_item(actual_page);
        if (actual_page < max_page - 1) append_pagination_item();
        append_pagination_item(max_page);
    }

    function update_pagination_menu(): void {
        max_page = Math.ceil(nb_filtered_users / per_page);
        updateArrows();
        update_pagination_items();
        const paginationContainer = document.querySelector<HTMLElement>('.pagination-container');
        if (paginationContainer) paginationContainer.style.display = max_page <= 1 ? 'none' : '';
    }

    function updateArrows(): void {
        document.querySelectorAll<HTMLElement>('.pagination-arrow.left').forEach(el => {
            if (actual_page === 1) el.classList.add('unavailable');
            else el.classList.remove('unavailable');
        });
        document.querySelectorAll<HTMLElement>('.pagination-arrow.right').forEach(el => {
            if (actual_page === max_page) el.classList.add('unavailable');
            else el.classList.remove('unavailable');
        });
    }

    /*------------------ Advanced filter ------------------*/

    function advanced_filter_button_click(): void {
        const advFilter = document.querySelector('.advanced-filter');
        if (advFilter && !advFilter.classList.contains('advanced-filter-open')) {
            advanced_filter_show();
        } else {
            advanced_filter_hide();
        }
    }

    function advanced_filter_show(): void {
        document.querySelectorAll('.advanced-filter-btn, .advanced-filter').forEach(el => { el.classList.add('advanced-filter-open'); });
    }

    function advanced_filter_hide(): void {
        document.querySelectorAll('.advanced-filter-btn, .advanced-filter').forEach(el => { el.classList.remove('advanced-filter-open'); });
    }

    function getDateStr(date: string): string {
        const date_arr = date.split('-');
        const curr_month = months[parseInt(date_arr[1]!) - 1];
        return curr_month + ' ' + date_arr[0]!;
    }

    function setupRegisterDates(reg_dates: string[]): void {
         
        const el = document.querySelector<SliderEl>('.advanced-filter .dates-select-bar .slider-bar-container');
        if (el) {
            noUiSlider.create(el, {
                start: [0, reg_dates.length - 1],
                range: { min: 0, max: reg_dates.length - 1 },
                step: 1,
                connect: true,
            });
            function updateDatesDisplay(values: (string | number)[]): void {
                const lo = Math.round(parseFloat(String(values[0])));
                const hi = Math.round(parseFloat(String(values[1])));
                const infos = document.querySelector<HTMLElement>('.advanced-filter .dates-infos');
                if (infos) infos.innerHTML = sprintf(dates_infos, getDateStr(reg_dates[lo]!), getDateStr(reg_dates[hi]!));
            }
            el.noUiSlider!.on('slide', updateDatesDisplay);
            el.noUiSlider!.on('change', function (values: (string | number)[]) {
                updateDatesDisplay(values);
                update_user_list();
            });
        }
        const datesInfos = document.querySelector<HTMLElement>('.advanced-filter .dates-infos');
        if (datesInfos) {
            datesInfos.innerHTML = sprintf(dates_infos, getDateStr(reg_dates[0]!), getDateStr(reg_dates[reg_dates.length - 1]!));
        }
    }

    /*------------------ Add User ------------------*/

    function gen_password(e: Event): void {
        e.preventDefault();
        const characterSet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        const length = Math.floor(Math.random() * 8) + 8;
        let password = '';
        for (let i = 0; i < length; i++) {
            password += characterSet.charAt(Math.floor(Math.random() * characterSet.length));
        }
        const addUserPassword = document.getElementById('AddUserPassword') as HTMLInputElement | null;
        if (addUserPassword) addUserPassword.value = password;
    }

    function add_user_close(): void {
        const addUser = document.getElementById('AddUser');
        if (addUser) addUser.style.display = 'none';
    }

    function add_user_open(): void {
        const addUser = document.getElementById('AddUser');
        if (addUser) {
            addUser.querySelectorAll<HTMLInputElement>('.AddUserInput').forEach(el => { el.value = ''; });
            addUser.style.display = 'flex';
            (document.querySelector<HTMLElement>('.AddUserLabelUsername input'))?.focus();
        }
    }

    /*------------------ Selection mode ------------------*/

    function checkbox_container_change(this: HTMLElement): void {
        if (this.getAttribute('data-selected') === '1') {
            this.setAttribute('data-selected', '0');
            this.querySelectorAll<HTMLElement>('i').forEach(el => { el.style.display = 'none'; });
        } else {
            this.setAttribute('data-selected', '1');
            this.querySelectorAll<HTMLElement>('i').forEach(el => { el.style.display = ''; });
        }
    }

    function checkbox_container_click(this: HTMLElement): void {
        const curr_container = this.closest<HTMLElement>('.user-container');
        const in_container = curr_container !== null;
        const curr_user = in_container
            ? (current_users[parseInt(curr_container.getAttribute('key') ?? '0')] ?? { id: -1 })
            : { id: -1 };
        if (this.getAttribute('data-selected') === '1') {
            this.setAttribute('data-selected', '0');
            this.querySelectorAll<HTMLElement>('i').forEach(el => { el.style.display = 'none'; });
            if (in_container) {
                curr_container.classList.remove('container-selected');
                selection = selection.filter((elem) => elem.id !== curr_user.id);
            }
        } else {
            this.setAttribute('data-selected', '1');
            this.querySelectorAll<HTMLElement>('i').forEach(el => { el.style.display = ''; });
            if (in_container && 'username' in curr_user) {
                curr_container.classList.add('container-selected');
                selection.push({ id: curr_user.id, username: (curr_user).username });
            }
        }
        if (in_container) update_selection_content();
    }

    void checkbox_container_change;
    void checkbox_container_click;

    function create_user_selected_item(user: SelectionEntry): HTMLElement | null {
        const template = document.querySelector<HTMLElement>('#template .user-selected-item');
        if (!template) return null;
        const new_elem = template.cloneNode(true) as HTMLElement;
        new_elem.setAttribute('data-id', String(user.id));
        const p = new_elem.querySelector<HTMLElement>('p');
        if (p) p.innerHTML = user.username ?? '';
        const a = new_elem.querySelector('a');
        if (a) {
            a.addEventListener('click', () => {
                selection.splice(selection.findIndex((i) => i.id === user.id), 1);
                update_selection_content();
            });
        }
        return new_elem;
    }

    function generate_user_selected_items(): void {
        let items_created = 0;
        const others = selection.length - 5;
        const userSelectedList = document.querySelector<HTMLElement>('.user-selected-list');
        if (userSelectedList) userSelectedList.querySelectorAll<HTMLElement>('.user-selected-item').forEach(el => el.remove());
        for (let i = 0; i < selection.length && items_created < 5; i++) {
            if (typeof selection[i]!.username !== 'undefined') {
                const item = create_user_selected_item(selection[i]!);
                if (item && userSelectedList) userSelectedList.appendChild(item);
                items_created += 1;
            }
        }
        const selectionOtherUsers = document.querySelector<HTMLElement>('.selection-other-users');
        if (selectionOtherUsers) {
            if (others >= 1) {
                selectionOtherUsers.innerHTML = str_and_others_tags.replace('%s', String(others));
                selectionOtherUsers.style.display = '';
            } else {
                selectionOtherUsers.style.display = 'none';
            }
        }
    }

    function fill_user_selected_list(): void {
        let elems_with_username = 0;
        for (let i = 0; i < selection.length && elems_with_username < 5; i++) {
            if (typeof selection[i]!.username !== 'undefined') elems_with_username += 1;
        }
        if (elems_with_username < 5 && elems_with_username !== selection.length) {
            get_first_selection_usernames(generate_user_selected_items);
        } else {
            generate_user_selected_items();
        }
    }

    function update_selection_content(): void {
        const number = selection.length;
        fill_user_selected_list();
        const forbidAction = document.getElementById('forbidAction');
        const permitActionUserList = document.getElementById('permitActionUserList');
        const selectionModeUl = document.querySelector<HTMLElement>('.selection-mode-ul');
        if (number === 0) {
            if (forbidAction) forbidAction.style.display = '';
            if (selectionModeUl) selectionModeUl.style.display = 'none';
            if (permitActionUserList) permitActionUserList.style.display = 'none';
        } else {
            if (forbidAction) forbidAction.style.display = 'none';
            if (permitActionUserList) permitActionUserList.style.display = '';
            if (selectionModeUl) selectionModeUl.style.display = '';
        }
        set_selected_to_selection();
        document.querySelectorAll<HTMLElement>('#applyActionBlock .infos').forEach(el => { el.style.display = 'none'; });
    }

    function set_selected_to_selection(): void {
        const toggleEl = document.getElementById('toggleSelectionMode') as HTMLInputElement | null;
        if (!toggleEl || !toggleEl.checked) return;
        const containers = document.querySelectorAll<HTMLElement>('.user-container-wrapper .user-container');
        containers.forEach((container, index) => {
            const selection_ids = selection.map((x) => x.id);
            const cuid = current_users[index]?.id; if (cuid !== undefined && selection_ids.includes(cuid)) {
                container.classList.add('container-selected');
                const checkbox = container.querySelector<HTMLElement>('.user-list-checkbox');
                if (checkbox) {
                    checkbox.setAttribute('data-selected', '1');
                    checkbox.querySelectorAll<HTMLElement>('i').forEach(el => { el.style.display = ''; });
                }
            } else {
                container.classList.remove('container-selected');
                const checkbox = container.querySelector<HTMLElement>('.user-list-checkbox');
                if (checkbox) {
                    checkbox.setAttribute('data-selected', '0');
                    checkbox.querySelectorAll<HTMLElement>('i').forEach(el => { el.style.display = 'none'; });
                }
            }
        });
    }

    function selectionMode(isSelection: boolean): void {
        const selectAction = document.querySelector<HTMLSelectElement>('#permitActionUserList select[name=selectAction]');
        if (selectAction) {
            selectAction.value = '-1';
            selectAction.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (isSelection) {
            set_selected_to_selection();
            document.querySelectorAll<HTMLElement>('.in-selection-mode').forEach(el => { el.style.display = ''; });
            document.querySelectorAll<HTMLElement>('.not-in-selection-mode').forEach(el => { el.style.display = 'none'; });
            if (view_selector === 'tile') {
                document.querySelectorAll<HTMLElement>('.user-container-email').forEach(el => { el.style.display = ''; });
            }
            if (view_selector === 'compact') {
                document.querySelectorAll<HTMLElement>('.user-container-email').forEach(el => { el.style.display = 'none'; });
            }
            document.querySelectorAll<HTMLElement>('.user-container').forEach(el => { el.classList.add('selectable'); });
        } else {
            document.querySelectorAll<HTMLElement>('.container-selected').forEach(el => { el.classList.remove('container-selected'); });
            document.querySelectorAll<HTMLElement>('.in-selection-mode').forEach(el => { el.style.display = 'none'; });
            document.querySelectorAll<HTMLElement>('.not-in-selection-mode').forEach(el => { el.style.display = ''; });
            if (view_selector === 'tile' || view_selector === 'line') {
                document.querySelectorAll<HTMLElement>('.user-container-email').forEach(el => { el.style.display = 'flex'; });
            }
            document.querySelectorAll<HTMLElement>('.user-container').forEach(el => { el.classList.remove('selectable'); });
        }
    }

    /*------------------ General functions ------------------*/

    function hide_temporary_messages(): void {
        document.querySelectorAll<HTMLElement>('.update-user-success').forEach(el => { el.style.display = 'none'; });
        const addUserSuccess = document.getElementById('AddUserSuccess');
        if (addUserSuccess) addUserSuccess.style.display = 'none';
        document.querySelectorAll<HTMLElement>('.error-msg').forEach(el => { el.style.display = 'none'; });
    }

    function get_group_name_from_id(id: string | number): string {
        for (let i = 0; i < groups_arr.length; i++) {
            if (groups_arr[i]![0] == id) return String(groups_arr[i]![1]);
        }
        return 'group_id error';
    }

    function get_container_index_from_uid(uid: number): number {
        for (let i = 0; i < current_users.length; i++) {
            if (current_users[i]!.id == uid) return i;
        }
        return -1;
    }

    /*----------------------- Generate User Containers -----------------------*/

    function user_container_click(this: HTMLElement): void {
        if (!isSelectionMode()) return;
        const container_checkbox = this.querySelector<HTMLElement>('.user-list-checkbox');
        const curr_user = current_users[parseInt(this.getAttribute('key') ?? '0')] ?? { id: -1 };
        if (container_checkbox && container_checkbox.getAttribute('data-selected') === '1') {
            container_checkbox.setAttribute('data-selected', '0');
            container_checkbox.querySelectorAll<HTMLElement>('i').forEach(el => { el.style.display = 'none'; });
            this.classList.remove('container-selected');
            selection = selection.filter((elem) => elem.id !== curr_user.id);
        } else if (container_checkbox) {
            container_checkbox.setAttribute('data-selected', '1');
            container_checkbox.querySelectorAll<HTMLElement>('i').forEach(el => { el.style.display = ''; });
            this.classList.add('container-selected');
            if ('username' in curr_user) {
                selection.push({ id: curr_user.id, username: (curr_user).username });
            }
        }
        update_selection_content();
    }

    function generate_groups(container: HTMLElement, groups: number[]): void {
        const groupsContainer = container.querySelector<HTMLElement>('.user-container-groups');
        if (groupsContainer) groupsContainer.innerHTML = '';
        if (groups.length >= 1) {
            const template = document.querySelector<HTMLElement>('#template .group-primary');
            if (template) {
                const primary_grp = template.cloneNode(true) as HTMLElement;
                primary_grp.innerHTML = get_group_name_from_id(groups[0]!);
                primary_grp.classList.add(color_icons[groups[0]! % 5]!);
                if (groupsContainer) groupsContainer.appendChild(primary_grp);
            }
        }
        if (groups.length >= 2) {
            const template = document.querySelector<HTMLElement>('#template .group-primary');
            if (template) {
                const primary_grp = template.cloneNode(true) as HTMLElement;
                primary_grp.innerHTML = get_group_name_from_id(groups[1]!);
                primary_grp.classList.add(color_icons[groups[1]! % 5]!);
                if (groupsContainer) groupsContainer.appendChild(primary_grp);
            }
        }
        if (groups.length >= 3) {
            const template = document.querySelector<HTMLElement>('#template .group-bonus');
            if (template) {
                const bonus_grp = template.cloneNode(true) as HTMLElement;
                bonus_grp.innerHTML = '...';
                bonus_grp.classList.add(color_icons[groups[2]! % 5]!, 'tiptip');
                let groups_in_title = '';
                for (let i = 2; i < groups.length; i++) {
                    groups_in_title += get_group_name_from_id(groups[i]!) + ', ';
                }
                groups_in_title = groups_in_title.substring(0, groups_in_title.length - 2);
                bonus_grp.setAttribute('title', groups_in_title);
                if (groupsContainer) groupsContainer.appendChild(bonus_grp);
            }
        }
    }

    function get_initials(username: string): string {
        const words = username.toUpperCase().split(' ');
        let res = words[0]?.[0] ?? '';
        if (words.length > 1 && words[1]?.[0] !== undefined) res += words[1][0];
        return res;
    }

    function fill_container_user_info(container: HTMLElement, user_index: number): void {
        const user = current_users[user_index]!;
        const registration_dates = user.registration_date.split(' ');
        container.setAttribute('key', String(user_index));
        const usernameSpan = container.querySelector<HTMLElement>('.user-container-username span');
        if (usernameSpan) usernameSpan.innerHTML = user.username;
        const initialsSpan = container.querySelector<HTMLElement>('.user-container-initials span');
        if (initialsSpan) {
            initialsSpan.innerHTML = get_initials(user.username);
            initialsSpan.classList.add(color_icons[user.id % 5]!);
        }
        const statusSpan = container.querySelector<HTMLElement>('.user-container-status span');
        if (statusSpan) statusSpan.innerHTML = status_to_str[user.status] ?? '';
        const emailSpan = container.querySelector<HTMLElement>('.user-container-email span');
        if (emailSpan) emailSpan.innerHTML = user.email;
        generate_groups(container, user.groups);
        const regDateEl = container.querySelector<HTMLElement>('.user-container-registration-date');
        if (regDateEl) regDateEl.innerHTML = registration_dates[0] ?? '';
        const regTimeEl = container.querySelector<HTMLElement>('.user-container-registration-time');
        if (regTimeEl) regTimeEl.innerHTML = registration_dates[1] ?? '';
        const regDateSinceEl = container.querySelector<HTMLElement>('.user-container-registration-date-since');
        if (regDateSinceEl) regDateSinceEl.innerHTML = user.registration_date_since;
    }

    function generate_user_list(): void {
        const userTableContent = document.getElementById('user-table-content');
        if (userTableContent) userTableContent.querySelectorAll<HTMLElement>('.user-container').forEach(el => el.remove());
        for (let i = 0; i < current_users.length; i++) {
            const template = document.querySelector<HTMLElement>('#template .user-container');
            if (template) {
                const new_container = template.cloneNode(true) as HTMLElement;
                fill_container_user_info(new_container, i);
                const wrapper = document.querySelector<HTMLElement>('#user-table-content .user-container-wrapper');
                if (wrapper) wrapper.appendChild(new_container);
            }
        }
        document.querySelectorAll<HTMLElement>('.user-container .user-list-checkbox').forEach(el => {
            el.removeEventListener('change', checkbox_change);
            el.removeEventListener('click', checkbox_click);
            el.addEventListener('change', checkbox_change);
        });
        document.querySelectorAll<HTMLElement>('.user-container').forEach(el => {
            el.removeEventListener('click', user_container_click);
            el.addEventListener('click', user_container_click);
        });
    }

    /*--------------------- Fill the pop-in values ---------------------*/

    function get_formatted_date(date_str: string | null): string {
        if (date_str === null) return 'N/A';
        const first_part = date_str.split(' ')[0];
        return (first_part ?? '').split('-').join('/');
    }

    function get_status_index(status: string): number {
        for (let i = 0; i < status_arr.length; i++) {
            if (status_arr[i] === status) return i;
        }
        return 0;
    }

    function get_level_index(level: string): number {
        for (let i = 0; i < level_arr.length; i++) {
            if (level_arr[i] === level) return i;
        }
        return 0;
    }

    function set_selected_groups(groups: number[]): void {
        for (let i = 0; i < groupOptions.length; i++) {
            groupOptions[i]!.isSelected = groups.includes(Number(groupOptions[i]!.value));
        }
    }

    function fill_user_edit_summary(user_to_edit: UserData, pop_in: Element, isGuest: boolean): void {
        const initialsSpan = pop_in.querySelector<HTMLElement>('.user-property-initials span');
        if (initialsSpan) {
            color_icons.forEach(icon => initialsSpan.classList.remove(icon));
            if (!isGuest) initialsSpan.innerHTML = get_initials(user_to_edit.username);
            initialsSpan.classList.add(color_icons[user_to_edit.id % 5]!);
        }
        const usernameSpan = pop_in.querySelector<HTMLElement>('.user-property-username span');
        if (usernameSpan) usernameSpan.innerHTML = user_to_edit.username;
        const editUsernameSpecifier = pop_in.querySelector<HTMLElement>('.user-property-username .edit-username-specifier');
        if (editUsernameSpecifier) {
            editUsernameSpecifier.style.display = (user_to_edit.id === connected_user || user_to_edit.id === 1) ? '' : 'none';
        }
        const usernameChangeInput = pop_in.querySelector<HTMLInputElement>('.user-property-username-change input');
        if (usernameChangeInput) usernameChangeInput.value = user_to_edit.username;
        const passwordChangeInput = pop_in.querySelector<HTMLInputElement>('.user-property-password-change input');
        if (passwordChangeInput) passwordChangeInput.value = '';
        const permissionsLink = pop_in.querySelector<HTMLAnchorElement>('.user-property-permissions a');
        if (permissionsLink) permissionsLink.setAttribute('href', `admin.php?page=user_perm&user_id=${user_to_edit.id}`);
        const registerEl = pop_in.querySelector<HTMLElement>('.user-property-register');
        if (registerEl) {
            registerEl.innerHTML = get_formatted_date(user_to_edit.registration_date);
            tippy(registerEl, {
                content: `${registered_str}<br />${user_to_edit.registration_date_since}`,
                allowHTML: true,
                placement: 'top',
            });
        }
        const lastVisitEl = pop_in.querySelector<HTMLElement>('.user-property-last-visit');
        if (lastVisitEl) {
            lastVisitEl.innerHTML = get_formatted_date(user_to_edit.last_visit);
            tippy(lastVisitEl, {
                content: `${last_visit_str}<br />${user_to_edit.last_visit_since}`,
                allowHTML: true,
                placement: 'top',
            });
        }
        const historyLink = pop_in.querySelector<HTMLAnchorElement>('.user-property-history a');
        if (historyLink) historyLink.setAttribute('href', history_base_url + String(user_to_edit.id));
    }

    function fill_user_edit_properties(user_to_edit: UserData, pop_in: Element): void {
        const status_index = get_status_index(user_to_edit.status);
        const level_index = get_level_index(user_to_edit.level);
        const current_group_selectize = user_to_edit.id === guest_id ? groupGuestSelectize : groupSelectize;

        const emailInput = pop_in.querySelector<HTMLInputElement>('.user-property-email input');
        if (emailInput) emailInput.value = user_to_edit.email;

        const statusOptions = pop_in.querySelectorAll<HTMLOptionElement>('.user-property-status select option');
        if (statusOptions[status_index]) statusOptions[status_index].selected = true;

        const levelOptions = pop_in.querySelectorAll<HTMLOptionElement>('.user-property-level select option');
        if (levelOptions[level_index]) levelOptions[level_index].selected = true;

        const photosInputs = pop_in.querySelectorAll<HTMLInputElement>('.photos-select-bar input');
        if (photosInputs[0]) photosInputs[0].value = String(user_to_edit.recent_period);

        set_selected_groups(user_to_edit.groups);
        if (current_group_selectize) {
            current_group_selectize.clear();
            current_group_selectize.clearOptions();
            current_group_selectize.addOptions(groupOptions);
            groupOptions.filter(g => g.isSelected).forEach(g => {
                current_group_selectize.addItem(String(g.value));
            });
        }

        const hdCheckbox = pop_in.querySelector<HTMLElement>('.user-list-checkbox[name="hd_enabled"]');
        if (hdCheckbox) hdCheckbox.setAttribute('data-selected', user_to_edit.enabled_high === 'true' ? '1' : '0');
    }

    function fill_user_edit_preferences(user_to_edit: UserData, pop_in: Element): void {
        const slider_key_photos = getSliderKeyFromValue(parseInt(String(user_to_edit.nb_image_page)), nb_image_page_values);
        const slider_key_period = getSliderKeyFromValue(parseInt(String(user_to_edit.recent_period)), recent_period_values);

         
        const photosSlider = pop_in.querySelector<SliderEl>('.photos-select-bar .slider-bar-container');
        if (photosSlider?.noUiSlider) photosSlider.noUiSlider.set(slider_key_photos);

        pop_in.querySelectorAll<HTMLOptionElement>('.user-property-theme select option').forEach(option => {
            if (option.value === user_to_edit.theme) option.selected = true;
        });
        pop_in.querySelectorAll<HTMLOptionElement>('.user-property-lang select option').forEach(option => {
            if (option.value === user_to_edit.language) option.selected = true;
        });

         
        const periodSlider = pop_in.querySelector<SliderEl>('.period-select-bar .slider-bar-container');
        if (periodSlider?.noUiSlider) periodSlider.noUiSlider.set(slider_key_period);

        const expandCheckbox = pop_in.querySelector<HTMLElement>('.user-list-checkbox[name="expand_all_albums"]');
        if (expandCheckbox) expandCheckbox.setAttribute('data-selected', user_to_edit.expand === 'true' ? '1' : '0');

        const nbCommentsCheckbox = pop_in.querySelector<HTMLElement>('.user-list-checkbox[name="show_nb_comments"]');
        if (nbCommentsCheckbox) nbCommentsCheckbox.setAttribute('data-selected', user_to_edit.show_nb_comments === 'true' ? '1' : '0');

        const nbHitsCheckbox = pop_in.querySelector<HTMLElement>('.user-list-checkbox[name="show_nb_hits"]');
        if (nbHitsCheckbox) nbHitsCheckbox.setAttribute('data-selected', user_to_edit.show_nb_hits === 'true' ? '1' : '0');
    }

    function fill_user_edit_update(user_to_edit: UserData, pop_in: Element): void {
        const updateButton = pop_in.querySelector<HTMLElement>('.update-user-button');
        if (updateButton) {
            updateButton.removeEventListener('click', update_user_info);
            updateButton.removeEventListener('click', update_guest_info);
            updateButton.addEventListener('click', user_to_edit.id === guest_id ? update_guest_info : update_user_info);
        }
        const editUsernameValidate = pop_in.querySelector<HTMLElement>('.edit-username-validate');
        if (editUsernameValidate) {
            editUsernameValidate.removeEventListener('click', update_user_username);
            editUsernameValidate.addEventListener('click', update_user_username);
        }
        const editPasswordValidate = pop_in.querySelector<HTMLElement>('.edit-password-validate');
        if (editPasswordValidate) {
            editPasswordValidate.removeEventListener('click', update_user_password);
            editPasswordValidate.addEventListener('click', update_user_password);
        }
        const deleteButton = pop_in.querySelector<HTMLElement>('.delete-user-button');
        if (deleteButton) {
            deleteButton.addEventListener('click', function () {
                pwgConfirm({
                    title: title_msg.replace('%s', user_to_edit.username),
                    buttons: {
                        confirm: { text: confirm_msg, btnClass: 'btn-red', action: function () { delete_user(user_to_edit.id); } },
                        cancel: { text: cancel_msg },
                    },
                });
            });
        }
    }

    function fill_user_edit_permissions(user_to_edit: UserData, pop_in: Element): void {
        function setPermissionDisplay(container: Element, selector: string, visible: boolean): void {
            container.querySelectorAll<HTMLElement>(selector).forEach(el => { el.style.display = visible ? '' : 'none'; });
        }
        function setDisabled(container: Element, selector: string, disabled: boolean): void {
            container.querySelectorAll<HTMLElement>(selector).forEach(el => {
                if (disabled) el.setAttribute('disabled', 'disabled');
                else el.removeAttribute('disabled');
            });
        }
        function setClass(container: Element, selector: string, className: string, add: boolean): void {
            container.querySelectorAll<HTMLElement>(selector).forEach(el => {
                if (add) el.classList.add(className);
                else el.classList.remove(className);
            });
        }

        if (user_to_edit.id !== connected_user) {
            if (!is_owner(connected_user)) {
                if (is_owner(user_to_edit.id)) {
                    setPermissionDisplay(pop_in, '.delete-user-button', false);
                    setPermissionDisplay(pop_in, '.user-property-password.edit-password', false);
                    setDisabled(pop_in, '.user-property-email .user-property-input', true);
                    setClass(pop_in, '.user-property-status .user-property-select', 'notClickable', true);
                    setPermissionDisplay(pop_in, '.user-property-username .edit-username', false);
                } else {
                    setPermissionDisplay(pop_in, '.user-property-password.edit-password', true);
                    setDisabled(pop_in, '.user-property-email .user-property-input', false);
                    setClass(pop_in, '.user-property-status .user-property-select', 'notClickable', false);
                    setPermissionDisplay(pop_in, '.user-property-username .edit-username', true);
                }
                if (user_to_edit.status === connected_user_status && connected_user_status === 'webmaster' && !is_owner(user_to_edit.id)) {
                    setPermissionDisplay(pop_in, '.delete-user-button', true);
                    setPermissionDisplay(pop_in, '.user-property-password.edit-password', true);
                    setDisabled(pop_in, '.user-property-email .user-property-input', false);
                    setClass(pop_in, '.user-property-status .user-property-select', 'notClickable', false);
                    setPermissionDisplay(pop_in, '.user-property-username .edit-username', true);
                } else if (user_to_edit.status === connected_user_status && connected_user_status === 'admin') {
                    setPermissionDisplay(pop_in, '.delete-user-button', false);
                    setPermissionDisplay(pop_in, '.user-property-password.edit-password', true);
                    setDisabled(pop_in, '.user-property-email .user-property-input', false);
                    setClass(pop_in, '.user-property-username .edit-username', 'notClickable', false);
                    setClass(pop_in, '.user-property-status .user-property-select', 'notClickable', true);
                } else if (user_to_edit.status === 'webmaster' && connected_user_status === 'admin') {
                    setPermissionDisplay(pop_in, '.user-property-password.edit-password', false);
                    setDisabled(pop_in, '.user-property-email .user-property-input', true);
                    setClass(pop_in, '.user-property-status .user-property-select', 'notClickable', true);
                    setPermissionDisplay(pop_in, '.user-property-username .edit-username', false);
                } else if (user_to_edit.status === 'admin' && connected_user_status === 'webmaster') {
                    setPermissionDisplay(pop_in, '.user-property-password.edit-password', true);
                    setDisabled(pop_in, '.user-property-email .user-property-input', false);
                    setClass(pop_in, '.user-property-status .user-property-select', 'notClickable', false);
                    setPermissionDisplay(pop_in, '.user-property-username .edit-username', true);
                }
            } else {
                setPermissionDisplay(pop_in, '.delete-user-button', true);
                setPermissionDisplay(pop_in, '.user-property-password.edit-password', true);
                setDisabled(pop_in, '.user-property-email .user-property-input', false);
                setClass(pop_in, '.user-property-status .user-property-select', 'notClickable', false);
                setPermissionDisplay(pop_in, '.user-property-username .edit-username', true);
            }
        } else {
            setPermissionDisplay(pop_in, '.delete-user-button', false);
            setPermissionDisplay(pop_in, '.user-property-password.edit-password', true);
            setDisabled(pop_in, '.user-property-email .user-property-input', false);
            setClass(pop_in, '.user-property-status .user-property-select', 'notClickable', true);
            setPermissionDisplay(pop_in, '.user-property-username .edit-username', true);
        }
        document.querySelectorAll<HTMLElement>('.notClickableBefore').forEach(el => { el.classList.remove('notClickableBefore'); });
        document.querySelectorAll<HTMLElement>('.notClickable').forEach(el => {
            if (el.parentElement) el.parentElement.classList.add('notClickableBefore');
        });
    }

    function is_owner(user_id: number): boolean {
        return user_id === owner_id;
    }

    function fill_user_edit(user_to_edit: UserData): void {
        const pop_in = document.querySelector('.UserListPopInContainer');
        if (!pop_in) return;
        fill_user_edit_summary(user_to_edit, pop_in, false);
        fill_user_edit_properties(user_to_edit, pop_in);
        fill_user_edit_preferences(user_to_edit, pop_in);
        fill_user_edit_update(user_to_edit, pop_in);
        fill_user_edit_permissions(user_to_edit, pop_in);
    }

    function fill_guest_edit(): void {
        const pop_in = document.querySelector('.GuestUserListPopInContainer');
        if (!pop_in) return;
        fill_user_edit_summary(guest_user, pop_in, true);
        fill_user_edit_properties(guest_user, pop_in);
        fill_user_edit_preferences(guest_user, pop_in);
        fill_user_edit_update(guest_user, pop_in);
    }

    /*------------------- Fill data for setInfo -------------------*/

    function fill_ajax_data_from_properties(ajax_data: AjaxData, pop_in: Element): AjaxData {
        const groups_selected: number[] = [];
        pop_in.querySelectorAll<HTMLElement>('.user-property-group .ts-control .item').forEach(item => {
            groups_selected.push(parseInt(item.getAttribute('data-value') ?? '0'));
        });
        const emailInput = pop_in.querySelector<HTMLInputElement>('.user-property-email input');
        ajax_data['email'] = emailInput ? emailInput.value : '';
        const statusSelect = pop_in.querySelector<HTMLSelectElement>('.user-property-status select');
        const statusValue = statusSelect ? statusSelect.value : '';
        if (connected_user_status === 'admin' && statusValue !== 'webmaster' && statusValue !== 'admin') {
            ajax_data['status'] = statusValue;
        } else if (connected_user_status === 'webmaster') {
            ajax_data['status'] = statusValue;
        }
        const levelSelect = pop_in.querySelector<HTMLSelectElement>('.user-property-level select');
        ajax_data['level'] = levelSelect ? levelSelect.value : '';
        ajax_data['group_id'] = groups_selected.length === 0 ? -1 : groups_selected;
        const hdCheckbox = pop_in.querySelector<HTMLElement>('.user-list-checkbox[name="hd_enabled"]');
        ajax_data['enabled_high'] = !!(hdCheckbox && hdCheckbox.getAttribute('data-selected') === '1');
        return ajax_data;
    }

    function fill_ajax_data_from_preferences(ajax_data: AjaxData, pop_in: Element): AjaxData {
        const themeSelect = pop_in.querySelector<HTMLSelectElement>('.user-property-theme select');
        ajax_data['theme'] = themeSelect ? themeSelect.value : '';
        const langSelect = pop_in.querySelector<HTMLSelectElement>('.user-property-lang select');
        ajax_data['language'] = langSelect ? langSelect.value : '';

        let photosValue = 0;
         
        const photosSlider2 = pop_in.querySelector<SliderEl>('.photos-select-bar .slider-bar-container');
        if (photosSlider2?.noUiSlider) photosValue = Math.round(parseFloat(String(photosSlider2.noUiSlider.get() as string | number)));
        ajax_data['nb_image_page'] = nb_image_page_values[photosValue]!;

        let periodValue = 0;
         
        const periodSlider2 = pop_in.querySelector<SliderEl>('.period-select-bar .slider-bar-container');
        if (periodSlider2?.noUiSlider) periodValue = Math.round(parseFloat(String(periodSlider2.noUiSlider.get() as string | number)));
        ajax_data['recent_period'] = recent_period_values[periodValue]!;

        const expandCheckbox = pop_in.querySelector<HTMLElement>('.user-list-checkbox[name="expand_all_albums"]');
        ajax_data['expand'] = !!(expandCheckbox && expandCheckbox.getAttribute('data-selected') === '1');
        const nbCommentsCheckbox = pop_in.querySelector<HTMLElement>('.user-list-checkbox[name="show_nb_comments"]');
        ajax_data['show_nb_comments'] = !!(nbCommentsCheckbox && nbCommentsCheckbox.getAttribute('data-selected') === '1');
        const nbHitsCheckbox = pop_in.querySelector<HTMLElement>('.user-list-checkbox[name="show_nb_hits"]');
        ajax_data['show_nb_hits'] = !!(nbHitsCheckbox && nbHitsCheckbox.getAttribute('data-selected') === '1');
        return ajax_data;
    }

    function fill_ajax_data_from_container(ajax_data: AjaxData, pop_in: Element): AjaxData {
        ajax_data = fill_ajax_data_from_properties(ajax_data, pop_in);
        ajax_data = fill_ajax_data_from_preferences(ajax_data, pop_in);
        return ajax_data;
    }

    /*---------------- Ajax Requests ----------------*/

    function get_first_selection_usernames(callback: () => void): void {
        const first_ids = selection.slice(0, 50).map((x) => x.id);
        const params = new URLSearchParams();
        params.append('display', 'username');
        params.append('order', 'id');
        first_ids.forEach(id => params.append('user_id[]', String(id)));
        params.append('exclude[]', String(guest_id));
        fetch('ws.php?format=json&method=pwg.users.getList', { method: 'POST', body: params })
            .then(response => response.json())
            .then((data: { result: { users: { id: number; username: string }[] } }) => {
                const result = data.result.users;
                for (let i = 0; i < result.length; i++) {
                    const index = selection.findIndex((x) => x.id === result[i]!.id);
                    if (index !== -1) selection[index]!.username = result[i]!.username;
                }
                callback();
            })
            .catch(err => console.error('Error getting selection usernames:', err));
    }

    function select_whole_set(): void {
        const statusSelect = document.querySelector<HTMLSelectElement>('.advanced-filter-select[name=filter_status]');
        const groupSelect = document.querySelector<HTMLSelectElement>('.advanced-filter-select[name=filter_group]');
        const levelSelect = document.querySelector<HTMLSelectElement>('.advanced-filter-select[name=filter_level]');
        let minRegister = 0, maxRegister = 0;
         
        const _datesSliderWS = document.querySelector<SliderEl>('.dates-select-bar .slider-bar-container');
        if (_datesSliderWS?.noUiSlider) {
            const _v = (Array.isArray(_datesSliderWS.noUiSlider.get())
                ? _datesSliderWS.noUiSlider.get()
                : [_datesSliderWS.noUiSlider.get()]) as string[];
            minRegister = parseInt(register_dates[Math.round(parseFloat(_v[0]!))]!);
            maxRegister = parseInt(register_dates[Math.round(parseFloat(_v[1] ?? _v[0]!))]!);
        }
        const params = new URLSearchParams();
        params.append('display', 'only_id');
        params.append('order', 'id');
        params.append('page', String(actual_page - 1));
        params.append('per_page', '0');
        params.append('exclude[]', String(guest_id));
        params.append('status', statusSelect ? statusSelect.value : '');
        params.append('group_id', groupSelect ? groupSelect.value : '');
        params.append('min_level', levelSelect ? levelSelect.value : '');
        params.append('max_level', levelSelect ? levelSelect.value : '');
        params.append('min_register', String(minRegister));
        params.append('max_register', String(maxRegister));
        const loadingEl = document.querySelector<HTMLElement>('#checkActions .loading');
        if (loadingEl) loadingEl.style.display = '';
        fetch('ws.php?format=json&method=pwg.users.getList', { method: 'POST', body: params })
            .then(response => response.json())
            .then((data: { result: number[] }) => {
                selection = data.result.map((x) => ({ id: x }));
                if (loadingEl) loadingEl.style.display = 'none';
                update_selection_content();
            })
            .catch(err => {
                console.error('Error selecting whole set:', err);
                if (loadingEl) loadingEl.style.display = 'none';
            });
    }

    function update_user_username(): void {
        const pop_in_container = document.querySelector<HTMLElement>('.UserListPopInContainer');
        if (!pop_in_container) return;
        const ajax_data: AjaxData = { pwg_token, user_id: last_user_id };
        const usernameInput = pop_in_container.querySelector<HTMLInputElement>('.user-property-input-username');
        ajax_data['username'] = usernameInput ? usernameInput.value : '';
        if (String(ajax_data['username']).replace(/\s/g, '').length === 0) {
            const failEl = document.querySelector<HTMLElement>('.update-user-fail');
            if (failEl) {
                failEl.innerHTML = fieldNotEmpty;
                failEl.style.display = '';
                setTimeout(() => { failEl.style.display = 'none'; }, 4000);
            }
            return;
        }
        const params = new URLSearchParams(ajax_data as Record<string, string>);
        fetch('ws.php?format=json&method=pwg.users.setInfo', { method: 'POST', body: params })
            .then(response => response.json())
            .then((data: { stat: string; result: { users: { username: string }[] } }) => {
                if (data.stat === 'ok') {
                    if (last_user_index !== -1) {
                        current_users[last_user_index]!.username = data.result.users[0]!.username;
                        const titleEl = document.querySelector<HTMLElement>('#UserList .user-property-username .edit-username-title');
                        if (titleEl) titleEl.innerHTML = current_users[last_user_index]!.username;
                        const initialsEl = document.querySelector<HTMLElement>('#UserList .user-property-initials span');
                        if (initialsEl) initialsEl.innerHTML = get_initials(current_users[last_user_index]!.username);
                        const container = document.querySelector<HTMLElement>('#user-table-content .user-container');
                        if (container) fill_container_user_info(container, last_user_index);
                    }
                    const successEl = document.querySelector<HTMLElement>('#UserList .update-user-success');
                    if (successEl) {
                        successEl.style.display = '';
                        setTimeout(() => { successEl.style.display = 'none'; }, 4000);
                    }
                    document.querySelectorAll<HTMLElement>('.user-property-username').forEach(el => { el.style.display = ''; });
                    document.querySelectorAll<HTMLElement>('.user-property-username-change').forEach(el => { el.style.display = 'none'; });
                }
            })
            .catch(err => console.error('Error updating username:', err));
    }

    function update_user_password(): void {
        const pop_in_container = document.querySelector<HTMLElement>('.UserListPopInContainer');
        if (!pop_in_container) return;
        const ajax_data: AjaxData = { pwg_token, user_id: last_user_id };
        const passwordInput = pop_in_container.querySelector<HTMLInputElement>('.user-property-input-password');
        ajax_data['password'] = passwordInput ? passwordInput.value : '';
        const params = new URLSearchParams(ajax_data as Record<string, string>);
        fetch('ws.php?format=json&method=pwg.users.setInfo', { method: 'POST', body: params })
            .then(response => response.json())
            .then((data: { stat: string }) => {
                if (data.stat === 'ok') {
                    const successEl = document.querySelector<HTMLElement>('#UserList .update-user-success');
                    if (successEl) {
                        successEl.style.display = '';
                        setTimeout(() => { successEl.style.display = 'none'; }, 4000);
                    }
                    document.querySelectorAll<HTMLElement>('.user-property-password').forEach(el => { el.style.display = ''; });
                    document.querySelectorAll<HTMLElement>('.user-property-password-change').forEach(el => { el.style.display = 'none'; });
                }
            })
            .catch(err => console.error('Error updating password:', err));
    }

    function update_user_info(): void {
        document.querySelectorAll<HTMLElement>('.update-user-button i').forEach(el => {
            el.classList.remove('icon-floppy');
            el.classList.add('icon-spin6', 'animate-spin');
        });
        document.querySelectorAll<HTMLElement>('.update-user-button').forEach(el => { el.classList.add('unclickable'); });
        const pop_in_container = document.querySelector<HTMLElement>('.UserListPopInContainer');
        if (!pop_in_container) return;
        let ajax_data: AjaxData = { pwg_token, user_id: last_user_id };
        ajax_data = fill_ajax_data_from_container(ajax_data, pop_in_container);
        document.querySelectorAll<HTMLElement>('#UserList .update-user-fail').forEach(el => { el.style.display = 'none'; });
        document.querySelectorAll<HTMLElement>('#UserList .update-user-success').forEach(el => { el.style.display = 'none'; });
        const params = new URLSearchParams(ajax_data as Record<string, string>);
        fetch('ws.php?format=json&method=pwg.users.setInfo', { method: 'POST', body: params })
            .then(response => response.json())
            .then((data: { stat: string; message?: string; result: { users: UserData[] } }) => {
                if (data.stat === 'ok') {
                    const result_user = data.result.users[0]!;
                    if (last_user_index !== -1) {
                        Object.assign(current_users[last_user_index]!, result_user);
                        const container = document.querySelector<HTMLElement>('#user-table-content .user-container');
                        if (container) fill_container_user_info(container, last_user_index);
                    }
                    const successEl = document.querySelector<HTMLElement>('#UserList .update-user-success');
                    if (successEl) {
                        successEl.style.display = '';
                        setTimeout(() => { successEl.style.display = 'none'; }, 4000);
                    }
                    document.querySelectorAll<HTMLElement>('.update-user-button i').forEach(el => {
                        el.classList.remove('icon-spin6', 'animate-spin');
                        el.classList.add('icon-floppy');
                    });
                    document.querySelectorAll<HTMLElement>('.update-user-button').forEach(el => { el.classList.remove('unclickable'); });
                } else if (data.stat === 'fail') {
                    const failEl = document.querySelector<HTMLElement>('#UserList .update-user-fail');
                    if (failEl) { failEl.innerHTML = data.message ?? ''; failEl.style.display = ''; }
                    document.querySelectorAll<HTMLElement>('.update-user-button i').forEach(el => {
                        el.classList.add('icon-floppy');
                        el.classList.remove('icon-spin6', 'animate-spin');
                    });
                    document.querySelectorAll<HTMLElement>('.update-user-button').forEach(el => { el.classList.remove('unclickable'); });
                    if (failEl) setTimeout(() => { failEl.style.display = 'none'; }, 5000);
                }
            })
            .catch(err => {
                console.error('Error updating user info:', err);
                document.querySelectorAll<HTMLElement>('.update-user-button i').forEach(el => {
                    el.classList.remove('icon-spin6', 'animate-spin');
                    el.classList.add('icon-floppy');
                });
                document.querySelectorAll<HTMLElement>('.update-user-button').forEach(el => { el.classList.remove('unclickable'); });
            });
    }

    function get_guest_info(): void {
        const params = new URLSearchParams({ display: 'all', user_id: String(guest_id) });
        fetch('ws.php?format=json&method=pwg.users.getList', { method: 'POST', body: params })
            .then(response => response.json())
            .then((data: { stat: string; result: { users: UserData[] } }) => {
                if (data.stat === 'ok') {
                    guest_user = data.result.users[0]!;
                    fill_guest_edit();
                }
            })
            .catch(err => console.error('Error getting guest info:', err));
    }

    function get_user_info(uid: number, callback: (() => void) | null = null): void {
        const params = new URLSearchParams({ display: 'all', user_id: String(uid) });
        fetch('ws.php?format=json&method=pwg.users.getList', { method: 'POST', body: params })
            .then(response => response.json())
            .then((data: { stat: string; result: { users: UserData[] } }) => {
                if (data.stat === 'ok') {
                    fill_user_edit(data.result.users[0]!);
                    if (callback) callback();
                }
            })
            .catch(err => console.error('Error getting user info:', err));
    }

    function update_guest_info(): void {
        document.querySelectorAll<HTMLElement>('.update-user-button i').forEach(el => {
            el.classList.remove('icon-floppy');
            el.classList.add('icon-spin6', 'animate-spin');
        });
        document.querySelectorAll<HTMLElement>('.update-user-button').forEach(el => { el.classList.add('unclickable'); });
        const pop_in_container = document.querySelector<HTMLElement>('.GuestUserListPopInContainer');
        if (!pop_in_container) return;
        let ajax_data: AjaxData = { pwg_token, user_id: guest_id };
        ajax_data = fill_ajax_data_from_container(ajax_data, pop_in_container);
        delete ajax_data['email'];
        delete ajax_data['status'];
        const params = new URLSearchParams(ajax_data as Record<string, string>);
        fetch('ws.php?format=json&method=pwg.users.setInfo', { method: 'POST', body: params })
            .then(response => response.json())
            .then((data: { stat: string }) => {
                if (data.stat === 'ok') {
                    const successEl = document.querySelector<HTMLElement>('#GuestUserList .update-user-success');
                    if (successEl) {
                        successEl.style.display = '';
                        setTimeout(() => { successEl.style.display = 'none'; }, 4000);
                    }
                }
                document.querySelectorAll<HTMLElement>('.update-user-button i').forEach(el => {
                    el.classList.remove('icon-spin6', 'animate-spin');
                    el.classList.add('icon-floppy');
                });
                document.querySelectorAll<HTMLElement>('.update-user-button').forEach(el => { el.classList.remove('unclickable'); });
            })
            .catch(err => {
                console.error('Error updating guest info:', err);
                document.querySelectorAll<HTMLElement>('.update-user-button i').forEach(el => {
                    el.classList.remove('icon-spin6', 'animate-spin');
                    el.classList.add('icon-floppy');
                });
                document.querySelectorAll<HTMLElement>('.update-user-button').forEach(el => { el.classList.remove('unclickable'); });
            });
    }

    function update_user_list(): void {
        const update_data: Record<string, string | number> = {
            display: 'all',
            order: 'id DESC',
            page: actual_page - 1,
            per_page: per_page,
        };
        const userSearch = document.getElementById('user_search') as HTMLInputElement | null;
        if (userSearch && userSearch.value.length !== 0) update_data['filter'] = userSearch.value;
        const advancedFilter = document.querySelector('.advanced-filter');
        if (advancedFilter && advancedFilter.classList.contains('advanced-filter-open')) {
            const statusSelect = document.querySelector<HTMLSelectElement>('.advanced-filter-select[name=filter_status]');
            const groupSelect = document.querySelector<HTMLSelectElement>('.advanced-filter-select[name=filter_group]');
            const levelSelect = document.querySelector<HTMLSelectElement>('.advanced-filter-select[name=filter_level]');
            update_data['status'] = statusSelect ? statusSelect.value : '';
            update_data['group_id'] = groupSelect ? groupSelect.value : '';
            update_data['min_level'] = levelSelect ? levelSelect.value : '';
            update_data['max_level'] = levelSelect ? levelSelect.value : '';
            let minRegister = 0, maxRegister = 0;
             
            const _datesSliderUL = document.querySelector<SliderEl>('.dates-select-bar .slider-bar-container');
            if (_datesSliderUL?.noUiSlider) {
                const _v = (Array.isArray(_datesSliderUL.noUiSlider.get())
                    ? _datesSliderUL.noUiSlider.get()
                    : [_datesSliderUL.noUiSlider.get()]) as string[];
                minRegister = parseInt(register_dates[Math.round(parseFloat(_v[0]!))]!);
                maxRegister = parseInt(register_dates[Math.round(parseFloat(_v[1] ?? _v[0]!))]!);
            }
            update_data['min_register'] = minRegister;
            update_data['max_register'] = maxRegister;
        }
        const spinnerEl = document.querySelector<HTMLElement>('.user-update-spinner');
        if (spinnerEl) spinnerEl.style.display = '';
        const params = new URLSearchParams(update_data as Record<string, string>);
        params.append('exclude[]', String(guest_id));
        fetch('ws.php?format=json&method=pwg.users.getList', { method: 'POST', body: params })
            .then(response => response.json())
            .then((data: { stat: string; message?: string; result: { total_count: number; users: UserData[] } }) => {
                if (data.stat === 'fail') {
                    console.log(data.message);
                    if (spinnerEl) spinnerEl.style.display = 'none';
                    return;
                }
                total_users = data.result.total_count;
                if (first_update) {
                    const h1 = document.querySelector<HTMLElement>('h1');
                    if (h1) {
                        h1.appendChild(Object.assign(document.createElement('span'), {
                            className: 'badge-number',
                            innerHTML: String(total_users),
                        }));
                    }
                    first_update = false;
                }
                nb_filtered_users = data.result.total_count;
                update_pagination_menu();
                current_users = data.result.users;
                generate_user_list();
                document.querySelectorAll<HTMLElement>('.user-col.user-first-col.user-container-edit').forEach(el => {
                    el.addEventListener('click', function (this: HTMLElement) {
                        const uid_index = parseInt(this.closest<HTMLElement>('.user-container')?.getAttribute('key') ?? '0');
                        last_user_id = current_users[uid_index]!.id;
                        last_user_index = uid_index;
                        fill_user_edit(current_users[uid_index]!);
                        const userListEl = document.getElementById('UserList');
                        if (userListEl) userListEl.style.display = 'flex';
                    });
                });
                set_selected_to_selection();
                if (spinnerEl) spinnerEl.style.display = 'none';
                let nb_filters = 0;
                const statusVal = document.querySelector<HTMLSelectElement>('.advanced-filter-select[name=filter_status]');
                if (statusVal && statusVal.value !== '') nb_filters += 1;
                const groupVal = document.querySelector<HTMLSelectElement>('.advanced-filter-select[name=filter_group]');
                if (groupVal && groupVal.value !== '') nb_filters += 1;
                const levelVal = document.querySelector<HTMLSelectElement>('.advanced-filter-select[name=filter_level]');
                if (levelVal && levelVal.value !== '') nb_filters += 1;
                 
                const _datesSliderF = document.querySelector<SliderEl>('.dates-select-bar .slider-bar-container');
                if (_datesSliderF?.noUiSlider) {
                    const _v = (Array.isArray(_datesSliderF.noUiSlider.get())
                        ? _datesSliderF.noUiSlider.get()
                        : [_datesSliderF.noUiSlider.get()]) as string[];
                    if (Math.round(parseFloat(_v[0]!)) !== 0) nb_filters += 1;
                    if (Math.round(parseFloat(_v[1] ?? _v[0]!)) !== register_dates.length - 1) nb_filters += 1;
                }
                show_filter_infos(nb_filters);
            })
            .catch(err => {
                console.error('Error updating user list:', err);
                if (spinnerEl) spinnerEl.style.display = 'none';
            });
    }

    function add_user(): void {
        const usernameInput = document.querySelector<HTMLInputElement>('.AddUserLabelUsername .user-property-input');
        const usernameVal = usernameInput ? usernameInput.value : '';
        const addUserErrors = document.querySelector<HTMLElement>('#AddUser .AddUserErrors');
        if (addUserErrors) addUserErrors.style.visibility = 'hidden';
        if (usernameVal === '') {
            if (addUserErrors) {
                addUserErrors.innerHTML = missingUsername;
                addUserErrors.style.visibility = 'visible';
            }
            return;
        }
        const ajax_data: AjaxData = { pwg_token, username: usernameVal };
        const passwordInput = document.getElementById('AddUserPassword') as HTMLInputElement | null;
        ajax_data['password'] = passwordInput ? passwordInput.value : '';
        const emailInput = document.querySelector<HTMLInputElement>('.AddUserLabelEmail .user-property-input');
        ajax_data['email'] = emailInput ? emailInput.value : '';
        const sendByEmailCheckbox = document.querySelector<HTMLElement>('.user-list-checkbox[name="send_by_email"]');
        ajax_data['send_password_by_mail'] = !!(sendByEmailCheckbox && sendByEmailCheckbox.getAttribute('data-selected') === '1');
        const params = new URLSearchParams(ajax_data as Record<string, string>);
        fetch('ws.php?format=json&method=pwg.users.add', { method: 'POST', body: params })
            .then(response => response.json())
            .then((data: { stat: string; message?: string; result: { users: { id: number }[] } }) => {
                if (data.stat === 'ok') {
                    const new_user_id = data.result.users[0]!.id;
                    update_user_list();
                    add_user_close();
                    document.querySelectorAll<HTMLInputElement>('#AddUser .user-property-input').forEach(el => { el.value = ''; });
                    const editNowBtn = document.querySelector<HTMLElement>('#AddUserSuccess .edit-now');
                    if (editNowBtn) {
                        editNowBtn.addEventListener('click', () => {
                            last_user_id = new_user_id;
                            last_user_index = get_container_index_from_uid(new_user_id);
                            if (last_user_index !== -1) {
                                fill_user_edit(current_users[last_user_index]!);
                                open_user_list();
                            } else {
                                get_user_info(new_user_id, open_user_list);
                            }
                        });
                    }
                    const successLabel = document.querySelector<HTMLElement>('#AddUserSuccess label span:first-child');
                    if (successLabel) successLabel.innerHTML = user_added_str.replace('%s', usernameVal);
                    const addUserSuccess = document.getElementById('AddUserSuccess');
                    if (addUserSuccess) addUserSuccess.style.display = 'flex';
                } else {
                    if (addUserErrors) {
                        addUserErrors.innerHTML = data.message ?? '';
                        addUserErrors.style.visibility = 'visible';
                    }
                }
            })
            .catch(err => console.error('Error adding user:', err));
    }

    function delete_user(uid: number): void {
        const params = new URLSearchParams({ user_id: String(uid), pwg_token });
        fetch('ws.php?format=json&method=pwg.users.delete', { method: 'POST', body: params })
            .then(response => response.json())
            .then(() => { close_user_list(); update_user_list(); })
            .catch(err => console.error('Error deleting user:', err));
    }

    function show_filter_infos(nb_filters: number): void {
        const userSearch = document.getElementById('user_search') as HTMLInputElement | null;
        if ((userSearch && userSearch.value.length !== 0) || nb_filters !== 0) {
            const message = total_users !== 1
                ? filtered_users.replace(/%d/g, String(total_users))
                : filtered_user.replace(/%d/g, String(total_users));
            document.querySelectorAll<HTMLElement>('.filtered-users').forEach(el => { el.innerHTML = message; });
        } else {
            document.querySelectorAll<HTMLElement>('.filtered-users').forEach(el => { el.innerHTML = ''; });
        }
        if (nb_filters !== 0) {
            document.querySelectorAll<HTMLElement>('.advanced-filter-btn').forEach(el => { el.style.width = '80px'; });
            document.querySelectorAll<HTMLElement>('.filter-counter').forEach(el => {
                el.innerHTML = String(nb_filters);
                el.style.display = 'flex';
            });
        } else {
            document.querySelectorAll<HTMLElement>('.advanced-filter-btn').forEach(el => { el.style.width = '70px'; });
            document.querySelectorAll<HTMLElement>('.filter-counter').forEach(el => {
                el.innerHTML = '0';
                el.style.display = 'none';
            });
        }
        // Startup
        setupRegisterDates(register_dates);
        selectionMode(false);
        get_guest_info();
        update_user_list();
        update_selection_content();

        tippy('.icon-help-circled', { maxWidth: 700, delay: 0, placement: 'top' });

        if (connected_user_status !== 'webmaster') {
            document.querySelectorAll<HTMLElement>('select[name="status"] option[value="webmaster"], select[name="status"] option[value="admin"]').forEach(function (el) {
                el.setAttribute('disabled', 'true');
            });
        }

        const applyActionBtn = document.getElementById('applyAction');
        if (applyActionBtn) {
            applyActionBtn.addEventListener('click', function (event) {
                event.preventDefault();
                const selectActionEl = document.querySelector<HTMLSelectElement>('select[name=selectAction]');
                const action = selectActionEl ? selectActionEl.value : '';
                let method = 'pwg.users.setInfo';
                const data: Record<string, unknown> = {
                    pwg_token,
                    user_id: selection.map(x => x.id),
                };
                switch (action) {
                    case 'delete':
                        if (!(document.querySelector<HTMLElement>('#permitActionUserList .user-list-checkbox[name=confirm_deletion]')
                            ?.getAttribute('data-selected') === '1')) {
                            alert(missingConfirm);
                            return;
                        }
                        method = 'pwg.users.delete';
                        break;
                    case 'group_associate':
                        method = 'pwg.groups.addUser';
                        data['group_id'] = document.querySelector<HTMLSelectElement>('#permitActionUserList select[name=associate]')?.value;
                        break;
                    case 'group_dissociate':
                        method = 'pwg.groups.deleteUser';
                        data['group_id'] = document.querySelector<HTMLSelectElement>('#permitActionUserList select[name=dissociate]')?.value;
                        break;
                    case 'status':
                        data['status'] = document.querySelector<HTMLSelectElement>('#permitActionUserList select[name=status]')?.value;
                        break;
                    case 'enabled_high':
                        data['enabled_high'] = document.querySelector<HTMLElement>('#permitActionUserList .user-list-checkbox[name=enabled_high_yes]')
                            ?.getAttribute('data-selected') === '1';
                        break;
                    case 'level':
                        data['level'] = document.querySelector<HTMLSelectElement>('#permitActionUserList select[name=level]')?.value;
                        break;
                    case 'nb_image_page':
                        data['nb_image_page'] = document.querySelector<HTMLInputElement>('#permitActionUserList input[name=nb_image_page]')?.value;
                        break;
                    case 'theme':
                        data['theme'] = document.querySelector<HTMLSelectElement>('#permitActionUserList select[name=theme]')?.value;
                        break;
                    case 'language':
                        data['language'] = document.querySelector<HTMLSelectElement>('#permitActionUserList select[name=language]')?.value;
                        break;
                    case 'recent_period': {
                         
                        const _ps = document.querySelector<SliderEl>('#permitActionUserList .period-select-bar .slider-bar-container');
                        data['recent_period'] = recent_period_values[_ps?.noUiSlider ? Math.round(parseFloat(String(_ps.noUiSlider.get() as string | number))) : 0];
                        break;
                    }
                    case 'expand':
                        data['expand'] = document.querySelector<HTMLElement>('#permitActionUserList .user-list-checkbox[name=expand_yes]')
                            ?.getAttribute('data-selected') === '1';
                        break;
                    case 'show_nb_comments':
                        data['show_nb_comments'] = document.querySelector<HTMLElement>('#permitActionUserList .user-list-checkbox[name=show_nb_comments_yes]')
                            ?.getAttribute('data-selected') === '1';
                        break;
                    case 'show_nb_hits':
                        data['show_nb_hits'] = document.querySelector<HTMLElement>('#permitActionUserList .user-list-checkbox[name=show_nb_hits_yes]')
                            ?.getAttribute('data-selected') === '1';
                        break;
                    default:
                        alert('Unexpected action');
                        return;
                }
                const loadingEl = document.getElementById('applyActionLoading');
                const infosEl = document.querySelector<HTMLElement>('#applyActionBlock .infos');
                if (loadingEl) loadingEl.style.display = '';
                if (infosEl) infosEl.style.display = 'none';
                const params = new URLSearchParams();
                Object.keys(data).forEach(function (k) {
                    const v = data[k];
                    if (Array.isArray(v)) {
                        (v as (string | number)[]).forEach(function (item) { params.append(k + '[]', String(item)); });
                    } else {
                        params.append(k, String(v));
                    }
                });
                fetch('ws.php?format=json&method=' + method, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params,
                })
                    .then(function (r) { return r.json(); })
                    .then(function () {
                        if (loadingEl) loadingEl.style.display = 'none';
                        if (infosEl) infosEl.style.display = 'inline-block';
                        update_user_list();
                        if (action === 'delete') {
                            selection = [];
                            update_selection_content();
                        }
                    })
                    .catch(function () {
                        if (loadingEl) loadingEl.style.display = 'none';
                    });
            });
        }
    }
}

initModule(init as unknown as (cfg: Record<string, unknown>) => void);
