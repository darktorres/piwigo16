import { initModule } from './moduleInit.js';
import { pwgDatepicker } from './datepicker.js';
import { sprintf } from './common.js';
import tippy from 'tippy.js';
import { GeoIp } from './geoip.js';

interface HistoryConfig {
    userName?: string;
    apiMethod?: string;
    strDwld?: string;
    strMostVisited?: string;
    strBestRated?: string;
    strList?: string;
    strFavorites?: string;
    strRecentCats?: string;
    strRecentPics?: string;
    strMemories?: string;
    strNoLongerExistPhoto?: string;
    strTags?: string;
    unitMb?: string;
    strGuest?: string;
    strContactForm?: string;
    strEditImg?: string;
    strSearchDetails?: Record<string, string>;
    strAndMore?: string;
    guestId?: string;
    userId?: string;
    imageId?: string;
    ip?: string;
}

interface HistoryParams {
    start: string;
    end: string;
    types: Record<number, string>;
    user_id: string;
    image_id: string;
    filename: string;
    ip: string;
    display_thumbnail: string;
    pageNumber: number;
}

interface HistoryLine {
    DATE: string;
    TIME: string;
    USERNAME: string;
    USERID: string;
    IP: string;
    IMAGEID: string;
    EDIT_IMAGE: string;
    IMAGE: string;
    IMAGENAME?: string;
    TYPE: string;
    SECTION: string;
    TAGS?: string[];
    TAGIDS?: string[];
    CATEGORY?: string;
    FULL_CATEGORY_PATH?: string;
    SEARCH_ID?: string | number;
    SEARCH_DETAILS?: Record<string, string | string[] | Record<string, string> | null>;
}

interface HistorySummary {
    NB_LINES: string | number;
    FILESIZE: string | number;
    USERS: string | number;
    GUESTS: string;
    MEMBERS: Record<string, string | number>[];
    SORTED_MEMBERS: Record<string, string | number>;
}

// Module-level mutable config - initialized by init()
let filter_user_name: string;
let API_METHOD: string;
let today: string;
let current_param: HistoryParams;
let str_dwld: string;
let str_most_visited: string;
let str_best_rated: string;
let str_list: string;
let str_favorites: string;
let str_recent_cats: string;
let str_recent_pics: string;
let str_memories: string;
let str_no_longer_exist_photo: string;
let unit_MB: string;
let str_guest: string;
let str_contact_form: string;
let str_edit_img: string;
let str_search_details: Record<string, string>;
let str_and_more: string;
let guest_id: string;

export function init(cfg: HistoryConfig): void {
    const dateObj = new Date();
    let month: string | number = dateObj.getUTCMonth() + 1;
    let day: string | number = dateObj.getUTCDate();
    const year = dateObj.getUTCFullYear();
    if (month < 10) month = '0' + String(month);
    if (day < 10) day = '0' + String(day);
    today = year + '-' + String(month) + '-' + String(day);

    filter_user_name = cfg.userName ?? '';
    API_METHOD = cfg.apiMethod ?? '';
    str_dwld = cfg.strDwld ?? '';
    str_most_visited = cfg.strMostVisited ?? '';
    str_best_rated = cfg.strBestRated ?? '';
    str_list = cfg.strList ?? '';
    str_favorites = cfg.strFavorites ?? '';
    str_recent_cats = cfg.strRecentCats ?? '';
    str_recent_pics = cfg.strRecentPics ?? '';
    str_memories = cfg.strMemories ?? '';
    str_no_longer_exist_photo = cfg.strNoLongerExistPhoto ?? '';
    unit_MB = cfg.unitMb ?? '';
    str_guest = cfg.strGuest ?? '';
    str_contact_form = cfg.strContactForm ?? '';
    str_edit_img = cfg.strEditImg ?? '';
    str_search_details = cfg.strSearchDetails ?? {};
    str_and_more = cfg.strAndMore ?? '';
    guest_id = cfg.guestId ?? '';

    current_param = {
        start: '',
        end: today,
        types: { 0: 'none', 1: 'picture', 2: 'high', 3: 'other' },
        user_id: cfg.userId ?? '-1',
        image_id: cfg.imageId ?? '',
        filename: '',
        ip: cfg.ip ?? '',
        display_thumbnail: 'display_thumbnail_classic',
        pageNumber: 0,
    };

    pwgDatepicker(document.querySelectorAll('[data-datepicker]'));
    activateLineOptions();
    checkFilters();

    if (current_param.ip !== '') addIpFilter(current_param.ip);
    if (current_param.image_id !== '') addImageFilter(current_param.image_id);
    if (current_param.user_id !== '-1') addUserFilter(filter_user_name);

    document.querySelector<HTMLSelectElement>('.elem-type-select')?.addEventListener('change', function () {
        console.log(document.querySelector<HTMLOptionElement>('.elem-type-select option:checked')?.getAttribute('value'));
        const val = document.querySelector<HTMLOptionElement>('.elem-type-select option:checked')?.getAttribute('value');
        if (val === 'visited') {
            current_param.types = { 0: 'none', 1: 'picture' };
        } else if (val === 'downloaded') {
            current_param.types = { 0: 'high', 1: 'other' };
        } else {
            current_param.types = { 0: 'none', 1: 'picture', 2: 'high', 3: 'other' };
        }
        fillHistoryResult(current_param);
    });

    document.querySelector<HTMLElement>('.date-start')?.addEventListener('change', function () {
        const newStart = document.querySelector<HTMLInputElement>('.date-start input[name="start"]')?.getAttribute('value') ?? '';
        if (current_param.start !== newStart) {
            current_param.start = newStart;
            current_param.pageNumber = 0;
            fillHistoryResult(current_param);
        }
    });

    document.querySelector<HTMLElement>('.date-end')?.addEventListener('change', function () {
        const newValue = document.querySelector<HTMLInputElement>('.date-end input[name="end"]')?.getAttribute('value') ?? '';
        if (current_param.end !== newValue) {
            current_param.end = newValue;
            current_param.pageNumber = 0;
            if (newValue !== '1899-12-31') {
                fillHistoryResult(current_param);
            }
        }
    });

    document.getElementById('start_unset')?.addEventListener('click', function () {
        console.log('here' + current_param.start);
        // @ts-expect-error preserved from original JS: !string == string is always false
        if (!current_param.start == '') {
            current_param.pageNumber = 0;
            current_param.start = '';
            fillHistoryResult(current_param);
        }
    });

    document.getElementById('end_unset')?.addEventListener('click', function () {
        // @ts-expect-error preserved from original JS: !string == string is always false
        if (!current_param.start == today) {
            current_param.end = today;
            current_param.pageNumber = 0;
            fillHistoryResult(current_param);
        }
    });

    document.querySelector<HTMLElement>('.pagination-arrow.right')?.addEventListener('click', function () {
        current_param.pageNumber += 1;
        fillHistoryResult(current_param);
    });

    document.querySelector<HTMLElement>('.pagination-arrow.left')?.addEventListener('click', function () {
        current_param.pageNumber -= 1;
        fillHistoryResult(current_param);
    });

    document.querySelector<HTMLElement>('.refresh-results')?.addEventListener('click', function () {
        fillHistoryResult(current_param);
    });

    function activateLineOptions(): void {
        document.querySelectorAll<HTMLElement>('.search-line .img-option').forEach(el => { el.style.display = 'none'; });

        document.querySelectorAll<HTMLElement>('.search-line .toggle-img-option').forEach(function (el) {
            el.addEventListener('click', function () {
                const imgOption = (this as HTMLElement).closest<HTMLElement>('.search-line')?.querySelector<HTMLElement>('.img-option');
                if (imgOption) imgOption.style.display = getComputedStyle(imgOption).display === 'none' ? '' : 'none';
            });
        });

        document.addEventListener('mouseup', function (e) {
            e.stopPropagation();
            let option_is_clicked = false;
            document.querySelectorAll<HTMLElement>('.img-option span').forEach(function (el) {
                if (el.contains(e.target as Node)) option_is_clicked = true;
            });
            if (!option_is_clicked) {
                document.querySelectorAll<HTMLElement>('.search-line .img-option').forEach(el => { el.style.display = 'none'; });
            }
        });
    }

    function fillSummaryResult(summary: HistorySummary): void {
        document.querySelector<HTMLElement>('.user-list')!.innerHTML = '';
        document.querySelector<HTMLElement>('.summary-lines .summary-data')!.innerHTML = String(summary.NB_LINES);
        document.querySelector<HTMLElement>('.summary-weight .summary-data')!.innerHTML = unit_MB.replace('%s', String(summary.FILESIZE));
        document.querySelector<HTMLElement>('.summary-users .summary-data')!.innerHTML = String(summary.USERS);
        document.querySelector<HTMLElement>('.summary-guests .summary-data')!.innerHTML = String(summary.GUESTS);

        if (summary.GUESTS.split(' ')[0] !== '0') {
            const guestDataEl = document.querySelector<HTMLElement>('.summary-guests .summary-data')!;
            guestDataEl.classList.add('icon-plus-circled');
            guestDataEl.addEventListener('click', function () {
                if (current_param.user_id === '-1') {
                    current_param.user_id = guest_id;
                    addGuestFilter(str_guest);
                    fillHistoryResult(current_param);
                }
            });
            guestDataEl.style.cursor = 'pointer';
            document.querySelector<HTMLElement>('.summary-guests')!.style.display = '';
        } else {
            document.querySelector<HTMLElement>('.summary-guests')!.style.display = 'none';
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
        const userDot = document.querySelector<HTMLElement>('.user-dot')!;
        userDot.setAttribute('title', user_dot_title);
        userDot.classList.add('tiptip');

        let tmp = 0;
        document.querySelector<HTMLElement>('.user-dot')!.style.display = 'none';
        for (const [key] of Object.entries(summary.SORTED_MEMBERS)) {
            if (tmp < 5) {
                const new_user_item = document.getElementById('-2')!.cloneNode(true) as HTMLElement;
                new_user_item.classList.remove('hide');
                new_user_item.querySelector<HTMLElement>('.user-item-name')!.innerHTML = key;
                new_user_item.dataset.userId = String(id_of[key]);
                new_user_item.addEventListener('click', function () {
                    if (current_param.user_id !== String(id_of[key])) {
                        current_param.user_id = (this as HTMLElement).dataset.userId ?? '-1';
                        addUserFilter(key);
                        fillHistoryResult(current_param);
                    }
                });
                document.querySelector<HTMLElement>('.user-list')!.appendChild(new_user_item);
                tmp++;
            } else {
                document.querySelector<HTMLElement>('.user-dot')!.style.display = '';
            }
        }
    }

    function showResults(doShow: boolean): void {
        console.log('EMPTY');
        if (doShow) {
            document.querySelector<HTMLElement>('.search-summary')!.style.display = '';
            document.querySelector<HTMLElement>('.container')!.style.display = '';
        } else {
            document.querySelector<HTMLElement>('.search-summary')!.style.display = 'none';
            document.querySelector<HTMLElement>('.container')!.style.display = 'none';
        }
    }

    function fillHistoryResult(ajaxParam: HistoryParams): void {
        fetch(API_METHOD, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(ajaxParam as unknown as Record<string, string>),
        })
            .then(r => r.json())
            .then(function (raw_data: { result: { lines: HistoryLine[]; params: { display_thumbnail: string }; maxPage: number; summary: HistorySummary } }) {
                const data = raw_data.result.lines;
                const imageDisplay = raw_data.result.params.display_thumbnail;
                const maxPage = raw_data.result.maxPage;
                const summary = raw_data.result.summary;

                showResults(false);
                document.querySelector<HTMLElement>('.loading')?.classList.remove('hide');
                document.querySelector<HTMLElement>('.noResults')!.style.display = 'none';
                document.querySelector<HTMLElement>('.tab')!.innerHTML = '';

                if (data.length > 0) {
                    let id = 0;
                    data.forEach((line) => { lineConstructor(line, id, imageDisplay); id++; });
                    fillSummaryResult(summary);
                    showResults(true);
                    document.querySelector<HTMLElement>('.noResults')!.style.display = 'none';
                } else {
                    showResults(false);
                    document.querySelector<HTMLElement>('.noResults')!.style.display = '';
                }

                activateLineOptions();
                document.querySelector<HTMLElement>('.loading')?.classList.add('hide');
                updatePagination(maxPage);
                tippy('.tiptip', { delay: 0, placement: 'top' });
            })
            .catch(function (e: unknown) { console.log(e); });
    }

    function lineConstructor(line: HistoryLine, id: number, imageDisplay: string): void {
        const newLine = document.getElementById('-1')!.cloneNode(true) as HTMLElement;

        const sections = [
            'categories', 'tags', 'best_rated', 'memories-1-year-ago',
            'list', 'search', 'most_visited', 'recent_pics', 'recent_cats', 'favorites',
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

        newLine.querySelector<HTMLElement>('.date-day')!.innerHTML = line.DATE;
        newLine.querySelector<HTMLElement>('.date-hour')!.innerHTML = line.TIME;
        newLine.querySelector<HTMLElement>('.user-name')!.innerHTML = line.USERNAME + '<i class="add-filter icon-plus-circled"></i>';
        newLine.querySelector<HTMLElement>('.user-name')!.id = line.USERID;

        if (current_param.user_id === '-1') {
            newLine.querySelector<HTMLElement>('.user-name')?.addEventListener('click', function () {
                current_param.user_id = (this as HTMLElement).id;
                current_param.pageNumber = 0;
                addUserFilter((this as HTMLElement).innerHTML);
                fillHistoryResult(current_param);
            });
        }

        newLine.querySelector<HTMLElement>('.user-ip')!.innerHTML = line.IP + '<i class="add-filter icon-plus-circled"></i>';
        newLine.querySelector<HTMLElement>('.user-ip')!.dataset.ip = line.IP;
        if (current_param.ip === '') {
            newLine.querySelector<HTMLElement>('.user-ip')?.addEventListener('click', function () {
                current_param.ip = (this as HTMLElement).dataset.ip ?? '';
                current_param.pageNumber = 0;
                addIpFilter((this as HTMLElement).innerHTML);
                fillHistoryResult(current_param);
            });
        }

        newLine.querySelector<HTMLElement>('.add-img-as-filter')!.dataset.imgId = line.IMAGEID;
        if (current_param.image_id === '') {
            newLine.querySelector<HTMLElement>('.add-img-as-filter')?.addEventListener('click', function () {
                current_param.image_id = (this as HTMLElement).dataset.imgId ?? '';
                current_param.pageNumber = 0;
                addImageFilter((this as HTMLElement).dataset.imgId ?? '');
                fillHistoryResult(current_param);
            });
        }

        if (line.EDIT_IMAGE !== '') {
            newLine.querySelector<HTMLAnchorElement>('.edit-img')?.setAttribute('href', line.EDIT_IMAGE);
        } else {
            const editImg = newLine.querySelector<HTMLAnchorElement>('.edit-img')!;
            editImg.setAttribute('href', '#');
            editImg.classList.add('notClickable', 'tiptip');
            editImg.setAttribute('title', str_no_longer_exist_photo);
            editImg.addEventListener('click', function (e) { e.preventDefault(); });
        }

        switch (line.SECTION) {
            case 'tags': {
                const tags = line.TAGS ?? [];
                const tagids = line.TAGIDS ?? [];
                if (tags.length > 1 && tags.length <= 2) {
                    newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = tags[0] + ', ' + tags[1] + ', ...';
                    newLine.querySelector<HTMLElement>('.type-id')!.innerHTML = '#' + tagids[0] + ', ' + tagids[1] + ', ...';
                } else if (tags.length > 2) {
                    newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = tags[0] + ', ' + tags[1] + ', ' + tags[2] + ', ...';
                    newLine.querySelector<HTMLElement>('.type-id')!.innerHTML = '#' + tagids[0] + ', ' + tagids[1] + ', ' + tagids[2] + ', ...';
                } else {
                    newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = tags[0] ?? '';
                    newLine.querySelector<HTMLElement>('.type-id')!.innerHTML = '#' + (tagids[0] ?? '');
                }
                let detail_str = '';
                tags.forEach((tag) => { detail_str += tag + ', '; });
                detail_str = detail_str.slice(0, -2);
                const detailItem1 = newLine.querySelector<HTMLElement>('.detail-item-1')!;
                detailItem1.innerHTML = detail_str;
                detailItem1.setAttribute('title', detail_str);
                detailItem1.classList.remove('hide', 'add');
                detailItem1.classList.add('icon-tags');
                break;
            }
            case 'most_visited': {
                newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = str_most_visited;
                const detail = newLine.querySelector<HTMLElement>('.detail-item-1')!;
                detail.innerHTML = str_most_visited;
                detail.classList.add('icon-fire');
                newLine.querySelector<HTMLElement>('.type-id')!.style.display = 'none';
                break;
            }
            case 'best_rated': {
                newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = str_best_rated;
                const detail = newLine.querySelector<HTMLElement>('.detail-item-1')!;
                detail.innerHTML = str_best_rated;
                detail.classList.add('icon-star');
                newLine.querySelector<HTMLElement>('.type-id')!.style.display = 'none';
                break;
            }
            case 'list': {
                newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = str_list;
                const detail = newLine.querySelector<HTMLElement>('.detail-item-1')!;
                detail.innerHTML = str_list;
                detail.classList.add('icon-dice-solid');
                newLine.querySelector<HTMLElement>('.type-id')!.style.display = 'none';
                break;
            }
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
                newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = line.SECTION;
                newLine.querySelector<HTMLElement>('.type-id')!.innerHTML = '#' + String(line.SEARCH_ID ?? '');
                if (!line.SEARCH_ID) {
                    newLine.querySelector<HTMLElement>('.type-id')!.style.display = 'none';
                }
                if (!search_details) {
                    newLine.querySelector<HTMLElement>('.detail-item-1')!.style.display = 'none';
                    break;
                }
                const active_search_details: Record<string, string | string[] | Record<string, string>> = {};
                Object.keys(search_details).forEach((key) => {
                    if (search_details[key] !== null) {
                        active_search_details[key] = search_details[key] as string | string[] | Record<string, string>;
                    }
                });
                let count_item = 1;
                const active_more: string[] = [];
                const active_items = Object.keys(active_search_details);
                if (active_items.length > 0) {
                    if (active_search_details.allwords) {
                        const detailEl = newLine.querySelector<HTMLElement>('.detail-item-' + String(count_item))!;
                        detailEl.innerHTML = (active_search_details.allwords as string[]).join(' ');
                        detailEl.classList.add(search_icons.allwords, 'tiptip');
                        detailEl.setAttribute('title', '<b>' + str_search_details['allwords'] + ' :</b> ' + (active_search_details.allwords as string[]).join(' '));
                        count_item++;
                        active_more.push('allwords');
                    }
                    if (active_search_details.cat) {
                        const array_cat = Object.values(active_search_details.cat as Record<string, string>);
                        const cat = array_cat.join(' + ');
                        const temp_div = document.createElement('div');
                        temp_div.innerHTML = cat;
                        const text = temp_div.textContent?.trim() ?? '';
                        const detailEl = newLine.querySelector<HTMLElement>('.detail-item-' + String(count_item))!;
                        detailEl.innerHTML = cat;
                        detailEl.classList.add(search_icons.cat, 'tiptip');
                        detailEl.setAttribute('title', '<b>' + str_search_details['cat'] + ' :</b> ' + text);
                        detailEl.classList.remove('hide');
                        count_item++;
                        active_more.push('cat');
                    }
                    if (count_item <= 2 && active_search_details.tags) {
                        const array_tags = Object.values(active_search_details.tags as Record<string, string>);
                        const detailEl = newLine.querySelector<HTMLElement>('.detail-item-' + String(count_item))!;
                        detailEl.innerHTML = array_tags.join(' + ');
                        detailEl.classList.add(search_icons.tags, 'tiptip');
                        detailEl.setAttribute('title', '<b>' + str_search_details['tags'] + ' :</b> ' + array_tags.join(' + '));
                        detailEl.classList.remove('hide');
                        count_item++;
                        active_more.push('tags');
                    }
                    if (count_item <= 2) {
                        const badge_to_add = active_items.length === 1 ? 1 : count_item === 1 ? 2 : 1;
                        let badge_added = 0;
                        active_items.some((key) => {
                            if (key !== 'allwords' && key !== 'cat' && key !== 'tags') {
                                let array_key: string[];
                                if (Array.isArray(active_search_details[key])) {
                                    array_key = active_search_details[key] as string[];
                                } else if (typeof active_search_details[key] === 'object') {
                                    array_key = Object.values(active_search_details[key] as Record<string, string>);
                                } else {
                                    array_key = [active_search_details[key] as string];
                                }
                                const detailEl = newLine.querySelector<HTMLElement>('.detail-item-' + String(count_item))!;
                                detailEl.innerHTML = array_key.join(' + ');
                                detailEl.classList.add(search_icons[key] ?? '', 'tiptip');
                                detailEl.setAttribute('title', '<b>' + (str_search_details[key] ?? '') + ' :</b> ' + array_key.join(' + '));
                                detailEl.classList.remove('hide');
                                count_item++;
                                badge_added++;
                                active_more.push(key);
                                if (badge_added === badge_to_add) return true;
                            }
                            return false;
                        });
                    }
                } else {
                    newLine.querySelector<HTMLElement>('.detail-item-1')!.style.display = 'none';
                }
                if (active_items.length >= 3) {
                    let count_more = 0;
                    const search_details_str = Object.entries(active_search_details)
                        .filter(([key]) => !active_more.includes(key))
                        .map(([key, value]) => {
                            let value_str: string;
                            if (Array.isArray(value)) {
                                value_str = (value as string[]).join(' + ');
                            } else if (typeof value === 'object') {
                                value_str = Object.entries(value as Record<string, string>)
                                    .map(([_k, v]) => v)
                                    .join(' + ');
                            } else {
                                value_str = value as string;
                            }
                            if (key === 'cat') {
                                const temp_div = document.createElement('div');
                                temp_div.innerHTML = value_str;
                                value_str = temp_div.textContent?.trim() ?? '';
                            }
                            count_more++;
                            return `<b>${str_search_details[key] ?? ''}</b> : ${value_str}`;
                        })
                        .join(' <br />');
                    const detailItem3 = newLine.querySelector<HTMLElement>('.detail-item-3')!;
                    detailItem3.innerHTML = sprintf(str_and_more, count_more);
                    detailItem3.classList.add('icon-info-circled-1', 'tiptip');
                    detailItem3.setAttribute('title', search_details_str);
                    detailItem3.classList.remove('hide');
                }
                break;
            }
            case 'favorites': {
                newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = str_favorites;
                const detail = newLine.querySelector<HTMLElement>('.detail-item-1')!;
                detail.innerHTML = str_favorites;
                detail.classList.add('icon-heart');
                newLine.querySelector<HTMLElement>('.type-id')!.style.display = 'none';
                break;
            }
            case 'recent_cats': {
                newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = str_recent_cats;
                const detail = newLine.querySelector<HTMLElement>('.detail-item-1')!;
                detail.innerHTML = str_recent_cats;
                detail.classList.add('icon-clock');
                newLine.querySelector<HTMLElement>('.type-id')!.style.display = 'none';
                break;
            }
            case 'recent_pics': {
                newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = str_recent_pics;
                const detail = newLine.querySelector<HTMLElement>('.detail-item-1')!;
                detail.innerHTML = str_recent_pics;
                detail.classList.add('icon-clock');
                newLine.querySelector<HTMLElement>('.type-id')!.style.display = 'none';
                break;
            }
            case 'categories': {
                newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = line.CATEGORY ?? '';
                const detail = newLine.querySelector<HTMLElement>('.detail-item-1')!;
                detail.innerHTML = line.CATEGORY ?? '';
                detail.classList.add('icon-folder-open', 'tiptip');
                detail.setAttribute('title', line.FULL_CATEGORY_PATH ?? '');
                if (line.IMAGE === '') newLine.querySelector<HTMLElement>('.type-id')!.style.display = 'none';
                break;
            }
            case 'memories-1-year-ago': {
                newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = str_memories;
                const detail = newLine.querySelector<HTMLElement>('.detail-item-1')!;
                detail.innerHTML = str_memories;
                detail.classList.add('icon-clock');
                newLine.querySelector<HTMLElement>('.type-id')!.style.display = 'none';
                break;
            }
            case 'contact': {
                newLine.querySelector<HTMLElement>('.type-icon i')!.classList.add('line-icon', 'icon-mail-1', 'icon-yellow');
                newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = str_contact_form;
                newLine.querySelector<HTMLElement>('.detail-item-1')!.innerHTML = str_contact_form;
                newLine.querySelector<HTMLElement>('.type-id')!.style.display = 'none';
                break;
            }
            default: {
                newLine.querySelector<HTMLElement>('.type-icon i')!.classList.add('line-icon', 'icon-help-puzzle', 'icon-grey');
                newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = line.SECTION;
                newLine.querySelector<HTMLElement>('.type-id')!.style.display = 'none';
                break;
            }
        }

        if (line.IMAGE !== '') {
            newLine.querySelector<HTMLElement>('.type-name')!.innerHTML = line.IMAGENAME ?? '';
            newLine.querySelector<HTMLElement>('.type-icon')!.innerHTML = line.IMAGE;
            newLine.querySelector<HTMLElement>('.type-id')!.innerHTML = '#' + line.IMAGEID;
            newLine.querySelector<HTMLAnchorElement>('.type-icon')?.setAttribute('href', line.EDIT_IMAGE);
            newLine.querySelector<HTMLElement>('.type-icon')?.classList.remove('no-img');
            newLine.querySelector<HTMLImageElement>('.type-icon img')?.setAttribute('title', str_edit_img);
            newLine.querySelector<HTMLImageElement>('.type-icon img')?.classList.add('tiptip');
            newLine.querySelector<HTMLElement>('.type-id')!.style.display = '';
        } else {
            const iconEl = newLine.querySelector<HTMLElement>('.type-icon .icon-file-image');
            if (iconEl) iconEl.classList.remove('icon-file-image');
            const toggleOption = newLine.querySelector<HTMLElement>('.toggle-img-option');
            if (toggleOption) toggleOption.style.display = 'none';

            const sectionIdx = sections.indexOf(line.SECTION);
            if (sectionIdx !== -1) {
                newLine.querySelector<HTMLElement>('.type-icon i')!.classList.add(...icons[sectionIdx].split(' '));
            } else {
                console.log('Unhandled section : ' + line.SECTION);
            }
        }

        newLine.querySelector<HTMLElement>('.detail-item-1')?.classList.remove('hide');
        if (line.TYPE === 'high') {
            const detailItem = newLine.querySelector<HTMLElement>('.detail-item-1')!;
            detailItem.innerHTML = str_dwld;
            detailItem.classList.add('icon-blue');
            detailItem.classList.remove('detail-item-1', 'hide');
            newLine.querySelector<HTMLElement>('.date-dwld-icon')?.classList.add('icon-blue', 'icon-floppy');
        } else {
            const dwldIcon = newLine.querySelector<HTMLElement>('.date-dwld-icon');
            if (dwldIcon) dwldIcon.remove();
        }

        void imageDisplay;
        displayLine(newLine);
    }

    function displayLine(line: HTMLElement): void {
        document.querySelector<HTMLElement>('.tab')?.appendChild(line);
    }

    function addUserFilter(username: string): void {
        const newFilter = document.getElementById('default-filter')!.cloneNode(true) as HTMLElement;
        newFilter.classList.remove('hide');
        newFilter.querySelector<HTMLElement>('.filter-title')!.innerHTML = username;
        newFilter.querySelector<HTMLElement>('.filter-icon')!.classList.add('icon-user');
        newFilter.querySelector<HTMLElement>('.remove-filter')?.addEventListener('click', function () {
            (this as HTMLElement).parentElement?.remove();
            current_param.user_id = '-1';
            current_param.pageNumber = 0;
            fillHistoryResult(current_param);
            checkFilters();
            document.querySelector<HTMLElement>('.summary-guests')!.style.display = '';
        });
        document.querySelector<HTMLElement>('.summary-guests')!.style.display = 'none';
        document.querySelector<HTMLElement>('.filter-container')?.appendChild(newFilter);
        checkFilters();
    }

    function addGuestFilter(username: string): void {
        const newFilter = document.getElementById('default-filter')!.cloneNode(true) as HTMLElement;
        newFilter.classList.remove('hide');
        newFilter.querySelector<HTMLElement>('.filter-title')!.innerHTML = username;
        newFilter.querySelector<HTMLElement>('.filter-icon')!.classList.add('icon-user-secret');
        newFilter.querySelector<HTMLElement>('.remove-filter')?.addEventListener('click', function () {
            (this as HTMLElement).parentElement?.remove();
            current_param.user_id = '-1';
            current_param.pageNumber = 0;
            fillHistoryResult(current_param);
            checkFilters();
        });
        document.querySelector<HTMLElement>('.filter-container')?.appendChild(newFilter);
        checkFilters();
    }

    function addIpFilter(ip: string): void {
        const newFilter = document.getElementById('default-filter')!.cloneNode(true) as HTMLElement;
        newFilter.classList.remove('hide');
        newFilter.querySelector<HTMLElement>('.filter-title')!.innerHTML = ip;
        const filterIcon = newFilter.querySelector<HTMLElement>('.filter-icon')!;
        filterIcon.innerHTML = 'IP ';
        filterIcon.classList.add('bold');
        newFilter.querySelector<HTMLElement>('.remove-filter')?.addEventListener('click', function () {
            (this as HTMLElement).parentElement?.remove();
            current_param.ip = '';
            current_param.pageNumber = 0;
            fillHistoryResult(current_param);
            checkFilters();
        });
        document.querySelector<HTMLElement>('.filter-container')?.appendChild(newFilter);
        checkFilters();
    }

    function addImageFilter(img_id: string): void {
        const newFilter = document.getElementById('default-filter')!.cloneNode(true) as HTMLElement;
        newFilter.classList.remove('hide');
        newFilter.querySelector<HTMLElement>('.filter-title')!.innerHTML = 'Image #' + img_id;
        newFilter.querySelector<HTMLElement>('.filter-icon')!.classList.add('icon-picture');
        newFilter.querySelector<HTMLElement>('.remove-filter')?.addEventListener('click', function () {
            (this as HTMLElement).parentElement?.remove();
            current_param.image_id = '';
            current_param.pageNumber = 0;
            fillHistoryResult(current_param);
            checkFilters();
        });
        document.querySelector<HTMLElement>('.filter-container')?.appendChild(newFilter);
        checkFilters();
    }

    function updateArrows(actualPage: number, maxPage: number): void {
        if (actualPage === 0) {
            document.querySelector<HTMLElement>('.pagination-arrow.left')?.classList.add('unavailable');
        } else {
            document.querySelector<HTMLElement>('.pagination-arrow.left')?.classList.remove('unavailable');
        }
        if (actualPage === maxPage - 1) {
            document.querySelector<HTMLElement>('.pagination-arrow.right')?.classList.add('unavailable');
        } else {
            document.querySelector<HTMLElement>('.pagination-arrow.right')?.classList.remove('unavailable');
        }
    }

    function updatePagination(maxPage: number): void {
        updateArrows(current_param.pageNumber, maxPage);
        const container = document.querySelector<HTMLElement>('.pagination-item-container')!;
        container.innerHTML = '';
        container.insertAdjacentHTML('beforeend', "<a class='actual'>" + String(current_param.pageNumber + 1) + '/' + String(maxPage) + '</a>');
    }

    function checkFilters(): void {
        const filterContainer = document.querySelector<HTMLElement>('.filter-container');
        if (filterContainer && filterContainer.childElementCount - 1 > 0) {
            document.querySelector<HTMLElement>('.filter-tags label')!.style.display = '';
        } else {
            document.querySelector<HTMLElement>('.filter-tags label')!.style.display = 'none';
        }
    }

    // GeoIP loading for IP addresses
    document.querySelectorAll<HTMLElement>('.IP').forEach(function (ipEl) {
        ipEl.addEventListener('mouseenter', function () {
            type GeoEl = HTMLElement & { _isOver?: boolean; _tippyInstance?: { setContent(c: string): void; show(): void } };
            const that = ipEl as GeoEl;
            that._isOver = true;
            that.addEventListener('mouseleave', function () { delete that._isOver; }, { once: true });
            GeoIp.get(that.textContent?.trim() ?? '', function (data: { fullName?: string; latitude?: string; longitude?: string; region_name?: string }) {
                if (!data.fullName) return;
                let content = data.fullName;
                if (data.latitude && data.region_name) {
                    content += '<br><a class="ipGeoOpen" data-lat="' + data.latitude + '" data-lon="' + (data.longitude ?? '') + '"';
                    content += ' href="#">show on a Google Map</a>';
                }
                if (that._tippyInstance) {
                    that._tippyInstance.setContent(content);
                } else {
                    const instance = tippy(that, { content, allowHTML: true, interactive: true, placement: 'right', maxWidth: 320 });
                    that._tippyInstance = Array.isArray(instance) ? (instance[0] as unknown as { setContent(c: string): void; show(): void }) : (instance as unknown as { setContent(c: string): void; show(): void });
                }
                if (that._isOver) that._tippyInstance?.show();
            });
        }, { once: true });
    });

    document.addEventListener('click', function (e) {
        const geoBtn = (e.target as HTMLElement).closest<HTMLElement>('.ipGeoOpen');
        if (!geoBtn) return;
        e.preventDefault();
        const lat = geoBtn.dataset.lat ?? '';
        const lon = geoBtn.dataset.lon ?? '';
        const parent = geoBtn.parentElement;
        geoBtn.remove();
        const append = '<br><img width=300 height=220 src="http://maps.googleapis.com/maps/api/staticmap'
            + '?sensor=false&size=300x220&zoom=6&markers=size:tiny%7C' + lat + ',' + lon + '">';
        if (parent) parent.insertAdjacentHTML('beforeend', append);
    });

    void filter_user_name;
}

initModule(init as (cfg: Record<string, unknown>) => void);
