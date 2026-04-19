import { initModule } from './moduleInit.js';
import Chart from 'chart.js';
import moment from 'moment';

interface StatsConfig {
    str_number_page_visited?: string;
    str_number_page_visited_with_year?: string;
    str_avg?: string;
    str_months_tosplit?: string;
}

void moment;

export function init(cfg: StatsConfig): void {
    const { str_number_page_visited, str_avg, str_months_tosplit } = cfg;

    const str_tooltip_format: Record<string, string> = {
        years: 'YYYY',
        months: 'MMMM YYYY',
        days: 'DD MMM',
        hours: 'LT',
    };
    const str_unit_format: Record<string, string> = {
        day: 'dddd',
        month: 'MMM YYYY',
    };
    const str_months = (str_months_tosplit ?? '').split('~');

    const dataEl = document.getElementById('data');
    const data: Record<string, Record<string, number> | null> = {};
    data['hours'] = dataEl ? JSON.parse(dataEl.dataset.hours ?? 'null') as Record<string, number> | null : null;
    data['days'] = dataEl ? JSON.parse(dataEl.dataset.days ?? 'null') as Record<string, number> | null : null;
    data['months'] = dataEl ? JSON.parse(dataEl.dataset.months ?? 'null') as Record<string, number> | null : null;
    data['years'] = dataEl ? JSON.parse(dataEl.dataset.years ?? 'null') as Record<string, number> | null : null;
    data['compare-years'] = dataEl ? JSON.parse(dataEl.dataset.compareYears ?? 'null') as Record<string, number> | null : null;

    interface MonthStats { month: Record<string, number>[]; avg: number }
    let monthStats: MonthStats | null = null;
    if (dataEl) monthStats = JSON.parse(dataEl.dataset.monthStats ?? 'null') as MonthStats | null;

    const data_unit: Record<string, string> = {
        hours: 'day',
        days: 'month',
        months: 'year',
        years: 'year',
    };

    let compareMode = false;

    const ctxEl = document.getElementById('stat-graph') as HTMLCanvasElement | null;
    if (!ctxEl) return;
    const ctx = ctxEl.getContext('2d')!;

    function gradient(r: number, g: number, b: number): CanvasGradient {
        const grad = ctx.createLinearGradient(0, 400, 0, 0);
        grad.addColorStop(0, `rgba(${r},${g},${b},0)`);
        grad.addColorStop(1, `rgba(${r},${g},${b},1)`);
        return grad;
    }

    // Chart.js v2 global defaults
     
    const defaults = (Chart).defaults.global;
    defaults.elements.point.radius = 0.1;
    defaults.elements.point.hitRadius = 10;
    defaults.defaultFontSize = 14;
    defaults.defaultFontColor = '#888';
    defaults.tooltips.intersect = false;
    defaults.legend.onClick = null;

     
    const statGraph = new (Chart)(ctx, { type: 'line', maintainAspectRatio: false });

    const displayOptions = {
        backgroundColor: gradient(255, 119, 0),
        borderColor: 'rgba(255,119,0,1)',
        lineTension: 0.2,
    };

    function changeData(dataType: string, options?: typeof displayOptions): void {
        options = options ?? displayOptions;
        if (!compareMode) {
            statGraph.data = {
                datasets: [{
                    label: str_number_page_visited,
                    data: getValues(data[dataType] ?? {}),
                    ...options,
                }],
            };
            statGraph.options = {
                scales: {
                    xAxes: [{ type: 'time', time: { tooltipFormat: 'll' }, gridLines: { display: false } }],
                    yAxes: [{ ticks: { min: 0 } }],
                },
                legend: { display: false },
                tooltips: { mode: 'index' },
                hover: { intersect: false },
            };
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            statGraph.options.scales.xAxes.forEach((axe: any) => {
                axe.time.tooltipFormat = str_tooltip_format[dataType];
                axe.time.unit = data_unit[dataType];
                axe.time.displayFormats = str_unit_format;
            });
            statGraph.update();
        } else {
            statGraph.options.legend.display = true;
            statGraph.options.hover = { intersect: true };
            statGraph.options.tooltips = { mode: 'nearest' };
            if (dataType === 'years') {
                statGraph.data = { datasets: getComparedYearDataset() };
                statGraph.options.scales = {
                    xAxes: [{ type: 'category', labels: str_months, gridLines: { display: false } }],
                    yAxes: [{ scaleLabel: { display: true, labelString: str_number_page_visited }, tick: { min: 0 } }],
                };
            } else if (dataType === 'months') {
                const days: number[] = [];
                for (let i = 1; i <= 31; i++) days.push(i);
                statGraph.data = { datasets: getMonthStatsDataset() };
                statGraph.options.scales = {
                    xAxes: [{ type: 'category', labels: days, gridLines: { display: false } }],
                    yAxes: [{ scaleLabel: { display: true, labelString: str_number_page_visited } }],
                };
            }
            statGraph.update();
        }
    }

    function getValues(d: Record<string, number>): { x: Date; y: number }[] {
        return Object.keys(d).map(key => ({ x: new Date(key), y: d[key] }));
    }

    function getComparedYearDataset(): unknown[] {
        const colors = ['#ffa744', '#ff5252', '#896af3', '#2883c3', '#6ece5e'];
        const values: Record<number, number[]> = {};
        const dataset: unknown[] = [];
        const compareYears = data['compare-years'] ?? {};

        Object.keys(compareYears).forEach(key => {
            const date = new Date(key);
            const yr = date.getFullYear();
            if (values[yr] === undefined) values[yr] = [];
            values[yr][date.getMonth()] = compareYears[key];
        });

        Object.keys(values).forEach(key => {
            dataset.push({
                label: key,
                data: values[parseInt(key)],
                lineTension: 0.2,
                borderColor: colors[parseInt(key) % colors.length],
                backgroundColor: 'rgba(0,0,0,0)',
            });
        });
        return dataset;
    }

    function getMonthStatsDataset(): unknown[] {
        const colors = ['#ffa744', '#ff5252', '#896af3', '#2883c3', '#6ece5e'];
        const dataset: unknown[] = [];
        let colorIndice = 0;
        let date = new Date();

        if (monthStats) {
            monthStats.month.forEach(values => {
                const days_data: number[] = [];
                Object.keys(values).forEach(key => {
                    date = new Date(key);
                    days_data[date.getUTCDate() - 1] = values[key];
                });
                dataset.push({
                    label: str_months[date.getMonth()] + ' ' + date.getFullYear(),
                    data: days_data,
                    lineTension: 0.2,
                    borderColor: colors[colorIndice % colors.length],
                    backgroundColor: 'rgba(0,0,0,0)',
                });
                colorIndice++;
            });

            const averageTab: number[] = Array(31).fill(monthStats.avg);
            dataset.push({
                label: str_avg,
                data: averageTab,
                lineTension: 0.2,
                borderColor: colors[4],
                backgroundColor: 'rgba(0,0,0,0)',
            });
        }
        return dataset;
    }

    document.querySelectorAll<HTMLElement>('.stat-data-selector label').forEach(label => {
        label.addEventListener('click', function () {
            const dataType = (this).dataset.value;
            if (dataType) changeData(dataType);
        });
    });

    document.querySelectorAll<HTMLInputElement>('.stat-compare-mode input').forEach(input => {
        input.addEventListener('change', function () {
            compareMode = (this).checked;
            let checkedLabel: HTMLElement | null;

            if (compareMode) {
                document.querySelectorAll<HTMLElement>('#hours-selector + label, #days-selector + label').forEach(el => el.classList.add('unavailable'));
                const hoursChecked = document.getElementById('hours-selector') as HTMLInputElement | null;
                const daysChecked = document.getElementById('days-selector') as HTMLInputElement | null;
                if ((hoursChecked?.checked) || (daysChecked?.checked)) {
                    const yearsSelector = document.getElementById('years-selector') as HTMLInputElement | null;
                    if (yearsSelector) yearsSelector.checked = true;
                    document.querySelectorAll<HTMLInputElement>('#hours-selector, #days-selector').forEach(el => { el.checked = false; });
                    changeData('years');
                } else {
                    checkedLabel = document.querySelector<HTMLElement>('.stat-data-selector input:checked + label');
                    if (checkedLabel?.dataset.value) changeData(checkedLabel.dataset.value);
                }
            } else {
                document.querySelectorAll<HTMLElement>('#hours-selector + label, #days-selector + label').forEach(el => el.classList.remove('unavailable'));
                checkedLabel = document.querySelector<HTMLElement>('.stat-data-selector input:checked + label');
                if (checkedLabel?.dataset.value) changeData(checkedLabel.dataset.value);
            }
        });
    });

    const checkedLabel = document.querySelector<HTMLElement>('.stat-data-selector input:checked + label');
    if (checkedLabel?.dataset.value) changeData(checkedLabel.dataset.value);
}

initModule(init);
