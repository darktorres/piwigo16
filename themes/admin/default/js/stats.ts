import {
  LineChart,
  type LineChartConfig,
  type LineChartPoint,
  type LineChartSeries,
  type LineChartUnit,
} from "../../../default/js/vendor/widgets/lineChart";
import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/pageData";
import { data as readData, ready } from "../../../default/js/vendor/utils/dom";

const strNumberPageVisited = pwg_getPageString("Page Visited");
const strAvg = pwg_getPageString("Average last 12 months");
const strMonthsTosplit = pwg_getPageData<string>("month_labels");
const strMonths = strMonthsTosplit.split("~");

// See vendor/widgets/lineChart.ts's own header comment: `moment.locale()` never
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

const strTooltipFormat: Record<DataType, (date: Date) => string> = {
  hours: (date) => timeFormat.format(date),
  days: dayMonth,
  months: (date) => monthLongYearFormat.format(date),
  years: (date) => yearFormat.format(date),
};

const strUnitFormat: Record<LineChartUnit, (date: Date) => string> = {
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
// one. `readData()` (vendor/utils/dom.ts) reproduces that same coercion natively
// (P49-C).
const dataElement = document.getElementById("data")!;
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- deliberate placeholder, immediately filled in below by real readData() calls before any other code can observe it.
const data = {} as StatData;
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- readData() reads this server-rendered #data element's own JSON-object attribute, never adversarial input.
data.hours = readData(dataElement, "hours") as StatDataPoint;
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- readData() reads this server-rendered #data element's own JSON-object attribute, never adversarial input.
data.days = readData(dataElement, "days") as StatDataPoint;
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- readData() reads this server-rendered #data element's own JSON-object attribute, never adversarial input.
data.months = readData(dataElement, "months") as StatDataPoint;
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- readData() reads this server-rendered #data element's own JSON-object attribute, never adversarial input.
data.years = readData(dataElement, "years") as StatDataPoint;
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- readData() reads this server-rendered #data element's own JSON-object attribute, never adversarial input.
data["compare-years"] = readData(dataElement, "compare-years") as StatDataPoint;
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- readData() reads this server-rendered #data element's own JSON-object attribute, never adversarial input.
data["month-stats"] = readData(dataElement, "month-stats") as {
  month: StatDataPoint[];
  avg: number;
};

const dataUnit: Record<DataType, LineChartUnit> = {
  hours: "day",
  days: "month",
  months: "year",
  years: "year",
};

let compareMode = false;

/*-------
Creating graph
-------*/
const canvas = document.querySelector<HTMLCanvasElement>("#stat-graph")!;
const chart = new LineChart(canvas, locale);

const LINE_COLOR = "#FFA646";
const FILL_RGB: [number, number, number] = [255, 119, 0];
const COMPARE_COLORS = ["#ffa744", "#ff5252", "#896af3", "#2883c3", "#6ece5e"];

function changeData(dataType: DataType): void {
  if (!compareMode) {
    const config: LineChartConfig = {
      xAxis: {
        kind: "time",
        unit: dataUnit[dataType],
        tickFormat: strUnitFormat[dataUnit[dataType]],
        tooltipFormat: strTooltipFormat[dataType],
      },
      series: [
        {
          label: strNumberPageVisited,
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
      xAxis: { kind: "category", labels: strMonths },
      series: getComparedYearDataset(),
      legend: true,
      yAxisLabel: strNumberPageVisited,
    });
  } else if (dataType === "months") {
    const days = Array.from({ length: 31 }, (_, i) => String(i + 1));
    chart.setData({
      xAxis: { kind: "category", labels: days },
      series: getMonthStatsDataset(),
      legend: true,
      yAxisLabel: strNumberPageVisited,
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

  data["month-stats"].month.forEach((values: StatDataPoint) => {
    const daysData: (number | undefined)[] = [];
    Object.keys(values).forEach(function (key) {
      lastDate = new Date(key);
      daysData[lastDate.getUTCDate() - 1] = values[key]!;
    });
    datasets.push({
      label:
        lastDate === undefined
          ? ""
          : `${strMonths[lastDate.getMonth()]!} ${String(lastDate.getFullYear())}`,
      color: COMPARE_COLORS[colorIndice % COMPARE_COLORS.length]!,
      points: daysData
        .map((y, day): LineChartPoint | null =>
          y === undefined ? null : { x: day, y },
        )
        .filter((p): p is LineChartPoint => p !== null),
    });
    colorIndice++;
  });

  datasets.push({
    label: strAvg,
    color: COMPARE_COLORS[4]!,
    points: Array.from({ length: 31 }, (_, day) => ({
      x: day,
      y: data["month-stats"].avg,
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

  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- readData() reads this label's own data-value attribute, this same app's own template writes it, never adversarial input.
  return readData(label, "value") as DataType;
}

function checkbox(id: string): HTMLInputElement | null {
  return document.querySelector<HTMLInputElement>("#" + id);
}

//Event listener
document.querySelectorAll(".stat-data-selector label").forEach((label) => {
  label.addEventListener("click", function () {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- readData() reads this label's own data-value attribute, this same app's own template writes it, never adversarial input.
    const dataType = readData(label, "value") as DataType;
    changeData(dataType);
  });
});

document
  .querySelectorAll<HTMLInputElement>(".stat-compare-mode input")
  .forEach((input) => {
    input.addEventListener("change", function () {
      compareMode = input.checked;

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
            .querySelectorAll<HTMLInputElement>(
              "#hours-selector, #days-selector",
            )
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
