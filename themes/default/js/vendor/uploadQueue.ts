import { delegate } from "./dom";

// Native port of plupload 2.1.2's HTML5-runtime `plupload.Uploader` +
// `jquery.plupload.queue.js`'s own file-list widget (P49-C), real source
// read from the vendored `moxiecode/plupload@v2.1.2` tag (`js/plupload.dev.js`,
// `js/jquery.plupload.queue/jquery.plupload.queue.js`). `photos_add_direct.ts`
// is the ONLY real consumer anywhere in this app.
//
// Both real upstream files are far larger than what's ported here: plupload
// itself negotiates between 4 real runtimes (html5/flash/silverlight/html4)
// via a whole separate multi-runtime abstraction library (`mOxie`, its own
// ~9000-line bundle) and supports real client-side chunking/resizing for its
// own native upload transport. None of that applies to this app's one real
// call site: `runtimes: "html5"` is hardcoded (a modern, Chromium-based test
// matrix needs nothing else), and the actual byte transfer was already fully
// replaced by tus-js-client earlier in this campaign (`photos_add_direct.ts`'s
// own leading comment) -- plupload's own real uploading/chunking/resizing
// code, and everything gated behind `uploader.state === STARTED` (`.start()`/
// `.stop()`/`StateChanged`/`disableBrowse()`), is dead here: `#startUpload`'s
// real click handler calls `startTusUploads(up)` instead of `up.start()`, so
// plupload's own upload state machine never actually runs. This class is
// narrowed to exactly the file-selection/drag-drop/validation/queue-UI
// surface that stays real once the transport is tus: `bind`/`trigger`
// (multi-listener, matching real plupload's registration-order semantics --
// `photos_add_direct.ts`'s own `preinit`/`init` maps and this module's own
// internal queue-widget listeners for the same event both still need to
// fire, in the real relative order jquery.plupload.queue.js/plupload.dev.js
// established: this module's own file-list rendering always runs LAST for a
// given event, exactly as the original queue widget's own listeners --
// bound only after `uploader.init()` returns -- always ran after the app's
// own `settings.init` map, itself bound in the middle of that same call),
// `files`/`total`/`getFile`/`removeFile`/`setOption`/`getOption`.
//
// The real queue widget's own auto-generated header/column-header row/
// buttons/progress-bar markup (`renderUI()`'s own `.plupload_header`,
// `.plupload_filelist_header`, `.plupload_buttons`, `.plupload_progress`)
// isn't rendered at all -- confirmed dead via this app's own theme.css,
// which hides all of it unconditionally (`.plupload_header {display:none;}`,
// `.plupload_filelist_header {display:none;}`, `#uploadForm .plupload_buttons,
// #uploadForm .plupload_progress {display:none !important;}`), and the one
// state transition that would ever un-hide `.plupload_progress`/
// `.plupload_upload_status` (`StateChanged` firing with `state ===
// STARTED`) never happens here regardless, since `.start()` is never called.
// The real hidden `<input>` fields `updateList()` generates per uploaded
// file (`{id}_{n}_name`/`_tmpname`/`_status`, `{id}_count`) aren't rendered
// either -- `#uploadForm` is never actually submitted as a form (confirmed:
// no `.submit()` call anywhere in this app, no server-side code reads any
// of those field names), so they're real but entirely inert markup in the
// original too.
//
// The browse button itself is a real, plain hidden `<input type="file">`
// clicked by proxy when `settings.browse_button` is clicked -- simpler than
// mOxie's own runtime-overlay-positioning technique (`uploader.refresh()`),
// which exists to keep an invisible native file input precisely stacked
// over the visible browse button across runtimes/browsers/resizes; with
// only the HTML5 runtime in play and no positioning trick needed, a plain
// proxied `.click()` is equivalent and real. Drag-and-drop's own real
// `drop_element` is `{browse_button's <div id> target}_filelist` -- i.e.
// the file list `<ul>` itself, not the wider container -- matching
// `jquery.plupload.queue.js`'s own `settings.drop_element = id +
// '_filelist'` line exactly.

const QUEUED = 1;
const UPLOADING = 2;
const FAILED = 4;
const DONE = 5;

export { UPLOADING, FAILED, DONE };
const FILE_SIZE_ERROR = -600;
const FILE_EXTENSION_ERROR = -601;

export interface UploadQueueFile {
  id: string;
  name: string;
  type: string;
  size: number;
  loaded: number;
  percent: number;
  status: number;
  hint?: string;
  format_of?: string;
  getNative(): File;
}

export interface UploadQueueTotal {
  size: number;
  loaded: number;
  uploaded: number;
  failed: number;
  queued: number;
  percent: number;
}

interface UploadQueueError {
  code?: number;
  message: string;
  // Always real/present at every real trigger site in this module (both
  // the file-selection validation errors below and every tus error
  // `photos_add_direct.ts` itself builds) -- required, not optional, so
  // a handler that always reads `error.file` (as this app's own real
  // `Error` handler does) type-checks without an unsound cast.
  file: UploadQueueFile;
}

/**
 * `TFileUploadedInfo` is generic since `FileUploaded`'s own real 3rd
 * argument is entirely this module's one real caller's own construction
 * (`uploadNextTusFile()`'s own plain `{imageId, addStatus, squareSrc,
 * name}` object) -- this module itself never inspects it, just forwards
 * it through `trigger()`/`bind()` untouched.
 */
export interface UploadQueueOptions<TFileUploadedInfo = unknown> {
  browse_button: string;
  filters: {
    max_file_size: string;
    mime_types: { title: string; extensions: string }[];
  };
  rename: boolean;
  dragdrop: boolean;
  preinit?: {
    Init?: (up: UploadQueue, info: { runtime: string }) => void;
  };
  init?: {
    QueueChanged?: (up: UploadQueue) => void;
    FilesAdded?: (up: UploadQueue, files: UploadQueueFile[]) => void | Promise<void>;
    FilesRemoved?: (up: UploadQueue, file: UploadQueueFile) => void;
    UploadProgress?: (up: UploadQueue, file: UploadQueueFile) => void;
    BeforeUpload?: (up: UploadQueue, file: UploadQueueFile) => void;
    FileUploaded?: (up: UploadQueue, file: UploadQueueFile, info: TFileUploadedInfo) => void;
    Error?: (up: UploadQueue, error: UploadQueueError) => void;
    UploadComplete?: (up: UploadQueue, files: UploadQueueFile[]) => void;
  };
}

let fileIdCounter = 0;

function generateFileId(): string {
  fileIdCounter += 1;
  return "pluploadFile" + String(fileIdCounter);
}

/**
 * Real algorithm read from `moxie.js`'s own `parseSizeStr` -- this app's 2
 * real callers (`max_file_size`/`chunk_size`, though the latter is never
 * routed through here, see the module comment) only ever pass a plain
 * integer plus a `"kb"`/`"mb"` suffix, but the original's own multiplier
 * table and stripped-then-matched regex are both reproduced verbatim.
 */
function parseSize(sizeStr: string): number {
  const cleaned = sizeStr.toLowerCase().replace(/[^0-9mkg]/g, "");
  const match = /^([0-9]+)([mgk]?)$/.exec(cleaned);
  if (match === null) {
    return 0;
  }
  const multipliers: Record<string, number> = {
    k: 1024,
    m: 1024 ** 2,
    g: 1024 ** 3,
  };
  const n = Number(match[1]);
  const mul = match[2]!;
  return mul in multipliers ? n * multipliers[mul]! : n;
}

/** Real algorithm read from `plupload.dev.js`'s own `formatSize`. */
function formatSize(size: number): string {
  if (Number.isNaN(size)) {
    return "N/A";
  }
  const round = (n: number, precision: number): number =>
    Math.round(n * 10 ** precision) / 10 ** precision;

  let boundary = 1024 ** 4;
  if (size > boundary) {
    return String(round(size / boundary, 1)) + " tb";
  }
  boundary /= 1024;
  if (size > boundary) {
    return String(round(size / boundary, 1)) + " gb";
  }
  boundary /= 1024;
  if (size > boundary) {
    return String(round(size / boundary, 1)) + " mb";
  }
  if (size > 1024) {
    return String(Math.round(size / 1024)) + " kb";
  }
  return String(size) + " b";
}

/**
 * Real algorithm read from `plupload.dev.js`'s own `_setOption('filters', ...)`
 * branch, which builds this same regexp from `settings.filters.mime_types`.
 */
function buildExtensionRegex(mimeTypes: { extensions: string }[]): RegExp {
  const parts: string[] = [];
  for (const filter of mimeTypes) {
    for (const ext of filter.extensions.split(",")) {
      if (/^\s*\*\s*$/.test(ext)) {
        parts.push("\\.*");
      } else {
        parts.push("\\." + ext.replace(/[/^$.*+?|()[\]{}\\]/g, "\\$&"));
      }
    }
  }
  return new RegExp("(" + parts.join("|") + ")$", "i");
}

export class UploadQueue<TFileUploadedInfo = unknown> {
  files: UploadQueueFile[] = [];
  total: UploadQueueTotal = {
    size: 0,
    loaded: 0,
    uploaded: 0,
    failed: 0,
    queued: 0,
    percent: 0,
  };

  private readonly settings: UploadQueueOptions<TFileUploadedInfo>;
  private readonly root: HTMLElement;
  private readonly listeners = new Map<string, ((...args: unknown[]) => void)[]>();
  private readonly options = new Map<string, unknown>();
  private readonly extensionRegex: RegExp;
  private readonly maxFileSize: number;
  private listEl!: HTMLUListElement;
  private totalStatusEl!: HTMLElement;
  private totalSizeEl!: HTMLElement;
  private fileInput!: HTMLInputElement;

  constructor(target: HTMLElement, settings: UploadQueueOptions<TFileUploadedInfo>) {
    this.root = target;
    this.settings = settings;
    this.extensionRegex = buildExtensionRegex(settings.filters.mime_types);
    this.maxFileSize = parseSize(settings.filters.max_file_size);
  }

  bind(name: string, fn: (...args: unknown[]) => void): void {
    const list = this.listeners.get(name) ?? [];
    list.push(fn);
    this.listeners.set(name, list);
  }

  trigger(name: string, ...args: unknown[]): void {
    for (const fn of this.listeners.get(name) ?? []) {
      fn(this, ...args);
    }
  }

  setOption(key: string, value: unknown): void {
    this.options.set(key, value);
  }

  getOption(key?: string): unknown {
    if (key === undefined) {
      return Object.fromEntries(this.options);
    }
    return this.options.get(key);
  }

  getFile(id: string): UploadQueueFile | undefined {
    return this.files.find((f) => f.id === id);
  }

  removeFile(fileOrId: UploadQueueFile | string): void {
    const id = typeof fileOrId === "string" ? fileOrId : fileOrId.id;
    const index = this.files.findIndex((f) => f.id === id);
    if (index === -1) {
      return;
    }
    const [removed] = this.files.splice(index, 1);
    this.recomputeTotals();
    this.trigger("QueueChanged");
    this.trigger("FilesRemoved", [removed]);
    this.renderFileList();
  }

  /**
   * Real relative binding order, read across both real source files: this
   * module's own `Error` alert (queue.js's own real, unconditional
   * `uploader.bind("Error", ...)`, bound before `uploader.init()` ever
   * runs) fires before `settings.preinit`/`settings.init` -- both of which
   * are themselves real shorthand for `.bind(name, fn)` per key
   * (`plupload.dev.js`'s own `Uploader.init()`) -- which in turn fire
   * before this module's own file-list-rendering listeners (queue.js's
   * own remaining real `.bind()` calls, all made only after
   * `uploader.init()` returns).
   */
  init(): void {
    this.bind("Error", (...args) => {
      // `trigger()` calls every listener as `fn(this, ...args)` (matching
      // real plupload's own `fn(up, ...)` convention) -- `args[0]` here is
      // the uploader itself, not the payload.
      const err = args[1] as UploadQueueError;
      if (err.code === FILE_SIZE_ERROR) {
        alert("Error: File too large: " + err.file.name);
      }
      if (err.code === FILE_EXTENSION_ERROR) {
        alert("Error: Invalid file extension: " + err.file.name);
      }
    });

    if (this.settings.preinit?.Init) {
      this.bind("Init", this.settings.preinit.Init as (...args: unknown[]) => void);
    }

    this.renderShell();

    const init = this.settings.init ?? {};
    for (const name of Object.keys(init) as (keyof typeof init)[]) {
      const fn = init[name];
      if (fn) {
        this.bind(name, fn as (...args: unknown[]) => void);
      }
    }

    this.bind("FilesAdded", () => {
      this.renderFileList();
    });
    this.bind("FilesRemoved", () => {
      this.renderFileList();
    });
    this.bind("FileUploaded", (...args) => {
      this.markFileStatus(args[1] as UploadQueueFile);
    });
    this.bind("UploadProgress", (...args) => {
      const file = args[1] as UploadQueueFile;
      this.updatePerFileProgress(file);
      this.markFileStatus(file);
      this.updateTotalProgressText();
    });

    this.trigger("Init", { runtime: "html5" });
    this.trigger("PostInit");
  }

  private recomputeTotals(): void {
    let size = 0;
    let loaded = 0;
    let uploaded = 0;
    let failed = 0;
    let queued = 0;
    for (const file of this.files) {
      size += file.size;
      loaded += file.loaded;
      if (file.status === DONE) {
        uploaded += 1;
      } else if (file.status === FAILED) {
        failed += 1;
      } else {
        queued += 1;
      }
    }
    this.total.size = size;
    this.total.loaded = loaded;
    this.total.uploaded = uploaded;
    this.total.failed = failed;
    this.total.queued = queued;
    this.total.percent =
      size > 0
        ? Math.ceil((loaded / size) * 100)
        : this.files.length > 0
          ? Math.ceil((uploaded / this.files.length) * 100)
          : 0;
  }

  private renderShell(): void {
    // eslint-disable-next-line @typescript-eslint/no-this-alias -- needs to stay reachable inside the delegated rename-click handler below, whose own `this` is rebound to the matched element by dom.ts's own delegate().
    const uploader = this;
    this.root.textContent = "";

    const wrapper = document.createElement("div");
    wrapper.className = "plupload_wrapper plupload_scroll";

    const container = document.createElement("div");
    container.className = "plupload_container";
    container.id = (this.root.id || "uploader") + "_container";

    const inner = document.createElement("div");
    inner.className = "plupload";

    const content = document.createElement("div");
    content.className = "plupload_content";

    this.listEl = document.createElement("ul");
    this.listEl.className = "plupload_filelist";
    this.listEl.id = (this.root.id || "uploader") + "_filelist";

    const footer = document.createElement("div");
    footer.className = "plupload_filelist_footer";
    footer.style.display = "none";

    const footerName = document.createElement("div");
    footerName.className = "plupload_file_name";
    const footerStatus = document.createElement("div");
    footerStatus.className = "plupload_file_status";
    this.totalStatusEl = document.createElement("span");
    this.totalStatusEl.className = "plupload_total_status";
    this.totalStatusEl.textContent = "0%";
    footerStatus.append(this.totalStatusEl);
    const footerSize = document.createElement("div");
    footerSize.className = "plupload_file_size";
    this.totalSizeEl = document.createElement("span");
    this.totalSizeEl.className = "plupload_total_file_size";
    this.totalSizeEl.textContent = "0 b";
    footerSize.append(this.totalSizeEl);
    const footerClearer = document.createElement("div");
    footerClearer.className = "plupload_clearer";
    footer.append(footerName, footerStatus, footerSize, footerClearer);

    content.append(this.listEl, footer);
    inner.append(content);
    container.append(inner);
    wrapper.append(container);
    this.root.append(wrapper);

    this.fileInput = document.createElement("input");
    this.fileInput.type = "file";
    this.fileInput.multiple = true;
    this.fileInput.style.display = "none";
    wrapper.append(this.fileInput);

    const browseButton = document.getElementById(this.settings.browse_button);
    browseButton?.addEventListener("click", (event) => {
      event.preventDefault();
      this.fileInput.click();
    });
    this.fileInput.addEventListener("change", () => {
      if (this.fileInput.files) {
        this.addFiles(this.fileInput.files);
      }
      this.fileInput.value = "";
    });

    if (this.settings.dragdrop) {
      this.listEl.addEventListener("dragenter", (event) => {
        event.preventDefault();
      });
      this.listEl.addEventListener("dragover", (event) => {
        event.preventDefault();
      });
      this.listEl.addEventListener("drop", (event) => {
        event.preventDefault();
        if (event.dataTransfer) {
          this.addFiles(event.dataTransfer.files);
        }
      });
    }

    if (this.settings.rename) {
      // Real source binds this delegated (`target.on('click', selector,
      // ...)`), not per-row -- survives the file list being wiped and
      // rebuilt on every add/remove.
      delegate(
        this.root,
        "click",
        ".plupload_filelist .plupload_file_name span",
        function (this: HTMLElement): void {
          uploadQueueBeginRename(this, uploader);
        },
      );
    }

    this.renderFileList();
  }

  private addFiles(fileList: FileList): void {
    const accepted: UploadQueueFile[] = [];
    for (const native of Array.from(fileList)) {
      const wrapped: UploadQueueFile = {
        id: generateFileId(),
        name: native.name,
        type: native.type,
        size: native.size,
        loaded: 0,
        percent: 0,
        status: QUEUED,
        getNative: () => native,
      };

      if (!this.extensionRegex.test(native.name)) {
        this.trigger("Error", {
          code: FILE_EXTENSION_ERROR,
          message: "File extension error.",
          file: wrapped,
        });
        continue;
      }
      if (this.maxFileSize > 0 && native.size > this.maxFileSize) {
        this.trigger("Error", {
          code: FILE_SIZE_ERROR,
          message: "File size error.",
          file: wrapped,
        });
        continue;
      }
      accepted.push(wrapped);
    }

    if (accepted.length > 0) {
      this.files.push(...accepted);
      this.recomputeTotals();
      this.trigger("QueueChanged");
      this.trigger("FilesAdded", accepted);
      this.renderFileList();
    }
  }

  private renderFileList(): void {
    this.listEl.textContent = "";

    for (const file of this.files) {
      const li = document.createElement("li");
      li.id = file.id;

      const nameDiv = document.createElement("div");
      nameDiv.className = "plupload_file_name";
      const nameSpan = document.createElement("span");
      nameSpan.textContent = file.name;
      nameDiv.append(nameSpan);

      const actionDiv = document.createElement("div");
      actionDiv.className = "plupload_file_action";
      const actionLink = document.createElement("a");
      actionLink.href = "#";
      actionDiv.append(actionLink);

      const statusDiv = document.createElement("div");
      statusDiv.className = "plupload_file_status";
      statusDiv.textContent = String(file.percent) + "%";

      const sizeDiv = document.createElement("div");
      sizeDiv.className = "plupload_file_size";
      sizeDiv.textContent = formatSize(file.size);

      const clearer = document.createElement("div");
      clearer.className = "plupload_clearer";

      li.append(nameDiv, actionDiv, statusDiv, sizeDiv, clearer);
      this.listEl.append(li);

      this.markFileStatus(file);

      // Real source only ever binds this for a currently-QUEUED row
      // (`$('#'+file.id+'.plupload_delete a')`, a selector that only
      // matches while `handleStatus()`'s own class is still
      // `plupload_delete`) -- an uploading/done/failed file's own action
      // icon is real but not clickable.
      if (file.status === QUEUED) {
        actionLink.addEventListener("click", (event) => {
          event.preventDefault();
          this.removeFile(file);
        });
      }
    }

    if (this.files.length === 0 && this.settings.dragdrop) {
      const dropText = document.createElement("li");
      dropText.className = "plupload_droptext";
      dropText.textContent = "Drag files here.";
      this.listEl.append(dropText);
    }
  }

  private markFileStatus(file: UploadQueueFile): void {
    const li = document.getElementById(file.id);
    if (li === null) {
      return;
    }
    li.className =
      file.status === DONE
        ? "plupload_done"
        : file.status === FAILED
          ? "plupload_failed"
          : file.status === UPLOADING
            ? "plupload_uploading"
            : "plupload_delete";

    const action = li.querySelector<HTMLAnchorElement>(".plupload_file_action a");
    if (action !== null) {
      action.style.display = "block";
      if (file.hint !== undefined && file.hint !== "") {
        action.title = file.hint;
      }
    }
  }

  private updatePerFileProgress(file: UploadQueueFile): void {
    const li = document.getElementById(file.id);
    const statusDiv = li?.querySelector(".plupload_file_status");
    if (statusDiv) {
      statusDiv.textContent = String(file.percent) + "%";
    }
  }

  private updateTotalProgressText(): void {
    this.totalStatusEl.textContent = String(this.total.percent) + "%";
    this.totalSizeEl.textContent = formatSize(this.total.size);
  }
}

/**
 * Real source (`file.name` split into base name + extension via
 * `/^(.+)(\.[^.]+)$/`, inline `<input>` replacing the `<span>`, Enter
 * commits and blurs, any blur restores the `<span>`).
 */
function uploadQueueBeginRename<T>(span: HTMLElement, uploader: UploadQueue<T>): void {
  const li = span.closest("li");
  if (li === null) {
    return;
  }
  const file = uploader.getFile(li.id);
  if (file === undefined) {
    return;
  }

  const match = /^(.+)(\.[^.]+)$/.exec(file.name);
  const baseName = match ? match[1]! : file.name;
  const ext = match ? match[2]! : "";

  span.style.display = "none";
  const input = document.createElement("input");
  input.type = "text";
  input.value = baseName;
  span.after(input);
  input.focus();

  input.addEventListener(
    "blur",
    () => {
      span.style.display = "";
      input.remove();
    },
    { once: true },
  );
  input.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      file.name = input.value + ext;
      span.textContent = file.name;
      input.blur();
    }
  });
}

export function uploadQueue<TFileUploadedInfo = unknown>(
  target: HTMLElement,
  settings: UploadQueueOptions<TFileUploadedInfo>,
): UploadQueue<TFileUploadedInfo> {
  const uploader = new UploadQueue(target, settings);
  uploader.init();
  return uploader;
}
