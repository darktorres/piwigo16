import type { operations } from "../../../../openapi/client/schema";
// common.ts's own side effects (font-checkbox init, search-cancel
// bindings) -- albums.latte's own `.font-checkbox` multi-view-selector
// radios need fontCheckbox() to run. This page used to get that
// incidentally, as a side effect of importing jConfirmConfirmOptions
// from what was then the same file (common.ts); the P51-I split made
// that dependency explicit instead of leaving it accidental.
import "./common";
import { jConfirmConfirmOptions } from "./jconfirmPresets";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/pageData";
import { ajax, AjaxError } from "../../../default/js/vendor/utils/ajax";
import { confirm } from "../../../default/js/vendor/widgets/jconfirm";
import {
  getTreeInstance,
  tree,
  type JqTreeInstance,
  type JqTreeMoveInfo,
  type JqTreeNode,
} from "../../../default/js/vendor/widgets/jqtree";
import {
  addClass,
  after,
  append,
  attr,
  attrOf,
  dataId,
  delegate,
  escapeId,
  fadeIn,
  fadeOut,
  find,
  hide,
  html,
  off,
  offset,
  on,
  outerHeight,
  ready,
  removeClass,
  resolveDuration,
  setData,
  setVal,
  show,
  swing,
  text,
  val,
  windowHeight,
} from "../../../default/js/vendor/utils/dom";
import { tipTip } from "../../../default/js/vendor/widgets/tiptip";

// `vendor/widgets/jqtree.ts`'s own real per-row shape (P49-B group 6B), traced to
// AlbumsPageRenderer.php's own `assocToOrderedTree()` -- the raw JSON
// tree fed into `tree(el, {data: ...})` and returned by `pwg_getPageData
// ("album_data")`, *before* jqtree wraps each row into a live node.
// `load_on_demand`/`haveChildren` are client-side-only fields albums.ts
// itself adds when reshaping a row for lazy loading -- never present in
// the raw PHP payload.
interface AlbumTreeNode extends Record<string, unknown> {
  id: string;
  rank: string | number | null;
  name: string;
  status: string;
  visible: string;
  uppercats: string;
  nb_images: number;
  last_updates: string;
  has_not_access: boolean;
  nb_sub_photos: number;
  nb_subcats?: number;
  children?: AlbumTreeNode[];
  load_on_demand?: boolean;
  haveChildren?: AlbumTreeNode[];
}

// The extra, real per-node data `vendor/widgets/jqtree.ts`'s generic node type
// carries alongside its own base fields (`.id`/`.parent`/`.children`/
// `.getLevel()`/...) -- every own-property of the raw `AlbumTreeNode`
// data becomes a real property on the resulting live node, simultaneously
// with the base shape.
interface AlbumNodeData extends Record<string, unknown> {
  rank: string | number | null;
  status: string;
  visible: string;
  uppercats: string;
  nb_images: number;
  last_updates: string;
  has_not_access: boolean;
  nb_sub_photos: number;
  nb_subcats?: number;
  haveChildren?: AlbumTreeNode[];
}
type AlbumJqTreeNode = JqTreeNode<AlbumNodeData>;

// Named `albumData` internally, not `data` -- this file no longer
// imports dom.ts's own `data()` helper (P51-D: every call site
// switched to `dataId()`), but kept off that name anyway to avoid
// re-shadowing it if a future edit needs it back. Re-exported as
// `data` below (its real, external name; categories/search.ts imports it as
// such, docs/PLAN.md P48) to keep that contract unchanged.
const albumData = pwg_getPageData<AlbumTreeNode[]>("album_data");
export { albumData as data };
const pwgToken = pwg_getPageData<string>("csrf_token");
const strAreYouSure = pwg_getPageString(
  "The status of the album '%s' and its sub-albums will change to private. Are you sure?",
);
const strYesChangeParent = pwg_getPageString("Yes change parent anyway");
const strNoChangeParent = pwg_getPageString("No, don't move this album here");
const strRoot = pwg_getPageString("Root");
const openCat = pwg_getPageData<string>("open_cat");
let nbAlbums = pwg_getPageData<number>("nb_albums");
const lightAlbumManager = pwg_getPageData<number>("light_album_manager");

const xNbSubcats = pwg_getPageString("%d sub-albums");
const xNbImages = pwg_getPageString("%d photos");
const xNbSubPhotos = pwg_getPageString("%d pictures in sub-albums");

// Not `str_albums_found`/`str_result_limit`/`str_album_found` too --
// AlbumsView.php's own exposedStrings() carries all 3, but the real
// reader is categories/search.ts, which imports them from album_selector.ts's
// own real exports instead (categories/search.ts's own leading comment: a
// genuine, coincidental same-wording duplicate, not the semantically
// "correct" owner). This file's own former local `const str_album_found`
// copy was real dead code -- confirmed zero consumers anywhere, `.ts`
// or `.latte` -- and was removed outright rather than exported to
// nothing (docs/PLAN.md P48, common.ts's own batch already established
// this same precedent for `array_delete`).
const strAlbsDragDrop = pwg_getPageString("Drag and drop to reorder albums");

const delayAutoOpen = pwg_getPageData<number>("delay_auto_open");

// Assigned once, at the top of `ready()` below, before the tree is
// initialized -- every other module-level function reads the live
// instance back through `getAlbumTree()` (same WeakMap-instance-lookup
// pattern `vendor/widgets/jqtree.ts` documents), matching the original's own
// repeated `jQuery(".tree").tree(...)` re-selection at each call site.
let treeEl: HTMLElement;
function getAlbumTree(): JqTreeInstance<AlbumNodeData> {
  return getTreeInstance<AlbumNodeData>(treeEl)!;
}

// Genuine pre-existing scope bug found only by real strict typechecking
// (invisible to non-strict JS): the tree click/`tree.open`/`tree.close`
// handlers below read these two bare, but the original code only ever
// declared them *inside* createAlbumNode()'s own local scope further
// down -- a real ReferenceError at runtime whenever one of those
// handlers actually ran. Fixed by hoisting to module scope (both are
// pure static strings, no dependency on createAlbumNode's own
// per-call `node`/`li` params) rather than just suppressing the type
// error.
const togglerClose = "<span class='icon-left-open'></span>";
const togglerOpen = "<span class='icon-down-open'></span>";

const deleteAlbumWithName = pwg_getPageString('Delete album "%s".');
const deleteAlbumWithSubs = pwg_getPageString(
  'Delete album "%s" and its %d sub-albums.',
);
const hasImagesAssociatedOutside = pwg_getPageString(
  "delete album and all %d photos, even the %d associated to other albums",
);
const hasImagesBecommingOrphans = pwg_getPageString(
  "delete album and the %d orphan photos",
);
const renameItem = pwg_getPageString('Rename "%s"');

const strAddAlbum = pwg_getPageString("Add Album");
const strEditAlbum = pwg_getPageString("Edit album");
const strAddPhoto = pwg_getPageString("Add Photos");
const strVisitGallery = pwg_getPageString("Visit Gallery");
const strSortOrder = pwg_getPageString("Automatic sort order");
const strDeleteAlbum = pwg_getPageString("Delete album");
const strRootOrder = pwg_getPageString("Apply to root albums");
const strSubAlbumOrder = pwg_getPageString("Apply to direct sub-albums");
const strAlbumNameEmpty = pwg_getPageString("Album name must not be empty");

const addAlbumRootTitle = pwg_getPageString("Create a new album at root");
const addSubAlbumOf = pwg_getPageString('Create a sub-album of "%s"');
const tiptipLockedAlbum = pwg_getPageString("Locked album");

// jQuery's default easing (`effects/Tween.js`) and speed-name table, both
// re-exported by dom.ts -- reused here rather than duplicated, since
// `scrollTop` is a DOM property, not a CSS one, and dom.ts's own
// animate() only ever writes to `el.style`.
function animateScrollTop(targetTop: number, duration?: number | string): void {
  const ms = resolveDuration(duration);
  const startTop = window.scrollY;
  const delta = targetTop - startTop;
  if (ms <= 0) {
    window.scrollTo(0, targetTop);
    return;
  }
  const startTime = performance.now();
  const step = (now: number): void => {
    const fraction = Math.min(1, (now - startTime) / ms);
    window.scrollTo(0, startTop + delta * swing(fraction));
    if (fraction < 1) {
      requestAnimationFrame(step);
    }
  };
  requestAnimationFrame(step);
}

// Rebinds the .move-cat-add/.move-cat-delete/.move-cat-title-container
// click handlers -- called after every tree mutation (add/delete/move/
// load-on-demand), same as the original's own repeated off()-then-on()
// blocks at each of those call sites. Broad on purpose: every call
// rebinds every matching element in the whole tree, not just the one a
// mutation just affected -- matching the original jQuery's own
// equally-broad `$(".move-cat-add").off("click").on(...)` pattern
// verbatim, not something to narrow while translating.
function rebindMoveCatActions(): void {
  off(document.querySelectorAll(".move-cat-add"), "click");
  on(
    document.querySelectorAll(".move-cat-add"),
    "click",
    function (this: Element, e: Event) {
      e.preventDefault();
      const aid = dataId(this, "aid");
      openAddAlbumPopIn(aid);
      setData(document.querySelector(".AddAlbumSubmit")!, "a-parent", aid);
    },
  );
  off(document.querySelectorAll(".move-cat-delete"), "click");
  on(
    document.querySelectorAll(".move-cat-delete"),
    "click",
    function (this: Element) {
      void triggerDeleteAlbum(dataId(this, "id"));
    },
  );
  off(document.querySelectorAll(".move-cat-title-container"), "click");
  on(
    document.querySelectorAll(".move-cat-title-container"),
    "click",
    function (this: Element) {
      openRenameAlbumPopIn(
        attrOf(find(this, ".move-cat-title"), "title") ?? undefined,
      );
      setData(
        document.querySelector(".RenameAlbumSubmit")!,
        "cat_id",
        Number(attrOf(this, "data-id")),
      );
    },
  );
  tipTip(document.querySelectorAll(".tiptip"), {
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
    edgeOffset: 3,
  });
}

ready(() => {
  const closePopin = document.querySelectorAll(
    ".cat-move-order-popin .close-popin",
  );
  on(closePopin, "click", function () {
    fadeOut(document.querySelectorAll(".cat-move-order-popin"));
  });

  const openUppercats =
    openCat === "-1"
      ? []
      : findAlbumById(albumData, openCat)!.uppercats.split(",");
  const newData = albumData.map((a: AlbumTreeNode) => {
    const al: AlbumTreeNode = {
      ...a,
      children: openUppercats.includes(a.id) ? (a.children ?? []) : [],
    };
    if (a.children) {
      al.load_on_demand = openUppercats.includes(a.id) ? false : true;
      al.haveChildren = a.children;
    }
    return al;
  });

  document
    .querySelector("h1")
    ?.insertAdjacentHTML(
      "beforeend",
      `<span class='badge-number'>` + String(nbAlbums) + `</span>`,
    );

  treeEl = document.querySelector(".tree")!;

  // Real init options only (P49-B group 6B): `autoOpen`/`onCanSelectNode`
  // are dropped from `vendor/widgets/jqtree.ts`'s own options surface entirely,
  // since this is the library's one real consumer and it always passes
  // `autoOpen: false` (no restore-from-storage, no auto-expand) and
  // permanently disables node selection -- see that module's own header
  // comment for the full narrowing rationale.
  tree<AlbumNodeData>(treeEl, {
    data: newData,
    dragAndDrop: true,
    openFolderDelay: delayAutoOpen,
    onCreateLi: createAlbumNode,
  });

  delegate(treeEl, "click", ".move-cat-toogler", function (this: Element) {
    const nodeId = attrOf(this, "data-id");
    const node = getAlbumTree().getNodeById(nodeId);

    if (node) {
      if (node.load_on_demand && node.haveChildren) {
        loadOnDemand(node);
      }

      const { open_nodes: openNodes } = getAlbumTree().getState();
      if (!openNodes.includes(node.id!)) {
        html(this, togglerOpen);
        getAlbumTree().openNode(node);
        // reset event here:
        rebindMoveCatActions();
      } else {
        html(this, togglerClose);
        getAlbumTree().closeNode(node);
      }
    }
  });

  on(treeEl, "tree.open", function (e: Event) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "tree.open" is jqtree.ts's own dispatchEvent(), always a real CustomEvent with this detail shape; on()'s own handler param is typed generically via the native EventListener interface.
    const { node } = (e as CustomEvent<{ node: AlbumJqTreeNode }>).detail;
    html(
      document.querySelectorAll(
        '.move-cat-toogler[data-id="' + String(node.id) + '"]',
      ),
      togglerOpen,
    );
  });

  on(treeEl, "tree.close", function (e: Event) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "tree.close" is jqtree.ts's own dispatchEvent(), always a real CustomEvent with this detail shape; on()'s own handler param is typed generically via the native EventListener interface.
    const { node } = (e as CustomEvent<{ node: AlbumJqTreeNode }>).detail;
    html(
      document.querySelectorAll(
        '.move-cat-toogler[data-id="' + String(node.id) + '"]',
      ),
      togglerClose,
    );
  });

  on(treeEl, "tree.move", function (e: Event) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "tree.move" is jqtree.ts's own dispatchEvent(), always a real CustomEvent with this detail shape; on()'s own handler param is typed generically via the native EventListener interface.
    const event = e as CustomEvent<JqTreeMoveInfo<AlbumNodeData>>;
    event.preventDefault();
    const moveInfo = event.detail;

    if (moveInfo.movedNode.status !== "private") {
      let parentIsPrivate = false;
      if (moveInfo.position === "after") {
        // Non-null: a same-level "after" move always has a real parent
        // in this tree (there is no top-level move target with a null
        // parent in practice) -- same unguarded assumption the
        // pre-P47 `any`-typed code already made.
        parentIsPrivate = moveInfo.targetNode.parent!.status === "private";
      } else if (moveInfo.position === "inside") {
        parentIsPrivate = moveInfo.targetNode.status === "private";
      }

      if (parentIsPrivate) {
        confirm({
          title: strAreYouSure.replace(/%s/g, moveInfo.movedNode.name),
          buttons: {
            confirm: {
              text: strYesChangeParent,
              btnClass: "btn-red",
              action: function () {
                makePrivateHierarchy(moveInfo.movedNode);
                applyMove(moveInfo);
              },
            },
            cancel: {
              text: strNoChangeParent,
            },
          },
          ...jConfirmConfirmOptions,
        });
      } else {
        applyMove(moveInfo);
      }
    } else {
      applyMove(moveInfo);
    }
  });

  delegate(treeEl, "click", ".move-cat-order", function (this: Element) {
    const nodeId = attrOf(this, "data-id");
    const node = getAlbumTree().getNodeById(nodeId);
    if (node) {
      fadeIn(document.querySelectorAll(".cat-move-order-popin"));
      html(
        document.querySelectorAll(".cat-move-order-popin .album-name"),
        getPathNode(node),
      );
      setVal(
        document.querySelectorAll(".cat-move-order-popin input[name=id]"),
        nodeId!,
      );
      attr(
        document.querySelectorAll("input[name=simpleAutoOrder]"),
        "value",
        strSubAlbumOrder,
      );
    }
  });

  on(document.querySelectorAll(".order-root"), "click", function () {
    fadeIn(document.querySelectorAll(".cat-move-order-popin"));
    html(
      document.querySelectorAll(".cat-move-order-popin .album-name"),
      strRoot,
    );
    setVal(
      document.querySelectorAll(".cat-move-order-popin input[name=id]"),
      "-1",
    );
    attr(
      document.querySelectorAll("input[name=simpleAutoOrder]"),
      "value",
      strRootOrder,
    );
  });

  on(treeEl, "mousedown mouseup", function mouseState(e: Event) {
    if (e.type === "mousedown") {
      addClass(treeEl, "dragging");
    } else if (e.type === "mouseup") {
      removeClass(document.querySelectorAll(".dragging"), "dragging");
    }
  });

  if (openCat !== "-1") {
    // Non-null: `openCat`, when not the "-1" sentinel, is always a real
    // album id present in this tree (same unguarded assumption the
    // pre-P47 `any`-typed code already made).
    const nodeToGo = getAlbumTree().getNodeById(openCat)!;

    goToNode(nodeToGo, nodeToGo);
    if (nodeToGo.children.length > 0) {
      getAlbumTree().openNode(nodeToGo, false);
    }

    const target = document.querySelector<HTMLElement>(
      "#" + escapeId("cat-" + openCat),
    )!;
    animateScrollTop(
      offset(target).top - windowHeight() / 2 + outerHeight(target) / 2,
      500,
    );
  }

  // RenameAlbumPopIn
  hide(document.querySelectorAll(".RenameAlbumErrors"));
  delegate(
    document,
    "click",
    ".move-cat-title-container",
    function (this: Element) {
      openRenameAlbumPopIn(
        attrOf(find(this, ".move-cat-title"), "title") ?? undefined,
      );
      setData(
        document.querySelector(".RenameAlbumSubmit")!,
        "cat_id",
        Number(attrOf(this, "data-id")),
      );
    },
  );
  on(document.querySelectorAll(".CloseRenameAlbum"), "click", function () {
    closeRenameAlbumPopIn();
  });
  on(document.querySelectorAll(".RenameAlbumCancel"), "click", function () {
    closeRenameAlbumPopIn();
  });

  on(
    document.querySelectorAll(".RenameAlbumSubmit"),
    "click",
    function (this: Element) {
      const catToEdit = dataId(this, "cat_id");
      void (async () => {
        try {
          await ajax({
            url: "api/v1/categories/" + String(catToEdit),
            type: "PATCH",
            json: {
              name: val(
                document.querySelectorAll(".RenameAlbumLabelUsername input"),
              ),
            },
            headers: { "X-CSRF-Token": pwgToken },
            dataType: "json",
          });

          const nodeId = attrOf(
            find(
              document.querySelectorAll(
                "#" + escapeId("cat-" + String(catToEdit)),
              ),
              ".move-cat-toogler",
            ),
            "data-id",
          );
          const node = getAlbumTree().getNodeById(nodeId)!;
          node.name = String(
            val(document.querySelectorAll(".RenameAlbumLabelUsername input")),
          );
          getAlbumTree().updateNode(
            node,
            val(document.querySelectorAll(".RenameAlbumLabelUsername input")),
          );

          rebindMoveCatActions();

          closeRenameAlbumPopIn();
        } catch (e) {
          console.error(e instanceof AjaxError ? e.responseText : e);
        }
      })();
    },
  );

  // AddAlbumPopIn
  hide(document.querySelectorAll(".AddAlbumErrors"));
  hide(document.querySelectorAll(".DeleteAlbumErrors"));
  on(document.querySelectorAll(".add-album-button"), "click", function () {
    openAddAlbumPopIn(0);
    setData(document.querySelector(".AddAlbumSubmit")!, "a-parent", 0);
  });
  delegate(
    document,
    "click",
    ".move-cat-add",
    function (this: Element, e: Event) {
      e.preventDefault();
      const aid = dataId(this, "aid");
      openAddAlbumPopIn(aid);
      setData(document.querySelector(".AddAlbumSubmit")!, "a-parent", aid);
    },
  );
  on(document.querySelectorAll(".CloseAddAlbum"), "click", function () {
    closeAddAlbumPopIn();
  });
  on(document.querySelectorAll(".AddAlbumCancel"), "click", function () {
    closeAddAlbumPopIn();
  });
  on(document.querySelectorAll(".DeleteAlbumCancel"), "click", function () {
    closeDeleteAlbumPopIn();
  });

  on(
    document.querySelectorAll(".AddAlbumSubmit"),
    "click",
    function (this: Element) {
      addClass(this, "notClickable");

      const newAlbumName = val(
        document.querySelectorAll(".AddAlbumLabelUsername input"),
      );
      const newAlbumParent = dataId(
        document.querySelector(".AddAlbumSubmit")!,
        "a-parent",
      );
      const newAlbumPosition = val(
        document.querySelectorAll("input[name=position]:checked"),
      );

      void (async () => {
        try {
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
          const response = (await ajax({
            url: "api/v1/categories",
            type: "POST",
            json: {
              name: newAlbumName,
              parentId: newAlbumParent,
              position: newAlbumPosition,
            },
            headers: { "X-CSRF-Token": pwgToken },
            dataType: "json",
          })) as operations["categoryCreate"]["responses"][201]["content"]["application/json"];

          const parentNode = getAlbumTree().getNodeById(newAlbumParent);
          if (
            parentNode &&
            parentNode.load_on_demand &&
            parentNode.haveChildren
          ) {
            loadOnDemand(parentNode);
          }
          if (parentNode) openNodeOnDemand(parentNode);

          if (newAlbumPosition === "last") {
            getAlbumTree().appendNode(
              {
                id: response.id,
                isEmptyFolder: true,
                // Non-null: a required admin form field, always populated
                // by the time this AJAX success handler runs.
                name: newAlbumName!,
              },
              parentNode ?? undefined,
            );
          } else {
            getAlbumTree().prependNode(
              {
                id: response.id,
                isEmptyFolder: true,
                // Non-null: a required admin form field, always populated
                // by the time this AJAX success handler runs.
                name: newAlbumName!,
              },
              parentNode ?? undefined,
            );
          }

          if (parentNode) {
            setSubcatsBadge(parentNode);

            delegate(
              document.querySelectorAll(
                "#" + escapeId("cat-" + String(parentNode.id)),
              ),
              "click",
              ".move-cat-toogler",
              function (this: Element) {
                const nodeId = parentNode.id;
                const node = getAlbumTree().getNodeById(nodeId);
                if (node) {
                  const { open_nodes: openNodes } = getAlbumTree().getState();
                  if (!openNodes.includes(nodeId!)) {
                    html(this, togglerOpen);
                    getAlbumTree().openNode(node);
                  } else {
                    html(this, togglerClose);
                    getAlbumTree().closeNode(node);
                  }
                }
              },
            );
          }

          rebindMoveCatActions();

          updateTitleBadge(nbAlbums + 1);

          goToNode(
            getAlbumTree().getNodeById(response.id)!,
            getAlbumTree().getNodeById(response.id)!,
          );
          animateScrollTop(
            offset(
              document.querySelector(
                "#" + escapeId("cat-" + String(response.id)),
              )!,
            ).top -
              screen.height / 2,
            "slow",
          );

          closeAddAlbumPopIn();
          removeClass(
            document.querySelectorAll(".AddAlbumSubmit"),
            "notClickable",
          );
        } catch (e) {
          console.error(e instanceof AjaxError ? e.responseText : e);
          text(document.querySelectorAll(".AddAlbumErrors"), strAlbumNameEmpty);
          show(document.querySelectorAll(".AddAlbumErrors"));
          removeClass(
            document.querySelectorAll(".AddAlbumSubmit"),
            "notClickable",
          );
        }
      })();
    },
  );

  // Delete Album
  on(
    document.querySelectorAll(".move-cat-delete"),
    "click",
    function (this: Element) {
      void triggerDeleteAlbum(dataId(this, "id"));
    },
  );

  off(document.querySelectorAll(".user-list-checkbox"), "change");
  on(
    document.querySelectorAll(".user-list-checkbox"),
    "change",
    checkboxChange,
  );
  off(document.querySelectorAll(".user-list-checkbox"), "click");
  on(document.querySelectorAll(".user-list-checkbox"), "click", checkboxClick);

  if (!lightAlbumManager) {
    tipTip(document.querySelectorAll(".tiptip"), {
      delay: 0,
      fadeIn: 200,
      fadeOut: 200,
      edgeOffset: 3,
    });
  }
});

function createAlbumNode(node: AlbumJqTreeNode, liEl: HTMLLIElement) {
  const icon = "<span class='%icon%'></span>";
  let title =
    '<span data-id="' + String(node.id) + '" class="move-cat-title-container ';
  // Non-null: every real album node's `.parent` is either a real
  // parent album, or jqtree's own invisible tree root (never a bare
  // `null` for a node actually handed to `onCreateLi`) -- same
  // assumption `getId()` further down already makes explicitly via
  // `parent.getLevel()`.
  if (node.status === "private" || node.parent!.status === "private") {
    node.status = "private";
    title += "icon-lock";
  }
  title += '">';
  // Was `node.parent.visble`/`node.visble = ...` -- a genuine typo
  // (masked by this file's pre-P47 `any` typing, and by `INode`'s own
  // `[key: string]: any` index signature even after that): the
  // lock-icon inheritance from a private/hidden parent never actually
  // worked, and the reassignment never actually persisted the
  // propagated flag onto the node.
  if (node.visible === "false" || node.parent!.visible === "false") {
    node.visible = "false";
    title +=
      '<span class="tiptip icon-cone" title="' +
      tiptipLockedAlbum +
      '" style="font-size: 16px"></span>';
  }
  title +=
    '<p class="move-cat-title" title="' +
    node.name +
    '">%name%</p> <span class="icon-pencil"></span> </span>';
  const togglerCont =
    "<div class='move-cat-toogler' data-id=%id%>%content%</div>";
  const actions =
    '<div class="move-cat-action-cont">' +
    "<div class='move-cat-action'>" +
    '<a class="move-cat-add icon-add-album tiptip" title="' +
    strAddAlbum +
    '" href="#" data-aid="' +
    String(node.id) +
    '"></a>' +
    '<a class="move-cat-edit icon-pencil tiptip" title="' +
    strEditAlbum +
    '" href="admin.php?page=album-' +
    String(node.id) +
    '"></a>' +
    '<a class="move-cat-upload icon-plus-circled tiptip" title="' +
    strAddPhoto +
    '" href="admin.php?page=photos_add&album=' +
    String(node.id) +
    '"></a>' +
    '<a class="move-cat-see icon-eye tiptip" title="' +
    strVisitGallery +
    '" href="index.php?/category/' +
    String(node.id) +
    '"></a>' +
    '<a data-id="' +
    String(node.id) +
    '" class="move-cat-order icon-sort-name-up tiptip" title="' +
    strSortOrder +
    '"></a>' +
    '<a data-id="' +
    String(node.id) +
    '" class="move-cat-delete icon-trash tiptip" title="' +
    strDeleteAlbum +
    '" ></a>' +
    "</div>" +
    "</div>";
  // action_order = '<a data-id="'+node.id+'" class="move-cat-order icon-sort-name-up tiptip" title="'+ strSortOrder +'"></a>';

  const cont = find(liEl, ".jqtree-element")[0]!;
  addClass(cont, "move-cat-container");
  attr(cont, "id", "cat-" + String(node.id));
  html(cont, "");

  append(cont, actions);

  on(find(cont, ".toggle-cat-option"), "click", function (this: Element) {
    hide(document.querySelectorAll(".cat-option"));
    find(this, ".cat-option").forEach((el) => {
      el.classList.toggle("cat-option-visible");
    });
  });

  let toggler: string;
  if (node.haveChildren || node.children.length !== 0) {
    const { open_nodes: openNodes } = getAlbumTree().getState();
    if (openNodes.includes(node.id!)) {
      toggler = togglerOpen;
    } else {
      toggler = togglerClose;
    }
    append(
      cont,
      togglerCont
        .replace(/%content%/g, toggler)
        .replace(/%id%/g, String(node.id)),
    );
  } else {
    addClass(find(cont, ".move-cat-order"), "notClickable");

    append(
      cont,
      togglerCont
        .replace(/%content%/g, togglerClose)
        .replace(/%id%/g, String(node.id)),
    );
    addClass(cont, "disabledToggle");
  }

  append(cont, icon.replace(/%icon%/g, "icon-grip-vertical-solid"));
  attr(find(cont, ".icon-grip-vertical-solid"), "title", strAlbsDragDrop);

  if (node.haveChildren || node.children.length !== 0) {
    append(cont, icon.replace(/%icon%/g, "icon-sitemap"));
  } else {
    append(cont, icon.replace(/%icon%/g, "icon-folder-open"));
  }

  append(cont, title.replace(/%name%/g, node.name));

  const colors = [
    "icon-red",
    "icon-blue",
    "icon-yellow",
    "icon-purple",
    "icon-green",
  ];
  const colorId = Number(node.id) % 5;
  addClass(
    find(cont, "span.icon-folder-open, span.icon-sitemap"),
    (colors[colorId] ?? "") + " node-icon",
  );

  after(
    find(cont, ".move-cat-title-container"),
    "<div class='badge-container'>" +
      "<i class='icon-blue icon-sitemap nb-subcats'>" +
      String(node.nb_subcats) +
      "</i>" +
      "<i class='icon-purple icon-picture nb-images'>" +
      String(node.nb_images) +
      "</i>" +
      "<i class='icon-green icon-imagefolder-01 nb-sub-photos'>" +
      String(node.nb_sub_photos) +
      "</i>" +
      "<div class='badge-dropdown'>" +
      "<span class='icon-blue icon-sitemap nb-subcats'>" +
      xNbSubcats.replace("%d", String(node.nb_subcats)) +
      "</span>" +
      "<span class='icon-purple icon-picture nb-images'>" +
      xNbImages.replace("%d", String(node.nb_images)) +
      "</span>" +
      "<span class='icon-green icon-imagefolder-01 nb-sub-photos'>" +
      xNbSubPhotos.replace("%d", String(node.nb_sub_photos)) +
      "</span>" +
      "</div>" +
      "</div>",
  );

  if (node.nb_subcats === undefined || node.nb_subcats === 0) {
    hide(find(cont, ".nb-subcats"));
  }

  if (!(node.nb_images !== 0 && node.nb_images)) {
    hide(find(cont, ".nb-images"));
  }

  if (!node.nb_sub_photos) {
    hide(find(cont, ".nb-sub-photos"));
  }

  if (node.has_not_access) {
    addClass(find(cont, ".move-cat-see"), "notClickable");
  }
}

/*----------------
Checkboxes
----------------*/

function checkboxChange(this: Element) {
  if (attrOf(this, "data-selected") === "1") {
    hide(find(this, "i"));
  } else {
    show(find(this, "i"));
  }
}

function checkboxClick(this: Element) {
  if (attrOf(this, "data-selected") === "1") {
    attr(this, "data-selected", "0");
    hide(find(this, "i"));
  } else {
    attr(this, "data-selected", "1");
    show(find(this, "i"));
  }
}

// Genuinely dead code -- zero real callers found (confirmed via grep)
// -- typed rather than left broken, same policy as other confirmed-dead
// functions found this campaign.
function openAddAlbumPopIn(parentAlbumId: number) {
  if (parentAlbumId !== 0) {
    html(
      document.querySelectorAll("#AddAlbum .AddIconTitle span"),
      addSubAlbumOf.replace(
        "%s",
        getAlbumTree().getNodeById(parentAlbumId)!.name,
      ),
    );
  } else {
    html(
      document.querySelectorAll("#AddAlbum .AddIconTitle span"),
      addAlbumRootTitle,
    );
  }
  fadeIn(document.querySelectorAll("#AddAlbum"));
  const modalInput = document.querySelectorAll(
    ".AddAlbumLabelUsername .user-property-input",
  );
  setVal(modalInput, "");
  document
    .querySelector<HTMLElement>(".AddAlbumLabelUsername .user-property-input")
    ?.focus();
  off(modalInput, "keyup");
  on(modalInput, "keyup", function (this: Element) {
    if (val(this) !== "") {
      hide(document.querySelectorAll(".AddAlbumErrors"));
    }
  });

  const addAlbum = document.querySelectorAll("#AddAlbum");
  off(addAlbum, "keyup");
  on(addAlbum, "keyup", function (e: Event) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keyup" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
    const { key } = e as KeyboardEvent;
    if (key === "Enter") {
      document.querySelector<HTMLElement>(".AddAlbumSubmit")?.click();
    }
    if (key === "Escape") {
      closeAddAlbumPopIn();
    }
  });
}

function closeAddAlbumPopIn() {
  fadeOut(document.querySelectorAll("#AddAlbum"), function () {
    hide(document.querySelectorAll(".AddAlbumErrors"));
  });
}

function openRenameAlbumPopIn(replacedAlbumName: string | undefined) {
  const safeName = replacedAlbumName ?? "";
  fadeIn(document.querySelectorAll("#RenameAlbum"));
  html(
    document.querySelectorAll(".RenameAlbumTitle span"),
    renameItem.replace("%s", safeName),
  );
  setVal(
    document.querySelectorAll(".RenameAlbumLabelUsername .user-property-input"),
    safeName,
  );
  document
    .querySelector<HTMLElement>(
      ".RenameAlbumLabelUsername .user-property-input",
    )
    ?.focus();

  off(document, "keypress");
  on(document, "keypress", function (e: Event) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keypress" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
    if ((e as KeyboardEvent).key === "Enter") {
      document.querySelector<HTMLElement>(".RenameAlbumSubmit")?.click();
    }
  });
}

function closeRenameAlbumPopIn() {
  fadeOut(document.querySelectorAll("#RenameAlbum"));
}

async function triggerDeleteAlbum(cat_id: number): Promise<void> {
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/categories/" + String(cat_id) + "/orphan-impact",
      type: "GET",
      dataType: "json",
    })) as operations["categoryOrphanImpact"]["responses"][200]["content"]["application/json"];

    if (response.nbImagesRecursive === 0) {
      hide(document.querySelectorAll(".deleteAlbumOptions"));
    } else {
      show(document.querySelectorAll(".deleteAlbumOptions"));
      if (response.nbImagesAssociatedOutside === 0) {
        hide(document.querySelectorAll("#IMAGES_ASSOCIATED_OUTSIDE"));
      } else {
        html(
          document.querySelectorAll("#IMAGES_ASSOCIATED_OUTSIDE .innerText"),
          "",
        );
        append(
          document.querySelectorAll(
            "#IMAGES_ASSOCIATED_OUTSIDE .innerText",
          )[0]!,
          hasImagesAssociatedOutside
            .replace("%d", String(response.nbImagesRecursive))
            .replace("%d", String(response.nbImagesAssociatedOutside)),
        );
      }
      if (response.nbImagesBecomingOrphan === 0) {
        hide(document.querySelectorAll("#IMAGES_BECOMING_ORPHAN"));
      } else {
        html(
          document.querySelectorAll("#IMAGES_BECOMING_ORPHAN .innerText"),
          "",
        );
        append(
          document.querySelectorAll("#IMAGES_BECOMING_ORPHAN .innerText")[0]!,
          hasImagesBecommingOrphans.replace(
            "%d",
            String(response.nbImagesBecomingOrphan),
          ),
        );
      }
    }

    openDeleteAlbumPopIn(cat_id);
  } catch (e) {
    console.error(e instanceof AjaxError ? e.responseText : e);
  }
}

function openDeleteAlbumPopIn(cat_to_delete: number) {
  fadeIn(document.querySelectorAll("#DeleteAlbum"));
  const node = getAlbumTree().getNodeById(cat_to_delete)!;
  if (node.children.length === 0) {
    html(
      document.querySelectorAll(".DeleteIconTitle span"),
      deleteAlbumWithName.replace("%s", node.name),
    );
  } else {
    html(
      document.querySelectorAll(".DeleteIconTitle span"),
      deleteAlbumWithSubs
        .replace("%s", node.name)
        .replace("%d", String(getAllSubAlbumsFromNode(node))),
    );
  }

  // Actually delete
  const deleteSubmit = document.querySelectorAll(".DeleteAlbumSubmit");
  off(deleteSubmit, "click");
  on(deleteSubmit, "click", function () {
    void (async () => {
      try {
        await ajax({
          url: "api/v1/categories/" + String(cat_to_delete),
          type: "DELETE",
          json: {
            photoDeletionMode: val(
              document.querySelectorAll(
                "input[name=photo_deletion_mode]:checked",
              ),
            ),
          },
          headers: { "X-CSRF-Token": pwgToken },
        });

        // Non-null: same "always a real parent, never bare null in
        // practice" invariant as createAlbumNode()'s own copy of this
        // comment above.
        const parentOfDeletedNode = node.parent!;
        getAlbumTree().removeNode(node);

        rebindMoveCatActions();

        updateTitleBadge(nbAlbums - 1);
        setSubcatsBadge(parentOfDeletedNode);
        closeDeleteAlbumPopIn();
      } catch (e) {
        console.error(e instanceof AjaxError ? e.responseText : e);
      }
    })();
  });
}

function closeDeleteAlbumPopIn() {
  fadeOut(document.querySelectorAll("#DeleteAlbum"));
}

function getAllSubAlbumsFromNode(node: AlbumJqTreeNode): number {
  let nbSubCats = 0;
  if (node.children.length !== 0) {
    node.children.forEach((child) => {
      nbSubCats++;
      nbSubCats += getAllSubAlbumsFromNode(child);
    });
  } else {
    return 0;
  }
  return nbSubCats;
}

function setSubcatsBadge(node: AlbumJqTreeNode) {
  if (node.children.length !== 0) {
    const nbSubcats = find(
      document.querySelectorAll("#" + escapeId("cat-" + String(node.id))),
      ".nb-subcats",
    );
    text(nbSubcats, String(node.children.length));
    show(nbSubcats, 100);
    text(
      find(
        find(
          document.querySelectorAll("#" + escapeId("cat-" + String(node.id))),
          ".badge-dropdown",
        ),
        ".nb-subcats",
      ),
      xNbSubcats.replace("%d", String(node.children.length)),
    );
  } else {
    hide(
      find(
        document.querySelectorAll("#" + escapeId("cat-" + String(node.id))),
        ".nb-subcats",
      ),
      100,
    );
  }
}

function updateTitleBadge(newNbAlbums: number) {
  nbAlbums = newNbAlbums;
  text(document.querySelectorAll(".badge-number"), String(newNbAlbums));
}

function goToNode(node: AlbumJqTreeNode, firstNode: AlbumJqTreeNode) {
  if (node.parent) {
    goToNode(node.parent, firstNode);
    if (node !== firstNode) {
      getAlbumTree().openNode(node);
      show(
        document.querySelectorAll(
          "#" + escapeId("cat-" + String(node.parent.id)),
        ),
      );
      addClass(
        document.querySelectorAll(
          "#" + escapeId("cat-" + String(node.parent.id)),
        ),
        "imune",
      );
    }
  } else {
    getAlbumTree().openNode(node);
    addClass(
      document.querySelectorAll("#" + escapeId("cat-" + String(firstNode.id))),
      "animateFocus",
    );

    showNodeChildrens(firstNode);
  }
}

function showNodeChildrens(node: AlbumJqTreeNode) {
  if (node.children.length > 0) {
    node.children.forEach((child) => {
      addClass(
        document.querySelectorAll("#" + escapeId("cat-" + String(child.id))),
        "imune",
      );
      showNodeChildrens(child);
    });
  }
}

// Genuinely dead code -- zero real callers found (confirmed via grep)
// -- typed rather than left broken, same policy as isNumeric() above.
function getId(parent: AlbumJqTreeNode): string | number {
  if (parent.getLevel() === 0) {
    return 0;
  } else {
    // Non-null: every real album node carries a real id (only jqtree's
    // own invisible tree root, excluded by the `getLevel() == 0` branch
    // above, ever lacks one).
    return parent.id!;
  }
}

function getRank(node: AlbumJqTreeNode, ignoreId: string | number): number {
  const previousSibling = node.getPreviousSibling();
  if (previousSibling != null) {
    if (node.id !== ignoreId) {
      return 1 + getRank(previousSibling, ignoreId);
    } else {
      return getRank(previousSibling, ignoreId);
    }
  } else {
    if (node.id !== ignoreId) {
      return 1;
    } else {
      return 0;
    }
  }
}

function applyMove(moveInfo: JqTreeMoveInfo<AlbumNodeData>) {
  const waitingTimeout = setTimeout(() => {
    addClass(document.querySelectorAll(".waiting-message"), "visible");
  }, 500);
  const id = moveInfo.movedNode.id!;
  let moveParent: string | number | null = null;
  let moveRank: number | null = null;
  const previousParent = moveInfo.previousParent!;
  const target = moveInfo.targetNode;
  if (moveInfo.position === "after") {
    // Non-null: same "always a real parent" invariant as elsewhere in
    // this file (a move target is never the tree's own invisible root).
    if (String(getId(previousParent)) !== String(getId(target.parent!))) {
      moveParent = getId(target.parent!);
    }
    moveRank = getRank(target, id) + 1;
  } else if (moveInfo.position === "inside") {
    if (String(getId(previousParent)) !== String(getId(target))) {
      moveParent = getId(target);
      const currentNode = getAlbumTree().getNodeById(moveParent);
      if (
        currentNode &&
        currentNode.load_on_demand &&
        currentNode.haveChildren
      ) {
        loadOnDemand(currentNode);
      }
    }
    moveRank = 1;
  } else if (moveInfo.position === "before") {
    if (String(getId(previousParent)) !== String(getId(target.parent!))) {
      moveParent = getId(target.parent!);
    }
    moveRank = 1;
  }
  moveNode(id, moveRank, moveParent)
    .then(() => {
      moveInfo.doMove();
      clearTimeout(waitingTimeout);
      removeClass(document.querySelectorAll(".waiting-message"), "visible");
      setSubcatsBadge(previousParent);
      // Was an unconditional `setSubcatsBadge($(".tree").tree
      // ("getNodeById", moveParent))` -- `moveParent` stays `null` for
      // a same-parent rank-only reorder (see the 3 branches above), and
      // `getNodeById(null)` resolves to no real node, so this crashed
      // on `.children.length` inside setSubcatsBadge() every time an
      // album was drag-reordered among its own siblings without
      // changing parent -- silently aborting the rest of this
      // `.then()` (the click-handler rebinds below never ran). Guarded:
      // when the parent didn't change, `previousParent`'s own
      // already-refreshed badge above covers it.
      if (moveParent !== null) {
        setSubcatsBadge(getAlbumTree().getNodeById(moveParent)!);
      }

      rebindMoveCatActions();
    })
    .catch(function (message: unknown) {
      console.error(
        "An error has occured : " +
          (message instanceof Error ? message.message : String(message)),
      );
      rebindMoveCatActions();
    });
}

async function moveNode(
  node: string | number,
  rank: number | null,
  parent: string | number | null,
): Promise<void> {
  try {
    if (parent != null) {
      await changeParent(node, parent, rank);
    } else if (rank != null) {
      await changeRank(node, rank);
    }
  } catch (e) {
    throw new Error("move failed", { cause: e });
  }
}

async function changeParent(
  node: string | number,
  parent: string | number,
  rank: number | null,
): Promise<void> {
  let response: operations["categoryMove"]["responses"][200]["content"]["application/json"];
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    response = (await ajax({
      url: "api/v1/categories/actions/move",
      type: "POST",
      json: {
        categoryIds: [Number(node)],
        parentId: Number(parent),
      },
      headers: { "X-CSRF-Token": pwgToken },
      dataType: "json",
    })) as operations["categoryMove"]["responses"][200]["content"]["application/json"];
  } catch (e) {
    throw new Error((e instanceof AjaxError && e.statusText) || "move failed", {
      cause: e,
    });
  }

  void changeRank(node, rank);
  response.updatedCategories.forEach((cat) => {
    const catNode = getAlbumTree().getNodeById(cat.categoryId)!;
    catNode.nb_sub_photos = cat.nbSubPhotos;
    getAlbumTree().updateNode(catNode, catNode.name);
  });
}

async function changeRank(
  node: string | number,
  rank: number | null,
): Promise<void> {
  try {
    await ajax({
      url: "api/v1/categories/actions/reorder",
      type: "POST",
      json: {
        categoryIds: [Number(node)],
        rank: Number(rank),
      },
      headers: { "X-CSRF-Token": pwgToken },
      dataType: "json",
    });
  } catch (e) {
    throw new Error(
      (e instanceof AjaxError && e.statusText) || "rank change failed",
      { cause: e },
    );
  }
}

function makePrivateHierarchy(node: AlbumJqTreeNode) {
  node.status = "private";
  node.children.forEach((child) => {
    makePrivateHierarchy(child);
  });
}

function getPathNode(node: AlbumJqTreeNode): string {
  // Non-null: same "always a real parent" invariant as elsewhere in
  // this file.
  if (node.parent!.getLevel() !== 0) {
    return getPathNode(node.parent!) + " / " + node.name;
  } else {
    return node.name;
  }
}

function findAlbumById(
  a: AlbumTreeNode[],
  id: string | number,
): AlbumTreeNode | null {
  for (const album of a) {
    if (album.id === String(id)) {
      return album;
    }

    if (
      (album.haveChildren && album.haveChildren.length > 0) ||
      (album.children && album.children.length > 0)
    ) {
      const al = findAlbumById(album.haveChildren ?? album.children ?? [], id);
      if (al) {
        return al;
      }
    }
  }
  return null;
}

function loadOnDemand(node: AlbumJqTreeNode) {
  const children = node.haveChildren!;
  const formatedChild = children.map((a: AlbumTreeNode) => {
    const al: AlbumTreeNode = { ...a, children: [] };
    if (a.children) {
      al.load_on_demand = true;
      al.haveChildren = a.children;
    }
    return al;
  });

  getAlbumTree().loadData(formatedChild, node);
  node.load_on_demand = false;
}

function openNodeOnDemand(node: AlbumJqTreeNode) {
  const { open_nodes: openNodes } = getAlbumTree().getState();
  // Was `openNodes.includes(node)` -- comparing the whole node object
  // against a list of plain ids, which real strict typing rejects
  // outright (`(string | number)[]` vs an object). Always evaluated to
  // `false`, so the "already open" guard below never actually skipped
  // anything; harmless in practice (jqtree's own `openNode()` no-ops on
  // an already-open node), but not the real intended check.
  if (!openNodes.includes(node.id!)) {
    getAlbumTree().openNode(node);
    rebindMoveCatActions();
  }
}

// `data` is a real export now (docs/PLAN.md P48) -- categories/search.ts
// imports it directly, no more `window.` latching.
