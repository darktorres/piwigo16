import TomSelect from 'tom-select';

declare var action: any;
declare var actionInfos_album_added: any; declare var actionInfos_album_deleted: any;
declare var actionInfos_album_edited: any; declare var actionInfos_album_moved: any;
declare var actionInfos_albums_added: any; declare var actionInfos_albums_deleted: any;
declare var actionInfos_albums_edited: any; declare var actionInfos_albums_moved: any;
declare var actionInfos_group_added: any; declare var actionInfos_group_deleted: any;
declare var actionInfos_group_edited: any; declare var actionInfos_group_moved: any;
declare var actionInfos_groups_added: any; declare var actionInfos_groups_deleted: any;
declare var actionInfos_groups_edited: any; declare var actionInfos_groups_moved: any;
declare var actionInfos_photo_added: any; declare var actionInfos_photo_deleted: any;
declare var actionInfos_photo_edited: any; declare var actionInfos_photo_moved: any;
declare var actionInfos_photos_added: any; declare var actionInfos_photos_deleted: any;
declare var actionInfos_photos_edited: any; declare var actionInfos_photos_moved: any;
declare var actionInfos_tag_added: any; declare var actionInfos_tag_deleted: any;
declare var actionInfos_tag_edited: any; declare var actionInfos_tag_moved: any;
declare var actionInfos_tags_added: any; declare var actionInfos_tags_deleted: any;
declare var actionInfos_tags_edited: any; declare var actionInfos_tags_moved: any;
declare var actionInfos_user_added: any; declare var actionInfos_user_deleted: any;
declare var actionInfos_user_edited: any; declare var actionInfos_user_logged_in: any;
declare var actionInfos_user_logged_out: any; declare var actionInfos_users_added: any;
declare var actionInfos_users_deleted: any; declare var actionInfos_users_edited: any;
declare var actionInfos_users_logged_in: any; declare var actionInfos_users_logged_out: any;
declare var actionType_add: any; declare var actionType_delete: any;
declare var actionType_edit: any; declare var actionType_login: any;
declare var actionType_logout: any; declare var actionType_move: any;
declare var action_filter: any; declare var activity_page: any;
declare var actual_page: any; declare var additional_filt_type: any;
declare var additional_filt_value: any; declare var color_icons: any;
declare var current_page_offset: any; declare var date_max: any;
declare var date_max_filter: any; declare var date_min: any;
declare var date_min_filter: any; declare var end_page: any;
declare var nb_users: any; declare var object: any;
declare var object_filter: any; declare var page_ellipsis: any;
declare var page_item: any; declare var page_offsets: any;
declare var uid_filter: any; declare var users_key: any;

if (additional_filt_type) object_filter = additional_filt_type;

get_user_activity(activity_page, uid_filter, action_filter, object_filter, [date_min_filter, date_max_filter], additional_filt_value);

function get_user_activity(page: any, uid: any, act: any, obj: any, date: any, id: any) {
    // beforeSend equivalent
    document.querySelectorAll<HTMLElement>('.tab > *:not(#-1):not(.loading)').forEach(el => el.remove());
    document.querySelectorAll<HTMLElement>('.loading').forEach(el => { el.style.display = ''; });
    document.querySelector('.pagination-arrow.rigth')?.classList.add('unavailable');
    document.querySelector('.pagination-arrow.left')?.classList.add('unavailable');
    document.querySelectorAll<HTMLElement>('.pagination-item-container').forEach(el => { el.style.display = 'none'; });
    document.querySelectorAll<HTMLElement>('.user-update-spinner').forEach(el => el.classList.add('icon-spin6'));

    const body = new URLSearchParams();
    const params: Record<string, any> = {
        page: page - 1, uid, action: act, object: obj,
        offset: page_offsets[page - 1],
        date_min: date[0], date_max: date[1], id: additional_filt_value
    };
    Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null) body.append(k, String(v)); });

    fetch('ws.php?format=json&method=pwg.activity.getList', { method: 'POST', body })
        .then(r => r.json())
        .then((data: any) => {
            uid_filter = uid; action_filter = act; object_filter = obj;
            date_min_filter = date[0]; date_max_filter = date[1];
            document.querySelectorAll<HTMLElement>('.loading').forEach(el => { el.style.display = 'none'; });

            if (data.result['result_lines'].length > 0) {
                data.result['result_lines'].forEach((line: any) => lineConstructor(line));
            } else {
                emptyLine();
            }

            current_page_offset = page_offsets[page - 1];
            end_page = data.result['end_page'];
            if (!page_offsets.includes(data.result['page_offset'])) page_offsets.push(data.result['page_offset']);
            document.querySelectorAll<HTMLElement>('.user-update-spinner').forEach(el => el.classList.remove('icon-spin6'));
            document.querySelectorAll<HTMLElement>('.pagination-item-container').forEach(el => { el.style.display = ''; });
            update_pagination_menu(activity_page);
        })
        .catch(e => { console.log('ajax call failed'); console.log(e); });
}

function lineConstructor(line: any) {
    const newLine = document.getElementById('-1')!.cloneNode(true) as HTMLElement;
    const qs = (sel: string): HTMLElement => newLine.querySelector<HTMLElement>(sel)!;

    document.querySelectorAll<HTMLElement>('.tab-title').forEach(el => { el.style.display = ''; });
    document.querySelectorAll<HTMLElement>('.activity-noresult').forEach(el => { el.style.display = 'none'; });
    newLine.classList.remove('hide');
    newLine.id = String(line.id);

    let final_albumInfos = '';

    const p = line.counter > 1;
    const c = line.counter;
    const addActionBase = (typeClass: string, icon: string, nameHtml: string) => {
        qs('.action-type').classList.add(typeClass);
        qs('.user-pic').classList.add(color_icons[line.user_id % 5]);
        qs('.action-icon').classList.add(icon);
        qs('.action-name').innerHTML = nameHtml;
    };
    const addObj = (infos: string, icon: string) => {
        final_albumInfos = infos; qs('.action-section').classList.add(icon);
    };

    switch (line.action) {
        case 'edit':
            addActionBase('icon-blue', 'icon-pencil', actionType_edit);
            switch (line.object) {
                case 'user':   addObj(p ? actionInfos_users_edited.replace('%d',c) : actionInfos_user_edited.replace('%d',c), 'icon-user-1'); break;
                case 'album':  addObj(p ? actionInfos_albums_edited.replace('%d',c) : actionInfos_album_edited.replace('%d',c), 'icon-folder-open'); break;
                case 'group':  addObj(p ? actionInfos_groups_edited.replace('%d',c) : actionInfos_group_edited.replace('%d',c), 'icon-users-1'); break;
                case 'photo':  addObj(p ? actionInfos_photos_edited.replace('%d',c) : actionInfos_photo_edited.replace('%d',c), 'icon-picture'); break;
                case 'tag':    addObj(p ? actionInfos_tags_edited.replace('%d',c) : actionInfos_tag_edited.replace('%d',c), 'icon-tags'); break;
                default: final_albumInfos = c + ' ' + line.object + ' ' + line.action;
            } break;
        case 'add':
            addActionBase('icon-green', 'icon-plus', actionType_add);
            switch (line.object) {
                case 'user':   addObj(p ? actionInfos_users_added.replace('%d',c) : actionInfos_user_added.replace('%d',c), 'icon-user-1'); break;
                case 'album':  addObj(p ? actionInfos_albums_added.replace('%d',c) : actionInfos_album_added.replace('%d',c), 'icon-folder-open'); break;
                case 'group':  addObj(p ? actionInfos_groups_added.replace('%d',c) : actionInfos_group_added.replace('%d',c), 'icon-users-1'); break;
                case 'photo':  addObj(p ? actionInfos_photos_added.replace('%d',c) : actionInfos_photo_added.replace('%d',c), 'icon-picture'); break;
                case 'tag':    addObj(p ? actionInfos_tags_added.replace('%d',c) : actionInfos_tag_added.replace('%d',c), 'icon-tags'); break;
                default: final_albumInfos = c + ' ' + line.object + ' ' + line.action;
            } break;
        case 'delete':
            addActionBase('icon-red', 'icon-trash-1', actionType_delete);
            switch (line.object) {
                case 'user':   addObj(p ? actionInfos_users_deleted.replace('%d',c) : actionInfos_user_deleted.replace('%d',c), 'icon-user-1'); break;
                case 'album':  addObj(p ? actionInfos_albums_deleted.replace('%d',c) : actionInfos_album_deleted.replace('%d',c), 'icon-folder-open'); break;
                case 'group':  addObj(p ? actionInfos_groups_deleted.replace('%d',c) : actionInfos_group_deleted.replace('%d',c), 'icon-users-1'); break;
                case 'photo':  addObj(p ? actionInfos_photos_deleted.replace('%d',c) : actionInfos_photo_deleted.replace('%d',c), 'icon-picture'); break;
                case 'tag':    addObj(p ? actionInfos_tags_deleted.replace('%d',c) : actionInfos_tag_deleted.replace('%d',c), 'icon-tags'); break;
                default: final_albumInfos = c + ' ' + line.object + ' ' + line.action;
            } break;
        case 'move':
            addActionBase('icon-yellow', 'icon-move', actionType_move);
            switch (line.object) {
                case 'album':  addObj(p ? actionInfos_albums_moved.replace('%d',c) : actionInfos_album_moved.replace('%d',c), 'icon-folder-open'); break;
                case 'group':  addObj(p ? actionInfos_groups_moved.replace('%d',c) : actionInfos_group_moved.replace('%d',c), 'icon-users-1'); break;
                case 'photo':  addObj(p ? actionInfos_photos_moved.replace('%d',c) : actionInfos_photo_moved.replace('%d',c), 'icon-picture'); break;
                case 'tag':    addObj(p ? actionInfos_tags_moved.replace('%d',c) : actionInfos_tag_moved.replace('%d',c), 'icon-tags'); break;
                default: final_albumInfos = c + ' ' + line.object + ' ' + line.action;
            } break;
        case 'login':
            qs('.action-type').classList.add('icon-purple');
            qs('.user-pic').classList.add(color_icons[line.user_id % 5]);
            qs('.action-icon').classList.add('icon-key');
            qs('.action-section').classList.add('icon-user-1');
            qs('.action-name').innerHTML = actionType_login;
            final_albumInfos = (p ? actionInfos_users_logged_in : actionInfos_user_logged_in).replace('%d', c);
            break;
        case 'logout':
            qs('.action-type').classList.add('icon-purple');
            qs('.user-pic').classList.add(color_icons[(line.user_id == 2 ? line.object_id[0] : line.user_id) % 5]);
            qs('.action-icon').classList.add('icon-logout');
            qs('.action-section').classList.add('icon-user-1');
            qs('.action-name').innerHTML = actionType_logout;
            final_albumInfos = (p ? actionInfos_users_logged_out : actionInfos_user_logged_out).replace('%d', c);
            break;
        default:
            qs('.action-type').classList.add('icon-purple');
            qs('.user-pic').classList.add(color_icons[line.user_id % 5]);
            qs('.action-section').classList.add('icon-user-1');
            qs('.action-name').innerHTML = line.action;
            final_albumInfos = 'x' + c;
    }

    qs('.action-infos-test').innerHTML = final_albumInfos;
    qs('.nb_items').innerHTML = line.counter;
    qs('.date-day').innerHTML = line.date;
    qs('.date-hour').innerHTML = line.hour;
    qs('.user-name').innerHTML = line.username;
    qs('.user-pic').innerHTML = get_initials(line.username);
    qs('.detail-item-1').innerHTML = line.ip_address;
    qs('.detail-item-1').title = 'IP: ' + line.ip_address;

    if (line.detailsType === 'script') {
        qs('.detail-item-2').innerHTML = line.details.script;
        qs('.detail-item-2').title = 'Script';
    } else if (line.detailsType === 'method') {
        qs('.detail-item-2').innerHTML = line.details.method;
        qs('.detail-item-2').title = 'API Method';
    }

    if (line.details.agent) {
        const api_key = line.details.connected_with ? 'API Key, ' : '';
        qs('.detail-item-3').innerHTML = line.details.connected_with ? '<i class="icon-key"></i>' + line.details.agent : line.details.agent;
        qs('.detail-item-3').title = api_key + 'User-Agent: ' + line.details.agent;
    } else if (line.details.users && line.action !== 'logout' && line.action !== 'login') {
        const user_string = [...new Set<unknown>(line.details.users)].toString();
        qs('.detail-item-3').innerHTML = user_string;
        qs('.detail-item-3').title = users_key + ': ' + user_string;
    } else {
        qs('.detail-item-3')?.remove();
    }

    newLine.classList.add('uid-' + line.user_id);
    document.querySelector<HTMLElement>('.tab')!.appendChild(newLine);
}

function emptyLine() {
    document.querySelectorAll<HTMLElement>('.tab-title').forEach(el => { el.style.display = 'none'; });
    document.querySelectorAll<HTMLElement>('.activity-noresult').forEach(el => { el.style.display = ''; });
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
    get_user_activity(page, uid_filter, action_filter, object_filter, [date_min_filter, date_max_filter], additional_filt_value);
}

document.querySelector('.pagination-arrow.rigth')?.addEventListener('click', () => move_to_page(actual_page + 1));
document.querySelector('.pagination-arrow.left')?.addEventListener('click', () => move_to_page(actual_page - 1));

function update_pagination_menu(page: any) {
    updateArrows();
    update_pagination_items();
    document.querySelectorAll<HTMLElement>('.pagination-container').forEach(el => {
        el.style.display = (end_page && actual_page == 1) ? 'none' : '';
    });
}

function updateArrows() {
    document.querySelector('.pagination-arrow.left')?.classList.toggle('unavailable', actual_page == 1);
    document.querySelector('.pagination-arrow.rigth')?.classList.toggle('unavailable', !!end_page);
}

function update_pagination_items() {
    document.querySelectorAll('.pagination-item-container a, .pagination-item-container span').forEach(el => el.remove());
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
                    get_user_activity(1, undefined, action_filter, object_filter, [date_min_filter, date_max_filter], additional_filt_value);
                } else {
                    get_user_activity(1, value, action_filter, object_filter, [date_min_filter, date_max_filter], additional_filt_value);
                }
            }
        });
        userTs.setValue('');
    }

    const actionSelectEl = document.querySelector<HTMLSelectElement>('select.action-selecter');
    if (actionSelectEl) {
        const actionTs = new TomSelect(actionSelectEl, {
            onChange(value: string) {
                page_reset();
                if (!value || value === 'none') {
                    get_user_activity(1, uid_filter, undefined, additional_filt_type ? object_filter : undefined, [date_min_filter, date_max_filter], additional_filt_value);
                } else {
                    object = value.split('/')[0];
                    action = value.split('/')[1];
                    get_user_activity(1, uid_filter, action, object, [date_min_filter, date_max_filter], additional_filt_value);
                }
            }
        });
        actionTs.setValue('');
    }

    const dateMin = document.getElementById('date_min_activity') as HTMLInputElement;
    const dateMax = document.getElementById('date_max_activity') as HTMLInputElement;
    dateMin?.addEventListener('change', () => {
        page_reset();
        document.getElementById('date_max_activity')!.setAttribute('min', dateMin.value || date_min);
        get_user_activity(activity_page, uid_filter, action_filter, object_filter, [dateMin.value, date_max_filter], additional_filt_value);
    });
    dateMax?.addEventListener('change', () => {
        page_reset();
        document.getElementById('date_min_activity')!.setAttribute('max', dateMax.value || date_max);
        get_user_activity(activity_page, uid_filter, action_filter, object_filter, [date_min_filter, dateMax.value], additional_filt_value);
    });

    const moreFilters = document.getElementById('activityMoreFilters');
    const moreFiltersContent = document.getElementById('activityMoreFiltersContent');
    if (additional_filt_type) moreFilters?.classList.add('extend-padding');
    else if (moreFiltersContent) moreFiltersContent.style.display = 'none';

    let toggleTriggered = false;
    moreFilters?.addEventListener('click', () => {
        if (toggleTriggered || !moreFiltersContent) return;
        toggleTriggered = true;
        const isHidden = moreFiltersContent.style.display === 'none' || getComputedStyle(moreFiltersContent).display === 'none';
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
