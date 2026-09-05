// Native port of jqtree (P49-B group 6B, `jqtree@1.4.12`, real source read
// from `github:mbraak/jqtree`'s own `lib/*.js`). One real consumer,
// `albums.ts`'s drag-and-drop-orderable album tree (~32 `.tree(...)` call
// sites), which narrows the real surface dramatically versus the full
// library:
//
// - Selection is permanently disabled at the one real call site
//   (`onCanSelectNode: () => false`) -- no click ever selects a node, so
//   the whole click-handling/select-node-handler/key-handler machinery
//   (and the `.jqtree-toggler`/`.jqtree-title` markup jqtree renders
//   internally, which `onCreateLi` unconditionally wipes and replaces
//   with its own markup at the one real call site anyway) is genuinely
//   dead weight here and is not ported.
// - `autoOpen`/`saveState` are always `false` (the real init call passes
//   `autoOpen: false`, never sets `saveState`), which collapses the
//   original's initial-state-restore/auto-open-nodes/load-on-demand-at-
//   init dance to a no-op -- so that machinery isn't ported either.
//   `getState()` itself (the `open_nodes` list) *is* real and load-
//   bearing (read directly by `albums.ts`), and is kept.
// - `dataUrl`/`dataFilter`/remote loading is never used (all data arrives
//   pre-fetched, fed in via `data:` at init or `loadData()` later) --
//   `data_loader.js`'s URL-loading path is not ported.
// - `rtl`/`keyboardSupport`/`useContextMenu`/`closedIcon`/`openedIcon`/
//   `buttonLeft`/`nodeClass`/`tabIndex` are never customized and are
//   dropped from the options surface entirely, along with the `tree.
//   click`/`tree.dblclick`/`tree.contextmenu`/`tree.init` events (no real
//   listener anywhere).
//
// Drag-and-drop (`dragAndDrop: true`, real and load-bearing -- this is
// the whole point of the widget on this page) is ported in full: hit-area
// generation, the ghost/border drop-hint, the folder auto-expand-on-
// hover-during-drag timer, and the `tree.move` cancelable event with its
// `do_move()` callback, plus the scroll-during-drag behavior.
import {
  css,
  offset,
  off,
  on,
  outerHeight,
  slideDown,
  slideUp,
  trigger,
  valueAt,
  width,
  windowHeight,
  windowWidth,
} from "../utils/dom";

type JqTreePosition = "before" | "after" | "inside" | "none";

type JqTreeNodeData<T extends Record<string, unknown>> = T & {
  id?: string | number;
  name: string;
  isEmptyFolder?: boolean;
  load_on_demand?: boolean;
  children?: JqTreeNodeData<T>[];
};

/**
 * The shape `appendNode()`/`prependNode()` real callers actually build --
 * a real new node never arrives with every one of `T`'s own fields
 * already populated (e.g. a freshly-created album has no `nb_images`/
 * `rank`/... yet), matching the original's own permissive `setData()`
 * (copies whichever keys are present, leaves the rest `undefined` until
 * a later `updateNode()`/reload fills them in).
 */
type JqTreeNewNodeInfo<T extends Record<string, unknown>> = Partial<T> & {
  id?: string | number;
  name: string;
  isEmptyFolder?: boolean;
  load_on_demand?: boolean;
  children?: JqTreeNodeData<T>[];
};

// Every `node as unknown as JqTreeNode<T>` / `treeNode as TreeNode` cast in
// this file crosses the same real internal/public boundary: `class
// TreeNode` below is the actual runtime implementation (genuinely not
// generic -- its own `[key: string]: unknown` index signature and
// `setData()`'s `for...of Object.entries(data)` loop copy every one of
// `T`'s own keys directly onto the instance), while `JqTreeNode<T>` is
// the public type callers see. TS has no way to verify that link
// structurally; each cast states it instead.
export type JqTreeNode<T extends Record<string, unknown>> = T & {
  id?: string | number;
  name: string;
  isEmptyFolder: boolean;
  load_on_demand: boolean;
  is_open: boolean;
  is_loading: boolean;
  element: HTMLLIElement | null;
  parent: JqTreeNode<T> | null;
  children: JqTreeNode<T>[];
  isFolder(): boolean;
  hasChildren(): boolean;
  getLevel(): number;
  getPreviousSibling(): JqTreeNode<T> | null;
  iterate(callback: (node: JqTreeNode<T>, level: number) => boolean): void;
};

interface JqTreeState {
  open_nodes: (string | number)[];
}

// getNodeById()'s own real id parameter -- nullable/optional since real
// callers look a possibly-absent id up (sonarjs/use-type-alias: 3 real
// repeats, the interface plus both of its own implementations below).
type JqTreeNodeId = string | number | null | undefined;

export interface JqTreeMoveInfo<T extends Record<string, unknown>> {
  movedNode: JqTreeNode<T>;
  targetNode: JqTreeNode<T>;
  position: JqTreePosition;
  previousParent: JqTreeNode<T> | null;
  doMove(): void;
}

export interface JqTreeOptions<T extends Record<string, unknown>> {
  data: JqTreeNodeData<T>[];
  dragAndDrop?: boolean;
  openFolderDelay?: number;
  onCreateLi?: (node: JqTreeNode<T>, li: HTMLLIElement) => void;
  animationSpeed?: string | number;
  slide?: boolean;
  autoEscape?: boolean;
  showEmptyFolder?: boolean;
  onDragMove?: (node: JqTreeNode<T>, event: MouseEvent | Touch) => void;
  onDragStop?: (node: JqTreeNode<T>, event: MouseEvent | Touch) => void;
  onCanMove?: (node: JqTreeNode<T>) => boolean;
  onCanMoveTo?: (
    movedNode: JqTreeNode<T>,
    targetNode: JqTreeNode<T>,
    position: JqTreePosition,
  ) => boolean;
  onIsMoveHandle?: (el: Element) => boolean;
}

export interface JqTreeInstance<T extends Record<string, unknown>> {
  getNodeById(id: JqTreeNodeId): JqTreeNode<T> | null;
  getState(): JqTreeState;
  openNode(
    node: JqTreeNode<T>,
    slide?: boolean,
    onFinished?: (node: JqTreeNode<T>) => void,
  ): void;
  closeNode(node: JqTreeNode<T>, slide?: boolean): void;
  updateNode(node: JqTreeNode<T>, data: unknown): void;
  appendNode(
    newNodeInfo: JqTreeNewNodeInfo<T>,
    parentNode?: JqTreeNode<T>,
  ): JqTreeNode<T>;
  prependNode(
    newNodeInfo: JqTreeNewNodeInfo<T>,
    parentNode?: JqTreeNode<T>,
  ): JqTreeNode<T>;
  removeNode(node: JqTreeNode<T>): void;
  loadData(data: JqTreeNodeData<T>[], parentNode: JqTreeNode<T>): void;
}

function htmlEscape(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#x27;")
    .replace(/\//g, "&#x2F;");
}

// ── Node model (real source: lib/node.js) ────────────────────────────────
//
// Every own-enumerable property of the raw data object becomes a real
// property on the resulting node instance (`setData()`), so `T`'s own
// fields (album `status`/`rank`/`nb_images`/...) are simultaneously real
// data AND accessed through the same object as `.parent`/`.children`/
// `.getLevel()` -- that dynamic shape-shifting is why the internal
// representation below is untyped where the original's own `Node` class
// is, with the public `JqTreeNode<T>` type applied only at the API
// boundary.

class TreeNode {
  [key: string]: unknown;
  id?: string | number;
  name = "";
  isEmptyFolder = false;
  load_on_demand: boolean | null = null;
  is_open = false;
  is_loading = false;
  element: HTMLLIElement | null = null;
  children: TreeNode[] = [];
  parent: TreeNode | null = null;
  // Only meaningful on the root node; every other node's own `tree`
  // points to the same root (set by `_setParent`).
  tree: TreeNode;
  idMapping = new Map<string, TreeNode>();

  constructor(data: unknown, isRoot = false) {
    this.tree = this;
    this.setData(data);
    if (!isRoot) {
      // Overwritten by `_setParent` once a real parent adopts this node;
      // matches the original leaving a freshly-constructed non-root node
      // with no working `tree`/index until `addChild()` runs.
      this.idMapping = new Map();
    }
  }

  setData(data: unknown): void {
    if (data == null) {
      return;
    }
    if (typeof data !== "object") {
      this.name = typeof data === "string" || typeof data === "number" ? String(data) : "";
      return;
    }
    for (const [key, value] of Object.entries(data)) {
      if (key === "label") {
        this.name = String(value);
      } else if (key !== "children") {
        this[key] = value;
      }
    }
  }

  loadFromData(data: readonly unknown[]): void {
    this.removeChildren();
    for (const raw of data) {
      const node = new TreeNode(raw);
      this.addChild(node);
      if (typeof raw === "object" && raw !== null && "children" in raw) {
        const kids = (raw as Record<string, unknown>)["children"];
        if (Array.isArray(kids)) {
          if (kids.length === 0) {
            node.isEmptyFolder = true;
          } else {
            node.loadFromData(kids as unknown[]);
          }
        }
      }
    }
  }

  addChild(node: TreeNode): void {
    this.children.push(node);
    node._setParent(this);
  }

  addChildAtPosition(node: TreeNode, index: number): void {
    this.children.splice(index, 0, node);
    node._setParent(this);
  }

  removeChild(node: TreeNode): void {
    node.removeChildren();
    this._removeChild(node);
  }

  getChildIndex(node: TreeNode): number {
    return this.children.indexOf(node);
  }

  hasChildren(): boolean {
    return this.children.length !== 0;
  }

  isFolder(): boolean {
    return this.hasChildren() || Boolean(this.load_on_demand);
  }

  iterate(callback: (node: TreeNode, level: number) => boolean): void {
    const iterateNode = (node: TreeNode, level: number): void => {
      for (const child of node.children) {
        const result = callback(child, level);
        if (result && child.hasChildren()) {
          iterateNode(child, level + 1);
        }
      }
    };
    iterateNode(this, 0);
  }

  isParentOf(node: TreeNode): boolean {
    let {parent} = node;
    while (parent) {
      if (parent === this) {
        return true;
      }
      ({ parent } = parent);
    }
    return false;
  }

  getLevel(): number {
    let level = 0;
    let node: TreeNode | null = this.parent;
    while (node) {
      level += 1;
      node = node.parent;
    }
    return level;
  }

  getNodeById(nodeId: JqTreeNodeId): TreeNode | null {
    if (nodeId == null) {
      return null;
    }
    return this.idMapping.get(String(nodeId)) ?? null;
  }

  addNodeToIndex(node: TreeNode): void {
    if (node.id != null) {
      this.idMapping.set(String(node.id), node);
    }
  }

  removeNodeFromIndex(node: TreeNode): void {
    if (node.id != null) {
      this.idMapping.delete(String(node.id));
    }
  }

  removeChildren(): void {
    this.iterate((child) => {
      this.tree.removeNodeFromIndex(child);
      return true;
    });
    this.children = [];
  }

  getPreviousSibling(): TreeNode | null {
    if (!this.parent) {
      return null;
    }
    const index = this.parent.getChildIndex(this) - 1;
    return index >= 0 ? (this.parent.children[index] ?? null) : null;
  }

  append(nodeInfo: unknown): TreeNode {
    const node = new TreeNode(nodeInfo);
    this.addChild(node);
    if (typeof nodeInfo === "object" && nodeInfo !== null && "children" in nodeInfo) {
      const kids = nodeInfo.children;
      if (Array.isArray(kids) && kids.length) {
        node.loadFromData(kids as unknown[]);
      }
    }
    return node;
  }

  prepend(nodeInfo: unknown): TreeNode {
    const node = new TreeNode(nodeInfo);
    this.addChildAtPosition(node, 0);
    if (typeof nodeInfo === "object" && nodeInfo !== null && "children" in nodeInfo) {
      const kids = nodeInfo.children;
      if (Array.isArray(kids) && kids.length) {
        node.loadFromData(kids as unknown[]);
      }
    }
    return node;
  }

  remove(): void {
    if (this.parent) {
      this.parent.removeChild(this);
      this.parent = null;
    }
  }

  _setParent(parent: TreeNode): void {
    this.parent = parent;
    this.tree = parent.tree;
    this.tree.addNodeToIndex(this);
  }

  _removeChild(node: TreeNode): void {
    this.children.splice(this.getChildIndex(node), 1);
    this.tree.removeNodeFromIndex(node);
  }
}

function moveNode(
  movedNode: TreeNode,
  targetNode: TreeNode,
  position: JqTreePosition,
): void {
  if (!movedNode.parent || movedNode.isParentOf(targetNode)) {
    return;
  }
  movedNode.parent._removeChild(movedNode);
  if (position === "after") {
    if (targetNode.parent) {
      targetNode.parent.addChildAtPosition(
        movedNode,
        targetNode.parent.getChildIndex(targetNode) + 1,
      );
    }
  } else if (position === "before") {
    if (targetNode.parent) {
      targetNode.parent.addChildAtPosition(
        movedNode,
        targetNode.parent.getChildIndex(targetNode),
      );
    }
  } else if (position === "inside") {
    targetNode.addChildAtPosition(movedNode, 0);
  }
}

// ── Rendering (real source: lib/elements_renderer.js) ────────────────────
//
// The internal `.jqtree-toggler`/`.jqtree-title` markup the original
// renders is not built here: the one real `onCreateLi` (`albums.ts`'s
// `createAlbumNode`) unconditionally clears and replaces `.jqtree-
// element`'s content on every node, so that markup would be destroyed
// before ever being seen. The wrapper `<li>`/`.jqtree-element` structure,
// its classes, and the nested `<ul>` per node (real, load-bearing --
// `FolderElement.open()/close()` and this file's own CSS depend on them)
// are ported faithfully.

function firstChildUl(li: HTMLLIElement): HTMLUListElement | null {
  for (const child of li.children) {
    if (child.tagName === "UL") {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the tagName check right above.
      return child as HTMLUListElement;
    }
  }
  return null;
}

function createLi(node: TreeNode, showEmptyFolder: boolean): HTMLLIElement {
  const mustShowFolder = node.isFolder() || (node.isEmptyFolder && showEmptyFolder);
  const li = document.createElement("li");
  const classes = ["jqtree_common"];
  if (mustShowFolder) {
    classes.push("jqtree-folder");
    if (!node.is_open) {
      classes.push("jqtree-closed");
    }
    if (node.is_loading) {
      classes.push("jqtree-loading");
    }
  }
  li.className = classes.join(" ");
  li.setAttribute("role", "presentation");

  const div = document.createElement("div");
  div.className = "jqtree-element jqtree_common";
  div.setAttribute("role", "presentation");
  li.appendChild(div);

  return li;
}

function createUl(isRoot: boolean): HTMLUListElement {
  const ul = document.createElement("ul");
  ul.className = isRoot ? "jqtree_common jqtree-tree" : "jqtree_common";
  ul.setAttribute("role", isRoot ? "tree" : "group");
  return ul;
}

class JqTreeController<T extends Record<string, unknown>> {
  el: HTMLElement;
  options: Required<
    Pick<JqTreeOptions<T>, "dragAndDrop" | "openFolderDelay" | "animationSpeed" | "slide" | "autoEscape" | "showEmptyFolder">
  > &
    JqTreeOptions<T>;
  root = new TreeNode(null, true);

  // Drag-and-drop mouse-widget state (real source: lib/mouse.widget.js).
  mouseDelay = 300;
  isMouseStarted = false;
  mouseDelayTimer: number | null = null;
  isMouseDelayMet = true;
  mouseDownInfo: PositionInfo | null = null;

  // Drag-and-drop handler state (real source: lib/drag_and_drop_handler.js).
  hoveredArea: HitArea | null = null;
  hitAreas: HitArea[] = [];
  isDragging = false;
  currentItem: TreeNode | null = null;
  dragElement: DragElementHandle | null = null;
  positionInfo: PositionInfo | null = null;
  openFolderTimer: number | null = null;
  previousGhost: { remove(): void } | null = null;

  // Scroll-during-drag state (real source: lib/scroll_handler.js).
  scrollInitialized = false;
  scrollParent: HTMLElement | null = null;
  scrollParentTop = 0;
  previousScrollTop = -1;

  constructor(el: HTMLElement, options: JqTreeOptions<T>) {
    this.el = el;
    this.options = {
      dragAndDrop: options.dragAndDrop ?? false,
      openFolderDelay: options.openFolderDelay ?? 500,
      animationSpeed: options.animationSpeed ?? "fast",
      slide: options.slide ?? true,
      autoEscape: options.autoEscape ?? true,
      showEmptyFolder: options.showEmptyFolder ?? false,
      ...options,
    };
  }

  init(): void {
    this.root.loadFromData(this.options.data);
    this.render(null);
    if (this.options.dragAndDrop) {
      on(this.el, "mousedown", this.#onMouseDown);
      on(this.el, "touchstart", this.#onTouchStart);
    }
  }

  // ── Rendering ───────────────────────────────────────────────────────

  render(fromNode: TreeNode | null): void {
    if (fromNode?.parent) {
      this.renderFromNode(fromNode);
    } else {
      this.renderFromRoot();
    }
  }

  renderFromRoot(): void {
    this.el.replaceChildren();
    this.createDomElements(this.el, this.root.children, true);
  }

  renderFromNode(node: TreeNode): void {
    const previousLi = node.element;
    const li = createLi(node, this.options.showEmptyFolder);
    node.element = li;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
    this.options.onCreateLi?.(node as unknown as JqTreeNode<T>, li);
    previousLi?.after(li);
    previousLi?.remove();
    this.createDomElements(li, node.children, false);
  }

  createDomElements(
    element: HTMLElement,
    children: TreeNode[],
    isRoot: boolean,
  ): void {
    const ul = createUl(isRoot);
    element.appendChild(ul);
    for (const child of children) {
      const li = createLi(child, this.options.showEmptyFolder);
      ul.appendChild(li);
      child.element = li;
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
      this.options.onCreateLi?.(child as unknown as JqTreeNode<T>, li);
      if (child.hasChildren()) {
        this.createDomElements(li, child.children, false);
      }
    }
  }

  // ── Public instance API ──────────────────────────────────────────────

  getNodeById(id: JqTreeNodeId): TreeNode | null {
    return this.root.getNodeById(id);
  }

  getState(): JqTreeState {
    const openNodes: (string | number)[] = [];
    this.root.iterate((node) => {
      if (node.is_open && node.id != null && node.hasChildren()) {
        openNodes.push(node.id);
      }
      return true;
    });
    return { open_nodes: openNodes };
  }

  openNode(
    node: TreeNode,
    slideParam?: boolean,
    onFinished?: (node: TreeNode) => void,
  ): void {
    if (!(node.isFolder() || node.isEmptyFolder)) {
      return;
    }
    const slide = slideParam ?? this.options.slide;
    let {parent} = node;
    while (parent) {
      if (parent.parent) {
        this.#doOpenNode(parent, false, undefined);
      }
      ({ parent } = parent);
    }
    this.#doOpenNode(node, slide, onFinished);
  }

  closeNode(node: TreeNode, slideParam?: boolean): void {
    const slide = slideParam ?? this.options.slide;
    if (!(node.isFolder() || node.isEmptyFolder)) {
      return;
    }
    this.#doCloseNode(node, slide);
  }

  updateNode(node: TreeNode, data: unknown): void {
    const idIsChanged =
      typeof data === "object" &&
      data !== null &&
      "id" in data &&
      (data as Record<string, unknown>)["id"] != null &&
      (data as Record<string, unknown>)["id"] !== node.id;
    if (idIsChanged) {
      this.root.removeNodeFromIndex(node);
    }
    node.setData(data);
    if (idIsChanged) {
      this.root.addNodeToIndex(node);
    }
    if (typeof data === "object" && data !== null && "children" in data) {
      const kids = (data as Record<string, unknown>)["children"];
      node.removeChildren();
      if (Array.isArray(kids) && kids.length) {
        node.loadFromData(kids as unknown[]);
      }
    }
    this.render(node);
  }

  appendNode(newNodeInfo: unknown, parentNodeParam?: TreeNode): TreeNode {
    const parentNode = parentNodeParam ?? this.root;
    const node = parentNode.append(newNodeInfo);
    this.render(parentNode);
    return node;
  }

  prependNode(newNodeInfo: unknown, parentNodeParam?: TreeNode): TreeNode {
    const parentNode = parentNodeParam ?? this.root;
    const node = parentNode.prepend(newNodeInfo);
    this.render(parentNode);
    return node;
  }

  removeNode(node: TreeNode): void {
    if (!node.parent) {
      return;
    }
    const {parent} = node;
    node.remove();
    this.render(parent);
  }

  loadData(data: readonly unknown[], parentNode: TreeNode): void {
    parentNode.loadFromData(data);
    parentNode.load_on_demand = false;
    parentNode.is_loading = false;
    this.render(parentNode);
    if (this.isDragging) {
      this.#refreshHitAreas();
    }
  }

  // ── Open/close (real source: lib/node_element.js FolderElement) ──────

  #doOpenNode(
    node: TreeNode,
    slide: boolean,
    onFinished: ((node: TreeNode) => void) | undefined,
  ): void {
    if (node.is_open) {
      return;
    }
    node.is_open = true;
    const li = node.element;
    const doOpen = (): void => {
      li?.classList.remove("jqtree-closed");
      onFinished?.(node);
      trigger(this.el, "tree.open", { node });
    };
    const ul = li ? firstChildUl(li) : null;
    if (slide && ul) {
      slideDown(ul, this.options.animationSpeed, doOpen);
    } else {
      if (ul) {
        ul.style.display = "";
      }
      doOpen();
    }
  }

  #doCloseNode(node: TreeNode, slide: boolean): void {
    if (!node.is_open) {
      return;
    }
    node.is_open = false;
    const li = node.element;
    const doClose = (): void => {
      li?.classList.add("jqtree-closed");
      trigger(this.el, "tree.close", { node });
    };
    const ul = li ? firstChildUl(li) : null;
    if (slide && ul) {
      slideUp(ul, this.options.animationSpeed, doClose);
    } else {
      if (ul) {
        ul.style.display = "none";
      }
      doClose();
    }
  }

  static #mustShowBorderDropHint(node: TreeNode, position: JqTreePosition): boolean {
    if (node.isFolder()) {
      return !node.is_open && position === "inside";
    }
    return position === "inside";
  }

  #addDropHint(node: TreeNode, position: JqTreePosition): { remove(): void } {
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- a node passed here is always a real, already-rendered TreeNode with its own element set.
    const li = node.element!;
    if (JqTreeController.#mustShowBorderDropHint(node, position)) {
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every rendered <li> always has its own real ":scope > .jqtree-element" child (this widget's own onCreateLi markup).
      const div = li.querySelector<HTMLElement>(":scope > .jqtree-element")!;
      const elWidth = width(li) || 0;
      const w = Math.max(elWidth + this.#getScrollLeft() - 4, 0);
      const elHeight = outerHeight(div) || 0;
      const h = Math.max(elHeight - 4, 0);
      const hint = document.createElement("span");
      hint.className = "jqtree-border";
      div.appendChild(hint);
      css(hint, { width: w, height: h });
      return { remove: () => { hint.remove(); } };
    }

    const ghost = document.createElement("li");
    ghost.className = "jqtree_common jqtree-ghost";
    ghost.innerHTML =
      '<span class="jqtree_common jqtree-circle"></span>' +
      '<span class="jqtree_common jqtree-line"></span>';
    if (position === "after") {
      li.after(ghost);
    } else if (position === "before") {
      li.before(ghost);
    } else if (node.isFolder() && node.is_open) {
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- an open folder node is always one this widget already rendered with real children/element (mustShowBorderDropHint's own "inside" check above already excluded the closed-folder case).
      valueAt(node.children, 0).element!.before(ghost);
    } else {
      li.after(ghost);
      ghost.classList.add("jqtree-inside");
    }
    return { remove: () => { ghost.remove(); } };
  }

  // ── Mouse/touch capture (real source: lib/mouse.widget.js) ───────────

  readonly #onMouseDown = (e: Event): void => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "mousedown" always dispatches a real MouseEvent; the class field is typed generically via the native EventListener interface.
    const event = e as MouseEvent;
    if (event.button !== 0) {
      return;
    }
    if (this.#handleMouseDown(JqTreeController.#positionInfoFromMouse(event))) {
      event.preventDefault();
    }
  };

  readonly #onTouchStart = (e: Event): void => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "touchstart" always dispatches a real TouchEvent; the class field is typed generically via the native EventListener interface.
    const event = e as TouchEvent;
    if (event.touches.length > 1) {
      return;
    }
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- a real "touch*" event with touches.length <= 1 (checked above) always carries at least one changed touch.
    const touch = event.changedTouches[0]!;
    this.#handleMouseDown(JqTreeController.#positionInfoFromTouch(touch, event));
  };

  readonly #onMouseMove = (e: Event): void => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "mousemove" always dispatches a real MouseEvent; the class field is typed generically via the native EventListener interface.
    const event = e as MouseEvent;
    this.#handleMouseMove(event, JqTreeController.#positionInfoFromMouse(event));
  };

  readonly #onTouchMove = (e: Event): void => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "touchmove" always dispatches a real TouchEvent; the class field is typed generically via the native EventListener interface.
    const event = e as TouchEvent;
    if (event.touches.length > 1) {
      return;
    }
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- a real "touch*" event with touches.length <= 1 (checked above) always carries at least one changed touch.
    const touch = event.changedTouches[0]!;
    this.#handleMouseMove(event, JqTreeController.#positionInfoFromTouch(touch, event));
  };

  readonly #onMouseUp = (e: Event): void => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "mouseup" always dispatches a real MouseEvent; the class field is typed generically via the native EventListener interface.
    this.#handleMouseUp(JqTreeController.#positionInfoFromMouse(e as MouseEvent));
  };

  readonly #onTouchEnd = (e: Event): void => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "touchend" always dispatches a real TouchEvent; the class field is typed generically via the native EventListener interface.
    const event = e as TouchEvent;
    if (event.touches.length > 1) {
      return;
    }
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- a real "touch*" event with touches.length <= 1 (checked above) always carries at least one changed touch.
    const touch = event.changedTouches[0]!;
    this.#handleMouseUp(JqTreeController.#positionInfoFromTouch(touch, event));
  };

  static #positionInfoFromMouse(e: MouseEvent): PositionInfo {
    return { pageX: e.pageX, pageY: e.pageY, target: e.target, originalEvent: e };
  }

  static #positionInfoFromTouch(touch: Touch, e: TouchEvent): PositionInfo {
    return { pageX: touch.pageX, pageY: touch.pageY, target: touch.target, originalEvent: e };
  }

  #handleMouseDown(positionInfo: PositionInfo): boolean {
    if (this.isMouseStarted) {
      this.#handleMouseUp(positionInfo);
    }
    this.mouseDownInfo = positionInfo;
    if (!this.#mouseCapture(positionInfo)) {
      return false;
    }
    this.#startMouseHandlers();
    return true;
  }

  #startMouseHandlers(): void {
    on(document, "mousemove", this.#onMouseMove);
    on(document, "touchmove", this.#onTouchMove);
    on(document, "mouseup", this.#onMouseUp);
    on(document, "touchend", this.#onTouchEnd);
    if (this.mouseDelay) {
      this.#startMouseDelayTimer();
    }
  }

  #startMouseDelayTimer(): void {
    if (this.mouseDelayTimer != null) {
      clearTimeout(this.mouseDelayTimer);
    }
    this.isMouseDelayMet = false;
    this.mouseDelayTimer = window.setTimeout(() => {
      this.isMouseDelayMet = true;
    }, this.mouseDelay);
  }

  #handleMouseMove(e: Event, positionInfo: PositionInfo): void {
    if (this.isMouseStarted) {
      this.#mouseDrag(positionInfo);
      e.preventDefault();
      return;
    }
    if (this.mouseDelay && !this.isMouseDelayMet) {
      return;
    }
    if (this.mouseDownInfo) {
      this.isMouseStarted = this.#mouseStart(this.mouseDownInfo);
    }
    if (this.isMouseStarted) {
      this.#mouseDrag(positionInfo);
    } else {
      this.#handleMouseUp(positionInfo);
    }
  }

  #handleMouseUp(positionInfo: PositionInfo): void {
    off(document, "mousemove", this.#onMouseMove);
    off(document, "touchmove", this.#onTouchMove);
    off(document, "mouseup", this.#onMouseUp);
    off(document, "touchend", this.#onTouchEnd);
    if (this.isMouseStarted) {
      this.isMouseStarted = false;
      this.#mouseStop(positionInfo);
    }
  }

  // ── Drag-and-drop handler (real source: lib/drag_and_drop_handler.js) ─

  #mouseCapture(positionInfo: PositionInfo): boolean {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real mouse/touch event's own target inside this tree is always an Element, never Text/Window/etc.
    const target = positionInfo.target as Element | null;
    if (target === null || target.matches("input,select,textarea")) {
      return false;
    }
    if (this.options.onIsMoveHandle && !this.options.onIsMoveHandle(target)) {
      return false;
    }
    const li = target.closest<HTMLLIElement>("li.jqtree_common");
    const node = li ? this.#findNodeByElement(li) : null;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
    if (node && this.options.onCanMove && !this.options.onCanMove(node as unknown as JqTreeNode<T>)) {
      this.currentItem = null;
      return false;
    }
    this.currentItem = node;
    return this.currentItem != null;
  }

  #findNodeByElement(li: HTMLLIElement): TreeNode | null {
    let found: TreeNode | null = null;
    this.root.iterate((node) => {
      if (node.element === li) {
        found = node;
        return false;
      }
      return true;
    });
    return found;
  }

  #mouseStart(positionInfo: PositionInfo): boolean {
    if (!this.currentItem || positionInfo.pageX === undefined || positionInfo.pageY === undefined) {
      return false;
    }
    this.#refreshHitAreas();
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real mouse/touch event's own target inside this tree is always an Element, never Text/Window/etc.
    const target = positionInfo.target as Element;
    const targetOffset = offset(target);
    const node = this.currentItem;
    const nodeName = this.options.autoEscape ? htmlEscape(node.name) : node.name;
    this.dragElement = createDragElement(
      nodeName,
      positionInfo.pageX - targetOffset.left,
      positionInfo.pageY - targetOffset.top,
      this.el,
    );
    this.isDragging = true;
    this.positionInfo = positionInfo;
    node.element?.classList.add("jqtree-moving");
    return true;
  }

  #applyMoveableHoveredArea(area: HitArea): void {
    if (!area.node.isFolder()) {
      this.#stopOpenFolderTimer();
    }
    if (this.hoveredArea !== area) {
      this.hoveredArea = area;
      if (JqTreeController.#mustOpenFolderTimer(area)) {
        this.#startOpenFolderTimer(area.node);
      } else {
        this.#stopOpenFolderTimer();
      }
      this.#updateDropHint();
    }
  }

  #clearHoveredArea(): void {
    this.hoveredArea = null;
    this.#removeDropHint();
    this.#stopOpenFolderTimer();
  }

  #mouseDrag(positionInfo: PositionInfo): boolean {
    if (!this.currentItem || !this.dragElement || positionInfo.pageX === undefined || positionInfo.pageY === undefined) {
      return false;
    }
    this.dragElement.move(positionInfo.pageX, positionInfo.pageY);
    this.positionInfo = positionInfo;
    const area = this.#findHoveredArea(positionInfo.pageX, positionInfo.pageY);
    const canMoveTo = this.#canMoveToArea(area);
    if (canMoveTo && area) {
      this.#applyMoveableHoveredArea(area);
    } else {
      this.#clearHoveredArea();
    }
    if (!area && this.options.onDragMove) {
      this.options.onDragMove(
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
        this.currentItem as unknown as JqTreeNode<T>,
        positionInfo.originalEvent instanceof MouseEvent
          ? positionInfo.originalEvent
          : // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- a real "touch*"-originated positionInfo always carries at least one changed touch.
            (positionInfo.originalEvent).changedTouches[0]!,
      );
    }
    this.#checkScrolling();
    return true;
  }

  #mouseStop(positionInfo: PositionInfo): boolean {
    this.#moveItem();
    // Real pre-existing bug found only by strict typechecking: this read
    // used to happen after the `this.hoveredArea = null` reset below,
    // making the `onDragStop`-vs-successful-move branch below always
    // take the "no hovered area" path -- captured before the reset now,
    // matching the same-purpose `currentItem` capture a few lines down.
    const hadHoveredArea = this.hoveredArea !== null;
    this.dragElement?.remove();
    this.dragElement = null;
    this.hoveredArea = null;
    this.#removeDropHint();
    this.hitAreas = [];
    const {currentItem} = this;
    currentItem?.element?.classList.remove("jqtree-moving");
    this.currentItem = null;
    this.isDragging = false;
    this.positionInfo = null;
    if (!hadHoveredArea && currentItem && this.options.onDragStop) {
      this.options.onDragStop(
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
        currentItem as unknown as JqTreeNode<T>,
        positionInfo.originalEvent instanceof MouseEvent
          ? positionInfo.originalEvent
          : // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- a real "touch*"-originated positionInfo always carries at least one changed touch.
            (positionInfo.originalEvent).changedTouches[0]!,
      );
    }
    return false;
  }

  #refreshHitAreas(): void {
    this.hitAreas = [];
    if (this.currentItem) {
      this.hitAreas = generateHitAreas(this.root, this.currentItem, this.#getTreeDimensions().bottom);
      if (this.isDragging) {
        this.currentItem.element?.classList.add("jqtree-moving");
      }
    }
  }

  #canMoveToArea(area: HitArea | null): boolean {
    if (!area || !this.currentItem) {
      return false;
    }
    if (this.options.onCanMoveTo) {
      return this.options.onCanMoveTo(
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
        this.currentItem as unknown as JqTreeNode<T>,
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
        area.node as unknown as JqTreeNode<T>,
        area.position,
      );
    }
    return true;
  }

  #removeDropHint(): void {
    this.previousGhost?.remove();
    this.previousGhost = null;
  }

  #findHoveredArea(x: number, y: number): HitArea | null {
    const dimensions = this.#getTreeDimensions();
    if (x < dimensions.left || y < dimensions.top || x > dimensions.right || y > dimensions.bottom) {
      return null;
    }
    let low = 0;
    let high = this.hitAreas.length;
    while (low < high) {
      const mid = (low + high) >> 1;
      const area = valueAt(this.hitAreas, mid);
      if (y < area.top) {
        high = mid;
      } else if (y > area.bottom) {
        low = mid + 1;
      } else {
        return area;
      }
    }
    return null;
  }

  static #mustOpenFolderTimer(area: HitArea): boolean {
    return area.node.isFolder() && !area.node.is_open && area.position === "inside";
  }

  #updateDropHint(): void {
    if (!this.hoveredArea) {
      return;
    }
    this.#removeDropHint();
    this.previousGhost = this.#addDropHint(this.hoveredArea.node, this.hoveredArea.position);
  }

  #startOpenFolderTimer(folder: TreeNode): void {
    this.#stopOpenFolderTimer();
    this.openFolderTimer = window.setTimeout(() => {
      this.#doOpenNode(folder, this.options.slide, () => {
        this.#refreshHitAreas();
        this.#updateDropHint();
      });
    }, this.options.openFolderDelay);
  }

  #stopOpenFolderTimer(): void {
    if (this.openFolderTimer != null) {
      clearTimeout(this.openFolderTimer);
      this.openFolderTimer = null;
    }
  }

  #moveItem(): void {
    if (
      !this.currentItem ||
      !this.hoveredArea ||
      this.hoveredArea.position === "none" ||
      !this.#canMoveToArea(this.hoveredArea)
    ) {
      return;
    }
    const movedNode = this.currentItem;
    const targetNode = this.hoveredArea.node;
    const {position} = this.hoveredArea;
    const previousParent = movedNode.parent;
    if (position === "inside") {
      targetNode.is_open = true;
    }
    const doMove = (): void => {
      moveNode(movedNode, targetNode, position);
      this.render(null);
    };
    const detail: JqTreeMoveInfo<T> = {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
      movedNode: movedNode as unknown as JqTreeNode<T>,
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
      targetNode: targetNode as unknown as JqTreeNode<T>,
      position,
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
      previousParent: previousParent as JqTreeNode<T> | null,
      doMove,
    };
    const event = new CustomEvent("tree.move", {
      bubbles: true,
      cancelable: true,
      detail,
    });
    this.el.dispatchEvent(event);
    if (!event.defaultPrevented) {
      doMove();
    }
  }

  #getTreeDimensions(): { left: number; top: number; right: number; bottom: number } {
    const treeOffset = offset(this.el);
    const treeWidth = width(this.el) || 0;
    const treeHeight = outerHeight(this.el) || 0;
    const left = treeOffset.left + this.#getScrollLeft();
    return {
      left,
      top: treeOffset.top,
      right: left + treeWidth,
      // A margin at the bottom, so a drop after the last element is
      // still reachable.
      bottom: treeOffset.top + treeHeight + 16,
    };
  }

  // ── Scroll-during-drag (real source: lib/scroll_handler.js) ──────────

  #ensureScrollInit(): void {
    if (this.scrollInitialized) {
      return;
    }
    if (getComputedStyle(this.el).position === "fixed") {
      this.scrollParent = null;
      this.scrollParentTop = 0;
    } else {
      const found = findScrollParent(this.el);
      if (found && found.tagName !== "HTML") {
        this.scrollParent = found;
        this.scrollParentTop = offset(found).top;
      } else {
        this.scrollParent = null;
        this.scrollParentTop = 0;
      }
    }
    this.scrollInitialized = true;
  }

  #getScrollLeft(): number {
    this.#ensureScrollInit();
    if (!this.scrollParent) {
      return 0;
    }
    return this.scrollParent.scrollLeft || 0;
  }

  #checkScrolling(): void {
    this.#ensureScrollInit();
    this.#checkVerticalScrolling();
    this.#checkHorizontalScrolling();
  }

  #checkVerticalScrolling(): void {
    const area = this.hoveredArea;
    if (!area || area.top === this.previousScrollTop) {
      return;
    }
    this.previousScrollTop = area.top;
    if (this.scrollParent) {
      const {scrollParent} = this;
      const distanceBottom =
        this.scrollParentTop + scrollParent.offsetHeight - area.bottom;
      if (distanceBottom < 20) {
        scrollParent.scrollTop += 20;
        this.#refreshHitAreas();
        this.previousScrollTop = -1;
      } else if (area.top - this.scrollParentTop < 20) {
        scrollParent.scrollTop -= 20;
        this.#refreshHitAreas();
        this.previousScrollTop = -1;
      }
    } else {
      const scrollTop = window.scrollY;
      const distanceTop = area.top - scrollTop;
      if (distanceTop < 20) {
        window.scrollTo(window.scrollX, scrollTop - 20);
      } else if (windowHeight() - (area.bottom - scrollTop) < 20) {
        window.scrollTo(window.scrollX, scrollTop + 20);
      }
    }
  }

  #checkHorizontalScrolling(): void {
    const {positionInfo} = this;
    if (positionInfo?.pageX === undefined || positionInfo.pageY === undefined) {
      return;
    }
    if (this.scrollParent) {
      const {scrollParent} = this;
      const scrollParentOffset = offset(scrollParent);
      const canScrollRight = scrollParent.scrollLeft + scrollParent.clientWidth < scrollParent.scrollWidth;
      const canScrollLeft = scrollParent.scrollLeft > 0;
      const rightEdge = scrollParentOffset.left + scrollParent.clientWidth;
      const leftEdge = scrollParentOffset.left;
      if (positionInfo.pageX > rightEdge - 20 && canScrollRight) {
        scrollParent.scrollLeft = Math.min(scrollParent.scrollLeft + 20, scrollParent.scrollWidth);
      } else if (positionInfo.pageX < leftEdge + 20 && canScrollLeft) {
        scrollParent.scrollLeft = Math.max(scrollParent.scrollLeft - 20, 0);
      }
    } else {
      const scrollLeft = window.scrollX;
      const canScrollLeft = scrollLeft > 0;
      const documentWidth = windowWidth();
      if (positionInfo.pageX > documentWidth - 20) {
        window.scrollTo(scrollLeft + 20, window.scrollY);
      } else if (positionInfo.pageX - scrollLeft < 20 && canScrollLeft) {
        window.scrollTo(Math.max(scrollLeft - 20, 0), window.scrollY);
      }
    }
  }
}

interface PositionInfo {
  pageX: number | undefined;
  pageY: number | undefined;
  target: EventTarget | null;
  originalEvent: MouseEvent | TouchEvent;
}

interface HitArea {
  top: number;
  bottom: number;
  node: TreeNode;
  position: JqTreePosition;
}

interface DragElementHandle {
  move(pageX: number, pageY: number): void;
  remove(): void;
}

function setPageOffset(el: HTMLElement, desired: { top: number; left: number }): void {
  const current = offset(el);
  const curTop = Number.parseFloat(el.style.top) || 0;
  const curLeft = Number.parseFloat(el.style.left) || 0;
  el.style.top = `${curTop + (desired.top - current.top)}px`;
  el.style.left = `${curLeft + (desired.left - current.left)}px`;
}

function createDragElement(
  nodeNameHtml: string,
  offsetX: number,
  offsetY: number,
  container: HTMLElement,
): DragElementHandle {
  const el = document.createElement("span");
  el.className = "jqtree-title jqtree-dragging";
  el.innerHTML = nodeNameHtml;
  el.style.position = "absolute";
  container.appendChild(el);
  return {
    move(pageX: number, pageY: number): void {
      setPageOffset(el, { left: pageX - offsetX, top: pageY - offsetY });
    },
    remove(): void {
      el.remove();
    },
  };
}

function findScrollParent(el: HTMLElement): HTMLElement | null {
  const hasOverflow = (candidate: Element): boolean => {
    const style = getComputedStyle(candidate);
    return (
      style.overflow === "auto" ||
      style.overflow === "scroll" ||
      style.overflowY === "auto" ||
      style.overflowY === "scroll"
    );
  };
  if (hasOverflow(el)) {
    return el;
  }
  let parent = el.parentElement;
  while (parent) {
    if (hasOverflow(parent)) {
      return parent;
    }
    parent = parent.parentElement;
  }
  return null;
}

// ── Hit-area generation (real source: lib/drag_and_drop_handler.js's own
// VisibleNodeIterator/HitAreasGenerator) -- ported literally, including
// its exact grouping arithmetic (a group is flushed using `previousTop`
// as its top edge, which is only updated on a flush, not on every push);
// this is faithful to the upstream algorithm as shipped, not something to
// "fix" while translating. ────────────────────────────────────────────

function isElementVisible(el: HTMLElement): boolean {
  return Boolean(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
}

interface VisibleNodeHandlers {
  handleFirstNode(node: TreeNode): void;
  handleOpenFolder(node: TreeNode, el: HTMLLIElement): boolean;
  handleClosedFolder(node: TreeNode, nextNode: TreeNode | null, el: HTMLLIElement): void;
  handleNode(node: TreeNode, nextNode: TreeNode | null, el: HTMLLIElement): void;
  handleAfterOpenFolder(node: TreeNode, nextNode: TreeNode | null): void;
}

/**
 * The `node.element` branch of `iterateVisibleNodes()`'s own recursive
 * `iterateNode()` below, extracted to keep that closure's own cognitive
 * complexity under the sonarjs limit -- a pure, faithful extraction (same
 * exact control flow), not a behavior change.
 *
 * @returns `"skip"` when the node's own element isn't visible (the
 *   original `return;` out of `iterateNode()` entirely, so children are
 *   never visited either); otherwise whether `mustIterateInside` (computed
 *   by the caller, before this runs) should be forced to `false` (the one
 *   real case: an open folder whose own `handleOpenFolder()` returns
 *   `false`), or left as the caller's own value.
 */
function handleNodeElement(
  node: TreeNode,
  nextNode: TreeNode | null,
  el: HTMLLIElement,
  isFirstNode: boolean,
  handlers: VisibleNodeHandlers,
): "skip" | { forceMustIterateInsideFalse: boolean } {
  if (!isElementVisible(el)) {
    return "skip";
  }
  if (isFirstNode) {
    handlers.handleFirstNode(node);
  }
  if (!node.hasChildren()) {
    handlers.handleNode(node, nextNode, el);
    return { forceMustIterateInsideFalse: false };
  }
  if (node.is_open) {
    return { forceMustIterateInsideFalse: !handlers.handleOpenFolder(node, el) };
  }
  handlers.handleClosedFolder(node, nextNode, el);
  return { forceMustIterateInsideFalse: false };
}

function iterateVisibleNodes(
  root: TreeNode,
  _currentNode: TreeNode,
  handlers: VisibleNodeHandlers,
): void {
  let isFirstNode = true;
  const iterateNode = (node: TreeNode, nextNode: TreeNode | null): void => {
    let mustIterateInside = (node.is_open || !node.element) && node.hasChildren();
    if (node.element) {
      const result = handleNodeElement(node, nextNode, node.element, isFirstNode, handlers);
      if (result === "skip") {
        return;
      }
      isFirstNode = false;
      if (result.forceMustIterateInsideFalse) {
        mustIterateInside = false;
      }
    }
    if (mustIterateInside) {
      const len = node.children.length;
      node.children.forEach((child, i) => {
        iterateNode(child, i === len - 1 ? null : (node.children[i + 1] ?? null));
      });
      if (node.is_open && node.element) {
        handlers.handleAfterOpenFolder(node, nextNode);
      }
    }
  };
  iterateNode(root, null);
}

function generateHitAreas(
  root: TreeNode,
  currentNode: TreeNode,
  treeBottom: number,
): HitArea[] {
  const positions: { top: number; node: TreeNode; position: JqTreePosition }[] = [];
  let lastTop = 0;
  const addPosition = (node: TreeNode, position: JqTreePosition, top: number): void => {
    positions.push({ top, node, position });
    lastTop = top;
  };
  const getTop = (el: HTMLLIElement): number => offset(el).top;

  iterateVisibleNodes(root, currentNode, {
    handleFirstNode(node) {
      if (node !== currentNode) {
        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- iterateVisibleNodes() only ever visits already-rendered nodes, which always have a real element.
        addPosition(node, "before", getTop(node.element!));
      }
    },
    handleOpenFolder(node, el) {
      if (node === currentNode) {
        return false;
      }
      if (node.children[0] !== currentNode) {
        addPosition(node, "inside", getTop(el));
      }
      return true;
    },
    handleClosedFolder(node, nextNode, el) {
      const top = getTop(el);
      if (node === currentNode) {
        addPosition(node, "none", top);
      } else {
        addPosition(node, "inside", top);
        if (nextNode !== currentNode) {
          addPosition(node, "after", top);
        }
      }
    },
    handleAfterOpenFolder(node, nextNode) {
      if (node === currentNode || nextNode === currentNode) {
        addPosition(node, "none", lastTop);
      } else {
        addPosition(node, "after", lastTop);
      }
    },
    handleNode(node, nextNode, el) {
      const top = getTop(el);
      if (node === currentNode) {
        addPosition(node, "none", top);
      } else {
        addPosition(node, "inside", top);
      }
      if (nextNode === currentNode || node === currentNode) {
        addPosition(node, "none", top);
      } else {
        addPosition(node, "after", top);
      }
    },
  });

  let previousTop = -1;
  let group: typeof positions = [];
  const hitAreas: HitArea[] = [];
  const flushGroup = (top: number, bottom: number): void => {
    const positionCount = Math.min(group.length, 4);
    const areaHeight = Math.round((bottom - top) / positionCount);
    let areaTop = top;
    for (const position of group.slice(0, positionCount)) {
      hitAreas.push({
        top: areaTop,
        bottom: areaTop + areaHeight,
        node: position.node,
        position: position.position,
      });
      areaTop += areaHeight;
    }
  };
  for (const position of positions) {
    if (position.top !== previousTop && group.length) {
      flushGroup(previousTop, position.top);
      previousTop = position.top;
      group = [];
    }
    group.push(position);
  }
  flushGroup(previousTop, treeBottom);
  return hitAreas;
}

// ── Public API ────────────────────────────────────────────────────────

const instances = new WeakMap<Element, JqTreeController<never>>();

/** `jQuery(el).tree(options)`. */
export function tree<T extends Record<string, unknown>>(
  el: HTMLElement,
  options: JqTreeOptions<T>,
): JqTreeInstance<T> {
  const controller = new JqTreeController<T>(el, options);
  // Registered before `init()`: that call synchronously renders the tree
  // and invokes the real `onCreateLi` for every node, and the one real
  // consumer's own callback reads the instance straight back via
  // `getTreeInstance()` -- it must already be resolvable mid-init.
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
  instances.set(el, controller as unknown as JqTreeController<never>);
  controller.init();
  return asInstance(controller);
}

/**
 * `jQuery(el).tree("getNodeById", ...)`/etc -- the instance stashed by
 * `tree()` above, for a call site in a different function/file than the
 * one that initialized it (same WeakMap-instance-lookup pattern as
 * `vendor/widgets/selectize.ts`/`vendor/widgets/slider.ts`, since the original library
 * stashes its instance directly on the DOM element and every real call
 * site reads it back that way).
 */
export function getTreeInstance<T extends Record<string, unknown>>(
  el: Element,
): JqTreeInstance<T> | undefined {
  const controller = instances.get(el);
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
  return controller ? asInstance(controller as unknown as JqTreeController<T>) : undefined;
}

function asInstance<T extends Record<string, unknown>>(
  controller: JqTreeController<T>,
): JqTreeInstance<T> {
  return {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
    getNodeById: (id) => controller.getNodeById(id) as JqTreeNode<T> | null,
    getState: () => controller.getState(),
    openNode: (node, slide, onFinished) =>
      { controller.openNode(
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
        node as unknown as TreeNode,
        slide,
        onFinished
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
          ? (n) => { onFinished(n as unknown as JqTreeNode<T>); }
          : undefined,
      ); },
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
    closeNode: (node, slide) => { controller.closeNode(node as unknown as TreeNode, slide); },
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
    updateNode: (node, data) => { controller.updateNode(node as unknown as TreeNode, data); },
    appendNode: (newNodeInfo, parentNode) =>
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
      controller.appendNode(
        newNodeInfo,
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
        parentNode as TreeNode | undefined,
      ) as unknown as JqTreeNode<T>,
    prependNode: (newNodeInfo, parentNode) =>
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
      controller.prependNode(
        newNodeInfo,
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
        parentNode as TreeNode | undefined,
      ) as unknown as JqTreeNode<T>,
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
    removeNode: (node) => { controller.removeNode(node as unknown as TreeNode); },
    loadData: (data, parentNode) =>
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- see JqTreeNode's own leading comment.
      { controller.loadData(data, parentNode as unknown as TreeNode); },
  };
}
