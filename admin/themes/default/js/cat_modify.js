document.addEventListener('DOMContentLoaded', function () {
    activateCommentDropdown();
    checkAlbumLock();

    document.querySelector(".unlock-album").addEventListener("click", function () {
        fetch("ws.php?format=json&method=pwg.categories.setInfo", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
                category_id: album_id,
                visible: "true",
            }),
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.stat == "ok") {
                    is_visible = "true";
                    if (document.getElementById("cat-locked").checked) {
                        document.querySelector("input[id='cat-locked']").click();
                    }
                    checkAlbumLock();

                    setTimeout(function () {
                        document.querySelector(".info-message").style.display = 'none';
                    }, 5000);
                } else {
                    document.querySelector(".info-error").style.display = '';
                    setTimeout(function () {
                        document.querySelector(".info-error").style.display = 'none';
                    }, 5000);
                }
            })
            .catch(function (error) {
                save_button_set_loading(false);

                document.querySelector(".info-error").style.display = '';
                setTimeout(function () {
                    document.querySelector(".info-error").style.display = 'none';
                }, 5000);
                console.log(error);
            });
    });

    tippy('.tiptip', { delay: 0, placement: 'top' });

    document.getElementById("cat-properties-save").addEventListener("click", function () {
        save_button_set_loading(true);
        document.querySelector(".info-error,.info-message").style.display = 'none';

        fetch("ws.php?format=json&method=pwg.categories.setInfo", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
                category_id: album_id,
                name: document.getElementById("cat-name").value,
                comment: document.getElementById("cat-comment").value,
                visible: document.getElementById("cat-locked").checked ? "false" : "true",
                commentable: document.getElementById("cat-commentable").checked
                    ? "true"
                    : "false",
                pwg_token: pwg_token,
            }),
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.stat == "ok") {
                    save_button_set_loading(false);

                    document.querySelector(".info-message").style.display = '';
                    document.querySelector(".cat-modification .cat-modify-info-subcontent").innerHTML =
                        str_just_now;
                    document.querySelector(".cat-modification .cat-modify-info-content").innerHTML =
                        str_just_now;

                    is_visible = document.getElementById("cat-locked").checked
                        ? "false"
                        : "true";
                    checkAlbumLock();

                    setTimeout(function () {
                        document.querySelector(".info-message").style.display = 'none';
                    }, 5000);
                } else {
                    document.querySelector(".info-error").style.display = '';
                    setTimeout(function () {
                        document.querySelector(".info-error").style.display = 'none';
                    }, 5000);
                }
            })
            .catch(function (error) {
                save_button_set_loading(false);

                document.querySelector(".info-error").style.display = '';
                setTimeout(function () {
                    document.querySelector(".info-error").style.display = 'none';
                }, 5000);
                console.log(error);
            });

        if (parent_album != default_parent_album) {
            fetch("ws.php?format=json&method=pwg.categories.move", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({
                    category_id: album_id,
                    parent: parent_album,
                    pwg_token: pwg_token,
                }),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.stat === "ok") {
                        document.querySelector(".cat-modify-ariane").innerHTML =
                            data.result.new_ariane_string;
                        default_parent_album = parent_album;
                    } else {
                        document.querySelector(".info-error").style.display = '';
                        setTimeout(function () {
                            document.querySelector(".info-error").style.display = 'none';
                        }, 5000);
                    }
                })
                .catch(function (e) {
                    console.log(e.message);
                });
        }
    });

    function save_button_set_loading(state = true) {
        var icon = document.querySelector("#cat-properties-save i");
        if (state) {
            icon.classList.remove("icon-floppy");
            icon.classList.add("icon-spin6", "animate-spin");
        } else {
            icon.classList.add("icon-floppy");
            icon.classList.remove("icon-spin6", "animate-spin");
        }

        document.getElementById("cat-properties-save").disabled = state;
    }

    document.querySelector(".deleteAlbum").addEventListener("click", function () {
        fetch("ws.php?format=json&method=pwg.categories.calculateOrphans&" + new URLSearchParams({ category_id: album_id }))
            .then(function (r) { return r.json(); })
            .then(function (raw_json) {
                let data = raw_json.result[0];

                let message =
                    "<p>" +
                    str_delete_album_and_his_x_subalbums
                        .replace("%s", "<strong>" + album_name + "</strong>")
                        .replace("%d", "<strong>" + nb_sub_albums + "</strong>") +
                    "</p>";

                message += `<div class="cat-delete-modes">`;
                message += `<div ${data.nb_images_recursive ? "" : "style='display:none'"}>
                    <input type="radio" name="deletion-mode" value="no_delete" id="no_delete" checked>
                    <label for="no_delete">${str_dont_delete_photos}</label>
                  </div>`;

                if (data.nb_images_recursive) {
                    let t = 0;
                    message += `<div>
                    <input type="radio" name="deletion-mode" value="force_delete" id="force_delete">
                    <label for="force_delete">${str_delete_all_photos.replaceAll("%d", (_) => [data.nb_images_recursive, data.nb_images_associated_outside][t++])}</label>
                  </div>`;
                }

                if (data.nb_images_becoming_orphan)
                    message += `<div>
                    <input type="radio" name="deletion-mode" value="delete_orphans" id="delete_orphans">
                    <label for="delete_orphans">${str_delete_orphans.replace("%d", data.nb_images_becoming_orphan)}</label>
                  </div>`;
                message += `</div>`;

                pwgConfirm({
                    title: str_delete_album,
                    content: message,
                    buttons: {
                        deleteAlbum: {
                            text: str_delete_album,
                            btnClass: "btn-red",
                            action: function () {
                                let deletionMode = document.querySelector(
                                    'input[name="deletion-mode"]:checked',
                                )?.value;
                                delete_album(deletionMode)
                                    .then(() => (window.location.href = u_delete))
                                    .catch((err) => { console.log(err); });
                            },
                        },
                        cancel: {
                            text: str_cancel,
                        },
                    },
                });
            })
            .catch(function () {
                pwgConfirm({
                    title: str_delete_album,
                    content: "An error has occurred while calculating orphans",
                    buttons: { cancel: { text: str_cancel } },
                });
            });
    });

    function delete_album(photo_deletion_mode) {
        return new Promise((res, rej) => {
            fetch("ws.php?format=json&method=pwg.categories.delete", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({
                    category_id: album_id,
                    photo_deletion_mode: photo_deletion_mode,
                    pwg_token: pwg_token,
                }),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    res();
                })
                .catch(function (error) {
                    rej(error);
                });
        });
    }

    document.getElementById("refreshRepresentative")?.addEventListener("click", function (e) {
        var method = "pwg.categories.refreshRepresentative";

        var icon = document.querySelector("#refreshRepresentative i");
        icon.classList.remove("icon-ccw");
        icon.classList.add("icon-spin6", "animate-spin");

        fetch("ws.php?format=json&method=" + method, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
                category_id: album_id,
            }),
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.stat == "ok") {
                    document.getElementById("deleteRepresentative").style.display = '';

                    document.querySelector(".cat-modify-representative")
                        .setAttribute(
                            "style",
                            `background-image:url('${data.result.src}')`,
                        );
                    document.querySelector(".cat-modify-representative")
                        .classList.remove("icon-dice-solid");
                } else {
                    console.error(data);
                }
                document.querySelector("#refreshRepresentative i")
                    .classList.add("icon-ccw");
                document.querySelector("#refreshRepresentative i")
                    .classList.remove("icon-spin6", "animate-spin");
            })
            .catch(function (error) {
                console.error(error);
                document.querySelector("#refreshRepresentative i")
                    .classList.add("icon-ccw");
                document.querySelector("#refreshRepresentative i")
                    .classList.remove("icon-spin6", "animate-spin");
            });

        e.preventDefault();
    });

    document.getElementById("deleteRepresentative")?.addEventListener("click", function (e) {
        var method = "pwg.categories.deleteRepresentative";

        var icon = document.querySelector("#deleteRepresentative i");
        icon.classList.remove("icon-cancel");
        icon.classList.add("icon-spin6", "animate-spin");

        fetch("ws.php?format=json&method=" + method, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
                category_id: album_id,
            }),
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.stat == "ok") {
                    document.getElementById("deleteRepresentative").style.display = 'none';
                    document.querySelector(".cat-modify-representative")
                        .setAttribute("style", ``);
                    document.querySelector(".cat-modify-representative")
                        .classList.add("icon-dice-solid");
                } else {
                    console.error(data);
                }
                document.querySelector("#deleteRepresentative i")
                    .classList.add("icon-cancel");
                document.querySelector("#deleteRepresentative i")
                    .classList.remove("icon-spin6", "animate-spin");
            })
            .catch(function (error) {
                console.error(error);
                document.querySelector("#deleteRepresentative i")
                    .classList.add("icon-cancel");
                document.querySelector("#deleteRepresentative i")
                    .classList.remove("icon-spin6", "animate-spin");
            });

        e.preventDefault();
    });

    // Parent album popin
    document.querySelector("#cat-parent.icon-pencil").addEventListener("click", function (e) {
        // Don't open the popin if you click on the album link
        if (e.target.localName != "a") {
            linked_albums_open();
            set_up_popin();

            if (parent_album != 0) {
                document.querySelector(".put-to-root").classList.remove("notClickable");
                document.querySelector(".put-to-root").addEventListener("click", function () {
                    add_related_category(0, str_root);
                });
            } else {
                document.querySelector(".put-to-root").classList.add("notClickable");
            }
        }
    });

    document.querySelector(".limitReached").innerHTML = str_no_search_in_progress;
    document.querySelectorAll(".search-cancel-linked-album").forEach(function (el) {
        el.style.display = 'none';
    });
    document.querySelectorAll(".linkedAlbumPopInContainer .searching").forEach(function (el) {
        el.style.display = 'none';
    });
    var linkedAlbumSearchInput = document.querySelector("#linkedAlbumSearch .search-input");
    if (linkedAlbumSearchInput) {
        linkedAlbumSearchInput.addEventListener("input", function () {
            if (this.value != 0) {
                var cancelBtn = document.querySelector("#linkedAlbumSearch .search-cancel-linked-album");
                if (cancelBtn) cancelBtn.style.display = '';
            } else {
                var cancelBtn = document.querySelector("#linkedAlbumSearch .search-cancel-linked-album");
                if (cancelBtn) cancelBtn.style.display = 'none';
            }

            if (this.value.length > 0) {
                linked_albums_search(this.value);
            } else {
                document.querySelector(".limitReached").innerHTML = str_no_search_in_progress;
                var searchResult = document.getElementById("searchResult");
                if (searchResult) searchResult.innerHTML = '';
            }
        });
    }

    document.querySelectorAll(".search-cancel-linked-album").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var searchInput = document.querySelector("#linkedAlbumSearch .search-input");
            if (searchInput) {
                searchInput.value = "";
                searchInput.dispatchEvent(new Event("input"));
            }
        });
    });

    document.querySelectorAll(".allow-comments").forEach(function (el) {
        el.addEventListener("click", function () {
            fetch("ws.php?format=json&method=pwg.categories.setInfo", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({
                    category_id: album_id,
                    commentable: true,
                    apply_commentable_to_subalbums: true,
                }),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.stat == "ok") {
                        save_button_set_loading(false);
                        if (!document.getElementById("cat-commentable").checked) {
                            document.getElementById("cat-commentable").click();
                        }

                        temp_txt = document.querySelector(".info-message").textContent;
                        document.querySelector(".info-message").textContent = str_album_comment_allow;
                        document.querySelector(".info-message").style.display = '';

                        setTimeout(function () {
                            document.querySelector(".info-message").style.display = 'none';
                            document.querySelector(".info-message").textContent = temp_txt;
                        }, 5000);
                    } else {
                        document.querySelector(".info-error").style.display = '';
                        setTimeout(function () {
                            document.querySelector(".info-error").style.display = 'none';
                        }, 5000);
                    }
                })
                .catch(function (e) {
                    console.log(e);
                    save_button_set_loading(false);
                });
        });
    });

    document.querySelectorAll(".disallow-comments").forEach(function (el) {
        el.addEventListener("click", function () {
            fetch("ws.php?format=json&method=pwg.categories.setInfo", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({
                    category_id: album_id,
                    commentable: false,
                    apply_commentable_to_subalbums: true,
                }),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.stat == "ok") {
                        save_button_set_loading(false);
                        if (document.getElementById("cat-commentable").checked) {
                            document.getElementById("cat-commentable").click();
                        }

                        temp_txt = document.querySelector(".info-message").textContent;
                        document.querySelector(".info-message").textContent = str_album_comment_disallow;
                        document.querySelector(".info-message").style.display = '';

                        setTimeout(function () {
                            document.querySelector(".info-message").style.display = 'none';
                            document.querySelector(".info-message").textContent = temp_txt;
                        }, 5000);
                    } else {
                        document.querySelector(".info-error").style.display = '';
                        setTimeout(function () {
                            document.querySelector(".info-error").style.display = 'none';
                        }, 5000);
                    }
                })
                .catch(function (e) {
                    console.log(e);
                    save_button_set_loading(false);
                });
        });
    });

    // Modal description
    let form_unsaved = false;
    const cat_modify = document.getElementById("cat-modify");
    const desc_modal = document.getElementById("desc-modal");
    const textareas = document.querySelectorAll(".sync-textarea");

    document.getElementById("desc-zoom-square")?.addEventListener("click", function () {
        desc_modal.style.display = getComputedStyle(desc_modal).display === 'none' ? '' : 'none';
    });

    document.getElementById("desc-modal-close")?.addEventListener("click", function () {
        desc_modal.style.display = getComputedStyle(desc_modal).display === 'none' ? '' : 'none';
    });

    textareas.forEach(function (textarea) {
        textarea.addEventListener("keyup", function () {
            textareas.forEach(function (ta) {
                ta.value = this.value;
            }.bind(this));
        });
    });

    window.addEventListener("click", function (e) {
        if (e.target === desc_modal) {
            desc_modal.style.display = getComputedStyle(desc_modal).display === 'none' ? '' : 'none';
        }
    });

    document.addEventListener("keyup", function (e) {
        // 27 is 'Escape'
        if (e.keyCode === 27 && getComputedStyle(desc_modal).display !== 'none') {
            desc_modal.style.display = 'none';
        }
    });
});

function checkAlbumLock() {
    if (is_visible == "true") {
        document.querySelectorAll(".warnings").forEach(function (el) {
            el.style.display = 'none';
        });
    } else {
        document.querySelectorAll(".warnings").forEach(function (el) {
            el.style.display = '';
        });
    }
}

// Parent album popin functions

function fill_results(cats) {
    var searchResult = document.getElementById("searchResult");
    if (!searchResult) return;
    searchResult.innerHTML = '';
    cats.forEach((cat) => {
        searchResult.insertAdjacentHTML(
            "beforeend",
            "<div class='search-result-item' id=" +
                cat.id +
                ">" +
                "<span class='search-result-path'>" +
                cat.fullname +
                "</span><span class='icon-plus-circled item-add'></span>" +
                "</div>",
        );

        // If the searched albums are in the children of the current album they become unclickable
        // Same if the album is already selected

        if (
            parent_album == cat.id ||
            cat.uppercats.split(",").includes(album_id + "")
        ) {
            var itemDiv = searchResult.querySelector("#" + cat.id + ".search-result-item");
            if (itemDiv) {
                itemDiv.classList.add("notClickable");
                itemDiv.setAttribute("title", str_already_in_related_cats);
                itemDiv.addEventListener("click", function (event) {
                    event.preventDefault();
                });
            }
        } else {
            var resultItem = searchResult.querySelector("#" + cat.id + ".search-result-item ");
            if (resultItem) {
                resultItem.addEventListener("click", function () {
                    add_related_category(cat.id, cat.full_name_with_admin_links);
                });
            }
        }
    });
}

function add_related_category(cat_id, cat_link_path) {
    if (parent_album != cat_id) {
        document.getElementById("cat-parent").innerHTML = cat_link_path;

        var searchItem = document.querySelector(".search-result-item #" + cat_id);
        if (searchItem) searchItem.classList.add("notClickable");
        parent_album = cat_id;

        var invisibleSelect = document.querySelector(".invisible-related-categories-select");
        if (invisibleSelect) {
            invisibleSelect.insertAdjacentHTML("beforeend", "<option selected value=" + cat_id + "></option>");
        }

        linked_albums_close();
    }
}

function activateCommentDropdown() {
    document.querySelectorAll(".toggle-comment-option .comment-option").forEach(function (el) {
        el.style.display = 'none';
    });

    /* Display the option on the click on "..." */
    document.querySelectorAll(".toggle-comment-option").forEach(function (el) {
        el.addEventListener("click", function () {
            this.querySelector(".comment-option").style.display =
                getComputedStyle(this.querySelector(".comment-option")).display === 'none' ? '' : 'none';
        });
    });

    /* Hide img options and rename field on click on the screen */

    document.addEventListener("mouseup", function (e) {
        e.stopPropagation();
        let option_is_clicked = false;
        document.querySelectorAll(".comment-option span").forEach(function (el) {
            if (el.contains(e.target)) {
                option_is_clicked = true;
            }
        });
        if (!option_is_clicked) {
            document.querySelectorAll(".toggle-comment-option .comment-option").forEach(function (el) {
                el.style.display = 'none';
            });
        }
    });
}
