import TomSelect from 'tom-select';
import { getPageData } from './page-data';
import { UsersCache } from './LocalStorageCache';
import { config } from './config';

interface UserActivityPageData {
    nb_users: number;
    additional_filt_type: string | null;
    additional_filt_value: number | null;
    date_min: string;
    date_max: string;
    color_icons: string[];
    page_ellipsis: string;
    page_item: string;
    users_key: string;
    line_key: string;
    lines_key: string;
    actionType_add: string;
    actionType_delete: string;
    actionType_move: string;
    actionType_edit: string;
    actionType_login: string;
    actionType_logout: string;
    actionInfos_album_added: string;
    actionInfos_album_deleted: string;
    actionInfos_album_edited: string;
    actionInfos_album_moved: string;
    actionInfos_albums_added: string;
    actionInfos_albums_deleted: string;
    actionInfos_albums_edited: string;
    actionInfos_albums_moved: string;
    actionInfos_user_added: string;
    actionInfos_user_deleted: string;
    actionInfos_user_edited: string;
    actionInfos_user_logged_in: string;
    actionInfos_user_logged_out: string;
    actionInfos_users_added: string;
    actionInfos_users_deleted: string;
    actionInfos_users_edited: string;
    actionInfos_users_logged_in: string;
    actionInfos_users_logged_out: string;
    actionInfos_photo_added: string;
    actionInfos_photo_deleted: string;
    actionInfos_photo_edited: string;
    actionInfos_photo_moved: string;
    actionInfos_photos_added: string;
    actionInfos_photos_deleted: string;
    actionInfos_photos_edited: string;
    actionInfos_photos_moved: string;
    actionInfos_group_added: string;
    actionInfos_group_deleted: string;
    actionInfos_group_edited: string;
    actionInfos_group_moved: string;
    actionInfos_groups_added: string;
    actionInfos_groups_deleted: string;
    actionInfos_groups_edited: string;
    actionInfos_groups_moved: string;
    actionInfos_tag_added: string;
    actionInfos_tag_deleted: string;
    actionInfos_tag_edited: string;
    actionInfos_tag_moved: string;
    actionInfos_tags_added: string;
    actionInfos_tags_deleted: string;
    actionInfos_tags_edited: string;
    actionInfos_tags_moved: string;
}

const {
    nb_users,
    additional_filt_type,
    additional_filt_value,
    date_min,
    date_max,
    color_icons,
    page_ellipsis,
    page_item,
    users_key,
    actionType_add,
    actionType_delete,
    actionType_move,
    actionType_edit,
    actionType_login,
    actionType_logout,
    actionInfos_album_added,
    actionInfos_album_deleted,
    actionInfos_album_edited,
    actionInfos_album_moved,
    actionInfos_albums_added,
    actionInfos_albums_deleted,
    actionInfos_albums_edited,
    actionInfos_albums_moved,
    actionInfos_user_added,
    actionInfos_user_deleted,
    actionInfos_user_edited,
    actionInfos_user_logged_in,
    actionInfos_user_logged_out,
    actionInfos_users_added,
    actionInfos_users_deleted,
    actionInfos_users_edited,
    actionInfos_users_logged_in,
    actionInfos_users_logged_out,
    actionInfos_photo_added,
    actionInfos_photo_deleted,
    actionInfos_photo_edited,
    actionInfos_photo_moved,
    actionInfos_photos_added,
    actionInfos_photos_deleted,
    actionInfos_photos_edited,
    actionInfos_photos_moved,
    actionInfos_group_added,
    actionInfos_group_deleted,
    actionInfos_group_edited,
    actionInfos_group_moved,
    actionInfos_groups_added,
    actionInfos_groups_deleted,
    actionInfos_groups_edited,
    actionInfos_groups_moved,
    actionInfos_tag_added,
    actionInfos_tag_deleted,
    actionInfos_tag_edited,
    actionInfos_tag_moved,
    actionInfos_tags_added,
    actionInfos_tags_deleted,
    actionInfos_tags_edited,
    actionInfos_tags_moved,
} = getPageData<UserActivityPageData>();

interface UserActivityCacheData {
    CACHE_KEYS: { users: string; _hash: string };
    ROOT_URL: string;
    str_create: string;
}

const cacheData = getPageData<UserActivityCacheData>('pwg-user-activity-data');

// Initialize UsersCache
const usersCache = new UsersCache({
    serverKey: cacheData.CACHE_KEYS.users,
    serverId: cacheData.CACHE_KEYS._hash,
    rootUrl: cacheData.ROOT_URL,
});

// Mutable state
let action: string | null = null;
let activity_page = 1;
let current_page_offset = 0;
let page_offsets: number[] = [0];
let actual_page = 1;
let end_page = false;
let uid_filter: string | undefined;
let action_filter: string | undefined;
let object_filter: string | undefined;
let date_min_filter: string = date_min;
let date_max_filter: string = date_max;

if (additional_filt_type) object_filter = additional_filt_type;

get_user_activity(
    activity_page,
    uid_filter,
    action_filter,
    object_filter,
    [date_min_filter, date_max_filter],
    additional_filt_value
);

function get_user_activity(page: any, uid: any, act: any, obj: any, date: any, id: any) {
    // beforeSend equivalent
    document
        .querySelectorAll<HTMLElement>('.tab > *:not([id="-1"]):not(.loading)')
        .forEach((el) => el.remove());
    document.querySelectorAll<HTMLElement>('.loading').forEach((el) => {
        el.style.display = '';
    });
    document.querySelector('.pagination-arrow.rigth')?.classList.add('unavailable');
    document.querySelector('.pagination-arrow.left')?.classList.add('unavailable');
    document.querySelectorAll<HTMLElement>('.pagination-item-container').forEach((el) => {
        el.style.display = 'none';
    });
    document
        .querySelectorAll<HTMLElement>('.user-update-spinner')
        .forEach((el) => el.classList.add('icon-spin6'));

    const body = new URLSearchParams();
    const params: Record<string, any> = {
        page: page - 1,
        uid,
        action: act,
        object: obj,
        offset: page_offsets[page - 1],
        date_min: date[0],
        date_max: date[1],
        id: additional_filt_value,
    };
    Object.entries(params).forEach(([k, v]) => {
        if (v !== undefined && v !== null) body.append(k, String(v));
    });

    fetch(config.wsUrl + '?format=json&method=pwg.activity.getList', { method: 'POST', body })
        .then((r) => r.json())
        .then((data: any) => {
            uid_filter = uid;
            action_filter = act;
            object_filter = obj;
            date_min_filter = date[0];
            date_max_filter = date[1];
            document.querySelectorAll<HTMLElement>('.loading').forEach((el) => {
                el.style.display = 'none';
            });

            if (data.result['result_lines'].length > 0) {
                data.result['result_lines'].forEach((line: any) => lineConstructor(line));
            } else {
                emptyLine();
            }

            current_page_offset = page_offsets[page - 1];
            end_page = data.result['end_page'];
            if (!page_offsets.includes(data.result['page_offset']))
                page_offsets.push(data.result['page_offset']);
            document
                .querySelectorAll<HTMLElement>('.user-update-spinner')
                .forEach((el) => el.classList.remove('icon-spin6'));
            document.querySelectorAll<HTMLElement>('.pagination-item-container').forEach((el) => {
                el.style.display = '';
            });
            update_pagination_menu(activity_page);
        })
        .catch((e) => {
            console.log('ajax call failed');
            console.log(e);
        });
}

function lineConstructor(line: any) {
    const template = document.getElementById('-1');
    if (!template) return;
    const newLine = template.cloneNode(true) as HTMLElement;
    const qs = (sel: string): HTMLElement | null => newLine.querySelector<HTMLElement>(sel);
    const set = (sel: string, html: string) => {
        const el = qs(sel);
        if (el) el.innerHTML = html;
    };

    document.querySelectorAll<HTMLElement>('.tab-title').forEach((el) => {
        el.style.display = '';
    });
    document.querySelectorAll<HTMLElement>('.activity-noresult').forEach((el) => {
        el.style.display = 'none';
    });
    newLine.classList.remove('hide');
    newLine.id = String(line.id);

    let final_albumInfos = '';

    const p = line.counter > 1;
    const c = line.counter;
    const addActionBase = (typeClass: string, icon: string, nameHtml: string) => {
        qs('.action-type')?.classList.add(typeClass);
        qs('.user-pic')?.classList.add(color_icons[line.user_id % 5]);
        qs('.action-icon')?.classList.add(icon);
        set('.action-name', nameHtml);
    };
    const addObj = (infos: string, icon: string) => {
        final_albumInfos = infos;
        qs('.action-section')?.classList.add(icon);
    };

    switch (line.action) {
        case 'edit':
            addActionBase('icon-blue', 'icon-pencil', actionType_edit);
            switch (line.object) {
                case 'user':
                    addObj(
                        p
                            ? actionInfos_users_edited.replace('%d', c)
                            : actionInfos_user_edited.replace('%d', c),
                        'icon-user-1'
                    );
                    break;
                case 'album':
                    addObj(
                        p
                            ? actionInfos_albums_edited.replace('%d', c)
                            : actionInfos_album_edited.replace('%d', c),
                        'icon-folder-open'
                    );
                    break;
                case 'group':
                    addObj(
                        p
                            ? actionInfos_groups_edited.replace('%d', c)
                            : actionInfos_group_edited.replace('%d', c),
                        'icon-users-1'
                    );
                    break;
                case 'photo':
                    addObj(
                        p
                            ? actionInfos_photos_edited.replace('%d', c)
                            : actionInfos_photo_edited.replace('%d', c),
                        'icon-picture'
                    );
                    break;
                case 'tag':
                    addObj(
                        p
                            ? actionInfos_tags_edited.replace('%d', c)
                            : actionInfos_tag_edited.replace('%d', c),
                        'icon-tags'
                    );
                    break;
                default:
                    final_albumInfos = c + ' ' + line.object + ' ' + line.action;
            }
            break;
        case 'add':
            addActionBase('icon-green', 'icon-plus', actionType_add);
            switch (line.object) {
                case 'user':
                    addObj(
                        p
                            ? actionInfos_users_added.replace('%d', c)
                            : actionInfos_user_added.replace('%d', c),
                        'icon-user-1'
                    );
                    break;
                case 'album':
                    addObj(
                        p
                            ? actionInfos_albums_added.replace('%d', c)
                            : actionInfos_album_added.replace('%d', c),
                        'icon-folder-open'
                    );
                    break;
                case 'group':
                    addObj(
                        p
                            ? actionInfos_groups_added.replace('%d', c)
                            : actionInfos_group_added.replace('%d', c),
                        'icon-users-1'
                    );
                    break;
                case 'photo':
                    addObj(
                        p
                            ? actionInfos_photos_added.replace('%d', c)
                            : actionInfos_photo_added.replace('%d', c),
                        'icon-picture'
                    );
                    break;
                case 'tag':
                    addObj(
                        p
                            ? actionInfos_tags_added.replace('%d', c)
                            : actionInfos_tag_added.replace('%d', c),
                        'icon-tags'
                    );
                    break;
                default:
                    final_albumInfos = c + ' ' + line.object + ' ' + line.action;
            }
            break;
        case 'delete':
            addActionBase('icon-red', 'icon-trash-1', actionType_delete);
            switch (line.object) {
                case 'user':
                    addObj(
                        p
                            ? actionInfos_users_deleted.replace('%d', c)
                            : actionInfos_user_deleted.replace('%d', c),
                        'icon-user-1'
                    );
                    break;
                case 'album':
                    addObj(
                        p
                            ? actionInfos_albums_deleted.replace('%d', c)
                            : actionInfos_album_deleted.replace('%d', c),
                        'icon-folder-open'
                    );
                    break;
                case 'group':
                    addObj(
                        p
                            ? actionInfos_groups_deleted.replace('%d', c)
                            : actionInfos_group_deleted.replace('%d', c),
                        'icon-users-1'
                    );
                    break;
                case 'photo':
                    addObj(
                        p
                            ? actionInfos_photos_deleted.replace('%d', c)
                            : actionInfos_photo_deleted.replace('%d', c),
                        'icon-picture'
                    );
                    break;
                case 'tag':
                    addObj(
                        p
                            ? actionInfos_tags_deleted.replace('%d', c)
                            : actionInfos_tag_deleted.replace('%d', c),
                        'icon-tags'
                    );
                    break;
                default:
                    final_albumInfos = c + ' ' + line.object + ' ' + line.action;
            }
            break;
        case 'move':
            addActionBase('icon-yellow', 'icon-move', actionType_move);
            switch (line.object) {
                case 'album':
                    addObj(
                        p
                            ? actionInfos_albums_moved.replace('%d', c)
                            : actionInfos_album_moved.replace('%d', c),
                        'icon-folder-open'
                    );
                    break;
                case 'group':
                    addObj(
                        p
                            ? actionInfos_groups_moved.replace('%d', c)
                            : actionInfos_group_moved.replace('%d', c),
                        'icon-users-1'
                    );
                    break;
                case 'photo':
                    addObj(
                        p
                            ? actionInfos_photos_moved.replace('%d', c)
                            : actionInfos_photo_moved.replace('%d', c),
                        'icon-picture'
                    );
                    break;
                case 'tag':
                    addObj(
                        p
                            ? actionInfos_tags_moved.replace('%d', c)
                            : actionInfos_tag_moved.replace('%d', c),
                        'icon-tags'
                    );
                    break;
                default:
                    final_albumInfos = c + ' ' + line.object + ' ' + line.action;
            }
            break;
        case 'login':
            qs('.action-type')?.classList.add('icon-purple');
            qs('.user-pic')?.classList.add(color_icons[line.user_id % 5]);
            qs('.action-icon')?.classList.add('icon-key');
            qs('.action-section')?.classList.add('icon-user-1');
            set('.action-name', actionType_login);
            final_albumInfos = (
                p ? actionInfos_users_logged_in : actionInfos_user_logged_in
            ).replace('%d', c);
            break;
        case 'logout':
            qs('.action-type')?.classList.add('icon-purple');
            qs('.user-pic')?.classList.add(
                color_icons[(line.user_id == 2 ? line.object_id[0] : line.user_id) % 5]
            );
            qs('.action-icon')?.classList.add('icon-logout');
            qs('.action-section')?.classList.add('icon-user-1');
            set('.action-name', actionType_logout);
            final_albumInfos = (
                p ? actionInfos_users_logged_out : actionInfos_user_logged_out
            ).replace('%d', c);
            break;
        default:
            qs('.action-type')?.classList.add('icon-purple');
            qs('.user-pic')?.classList.add(color_icons[line.user_id % 5]);
            qs('.action-section')?.classList.add('icon-user-1');
            set('.action-name', line.action);
            final_albumInfos = 'x' + c;
    }

    set('.action-infos-test', final_albumInfos);
    set('.nb_items', line.counter);
    set('.date-day', line.date);
    set('.date-hour', line.hour);
    set('.user-name', line.username);
    set('.user-pic', get_initials(line.username));
    const d1 = qs('.detail-item-1');
    if (d1) {
        d1.innerHTML = line.ip_address;
        d1.title = 'IP: ' + line.ip_address;
    }

    const d2 = qs('.detail-item-2');
    if (d2) {
        if (line.detailsType === 'script') {
            d2.innerHTML = line.details.script;
            d2.title = 'Script';
        } else if (line.detailsType === 'method') {
            d2.innerHTML = line.details.method;
            d2.title = 'API Method';
        }
    }

    const d3 = qs('.detail-item-3');
    if (line.details.agent) {
        if (d3) {
            const api_key = line.details.connected_with ? 'API Key, ' : '';
            d3.innerHTML = line.details.connected_with
                ? '<i class="icon-key"></i>' + line.details.agent
                : line.details.agent;
            d3.title = api_key + 'User-Agent: ' + line.details.agent;
        }
    } else if (line.details.users && line.action !== 'logout' && line.action !== 'login') {
        const user_string = [...new Set<unknown>(line.details.users)].toString();
        if (d3) {
            d3.innerHTML = user_string;
            d3.title = users_key + ': ' + user_string;
        }
    } else {
        d3?.remove();
    }

    newLine.classList.add('uid-' + line.user_id);
    document.querySelector<HTMLElement>('.tab')?.appendChild(newLine);
}

function emptyLine() {
    document.querySelectorAll<HTMLElement>('.tab-title').forEach((el) => {
        el.style.display = 'none';
    });
    document.querySelectorAll<HTMLElement>('.activity-noresult').forEach((el) => {
        el.style.display = '';
    });
}

function get_initials(username: any) {
    const words = username.toUpperCase().split(' ');
    let res = words[0][0];
    if (words.length > 1 && words[1][0] !== undefined) res += words[1][0];
    return res;
}

function move_to_page(page: any) {
    if (page < 0) return;
    actual_page = page;
    update_pagination_menu(page);
    get_user_activity(
        page,
        uid_filter,
        action_filter,
        object_filter,
        [date_min_filter, date_max_filter],
        additional_filt_value
    );
}

document
    .querySelector('.pagination-arrow.rigth')
    ?.addEventListener('click', () => move_to_page(actual_page + 1));
document
    .querySelector('.pagination-arrow.left')
    ?.addEventListener('click', () => move_to_page(actual_page - 1));

function update_pagination_menu(page: any) {
    updateArrows();
    update_pagination_items();
    document.querySelectorAll<HTMLElement>('.pagination-container').forEach((el) => {
        el.style.display = end_page && actual_page == 1 ? 'none' : '';
    });
}

function updateArrows() {
    document
        .querySelector('.pagination-arrow.left')
        ?.classList.toggle('unavailable', actual_page == 1);
    document.querySelector('.pagination-arrow.rigth')?.classList.toggle('unavailable', !!end_page);
}

function update_pagination_items() {
    document
        .querySelectorAll('.pagination-item-container a, .pagination-item-container span')
        .forEach((el) => el.remove());
    append_pagination_item(1);
    if (actual_page > 2) append_pagination_item();
    if (actual_page != 1) append_pagination_item(actual_page);
    if (!end_page) append_pagination_item();
}

function append_pagination_item(page: any = null) {
    const container = document.querySelector<HTMLElement>('.pagination-item-container')!;
    if (page != null) {
        const div = document.createElement('div');
        div.innerHTML = page_item.replace(/%d/g, page);
        const el = div.firstElementChild as HTMLElement;
        if (actual_page == page) el.classList.add('actual');
        el.addEventListener('click', () => move_to_page(Number(el.dataset['page'])));
        container.appendChild(el);
    } else {
        const div = document.createElement('div');
        div.innerHTML = page_ellipsis;
        container.appendChild(div.firstElementChild!);
    }
}

function page_reset() {
    activity_page = 1;
    current_page_offset = 0;
    page_offsets = [0];
    actual_page = 1;
    end_page = false;
}

document.addEventListener('DOMContentLoaded', () => {
    const h1 = document.querySelector('h1');
    if (h1) h1.insertAdjacentHTML('beforeend', `<span class='badge-number'>${nb_users - 1}</span>`);

    const userSelectEl = document.querySelector<HTMLSelectElement>('select.user-selecter');
    if (userSelectEl) {
        const userTs = new TomSelect(userSelectEl, {
            onChange(value: string) {
                page_reset();
                if (!value || value === 'none') {
                    get_user_activity(
                        1,
                        undefined,
                        action_filter,
                        object_filter,
                        [date_min_filter, date_max_filter],
                        additional_filt_value
                    );
                } else {
                    get_user_activity(
                        1,
                        value,
                        action_filter,
                        object_filter,
                        [date_min_filter, date_max_filter],
                        additional_filt_value
                    );
                }
            },
        });
        userTs.setValue('');
    }

    const actionSelectEl = document.querySelector<HTMLSelectElement>('select.action-selecter');
    if (actionSelectEl) {
        const actionTs = new TomSelect(actionSelectEl, {
            onChange(value: string) {
                page_reset();
                if (!value || value === 'none') {
                    get_user_activity(
                        1,
                        uid_filter,
                        undefined,
                        additional_filt_type ? object_filter : undefined,
                        [date_min_filter, date_max_filter],
                        additional_filt_value
                    );
                } else {
                    const obj = value.split('/')[0];
                    action = value.split('/')[1];
                    get_user_activity(
                        1,
                        uid_filter,
                        action,
                        obj,
                        [date_min_filter, date_max_filter],
                        additional_filt_value
                    );
                }
            },
        });
        actionTs.setValue('');
    }

    const dateMin = document.getElementById('date_min_activity') as HTMLInputElement;
    const dateMax = document.getElementById('date_max_activity') as HTMLInputElement;
    dateMin?.addEventListener('change', () => {
        page_reset();
        document
            .getElementById('date_max_activity')!
            .setAttribute('min', dateMin.value || date_min);
        get_user_activity(
            activity_page,
            uid_filter,
            action_filter,
            object_filter,
            [dateMin.value, date_max_filter],
            additional_filt_value
        );
    });
    dateMax?.addEventListener('change', () => {
        page_reset();
        document
            .getElementById('date_min_activity')!
            .setAttribute('max', dateMax.value || date_max);
        get_user_activity(
            activity_page,
            uid_filter,
            action_filter,
            object_filter,
            [date_min_filter, dateMax.value],
            additional_filt_value
        );
    });

    const moreFilters = document.getElementById('activityMoreFilters');
    const moreFiltersContent = document.getElementById('activityMoreFiltersContent');
    if (additional_filt_type) moreFilters?.classList.add('extend-padding');
    else if (moreFiltersContent) moreFiltersContent.style.display = 'none';

    let toggleTriggered = false;
    moreFilters?.addEventListener('click', () => {
        if (toggleTriggered || !moreFiltersContent) return;
        toggleTriggered = true;
        const isHidden =
            moreFiltersContent.style.display === 'none' ||
            getComputedStyle(moreFiltersContent).display === 'none';
        if (isHidden) {
            moreFilters.classList.add('extend-padding');
            moreFiltersContent.style.display = 'flex';
        } else {
            moreFiltersContent.style.display = 'none';
            moreFilters.classList.remove('extend-padding');
        }
        toggleTriggered = false;
    });
});

export {};
