document.addEventListener('DOMContentLoaded', () => {
    formatedData = data;

    document.querySelector("h1").appendChild(document.createElement("span")).className = "badge-number";
    document.querySelector("h1 .badge-number").textContent = nb_albums;

    var treeEl = document.querySelector('.tree');

    var pwgTree = new PwgTree(treeEl, {
        data: formatedData,
        autoOpen: false,
        dragAndDrop: true,
        openFolderDelay: delay_autoOpen,
        onCreateLi: createAlbumNode,
        onCanSelectNode: function (node) {
            return false;
        },
    });

    treeEl.addEventListener('click', function (e) {
        var toggler = e.target.closest('.move-cat-toggler');
        if (!toggler) return;
        var node_id = toggler.getAttribute('data-id');
        var node = pwgTree.getNodeById(node_id);
        if (node) {
            var open_nodes = pwgTree.getState().open_nodes;
            if (!open_nodes.includes(node_id)) {
                toggler.innerHTML = toggler_open;
                pwgTree.openNode(node);
            } else {
                toggler.innerHTML = toggler_close;
                pwgTree.closeNode(node);
            }
        }
    });

    treeEl.addEventListener('tree.open', function (e) {
        var el = document.querySelector('.move-cat-toggler[data-id="' + e.node.id + '"]');
        if (el) el.innerHTML = toggler_open;
    });

    treeEl.addEventListener('tree.close', function (e) {
        var el = document.querySelector('.move-cat-toggler[data-id="' + e.node.id + '"]');
        if (el) el.innerHTML = toggler_close;
    });

    treeEl.addEventListener('tree.move', function (e) {
        var event = { move_info: e.move_info };

        if (event.move_info.moved_node.status != "private") {
            var parentIsPrivate = false;
            if (event.move_info.position == "after") {
                parentIsPrivate =
                    event.move_info.target_node.parent.status == "private";
            } else if (event.move_info.position == "inside") {
                parentIsPrivate =
                    event.move_info.target_node.status == "private";
            }

            if (parentIsPrivate) {
                pwgConfirm({
                    title: str_are_you_sure.replace(
                        /%s/g,
                        event.move_info.moved_node.name,
                    ),
                    buttons: {
                        confirm: {
                            text: str_yes_change_parent,
                            btnClass: "btn-red",
                            action: function () {
                                makePrivateHierarchy(
                                    event.move_info.moved_node,
                                );
                                applyMove(event, pwgTree);
                            },
                        },
                        cancel: {
                            text: str_no_change_parent,
                        },
                    },
                });
            } else {
                applyMove(event, pwgTree);
            }
        } else {
            applyMove(event, pwgTree);
        }
    });

    treeEl.addEventListener('click', function (e) {
        var orderBtn = e.target.closest('.move-cat-order');
        if (!orderBtn) return;
        var node_id = orderBtn.getAttribute('data-id');
        var node = pwgTree.getNodeById(node_id);
        if (node) {
            document.querySelector(".cat-move-order-popin").style.display = '';
            document.querySelector(".cat-move-order-popin .album-name").innerHTML = getPathNode(node);
            document.querySelector(".cat-move-order-popin input[name=id]").value = node_id;
            document.querySelector("input[name=simpleAutoOrder]").setAttribute("value", str_sub_album_order);
        }
    });

    document.querySelector(".order-root").addEventListener("click", function () {
        document.querySelector(".cat-move-order-popin").style.display = '';
        document.querySelector(".cat-move-order-popin .album-name").innerHTML = str_root;
        document.querySelector(".cat-move-order-popin input[name=id]").value = -1;
        document.querySelector("input[name=simpleAutoOrder]").setAttribute("value", str_root_order);
    });

    treeEl.addEventListener('mousedown', function () {
        treeEl.classList.add("dragging");
    });
    treeEl.addEventListener('mouseup', function () {
        document.querySelectorAll(".dragging").forEach(function (el) {
            el.classList.remove("dragging");
        });
    });

    if (openCat != -1) {
        var nodeToGo = pwgTree.getNodeById(openCat);

        goToNode(nodeToGo, nodeToGo, pwgTree);
        if (nodeToGo.children) {
            pwgTree.openNode(nodeToGo, false);
        }

        var scrollElement = document.documentElement;
        if (document.body.scrollHeight > document.documentElement.scrollHeight) {
            scrollElement = document.body;
        }
        var targetElement = document.getElementById("cat-" + openCat);
        if (targetElement) {
            scrollElement.scrollTop = targetElement.offsetTop;
        }
    }

    // RenameAlbumPopIn
    document.querySelector(".RenameAlbumErrors").style.display = 'none';
    document.querySelector(".move-cat-title-container").addEventListener("click", function () {
        var titleEl = this.querySelector(".move-cat-title");
        openRenameAlbumPopIn(titleEl.getAttribute("title"));
        document.querySelector(".RenameAlbumSubmit").dataset.cat_id = this.getAttribute("data-id");
    });
    document.querySelector(".CloseRenameAlbum").addEventListener("click", function () {
        closeRenameAlbumPopIn();
    });
    document.querySelector(".RenameAlbumCancel").addEventListener("click", function () {
        closeRenameAlbumPopIn();
    });

    document.querySelector(".RenameAlbumSubmit").addEventListener("click", function () {
        var catToEdit = this.dataset.cat_id;
        fetch("ws.php?format=json&method=pwg.categories.setInfo", {
            method: "POST",
            body: new URLSearchParams({
                category_id: catToEdit,
                name: document.querySelector(".RenameAlbumLabelUsername input").value,
            }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
        .then(function (response) { return response.text(); })
        .then(function (raw_data) {
            data = JSON.parse(raw_data);
            var node_id = document.getElementById("cat-" + catToEdit)
                .querySelector(".move-cat-toggler")
                .getAttribute("data-id");
            var node = pwgTree.getNodeById(node_id);
            node.name = document.querySelector(".RenameAlbumLabelUsername input").value;
            pwgTree.updateNode(
                node,
                document.querySelector(".RenameAlbumLabelUsername input").value,
            );

            document.querySelectorAll(".move-cat-title-container").forEach(function (el) {
                el.addEventListener("click", function () {
                    openRenameAlbumPopIn(
                        this.querySelector(".move-cat-title").getAttribute("title"),
                    );
                    document.querySelector(".RenameAlbumSubmit").dataset.cat_id = this.getAttribute("data-id");
                });
            });

            document.querySelectorAll(".move-cat-add").forEach(function (el) {
                el.addEventListener("click", function () {
                    openAddAlbumPopIn(this.dataset.aid, pwgTree);
                    document.querySelector(".AddAlbumSubmit").dataset.a_parent = this.dataset.aid;
                });
            });

            tippy('.tiptip', { delay: 0, placement: 'top' });

            closeRenameAlbumPopIn();
        })
        .catch(function (error) { console.log(error); });
    });

    // AddAlbumPopIn
    document.querySelector(".AddAlbumErrors").style.display = 'none';
    document.querySelector(".DeleteAlbumErrors").style.display = 'none';
    document.querySelector(".add-album-button").addEventListener("click", function () {
        openAddAlbumPopIn(0, pwgTree);
        document.querySelector(".AddAlbumSubmit").dataset.a_parent = 0;
    });
    document.querySelectorAll(".move-cat-add").forEach(function (el) {
        el.addEventListener("click", function () {
            openAddAlbumPopIn(this.dataset.aid, pwgTree);
            document.querySelector(".AddAlbumSubmit").dataset.a_parent = this.dataset.aid;
        });
    });
    document.querySelector(".CloseAddAlbum").addEventListener("click", function () {
        closeAddAlbumPopIn();
    });
    document.querySelector(".AddAlbumCancel").addEventListener("click", function () {
        closeAddAlbumPopIn();
    });
    document.querySelector(".DeleteAlbumCancel").addEventListener("click", function () {
        closeDeleteAlbumPopIn();
    });

    document.querySelector(".AddAlbumSubmit").addEventListener("click", function () {
        this.classList.add("notClickable");

        var newAlbumName = document.querySelector(".AddAlbumLabelUsername input").value;
        var newAlbumParent = this.dataset.a_parent;
        var newAlbumPosition = document.querySelector("input[name=position]:checked").value;

        fetch("ws.php?format=json&method=pwg.categories.add", {
            method: "POST",
            body: new URLSearchParams({
                name: newAlbumName,
                parent: newAlbumParent,
                position: newAlbumPosition,
            }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
        .then(function (response) { return response.text(); })
        .then(function (raw_data) {
            data = JSON.parse(raw_data);
            var parent_node = pwgTree.getNodeById(newAlbumParent);

            if (data.stat == "ok") {
                if (newAlbumPosition == "last") {
                    pwgTree.appendNode(
                        {
                            id: data.result.id,
                            isEmptyFolder: true,
                            name: newAlbumName,
                        },
                        parent_node,
                    );
                } else {
                    pwgTree.prependNode(
                        {
                            id: data.result.id,
                            isEmptyFolder: true,
                            name: newAlbumName,
                        },
                        parent_node,
                    );
                }

                if (parent_node) {
                    setSubcatsBadge(parent_node);

                    document.getElementById("cat-" + parent_node.id).addEventListener(
                        "click",
                        function (e) {
                            if (e.target.classList.contains("move-cat-toggler")) {
                                var node_id = parent_node.id;
                                var node = pwgTree.getNodeById(node_id);
                                if (node) {
                                    var open_nodes = pwgTree.getState().open_nodes;
                                    if (!open_nodes.includes(node_id)) {
                                        e.target.innerHTML = toggler_open;
                                        pwgTree.openNode(node);
                                    } else {
                                        e.target.innerHTML = toggler_close;
                                        pwgTree.closeNode(node);
                                    }
                                }
                            }
                        }
                    );
                }

                document.querySelectorAll(".move-cat-add").forEach(function (el) {
                    el.addEventListener("click", function () {
                        openAddAlbumPopIn(this.dataset.aid, pwgTree);
                        document.querySelector(".AddAlbumSubmit").dataset.a_parent = this.dataset.aid;
                    });
                });

                document.querySelectorAll(".move-cat-delete").forEach(function (el) {
                    el.addEventListener("click", function () {
                        triggerDeleteAlbum(this.dataset.id, pwgTree);
                    });
                });

                document.querySelectorAll(".move-cat-title-container").forEach(function (el) {
                    el.addEventListener("click", function () {
                        openRenameAlbumPopIn(
                            this.querySelector(".move-cat-title").getAttribute("title"),
                        );
                        document.querySelector(".RenameAlbumSubmit").dataset.cat_id = this.getAttribute("data-id");
                    });
                });

                tippy('.tiptip', { delay: 0, placement: 'top' });

                updateTitleBadge(nb_albums + 1);

                goToNode(
                    pwgTree.getNodeById(data.result.id),
                    pwgTree.getNodeById(data.result.id),
                    pwgTree,
                );

                var targetElement = document.getElementById("cat-" + data.result.id);
                if (targetElement) {
                    window.scrollTo(0, targetElement.offsetTop - window.innerHeight / 2);
                }

                closeAddAlbumPopIn();
                document.querySelector(".AddAlbumSubmit").classList.remove("notClickable");
            } else {
                document.querySelector(".AddAlbumErrors").textContent = str_album_name_empty;
                document.querySelector(".AddAlbumErrors").style.display = '';
                document.querySelector(".AddAlbumSubmit").classList.remove("notClickable");
            }
        })
        .catch(function (error) {
            console.log(error);
            document.querySelector(".AddAlbumSubmit").classList.remove("notClickable");
        });
    });

    // Delete Album
    document.querySelectorAll(".move-cat-delete").forEach(function (el) {
        el.addEventListener("click", function () {
            triggerDeleteAlbum(this.dataset.id, pwgTree);
        });
    });

    document.querySelectorAll(".user-list-checkbox").forEach(function (el) {
        el.removeEventListener("change", checkbox_change);
        el.addEventListener("change", checkbox_change);
        el.removeEventListener("click", checkbox_click);
        el.addEventListener("click", checkbox_click);
    });

    if (!light_album_manager) {
        tippy('.tiptip', { delay: 0, placement: 'top' });
    }
});

function createAlbumNode(node, li) {
    var icon = "<span class='%icon%'></span>";
    var title = '<span data-id="' + node.id + '" class="move-cat-title-container ';
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
    var toggler_cont = "<div class='move-cat-toggler' data-id=%id%>%content%</div>";
    toggler_close = "<span class='icon-left-open'></span>";
    toggler_open = "<span class='icon-down-open'></span>";
    var actions =
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

    var cont = li.querySelector('.jqtree-element');
    cont.classList.add("move-cat-container");
    cont.id = "cat-" + node.id;
    cont.innerHTML = '';

    cont.insertAdjacentHTML('beforeend', actions);

    cont.querySelectorAll(".toggle-cat-option").forEach(function (el) {
        el.addEventListener("click", function () {
            document.querySelectorAll(".cat-option").forEach(function (opt) { opt.style.display = 'none'; });
            var options = this.querySelector(".cat-option");
            if (options) {
                options.style.display = options.style.display === 'none' ? '' : 'none';
            }
        });
    });

    if (node.children.length != 0) {
        var open_nodes = [];
        // pwgTree is not in scope here; toggler state is managed via tree.open/close events
        var toggler = toggler_close;
        cont.insertAdjacentHTML('beforeend',
            toggler_cont
                .replace(/%content%/g, toggler)
                .replace(/%id%/g, node.id)
        );
    } else {
        cont.querySelector(".move-cat-order").classList.add("notClickable");

        cont.insertAdjacentHTML('beforeend',
            toggler_cont
                .replace(/%content%/g, toggler_close)
                .replace(/%id%/g, node.id)
        );
        cont.classList.add("disabledToggle");
    }

    cont.insertAdjacentHTML('beforeend', icon.replace(/%icon%/g, "icon-grip-vertical-solid"));

    if (node.children.length != 0) {
        cont.insertAdjacentHTML('beforeend', icon.replace(/%icon%/g, "icon-sitemap"));
    } else {
        cont.insertAdjacentHTML('beforeend', icon.replace(/%icon%/g, "icon-folder-open"));
    }

    cont.insertAdjacentHTML('beforeend', title.replace(/%name%/g, node.name));

    var colors = [
        "icon-red",
        "icon-blue",
        "icon-yellow",
        "icon-purple",
        "icon-green",
    ];
    var colorId = Number(node.id) % 5;
    cont.querySelectorAll("span.icon-folder-open, span.icon-sitemap").forEach(function (el) {
        el.classList.add(colors[colorId]);
        el.classList.add("node-icon");
    });

    var titleContainer = cont.querySelector(".move-cat-title-container");
    if (titleContainer) {
        titleContainer.insertAdjacentHTML('afterend',
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
                "</div>"
        );
    }

    if (!node.nb_subcats) {
        cont.querySelectorAll(".nb-subcats").forEach(function (el) { el.style.display = 'none'; });
    }

    if (!(node.nb_images != 0 && node.nb_images)) {
        cont.querySelectorAll(".nb-images").forEach(function (el) { el.style.display = 'none'; });
    }

    if (!node.nb_sub_photos) {
        cont.querySelectorAll(".nb-sub-photos").forEach(function (el) { el.style.display = 'none'; });
    }

    if (node.has_not_access) {
        var seeBtn = cont.querySelector(".move-cat-see");
        if (seeBtn) seeBtn.classList.add("notClickable");
    }

    // Set draggable on the element for HTML5 DnD
    cont.setAttribute('draggable', 'true');
}

/*----------------
Checkboxes
----------------*/

function checkbox_change() {
    if (this.getAttribute("data-selected") == "1") {
        this.querySelector("i").style.display = 'none';
    } else {
        this.querySelector("i").style.display = '';
    }
}

function checkbox_click() {
    if (this.getAttribute("data-selected") == "1") {
        this.setAttribute("data-selected", "0");
        this.querySelector("i").style.display = 'none';
    } else {
        this.setAttribute("data-selected", "1");
        this.querySelector("i").style.display = '';
    }
}

function isNumeric(num) {
    return !isNaN(num);
}

function openAddAlbumPopIn(parentAlbumId, pwgTree) {
    if (parentAlbumId != 0) {
        document.querySelector("#AddAlbum .AddIconTitle span").innerHTML =
            add_sub_album_of.replace(
                "%s",
                pwgTree.getNodeById(parentAlbumId).name,
            );
    } else {
        document.querySelector("#AddAlbum .AddIconTitle span").innerHTML = add_album_root_title;
    }
    document.getElementById("AddAlbum").style.display = '';
    document.querySelector(".AddAlbumLabelUsername .user-property-input").value = "";
    document.querySelector(".AddAlbumLabelUsername .user-property-input").focus();

    document.getElementById("AddAlbum").removeEventListener("keyup", arguments.callee);
    document.getElementById("AddAlbum").addEventListener("keyup", function (e) {
        // 13 is 'Enter'
        if (e.keyCode === 13) {
            document.querySelector(".AddAlbumSubmit").click();
        }
        // 27 is 'Escape'
        if (e.keyCode === 27) {
            closeAddAlbumPopIn();
        }
    });
}

function closeAddAlbumPopIn() {
    document.getElementById("AddAlbum").style.display = 'none';
}

function openRenameAlbumPopIn(replacedAlbumName) {
    document.getElementById("RenameAlbum").style.display = '';
    document.querySelector(".RenameAlbumTitle span").innerHTML =
        rename_item.replace("%s", replacedAlbumName);
    document.querySelector(".RenameAlbumLabelUsername .user-property-input").value = replacedAlbumName;
    document.querySelector(".RenameAlbumLabelUsername .user-property-input").focus();

    document.removeEventListener("keypress", arguments.callee);
    document.addEventListener("keypress", function (e) {
        if (e.which == 13) {
            document.querySelector(".RenameAlbumSubmit").click();
        }
    });
}

function closeRenameAlbumPopIn() {
    document.getElementById("RenameAlbum").style.display = 'none';
}

function triggerDeleteAlbum(cat_id, pwgTree) {
    fetch("ws.php?format=json&method=pwg.categories.calculateOrphans?category_id=" + cat_id, {
        method: "GET",
    })
    .then(function (response) { return response.text(); })
    .then(function (raw_data) {
        var data = JSON.parse(raw_data).result[0];
        if (data.nb_images_recursive == 0) {
            document.querySelector(".deleteAlbumOptions").style.display = 'none';
        } else {
            document.querySelector(".deleteAlbumOptions").style.display = '';
            if (data.nb_images_associated_outside == 0) {
                document.getElementById("IMAGES_ASSOCIATED_OUTSIDE").style.display = 'none';
            } else {
                document.querySelector("#IMAGES_ASSOCIATED_OUTSIDE .innerText").innerHTML = "";
                document.querySelector("#IMAGES_ASSOCIATED_OUTSIDE .innerText").appendChild(
                    document.createTextNode(
                        has_images_associated_outside
                            .replace("%d", data.nb_images_recursive)
                            .replace("%d", data.nb_images_associated_outside)
                    )
                );
            }
            if (data.nb_images_becoming_orphan == 0) {
                document.getElementById("IMAGES_BECOMING_ORPHAN").style.display = 'none';
            } else {
                document.querySelector("#IMAGES_BECOMING_ORPHAN .innerText").innerHTML = "";
                document.querySelector("#IMAGES_BECOMING_ORPHAN .innerText").appendChild(
                    document.createTextNode(
                        has_images_becoming_orphans.replace(
                            "%d",
                            data.nb_images_becoming_orphan,
                        )
                    )
                );
            }
        }
        openDeleteAlbumPopIn(cat_id, pwgTree);
    })
    .catch(function (error) { console.log(error); });
}

function openDeleteAlbumPopIn(cat_to_delete, pwgTree) {
    document.getElementById("DeleteAlbum").style.display = '';
    var node = pwgTree.getNodeById(cat_to_delete);
    if (node.children.length == 0) {
        document.querySelector(".DeleteIconTitle span").innerHTML =
            delete_album_with_name.replace("%s", node.name);
    } else {
        var nb_sub_cats = 0;
        document.querySelector(".DeleteIconTitle span").innerHTML =
            delete_album_with_subs
                .replace("%s", node.name)
                .replace("%d", getAllSubAlbumsFromNode(node, nb_sub_cats));
    }

    // Actually delete
    document.querySelector(".DeleteAlbumSubmit").removeEventListener("click", arguments.callee);
    document.querySelector(".DeleteAlbumSubmit").addEventListener("click", function () {
        fetch("ws.php?format=json&method=pwg.categories.delete", {
            method: "POST",
            body: new URLSearchParams({
                category_id: cat_to_delete,
                photo_deletion_mode: document.querySelector("input[name=photo_deletion_mode]:checked").value,
                pwg_token: pwg_token,
            }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
        .then(function (response) { return response.text(); })
        .then(function (raw_data) {
            var parentOfDeletedNode = node.parent;
            pwgTree.removeNode(node);

            document.querySelectorAll(".move-cat-add").forEach(function (el) {
                el.addEventListener("click", function () {
                    openAddAlbumPopIn(this.dataset.aid, pwgTree);
                    document.querySelector(".AddAlbumSubmit").dataset.a_parent = this.dataset.aid;
                });
            });
            document.querySelectorAll(".move-cat-delete").forEach(function (el) {
                el.addEventListener("click", function () {
                    triggerDeleteAlbum(this.dataset.id, pwgTree);
                });
            });
            document.querySelectorAll(".move-cat-title-container").forEach(function (el) {
                el.addEventListener("click", function () {
                    openRenameAlbumPopIn(
                        this.querySelector(".move-cat-title").getAttribute("title"),
                    );
                    document.querySelector(".RenameAlbumSubmit").dataset.cat_id = this.getAttribute("data-id");
                });
            });

            tippy('.tiptip', { delay: 0, placement: 'top' });

            updateTitleBadge(nb_albums - 1);
            setSubcatsBadge(parentOfDeletedNode);
            closeDeleteAlbumPopIn();
        })
        .catch(function (error) { console.log(error); });
    });
}

function closeDeleteAlbumPopIn() {
    document.getElementById("DeleteAlbum").style.display = 'none';
}

function getAllSubAlbumsFromNode(node, nb_sub_cats) {
    nb_sub_cats = 0;
    if (node.children != 0) {
        node.children.forEach(function (child) {
            nb_sub_cats++;
            var tmp = getAllSubAlbumsFromNode(child, nb_sub_cats);
            nb_sub_cats += tmp;
        });
    } else {
        return 0;
    }
    return nb_sub_cats;
}

function setSubcatsBadge(node) {
    if (!node) return;
    if (node.children.length != 0) {
        var nbSubcatsEl = document.querySelector("#cat-" + node.id + " .nb-subcats");
        if (nbSubcatsEl) {
            nbSubcatsEl.textContent = node.children.length;
            nbSubcatsEl.style.display = '';
        }
        var badgeDropdown = document.querySelector("#cat-" + node.id + " .badge-dropdown .nb-subcats");
        if (badgeDropdown) {
            badgeDropdown.textContent = x_nb_subcats.replace("%d", node.children.length);
        }
    } else {
        var nbSubcatsEl = document.querySelector("#cat-" + node.id + " .nb-subcats");
        if (nbSubcatsEl) {
            nbSubcatsEl.style.display = 'none';
        }
    }
}

function updateTitleBadge(new_nb_albums) {
    nb_albums = new_nb_albums;
    document.querySelector(".badge-number").textContent = new_nb_albums;
}

function goToNode(node, firstNode, pwgTree) {
    if (node.parent) {
        goToNode(node.parent, firstNode, pwgTree);
        if (node != firstNode) {
            pwgTree.openNode(node);
            var parentEl = document.getElementById("cat-" + node.parent.id);
            if (parentEl) {
                parentEl.style.display = '';
                parentEl.classList.add("immune");
            }
        }
    } else {
        pwgTree.openNode(node);
        var firstEl = document.getElementById("cat-" + firstNode.id);
        if (firstEl) {
            firstEl.classList.add("animateFocus");
        }

        showNodeChildren(firstNode);
    }
}

function showNodeChildren(node) {
    if (node.children) {
        node.children.forEach(function (child) {
            var childEl = document.getElementById("cat-" + child.id);
            if (childEl) {
                childEl.classList.add("immune");
            }
            showNodeChildren(child);
        });
    }
}

function closeTree(pwgTree) {
    var open_nodes = pwgTree.getState().open_nodes;
    if (open_nodes.length > 0) {
        open_nodes.forEach(function (nodeItem) {
            var node = pwgTree.getNodeById(nodeItem);
            pwgTree.closeNode(node);
        });
    }
}

function getId(parent) {
    if (parent.getLevel() == 0) {
        return 0;
    } else {
        return parent.id;
    }
}

function getRank(node, ignoreId) {
    if (ignoreId === undefined) ignoreId = null;
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

function applyMove(event, pwgTree) {
    var waitingTimeout = setTimeout(function () {
        document.querySelector(".waiting-message").classList.add("visible");
    }, 500);
    var id = event.move_info.moved_node.id;
    var moveParent = null;
    var moveRank = null;
    var previous_parent = event.move_info.previous_parent;
    var target = event.move_info.target_node;
    if (event.move_info.position == "after") {
        if (getId(previous_parent) != getId(target.parent)) {
            moveParent = getId(target.parent);
        }
        moveRank = getRank(target, id) + 1;
    } else if (event.move_info.position == "inside") {
        if (getId(previous_parent) != getId(target)) {
            moveParent = getId(target);
        }
        moveRank = 1;
    } else if (event.move_info.position == "before") {
        if (getId(previous_parent) != getId(target.parent)) {
            moveParent = getId(target.parent);
        }
        moveRank = 1;
    }
    moveNode(id, moveRank, moveParent, pwgTree)
        .then(function () {
            event.move_info.do_move();
            clearTimeout(waitingTimeout);
            document.querySelector(".waiting-message").classList.remove("visible");
            setSubcatsBadge(previous_parent);
            setSubcatsBadge(pwgTree ? pwgTree.getNodeById(moveParent) : null);

            document.querySelectorAll(".move-cat-add").forEach(function (el) {
                el.addEventListener("click", function () {
                    openAddAlbumPopIn(this.dataset.aid, pwgTree);
                    document.querySelector(".AddAlbumSubmit").dataset.a_parent = this.dataset.aid;
                });
            });
            document.querySelectorAll(".move-cat-delete").forEach(function (el) {
                el.addEventListener("click", function () {
                    triggerDeleteAlbum(this.dataset.id, pwgTree);
                });
            });
            document.querySelectorAll(".move-cat-title-container").forEach(function (el) {
                el.addEventListener("click", function () {
                    openRenameAlbumPopIn(
                        this.querySelector(".move-cat-title").getAttribute("title"),
                    );
                    document.querySelector(".RenameAlbumSubmit").dataset.cat_id = this.getAttribute("data-id");
                });
            });

            tippy('.tiptip', { delay: 0, placement: 'top' });
        })
        .catch(function (message) {
            console.log("An error has occurred : " + message);
            document.querySelectorAll(".move-cat-add").forEach(function (el) {
                el.addEventListener("click", function () {
                    openAddAlbumPopIn(this.dataset.aid, pwgTree);
                    document.querySelector(".AddAlbumSubmit").dataset.a_parent = this.dataset.aid;
                });
            });
            document.querySelectorAll(".move-cat-delete").forEach(function (el) {
                el.addEventListener("click", function () {
                    triggerDeleteAlbum(this.dataset.id, pwgTree);
                });
            });
            document.querySelectorAll(".move-cat-title-container").forEach(function (el) {
                el.addEventListener("click", function () {
                    openRenameAlbumPopIn(
                        this.querySelector(".move-cat-title").getAttribute("title"),
                    );
                    document.querySelector(".RenameAlbumSubmit").dataset.cat_id = this.getAttribute("data-id");
                });
            });

            tippy('.tiptip', { delay: 0, placement: 'top' });
        });
}

function moveNode(node, rank, parent, pwgTree) {
    return new Promise(function (res, rej) {
        if (parent != null) {
            changeParent(node, parent, rank, pwgTree)
                .then(function () { res(); })
                .catch(function () { rej(); });
        } else if (rank != null) {
            changeRank(node, rank)
                .then(function () { res(); })
                .catch(function () { rej(); });
        }
    });
}

function changeParent(node, parent, rank, pwgTree) {
    return new Promise(function (res, rej) {
        fetch("ws.php?format=json&method=pwg.categories.move", {
            method: "POST",
            body: new URLSearchParams({
                category_id: node,
                parent: parent,
                pwg_token: pwg_token,
            }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
        .then(function (response) { return response.text(); })
        .then(function (raw_data) {
            data = JSON.parse(raw_data);
            if (data.stat === "ok") {
                changeRank(node, rank);
                var updated_cats = data.result.updated_cats;
                if (updated_cats) {
                    updated_cats.forEach(function (cat) {
                        var catNode = pwgTree.getNodeById(cat.cat_id);
                        if (catNode) {
                            catNode.nb_sub_photos = cat.nb_sub_photos;
                            pwgTree.updateNode(catNode, catNode.name);
                        }
                    });
                }
                res();
            } else {
                rej(raw_data);
            }
        })
        .catch(function (error) { rej(error); });
    });
}

function changeRank(node, rank) {
    return new Promise(function (res, rej) {
        fetch("ws.php?format=json&method=pwg.categories.setRank", {
            method: "POST",
            body: new URLSearchParams({
                category_id: node,
                rank: rank,
            }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
        .then(function (response) { return response.text(); })
        .then(function (raw_data) {
            data = JSON.parse(raw_data);
            if (data.stat === "ok") {
                res();
            } else {
                rej(raw_data);
            }
        })
        .catch(function (error) { rej(error); });
    });
}

function makePrivateHierarchy(node) {
    node.status = "private";
    node.children.forEach(function (child) {
        makePrivateHierarchy(child);
    });
}

function getPathNode(node) {
    if (node.parent.getLevel() != 0) {
        return getPathNode(node.parent) + " / " + node.name;
    } else {
        return node.name;
    }
}
