import { getPageData } from './page-data';

declare var Chart: any;

interface StatsPageData {
    str_avg: string;
    str_number_page_visited: string;
    str_months: string[];
}

const { str_avg, str_number_page_visited, str_months } = getPageData<StatsPageData>();

const str_tooltip_format: Record<string, string> = { years: 'YYYY', months: 'MMMM YYYY', days: 'DD MMM', hours: 'LT' };
const str_unit_format: Record<string, string> = { day: 'dddd', month: 'MMM YYYY' };

let averageTab: any;
let colorIndice: any;
let colors: any;
let compareMode: any;
let dataType: any;
let data_unit: any;
let dataset: any;
let date: any;
let days: any;
let values: any;

/*-------
Data Get
-------*/
const dataEl = document.getElementById('data')!;
let data: any = {};
data["hours"] = JSON.parse(dataEl.dataset['hours'] ?? '{}');
data["days"] = JSON.parse(dataEl.dataset['days'] ?? '{}');
data["months"] = JSON.parse(dataEl.dataset['months'] ?? '{}');
data["years"] = JSON.parse(dataEl.dataset['years'] ?? '{}');
data["compare-years"] = JSON.parse(dataEl.dataset['compareYears'] ?? '{}');
data["month-stats"] = JSON.parse(dataEl.dataset['monthStats'] ?? '{}');

data_unit = { "hours": "day", "days": "month", "months": "year", "years": "year" };
compareMode = false;

/*-------
Creating graph
-------*/
var ctx = (document.getElementById('stat-graph') as HTMLCanvasElement).getContext('2d')!;

function gradient(r: any, g: any, b: any) {
    let grad = ctx.createLinearGradient(0, 400, 0, 0);
    grad.addColorStop(0, 'rgba(' + r + ',' + g + ',' + b + ',0)');
    grad.addColorStop(1, 'rgba(' + r + ',' + g + ',' + b + ',1)');
    return grad;
}

Chart.defaults.global.elements.point.radius = 0.1;
Chart.defaults.global.elements.point.hitRadius = 10;
Chart.defaults.global.defaultFontSize = 14;
Chart.defaults.global.defaultFontColor = '#888';
Chart.defaults.global.tooltips.intersect = false;
Chart.defaults.global.legend.onClick = null;

var statGraph = new Chart(ctx, { type: 'line', maintainAspectRatio: false });

var displayOptions = { backgroundColor: gradient(255, 119, 0), borderColor: '#FFA646 ', lineTension: 0.2 };

function changeData(dataType: any, options = displayOptions) {
    if (!compareMode) {
        statGraph.data = {
            datasets: [{ label: str_number_page_visited, data: getValues(data[dataType]), ...options }]
        };
        statGraph.options = {
            scales: {
                xAxes: [{ type: 'time', time: { tooltipFormat: 'll' }, gridLines: { display: false } }],
                yAxes: [{ ticks: { min: 0 } }]
            },
            legend: { display: false },
            tooltips: { mode: 'index' },
            hover: { intersect: false }
        };
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
        if (dataType == "years") {
            statGraph.data = { datasets: getComparedYearDataset() };
            statGraph.options.scales = {
                xAxes: [{ type: 'category', labels: str_months, gridLines: { display: false } }],
                yAxes: [{ scaleLabel: { display: true, labelString: str_number_page_visited }, tick: { min: 0 } }]
            };
        } else if (dataType == "months") {
            days = [];
            for (let i = 1; i <= 31; i++) days.push(i);
            statGraph.data = { datasets: getMonthStatsDataset() };
            statGraph.options.scales = {
                xAxes: [{ type: 'category', labels: days, gridLines: { display: false } }],
                yAxes: [{ scaleLabel: { display: true, labelString: str_number_page_visited } }]
            };
        }
        statGraph.update();
    }
}

function getValues(data: any) {
    values = [];
    Object.keys(data).forEach(key => values.push({ x: new Date(key), y: data[key] }));
    return values;
}

function getComparedYearDataset() {
    colors = ["#ffa744", "#ff5252", "#896af3", "#2883c3", "#6ece5e"];
    values = {};
    dataset = [];
    Object.keys(data["compare-years"]).forEach(key => {
        date = new Date(key);
        if (values[date.getFullYear()] == undefined) values[date.getFullYear()] = [];
        values[date.getFullYear()][parseInt(date.getMonth())] = data["compare-years"][key];
    });
    Object.keys(values).forEach(key => {
        dataset.push({ label: key, data: values[key], lineTension: 0.2, borderColor: colors[parseInt(key) % colors.length], backgroundColor: "rgba(0,0,0,0)" });
    });
    return dataset;
}

function getMonthStatsDataset() {
    colors = ["#ffa744", "#ff5252", "#896af3", "#2883c3", "#6ece5e"];
    dataset = [];
    colorIndice = 0;
    let date: any;
    data["month-stats"]["month"].forEach((vals: any) => {
        let days_data: any[] = [];
        Object.keys(vals).forEach(key => {
            date = new Date(key);
            days_data[parseInt(date.getUTCDate()) - 1] = vals[key];
        });
        dataset.push({ label: str_months[date.getMonth()] + " " + date.getFullYear(), data: days_data, lineTension: 0.2, borderColor: colors[colorIndice % colors.length], backgroundColor: "rgba(0,0,0,0)" });
        colorIndice++;
    });
    averageTab = [];
    for (let i = 0; i < 31; i++) averageTab[i] = data["month-stats"]["avg"];
    dataset.push({ label: str_avg, data: averageTab, lineTension: 0.2, borderColor: colors[4], backgroundColor: "rgba(0,0,0,0)" });
    return dataset;
}

/*-------
Event listeners
-------*/
document.querySelectorAll<HTMLElement>(".stat-data-selector label").forEach(el => {
    el.addEventListener("click", function(this: HTMLElement) {
        dataType = this.dataset['value'];
        changeData(dataType);
    });
});

document.querySelectorAll<HTMLInputElement>(".stat-compare-mode input").forEach(el => {
    el.addEventListener("change", function(this: HTMLInputElement) {
        compareMode = this.checked;
        if (compareMode) {
            document.querySelectorAll<HTMLElement>('#hours-selector + label, #days-selector + label')
                .forEach(l => l.classList.add('unavailable'));
            const hoursChecked = (document.getElementById('hours-selector') as HTMLInputElement).checked;
            const daysChecked = (document.getElementById('days-selector') as HTMLInputElement).checked;
            if (hoursChecked || daysChecked) {
                (document.getElementById('years-selector') as HTMLInputElement).checked = true;
                (document.getElementById('hours-selector') as HTMLInputElement).checked = false;
                (document.getElementById('days-selector') as HTMLInputElement).checked = false;
                changeData("years");
            } else {
                changeData(document.querySelector<HTMLElement>('.stat-data-selector input:checked + label')?.dataset['value']);
            }
        } else {
            document.querySelectorAll<HTMLElement>('#hours-selector + label, #days-selector + label')
                .forEach(l => l.classList.remove('unavailable'));
            changeData(document.querySelector<HTMLElement>('.stat-data-selector input:checked + label')?.dataset['value']);
        }
    });
});

/*-------
Initialize
-------*/
changeData(document.querySelector<HTMLElement>('.stat-data-selector input:checked + label')?.dataset['value']);

export {};
