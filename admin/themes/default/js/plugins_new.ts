import TomSelect from 'tom-select';
import noUiSlider from 'nouislider';
import 'nouislider/dist/nouislider.css';
import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';

declare var $select: any;
declare var _: any;
declare var str_cancel_msg: any;
declare var str_confirm_msg: any;
declare var str_from_begining: any;
declare var str_install_title: any;
declare var str_x_month: any;
declare var str_x_months: any;
declare var str_x_year: any;
declare var str_x_years: any;
declare var strs_certification: any;

const qs = <T extends HTMLElement = HTMLElement>(sel: string, ctx: Element | Document = document) => ctx.querySelector<T>(sel);
const qsa = <T extends HTMLElement = HTMLElement>(sel: string) => Array.from(document.querySelectorAll<T>(sel));

let sortOrder = 'date';
let filters: Record<string, any> = {};
let ratingValue = 0, certValue = 0, revisionValue = 0;

function sortPlugins(a: HTMLElement, b: HTMLElement): number {
    if (sortOrder === 'downloads' || sortOrder === 'revision' || sortOrder === 'date') {
        return parseInt(a.dataset[sortOrder] ?? '0') < parseInt(b.dataset[sortOrder] ?? '0') ? 1 : -1;
    }
    return (a.dataset[sortOrder] ?? '').toLowerCase() > (b.dataset[sortOrder] ?? '').toLowerCase() ? 1 : -1;
}

function value_to_month(val: number): [number, string] {
    switch (val) {
        case 6: return [1, str_x_month.replace('%d', 1)];
        case 5: return [3, str_x_months.replace('%d', 3)];
        case 4: return [6, str_x_months.replace('%d', 6)];
        case 3: return [12, str_x_year.replace('%d', 1)];
        case 2: return [24, str_x_years.replace('%d', 2)];
        case 1: return [60, str_x_years.replace('%d', 5)];
        default: return [Number.MAX_SAFE_INTEGER, str_from_begining];
    }
}

function monthDiff(d1: Date, d2: Date): number {
    let months = (d2.getFullYear() - d1.getFullYear()) * 12;
    months -= d1.getMonth();
    months += d2.getMonth();
    return months <= 0 ? 0 : months;
}

function displayStars(element: HTMLElement, rating: number) {
    element.querySelectorAll('span').forEach(s => s.classList.add('icon-star-empty'));
    element.querySelectorAll('span i').forEach(i => { i.className = ''; });
    rating = Math.round(rating * 2);
    if (rating % 2 === 1) {
        element.querySelector<HTMLElement>(`span[data-star="${(rating - 1) / 2}"] i`)?.classList.add('icon-star-half');
        rating -= 1;
    }
    while (rating > 0) {
        rating -= 2;
        element.querySelector<HTMLElement>(`span[data-star="${rating / 2}"] i`)?.classList.add('icon-star');
        element.querySelector<HTMLElement>(`span[data-star="${rating / 2}"]`)?.classList.remove('icon-star-empty');
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
    certifNode.title = strs_certification[String(value)];
    tippy(certifNode, { delay: [0, 0], duration: [200, 200] });
}

function updateRevisionFilterLabel(val: number) {
    const [, label] = value_to_month(val);
    const el = qs<HTMLElement>('.revision-date');
    if (el) el.innerHTML = label;
}

function checkPlugin(box: HTMLElement): boolean {
    const pluginRating = parseFloat((box.querySelector<HTMLElement>('.pluginRating') as HTMLElement)?.dataset['rating'] ?? '0') || 0;
    const pluginCertification = parseInt(box.querySelector<HTMLElement>('.certification')?.dataset['certification'] ?? '0');
    const pluginAuthors = (box.dataset['author'] ?? '').split(', ');
    const pluginName = (box.dataset['name'] ?? '').toUpperCase();
    const pluginTags = (box.dataset['tags'] ?? '').split(', ');
    const revision = box.dataset['revision'] ?? '0';
    const pluginRevisionOld = monthDiff(new Date(parseInt(revision) * 1000), new Date());

    return (pluginRating >= filters.rating)
        && (pluginCertification >= filters.certification)
        && (filters.search === '' || pluginName.includes(filters.search))
        && (filters.author === '' || pluginAuthors.includes(filters.author))
        && (filters.tag === '' || pluginTags.includes(filters.tag))
        && pluginRevisionOld <= filters['revision'];
}

function applyFilter(changed: string, value: any) {
    filters[changed] = value;
    qsa('.pluginBox').forEach(box => { box.style.display = checkPlugin(box) ? '' : 'none'; });
}

document.addEventListener('DOMContentLoaded', () => {
    const betaTestPlugins = document.getElementById('showBetaTestPlugin')!.hasAttribute('checked');
    const minCertification = betaTestPlugins ? -1 : 0;

    document.querySelector<HTMLSelectElement>('select[name="selectOrder"]')?.addEventListener('change', function(this: HTMLSelectElement) {
        sortOrder = this.value;
        const container = qs('.pluginBox')?.parentElement;
        if (container) {
            const boxes = Array.from(container.querySelectorAll<HTMLElement>('.pluginBox'));
            boxes.sort(sortPlugins);
            boxes.forEach(box => container.appendChild(box));
        }
        fetch('admin.php?plugins_new_order=' + sortOrder);
    });

    document.getElementById('search')?.addEventListener('input', function(this: HTMLInputElement) {
        applyFilter('search', this.value.toUpperCase());
    });

    qs('.search-cancel')?.addEventListener('click', () => applyFilter('search', ''));

    qsa('.buttonInstall').forEach(btn => {
        const pluginBox = btn.closest<HTMLElement>('.pluginBox');
        const plugin_name = pluginBox?.dataset['name'] ?? '';
        (window as any).pwg_jconfirm_follow_href_fn(btn, {
            alert_title: str_install_title.replace('%s', plugin_name),
            alert_confirm: str_confirm_msg,
            alert_cancel: str_cancel_msg,
        });
    });

    tippy('.certification', { delay: [0, 0], duration: [200, 200] });

    qsa('.pluginRating').forEach(container => {
        const rating = parseFloat(container.dataset['rating'] ?? '0');
        const starContainer = container.querySelector<HTMLElement>('.rating-star-container');
        if (starContainer) displayStars(starContainer, rating);
    });

    const authorNames: { value: string; text: string }[] = [{ value: '', text: '-' }];
    const tagsNames: { value: string; text: string }[] = [{ value: '', text: '-' }];

    qsa('.pluginBox').forEach(box => {
        (box.dataset['author'] ?? '').split(', ').forEach(name => {
            if (!authorNames.find(el => el.value === name)) authorNames.push({ value: name, text: name });
        });
        (box.dataset['tags'] ?? '').split(', ').forEach(tag => {
            if (!tagsNames.find(el => el.value === tag)) tagsNames.push({ value: tag, text: tag });
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
        range: { min: 0, max: 5 }, start: 0, step: 0.5, connect: [true, false]
    });
    ratingSlider.on('update', ([val]) => {
        ratingValue = parseFloat(String(val));
        updateRatingFilterLabel(ratingValue);
        applyFilter('rating', ratingValue);
    });

    const revSliderEl = qs<HTMLElement>('.revision-date-filter-slider')!;
    const revSlider = noUiSlider.create(revSliderEl, {
        range: { min: 0, max: 6 }, start: 0, step: 1, connect: [true, false]
    });
    revSlider.on('update', ([val]) => {
        const intVal = parseInt(String(val));
        const [month] = value_to_month(intVal);
        revisionValue = intVal;
        updateRevisionFilterLabel(intVal);
        applyFilter('revision', month);
    });

    const certSliderEl = qs<HTMLElement>('.certification-filter-slider')!;
    const certSlider = noUiSlider.create(certSliderEl, {
        range: { min: minCertification, max: 3 }, start: minCertification, step: 1, connect: [true, false]
    });
    certSlider.on('update', ([val]) => {
        certValue = parseInt(String(val));
        updateCertificationFilterLabel(certValue);
        applyFilter('certification', certValue);
    });

    updateRatingFilterLabel(0);
    updateCertificationFilterLabel(minCertification);
    updateRevisionFilterLabel(0);

    filters = {
        search: (document.getElementById('search') as HTMLInputElement)?.value ?? '',
        author: '',
        tag: '',
        rating: 0,
        certification: minCertification,
        revision: value_to_month(0)[0],
    };

    authorTs.setValue('');
    tagTs.setValue('');

    qsa('.pluginName span').forEach(el => {
        if (el.innerHTML.length > 30) el.innerHTML = el.innerHTML.slice(0, 30) + '...';
    });

    document.getElementById('showBetaTestPlugin')?.addEventListener('change', function(this: HTMLInputElement) {
        qs('.beta-test-plugin-switch .slider')?.classList.add('loading');
        const queryParams = new URLSearchParams(window.location.search);
        queryParams.set('beta-test', String(this.checked));
        history.replaceState(null, '', '?' + queryParams.toString());
        window.location.reload();
    });
});

export {};
