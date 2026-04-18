import { initModule } from './moduleInit.js';
import Chart from 'chart.js';
import moment from 'moment';

export function init(cfg) {
    const { str_number_page_visited, str_number_page_visited_with_year, str_avg, str_months_tosplit } = cfg;

    const str_tooltip_format = {
      "years": "YYYY",
      "months": "MMMM YYYY",
      "days": "DD MMM",
      "hours": "LT"
    };
    const str_unit_format = {
      "day": "dddd",
      "month": "MMM YYYY"
    };
    const str_months = (str_months_tosplit || '').split('~');

/*-------
Data Get
-------*/
const dataEl = document.getElementById("data");
const data = {};
data["hours"] = dataEl ? JSON.parse(dataEl.dataset.hours || 'null') : null;
data["days"] = dataEl ? JSON.parse(dataEl.dataset.days || 'null') : null;
data["months"] = dataEl ? JSON.parse(dataEl.dataset.months || 'null') : null;
data["years"] = dataEl ? JSON.parse(dataEl.dataset.years || 'null') : null;
data["compare-years"] = dataEl ? JSON.parse(dataEl.dataset.compareYears || 'null') : null;
data["month-stats"] = dataEl ? JSON.parse(dataEl.dataset.monthStats || 'null') : null;

const data_unit = {
    hours: "day",
    days: "month",
    months: "year",
    years: "year",
};

let compareMode = false;

/*-------
Creating graph
-------*/
const ctx = document.getElementById("stat-graph").getContext("2d");
//Create the gradient under the curve
function gradient(r, g, b) {
    let gradient = ctx.createLinearGradient(0, 400, 0, 0);
    gradient.addColorStop(0, "rgba(" + r + "," + g + "," + b + ",0)");
    gradient.addColorStop(1, "rgba(" + r + "," + g + "," + b + ",1)");
    return gradient;
}

//Setup the graph
Chart.defaults.global.elements.point.radius = 0.1;
Chart.defaults.global.elements.point.hitRadius = 10;
Chart.defaults.global.defaultFontSize = 14;
Chart.defaults.global.defaultFontColor = "#888";
Chart.defaults.global.tooltips.intersect = false;
Chart.defaults.global.legend.onClick = null;

const statGraph = new Chart(ctx, {
    type: "line",
    maintainAspectRatio: false,
});

//Line options
const displayOptions = {
    backgroundColor: gradient(255, 119, 0),
    borderColor: "rgba(255,119,0,1)",
    lineTension: 0.2,
};

function changeData(dataType, options) {
    options = options || displayOptions;
    if (!compareMode) {
        statGraph.data = {
            datasets: [
                {
                    label: str_number_page_visited,
                    data: getValues(data[dataType]),
                    ...options,
                },
            ],
        };
        statGraph.options = {
            scales: {
                xAxes: [
                    {
                        type: "time",
                        time: {
                            tooltipFormat: "ll",
                        },
                        gridLines: {
                            display: false,
                        },
                    },
                ],
                yAxes: [
                    {
                        ticks: {
                            min: 0,
                        },
                    },
                ],
            },
            legend: {
                display: false,
            },
            tooltips: {
                mode: "index",
            },
            hover: {
                intersect: false,
            },
        };
        statGraph.options.scales.xAxes.forEach((axe) => {
            axe.time.tooltipFormat = str_tooltip_format[dataType];
            axe.time.unit = data_unit[dataType];
            axe.time.displayFormats = str_unit_format;
        });
        statGraph.update();
    } else {
        statGraph.options.legend.display = true;
        statGraph.options.hover = {
            intersect: true,
        };
        statGraph.options.tooltips = {
            mode: "nearest",
        };
        if (dataType == "years") {
            statGraph.data = {
                datasets: getComparedYearDataset(),
            };
            statGraph.options.scales = {
                xAxes: [
                    {
                        type: "category",
                        labels: str_months,
                        gridLines: {
                            display: false,
                        },
                    },
                ],
                yAxes: [
                    {
                        scaleLabel: {
                            display: true,
                            labelString: str_number_page_visited,
                        },
                        tick: {
                            min: 0,
                        },
                    },
                ],
            };
        } else if (dataType == "months") {
            days = [];
            for (let i = 1; i <= 31; i++) {
                days.push(i);
            }
            statGraph.data = {
                datasets: getMonthStatsDataset(),
            };
            statGraph.options.scales = {
                xAxes: [
                    {
                        type: "category",
                        labels: days,
                        gridLines: {
                            display: false,
                        },
                    },
                ],
                yAxes: [
                    {
                        scaleLabel: {
                            display: true,
                            labelString: str_number_page_visited,
                        },
                    },
                ],
            };
        }
        statGraph.update();
    }
}

//Make Data readable by Chart.js
function getValues(data) {
    values = [];
    Object.keys(data).forEach(function (key) {
        var newPoint = {
            x: new Date(key),
            y: data[key],
        };
        values.push(newPoint);
    });
    return values;
}

function getComparedYearDataset() {
    colors = ["#ffa744", "#ff5252", "#896af3", "#2883c3", "#6ece5e"];
    values = {};
    dataset = [];

    Object.keys(data["compare-years"]).forEach(function (key) {
        date = new Date(key);
        if (values[date.getFullYear()] == undefined) {
            values[date.getFullYear()] = [];
        }
        values[date.getFullYear()][parseInt(date.getMonth())] =
            data["compare-years"][key];
    });

    Object.keys(values).forEach(function (key) {
        dataset.push({
            label: key,
            data: values[key],
            lineTension: 0.2,
            borderColor: colors[parseInt(key) % colors.length],
            backgroundColor: "rgba(0,0,0,0)",
        });
    });

    return dataset;
}

function getMonthStatsDataset() {
    colors = ["#ffa744", "#ff5252", "#896af3", "#2883c3", "#6ece5e"];
    dataset = [];
    colorIndice = 0;
    let date;

    data["month-stats"]["month"].forEach((values) => {
        let days_data = [];
        Object.keys(values).forEach(function (key) {
            date = new Date(key);
            days_data[parseInt(date.getUTCDate()) - 1] = values[key];
        });
        dataset.push({
            label: str_months[date.getMonth()] + " " + date.getFullYear(),
            data: days_data,
            lineTension: 0.2,
            borderColor: colors[colorIndice % colors.length],
            backgroundColor: "rgba(0,0,0,0)",
        });
        colorIndice++;
    });

    averageTab = [];
    for (let i = 0; i < 31; i++) {
        averageTab[i] = data["month-stats"]["avg"];
    }
    dataset.push({
        label: str_avg,
        data: averageTab,
        lineTension: 0.2,
        borderColor: colors[4],
        backgroundColor: "rgba(0,0,0,0)",
    });

    return dataset;
}

//Event listener
document.querySelectorAll(".stat-data-selector label").forEach(function (label) {
    label.addEventListener("click", function () {
        dataType = this.dataset.value;
        changeData(dataType);
    });
});

document.querySelectorAll(".stat-compare-mode input").forEach(function (input) {
    input.addEventListener("change", function () {
        compareMode = this.checked;

        if (compareMode) {
            document.querySelectorAll("#hours-selector + label, #days-selector + label").forEach(function (el) {
                el.classList.add("unavailable");
            });
            var hoursChecked = document.getElementById("hours-selector");
            var daysChecked = document.getElementById("days-selector");
            if (
                (hoursChecked && hoursChecked.checked) ||
                (daysChecked && daysChecked.checked)
            ) {
                var yearsSelector = document.getElementById("years-selector");
                if (yearsSelector) yearsSelector.checked = true;
                document.querySelectorAll("#hours-selector, #days-selector").forEach(function (el) {
                    el.checked = false;
                });
                changeData("years");
            } else {
                var checkedLabel = document.querySelector(".stat-data-selector input:checked + label");
                if (checkedLabel) changeData(checkedLabel.dataset.value);
            }
        } else {
            document.querySelectorAll("#hours-selector + label, #days-selector + label").forEach(function (el) {
                el.classList.remove("unavailable");
            });
            var checkedLabel = document.querySelector(".stat-data-selector input:checked + label");
            if (checkedLabel) changeData(checkedLabel.dataset.value);
        }
    });
});

/*-------
Initialize the page
-------*/
    var checkedLabel = document.querySelector(".stat-data-selector input:checked + label");
    if (checkedLabel) changeData(checkedLabel.dataset.value);
}

initModule(init);
