export {};

const data = pwg_getPageData("album_data");
const pwg_token = pwg_getPageData("csrf_token");
const str_are_you_sure = pwg_getPageString(
  "The status of the album '%s' and its sub-albums will change to private. Are you sure?",
);
const str_yes_change_parent = pwg_getPageString("Yes change parent anyway");
const str_no_change_parent = pwg_getPageString(
  "No, don't move this album here",
);
const str_root = pwg_getPageString("Root");
const openCat = pwg_getPageData("open_cat");
let nb_albums = pwg_getPageData("nb_albums");
const light_album_manager = pwg_getPageData("light_album_manager");

const x_nb_subcats = pwg_getPageString("%d sub-albums");
const x_nb_images = pwg_getPageString("%d photos");
const x_nb_sub_photos = pwg_getPageString("%d pictures in sub-albums");

const str_albums_found = pwg_getPageString("<b>%d</b> albums found");
const str_album_found = pwg_getPageString("<b>1</b> album found");
const str_result_limit = pwg_getPageString(
  "<b>%d+</b> albums found, try to refine the search",
);
const str_albs_drag_drop = pwg_getPageString("Drag and drop to reorder albums");

const delay_autoOpen = pwg_getPageData("delay_auto_open");

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

$(document).ready(() => {
  const openUppercats =
    openCat == -1 ? [] : findAlbumById(data, openCat).uppercats.split(",");
  const new_data = data.map((a: any) => {
    const al = {
      ...a,
      children: openUppercats.includes(a.id) ? a.children : [],
    };
    if (a.children) {
      al.load_on_demand = openUppercats.includes(a.id) ? false : true;
      al.haveChildren = a.children;
    }
    return al;
  });

  $("h1").append(`<span class='badge-number'>` + nb_albums + `</span>`);

  $(".tree").tree({
    data: new_data,
    autoOpen: false,
    dragAndDrop: true,
    openFolderDelay: delay_autoOpen,
    onCreateLi: createAlbumNode,
    onCanSelectNode: function (node: any) {
      return false;
    },
  });

  $(".tree").on("click", ".move-cat-toogler", function (e) {
    const node_id = $(this).attr("data-id");
    const node: any = $(".tree").tree("getNodeById", node_id);

    if (node.load_on_demand && node.haveChildren) {
      loadOnDemand(node);
    }

    if (node) {
      const open_nodes: any = $(".tree").tree("getState").open_nodes;
      if (!open_nodes.includes(node_id)) {
        $(this).html(toggler_open);
        $(".tree").tree("openNode", node);
        // reset event here:
        $(".move-cat-add")
          .off("click")
          .on("click", function (e) {
            e.preventDefault();
            openAddAlbumPopIn($(this).data("aid"));
            $(".AddAlbumSubmit").data("a-parent", $(this).data("aid"));
          });
        $(".move-cat-delete")
          .off("click")
          .on("click", function () {
            triggerDeleteAlbum($(this).data("id"));
          });
        $(".move-cat-title-container")
          .off("click")
          .on("click", function () {
            openRenameAlbumPopIn($(this).find(".move-cat-title").attr("title"));
            $(".RenameAlbumSubmit").data("cat_id", $(this).attr("data-id")!);
          });
      } else {
        $(this).html(toggler_close);
        $(".tree").tree("closeNode", node);
      }
    }
  });

  $(".tree").on("tree.open", function (e: any) {
    $(".move-cat-toogler[data-id=" + e.node.id + "]").html(toggler_open);
  });

  $(".tree").on("tree.close", function (e: any) {
    $(".move-cat-toogler[data-id=" + e.node.id + "]").html(toggler_close);
  });

  $(".tree").on("tree.move", function (event: any) {
    event.preventDefault();

    if (event.move_info.moved_node.status != "private") {
      let parentIsPrivate = false;
      if (event.move_info.position == "after") {
        parentIsPrivate =
          event.move_info.target_node.parent.status == "private";
      } else if (event.move_info.position == "inside") {
        parentIsPrivate = event.move_info.target_node.status == "private";
      }

      if (parentIsPrivate) {
        $.confirm({
          title: str_are_you_sure.replace(
            /%s/g,
            event.move_info.moved_node.name,
          ),
          buttons: {
            confirm: {
              text: str_yes_change_parent,
              btnClass: "btn-red",
              action: function () {
                makePrivateHierarchy(event.move_info.moved_node);
                applyMove(event);
              },
            },
            cancel: {
              text: str_no_change_parent,
            },
          },
          ...jConfirm_confirm_options,
        });
      } else {
        applyMove(event);
      }
    } else {
      applyMove(event);
    }
  });

  $(".tree").on("click", ".move-cat-order", function (e) {
    const node_id = $(this).attr("data-id");
    const node: any = $(".tree").tree("getNodeById", node_id);
    if (node) {
      $(".cat-move-order-popin").fadeIn();
      $(".cat-move-order-popin .album-name").html(getPathNode(node));
      $(".cat-move-order-popin input[name=id]").val(node_id!);
      $("input[name=simpleAutoOrder]").attr("value", str_sub_album_order);
    }
  });

  $(".order-root").on("click", function () {
    $(".cat-move-order-popin").fadeIn();
    $(".cat-move-order-popin .album-name").html(str_root);
    $(".cat-move-order-popin input[name=id]").val(-1);
    $("input[name=simpleAutoOrder]").attr("value", str_root_order);
  });

  $(".tree").on("mousedown mouseup", function mouseState(e: any) {
    if (e.type == "mousedown") {
      $(".tree").addClass("dragging");
    } else if (e.type == "mouseup") {
      $(".dragging").removeClass("dragging");
    }
  });

  if (openCat != -1) {
    const nodeToGo: any = $(".tree").tree("getNodeById", openCat);

    goToNode(nodeToGo, nodeToGo);
    if (nodeToGo.children) {
      $(".tree").tree("openNode", nodeToGo, false);
    }

    $([document.documentElement, document.body]).animate(
      {
        scrollTop:
          $("#cat-" + openCat).offset()!.top -
          $(window).height()! / 2 +
          $("#cat-" + openCat).outerHeight()! / 2,
      },
      500,
    );
  }

  // RenameAlbumPopIn
  $(".RenameAlbumErrors").hide();
  $(".move-cat-title-container").on("click", function () {
    openRenameAlbumPopIn($(this).find(".move-cat-title").attr("title"));
    $(".RenameAlbumSubmit").data("cat_id", $(this).attr("data-id")!);
  });
  $(".CloseRenameAlbum").on("click", function () {
    closeRenameAlbumPopIn();
  });
  $(".RenameAlbumCancel").on("click", function () {
    closeRenameAlbumPopIn();
  });

  $(".RenameAlbumSubmit").on("click", function () {
    const catToEdit = $(this).data("cat_id");
    jQuery.ajax({
      url: "api/v1/categories/" + catToEdit,
      type: "PATCH",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      data: JSON.stringify({
        name: $(".RenameAlbumLabelUsername input").val(),
      }),
      dataType: "json",
      success: function (data: any) {
        const node_id = $("#cat-" + catToEdit)
          .find(".move-cat-toogler")
          .attr("data-id");
        const node: any = $(".tree").tree("getNodeById", node_id);
        node.name = $(".RenameAlbumLabelUsername input").val();
        $(".tree").tree(
          "updateNode",
          node,
          $(".RenameAlbumLabelUsername input").val(),
        );

        $(".move-cat-title-container").on("click", function () {
          openRenameAlbumPopIn($(this).find(".move-cat-title").attr("title"));
          $(".RenameAlbumSubmit").data("cat_id", $(this).attr("data-id")!);
        });

        $(".move-cat-add")
          .off("click")
          .on("click", function (e) {
            e.preventDefault();
            openAddAlbumPopIn($(this).data("aid"));
            $(".AddAlbumSubmit").data("a-parent", $(this).data("aid"));
          });

        closeRenameAlbumPopIn();
      },
      error: function (message: any) {
        console.log(message);
      },
    });
  });

  // AddAlbumPopIn
  $(".AddAlbumErrors").hide();
  $(".DeleteAlbumErrors").hide();
  $(".add-album-button").on("click", function () {
    openAddAlbumPopIn(0);
    $(".AddAlbumSubmit").data("a-parent", 0);
  });
  $(".move-cat-add").on("click", function (e) {
    e.preventDefault();
    openAddAlbumPopIn($(this).data("aid"));
    $(".AddAlbumSubmit").data("a-parent", $(this).data("aid"));
  });
  $(".CloseAddAlbum").on("click", function () {
    closeAddAlbumPopIn();
  });
  $(".AddAlbumCancel").on("click", function () {
    closeAddAlbumPopIn();
  });
  $(".DeleteAlbumCancel").on("click", function () {
    closeDeleteAlbumPopIn();
  });

  $(".AddAlbumSubmit").on("click", function () {
    $(this).addClass("notClickable");

    const newAlbumName = $(".AddAlbumLabelUsername input").val();
    const newAlbumParent = $(".AddAlbumSubmit").data("a-parent");
    const newAlbumPosition = $("input[name=position]:checked").val();

    jQuery.ajax({
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
      success: function (data: any) {
        const parent_node: any = $(".tree").tree("getNodeById", newAlbumParent);
        if (
          parent_node &&
          parent_node.load_on_demand &&
          parent_node.haveChildren
        ) {
          loadOnDemand(parent_node);
        }
        if (parent_node) openNodeOnDemand(parent_node);

        if (newAlbumPosition == "last") {
          $(".tree").tree(
            "appendNode",
            {
              id: data.id,
              isEmptyFolder: true,
              name: newAlbumName,
            },
            parent_node,
          );
        } else {
          $(".tree").tree(
            "prependNode",
            {
              id: data.id,
              isEmptyFolder: true,
              name: newAlbumName,
            },
            parent_node,
          );
        }

        if (parent_node) {
          setSubcatsBadge(parent_node);

          $("#cat-" + parent_node.id).on(
            "click",
            ".move-cat-toogler",
            function (e) {
              const node_id = parent_node.id;
              const node: any = $(".tree").tree("getNodeById", node_id);
              if (node) {
                const open_nodes: any = $(".tree").tree("getState").open_nodes;
                if (!open_nodes.includes(node_id)) {
                  $(this).html(toggler_open);
                  $(".tree").tree("openNode", node);
                } else {
                  $(this).html(toggler_close);
                  $(".tree").tree("closeNode", node);
                }
              }
            },
          );
        }

        $(".move-cat-add")
          .off("click")
          .on("click", function (e) {
            e.preventDefault();
            openAddAlbumPopIn($(this).data("aid"));
            $(".AddAlbumSubmit").data("a-parent", $(this).data("aid"));
          });
        $(".move-cat-delete").on("click", function () {
          triggerDeleteAlbum($(this).data("id"));
        });
        $(".move-cat-title-container")
          .unbind("click")
          .on("click", function () {
            openRenameAlbumPopIn($(this).find(".move-cat-title").attr("title"));
            $(".RenameAlbumSubmit").data("cat_id", $(this).attr("data-id")!);
          });
        $(".tiptip").tipTip({
          delay: 0,
          fadeIn: 200,
          fadeOut: 200,
          edgeOffset: 3,
        });

        updateTitleBadge(nb_albums + 1);

        goToNode(
          $(".tree").tree("getNodeById", data.id),
          $(".tree").tree("getNodeById", data.id),
        );
        $("html,body").animate(
          {
            scrollTop: $("#cat-" + data.id).offset()!.top - screen.height / 2,
          },
          "slow",
        );

        closeAddAlbumPopIn();
        $(".AddAlbumSubmit").removeClass("notClickable");
      },
      error: function (message: any) {
        console.log(message);
        $(".AddAlbumErrors").text(str_album_name_empty).show();
        $(".AddAlbumSubmit").removeClass("notClickable");
      },
    });
  });

  // Delete Album
  $(".move-cat-delete").on("click", function () {
    triggerDeleteAlbum($(this).data("id"));
  });

  $(".user-list-checkbox").unbind("change").change(checkbox_change);
  $(".user-list-checkbox").unbind("click").click(checkbox_click);

  if (!light_album_manager) {
    $(".tiptip").tipTip({
      delay: 0,
      fadeIn: 200,
      fadeOut: 200,
      edgeOffset: 3,
    });
  }
});

function createAlbumNode(node: any, li: any) {
  const icon = "<span class='%icon%'></span>";
  let title =
    '<span data-id="' + node.id + '" class="move-cat-title-container ';
  if (node.status == "private" || node.parent.status == "private") {
    node.status = "private";
    title += "icon-lock";
  }
  title += '">';
  if (node.visible == "false" || node.parent.visble == "false") {
    node.visble = "false";
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

  const cont = li.find(".jqtree-element");
  cont.addClass("move-cat-container");
  cont.attr("id", "cat-" + node.id);
  cont.html("");

  cont.append(actions);

  cont.find(".toggle-cat-option").on("click", function (this: any) {
    $(".cat-option").hide();
    $(this).find(".cat-option").toggle();
  });

  let toggler: string;
  if (node.haveChildren || node.children.length != 0) {
    const open_nodes: any = $(".tree").tree("getState").open_nodes;
    if (open_nodes.includes(node.id)) {
      toggler = toggler_open;
    } else {
      toggler = toggler_close;
    }
    cont.append(
      $(toggler_cont.replace(/%content%/g, toggler).replace(/%id%/g, node.id)),
    );
  } else {
    cont.find(".move-cat-order").addClass("notClickable");

    cont
      .append(
        $(
          toggler_cont
            .replace(/%content%/g, toggler_close)
            .replace(/%id%/g, node.id),
        ),
      )
      .addClass("disabledToggle");
  }

  cont.append($(icon.replace(/%icon%/g, "icon-grip-vertical-solid")));
  cont.find(".icon-grip-vertical-solid").attr("title", str_albs_drag_drop);

  if (node.haveChildren || node.children.length != 0) {
    cont.append($(icon.replace(/%icon%/g, "icon-sitemap")));
  } else {
    cont.append($(icon.replace(/%icon%/g, "icon-folder-open")));
  }

  cont.append($(title.replace(/%name%/g, node.name)));

  const colors = [
    "icon-red",
    "icon-blue",
    "icon-yellow",
    "icon-purple",
    "icon-green",
  ];
  const colorId = Number(node.id) % 5;
  cont
    .find("span.icon-folder-open, span.icon-sitemap")
    .addClass(colors[colorId]!)
    .addClass("node-icon");

  cont
    .find(".move-cat-title-container")
    .after(
      "<div class='badge-container'>" +
        "<i class='icon-blue icon-sitemap nb-subcats'>" +
        node.nb_subcats +
        "</i>" +
        "<i class='icon-purple icon-picture nb-images'>" +
        node.nb_images +
        "</i>" +
        "<i class='icon-green icon-imagefolder-01 nb-sub-photos'>" +
        node.nb_sub_photos +
        "</i>" +
        "<div class='badge-dropdown'>" +
        "<span class='icon-blue icon-sitemap nb-subcats'>" +
        x_nb_subcats.replace("%d", node.nb_subcats) +
        "</span>" +
        "<span class='icon-purple icon-picture nb-images'>" +
        x_nb_images.replace("%d", node.nb_images) +
        "</span>" +
        "<span class='icon-green icon-imagefolder-01 nb-sub-photos'>" +
        x_nb_sub_photos.replace("%d", node.nb_sub_photos) +
        "</span>" +
        "</div>" +
        "</div>",
    );

  if (!node.nb_subcats) {
    cont.find(".nb-subcats").hide();
  }

  if (!(node.nb_images != 0 && node.nb_images)) {
    cont.find(".nb-images").hide();
  }

  if (!node.nb_sub_photos) {
    cont.find(".nb-sub-photos").hide();
  }

  if (node.has_not_access) {
    cont.find(".move-cat-see").addClass("notClickable");
  }
}

/*----------------
Checkboxes
----------------*/

function checkbox_change(this: HTMLElement) {
  if ($(this).attr("data-selected") == "1") {
    $(this).find("i").hide();
  } else {
    $(this).find("i").show();
  }
}

function checkbox_click(this: HTMLElement) {
  if ($(this).attr("data-selected") == "1") {
    $(this).attr("data-selected", "0");
    $(this).find("i").hide();
  } else {
    $(this).attr("data-selected", "1");
    $(this).find("i").show();
  }
}

function isNumeric(num: any) {
  return !isNaN(num);
}

function openAddAlbumPopIn(parentAlbumId: any) {
  if (parentAlbumId != 0) {
    $("#AddAlbum .AddIconTitle span").html(
      add_sub_album_of.replace(
        "%s",
        $(".tree").tree("getNodeById", parentAlbumId).name,
      ),
    );
  } else {
    $("#AddAlbum .AddIconTitle span").html(add_album_root_title);
  }
  $("#AddAlbum").fadeIn();
  const modalInput = $(".AddAlbumLabelUsername .user-property-input");
  modalInput.val("");
  modalInput.trigger("focus");
  modalInput.off("keyup").on("keyup", function () {
    if ($(this).val() !== "") {
      $(".AddAlbumErrors").hide();
    }
  });

  $("#AddAlbum").unbind("keyup");
  $("#AddAlbum").on("keyup", function (e: any) {
    // 13 is 'Enter'
    if (e.keyCode === 13) {
      $(".AddAlbumSubmit").trigger("click");
    }
    // 27 is 'Escape'
    if (e.keyCode === 27) {
      closeAddAlbumPopIn();
    }
  });
}

function closeAddAlbumPopIn() {
  $("#AddAlbum").fadeOut(function () {
    $(".AddAlbumErrors").hide();
  });
}

function openRenameAlbumPopIn(replacedAlbumName: any) {
  $("#RenameAlbum").fadeIn();
  $(".RenameAlbumTitle span").html(
    rename_item.replace("%s", replacedAlbumName),
  );
  $(".RenameAlbumLabelUsername .user-property-input").val(replacedAlbumName);
  $(".RenameAlbumLabelUsername .user-property-input").focus();

  $(document)
    .unbind("keypress")
    .on("keypress", function (e: any) {
      if (e.which == 13) {
        $(".RenameAlbumSubmit").trigger("click");
      }
    });
}

function closeRenameAlbumPopIn() {
  $("#RenameAlbum").fadeOut();
}

function triggerDeleteAlbum(cat_id: any) {
  $.ajax({
    url: "api/v1/categories/" + cat_id + "/orphan-impact",
    type: "GET",
    dataType: "json",
    success: function (data: any) {
      if (data.nbImagesRecursive == 0) {
        $(".deleteAlbumOptions").hide();
      } else {
        $(".deleteAlbumOptions").show();
        if (data.nbImagesAssociatedOutside == 0) {
          $("#IMAGES_ASSOCIATED_OUTSIDE").hide();
        } else {
          $("#IMAGES_ASSOCIATED_OUTSIDE .innerText").html("");
          $("#IMAGES_ASSOCIATED_OUTSIDE .innerText").append(
            has_images_associated_outside
              .replace("%d", data.nbImagesRecursive)
              .replace("%d", data.nbImagesAssociatedOutside),
          );
        }
        if (data.nbImagesBecomingOrphan == 0) {
          $("#IMAGES_BECOMING_ORPHAN").hide();
        } else {
          $("#IMAGES_BECOMING_ORPHAN .innerText").html("");
          $("#IMAGES_BECOMING_ORPHAN .innerText").append(
            has_images_becomming_orphans.replace(
              "%d",
              data.nbImagesBecomingOrphan,
            ),
          );
        }
      }
    },
    error: function (message: any) {
      console.log(message);
    },
  }).done(function () {
    openDeleteAlbumPopIn(cat_id);
  });
}

function openDeleteAlbumPopIn(cat_to_delete: any) {
  $("#DeleteAlbum").fadeIn();
  const node: any = $(".tree").tree("getNodeById", cat_to_delete);
  if (node.children.length == 0) {
    $(".DeleteIconTitle span").html(
      delete_album_with_name.replace("%s", node.name),
    );
  } else {
    const nb_sub_cats = 0;
    $(".DeleteIconTitle span").html(
      delete_album_with_subs
        .replace("%s", node.name)
        .replace("%d", getAllSubAlbumsFromNode(node, nb_sub_cats)),
    );
  }

  // Actually delete
  $(".DeleteAlbumSubmit")
    .unbind("click")
    .on("click", function () {
      $.ajax({
        url: "api/v1/categories/" + cat_to_delete,
        type: "DELETE",
        contentType: "application/json",
        headers: { "X-CSRF-Token": pwg_token },
        data: JSON.stringify({
          photoDeletionMode: $("input[name=photo_deletion_mode]:checked").val(),
        }),
        success: function (raw_data: any) {
          const parentOfDeletedNode = node.parent;
          $(".tree").tree("removeNode", node);

          $(".move-cat-add").on("click", function (e) {
            e.preventDefault();
            openAddAlbumPopIn($(this).data("aid"));
            $(".AddAlbumSubmit").data("a-parent", $(this).data("aid"));
          });
          $(".move-cat-delete").on("click", function () {
            triggerDeleteAlbum($(this).data("id"));
          });
          $(".move-cat-title-container")
            .unbind("click")
            .on("click", function () {
              openRenameAlbumPopIn(
                $(this).find(".move-cat-title").attr("title"),
              );
              $(".RenameAlbumSubmit").data("cat_id", $(this).attr("data-id")!);
            });
          $(".tiptip").tipTip({
            delay: 0,
            fadeIn: 200,
            fadeOut: 200,
            edgeOffset: 3,
          });

          updateTitleBadge(nb_albums - 1);
          setSubcatsBadge(parentOfDeletedNode);
          closeDeleteAlbumPopIn();
        },
        error: function (message: any) {
          console.log(message);
        },
      });
    });
}

function closeDeleteAlbumPopIn() {
  $("#DeleteAlbum").fadeOut();
}

function getAllSubAlbumsFromNode(node: any, nb_sub_cats: any) {
  nb_sub_cats = 0;
  if (node.children != 0) {
    node.children.forEach((child: any) => {
      nb_sub_cats++;
      const tmp = getAllSubAlbumsFromNode(child, nb_sub_cats);
      nb_sub_cats += tmp;
    });
  } else {
    return 0;
  }
  return nb_sub_cats;
}

function setSubcatsBadge(node: any) {
  if (node.children.length != 0) {
    $("#cat-" + node.id)
      .find(".nb-subcats")
      .text(node.children.length)
      .show(100);
    $("#cat-" + node.id)
      .find(".badge-dropdown")
      .find(".nb-subcats")
      .text(x_nb_subcats.replace("%d", node.children.length));
  } else {
    $("#cat-" + node.id)
      .find(".nb-subcats")
      .hide(100);
  }
}

function updateTitleBadge(new_nb_albums: any) {
  nb_albums = new_nb_albums;
  $(".badge-number").text(new_nb_albums);
}

function goToNode(node: any, firstNode: any) {
  // console.log(firstNode.id, node.id);
  if (node.parent) {
    goToNode(node.parent, firstNode);
    if (node != firstNode) {
      $(".tree").tree("openNode", node);
      // console.log("parent id : " + node.parent.id);
      $("#cat-" + node.parent.id).show();
      $("#cat-" + node.parent.id).addClass("imune");
    }
  } else {
    $(".tree").tree("openNode", node);
    $("#cat-" + firstNode.id).addClass("animateFocus");

    showNodeChildrens(firstNode);
  }
}

function showNodeChildrens(node: any) {
  if (node.children) {
    // console.log("childrens : " + node.children);
    node.children.forEach((child: any) => {
      // console.log("children : " + child.id, child.name);
      $("#cat-" + child.id).addClass("imune");
      showNodeChildrens(child);
    });
  }
}

function closeTree(tree: any) {
  // console.log(tree);
  if (tree.tree("getState").open_nodes.length > 0) {
    tree.tree("getState").open_nodes.forEach((nodeItem: any) => {
      const node = tree.tree("getNodeById", nodeItem);
      tree.tree("closeNode", node);
    });
  }
}

function getId(parent: any) {
  if (parent.getLevel() == 0) {
    return 0;
  } else {
    return parent.id;
  }
}

function getRank(node: any, ignoreId: any = null): number {
  if (node.getPreviousSibling() != null) {
    if (node.id != ignoreId) {
      return 1 + getRank(node.getPreviousSibling(), ignoreId);
    } else {
      return getRank(node.getPreviousSibling(), ignoreId);
    }
  } else {
    if (node.id != ignoreId) {
      return 1;
    } else {
      return 0;
    }
  }
}

function applyMove(event: any) {
  const waitingTimeout = setTimeout(() => {
    $(".waiting-message").addClass("visible");
  }, 500);
  const id = event.move_info.moved_node.id;
  let moveParent: any = null;
  let moveRank: any = null;
  const previous_parent = event.move_info.previous_parent;
  const target = event.move_info.target_node;
  if (event.move_info.position == "after") {
    if (getId(previous_parent) != getId(target.parent)) {
      moveParent = getId(target.parent);
    }
    moveRank = getRank(target, id) + 1;
  } else if (event.move_info.position == "inside") {
    if (getId(previous_parent) != getId(target)) {
      moveParent = getId(target);
      const currentNode = $(".tree").tree("getNodeById", moveParent);
      if (
        currentNode &&
        currentNode.load_on_demand &&
        currentNode.haveChildren
      ) {
        loadOnDemand(currentNode);
      }
    }
    moveRank = 1;
  } else if (event.move_info.position == "before") {
    if (getId(previous_parent) != getId(target.parent)) {
      moveParent = getId(target.parent);
    }
    moveRank = 1;
  }
  moveNode(id, moveRank, moveParent)
    .then(() => {
      event.move_info.do_move();
      clearTimeout(waitingTimeout);
      $(".waiting-message").removeClass("visible");
      setSubcatsBadge(previous_parent);
      setSubcatsBadge($(".tree").tree("getNodeById", moveParent));

      $(".move-cat-add")
        .off("click")
        .on("click", function (e) {
          e.preventDefault();
          openAddAlbumPopIn($(this).data("aid"));
          $(".AddAlbumSubmit").data("a-parent", $(this).data("aid"));
        });
      $(".move-cat-delete").on("click", function () {
        triggerDeleteAlbum($(this).data("id"));
      });
      $(".move-cat-title-container").on("click", function () {
        openRenameAlbumPopIn($(this).find(".move-cat-title").attr("title"));
        $(".RenameAlbumSubmit").data("cat_id", $(this).attr("data-id")!);
      });
      $(".tiptip").tipTip({
        delay: 0,
        fadeIn: 200,
        fadeOut: 200,
        edgeOffset: 3,
      });
    })
    .catch(function (message: any) {
      console.log("An error has occured : " + message);
      $(".move-cat-add")
        .off("click")
        .on("click", function (e) {
          e.preventDefault();
          openAddAlbumPopIn($(this).data("aid"));
          $(".AddAlbumSubmit").data("a-parent", $(this).data("aid"));
        });
      $(".move-cat-delete").on("click", function () {
        triggerDeleteAlbum($(this).data("id"));
      });
      $(".move-cat-title-container").on("click", function () {
        openRenameAlbumPopIn($(this).find(".move-cat-title").attr("title"));
        $(".RenameAlbumSubmit").data("cat_id", $(this).attr("data-id")!);
      });
      $(".tiptip").tipTip({
        delay: 0,
        fadeIn: 200,
        fadeOut: 200,
        edgeOffset: 3,
      });
    });
}

function moveNode(node: any, rank: any, parent: any) {
  return new Promise<void>((res, rej) => {
    if (parent != null) {
      changeParent(node, parent, rank)
        .then(() => res())
        .catch(() => rej(new Error("move failed")));
    } else if (rank != null) {
      changeRank(node, rank)
        .then(() => res())
        .catch(() => rej(new Error("move failed")));
    }
  });
}

function changeParent(node: any, parent: any, rank: any) {
  let oldParent = node.parent;
  return new Promise<void>((res, rej) => {
    jQuery.ajax({
      url: "api/v1/categories/actions/move",
      type: "POST",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      data: JSON.stringify({
        categoryIds: [Number(node)],
        parentId: Number(parent),
      }),
      dataType: "json",
      success: function (data: any) {
        oldParent = node.parent;
        void changeRank(node, rank);
        const updatedCategories = data.updatedCategories;
        if (updatedCategories) {
          updatedCategories.forEach((cat: any) => {
            const node: any = $(".tree").tree("getNodeById", cat.categoryId);
            node.nb_sub_photos = cat.nbSubPhotos;
            $(".tree").tree("updateNode", node, node.name);
          });
        }
        res();
      },
      error: function (message: any) {
        rej(new Error(message?.statusText || "move failed"));
      },
    });
  });
}

function changeRank(node: any, rank: any) {
  return new Promise<void>((res, rej) => {
    jQuery.ajax({
      url: "api/v1/categories/actions/reorder",
      type: "POST",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      data: JSON.stringify({
        categoryIds: [Number(node)],
        rank: Number(rank),
      }),
      dataType: "json",
      success: function (_data: any) {
        res();
      },
      error: function (message: any) {
        rej(new Error(message?.statusText || "rank change failed"));
      },
    });
  });
}

function makePrivateHierarchy(node: any) {
  node.status = "private";
  node.children.forEach((node: any) => {
    makePrivateHierarchy(node);
  });
}

function getPathNode(node: any): string {
  if (node.parent.getLevel() != 0) {
    return getPathNode(node.parent) + " / " + node.name;
  } else {
    return node.name;
  }
}

function findAlbumById(a: any, id: any): any {
  for (const album of a) {
    if (album.id == id) {
      return album;
    }

    if (
      (album.haveChildren && album.haveChildren.length > 0) ||
      (album.children && album.children.length > 0)
    ) {
      const al = findAlbumById(album.haveChildren ?? album.children, id);
      if (al) {
        return al;
      }
    }
  }
  return null;
}

function loadOnDemand(node: any) {
  const children = node.haveChildren;
  const formatedChild = children.map((a: any) => {
    const al = { ...a, children: [] };
    if (a.children) {
      al.load_on_demand = true;
      al.haveChildren = a.children;
    }
    return al;
  });

  $(".tree").tree("loadData", formatedChild, node);
  node.load_on_demand = false;
}

function openNodeOnDemand(node: any) {
  const open_nodes: any = $(".tree").tree("getState").open_nodes;
  if (!open_nodes.includes(node)) {
    $(".tree").tree("openNode", node);
    $(".move-cat-add")
      .off("click")
      .on("click", function (e) {
        e.preventDefault();
        openAddAlbumPopIn($(this).data("aid"));
        $(".AddAlbumSubmit").data("a-parent", $(this).data("aid"));
      });
    $(".move-cat-delete")
      .off("click")
      .on("click", function () {
        triggerDeleteAlbum($(this).data("id"));
      });
    $(".move-cat-title-container")
      .off("click")
      .on("click", function () {
        openRenameAlbumPopIn($(this).find(".move-cat-title").attr("title"));
        $(".RenameAlbumSubmit").data("cat_id", $(this).attr("data-id")!);
      });
  }
}

// Explicit `window.` exposure -- required at runtime, not decorative
// (see plugins_installed_config.ts's own leading comment for the full
// explanation: this file's own top-level declarations become
// IIFE-private at build time, so a bare cross-file read needs this to
// resolve). cat_search.ts reads both of these bare.
window.data = data;
window.str_album_found = str_album_found;
