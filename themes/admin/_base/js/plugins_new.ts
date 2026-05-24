import '../css/pages/plugins.css';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.default.css';
import noUiSlider from 'nouislider';
import 'nouislider/dist/nouislider.css';
import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';

import { getPageData } from './page-data';
import { config } from './config';

interface PluginsNewPageData {
    str_confirm_msg: string;
    str_cancel_msg: string;
    str_install_title: string;
    str_x_month: string;
    str_x_months: string;
    str_x_year: string;
    str_x_years: string;
    str_from_begining: string;
    strs_certification: Record<string, string>;
}

const {
    str_confirm_msg,
    str_cancel_msg,
    str_install_title,
    str_x_month,
    str_x_months,
    str_x_year,
    str_x_years,
    str_from_begining,
    strs_certification,
} = getPageData<PluginsNewPageData>();

const qs = <T extends HTMLElement = HTMLElement>(sel: string, ctx: Element | Document = document) =>
    ctx.querySelector<T>(sel);
const qsa = <T extends HTMLElement = HTMLElement>(sel: string) =>
    Array.from(document.querySelectorAll<T>(sel));

let sortOrder = 'date';
interface PluginFilters {
    search: string;
    author: string;
    tag: string;
    rating: number;
    certification: number;
    revision: number;
    [key: string]: string | number;
}
let filters: PluginFilters = {
    search: '',
    author: '',
    tag: '',
    rating: 0,
    certification: 0,
    revision: 0,
};
let ratingValue = 0,
    certValue = 0,
    _revisionValue = 0;

function sortPlugins(a: HTMLElement, b: HTMLElement): number {
    if (sortOrder === 'downloads' || sortOrder === 'revision' || sortOrder === 'date') {
        return parseInt(a.dataset[sortOrder] ?? '0') < parseInt(b.dataset[sortOrder] ?? '0')
            ? 1
            : -1;
    }
    return (a.dataset[sortOrder] ?? '').toLowerCase() > (b.dataset[sortOrder] ?? '').toLowerCase()
        ? 1
        : -1;
}

function value_to_month(val: number): [number, string] {
    switch (val) {
        case 6:
            return [1, str_x_month.replace('%d', '1')];
        case 5:
            return [3, str_x_months.replace('%d', '3')];
        case 4:
            return [6, str_x_months.replace('%d', '6')];
        case 3:
            return [12, str_x_year.replace('%d', '1')];
        case 2:
            return [24, str_x_years.replace('%d', '2')];
        case 1:
            return [60, str_x_years.replace('%d', '5')];
        default:
            return [Number.MAX_SAFE_INTEGER, str_from_begining];
    }
}

function monthDiff(d1: Date, d2: Date): number {
    let months = (d2.getFullYear() - d1.getFullYear()) * 12;
    months -= d1.getMonth();
    months += d2.getMonth();
    return months <= 0 ? 0 : months;
}

function displayStars(element: HTMLElement, rating: number) {
    const spans = Array.from(element.querySelectorAll<HTMLElement>('span'));
    const icons = Array.from(element.querySelectorAll<HTMLElement>('span i'));
    spans.forEach((s) => s.classList.add('icon-star-empty'));
    icons.forEach((i) => {
        i.className = '';
    });
    let r = Math.round(rating * 2);
    if (r % 2 === 1) {
        const halfStar = spans
            .find((s) => s.dataset['star'] === String((r - 1) / 2))
            ?.querySelector<HTMLElement>('i');
        if (halfStar) halfStar.classList.add('icon-star-half');
        r -= 1;
    }
    while (r > 0) {
        r -= 2;
        const star = spans.find((s) => s.dataset['star'] === String(r / 2));
        if (star) {
            const icon = star.querySelector<HTMLElement>('i');
            if (icon) icon.classList.add('icon-star');
            star.classList.remove('icon-star-empty');
        }
    }
}

function updateRatingFilterLabel(value: number) {
    const container = qs<HTMLElement>('.advanced-filter-rating .rating-star-container');
    if (container) displayStars(container, value);
}

function updateCertificationFilterLabel(value: number) {
    const certifNode = qs<HTMLElement>('.advanced-filter-certification .certification');
    if (!certifNode) return;
    certifNode.setAttribute('data-certification', String(value));
    certifNode.title = strs_certification[String(value)] ?? '';
    tippy(certifNode, { delay: [0, 0], duration: [200, 200] });
}

function updateRevisionFilterLabel(val: number) {
    const [, label] = value_to_month(val);
    const el = qs<HTMLElement>('.revision-date');
    if (el) el.innerHTML = label;
}

function checkPlugin(box: HTMLElement): boolean {
    const pluginRatingRaw = parseFloat(
        box.querySelector<HTMLElement>('.pluginRating')?.dataset['rating'] ?? '0'
    );
    const pluginRating = isNaN(pluginRatingRaw) ? 0 : pluginRatingRaw;
    const pluginCertification = parseInt(
        box.querySelector<HTMLElement>('.certification')?.dataset['certification'] ?? '0'
    );
    const pluginAuthors = (box.dataset['author'] ?? '').split(', ');
    const pluginName = (box.dataset['name'] ?? '').toUpperCase();
    const pluginTags = (box.dataset['tags'] ?? '').split(', ');
    const revision = box.dataset['revision'] ?? '0';
    const pluginRevisionOld = monthDiff(new Date(parseInt(revision) * 1000), new Date());

    return (
        pluginRating >= filters.rating &&
        pluginCertification >= filters.certification &&
        (filters.search === '' || pluginName.includes(filters.search)) &&
        (filters.author === '' || pluginAuthors.includes(filters.author)) &&
        (filters.tag === '' || pluginTags.includes(filters.tag)) &&
        pluginRevisionOld <= filters['revision']
    );
}

function applyFilter(changed: string, value: string | number) {
    filters[changed] = value;
    const boxes = qsa('.pluginBox');
    boxes.forEach((box) => {
        box.style.display = checkPlugin(box) ? '' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document
        .querySelector<HTMLSelectElement>('select[name="selectOrder"]')
        ?.addEventListener('change', function (this: HTMLSelectElement) {
            sortOrder = this.value;
            const container = qs('.pluginBox')?.parentElement;
            if (container) {
                const boxes = Array.from(container.querySelectorAll<HTMLElement>('.pluginBox'));
                boxes.sort(sortPlugins);
                const fragment = document.createDocumentFragment();
                boxes.forEach((box) => fragment.appendChild(box));
                container.appendChild(fragment);
            }
            void fetch(config.adminUrl + 'plugins_new_order=' + sortOrder);
        });

    document.getElementById('search')?.addEventListener('input', function (this: HTMLInputElement) {
        applyFilter('search', this.value.toUpperCase());
    });

    qs('.search-cancel')?.addEventListener('click', () => applyFilter('search', ''));

    const toggleAdvancedFilter = (open: boolean) =>
        qsa('.advanced-filter-btn, .advanced-filter').forEach((el) =>
            el.classList.toggle('advanced-filter-open', open)
        );
    qs('.advanced-filter-btn')?.addEventListener('click', () => {
        const isOpen = qs('.advanced-filter')?.classList.contains('advanced-filter-open') === true;
        toggleAdvancedFilter(!isOpen);
    });
    qs('.advanced-filter-close')?.addEventListener('click', () => toggleAdvancedFilter(false));

    qsa('.buttonInstall').forEach((btn) => {
        const pluginBox = btn.closest<HTMLElement>('.pluginBox');
        const plugin_name = pluginBox?.dataset['name'] ?? '';
        (
            window as Window & {
                pwg_jconfirm_follow_href_fn?: (
                    el: HTMLElement,
                    opts: { alert_title: string; alert_confirm: string; alert_cancel: string }
                ) => void;
            }
        ).pwg_jconfirm_follow_href_fn?.(btn, {
            alert_title: str_install_title.replace('%s', plugin_name),
            alert_confirm: str_confirm_msg,
            alert_cancel: str_cancel_msg,
        });
    });

    qsa('.pluginRating').forEach((container) => {
        const rating = parseFloat(container.dataset['rating'] ?? '0');
        const starContainer = container.querySelector<HTMLElement>('.rating-star-container');
        if (starContainer) displayStars(starContainer, rating);
    });

    requestIdleCallback(() => {
        tippy('.certification', { delay: [0, 0], duration: [200, 200] });
    });

    const authorNames: { value: string; text: string }[] = [{ value: '', text: '-' }];
    const tagsNames: { value: string; text: string }[] = [{ value: '', text: '-' }];
    const authorSet = new Set(['']);
    const tagSet = new Set(['']);

    qsa('.pluginBox').forEach((box) => {
        (box.dataset['author'] ?? '').split(', ').forEach((name) => {
            if (!authorSet.has(name)) {
                authorNames.push({ value: name, text: name });
                authorSet.add(name);
            }
        });
        (box.dataset['tags'] ?? '').split(', ').forEach((tag) => {
            if (!tagSet.has(tag)) {
                tagsNames.push({ value: tag, text: tag });
                tagSet.add(tag);
            }
        });
    });

    const authorSelectEl = document.getElementById('author-filter') as HTMLSelectElement;
    const authorTs = new TomSelect(authorSelectEl, {
        onChange: (value: string) => applyFilter('author', value),
        plugins: { remove_button: {} },
    });
    authorNames.forEach(({ value, text }) => authorTs.addOption({ value, text }));

    const tagSelectEl = document.getElementById('tag-filter') as HTMLSelectElement;
    const tagTs = new TomSelect(tagSelectEl, {
        onChange: (value: string) => applyFilter('tag', value),
        plugins: { remove_button: {} },
    });
    tagsNames.forEach(({ value, text }) => tagTs.addOption({ value, text }));

    const ratingSliderEl = qs<HTMLElement>('.notation-filter-slider')!;
    const ratingSlider = noUiSlider.create(ratingSliderEl, {
        range: { min: 0, max: 5 },
        start: 0,
        step: 0.5,
        connect: [true, false],
    });
    ratingSlider.on('update', ([val]) => {
        ratingValue = parseFloat(String(val));
        updateRatingFilterLabel(ratingValue);
        applyFilter('rating', ratingValue);
    });

    const revSliderEl = qs<HTMLElement>('.revision-date-filter-slider')!;
    const revSlider = noUiSlider.create(revSliderEl, {
        range: { min: 0, max: 6 },
        start: 0,
        step: 1,
        connect: [true, false],
    });
    revSlider.on('update', ([val]) => {
        const intVal = parseInt(String(val));
        const [month] = value_to_month(intVal);
        _revisionValue = intVal;
        updateRevisionFilterLabel(intVal);
        applyFilter('revision', month);
    });

    const certSliderEl = qs<HTMLElement>('.certification-filter-slider')!;
    const certSlider = noUiSlider.create(certSliderEl, {
        range: { min: 0, max: 3 },
        start: 0,
        step: 1,
        connect: [true, false],
    });
    certSlider.on('update', ([val]) => {
        certValue = parseInt(String(val));
        updateCertificationFilterLabel(certValue);
        applyFilter('certification', certValue);
    });

    updateRatingFilterLabel(0);
    updateCertificationFilterLabel(0);
    updateRevisionFilterLabel(0);

    filters = {
        search: (document.getElementById('search') as HTMLInputElement | null)?.value ?? '',
        author: '',
        tag: '',
        rating: 0,
        certification: 0,
        revision: value_to_month(0)[0],
    };
    void certValue;
    void ratingValue;

    requestIdleCallback(() => {
        authorTs.setValue('');
        tagTs.setValue('');
    });

    qsa('.pluginName span').forEach((el) => {
        const text = el.textContent;
        if (text.length > 30) el.textContent = text.slice(0, 30) + '...';
    });
});

export {};
