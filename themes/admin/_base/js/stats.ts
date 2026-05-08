import { Chart, registerables } from 'chart.js';
import type { ChartConfiguration, ChartDataset } from 'chart.js';
import 'chartjs-adapter-dayjs-4';
import dayjs from 'dayjs';
import LocalizedFormat from 'dayjs/plugin/localizedFormat';
import { getPageData } from './page-data';

Chart.register(...registerables);
dayjs.extend(LocalizedFormat);

interface StatsPageData {
    str_avg: string;
    str_number_page_visited: string;
    str_months: string[];
    lang_code: string;
}

interface MonthStats {
    month: Array<Record<string, number>>;
    avg: number;
}

interface PageDataset {
    hours: Record<string, number>;
    days: Record<string, number>;
    months: Record<string, number>;
    years: Record<string, number>;
    'compare-years': Record<string, number>;
    'month-stats': MonthStats;
}

type DataType = 'hours' | 'days' | 'months' | 'years';

const { str_avg, str_number_page_visited, str_months, lang_code } = getPageData<StatsPageData>();

const str_tooltip_format: Record<DataType, string> = {
    years: 'YYYY',
    months: 'MMMM YYYY',
    days: 'DD MMM',
    hours: 'LT',
};
const str_unit_format: Record<string, string> = { day: 'dddd', month: 'MMM YYYY' };

// Each entry must be a literal import() so Vite can code-split per locale.
// Add a row when Piwigo gains a translation.
const dayjsLocaleLoaders: Record<string, () => Promise<unknown>> = {
    cs: () => import('dayjs/locale/cs.js'),
    da: () => import('dayjs/locale/da.js'),
    de: () => import('dayjs/locale/de.js'),
    el: () => import('dayjs/locale/el.js'),
    'en-gb': () => import('dayjs/locale/en-gb.js'),
    es: () => import('dayjs/locale/es.js'),
    fi: () => import('dayjs/locale/fi.js'),
    fr: () => import('dayjs/locale/fr.js'),
    hu: () => import('dayjs/locale/hu.js'),
    it: () => import('dayjs/locale/it.js'),
    ja: () => import('dayjs/locale/ja.js'),
    ko: () => import('dayjs/locale/ko.js'),
    nb: () => import('dayjs/locale/nb.js'),
    nl: () => import('dayjs/locale/nl.js'),
    pl: () => import('dayjs/locale/pl.js'),
    pt: () => import('dayjs/locale/pt.js'),
    'pt-br': () => import('dayjs/locale/pt-br.js'),
    ro: () => import('dayjs/locale/ro.js'),
    ru: () => import('dayjs/locale/ru.js'),
    sk: () => import('dayjs/locale/sk.js'),
    sl: () => import('dayjs/locale/sl.js'),
    sv: () => import('dayjs/locale/sv.js'),
    tr: () => import('dayjs/locale/tr.js'),
    uk: () => import('dayjs/locale/uk.js'),
    vi: () => import('dayjs/locale/vi.js'),
    'zh-cn': () => import('dayjs/locale/zh-cn.js'),
    'zh-tw': () => import('dayjs/locale/zh-tw.js'),
};

// Piwigo locale (e.g. "en_UK", "fr_FR", "pt_BR") → dayjs locale identifier.
function piwigoToDayjsLocale(code: string): string {
    const special: Record<string, string> = {
        en_UK: 'en-gb',
        en_GB: 'en-gb',
        pt_BR: 'pt-br',
        zh_CN: 'zh-cn',
        zh_TW: 'zh-tw',
    };
    if (code in special) return special[code];
    const lang = code.split('_')[0];
    return lang.toLowerCase();
}

async function loadDayjsLocale(code: string): Promise<void> {
    if (code === 'en') return;
    const loader = dayjsLocaleLoaders[code];
    await loader();
    dayjs.locale(code);
}

const dataEl = document.getElementById('data')!;
const data: PageDataset = {
    hours: JSON.parse(dataEl.dataset['hours'] ?? '{}') as Record<string, number>,
    days: JSON.parse(dataEl.dataset['days'] ?? '{}') as Record<string, number>,
    months: JSON.parse(dataEl.dataset['months'] ?? '{}') as Record<string, number>,
    years: JSON.parse(dataEl.dataset['years'] ?? '{}') as Record<string, number>,
    'compare-years': JSON.parse(dataEl.dataset['compareYears'] ?? '{}') as Record<string, number>,
    'month-stats': JSON.parse(dataEl.dataset['monthStats'] ?? '{}') as MonthStats,
};

const data_unit: Record<DataType, 'day' | 'month' | 'year'> = {
    hours: 'day',
    days: 'month',
    months: 'year',
    years: 'year',
};
let compareMode = false;

const ctx = (document.getElementById('stat-graph') as HTMLCanvasElement).getContext('2d')!;

function gradient(r: number, g: number, b: number): CanvasGradient {
    const grad = ctx.createLinearGradient(0, 400, 0, 0);
    grad.addColorStop(0, `rgba(${r},${g},${b},0)`);
    grad.addColorStop(1, `rgba(${r},${g},${b},1)`);
    return grad;
}

Chart.defaults.elements.point.radius = 0.1;
Chart.defaults.elements.point.hitRadius = 10;
Chart.defaults.font.size = 14;
Chart.defaults.color = '#888';
Chart.defaults.plugins.tooltip.intersect = false;
Chart.defaults.plugins.legend.onClick = () => {};

const baseConfig: ChartConfiguration<'line'> = {
    type: 'line',
    data: { datasets: [] },
    options: { maintainAspectRatio: false },
};
const statGraph = new Chart(ctx, baseConfig);

const displayOptions = {
    backgroundColor: gradient(255, 119, 0),
    borderColor: '#FFA646',
    tension: 0.2,
};

function getValues(d: Record<string, number>): Array<{ x: Date; y: number }> {
    return Object.keys(d).map((key) => ({ x: new Date(key), y: d[key] }));
}

function getComparedYearDataset(): ChartDataset<'line'>[] {
    const colors = ['#ffa744', '#ff5252', '#896af3', '#2883c3', '#6ece5e'];
    const valuesByYear: Record<number, number[]> = {};
    Object.keys(data['compare-years']).forEach((key) => {
        const d = new Date(key);
        const year = d.getFullYear();
        valuesByYear[year] ??= [];
        valuesByYear[year][d.getMonth()] = data['compare-years'][key]!;
    });
    return Object.keys(valuesByYear).map((key, i) => ({
        label: key,
        data: valuesByYear[Number(key)],
        tension: 0.2,
        borderColor: colors[i % colors.length],
        backgroundColor: 'rgba(0,0,0,0)',
    }));
}

function getMonthStatsDataset(): ChartDataset<'line'>[] {
    const colors = ['#ffa744', '#ff5252', '#896af3', '#2883c3', '#6ece5e'];
    const datasets: ChartDataset<'line'>[] = [];
    data['month-stats'].month.forEach((vals, i) => {
        const days_data: number[] = [];
        let lastDate = new Date();
        Object.keys(vals).forEach((key) => {
            lastDate = new Date(key);
            days_data[lastDate.getUTCDate() - 1] = vals[key]!;
        });
        datasets.push({
            label: `${str_months[lastDate.getMonth()]} ${lastDate.getFullYear()}`,
            data: days_data,
            tension: 0.2,
            borderColor: colors[i % colors.length],
            backgroundColor: 'rgba(0,0,0,0)',
        });
    });
    const averageTab = new Array<number>(31).fill(data['month-stats'].avg);
    datasets.push({
        label: str_avg,
        data: averageTab,
        tension: 0.2,
        borderColor: colors[4],
        backgroundColor: 'rgba(0,0,0,0)',
    });
    return datasets;
}

function changeData(dataType: DataType, options = displayOptions): void {
    if (!compareMode) {
        statGraph.data = {
            datasets: [
                {
                    label: str_number_page_visited,
                    data: getValues(data[dataType]) as unknown as number[],
                    ...options,
                },
            ],
        };
        statGraph.options = {
            maintainAspectRatio: false,
            scales: {
                x: {
                    type: 'time',
                    time: {
                        tooltipFormat: str_tooltip_format[dataType],
                        unit: data_unit[dataType],
                        displayFormats: str_unit_format,
                    },
                    grid: { display: false },
                },
                y: { min: 0 },
            },
            plugins: {
                legend: { display: false },
                tooltip: { mode: 'index' },
            },
            interaction: { intersect: false },
        };
    } else if (dataType === 'years') {
        statGraph.data = { datasets: getComparedYearDataset() };
        statGraph.options = {
            maintainAspectRatio: false,
            scales: {
                x: { type: 'category', labels: str_months, grid: { display: false } },
                y: { min: 0, title: { display: true, text: str_number_page_visited } },
            },
            plugins: {
                legend: { display: true },
                tooltip: { mode: 'nearest' },
            },
            interaction: { intersect: true },
        };
    } else if (dataType === 'months') {
        const days = Array.from({ length: 31 }, (_, i) => String(i + 1));
        statGraph.data = { datasets: getMonthStatsDataset() };
        statGraph.options = {
            maintainAspectRatio: false,
            scales: {
                x: { type: 'category', labels: days, grid: { display: false } },
                y: { title: { display: true, text: str_number_page_visited } },
            },
            plugins: {
                legend: { display: true },
                tooltip: { mode: 'nearest' },
            },
            interaction: { intersect: true },
        };
    }
    statGraph.update();
}

// Locale loads async; once ready, attach listeners and render the initial chart.
// The data-type labels begin disabled-ish (no chart yet) but typical render is sub-100ms.
void loadDayjsLocale(piwigoToDayjsLocale(lang_code)).then(() => {
    document.querySelectorAll<HTMLElement>('.stat-data-selector label').forEach((el) => {
        el.addEventListener('click', function (this: HTMLElement) {
            const value = this.dataset['value'] as DataType | undefined;
            if (value) changeData(value);
        });
    });

    document.querySelectorAll<HTMLInputElement>('.stat-compare-mode input').forEach((el) => {
        el.addEventListener('change', function (this: HTMLInputElement) {
            compareMode = this.checked;
            const hoursSel = document.getElementById('hours-selector') as HTMLInputElement;
            const daysSel = document.getElementById('days-selector') as HTMLInputElement;
            const yearsSel = document.getElementById('years-selector') as HTMLInputElement;
            const labels = document.querySelectorAll<HTMLElement>(
                '#hours-selector + label, #days-selector + label'
            );

            if (compareMode) {
                labels.forEach((l) => l.classList.add('unavailable'));
                if (hoursSel.checked || daysSel.checked) {
                    yearsSel.checked = true;
                    hoursSel.checked = false;
                    daysSel.checked = false;
                    changeData('years');
                    return;
                }
            } else {
                labels.forEach((l) => l.classList.remove('unavailable'));
            }
            const current = document.querySelector<HTMLElement>(
                '.stat-data-selector input:checked + label'
            )?.dataset['value'] as DataType | undefined;
            if (current) changeData(current);
        });
    });

    const initial = document.querySelector<HTMLElement>('.stat-data-selector input:checked + label')
        ?.dataset['value'] as DataType | undefined;
    if (initial) changeData(initial);
});

export {};
