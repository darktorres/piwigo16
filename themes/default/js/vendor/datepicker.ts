// Native port of jQuery UI's datepicker widget + jquery-timepicker-addon
// (P49-B, `pwgDatepicker`), real source read from the vendored packages
// (`node_modules/jquery-ui/datepicker.js`, jQuery UI 1.10.4;
// `node_modules/jquery-timepicker-addon/src/jquery-ui-timepicker-addon.js`,
// `github:trentrichardson/jQuery-Timepicker-Addon#v1.4.4`), across all 4
// real call sites (`batchManagerGlobal.ts`/`batchManagerUnit.ts`/
// `picture_modify.ts`'s own `{showTimepicker:true, cancelButton:...}`
// creation-date pickers, `history.ts`'s own plain (no time, no cancel
// button) start/end search-range pickers) plus `datepicker.ts`'s own
// former `jQuery.fn.pwgDatepicker` wrapper, folded directly into this
// module rather than kept as a separate wrapper layer over a generic
// vendored widget -- unlike jQuery UI's own datepicker, nothing else in
// this app ever uses it un-wrapped.
//
// Narrowed hard to what these 4 real call sites actually reach:
// - Every real picker is "linked" (`data-datepicker="name"` always
//   matches a real `<input type=hidden name="name">` elsewhere in the
//   form) -- the original's own "unlinked, standalone" branch
//   (`dateFormat: "yy-mm-dd"`, `altField: null`) is real, unreachable
//   dead code here and isn't ported; the visible field's format is
//   always the linked one (`"DD d MM yy"`, e.g. "Monday 1 September
//   2026"), and the alt (hidden, form-submitted) field's format is
//   always `"yy-mm-dd"` (`showTimepicker`: `"yy-mm-dd HH:mm:ss"`).
// - Every real visible input is `readonly` (confirmed in every one of
//   the 4 real templates) -- manual typing into it is unreachable in
//   every real call site, so the original's own `constrainInput`
//   character-filtering and keyup-parses-what-you-typed sync aren't
//   ported; the only way to change the value is this module's own UI.
// - Single month view only (`numberOfMonths` never set); no inline mode
//   (every real target is a plain `<input>`); no `beforeShowDay`/
//   `showOtherMonths`/`selectOtherMonths` (real, always-off defaults);
//   no button-trigger icon (`showOn` always its own real default,
//   "focus").
// - Locale IS real and load-bearing here, unlike most of this app's
//   other P49 ports: `DatepickerView.php`'s own former per-request
//   `jqueryCode` (`Lang::langInfo()['jquery_code']`) picked which of
//   jQuery UI's 67 real `ui/i18n/jquery.ui.datepicker-*.js` files and
//   jquery-timepicker-addon's own 39 real `i18n/jquery-ui-timepicker-
//   *.js` files to load, each self-registering into `$.datepicker.
//   regional[code]`/`$.timepicker.regional[code]` and calling
//   `setDefaults()` -- a real, live production behavior for this
//   install's 72 real installed languages, not an unreachable option.
//   `vendor/datepickerLocales.ts` carries every one of the two real,
//   authoritative locale sets verbatim (extracted from the real
//   upstream files, not hand-translated), keyed the same way
//   `Lang::langInfo()['jquery_code']` resolves; `admin_help.ts`-style
//   `pwg_getPageData<string>("jquery_code")` (`AdminShellFramePageContext`'s
//   own new field, since this is the first native port needing a
//   locale identifier client-side rather than picking a script to load
//   server-side) supplies the current request's own code, which may
//   match neither list (a real, current production gap already --
//   `DatepickerView.php`'s own `in_array()` gate silently fell back to
//   English for exactly the same mismatches, e.g. Basque's real
//   `jquery_code` "eus" vs. jQuery UI's own "eu"), in which case this
//   module falls back to the same English defaults every other P49
//   port already hardcodes. `dayNamesShort`/`weekHeader`/`dateFormat`/
//   `amNames`/`pmNames`/`timeFormat`/`timeSuffix`/second-and-below text
//   are real per-locale fields this app's own narrowed usage never
//   surfaces (no week-of-year column, no manual typing to parse, no
//   AM/PM or sub-minute time units -- see below) and aren't carried.
// - `yearRange`'s own min/max-year gating on the prev/next arrows isn't
//   ported: `datepicker.ts`'s own former real customization (still
//   applied here directly, not as a monkeypatch) already replaces the
//   year `<select>` with a free-typed number input, so yearRange's
//   *only* remaining real effect was arrow-disabling after enough
//   consecutive clicks -- an edge case nothing exercises, since typing
//   a year directly (unbounded) was always the app's own intended path
//   for going further than that. `minDate`/`maxDate` (real, used by
//   the history search range's own cross-linking) still gate the
//   prev/next arrows and disable individual days.
// - Time: hour + minute only -- `timeFormat` is always `"HH:mm"` (no
//   seconds/millisec/microsec/AM-PM/timezone tokens), so
//   `jquery-timepicker-addon`'s own format-driven "which units does
//   this format need" detection always resolves to just those two,
//   both real, always-visible sliders (`controlType` never overridden
//   from its own real default, `"slider"`) -- reusing the already-
//   ported `vendor/slider.ts` rather than reimplementing jQuery UI's
//   slider widget again. No grids, no slider-access touch integration,
//   no per-instance `minTime`/`maxTime` (never set; the *cross-picker*
//   `minDate`/`maxDate` linking only ever constrains whole days here,
//   never a specific time-of-day boundary, since no real call site
//   links two *time-bearing* pickers to each other -- only history.ts's
//   own plain, time-less pair).
// - The "Now"/"Done" button pane is the original's own datepicker-core
//   button panel (`showButtonPanel`, always real/on once
//   `jquery-timepicker-addon` is in the picture at all -- even for a
//   plain, showTimepicker-false picker like history.ts's own), just
//   relabeled by the addon's own regional strings ("Today"/"Close" ->
//   "Now"/"Done"); "Now" also grabs the current time, a real
//   `jquery-timepicker-addon` behavior even when no time UI is shown
//   (harmless there, since nothing reads the hour/minute it sets).
// - Selecting a day keeps the picker open when `showTimepicker` (so the
//   time can still be adjusted before "Done"), matching the original's
//   own `_selectDate` override exactly; it closes immediately
//   otherwise (`history.ts`'s own real, unmodified base behavior).
import { slider } from "./slider";
import {
  DATEPICKER_LOCALES,
  TIMEPICKER_LOCALES,
  type DatepickerLocale,
  type TimepickerLocale,
} from "./datepickerLocales";

export interface PwgDatepickerOptions {
  showTimepicker?: boolean;
  cancelButton?: string | false;
  jqueryCode?: string | undefined;
}

const ENGLISH_DATEPICKER_LOCALE: DatepickerLocale = {
  monthNames: [
    "January",
    "February",
    "March",
    "April",
    "May",
    "June",
    "July",
    "August",
    "September",
    "October",
    "November",
    "December",
  ],
  monthNamesShort: [
    "Jan",
    "Feb",
    "Mar",
    "Apr",
    "May",
    "Jun",
    "Jul",
    "Aug",
    "Sep",
    "Oct",
    "Nov",
    "Dec",
  ],
  dayNames: [
    "Sunday",
    "Monday",
    "Tuesday",
    "Wednesday",
    "Thursday",
    "Friday",
    "Saturday",
  ],
  dayNamesMin: ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"],
  firstDay: 0,
  isRTL: false,
  showMonthAfterYear: false,
  yearSuffix: "",
  prevText: "Prev",
  nextText: "Next",
};

const ENGLISH_TIMEPICKER_LOCALE: TimepickerLocale = {
  currentText: "Now",
  closeText: "Done",
  timeText: "Time",
  hourText: "Hour",
  minuteText: "Minute",
};

function resolveDatepickerLocale(jqueryCode: string | undefined): DatepickerLocale {
  if (jqueryCode === undefined) {
    return ENGLISH_DATEPICKER_LOCALE;
  }
  return DATEPICKER_LOCALES[jqueryCode] ?? ENGLISH_DATEPICKER_LOCALE;
}

function resolveTimepickerLocale(jqueryCode: string | undefined): TimepickerLocale {
  if (jqueryCode === undefined) {
    return ENGLISH_TIMEPICKER_LOCALE;
  }
  return TIMEPICKER_LOCALES[jqueryCode] ?? ENGLISH_TIMEPICKER_LOCALE;
}

function pad2(n: number): string {
  return (n < 10 ? "0" : "") + String(n);
}

function daysInMonth(year: number, month: number): number {
  return new Date(year, month + 1, 0).getDate();
}

function sameDay(a: Date | null, b: Date | null): boolean {
  return (
    a !== null &&
    b !== null &&
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  );
}

function stripTime(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

/** `$.datepicker.formatDate("DD d MM yy", date)` -- the visible field. */
function formatLongDate(date: Date, locale: DatepickerLocale): string {
  // `yearSuffix` is real, but only in the calendar header's own
  // month/year title (`_generateMonthYearHeader()`) -- `_formatDate()`
  // itself never appends it, confirmed against the real source
  // (`node_modules/jquery-ui/datepicker.js`'s only other reference).
  return (
    locale.dayNames[date.getDay()]! +
    " " +
    String(date.getDate()) +
    " " +
    locale.monthNames[date.getMonth()]! +
    " " +
    String(date.getFullYear())
  );
}

function longestNameIndex(names: readonly string[]): number {
  let maxLength = 0;
  let maxIndex = 0;
  for (let i = 0; i < names.length; i++) {
    if (names[i]!.length > maxLength) {
      maxLength = names[i]!.length;
      maxIndex = i;
    }
  }
  return maxIndex;
}

/**
 * `$.datepicker._autoSize()` -- real, always-on for every linked picker
 * (the original's own hardcoded `autoSize: true`): sizes the visible
 * field to fit the longest real day/month name combination in the
 * active locale, not a fixed guess -- dropping it left the field its
 * own browser-default width, reflowing every sibling on the row (a
 * real VR regression this reproduced, `dateFormat` always matching
 * `DD`+`MM`, so this always measures the full (never short) names).
 */
function autoSizeLength(locale: DatepickerLocale): number {
  const date = new Date(2009, 11, 20);
  date.setMonth(longestNameIndex(locale.monthNames));
  date.setDate(longestNameIndex(locale.dayNames) + 20 - date.getDay());
  return formatLongDate(date, locale).length;
}

/** `$.datepicker.formatDate("yy-mm-dd", date)` -- the alt (hidden) field. */
function formatIsoDate(date: Date): string {
  return (
    String(date.getFullYear()) +
    "-" +
    pad2(date.getMonth() + 1) +
    "-" +
    pad2(date.getDate())
  );
}

/** `$.datepicker.formatTime("HH:mm", ...)`. */
function formatShortTime(hour: number, minute: number): string {
  return pad2(hour) + ":" + pad2(minute);
}

/** `$.datepicker.formatTime("HH:mm:ss", ...)`. */
function formatIsoTime(hour: number, minute: number): string {
  return pad2(hour) + ":" + pad2(minute) + ":00";
}

/**
 * `$.datepicker.parseDate("yy-mm-dd", ...)`/`.parseDateTime("yy-mm-dd",
 * "HH:mm:ss", ...)` -- the only two real formats ever parsed back
 * (the alt field's own value, read at init to seed the picker).
 */
function parseIsoDateTime(
  value: string
): { date: Date; hour: number; minute: number } | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/.exec(value);
  if (match === null) {
    return null;
  }
  const year = Number(match[1]);
  const month = Number(match[2]) - 1;
  const day = Number(match[3]);
  const date = new Date(year, month, day);
  if (
    date.getFullYear() !== year ||
    date.getMonth() !== month ||
    date.getDate() !== day
  ) {
    return null;
  }
  return {
    date,
    hour: match[4] !== undefined ? Number(match[4]) : 0,
    minute: match[5] !== undefined ? Number(match[5]) : 0,
  };
}

interface Instance {
  input: HTMLInputElement;
  altField: HTMLInputElement;
  showTimepicker: boolean;
  cancelButtonLabel: string | false;
  dpLocale: DatepickerLocale;
  tpLocale: TimepickerLocale;
  unsetEl: Element | null;
  minDate: Date | null;
  maxDate: Date | null;
  onCloseNotify: ((date: Date | null) => void) | null;
  selected: Date | null;
  hour: number;
  minute: number;
  drawMonth: number;
  drawYear: number;
  originalDate: Date | null;
  originalHour: number;
  originalMinute: number;
}

const instancesByKey = new Map<string, Instance>();
const instanceByInput = new WeakMap<HTMLInputElement, Instance>();

let active: Instance | undefined;

// ── Shared popup singleton ──────────────────────────────────────────────

let popupEl: HTMLDivElement;
let headerEl: HTMLDivElement;
let titleEl: HTMLDivElement;
let monthSelectEl: HTMLSelectElement;
let yearInputEl: HTMLInputElement;
let prevBtn: HTMLAnchorElement;
let prevIcon: HTMLSpanElement;
let nextBtn: HTMLAnchorElement;
let nextIcon: HTMLSpanElement;
let calendarBodyEl: HTMLTableSectionElement;
let headerThs: HTMLTableCellElement[];
let headerSpans: HTMLSpanElement[];
let timeDivEl: HTMLDivElement;
let timeLabelDtEl: HTMLElement;
let timeLabelEl: HTMLElement;
let hourDtEl: HTMLElement;
let hourSliderEl: HTMLDivElement;
let minuteDtEl: HTMLElement;
let minuteSliderEl: HTMLDivElement;
let buttonPaneEl: HTMLDivElement;
let nowBtn: HTMLButtonElement;
let doneBtn: HTMLButtonElement;
let cancelBtnEl: HTMLButtonElement | undefined;
let popupBuilt = false;

function tag<K extends keyof HTMLElementTagNameMap>(
  name: K,
  className?: string
): HTMLElementTagNameMap[K] {
  const el = document.createElement(name);
  if (className !== undefined) {
    el.className = className;
  }
  return el;
}

function buildPopup(): void {
  if (popupBuilt) {
    return;
  }
  popupBuilt = true;

  popupEl = tag(
    "div",
    "ui-datepicker ui-widget ui-widget-content ui-helper-clearfix ui-corner-all"
  );
  popupEl.style.display = "none";
  popupEl.style.position = "absolute";
  popupEl.style.zIndex = "1";

  headerEl = tag(
    "div",
    "ui-datepicker-header ui-widget-header ui-helper-clearfix ui-corner-all"
  );

  prevBtn = tag("a", "ui-datepicker-prev ui-corner-all");
  prevBtn.href = "#";
  prevIcon = tag("span", "ui-icon ui-icon-circle-triangle-w");
  prevBtn.append(prevIcon);

  nextBtn = tag("a", "ui-datepicker-next ui-corner-all");
  nextBtn.href = "#";
  nextIcon = tag("span", "ui-icon ui-icon-circle-triangle-e");
  nextBtn.append(nextIcon);

  titleEl = tag("div", "ui-datepicker-title");
  monthSelectEl = tag("select", "ui-datepicker-month");
  yearInputEl = tag("input", "ui-datepicker-year");
  yearInputEl.type = "number";
  yearInputEl.style.width = "4em";
  yearInputEl.style.marginLeft = "2px";
  titleEl.append(monthSelectEl, document.createTextNode(" "), yearInputEl);

  headerEl.append(prevBtn, nextBtn, titleEl);

  const calendarTable = tag("table", "ui-datepicker-calendar");
  const thead = document.createElement("thead");
  const headRow = document.createElement("tr");
  headerThs = [];
  headerSpans = [];
  for (let dow = 0; dow < 7; dow++) {
    const th = document.createElement("th");
    const span = document.createElement("span");
    th.append(span);
    headRow.append(th);
    headerThs.push(th);
    headerSpans.push(span);
  }
  thead.append(headRow);
  calendarBodyEl = document.createElement("tbody");
  calendarTable.append(thead, calendarBodyEl);

  timeDivEl = tag("div", "ui-timepicker-div");
  const dl = document.createElement("dl");
  timeLabelDtEl = tag("dt", "ui_tpicker_time_label");
  timeLabelEl = tag("dd", "ui_tpicker_time");
  hourDtEl = tag("dt", "ui_tpicker_hour_label");
  const hourDd = tag("dd", "ui_tpicker_hour");
  hourSliderEl = tag("div", "ui_tpicker_hour_slider");
  hourDd.append(hourSliderEl);
  minuteDtEl = tag("dt", "ui_tpicker_minute_label");
  const minuteDd = tag("dd", "ui_tpicker_minute");
  minuteSliderEl = tag("div", "ui_tpicker_minute_slider");
  minuteDd.append(minuteSliderEl);
  dl.append(timeLabelDtEl, timeLabelEl, hourDtEl, hourDd, minuteDtEl, minuteDd);
  timeDivEl.append(dl);

  buttonPaneEl = tag("div", "ui-datepicker-buttonpane ui-widget-content");
  nowBtn = tag(
    "button",
    "ui-datepicker-current ui-state-default ui-priority-secondary ui-corner-all"
  );
  nowBtn.type = "button";
  doneBtn = tag(
    "button",
    "ui-datepicker-close ui-state-default ui-priority-primary ui-corner-all"
  );
  doneBtn.type = "button";
  buttonPaneEl.append(nowBtn, doneBtn);

  popupEl.append(headerEl, calendarTable, timeDivEl, buttonPaneEl);
  document.body.append(popupEl);

  prevBtn.addEventListener("click", (e) => {
    e.preventDefault();
    adjustMonth(-1);
  });
  nextBtn.addEventListener("click", (e) => {
    e.preventDefault();
    adjustMonth(1);
  });
  monthSelectEl.addEventListener("change", () => {
    if (active === undefined) {
      return;
    }
    active.drawMonth = Number(monthSelectEl.value);
    renderCalendar();
  });
  yearInputEl.addEventListener("change", () => {
    if (active === undefined) {
      return;
    }
    const year = parseInt(yearInputEl.value, 10);
    if (!Number.isNaN(year)) {
      active.drawYear = year;
      renderCalendar();
    }
  });
  nowBtn.addEventListener("click", () => {
    if (active === undefined) {
      return;
    }
    const now = new Date();
    active.drawMonth = now.getMonth();
    active.drawYear = now.getFullYear();
    active.hour = now.getHours();
    active.minute = now.getMinutes();
    selectDate(stripTime(now), false);
  });
  doneBtn.addEventListener("click", () => {
    hidePopup();
  });

  document.addEventListener("mousedown", (e) => {
    if (active === undefined) {
      return;
    }
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real mousedown event's own target inside the document is always a Node (or null), never a bare EventTarget with no Node interface.
    const target = e.target as Node;
    if (
      !popupEl.contains(target) &&
      target !== active.input &&
      !active.input.contains(target)
    ) {
      hidePopup();
    }
  });
  document.addEventListener("keydown", (e) => {
    if (active !== undefined && e.key === "Escape") {
      e.preventDefault();
      hidePopup();
    }
  });
}

/**
 * Applies `inst.dpLocale`/`inst.tpLocale` to the shared popup's own
 * locale-dependent text/layout, matching jQuery UI's own real
 * `_generateHTML()` semantics: header day-of-week columns are `(dow +
 * firstDay) % 7`, "week-end" columns are `(dow + firstDay + 6) % 7 >=
 * 5` (real source, `datepicker.js:1705`), `isRTL` swaps the prev/next
 * icon direction and the button-pane order (`datepicker.js:1636-1659`),
 * and `showMonthAfterYear` swaps the month-select/year-input order.
 */
function applyLocale(inst: Instance): void {
  const dp = inst.dpLocale;
  const tp = inst.tpLocale;

  popupEl.classList.toggle("ui-datepicker-rtl", dp.isRTL);
  popupEl.dir = dp.isRTL ? "rtl" : "ltr";

  prevIcon.className = "ui-icon ui-icon-circle-triangle-" + (dp.isRTL ? "e" : "w");
  prevIcon.textContent = dp.prevText;
  prevBtn.title = dp.prevText;
  nextIcon.className = "ui-icon ui-icon-circle-triangle-" + (dp.isRTL ? "w" : "e");
  nextIcon.textContent = dp.nextText;
  nextBtn.title = dp.nextText;

  const monthValue = monthSelectEl.value;
  monthSelectEl.replaceChildren();
  for (let m = 0; m < 12; m++) {
    const opt = document.createElement("option");
    opt.value = String(m);
    opt.textContent = dp.monthNamesShort[m]!;
    monthSelectEl.append(opt);
  }
  monthSelectEl.value = monthValue;

  titleEl.replaceChildren();
  if (dp.showMonthAfterYear) {
    titleEl.append(yearInputEl, document.createTextNode(" "), monthSelectEl);
  } else {
    titleEl.append(monthSelectEl, document.createTextNode(" "), yearInputEl);
  }

  for (let dow = 0; dow < 7; dow++) {
    const actualDow = (dow + dp.firstDay) % 7;
    headerThs[dow]!.className =
      (dow + dp.firstDay + 6) % 7 >= 5 ? "ui-datepicker-week-end" : "";
    headerSpans[dow]!.title = dp.dayNames[actualDow]!;
    headerSpans[dow]!.textContent = dp.dayNamesMin[actualDow]!;
  }

  timeLabelDtEl.textContent = tp.timeText;
  hourDtEl.textContent = tp.hourText;
  minuteDtEl.textContent = tp.minuteText;
  nowBtn.textContent = tp.currentText;
  doneBtn.textContent = tp.closeText;

  buttonPaneEl.replaceChildren();
  if (dp.isRTL) {
    buttonPaneEl.append(doneBtn, nowBtn);
  } else {
    buttonPaneEl.append(nowBtn, doneBtn);
  }
}

// ── Calendar rendering ───────────────────────────────────────────────────

function canAdjustMonth(inst: Instance, offset: number): boolean {
  const date = new Date(inst.drawYear, inst.drawMonth + offset, 1);
  if (offset < 0) {
    date.setDate(daysInMonth(date.getFullYear(), date.getMonth()));
  }
  if (inst.minDate !== null && date.getTime() < stripTime(inst.minDate).getTime()) {
    return false;
  }
  if (inst.maxDate !== null && date.getTime() > stripTime(inst.maxDate).getTime()) {
    return false;
  }
  return true;
}

function adjustMonth(offset: number): void {
  if (active === undefined) {
    return;
  }
  if (!canAdjustMonth(active, offset)) {
    return;
  }
  active.drawMonth += offset;
  if (active.drawMonth < 0) {
    active.drawMonth = 11;
    active.drawYear -= 1;
  } else if (active.drawMonth > 11) {
    active.drawMonth = 0;
    active.drawYear += 1;
  }
  renderCalendar();
}

function renderCalendar(): void {
  if (active === undefined) {
    return;
  }
  const inst = active;

  monthSelectEl.value = String(inst.drawMonth);
  yearInputEl.value = String(inst.drawYear);

  prevBtn.classList.toggle("ui-state-disabled", !canAdjustMonth(inst, -1));
  nextBtn.classList.toggle("ui-state-disabled", !canAdjustMonth(inst, 1));

  const {firstDay} = inst.dpLocale;
  const today = stripTime(new Date());
  const firstOfMonth = new Date(inst.drawYear, inst.drawMonth, 1);
  const leadDays = (firstOfMonth.getDay() - firstDay + 7) % 7;
  const numDays = daysInMonth(inst.drawYear, inst.drawMonth);
  const numRows = Math.ceil((leadDays + numDays) / 7);

  calendarBodyEl.replaceChildren();
  const printDate = new Date(inst.drawYear, inst.drawMonth, 1 - leadDays);
  for (let row = 0; row < numRows; row++) {
    const tr = document.createElement("tr");
    for (let dow = 0; dow < 7; dow++) {
      const cellDate = new Date(printDate);
      const otherMonth = cellDate.getMonth() !== inst.drawMonth;
      const unselectable =
        (inst.minDate !== null && cellDate.getTime() < stripTime(inst.minDate).getTime()) ||
        (inst.maxDate !== null && cellDate.getTime() > stripTime(inst.maxDate).getTime());

      const td = document.createElement("td");
      const classes = [];
      if ((dow + firstDay + 6) % 7 >= 5) {
        classes.push("ui-datepicker-week-end");
      }
      if (otherMonth) {
        classes.push("ui-datepicker-other-month");
      }
      if (unselectable) {
        classes.push("ui-datepicker-unselectable", "ui-state-disabled");
      }
      if (cellDate.getTime() === today.getTime()) {
        classes.push("ui-datepicker-today");
      }
      td.className = classes.join(" ");

      if (otherMonth) {
        td.innerHTML = "&#xa0;";
      } else if (unselectable) {
        const span = document.createElement("span");
        span.className = "ui-state-default";
        span.textContent = String(cellDate.getDate());
        td.append(span);
      } else {
        const a = document.createElement("a");
        a.href = "#";
        const aClasses = ["ui-state-default"];
        if (cellDate.getTime() === today.getTime()) {
          aClasses.push("ui-state-highlight");
        }
        if (sameDay(inst.selected, cellDate)) {
          aClasses.push("ui-state-active");
        }
        a.className = aClasses.join(" ");
        a.textContent = String(cellDate.getDate());
        const dateForClick = new Date(cellDate);
        a.addEventListener("click", (e) => {
          e.preventDefault();
          selectDate(dateForClick, inst.showTimepicker);
        });
        td.append(a);
      }
      tr.append(td);
      printDate.setDate(printDate.getDate() + 1);
    }
    calendarBodyEl.append(tr);
  }
}

function renderTimeLabel(): void {
  if (active === undefined) {
    return;
  }
  timeLabelEl.textContent = formatShortTime(active.hour, active.minute);
}

function selectDate(date: Date, keepOpen: boolean): void {
  if (active === undefined) {
    return;
  }
  active.selected = date;
  active.drawMonth = date.getMonth();
  active.drawYear = date.getFullYear();
  writeValue(active);
  if (keepOpen) {
    renderCalendar();
  } else {
    hidePopup();
  }
}

function writeValue(inst: Instance): void {
  if (inst.selected === null) {
    inst.input.value = "";
    inst.altField.value = "";
  } else {
    inst.input.value = inst.showTimepicker
      ? formatLongDate(inst.selected, inst.dpLocale) + " " + formatShortTime(inst.hour, inst.minute)
      : formatLongDate(inst.selected, inst.dpLocale);
    inst.altField.value = inst.showTimepicker
      ? formatIsoDate(inst.selected) + " " + formatIsoTime(inst.hour, inst.minute)
      : formatIsoDate(inst.selected);
  }
  // `jquery-timepicker-addon`'s own real `_updateDateTime()` (its
  // monkeypatched `_updateDatepicker`) unconditionally `.trigger("change")`s
  // the *visible* field, not the alt field, every time it writes a
  // value -- `batchManagerUnit.ts`'s own per-photo "unsaved changes"
  // listener depends on seeing this real event.
  inst.input.dispatchEvent(new Event("change", { bubbles: true }));
}

// ── Show / hide ──────────────────────────────────────────────────────────

function showPopup(inst: Instance): void {
  buildPopup();

  if (active !== undefined && active !== inst) {
    hidePopup();
  }

  active = inst;
  inst.originalDate = inst.selected;
  inst.originalHour = inst.hour;
  inst.originalMinute = inst.minute;

  applyLocale(inst);

  const base = inst.selected ?? stripTime(new Date());
  inst.drawMonth = base.getMonth();
  inst.drawYear = base.getFullYear();

  timeDivEl.style.display = inst.showTimepicker ? "" : "none";
  if (inst.showTimepicker) {
    slider(hourSliderEl, {
      min: 0,
      max: 23,
      step: 1,
      value: inst.hour,
      slide: (_e, ui) => {
        active!.hour = ui.value ?? active!.hour;
        renderTimeLabel();
      },
      stop: () => {
        if (active!.selected !== null) {
          writeValue(active!);
        }
      },
    });
    slider(minuteSliderEl, {
      min: 0,
      max: 59,
      step: 1,
      value: inst.minute,
      slide: (_e, ui) => {
        active!.minute = ui.value ?? active!.minute;
        renderTimeLabel();
      },
      stop: () => {
        if (active!.selected !== null) {
          writeValue(active!);
        }
      },
    });
    renderTimeLabel();
  }

  if (cancelBtnEl !== undefined) {
    cancelBtnEl.remove();
    cancelBtnEl = undefined;
  }
  if (inst.cancelButtonLabel !== false) {
    cancelBtnEl = document.createElement("button");
    cancelBtnEl.type = "button";
    cancelBtnEl.className = "pwg-datepicker-cancel ui-state-error ui-corner-all";
    cancelBtnEl.textContent = inst.cancelButtonLabel;
    cancelBtnEl.addEventListener("click", () => {
      const cur = active;
      if (cur === undefined) {
        return;
      }
      cur.selected = cur.originalDate;
      cur.hour = cur.originalHour;
      cur.minute = cur.originalMinute;
      writeValue(cur);
      hidePopup();
    });
    buttonPaneEl.append(cancelBtnEl);
  }

  renderCalendar();

  popupEl.style.display = "block";
  const rect = inst.input.getBoundingClientRect();
  popupEl.style.left = String(window.scrollX + rect.left) + "px";
  popupEl.style.top = String(window.scrollY + rect.bottom) + "px";

  const popupRect = popupEl.getBoundingClientRect();
  const viewWidth = document.documentElement.clientWidth;
  if (popupRect.right > viewWidth) {
    popupEl.style.left =
      String(Math.max(0, window.scrollX + viewWidth - popupRect.width)) + "px";
  }
}

function hidePopup(): void {
  if (active === undefined) {
    return;
  }
  const inst = active;
  popupEl.style.display = "none";
  active = undefined;
  inst.onCloseNotify?.(inst.selected);
}

// ── Registration / linking ──────────────────────────────────────────────

function registerInstance(input: HTMLInputElement, options: PwgDatepickerOptions): Instance {
  const key = input.dataset["datepicker"] ?? "";
  const altField = document.querySelector<HTMLInputElement>(
    '[name="' + key + '"]'
  );

  const inst: Instance = {
    input,
    // Every real call site's `data-datepicker` value matches a real
    // hidden `<input>` -- see this module's own leading comment.
    altField: altField!,
    showTimepicker: options.showTimepicker ?? false,
    cancelButtonLabel: options.cancelButton ?? false,
    dpLocale: resolveDatepickerLocale(options.jqueryCode),
    tpLocale: resolveTimepickerLocale(options.jqueryCode),
    unsetEl: null,
    minDate: null,
    maxDate: null,
    onCloseNotify: null,
    selected: null,
    hour: 0,
    minute: 0,
    drawMonth: 0,
    drawYear: 0,
    originalDate: null,
    originalHour: 0,
    originalMinute: 0,
  };

  const parsed = parseIsoDateTime(altField?.value ?? "");
  if (parsed !== null) {
    inst.selected = parsed.date;
    inst.hour = parsed.hour;
    inst.minute = parsed.minute;
  }

  // `$.datepicker`'s own real `markerClassName` default ("hasDatepicker"),
  // stamped onto every attached input (`_attachDatepicker()`) -- real,
  // load-bearing CSS here (`history.css`'s own `.hasDatepicker` rule:
  // border/padding/max-width), not decorative; dropping it left the
  // field unstyled, a real VR regression this reproduced.
  input.classList.add("hasDatepicker");

  // `autoSize: true`'s own real effect (see autoSizeLength()'s own
  // comment) -- the original's own former `if (options.showTimepicker)
  // { $this.attr("size", parseInt($this.attr("size")!) + 6); }` pads 6
  // more characters on top of the date-only autoSize width, since
  // `_autoSize()` itself never accounts for the appended time text.
  input.size =
    autoSizeLength(inst.dpLocale) + (inst.showTimepicker ? 6 : 0);

  // The original's own `set(date, true)` runs unconditionally at init
  // for every linked picker (`jquery.fn.pwgDatepicker`'s own former
  // `if (linked) { ... set(..., true); }`), real date or not --
  // `.datetimepicker("setDate", ...)` always calls through to
  // `_updateDateTime()`, which always `.trigger("change")`s the visible
  // field. `history.ts`'s own `.date-start`/`.date-end` change
  // listeners fire the page's very first, unfiltered search from
  // exactly this initial dispatch -- writeValue() here (not a bare
  // `input.value = ...` write) is what reproduces it.
  writeValue(inst);

  input.addEventListener("focus", () => {
    showPopup(inst);
  });

  const unsetKey = input.dataset["datepickerUnset"];
  if (unsetKey !== undefined) {
    const unsetEl = document.getElementById(unsetKey);
    inst.unsetEl = unsetEl;
    unsetEl?.addEventListener("click", (e) => {
      e.preventDefault();
      inst.selected = null;
      writeValue(inst);
      if (active === inst) {
        renderCalendar();
      }
    });
  }

  if (key !== "") {
    instancesByKey.set(key, inst);
  }
  instanceByInput.set(input, inst);

  return inst;
}

function linkRange(input: HTMLInputElement, inst: Instance): void {
  const startKey = input.dataset["datepickerStart"];
  const endKey = input.dataset["datepickerEnd"];

  if (startKey !== undefined) {
    const startInst = instancesByKey.get(startKey);
    if (startInst !== undefined) {
      inst.minDate = startInst.selected;
      inst.onCloseNotify = (date) => {
        startInst.maxDate = date;
      };
    }
  } else if (endKey !== undefined) {
    const endInst = instancesByKey.get(endKey);
    if (endInst !== undefined) {
      inst.onCloseNotify = (date) => {
        endInst.minDate = date;
      };
    }
  }
}

export function pwgDatepicker(
  elements: Element | ArrayLike<Element>,
  options: PwgDatepickerOptions = {}
): void {
  const inputs = (
    elements instanceof Element ? [elements] : Array.from(elements)
  ).filter((el): el is HTMLInputElement => el instanceof HTMLInputElement);

  const created = inputs.map((input) => registerInstance(input, options));

  inputs.forEach((input, i) => {
    linkRange(input, created[i]!);
  });
}
