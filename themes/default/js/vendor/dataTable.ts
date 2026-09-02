// Native port of the legacy DataTables 1.10.11 jQuery plugin (P49-C), real
// source read from the vendored `datatables.net@1.10.11` bundle's own
// `js/jquery.dataTables.js` (15267 lines). `rating_user.ts` is the ONLY
// real consumer anywhere in this app (confirmed via a repo-wide grep for
// `dataTable`/`DataTable`), so this isn't a generic reimplementation of
// the plugin's own vast real option surface -- just the one real
// `dom: '<"dtBar"filp>rt<"dtBar"ilp>'` layout (a top bar with a search
// filter, the entries-count info text, the page-length menu and
// pagination; a bottom bar repeating everything but the filter), the one
// real `columnDefs` shape (keyed by a `<th>`'s own class name, via the
// legacy `aTargets` string-matching form -- `sClass`/`bSortable: false`
// aside, this app never uses the numeric-index or `"_all"` forms
// `aTargets` also accepts), and the one real row-removal API surface
// (`row(tr).remove().draw()`) that file's own source actually calls.
// `autoWidth` (irrelevant to a plain, unstyled `<table>` with no explicit
// column widths) and `sortClasses: false` (the zebra `sorting_1`/`_2`/`_3`
// per-cell highlight the active sort column would otherwise get) are real
// options on the original call, but the second is already off there and
// the first has no visible effect either way, so neither is ported.
//
// Multi-column (shift-click) sort isn't ported: no real call site (or its
// own tests) ever exercises it, and `sortClasses: false` already means
// the one piece of UI that would visibly distinguish it (which column
// sorted first) is disabled anyway. Clicking a sortable header instead
// cycles that single column through its own two-entry `sortDirections`
// list, then back to the table's original (server-rendered) row order --
// the real 3-state default this library's own docs describe, not a
// 2-state asc/desc-only toggle.
//
// `sType`'s real "string"/"numeric"/"html" 3-way split collapses to 2
// here (`"string" | "numeric"`): a cell's `.textContent` already strips
// every tag on its own, so a `sType: "html"` column (every real
// `dtc_rate` cell here is just a plain rating count inside an `<a
// title=...>`) needs nothing beyond the same numeric comparison a
// `sType: "numeric"` column gets once its text is extracted.

interface DataTableColumnDef {
  targetClass: string;
  sortable?: boolean;
  searchable?: boolean;
  sortDirections?: ("asc" | "desc")[];
  type?: "string" | "numeric";
}

export interface DataTableOptions {
  pageLength: number;
  lengthMenu: number[];
  columnDefs: DataTableColumnDef[];
}

interface DataTableRow {
  remove(): { draw(): void };
}

export interface DataTableApi {
  row(tr: HTMLTableRowElement): DataTableRow;
}

interface ResolvedColumn {
  th: HTMLTableCellElement;
  sortable: boolean;
  searchable: boolean;
  sortDirections: ("asc" | "desc")[];
  type: "string" | "numeric";
}

interface SortState {
  colIndex: number;
  direction: "asc" | "desc";
}

interface Bar {
  info: HTMLElement;
  lengthSelect: HTMLSelectElement;
  pagination: HTMLElement;
}

function cellText(tr: HTMLTableRowElement, colIndex: number): string {
  return (tr.cells[colIndex]?.textContent ?? "").trim();
}

function compare(type: "string" | "numeric", a: string, b: string): number {
  if (type === "string") {
    return a.localeCompare(b);
  }
  const numA = parseFloat(a);
  const numB = parseFloat(b);
  const validA = !Number.isNaN(numA);
  const validB = !Number.isNaN(numB);
  if (validA && validB) {
    return numA - numB;
  }
  // A blank/non-numeric cell (`cdTop`'s own empty-when-null real case)
  // sorts after every real numeric value, in both directions.
  if (validA !== validB) {
    return validA ? -1 : 1;
  }
  return a.localeCompare(b);
}

/**
 * A simple current-page-centered window with first/last pinned and `null`
 * standing in for an ellipsis gap -- not the original's own considerably
 * more configurable `pagingType`, which this app never varies from its
 * own real default.
 */
function paginationWindow(current: number, total: number): (number | null)[] {
  const pages = new Set<number>(
    [1, total, current - 2, current - 1, current, current + 1, current + 2].filter(
      (p) => p >= 1 && p <= total,
    ),
  );
  const sorted = Array.from(pages).sort((a, b) => a - b);
  const result: (number | null)[] = [];
  let previous = 0;
  for (const page of sorted) {
    if (previous !== 0 && page - previous > 1) {
      result.push(null);
    }
    result.push(page);
    previous = page;
  }
  return result;
}

export function dataTable(
  table: HTMLTableElement,
  options: DataTableOptions,
): DataTableApi {
  const thead = table.tHead;
  const tbodyEl = table.tBodies[0];
  const headerRow = thead?.rows[0];
  if (!thead || !tbodyEl || !headerRow) {
    throw new Error("dataTable() requires a <thead> row and a <tbody>");
  }
  const tbody = tbodyEl;

  const columns: ResolvedColumn[] = Array.from(headerRow.cells).map((th) => {
    const def = options.columnDefs.find((d) => th.classList.contains(d.targetClass));
    return {
      th,
      sortable: def?.sortable ?? true,
      searchable: def?.searchable ?? true,
      sortDirections: def?.sortDirections ?? ["asc", "desc"],
      type: def?.type ?? "string",
    };
  });

  const masterRows: HTMLTableRowElement[] = Array.from(tbody.rows);
  let sortState: SortState | null = null;
  let searchTerm = "";
  let pageSize = options.pageLength;
  let currentPage = 1;

  const wrapper = document.createElement("div");
  wrapper.className = "dataTables_wrapper";
  table.before(wrapper);
  const topBar = buildBar(true);
  const bottomBar = buildBar(false);
  wrapper.append(topBar.el, table, bottomBar.el);

  function buildBar(withFilter: boolean): { el: HTMLElement; bar: Bar } {
    const el = document.createElement("div");
    el.className = "dtBar";

    if (withFilter) {
      const filterLabel = document.createElement("label");
      filterLabel.append("Search: ");
      const filterInput = document.createElement("input");
      filterInput.type = "search";
      filterInput.addEventListener("input", () => {
        searchTerm = filterInput.value;
        currentPage = 1;
        draw();
      });
      filterLabel.append(filterInput);
      el.append(filterLabel);
    }

    const info = document.createElement("span");
    info.className = "dataTables_info";
    el.append(info);

    const lengthLabel = document.createElement("label");
    lengthLabel.append("Show ");
    const lengthSelect = document.createElement("select");
    for (const n of options.lengthMenu) {
      const opt = document.createElement("option");
      opt.value = String(n);
      opt.textContent = n === -1 ? "All" : String(n);
      lengthSelect.append(opt);
    }
    lengthSelect.value = String(pageSize);
    lengthSelect.addEventListener("change", () => {
      pageSize = Number(lengthSelect.value);
      currentPage = 1;
      draw();
    });
    lengthLabel.append(lengthSelect, " entries");
    el.append(lengthLabel);

    const pagination = document.createElement("div");
    pagination.className = "dataTables_paginate";
    el.append(pagination);

    return { el, bar: { info, lengthSelect, pagination } };
  }

  function renderPagination(container: HTMLElement, totalPages: number): void {
    container.replaceChildren();

    const addButton = (label: string, page: number, disabled: boolean, current: boolean): void => {
      const a = document.createElement("a");
      a.className =
        "paginate_button" + (current ? " current" : "") + (disabled ? " disabled" : "");
      a.textContent = label;
      if (!disabled && !current) {
        a.addEventListener("click", (event) => {
          event.preventDefault();
          currentPage = page;
          draw();
        });
      }
      container.append(a);
    };

    addButton("Previous", currentPage - 1, currentPage === 1, false);
    for (const page of paginationWindow(currentPage, totalPages)) {
      if (page === null) {
        const ellipsis = document.createElement("span");
        ellipsis.className = "ellipsis";
        ellipsis.textContent = "…";
        container.append(ellipsis);
      } else {
        addButton(String(page), page, false, page === currentPage);
      }
    }
    addButton("Next", currentPage + 1, currentPage === totalPages, false);
  }

  function updateHeaderClasses(): void {
    columns.forEach((col, index) => {
      col.th.classList.remove("sorting", "sorting_asc", "sorting_desc");
      if (!col.sortable) {
        return;
      }
      if (sortState !== null && sortState.colIndex === index) {
        col.th.classList.add(sortState.direction === "asc" ? "sorting_asc" : "sorting_desc");
      } else {
        col.th.classList.add("sorting");
      }
    });
  }

  function draw(): void {
    const term = searchTerm.trim().toLowerCase();
    const visible =
      term === ""
        ? masterRows.slice()
        : masterRows.filter((tr) =>
            columns.some(
              (col, index) => col.searchable && cellText(tr, index).toLowerCase().includes(term),
            ),
          );

    if (sortState !== null) {
      const { colIndex, direction } = sortState;
      const col = columns[colIndex]!;
      const sign = direction === "asc" ? 1 : -1;
      visible.sort((a, b) => sign * compare(col.type, cellText(a, colIndex), cellText(b, colIndex)));
    }

    const total = visible.length;
    const effectivePageSize = pageSize === -1 ? Math.max(total, 1) : pageSize;
    const totalPages = Math.max(1, Math.ceil(total / effectivePageSize));
    currentPage = Math.min(Math.max(currentPage, 1), totalPages);

    const start = (currentPage - 1) * effectivePageSize;
    const pageRows = pageSize === -1 ? visible : visible.slice(start, start + effectivePageSize);

    tbody.replaceChildren(...pageRows);
    updateHeaderClasses();

    const from = total === 0 ? 0 : start + 1;
    const to = total === 0 ? 0 : start + pageRows.length;
    const filteredSuffix =
      term === "" || total === masterRows.length
        ? ""
        : ` (filtered from ${String(masterRows.length)} total entries)`;
    const infoText = `Showing ${String(from)} to ${String(to)} of ${String(total)} entries${filteredSuffix}`;

    for (const bar of [topBar.bar, bottomBar.bar]) {
      bar.info.textContent = infoText;
      if (bar.lengthSelect.value !== String(pageSize)) {
        bar.lengthSelect.value = String(pageSize);
      }
      renderPagination(bar.pagination, totalPages);
    }
  }

  columns.forEach((col, index) => {
    if (!col.sortable) {
      return;
    }
    col.th.addEventListener("click", () => {
      if (sortState !== null && sortState.colIndex === index) {
        const stateIndex = col.sortDirections.indexOf(sortState.direction);
        const next = stateIndex === 0 ? col.sortDirections[1] : undefined;
        sortState = next === undefined ? null : { colIndex: index, direction: next };
      } else {
        sortState = { colIndex: index, direction: col.sortDirections[0]! };
      }
      currentPage = 1;
      draw();
    });
  });

  draw();

  return {
    row(tr: HTMLTableRowElement): DataTableRow {
      return {
        remove(): { draw(): void } {
          const index = masterRows.indexOf(tr);
          if (index !== -1) {
            masterRows.splice(index, 1);
          }
          return { draw };
        },
      };
    },
  };
}
