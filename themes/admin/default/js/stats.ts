import {
  LineChart,
  type LineChartConfig,
  type LineChartPoint,
  type LineChartSeries,
  type LineChartUnit,
} from "../../../default/js/vendor/lineChart";
import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { data as readData, ready } from "../../../default/js/vendor/dom";
export {};

const str_number_page_visited = pwg_getPageString("Page Visited");
const str_avg = pwg_getPageString("Average last 12 months");
const str_months_tosplit = pwg_getPageData<string>("month_labels");
const str_months = str_months_tosplit.split("~");

// See vendor/lineChart.ts's own header comment: `moment.locale()` never
// actually took effect in production (no `moment/locale/*` file was ever
// imported), so this is a real, deliberate improvement over the old
// behaviour, not a preserved quirk -- `Intl.DateTimeFormat` needs no
// separate locale data file to honor it. `LangCode`'s own `ll_RR` form
// (underscore) becomes the `ll-RR` BCP-47 form `Intl` expects.
const locale = pwg_getPageData<string>("lang_code").replace("_", "-");

const weekdayLongFormat = new Intl.DateTimeFormat(locale, { weekday: "long" });
const monthShortFormat = new Intl.DateTimeFormat(locale, { month: "short" });
const monthLongYearFormat = new Intl.DateTimeFormat(locale, {
  month: "long",
  year: "numeric",
});
const monthShortYearFormat = new Intl.DateTimeFormat(locale, {
  month: "short",
  year: "numeric",
});
const yearFormat = new Intl.DateTimeFormat(locale, { year: "numeric" });
const dayFormat = new Intl.DateTimeFormat(locale, { day: "2-digit" });
const timeFormat = new Intl.DateTimeFormat(locale, {
  hour: "numeric",
  minute: "2-digit",
});

/**
 * moment's `"DD MMM"` is a fixed-order format -- day always before month,
 * regardless of locale, only the month's own name text is localized. Built
 * from 2 separate formatters rather than one combined
 * `Intl.DateTimeFormat({day, month})`, which would follow the locale's own
 * field order instead (month-then-day for `en-US`).
 */
function dayMonth(date: Date): string {
  return `${dayFormat.format(date)} ${monthShortFormat.format(date)}`;
}

type DataType = "hours" | "days" | "months" | "years";

const str_tooltip_format: Record<DataType, (date: Date) => string> = {
  hours: (date) => timeFormat.format(date),
  days: dayMonth,
  months: (date) => monthLongYearFormat.format(date),
  years: (date) => yearFormat.format(date),
};

const str_unit_format: Record<LineChartUnit, (date: Date) => string> = {
  day: (date) => weekdayLongFormat.format(date),
  month: (date) => monthShortYearFormat.format(date),
  year: (date) => yearFormat.format(date),
};

/*-------
Data Get
-------*/
// Each of hours/days/months/years/compare-years is keyed by a
// date-parseable string, valued by a real hit count (real usage: `new
// Date(key)` + `y: data[key]` in getValues() below). month-stats is a
// different real shape: one such record per calendar day-of-month
// across every month shown, plus a single running average.
type StatDataPoint = Record<string, number>;
interface StatData {
  hours: StatDataPoint;
  days: StatDataPoint;
  months: StatDataPoint;
  years: StatDataPoint;
  "compare-years": StatDataPoint;
  "month-stats": { month: StatDataPoint[]; avg: number };
}
// jQuery's `.data()`, not `dataset`: these six attributes hold JSON
// objects, and the coercion that turns a brace-wrapped attribute into a
// parsed object is jQuery's, not the DOM's. `dataset` would hand back the
// raw strings and every `Object.keys()` below would walk the characters of
// one. `readData()` (vendor/dom.ts) reproduces that same coercion natively
// (P49-C).
const dataElement = document.getElementById("data")!;
const data = {} as StatData;
data["hours"] = readData(dataElement, "hours") as StatDataPoint;
data["days"] = readData(dataElement, "days") as StatDataPoint;
data["months"] = readData(dataElement, "months") as StatDataPoint;
data["years"] = readData(dataElement, "years") as StatDataPoint;
data["compare-years"] = readData(dataElement, "compare-years") as StatDataPoint;
data["month-stats"] = readData(dataElement, "month-stats") as {
  month: StatDataPoint[];
  avg: number;
};

const data_unit: Record<DataType, LineChartUnit> = {
  hours: "day",
  days: "month",
  months: "year",
  years: "year",
};

let compareMode = false;

/*-------
Creating graph
-------*/
const canvas = document.getElementById("stat-graph") as HTMLCanvasElement;
const chart = new LineChart(canvas, locale);

const LINE_COLOR = "#FFA646";
const FILL_RGB: [number, number, number] = [255, 119, 0];
const COMPARE_COLORS = ["#ffa744", "#ff5252", "#896af3", "#2883c3", "#6ece5e"];

function changeData(dataType: DataType): void {
  if (!compareMode) {
    const config: LineChartConfig = {
      xAxis: {
        kind: "time",
        unit: data_unit[dataType],
        tickFormat: str_unit_format[data_unit[dataType]],
        tooltipFormat: str_tooltip_format[dataType],
      },
      series: [
        {
          label: str_number_page_visited,
          color: LINE_COLOR,
          fillColor: FILL_RGB,
          points: getValues(data[dataType]),
        },
      ],
      legend: false,
    };
    chart.setData(config);
  } else if (dataType === "years") {
    chart.setData({
      xAxis: { kind: "category", labels: str_months },
      series: getComparedYearDataset(),
      legend: true,
      yAxisLabel: str_number_page_visited,
    });
  } else if (dataType === "months") {
    const days = Array.from({ length: 31 }, (_, i) => String(i + 1));
    chart.setData({
      xAxis: { kind: "category", labels: days },
      series: getMonthStatsDataset(),
      legend: true,
      yAxisLabel: str_number_page_visited,
    });
  }
  // "hours"/"days" are unreachable here: the compare-mode toggle handler
  // below forces the selection to "years" whenever one of them was active,
  // and their own labels are marked `.unavailable` (`pointer-events: none`)
  // for as long as compare mode stays on.
}

//Make Data readable by the chart
function getValues(statDataPoint: StatDataPoint): LineChartPoint[] {
  return Object.keys(statDataPoint).map((key) => ({
    x: new Date(key).getTime(),
    y: statDataPoint[key]!,
  }));
}

function getComparedYearDataset(): LineChartSeries[] {
  // Genuine pre-existing implicit-global bug (no `var`/`let`/`const`
  // anywhere) -- confirmed via the P46-C full sweep's own standalone
  // finding for this exact file. `colors` here and in
  // getMonthStatsDataset() below are 2 independent, always-fully-
  // reinitialized copies of the same literal array, not a real shared
  // global -- safe to properly scope, matching the same "harmless
  // implicit global, no cross-file reliance" fix already applied
  // throughout this campaign (e.g. phpWGOpenWindow's img/newWin).
  const values: Record<string, (number | undefined)[]> = {};

  Object.keys(data["compare-years"]).forEach(function (key) {
    const date = new Date(key);
    const year = String(date.getFullYear());
    values[year] ??= [];
    values[year][date.getMonth()] = data["compare-years"][key]!;
  });

  return Object.keys(values).map((year) => ({
    label: year,
    color: COMPARE_COLORS[parseInt(year) % COMPARE_COLORS.length]!,
    points: values[year]!.map((y, month): LineChartPoint | null =>
      y === undefined ? null : { x: month, y },
    ).filter((p): p is LineChartPoint => p !== null),
  }));
}

function getMonthStatsDataset(): LineChartSeries[] {
  const datasets: LineChartSeries[] = [];
  let colorIndice = 0;
  let lastDate: Date | undefined;

  data["month-stats"]["month"].forEach((values: StatDataPoint) => {
    const days_data: (number | undefined)[] = [];
    Object.keys(values).forEach(function (key) {
      lastDate = new Date(key);
      days_data[lastDate.getUTCDate() - 1] = values[key]!;
    });
    datasets.push({
      label:
        lastDate === undefined
          ? ""
          : `${str_months[lastDate.getMonth()]} ${String(lastDate.getFullYear())}`,
      color: COMPARE_COLORS[colorIndice % COMPARE_COLORS.length]!,
      points: days_data
        .map((y, day): LineChartPoint | null =>
          y === undefined ? null : { x: day, y },
        )
        .filter((p): p is LineChartPoint => p !== null),
    });
    colorIndice++;
  });

  datasets.push({
    label: str_avg,
    color: COMPARE_COLORS[4]!,
    points: Array.from({ length: 31 }, (_, day) => ({
      x: day,
      y: data["month-stats"]["avg"],
    })),
  });

  return datasets;
}

// The label carries `data-value`; reading it through the helper keeps
// jQuery's coercion, which leaves a plain word a string.
function selectedDataType(): DataType {
  const label = document.querySelector(
    ".stat-data-selector input:checked + label",
  )!;

  return readData(label, "value") as DataType;
}

function checkbox(id: string): HTMLInputElement | null {
  return document.getElementById(id) as HTMLInputElement | null;
}

//Event listener
document.querySelectorAll(".stat-data-selector label").forEach((label) => {
  label.addEventListener("click", function () {
    const dataType = readData(label, "value") as DataType;
    changeData(dataType);
  });
});

document.querySelectorAll(".stat-compare-mode input").forEach((input) => {
  input.addEventListener("change", function () {
    compareMode = (input as HTMLInputElement).checked;

    const unavailable = document.querySelectorAll(
      "#hours-selector + label, #days-selector + label",
    );

    if (compareMode) {
      unavailable.forEach((label) => {
        label.classList.add("unavailable");
      });
      if (
        checkbox("hours-selector")?.checked === true ||
        checkbox("days-selector")?.checked === true
      ) {
        const years = checkbox("years-selector");
        if (years !== null) {
          years.checked = true;
        }
        document
          .querySelectorAll<HTMLInputElement>("#hours-selector, #days-selector")
          .forEach((selector) => {
            selector.checked = false;
          });
        changeData("years");
      } else {
        changeData(selectedDataType());
      }
    } else {
      unavailable.forEach((label) => {
        label.classList.remove("unavailable");
      });
      changeData(selectedDataType());
    }
  });
});

/*-------
Initialize the page
-------*/
ready(function () {
  changeData(selectedDataType());
});
