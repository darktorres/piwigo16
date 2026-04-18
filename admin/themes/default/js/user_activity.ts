import { UsersCache } from './LocalStorageCache.js';
import { initModule } from './moduleInit.js';
import TomSelect from 'tom-select';

interface ActionTypes { add?: string; delete?: string; move?: string; edit?: string; login?: string; logout?: string }
interface ActionInfoEntry { added?: string; deleted?: string; edited?: string; moved?: string; loggedIn?: string; loggedOut?: string; addedPlural?: string; deletedPlural?: string; editedPlural?: string; movedPlural?: string; loggedInPlural?: string; loggedOutPlural?: string }
interface ActionInfos { album?: ActionInfoEntry; user?: ActionInfoEntry; photo?: ActionInfoEntry; group?: ActionInfoEntry; tag?: ActionInfoEntry }

interface UserActivityConfig {
    usersServerKey?: string;
    usersServerId?: string;
    rootUrl?: string;
    usersKey?: string;
    nbUsers?: number;
    actionTypes?: ActionTypes;
    actionInfos?: ActionInfos;
}

interface ActivityLine {
    id: string;
    action: string;
    object: string;
    counter: number;
    user_id: number;
    object_id: number[];
    username: string;
    ip_address: string;
    date: string;
    hour: string;
    detailsType: string;
    details: { script?: string; method?: string; agent?: string; users_string?: string };
}

export function init(cfg: UserActivityConfig): void {
    const {
        usersServerKey = '', usersServerId = '', rootUrl = '',
        usersKey = 'Users', actionTypes = {}, actionInfos = {},
    } = cfg;

    const usersCache = new UsersCache({ serverKey: usersServerKey, serverId: usersServerId, rootUrl });
    void usersCache;

    const color_icons = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'];
    let activity_page = 1;
    let actual_page = 1;
    let max_page = 1;
    let uid_filter: string | undefined;
    const page_ellipsis = '<span>...</span>';
    const page_item = '<a data-page="%d">%d</a>';

    const ai = actionInfos;
    const at = actionTypes;

    get_user_activity(activity_page, uid_filter);

    function get_user_activity(page: number, uid?: string): void {
        const tab = document.querySelector<HTMLElement>('.tab');
        if (tab) {
            Array.from(tab.children).forEach(function (child) {
                if (child.id !== '-1' && !child.classList.contains('loading')) child.remove();
            });
        }
        document.querySelectorAll<HTMLElement>('.loading').forEach(el => { el.style.display = ''; });
        document.querySelectorAll<HTMLElement>('.pagination-arrow.right').forEach(el => el.classList.add('unavailable'));
        document.querySelectorAll<HTMLElement>('.pagination-arrow.left').forEach(el => el.classList.add('unavailable'));
        document.querySelectorAll<HTMLElement>('.pagination-item-container').forEach(el => { el.style.display = 'none'; });
        document.querySelectorAll<HTMLElement>('.user-update-spinner').forEach(el => el.classList.add('icon-spin6'));

        fetch('ws.php?format=json&method=pwg.activity.getList', {
            method: 'POST',
            body: new URLSearchParams({ page: String(page - 1), uid: uid ?? '' }),
        })
            .then(r => r.json())
            .then(function (data: { result: { result_lines: ActivityLine[]; max_page: number } }) {
                uid_filter = uid;
                const lines = data.result.result_lines;
                setCreationDate(lines[lines.length - 1].date, lines[0].date);
                document.querySelectorAll<HTMLElement>('.loading').forEach(el => { el.style.display = 'none'; });
                lines.forEach(line => lineConstructor(line));
                max_page = data.result.max_page;
                document.querySelectorAll<HTMLElement>('.user-update-spinner').forEach(el => el.classList.remove('icon-spin6'));
                document.querySelectorAll<HTMLElement>('.pagination-item-container').forEach(el => { el.style.display = ''; });
                update_pagination_menu();
            })
            .catch(function (e: unknown) { console.log('ajax call failed', e); });
    }

    function lineConstructor(line: ActivityLine): void {
        const template = document.getElementById('-1');
        if (!template) return;
        const newLine = template.cloneNode(true) as HTMLElement;
        newLine.classList.remove('hide');
        newLine.id = line.id;

        let final_albumInfos = '';
        const isPlural = line.counter > 1;
        const obj = line.object;

        function getInfo(single: string | undefined, plural: string | undefined): string {
            return (isPlural ? plural : single) ?? String(line.counter) + ' ' + obj + ' ' + line.action;
        }

        switch (line.action) {
            case 'edit':
                newLine.querySelector('.action-type')?.classList.add('icon-blue');
                newLine.querySelector('.user-pic')?.classList.add(color_icons[line.user_id % 5]);
                newLine.querySelector('.action-icon')?.classList.add('icon-pencil');
                (newLine.querySelector('.action-name') as HTMLElement).innerHTML = at.edit ?? 'edit';
                final_albumInfos = getInfo(ai[obj as keyof ActionInfos]?.edited, ai[obj as keyof ActionInfos]?.editedPlural);
                newLine.querySelector('.action-section')?.classList.add(getObjIcon(obj));
                break;
            case 'add':
                newLine.querySelector('.action-type')?.classList.add('icon-green');
                newLine.querySelector('.user-pic')?.classList.add(color_icons[line.user_id % 5]);
                newLine.querySelector('.action-icon')?.classList.add('icon-plus');
                (newLine.querySelector('.action-name') as HTMLElement).innerHTML = at.add ?? 'add';
                final_albumInfos = getInfo(ai[obj as keyof ActionInfos]?.added, ai[obj as keyof ActionInfos]?.addedPlural);
                newLine.querySelector('.action-section')?.classList.add(getObjIcon(obj));
                break;
            case 'delete':
                newLine.querySelector('.action-type')?.classList.add('icon-red');
                newLine.querySelector('.user-pic')?.classList.add(color_icons[line.user_id % 5]);
                newLine.querySelector('.action-icon')?.classList.add('icon-trash-1');
                (newLine.querySelector('.action-name') as HTMLElement).innerHTML = at.delete ?? 'delete';
                final_albumInfos = getInfo(ai[obj as keyof ActionInfos]?.deleted, ai[obj as keyof ActionInfos]?.deletedPlural);
                newLine.querySelector('.action-section')?.classList.add(getObjIcon(obj));
                break;
            case 'move':
                newLine.querySelector('.action-type')?.classList.add('icon-yellow');
                newLine.querySelector('.user-pic')?.classList.add(color_icons[line.user_id % 5]);
                newLine.querySelector('.action-icon')?.classList.add('icon-move');
                (newLine.querySelector('.action-name') as HTMLElement).innerHTML = at.move ?? 'move';
                final_albumInfos = getInfo(ai[obj as keyof ActionInfos]?.moved, ai[obj as keyof ActionInfos]?.movedPlural);
                newLine.querySelector('.action-section')?.classList.add(getObjIcon(obj));
                break;
            case 'login':
                newLine.querySelector('.action-type')?.classList.add('icon-purple');
                newLine.querySelector('.user-pic')?.classList.add(color_icons[line.user_id % 5]);
                newLine.querySelector('.action-icon')?.classList.add('icon-key');
                newLine.querySelector('.action-section')?.classList.add('icon-user-1');
                (newLine.querySelector('.action-name') as HTMLElement).innerHTML = at.login ?? 'login';
                final_albumInfos = (isPlural ? ai.user?.loggedInPlural : ai.user?.loggedIn) ?? '';
                if (isPlural) final_albumInfos = final_albumInfos.replace('%d', String(line.counter));
                break;
            case 'logout':
                newLine.querySelector('.action-type')?.classList.add('icon-purple');
                newLine.querySelector('.user-pic')?.classList.add(color_icons[line.user_id !== 2 ? line.user_id % 5 : line.object_id[0] % 5]);
                newLine.querySelector('.action-icon')?.classList.add('icon-logout');
                newLine.querySelector('.action-section')?.classList.add('icon-user-1');
                (newLine.querySelector('.action-name') as HTMLElement).innerHTML = at.logout ?? 'logout';
                final_albumInfos = (isPlural ? ai.user?.loggedOutPlural : ai.user?.loggedOut) ?? '';
                if (isPlural) final_albumInfos = final_albumInfos.replace('%d', String(line.counter));
                break;
            default:
                newLine.querySelector('.action-type')?.classList.add('icon-purple');
                newLine.querySelector('.user-pic')?.classList.add(color_icons[line.user_id % 5]);
        }

        if (final_albumInfos) final_albumInfos = final_albumInfos.replace('%d', String(line.counter));

        (newLine.querySelector('.action-infos-test') as HTMLElement).innerHTML = final_albumInfos;
        (newLine.querySelector('.nb_items') as HTMLElement).innerHTML = String(line.counter);
        (newLine.querySelector('.date-day') as HTMLElement).innerHTML = line.date;
        (newLine.querySelector('.date-hour') as HTMLElement).innerHTML = line.hour;
        (newLine.querySelector('.user-name') as HTMLElement).innerHTML = line.username;
        (newLine.querySelector('.user-pic') as HTMLElement).innerHTML = get_initials(line.username);
        (newLine.querySelector('.detail-item-1') as HTMLElement).innerHTML = line.ip_address;
        newLine.querySelector('.detail-item-1')?.setAttribute('title', 'IP');

        if (line.detailsType === 'script') {
            (newLine.querySelector('.detail-item-2') as HTMLElement).innerHTML = line.details.script ?? '';
            newLine.querySelector('.detail-item-2')?.setAttribute('title', 'Script');
        } else if (line.detailsType === 'method') {
            (newLine.querySelector('.detail-item-2') as HTMLElement).innerHTML = line.details.method ?? '';
            newLine.querySelector('.detail-item-2')?.setAttribute('title', 'API Method');
        }

        if (line.details.agent) {
            (newLine.querySelector('.detail-item-3') as HTMLElement).innerHTML = line.details.agent;
            newLine.querySelector('.detail-item-3')?.setAttribute('title', line.details.agent);
        } else if (line.details.users_string && line.action !== 'logout' && line.action !== 'login') {
            (newLine.querySelector('.detail-item-3') as HTMLElement).innerHTML = line.details.users_string;
            newLine.querySelector('.detail-item-3')?.setAttribute('title', usersKey + ': ' + line.details.users_string);
        } else {
            newLine.querySelector('.detail-item-3')?.remove();
        }

        newLine.classList.add('uid-' + line.user_id);
        document.querySelector<HTMLElement>('.tab')?.appendChild(newLine);
    }

    function getObjIcon(obj: string): string {
        switch (obj) {
            case 'user': return 'icon-user-1';
            case 'album': return 'icon-folder-open';
            case 'group': return 'icon-users-1';
            case 'photo': return 'icon-picture';
            case 'tag': return 'icon-tags';
            default: return '';
        }
    }

    function get_initials(username: string): string {
        const words = username.toUpperCase().split(' ');
        let res = words[0][0] ?? '';
        if (words.length > 1 && words[1][0] !== undefined) res += words[1][0];
        return res;
    }

    function setCreationDate(startDate: string, endDate: string): void {
        document.querySelectorAll<HTMLElement>('.start-date').forEach(el => { el.innerHTML = startDate; });
        document.querySelectorAll<HTMLElement>('.end-date').forEach(el => { el.innerHTML = endDate; });
    }

    function move_to_page(page: number): void {
        if (page < 1 || page > max_page) return;
        actual_page = page;
        update_pagination_menu();
        get_user_activity(page, uid_filter);
    }

    document.querySelectorAll<HTMLElement>('.pagination-arrow.right').forEach(el => {
        el.addEventListener('click', () => move_to_page(actual_page + 1));
    });
    document.querySelectorAll<HTMLElement>('.pagination-arrow.left').forEach(el => {
        el.addEventListener('click', () => move_to_page(actual_page - 1));
    });

    function update_pagination_menu(): void {
        updateArrows();
        update_pagination_items();
        document.querySelectorAll<HTMLElement>('.pagination-container').forEach(el => {
            el.style.display = max_page <= 1 ? 'none' : '';
        });
    }

    function updateArrows(): void {
        document.querySelectorAll<HTMLElement>('.pagination-arrow.left').forEach(el => {
            el.classList.toggle('unavailable', actual_page === 1);
        });
        document.querySelectorAll<HTMLElement>('.pagination-arrow.right').forEach(el => {
            el.classList.toggle('unavailable', actual_page === max_page);
        });
    }

    function update_pagination_items(): void {
        document.querySelectorAll<HTMLElement>('.pagination-item-container a, .pagination-item-container span').forEach(el => el.remove());
        append_pagination_item(1);
        if (actual_page > 2) append_pagination_item(null);
        if (actual_page !== 1 && actual_page !== max_page) append_pagination_item(actual_page);
        if (actual_page < (max_page - 1)) append_pagination_item(null);
        append_pagination_item(max_page);
    }

    function append_pagination_item(page: number | null): void {
        const container = document.querySelector<HTMLElement>('.pagination-item-container');
        if (!container) return;
        if (page !== null) {
            container.insertAdjacentHTML('beforeend', page_item.replace(/%d/g, String(page)));
            const new_tag = container.lastElementChild as HTMLElement;
            if (actual_page === page) new_tag.classList.add('actual');
            new_tag.addEventListener('click', function () {
                move_to_page(parseInt(new_tag.dataset.page ?? '1'));
            });
        } else {
            container.insertAdjacentHTML('beforeend', page_ellipsis);
        }
    }

    const h1El = document.querySelector('h1');
    if (h1El) h1El.insertAdjacentHTML('beforeend', "<span class='badge-number'>" + ((window as unknown as Record<string, number>).nbUsers - 1) + '</span>');

    const userSelector = document.querySelector<HTMLSelectElement>('.user-selector');
    if (userSelector) {
        const ts = new TomSelect(userSelector, {});
        ts.setValue(null as unknown as string);
    }

    document.querySelectorAll<HTMLSelectElement>('select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            const tsControl = document.querySelector<HTMLElement>('.ts-control');
            if (tsControl?.classList.contains('full')) {
                const item = document.querySelector<HTMLElement>('.ts-control .item');
                get_user_activity(1, item?.dataset.value);
            }
        });
    });

    document.querySelectorAll<HTMLElement>('.cancel-icon').forEach(function (el) {
        el.addEventListener('click', function () {
            const userSel = document.querySelector<HTMLSelectElement & { tomselect?: { clear(silent?: boolean): void } }>('.user-selector');
            if (userSel?.tomselect) userSel.tomselect.clear(true);
            document.querySelectorAll<HTMLElement>('.line').forEach(l => { l.style.display = 'flex'; });
        });
    });

    void activity_page;
}

initModule(init as (cfg: Record<string, unknown>) => void);
