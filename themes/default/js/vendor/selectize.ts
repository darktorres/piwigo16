import { on } from "./dom";

/**
 * Port of selectize.js v0.11.2's own `$.fn.selectize()` (real source read
 * from the CDN, `selectize.js@v0.11.2/src/selectize.js` +
 * `selectize.jquery.js`) -- narrowed to the real subset every call site
 * across this app actually uses, confirmed via an exhaustive grep of
 * every `.selectize(`/`.selectize.<method>`/`<instance-var>.<method>`
 * call, not assumed:
 *
 * - Only the `remove_button` plugin is ever passed -- `dropdown_header`/
 *   `optgroup_columns`/`restore_on_backspace`/`drag_drop` are never
 *   referenced, so no plugin *system* is ported, `remove_button` is
 *   simply always-available, gated on `options.plugins?.includes(...)`.
 * - No optgroups, no remote/debounced `load(query, callback)` search, no
 *   `dropdownParent`, no custom `score`, no `persist`/`maxItems`/
 *   `delimiter`/`closeAfterSelect` override -- none used anywhere.
 * - Search-term highlighting (wrapping matches in `<span class="highlight">`)
 *   IS real, always-on default behavior every call site gets (`settings.
 *   highlight` defaults `true`, never overridden) -- ported, not dropped.
 * - Left/right arrow-key caret navigation between existing chips is
 *   real selectize behavior but no real call site here ever depends on
 *   caret position programmatically -- simplified to backspace-removes-
 *   last-item (the load-bearing tag-input UX), not fully replicated.
 *
 * `AbstractSelectizer.getRender()` (`themes/admin/default/js/
 * LocalStorageCache.ts`) deliberately calls its own render functions
 * with an `escape` argument it ignores -- faithfully preserved (its own
 * 4 real consumers render entity names unescaped, same as before this
 * port).
 */

export interface SelectizeRenderers<U> {
  option?(data: U, escape: (input: string) => string): string;
  item?(data: U, escape: (input: string) => string): string;
  option_create?(data: { input: string }, escape: (input: string) => string): string;
}

export interface SelectizeOptions<T extends string | number, U> {
  valueField?: string;
  labelField?: string;
  sortField?: string;
  searchField?: string[];
  plugins?: string[];
  create?: boolean;
  maxOptions?: number;
  items?: T[] | undefined;
  onChange?: (value: T | T[]) => void;
  render?: SelectizeRenderers<U>;
}

export interface SelectizeInstance<
  T extends string | number = string | number,
  U extends Record<string, unknown> = Record<string, unknown>,
> {
  options: Record<string, U>;
  settings: { maxOptions: number; create: boolean };
  getValue(): T | T[];
  setValue(value: T | T[], silent?: boolean): void;
  addItem(value: T, silent?: boolean): void;
  removeItem(value: T, silent?: boolean): void;
  getItem(value: T): HTMLElement | null;
  on(event: "item_remove" | "dropdown_close", handler: (value: T) => void): void;
  addOption(data: U | U[]): void;
  removeOption(value: T): void;
  clearOptions(): void;
  refreshOptions(triggerDropdown?: boolean): void;
  clear(silent?: boolean): void;
  load(fn: (this: SelectizeInstance<T, U>, callback: (data: U[]) => void) => void): void;
}

function escapeHtml(input: string): string {
  return input
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function escapeRegExp(input: string): string {
  return input.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

// Every real field value in this app's own real data (category/tag/group/
// user names, ids) is a string or number -- an object would only reach
// here from a caller passing a genuinely malformed data row, not a real
// call site -- so this narrows to the only two shapes that occur rather
// than trusting `String(x)` on an `unknown` field pulled off a generic
// `U extends Record<string, unknown>`.
function fieldToString(value: unknown): string {
  return typeof value === "string" || typeof value === "number" ? String(value) : "";
}

const CREATE_SENTINEL = "$$selectize_create$$";

// The original library stashes the live instance directly on the DOM
// element (`$input[0].selectize = self`) -- real call sites read it back
// that way later (`albumParent.selectize`, `$select[0]!.selectize`,
// `jQuery("#tag-search")[0]!.selectize`), sometimes from a completely
// different function than the one that called `selectize()` in the
// first place. Same WeakMap-of-state pattern `vendor/slider.ts` already
// established for the identical problem, rather than a real DOM
// property (which would need `unknown`-then-cast at every read site).
// The WeakMap itself must hold one concrete instance type, so every real
// instance is stored erased to `SelectizeInstance<never, never>` and cast
// back to the caller's own T/U at each of the two boundary points below
// (get and set) -- the real per-call-site generic is never actually
// checked here, same as `vendor/slider.ts`'s identical pattern.
const instances = new WeakMap<HTMLSelectElement, SelectizeInstance<never, never>>();

export function getSelectizeInstance<
  T extends string | number = string | number,
  U extends Record<string, unknown> = Record<string, unknown>,
>(el: HTMLSelectElement): SelectizeInstance<T, U> | undefined {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- WeakMap-erasure boundary, see comment above.
  return instances.get(el) as SelectizeInstance<T, U> | undefined;
}

const defaultRenderers = <U extends Record<string, unknown>>(
  labelField: string,
): Required<SelectizeRenderers<U>> => ({
  option: (data, escape) =>
    `<div class="option">${escape(fieldToString(data[labelField]))}</div>`,
  item: (data, escape) => `<div class="item">${escape(fieldToString(data[labelField]))}</div>`,
  option_create: (data, escape) =>
    `<div class="create">Add <strong>${escape(data.input)}</strong>&hellip;</div>`,
});

// Every value this function itself reads back off the DOM (a
// `data-value` attribute, the highlighted option's key, the create-input
// text) round-trips through the DOM as a plain string, then gets cast to
// this instance's own T at each such boundary below -- always safe
// because every real call site in this app declares T as `string` or
// `string | number`, never a bare `number`, so a string value is always
// assignable to the real T no caller ever narrows further.
export function selectize<
  T extends string | number = string | number,
  U extends Record<string, unknown> = Record<string, unknown>,
>(el: HTMLSelectElement, init: SelectizeOptions<T, U> = {}): SelectizeInstance<T, U> {
  const valueField = init.valueField ?? "value";
  const labelField = init.labelField ?? "text";
  const searchField = init.searchField ?? [labelField];
  const {sortField} = init;
  const multi = el.multiple;
  const maxItems = multi ? Infinity : 1;
  // Real `remove_button` plugin's own definition: `if (this.settings.mode
  // === 'single') return;` -- a pure no-op for single-select, regardless
  // of whether the caller lists it in `plugins` (every real Cache-backed
  // call site does, unconditionally).
  const hasRemoveButton = multi && (init.plugins?.includes("remove_button") ?? false);
  const renderers = { ...defaultRenderers<U>(labelField), ...init.render };

  // Declared here (assigned much further down, once its own real
  // methods are built) so the click/event handlers registered before
  // that point -- which only ever run later, on real user interaction
  // -- can reference the same instance without a forward-reference.
  // eslint-disable-next-line prefer-const -- deliberately `let`: a real object literal this large has no safe placeholder value to initialize with here, and the whole point of this declaration is the single, deferred assignment below.
  let instance: SelectizeInstance<T, U>;

  const options: Record<string, U> = {};
  const order: string[] = [];
  const items: T[] = [];
  const listeners: { item_remove: ((value: T) => void)[]; dropdown_close: (() => void)[] } = {
    item_remove: [],
    dropdown_close: [],
  };
  const settings = {
    maxOptions: init.maxOptions ?? 1000,
    create: init.create ?? false,
  };

  // Real `<option>` children seed the pool exactly like the original's
  // own `init_select()` -- this app's own direct (non-cache) call sites
  // (album_notification.ts, group_list.ts's `.AddUserBlock`, plugins_new.ts's
  // author/tag-filter) all rely on this.
  // Real `init_select()`'s own `if ($option.is(':selected')) settings_element.
  // items.push(value);` -- a server-rendered `<option selected>` (real usage:
  // `album_notification.ts`'s own `.who_option select`) must still come up
  // pre-selected, not just contribute to the searchable pool.
  const domSelectedValues: string[] = [];
  Array.from(el.options).forEach((opt, i) => {
    if (opt.value === "") return;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- computed-key record built from valueField/labelField, whose real shape can't be statically checked against the caller's own U.
    const data = {
      [valueField]: opt.value,
      [labelField]: opt.textContent,
      $order: i,
    } as unknown as U;
    options[opt.value] = data;
    order.push(opt.value);
    if (opt.selected) domSelectedValues.push(opt.value);
  });

  // Real `setup()` copies the original element's own class list onto
  // both the wrapper and (per `copyClassesToDropdown`, always true --
  // never overridden by any real call site) the dropdown too, and its
  // inline `style.width` onto the wrapper -- `rating.latte`'s own
  // `.rating-album-filter` (400px, an external stylesheet rule keyed on
  // that copied class) depends on this, not just cosmetic parity.
  const originalClasses = el.className;

  el.style.display = "none";

  const control = document.createElement("div");
  control.className =
    `selectize-control ${multi ? "multi" : "single"} ${originalClasses}` +
    (hasRemoveButton ? " plugin-remove_button" : "");
  if (el.style.width) {
    control.style.width = el.style.width;
  }

  const input = document.createElement("div");
  // Real `setup()`'s own `.addClass(settings.inputClass).addClass('items')`
  // -- several page-specific stylesheets (e.g. `user_list.css`'s own
  // `.selectize-input.items` rules) key off this literal `items` class,
  // not just `selectize-input` alone.
  input.className = "selectize-input items";

  const textInput = document.createElement("input");
  textInput.type = "text";
  textInput.autocomplete = "off";
  const originalPlaceholder = el.getAttribute("placeholder") ?? "";
  if (originalPlaceholder) textInput.placeholder = originalPlaceholder;
  input.appendChild(textInput);

  const dropdown = document.createElement("div");
  dropdown.className = `selectize-dropdown ${multi ? "multi" : "single"} ${originalClasses}`;
  dropdown.style.display = "none";
  const dropdownContent = document.createElement("div");
  dropdownContent.className = "selectize-dropdown-content";
  dropdown.appendChild(dropdownContent);

  control.appendChild(input);
  control.appendChild(dropdown);
  el.insertAdjacentElement("afterend", control);

  let isOpen = false;
  let isFocused = false;
  let highlighted: string | null = null;

  function renderItems(): void {
    input.querySelectorAll<HTMLElement>("[data-value]").forEach((n) => { n.remove(); });
    items.forEach((value) => {
      const data = options[String(value)];
      const html = renderers.item(
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- computed-key record built from valueField/labelField, whose real shape can't be statically checked against the caller's own U.
        data ?? ({ [valueField]: value, [labelField]: String(value) } as unknown as U),
        escapeHtml,
      );
      const wrapper = document.createElement("div");
      wrapper.innerHTML = html.trim();
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- renderers.item() always returns exactly one non-empty root element, this file's own template convention (see SelectizeRenderers's own doc).
      const itemEl = wrapper.firstElementChild as HTMLElement;
      itemEl.setAttribute("data-value", String(value));
      if (hasRemoveButton) {
        const remove = document.createElement("a");
        remove.className = "remove";
        remove.href = "javascript:void(0)";
        remove.innerHTML = "&times;";
        on(remove, "click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          instance.removeItem(value);
        });
        itemEl.appendChild(remove);
      }
      input.insertBefore(itemEl, textInput);
    });
    input.classList.toggle("has-items", items.length > 0);
    input.classList.toggle("has-options", order.length > 0);
    // Real `refreshClasses()`'s own `.full`/`.not-full` toggle
    // (`isFull()`) -- `user_activity.ts`'s own filter-panel logic reads
    // `.selectize-input.full` directly to know a single-select control
    // already has a value.
    input.classList.toggle("full", items.length >= maxItems);
    input.classList.toggle("not-full", items.length < maxItems);
    // Real `updatePlaceholder()`: the placeholder attribute itself is
    // removed while any item is selected, not just visually overlapped
    // -- restored once the control is empty again.
    if (originalPlaceholder) {
      if (items.length > 0) {
        textInput.removeAttribute("placeholder");
      } else {
        textInput.placeholder = originalPlaceholder;
      }
    }
    updatePlaceholderVisibility();
    autoGrowInput();
  }

  // Real `open()` only gates on `isFull()` in *multi* mode (selectize.js's
  // own `self.settings.mode === 'multi' && self.isFull()` check) --
  // single-select always allows reopening the dropdown to replace its
  // one value (`addItem()` clears the old value first in single mode),
  // so the input never hides for single, only for a full multi-select.
  function updatePlaceholderVisibility(): void {
    textInput.style.display = multi && items.length >= maxItems ? "none" : "";
  }

  function matchesQuery(data: U, tokens: string[]): boolean {
    if (tokens.length === 0) return true;
    const haystack = searchField
      .map((f) => fieldToString(data[f]))
      .join("   ")
      .toLowerCase();
    return tokens.every((t) => haystack.includes(t));
  }

  function sortedMatches(tokens: string[]): string[] {
    const matches = order.filter((value) => {
      const data = options[value]!;
      // Was `items.includes(value as unknown as T)` -- `value` (from
      // `order`) is always a string, but `items` can genuinely hold a
      // real T set externally (e.g. `setValue(42)`), so a strict
      // `.includes()` silently failed to exclude an already-selected
      // numeric item from the dropdown. `String()` on both sides is the
      // same key-comparison convention `addItem()`/`removeItem()` already
      // use.
      if (items.some((v) => String(v) === value)) return false;
      return matchesQuery(data, tokens);
    });

    if (sortField !== undefined && sortField !== "") {
      matches.sort((a, b) => {
        const av = options[a]![sortField];
        const bv = options[b]![sortField];
        if (typeof av === "number" && typeof bv === "number") return av - bv;
        return fieldToString(av).localeCompare(fieldToString(bv));
      });
    }

    return matches;
  }

  function highlightMatches(container: HTMLElement, tokens: string[]): void {
    if (tokens.length === 0) return;
    const regex = new RegExp(`(${tokens.map(escapeRegExp).join("|")})`, "gi");
    const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT);
    const textNodes: Text[] = [];
    let node: Node | null;
     
    while ((node = walker.nextNode())) {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- createTreeWalker(container, NodeFilter.SHOW_TEXT) guarantees nextNode() only ever returns a Text node (or null at the end).
      textNodes.push(node as Text);
    }
    textNodes.forEach((textNode) => {
      const text = textNode.textContent;
      if (!regex.test(text)) return;
      regex.lastIndex = 0;
      const span = document.createElement("span");
      span.innerHTML = text.replace(regex, '<span class="highlight">$1</span>');
      textNode.replaceWith(...Array.from(span.childNodes));
    });
  }

  function renderOptions(triggerDropdown = true): void {
    const query = textInput.value.trim();
    const tokens = query.toLowerCase().split(/\s+/).filter(Boolean);
    const matches = sortedMatches(tokens).slice(0, settings.maxOptions);

    dropdownContent.innerHTML = "";
    matches.forEach((value) => {
      const data = options[value]!;
      const html = renderers.option(data, escapeHtml);
      const wrapper = document.createElement("div");
      wrapper.innerHTML = html.trim();
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- renderers.option() always returns exactly one non-empty root element, this file's own template convention (see SelectizeRenderers's own doc).
      const optionEl = wrapper.firstElementChild as HTMLElement;
      optionEl.setAttribute("data-value", value);
      optionEl.setAttribute("data-selectable", "");
      dropdownContent.appendChild(optionEl);
    });

    if (settings.create && query !== "" && !(query in options)) {
      const html = renderers.option_create({ input: query }, escapeHtml);
      const wrapper = document.createElement("div");
      wrapper.innerHTML = html.trim();
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- renderers.option_create() always returns exactly one non-empty root element, this file's own template convention (see SelectizeRenderers's own doc).
      const createEl = wrapper.firstElementChild as HTMLElement;
      createEl.setAttribute("data-selectable", "");
      createEl.setAttribute("data-create", "");
      // A sentinel `data-value`, never a real option value (options are
      // always keyed by their real `valueField`) -- keeps the create row
      // addressable by the same `highlighted`/moveHighlight()/
      // selectHighlighted() machinery every real option row uses,
      // instead of a separate code path.
      createEl.setAttribute("data-value", CREATE_SENTINEL);
      dropdownContent.appendChild(createEl);
    }

    highlightMatches(dropdownContent, tokens);

    if (highlighted === null || !dropdownContent.querySelector(`[data-value="${CSS.escape(highlighted)}"]`)) {
      const first = dropdownContent.querySelector<HTMLElement>("[data-selectable]");
      highlighted = first?.getAttribute("data-value") ?? null;
    }
    updateHighlightClass();

    const blocked = multi && items.length >= maxItems;
    if (triggerDropdown && !blocked && dropdownContent.children.length > 0) {
      openDropdown();
    } else if (dropdownContent.children.length === 0 || blocked) {
      closeDropdown();
    }
  }

  function updateHighlightClass(): void {
    dropdownContent.querySelectorAll("[data-selectable]").forEach((n) => {
      n.classList.toggle("active", n.getAttribute("data-value") === highlighted);
    });
  }

  // Real `utils.js`'s own `autoGrow()` -- the text `<input>` has no CSS
  // rule sizing it to its own content/placeholder (only `display:
  // inline-block`), so without this it clips at its default browser
  // width regardless of how wide `.selectize-input` itself is. Measures
  // via an offscreen clone carrying the same font metrics, exactly like
  // the original's own `measureString()`.
  function autoGrowInput(): void {
    const value = textInput.value || textInput.placeholder || "";
    if (!value) {
      textInput.style.width = "4px";
      return;
    }
    const measurer = document.createElement("span");
    measurer.style.position = "absolute";
    measurer.style.top = "-99999px";
    measurer.style.left = "-99999px";
    measurer.style.whiteSpace = "pre";
    const computed = window.getComputedStyle(textInput);
    measurer.style.fontSize = computed.fontSize;
    measurer.style.fontFamily = computed.fontFamily;
    measurer.style.fontWeight = computed.fontWeight;
    measurer.style.letterSpacing = computed.letterSpacing;
    measurer.textContent = value;
    document.body.appendChild(measurer);
    textInput.style.width = `${measurer.offsetWidth + 4}px`;
    measurer.remove();
  }

  function positionDropdown(): void {
    dropdown.style.width = `${control.offsetWidth}px`;
    dropdown.style.top = `${input.offsetHeight}px`;
    dropdown.style.left = "0px";
  }

  function openDropdown(): void {
    if (isOpen) return;
    isOpen = true;
    dropdown.style.display = "";
    input.classList.add("dropdown-active");
    positionDropdown();
  }

  function closeDropdown(): void {
    if (!isOpen) return;
    isOpen = false;
    dropdown.style.display = "none";
    input.classList.remove("dropdown-active");
    listeners.dropdown_close.forEach((fn) => { fn(); });
  }

  function selectHighlighted(): void {
    if (highlighted === null) return;
    if (highlighted === CREATE_SENTINEL) {
      createFromInput();
      return;
    }
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- DOM-string-to-T boundary, see selectize()'s own header comment.
    instance.addItem(highlighted as T);
  }

  function createFromInput(): void {
    const query = textInput.value.trim();
    if (query === "") return;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- computed-key record built from valueField/labelField, whose real shape can't be statically checked against the caller's own U.
    const data = { [valueField]: query, [labelField]: query } as unknown as U;
    instance.addOption(data);
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- DOM-string-to-T boundary, see selectize()'s own header comment.
    instance.addItem(query as T);
  }

  function moveHighlight(direction: 1 | -1): void {
    const rows = Array.from(
      dropdownContent.querySelectorAll<HTMLElement>("[data-selectable]"),
    );
    if (rows.length === 0) return;
    const idx = rows.findIndex((r) => r.getAttribute("data-value") === highlighted);
    const next = idx === -1 ? (direction === 1 ? 0 : rows.length - 1) : idx + direction;
    const clamped = Math.max(0, Math.min(rows.length - 1, next));
    highlighted = rows[clamped]!.getAttribute("data-value");
    updateHighlightClass();
    rows[clamped]!.scrollIntoView({ block: "nearest" });
  }

  on(input, "click", () => { textInput.focus(); });

  on(textInput, "focus", () => {
    isFocused = true;
    input.classList.add("focus");
    renderOptions(true);
  });

  on(textInput, "blur", () => {
    isFocused = false;
    input.classList.remove("focus");
    closeDropdown();
  });

  on(textInput, "input", () => {
    autoGrowInput();
    renderOptions(true);
  });

  on(textInput, "keydown", (evt) => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keydown" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
    const e = evt as KeyboardEvent;
    switch (e.key) {
      case "Escape":
        closeDropdown();
        e.preventDefault();
        return;
      case "ArrowDown":
        if (!isOpen) {
          renderOptions(true);
        } else {
          moveHighlight(1);
        }
        e.preventDefault();
        return;
      case "ArrowUp":
        if (isOpen) moveHighlight(-1);
        e.preventDefault();
        return;
      case "Enter":
        if (isOpen) {
          selectHighlighted();
          e.preventDefault();
        }
        return;
      case "Backspace":
        if (textInput.value === "" && items.length > 0) {
          instance.removeItem(items[items.length - 1]!);
          e.preventDefault();
        }
        return;
    }
  });

  on(dropdownContent, "mousedown", (evt) => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real mousedown inside dropdownContent always targets an HTMLElement (this file's own rendered option/create rows), never a bare EventTarget with no Element interface.
    const target = (evt.target as HTMLElement).closest<HTMLElement>("[data-selectable]");
    if (!target) return;
    evt.preventDefault();
    if (target.hasAttribute("data-create")) {
      createFromInput();
      return;
    }
    const value = target.getAttribute("data-value")!;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- DOM-string-to-T boundary, see selectize()'s own header comment.
    instance.addItem(value as T);
  });

  on(dropdownContent, "mousemove", (evt) => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real mousemove inside dropdownContent always targets an HTMLElement (this file's own rendered option/create rows), never a bare EventTarget with no Element interface.
    const target = (evt.target as HTMLElement).closest<HTMLElement>("[data-selectable]");
    if (!target) return;
    highlighted = target.getAttribute("data-value");
    updateHighlightClass();
  });

  on(document, "mousedown", (evt) => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real mousedown event's own target inside the document is always a Node (or null), never a bare EventTarget with no Node interface.
    if (!control.contains(evt.target as Node)) {
      closeDropdown();
    }
  });

  // Real `updateOriginalInput()`: for a `<select>`, this fully
  // *regenerates* its `<option>` children from `self.items` on every
  // change, rather than toggling `.selected` on whatever options already
  // happen to exist -- load-bearing for every Cache-backed control here
  // (`CategoriesCache`/`TagsCache`/`GroupsCache`/`UsersCache`), whose
  // underlying `<select>` starts with zero real `<option>` children (all
  // real data comes from the API, never server-rendered `<option>`s).
  function syncOriginalSelect(): void {
    el.innerHTML = "";
    items.forEach((value) => {
      const opt = document.createElement("option");
      opt.value = String(value);
      opt.selected = true;
      el.appendChild(opt);
    });
    if (items.length === 0 && !multi) {
      const opt = document.createElement("option");
      opt.value = "";
      opt.selected = true;
      el.appendChild(opt);
    }
  }

  instance = {
    options,
    settings,
    getValue(): T | T[] {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- empty-selection placeholder, same DOM-string-to-T boundary as selectize()'s own header comment.
      return multi ? [...items] : (items[0] ?? ("" as T));
    },
    setValue(value, silent) {
      instance.clear(true);
      const values = Array.isArray(value) ? value : [value];
      values.forEach((v) => { instance.addItem(v, true); });
      if (silent !== true) triggerChange();
    },
    addItem(value, silent) {
      const key = String(value);
      if (items.some((v) => String(v) === key)) return;
      if (!(key in options)) return;
      if (!multi) {
        items.length = 0;
      } else if (items.length >= maxItems) {
        return;
      }
      items.push(value);
      renderItems();
      textInput.value = "";
      // Real `addItem()`'s own `self.refreshOptions(self.isFocused &&
      // inputMode !== 'single')` -- gated on real focus, not
      // unconditionally true, so a silently pre-seeded item (real
      // `<option selected>`, or a `data-value` default) during setup
      // never force-opens the dropdown the way an actual user pick does.
      if (items.length >= maxItems || dropdownContent.children.length === 0) {
        closeDropdown();
      } else {
        renderOptions(isFocused);
      }
      syncOriginalSelect();
      if (silent !== true) {
        triggerChange();
      }
    },
    getItem(value) {
      return input.querySelector<HTMLElement>(`[data-value="${CSS.escape(String(value))}"]`);
    },
    on(event, handler) {
      if (event === "item_remove") {
        listeners.item_remove.push(handler);
      } else {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- SelectizeInstance.on()'s own interface declares one shared handler type `(value: T) => void` for both events, but a "dropdown_close" handler is always called with zero arguments -- a real, narrower shape than the declared union covers.
        listeners.dropdown_close.push(handler as () => void);
      }
    },
    addOption(data) {
      const list = Array.isArray(data) ? data : [data];
      list.forEach((d) => {
        const value = String(d[valueField]);
        if (value in options) return;
        options[value] = d;
        order.push(value);
      });
      input.classList.toggle("has-options", order.length > 0);
    },
    removeOption(value) {
      const key = String(value);
      Reflect.deleteProperty(options, key);
      const idx = order.indexOf(key);
      if (idx !== -1) order.splice(idx, 1);
      input.classList.toggle("has-options", order.length > 0);
      renderOptions(false);
    },
    clearOptions() {
      order.length = 0;
      Object.keys(options).forEach((k) => Reflect.deleteProperty(options, k));
      input.classList.remove("has-options");
      renderOptions(false);
    },
    refreshOptions(triggerDropdown = false) {
      renderOptions(triggerDropdown);
    },
    clear(silent) {
      if (items.length === 0) return;
      items.length = 0;
      renderItems();
      syncOriginalSelect();
      if (silent !== true) triggerChange();
    },
    load(fn) {
      fn.call(instance, (data) => {
        if (data.length > 0) {
          instance.addOption(data);
          renderOptions(false);
        }
      });
    },
    removeItem(value, silent) {
      const key = String(value);
      const idx = items.findIndex((v) => String(v) === key);
      if (idx === -1) return;
      items.splice(idx, 1);
      renderItems();
      syncOriginalSelect();
      if (silent !== true) triggerChange();
      listeners.item_remove.forEach((fn) => { fn(value); });
    },
  };

  // Real selectize.js's own `updateOriginalInput()` dispatches a jQuery
  // `change` trigger on the original `<select>` on every value change --
  // `rating.ts`'s own `jQuery("select[name=cat]").change(...)` (now a
  // real native `on(el, "change", ...)`) depends on this actually firing
  // a real DOM event, not just calling `onChange`.
  function triggerChange(): void {
    init.onChange?.(instance.getValue());
    el.dispatchEvent(new Event("change", { bubbles: true }));
  }

  domSelectedValues.forEach((value) => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- DOM-string-to-T boundary, see selectize()'s own header comment.
    instance.addItem(value as T, true);
  });
  (init.items ?? []).forEach((value) => { instance.addItem(value, true); });
  renderItems();

  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- WeakMap-erasure boundary, see comment above the WeakMap's own declaration.
  instances.set(el, instance as unknown as SelectizeInstance<never, never>);
  return instance;
}
