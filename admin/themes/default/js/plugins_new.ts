import { initModule } from './moduleInit.js';
import tippy from 'tippy.js';
import TomSelect from 'tom-select';
import noUiSlider, { type API as NoUiSliderAPI } from 'nouislider';
import { pwgConfirmFollowHref } from './pwgConfirm.js';

interface PluginsNewConfig {
    str_install_title?: string;
    str_confirm_msg?: string;
    str_cancel_msg?: string;
    strs_certification?: Record<string, string>;
    str_x_month?: string;
    str_x_months?: string;
    str_x_year?: string;
    str_x_years?: string;
    str_from_beginning?: string;
}

interface NoUiTarget extends HTMLElement { noUiSlider?: NoUiSliderAPI }

export function init(cfg: PluginsNewConfig): void {
    const {
        str_install_title = 'Install %s?', str_confirm_msg = 'Yes', str_cancel_msg = 'No',
        strs_certification = {}, str_x_month = '%d month', str_x_months = '%d months',
        str_x_year = '%d year', str_x_years = '%d years', str_from_beginning = 'From the beginning',
    } = cfg;

    let sortOrder = 'date';
    const sortPlugins = function (a: HTMLElement, b: HTMLElement): number {
        if (sortOrder === 'downloads' || sortOrder === 'revision' || sortOrder === 'date')
            return parseInt(a.dataset[sortOrder] ?? '0') < parseInt(b.dataset[sortOrder] ?? '0') ? 1 : -1;
        else
            return (a.dataset[sortOrder] ?? '').toLowerCase() > (b.dataset[sortOrder] ?? '').toLowerCase() ? 1 : -1;
    };

    const betaTestPlugins = document.getElementById('showBetaTestPlugin')?.hasAttribute('checked') ?? false;
    let filters: Record<string, number | string> = {};

    document.querySelector('.advanced-filter-btn')?.addEventListener('click', advanced_filter_button_click);
    document.querySelector('.advanced-filter span.icon-cancel')?.addEventListener('click', advanced_filter_hide);

    function advanced_filter_button_click(): void {
        if (!document.querySelector('.advanced-filter')?.classList.contains('advanced-filter-open')) {
            advanced_filter_show();
        } else {
            advanced_filter_hide();
        }
    }
    function advanced_filter_show(): void {
        document.querySelector('.advanced-filter-btn')?.classList.add('advanced-filter-open');
        document.querySelector('.advanced-filter')?.classList.add('advanced-filter-open');
    }
    function advanced_filter_hide(): void {
        document.querySelector('.advanced-filter-btn')?.classList.remove('advanced-filter-open');
        document.querySelector('.advanced-filter')?.classList.remove('advanced-filter-open');
    }

    const orderSelect = document.querySelector<HTMLSelectElement>('select[name="selectOrder"]');
    if (orderSelect) {
        orderSelect.addEventListener('change', function () {
            sortOrder = orderSelect.value;
            const first = document.querySelector('.pluginBox');
            const container = first?.parentElement;
            if (container) {
                const boxes = Array.from(container.querySelectorAll<HTMLElement>('.pluginBox'));
                boxes.sort(sortPlugins);
                boxes.forEach(el => container.appendChild(el));
            }
            void fetch('admin.php?plugins_new_order=' + sortOrder);
        });
    }

    const searchEl = document.getElementById('search') as HTMLInputElement | null;
    if (searchEl) {
        searchEl.addEventListener('input', function () {
            applyFilter('search', searchEl.value.toUpperCase());
            searchEl.click();
        });
    }

    document.querySelector('.search-cancel')?.addEventListener('click', function () { applyFilter('search', ''); });

    document.querySelectorAll<HTMLElement>('.buttonInstall').forEach(function (el) {
        const pluginBox = el.closest<HTMLElement>('.pluginBox');
        const plugin_name = pluginBox?.dataset['name'] ?? '';
        pwgConfirmFollowHref(el as HTMLAnchorElement, {
            alert_title: str_install_title.replace('%s', plugin_name),
            alert_confirm: str_confirm_msg,
            alert_cancel: str_cancel_msg,
        });
    });

    tippy('.certification', { delay: 0, placement: 'top' });

    document.querySelectorAll<HTMLElement>('.pluginRating').forEach(function (node) {
        const rating = node.dataset['rating'] ?? '0';
        displayStars(node.querySelector<HTMLElement>('.rating-star-container')!, rating);
    });

    const authorNames: { value: string; text: string }[] = [{ value: '', text: '-' }];
    const tagsNames: { value: string; text: string }[] = [{ value: '', text: '-' }];

    document.querySelectorAll<HTMLElement>('.pluginBox').forEach(function (el) {
        (el.dataset['author'] ?? '').split(', ').forEach(name => {
            if (!authorNames.find(a => a.value === name)) authorNames.push({ value: name, text: name });
        });
        (el.dataset['tags'] ?? '').split(', ').forEach(tag => {
            if (!tagsNames.find(t => t.value === tag)) tagsNames.push({ value: tag, text: tag });
        });
    });

    const authorEl = document.getElementById('author-filter') as HTMLSelectElement | null;
    if (authorEl) {
        const selectizeAuthor = new TomSelect(authorEl, {
            onChange: function (value: string) { applyFilter('author', value); },
            plugins: ['remove_button'],
        });
        selectizeAuthor.addOption(authorNames);
    }

    const tagEl = document.getElementById('tag-filter') as HTMLSelectElement | null;
    if (tagEl) {
        const selectizeTag = new TomSelect(tagEl, {
            onChange: function (value: string) { applyFilter('tag', value); },
            plugins: ['remove_button'],
        });
        selectizeTag.addOption(tagsNames);
    }

    const notationSliderEl = document.querySelector<NoUiTarget>('.notation-filter-slider');
    if (notationSliderEl) {
        noUiSlider.create(notationSliderEl, { start: [0], range: { min: 0, max: 5 }, step: 0.5, connect: [true, false] });
        notationSliderEl.noUiSlider?.on('slide', function (values: (string | number)[]) {
            const val = parseFloat(String(values[0]));
            updateRatingFilterLabel(val);
            applyFilter('rating', val);
        });
    }

    const revisionSliderEl = document.querySelector<NoUiTarget>('.revision-date-filter-slider');
    if (revisionSliderEl) {
        noUiSlider.create(revisionSliderEl, { start: [0], range: { min: 0, max: 6 }, step: 1, connect: [true, false] });
        revisionSliderEl.noUiSlider?.on('slide', function (values: (string | number)[]) {
            const val = Math.round(parseFloat(String(values[0])));
            const [month] = value_to_month(val);
            updateRevisionFilterLabel(val);
            applyFilter('revision', month);
        });
    }

    const minCertification = betaTestPlugins ? -1 : 0;
    const certificationSliderEl = document.querySelector<NoUiTarget>('.certification-filter-slider');
    if (certificationSliderEl) {
        noUiSlider.create(certificationSliderEl, { start: [minCertification], range: { min: minCertification, max: 3 }, step: 1, connect: [true, false] });
        certificationSliderEl.noUiSlider?.on('slide', function (values: (string | number)[]) {
            const val = Math.round(parseFloat(String(values[0])));
            updateCertificationFilterLabel(val);
            applyFilter('certification', val);
        });
    }

    updateRatingFilterLabel(0);
    updateCertificationFilterLabel(minCertification);
    updateRevisionFilterLabel(0);

    const showBetaEl = document.getElementById('showBetaTestPlugin') as HTMLInputElement | null;
    if (showBetaEl) {
        showBetaEl.addEventListener('change', function (e) {
            document.querySelectorAll<HTMLElement>('.beta-test-plugin-switch .slider').forEach(el => el.classList.add('loading'));
            const queryParams = new URLSearchParams(window.location.search);
            queryParams.set('beta-test', ((e.currentTarget as HTMLInputElement).checked).toString());
            history.replaceState(null, '', '?' + queryParams.toString());
            window.location.reload();
        });
    }

    function value_to_month(val: number): [number, string] {
        switch (val) {
            case 6: return [1, str_x_month.replace('%d', '1')];
            case 5: return [3, str_x_months.replace('%d', '3')];
            case 4: return [6, str_x_months.replace('%d', '6')];
            case 3: return [12, str_x_year.replace('%d', '1')];
            case 2: return [24, str_x_years.replace('%d', '2')];
            case 1: return [60, str_x_years.replace('%d', '5')];
            default: return [Number.MAX_SAFE_INTEGER, str_from_beginning];
        }
    }

    function monthDiff(d1: Date, d2: Date): number {
        let months = (d2.getFullYear() - d1.getFullYear()) * 12;
        months -= d1.getMonth();
        months += d2.getMonth();
        return months <= 0 ? 0 : months;
    }

    function displayStars(element: HTMLElement, rating: string | number): void {
        element.querySelectorAll<HTMLElement>('span').forEach(function (span) {
            span.classList.add('icon-star-empty');
            const i = span.querySelector('i');
            if (i) i.className = '';
        });

        let r = Math.round(Number(rating) * 2);
        if (r % 2 === 1) {
            element.querySelector<HTMLElement>("span[data-star='" + (r - 1) / 2 + "'] i")?.classList.add('icon-star-half');
            r -= 1;
        }
        while (r > 0) {
            r -= 2;
            element.querySelector<HTMLElement>("span[data-star='" + r / 2 + "'] i")?.classList.add('icon-star');
            element.querySelector<HTMLElement>("span[data-star='" + r / 2 + "']")?.classList.remove('icon-star-empty');
        }
    }

    function updateRatingFilterLabel(value: number): void {
        const c = document.querySelector<HTMLElement>('.advanced-filter-rating .rating-star-container');
        if (c) displayStars(c, value);
    }

    function updateCertificationFilterLabel(value: number): void {
        const certifNode = document.querySelector<HTMLElement>('.advanced-filter-certification .certification');
        if (!certifNode) return;
        certifNode.setAttribute('data-certification', String(value));
        certifNode.setAttribute('title', strs_certification[String(value)] ?? '');
        tippy(certifNode, { delay: 0, placement: 'top' });
    }

    function updateRevisionFilterLabel(val: number): void {
        const [, label] = value_to_month(val);
        const el = document.querySelector<HTMLElement>('.revision-date');
        if (el) el.innerHTML = label;
    }

    filters = {
        search: searchEl?.value ?? '',
        author: '',
        tag: '',
        rating: notationSliderEl?.noUiSlider ? parseFloat(notationSliderEl.noUiSlider.get() as string) : 0,
        certification: certificationSliderEl?.noUiSlider ? Math.round(parseFloat(certificationSliderEl.noUiSlider.get() as string)) : minCertification,
        revision: value_to_month(certificationSliderEl?.noUiSlider ? Math.round(parseFloat(certificationSliderEl.noUiSlider.get() as string)) : minCertification)[0],
    };

    if (authorEl && (authorEl as unknown as { tomselect?: { setValue(v: string): void } }).tomselect) {
        (authorEl as unknown as { tomselect: { setValue(v: string): void } }).tomselect.setValue('');
    }
    if (tagEl && (tagEl as unknown as { tomselect?: { setValue(v: string): void } }).tomselect) {
        (tagEl as unknown as { tomselect: { setValue(v: string): void } }).tomselect.setValue('');
    }

    function applyFilter(changed: string, value: number | string): void {
        filters[changed] = value;

        sort(function (pluginBox: HTMLElement): boolean {
            const pluginRating = parseFloat(pluginBox.querySelector<HTMLElement>('.pluginRating')?.dataset['rating'] ?? '0');
            const pluginCertification = parseInt(pluginBox.querySelector<HTMLElement>('.certification')?.dataset['certification'] ?? '0');
            const pluginAuthors = (pluginBox.dataset['author'] ?? '').split(', ');
            const pluginName = (pluginBox.dataset['name'] ?? '').toUpperCase();
            const pluginTags = (pluginBox.dataset['tags'] ?? '').split(', ');
            const pluginRevisionOld = monthDiff(new Date(parseInt(pluginBox.dataset['revision'] ?? '0') * 1000), new Date());

            return (
                pluginRating >= (filters['rating'] as number) &&
                pluginCertification >= (filters['certification'] as number) &&
                (filters['search'] === '' || pluginName.indexOf(filters['search'] as string) !== -1) &&
                (filters['author'] === '' || pluginAuthors.includes(filters['author'] as string)) &&
                (filters['tag'] === '' || pluginTags.includes(filters['tag'] as string)) &&
                pluginRevisionOld <= (filters['revision'] as number)
            );
        });
    }

    function sort(sortFunction: (el: HTMLElement) => boolean): void {
        document.querySelectorAll<HTMLElement>('.pluginBox').forEach(function (el) {
            el.style.display = sortFunction(el) ? '' : 'none';
        });
    }

    document.querySelectorAll<HTMLElement>('.pluginName span').forEach(function (el) {
        if (el.innerHTML.length > 30) el.innerHTML = el.innerHTML.slice(0, 30) + '...';
    });
}

initModule(init as (cfg: Record<string, unknown>) => void);
