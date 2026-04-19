import { initModule } from './moduleInit.js';
import Chart from 'chart.js/auto';
import 'chartjs-adapter-moment';
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
    data['hours'] = dataEl ? JSON.parse(dataEl.dataset['hours'] ?? 'null') as Record<string, number> | null : null;
    data['days'] = dataEl ? JSON.parse(dataEl.dataset['days'] ?? 'null') as Record<string, number> | null : null;
    data['months'] = dataEl ? JSON.parse(dataEl.dataset['months'] ?? 'null') as Record<string, number> | null : null;
    data['years'] = dataEl ? JSON.parse(dataEl.dataset['years'] ?? 'null') as Record<string, number> | null : null;
    data['compare-years'] = dataEl ? JSON.parse(dataEl.dataset['compareYears'] ?? 'null') as Record<string, number> | null : null;

    interface MonthStats { month: Record<string, number>[]; avg: number }
    let monthStats: MonthStats | null = null;
    if (dataEl) monthStats = JSON.parse(dataEl.dataset['monthStats'] ?? 'null') as MonthStats | null;

    const data_unit: Record<string, string> = {
        hours: 'day',
        days: 'month',
        months: 'year',
        years: 'year',
    };

    let compareMode = false;

    const ctxEl = document.getElementById('stat-graph') as HTMLCanvasElement | null;
    if (!ctxEl) return;

    // Chart.js v4 defaults
    Chart.defaults.elements.point.radius = 0.1;
    Chart.defaults.elements.point.hitRadius = 10;
    Chart.defaults.font.size = 14;
    Chart.defaults.color = '#888';
    Chart.defaults.plugins.tooltip.intersect = false;
    Chart.defaults.plugins.legend.onClick = () => { /* noop */ };

    const statGraph = new Chart(ctxEl, {
        type: 'line',
        data: { datasets: [] },
        options: { maintainAspectRatio: false },
    });

    const displayOptions = {
        backgroundColor: gradient(255, 119, 0),
        borderColor: 'rgba(255,119,0,1)',
        tension: 0.2,
    };

    function gradient(r: number, g: number, b: number): CanvasGradient {
        const ctx = ctxEl.getContext('2d')!;
        const grad = ctx.createLinearGradient(0, 400, 0, 0);
        grad.addColorStop(0, `rgba(${r},${g},${b},0)`);
        grad.addColorStop(1, `rgba(${r},${g},${b},1)`);
        return grad;
    }

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
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            tooltipFormat: str_tooltip_format[dataType] ?? 'll',
                            unit: (data_unit[dataType] ?? 'day') as 'millisecond' | 'second' | 'minute' | 'hour' | 'day' | 'week' | 'month' | 'quarter' | 'year',
                            displayFormats: str_unit_format,
                        },
                        grid: { display: false },
                    },
                    y: { min: 0 },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { mode: 'index', intersect: false },
                },
            };
            statGraph.update();
        } else {
            if (statGraph.options.plugins) {
                if (statGraph.options.plugins.legend) statGraph.options.plugins.legend.display = true;
                statGraph.options.plugins.tooltip = { mode: 'nearest', intersect: true };
            }
            if (dataType === 'years') {
                statGraph.data = { datasets: getComparedYearDataset() };
                statGraph.options.scales = {
                    x: { type: 'category', labels: str_months, grid: { display: false } },
                    y: { min: 0, title: { display: true, text: str_number_page_visited } },
                };
            } else if (dataType === 'months') {
                const days: number[] = [];
                for (let i = 1; i <= 31; i++) days.push(i);
                statGraph.data = { datasets: getMonthStatsDataset() };
                statGraph.options.scales = {
                    x: { type: 'category', labels: days.map(String), grid: { display: false } },
                    y: { title: { display: true, text: str_number_page_visited } },
                };
            }
            statGraph.update();
        }
    }

    function getValues(d: Record<string, number>): { x: number; y: number }[] {
        return Object.keys(d).map(key => ({ x: new Date(key).getTime(), y: d[key]! }));
    }

    function getComparedYearDataset(): object[] {
        const colors = ['#ffa744', '#ff5252', '#896af3', '#2883c3', '#6ece5e'];
        const values: Record<number, number[]> = {};
        const dataset: object[] = [];
        const compareYears = data['compare-years'] ?? {};

        Object.keys(compareYears).forEach(key => {
            const date = new Date(key);
            const yr = date.getFullYear();
            values[yr] ??= [];
            values[yr][date.getMonth()] = compareYears[key]!;
        });

        Object.keys(values).forEach(key => {
            dataset.push({
                label: key,
                data: values[parseInt(key)],
                tension: 0.2,
                borderColor: colors[parseInt(key) % colors.length],
                backgroundColor: 'rgba(0,0,0,0)',
            });
        });
        return dataset;
    }

    function getMonthStatsDataset(): object[] {
        const colors = ['#ffa744', '#ff5252', '#896af3', '#2883c3', '#6ece5e'];
        const dataset: object[] = [];
        let colorIndice = 0;
        let date = new Date();

        if (monthStats) {
            monthStats.month.forEach(values => {
                const days_data: number[] = [];
                Object.keys(values).forEach(key => {
                    date = new Date(key);
                    days_data[date.getUTCDate() - 1] = values[key]!;
                });
                dataset.push({
                    label: str_months[date.getMonth()] + ' ' + date.getFullYear(),
                    data: days_data,
                    tension: 0.2,
                    borderColor: colors[colorIndice % colors.length],
                    backgroundColor: 'rgba(0,0,0,0)',
                });
                colorIndice++;
            });

            const averageTab: number[] = Array<number>(31).fill(monthStats.avg);
            dataset.push({
                label: str_avg,
                data: averageTab,
                tension: 0.2,
                borderColor: colors[4],
                backgroundColor: 'rgba(0,0,0,0)',
            });
        }
        return dataset;
    }

    document.querySelectorAll<HTMLElement>('.stat-data-selector label').forEach(label => {
        label.addEventListener('click', function () {
            const dataType = (this).dataset['value'];
            if (dataType) changeData(dataType);
        });
    });

    document.querySelectorAll<HTMLInputElement>('.stat-compare-mode input').forEach(input => {
        input.addEventListener('change', function () {
            compareMode = (this).checked;
            let checkedLabel: HTMLElement | null;

            if (compareMode) {
                document.querySelectorAll<HTMLElement>('#hours-selector + label, #days-selector + label').forEach(el => { el.classList.add('unavailable'); });
                const hoursChecked = document.getElementById('hours-selector') as HTMLInputElement | null;
                const daysChecked = document.getElementById('days-selector') as HTMLInputElement | null;
                if ((hoursChecked?.checked) || (daysChecked?.checked)) {
                    const yearsSelector = document.getElementById('years-selector') as HTMLInputElement | null;
                    if (yearsSelector) yearsSelector.checked = true;
                    document.querySelectorAll<HTMLInputElement>('#hours-selector, #days-selector').forEach(el => { el.checked = false; });
                    changeData('years');
                } else {
                    checkedLabel = document.querySelector<HTMLElement>('.stat-data-selector input:checked + label');
                    if (checkedLabel?.dataset['value']) changeData(checkedLabel.dataset['value']);
                }
            } else {
                document.querySelectorAll<HTMLElement>('#hours-selector + label, #days-selector + label').forEach(el => { el.classList.remove('unavailable'); });
                checkedLabel = document.querySelector<HTMLElement>('.stat-data-selector input:checked + label');
                if (checkedLabel?.dataset['value']) changeData(checkedLabel.dataset['value']);
            }
        });
    });

    const checkedLabel = document.querySelector<HTMLElement>('.stat-data-selector input:checked + label');
    if (checkedLabel?.dataset['value']) changeData(checkedLabel.dataset['value']);
}

initModule(init);
