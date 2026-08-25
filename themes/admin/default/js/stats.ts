export {};

const str_number_page_visited = pwg_getPageString("Page Visited");
const str_tooltip_format: Record<string, string> = {
  years: "YYYY",
  months: "MMMM YYYY",
  days: "DD MMM",
  hours: "LT",
};
const str_unit_format = {
  day: "dddd",
  month: "MMM YYYY",
};
const str_avg = pwg_getPageString("Average last 12 months");
const str_months_tosplit = pwg_getPageData("month_labels");
const str_months = str_months_tosplit.split("~");
moment.locale(pwg_getPageData("lang_code"));

/*-------
Data Get
-------*/
const data: Record<string, any> = {};
data["hours"] = $("#data").data("hours");
data["days"] = $("#data").data("days");
data["months"] = $("#data").data("months");
data["years"] = $("#data").data("years");
data["compare-years"] = $("#data").data("compare-years");
data["month-stats"] = $("#data").data("month-stats");

const data_unit: Record<string, string> = {
  hours: "day",
  days: "month",
  months: "year",
  years: "year",
};

let compareMode = false;

/*-------
Creating graph
-------*/
const ctx = (
  document.getElementById("stat-graph") as HTMLCanvasElement
).getContext("2d")!;
//Create the gradient under the curve
function gradient(r: number, g: number, b: number) {
  const gradient = ctx.createLinearGradient(0, 400, 0, 0);
  gradient.addColorStop(0, "rgba(" + r + "," + g + "," + b + ",0)");
  gradient.addColorStop(1, "rgba(" + r + "," + g + "," + b + ",1)");
  return gradient;
}

//Setup the graph
window.Chart.defaults.global.elements!.point!.radius = 0.1;
window.Chart.defaults.global.elements!.point!.hitRadius = 10;
window.Chart.defaults.global.defaultFontSize = 14;
window.Chart.defaults.global.defaultFontColor = "#888";
window.Chart.defaults.global.tooltips.intersect = false;
window.Chart.defaults.global.legend!.onClick = undefined;

const statGraph = new window.Chart(ctx, {
  type: "line",
  options: {
    maintainAspectRatio: false,
  },
});

//Line options
const displayOptions = {
  backgroundColor: gradient(255, 119, 0),
  borderColor: "#FFA646 ",
  lineTension: 0.2,
};

function changeData(dataType: any, options: any = displayOptions) {
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
    statGraph.options.scales!.xAxes!.forEach((axe: any) => {
      axe.time.tooltipFormat = str_tooltip_format[dataType];
      axe.time.unit = data_unit[dataType];
      axe.time.displayFormats = str_unit_format;
    });
    statGraph.update();
  } else {
    statGraph.options.legend!.display = true;
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
            ticks: {
              min: 0,
            },
          },
        ],
      };
    } else if (dataType == "months") {
      const days: string[] = [];
      for (let i = 1; i <= 31; i++) {
        days.push(String(i));
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
function getValues(data: any) {
  const values: any[] = [];
  Object.keys(data).forEach(function (key) {
    const newPoint = {
      x: new Date(key),
      y: data[key],
    };
    values.push(newPoint);
  });
  return values;
}

function getComparedYearDataset() {
  // Genuine pre-existing implicit-global bug (no `var`/`let`/`const`
  // anywhere) -- confirmed via the P46-C full sweep's own standalone
  // finding for this exact file. `colors` here and in
  // getMonthStatsDataset() below are 2 independent, always-fully-
  // reinitialized copies of the same literal array, not a real shared
  // global -- safe to properly scope, matching the same "harmless
  // implicit global, no cross-file reliance" fix already applied
  // throughout this campaign (e.g. phpWGOpenWindow's img/newWin).
  const colors = ["#ffa744", "#ff5252", "#896af3", "#2883c3", "#6ece5e"];
  const values: Record<string, any> = {};
  const dataset: any[] = [];

  Object.keys(data["compare-years"]).forEach(function (key) {
    const date = new Date(key);
    if (values[date.getFullYear()] == undefined) {
      values[date.getFullYear()] = [];
    }
    values[date.getFullYear()][parseInt(String(date.getMonth()))] =
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
  const colors = ["#ffa744", "#ff5252", "#896af3", "#2883c3", "#6ece5e"];
  const dataset: any[] = [];
  let colorIndice = 0;
  let date: Date;

  data["month-stats"]["month"].forEach((values: any) => {
    const days_data: any[] = [];
    Object.keys(values).forEach(function (key) {
      date = new Date(key);
      days_data[parseInt(String(date.getUTCDate())) - 1] = values[key];
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

  const averageTab: any[] = [];
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
$(".stat-data-selector label").on("click", function () {
  const dataType = $(this).data("value");
  changeData(dataType);
});

$(".stat-compare-mode input").on("change", function () {
  compareMode = ($(this)[0] as HTMLInputElement).checked;

  if (compareMode) {
    $("#hours-selector + label, #days-selector + label").addClass(
      "unavailable",
    );
    if (
      $("#hours-selector").prop("checked") ||
      $("#days-selector").prop("checked")
    ) {
      $("#years-selector").prop("checked", true);
      $("#hours-selector, #days-selector").prop("checked", false);
      changeData("years");
    } else {
      changeData($(".stat-data-selector input:checked + label").data("value"));
    }
  } else {
    $("#hours-selector + label, #days-selector + label").removeClass(
      "unavailable",
    );
    changeData($(".stat-data-selector input:checked + label").data("value"));
  }
});

/*-------
Initialize the page
-------*/
$(function () {
  changeData($(".stat-data-selector input:checked + label").data("value"));
});
