import type { operations } from "../../../../openapi/client/schema";
import { jConfirm_confirm_options } from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import { confirm } from "../../../default/js/vendor/jconfirm";
import {
  getTreeInstance,
  tree,
  type JqTreeInstance,
  type JqTreeMoveInfo,
  type JqTreeNode,
} from "../../../default/js/vendor/jqtree";
import {
  addClass,
  after,
  append,
  attr,
  attrOf,
  data,
  delegate,
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
} from "../../../default/js/vendor/dom";
import { tipTip } from "../../../default/js/vendor/tiptip";
export {};

// `vendor/jqtree.ts`'s own real per-row shape (P49-B group 6B), traced to
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

// The extra, real per-node data `vendor/jqtree.ts`'s generic node type
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

// Named `albumData` internally, not `data` -- avoids shadowing dom.ts's
// own imported `data()` helper throughout this file. Re-exported as
// `data` below (its real, external name; cat_search.ts imports it as
// such, docs/PLAN.md P48) to keep that contract unchanged.
const albumData = pwg_getPageData<AlbumTreeNode[]>("album_data");
export { albumData as data };
const pwg_token = pwg_getPageData<string>("csrf_token");
const str_are_you_sure = pwg_getPageString(
  "The status of the album '%s' and its sub-albums will change to private. Are you sure?",
);
const str_yes_change_parent = pwg_getPageString("Yes change parent anyway");
const str_no_change_parent = pwg_getPageString(
  "No, don't move this album here",
);
const str_root = pwg_getPageString("Root");
const openCat = pwg_getPageData<string>("open_cat");
let nb_albums = pwg_getPageData<number>("nb_albums");
const light_album_manager = pwg_getPageData<number>("light_album_manager");

const x_nb_subcats = pwg_getPageString("%d sub-albums");
const x_nb_images = pwg_getPageString("%d photos");
const x_nb_sub_photos = pwg_getPageString("%d pictures in sub-albums");

// Not `str_albums_found`/`str_result_limit`/`str_album_found` too --
// AlbumsView.php's own exposedStrings() carries all 3, but the real
// reader is cat_search.ts, which imports them from album_selector.ts's
// own real exports instead (cat_search.ts's own leading comment: a
// genuine, coincidental same-wording duplicate, not the semantically
// "correct" owner). This file's own former local `const str_album_found`
// copy was real dead code -- confirmed zero consumers anywhere, `.ts`
// or `.latte` -- and was removed outright rather than exported to
// nothing (docs/PLAN.md P48, common.ts's own batch already established
// this same precedent for `array_delete`).
const str_albs_drag_drop = pwg_getPageString("Drag and drop to reorder albums");

const delay_autoOpen = pwg_getPageData<number>("delay_auto_open");

// Assigned once, at the top of `ready()` below, before the tree is
// initialized -- every other module-level function reads the live
// instance back through `getAlbumTree()` (same WeakMap-instance-lookup
// pattern `vendor/jqtree.ts` documents), matching the original's own
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
const toggler_close = "<span class='icon-left-open'></span>";
const toggler_open = "<span class='icon-down-open'></span>";

const delete_album_with_name = pwg_getPageString('Delete album "%s".');
const delete_album_with_subs = pwg_getPageString(
  'Delete album "%s" and its %d sub-albums.',
);
const has_images_associated_outside = pwg_getPageString(
  "delete album and all %d photos, even the %d associated to other albums",
);
const has_images_becomming_orphans = pwg_getPageString(
  "delete album and the %d orphan photos",
);
const rename_item = pwg_getPageString('Rename "%s"');

const str_add_album = pwg_getPageString("Add Album");
const str_edit_album = pwg_getPageString("Edit album");
const str_add_photo = pwg_getPageString("Add Photos");
const str_visit_gallery = pwg_getPageString("Visit Gallery");
const str_sort_order = pwg_getPageString("Automatic sort order");
const str_delete_album = pwg_getPageString("Delete album");
const str_root_order = pwg_getPageString("Apply to root albums");
const str_sub_album_order = pwg_getPageString("Apply to direct sub-albums");
const str_album_name_empty = pwg_getPageString("Album name must not be empty");

const add_album_root_title = pwg_getPageString("Create a new album at root");
const add_sub_album_of = pwg_getPageString('Create a sub-album of "%s"');
const tiptip_locked_album = pwg_getPageString("Locked album");

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
  on(document.querySelectorAll(".move-cat-add"), "click", function (e: Event) {
    e.preventDefault();
    const target = e.currentTarget as Element;
    const aid = data(target, "aid") as string | number;
    openAddAlbumPopIn(aid);
    setData(document.querySelector(".AddAlbumSubmit")!, "a-parent", aid);
  });
  off(document.querySelectorAll(".move-cat-delete"), "click");
  on(
    document.querySelectorAll(".move-cat-delete"),
    "click",
    function (e: Event) {
      triggerDeleteAlbum(
        data(e.currentTarget as Element, "id") as string | number,
      );
    },
  );
  off(document.querySelectorAll(".move-cat-title-container"), "click");
  on(
    document.querySelectorAll(".move-cat-title-container"),
    "click",
    function (e: Event) {
      const target = e.currentTarget as Element;
      openRenameAlbumPopIn(
        attrOf(find(target, ".move-cat-title"), "title") ?? undefined,
      );
      setData(
        document.querySelector(".RenameAlbumSubmit")!,
        "cat_id",
        attrOf(target, "data-id")!,
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
    openCat == "-1"
      ? []
      : findAlbumById(albumData, openCat)!.uppercats.split(",");
  const new_data = albumData.map((a: AlbumTreeNode) => {
    const al: AlbumTreeNode = {
      ...a,
      children: openUppercats.includes(a.id) ? a.children : [],
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
      `<span class='badge-number'>` + nb_albums + `</span>`,
    );

  treeEl = document.querySelector(".tree")!;

  // Real init options only (P49-B group 6B): `autoOpen`/`onCanSelectNode`
  // are dropped from `vendor/jqtree.ts`'s own options surface entirely,
  // since this is the library's one real consumer and it always passes
  // `autoOpen: false` (no restore-from-storage, no auto-expand) and
  // permanently disables node selection -- see that module's own header
  // comment for the full narrowing rationale.
  tree<AlbumNodeData>(treeEl, {
    data: new_data,
    dragAndDrop: true,
    openFolderDelay: delay_autoOpen,
    onCreateLi: createAlbumNode,
  });

  delegate(treeEl, "click", ".move-cat-toogler", function (this: Element) {
    const node_id = attrOf(this, "data-id");
    const node = getAlbumTree().getNodeById(node_id);

    if (node) {
      if (node.load_on_demand && node.haveChildren) {
        loadOnDemand(node);
      }

      const open_nodes = getAlbumTree().getState().open_nodes;
      if (!open_nodes.includes(node.id!)) {
        html(this, toggler_open);
        getAlbumTree().openNode(node);
        // reset event here:
        rebindMoveCatActions();
      } else {
        html(this, toggler_close);
        getAlbumTree().closeNode(node);
      }
    }
  });

  on(treeEl, "tree.open", function (e: Event) {
    const { node } = (e as CustomEvent<{ node: AlbumJqTreeNode }>).detail;
    html(
      document.querySelectorAll('.move-cat-toogler[data-id="' + node.id + '"]'),
      toggler_open,
    );
  });

  on(treeEl, "tree.close", function (e: Event) {
    const { node } = (e as CustomEvent<{ node: AlbumJqTreeNode }>).detail;
    html(
      document.querySelectorAll('.move-cat-toogler[data-id="' + node.id + '"]'),
      toggler_close,
    );
  });

  on(treeEl, "tree.move", function (e: Event) {
    const event = e as CustomEvent<JqTreeMoveInfo<AlbumNodeData>>;
    event.preventDefault();
    const moveInfo = event.detail;

    if (moveInfo.movedNode.status != "private") {
      let parentIsPrivate = false;
      if (moveInfo.position == "after") {
        // Non-null: a same-level "after" move always has a real parent
        // in this tree (there is no top-level move target with a null
        // parent in practice) -- same unguarded assumption the
        // pre-P47 `any`-typed code already made.
        parentIsPrivate = moveInfo.targetNode.parent!.status == "private";
      } else if (moveInfo.position == "inside") {
        parentIsPrivate = moveInfo.targetNode.status == "private";
      }

      if (parentIsPrivate) {
        confirm({
          title: str_are_you_sure.replace(/%s/g, moveInfo.movedNode.name),
          buttons: {
            confirm: {
              text: str_yes_change_parent,
              btnClass: "btn-red",
              action: function () {
                makePrivateHierarchy(moveInfo.movedNode);
                applyMove(moveInfo);
              },
            },
            cancel: {
              text: str_no_change_parent,
            },
          },
          ...jConfirm_confirm_options,
        });
      } else {
        applyMove(moveInfo);
      }
    } else {
      applyMove(moveInfo);
    }
  });

  delegate(treeEl, "click", ".move-cat-order", function (this: Element) {
    const node_id = attrOf(this, "data-id");
    const node = getAlbumTree().getNodeById(node_id);
    if (node) {
      fadeIn(document.querySelectorAll(".cat-move-order-popin"));
      html(
        document.querySelectorAll(".cat-move-order-popin .album-name"),
        getPathNode(node),
      );
      setVal(
        document.querySelectorAll(".cat-move-order-popin input[name=id]"),
        node_id!,
      );
      attr(
        document.querySelectorAll("input[name=simpleAutoOrder]"),
        "value",
        str_sub_album_order,
      );
    }
  });

  on(document.querySelectorAll(".order-root"), "click", function () {
    fadeIn(document.querySelectorAll(".cat-move-order-popin"));
    html(
      document.querySelectorAll(".cat-move-order-popin .album-name"),
      str_root,
    );
    setVal(
      document.querySelectorAll(".cat-move-order-popin input[name=id]"),
      "-1",
    );
    attr(
      document.querySelectorAll("input[name=simpleAutoOrder]"),
      "value",
      str_root_order,
    );
  });

  on(treeEl, "mousedown mouseup", function mouseState(e: Event) {
    if (e.type == "mousedown") {
      addClass(treeEl, "dragging");
    } else if (e.type == "mouseup") {
      removeClass(document.querySelectorAll(".dragging"), "dragging");
    }
  });

  if (openCat != "-1") {
    // Non-null: `openCat`, when not the "-1" sentinel, is always a real
    // album id present in this tree (same unguarded assumption the
    // pre-P47 `any`-typed code already made).
    const nodeToGo = getAlbumTree().getNodeById(openCat)!;

    goToNode(nodeToGo, nodeToGo);
    if (nodeToGo.children) {
      getAlbumTree().openNode(nodeToGo, false);
    }

    const target = document.querySelector("#cat-" + openCat)!;
    animateScrollTop(
      offset(target).top -
        windowHeight() / 2 +
        outerHeight(target as HTMLElement) / 2,
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
        attrOf(this, "data-id")!,
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
      const catToEdit = data(this, "cat_id") as string | number;
      void ajax({
        url: "api/v1/categories/" + catToEdit,
        type: "PATCH",
        contentType: "application/json",
        headers: { "X-CSRF-Token": pwg_token },
        data: JSON.stringify({
          name: val(
            document.querySelectorAll(".RenameAlbumLabelUsername input"),
          ),
        }),
        dataType: "json",
        success: function (
          _data: operations["categoryUpdate"]["responses"][200]["content"]["application/json"],
        ) {
          const node_id = attrOf(
            find(
              document.querySelectorAll("#cat-" + catToEdit),
              ".move-cat-toogler",
            ),
            "data-id",
          );
          const node = getAlbumTree().getNodeById(node_id)!;
          node.name = String(
            val(document.querySelectorAll(".RenameAlbumLabelUsername input")),
          );
          getAlbumTree().updateNode(
            node,
            val(document.querySelectorAll(".RenameAlbumLabelUsername input")),
          );

          rebindMoveCatActions();

          closeRenameAlbumPopIn();
        },
        error: function (message) {
          console.log(message);
        },
      });
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
      const aid = data(this, "aid") as string | number;
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
      const newAlbumParent = data(
        document.querySelector(".AddAlbumSubmit")!,
        "a-parent",
      ) as string | number;
      const newAlbumPosition = val(
        document.querySelectorAll("input[name=position]:checked"),
      );

      void ajax({
        url: "api/v1/categories",
        type: "POST",
        contentType: "application/json",
        headers: { "X-CSRF-Token": pwg_token },
        data: JSON.stringify({
          name: newAlbumName,
          parentId: Number(newAlbumParent),
          position: newAlbumPosition,
        }),
        dataType: "json",
        success: function (
          data: operations["categoryCreate"]["responses"][201]["content"]["application/json"],
        ) {
          const parent_node = getAlbumTree().getNodeById(newAlbumParent);
          if (
            parent_node &&
            parent_node.load_on_demand &&
            parent_node.haveChildren
          ) {
            loadOnDemand(parent_node);
          }
          if (parent_node) openNodeOnDemand(parent_node);

          if (newAlbumPosition == "last") {
            getAlbumTree().appendNode(
              {
                id: data.id,
                isEmptyFolder: true,
                // Non-null: a required admin form field, always populated
                // by the time this AJAX success handler runs.
                name: newAlbumName!,
              },
              parent_node ?? undefined,
            );
          } else {
            getAlbumTree().prependNode(
              {
                id: data.id,
                isEmptyFolder: true,
                // Non-null: a required admin form field, always populated
                // by the time this AJAX success handler runs.
                name: newAlbumName!,
              },
              parent_node ?? undefined,
            );
          }

          if (parent_node) {
            setSubcatsBadge(parent_node);

            delegate(
              document.querySelectorAll("#cat-" + parent_node.id),
              "click",
              ".move-cat-toogler",
              function (this: Element) {
                const node_id = parent_node.id;
                const node = getAlbumTree().getNodeById(node_id);
                if (node) {
                  const open_nodes = getAlbumTree().getState().open_nodes;
                  if (!open_nodes.includes(node_id!)) {
                    html(this, toggler_open);
                    getAlbumTree().openNode(node);
                  } else {
                    html(this, toggler_close);
                    getAlbumTree().closeNode(node);
                  }
                }
              },
            );
          }

          rebindMoveCatActions();

          updateTitleBadge(nb_albums + 1);

          goToNode(
            getAlbumTree().getNodeById(data.id)!,
            getAlbumTree().getNodeById(data.id)!,
          );
          animateScrollTop(
            offset(document.querySelector("#cat-" + data.id)!).top -
              screen.height / 2,
            "slow",
          );

          closeAddAlbumPopIn();
          removeClass(
            document.querySelectorAll(".AddAlbumSubmit"),
            "notClickable",
          );
        },
        error: function (message) {
          console.log(message);
          text(
            document.querySelectorAll(".AddAlbumErrors"),
            str_album_name_empty,
          );
          show(document.querySelectorAll(".AddAlbumErrors"));
          removeClass(
            document.querySelectorAll(".AddAlbumSubmit"),
            "notClickable",
          );
        },
      });
    },
  );

  // Delete Album
  on(
    document.querySelectorAll(".move-cat-delete"),
    "click",
    function (e: Event) {
      triggerDeleteAlbum(
        data(e.currentTarget as Element, "id") as string | number,
      );
    },
  );

  off(document.querySelectorAll(".user-list-checkbox"), "change");
  on(
    document.querySelectorAll(".user-list-checkbox"),
    "change",
    checkbox_change,
  );
  off(document.querySelectorAll(".user-list-checkbox"), "click");
  on(document.querySelectorAll(".user-list-checkbox"), "click", checkbox_click);

  if (!light_album_manager) {
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
    '<span data-id="' + node.id + '" class="move-cat-title-container ';
  // Non-null: every real album node's `.parent` is either a real
  // parent album, or jqtree's own invisible tree root (never a bare
  // `null` for a node actually handed to `onCreateLi`) -- same
  // assumption `getId()` further down already makes explicitly via
  // `parent.getLevel()`.
  if (node.status == "private" || node.parent!.status == "private") {
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
  if (node.visible == "false" || node.parent!.visible == "false") {
    node.visible = "false";
    title +=
      '<span class="tiptip icon-cone" title="' +
      tiptip_locked_album +
      '" style="font-size: 16px"></span>';
  }
  title +=
    '<p class="move-cat-title" title="' +
    node.name +
    '">%name%</p> <span class="icon-pencil"></span> </span>';
  const toggler_cont =
    "<div class='move-cat-toogler' data-id=%id%>%content%</div>";
  const actions =
    '<div class="move-cat-action-cont">' +
    "<div class='move-cat-action'>" +
    '<a class="move-cat-add icon-add-album tiptip" title="' +
    str_add_album +
    '" href="#" data-aid="' +
    node.id +
    '"></a>' +
    '<a class="move-cat-edit icon-pencil tiptip" title="' +
    str_edit_album +
    '" href="admin.php?page=album-' +
    node.id +
    '"></a>' +
    '<a class="move-cat-upload icon-plus-circled tiptip" title="' +
    str_add_photo +
    '" href="admin.php?page=photos_add&album=' +
    node.id +
    '"></a>' +
    '<a class="move-cat-see icon-eye tiptip" title="' +
    str_visit_gallery +
    '" href="index.php?/category/' +
    node.id +
    '"></a>' +
    '<a data-id="' +
    node.id +
    '" class="move-cat-order icon-sort-name-up tiptip" title="' +
    str_sort_order +
    '"></a>' +
    '<a data-id="' +
    node.id +
    '" class="move-cat-delete icon-trash tiptip" title="' +
    str_delete_album +
    '" ></a>' +
    "</div>" +
    "</div>";
  // action_order = '<a data-id="'+node.id+'" class="move-cat-order icon-sort-name-up tiptip" title="'+ str_sort_order +'"></a>';

  const cont = find(liEl, ".jqtree-element")[0]!;
  addClass(cont, "move-cat-container");
  attr(cont, "id", "cat-" + node.id);
  html(cont, "");

  append(cont, actions);

  on(find(cont, ".toggle-cat-option"), "click", function (this: Element) {
    hide(document.querySelectorAll(".cat-option"));
    find(this, ".cat-option").forEach((el) => {
      el.classList.toggle("cat-option-visible");
    });
  });

  let toggler: string;
  if (node.haveChildren || node.children.length != 0) {
    const open_nodes = getAlbumTree().getState().open_nodes;
    if (open_nodes.includes(node.id!)) {
      toggler = toggler_open;
    } else {
      toggler = toggler_close;
    }
    append(
      cont,
      toggler_cont
        .replace(/%content%/g, toggler)
        .replace(/%id%/g, String(node.id)),
    );
  } else {
    addClass(find(cont, ".move-cat-order"), "notClickable");

    append(
      cont,
      toggler_cont
        .replace(/%content%/g, toggler_close)
        .replace(/%id%/g, String(node.id)),
    );
    addClass(cont, "disabledToggle");
  }

  append(cont, icon.replace(/%icon%/g, "icon-grip-vertical-solid"));
  attr(find(cont, ".icon-grip-vertical-solid"), "title", str_albs_drag_drop);

  if (node.haveChildren || node.children.length != 0) {
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
    colors[colorId] + " node-icon",
  );

  after(
    find(cont, ".move-cat-title-container"),
    "<div class='badge-container'>" +
      "<i class='icon-blue icon-sitemap nb-subcats'>" +
      String(node.nb_subcats) +
      "</i>" +
      "<i class='icon-purple icon-picture nb-images'>" +
      node.nb_images +
      "</i>" +
      "<i class='icon-green icon-imagefolder-01 nb-sub-photos'>" +
      node.nb_sub_photos +
      "</i>" +
      "<div class='badge-dropdown'>" +
      "<span class='icon-blue icon-sitemap nb-subcats'>" +
      x_nb_subcats.replace("%d", String(node.nb_subcats)) +
      "</span>" +
      "<span class='icon-purple icon-picture nb-images'>" +
      x_nb_images.replace("%d", String(node.nb_images)) +
      "</span>" +
      "<span class='icon-green icon-imagefolder-01 nb-sub-photos'>" +
      x_nb_sub_photos.replace("%d", String(node.nb_sub_photos)) +
      "</span>" +
      "</div>" +
      "</div>",
  );

  if (!node.nb_subcats) {
    hide(find(cont, ".nb-subcats"));
  }

  if (!(node.nb_images != 0 && node.nb_images)) {
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

function checkbox_change(this: Element) {
  if (attrOf(this, "data-selected") == "1") {
    hide(find(this, "i"));
  } else {
    show(find(this, "i"));
  }
}

function checkbox_click(this: Element) {
  if (attrOf(this, "data-selected") == "1") {
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
function openAddAlbumPopIn(parentAlbumId: string | number) {
  if (parentAlbumId != 0) {
    html(
      document.querySelectorAll("#AddAlbum .AddIconTitle span"),
      add_sub_album_of.replace(
        "%s",
        getAlbumTree().getNodeById(parentAlbumId)!.name,
      ),
    );
  } else {
    html(
      document.querySelectorAll("#AddAlbum .AddIconTitle span"),
      add_album_root_title,
    );
  }
  fadeIn(document.querySelectorAll("#AddAlbum"));
  const modalInput = document.querySelectorAll(
    ".AddAlbumLabelUsername .user-property-input",
  );
  setVal(modalInput, "");
  (modalInput[0] as HTMLElement | undefined)?.focus();
  off(modalInput, "keyup");
  on(modalInput, "keyup", function (this: Element) {
    if (val(this) !== "") {
      hide(document.querySelectorAll(".AddAlbumErrors"));
    }
  });

  const addAlbum = document.querySelectorAll("#AddAlbum");
  off(addAlbum, "keyup");
  on(addAlbum, "keyup", function (e: Event) {
    // 13 is 'Enter'
    if ((e as KeyboardEvent).keyCode === 13) {
      document.querySelector<HTMLElement>(".AddAlbumSubmit")?.click();
    }
    // 27 is 'Escape'
    if ((e as KeyboardEvent).keyCode === 27) {
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
    rename_item.replace("%s", safeName),
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
    if ((e as KeyboardEvent).which == 13) {
      document.querySelector<HTMLElement>(".RenameAlbumSubmit")?.click();
    }
  });
}

function closeRenameAlbumPopIn() {
  fadeOut(document.querySelectorAll("#RenameAlbum"));
}

function triggerDeleteAlbum(cat_id: string | number) {
  void ajax({
    url: "api/v1/categories/" + cat_id + "/orphan-impact",
    type: "GET",
    dataType: "json",
    success: function (
      data: operations["categoryOrphanImpact"]["responses"][200]["content"]["application/json"],
    ) {
      if (data.nbImagesRecursive == 0) {
        hide(document.querySelectorAll(".deleteAlbumOptions"));
      } else {
        show(document.querySelectorAll(".deleteAlbumOptions"));
        if (data.nbImagesAssociatedOutside == 0) {
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
            has_images_associated_outside
              .replace("%d", String(data.nbImagesRecursive))
              .replace("%d", String(data.nbImagesAssociatedOutside)),
          );
        }
        if (data.nbImagesBecomingOrphan == 0) {
          hide(document.querySelectorAll("#IMAGES_BECOMING_ORPHAN"));
        } else {
          html(
            document.querySelectorAll("#IMAGES_BECOMING_ORPHAN .innerText"),
            "",
          );
          append(
            document.querySelectorAll("#IMAGES_BECOMING_ORPHAN .innerText")[0]!,
            has_images_becomming_orphans.replace(
              "%d",
              String(data.nbImagesBecomingOrphan),
            ),
          );
        }
      }
    },
    error: function (message) {
      console.log(message);
    },
  }).done(function () {
    openDeleteAlbumPopIn(cat_id);
  });
}

function openDeleteAlbumPopIn(cat_to_delete: string | number) {
  fadeIn(document.querySelectorAll("#DeleteAlbum"));
  const node = getAlbumTree().getNodeById(cat_to_delete)!;
  if (node.children.length == 0) {
    html(
      document.querySelectorAll(".DeleteIconTitle span"),
      delete_album_with_name.replace("%s", node.name),
    );
  } else {
    const nb_sub_cats = 0;
    html(
      document.querySelectorAll(".DeleteIconTitle span"),
      delete_album_with_subs
        .replace("%s", node.name)
        .replace("%d", String(getAllSubAlbumsFromNode(node, nb_sub_cats))),
    );
  }

  // Actually delete
  const deleteSubmit = document.querySelectorAll(".DeleteAlbumSubmit");
  off(deleteSubmit, "click");
  on(deleteSubmit, "click", function () {
    void ajax({
      url: "api/v1/categories/" + cat_to_delete,
      type: "DELETE",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      data: JSON.stringify({
        photoDeletionMode: val(
          document.querySelectorAll("input[name=photo_deletion_mode]:checked"),
        ),
      }),
      success: function (
        _raw_data: operations["categoryDelete"]["responses"][200]["content"]["application/json"],
      ) {
        // Non-null: same "always a real parent, never bare null in
        // practice" invariant as createAlbumNode()'s own copy of this
        // comment above.
        const parentOfDeletedNode = node.parent!;
        getAlbumTree().removeNode(node);

        rebindMoveCatActions();

        updateTitleBadge(nb_albums - 1);
        setSubcatsBadge(parentOfDeletedNode);
        closeDeleteAlbumPopIn();
      },
      error: function (message) {
        console.log(message);
      },
    });
  });
}

function closeDeleteAlbumPopIn() {
  fadeOut(document.querySelectorAll("#DeleteAlbum"));
}

function getAllSubAlbumsFromNode(
  node: AlbumJqTreeNode,
  nb_sub_cats: number,
): number {
  nb_sub_cats = 0;
  if (node.children.length != 0) {
    node.children.forEach((child) => {
      nb_sub_cats++;
      const tmp = getAllSubAlbumsFromNode(child, nb_sub_cats);
      nb_sub_cats += tmp;
    });
  } else {
    return 0;
  }
  return nb_sub_cats;
}

function setSubcatsBadge(node: AlbumJqTreeNode) {
  if (node.children.length != 0) {
    const nbSubcats = find(
      document.querySelectorAll("#cat-" + node.id),
      ".nb-subcats",
    );
    text(nbSubcats, String(node.children.length));
    show(nbSubcats, 100);
    text(
      find(
        find(document.querySelectorAll("#cat-" + node.id), ".badge-dropdown"),
        ".nb-subcats",
      ),
      x_nb_subcats.replace("%d", String(node.children.length)),
    );
  } else {
    hide(
      find(document.querySelectorAll("#cat-" + node.id), ".nb-subcats"),
      100,
    );
  }
}

function updateTitleBadge(new_nb_albums: number) {
  nb_albums = new_nb_albums;
  text(document.querySelectorAll(".badge-number"), String(new_nb_albums));
}

function goToNode(node: AlbumJqTreeNode, firstNode: AlbumJqTreeNode) {
  // console.log(firstNode.id, node.id);
  if (node.parent) {
    goToNode(node.parent, firstNode);
    if (node != firstNode) {
      getAlbumTree().openNode(node);
      // console.log("parent id : " + node.parent.id);
      show(document.querySelectorAll("#cat-" + node.parent.id));
      addClass(document.querySelectorAll("#cat-" + node.parent.id), "imune");
    }
  } else {
    getAlbumTree().openNode(node);
    addClass(document.querySelectorAll("#cat-" + firstNode.id), "animateFocus");

    showNodeChildrens(firstNode);
  }
}

function showNodeChildrens(node: AlbumJqTreeNode) {
  if (node.children) {
    // console.log("childrens : " + node.children);
    node.children.forEach((child) => {
      // console.log("children : " + child.id, child.name);
      addClass(document.querySelectorAll("#cat-" + child.id), "imune");
      showNodeChildrens(child);
    });
  }
}

// Genuinely dead code -- zero real callers found (confirmed via grep)
// -- typed rather than left broken, same policy as isNumeric() above.
function getId(parent: AlbumJqTreeNode): string | number {
  if (parent.getLevel() == 0) {
    return 0;
  } else {
    // Non-null: every real album node carries a real id (only jqtree's
    // own invisible tree root, excluded by the `getLevel() == 0` branch
    // above, ever lacks one).
    return parent.id!;
  }
}

function getRank(
  node: AlbumJqTreeNode,
  ignoreId: string | number | null = null,
): number {
  const previousSibling = node.getPreviousSibling();
  if (previousSibling != null) {
    if (node.id != ignoreId) {
      return 1 + getRank(previousSibling, ignoreId);
    } else {
      return getRank(previousSibling, ignoreId);
    }
  } else {
    if (node.id != ignoreId) {
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
  const previous_parent = moveInfo.previousParent!;
  const target = moveInfo.targetNode;
  if (moveInfo.position == "after") {
    // Non-null: same "always a real parent" invariant as elsewhere in
    // this file (a move target is never the tree's own invisible root).
    if (getId(previous_parent) != getId(target.parent!)) {
      moveParent = getId(target.parent!);
    }
    moveRank = getRank(target, id) + 1;
  } else if (moveInfo.position == "inside") {
    if (getId(previous_parent) != getId(target)) {
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
  } else if (moveInfo.position == "before") {
    if (getId(previous_parent) != getId(target.parent!)) {
      moveParent = getId(target.parent!);
    }
    moveRank = 1;
  }
  moveNode(id, moveRank, moveParent)
    .then(() => {
      moveInfo.doMove();
      clearTimeout(waitingTimeout);
      removeClass(document.querySelectorAll(".waiting-message"), "visible");
      setSubcatsBadge(previous_parent);
      // Was an unconditional `setSubcatsBadge($(".tree").tree
      // ("getNodeById", moveParent))` -- `moveParent` stays `null` for
      // a same-parent rank-only reorder (see the 3 branches above), and
      // `getNodeById(null)` resolves to no real node, so this crashed
      // on `.children.length` inside setSubcatsBadge() every time an
      // album was drag-reordered among its own siblings without
      // changing parent -- silently aborting the rest of this
      // `.then()` (the click-handler rebinds below never ran). Guarded:
      // when the parent didn't change, `previous_parent`'s own
      // already-refreshed badge above covers it.
      if (moveParent !== null) {
        setSubcatsBadge(getAlbumTree().getNodeById(moveParent)!);
      }

      rebindMoveCatActions();
    })
    .catch(function (message: unknown) {
      console.log(
        "An error has occured : " +
          (message instanceof Error ? message.message : String(message)),
      );
      rebindMoveCatActions();
    });
}

function moveNode(
  node: string | number,
  rank: number | null,
  parent: string | number | null,
) {
  return new Promise<void>((res, rej) => {
    if (parent != null) {
      changeParent(node, parent, rank)
        .then(() => {
          res();
        })
        .catch(() => {
          rej(new Error("move failed"));
        });
    } else if (rank != null) {
      changeRank(node, rank)
        .then(() => {
          res();
        })
        .catch(() => {
          rej(new Error("move failed"));
        });
    }
  });
}

function changeParent(
  node: string | number,
  parent: string | number,
  rank: number | null,
) {
  return new Promise<void>((res, rej) => {
    void ajax({
      url: "api/v1/categories/actions/move",
      type: "POST",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      data: JSON.stringify({
        categoryIds: [Number(node)],
        parentId: Number(parent),
      }),
      dataType: "json",
      success: function (
        data: operations["categoryMove"]["responses"][200]["content"]["application/json"],
      ) {
        void changeRank(node, rank);
        const updatedCategories = data.updatedCategories;
        if (updatedCategories) {
          updatedCategories.forEach((cat) => {
            const catNode = getAlbumTree().getNodeById(cat.categoryId)!;
            catNode.nb_sub_photos = cat.nbSubPhotos;
            getAlbumTree().updateNode(catNode, catNode.name);
          });
        }
        res();
      },
      error: function (message) {
        rej(new Error(message.statusText || "move failed"));
      },
    });
  });
}

function changeRank(node: string | number, rank: number | null) {
  return new Promise<void>((res, rej) => {
    void ajax({
      url: "api/v1/categories/actions/reorder",
      type: "POST",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      data: JSON.stringify({
        categoryIds: [Number(node)],
        rank: Number(rank),
      }),
      dataType: "json",
      success: function (_data: unknown) {
        res();
      },
      error: function (message) {
        rej(new Error(message.statusText || "rank change failed"));
      },
    });
  });
}

function makePrivateHierarchy(node: AlbumJqTreeNode) {
  node.status = "private";
  node.children.forEach((node) => {
    makePrivateHierarchy(node);
  });
}

function getPathNode(node: AlbumJqTreeNode): string {
  // Non-null: same "always a real parent" invariant as elsewhere in
  // this file.
  if (node.parent!.getLevel() != 0) {
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
    if (album.id == id) {
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
  const open_nodes = getAlbumTree().getState().open_nodes;
  // Was `open_nodes.includes(node)` -- comparing the whole node object
  // against a list of plain ids, which real strict typing rejects
  // outright (`(string | number)[]` vs an object). Always evaluated to
  // `false`, so the "already open" guard below never actually skipped
  // anything; harmless in practice (jqtree's own `openNode()` no-ops on
  // an already-open node), but not the real intended check.
  if (!open_nodes.includes(node.id!)) {
    getAlbumTree().openNode(node);
    rebindMoveCatActions();
  }
}

// `data` is a real export now (docs/PLAN.md P48) -- cat_search.ts
// imports it directly, no more `window.` latching.
