import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import { getPageData } from './page-data';
import { GeoIp } from './geoip';

declare function sprintf(fmt: string, ...args: unknown[]): string;

interface HistoryPageData {
    API_METHOD: string;
    filter_user_name: string;
    guest_id: number;
    today: string;
    initial_user_id: string;
    initial_image_id: string;
    initial_ip: string;
    new_user_item: null;
    str_dwld: string;
    str_most_visited: string;
    str_best_rated: string;
    str_list: string;
    str_favorites: string;
    str_recent_cats: string;
    str_recent_pics: string;
    str_memories: string;
    str_no_longer_exist_photo: string;
    str_guest: string;
    str_contact_form: string;
    str_edit_img: string;
    unit_MB: string;
    str_and_more: string;
    str_search_details: Record<string, string>;
}

const {
    API_METHOD,
    filter_user_name,
    guest_id,
    today,
    initial_user_id,
    initial_image_id,
    initial_ip,
    str_dwld,
    str_most_visited,
    str_best_rated,
    str_list,
    str_favorites,
    str_recent_cats,
    str_recent_pics,
    str_memories,
    str_no_longer_exist_photo,
    str_guest,
    str_contact_form,
    str_edit_img,
    unit_MB,
    str_and_more,
    str_search_details,
} = getPageData<HistoryPageData>();

interface HistoryParam {
    start: string;
    end: string;
    types: Record<string, string>;
    user_id: string | number;
    image_id: string;
    filename: string;
    ip: string;
    display_thumbnail: string;
    pageNumber: number;
    [key: string]: unknown;
}

interface HistorySummary {
    NB_LINES: string | number;
    FILESIZE: string | number;
    USERS: string;
    GUESTS: string;
    MEMBERS: Array<Record<string, string | number>>;
    SORTED_MEMBERS: Record<string, unknown>;
}

interface HistoryLine {
    DATE: string;
    TIME: string;
    USERNAME: string;
    USERID: string | number;
    IP: string;
    IMAGEID: string | number;
    EDIT_IMAGE: string;
    SECTION: string;
    TAGS?: string[];
    TAGIDS?: Array<string | number>;
    SEARCH_DETAILS?: Record<string, unknown> | null;
    SEARCH_ID?: string | number | null;
    CATEGORY?: string;
    FULL_CATEGORY_PATH?: string;
    IMAGE: string;
    IMAGENAME?: string;
    TYPE?: string;
}

interface HistoryResult {
    lines: HistoryLine[];
    params: { display_thumbnail: string };
    maxPage: number;
    summary: HistorySummary;
}

interface HistoryResponse {
    stat?: string;
    result: HistoryResult;
    err?: string;
    message?: string;
}

const current_param: HistoryParam = {
    start: '',
    end: today,
    types: { 0: 'none', 1: 'picture', 2: 'high', 3: 'other' },
    user_id: initial_user_id,
    image_id: initial_image_id,
    filename: '',
    ip: initial_ip,
    display_thumbnail: 'display_thumbnail_classic',
    pageNumber: 0,
};
let data: HistoryLine[] = [];
let imageDisplay = '';
let maxPage = 0;
let summary: HistorySummary = {
    NB_LINES: '',
    FILESIZE: '',
    USERS: '',
    GUESTS: '',
    MEMBERS: [],
    SORTED_MEMBERS: {},
};

document.addEventListener('DOMContentLoaded', () => {
    activateLineOptions();
    checkFilters();
    if (current_param.ip !== '') addIpFilter(current_param.ip);
    if (current_param.image_id !== '') addImageFilter(current_param.image_id);
    if (current_param.user_id !== '-1') addUserFilter(filter_user_name);

    document.querySelectorAll<HTMLElement>('.elem-type-select').forEach((el) => {
        el.addEventListener('change', () => {
            const val = document.querySelector<HTMLSelectElement>(
                '.elem-type-select option:checked'
            )?.value;
            if (val === 'visited') {
                current_param.types = { 0: 'none', 1: 'picture' };
            } else if (val === 'downloaded') {
                current_param.types = { 0: 'high', 1: 'other' };
            } else {
                current_param.types = { 0: 'none', 1: 'picture', 2: 'high', 3: 'other' };
            }
            fillHistoryResult(current_param);
        });
    });

    document.querySelector('.date-start')?.addEventListener('change', () => {
        const val = document
            .querySelector<HTMLInputElement>('.date-start input[name="start"]')
            ?.getAttribute('value');
        if (current_param.start !== val) {
            current_param.start = val ?? '';
            current_param.pageNumber = 0;
            fillHistoryResult(current_param);
        }
    });

    document.querySelector('.date-end')?.addEventListener('change', () => {
        const newValue = document
            .querySelector<HTMLInputElement>('.date-end input[name="end"]')
            ?.getAttribute('value');
        if (current_param.end !== newValue) {
            current_param.end = newValue ?? '';
            current_param.pageNumber = 0;
            if (newValue !== '1899-12-31') fillHistoryResult(current_param);
        }
    });

    document.getElementById('start_unset')?.addEventListener('click', () => {
        if (current_param.start !== '') {
            current_param.pageNumber = 0;
            current_param.start = '';
            fillHistoryResult(current_param);
        }
    });

    document.getElementById('end_unset')?.addEventListener('click', () => {
        if (current_param.end !== today) {
            current_param.end = today;
            current_param.pageNumber = 0;
            fillHistoryResult(current_param);
        }
    });

    document.querySelector('.pagination-arrow.rigth')?.addEventListener('click', () => {
        current_param.pageNumber += 1;
        fillHistoryResult(current_param);
    });
    document.querySelector('.pagination-arrow.left')?.addEventListener('click', () => {
        current_param.pageNumber -= 1;
        fillHistoryResult(current_param);
    });

    document.querySelector<HTMLElement>('.refresh-results')?.addEventListener('click', () => {
        fillHistoryResult(current_param);
    });
});

function activateLineOptions() {
    document.querySelectorAll<HTMLElement>('.search-line .img-option').forEach((el) => {
        el.style.display = 'none';
    });
    document.querySelectorAll<HTMLElement>('.search-line .toggle-img-option').forEach((el) => {
        el.addEventListener('click', () => {
            const opt = el.querySelector<HTMLElement>('.img-option');
            if (opt) opt.style.display = opt.style.display === 'none' ? '' : 'none';
        });
    });
    document.addEventListener('mouseup', (e: MouseEvent) => {
        e.stopPropagation();
        const target = e.target as Element;
        const clicked = Array.from(document.querySelectorAll('.img-option span')).some((span) =>
            span.contains(target)
        );
        if (!clicked)
            document.querySelectorAll<HTMLElement>('.search-line .img-option').forEach((el) => {
                el.style.display = 'none';
            });
    });
}

function fillSummaryResult(summary: HistorySummary) {
    document.querySelector('.user-list')!.innerHTML = '';
    document.querySelector<HTMLElement>('.summary-lines .summary-data')!.innerHTML = String(
        summary.NB_LINES
    );
    document.querySelector<HTMLElement>('.summary-weight .summary-data')!.innerHTML =
        unit_MB.replace('%s', String(summary.FILESIZE));
    document.querySelector<HTMLElement>('.summary-users .summary-data')!.innerHTML = summary.USERS;
    document.querySelector<HTMLElement>('.summary-guests .summary-data')!.innerHTML =
        summary.GUESTS;

    const guestsData = document.querySelector<HTMLElement>('.summary-guests .summary-data')!;
    const guestsEl = document.querySelector<HTMLElement>('.summary-guests')!;
    if (summary.GUESTS.split(' ')[0] !== '0') {
        guestsData.classList.add('icon-plus-circled');
        guestsData.style.cursor = 'pointer';
        guestsData.addEventListener('click', () => {
            if (current_param.user_id === '-1') {
                current_param.user_id = guest_id;
                addGuestFilter(str_guest);
                fillHistoryResult(current_param);
            }
        });
        guestsEl.style.display = '';
    } else {
        guestsEl.style.display = 'none';
    }

    const id_of: Record<string, string | number> = {};
    let user_dot_title = '';
    summary.MEMBERS.forEach((keyval) => {
        for (const [key, value] of Object.entries(keyval)) {
            id_of[key] = value;
            user_dot_title += key + ', ';
        }
    });
    user_dot_title = user_dot_title.slice(0, -2);
    document.querySelectorAll<HTMLElement>('.user-dot').forEach((el) => {
        el.title = user_dot_title;
        el.classList.add('tiptip');
        el.style.display = 'none';
    });

    let tmp = 0;
    for (const [key] of Object.entries(summary.SORTED_MEMBERS)) {
        if (tmp < 5) {
            const item = document.getElementById('-2')!.cloneNode(true) as HTMLElement;
            item.classList.remove('hide');
            item.querySelector<HTMLElement>('.user-item-name')!.innerHTML = key;
            item.dataset['userId'] = String(id_of[key]);
            item.addEventListener('click', () => {
                if (current_param.user_id !== id_of[key]) {
                    current_param.user_id = item.dataset['userId'] ?? '';
                    addUserFilter(key);
                    fillHistoryResult(current_param);
                }
            });
            document.querySelector('.user-list')!.appendChild(item);
            tmp++;
        } else {
            document.querySelectorAll<HTMLElement>('.user-dot').forEach((el) => {
                el.style.display = '';
            });
        }
    }
}

function showResults(doShow: boolean) {
    const display = doShow ? '' : 'none';
    document.querySelectorAll<HTMLElement>('.search-summary,.container').forEach((el) => {
        el.style.display = display;
    });
}

function stringify(v: unknown): string {
    if (typeof v === 'string') return v;
    if (typeof v === 'number' || typeof v === 'boolean') return String(v);
    return JSON.stringify(v);
}

function fillHistoryResult(ajaxParam: HistoryParam) {
    showResults(false);
    document.querySelector<HTMLElement>('.loading')!.classList.remove('hide');
    document.querySelectorAll<HTMLElement>('.noResults').forEach((el) => {
        el.style.display = 'none';
    });
    document.querySelector<HTMLElement>('.tab')!.innerHTML = '';

    const body = new URLSearchParams();
    for (const [k, v] of Object.entries(ajaxParam)) {
        if (v !== null && v !== undefined) body.append(k, stringify(v));
    }
    void fetch(API_METHOD, { method: 'POST', body })
        .then((r) => r.json() as Promise<HistoryResponse>)
        .then((raw_data) => {
            data = raw_data.result.lines;
            imageDisplay = raw_data.result.params.display_thumbnail;
            maxPage = raw_data.result.maxPage;
            summary = raw_data.result.summary;

            if (data.length > 0) {
                data.forEach((line, id) => lineConstructor(line, id, imageDisplay));
                fillSummaryResult(summary);
                showResults(true);
                document.querySelectorAll<HTMLElement>('.noResults').forEach((el) => {
                    el.style.display = 'none';
                });
            } else {
                showResults(false);
                document.querySelectorAll<HTMLElement>('.noResults').forEach((el) => {
                    el.style.display = '';
                });
            }

            activateLineOptions();
            document.querySelector<HTMLElement>('.loading')!.classList.add('hide');
            updatePagination(maxPage);
            tippy('.tiptip', { delay: [0, 0], duration: [200, 200] });
        })
        .catch((e: unknown) => console.error(e));
}

function lineConstructor(line: HistoryLine, id: number, _imageDisplay: string) {
    const newLine = document.getElementById('-1')!.cloneNode(true) as HTMLElement;
    const find = (sel: string) => newLine.querySelector<HTMLElement>(sel)!;

    const sections = [
        'categories',
        'tags',
        'best_rated',
        'memories-1-year-ago',
        'list',
        'search',
        'most_visited',
        'recent_pics',
        'recent_cats',
        'favorites',
    ];
    const icons = [
        'line-icon icon-folder-open icon-yellow',
        'line-icon icon-tags icon-blue',
        'line-icon icon-star icon-green',
        'line-icon icon-clock icon-yellow',
        'line-icon icon-dice-solid icon-purple',
        'line-icon icon-search icon-purple',
        'line-icon icon-fire icon-red',
        'line-icon icon-clock icon-yellow',
        'line-icon icon-clock icon-yellow',
        'line-icon icon-heart icon-red',
    ];

    newLine.classList.remove('hide');
    newLine.id = String(id);
    find('.date-day').innerHTML = line.DATE;
    find('.date-hour').innerHTML = line.TIME;
    find('.user-name').innerHTML = line.USERNAME + '<i class="add-filter icon-plus-circled"></i>';
    find('.user-name').id = String(line.USERID);

    if (current_param.user_id === '-1') {
        find('.user-name').addEventListener('click', function (this: HTMLElement) {
            current_param.user_id = String(this.id);
            current_param.pageNumber = 0;
            addUserFilter(this.innerHTML);
            fillHistoryResult(current_param);
        });
    }

    find('.user-ip').innerHTML = line.IP + '<i class="add-filter icon-plus-circled"></i>';
    find('.user-ip').dataset['ip'] = line.IP;
    if (current_param.ip === '') {
        find('.user-ip').addEventListener('click', function (this: HTMLElement) {
            current_param.ip = this.dataset['ip'] ?? '';
            current_param.pageNumber = 0;
            addIpFilter(this.innerHTML);
            fillHistoryResult(current_param);
        });
    }

    find('.add-img-as-filter').dataset['imgId'] = String(line.IMAGEID);
    if (current_param.image_id === '') {
        find('.add-img-as-filter').addEventListener('click', function (this: HTMLElement) {
            current_param.image_id = this.dataset['imgId'] ?? '';
            current_param.pageNumber = 0;
            addImageFilter(this.dataset['imgId'] ?? '');
            fillHistoryResult(current_param);
        });
    }

    if (line.EDIT_IMAGE !== '') {
        (find('.edit-img') as HTMLAnchorElement).href = line.EDIT_IMAGE;
    } else {
        const editImg = find('.edit-img') as HTMLAnchorElement;
        editImg.href = '#';
        editImg.classList.add('notClickable', 'tiptip');
        editImg.title = str_no_longer_exist_photo;
        editImg.addEventListener('click', (e) => e.preventDefault());
    }

    const setDetailItem = (
        n: number,
        html: string,
        classes: string,
        title?: string,
        showItem = false
    ) => {
        const di = newLine.querySelector<HTMLElement>('.detail-item-' + n);
        if (!di) return;
        di.innerHTML = html;
        classes
            .split(' ')
            .filter(Boolean)
            .forEach((c) => di.classList.add(c));
        if (title !== undefined) di.title = title;
        if (showItem) di.classList.remove('hide');
    };

    switch (line.SECTION) {
        case 'tags': {
            const tags = line.TAGS ?? [];
            const tagIds = line.TAGIDS ?? [];
            const tl = tags.length;
            if (tl > 2) {
                find('.type-name').innerHTML = tags[0] + ', ' + tags[1] + ', ' + tags[2] + ', ...';
                find('.type-id').innerHTML =
                    '#' +
                    String(tagIds[0]) +
                    ', ' +
                    String(tagIds[1]) +
                    ', ' +
                    String(tagIds[2]) +
                    ', ...';
            } else if (tl > 1) {
                find('.type-name').innerHTML = tags[0] + ', ' + tags[1] + ', ...';
                find('.type-id').innerHTML =
                    '#' + String(tagIds[0]) + ', ' + String(tagIds[1]) + ', ...';
            } else {
                find('.type-name').innerHTML = tags[0] ?? '';
                find('.type-id').innerHTML = '#' + String(tagIds[0]);
            }
            const detail_str = tags.join(', ');
            setDetailItem(1, detail_str, 'icon-tags', detail_str, true);
            break;
        }
        case 'most_visited':
            find('.type-name').innerHTML = str_most_visited;
            setDetailItem(1, str_most_visited, 'icon-fire');
            find('.type-id').style.display = 'none';
            break;
        case 'best_rated':
            find('.type-name').innerHTML = str_best_rated;
            setDetailItem(1, str_best_rated, 'icon-star');
            find('.type-id').style.display = 'none';
            break;
        case 'list':
            find('.type-name').innerHTML = str_list;
            setDetailItem(1, str_list, 'icon-dice-solid');
            find('.type-id').style.display = 'none';
            break;
        case 'search': {
            const search_details = line.SEARCH_DETAILS;
            const search_icons: Record<string, string> = {
                allwords: 'gallery-icon-search',
                tags: 'gallery-icon-tag',
                date_posted: 'gallery-icon-calendar-plus',
                cat: 'gallery-icon-album',
                author: 'gallery-icon-user-edit',
                added_by: 'gallery-icon-user',
                filetypes: 'gallery-icon-file-image',
            };
            find('.type-name').innerHTML = line.SECTION;
            find('.type-id').innerHTML = '#' + String(line.SEARCH_ID ?? '');
            if (line.SEARCH_ID === null || line.SEARCH_ID === undefined || line.SEARCH_ID === '')
                find('.type-id').style.display = 'none';
            if (search_details === null || search_details === undefined) {
                find('.detail-item-1').style.display = 'none';
                break;
            }
            const active: Record<string, unknown> = {};
            Object.keys(search_details).forEach((k) => {
                if (search_details[k] !== null) active[k] = search_details[k];
            });
            const toArr = (v: unknown): unknown[] =>
                Array.isArray(v) ? v : typeof v === 'object' && v !== null ? Object.values(v) : [v];
            const arrJoin = (v: unknown, sep: string): string => toArr(v).map(stringify).join(sep);
            let count_item = 1;
            const active_more: string[] = [];
            const active_items = Object.keys(active);
            if (active_items.length > 0) {
                if (active['allwords'] !== undefined && active['allwords'] !== null) {
                    setDetailItem(
                        count_item,
                        arrJoin(active['allwords'], ' '),
                        search_icons['allwords'] + ' tiptip',
                        '<b>' +
                            (str_search_details['allwords'] ?? '') +
                            ' :</b> ' +
                            arrJoin(active['allwords'], ' ')
                    );
                    count_item++;
                    active_more.push('allwords');
                }
                if (active['cat'] !== undefined && active['cat'] !== null) {
                    const cat = arrJoin(active['cat'], ' + ');
                    const tmp = document.createElement('div');
                    tmp.innerHTML = cat;
                    setDetailItem(
                        count_item,
                        cat,
                        search_icons['cat'] + ' tiptip',
                        '<b>' +
                            (str_search_details['cat'] ?? '') +
                            ' :</b> ' +
                            ((tmp.textContent as string | null) ?? '').trim(),
                        true
                    );
                    count_item++;
                    active_more.push('cat');
                }
                if (count_item <= 2 && active['tags'] !== undefined && active['tags'] !== null) {
                    setDetailItem(
                        count_item,
                        arrJoin(active['tags'], ' + '),
                        search_icons['tags'] + ' tiptip',
                        '<b>' +
                            (str_search_details['tags'] ?? '') +
                            ' :</b> ' +
                            arrJoin(active['tags'], ' + '),
                        true
                    );
                    count_item++;
                    active_more.push('tags');
                }
                if (count_item <= 2) {
                    const badge_to_add = active_items.length === 1 ? 1 : count_item === 1 ? 2 : 1;
                    let badge_added = 0;
                    active_items.some((key) => {
                        if (['allwords', 'cat', 'tags'].includes(key)) return false;
                        const vals = toArr(active[key]);
                        setDetailItem(
                            count_item,
                            vals.map(stringify).join(' + '),
                            (search_icons[key] ?? '') + ' tiptip',
                            '<b>' +
                                (str_search_details[key] ?? '') +
                                ' :</b> ' +
                                vals.map(stringify).join(' + '),
                            true
                        );
                        count_item++;
                        badge_added++;
                        active_more.push(key);
                        return badge_added === badge_to_add;
                    });
                }
            } else {
                find('.detail-item-1').style.display = 'none';
            }
            if (active_items.length >= 3) {
                let count_more = 0;
                const details_str = Object.entries(active)
                    .filter(([k]) => !active_more.includes(k))
                    .map(([k, v]) => {
                        let vs = arrJoin(v, ' + ');
                        if (k === 'cat') {
                            const d = document.createElement('div');
                            d.innerHTML = vs;
                            vs = ((d.textContent as string | null) ?? '').trim();
                        }
                        count_more++;
                        return `<b>${str_search_details[k] ?? ''}</b> : ${vs}`;
                    })
                    .join(' <br />');
                setDetailItem(
                    3,
                    sprintf(str_and_more, count_more),
                    'icon-info-circled-1 tiptip',
                    details_str,
                    true
                );
            }
            break;
        }
        case 'favorites':
            find('.type-name').innerHTML = str_favorites;
            setDetailItem(1, str_favorites, 'icon-heart');
            find('.type-id').style.display = 'none';
            break;
        case 'recent_cats':
            find('.type-name').innerHTML = str_recent_cats;
            setDetailItem(1, str_recent_cats, 'icon-clock');
            find('.type-id').style.display = 'none';
            break;
        case 'recent_pics':
            find('.type-name').innerHTML = str_recent_pics;
            setDetailItem(1, str_recent_pics, 'icon-clock');
            find('.type-id').style.display = 'none';
            break;
        case 'categories':
            find('.type-name').innerHTML = line.CATEGORY ?? '';
            setDetailItem(
                1,
                line.CATEGORY ?? '',
                'icon-folder-open tiptip',
                line.FULL_CATEGORY_PATH
            );
            if (line.IMAGE === '') find('.type-id').style.display = 'none';
            break;
        case 'memories-1-year-ago':
            find('.type-name').innerHTML = str_memories;
            setDetailItem(1, str_memories, 'icon-clock');
            find('.type-id').style.display = 'none';
            break;
        case 'contact':
            find('.type-icon i').classList.add('line-icon', 'icon-mail-1', 'icon-yellow');
            find('.type-name').innerHTML = str_contact_form;
            setDetailItem(1, str_contact_form, '');
            find('.type-id').style.display = 'none';
            break;
        default:
            find('.type-icon i').classList.add('line-icon', 'icon-help-puzzle', 'icon-grey');
            find('.type-name').innerHTML = line.SECTION;
            find('.type-id').style.display = 'none';
            break;
    }

    if (line.IMAGE !== '') {
        find('.type-name').innerHTML = line.IMAGENAME ?? '';
        find('.type-icon').innerHTML = line.IMAGE;
        find('.type-id').innerHTML = '#' + String(line.IMAGEID);
        (find('.type-icon') as HTMLAnchorElement).href = line.EDIT_IMAGE;
        find('.type-icon').classList.remove('no-img');
        const img = newLine.querySelector<HTMLElement>('.type-icon img');
        if (img !== null) {
            img.title = str_edit_img;
            img.classList.add('tiptip');
        }
        find('.type-id').style.display = '';
    } else {
        newLine
            .querySelector<HTMLElement>('.type-icon .icon-file-image')
            ?.classList.remove('icon-file-image');
        find('.toggle-img-option').style.display = 'none';
        const sIdx = sections.indexOf(line.SECTION);
        if (sIdx !== -1) find('.type-icon i').classList.add(...icons[sIdx]!.split(' '));
        else console.warn('Unhandled section : ' + line.SECTION);
    }

    find('.detail-item-1').classList.remove('hide');
    if (line.TYPE === 'high') {
        const di1 = find('.detail-item-1');
        di1.innerHTML = str_dwld;
        di1.classList.add('icon-blue');
        di1.classList.remove('detail-item-1', 'hide');
        find('.date-dwld-icon').classList.add('icon-blue', 'icon-floppy');
    } else {
        newLine.querySelector<HTMLElement>('.date-dwld-icon')?.remove();
    }

    document.querySelector<HTMLElement>('.tab')!.appendChild(newLine);
}

function makeFilter(html: string, iconClass: string, onRemove: () => void): HTMLElement {
    const newFilter = document.getElementById('default-filter')!.cloneNode(true) as HTMLElement;
    newFilter.classList.remove('hide');
    newFilter.querySelector<HTMLElement>('.filter-title')!.innerHTML = html;
    const icon = newFilter.querySelector<HTMLElement>('.filter-icon')!;
    if (iconClass) icon.classList.add(iconClass);
    newFilter.querySelector<HTMLElement>('.remove-filter')!.addEventListener('click', () => {
        newFilter.remove();
        onRemove();
        checkFilters();
    });
    document.querySelector('.filter-container')!.appendChild(newFilter);
    checkFilters();
    return newFilter;
}

function addUserFilter(username: string) {
    makeFilter(username, 'icon-user', () => {
        current_param.user_id = '-1';
        current_param.pageNumber = 0;
        fillHistoryResult(current_param);
        document.querySelectorAll<HTMLElement>('.summary-guests').forEach((el) => {
            el.style.display = '';
        });
    });
    document.querySelectorAll<HTMLElement>('.summary-guests').forEach((el) => {
        el.style.display = 'none';
    });
}

function addGuestFilter(username: string) {
    makeFilter(username, 'icon-user-secret', () => {
        current_param.user_id = '-1';
        current_param.pageNumber = 0;
        fillHistoryResult(current_param);
    });
}

function addIpFilter(ip: string) {
    const f = makeFilter(ip, '', () => {
        current_param.ip = '';
        current_param.pageNumber = 0;
        fillHistoryResult(current_param);
    });
    f.querySelector<HTMLElement>('.filter-icon')!.innerHTML = 'IP ';
    f.querySelector<HTMLElement>('.filter-icon')!.classList.add('bold');
}

function addImageFilter(img_id: string) {
    makeFilter('Image #' + img_id, 'icon-picture', () => {
        current_param.image_id = '';
        current_param.pageNumber = 0;
        fillHistoryResult(current_param);
    });
}

function updateArrows(actualPage: number, maxPage: number) {
    document
        .querySelector('.pagination-arrow.left')
        ?.classList.toggle('unavailable', actualPage === 0);
    document
        .querySelector('.pagination-arrow.rigth')
        ?.classList.toggle('unavailable', actualPage === maxPage - 1);
}

function updatePagination(maxPage: number) {
    updateArrows(current_param.pageNumber, maxPage);
    const container = document.querySelector<HTMLElement>('.pagination-item-container')!;
    container.innerHTML = '';
    container.insertAdjacentHTML(
        'beforeend',
        `<a class='actual'>${current_param.pageNumber + 1}/${maxPage}</a>`
    );
}

function checkFilters() {
    const hasFilters =
        (document.querySelector('.filter-container')?.childElementCount ?? 0) - 1 > 0;
    document.querySelectorAll<HTMLElement>('.filter-tags label').forEach((el) => {
        el.style.display = hasFilters ? '' : 'none';
    });
}

// Hover-to-resolve IP geolocation popup (extracted from history.tpl footer_script).
Array.from(document.querySelectorAll<HTMLElement>('.IP')).forEach((ipEl) => {
    let handled = false;
    const onFirstEnter = () => {
        if (handled) return;
        handled = true;
        ipEl.removeEventListener('mouseenter', onFirstEnter);

        GeoIp.get(ipEl.textContent, (data) => {
            if (data.fullName === undefined || data.fullName === '') return;

            let content = data.fullName;
            if (
                data.latitude !== undefined &&
                data.latitude !== 0 &&
                data.region_name !== undefined &&
                data.region_name !== ''
            ) {
                content +=
                    '<br><a class="ipGeoOpen" data-lat="' +
                    data.latitude +
                    '" data-lon="' +
                    data.longitude +
                    '"';
                content += ' href="#">show on a Google Map</a>';
            }

            tippy(ipEl, {
                content,
                allowHTML: true,
                placement: 'right',
                maxWidth: 320,
                trigger: 'mouseenter focus',
            });
        });
    };
    ipEl.addEventListener('mouseenter', onFirstEnter);
});

document.addEventListener('click', (e) => {
    const target = (e.target as HTMLElement | null)?.closest<HTMLElement>('.ipGeoOpen');
    if (!target) return;
    e.preventDefault();
    const lat = target.dataset['lat'];
    const lon = target.dataset['lon'];
    const parent = target.parentElement;
    target.remove();

    let append = '<br><img width=300 height=220 src="http://maps.googleapis.com/maps/api/staticmap';
    append += '?sensor=false&size=300x220&zoom=6&markers=size:tiny%7C' + lat + ',' + lon + '">';

    if (parent) parent.insertAdjacentHTML('beforeend', append);
});

export {};
